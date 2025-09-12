# -------- STAGE 1: Build de dependencias PHP con Composer --------
FROM composer:2 AS vendor

WORKDIR /app
# Copiamos solo composer.* primero para cachear capas
COPY composer.json composer.lock ./
# Instala dependencias sin dev (si no tienes lock, composer lo generará)
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

# Ahora copiamos el resto del proyecto y regeneramos autoload si hiciera falta
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

# -------- STAGE 2: Imagen de runtime con Nginx + PHP-FPM --------
FROM richarvey/nginx-php-fpm:latest

# Directorio público de Laravel
ENV DOCUMENT_ROOT=/var/www/html/public
ENV COMPOSER_ALLOW_SUPERUSER=1

# Copiamos el código de la app
WORKDIR /var/www/html
COPY . /var/www/html

# Copiamos vendor desde el stage anterior (evitamos correr composer aquí)
COPY --from=vendor /app/vendor /var/www/html/vendor

# Config Nginx
COPY conf/nginx/nginx-site.conf /etc/nginx/sites-enabled/default

# Permisos mínimos para Laravel
RUN chown -R www-data:www-data /var/www/html \
 && find storage -type d -exec chmod 775 {} \; \
 && find bootstrap/cache -type d -exec chmod 775 {} \;

# Entrypoint que hace key:generate, storage:link, migrate, etc.
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
