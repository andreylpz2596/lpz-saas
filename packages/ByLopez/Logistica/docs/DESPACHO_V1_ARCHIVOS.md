# Despacho V1 + Corregir guía — inventario de archivos para el cierre de etapa

**Fecha:** 2026-10-09 · **Estado:** implementado en desarrollo, sin commit, pendiente de la prueba manual de Camilo.
**Para qué sirve:** el repositorio acumula varias etapas sin commit (COSTOS, LOG-01, G-11, COM-01…03 y fases anteriores de VentasAsesor). Este documento separa **qué es de Despacho V1 (incluye «Corregir guía»)** para poder cerrarlo limpiamente después de la validación manual.

> HEAD de referencia: `2af0855591`. Webkul y CorteConfeccion: sin cambios.

## 1. Archivos NUEVOS que son exclusivamente de Despacho V1 (sin seguimiento en git)

### Paquete Logística (`packages/ByLopez/Logistica/`)
| Grupo | Archivos |
|---|---|
| Migraciones | `src/Database/Migrations/2026_10_09_100004_create_bylopez_lg_guias_tables.php` · `src/Database/Migrations/2026_10_09_100005_create_bylopez_lg_guia_correcciones_table.php` |
| Modelos | `src/Models/Guia.php` · `src/Models/GuiaArchivo.php` · `src/Models/GuiaCorreccion.php` |
| Enums | `src/Enums/OrigenGuia.php` · `src/Enums/PagadorFlete.php` · `src/Enums/EstadoRecaudo.php` · `src/Enums/OrigenArchivoGuia.php` |
| Contratos | `src/Contracts/SugiereRecaudo.php` · `src/Contracts/AccesoGuiaPedido.php` |
| Objetos de solicitud | `src/DataObjects/SolicitudDespacho.php` · `src/DataObjects/SolicitudRecogida.php` · `src/DataObjects/ResultadoDespacho.php` |
| Servicios | `src/Services/Despachos/DespachoService.php` · `UnidadesDespachables.php` · `ContextoDespachoService.php` · `ConsultaDespachoService.php` · `src/Services/Guias/GuiaArchivoService.php` · `ConsultaGuiaService.php` · `CorregirGuiaService.php` |
| Soporte | `src/Support/RecaudoSugeridoBagisto.php` · `src/Support/AccesoGuiaPorPermiso.php` |
| Controladores | `src/Http/Controllers/Admin/DespachosController.php` · `src/Http/Controllers/Admin/GuiasController.php` · `src/Http/Controllers/Concerns/ResponderOperacionesLogisticas.php` |
| Vistas | `src/Resources/views/admin/despachos/componente.blade.php` (drawer compartido) · `index.blade.php` (bandeja) · `acciones.blade.php` |
| Pruebas | `tests/helpersDespacho.php` · `tests/Feature/Log02DespachoTest.php` (11) · `tests/Feature/Log03CorregirGuiaTest.php` (8) · `tests/Feature/Log04CantidadesYFleteTest.php` (8) · `tests/js/componente_despacho.mjs` (comportamiento del JavaScript del drawer, ejecutado con Node) |
| Documentación | `docs/DESPACHO_V1_ARCHIVOS.md` (este documento) |
| **Excepción de stock** (§11.14) | `src/Database/Migrations/2026_10_09_100006_create_bylopez_lg_incidencias_stock_tables.php` · `src/Enums/EstadoIncidenciaStock.php` · `src/Models/IncidenciaStock.php` · `IncidenciaStockLinea.php` · `src/Contracts/AjustaInventario.php` · `src/Support/Inventario/AjusteViaCorteConfeccion.php` · `src/Services/Stock/IncidenciasStockService.php` · `src/Http/Controllers/Admin/IncidenciasStockController.php` · `src/Resources/views/admin/despachos/incidencias.blade.php` · `tests/Feature/Log05IncidenciasStockTest.php` (13) |
| (mezclados por la excepción de stock) | `Config/acl.php`, `Http/routes.php`, `Providers/LogisticaServiceProvider.php`, `Support/AutorizacionLogistica.php`, `Exceptions/OperacionLogisticaInvalidaException.php`, `Concerns/ResponderOperacionesLogisticas.php`, `DespachosController.php`, `ContextoDespachoService.php`, `despachos/componente.blade.php`, `despachos/index.blade.php`, `lang/es\|en/app.php`, `tests/js/componente_despacho.mjs` |

