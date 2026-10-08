#!/usr/bin/env python3
"""Real old-to-new Docker entrypoint upgrade; isolated DB/network/data, no LAN ports."""
import os
import pathlib
import subprocess
import time
import uuid
import urllib.request
import urllib.parse
import http.cookiejar
import re

ROOT = pathlib.Path(__file__).resolve().parents[1]
NAME = 'dalo-upgrade-' + uuid.uuid4().hex[:8]
DB, WEB = NAME + '-db', NAME + '-web'
VOLUME = NAME + '-data'
OLD = os.environ.get('DALORADIUS_OLD_IMAGE', 'lirantal/daloradius')
NEW = os.environ.get('DALORADIUS_TEST_PHP_IMAGE', 'dalo-unified-upgrade:local')
SECRET = uuid.uuid4().hex

def run(args, **kwargs):
    return subprocess.run(args, text=True, capture_output=True, **kwargs)

def checked(args, **kwargs):
    result = run(args, **kwargs)
    if result.returncode:
        raise RuntimeError(result.stderr or result.stdout)
    return result.stdout

def sql(text):
    return checked(['docker', 'exec', '-i', DB, 'mariadb', '-uroot', '-N', '-B', 'fixture'], input=text)

def start_web(image):
    checked(['docker', 'run', '-d', '--name', WEB, '--network', NAME,
        '-p', '127.0.0.1::8000', '-v', VOLUME + ':/data',
        '-e', 'MYSQL_HOST=' + DB, '-e', 'MYSQL_DATABASE=fixture',
        '-e', 'MYSQL_USER=fixture', '-e', 'MYSQL_PASSWORD=' + SECRET,
        '-e', 'DEFAULT_CLIENT_SECRET=' + SECRET, image])
    port = checked(['docker', 'port', WEB, '8000/tcp']).strip()
    url = 'http://' + port
    for _ in range(90):
        try:
            with urllib.request.urlopen(url + '/login.php', timeout=2) as response:
                if response.status == 200:
                    return url
        except Exception:
            pass
        if checked(['docker', 'inspect', '-f', '{{.State.Running}}', WEB]).strip() != 'true':
            logs = run(['docker', 'logs', WEB])
            raise AssertionError('Web entrypoint stopped: ' + (logs.stdout + logs.stderr).replace(SECRET, '[REDACTED]'))
        time.sleep(1)
    raise AssertionError('Web did not become ready')

def auth(url):
    jar = http.cookiejar.CookieJar()
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    page = client.open(url + '/login.php').read().decode()
    token = re.search(r'name="csrf_token"[^>]*value="([^"]+)"', page).group(1)
    body = urllib.parse.urlencode({'operator_user':'administrator','operator_pass':'radius','csrf_token':token,'location':'default'}).encode()
    response = client.open(url + '/dologin.php', body)
    html = response.read().decode()
    assert 'home-main.php' in response.url and 'Dashboard' in html

