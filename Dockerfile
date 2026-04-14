FROM php:8.2-apache AS runtime-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        unzip \
        libemail-outlook-message-perl \
        libemail-address-perl \
        libemail-mime-perl \
        libemail-sender-perl \
        libio-string-perl \
        libole-storage-lite-perl \
    && apt-get install -y --no-install-recommends build-essential \
    && pecl install mailparse \
    && docker-php-ext-enable mailparse \
    && apt-get purge -y --auto-remove build-essential \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf

COPY public/ /var/www/html/public/
COPY bin/ /var/www/html/bin/
COPY data_paths.php /var/www/html/data_paths.php

RUN chmod +x /var/www/html/bin/entrypoint.sh /var/www/html/bin/worker.sh

EXPOSE 80
ENTRYPOINT ["/var/www/html/bin/entrypoint.sh"]


FROM php:8.2-cli-alpine AS runtime-alpine

RUN apk add --no-cache bash unzip perl perl-utils \
    && apk add --no-cache --virtual .build-deps perl-dev make gcc musl-dev wget \
    && wget -qO - https://cpanmin.us | perl - --notest --quiet Email::Outlook::Message \
    && apk del --purge .build-deps

COPY public/ /var/www/html/public/
COPY bin/ /var/www/html/bin/
COPY data_paths.php /var/www/html/data_paths.php

RUN chmod +x /var/www/html/bin/entrypoint.sh /var/www/html/bin/worker.sh

EXPOSE 80
ENTRYPOINT ["/var/www/html/bin/entrypoint.sh"]
