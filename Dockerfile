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
    icu-dev \
    icu-data-full \
    libjpeg-turbo-dev \
    libwebp-dev \
    libpng-dev \
    freetype-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && docker-php-ext-install intl \
    # sockets: exigido pelo SDK do RoadRunner, que fala com o processo pai por socket
    && docker-php-ext-install sockets exif \
    && docker-php-ext-install pdo_mysql mysqli \
    # gd para o adapter de imagem opcional: sem ele o pacote existiria sem nunca
    # ter executado, que é o erro que este projeto já cometeu com o S3.
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install gd

# Instala a extensão Redis via PECL
RUN pecl install redis && docker-php-ext-enable redis

# pcov e não xdebug: o Infection precisa de um driver de cobertura, e o xdebug
# deixa a suíte inteira várias vezes mais lenta mesmo quando não está coletando.
# Fica desabilitado por padrão — `php -d pcov.enabled=1` liga só onde interessa.
RUN pecl install pcov \
    && docker-php-ext-enable pcov \
    && echo "pcov.enabled=0" > /usr/local/etc/php/conf.d/zz-pcov.ini

# Instala o Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

EXPOSE 80

CMD ["php-fpm"]
