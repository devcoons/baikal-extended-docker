#!/bin/sh
set -e

ME=$(basename "$0")

if [ ! -d /var/www/baikal/Specific/db ]; then
    echo "$ME: creating Baïkal database directory"
    mkdir -p /var/www/baikal/Specific/db
fi

if [ ! -d /var/www/baikal/config ]; then
    echo "$ME: creating Baïkal config directory"
    mkdir -p /var/www/baikal/config
fi
