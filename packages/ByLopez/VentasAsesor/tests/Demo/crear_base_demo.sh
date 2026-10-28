#!/usr/bin/env bash
# Crea una base AISLADA para la demostracion a partir de la estructura y el CATALOGO de la base de desarrollo (solo lectura sobre el origen).
# No copia pedidos, clientes, pagos ni envios. Uso (desde la raiz del repositorio):
#   DEMO_DB=lpz_saas_demo_20261028 ORIGEN=lpz_saas bash packages/ByLopez/VentasAsesor/tests/Demo/crear_base_demo.sh
# Credenciales: DB_USERNAME / DB_PASSWORD del entorno o, si faltan, las del .env (no se imprimen). MySQL en C:\xampp\mysql\bin por defecto.
set -euo pipefail
cd "$(dirname "$0")/../../../../.."

DEMO_DB="${DEMO_DB:?Indique DEMO_DB (debe empezar por lpz_saas_demo_)}"
ORIGEN="${ORIGEN:-lpz_saas}"
case "$DEMO_DB" in lpz_saas_demo_*) ;; *) echo "DEMO_DB debe empezar por lpz_saas_demo_" >&2; exit 1;; esac
[ "$DEMO_DB" != "$ORIGEN" ] || { echo "La base de la demo no puede ser el origen" >&2; exit 1; }

BIN="${MYSQL_BIN:-/c/xampp/mysql/bin}"
U="${DB_USERNAME:-$(grep '^DB_USERNAME=' .env | cut -d= -f2)}"
P="${DB_PASSWORD:-$(grep '^DB_PASSWORD=' .env | cut -d= -f2)}"
AUTH=(-u"$U"); [ -n "$P" ] && AUTH+=(-p"$P")
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

if "$BIN/mysql" "${AUTH[@]}" -N -e "SHOW DATABASES LIKE '$DEMO_DB'" | grep -q .; then echo "La base $DEMO_DB ya existe: elija otro nombre (no se sobrescribe)." >&2; exit 1; fi

echo "1/5 estructura de $ORIGEN"; "$BIN/mysqldump" "${AUTH[@]}" --no-data --routines --skip-triggers "$ORIGEN" > "$TMP/estructura.sql"
echo "2/5 catalogo de $ORIGEN";  "$BIN/mysqldump" "${AUTH[@]}" --no-create-info --skip-triggers --single-transaction "$ORIGEN" $(tr '\r\n' '  ' < packages/ByLopez/VentasAsesor/tests/Demo/tablas_catalogo.txt) > "$TMP/datos.sql"
echo "3/5 creando $DEMO_DB"; "$BIN/mysql" "${AUTH[@]}" -e "CREATE DATABASE \`$DEMO_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
echo "4/5 cargando"; "$BIN/mysql" "${AUTH[@]}" "$DEMO_DB" < "$TMP/estructura.sql"; "$BIN/mysql" "${AUTH[@]}" "$DEMO_DB" < "$TMP/datos.sql"
# Las tablas intermedias de Cargues (bylopez_cgi_*) vienen del origen sin sus registros del modulo; el modulo las CREA al activar un tipo y se niega a adoptar tablas ajenas.
for t in $("$BIN/mysql" "${AUTH[@]}" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$DEMO_DB' AND table_name LIKE 'bylopez_cgi\_%'"); do
  "$BIN/mysql" "${AUTH[@]}" "$DEMO_DB" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS \`$t\`"
done
echo "5/5 migraciones propias pendientes en la demo (solo las de Logistica listadas; nunca un migrate general)"
for m in packages/ByLopez/Logistica/src/Database/Migrations/2026_10_27_100019_*.php packages/ByLopez/Logistica/src/Database/Migrations/2026_10_27_100020_*.php packages/ByLopez/Logistica/src/Database/Migrations/2026_10_28_100021_*.php; do
  DB_DATABASE="$DEMO_DB" php artisan migrate --force --path="$m" | sed 's/\x1b\[[0-9;]*m//g' | grep -E "DONE|Nothing|FAIL" || true
done
echo "Listo: $DEMO_DB. Siguiente: DB_DATABASE=$DEMO_DB DEMO_PASSWORD=... php packages/ByLopez/VentasAsesor/tests/Demo/preparar_demo.php"
