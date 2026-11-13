# Cjenici (cjenik-smit): PHP 8 + Apache. Konfiguracija ide iskljucivo iz varijabli okruzenja (app/config.docker.php).
# Trajni podaci: /var/www/html (objavljeni cjenici, CSV) i /var/www/storage (logovi, backup) - montirati volumene.
FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY docker/cjenik.ini /usr/local/etc/php/conf.d/cjenik.ini
COPY docker/apache-cjenik.conf /etc/apache2/conf-available/cjenik.conf
RUN a2enconf cjenik

# Aplikacija izvan document roota: /var/www/app (public_html/index.php ocekuje ../app)
COPY app/ /var/www/app/
COPY app/config.docker.php /var/www/app/config.php
COPY seed/ /var/www/seed/
# Web dio u sliku, a entrypoint ga kopira u document root (volumen) pri svakom pokretanju
COPY public_html/ /opt/cjenik/public_html/
COPY docker/healthcheck.php /opt/cjenik/healthcheck.php
COPY docker/entrypoint.sh /usr/local/bin/cjenik-entrypoint
RUN chmod +x /usr/local/bin/cjenik-entrypoint \
    && mkdir -p /var/www/storage \
    && chown -R www-data:www-data /var/www/app /var/www/seed

VOLUME ["/var/www/html", "/var/www/storage"]
EXPOSE 80

HEALTHCHECK --interval=60s --timeout=6s --start-period=90s \
    CMD ["php", "/opt/cjenik/healthcheck.php"]

ENTRYPOINT ["cjenik-entrypoint"]
