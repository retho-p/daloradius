# Upgrading daloRADIUS

Use the path matching how daloRADIUS was installed. All paths use the same
MariaDB migration runner and the same `daloradius_schema_migrations` history.
Repeated runs skip recorded SQL; no separate manual SQL loop is needed.

The [legacy manual guidance](../../README.md#legacymanual-procedure) and
[legacy Docker SQL examples](../../README.docker.md#legacymanual-sql-migrations)
remain available for older releases. For a version containing this runner, use
one of the paths below; do not combine both procedures in the same upgrade.

| Installation | Application update | Database update |
| --- | --- | --- |
| Standard Debian installation using `setup/install.sh` | `setup/upgrade.sh` | Included |
| Manual Debian or AlmaLinux installation | Follow the distribution upgrade procedure | `migrate-db.php --apply` |
| Docker Compose | Rebuild/recreate the selected application images | Web entrypoint runs `migrate-db.php --apply` |

The upgrader changes application files, merges configuration defaults and
applies bundled schema migrations. It does **not** upgrade Debian packages,
MariaDB, PHP or FreeRADIUS, convert MyISAM tables, replace Apache/FreeRADIUS
configuration, hash existing portal passwords or reconcile custom tables.
Review [PDO prerequisites](pdo-database-upgrade.md) when crossing the PDO update.
The existing `mysql`/`mysqli` database-engine setting can be retained.

## Debian installation made with install.sh

Run as root. Use a reviewed copy of `setup/upgrade.sh` from the version containing
this helper if the installed checkout predates it. Keep it outside the application
checkout until deployment; adding an untracked script inside the checkout makes
the clean-tree check fail. Do not run `install.sh` again to perform an upgrade.

```sh
# From a trusted checkout containing the helper:
sudo bash setup/upgrade.sh --check --ref origin/master
# Replace COMMIT_PRINTED_BY_CHECK with the full planned application revision
# printed by --check, so a moving branch cannot change the reviewed target.
sudo bash setup/upgrade.sh --apply --ref COMMIT_PRINTED_BY_CHECK

# An exact published release tag or commit ID can also be checked first.
sudo bash setup/upgrade.sh --check --ref REF
sudo bash setup/upgrade.sh --apply --ref REF
```

A bare branch name such as `master` selects the fetched branch on `--remote`;
qualified refs such as `origin/master`, release tags and commit IDs also work.
The default is `origin/master`. `--root-dir` overrides `/var/www/daloradius`;
`--remote` overrides `origin`; `--backup-dir` overrides
`/root/daloradius-backups`. There is no need to enter the database password:
connection settings come from the installed `daloradius.conf.php`.
An optional private root-owned `--db-config` supplies backup credentials and
client options, for example a separate account with dump privileges. The
application configuration always determines the host, port, transport, socket
and database; connection settings in the supplied file cannot redirect the
backup to another instance. For `localhost`, PHP CLI must have an absolute
`mysqli.default_socket`; other hosts use TCP. The migration runner still uses
the application's database account.

`--check` fetches Git refs and creates temporary staging/lock files, but does not
change deployed application files, stop services or write to the database.
It checks the target files and migration history before any planned upgrade;
it does not execute the SQL or prove future DDL privileges. When `--ref` is a
branch, each invocation fetches its current tip. Use the full commit printed by
`--check` for `--apply` when you need to deploy exactly the reviewed revision.
The supported checkout must be clean, and the selected revision must be a
**fast-forward** of the installed one. Downgrades, divergent branches and local
tracked/untracked changes are refused rather than discarded. Review and save
local modifications separately; do not reset them blindly to bypass the check.

**Plan a maintenance window.** Web portals and RADIUS authentication/accounting
are unavailable while their services are stopped, including the time needed to
create the backup. Large application archives or databases can extend this window.

`--apply` performs the following:

1. Resolve and stage the exact fetched target commit, under a host upgrade lock.
2. Check PHP mysqli/PDO MySQL, the database and migration history.
3. Stop Apache and FreeRADIUS if they were running.
4. Save a private file archive, database dump, application configuration,
   source/target revisions and SHA-256 manifest.
5. Run migrations from the **selected target**, using the common runner.
6. Fast-forward the checkout and merge new sample defaults while retaining local
   `configValues`, ownership, permissions and POSIX ACLs. Load the staged
   configuration as `www-data` before replacing the installed file, then check
   access again after replacement.
7. Validate Apache/FreeRADIUS configuration, restore their previous active state
   and recheck application-configuration access. Check an authenticated web route
   and your normal RADIUS probes after the helper completes; active services alone
   do not certify every application workflow.

Stop any additional scheduled/custom writers yourself. Named database locations
are not discovered automatically: migrate each separately. Backups are kept in a
unique directory printed by the script. A successful checksum/read-back verifies
file integrity, not that an arbitrary production backup is restorable; rehearse
restoration separately by restoring the generated dump into a separate database
and comparing its contents.

### Failure recovery

An error before SQL starts restores stopped services. After SQL starts, failures
restore changed application/configuration files where possible but **do not
restore the database automatically**. Apache/FreeRADIUS are left stopped because
DDL can have committed. Inspect the backup and migration history, then restore
or repair the database deliberately before starting the services:

```sh
systemctl start freeradius apache2
```

Do not issue that command until recovery is verified. The files archive excludes
`.git`; the recorded source revision is retained in the existing Git checkout.
See [migration recovery](../../contrib/db/migrations/README.md#failure-and-recovery)
for interrupted SQL. The migration runner adopts the previous Bash helper's
`sha256`/full-path ledger only after validating every recorded file and checksum.
No force/baseline switch silently declares unknown SQL complete.

## Manual Debian and AlmaLinux installations

Continue using the normal distribution upgrade procedure for backups, stopping
writers, updating the selected application revision and aligning configuration.
Replace the former `for ... migrations/*.sql` loop with the commands below,
after updating application files and before restarting services.

Debian:

```sh
cd /var/www/daloradius
sudo -u www-data php contrib/scripts/maintenance/migrate-db.php
sudo -u www-data php contrib/scripts/maintenance/migrate-db.php --apply
```

AlmaLinux:

```sh
cd /var/www/daloradius
sudo -u apache php contrib/scripts/maintenance/migrate-db.php
sudo -u apache php contrib/scripts/maintenance/migrate-db.php --apply
```

Use PHP CLI with `mysqli` (`php-mysql` on Debian, `php-mysqlnd` on AlmaLinux).
The selected account must read the application configuration; its database account
needs the DDL/DML privileges used by the SQL. Preserve appropriate SELinux labels
and the distribution's existing HTTP/PHP configuration. Debian service names are
`apache2`/`freeradius`; AlmaLinux names are `httpd`/`radiusd`. `upgrade.sh` is
intentionally Debian-only; the PHP schema runner is independent of systemd and
the HTTP user name.

If migrations were already applied manually, the first preview can show
`PENDING`: it means *unrecorded*, not *missing*. The currently bundled SQL has
been tested on already-updated schemas, including LDAP NULL passwords; the
first application processes and records it once. Subsequent calls skip it.
See [runner usage and limits](../../contrib/db/migrations/README.md).

## Docker Compose installations

Do not use `upgrade.sh` inside Docker. Retain `.env`, database storage, `/data`
and the FreeRADIUS logs volume. Do not delete data or use `down --volumes` as
an upgrade command. Back up the database with a private client option file and
verify restoration; also preserve application/FreeRADIUS data and configuration.

For the repository's source-build Compose deployment, stop **both** writers
before deploying the reviewed selected Git revision:

```sh
docker compose stop radius-web radius
# Update the checkout to the chosen compatible release/branch here.
docker compose build radius-web radius
docker compose up -d radius-mysql
# Wait for the database to be healthy, then migrate while both writers are stopped.
docker compose run --rm --no-deps radius-web \
  /bin/bash /var/www/daloradius/init.sh --migrate-only
docker compose up -d radius radius-web
docker compose ps
docker compose logs --tail=100 radius-web
```

Starting the web image initializes its configuration and runs the common runner
before Apache starts. A migration failure stops startup; inspect logs/history and
recover before retrying (a restart policy can otherwise repeat failing startup).
The database-scoped lock coordinates concurrent runners, not external SQL or
FreeRADIUS writers. The `--migrate-only` one-off command above initializes the current image's
configuration from the normal environment and applies migrations **without
starting Apache or cron**, while both application writers remain stopped. It
requires a running database. A first-ever installation uses ordinary Compose
startup. The web entrypoint later skips the migrations recorded by this command. Do not edit historical SQL to make a
checksum mismatch disappear.
