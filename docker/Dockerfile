FROM php:8.5-fpm-alpine

RUN apk add --no-cache \
  unzip \
  zip \
  nano \
  libpng-dev

RUN docker-php-ext-install bcmath gd mysqli pdo_mysql

RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
  && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
  && rm /tmp/composer-setup.php

WORKDIR /var/www/html

CMD ["php-fpm"]
