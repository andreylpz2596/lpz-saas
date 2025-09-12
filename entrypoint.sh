#!/usr/bin/env bash
set -e


# Variables útiles (Render inyecta variables por entorno)
: "${APP_ENV:=production}"
: "${APP_DEBUG:=false}"


cd /var/www/html


# Si no existe .env, lo creamos a partir del ejemplo
if [ ! -f .env ]; then
cp .env.example .env || true
fi


# Genera APP_KEY si falta
php artisan key:generate --force || true


# En entornos detrás de proxy HTTPS (Render), fuerza https si APP_URL usa https
php -r "file_exists('app/Providers/AppServiceProvider.php') || exit(0);"


# Enlace de storage (si falla, que no rompa el arranque)
php artisan storage:link || true


# Migraciones y seeds (si la DB aún no está lista, vuelve a intentar unos segundos)
(&) {
for i in {1..10}; do
if php artisan migrate --force; then
php artisan db:seed --force || true
break
else
echo "[entrypoint] DB no disponible aún. Reintentando ($i/10)..."
sleep 5
fi
done
}


# Caches de Laravel (no imprescindible, pero recomendado)
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true


# Si existe script post-deploy, ejecútalo
if [ -f scripts/post-deploy.sh ]; then
bash scripts/post-deploy.sh || true
fi


# Lanza el proceso por defecto (Nginx + PHP-FPM) de la imagen base
exec /start.sh