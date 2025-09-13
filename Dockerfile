FROM richarvey/nginx-php-fpm:latest

# Instalar Composer en la imagen final
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# En Alpine usamos apk (no apt). Instala git y unzip para Composer.
RUN apk add --no-cache git unzip

ENV DOCUMENT_ROOT=/var/www/html/public
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www/html

# 1) Copia composer.* primero para cachear capas
COPY composer.json composer.lock ./

# 2) Instala dependencias PHP (sin dev). -vvv para ver errores si algo falla.
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress -vvv

# 3) Copia el resto del código
COPY . .

# Nginx
COPY conf/nginx/nginx-site.conf /etc/nginx/sites-enabled/default

# Permisos mínimos para Laravel
RUN chown -R www-data:www-data /var/www/html \
 && find storage -type d -exec chmod 775 {} \; \
 && find bootstrap/cache -type d -exec chmod 775 {} \;

# Entrypoint (key:generate, migrate, etc.)
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
