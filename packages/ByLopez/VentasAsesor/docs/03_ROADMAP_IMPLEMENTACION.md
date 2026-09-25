# 03_ROADMAP_IMPLEMENTACION.md

Roadmap de implementación del módulo `ByLopez\VentasAsesor`.

**Última actualización:** 2026-09-20 (Fase 02B1A completada)
**Estado:** Fases 01, 01A, 01B, 02A, 02A1, 02A2, 02B0 y **02B1A completadas**. La **Fase 02 sigue
en progreso**: su tramo **02B1B** (comando de reconciliación) está pendiente de aprobación y no
se ha iniciado. El estado de fases lo manda `00_STATUS.md`.

> **Actualizado 2026-09-25:** la 02B1B está **completada** (Doc. 10). Las menciones «02B1B pendiente» de este archivo son históricas.

Referencias `[A-§n]` → `01_AUDITORIA_CORE_VENTAS_BAGISTO.md`;
`[M-§n]` → `02_MODELO_FUNCIONAL_VENTAS_ASESOR.md`;
`[T-§n]` → `05_DISENO_TECNICO_CREACION_PEDIDOS_ASESOR.md`;
`[R-§n]` → `05A_AUDITORIA_RMA_Y_MAPA_CORE_RELACIONADO.md`;
`D-nn` → `04_DECISIONES_PENDIENTES.md`.

**Cambios de la Fase 00C.** El **orden de las fases no se modifica**. Se
modifican: el alcance de la Fase 07 (empieza por una prueba funcional de RMA) y
las decisiones bloqueantes de las Fases 07, 08 y 09 (D-24 a D-26) y los
requisitos técnicos RT-01 y RT-02 introducidos en la Fase 00C1.

**Cambios de la Fase 01.** El orden tampoco cambia. La Fase 01 quedó completada;
la Fase 02 suma la decisión **D-27**; y las Fases 02, 05, 09 y 12 recibieron
precisiones sobre la atribución 1:1 conceptual, las pruebas IDOR obligatorias, la
identidad de la línea de comisión y la `external_reference` compuesta.

**Cambios de la Fase 01B.** El orden sigue igual. Fase de auditoría y documentación,
sin código funcional (`06B_AUDITORIA_CLIENTES_PRECIOS_Y_PROPIEDAD_COMERCIAL.md`,
citado como `[B-§n]`). **D-16 y D-27 quedaron decididas**, así que la Fase 02 ya no
tiene decisiones bloqueantes. Se añadieron **RT-03** (precio por grupo del cliente del
carrito) y **RT-04** (compatibilidad de clientes sin correo), que bloquean las Fases
03 y 04, y las decisiones **D-28 a D-34**. Se registra una **fase POS futura** sin
número. Se recomienda renombrar el servicio conceptual a `CrearPedidoComercialService`
al iniciar la Fase 03.

**Convención de tablas:** prefijo `bylopez_va_`, siguiendo `bylopez_cc_`.

**Regla transversal:** cada fase construye sus propias pruebas. La Fase 14 es de
consolidación y regresión, **no** el primer momento en que se prueba el módulo.
Toda fase cierra verificando que `packages/Webkul/` no tiene cambios.

---

## Orden de fases

| # | Fase | Estado |
|---|---|---|
| 00 | AUDITORIA_CORE_VENTAS | COMPLETADA |
| 00B | CORRECCION_Y_CIERRE_MODELO | COMPLETADA |
| 00C / 00C1 | AUDITORIA_RMA_Y_MAPA_CORE / CIERRE_COBERTURA_RMA | COMPLETADA |
| 01 | BASE_PAQUETE_ACL_Y_PREVENCION_ACCESO | **COMPLETADA** (tras el cierre 01A) |
| 01B | AUDITORIA_CLIENTES_PRECIOS_Y_PROPIEDAD_COMERCIAL | **COMPLETADA** (sin código) |
| 02A | MODELO_Y_MOTOR_INTERNO_ATRIBUCION | **COMPLETADA, CORREGIDA Y AUDITADA** (Fases 02A1 y 02A2) |
| 02A1 | CORRECCION_INTEGRIDAD_IDEMPOTENCIA_HISTORIAL | **COMPLETADA** |
| 02A2 | CIERRE_AUDITADO_ANTES_DE_INTEGRAR | **COMPLETADA** |
| 02B0 | AUDITORIA_CONEXION_PEDIDOS | **COMPLETADA Y CORREGIDA** (sin código) |
| 02B1A | LISTENER_CONTEXTO_E_INCIDENCIAS | **COMPLETADA** |
| 02B1B | COMANDO_DE_RECONCILIACION | PENDIENTE DE APROBACIÓN — sin decisiones bloqueantes |
| 03 | MOTOR_CREACION_PEDIDO_ASESOR | PROPUESTA |
| 04 | INTERFAZ_CREACION_PEDIDO_ASESOR | PROPUESTA |
| 05 | LISTADO_DETALLE_ASIGNACION_Y_BACKFILL | PROPUESTA |
| 06 | SEGUIMIENTO_LOGISTICO_POR_ENVIO | PROPUESTA |
| 07 | DEVOLUCIONES_RMA_Y_CONCILIACION | PROPUESTA |
| 08 | REGLAS_Y_EVENTOS_DE_COMISION | PROPUESTA |
| 09 | LIQUIDACIONES_Y_AJUSTES | PROPUESTA |
| 10 | KPIS_Y_DASHBOARD | PROPUESTA |
| 11 | EVIDENCIAS_PRIVADAS | PROPUESTA |
| 12 | API_N8N_WHATSAPP | PROPUESTA |
| 13 | IMPORTACION_HISTORICA_EXCEL | PROPUESTA |
| 14 | PRUEBAS_INTEGRALES_Y_DESPLIEGUE | PROPUESTA |

