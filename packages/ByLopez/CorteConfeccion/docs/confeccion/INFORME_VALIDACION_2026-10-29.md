# Informe de validación — Corte y Confección + Reasignación de prendas (2026-10-29)

Plan ejecutado: `Plan_Claude_CorteConfeccion_Reasignacion.md` (28-oct-2026). Alcance: `packages/ByLopez/CorteConfeccion/`.
Estados usados: **PROBADO**, **FALLÓ** (reproducido antes de corregir), **VERIFICADO EN CÓDIGO**, **SIMULADO**, **NO IMPLEMENTADO**, **PENDIENTE/BLOQUEADO**.

## 0. Entorno

| | |
|---|---|
| Rama / commit | `upgrade/bagisto-2.4.9` (HEAD al cierre `48dcd7fbba`; árbol de trabajo con cambios sin commit, sin push) |
| PHP / Laravel / Bagisto | 8.3.33 / 12.62.0 / 2.4.x |
| Motor SQL | MariaDB 10.4.28 (XAMPP, Windows) |
| Base de pruebas | `lpz_saas_testing` (transacciones por prueba; los tests de concurrencia confirman y borran lo suyo) |
| Base demo/visual | `lpz_saas_demo_cc_20261029` (clon de `lpz_saas_demo_20261028c` + 2 migraciones nuevas), servidor `php -S` local |
| Base dev | `lpz_saas`: migraciones nuevas **aplicadas** el 2026-10-29 (respaldo previo en `storage/logs/respaldos/`); 0 filas con familia sin rellenar, 0 reasignaciones |
| Navegador | Chrome headless vía Playwright (emulación; **no** Safari/iPhone físico) |

La suite del paquete **sí** viene configurada en la aplicación (`phpunit.xml`: «CorteConfeccion Unit/Feature Test»). Línea base antes de tocar nada: **405 passed (2.243 aserciones)**, 0 fallos.

## 1. Mapa real del flujo

```
Proveedor → Referencia (tela + color_option_id + gramaje + $/kg) → Compra (rollos nacen aquí) → Rollo (disponible)
  → Orden de corte V2 (rollos → tela/colores → línea → talla/eje → variantes simples)   [rollo: reservado_en_corte]
     pendiente_entrega → entregado_a_cortador → en_corte → reportado_por_cortador → recibido_bodega → cerrado   (anular solo antes de en_corte)
  → Recepción ÚNICA por orden (UNIQUE orden_corte_id): recibidas (INCLUYE defectuosas), merma única F3 → aptas = recibidas − defectuosas
  → Distribución (create/store de 1 taller + distribuir multi-taller): OrdenConfeccion por taller, disponible = aptas − Σ vigente
  → Taller reporta (ENT inmutable, no mueve stock) → Bodega recibe (buenas+defectuosas+faltantes = reportadas) → buenas = entrada_confeccion (1 por retorno_detalle)
  → Orden de confección: activa → en_confeccion → terminada_taller → recibida_bodega → cerrada   (anulada | reasignada)
Reasignación: mueve responsabilidad vigente entre talleres sin editar lo original (ver 06_REASIGNACION_PRENDAS.md)
Tarifas: vigencias por prestador + tipo de tela + familia; cada orden conserva SU tarifa por combinación (NULL = «Sin tarifa»)
```

Roles: admin (todo), bodega (ACL custom: distribuir, recibir, reasignar), cortador (reporta corte propio), taller (solo sus órdenes; reporta con ACL `reportar_taller`).
Saldos: aptas del corte = Σ responsabilidades vigentes + saldo sin asignar (invariante verificado por variante en R01–R23).

## 2. Hallazgos H01–H10 (reproducidos primero, corregidos después)

Cada prueba afirma el comportamiento correcto (`tests/Feature/HallazgosAuditoriaH01H10Test.php`). «Antes» = resultado en el código sin corregir.

