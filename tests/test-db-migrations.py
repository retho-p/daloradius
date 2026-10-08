#!/usr/bin/env python3
"""Real MariaDB/PHP runner regression tests in disposable Docker fixtures."""
import os
import pathlib
import subprocess
import tempfile
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[1]
name = 'dalo-migrations-' + uuid.uuid4().hex[:10]
php_image = os.environ.get('DALORADIUS_TEST_PHP_IMAGE', 'lirantal/daloradius')

def run(args, **kwargs):
    return subprocess.run(args, text=True, capture_output=True, **kwargs)

def checked(args, **kwargs):
    result = run(args, **kwargs)
    if result.returncode:
        raise RuntimeError(result.stderr or result.stdout)
    return result.stdout

def sql(text):
    return checked(['docker', 'exec', '-i', name, 'mariadb', '-uroot', '-N', '-B', 'fixture'], input=text)

try:
    checked(['docker', 'network', 'create', '--internal', name])
    checked(['docker', 'run', '-d', '--name', name, '--network', name,
             '-e', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1', '-e', 'MARIADB_DATABASE=fixture', 'mariadb:11.8'])
    for attempt in range(90):
        if run(['docker', 'exec', name, 'mariadb', '-h127.0.0.1', '-uroot', '-e', 'SELECT 1']).returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('MariaDB did not become ready')
    with tempfile.TemporaryDirectory(prefix=name + '-') as tmp:
        config = pathlib.Path(tmp) / 'config.php'
        config.write_text("<?php $configValues = ['CONFIG_DB_ENGINE'=>'mysqli', 'CONFIG_DB_HOST'=>'" + name + "', 'CONFIG_DB_USER'=>'root', 'CONFIG_DB_PASS'=>'', 'CONFIG_DB_NAME'=>'fixture'];")
        def invoke(*args):
            return run(['docker', 'run', '--rm', '--network', name, '-v', str(ROOT) + ':/source:ro',
                        '-v', str(config) + ':/config.php:ro', '--entrypoint', 'php', php_image,
                        '/source/contrib/scripts/maintenance/migrate-db.php', '--config=/config.php', *args])
        sql("""CREATE TABLE operators (id INT PRIMARY KEY, username VARCHAR(128), password VARCHAR(32) NOT NULL);
CREATE TABLE operators_acl_files (file VARCHAR(128), category VARCHAR(128), section VARCHAR(128));
CREATE TABLE operators_acl (operator_id INT, file VARCHAR(128), access INT);
CREATE TABLE userinfo (id INT PRIMARY KEY, portalloginpassword VARCHAR(32));
INSERT INTO operators VALUES(1,'administrator','legacy-value'),(2,'test-operator','keep-me');
INSERT INTO userinfo VALUES(1,'keep-portal');
INSERT INTO operators_acl_files VALUES('rep_username','Reports','Users');
INSERT INTO operators_acl VALUES(1,'rep_username',1);
""")
        before = sql('SHOW TABLES;')
        result = invoke()
        assert result.returncode == 0 and result.stdout.count('PENDING ') == 7, result.stderr
        assert sql('SHOW TABLES;') == before
        print('PASS read-only preview / seven pending migrations')
        result = invoke('--apply')
        assert result.returncode == 0 and result.stdout.count('APPLIED ') == 7, result.stderr
        assert sql("SELECT COUNT(*) FROM daloradius_schema_migrations WHERE status='applied';").strip() == '7'
        assert sql('SELECT password FROM operators ORDER BY id;').splitlines() == ['legacy-value', 'keep-me']
        assert sql('SELECT portalloginpassword FROM userinfo;').strip() == 'keep-portal'
        assert sql("SELECT COUNT(*) FROM operators_acl WHERE file='rep_username';").strip() == '0'
        assert sql("SELECT access FROM operators_acl WHERE operator_id=2 ORDER BY file;").splitlines() == ['0', '0', '0']
        print('PASS legacy upgrade / history / preserved data / ACLs')
        result = invoke('--apply')
        assert result.returncode == 0 and result.stdout.count('SKIP ') == 7, result.stderr
        print('PASS repeat run skips completed migrations')
        # Fresh schema with an LDAP NULL password must not regress to NOT NULL.
        sql('DROP DATABASE fixture; CREATE DATABASE fixture;')
        sql((ROOT / 'contrib/db/fr3-mariadb-freeradius.sql').read_text())
        sql((ROOT / 'contrib/db/mariadb-daloradius.sql').read_text())
        sql("UPDATE operators SET auth_source='ldap',password=NULL WHERE id=(SELECT minid FROM (SELECT MIN(id) minid FROM operators) x);")
        result = invoke('--apply')
        assert result.returncode == 0 and 'SATISFIED 2025-03' in result.stdout, result.stderr
        assert int(sql('SELECT COUNT(*) FROM operators WHERE password IS NULL;').strip()) >= 1
        print('PASS current schema / LDAP NULL preserved')
        sql("UPDATE daloradius_schema_migrations SET checksum=REPEAT('0',64) WHERE filename='2026-09-operator-ldap.sql';")
        result = invoke('--apply')
        assert result.returncode != 0 and 'history differs' in result.stderr
        print('PASS checksum drift rejected')
        sql("UPDATE daloradius_schema_migrations SET status='running' WHERE filename='2026-09-operator-ldap.sql';")
        result = invoke()
        assert result.returncode != 0
        print('PASS interrupted history rejected')
        # Native late SQL error: first statement commits, second fails.
        sql('DROP DATABASE fixture; CREATE DATABASE fixture; USE fixture; CREATE TABLE operators(id INT, password VARCHAR(32));')
        result = invoke('--apply')
        assert result.returncode != 0
        assert sql("SELECT status FROM daloradius_schema_migrations WHERE filename='2026-06-operator-totp-mfa.sql';").strip() == 'running'
        assert sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='fixture' AND TABLE_NAME='operators' AND COLUMN_NAME='totp_enabled';").strip() == '1'
        assert invoke('--apply').returncode != 0
        print('PASS late multi-statement error / partial DDL detected / retry blocked')
        assert invoke('--unknown').returncode == 2
        print('PASS invalid argument')
finally:
    run(['docker', 'rm', '-f', name])
    run(['docker', 'network', 'rm', name])
    assert run(['docker', 'inspect', name]).returncode != 0
    assert run(['docker', 'network', 'inspect', name]).returncode != 0
    print('Fixture container and network removed')