---

## Fase 01 — BASE_PAQUETE_ACL_Y_PREVENCION_ACCESO

**Objetivo.** Dejar el paquete instalado, cargado y con ACL propia y granular,
sin lógica de negocio, y sin que el módulo amplíe los privilegios de nadie.

**Estado:** ✅ **COMPLETADA** (2026-09-13), tras el cierre técnico de la Fase 01A.
Implementada según `06_FASE_01_BASE_PAQUETE_ACL.md`; el cierre está documentado en
`06A_CIERRE_FASE_01_Y_ENTORNO_PRUEBAS.md`: autorización cerrada para tipos de permiso
desconocidos, base de pruebas aislada y sembrada sin RMA, guard global, 72/72 casos
propios en verde en el entorno aislado y base de desarrollo intacta.

> **ACL provisional.** La protección de la pantalla inicial depende de que
> `ventas_asesor` sea la **última** entrada que lista su ruta compartida. Es una
> solución **provisional**: solo protege esa pantalla y **no** puede convertirse en
> el mecanismo de las rutas futuras. Cada operación nueva debe tener su propia ruta
> y su propia clave ACL, y la reutilización de la ruta inicial debe desaparecer a
> medida que existan rutas reales. La prueba de manipular parámetros de URL **no**
> demuestra protección IDOR sobre pedidos; eso debe comprobarse cuando existan
> atribuciones y rutas con `order_id`.

**Dependencias.** Fase 00B.
**Decisiones que la bloqueaban.** Ninguna.

**Capas implementadas.**
`VentasAsesorServiceProvider`; `Config/acl.php` con 23 claves (las 19 de
[M-§12.2] más 4 agrupadores que exige la pantalla de roles); `Config/menu.php` con
un único nodo; traducciones `es`/`en`; una ruta `GET admin/ventas-asesor`;
registro PSR-4 en `composer.json` raíz, provider en `bootstrap/providers.php` y dos
testsuites en `phpunit.xml`.

**Tablas creadas.** Ninguna.
**Servicios creados.** `AutorizacionVentasAsesor`: asesor por permiso marcador,
alcance `view_own`/`view_all` y regla de registro individual que falla cerrada.

**Pruebas.** 49 propias en verde (229 aserciones), sin escribir en la base de
desarrollo. Regresión: 166 de 170 aprobadas; los 4 fallos son preexistentes, de
locale y datos, en `Admin/Settings/UsersTest`.

**Riesgos que dejó registrados.** El orden de `acl.php` es parte de la seguridad
(la clave raíz debe ir la última, protegido por prueba y mutación); las claves no
raíz apuntan de forma provisional a la pantalla de inicio; un administrador `all`
se reconoce como asesor (D-27).

**Criterio de salida.** Cumplido: paquete cargado, ACL y menú fusionados,
autorización en servidor y cero cambios en `packages/Webkul/`.

**Precondición operativa (fuera del código).** Corregir el `role_id = 3`
huérfano de ASESOR_PRUEBA y revisar los 18 permisos de `ASESOR VENTAS` [A-§18]. Ninguna
de las dos bloqueó la fase.

---

## Fase 02A — MODELO_Y_MOTOR_INTERNO_ATRIBUCION

**Estado:** ✅ **COMPLETADA** (2026-09-15). Detalle en
`07_FASE_02A_MODELO_Y_MOTOR_ATRIBUCION.md` (`[C-§n]`).

**Objetivo cumplido.** Modelo de datos, dominio y servicios internos de atribución
comercial, **sin conectar** el motor con la creación real de pedidos.

**Entregado.** Tablas `bylopez_va_order_attributions` (UNIQUE `order_id`) y
`bylopez_va_advisor_assignment_logs`; cinco enums; `ContextoComercial` y
`SeleccionPropiedadComercial`; modelos con `belongsTo` hacia `OrderProxy` y
`AdminProxy`; repositorios; `ResolverAtribucionService`, `RegistrarAtribucionService`
(idempotente) y `AsignacionAsesorService` (con historial y permisos).

**Pruebas.** 112 casos propios en verde, 458 aserciones; 40 casos nuevos.

**Lo que esta fase NO hizo:** listener, rutas, controladores, vistas, DataGrids,
bandeja, backfill, integración con `OrderRepository`/`OrderResource` y cualquier cambio
en tablas del core.

---

## Fase 02A1 — CORRECCION_INTEGRIDAD_IDEMPOTENCIA_HISTORIAL

**Estado:** ✅ **COMPLETADA** (2026-09-15). Detalle en
`07A_CORRECCIONES_INTEGRIDAD_FASE_02A.md` (`[C1-§n]`).

