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
DB_CONFIG=""
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
FR_RESTORE_REQUIRED=false
STAGE_DIR=""
RUNNER=""
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
  --db-config PATH        Optional root-owned MariaDB client option file.
                          Default: use the installed application configuration.
  --remote NAME           Git remote to fetch (default: origin).
  --ref REF               Git ref or commit to deploy (default: REMOTE/master).
  --backup-dir PATH       Backup root (default: /root/daloradius-backups).
  -h, --help              Show this help.

An explicitly supplied MariaDB option file must contain a [client] section with credentials and
connection settings for the application database. It must be owned by root and not readable by the
group or other users.

Examples:
  setup/upgrade.sh --check --ref origin/master
  setup/upgrade.sh --apply --ref v2.3
EOF
}

cleanup() {
    local file
    for file in "${TMP_FILES[@]}"; do
        rm -f -- "$file"
    done
    [[ -z "$STAGE_DIR" ]] || rm -rf -- "$STAGE_DIR"
}

rollback_changes() {
    [[ "$APPLY" == true ]] || return 0
    [[ "$ROLLBACK_IN_PROGRESS" == false ]] || return 0
    [[ "$CODE_UPDATED" == true || "$CONFIG_UPDATED" == true || \
       "$MIGRATIONS_STARTED" == true || "$APACHE_RESTORE_REQUIRED" == true || "$FR_RESTORE_REQUIRED" == true ]] || return 0

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

    if [[ "$MIGRATIONS_STARTED" == true ]]; then
        systemctl stop apache2 freeradius || warn "Could not stop all application writers."
        warn "Application writers remain stopped. Repair or restore the database, then start Apache/FreeRADIUS."
        return
    fi
    if [[ "$FR_RESTORE_REQUIRED" == true ]]; then
        systemctl start freeradius || warn "Could not restore FreeRADIUS."
        FR_RESTORE_REQUIRED=false
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
    warn "Command failed at line $line."
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
    [[ "$REMOTE" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || fail "Invalid Git remote name: $REMOTE"
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

prepare_client_config() {
    id www-data >/dev/null 2>&1 || fail "The www-data account is required."
    runuser -u www-data -- test -r "$CONFIG_FILE" || fail "www-data cannot read the application configuration."
    if [[ -z "$DB_CONFIG" ]]; then
        DB_CONFIG=$(mktemp /run/daloradius-client.XXXXXX.cnf)
        TMP_FILES+=("$DB_CONFIG")
        runuser -u www-data -- php -d display_errors=0 -r '
            require $argv[1];
            $mapping = ["host"=>"CONFIG_DB_HOST", "port"=>"CONFIG_DB_PORT", "user"=>"CONFIG_DB_USER",
                        "password"=>"CONFIG_DB_PASS"];
            echo "[client]\n";
            foreach ($mapping as $option=>$key) {
                $value = (string)($configValues[$key] ?? ($option === "port" ? "3306" : ""));
                $value = str_replace(["\\", "\"", "\n", "\r"], ["\\\\", "\\\"", "\\n", "\\r"], $value);
                echo $option . "=\"" . $value . "\"\n";
            }
        ' "$CONFIG_FILE" > "$DB_CONFIG"
    fi
    [[ -f "$DB_CONFIG" && ! -L "$DB_CONFIG" ]] || fail "MariaDB option file must be a regular, non-symlinked file."
    local owner mode
    owner=$(stat -c '%u' -- "$DB_CONFIG")
    mode=$(stat -c '%a' -- "$DB_CONFIG")
    [[ "$owner" == 0 ]] || fail "MariaDB option file must be owned by root."
    (( (8#$mode & 077) == 0 )) || fail "MariaDB option file must have private permissions."
}

validate_database() {
    local database configured
    configured=$(runuser -u www-data -- php -d display_errors=0 -r 'require $argv[1]; echo $configValues["CONFIG_DB_NAME"];' "$CONFIG_FILE")
    database=$(mariadb --defaults-extra-file="$DB_CONFIG" --database="$configured" --batch --skip-column-names --execute='SELECT DATABASE();' 2>/dev/null) ||
        fail "Could not connect to MariaDB."
    configured=$(runuser -u www-data -- php -d display_errors=0 -r 'require $argv[1]; echo $configValues["CONFIG_DB_NAME"];' "$CONFIG_FILE")
    [[ -n "$database" && "$database" != NULL && "$database" == "$configured" ]] ||
        fail "The backup connection must select the same database as the application configuration."
    DB_NAME=$database
    log "MariaDB connection verified for database $DB_NAME."
}

prepare_backup() {
    local stamp archive dump config_meta app_name
    stamp=$(date -u +%Y%m%dT%H%M%SZ)
    if [[ ! -e "$BACKUP_ROOT" ]]; then
        mkdir -m 700 -- "$BACKUP_ROOT"
    fi
    BACKUP_DIR=$(mktemp -d "$BACKUP_ROOT/$stamp.XXXXXX")
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
        --single-transaction --quick --routines --events --triggers "$DB_NAME" > "$dump.tmp"
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
        printf 'target_commit=%s\n' "$TARGET_COMMIT"
        printf 'created_at_utc=%s\n' "$(date -u +%FT%TZ)"
    } > "$config_meta"
    chmod 600 -- "$config_meta"

    (
        cd "$BACKUP_DIR"
        sha256sum daloradius-files.tar.gz database.sql daloradius.conf.php metadata.txt > SHA256SUMS
    )
    chmod 600 -- "$BACKUP_DIR/SHA256SUMS"
    (cd "$BACKUP_DIR" && sha256sum -c SHA256SUMS >/dev/null)
    log "Backup and checksum manifest created at $BACKUP_DIR."
}

fetch_target() {
    log "Fetching Git refs from $REMOTE."
    git -C "$APP_ROOT" fetch --tags --prune -- "$REMOTE"
    if git -C "$APP_ROOT" show-ref --verify --quiet "refs/remotes/$REMOTE/$TARGET_REF"; then
        TARGET_REF="$REMOTE/$TARGET_REF"
    fi
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

stage_target() {
    STAGE_DIR=$(mktemp -d /run/daloradius-upgrade.XXXXXX)
    chgrp www-data "$STAGE_DIR"
    chmod 750 "$STAGE_DIR"
    git -C "$APP_ROOT" archive "$TARGET_COMMIT" | tar -x -C "$STAGE_DIR"
    RUNNER="$STAGE_DIR/contrib/scripts/maintenance/migrate-db.php"
    if [[ ! -f "$RUNNER" ]]; then
        mkdir -p "$STAGE_DIR/contrib/scripts/maintenance"
        local sibling
        sibling="$(dirname "$(realpath "$0")")/../contrib/scripts/maintenance/migrate-db.php"
        if [[ -f "$sibling" ]]; then
            cp -- "$sibling" "$RUNNER"
        else
            git -C "$APP_ROOT" show "$REMOTE/master:contrib/scripts/maintenance/migrate-db.php" > "$RUNNER" ||
                fail "No shared migration runner available in the target or remote master."
        fi
        chmod 644 "$RUNNER"
        chmod 755 "$STAGE_DIR/contrib" "$STAGE_DIR/contrib/scripts" "$STAGE_DIR/contrib/scripts/maintenance"
    fi
    php -l "$RUNNER" >/dev/null
    php -r 'exit(extension_loaded("mysqli") && in_array("mysql", PDO::getAvailableDrivers(), true) ? 0 : 1);' ||
        fail "PHP mysqli and PDO MySQL are required (Debian package php-mysql)."
    if [[ -n "$(migration_list)" ]]; then
        runuser -u www-data -- php "$RUNNER" --config="$CONFIG_FILE" --migrations-dir="$STAGE_DIR/contrib/db/migrations"
    fi
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

run_migrations() {
    if [[ -z "$(migration_list)" ]]; then
        log "No database migration files in this target."
        return
    fi
    MIGRATIONS_STARTED=true
    runuser -u www-data -- php "$RUNNER" --config="$CONFIG_FILE" --migrations-dir="$STAGE_DIR/contrib/db/migrations" --apply
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
    local apache_was_active=$1 radius_was_active=$2
    if [[ "$radius_was_active" == true ]]; then
        systemctl is-active --quiet freeradius || fail "FreeRADIUS is not active after the upgrade."
    fi
    if [[ "$apache_was_active" == true ]]; then
        systemctl is-active --quiet apache2 || fail "Apache is not active after the upgrade."
    fi
}

apply_upgrade() {
    local apache_was_active=false radius_was_active=false
    if systemctl is-active --quiet apache2; then
        apache_was_active=true
        APACHE_RESTORE_REQUIRED=true
        systemctl stop apache2
    fi
    if systemctl is-active --quiet freeradius; then
        radius_was_active=true
        FR_RESTORE_REQUIRED=true
        systemctl stop freeradius
    fi
    prepare_backup
    run_migrations
    CODE_UPDATED=true
    git -C "$APP_ROOT" merge --ff-only "$TARGET_COMMIT" >/dev/null
    merge_configuration
    apachectl configtest >/dev/null
    freeradius -XC > "$BACKUP_DIR/freeradius-config-check.log" 2>&1
    [[ "$radius_was_active" == false ]] || systemctl start freeradius
    [[ "$apache_was_active" == false ]] || systemctl start apache2
    validate_services "$apache_was_active" "$radius_was_active"
    APACHE_RESTORE_REQUIRED=false
    FR_RESTORE_REQUIRED=false
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
    require_commands git mariadb realpath stat flock sort php runuser tar mktemp
    if [[ "$APPLY" == true ]]; then
        require_commands mariadb-dump php runuser tar sha256sum mktemp systemctl apachectl freeradius
    fi
    validate_paths
    validate_backup_root
    validate_git_inputs
    acquire_lock
    validate_git_state
    prepare_client_config
    validate_database
    fetch_target
    stage_target
    show_plan

    [[ "$APPLY" == true ]] || return 0
    apply_upgrade
}

main "$@"