| ID | Antes | Impacto | Corrección |
|---|---|---|---|
| H01 | **FALLÓ**: 2 filas del mismo `product_id` (80+80 con disponible 100) → se creó la orden (servicio y HTTP 302) | sobre-asignación de 160 sobre 100 | `create()` consolida por `product_id` y compara el total |
| H02 | **FALLÓ**: taller con ACL/`all` creó órdenes por el endpoint antiguo | un taller distribuía mercancía | `StoreOrdenConfeccionRequest::authorize()` usa `DistribucionConfeccionGate` |
| H03 | **FALLÓ ×3**: anular rollo reservado/agotado; editar pesos/costo de rollo usado (y «revivirlo» por peso); POST sin error | orden de corte apuntando a tela inexistente; costo histórico alterable | `RolloService::cancel/update` con estado fresco bajo bloqueo y `vinculoConOrdenCorte()` |
| H04 | **FALLÓ**: recepción con 1 de 2 detalles aceptada (no lanzó excepción) | unidades fuera para siempre (recepción única) | exige **todos** los detalles (0 si no se recibió) |
| H05 | **FALLÓ**: `agregarDetalle` aceptó un configurable | camino antiguo esquivaba el contrato V2 | exige variante simple con tela y color de los rollos; falla cerrado si no se puede verificar |
| H06 | **FALLÓ**: `cancel` con instancia vieja anuló una orden ya reportada | liberaba al pool lo ya trabajado | `cancel` transaccional, bloqueo recepción→orden, estado fresco; bloquea si hay ENT o reasignación vigente |
| H07 | combinaciones derivadas **en vivo** de `products.attribute_family_id` (lectura estática; la 1ª corrida no ejercitó el fallo por falta de 2ª familia) | si el catálogo reasigna la familia, la lectura histórica cambia y la tarifa guardada queda huérfana | familia **conservada** al crear el detalle (migración `…000002`, hook `creating`); prueba pasa tras la corrección |
| H08 | **FALLÓ**: cierre dejó `total_piezas_cortadas`=120 (reportado) aunque bodega recibió 110 | dos semánticas para el mismo campo | el cierre prioriza lo **verificado** por bodega; el reporte del cortador queda intacto |
| H09 | **FALLÓ**: cambiar proveedor de la compra dejó rollos vinculados con el proveedor viejo | cabecera y rollos divergen | con rollos vinculados, el proveedor es inmutable (error explícito) |
| H10 | se rechazaba la 2ª recepción/reporte por estado/UNIQUE (**no falló**) | sin bloqueo, en carrera real daría 500 | `lockForUpdate` + `refresh()` de la orden en recepción, reporte y cierre. **Sin prueba concurrente real de recepción** (solo secuencial) |

**H11 (nuevo, NO corregido, requiere decisión):** un usuario con rol `CORTADOR` y la ACL `…ordenes_confeccion.operar` (o `permission_type=all`) puede **anular órdenes de confección ajenas** (`OrdenConfeccionGate::puedeOperar` solo restringe por ownership a los usuarios de taller). Hoy lo frena únicamente la configuración del rol. Propuesta: denylist de `cortador` en `puedeOperar` como ya tienen reportar/distribuir.

**Otras observaciones:** (a) `numero_factura` sin unicidad proveedor+factura (VERIFICADO EN CÓDIGO; no se impuso regla inventada); (b) **no existe ruta para anular compras** (NO IMPLEMENTADO; el enum `Anulada` no implementa el flujo); (c) `php artisan bagisto:translations:check` falla en el paquete Root de Bagisto (ajeno a ByLopez; `en/app.php` de este módulo delega en `es`); (d) el DataGrid de órdenes devuelve 500 con `filters[taller_id][]=` mal formado (se usa `?taller_id=`); (e) diagnóstico de catálogo real: exit 0 pero **0 errores y 5 advertencias** de 78 configurables/304 simples.

**Hallazgo visual (no lo detectaban las pruebas HTTP):** el layout admin de Bagisto **no publica** `meta csrf-token`; el primer `fetch` del modal enviaba el token vacío y el POST real fallaba (Bagisto lo muestra como «500 Error Interno»). Corregido (`data-csrf` en el modal) con aserción en R22.

## 3. Archivos y migraciones

Nuevos: migraciones `2026_10_29_000001_create_bylopez_cc_reasignaciones_tables`, `2026_10_29_000002_add_attribute_family_snapshot_to_detalles` (aditivas, con `down()`); `Models/Reasignacion`, `ReasignacionLinea`; `Enums/EstadoReasignacion`, `SituacionFisicaReasignacion`; `Services/ReasignacionService`; `Support/ReasignacionGate`; `Http/Requests/ReasignarPrendasRequest`; `Exceptions/ReasignacionConflictoException`; parciales `reasignaciones-orden` y `reasignaciones-taller`; docs `06_REASIGNACION_PRENDAS.md`, este informe y `evidencia_2026-10-29/`.
Modificados: `OrdenConfeccionService` (cancel, estado, públicos `recalcularEstado/generarCodigo/validarProductosSimples`), `DisponibilidadConfeccionService`, `OrdenTarifaService`, `OrdenCorteService`, `RecepcionCorteService`, `RolloService`, `CompraTelaService`, modelos de detalle y orden, `EstadoOrdenConfeccion` (+`reasignada`), 3 DataGrids/controladores de resumen, vistas (ficha de orden, ficha de taller, orden de corte), `acl.php`, `routes.php`, `lang/es`, y el CSS compilado `public/themes/admin/default/build-bylopez/corteconfeccion.css` (build propio del paquete; no se tocó ningún asset core).