**Objetivo cumplido.** Corregir, antes de conectar listeners, lo que una revisión externa
encontró en la 02A: cascadas que destruían auditoría, claves foráneas de administradores
que rompían la invariante `advisor → advisor_id`, idempotencia que no comparaba contexto,
falta de historial inicial, historial mutable, reasignación sin control de concurrencia,
lógica anticipada de comisiones y un `fallo_tecnico` inalcanzable.

**Entregado.** Migración correctiva aditiva; `TipoOperacionAtribucion`;
`HistorialAtribucionService` como único punto de escritura de auditoría;
`AtribucionEnConflictoException` y `HistorialInmutableException`; camino autorizado de
`fallo_tecnico`; retiro de `is_excluded`, `exclusion_reason` y `esComisionable()`.

**Pruebas.** 145 casos propios en verde (643 aserciones) y 6 mutaciones que demuestran
sensibilidad.

**Lo que esta fase NO hizo:** ningún listener, integración con pedidos reales,
reconciliación, backfill, ruta, controlador, vista ni corrección de clientes o precios.

---

## Fase 02A2 — CIERRE_AUDITADO_ANTES_DE_INTEGRAR

**Estado:** ✅ **COMPLETADA** (2026-09-19). Detalle en
`07A_CORRECCIONES_INTEGRIDAD_FASE_02A.md` §19.

**Objetivo cumplido.** Cerrar los seis huecos que encontró la segunda revisión externa
antes de tocar listeners: prueba real de la carrera concurrente, `fallo_tecnico` sin
notas libres y con su código dentro de la idempotencia, `down()` que trata los datos
antes del esquema, historial que también rechaza `save()`, y reglas de conflicto y
alcance de inmutabilidad corregidos en la documentación.

**Sin cambios de esquema:** no hubo migración nueva.

**Pruebas.** 153 casos propios en verde (678 aserciones), más la verificación dirigida
del `down()`/`up()` fuera de la suite.

---

## Fase 02B0 — AUDITORIA_CONEXION_PEDIDOS

**Estado:** ✅ **COMPLETADA** (2026-09-20). Detalle en
`08_FASE_02B0_AUDITORIA_CONEXION_PEDIDOS.md` (`[D-§n]`).

**Qué dejó decidido con evidencia.** Evento `checkout.order.save.after`
(`OrderRepository.php:86`), que corre **dentro** de la transacción del repositorio
(medido: nivel 1, pedido invisible desde otra conexión); un `Throwable` del listener
revierte y reintenta la venta hasta tres veces (medido); los nueve flujos que crean
pedidos convergen en el mismo repositorio; el contexto se transporta **acotado a la
petición e indexado por `cart_id`**, y la tabla durable ligada al carrito **se descarta**
por falta de evidencia que la justifique.

---

## Fase 02B1 — IMPLEMENTACION_LISTENER_Y_RECONCILIACION

> **Dividida en dos tramos (2026-09-20).** La 02B1A entregó el enganche runtime y está
> **COMPLETADA** (`09_FASE_02B1A_LISTENER_CONTEXTO_INCIDENCIAS.md`). La 02B1B, con el comando
> de reconciliación, sigue **pendiente de aprobación** y no se ha iniciado. Lo que sigue en esta
> sección se conserva como estaba escrito; lo ya entregado se detalla en el Doc. 09.
>
> **Entregado por la 02B1A:** contexto `scoped` por `cart_id` con `durante()`; clasificación
> cerrada por las 9 rutas exactas (`RutasDeCreacionDePedido`); listener síncrono registrado una
> sola vez sobre `checkout.order.save.after`; cuarta migración
> `bylopez_va_attribution_incidents` con servicio idempotente; taxonomía de errores por
> categoría; 198 casos propios en verde y 5 mutaciones demostradas.
>
> **Reservado a la 02B1B:** comando de reconciliación con `--dry-run` para pedidos anteriores al
> listener y para los que quedaron en `fallo_tecnico` (Doc. 09 §13, riesgos R-1 y R-2).

**Objetivo.** Conectar el motor de la 02A con los flujos reales: listener **único
escritor**, adaptador que construye el contexto, detección de huérfanos y comando de
reconciliación. **Sin interfaz.**

**Dependencias.** Fase 02B0.
**Decisiones que la bloquean.** Ninguna. **Tramo 02B1A ejecutado; 02B1B pendiente de aprobación.**

> **Alcance acotado en la 02B0 [D-§11].** La **bandeja visual**, el DataGrid, el detalle,
> la asignación desde interfaz y el backfill son de la **Fase 05**. La 02B1 entrega solo
> el backend: listener, contexto, detección y reconciliación por comando.

> **Correcciones que impone la revisión externa de la 02B0 [D-§16].**
>
> - **Clasificación de origen cerrada:** contexto explícito → ruta administrativa exacta →
>   ruta exacta de la lista verificada de tienda o pasarela → **no determinable**. Una
>   ruta desconocida o ausente **nunca** es tienda, y el guard `admin` **nunca** implica
>   panel.
> - **El listener no consume el contexto:** lo lee por `cart_id` y la limpieza la hace
>   quien lo estableció, con `try/finally` alrededor de toda la llamada a `create()`,
>   después de los reintentos del repositorio.
> - **Se aprueba la tabla `bylopez_va_attribution_incidents`**, con unicidad
>   `(order_id, codigo)`, para que un conflicto del listener quede consultable y
>   resoluble sin tocar la atribución ni el historial inmutable.
> - **`catch (Throwable)` no garantiza conservar la venta** ante fallos de conexión o de
>   la transacción compartida; la 02B1 debe distinguir conflicto, historial incoherente,
>   error de dominio, error inesperado y error de base.
> - Quince pruebas obligatorias [D-§16.6].

