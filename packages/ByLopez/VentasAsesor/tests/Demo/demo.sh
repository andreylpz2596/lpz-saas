#!/usr/bin/env bash
# Arranque REPRODUCIBLE de la demo aislada. Uso (desde cualquier directorio):
#   bash packages/ByLopez/VentasAsesor/tests/Demo/demo.sh <iniciar|estado|parar|verificar|preparar|recorridos|worker|php> [args]
# - Todo proceso recibe la MISMA configuracion desde demo.env (base, activacion de comisiones/C6, correo en log, cola sync...).
# - artisan y php se ejecutan SIEMPRE desde la raiz del proyecto; el servidor, desde public/.
# - Falla de forma segura si DB_DATABASE no empieza por lpz_saas_demo_ o coincide con la base del .env compartido.
# - La clave de los usuarios demo sale de DEMO_PASSWORD o del archivo apuntado por DEMO_CLAVE_ARCHIVO (nunca de un archivo versionado).
set -uo pipefail
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RAIZ="$(cd "$AQUI/../../../../.." && pwd)"
cd "$RAIZ"
set -a
. "${DEMO_ENV_ARCHIVO:-$AQUI/demo.env}"
set +a

# Cuenta restringida de VALIDAR propia de la demo (la de desarrollo tiene permisos sobre la base de desarrollo y el modulo la rechazaria).
# La clave se genera al crearla, vive solo en este archivo local (storage/logs, fuera de git) y nunca se imprime.
CUENTA_CARGUES="$RAIZ/storage/logs/demo/cuenta_cargues.env"
if [ -f "$CUENTA_CARGUES" ]; then
  set -a
  . "$CUENTA_CARGUES"
  set +a
fi

PIDS="$RAIZ/storage/logs/demo/pids"
mkdir -p "$PIDS"
PHP="${PHP_BIN:-/c/xampp/php/php}"

seguridad() {
  case "$DB_DATABASE" in
    lpz_saas_demo_*) ;;
    *) echo "BLOQUEADO: DB_DATABASE='$DB_DATABASE' no es una base demo (lpz_saas_demo_*)." >&2; exit 2 ;;
  esac
  local compartida
  compartida="$(grep -E '^DB_DATABASE=' .env | cut -d= -f2 | tr -d '\r')"
  [ "$DB_DATABASE" != "$compartida" ] || { echo "BLOQUEADO: la base demo coincide con la del .env compartido." >&2; exit 2; }
  case "$DEMO_HOST" in
    127.0.0.1|localhost) ;;
    *) echo "BLOQUEADO: DEMO_HOST debe ser local." >&2; exit 2 ;;
  esac
  [ ! -f bootstrap/cache/config.php ] || { echo "BLOQUEADO: existe bootstrap/cache/config.php; ignoraria las variables (php artisan config:clear)." >&2; exit 2; }
  "$PHP" "$AQUI/verificar_entorno.php" --solo-seguridad || exit 2
}

clave() {
  if [ -z "${DEMO_PASSWORD:-}" ] && [ -n "${DEMO_CLAVE_ARCHIVO:-}" ] && [ -f "$DEMO_CLAVE_ARCHIVO" ]; then
    DEMO_PASSWORD="$(tr -d '\r\n' < "$DEMO_CLAVE_ARCHIVO")"
    export DEMO_PASSWORD
  fi
}

winpid() { ps -p "$1" -W 2>/dev/null | awk 'NR==2{print $4}'; }

vivo() {
  [ -f "$PIDS/$1.pid" ] && tasklist //FI "PID eq $(cat "$PIDS/$1.pid")" 2>/dev/null | grep -qi php
}

arrancar() {
  local n="$1"
  shift
  if vivo "$n"; then echo "$n ya esta corriendo (pid $(cat "$PIDS/$n.pid"))"; return; fi
  "$@" > "$RAIZ/storage/logs/demo/$n.log" 2>&1 &
  local p=$!
  sleep 2
  winpid "$p" > "$PIDS/$n.pid"
  echo "$n iniciado (pid $(cat "$PIDS/$n.pid"))"
}

detener() {
  if vivo "$1"; then
    taskkill //F //T //PID "$(cat "$PIDS/$1.pid")" > /dev/null 2>&1 && echo "$1 detenido"
  else
    echo "$1 no estaba corriendo"
  fi
  rm -f "$PIDS/$1.pid"
}

