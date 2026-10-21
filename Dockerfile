# Roue de la Chance — image Docker pour Render (PHP 8.3 + Apache).
# Le site et ses réglages arrivent dans une seule archive, site.tar.gz, à envoyer telle quelle sur GitHub
# à côté de ce fichier (sans la décompresser) : Docker l'ouvre lui-même. La base des tickets et les photos
# vont sur le disque permanent /var/data.
FROM php:8.3-apache-trixie

# Extensions PHP : GD (réduction des photos), EXIF (orientation des photos), OPcache (rapidité).
# SQLite et mbstring sont déjà inclus dans l'image officielle.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libfreetype-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd exif opcache \
 && rm -rf /var/lib/apt/lists/*

# Le site (dossier site/) et les réglages PHP et Apache (dossier docker/), décompressés par Docker
ADD site.tar.gz /usr/src/roue/
RUN set -eu; \
    src=/usr/src/roue; \
    if [ ! -f "$src/site/index.php" ] || [ ! -f "$src/site/app/bootstrap.php" ] || [ ! -f "$src/docker/start.sh" ]; then \
      echo "ERREUR : le fichier site.tar.gz est incomplet. Renvoyez sur GitHub le fichier site.tar.gz fourni, sans le décompresser." >&2; \
      exit 1; \
    fi; \
    cp "$src/docker/php.ini" /usr/local/etc/php/conf.d/zz-roue.ini; \
    cp "$src/docker/ports.conf" /etc/apache2/ports.conf; \
    cp "$src/docker/vhost.conf" /etc/apache2/sites-available/000-default.conf; \
    cp "$src/docker/apache.conf" /etc/apache2/conf-available/roue.conf; \
    install -m 755 "$src/docker/start.sh" /usr/local/bin/roue-start; \
    a2enmod headers; \
    a2enconf roue; \
    rm -rf /var/www/html; \
    cp -a "$src/site" /var/www/html; \
    mkdir -p /var/www/html/uploads /var/www/html/data /usr/local/share/roue; \
    cp -a /var/www/html/uploads /usr/local/share/roue/uploads; \
    chown -R www-data:www-data /var/www/html/data /var/www/html/uploads; \
    rm -rf "$src"

ENV PORT=10000 \
    RDC_DATA_DIR=/var/data \
    RDC_CLIENT_IP_HEADER=HTTP_CF_CONNECTING_IP
EXPOSE 10000
CMD ["roue-start"]