> **Reglas que imponen las Fases 02A2 y 02B0 [C1-§19.7, D-§8].**
>
> ```text
> No existe atribución y no puede determinarse el contexto
>   → puede registrarse fallo_tecnico.
>
> Ya existe una atribución equivalente
>   → reintento idempotente.
>
> Ya existe una atribución incompatible
>   → conservarla intacta y enviar la incidencia a reconciliación.
>
> Nunca convertir una atribución existente incompatible en fallo_tecnico.
> Nunca sobrescribirla desde el listener.
> ```
>
> Además: el contexto no puede llevar notas variables, porque la idempotencia compara
> `notes`; y un conflicto nunca debe propagarse hasta abortar una venta real.

**Pruebas obligatorias.** Un pedido real del panel queda atribuido; uno del storefront
queda como venta de empresa sin creador; ejecutar el listener dos veces produce una sola
fila; una excepción del listener **no** aborta el checkout; la reconciliación es
idempotente.

> **Resultado 02B1A (2026-09-20).** Las cuatro primeras quedaron cubiertas por las 35 pruebas
> del Doc. 09 §10. La quinta —idempotencia de la reconciliación— sigue pendiente porque el
> comando es de la 02B1B.

---

## Fase 02 — ATRIBUCION_Y_CONTEXTO_CREACION *(dividida en 02A y 02B)*

**Objetivo.** Registrar creador, asesor, canal comercial y mecanismo de creación
de cada pedido, con la estrategia de atribución de [M-§10].

**Dependencias.** Fase 01.
**Decisiones que la bloquean.** Ninguna desde la Fase 01B. D-16 (carrito o borrador
durante la negociación; pedido solo al confirmar) y D-27 (propiedad comercial cuando
vende un administrador) están **decididas**.

> **Alcance ajustado por la Fase 01B.** El registro de atribución debe separar
> `created_by_admin_id`, `advisor_id` (nullable) y `commercial_owner_type`
> (`advisor` | `company` | `unassigned`) [B-§7]. Reglas: un asesor normal se asigna a
> sí mismo; un administrador `all` **nunca** recibe la venta automáticamente; una
> venta `company` no tiene asesor ni comisión. Si existe preferencia comercial del
> cliente, el pedido guarda su propia decisión histórica. `commercial_owner_type` es
> propiedad **comercial**; `attribution_status` sigue siendo el estado **técnico** de
> la atribución (`completa` | `sin_asesor` | `fallo_tecnico`).

**Capas previstas.** Modelo `OrderAttribution` con `belongsTo` hacia
`OrderProxy` [M-§11.1]; `AdvisorAssignmentService`; `AttributionResolver`;
listener idempotente sobre `checkout.order.save.after`; comando de
reconciliación.

**Tablas previstas.**
`bylopez_va_order_attributions` (UNIQUE `order_id`),
`bylopez_va_advisor_assignment_logs`.

> **Precisión (Fase 01).** La relación «pedido 1:1 asesor» es **conceptual**. No se
> agrega `advisor_id` a la tabla core `orders`: la atribución vive en
> `bylopez_va_order_attributions`, relacionada uno a uno con `orders` por
> `order_id`. La regla de visibilidad por propietario ya existe y está probada en
> `AutorizacionVentasAsesor::puedeVerRegistroDe()` (Fase 01); esta fase debe
> consumirla, no reimplementarla. **Pruebas IDOR obligatorias:** un asesor con
> `view_own` que pide un `order_id` ajeno por URL debe recibir denegación.

**Pruebas obligatorias.**
Pedido desde el panel → creador correcto; pedido desde storefront → creador
`null`, `creation_source = storefront`, `attribution_status = sin_asesor` *(superado: el storefront es `company` + `completa`; ver Doc. 10 §9)*;
ejecutar el listener dos veces produce una sola fila; una excepción del listener
**no** aborta el checkout; el comando de reconciliación es idempotente;
`sin_asesor` y `fallo_tecnico` se distinguen.

**Riesgos.** El listener corre dentro de la transacción del checkout: una
excepción propagada abortaría una venta real. `auth()->guard('admin')` no existe
en storefront ni en colas.

**Criterio de salida.** Todo pedido nuevo tiene fila de atribución o queda
detectado como incidencia; ningún checkout falla por causa del módulo.

---

## Fase 03 — MOTOR_CREACION_PEDIDO_ASESOR

**Objetivo.** `CrearPedidoAsesorService`: orquestar el flujo oficial de Bagisto
desde código propio, sin interfaz todavía. Detalle completo en `[T-§3]`.

> **Nombre (Fase 01B).** Como el mismo servicio atenderá la interfaz del asesor, el
> POS, la API y n8n, se recomienda nombrarlo `CrearPedidoComercialService` al iniciar
> esta fase. No se ha renombrado nada: todavía no existe código [B-§8.3].