### Paquete VentasAsesor (`packages/ByLopez/VentasAsesor/`)
| Grupo | Archivos |
|---|---|
| Controlador | `src/Http/Controllers/Admin/GuiaPedidoController.php` |
| Soporte | `src/Support/Logistica/AccesoGuiaDelAsesor.php` · `src/Support/Logistica/SugiereRecaudoDesdeCartera.php` |
| Vistas | `src/Resources/views/admin/pedidos/acciones-envio.blade.php` · `src/Resources/views/admin/pedidos/guia-resumen.blade.php` |

**Total: 40 archivos nuevos** (35 en Logística, incluido este documento, y 5 en VentasAsesor).

## 2. Archivos EXISTENTES que Despacho V1 modificó — MEZCLADOS con trabajo de etapas anteriores

> No se pueden commitear «solo la V1» sin revisar hunks. «Mezclado» significa que el archivo ya traía cambios sin commit de otra etapa.

### 2.1 Creados en LOG-01 (sin seguimiento): la V1 agregó hunks encima
| Archivo | Qué agregó la V1 |
|---|---|
| `Logistica/src/Config/acl.php` | Claves `logistica.despachos(.despachar)`, `logistica.guias(.ver/.archivo/.corregir)`; orden de las rutas |
| `Logistica/src/Config/menu.php` | Entrada «Despachos» y raíz hacia la bandeja |
| `Logistica/src/Http/routes.php` | Rutas de despachos, PDF y corregir guía |
| `Logistica/src/Support/AutorizacionLogistica.php` | Constantes de los permisos nuevos |
| `Logistica/src/Exceptions/OperacionLogisticaInvalidaException.php` | Códigos de error del despacho, PDF, límite del servidor y `sin_cambios` |
| `Logistica/docs/MAPA_DOMINIO_LOGISTICA.md` | Sección 11 |

### 2.2 Tracked, con cambios previos sin commit
| Archivo | Cambios previos que lo mezclan | Hunks de la V1 |
|---|---|---|
| `Logistica/src/Providers/LogisticaServiceProvider.php` | LOG-01 (migraciones, rutas, vistas, listeners) | Enlaces de `SugiereRecaudo` y `AccesoGuiaPedido` + imports |
| `Logistica/src/Resources/lang/es/app.php` y `en/app.php` | LOG-01 (todo `entregas`, `fuentes`, `transportadoras`, `errores`) | Grupos `despachos`, `pagadores`, `origenes_guia`, `estados_recaudo`, `guias`; claves `acl.*`, `menu.despachos`, errores nuevos |
| `VentasAsesor/src/Config/acl.php` | COSTOS, G-11 | Solo la ruta `admin.ventas_asesor.pedidos.guia` en `view_own` |
| `VentasAsesor/src/Http/routes.php` | COSTOS | Solo la ruta `pedidos/{pedido}/guias/{guia}/{modo}` y su import |
| `VentasAsesor/src/Providers/VentasAsesorServiceProvider.php` | COSTOS, G-11 | Solo los dos `bind` de los contratos y sus imports |
| `VentasAsesor/docs/00_STATUS.md` y `04_DECISIONES_PENDIENTES.md` | Todas las etapas | Cabeceras, fila `DESPACHO V1`, bloque `DESP-D-01…11` |

