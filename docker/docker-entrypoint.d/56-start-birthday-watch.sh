#!/bin/sh
# Start the event-driven birthday-sync watcher.
#
# The watcher reacts to contact changes published by the in-server change-trigger
# plugin (see src_ext). Controlled by:
#   BAIKAL_EXT_BIRTHDAY_WATCH   "true" to enable the watcher (default: true)
#   BAIKAL_EXT_QUEUE        queue directory (default: <Specific>/birthday-queue)
set -e
ME=$(basename "$0")

ENABLED="${BAIKAL_EXT_BIRTHDAY_WATCH:-true}"
case "$ENABLED" in
    1|true|TRUE|yes|on) : ;;
    *)
        echo "$ME: birthday watcher disabled (BAIKAL_EXT_BIRTHDAY_WATCH=$ENABLED)"
        exit 0
        ;;
esac

SPECIFIC="${BAIKAL_PATH_SPECIFIC:-/var/www/baikal/Specific}"
QUEUE="${BAIKAL_EXT_QUEUE:-$SPECIFIC/birthday-queue}"

# Pre-create the queue with web-server ownership so the plugin (running as
# www-data) can write markers and the watcher can consume them.
mkdir -p "$QUEUE"
chown -R www-data:www-data "$QUEUE" 2>/dev/null || true

if ! command -v inotifywait >/dev/null 2>&1; then
    echo "$ME: warning: inotifywait missing; skipping watcher" >&2
    exit 0
fi

# Run the watcher in the background; its sync calls drop privileges themselves.
echo "$ME: starting birthday watcher (queue: $QUEUE)"
BAIKAL_EXT_QUEUE="$QUEUE" /usr/local/bin/birthday-watch >> /proc/1/fd/1 2>> /proc/1/fd/2 &
