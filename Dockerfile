# Imagen con Nginx + PHP-FPM lista para Laravel
FROM richarvey/nginx-php-fpm:latest


# Define el docroot para Laravel
ENV DOCUMENT_ROOT=/var/www/html/public


# Copiamos el código de la app
COPY . /var/www/html


# Copiamos la config de Nginx
COPY conf/nginx/nginx-site.conf /etc/nginx/sites-enabled/default


# Instala dependencias PHP y prepara cachés (en build)
WORKDIR /var/www/html
RUN composer install \
--no-dev \
--prefer-dist \
--no-interaction \
--no-progress \
&& chown -R www-data:www-data /var/www/html \
&& find storage -type d -exec chmod 775 {} \; \
&& find bootstrap/cache -type d -exec chmod 775 {} \;


# Copiamos nuestro entrypoint que hará migraciones/seed al arrancar
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh


# Render permite override del Start Command, pero dejamos un ENTRYPOINT sensato
ENTRYPOINT ["/entrypoint.sh"]