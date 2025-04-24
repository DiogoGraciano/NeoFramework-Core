FROM php:8.4-fpm-alpine

# Instala dependências necessárias
RUN apk add --no-cache \
    postgresql-dev \
    libpq \
    mysql-client \
    curl \
    libzip-dev \
    zlib-dev \
    libxml2-dev \
    autoconf \
    g++ \
    make \
    bash \
    linux-headers \
    && docker-php-ext-install pdo_pgsql pgsql \
    && docker-php-ext-install pdo_mysql mysqli

# Instala a extensão Redis via PECL
RUN pecl install redis && docker-php-ext-enable redis

# Instala o Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

EXPOSE 80

CMD ["php-fpm"]
