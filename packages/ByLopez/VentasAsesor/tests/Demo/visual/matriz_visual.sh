#!/usr/bin/env bash
# Matriz visual: pantallas clave x rol x ancho (escritorio 1440 / movil 390) x tema (claro / oscuro). Salida: una linea JSON por captura.
# Uso: DEMO_PASSWORD=... PLAYWRIGHT_CORE=<ruta a playwright-core> bash matriz_visual.sh > salida.txt
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")"
declare -a CASOS=(
  "admin /admin/ventas-asesor/comisiones comisiones"
  "admin /admin/logistica/cuentas cuentas"
  "admin /admin/ventas-asesor/cartera cartera"
  "admin /admin/logistica/despachos despachos"
  "admin /admin/logistica/entregas entregas"
  "asesora /admin/ventas-asesor/pedidos mis_ventas"
  "asesora /admin/ventas-asesor/comisiones comisiones_propias"
  "asesora /admin/ventas-asesor/nueva-venta/pantalla nueva_venta"
  "umbral /admin/ventas-asesor/comisiones comisiones_propias"
  "bodega /admin/logistica/despachos despachos"
  "bodega /admin/logistica/entregas entregas"
)
for caso in "${CASOS[@]}"; do
  read -r rol ruta paso <<< "$caso"
  for ancho in 1440 390; do
    for tema in claro oscuro; do
      salida=$(PAGINA="$ruta" PASO="$paso" node run_demo.cjs pagina.cjs "$rol" "$ancho" "$tema" 2>&1 | tr '\n' ' ' | sed 's/  */ /g')
      echo "{\"rol\":\"$rol\",\"ancho\":$ancho,\"tema\":\"$tema\",\"paso\":\"$paso\",\"salida\":\"$(echo "$salida" | sed 's/"/\\"/g' | cut -c1-600)\"}"
    done
  done
done
