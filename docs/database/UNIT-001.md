# UNIT-001 — PDO connection, error handling and lifecycle

Scope: `DB-COMMON-004`, `DB-INFRA-001`, `DB-INFRA-002` in the migration inventory. `DB-INFRA-002` was identified as a static-inventory false positive; there is no SQL block to migrate for it.

The PDO factory is **opt-in**. Existing PEAR callers continue using `$dbSocket` unchanged; migrated blocks explicitly request a separate PDO connection:

```php
include __DIR__ . '/db_open.php';
$pdo = dalo_pdo_connect($configValues, $_SESSION['location_name'] ?? 'default');
$stmt = $pdo->prepare('SELECT username FROM radcheck WHERE username = ?');
$stmt->execute([$username]);
// ...
include __DIR__ . '/db_close.php';
```

Use `$pdo` consistently inside a transaction. **Never** execute part of a transaction via `$dbSocket` and another part via `$pdo`: they are independent connections and have no shared commit/rollback. PEAR stays installed until all dependent blocks have migrated. `db_close.php` releases a local `$pdo` variable, if any, and preserves the existing PEAR disconnect; a returned PDO handle held elsewhere remains alive until its last reference is released.

Connection mapping: the default `CONFIG_DB_*` values or all six values of the selected `CONFIG_LOCATIONS` entry are passed separately to PDO. A missing/incomplete named location fails closed. MySQL (`mysql`/`mysqli`) uses `pdo_mysql`, explicit `utf8mb4`, native prepares, exceptions, associative fetches, non-persistent connections and the legacy empty session `sql_mode`; PostgreSQL (`pgsql`) uses `pdo_pgsql` if installed. Legacy ODBC/MSSQL connection strings are **not silently mapped** to unsupported PDO drivers. Driver coverage and behavior for these engines need a separately scoped block before use. Values destined for a DSN are validated; passwords never enter the DSN. Exceptions from connection initialization and the portal password sensitive-call helper are redacted, without embedding the original PDOException as `previous`.

Checks for a candidate build:

```sh
php -l app/common/includes/pdo_connection.php
php tests/pdo-connection.test.php
php tests/portal-password.test.php
php tests/portal-password-storage.test.php
```

The standalone test has an optional `DALORADIUS_PDO_TEST_LIVE_CONFIG=/path/to/daloradius.conf.php` read-only database probe (`SELECT 1`, session mode, PDO attributes, invalid-credentials redaction). Do not put credentials in the command line. The configured MySQL/MariaDB test requires `pdo_mysql`. Compare PEAR and PDO results on two isolated databases seeded from the same dump **before** migrating business-query blocks; the live probe here tests connection wiring only, not application-level differential behavior.

Validation performed in the dev lab: PHP lint of the five touched PHP files, standalone PDO and portal password tests, PEAR and PDO read-only `SELECT 1` against the live dev database, session `sql_mode`, default charset/collation parity, and incorrect-credential redaction. No app code was deployed into the running service and no schema/data mutation was performed.