**Dependencias.** Fase 02.
**Decisiones que la bloquean.** D-17 (identidad del cliente), D-18 (precios),
D-19 (sobreventa), **D-28** (clientes sin correo).
**Requisitos técnicos que la bloquean (Fase 01B).** **RT-03**: hoy Bagisto calcula el
precio con el grupo del cliente autenticado en la **sesión**, no con el del cliente
del carrito; está reproducido [B-§4]. **RT-04**, si D-28 adopta clientes con correo
nulo [B-§2].

**Capas previstas.** `CrearPedidoAsesorService`, DTOs de contexto, solicitud y
resultado; `ResolverClienteService`; `ValidacionPedidoAsesor`;
`IdempotenciaPedidoService`.

**Tablas previstas.** `bylopez_va_creation_references` (`external_reference`
UNIQUE) — solo si D-16/D-17 lo confirman; podría resolverse con una columna en
la tabla de atribución.

**Pruebas obligatorias.**
El servicio crea un pedido con totales **idénticos** al flujo oficial con los
mismos datos; un producto configurable genera padre e hija y el conteo con
`parent_id IS NULL` da la cantidad real; `external_reference` repetido no crea
un segundo pedido; stock insuficiente no crea pedido ni reserva inventario;
el pedido queda atribuido en la misma operación.

**Riesgos.** Reimplementar mal las cinco comprobaciones de `validateOrder()`
[T-§4.3]; ventana de carrera de stock; que la lógica se filtre al controlador y
la API futura no pueda reutilizarla.

**Criterio de salida.** Un pedido creado por el servicio es indistinguible de
uno del panel oficial, verificado campo a campo.

---

## Fase 04 — INTERFAZ_CREACION_PEDIDO_ASESOR

**Objetivo.** Pantalla propia de creación de venta, adaptada a WhatsApp.

**Dependencias.** Fase 03.
**Decisiones que la bloquean.** D-17, D-18, D-21, **D-28**, **D-29**, **D-30**; requisito
técnico **RT-03** (la pantalla debe mostrar cliente, grupo y precio aplicados, y
bloquear la confirmación ante inconsistencia [B-§5]).

**Capas previstas.** `PedidoAsesorController`; Form Requests; vistas Blade y
componentes coherentes con Bagisto; buscador de cliente con detección de
duplicados; buscador de producto con color, talla y stock de la variante;
resumen previo a confirmar.

**Funciones exigidas.** Buscar o crear cliente sin duplicarlo; buscar productos;
seleccionar color y talla reales; mostrar stock de la variante; agregar varias
referencias; modificar cantidades antes de confirmar; mostrar subtotal, envío,
descuentos y total **calculados por Bagisto**; seleccionar canal comercial, pago
y envío; confirmar mediante `CrearPedidoAsesorService`; redirigir al detalle
propio; no exponer pedidos de otros asesores.

**Tablas previstas.** Ninguna.

**Pruebas obligatorias.**
Flujo completo end-to-end; doble envío del formulario no duplica el pedido;
un asesor sin permiso recibe 401; los totales mostrados coinciden con los
persistidos; la variante seleccionada es la que llega al pedido.

**Riesgos.** Que la interfaz calcule precios por su cuenta; que el asesor pueda
forzar `advisor_id` ajeno; complejidad del selector de variantes.

**Criterio de salida.** Un asesor registra una venta completa sin tocar la
pantalla oficial de Bagisto.

---

## Fase 05 — LISTADO_DETALLE_ASIGNACION_Y_BACKFILL

**Objetivo.** Consulta, detalle, asignación y tratamiento del histórico.

**Dependencias.** Fase 02.
**Decisiones que la bloquean.** D-12 (histórico), D-13 (visibilidad entre
asesores).

**Capas previstas.** `SalesDataGrid` propio con **scope obligatorio** de
aislamiento; vista de detalle propia; bandeja "Ventas sin asignar" con
distinción entre `sin_asesor` y `fallo_tecnico`; asignación individual y masiva;
comando de backfill con `--dry-run`.

**Tablas previstas.** Ninguna nueva.

**Pruebas obligatorias.**
Un asesor no obtiene datos de otro **por HTTP directo**, incluido acceso por
`order_id` conocido (**prueba IDOR obligatoria**, que debe decidir con
`AutorizacionVentasAsesor::puedeVerRegistroDe()` de la Fase 01: sin atribución
válida y sin `view_all`, acceso denegado); el backfill es idempotente; la asignación masiva genera una
fila de historial por pedido; pedidos históricos se visualizan sin error;
rendimiento del grid con el volumen real.

**Riesgos.** Confiar el aislamiento a la interfaz; consultas N+1 sobre
`order_items` y `shipments`; atribuir masivamente el histórico y falsear KPIs.

**Criterio de salida.** Aislamiento verificado por prueba automatizada; ningún
pedido en estado ambiguo.

---

## Fase 06 — SEGUIMIENTO_LOGISTICO_POR_ENVIO

**Objetivo.** Modelar el estado logístico que el core no tiene [A-§4.4] y el
**evento de entrega** que causará la comisión.

**Dependencias.** Fase 05.
**Decisiones que la bloquean.** D-07 (estado `preparando`), D-14 (quién
confirma la entrega).

**Capas previstas.** `ShipmentTracking` + máquina de estados [M-§7.3];
`DeliveryEventService` (eventos por `shipment_item`, [M-§8.3]); catálogo de
transportadoras; listener sobre `sales.shipment.save.after`; servicio de
derivación del estado visible [M-§7.4].

