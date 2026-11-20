# TPWeb — image Apache + PHP 8.3 prête à l'emploi.
#   docker run -d -p 8080:80 -v tpweb-data:/var/TPWebData ghcr.io/xeros59/tpweb:latest
# Variante avec LibreOffice (aperçus Word / PowerPoint, annotation des copies Office) : ghcr.io/xeros59/tpweb:office
FROM php:8.3-apache

ARG WITH_LIBREOFFICE=false

LABEL org.opencontainers.image.source="https://github.com/Xeros59/tpweb" \
      org.opencontainers.image.title="TPWeb" \
      org.opencontainers.image.description="Portail pédagogique auto-hébergé : cours, dépôts, QCM, annotation des copies"

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libzip-dev fonts-dejavu-core; \
    if [ "$WITH_LIBREOFFICE" = "true" ]; then \
      apt-get install -y --no-install-recommends libreoffice-writer libreoffice-calc libreoffice-impress \
        fonts-liberation fonts-crosextra-carlito fonts-crosextra-caladea; \
    fi; \
    docker-php-ext-install -j"$(nproc)" zip opcache; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod headers remoteip

COPY docker/php.ini /usr/local/etc/php/conf.d/tpweb.ini
COPY docker/apache.conf /etc/apache2/conf-enabled/tpweb.conf
COPY docker/entrypoint.sh /usr/local/bin/tpweb-entrypoint
COPY . /var/www/html/

# Code en lecture seule pour Apache ; données dans le volume.
RUN chmod 755 /usr/local/bin/tpweb-entrypoint \
 && rm -rf /var/www/html/docker \
 && chown -R root:root /var/www/html && chmod -R a+rX,go-w /var/www/html \
 && mkdir -p /var/TPWebData && chown www-data:www-data /var/TPWebData

ENV TPW_DATA=/var/TPWebData \
    TPW_DOCKER_IMAGE=ghcr.io/xeros59/tpweb \
    TPW_CRON=1 \
    TZ=Europe/Paris
VOLUME ["/var/TPWebData"]
EXPOSE 80

HEALTHCHECK --interval=60s --timeout=5s --start-period=20s \
  CMD curl -fsS -o /dev/null http://127.0.0.1/index.php || exit 1

ENTRYPOINT ["tpweb-entrypoint"]
CMD ["apache2-foreground"]
