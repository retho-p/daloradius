#!/usr/bin/env bash
# daloRADIUS - safe Debian upgrade helper
#
# This script intentionally upgrades only the daloRADIUS checkout and database.
# It does not upgrade the operating system or rewrite Apache configuration.

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

readonly SCRIPT_NAME="$(basename "$0")"
APP_ROOT="/var/www/daloradius"
DB_CONFIG="/root/.my.cnf"
REMOTE="origin"
TARGET_REF="origin/master"
BACKUP_ROOT="/root/daloradius-backups"
APPLY=false
REF_EXPLICIT=false
CHECK_EXPLICIT=false

BACKUP_DIR=""
CONFIG_FILE=""
LOCK_FILE="/run/lock/daloradius-upgrade.lock"
CONFIG_BACKUP=""
CURRENT_COMMIT=""
TARGET_COMMIT=""
DB_NAME=""
CODE_UPDATED=false
CONFIG_UPDATED=false
MIGRATIONS_STARTED=false
APACHE_RESTORE_REQUIRED=false
ROLLBACK_IN_PROGRESS=false
TMP_FILES=()

log() {
    printf '[%s] %s\n' "$SCRIPT_NAME" "$*"
}

warn() {
    printf '[%s] WARNING: %s\n' "$SCRIPT_NAME" "$*" >&2
}

fail() {
    printf '[%s] ERROR: %s\n' "$SCRIPT_NAME" "$*" >&2
    exit 1
}

usage() {
    cat <<'EOF'
Usage:
  setup/upgrade.sh --check [options]
  setup/upgrade.sh --apply [options]

Safely upgrade a standard Debian installation of daloRADIUS.

Options:
  --apply                 Apply the planned upgrade. Without this, no service,
                          application file, or database schema is changed.
  --root-dir PATH         daloRADIUS checkout (default: /var/www/daloradius).
  --db-config PATH        MariaDB client option file (default: /root/.my.cnf).
  --remote NAME           Git remote to fetch (default: origin).
  --ref REF               Git ref or commit to deploy (default: REMOTE/master).
  --backup-dir PATH       Backup root (default: /root/daloradius-backups).
  -h, --help              Show this help.

The MariaDB option file must contain a [client] section with credentials and
an explicit database= value. It must be owned by root and not readable by the
group or other users.

Examples:
  setup/upgrade.sh --check --db-config /root/daloradius.cnf --ref origin/master
  setup/upgrade.sh --apply --db-config /root/daloradius.cnf --ref v2.3
EOF
}

cleanup() {
    local file
    for file in "${TMP_FILES[@]}"; do
        rm -f -- "$file"
    done
}

rollback_changes() {
    [[ "$APPLY" == true ]] || return 0
    [[ "$ROLLBACK_IN_PROGRESS" == false ]] || return 0
    [[ "$CODE_UPDATED" == true || "$CONFIG_UPDATED" == true || \
       "$MIGRATIONS_STARTED" == true || "$APACHE_RESTORE_REQUIRED" == true ]] || return 0

    ROLLBACK_IN_PROGRESS=true
    trap - ERR INT TERM

    if [[ "$APACHE_RESTORE_REQUIRED" == true && \
          ( "$CODE_UPDATED" == true || "$CONFIG_UPDATED" == true ) ]]; then
        systemctl stop apache2 >/dev/null 2>&1 || \
            warn "Could not stop Apache before restoring application files."
    fi

    if [[ "$CODE_UPDATED" == true || "$CONFIG_UPDATED" == true ]]; then
        warn "The upgrade failed after changing files; restoring the previous application state."
    fi

    if [[ "$CONFIG_UPDATED" == true && -f "$CONFIG_BACKUP" ]]; then
        if cp -p -- "$CONFIG_BACKUP" "$CONFIG_FILE"; then
            log "Restored the previous daloRADIUS configuration."
        else
            warn "Could not restore $CONFIG_FILE; use the verified backup in $BACKUP_DIR."
        fi
    fi

    if [[ "$CODE_UPDATED" == true && -n "$CURRENT_COMMIT" ]]; then
        if git -C "$APP_ROOT" reset --hard "$CURRENT_COMMIT" >/dev/null; then
            log "Restored application revision $CURRENT_COMMIT."
        else
            warn "Could not restore the previous Git revision; use the verified backup in $BACKUP_DIR."
        fi
    fi

    if [[ "$MIGRATIONS_STARTED" == true ]]; then
        warn "The database was not automatically restored. Review $BACKUP_DIR before any manual restore."
    fi

    if [[ "$APACHE_RESTORE_REQUIRED" == true ]]; then
        if systemctl start apache2; then
            APACHE_RESTORE_REQUIRED=false
            log "Restored Apache to its previous active state."
        else
            warn "Could not restart Apache; run 'systemctl start apache2' after reviewing the failure."
        fi
    fi
}

