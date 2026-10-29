#!/usr/bin/env bash
# Capturas principales de la entrega (Chrome headless contra la demo en marcha). Uso, DESPUES de `demo.sh recorridos todos`, `I`, `J` y `V`:
#   DEMO_CLAVE_ARCHIVO=<archivo con la clave> PEDIDO_CANCELAR=.. PEDIDO_CANCELAR_MOVIL=.. PEDIDO_F6=.. PLAYWRIGHT_CORE=<ruta a playwright-core> \
#     bash packages/ByLopez/VentasAsesor/tests/Demo/visual/capturas_finales.sh
# Las capturas quedan en storage/logs/demo/capturas con nombre <escenario>__<rol>__<ancho>__<tema>__<paso>.png.
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")"
RAIZ="$(cd ../../../../../.. && pwd)"
set -a
. ../demo.env
set +a
[ -n "${DEMO_PASSWORD:-}" ] || DEMO_PASSWORD="$(tr -d '\r\n' < "$DEMO_CLAVE_ARCHIVO")"
export RUN_ID="${RUN_ID:-$(date +%Y%m%d%H%M%S)-$RANDOM}"
FALLOS=0
export DEMO_PASSWORD BASE_URL="http://$DEMO_HOST:$DEMO_PORT"
BIN="${MYSQL_BIN:-/c/xampp/mysql/bin}"
q() { "$BIN/mysql" -uroot -N -e "$1" "$DB_DATABASE" 2>/dev/null; }

E1_ENVIO="$(q "SELECT r.shipment_id FROM bylopez_lg_retornos r JOIN bylopez_lg_recepciones_devolucion x ON x.retorno_id = r.id JOIN bylopez_lg_unidades_defectuosas d ON d.order_id = r.order_id ORDER BY r.id LIMIT 1")"
LIQ="$(q "SELECT id FROM bylopez_lg_liquidaciones_transportadora WHERE estado='cerrada' ORDER BY id LIMIT 1")"
INTER="$(q "SELECT id FROM bylopez_lg_transportadoras WHERE codigo='INTER_RAPIDISIMO'")"
CARGA="$(q "SELECT MIN(id) FROM bylopez_cg_cargas")"
echo "ids: envio_devolucion=$E1_ENVIO liquidacion=$LIQ inter=$INTER carga=$CARGA cancelar=$PEDIDO_CANCELAR f6=$PEDIDO_F6"

cap() { # escenario rol ancho tema [ENV=valor ...]
  local esc="$1" rol="$2" ancho="$3" tema="$4"
  shift 4
  env "$@" node run_demo.cjs "$esc" "$rol" "$ancho" "$tema" 2>&1 | tr '\n' ' ' | sed 's/  */ /g' | cut -c1-400
  echo
}

# 1) Cancelacion del pendiente (escritorio claro y movil oscuro)
cap despacho_cancelar.cjs admin 1440 claro PEDIDO="$PEDIDO_CANCELAR"
# 2) F6 tras devolucion: la decision anterior ya no vale
cap despacho_f6.cjs bodega 1440 claro PEDIDO="$PEDIDO_F6"
# 3) Devolucion recibida (Entregas, drawer del envio con devolucion) y listado
cap pagina.cjs admin 1440 claro PAGINA="/admin/logistica/entregas?envio=$E1_ENVIO" PASO=devolucion_recibida_drawer
cap pagina.cjs admin 1440 claro PAGINA="/admin/logistica/entregas" PASO=entregas_listado
# 4) Cargues: historial y carga abierta con su resultado por fila
cap cargues_resultado.cjs admin 1440 claro CARGA="$CARGA"
# 5) Custodia y conciliacion del transportador
cap pagina.cjs admin 1440 claro PAGINA="/admin/logistica/cuentas" PASO=cuentas_custodia
cap pagina.cjs admin 1440 claro PAGINA="/admin/logistica/cuentas/liquidaciones/$LIQ" PASO=liquidacion_conciliacion
# 6) Comision (umbral 998 + 5, liquidacion y abonos) y cartera
cap pagina.cjs admin 1440 claro PAGINA="/admin/ventas-asesor/comisiones" PASO=comisiones
cap pagina.cjs admin 1440 claro PAGINA="/admin/ventas-asesor/cartera" PASO=cartera
# 7) Movil y tema oscuro de las mismas pantallas clave
cap despacho_cancelar.cjs admin 390 oscuro PEDIDO="$PEDIDO_CANCELAR_MOVIL"
cap pagina.cjs admin 390 oscuro PAGINA="/admin/ventas-asesor/comisiones" PASO=comisiones
cap pagina.cjs admin 390 oscuro PAGINA="/admin/logistica/cuentas" PASO=cuentas_custodia
echo "RUN_ID=$RUN_ID  escenarios con fallo o error inesperado: $FALLOS"
[ $FALLOS -eq 0 ]
