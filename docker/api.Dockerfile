# update-api / update-worker / update-scheduler / signing-service image (§3.5).
FROM php:8.4-fpm-bookworm AS base
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl mysqli pdo_mysql zip redis opcache sodium \
 && apt-get update && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /srv/update-center/backend
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader
COPY backend/ ./
COPY VERSION /srv/update-center/VERSION
COPY openapi/ /srv/update-center/openapi/
ARG UC_BUILD=""
ARG UC_COMMIT=""
ENV UC_BUILD=${UC_BUILD} UC_COMMIT=${UC_COMMIT} CI_ENVIRONMENT=production
RUN mkdir -p writable/cache writable/logs writable/uploads writable/session \
 && chown -R www-data:www-data writable \
 && printf 'expose_php=Off\nmemory_limit=256M\nupload_max_filesize=24M\npost_max_size=24M\nopcache.validate_timestamps=0\n' > /usr/local/etc/php/conf.d/zz-update-center.ini
USER www-data
CMD ["php-fpm"]

# Worker image: adds read-only verification tools (osslsigncode, gpg, rpm, rcodesign).
# Uploaded files are only read by these tools; nothing is ever executed (FR-UC-UPL-07).
FROM base AS worker
USER root
RUN apt-get update && apt-get install -y --no-install-recommends osslsigncode gnupg rpm ca-certificates curl \
 && curl -fsSL https://github.com/indygreg/apple-platform-rs/releases/download/apple-codesign%2F0.29.0/apple-codesign-0.29.0-x86_64-unknown-linux-musl.tar.gz \
    | tar -xz --strip-components=1 -C /usr/local/bin apple-codesign-0.29.0-x86_64-unknown-linux-musl/rcodesign \
 && rm -rf /var/lib/apt/lists/*
USER www-data
CMD ["php", "spark", "uc:worker"]