**Tablas previstas.**
`bylopez_va_shipment_trackings`, `bylopez_va_shipment_tracking_logs`,
`bylopez_va_delivery_events`, `bylopez_va_carriers`.

**Pruebas obligatorias.**
Un pedido con dos envíos mantiene estados independientes; transiciones inválidas
rechazadas; un `order_item` repartido en dos envíos genera dos eventos de
entrega con fechas distintas; retorno de transportadora es terminal y no genera
entrega; concurrencia de dos usuarios sobre el mismo envío; guía corregida deja
historial; envíos previos al módulo no rompen nada.

**Riesgos.** Duplicar el concepto de estado del pedido; escribir en
`orders.status` (prohibido); envíos antiguos sin registro de seguimiento;
que el dato de entrega se capture con poca disciplina y arruine la comisión.

**Criterio de salida.** Todo envío tiene estado logístico trazable y toda
entrega confirmada tiene su evento anclado a `shipment_item_id`.

---

## Fase 07 — DEVOLUCIONES_RMA_Y_CONCILIACION

**Objetivo.** Conciliar RMA, reembolso y retorno logístico **sin duplicar
tablas de devoluciones** [R-§19].

**Dependencias.** Fase 06.
**Decisiones que la bloquean.** **D-25** (habilitación de RMA).
**Requisitos técnicos que la bloquean (Fase 00C1).** **RT-01** (idempotencia de la devolución) y **RT-02** (trazabilidad del reembolso) — requisitos, no decisiones de negocio [R-§26].

> **Cambio de alcance (Fase 00C).** Esta fase debe **empezar** por una prueba
> funcional controlada que confirme o descarte **H-RMA-8** (reembolso repetible
> en devoluciones parciales) y **H-RMA-12** (la cancelación parcial devuelve
> inventario del ítem completo). Ambos son **[INFERIDO]** sobre un módulo que
> nunca se ha ejecutado en esta instalación [R-§16]. El diseño de la
> conciliación no puede fijarse antes de ese resultado.

**Capas previstas.** `ConciliacionDevolucionesService`; listener sobre
`sales.refund.save.after` **más** proceso de reconciliación periódico — la
combinación recomendada en [R-§12.4], porque el evento llega siempre pero **no
permite saber si el reembolso vino de un RMA** (no hay FK `rma` ↔ `refunds`,
[R-§7.2]); vista de conciliación; detección de duplicados.

**Tablas previstas.** `bylopez_va_return_reconciliations` — solo el enlace entre
evento de entrega, RMA y reembolso; **ninguna tabla de devoluciones propia**.

**Pruebas obligatorias.**
Una unidad presente en RMA y en reembolso genera **un solo** ajuste; un retorno
de transportadora no genera ajuste porque nunca causó comisión; una devolución
parcial descuenta solo la cantidad devuelta; el proceso es idempotente.

**Riesgos.** Doble conteo entre `qty_refunded` y RMA (el error corregido en
[M-§7.1]); la ausencia de evento en la transición de estado administrativa
[R-§12.3] obliga a consulta periódica, con latencia; `rma.order_id` y
`rma_items.order_item_id` son **CASCADE** en el core, así que borrar un pedido
o un ítem borra su RMA y dejaría huérfano cualquier ajuste que apunte solo al
RMA [R-§7.2]; RMA no registra **qué administrador** ejecutó el reembolso
[R-§5.5], por lo que el autor del ajuste debe capturarlo el módulo.

**Criterio de salida.** Los cuatro conceptos de [M-§7.1] quedan separados y
conciliados sin duplicación.

---

## Fase 08 — REGLAS_Y_EVENTOS_DE_COMISION

**Objetivo.** Definir cómo se calcula la comisión y calcular las unidades
elegibles, sin liquidar todavía.

**Dependencias.** Fases 06 y 07.
**Decisiones que la bloquean.** D-03 (alcance y exclusiones), D-21 (si el
transporte entra en la base), **D-26** (reposición y garantía).
**Requisitos técnicos que la bloquean (Fase 00C1).** **RT-01** y **RT-02**: sin
ellos, un reembolso duplicado o mal atribuido produciría un **descuento de
comisión duplicado** [R-§26].

**Capas previstas.** `CommissionRule` con vigencia y prioridad;
`CommissionRuleResolver`; `EligibleUnitsCalculator` basado en **eventos de
entrega**, no en `order_items` [M-§8.2].

**Tablas previstas.** `bylopez_va_commission_rules`,
`bylopez_va_non_commissionable_products` (o marca equivalente).

**Pruebas obligatorias.**
Unitarias del cálculo con cancelación parcial, devolución parcial, retorno de
transportadora y producto excluido; resolución de reglas solapadas por
prioridad; vigencia por fecha; un configurable no duplica unidades.

**Riesgos.** Definir la comisión sobre "pedidos" en vez de prendas entregadas;
`OrderItemRepository::collectTotals()` recalcula cantidades tras la venta
[A-§6], así que el cálculo debe leerse al liquidar, no antes.

**Criterio de salida.** Las unidades elegibles coinciden con la verificación
manual en todos los escenarios de prueba.

---

## Fase 09 — LIQUIDACIONES_Y_AJUSTES

