FROM php:8.5.8-apache-trixie

ENV APACHE_DOCUMENT_ROOT=/var/www/html \
    ESTAB_DB_HOST=db \
    ESTAB_DB_PORT=3306 \
    ESTAB_DB_USER=estab \
    ESTAB_DB_NAME=estab \
    ESTAB_BASE_PATH= \
    TZ=Europe/Berlin

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        apache2-utils \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j1 gd mysqli zip; \
    php -r 'foreach (["gd", "mbstring", "mysqli", "Zend OPcache", "zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: $extension\n"); exit(1); } }'; \
    a2enmod auth_basic authn_file headers; \
    a2dissite 000-default; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY index.php health.php config.inc.php dbcfg.inc.php e_cfg.inc.php favicon.ico menue.inc.php ./
COPY 4fach/ ./4fach/
COPY 4fadm/ ./4fadm/
COPY 4fbak/ ./4fbak/
COPY 4fcfg/ ./4fcfg/
COPY 4fcss/ ./4fcss/
COPY 4fexch/ ./4fexch/
COPY 4fsym/ ./4fsym/
COPY 4fueltg/ ./4fueltg/
COPY app/ ./app/
COPY br/ ./br/
COPY doku/ ./doku/
COPY fmtbb/ ./fmtbb/
COPY language/ ./language/
COPY sammlung/ ./sammlung/
COPY stabinfo/ ./stabinfo/
COPY stabetb/ ./stabetb/
COPY ubltg/ ./ubltg/

COPY docker/apache/estab.conf /etc/apache2/sites-available/estab.conf
COPY docker/apache/ports.conf /etc/apache2/ports.conf
COPY docker/php/estab.ini /usr/local/etc/php/conf.d/zz-estab.ini
COPY docker/app/entrypoint.sh /usr/local/bin/estab-entrypoint
COPY docker/app/healthcheck.php /usr/local/bin/estab-healthcheck

RUN set -eux; \
    a2ensite estab; \
    install -d -o www-data -g www-data -m 0770 \
        /var/www/html/4fdata \
        /var/lib/estab/export \
        /var/lib/php/sessions; \
    chmod 0755 /usr/local/bin/estab-entrypoint /usr/local/bin/estab-healthcheck; \
    find /var/www/html -xdev -type d ! -path '/var/www/html/4fdata*' -exec chmod 0755 '{}' +; \
    find /var/www/html -xdev -type f ! -path '/var/www/html/4fdata/*' -exec chmod 0644 '{}' +

EXPOSE 8080

ENTRYPOINT ["estab-entrypoint"]
CMD ["apache2-foreground"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD ["estab-healthcheck"]
