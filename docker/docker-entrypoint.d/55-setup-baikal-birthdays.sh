#!/bin/sh
# Configure and start the periodic birthday-sync job.
#
# Behaviour is controlled via environment variables:
#   BAIKAL_BIRTHDAY_ENABLED   "true" to enable the cron job (default: true)
#   BAIKAL_BIRTHDAY_CRON      cron schedule (default: "30 0 * * *" = daily 00:30)
#   BAIKAL_BIRTHDAY_RUN_ON_START  "true" to also run once shortly after boot
#
# The job itself reads its options (calendar name, alarm time, ...) from the
# BAIKAL_BIRTHDAY_* variables, which are propagated into the cron environment.
set -e
ME=$(basename "$0")

ENABLED="${BAIKAL_BIRTHDAY_ENABLED:-true}"
CRON_FILE="/etc/cron.d/baikal-birthdays"

case "$ENABLED" in
    1|true|TRUE|yes|on) : ;;
    *)
        echo "$ME: birthday sync disabled (BAIKAL_BIRTHDAY_ENABLED=$ENABLED)"
        rm -f "$CRON_FILE"
        exit 0
        ;;
esac

if ! command -v cron >/dev/null 2>&1; then
    echo "$ME: warning: cron not installed; skipping periodic setup" >&2
    exit 0
fi

SCHEDULE="${BAIKAL_BIRTHDAY_CRON:-30 0 * * *}"

# Propagate the runtime configuration into cron's (otherwise empty) environment.
{
    echo "# Managed by $ME - do not edit by hand"
    echo "SHELL=/bin/sh"
    echo "PATH=/usr/local/bin:/usr/local/sbin:/usr/sbin:/usr/bin:/sbin:/bin"
    for var in BAIKAL_HOME BAIKAL_EXT_HOME BAIKAL_PATH_CONFIG BAIKAL_PATH_SPECIFIC \
               BAIKAL_BIRTHDAY_CALENDAR BAIKAL_BIRTHDAY_ADDRESSBOOK \
               BAIKAL_BIRTHDAY_ALARM_TIME BAIKAL_BIRTHDAY_CREATE_CALENDAR; do
        eval "value=\${$var:-}"
        if [ -n "$value" ]; then
            echo "$var=$value"
        fi
    done
    # Run as root; the wrapper drops to www-data. Logs go to the container stdout.
    echo "$SCHEDULE root /usr/local/bin/baikal-birthdays run >> /proc/1/fd/1 2>> /proc/1/fd/2"
} > "$CRON_FILE"

chmod 0644 "$CRON_FILE"
echo "$ME: installed birthday-sync schedule ($SCHEDULE)"

# Start cron if it isn't already running.
if ! pgrep -x cron >/dev/null 2>&1; then
    cron
    echo "$ME: started cron daemon"
fi

case "${BAIKAL_BIRTHDAY_RUN_ON_START:-false}" in
    1|true|TRUE|yes|on)
        echo "$ME: running an initial birthday sync in the background"
        ( sleep 10; /usr/local/bin/baikal-birthdays run >> /proc/1/fd/1 2>> /proc/1/fd/2 || true ) &
        ;;
esac