**Objetivo.** Liquidar, aprobar y pagar comisiones con trazabilidad completa e
inmutabilidad.

**Dependencias.** Fase 08.
**Decisiones que la bloquean.** D-15 (periodo de corte), D-04 (reasignación tras
liquidar), **D-24** (si un reembolso sin RMA descuenta comisión).
**Requisitos técnicos que la bloquean (Fase 00C1).** **RT-01** y **RT-02**: los
ajustes negativos de D-02 dependen de poder atribuir con exactitud cada
reembolso a una devolución [R-§26].

**Capas previstas.** `CommissionSettlement` + **líneas con snapshot completo**
[M-§8.5]; `CommissionAdjustment`; servicio de liquidación por periodo; flujo
`provisional → aprobada → pagada`.

**Tablas previstas.**
`bylopez_va_commission_settlements`,
`bylopez_va_commission_settlement_items` (identidad por definir en esta fase:
`UNIQUE(delivery_event_id)` es demasiado amplia si una entrega agrupa varios
`shipment_items`; candidata `delivery_event_id + shipment_item_id`, más una porción
o secuencia si hay entregas parciales del mismo ítem — **índice final no
aprobado**, precisión de la Fase 01),
`bylopez_va_commission_adjustments`,
`bylopez_va_commission_settlement_logs`.

**Pruebas obligatorias.**
El snapshot por línea es inmutable; cambiar la regla no altera lo aprobado; la
misma cantidad entregada no se liquida dos veces [M-§8.6]; una devolución
posterior genera exactamente un ajuste negativo, no dos; doble liquidación del
mismo periodo bloqueada; suma de líneas igual al total; concurrencia al aprobar
y pagar.

**Riesgos.** **Dinero real**: cualquier error aquí es de severidad crítica.
Recálculo retroactivo destructivo. FK en cascada que borren historial financiero
[M-§11.2].

**Criterio de salida.** Una liquidación pagada es inmutable y auditable línea a
línea.

---

## Fase 10 — KPIS_Y_DASHBOARD

**Objetivo.** Dashboard del asesor y del supervisor, con las métricas separadas
de [M-§14].

**Dependencias.** Fases 05, 06, 07 y 09.
**Decisiones que la bloquean.** D-11 (metas), D-22 (qué cifra es la principal),
D-10 (si el asesor ve costos).

**Capas previstas.** Servicios de agregación por KPI; `AdvisorGoal`; vistas de
dashboard; caché de agregados si el volumen lo exige.

**Tablas previstas.** `bylopez_va_advisor_goals`.

**Pruebas obligatorias.**
Cada KPI contrastado contra su consulta SQL manual; los conteos de prendas
aplican `parent_id IS NULL`; las cifras monetarias usan moneda base; un asesor
no ve KPIs de otro; rendimiento con volumen real.

**Riesgos.** Mezclar pedidos, prendas y dinero en un mismo indicador; llamar
"venta" a valor no entregado; contradecir los reportes oficiales de Bagisto.

**Criterio de salida.** Cada KPI es reproducible y explicable uno a uno.

---

## Fase 11 — EVIDENCIAS_PRIVADAS

**Objetivo.** Documentación probatoria asociada a pedidos y envíos.

**Dependencias.** Fase 05.
**Decisiones que la bloquean.** D-09 (formatos, tamaño y retención).

**Capas previstas.** `OrderEvidence`; almacenamiento en disco **privado**;
controlador de subida y de descarga autorizada; componente de carga.

**Tablas previstas.** `bylopez_va_order_evidences`.

**Pruebas obligatorias.**
El archivo no es accesible por URL pública; validación de tipo y tamaño;
descarga sin permiso devuelve 401/403; borrado lógico conserva historial.

**Riesgos.** Datos personales en capturas de WhatsApp; crecimiento del
almacenamiento; subida de archivos ejecutables.

**Criterio de salida.** Ninguna evidencia accesible sin autorización de servidor.

---

## Fase 12 — API_N8N_WHATSAPP

**Objetivo.** Exponer la creación de pedidos a la automatización, **reutilizando
exactamente** `CrearPedidoAsesorService` [T-§17].

**Dependencias.** Fases 03 y 04 estabilizadas. **No antes**: la API no debe ser
el primer consumidor del motor.
**Decisiones que la bloquean.** D-08 (canal y usuario técnico), D-16, D-17.

**Capas previstas.** Rutas de API con autenticación por token; Form Requests
propios; mapeo de payload a los DTOs existentes; **ninguna lógica de creación
propia**.

**Tablas previstas.** Ninguna nueva (`external_reference` ya existe).

> **Precisión (Fase 01).** `external_reference` no es globalmente única por sí
> sola. La idempotencia debe evaluarse sobre `creation_source + external_reference`
> o `integration_id + external_reference`, para que n8n, las importaciones y otras
> integraciones no colisionen entre sí.

**Pruebas obligatorias.**
Reintento con el mismo `external_reference` devuelve el pedido existente y no
crea otro; payload inválido devuelve 422 con códigos, no textos; token inválido
devuelve 401; el pedido creado por API es idéntico al creado por interfaz con
los mismos datos.

**Riesgos.** Pedidos duplicados por reintentos de n8n; que la API derive en una
segunda implementación del motor; exposición de datos sin aislamiento.

**Criterio de salida.** La API no contiene ni una línea de lógica de creación
propia y la idempotencia está verificada.