orden="${1:-estado}"
[ $# -gt 0 ] && shift

case "$orden" in
  iniciar)
    seguridad
    cd public && rm -f "$PIDS"/*.entorno.json; arrancar servidor "$PHP" -S "$DEMO_HOST:$DEMO_PORT" "$AQUI/router_demo.php"
    cd "$RAIZ"
    arrancar scheduler "$PHP" "$AQUI/proceso_demo.php" scheduler schedule:work
    if [ "${1:-}" = "--con-worker" ]; then arrancar worker "$PHP" "$AQUI/proceso_demo.php" worker queue:work cargues --queue=cargues --tries=1 --timeout=900 --sleep=1; fi
    sleep 2
    clave
    "$PHP" "$AQUI/verificar_entorno.php" --http
    ;;
  parar) detener servidor; detener scheduler; detener worker ;;
  worker) seguridad; arrancar worker "$PHP" "$AQUI/proceso_demo.php" worker queue:work cargues --queue=cargues --tries=1 --timeout=900 --sleep=1 ;;
  estado)
    for n in servidor scheduler worker; do
      if vivo "$n"; then echo "$n: corriendo (pid $(cat "$PIDS/$n.pid"))"; else echo "$n: detenido"; fi
    done
    curl -s -o /dev/null -w "HTTP /admin/login: %{http_code}\n" "http://$DEMO_HOST:$DEMO_PORT/admin/login" || true\n    "$PHP" "$AQUI/verificar_entorno.php" --procesos || true
    ;;
  verificar) seguridad; clave; "$PHP" "$AQUI/verificar_entorno.php" --http ;;
  preparar) seguridad; clave; "$PHP" "$AQUI/preparar_demo.php" "$@" ;;
  recorridos)
    seguridad; clave
    [ -n "${DEMO_PASSWORD:-}" ] || { echo "Falta DEMO_PASSWORD o DEMO_CLAVE_ARCHIVO" >&2; exit 2; }
    "$PHP" "$AQUI/recorridos.php" "$@"
    ;;
  cuenta-cargues)
    seguridad
    mkdir -p "$RAIZ/storage/logs/demo"
    if [ -f "$CUENTA_CARGUES" ]; then echo "La cuenta de VALIDAR de la demo ya existe ($CUENTA_CARGUES)."; exit 0; fi
    BIN="${MYSQL_BIN:-/c/xampp/mysql/bin}"
    U="${DB_USERNAME:-$(grep '^DB_USERNAME=' .env | cut -d= -f2 | tr -d '\r')}"
    P="${DB_PASSWORD:-$(grep '^DB_PASSWORD=' .env | cut -d= -f2 | tr -d '\r')}"
    AUTH=(-u"$U")
    [ -n "$P" ] && AUTH+=(-p"$P")
    CLAVE="$("$PHP" -r 'echo bin2hex(random_bytes(16));')"
    "$BIN/mysql" "${AUTH[@]}" -e "CREATE USER IF NOT EXISTS 'cargues_validar_demo'@'127.0.0.1' IDENTIFIED BY '$CLAVE'" || exit 1
    printf 'CARGUES_VALIDAR_DB_USERNAME=cargues_validar_demo\nCARGUES_VALIDAR_DB_PASSWORD=%s\n' "$CLAVE" > "$CUENTA_CARGUES"
    echo "Cuenta cargues_validar_demo@127.0.0.1 creada (sin permisos; el modulo concede los minimos sobre la base demo al activar el tipo). Clave en $CUENTA_CARGUES (no versionado)."
    ;;
  crear-base)
    # Crea la base demo definida en demo.env (estructura + catalogo del origen, sin pedidos ni clientes). No sobrescribe una existente.
    seguridad_nombre() { case "$DB_DATABASE" in lpz_saas_demo_*) ;; *) echo "BLOQUEADO: DB_DATABASE no es lpz_saas_demo_*" >&2; exit 2 ;; esac; }
    seguridad_nombre
    DEMO_DB="$DB_DATABASE" bash "$AQUI/crear_base_demo.sh"
    ;;
  preparar-cargues)
    seguridad; clave
    bash "$0" cuenta-cargues && "$PHP" "$AQUI/cuenta_cargues_limpiar.php" && "$PHP" "$AQUI/preparar_cargues.php"
    ;;
  ensayo)
    # Ensayo general: PREP, recorridos A-J y preparacion visual V (cada uno imprime su conciliacion y falla si algun numero no coincide).
    seguridad; clave
    [ -n "${DEMO_PASSWORD:-}" ] || { echo "Falta DEMO_PASSWORD o DEMO_CLAVE_ARCHIVO" >&2; exit 2; }
    for r in PREP A B C D E F G H I J K V; do
      echo; echo "######## $r"
      "$PHP" "$AQUI/recorridos.php" "$r" || { echo "FALLO el recorrido $r" >&2; exit 1; }
    done
    ;;
  php) seguridad; clave; "$PHP" "$@" ;;
  *) echo "Uso: demo.sh <crear-base|preparar|preparar-cargues|iniciar [--con-worker]|ensayo|recorridos A..K|todos|estado|verificar|parar|worker|php script.php>" >&2; exit 1 ;;
esac
