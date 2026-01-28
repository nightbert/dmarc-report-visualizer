FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf

COPY public/ /var/www/html/public/
COPY bin/ /var/www/html/bin/
COPY data_paths.php /var/www/html/data_paths.php

RUN chmod +x /var/www/html/bin/entrypoint.sh /var/www/html/bin/worker.sh

EXPOSE 80
ENTRYPOINT ["/var/www/html/bin/entrypoint.sh"]
