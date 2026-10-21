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

**Backups and recovery belong to the administrator.** Before applying an
upgrade, use your normal operational tools to back up the database,
configuration and local application data, and verify that you can restore a
consistent installation. The upgrade helper and migration runner do not
create backups or perform automatic rollbacks. No backup account, destination
or storage policy is imposed by daloRADIUS.

The upgrader changes application files and applies bundled schema migrations.
It leaves the installed `daloradius.conf.php` untouched. It does **not** upgrade
Debian packages, MariaDB, PHP or FreeRADIUS, convert MyISAM tables, replace Apache/FreeRADIUS
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
`--remote` overrides `origin`. There is no need to enter the database password:
the shared migration runner uses the installed `daloradius.conf.php` and the
application's database account. The upgrade helper does not need the `mariadb`
or `mariadb-dump` command-line clients or a separate account with dump privileges.

**Keep your existing configuration.** The helper does not regenerate, merge or
append settings to `daloradius.conf.php`: comments, PHP expressions and include
statements remain intact, as do ownership, permissions and POSIX ACLs. Review the
release notes and compare the new `daloradius.conf.php.sample` with your local
configuration; add any required new settings yourself before applying the
upgrade. A configuration tracked by Git, or a target that would introduce a
tracked configuration file at that path, is refused before stopping services or
starting migrations.

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

**Plan a web maintenance window.** The helper stops only Apache (`apache2`),
if it was active, so the daloRADIUS web portals are unavailable during the
upgrade. It does not stop, start, restart or validate FreeRADIUS, and does not
require the FreeRADIUS binary or a local FreeRADIUS service. MariaDB is not
stopped either. Complete your own backup procedure before invoking `--apply`;
the helper only prints a reminder and does not check or attest that a backup exists.

`--apply` performs the following:

1. Resolve and stage the exact fetched target commit, under a host upgrade lock.
2. Check configuration access and PHP mysqli/PDO MySQL; when the target contains
   migrations, use the shared runner to check the database and migration history.
3. Stop Apache if it was running; leave FreeRADIUS untouched.
4. Run migrations from the **selected target**, using the common runner.
5. Fast-forward the checkout without changing the existing configuration file,
   then recheck that `www-data` can load it.
6. Validate Apache configuration, restore its previous active state
   and recheck application-configuration access. Check an authenticated web route
   and your normal RADIUS probes after the helper completes; active services alone
   do not certify every application workflow.

Review any scheduled/custom writers and migration-specific maintenance needs
yourself. If a migration affects tables used by your FreeRADIUS deployment,
coordinate the required RADIUS maintenance separately; the helper never manages
that service. Named database locations are not discovered automatically:
migrate each separately.

### Failure recovery

If a failure occurs before the application/schema update starts, Apache is
returned to its previous active state if the helper stopped it. Once the
update starts, a failure leaves Apache stopped: SQL DDL may already
have committed, and application files may already have changed.
**There is no automatic Git reset, configuration restore or database rollback.**
The helper prints the source and planned target revisions. Inspect the actual
checkout, configuration and migration history; repair the failed step or restore
a coherent application/database state using your own backups before starting
Apache. FreeRADIUS is left untouched, including on failure:

```sh
systemctl start apache2
```

Do not issue that command until recovery is verified. Do not downgrade only the
application code after a schema change or blindly retry interrupted SQL.
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
an upgrade command. Back up the database and application/FreeRADIUS data and
configuration using your normal operational tools, and verify restoration.

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
