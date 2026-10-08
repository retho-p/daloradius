#!/usr/bin/env python3
"""Run native PHP/MariaDB migration tests; requires local MariaDB root socket access.

sudo python3 tests/test-db-migrations-native.py
Only uniquely named disposable databases/accounts are created and removed.
"""
import os
import pathlib
import secrets
import subprocess
import tempfile
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[1]
NAME = 'dalo_migration_test_' + uuid.uuid4().hex[:12]
USER = 'dalo_mig_' + uuid.uuid4().hex[:12]
PASSWORD = secrets.token_hex(24)
SCRIPT = ROOT / 'contrib/scripts/maintenance/migrate-db.php'

def run(args, **kwargs):
    return subprocess.run(args, text=True, capture_output=True, **kwargs)

def sql(text, database=None):
    args = ['mariadb', '-uroot', '-N', '-B']
    if database:
        args.append(database)
    result = run(args, input=text)
    if result.returncode:
        # Do not include SQL input or errors that could carry credentials.
        raise RuntimeError('Fixture SQL failed, code ' + str(result.returncode))
    return result.stdout

def reset():
    sql('DROP DATABASE IF EXISTS `' + NAME + '`; CREATE DATABASE `' + NAME + '`;')

def snapshot():
    result = run(['mariadb-dump', '-uroot', '--skip-comments', '--compact', '--skip-extended-insert', NAME])
    assert result.returncode == 0
    return result.stdout

