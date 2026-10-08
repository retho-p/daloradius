# Applying bundled database migrations

From the root of the **updated** daloRADIUS checkout:

```sh
php contrib/scripts/maintenance/migrate-db.php
# Stop application writes and make/verify a database backup before continuing.
php contrib/scripts/maintenance/migrate-db.php --apply
```

The default command is a read-only preview. `--apply` executes pending `*.sql`
files from this directory in filename order. It uses the default database from
`app/common/includes/daloradius.conf.php`; another trusted PHP configuration can
be selected with `--config=/absolute/path/to/daloradius.conf.php`. Configuration
files are executable PHP: never use an untrusted file. No password is passed on
the command line. Run as an account allowed to read the application configuration.

Requirements: PHP CLI with mysqli, MariaDB, standard table names and database
privileges required by the bundled SQL (SELECT/INSERT/UPDATE/DELETE/CREATE/ALTER
and indexes). Before writing, the runner checks that the required standard
application tables and key columns exist; a missing table/column is refused
without creating history or starting DDL. This does not predict all later SQL
failures. These migrations are **not MySQL/PostgreSQL-compatible**. Named
locations are not selected automatically: migrate each database using its own
configuration. Keep this script and SQL files from the same checkout.

The runner creates `daloradius_schema_migrations` in the selected database,
records each filename and SHA-256 checksum, and skips successful migrations on
later runs. A database-scoped advisory lock prevents two runners executing at
once. It does not block application writers or manual SQL: use a maintenance
window. Dry runs do not create a history table or validate future DDL privileges.

Existing installations without history are processed once using the bundled
idempotent SQL. The old operator-password widening migration is considered
satisfied if the VARCHAR already has capacity 95 or greater, preserving LDAP
NULL passwords. Existing password values are not hashed by this runner. Portal
password conversion remains a separate maintenance task.

## Failure and recovery

**MariaDB DDL commits implicitly: this is not an atomic upgrade or a rollback
tool.** Each migration is marked `running` before execution and `applied` only
after all its statements succeed. A failure or interruption can leave partially
applied SQL. The next invocation refuses to proceed if history is unfinished,
a tracked checksum changed, or a tracked file disappeared.

Do not delete history and rerun blindly. Inspect the named SQL file, current
schema/data and the history row using an administrator connection. Restore the
verified backup or complete/repair the interrupted SQL manually. Only after
verifying that the entire file's intended changes are present should an
administrator set its history `status` to `applied` and `applied_at` appropriately.
The runner intentionally has no force/retry/automatic-baseline flag. It reports
SQL error codes rather than driver messages that might disclose data.

The runner executes trusted repository SQL, not user-supplied SQL. New migrations
must be reviewed for safe ordering and their own idempotence. Files using mysql
client commands such as `DELIMITER`, `SOURCE` or `USE` are not supported.

## Regression tests

```sh
python3 tests/test-db-migrations.py
```

This runs PHP/mysqli and MariaDB 11.8 in disposable Docker fixtures, without
using the installed application's database. Override `DALORADIUS_TEST_PHP_IMAGE`
if needed with an image containing PHP CLI and mysqli. No ports are published.
It verifies preview, upgrade, repeat execution, data preservation, current
schema with LDAP NULL passwords, history checks, and a real late SQL failure.

For a native Debian/MariaDB installation, an additional suite runs without
Docker (requires local MariaDB root socket access and PHP CLI/mysqli):

```sh
sudo python3 tests/test-db-migrations-native.py
```

It creates and removes uniquely named test databases and a SELECT-only test
account. It does not use the application's database. This suite also exercises
real advisory-lock contention, permission failures, incomplete-schema preflight,
and explicit manual recovery after a partial migration. Schema checks reduce
obvious failures but do not make DDL atomic.
