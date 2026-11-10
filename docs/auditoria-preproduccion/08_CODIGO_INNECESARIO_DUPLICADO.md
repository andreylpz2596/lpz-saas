# 08 · Código innecesario y duplicado (candidatos; nada eliminado)

Método: cruces de lectura. (a) cada controlador contra los `routes.php`; (b) cada vista Blade contra referencias por nombre; (c) cada clase de `Services/` contra referencias en todo el paquete; (d) `dd/dump/var_dump/console.log`; (e) archivos del árbol de trabajo. Los cruces por nombre **no ven** uso dinámico (Blade dinámico, listeners, contenedor), por eso todo candidato exige verificación.

## Resultados de los cruces [C]

- Controladores sin ruta: **0**. Servicios sin referencia: **0** (el cruce terminó sin candidatos). Rutas sin ACL: ver doc 10.
- Código de depuración (`dd`, `dump`, `var_dump`, `print_r`, `console.log`, `debugger`) en `src`: **0**.
- Interpolación en SQL crudo: los `whereRaw/selectRaw` hallados usan constantes internas o `$porConfeccionar/$vigente` calculados por el servicio (`ConsolidadoConfeccionService:142-151`); no vienen del request. Revisar que ambas variables sean enteros/expresiones fijas (V-15).

## Candidatos

| # | Archivo | Motivo | Referencias | Uso dinámico posible | Riesgo | Confianza | Clase | Recomendación |
|---|---|---|---|---|---|---|---|---|
| 1 | `packages/ByLopez/CorteConfeccion.rar`, `VentasAsesor.rar` (8,5 MB) | copias comprimidas del código | ninguna | no | bajo | alta | **A** | Sacar del árbol (respaldo externo); añadir `*.rar` al `.gitignore` |
| 2 | `grep.exe.stackdump` | volcado de crash | ninguna | no | nulo | alta | **A** | borrar tras aprobación |
| 3 | `docs/Cartera_Completa_2026-09-12.xlsx`, `docs/Envios_Completos_2026-10-03.xlsx` | datos personales reales | docs 10/13/15 las citan como fuente | no | **privacidad** | alta | **B** | mover fuera del repo; ampliar `.gitignore` a `/docs/*.xlsx` |
| 4 | `docs/pruebas_cargues/` y `Cargues/docs/pruebas_cargues/` | posible duplicado de evidencia | doc 11/16 | no | bajo | media | **D/B** | comparar contenido y conservar una |
| 5 | `CorteConfeccion/.../ordenes-corte/partials/card-solicitud.blade.php` | sin referencias | solo docs | include dinámico no hallado | bajo | media | **B** | verificar con `grep` de `@include(` dinámicos y pruebas de pantalla |
| 6 | `CorteConfeccion/.../components/record-search.blade.php` | sin uso en Blade (`x-corteconfeccion::record-search`), citado por docs 03B y Cargues 01/04 | docs | componente anónimo | medio (docs lo prometen como reutilizable) | media | **B/C** | no eliminar sin decidir si Cargues lo usará |
| 7 | `Cargues/.../sp_cg_*_v1/v2.sql` | versiones anteriores de procedimientos | cargas históricas congeladas (`config_congelada`, versiones de tipo) | sí: una carga vieja puede apuntar a v1 | alto | alta | **C/E** | **no tocar** |
| 8 | `VentasAsesor/docs/30_COTIZACIONES.md` y `30_TESTING_POR_ASESORA_Y_DEVOLUCIONES.md` | numeración duplicada (otra del tipo ya ocurrió con 28/29) | — | — | nulo | alta | **D** | renumerar |
| 9 | `CorteConfeccion/docs/confeccion/INFORME_VALIDACION_2026-10-29.md` y `07_CIERRE_…` | informes históricos | docs | — | bajo | media | **C** | conservar como histórico |
| 10 | `CorteConfeccion/src/Resources/views/admin/partials/debugbar-local.blade.php` | solo con `APP_DEBUG` | incluido por vistas | — | nulo | alta | **C** | conservar |
| 11 | `lang/en` vs `lang/es` (Logística 1220/1218 líneas, etc.) | espejo de idiomas, no duplicado | — | — | — | alta | **E** | no tocar (CLAUDE.md exige todos los locales) |
| 12 | `public/themes/admin/default/build-bylopez/` | compilado de CSS propio | layouts | sí | medio | media | **B** | decidir si se versiona o se genera en el build |

## Duplicación (clase D) observada

- Resúmenes de pill/estado repetidos como closures en varias vistas de VentasAsesor (`$pill`, `$pillEstado`, `$pillPago`…): candidato a componente Blade compartido (P3).
- Archivos de vista de >1000 líneas con JavaScript embebido: `nueva-venta/index` (4630), `despachos/componente` (2136), `ordenes-confeccion/show` (1693), `create` (1463): mantenibilidad, **no** tocar antes de publicar.
- 66 usos de `{!! !!}`: revisados por muestreo; los de `InventarioMovimientoHtml` escapan con `e()`; el resto son insignias/píldoras construidas en servidor. Verificación completa pendiente (V-16, doc 10).

## Candidatos añadidos en la 2.ª etapa

| # | Archivo | Motivo | Clase | Recomendación |
|---|---|---|---|---|
| 13 | `VentasAsesor/tests/Demo/visual/bash.exe.stackdump` | 2.º volcado de crash | **A** | borrar tras aprobación y añadir `*.stackdump` al `.gitignore` |
| 14 | `docs/pruebas_cargues/Envios_Completos_2026-10-03_PRUEBA_240099900001.xlsx` | copia de prueba de una exportación **real** | **B (sensible)** | mover fuera del repo; no versionar |
| 15 | `CorteConfeccion/docs/confeccion/evidencia_2026-10-29/lectura_local_2026-11-02/*.png` y `evaluacion_ocr_comprobantes_reales.json` | comprobantes reales | **B (sensible)** | no versionar; sustituir por evidencia anonimizada |
| 16 | `CorteConfeccion/docs/confeccion/evidencia_*` (141 archivos: 105 PNG, 34 JSON) | evidencia de pruebas, no código | **C** | versionar solo la sanitizada; es peso, no riesgo funcional |