## 4. Matriz P / R (esperado vs obtenido)

**R01–R23: PROBADO** (`ReasignacionPrendasTest`, 25 pruebas). Resultado clave R01: Jairo 700→600, Andrea 300→400, M 200/200, total 1.000, stock y movimientos sin cambios, orden de Andrea de 300 intacta + otra de 100. R23: **985 buenas + 8 defectuosas + 7 faltantes**, stock físico **+985** (S +294, M +395, L +296), las 3 órdenes quedan `cerrada`, disponible por variante 0; control sin reasignación: +985. R13/R14 además con **procesos PHP reales** (`tests/Concurrencia`, RCC-1..4): 2 reasignaciones sobre el mismo saldo → 1 gana; reasignar vs reportar → 1 gana y Σ ≤ 300; reasignar vs anular → una sola; misma llave simultánea → una operación y una orden destino. R16/R17/R21 (cancelar, revertir, cadena A→B→C) probados en orden estricto.

| Área | Estado | Evidencia / observación |
|---|---|---|
| P01 Proveedores, referencias, personas | PROBADO (regresión existente) | `ProveedorTelaVistaDetalle`(23), `RolloColorF1D`, `ColorEstructuradoF1A`, `RolesYTalleres`, `CortadorFichaYAislamiento`. No se re-ejecutó cada sub-caso fino de modal/duplicados uno por uno |
| P02 Compra / carga masiva | PROBADO + H09 | `CompraTelaCargaMasiva`(7), `BlindajeRollosCompraPreF4`(27). Facturas repetidas: permitido (código). Anulación de compra: NO IMPLEMENTADO |
| P03 Inventario de rollos | PROBADO + H03 | `RollosResumenYFiltro`, `RollosPaginaRender`. Dos órdenes simultáneas sobre los mismos rollos con procesos reales: **PENDIENTE** |
| P04 Catálogo V2 y adicionales | PROBADO + H05 | `ResolvedorVarianteCorteService`(28), `CreacionV2…`(19), `Diagnostico…`(14), `AdicionalesCortadorF5`(14). Camino positivo de `agregarDetalle` con catálogo completo: no cubierto (solo rechazo) |
| P05 Orden de corte / recepción única | PROBADO + H04/H08/H10 | `OrdenCorteResultadoYRecepcion`(35), `MermaTotalOrdenCorteF3`(20) |
| P06 Distribución | PROBADO + H01/H02 | `DistribucionConfeccionTest`(65), `SEC01…`(10), `DistribuirBaseFrontend`(5) |
| P07 Reasignación | PROBADO | R01–R23 |
| P08 Confección y retornos ENT | PROBADO | RET-* en `DistribucionConfeccionTest`; N1 **no** implementado ni simulado |
| P09 Inventario y cierre | PROBADO (suite existente) | `InventarioAjusteManual`(9), `InventarioMovimientoVista`(7). Entrada externa con módulos consumidores: **PENDIENTE** (no verificado aquí) |
| P10 Tarifas | PROBADO (4 pruebas nuevas) | `TarifasPorCombinacionTest`: orden mixta, «Sin tarifa» = NULL, ficha posterior no retroactiva, pactar con motivo/evento/permiso. Cuenta/pagos: **NO IMPLEMENTADO** (totales siguen «referenciales») |
| P11 Permisos y aislamiento | PROBADO (5 nuevas) + H11 abierto | `PermisosMatrizEndpointsTest`: ownership por URL/`taller_id`, taller/cortador con `all` bloqueados en distribuir/reasignar/tarifas/ajuste, sin ACL falla cerrado, bodega/admin transversales, escape HTML, el taller no ve al otro taller |
| P12 Visual / scroll / rendimiento | PROBADO en Chromium; **iPhone físico PENDIENTE** | 15 pantallas × 360/390/430/1280/1440 = 75 cargas: HTTP 200, **0 px de desbordamiento horizontal**, **0 errores** de consola/HTTP≥500, scroll vertical responde arriba/centro/abajo; modal: máximos, antes/después, error, envío real (HTTP 200 → historial), scroll del modal y de la página tras cerrarlo (sin bloqueo). Tema oscuro y volumen/N+1 con datos grandes: **no medidos** |

## 5. Resultados actuales (comandos y entorno arriba)