with tempfile.TemporaryDirectory(prefix=NAME + '-', dir=os.environ.get('TMPDIR', str(ROOT.parent))) as tmp:
    config = pathlib.Path(tmp) / 'config.php'
    def configure(user='root', password=''):
        config.write_text("<?php $configValues = ['CONFIG_DB_ENGINE'=>'mysqli','CONFIG_DB_HOST'=>'localhost','CONFIG_DB_USER'=>'" + user + "','CONFIG_DB_PASS'=>'" + password + "','CONFIG_DB_NAME'=>'" + NAME + "'];")
        config.chmod(0o600)
    configure()
    def invoke(*args):
        return run(['php', str(SCRIPT), '--config=' + str(config), *args])
    try:
        reset()
        sql("""CREATE TABLE operators (id INT PRIMARY KEY, username VARCHAR(128), password VARCHAR(32) NOT NULL);
CREATE TABLE operators_acl_files (file VARCHAR(128), category VARCHAR(128), section VARCHAR(128));
CREATE TABLE operators_acl (operator_id INT, file VARCHAR(128), access INT);
CREATE TABLE userinfo (id INT PRIMARY KEY, portalloginpassword VARCHAR(32));
INSERT INTO operators VALUES(1,'administrator','legacy-value'),(2,'test-operator','keep-me');
INSERT INTO userinfo VALUES(1,'keep-portal');
INSERT INTO operators_acl_files VALUES('rep_username','Reports','Users');
INSERT INTO operators_acl VALUES(1,'rep_username',1);
""", NAME)
        before = snapshot()
        result = invoke()
        assert result.returncode == 0 and result.stdout.count('PENDING ') == 7, result.stderr
        assert snapshot() == before
        print('PASS native read-only preview / exact schema and data unchanged', flush=True)
        # A real independent connection holds the same advisory lock.
        holder = subprocess.Popen(['mariadb', '-uroot', '-N', '-B', NAME], stdin=subprocess.PIPE,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        holder.stdin.write("SELECT GET_LOCK(CONCAT('dalo-migrate:',LEFT(SHA2(DATABASE(),256),40)),0); SELECT SLEEP(30);\n")
        holder.stdin.flush()
        connection_id = None
        try:
            for attempt in range(100):
                value = sql("SELECT IS_USED_LOCK(CONCAT('dalo-migrate:',LEFT(SHA2(DATABASE(),256),40)));", NAME).strip()
                if value != 'NULL':
                    connection_id = int(value)
                    break
                time.sleep(0.05)
            assert connection_id is not None
            result = invoke('--apply')
            assert result.returncode != 0 and 'holds the lock' in result.stderr, result.stderr
            assert snapshot() == before
            assert invoke().returncode == 0
            print('PASS native advisory-lock contention / no writes / preview still usable', flush=True)
        finally:
            if connection_id is not None:
                sql('KILL ' + str(connection_id) + ';')
            holder.communicate(timeout=10)
        # SELECT-only credentials can preview, but cannot apply.
        sql("CREATE USER '" + USER + "'@'localhost' IDENTIFIED BY '" + PASSWORD + "'; GRANT SELECT ON `" + NAME + "`.* TO '" + USER + "'@'localhost';")
        configure(USER, PASSWORD)
        assert invoke().returncode == 0
        result = invoke('--apply')
        assert result.returncode != 0 and PASSWORD not in result.stderr and PASSWORD not in result.stdout
        assert snapshot() == before
        configure()
        print('PASS SELECT-only preview / apply permission failure without changes', flush=True)
        result = invoke('--apply')
        assert result.returncode == 0 and result.stdout.count('APPLIED ') == 7, result.stderr
        assert sql("SELECT COUNT(*) FROM daloradius_schema_migrations WHERE status='applied';", NAME).strip() == '7'
        assert sql('SELECT password FROM operators ORDER BY id;', NAME).splitlines() == ['legacy-value', 'keep-me']
        assert sql('SELECT portalloginpassword FROM userinfo;', NAME).strip() == 'keep-portal'
        assert sql("SELECT COUNT(*) FROM operators_acl WHERE file='rep_username';", NAME).strip() == '0'
        assert sql("SELECT access FROM operators_acl WHERE operator_id=2 ORDER BY file;", NAME).splitlines() == ['0', '0', '0']
        print('PASS native legacy upgrade / data preservation / ACL changes', flush=True)
        before = snapshot()
        result = invoke('--apply')
        assert result.returncode == 0 and result.stdout.count('SKIP ') == 7, result.stderr
        assert snapshot() == before
        print('PASS repeat run / exact database unchanged', flush=True)
        reset()
        sql((ROOT / 'contrib/db/fr3-mariadb-freeradius.sql').read_text(), NAME)
        sql((ROOT / 'contrib/db/mariadb-daloradius.sql').read_text(), NAME)
        sql("UPDATE operators SET auth_source='ldap',password=NULL WHERE id=(SELECT minid FROM (SELECT MIN(id) minid FROM operators) x);", NAME)
        result = invoke('--apply')
        assert result.returncode == 0 and 'SATISFIED 2025-03' in result.stdout, result.stderr
        assert int(sql('SELECT COUNT(*) FROM operators WHERE password IS NULL;', NAME).strip()) >= 1
        print('PASS native current full schema / LDAP NULL preserved', flush=True)
        sql("UPDATE daloradius_schema_migrations SET checksum=REPEAT('0',64) WHERE filename='2026-09-operator-ldap.sql';", NAME)
        before = snapshot()
        result = invoke('--apply')
        assert result.returncode != 0 and 'history differs' in result.stderr
        assert snapshot() == before
        print('PASS history checksum drift rejected before writes', flush=True)
        reset()
        sql('CREATE TABLE operators(id INT, password VARCHAR(32));', NAME)
        before = snapshot()
        result = invoke('--apply')
        assert result.returncode != 0 and 'No migrations started' in result.stderr
        assert snapshot() == before
        assert sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='daloradius_schema_migrations';", NAME).strip() == '0'
        print('PASS incomplete schema rejected before DDL or history writes', flush=True)
        sql("""ALTER TABLE operators ADD username VARCHAR(128);
CREATE TABLE operators_acl_files(file VARCHAR(128), category VARCHAR(128), section VARCHAR(128));
CREATE TABLE operators_acl(operator_id INT, file VARCHAR(128), access INT);
CREATE TABLE userinfo(portalloginpassword VARCHAR(32));
CREATE TRIGGER fail_mfa BEFORE INSERT ON operators_acl_files FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture late failure';
""", NAME)
        result = invoke('--apply')
        assert result.returncode != 0
        assert sql("SELECT status FROM daloradius_schema_migrations WHERE filename='2026-06-operator-totp-mfa.sql';", NAME).strip() == 'running'
        assert sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='operators' AND COLUMN_NAME='totp_enabled';", NAME).strip() == '1'
        before = snapshot()
        assert invoke('--apply').returncode != 0
        assert snapshot() == before
        print('PASS native late SQL error / partial DDL / blind retry blocked', flush=True)
        # Repair exactly the failed migration, then confirm the manual history
        # recovery documented for administrators permits the remaining upgrade.
        sql('DROP TRIGGER fail_mfa;', NAME)
        sql((ROOT / 'contrib/db/migrations/2026-06-operator-totp-mfa.sql').read_text(), NAME)
        sql("UPDATE daloradius_schema_migrations SET status='applied',applied_at=NOW() WHERE filename='2026-06-operator-totp-mfa.sql';", NAME)
        result = invoke('--apply')
        assert result.returncode == 0 and result.stdout.count('SKIP ') == 2 and result.stdout.count('APPLIED ') == 5, result.stderr
        assert sql("SELECT COUNT(*) FROM daloradius_schema_migrations WHERE status='applied';", NAME).strip() == '7'
        print('PASS explicit manual repair / verified history / remaining migrations', flush=True)
        result = invoke('--unknown')
        assert result.returncode == 2
        print('PASS invalid argument', flush=True)
    finally:
        sql('DROP DATABASE IF EXISTS `' + NAME + '`;')
        sql("DROP USER IF EXISTS '" + USER + "'@'localhost';")
        assert sql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" + NAME + "';").strip() == '0'
        assert sql("SELECT COUNT(*) FROM mysql.user WHERE User='" + USER + "';").strip() == '0'
        print('Native fixture database and account removed', flush=True)
