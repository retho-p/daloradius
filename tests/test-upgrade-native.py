#!/usr/bin/env python3
"""Exercise upgrade.sh on native Debian services with disposable app/DB fixtures.
Requires root, local MariaDB root socket and an existing Debian install.
Stops/restores Apache and FreeRADIUS briefly; never changes their configs/DB.
"""
import os
import pathlib
import shutil
import subprocess
import tempfile
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[1]
NAME = 'dalo_upgrade_' + uuid.uuid4().hex[:8]
APP = pathlib.Path('/var/www') / NAME
BACKUP = pathlib.Path('/root') / (NAME + '_backups')
REMOTE = pathlib.Path('/var/www') / (NAME + '_remote.git')
SCRIPT = ROOT / 'setup/upgrade.sh'

def run(args, **kw):
    return subprocess.run([str(x) for x in args], text=True, capture_output=True, **kw)

def checked(args, **kw):
    r = run(args, **kw)
    if r.returncode:
        raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

def sql(query, db=None):
    return checked(['mariadb', '-N', '-B', *([db] if db else [])], input=query)

def service_state():
    return tuple(run(['systemctl', 'is-active', s]).stdout.strip() for s in ('apache2', 'freeradius'))

def snapshot():
    return checked(['mariadb-dump', '--skip-comments', '--skip-dump-date', '--order-by-primary', NAME])

def invoke(*args):
    return run(['bash', SCRIPT, '--root-dir', APP, '--backup-dir', BACKUP, '--ref', 'candidate', *args])

def baseline():
    checked(['git', '-C', APP, 'reset', '--hard', BASE])
    sql('DROP DATABASE IF EXISTS `' + NAME + '`; CREATE DATABASE `' + NAME + '`;')
    sql((ROOT / 'contrib/db/fr3-mariadb-freeradius.sql').read_text(), NAME)
    sql((ROOT / 'contrib/db/mariadb-daloradius.sql').read_text(), NAME)

