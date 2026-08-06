FROM php:8.5.8-apache-trixie@sha256:eacc0d98992683cb46e4f8f44b2418a0323855dc8b59d32dc54f7a9b90a966dd

LABEL org.opencontainers.image.title="eStab" \
    org.opencontainers.image.description="Containerized eStab application" \
    org.opencontainers.image.source="https://github.com/e-stab/estab" \
    org.opencontainers.image.licenses="GPL-3.0-only"

ENV ESTAB_DB_HOST=db \
    ESTAB_DB_PORT=3306 \
    ESTAB_DB_USER=estab \
    ESTAB_DB_NAME=estab \
    ESTAB_BASE_PATH= \
    TZ=Europe/Berlin

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        acl \
        apache2-utils \
        libexpat1 \
        libfreetype6 \
        libfreetype6-dev \
        libjpeg62-turbo \
        libjpeg62-turbo-dev \
        libpng16-16t64 \
        libpng-dev \
        libzip5 \
        libzip-dev \
        poppler-utils; \
    dpkg --compare-versions \
        "$(dpkg-query --showformat='${Version}' --show libexpat1)" \
        ge '2.8.2-1~deb13u1'; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j1 gd mysqli zip; \
    php -r 'foreach (["fileinfo", "gd", "mbstring", "mysqli", "Zend OPcache", "zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: $extension\n"); exit(1); } } $gd = gd_info(); foreach (["JPEG Support", "PNG Support", "GIF Read Support", "BMP Support"] as $feature) { if (!($gd[$feature] ?? false)) { fwrite(STDERR, "Missing GD feature: $feature\n"); exit(1); } }'; \
    php -r 'if (!defined("PASSWORD_ARGON2ID")) { fwrite(STDERR, "Missing Argon2id password support\n"); exit(1); } $options = ["memory_cost" => PASSWORD_ARGON2_DEFAULT_MEMORY_COST, "time_cost" => PASSWORD_ARGON2_DEFAULT_TIME_COST, "threads" => PASSWORD_ARGON2_DEFAULT_THREADS]; $prefix = str_repeat("a", 72); $hash = password_hash($prefix . "x", PASSWORD_ARGON2ID, $options); $info = is_string($hash) ? password_get_info($hash) : []; if (!is_string($hash) || strlen($hash) > 255 || ($info["algoName"] ?? "") !== "argon2id" || ($info["options"] ?? null) !== $options || !password_verify($prefix . "x", $hash) || password_verify($prefix . "y", $hash)) { fwrite(STDERR, "Argon2id password verification is unsafe\n"); exit(1); }'; \
    command -v setpriv >/dev/null; \
    command -v prlimit >/dev/null; \
    command -v pdfinfo >/dev/null; \
    command -v pdftoppm >/dev/null; \
    command -v getfacl >/dev/null; \
    command -v setfacl >/dev/null; \
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

COPY --chmod=0444 LICENSE /usr/share/licenses/estab/LICENSE
COPY --chmod=0444 third_party/Noto-OFL-1.1.txt /usr/share/licenses/estab/Noto-OFL-1.1.txt
COPY index.php health.php favicon.ico menue.inc.php estab-ui.css estab-password-policy.js ./

# Git history and the migration evidence preserve the upstream lineage, while
# the runtime image contains only maintained code and assets. In particular,
# the historical 4fadm/00.htpasswd is never copied; the separate, networkless
# admin-auth initializer generates the effective bcrypt file at runtime.
COPY 4fach/4fachform.php \
    4fach/official_message_form.php \
    4fach/activity.php \
    4fach/anhang.php \
    4fach/button.php \
    4fach/createbutton.php \
    4fach/data_hndl.php \
    4fach/db_operation.php \
    4fach/download.php \
    4fach/email.php \
    4fach/fuehrungsstelle.php \
    4fach/index.php \
    4fach/info.php \
    4fach/katego.php \
    4fach/kategobutton.php \
    4fach/katgoedt.php \
    4fach/liste.php \
    4fach/logoff.php \
    4fach/logout.php \
    4fach/mainindex.php \
    4fach/nachwea.php \
    4fach/protokoll.php \
    4fach/resetpic.php \
    4fach/showpic.php \
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
    ./4fach/design/HS/
