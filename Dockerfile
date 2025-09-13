# -------- STAGE 1: instalar dependencias PHP con Composer --------
FROM composer:2 AS vendor

WORKDIR /app

# Copiamos sólo composer.* para cachear capas
COPY composer.json composer.lock ./

# Instala dependencias SIN dev e IGNORA requisitos de plataforma (extensiones),
# esto evita que falle el build por falta de ext en el entorno de compilación.
RUN composer install \
    --no-dev --prefer-dist --no-interaction --no-progress --no-scripts \
    --ignore-platform-reqs

# Ahora copiamos todo el proyecto (por si hay autoloads adicionales)
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

# -------- STAGE 2: runtime con Nginx + PHP-FPM --------
FROM richarvey/nginx-php-fpm:latest

ENV DOCUMENT_ROOT=/var/www/html/public
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Copiamos el código de la app
COPY . /var/www/html

# Copiamos vendor desde el stage anterior (ya resuelto)
COPY --from=vendor /app/vendor /var/www/html/vendor

# Config Nginx
COPY conf/nginx/nginx-site.conf /etc/nginx/sites-enabled/default

# Permisos mínimos Laravel
RUN chown -R www-data:www-data /var/www/html \
 && find storage -type d -exec chmod 775 {} \; \
 && find bootstrap/cache -type d -exec chmod 775 {} \;

# Entrypoint: genera APP_KEY, storage:link, migrate, seed, etc.
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
