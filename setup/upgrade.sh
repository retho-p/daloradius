#!/usr/bin/env bash
# daloRADIUS - Debian application and schema upgrade helper
#
# This script intentionally upgrades only the daloRADIUS checkout and database.
# It does not upgrade the operating system or rewrite Apache configuration.
# The installed daloRADIUS configuration is loaded, never rewritten.

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

SCRIPT_NAME="$(basename "$0")"
readonly SCRIPT_NAME
APP_ROOT="/var/www/daloradius"
REMOTE="origin"
TARGET_REF="origin/master"
APPLY=false
REF_EXPLICIT=false
CHECK_EXPLICIT=false

CONFIG_FILE=""
LOCK_FILE="/run/lock/daloradius-upgrade.lock"
CURRENT_COMMIT=""
TARGET_COMMIT=""
UPGRADE_STARTED=false
APACHE_RESTORE_REQUIRED=false
STAGE_DIR=""
RUNNER=""

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

Upgrade the application and schema of a standard Debian daloRADIUS installation.

Options:
  --check                 Preview the target and migration history (the default).
  --apply                 Apply the planned upgrade. Without this, no service,
                          application file, or database schema is changed.
  --root-dir PATH         daloRADIUS checkout (default: /var/www/daloradius).
  --remote NAME           Git remote to fetch (default: origin).
  --ref REF               Git ref or commit to deploy (default: REMOTE/master).
  -h, --help              Show this help.

Database migrations use the installed application configuration and account.
The existing daloradius.conf.php is left unchanged. Review new settings in the
release notes and configuration sample; add any required settings yourself.
Backups and recovery are the administrator's responsibility. This script does
not create backups or automatically roll back application/schema changes.
Before --apply, back up the database, configuration and local application data
using your normal tools, and verify that you can restore them.

--apply stops Apache during the upgrade, interrupting the web portals.
FreeRADIUS and MariaDB services are not managed by this helper.

Examples:
  setup/upgrade.sh --check --ref origin/master
  setup/upgrade.sh --apply --ref COMMIT_PRINTED_BY_CHECK
EOF
}

cleanup() {
    [[ -z "$STAGE_DIR" ]] || rm -rf -- "$STAGE_DIR"
}

handle_failure() {
    [[ "$APPLY" == true ]] || return 0
    if [[ "$UPGRADE_STARTED" == true ]]; then
        systemctl stop apache2 || warn "Could not stop Apache."
        warn "Application/schema changes may have started; no automatic rollback was attempted."
        warn "Source revision: $CURRENT_COMMIT; planned target: $TARGET_COMMIT."
        warn "Inspect the current checkout and migration history. Repair or restore using your own backups."
        warn "Keep Apache stopped until recovery is verified."
        return
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
        handle_failure || true
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
    if git -C "$APP_ROOT" ls-files --error-unmatch -- \
        app/common/includes/daloradius.conf.php >/dev/null 2>&1; then
        fail "Application configuration is tracked by Git; refusing to risk changing it."
    fi
}

fetch_target() {
    log "Fetching Git refs from $REMOTE."
    git -C "$APP_ROOT" fetch --tags --prune -- "$REMOTE" \
        "+refs/heads/*:refs/remotes/$REMOTE/*"
    if git -C "$APP_ROOT" show-ref --verify --quiet "refs/remotes/$REMOTE/$TARGET_REF"; then
        TARGET_REF="$REMOTE/$TARGET_REF"
    fi
    TARGET_COMMIT=$(git -C "$APP_ROOT" rev-parse --verify "$TARGET_REF^{commit}") || \
        fail "Git ref does not resolve to a commit: $TARGET_REF"
    git -C "$APP_ROOT" cat-file -e \
        "$TARGET_COMMIT:app/common/includes/daloradius.conf.php.sample" || \
        fail "Target revision does not contain the daloRADIUS configuration sample."
    if git -C "$APP_ROOT" cat-file -e \
        "$TARGET_COMMIT:app/common/includes/daloradius.conf.php" 2>/dev/null; then
        fail "Target revision tracks the local application configuration; refusing to overwrite it."
    fi

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
    log "daloRADIUS, Apache and FreeRADIUS configuration files will not be rewritten."
    if [[ "$APPLY" == false ]]; then
        log "Check complete; no upgrade was applied. Use --apply to proceed."
    fi
}

run_migrations() {
    if [[ -z "$(migration_list)" ]]; then
        log "No database migration files in this target."
        return
    fi
    runuser -u www-data -- php "$RUNNER" --config="$CONFIG_FILE" --migrations-dir="$STAGE_DIR/contrib/db/migrations" --apply
}

validate_configuration_access() {
    runuser -u www-data -- php -d display_errors=stderr -r '
        require $argv[1];
        exit(isset($configValues) && is_array($configValues) ? 0 : 1);
    ' "$1" || fail "www-data cannot load the application configuration."
}

validate_services() {
    local apache_was_active=$1
    validate_configuration_access "$CONFIG_FILE"
    if [[ "$apache_was_active" == true ]]; then
        systemctl is-active --quiet apache2 || fail "Apache is not active after the upgrade."
    fi
}

apply_upgrade() {
    local apache_was_active=false
    warn "No backup will be created. Verify your own database, configuration and local-data backups before upgrading."
    log "Maintenance: stopping Apache for the upgrade if active."
    if systemctl is-active --quiet apache2; then
        apache_was_active=true
        APACHE_RESTORE_REQUIRED=true
        systemctl stop apache2
    fi
    UPGRADE_STARTED=true
    run_migrations
    # Repository files are public source; private umask 077 is for temporary files.
    # Git creates replaced/new files using umask, so 077 would hide PHP from Apache.
    (umask 022; git -C "$APP_ROOT" merge --ff-only "$TARGET_COMMIT" >/dev/null)
    validate_configuration_access "$CONFIG_FILE"
    apachectl configtest >/dev/null
    [[ "$apache_was_active" == false ]] || systemctl start apache2
    validate_services "$apache_was_active"
    APACHE_RESTORE_REQUIRED=false
    UPGRADE_STARTED=false
    log "Upgrade completed successfully."
    log "Application revision: $TARGET_COMMIT"
}

main() {
    parse_args "$@"
    validate_host
    require_commands git realpath stat flock sort php runuser tar mktemp cp
    if [[ "$APPLY" == true ]]; then
        require_commands systemctl apachectl
    fi
    validate_paths
    validate_git_inputs
    acquire_lock
    validate_git_state
    id www-data >/dev/null 2>&1 || fail "The www-data account is required."
    validate_configuration_access "$CONFIG_FILE"
    fetch_target
    stage_target
    show_plan

    [[ "$APPLY" == true ]] || return 0
    apply_upgrade
}

main "$@"