COPY 4fach/design/mr/folder_global.gif ./4fach/design/mr/
COPY 4fach/null.gif ./4fach/

COPY 4fadm/admin.php \
    4fadm/export.php \
    4fadm/fuehrungsstelle.php \
    4fadm/incident_export.php \
    4fadm/incidents.php \
    4fadm/make_fkt.php \
    4fadm/password_policy.php \
    4fadm/self_registration.php \
    4fadm/set_number_after_crash.php \
    4fadm/system_status.php \
    4fadm/users.php \
    ./4fadm/

# Only the active PDF generator is shipped. The old bitmap generator and
# bundled FPDF examples/documentation/archive stay in Git.
COPY 4fbak/backup.php \
    4fbak/backup_pdf.php \
    4fbak/fpdf.php \
    4fbak/thw.png \
    ./4fbak/
COPY 4fbak/fpdf/font/*.php ./4fbak/fpdf/font/
# Dynamic button rendering uses the bundled OFL-licensed font when FreeType exists.
COPY 4fbak/fonts/NotoSerif-BoldItalic.ttf ./4fbak/fonts/

COPY 4fcfg/color.inc.php \
    4fcfg/config.inc.php \
    4fcfg/d_cfg.inc.php \
    4fcfg/dbcfg.inc.php \
    4fcfg/e_cfg.inc.php \
    4fcfg/fkt_rolle.inc.php \
    4fcfg/para.inc.php \
    ./4fcfg/
COPY 4fsym/4fach_aktiv.png \
    4fsym/adm_aktiv.png \
    4fsym/all_msg.png \
    4fsym/el80.gif \
    4fsym/etb_aktiv.png \
    4fsym/icon_handbuch.gif \
    4fsym/iuk_80.jpg \
    4fsym/iuk_hs80.png \
    4fsym/merke32.gif \
    4fsym/null.gif \
    4fsym/nw.png \
    4fsym/tbb_aktiv.png \
    ./4fsym/
COPY 4fueltg/ue_ltg.php 4fueltg/null.gif ./4fueltg/
COPY app/*.php ./app/
COPY handbuch/index.php \
    handbuch/handbuch.css \
    handbuch/handbuch.js \
    ./handbuch/
COPY fmtbb/tbb.php fmtbb/null.gif fmtbb/null.jpg ./fmtbb/
COPY language/german/helptext.php \
    language/german/hilfetext.php \
    ./language/german/
COPY stabinfo/*.php ./stabinfo/
COPY stabinfo/*.html ./stabinfo/
COPY stabinfo/*.jpg stabinfo/*.png ./stabinfo/
COPY stabetb/etb.php stabetb/null.gif stabetb/null.jpg ./stabetb/

COPY docker/apache/estab.conf /etc/apache2/sites-available/estab.conf
COPY docker/apache/ports.conf /etc/apache2/ports.conf
COPY docker/php/estab.ini /usr/local/etc/php/conf.d/zz-estab.ini
COPY docker/app/entrypoint.sh /usr/local/bin/estab-entrypoint
COPY docker/app/cleanup-pdf-render-tmp.sh /usr/local/bin/estab-cleanup-pdf-render-tmp
COPY docker/app/init-admin-auth.sh /usr/local/bin/estab-init-admin-auth
COPY docker/app/healthcheck.php /usr/local/bin/estab-healthcheck
COPY docker/app/verify-runtime-surface.sh /usr/local/bin/estab-verify-runtime-surface

RUN set -eux; \
    command -v setpriv >/dev/null; \
    command -v getfacl >/dev/null; \
    command -v setfacl >/dev/null; \
    a2ensite estab; \
    install -d -o www-data -g www-data -m 0770 \
        /var/www/html/4fdata \
        /var/lib/estab/export \
        /var/lib/php/sessions; \
    chmod 0755 \
        /usr/local/bin/estab-entrypoint \
        /usr/local/bin/estab-cleanup-pdf-render-tmp \
        /usr/local/bin/estab-init-admin-auth \
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
