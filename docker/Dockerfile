FROM php:7.4-cli

RUN apt-get update
RUN apt-get install -y autoconf pkg-config libssl-dev libonig-dev libzip-dev
RUN pecl install mongodb
RUN docker-php-ext-install pdo_mysql mbstring zip opcache
RUN echo "extension=mongodb.so" >> /usr/local/etc/php/conf.d/mongodb.ini
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