on_error() {
    local rc=$?
    local line="$1"
    local command="$2"
    warn "Command failed at line $line: $command"
    exit "$rc"
}

on_signal() {
    local rc=$1
    local signal=$2
    warn "Received $signal; aborting the upgrade."
    exit "$rc"
}

on_exit() {
    local rc=$?
    trap - EXIT ERR INT TERM
    if ((rc != 0)); then
        rollback_changes || true
    fi
    cleanup
    exit "$rc"
}

trap on_exit EXIT
trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR
trap 'on_signal 130 INT' INT
trap 'on_signal 143 TERM' TERM

require_argument() {
    [[ $# -ge 2 && -n "$2" ]] || fail "Missing argument for $1."
}

parse_args() {
    while (($#)); do
        case "$1" in
            --apply)
                [[ "$CHECK_EXPLICIT" == false ]] || \
                    fail "--apply and --check cannot be used together."
                APPLY=true
                shift
                ;;
            --root-dir)
                require_argument "$@"
                APP_ROOT=$2
                shift 2
                ;;
            --db-config)
                require_argument "$@"
                DB_CONFIG=$2
                shift 2
                ;;
            --remote)
                require_argument "$@"
                REMOTE=$2
                shift 2
                ;;
            --ref)
                require_argument "$@"
                TARGET_REF=$2
                REF_EXPLICIT=true
                shift 2
                ;;
            --backup-dir)
                require_argument "$@"
                BACKUP_ROOT=$2
                shift 2
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            --check)
                [[ "$APPLY" == false ]] || \
                    fail "--apply and --check cannot be used together."
                CHECK_EXPLICIT=true
                shift
                ;;
            *)
                fail "Unknown option: $1"
                ;;
        esac
    done

    if [[ "$REF_EXPLICIT" == false ]]; then
        TARGET_REF="$REMOTE/master"
    fi
}

require_commands() {
    local command
    for command in "$@"; do
        command -v "$command" >/dev/null 2>&1 || fail "Required command not found: $command"
    done
}

validate_host() {
    [[ "$(id -u)" -eq 0 ]] || fail "This script must be run as root."
    [[ -r /etc/os-release ]] || fail "Cannot identify the operating system."
    # shellcheck disable=SC1091
    . /etc/os-release
    [[ "${ID:-}" == "debian" || "${ID_LIKE:-}" == *debian* ]] || \
        fail "This script supports Debian-based systems only."
}