| Comando | Resultado |
|---|---|
| Línea base `vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"` | 405 passed / 2.243 aserciones |
| Tras el trabajo (primera regresión completa) | 443 passed / 2.462 aserciones (sin las 2 suites nuevas de tarifas/permisos) |
| Regresión final | **452 passed / 2.544 aserciones, 0 fallos** (≈7 min) con las 2 suites nuevas de tarifas y permisos; incluye 405 previas + H01–H10 (13) + R01–R23 (25) + tarifas (4) + permisos (5) |
| `vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Concurrencia` (procesos reales, base de pruebas) | 4 passed (19 aserciones); evidencia JSON en `evidencia_2026-10-29/RCC-*.json`; 0 filas residuales |
| `node tests/js/moneda.mjs` y `moneda_dom.mjs` | JS-OK ×2 (DOM simulado: no acredita navegador) |
| `vendor/bin/pint --test packages/ByLopez/CorteConfeccion` | passed |
| `php artisan route:list --path=corte-confeccion` | 87 rutas del módulo (+4 de reasignación) |
| `php artisan corteconfeccion:diagnosticar-catalogo --json` | exit 0; 0 errores, 5 advertencias |
| Recorrido visual (Playwright, demo aislada) | ver P12; resultados en `evidencia_2026-10-29/resultado_*.json` |

No se reutiliza «382 passed» del STATUS.

## 6. Demo reproducible

1. `mysqldump` de una base demo existente → `lpz_saas_demo_cc_YYYYMMDD`; `DB_DATABASE=… php artisan migrate --force --path=…/2026_10_29_000001… --path=…/2026_10_29_000002…`.
2. `DB_DATABASE=… DEMO_CC_PASSWORD=… php packages/ByLopez/CorteConfeccion/tests/Demo/preparar_corte_demo.php` (falla si la base no empieza por `lpz_saas_demo_` o es la del `.env`): crea proveedor→compra→corte→reporte→recepción→distribución (S300/M400/L300; Jairo 700 / Andrea 300) por los servicios reales, usuarios `cc.admin|bodega|jairo|andrea@bylopez.test` y un 2.º corte con una reasignación aplicada y otra en tránsito.
3. Servidor: `cd public && php -d zend_extension=opcache -d opcache.enable_cli=1 -S 127.0.0.1:8021 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php` con `DB_DATABASE` de la demo (con opcache: 0,2 s por página; sin él, 2–3 s y se bloquea).
4. `node tests/Demo/visual_corte.cjs <salida>` (`PLAYWRIGHT_CORE`, `DEMO_CC_PASSWORD`, `DEMO_CC_IDS`, `ANCHOS`, `MODO=pantallas|flujo|todo`).
   Reasignación 700/300 → 600/400: modal «Reasignar prendas» en la ficha de Jairo (captura `modal-lleno__1440.png`).

## 7. Pasos pendientes en iPhone físico (Camilo)

Con Safari en el iPhone, en la ficha de una orden de confección con saldo: (1) abrir «Reasignar prendas» desde arriba, centro y abajo de la página y comprobar que se puede desplazar la ficha detrás y el contenido del modal; (2) escribir en «Mover» y «Motivo» (teclado) y confirmar que el botón no queda tapado; (3) cerrar el modal y verificar que la página sigue desplazándose (la auditoría previa halló 12 px de scroll por el contenedor `overflow-x-hidden` de Bagisto); (4) repetir en modo oscuro. Mientras no se haga, el caso queda **PENDIENTE**.

## 8. Alcance respetado

`packages/Webkul/`, `vendor/`, `bootstrap/` y proveedores core **sin cambios** (`git status` vacío en esas rutas). Sin push ni despliegue. Sin tocar datos reales: en `lpz_saas` solo se agregaron columnas/tablas (con respaldo) y se rellenó la familia de los detalles existentes; no hay órdenes ni reasignaciones nuevas allí. Quedan sin commit todos los cambios. `packages/ByLopez/Logistica/…/guardia-scroll.blade.php` ya estaba modificado antes de empezar y no es de este trabajo.

## 9. Decisiones pendientes

1. **Cuenta/pagos a prestadores (Fase 3) y N1** — siguen sin implementar; la reasignación no los toca ni los bloquea (los eventos de tarifas no se mueven).
2. **H11** — ¿denylist del rol `cortador` en `puedeOperar` (anular órdenes de confección)?
3. **ACL `reasignar`** — asignarla a BODEGA en Configuración → Roles (admin la tiene por `all`).
4. **«En proceso»** — hoy lo declara el operador; control real exigiría registrar el despacho físico al taller (no existe).
5. Unicidad proveedor+factura y anulación de compras — solo si el negocio las quiere.
