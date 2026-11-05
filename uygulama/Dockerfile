FROM php:8.2-apache
RUN docker-php-ext-install pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis