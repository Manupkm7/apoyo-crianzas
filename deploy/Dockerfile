FROM php:8.4-fpm-alpine

# install-php-extensions maneja todas las dependencias del sistema automáticamente.
# Evita tener que instalar autoconf, pecl, librerías de desarrollo, etc. manualmente.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    pdo_pgsql \
    zip \
    mbstring \
    xml \
    bcmath \
    pcntl \
    redis

# ─── Composer ───────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ─── Código de la aplicación ────────────────────────────────────────────────
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