before_services = service_state()
assert before_services == ('active', 'active'), before_services
try:
    # External bare remote makes fetch and branch-name selection genuine Git operations.
    checked(['git', 'clone', '--bare', ROOT, REMOTE])
    TARGET = checked(['git', '-C', ROOT, 'rev-parse', 'HEAD']).strip()
    BASE = checked(['git', '-C', ROOT, 'merge-base', 'HEAD', 'upstream/master']).strip()
    checked(['git', '-C', REMOTE, 'update-ref', 'refs/heads/candidate', TARGET])
    checked(['git', 'clone', REMOTE, APP])
    checked(['git', '-C', APP, 'config', 'user.name', 'Upgrade fixture'])
    checked(['git', '-C', APP, 'config', 'user.email', 'fixture@example.invalid'])
    conf = APP / 'app/common/includes/daloradius.conf.php'
    conf.write_text("<?php $configValues = ['CONFIG_DB_ENGINE'=>'mysqli','CONFIG_DB_HOST'=>'localhost','CONFIG_DB_PORT'=>3306,'CONFIG_DB_USER'=>'" + NAME + "','CONFIG_DB_PASS'=>'','CONFIG_DB_NAME'=>'" + NAME + "','FIXTURE_KEEP'=>'unchanged'];\n")
    shutil.chown(conf, 'www-data', 'www-data')
    conf.chmod(0o600)
    sql("CREATE USER '" + NAME + "'@'localhost' IDENTIFIED BY ''; GRANT ALL ON `" + NAME + "`.* TO '" + NAME + "'@'localhost';")
    baseline()
    initial = snapshot()
    config_initial = conf.read_bytes()
    r = invoke('--check')
    assert r.returncode == 0, r.stderr + r.stdout
    assert snapshot() == initial and conf.read_bytes() == config_initial
    assert checked(['git', '-C', APP, 'rev-parse', 'HEAD']).strip() == BASE
    assert service_state() == before_services and not BACKUP.exists()
    print('PASS native upgrade preview / DB+files+services unchanged', flush=True)
    dirty = APP / 'fixture-dirty.txt'
    dirty.write_text('keep')
    r = invoke('--apply')
    assert r.returncode != 0 and 'not clean' in r.stderr
    assert dirty.read_text() == 'keep' and snapshot() == initial
    dirty.unlink()
    print('PASS dirty checkout refused without changes', flush=True)
    lock = subprocess.Popen(['flock', '/run/lock/daloradius-upgrade.lock', 'sleep', '60'])
    import time
    time.sleep(0.2)
    try:
        r = invoke('--check')
        assert r.returncode != 0 and 'already running' in r.stderr
    finally:
        lock.terminate(); lock.wait()
    print('PASS native host upgrade-lock contention', flush=True)
    r = invoke('--apply')
    assert r.returncode == 0, r.stderr + r.stdout
    assert checked(['git', '-C', APP, 'rev-parse', 'HEAD']).strip() == TARGET
    assert service_state() == before_services
    assert sql("SELECT COUNT(*) FROM daloradius_schema_migrations WHERE status='applied';", NAME).strip() == '7'
    assert checked(['php', '-r', 'require $argv[1]; exit($configValues["FIXTURE_KEEP"] === "unchanged" && isset($configValues["CONFIG_DB_TBL_RADACCT"]) ? 0 : 1);', conf]) == ''
    assert conf.stat().st_mode & 0o777 == 0o600
    backup = next(BACKUP.iterdir())
    checked(['sha256sum', '-c', 'SHA256SUMS'], cwd=backup)
    restore = NAME + '_restore'
    sql('CREATE DATABASE `' + restore + '`;')
    try:
        sql((backup / 'database.sql').read_text(), restore)
        restored = checked(['mariadb-dump', '--skip-comments', '--skip-dump-date', '--order-by-primary', restore])
        assert restored == initial
    finally:
        sql('DROP DATABASE `' + restore + '`;')
    print('PASS native FF upgrade / config values+mode / services / backup actually restored', flush=True)
    upgraded = snapshot()
    r = invoke('--apply')
    assert r.returncode == 0 and snapshot() == upgraded, r.stderr
    print('PASS repeated upgrade / exact DB unchanged', flush=True)
    r = run(['bash', SCRIPT, '--root-dir', APP, '--backup-dir', BACKUP, '--ref', BASE, '--apply'])
    assert r.returncode != 0 and 'not a fast-forward' in r.stderr
    assert snapshot() == upgraded and service_state() == before_services
    print('PASS downgrade refused before stopping writers', flush=True)
    # Late DDL error through the same native runner and real service control.
    baseline()
    sql("DELETE FROM operators_acl_files WHERE file='config_operator_mfa'; CREATE TRIGGER fixture_fail BEFORE INSERT ON operators_acl_files FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure';", NAME)
    r = invoke('--apply')
    assert r.returncode != 0, r.stdout
    assert service_state() == ('inactive', 'inactive')
    assert checked(['git', '-C', APP, 'rev-parse', 'HEAD']).strip() == BASE
    assert sql("SELECT status FROM daloradius_schema_migrations WHERE filename='2026-06-operator-totp-mfa.sql';", NAME).strip() == 'running'
    interrupted = snapshot()
    r = invoke('--apply')
    assert r.returncode != 0 and snapshot() == interrupted
    print('PASS late SQL failure / writers stopped / code untouched / blind retry refused', flush=True)
finally:
    for s, status in zip(('apache2', 'freeradius'), before_services):
        checked(['systemctl', 'start' if status == 'active' else 'stop', s])
    sql('DROP DATABASE IF EXISTS `' + NAME + '`; DROP USER IF EXISTS \'' + NAME + "'@'localhost';")
    for p in (APP, REMOTE, BACKUP):
        if p.exists(): shutil.rmtree(p)
    assert service_state() == before_services
    assert all(not p.exists() for p in (APP, REMOTE, BACKUP))
    print('Native upgrade fixtures removed; original services restored', flush=True)
