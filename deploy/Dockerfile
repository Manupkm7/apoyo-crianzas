# Imagen PHP-FPM para producción del Sistema de Apoyo a la Crianza.
# Basada en Alpine para mantener el tamaño pequeño.
# En desarrollo se usa Laravel Sail; esta imagen es solo para producción.

FROM php:8.3-fpm-alpine

# ─── Dependencias del sistema ──────────────────────────────────────────────
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    oniguruma-dev \
    libxml2-dev \
    linux-headers

# ─── Extensiones PHP ────────────────────────────────────────────────────────
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    zip \
    mbstring \
    xml \
    bcmath \
    pcntl

# Redis (via PECL — no está en las extensiones core de PHP)
RUN pecl install redis && docker-php-ext-enable redis

# ─── Composer ───────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ─── Código de la aplicación ────────────────────────────────────────────────
WORKDIR /var/www/html

# Copiamos lock files primero para aprovechar la cache de capas de Docker:
# si composer.json y composer.lock no cambiaron, esta capa se reutiliza.
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --prefer-dist

# Ahora sí copiamos el resto del código
COPY . .

# Autoloader optimizado para producción (classmaps precalculados)
RUN composer dump-autoload --optimize --no-dev

# Permisos para que PHP-FPM (www-data) pueda escribir en storage y cache
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