validate_paths() {
    [[ -d "$APP_ROOT" ]] || fail "daloRADIUS directory does not exist: $APP_ROOT"
    [[ ! -L "$APP_ROOT" ]] || fail "Refusing to operate on a symlinked application root: $APP_ROOT"
    APP_ROOT=$(realpath -e "$APP_ROOT")
    CONFIG_FILE="$APP_ROOT/app/common/includes/daloradius.conf.php"

    [[ -d "$APP_ROOT/.git" ]] || fail "Not a Git checkout: $APP_ROOT"
    [[ -f "$CONFIG_FILE" ]] || fail "Configuration file not found: $CONFIG_FILE"
    [[ ! -L "$CONFIG_FILE" ]] || fail "Refusing to operate on a symlinked configuration file."
    [[ -f "$APP_ROOT/app/common/includes/daloradius.conf.php.sample" ]] || \
        fail "Configuration sample not found in the checkout."

    [[ -f "$DB_CONFIG" ]] || fail "MariaDB option file not found: $DB_CONFIG"
    [[ ! -L "$DB_CONFIG" ]] || fail "Refusing to use a symlinked MariaDB option file."

    local owner mode
    owner=$(stat -c '%u' -- "$DB_CONFIG")
    mode=$(stat -c '%a' -- "$DB_CONFIG")
    [[ "$owner" == 0 ]] || fail "MariaDB option file must be owned by root."
    if (( 8#$mode & 077 )); then
        fail "MariaDB option file must not be readable or writable by group or other users (mode $mode)."
    fi
}

validate_backup_root() {
    [[ "$BACKUP_ROOT" = /* ]] || fail "Backup directory must be an absolute path."
    local parent owner mode
    [[ ! -L "$BACKUP_ROOT" ]] || fail "Refusing to use a symlinked backup directory."
    parent=$(realpath -e "$(dirname "$BACKUP_ROOT")") || \
        fail "Backup directory parent does not exist: $(dirname "$BACKUP_ROOT")"
    [[ -d "$parent" ]] || fail "Backup directory parent is not a directory: $parent"
    BACKUP_ROOT=$(realpath -m -- "$parent/$(basename "$BACKUP_ROOT")")
    [[ "$BACKUP_ROOT" != / ]] || fail "Backup directory must not be the filesystem root."
    [[ "$BACKUP_ROOT" != "$APP_ROOT" && "$BACKUP_ROOT" != "$APP_ROOT/"* ]] || \
        fail "Backup directory must be outside the daloRADIUS application root."
    owner=$(stat -c '%u' -- "$parent")
    mode=$(stat -c '%a' -- "$parent")
    [[ "$owner" == 0 ]] || fail "Backup directory parent must be owned by root."
    if (( 8#$mode & 022 )); then
        fail "Backup directory parent must not be group/world writable (mode $mode)."
    fi

    if [[ -e "$BACKUP_ROOT" ]]; then
        [[ -d "$BACKUP_ROOT" ]] || fail "Backup path is not a directory: $BACKUP_ROOT"
        owner=$(stat -c '%u' -- "$BACKUP_ROOT")
        mode=$(stat -c '%a' -- "$BACKUP_ROOT")
        [[ "$owner" == 0 ]] || fail "Backup directory must be owned by root."
        if (( 8#$mode & 077 )); then
            fail "Backup directory must not be readable or writable by group or other users (mode $mode)."
        fi
    fi
}

validate_git_inputs() {
    [[ "$REMOTE" =~ ^[A-Za-z0-9._-]+$ ]] || fail "Invalid Git remote name: $REMOTE"
    [[ "$TARGET_REF" =~ ^[A-Za-z0-9._/@:-]+$ ]] || fail "Invalid Git ref: $TARGET_REF"
    [[ "$TARGET_REF" != -* ]] || fail "Git ref must not start with '-'."
}

acquire_lock() {
    local owner mode
    owner=$(stat -c '%u' -- /run/lock)
    mode=$(stat -c '%a' -- /run/lock)
    [[ "$owner" == 0 ]] || fail "/run/lock must be owned by root."
    if (( (8#$mode & 022) && (8#$mode & 01000) == 0 )); then
        fail "/run/lock must be non-writable or sticky (mode $mode)."
    fi
    if [[ ! -e "$LOCK_FILE" && ! -L "$LOCK_FILE" ]]; then
        (set -o noclobber; : > "$LOCK_FILE") 2>/dev/null || true
    fi
    [[ ! -L "$LOCK_FILE" ]] || fail "Refusing to use a symlinked lock file."
    [[ -f "$LOCK_FILE" ]] || fail "Unable to create lock file: $LOCK_FILE"
    owner=$(stat -c '%u' -- "$LOCK_FILE")
    mode=$(stat -c '%a' -- "$LOCK_FILE")
    [[ "$owner" == 0 ]] || fail "Lock file must be owned by root."
    if (( 8#$mode & 077 )); then
        fail "Lock file must not be readable or writable by group or other users (mode $mode)."
    fi
    exec 9>"$LOCK_FILE"
    flock -n 9 || fail "Another daloRADIUS upgrade is already running."
}

validate_git_state() {
    local status
    status=$(git -C "$APP_ROOT" status --porcelain --untracked-files=all)
    [[ -z "$status" ]] || {
        printf '%s\n' "$status" >&2
        fail "Git checkout is not clean; refusing to overwrite local changes."
    }

    CURRENT_COMMIT=$(git -C "$APP_ROOT" rev-parse HEAD)
    git -C "$APP_ROOT" config remote."$REMOTE".url >/dev/null 2>&1 || \
        fail "Git remote does not exist: $REMOTE"
}

validate_database() {
    local database
    database=$(mariadb --defaults-extra-file="$DB_CONFIG" \
        --batch --skip-column-names --execute='SELECT DATABASE();' 2>/dev/null) || \
        fail "Could not connect to MariaDB with the supplied option file."
    [[ -n "$database" && "$database" != "NULL" ]] || \
        fail "The MariaDB option file must define an explicit database=."
    DB_NAME=$database
    log "MariaDB connection verified for database $DB_NAME."
}

prepare_backup() {
    local stamp archive dump config_meta app_name
    stamp=$(date -u +%Y%m%dT%H%M%SZ)
    if [[ ! -e "$BACKUP_ROOT" ]]; then
        mkdir -m 700 -- "$BACKUP_ROOT"
    fi
    BACKUP_DIR="$BACKUP_ROOT/$stamp"
    [[ ! -e "$BACKUP_DIR" ]] || fail "Backup directory already exists: $BACKUP_DIR"
    mkdir -m 700 -- "$BACKUP_DIR"
    chmod 700 -- "$BACKUP_DIR"

    archive="$BACKUP_DIR/daloradius-files.tar.gz"
    app_name=$(basename "$APP_ROOT")
    log "Creating application backup: $archive"
    tar -C "$(dirname "$APP_ROOT")" --exclude="$app_name/.git" \
        -czf "$archive" "$app_name"
    [[ -s "$archive" ]] || fail "Application backup is empty."
    tar -tzf "$archive" >/dev/null || fail "Application backup could not be read back."

    dump="$BACKUP_DIR/database.sql"
    TMP_FILES+=("$dump.tmp")
    log "Creating database backup: $dump"
    mariadb-dump --defaults-extra-file="$DB_CONFIG" \
        --single-transaction --quick --routines --events --triggers > "$dump.tmp"
    [[ -s "$dump.tmp" ]] || fail "Database backup is empty."
    mv -- "$dump.tmp" "$dump"
    chmod 600 -- "$dump"

    CONFIG_BACKUP="$BACKUP_DIR/daloradius.conf.php"
    cp -p -- "$CONFIG_FILE" "$CONFIG_BACKUP"
    config_meta="$BACKUP_DIR/metadata.txt"
    {
        printf 'application_root=%s\n' "$APP_ROOT"
        printf 'database=%s\n' "$DB_NAME"
        printf 'current_commit=%s\n' "$CURRENT_COMMIT"
        printf 'target_ref=%s\n' "$TARGET_REF"
        printf 'created_at_utc=%s\n' "$(date -u +%FT%TZ)"
    } > "$config_meta"
    chmod 600 -- "$config_meta" "$BACKUP_DIR/daloradius.conf.php"

    (
        cd "$BACKUP_DIR"
        sha256sum daloradius-files.tar.gz database.sql daloradius.conf.php metadata.txt > SHA256SUMS
    )
    chmod 600 -- "$BACKUP_DIR/SHA256SUMS"
    log "Verified backup created at $BACKUP_DIR."
}

fetch_target() {
    log "Fetching Git refs from $REMOTE."
    git -C "$APP_ROOT" fetch --tags --prune "$REMOTE"
    TARGET_COMMIT=$(git -C "$APP_ROOT" rev-parse --verify "$TARGET_REF^{commit}") || \
        fail "Git ref does not resolve to a commit: $TARGET_REF"
    git -C "$APP_ROOT" cat-file -e \
        "$TARGET_COMMIT:app/common/includes/daloradius.conf.php.sample" || \
        fail "Target revision does not contain the daloRADIUS configuration sample."

    [[ "$CURRENT_COMMIT" == "$TARGET_COMMIT" ]] && {
        log "The checkout is already at $TARGET_COMMIT."
        return
    }

    git -C "$APP_ROOT" merge-base --is-ancestor "$CURRENT_COMMIT" "$TARGET_COMMIT" || \
        fail "Target is not a fast-forward from the current revision; refusing update."
    log "Upgrade plan: $CURRENT_COMMIT -> $TARGET_COMMIT."
}

migration_list() {
    git -C "$APP_ROOT" ls-tree -r --name-only "$TARGET_COMMIT" | \
        while IFS= read -r path; do
            case "$path" in
                contrib/db/migrations/*.sql) printf '%s\n' "$path" ;;
            esac
        done | sort
}

sql_quote() {
    local value=$1
    value=${value//\'/\'\'}
    printf "'%s'" "$value"
}

show_plan() {
    local migration
    log "Planned application revision: $TARGET_COMMIT"
    log "Database migrations available in the target revision (the ledger will skip applied ones):"
    if ! migration_list | while IFS= read -r migration; do
        [[ -n "$migration" ]] && printf '  - %s\n' "$migration"
    done; then
        fail "Could not enumerate database migrations."
    fi
    log "Apache and FreeRADIUS configuration files will not be rewritten."
    if [[ "$APPLY" == false ]]; then
        log "Check complete; no upgrade was applied. Use --apply to proceed."
    fi
}

apply_migrations() {
    local migrations=$1
    local path tmp quoted stored checksum
    tmp=$(mktemp "$BACKUP_DIR/migration.XXXXXX.sql")
    TMP_FILES+=("$tmp")

    while IFS= read -r path; do
        [[ -n "$path" ]] || continue
        [[ "$path" =~ ^contrib/db/migrations/[A-Za-z0-9._-]+\.sql$ ]] || \
            fail "Unexpected migration path: $path"
        git -C "$APP_ROOT" show "$TARGET_COMMIT:$path" > "$tmp"
        [[ -s "$tmp" ]] || fail "Migration is empty: $path"

        checksum=$(sha256sum "$tmp" | cut -d' ' -f1)
        quoted=$(sql_quote "$path")
        stored=$(mariadb --defaults-extra-file="$DB_CONFIG" --batch --skip-column-names \
            --execute="SELECT sha256 FROM daloradius_schema_migrations WHERE filename=$quoted;")

        if [[ -n "$stored" ]]; then
            [[ "$stored" == "$checksum" ]] || \
                fail "Migration checksum changed after it was applied: $path"
            log "Skipping already applied migration: $path"
            continue
        fi

        log "Applying migration: $path"
        mariadb --defaults-extra-file="$DB_CONFIG" < "$tmp"
        mariadb --defaults-extra-file="$DB_CONFIG" --execute="INSERT INTO daloradius_schema_migrations (filename, sha256) VALUES ($quoted, '$checksum');"
    done <<< "$migrations"
}

run_migrations() {
    local migrations
    migrations=$(migration_list) || fail "Could not enumerate database migrations."
    if [[ -z "$migrations" ]]; then
        log "No database migration files detected."
        return
    fi

    MIGRATIONS_STARTED=true
    log "Preparing migration ledger."
    mariadb --defaults-extra-file="$DB_CONFIG" <<'SQL'
CREATE TABLE IF NOT EXISTS daloradius_schema_migrations (
    filename VARCHAR(255) NOT NULL PRIMARY KEY,
    sha256 CHAR(64) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
SQL
    apply_migrations "$migrations"
}

merge_configuration() {
    local sample tmp config_dir uid gid mode
    config_dir=$(dirname "$CONFIG_FILE")
    sample=$(mktemp "$config_dir/.daloradius.conf.sample.XXXXXX.php")
    TMP_FILES+=("$sample")
    chmod 644 -- "$sample"
    git -C "$APP_ROOT" show "$TARGET_COMMIT:app/common/includes/daloradius.conf.php.sample" > "$sample"

    id www-data >/dev/null 2>&1 || fail "The www-data account is required to merge the PHP configuration."
    runuser -u www-data -- test -r "$CONFIG_FILE" || \
        fail "The www-data account cannot read $CONFIG_FILE."
    tmp=$(mktemp "$config_dir/.daloradius.conf.php.XXXXXX")
    TMP_FILES+=("$tmp")

    runuser -u www-data -- php -d display_errors=stderr -r '
        $localFile = $argv[1];
        $sampleFile = $argv[2];
        $configValues = [];
        require $localFile;
        $local = $configValues;
        $configValues = [];
        require $sampleFile;
        $defaults = $configValues;
        $merged = array_replace($defaults, $local);
        echo "<?php\n";
        foreach ($merged as $key => $value) {
            echo "\$configValues[" . var_export($key, true) . "] = " . var_export($value, true) . ";\n";
        }
    ' "$CONFIG_FILE" "$sample" > "$tmp"
    php -l "$tmp" >/dev/null

    uid=$(stat -c '%u' -- "$CONFIG_FILE")
    gid=$(stat -c '%g' -- "$CONFIG_FILE")
    mode=$(stat -c '%a' -- "$CONFIG_FILE")
    mv -f -- "$tmp" "$CONFIG_FILE"
    CONFIG_UPDATED=true
    chown "$uid:$gid" -- "$CONFIG_FILE"
    mode=$(printf '%o' $((8#$mode & 0770)))
    chmod "$mode" -- "$CONFIG_FILE"
    log "Merged the current configuration with the target sample."
}

validate_services() {
    local apache_was_active=$1
    systemctl is-active --quiet freeradius || fail "FreeRADIUS is not active after the upgrade."
    if [[ "$apache_was_active" == true ]]; then
        systemctl is-active --quiet apache2 || fail "Apache is not active after the upgrade."
    else
        systemctl is-active --quiet apache2 && fail "Apache became active although it was inactive before the upgrade." || true
    fi
}

apply_upgrade() {
    local apache_was_active=true
    if ! systemctl is-active --quiet apache2; then
        apache_was_active=false
        warn "Apache was inactive before the upgrade; it will remain inactive."
    fi
    systemctl is-active --quiet freeradius || fail "FreeRADIUS must be active before applying the upgrade."

    prepare_backup
    if [[ "$apache_was_active" == true ]]; then
        APACHE_RESTORE_REQUIRED=true
        systemctl stop apache2
    fi

    run_migrations

    CODE_UPDATED=true
    git -C "$APP_ROOT" merge --ff-only "$TARGET_COMMIT" >/dev/null
    merge_configuration

    apachectl configtest >/dev/null
    freeradius -XC >/dev/null

    if [[ "$apache_was_active" == true ]]; then
        systemctl start apache2
    fi
    validate_services "$apache_was_active"

    APACHE_RESTORE_REQUIRED=false
    CODE_UPDATED=false
    CONFIG_UPDATED=false
    MIGRATIONS_STARTED=false
    log "Upgrade completed successfully."
    log "Application revision: $TARGET_COMMIT"
    log "Backup retained at: $BACKUP_DIR"
}

main() {
    parse_args "$@"
    validate_host
    require_commands git mariadb realpath stat flock sort
    if [[ "$APPLY" == true ]]; then
        require_commands mariadb-dump php runuser tar sha256sum mktemp systemctl apachectl freeradius
    fi
    validate_paths
    validate_backup_root
    validate_git_inputs
    acquire_lock
    validate_git_state
    validate_database
    fetch_target
    show_plan

    [[ "$APPLY" == true ]] || return 0
    apply_upgrade
}

main "$@"
