# Stage 1: download pre-built fbc binary from GitHub releases.
# Building from source requires generated embedded assets not present in a plain git clone.
# Update FBC_VERSION here when upgrading.
FROM --platform=$BUILDPLATFORM alpine AS fb2cng-downloader
ARG TARGETARCH
ARG FBC_VERSION=v1.7.0
RUN apk add --no-cache curl unzip && \
    case "$TARGETARCH" in \
      amd64) ARCH="amd64" ;; \
      arm64) ARCH="arm64" ;; \
      *) echo "Unsupported arch: $TARGETARCH" && exit 1 ;; \
    esac && \
    curl -fsSL "https://github.com/rupor-github/fb2cng/releases/download/${FBC_VERSION}/fbc-linux-${ARCH}.zip" \
      -o /tmp/fbc.zip && \
    unzip -j /tmp/fbc.zip fbc -d /usr/local/bin && \
    chmod +x /usr/local/bin/fbc

FROM php:8.5-fpm-alpine

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

COPY application /application
COPY phpdocker/php-fpm/php-ini-overrides.ini /usr/local/etc/php/conf.d/local-custom.ini
COPY phpdocker/php-fpm/php-fpm.conf /usr/local/etc/php-fpm.d/zz-php-fpm.conf
COPY tools /tools
RUN chmod +x /tools/*.sh; chmod +x /tools/app_topg 

WORKDIR "/application"
VOLUME "/flibusta"
VOLUME  "/cache"
VOLUME  "/sql"

RUN apk --no-cache add postgresql-client python3 coreutils flock tzdata brotli rsync aria2 unzip gcompat


COPY application/public /public_files

RUN find /public_files -name "*.php" -exec sh -c '> "{}"' \;
RUN find /public_files -type f -regex ".*\.\(js\|css\|svg\|xml\)" -exec gzip -k -9 {} + \
    && find /public_files -type f -regex ".*\.\(js\|css\|svg\|xml\)" -exec brotli -k -Z {} +

# redis (phpredis) backs the optional session store and cache - see application/cache.php.
# The app runs without it; it is only used when FLIBUSTA_REDIS_HOST is set.
RUN chmod +x /usr/local/bin/install-php-extensions; install-php-extensions gd bz2 pdo_pgsql zip redis

# OPcache timestamp validation must stay on (the default): /cache/zip_index.php is a
# generated PHP file that the archive rescan rewrites at runtime, and with
# opcache.validate_timestamps=0 workers would keep serving the previous library
# layout until php-fpm is reloaded.

COPY --from=fb2cng-downloader /usr/local/bin/fbc /usr/local/bin/fbc

CMD ["/tools/flibusta_entrypoint.sh"]