try:
    checked(['docker', 'network', 'create', NAME])
    checked(['docker', 'volume', 'create', VOLUME])
    checked(['docker', 'run', '-d', '--name', DB, '--network', NAME,
        '-e', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1', '-e', 'MARIADB_DATABASE=fixture',
        '-e', 'MARIADB_USER=fixture', '-e', 'MARIADB_PASSWORD=' + SECRET, 'mariadb:11.8'])
    for _ in range(90):
        if run(['docker', 'exec', DB, 'mariadb', '-h127.0.0.1', '-uroot', '-e', 'SELECT 1']).returncode == 0: break
        time.sleep(1)
    else: raise AssertionError('DB not ready')
    # FreeRADIUS owns these base tables in the real Compose deployment.
    radius_schema = checked(['docker', 'run', '--rm', '--entrypoint', 'cat', 'lirantal/dalofreeradius', '/etc/raddb/mods-config/sql/main/mysql/schema.sql'])
    sql(radius_schema)
    old_url = start_web(OLD)
    auth(old_url)
    sql("INSERT INTO radcheck(username,attribute,op,value) VALUES('fixture-kept','Cleartext-Password',':=','fixture-only'); INSERT INTO userinfo(username,firstname) VALUES('fixture-kept','Preserved');")
    sql("UPDATE operators SET auth_source='ldap',password=NULL WHERE username='administrator';")
    def projection():
        return sql("SELECT id,username,attribute,op,value FROM radcheck ORDER BY id; SELECT id,username,firstname,portalloginpassword FROM userinfo ORDER BY id; SELECT id,username,auth_source,password FROM operators ORDER BY id;")
    before = projection()
    def migrate_only():
        return run(['docker', 'run', '--rm', '--network', NAME,
            '-v', VOLUME + ':/data', '-e', 'MYSQL_HOST=' + DB,
            '-e', 'MYSQL_DATABASE=fixture', '-e', 'MYSQL_USER=fixture',
            '-e', 'MYSQL_PASSWORD=' + SECRET, '-e', 'DEFAULT_CLIENT_SECRET=' + SECRET,
            NEW, '/bin/bash', '/var/www/daloradius/init.sh', '--migrate-only'])
    # Older container had feature-specific migrations but no shared history.
    sql('DROP TABLE IF EXISTS daloradius_schema_migrations;')
    checked(['docker', 'rm', '-f', WEB])
    result = migrate_only()
    assert result.returncode == 0 and 'application services were not started' in result.stdout, (result.stdout + result.stderr).replace(SECRET, '[REDACTED]')
    assert projection() == before
    print('PASS Docker migration-only entrypoint / stopped writers / preserved data', flush=True)
    new_url = start_web(NEW)
    assert projection() == before
    assert sql("SELECT COUNT(*) FROM daloradius_schema_migrations WHERE status='applied';").strip() == '7'
    print('PASS Docker existing-data upgrade / all migrations / LDAP NULL+account data preserved', flush=True)
    assert checked(['docker', 'exec', WEB, 'php', '/var/www/daloradius/contrib/scripts/maintenance/migrate-db.php', '--apply']).count('SKIP ') == 7
    checked(['docker', 'restart', WEB])
    new_url = 'http://' + checked(['docker', 'port', WEB, '8000/tcp']).strip()
    for _ in range(45):
        try:
            if urllib.request.urlopen(new_url + '/login.php', timeout=2).status == 200: break
        except Exception: pass
        time.sleep(1)
    else:
        logs = run(['docker', 'logs', WEB])
        raise AssertionError('Restart HTTP not ready: ' + (logs.stdout + logs.stderr).replace(SECRET, '[REDACTED]'))
    assert projection() == before
    print('PASS Docker repeated boot / history skips / exact retained data', flush=True)
    # History failure must make the maintenance entrypoint fail closed too.
    checksum = sql("SELECT checksum FROM daloradius_schema_migrations WHERE filename='2026-09-operator-ldap.sql';").strip()
    sql("UPDATE daloradius_schema_migrations SET checksum=REPEAT('0',64) WHERE filename='2026-09-operator-ldap.sql';")
    result = migrate_only()
    assert result.returncode != 0 and 'history differs' in result.stderr
    assert 'application services were not started' not in result.stdout
    sql("UPDATE daloradius_schema_migrations SET checksum='" + checksum + "' WHERE filename='2026-09-operator-ldap.sql';")
    print('PASS Docker migration-only checksum mismatch / nonzero failure', flush=True)
    # Re-enable fixture local account for the real authentication probe.
    sql("UPDATE operators SET auth_source='local',password='radius' WHERE username='administrator';")
    auth(new_url)
    print('PASS Docker upgraded operator HTTP login', flush=True)
    checked(['docker', 'rm', '-f', WEB])
    # Fresh candidate startup must import complete schemas then record migration history.
    sql('DROP DATABASE fixture; CREATE DATABASE fixture;')
    sql(radius_schema)
    checked(['docker', 'volume', 'rm', VOLUME])
    checked(['docker', 'volume', 'create', VOLUME])
    new_url = start_web(NEW)
    assert sql("SELECT COUNT(*) FROM daloradius_schema_migrations WHERE status='applied';").strip() == '7'
    auth(new_url)
    print('PASS Docker fresh initialization / migration ledger / operator HTTP login', flush=True)
finally:
    for container in (WEB, DB): run(['docker', 'rm', '-f', container])
    run(['docker', 'volume', 'rm', VOLUME])
    run(['docker', 'network', 'rm', NAME])
    assert all(run(['docker', 'inspect', x]).returncode != 0 for x in (WEB, DB))
    assert run(['docker', 'volume', 'inspect', VOLUME]).returncode != 0
    assert run(['docker', 'network', 'inspect', NAME]).returncode != 0
    print('Docker fixture containers, volume and network removed', flush=True)
