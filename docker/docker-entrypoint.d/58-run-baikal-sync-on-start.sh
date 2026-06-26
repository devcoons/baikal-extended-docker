#!/bin/sh
# Enforce a one-off birthday/anniversary sync once the database is ready.
#
# This runs entirely in the background so it never delays Apache startup. It
# waits until the database is reachable AND Baïkal is installed (schema present),
# works for both SQLite and MySQL/PostgreSQL, then runs a full sync exactly once.
#
# Besides covering fresh boots, this also MIGRATES events created by older
# versions of the extension to the current format (e.g. splitting a legacy
# single event into the "next occurrence" + recurring "series" pair).
#
#   BAIKAL_EXT_BIRTHDAY_RUN_ON_START   "true" to enable (default: true)
#   BAIKAL_EXT_BIRTHDAY_START_TIMEOUT  max seconds to wait for the DB (default: 300)
set -e
ME=$(basename "$0")

ENABLED="${BAIKAL_EXT_BIRTHDAY_RUN_ON_START:-true}"
case "$ENABLED" in
    1|true|TRUE|yes|on) : ;;
    *)
        echo "$ME: first-run sync disabled (BAIKAL_EXT_BIRTHDAY_RUN_ON_START=$ENABLED)"
        exit 0
        ;;
esac

# Respect the master switch for the extension's periodic job too.
case "${BAIKAL_EXT_BIRTHDAY_ENABLED:-true}" in
    1|true|TRUE|yes|on) : ;;
    *)
        echo "$ME: birthday sync disabled (BAIKAL_EXT_BIRTHDAY_ENABLED); skipping first run"
        exit 0
        ;;
esac

EXT_HOME="${BAIKAL_EXT_HOME:-/opt/baikal-ext}"
TIMEOUT="${BAIKAL_EXT_BIRTHDAY_START_TIMEOUT:-300}"
RUN_AS="${BAIKAL_EXT_USER:-www-data}"

# Run the readiness probe as the web-server user so it never creates
# root-owned SQLite side files (-wal/-shm).
probe() {
    if [ "$(id -u)" = "0" ] && [ "$RUN_AS" != "root" ] && command -v runuser >/dev/null 2>&1; then
        runuser -u "$RUN_AS" -- php "$EXT_HOME/bin/db-ready.php"
    else
        php "$EXT_HOME/bin/db-ready.php"
    fi
}

echo "$ME: scheduling first-run sync once the database is ready (timeout ${TIMEOUT}s)"

# Background the whole wait+run so the entrypoint can hand off to Apache.
(
    waited=0
    until probe; do
        if [ "$waited" -ge "$TIMEOUT" ]; then
            echo "$ME: database not ready after ${TIMEOUT}s; skipping first-run sync" >> /proc/1/fd/2
            exit 0
        fi
        sleep 5
        waited=$((waited + 5))
    done

    echo "$ME: database ready; running initial birthday/anniversary sync" >> /proc/1/fd/1
    if ! /usr/local/bin/baikal-birthdays run >> /proc/1/fd/1 2>> /proc/1/fd/2; then
        echo "$ME: initial sync failed" >> /proc/1/fd/2
    fi
) &
