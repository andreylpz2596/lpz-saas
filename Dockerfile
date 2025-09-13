FROM richarvey/nginx-php-fpm:latest

# Composer dentro de la imagen final
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Paquetes necesarios para composer y VCS (git/unzip)
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*

ENV DOCUMENT_ROOT=/var/www/html/public
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www/html

# 1) Copiamos composer.* primero para cachear capas
COPY composer.json composer.lock ./

# 2) Instala dependencias PHP (sin dev) con salida VERBOSA para ver el error real si falla
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress -vvv

# 3) Ahora sí, copiamos el resto del código
COPY . .

# Config Nginx
COPY conf/nginx/nginx-site.conf /etc/nginx/sites-enabled/default

# Permisos mínimos para Laravel
RUN chown -R www-data:www-data /var/www/html \
 && find storage -type d -exec chmod 775 {} \; \
 && find bootstrap/cache -type d -exec chmod 775 {} \;

# Entrypoint (key:generate, migrate, etc.)
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