---

## Fase 13 — IMPORTACION_HISTORICA_EXCEL

**Objetivo.** Cargar ventas históricas o masivas.

**Dependencias.** Fase 12.
**Decisiones que la bloquean.** D-12 (qué hacer con el histórico).

**Capas previstas.** Comando de importación con `--dry-run`; validación por
fila; informe de errores; uso del mismo servicio de creación.

**Tablas previstas.** `bylopez_va_import_batches` (traza de la ejecución).

**Pruebas obligatorias.**
Reejecución no duplica (idempotencia por `external_reference` = id de fila);
una fila inválida no aborta el lote; el informe identifica cada fallo por fila.

**Riesgos.** Reservar inventario por pedidos históricos ya despachados;
contaminar KPIs con fechas de importación en vez de fechas reales.

**Criterio de salida.** Importación idempotente y auditable.

---

## Fase 14 — PRUEBAS_INTEGRALES_Y_DESPLIEGUE

**Objetivo.** Consolidación y regresión. **No** es la primera vez que se prueba
el módulo: cada fase anterior entregó las suyas.

**Dependencias.** Fases 01 a 13.

**Alcance.**
Suite completa de casos límite CL-01…CL-22 [M-§15]; regresión sobre el checkout
del storefront y sobre Corte y Confección; verificación de que
`packages/Webkul/` sigue intacto; plan de despliegue (orden de migraciones,
backfill, activación por rol); plan de reversión.

**Riesgos.** Migraciones sobre una base con datos reales; despliegue simultáneo
con la operación en curso.

**Criterio de salida.** Suite en verde y plan de reversión probado.

---

## Fase futura — POS (sin número, no aprobada)

Registrada en la Fase 01B, **sin implementar ni planificar**. Interfaz de venta en el
local físico que reutiliza clientes, grupos, precios, inventario, carritos, pedidos y
**el mismo servicio común de creación** (`CrearPedidoComercialService`). Usará
`sales_channel = tienda_fisica` y `creation_source = pos`.

**Dependencias.** Fases 03 y 04 estabilizadas, además de logística, entregas,
devoluciones y comisiones: el POS no se adelanta a la venta por asesor.
**Decisiones que la bloquean.** D-30 (datos mínimos en POS), D-33 (comisión POS),
D-34 (alcance operativo y fiscal). Requisito técnico RT-03.

**Regla ya fijada.** Una venta POS con entrega inmediata solo genera el evento de
entrega **después** de registrar el pago y la salida de inventario; crear el pedido
no equivale a entregarlo.

---

## Grafo de dependencias

```
01 ──► 02 ──┬──► 03 ──► 04 ──────────────► 12 ──► 13
            │
            └──► 05 ──┬──► 06 ──► 07 ──► 08 ──► 09 ──► 10
                      │
                      └──► 11
```

Pueden avanzar en paralelo tras la Fase 02: la rama 03→04 (creación) y la rama
05→06 (consulta y logística). La 11 es independiente de la cadena de comisiones.

---

## Mapa decisión → fase bloqueada

Cada decisión bloquea **solo** la fase que realmente depende de ella.

| Decisión | Fase bloqueada |
|---|---|
| D-01 *(decidida)* | 08 — desbloqueada |
| D-02 *(decidida)* | 09 — desbloqueada |
| D-03 | 08 |
| D-04 | 09 |
| D-05 *(decidida)* | 02 — desbloqueada |
| D-06 *(decidida)* | 01, 02 — desbloqueadas |
| D-07 | 06 |
| D-08 | 12 |
| D-09 | 11 |
| D-10 | 10 |
| D-11 | 10 |
| D-12 | 05, 13 |
| D-13 | 05 |
| D-14 | 06 |
| D-15 | 09 |
| D-16 *(decidida 01B)* | 02, 03 — desbloqueadas |
| D-17 | 03, 04 |
| D-18 | 03, 04 |
| D-19 | 03 |
| D-20 | 04 |
| D-21 | 04, 08 |
| D-22 | 10 |
| ~~D-23~~ → **RT-01** | 07, 08, 09 (requisito técnico) |
| **D-24** (reformulada) | **09** |
| **RT-02** | 07, 08, 09 (requisito técnico) |
| **D-25** | **07** |
| **D-26** | **08** |
| D-27 *(decidida 01B)* | 02 — desbloqueada |
| **D-28** | **03, 04** |
| **D-29** | **04** |
| **D-30** | **04, POS** |
| **D-31** | **08** |
| **D-32** | **08** |
| **D-33** | **POS** |
| **D-34** | **POS** |
| **RT-03** | 03, 04 (requisito técnico) |
| **RT-04** | 03, 04 (requisito técnico, si D-28 adopta correo nulo) |

**Estado tras la Fase 01B.** Las Fases 01, 01A y 01B están completadas. La Fase 02 no
tiene decisiones bloqueantes y **no se ha iniciado**. La división en «Fase 02A» que
propuso la 01A deja de ser necesaria para desbloquearla.

---

## Principio de reversibilidad

Ninguna fase altera tablas, columnas ni estados del core. Desactivar el service
provider debe dejar Bagisto funcionando exactamente igual que antes de instalar
el módulo. Es un requisito de aceptación (CA-14, CA-15), no una aspiración.