### 2.3 NUNCA commiteados (sin seguimiento): contienen la V1 junto con las fases 05a/PAG anteriores
Estos cuatro son el listado y el detalle de «Mis ventas / Pedidos». No existen en HEAD, así que **entran completos en cualquier commit**; no hay forma de separar hunks de V1 sin partir el archivo.
| Archivo | Hunks de la V1 |
|---|---|
| `VentasAsesor/src/Http/Controllers/Admin/PedidosAsesorController.php` | Inyección de `AutorizacionLogistica`, `permisosDeEnvio()` y su paso a las dos vistas |
| `VentasAsesor/src/Services/Pedidos/ConsultaPedidosAsesorService.php` | Inyección de `ConsultaGuiaService`; `guias` en `listar()` y `detalle()` |
| `VentasAsesor/src/Resources/views/admin/pedidos/index.blade.php` | Anchos de columna, `guia-resumen`, celda de acciones, `data-pedido` en tarjetas, inclusión del drawer |
| `VentasAsesor/src/Resources/views/admin/pedidos/detalle.blade.php` | Bloque «Envío y guía» y el drawer |
| `VentasAsesor/docs/15_MAPA_DOMINIO_ASESORES.md` y `18_ROADMAP_COMISIONES.md` | Filas de ACL y de roadmap de la V1 |

## 3. Fuera del repositorio (no aparecen en git)
- **Base `lpz_saas_testing` y `lpz_saas` (desarrollo):** migraciones `100004` y `100005` aplicadas con `--path`.
- **Datos en desarrollo:** rol **BODEGA #4** con 39 permisos (+9 claves `logistica.*`; ninguna comercial). Para producción esas claves **no están asignadas**.
- **Memoria del asistente:** `project_logistica_despacho_ux.md`.

## 4. Archivos que NO son de esta V1 aunque aparezcan cambiados
- Todo lo de LOG-01 (tablas `bylopez_lg_entregas/retornos/transportadoras`, servicios `Services/Entregas`, `Services/Transportadoras`, vistas `entregas/` y `transportadoras/`, `Log01EntregaCanonicaTest`).
- G-11 (`bylopez_va_clasificaciones_pedido`, `TipoOperacionPedido*`), COSTOS (`Costos/*`), COM-01…03, ASESOR-01, PAG-*.
- Pint reordenó imports de `VentasAsesor/tests/Feature/Fase03gdEntregaDireccionPagoTest.php` (cambio sin lógica, fuera de la V1).

## 5. Riesgos para cerrar la etapa
1. **Los 4 archivos del §2.3 no se pueden aislar:** cerrar la V1 implica commitear también las fases 05a/PAG anteriores que ya viven en esos archivos.
2. Los archivos del §2.2 sí se pueden separar con `git add -p`, pero las etapas anteriores comparten los mismos archivos (en especial los dos `lang` y el `acl.php` de VentasAsesor).
3. **Dependencia de orden:** VentasAsesor ahora importa clases de Logística (contratos y `ConsultaGuiaService`). Logística nunca importa VentasAsesor. Un commit que incluya VentasAsesor debe incluir Logística.
4. **Migraciones:** las dos de la V1 son aditivas y se aplican con `--path`; en testing hay migraciones ajenas pendientes (pickup y CorteConfeccion) que no se ejecutaron.
5. **Producción:** ninguna de estas piezas se ha aplicado. Antes del rollout: migraciones `100001…100005` y `000018`, y asignar las claves `logistica.*` a BODEGA.

## Addendum 2026-10-14 — Guía interna GI-000001 (reemplaza la propuesta BL)

Archivos NUEVOS (Logística): `src/Database/Migrations/2026_10_14_100013_add_guia_interna_gi_to_bylopez_lg_guias_table.php` · `src/Enums/ModoGuia.php` · `src/Support/ConsecutivoGuiaInterna.php`.

Archivos MODIFICADOS (Logística): `src/Enums/OrigenGuia.php` (caso `interna` + `elegibles()`) · `src/Models/Guia.php` (`consecutivo_interno`, `esInterna()`, número GI inmutable) · `src/Services/Despachos/DespachoService.php` · `ContextoDespachoService.php` (solo ofrece orígenes externos) · `src/Services/Guias/CorregirGuiaService.php` · `src/Services/Transportadoras/TransportadorasService.php` · `src/Http/Controllers/Admin/TransportadorasController.php` · `src/Exceptions/OperacionLogisticaInvalidaException.php` · `src/Resources/views/admin/despachos/componente.blade.php` · `src/Resources/views/admin/transportadoras/index.blade.php` · `src/Resources/lang/{es,en}/app.php`. Detalle y alcance: `MAPA_DOMINIO_LOGISTICA.md` §13. Webkul: sin cambios.