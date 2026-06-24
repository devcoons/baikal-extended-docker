#!/bin/sh

APACHE_CONFIG="/etc/apache2/sites-available/000-default.conf"

if [ -n "${BAIKAL_SERVERNAME:-}" ]; then
    sed -i "s/^\s*ServerName .*/ServerName ${BAIKAL_SERVERNAME}/" "$APACHE_CONFIG"
fi

if [ -n "${BAIKAL_SERVERALIAS:-}" ]; then
    sed -i "s/# InjectedServerAlias .*/ServerAlias ${BAIKAL_SERVERALIAS}/" "$APACHE_CONFIG"
fi
