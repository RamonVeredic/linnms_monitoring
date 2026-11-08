FROM lscr.io/linuxserver/nginx:1.30.4 AS loader-build
RUN apk add --no-cache php85-dev build-base autoconf libsodium-dev pkgconf
COPY pq/loader/ /build/loader/
RUN cd /build/loader \
    && phpize85 \
    && ./configure --with-php-config=/usr/bin/php-config85 --enable-nx \
    && make -j2 \
    && test -s modules/nx.so \
    && test -s build.h \
    && strip --strip-unneeded modules/nx.so

FROM lscr.io/linuxserver/nginx:1.30.4
LABEL org.opencontainers.image.title="LINNMS" \
      org.opencontainers.image.description="LINNMS" \
      org.opencontainers.image.source="https://github.com/RamonVeredic/linnms_monitoring"

RUN apk add --no-cache \
      inetutils-telnet net-snmp-tools php85-pdo_mysql php85-snmp php85-sodium libsodium
COPY --from=loader-build /build/loader/modules/nx.so /usr/lib/php85/modules/nx.so
RUN printf '%s\n' \
      'extension=nx' \
      'nx.roots=/opt/linnms/runtime,/opt/linnms/config,/opt/linnms/frontend' \
      'opcache.enable=0' \
      'opcache.enable_cli=0' \
      > /etc/php85/conf.d/99-nx.ini \
    && php -m | grep -Fx nx \
    && php -r 'if (!extension_loaded("sodium")) exit(1);'

RUN mkdir -p /opt/linnms/runtime /opt/linnms/config /opt/linnms/frontend \
      /opt/linnms/nginx /custom-cont-init.d /custom-services.d
COPY bundle/config/app/runtime/ /opt/linnms/runtime/
COPY bundle/config/app/config/ /opt/linnms/config/
COPY bundle/config/www/ /opt/linnms/frontend/
COPY bundle/nginx/ /opt/linnms/nginx/
COPY bundle/linnms.init.d/50-linnms-public-init /custom-cont-init.d/50-linnms-public-init
COPY bundle/linnms.service.d/ /custom-services.d/

# Preserve the source layout expected by frontend/app/bootstrap.php.
# Staging has config/www beside config/app/config; production uses /opt/linnms.
RUN mkdir -p /opt/linnms/app \
    && ln -s ../config /opt/linnms/app/config \
    && test -f /opt/linnms/app/config/session.php
RUN set -eu; \
    ! find /opt/linnms -name .github -o -name .git -o -name 'Dockerfile*' -o -name '*.sample' | grep .; \
    ! grep -E 'SOURCE_(MOUNTS|ROOT|SEED)|staging|source-seed' /custom-cont-init.d/50-linnms-public-init; \
    find /opt/linnms -type f -name '*.php' -exec php -r \
      'foreach(array_slice($argv,1) as $f) { $h=fopen($f,"rb"); $b=fread($h,4); fclose($h); if ($b!=="LNC1") {fwrite(STDERR, "E31\n");exit(1);} }' \
      -- {} +; \
    test -f /opt/linnms/frontend/index.php; \
    test ! -e /opt/linnms/source-seed
RUN php -d display_errors=1 -r 'require_once "/opt/linnms/runtime/app/paths.php"; require_once "/opt/linnms/runtime/config/config_default.php"; if (!class_exists("LinnmsDefaults")) { fwrite(STDERR, "E30\\n"); exit(1); } echo "BUILD_OK\\n";' \
    | grep -F BUILD_OK

RUN chmod 0755 /custom-cont-init.d/50-linnms-public-init /custom-services.d/* \
    && chown -R root:root /opt/linnms \
    && find /opt/linnms -type d -exec chmod 0555 {} \; \
    && find /opt/linnms -type f -exec chmod 0444 {} \;
EXPOSE 80 443
