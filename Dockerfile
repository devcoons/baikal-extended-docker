# syntax=docker/dockerfile:1

# Build Baïkal from the src/ submodule (sabre-io/Baikal).
# Multi-arch: official php/composer images support amd64, arm64, arm/v7, etc.
# Build example:
#   docker buildx build --platform linux/amd64,linux/arm64 -t baikal:latest .

FROM docker.io/library/composer:2 AS builder

WORKDIR /build

COPY src/composer.json ./
RUN composer config platform.php 8.2

COPY src/Core ./Core
COPY src/html ./html
COPY src/Specific/.htaccess ./Specific/.htaccess
COPY src/LICENSE src/README.md ./

# Apply our patches to the pristine Baikal submodule sources. The build fails
# loudly if a patch no longer applies (e.g. after a Baikal upgrade), so the hook
# is never silently lost. Patches live in src_ext/patches and are kept out of the
# submodule itself.
COPY src_ext/patches ./patches
RUN apk add --no-cache patch \
    && for p in patches/*.patch; do \
        echo "Applying $p"; \
        patch -p1 --forward < "$p" || exit 1; \
    done \
    && rm -rf patches

RUN mkdir -p Specific/db config \
    && touch Specific/db/.empty config/.empty \
    && composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader \
    && rm -f composer.json composer.lock

FROM docker.io/library/php:8.2-apache-bookworm

LABEL org.opencontainers.image.title="Baïkal" \
      org.opencontainers.image.description="CalDAV and CardDAV server built from sabre-io/Baikal" \
      org.opencontainers.image.source="https://github.com/sabre-io/Baikal" \
      org.opencontainers.image.licenses="GPL-3.0-only"

ENV BAIKAL_HOME=/var/www/baikal \
    BAIKAL_EXT_HOME=/opt/baikal-ext

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libpq-dev \
        cron \
        inotify-tools \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install curl pdo_mysql pdo_pgsql pgsql \
    && a2enmod rewrite headers \
    && sed 's/expose_php = On/expose_php = Off/' \
        /usr/local/etc/php/php.ini-production > /usr/local/etc/php/php.ini

COPY --from=builder --chown=www-data:www-data /build ${BAIKAL_HOME}

# Extension: occasion sync (birthdays + anniversaries) + backups.
# Kept separate from the Baikal submodule so upstream can be upgraded cleanly.
COPY src_ext ${BAIKAL_EXT_HOME}
RUN ln -sf ${BAIKAL_EXT_HOME}/bin/baikal-birthdays /usr/local/bin/baikal-birthdays \
    && ln -sf ${BAIKAL_EXT_HOME}/bin/birthday-watch /usr/local/bin/birthday-watch \
    && ln -sf ${BAIKAL_EXT_HOME}/bin/baikal-backup /usr/local/bin/baikal-backup \
    && chmod +x ${BAIKAL_EXT_HOME}/bin/baikal-birthdays ${BAIKAL_EXT_HOME}/bin/birthday-watch ${BAIKAL_EXT_HOME}/bin/baikal-backup

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/docker-entrypoint.sh /docker-entrypoint.sh
COPY docker/docker-entrypoint.d/ /docker-entrypoint.d/

RUN chmod +x /docker-entrypoint.sh \
    && find /docker-entrypoint.d -type f -name '*.sh' -exec chmod +x {} +

EXPOSE 80

VOLUME ["${BAIKAL_HOME}/config", "${BAIKAL_HOME}/Specific"]

# Liveness probe: confirms Apache + PHP are actually serving (root redirects via PHP).
# Drives the restart logic (autoheal / orchestrator) when the process is alive but stuck.
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1/ || exit 1

ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
