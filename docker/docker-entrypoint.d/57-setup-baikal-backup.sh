#!/bin/sh
# Configure the periodic database + config backup job.
#
#   BAIKAL_EXT_BACKUP_ENABLED    "true" to enable (default: true)
#   BAIKAL_EXT_BACKUP_CRON       cron schedule (default: "0 3 * * *" = daily 03:00)
#   BAIKAL_EXT_BACKUP_DIR        output dir (default: <Specific>/backups)
#   BAIKAL_EXT_BACKUP_KEEP       archives to retain (default: 7)
#   BAIKAL_EXT_BACKUP_RUN_ON_START  "true" to also back up shortly after boot
set -e
ME=$(basename "$0")

ENABLED="${BAIKAL_EXT_BACKUP_ENABLED:-true}"
CRON_FILE="/etc/cron.d/baikal-backup"

case "$ENABLED" in
    1|true|TRUE|yes|on) : ;;
    *)
        echo "$ME: backups disabled (BAIKAL_EXT_BACKUP_ENABLED=$ENABLED)"
        rm -f "$CRON_FILE"
        exit 0
        ;;
esac

SPECIFIC="${BAIKAL_PATH_SPECIFIC:-/var/www/baikal/Specific}"
BACKUP_DIR="${BAIKAL_EXT_BACKUP_DIR:-$SPECIFIC/backups}"
mkdir -p "$BACKUP_DIR"
chown -R www-data:www-data "$BACKUP_DIR" 2>/dev/null || true

if ! command -v cron >/dev/null 2>&1; then
    echo "$ME: warning: cron not installed; skipping backup schedule" >&2
    exit 0
fi

SCHEDULE="${BAIKAL_EXT_BACKUP_CRON:-0 3 * * *}"

{
    echo "# Managed by $ME - do not edit by hand"
    echo "SHELL=/bin/sh"
    echo "PATH=/usr/local/bin:/usr/local/sbin:/usr/sbin:/usr/bin:/sbin:/bin"
    for var in BAIKAL_HOME BAIKAL_EXT_HOME BAIKAL_PATH_CONFIG BAIKAL_PATH_SPECIFIC \
               BAIKAL_EXT_BACKUP_DIR BAIKAL_EXT_BACKUP_KEEP; do
        eval "value=\${$var:-}"
        if [ -n "$value" ]; then
            echo "$var=$value"
        fi
    done
    echo "$SCHEDULE root /usr/local/bin/baikal-backup >> /proc/1/fd/1 2>> /proc/1/fd/2"
} > "$CRON_FILE"

chmod 0644 "$CRON_FILE"
echo "$ME: installed backup schedule ($SCHEDULE) -> $BACKUP_DIR"

# Ensure cron is running (55-setup-baikal-birthdays.sh usually starts it first).
if ! pgrep -x cron >/dev/null 2>&1; then
    cron
    echo "$ME: started cron daemon"
fi

case "${BAIKAL_EXT_BACKUP_RUN_ON_START:-false}" in
    1|true|TRUE|yes|on)
        echo "$ME: running an initial backup in the background"
        ( sleep 15; /usr/local/bin/baikal-backup >> /proc/1/fd/1 2>> /proc/1/fd/2 || true ) &
        ;;
esac
