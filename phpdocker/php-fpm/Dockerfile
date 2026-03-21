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

RUN apk --no-cache add postgresql-client python3 coreutils flock tzdata

RUN chmod +x /usr/local/bin/install-php-extensions; install-php-extensions gd bz2 pdo_pgsql zip
CMD ["/tools/flibusta_entrypoint.sh"]