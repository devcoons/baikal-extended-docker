#!/bin/sh

# Ensure writable config and data dirs unless explicitly disabled.
if [ -z "${BAIKAL_SKIP_CHOWN+x}" ]; then
    chown -R www-data:www-data /var/www/baikal/config /var/www/baikal/Specific
fi
