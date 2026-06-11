FROM php:8.2-cli-alpine

RUN apk add --no-cache bash unzip perl perl-utils \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS perl-dev make gcc musl-dev wget \
    && wget -qO - https://cpanmin.us | perl - --notest --quiet Email::Outlook::Message \
    && pecl install mailparse \
    && docker-php-ext-enable mailparse \
    && apk del --purge .build-deps \
    && rm -rf /tmp/pear

COPY public/ /var/www/html/public/
COPY bin/ /var/www/html/bin/
COPY data_paths.php /var/www/html/data_paths.php
COPY report_index.php /var/www/html/report_index.php

RUN chmod +x /var/www/html/bin/entrypoint.sh /var/www/html/bin/worker.sh

EXPOSE 80
ENTRYPOINT ["/var/www/html/bin/entrypoint.sh"]
