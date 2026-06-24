#!/bin/sh
set -e

if [ -z "${NGINX_ENTRYPOINT_QUIET_LOGS:-}" ]; then
    exec 3>&1
else
    exec 3>/dev/null
fi

if [ "$1" = "apache2-foreground" ]; then
    if find "/docker-entrypoint.d/" -mindepth 1 -maxdepth 1 -type f -print -quit 2>/dev/null | read -r _; then
        echo >&3 "$0: running /docker-entrypoint.d/ scripts"
        find "/docker-entrypoint.d/" -follow -type f | sort -V | while read -r f; do
            case "$f" in
                *.sh)
                    if [ -x "$f" ]; then
                        echo >&3 "$0: launching $f"
                        "$f"
                    else
                        echo >&3 "$0: ignoring $f (not executable)"
                    fi
                    ;;
                *)
                    echo >&3 "$0: ignoring $f"
                    ;;
            esac
        done
        echo >&3 "$0: configuration complete"
    fi
fi

exec "$@"
