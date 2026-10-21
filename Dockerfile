# Roue de la Chance — image Docker pour Render (PHP 8.3 + Apache).
# Le site est dans site/. La base des tickets et les photos vont sur le disque permanent /var/data.
FROM php:8.3-apache-trixie

# Extensions PHP : GD (réduction des photos), EXIF (orientation des photos), OPcache (rapidité).
# SQLite et mbstring sont déjà inclus dans l'image officielle.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libfreetype-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd exif opcache \
 && rm -rf /var/lib/apt/lists/*

# Réglages PHP et Apache : port fourni par Render, protection des dossiers internes
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-roue.ini
COPY docker/ports.conf /etc/apache2/ports.conf
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache.conf /etc/apache2/conf-available/roue.conf
COPY docker/start.sh /usr/local/bin/roue-start
RUN a2enmod headers && a2enconf roue && chmod 755 /usr/local/bin/roue-start

# Le site
COPY site/ /var/www/html/
# (un dépôt Git ne garde pas les dossiers vides : on les recrée au besoin)
RUN mkdir -p /usr/local/share/roue /var/www/html/uploads /var/www/html/data \
 && cp -a /var/www/html/uploads /usr/local/share/roue/uploads \
 && chown -R www-data:www-data /var/www/html/data /var/www/html/uploads

ENV PORT=10000 \
    RDC_DATA_DIR=/var/data \
    RDC_CLIENT_IP_HEADER=HTTP_CF_CONNECTING_IP
EXPOSE 10000
CMD ["roue-start"]
