FROM php:8.2-cli-alpine

RUN apk add --no-cache linux-headers $PHPIZE_DEPS libpq-dev libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql zip pcntl \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . .
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
