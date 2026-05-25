FROM php:8.2-apache

# System balíčky
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    p7zip-full \
    default-mysql-client \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# GD (kvůli QR)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd

# MySQL driver
RUN docker-php-ext-install pdo_mysql mysqli

# Apache moduly
RUN a2enmod rewrite

COPY ./src/www /var/www
COPY ./src/docker/apache.conf /etc/apache2/ports.conf
