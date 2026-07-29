FROM php:8.5.8-apache-trixie@sha256:eacc0d98992683cb46e4f8f44b2418a0323855dc8b59d32dc54f7a9b90a966dd

LABEL org.opencontainers.image.title="eStab" \
    org.opencontainers.image.description="Containerized eStab application" \
    org.opencontainers.image.source="https://github.com/e-stab/estab"

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
        libfreetype6 \
        libfreetype6-dev \
        libjpeg62-turbo \
        libjpeg62-turbo-dev \
        libpng16-16t64 \
        libpng-dev \
        libzip5 \
        libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j1 gd mysqli zip; \
    php -r 'foreach (["gd", "mbstring", "mysqli", "Zend OPcache", "zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: $extension\n"); exit(1); } }'; \
    a2enmod auth_basic authn_file headers; \
    a2dissite 000-default; \
    apt-get purge -y --auto-remove \
        libc6-dev \
        libfreetype-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY index.php health.php config.inc.php dbcfg.inc.php e_cfg.inc.php favicon.ico menue.inc.php estab-ui.css ./

# The repository preserves the complete upstream history, but the runtime
# image contains only exercised application code and assets. In particular,
# the historical 4fadm/00.htpasswd is never copied; entrypoint.sh generates
# the effective bcrypt file from the runtime secret below /run instead.
COPY 4fach/4fachform.php \
    4fach/anhang.php \
    4fach/button.php \
    4fach/counter.php \
    4fach/createbutton.php \
    4fach/data_hndl.php \
    4fach/db_operation.php \
    4fach/download.php \
    4fach/index.php \
    4fach/info.php \
    4fach/katego.php \
    4fach/kategobutton.php \
    4fach/katgoedt.php \
    4fach/liste.php \
    4fach/logoff.php \
    4fach/logout.php \
    4fach/mainindex.php \
    4fach/menue.php \
    4fach/nachwea.php \
    4fach/protokoll.php \
    4fach/resetpic.php \
    4fach/showpic.php \
    4fach/status.php \
    4fach/tools.php \
    4fach/upload.php \
    4fach/upload_class.php \
    4fach/vali_data.php \
    4fach/vordrucke.php \
    4fach/vorgaben.php \
    ./4fach/
COPY 4fach/upload/upload.php ./4fach/upload/
COPY 4fach/audio/*.wav ./4fach/audio/
COPY 4fach/design/HS/*.gif \
    4fach/design/HS/*.jpg \
    4fach/design/HS/*.wav \
    ./4fach/design/HS/
COPY 4fach/design/mr/folder_global.gif ./4fach/design/mr/
COPY 4fach/null.gif ./4fach/

COPY 4fadm/admin.php \
    4fadm/export.php \
    4fadm/incident_export.php \
    4fadm/incidents.php \
    4fadm/make_fkt.php \
    4fadm/set_number_after_crash.php \
    4fadm/system_status.php \
    4fadm/users.php \
    ./4fadm/

# Only the active PDF generator is shipped. The old bitmap generator, bundled
# FPDF examples/documentation/archive and unused font collection stay in Git.
COPY 4fbak/backup.php \
    4fbak/backup_pdf.php \
    4fbak/fpdf.php \
    ./4fbak/
COPY 4fbak/fpdf/font/*.php ./4fbak/fpdf/font/
# Dynamic button rendering uses this one historical font when FreeType exists.
COPY 4fbak/fonts/georgiaz.ttf ./4fbak/fonts/

COPY 4fcfg/color.inc.php \
    4fcfg/config.inc.php \
    4fcfg/d_cfg.inc.php \
    4fcfg/dbcfg.inc.php \
    4fcfg/e_cfg.inc.php \
    4fcfg/fkt_rolle.inc.php \
    4fcfg/para.inc.php \
    ./4fcfg/
COPY 4fsym/*.bmp \
    4fsym/*.gif \
    4fsym/*.jpg \
    4fsym/*.png \
    ./4fsym/
COPY 4fueltg/ue_ltg.php 4fueltg/null.gif ./4fueltg/
COPY app/*.php ./app/
COPY doku/Handbuch_eStab.pdf ./doku/
COPY fmtbb/tbb.php fmtbb/null.gif fmtbb/null.jpg ./fmtbb/
COPY language/german/helptext.php \
    language/german/hilfetext.php \
    ./language/german/
COPY stabinfo/ ./stabinfo/
COPY stabetb/etb.php stabetb/null.gif stabetb/null.jpg ./stabetb/

COPY docker/apache/estab.conf /etc/apache2/sites-available/estab.conf
COPY docker/apache/ports.conf /etc/apache2/ports.conf
COPY docker/php/estab.ini /usr/local/etc/php/conf.d/zz-estab.ini
COPY docker/app/entrypoint.sh /usr/local/bin/estab-entrypoint
COPY docker/app/healthcheck.php /usr/local/bin/estab-healthcheck
COPY docker/app/verify-runtime-surface.sh /usr/local/bin/estab-verify-runtime-surface

RUN set -eux; \
    a2ensite estab; \
    install -d -o www-data -g www-data -m 0770 \
        /var/www/html/4fdata \
        /var/lib/estab/export \
        /var/lib/php/sessions; \
    chmod 0755 \
        /usr/local/bin/estab-entrypoint \
        /usr/local/bin/estab-healthcheck \
        /usr/local/bin/estab-verify-runtime-surface; \
    find /var/www/html -xdev -type d ! -path '/var/www/html/4fdata*' -exec chmod 0755 '{}' +; \
    find /var/www/html -xdev -type f ! -path '/var/www/html/4fdata/*' -exec chmod 0644 '{}' +; \
    estab-verify-runtime-surface /var/www/html

EXPOSE 8080

ENTRYPOINT ["estab-entrypoint"]
CMD ["apache2-foreground"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD ["estab-healthcheck"]
