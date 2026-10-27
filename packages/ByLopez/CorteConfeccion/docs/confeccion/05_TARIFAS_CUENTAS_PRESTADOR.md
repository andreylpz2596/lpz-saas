# 05 — Tarifas y cuentas por pagar de talleres y cortadores

Estado: **FASE 1 COMPLETADA. FASE 2 IMPLEMENTADA Y AJUSTADA A TARIFAS POR REFERENCIA (2026-10-14), PENDIENTE DE PRUEBA MANUAL DE CAMILO. Fase 3 pendiente de N1.**
Alcance: `packages/ByLopez/CorteConfeccion/` únicamente. `packages/Webkul/` no se toca.

Terminología: **prestador** = Taller de confección o Cortador. Se evita «proveedor» porque en el
módulo ya significa Proveedor de Tela (`ProveedorTela`).

---

## 1. Qué existe hoy (punto de partida verificado)

| Pieza | Situación actual | Uso en esta ampliación |
|---|---|---|
| `bylopez_cc_talleres` / `Taller` | Ficha con `usuario_id` (admin del rol `TALLER CONFECCION`). Sin tarifa. | Se le cuelga el historial de tarifas. |
| `bylopez_cc_cortadores` / `Cortador` | Ficha con `usuario_id` **único** (admin del rol `CORTADOR`). Sin tarifa. | Ídem. |
| `bylopez_cc_ordenes_confeccion` | `taller_id` (FK). Nace en `OrdenConfeccionService::create()` y `::distribuir()`. | Snapshot de tarifa por orden. |
| `bylopez_cc_ordenes_corte` | `cortador_id` = **id de admin**, no id de `Cortador`; la ficha se resuelve por `cortadores.usuario_id`. Nace en `OrdenCorteService::create()` y `::crearDesdeSeleccion()`. | Snapshot de tarifa por orden. |
| Retornos de confección (`..._retornos`, `..._retorno_detalles`) | Entregas parciales: reportada / recibida / defectuosa / faltante. | Fuente candidata de cantidad pagable (pendiente). |
| Recepción de corte (`bylopez_cc_recepciones_corte` + detalles) | **Una** por orden de corte; recibida / defectuosa por detalle. | Fuente candidata de cantidad pagable (pendiente). |
| `talleres/show.blade.php`, `cortadores/show.blade.php` | Franja de indicadores + DataGrid de órdenes + card **«Historial del taller / del cortador» vacía y reservada**. | Se reutilizan franja, card de historial y accordion lateral. |
| `TallerController::resumenTaller()`, `CortadorController::resumenCortador()` | Indicadores productivos por subconsultas. | Se amplían con saldo y anticipo. |
| `TallerConfeccionAccess`, `CortadorAccess` | `puedeAccederTaller/Cortador()` ya limita al usuario prestador a **su propia ficha**; `filtrar*SiAplica()` limita listados. | Mismo aislamiento para cuenta e historial. |
| `partials/moneda.blade.php` | Máscara monetaria visual (`<v-moneda>`, `ByLopezMoneda.formatear`). | Montos de tarifas y pagos. |
| `Config/acl.php` | Sin permisos financieros. | Se agregan llaves nuevas (§5). |
| VentasAsesor — `bylopez_va_pagos`, `..._aplicaciones_pago`, `..._pagos_eventos` | Patrón ya aprobado en el proyecto: pago con monto, aplicaciones anulables (`anulada_en`, nunca se borran), eventos append-only, FKs RESTRICT, `decimal(12,4)`, CHECK monto > 0, idempotencia. | **Se copia el patrón**, no se depende del paquete VentasAsesor. |

No existe ningún campo, tabla, servicio ni permiso de tarifas o pagos a prestadores
(confirmado también en `STATUS.md`, «Problemas/riesgos abiertos»).

---

## 2. Reglas CONFIRMADAS (Camilo, 2026-10-12)

- R1. La tarifa se administra en la ficha de cada taller/cortador, por **tipo de tela + referencia del producto**, con historial de vigencias por combinación (ajuste 2026-10-14).
- R2. No hay precios por variante (talla/color) ni una tarifa general con excepciones: cada referencia se configura explícitamente (ajuste 2026-10-14).
- R3. Cada orden conserva la tarifa unitaria pactada **por combinación tela/referencia**; importe = tarifa × cantidad pagable de cada una.
- R4. Cambiar la tarifa de la ficha no altera órdenes anteriores.
- R5. Estado productivo y estado de pago son independientes (ya en `CONFECCION_MASTER.md` §23).
- R6. Se permiten abonos parciales, un pago para varias órdenes y excedentes como anticipo.
- R7. Cada aplicación de un anticipo queda registrada; el dinero no se cuenta dos veces.
- R8. «Historial del taller» muestra recepciones, pagos, anticipos y ajustes; mismo criterio para el cortador.
- R9. La empresa registra los pagos; cada prestador consulta exclusivamente sus órdenes y su cuenta.
- R10. Correcciones y reversos conservan trazabilidad (nada se borra ni se sobrescribe).
- R11. Las órdenes antiguas no se convierten automáticamente en deuda.
- R12. Fuera de esta versión: confirmación del pago por el prestador, notificaciones, conciliación bancaria.

## 3. Decisiones de Camilo (2026-10-12) y pendientes

Confirmadas (antes P1–P4):

- R13 (P3). La orden toma por defecto la tarifa **vigente de esa combinación en la ficha** al aparecer en la orden. Se puede cambiar **solo para esa combinación en esa orden** (p. ej. descuento por volumen con el satélite/taller), con motivo obligatorio y sin tocar la ficha.
  (p. ej. descuento pactado por volumen con el satélite/taller), con motivo obligatorio y sin tocar la ficha.
- R14 (P4). La tarifa del cortador es **por prenda**, igual que la del taller.
- R15 (P1). La cantidad pagable sale de la **recepción validada por Bodega**: al confirmarse, queda definida.
- R16 (P2). **Cortador:** se paga todo lo cortado, incluidas las defectuosas (el costo de la tela ya lo asume la
  empresa). **Taller:** solo se pagan las prendas buenas.

PENDIENTE (bloquea la Fase 3 del taller):

- N1. **Defectuosas totales declaradas por el taller.** Camilo quiere que el taller pueda registrar en la orden de
  confección cuántas prendas salieron defectuosas y NO se pueden confeccionar. Hoy `cantidad_defectuosa` la fija
  *Bodega* al recibir un retorno (RET-02); lo nuevo es una declaración *previa* del taller, sobre prendas que no
  vuelven como producto terminado. Falta definir antes de tocar el flujo de retornos:
  (a) ¿se declara al reportar un retorno o en un registro aparte?; (b) ¿Bodega las verifica físicamente?;
  (c) ¿cuentan como "resueltas" para cerrar la orden (hoy cierra con reportado = entregado)?;
  (d) ¿impiden el pago (R16) y afectan el stock? Propuesta: columna propia (p. ej. `cantidad_no_confeccionable`)
  distinta de `cantidad_defectuosa`, para no mezclar conceptos y conservar trazabilidad.

Decisiones técnicas (no son reglas de negocio):

- T1. La tarifa que se copia a la orden es la vigente **en la fecha de creación de la orden**.
- T2. Una orden **sin tarifa** (todas las anteriores a esta versión, y las de un cortador sin ficha)
  queda «Sin tarifa» y fuera de la cuenta. Solo entra si alguien con permiso le fija tarifa
  explícitamente (acción auditada). Esto cumple R11.
- T3. Los ajustes siempre pertenecen a una orden y llevan motivo obligatorio; nunca se editan, se anulan.
- T4. Una orden anulada con aplicaciones activas no se puede anular hasta anular esas aplicaciones
  (se valida en el servicio de cuenta, sin tocar `OrdenCorteEstadoMachine`).
- T5 (implementada en Fase 2). Una orden con tarifa pactada conserva `tarifa_prestador_id` (de qué tarifa de la
  ficha partió) y guarda `tarifa_motivo`. En Fase 3, cambiar la tarifa de una orden con pagos aplicados se bloquea.

---

## 4. Modelo

### 4.1 Tarifas por referencia (Fase 2 — AJUSTADA 2026-10-14; reemplaza la «tarifa general por prestador»)

Decisión de Camilo (2026-10-14): **no hay tarifa general con excepciones**. Cada taller y cada cortador tiene una lista
explícita de **referencias** y su valor por unidad. Cada tarifa es de una combinación
**proveedor del servicio + tipo de tela + referencia del producto**; aunque dos referencias valgan lo mismo, se configuran
y cambian por separado. Taller y cortador tienen tarifas propias e independientes.

Qué es cada pieza (sin catálogos nuevos, sin texto libre):

| Pieza | Fuente real |
|---|---|
| Tipo de tela | Opciones del atributo Bagisto `tipo_tela` (mismo catálogo de `Rollo.tipo_tela_id` y de las referencias del proveedor de tela). |
| Referencia del producto | **Familia Bagisto** (`attribute_families`), la «línea de corte» definida en `ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md`. No es la talla/color ni la referencia comercial de la tela. En el modal solo se ofrecen las familias con configurables soportados para esa tela (`ResolvedorVarianteCorteService::lineasPorTipoTela`). |

`bylopez_cc_tarifas_prestador` (migración `2026_10_14_000001`, aditiva): se añaden `tipo_tela_id` y `attribute_family_id`
(FK RESTRICT, juntas o ambas NULL). Una fila = una vigencia de una combinación; historial inmutable (se anula, no se edita).

- **Vigencias:** una vigencia rige desde `vigente_desde` hasta el día anterior a la siguiente activa de la **misma
  combinación**. La nueva vigencia debe ser **posterior** a la última activa, así nunca se superponen. Cambiar una tarifa =
  crear una vigencia nueva; el histórico y las tarifas conservadas en órdenes no se reescriben.
- **Transición (nada se borra):** las 2 tarifas generales creadas en dev durante las pruebas (taller 19 = $1.450,
  cortador 1 = $300) siguen en la tabla con ambas columnas NULL. **No se aplican a ninguna orden ni se distribuyen entre
  referencias.** Cada ficha muestra un aviso mientras exista una tarifa general activa. **Pendiente de configurar:** todas las
  referencias de todos los talleres y cortadores; hasta entonces las órdenes nuevas quedan «Sin tarifa».

### 4.2 Tarifa dentro de la orden (por combinación)

`bylopez_cc_orden_tarifas` (migración `2026_10_14_000002`): una fila por combinación tela + referencia presente en la orden
(corte o confección). Una **orden mixta** conserva una tarifa por combinación y su total sale de la cantidad de cada una.

- **Cómo se identifica la combinación desde la orden (solo relaciones):** tipo de tela = `rollo.tipo_tela_id` de los rollos
  de la orden de corte (la de confección usa los de su orden de corte; si hubiera más de uno queda «sin determinar»);
  referencia = `products.attribute_family_id` del `product_id` de cada detalle. Verificado sobre OC-FRE-000011 y OF-000012
  (Piel Durazno · Camiseta Hombre / Camiseta Mujer).
- **Faltantes concretos de relación:** las órdenes **no guardan** la familia ni el tipo de tela; se derivan en vivo. Si el
  producto cambia de familia después, el cálculo de combinaciones cambiaría (la tarifa guardada no, pero la fila quedaría
  huérfana). Los detalles con `product_id` NULL (columna nullable) no se pueden tarifar y se avisan. Recomendación futura, no
  hecha: guardar `attribute_family_id` y `tipo_tela_id` en los detalles al crearlos.
- La fila se crea cuando la combinación aparece en la orden (creación, adicionales del cortador, detalle agregado) y copia la
  tarifa vigente de la ficha en ese momento; si no hay, `tarifa_unitaria` queda NULL = **«Sin tarifa»** (nunca 0, nunca una
  tarifa general). Una tarifa configurada **después** no se aplica sola a una orden ya creada.
- Con permiso se puede **pactar otra tarifa solo para esa combinación en esa orden** (descuento por volumen), con motivo
  obligatorio y auditado; también sirve para fijar una combinación «Sin tarifa». No cambia la ficha ni otras órdenes.
- Las columnas `tarifa_*` que el primer intento de Fase 2 añadió a `ordenes_confeccion` y `ordenes_corte` quedan sin uso y
  sin borrarse (ninguna orden las tenía pobladas).
- El total mostrado en la orden es **referencial**: usa la cantidad asignada (confección) o cortada (corte). La cantidad
  **pagable** (R15/R16) es de la Fase 3 y no se tocó.

### 4.3 Cuenta (Fase 3)

- `bylopez_cc_pagos_prestador`: `taller_id`/`cortador_id` (uno), `monto` CHECK > 0, `fecha_pago`,
  `medio`, `referencia`, `observacion`, `estado` (`registrado` | `reversado`), `registrado_por_admin_id`,
  `reversado_en`, `reversado_por_admin_id`, `motivo_reverso`, `idempotency_key` UNIQUE, timestamps.
- `bylopez_cc_pago_aplicaciones`: `pago_id` (FK RESTRICT), `orden_confeccion_id` / `orden_corte_id`
  (uno), `monto` CHECK > 0, `anulada_en`, `anulada_por_admin_id`, `motivo_anulacion`,
  `creado_por_admin_id`, `created_at`.
- `bylopez_cc_cuenta_ajustes`: orden (uno de los dos ids), `monto` con signo, `motivo` obligatorio,
  `creado_por_admin_id`, `anulado_en`, `anulado_por_admin_id`, `motivo_anulacion`, `created_at`.
- `bylopez_cc_cuenta_eventos` (append-only, se crea en Fase 2 para registrar cambios de tarifa):
  prestador, `tipo`, `pago_id`/orden opcionales, `actor_admin_id`, `motivo`, `datos` JSON, `created_at`.

Derivados — **nunca se almacenan**:

- Cantidad pagable (R15/R16): taller = Σ `cantidad_recibida` (buenas) de los retornos que Bodega ya recibió; cortador = recibida + defectuosa de la recepción de corte confirmada por Bodega. Pendiente N1 para el taller.
- Importe de la orden = `tarifa_unitaria` × cantidad pagable + Σ ajustes activos.
- Pagado de la orden = Σ aplicaciones activas.
- Saldo de la orden = importe − pagado. Una aplicación nunca puede superar el saldo.
- Anticipo disponible de un pago = monto − Σ sus aplicaciones activas (pagos `registrado`).
- Anticipo del prestador = Σ anticipos disponibles de sus pagos.
- Estado de pago de la orden: `sin_tarifa` | `pendiente` | `abonada` | `pagada`.

Por qué no hay doble conteo (R7): el dinero vive solo en el pago; aplicar un anticipo es crear otra
aplicación del **mismo** pago a otra orden, lo que reduce su remanente. No existe un registro
«anticipo» con monto propio.

Reverso de un pago: anula todas sus aplicaciones activas y lo marca `reversado`, con evento.
Corrección de una distribución: anular la aplicación (motivo) y aplicar de nuevo.

### 4.4 Historial (Fase 3)

`HistorialPrestadorService` arma una línea de tiempo leyendo:
retornos recibidos (taller) o recepción de corte (cortador), pagos, aplicaciones (incluido el uso de
anticipos), ajustes, reversos/anulaciones y cambios de tarifa. Se pinta en la card «Historial»
existente de cada ficha. Sin tabla nueva para el historial.

---

## 5. Permisos (nuevas llaves en `Config/acl.php`)

- `corte_confeccion.talleres.tarifas` / `corte_confeccion.cortadores.tarifas` — crear/anular tarifas
  y fijar tarifa en una orden.
- `corte_confeccion.cuentas_prestador.pagar` — registrar pagos, aplicar anticipos, ajustes.
- `corte_confeccion.cuentas_prestador.reversar` — reversar pagos y anular aplicaciones/ajustes.

Consulta: quien ya ve la ficha ve la cuenta. El usuario prestador queda limitado a la suya por
`puedeAccederTaller/Cortador()` (ya existente); todas las rutas nuevas lo validan en backend además
de ocultar botones. Verificar en BD qué roles tienen hoy `corte_confeccion.talleres` /
`corte_confeccion.cortadores` antes de cerrar Fase 3.

---

## 6. Plan (máximo cuatro fases)

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Revisión puntual y este documento | **Completada 2026-10-12** |
| 2 | Tarifas históricas **por referencia** en ficha + tarifa por combinación en la orden (backend + frontend) | **Implementada y ajustada 2026-10-14; falta prueba manual** |
| 3 | Cuenta, pagos, abonos, anticipos, ajustes, historial (backend + frontend) | Reglas R15/R16 definidas; el lado taller espera N1 |
| 4 | Correcciones tras la revisión manual de Camilo + actualización de documentos | — |

### Fase 2 — implementada y AJUSTADA a referencias (2026-10-14)

Migraciones aplicadas en dev `lpz_saas`: `2026_10_13_000001` (tarifas), `000002` (columnas `tarifa_*` en órdenes, ya sin uso),
`000003` (eventos de cuenta, append-only) y, del ajuste, `2026_10_14_000001` (tela + referencia en la tarifa) y `000002`
(`bylopez_cc_orden_tarifas`).

Nuevos: `Models/TarifaPrestador`, `Models/OrdenTarifa`, `Models/CuentaEvento`, `Contracts/TarifaPrestador`,
`Repositories/TarifaPrestadorRepository`, `Services/TarifaPrestadorService` (vigencias por combinación, historial, avisos),
`Services/TarifaReferenciaCatalogo` (telas y referencias del catálogo), `Services/OrdenTarifaService` (combinaciones de una
orden, sincronizar, resumen, pactar), `Support/TarifaPrestadorGate`, `DataGrids/TarifaReferenciaDataGrid`,
`Http/Controllers/Admin/TarifaPrestadorController`, Requests `StoreTarifaPrestadorRequest`, `AnularTarifaPrestadorRequest`,
`CambiarTarifaOrdenRequest`.

Vistas: `partials/tarifas-prestador.blade.php` (tabla «Referencias y precios» + modales Agregar/Cambiar, Historial y Anular;
se incluye en `talleres/show` —«Referencias y precios de confección»— y `cortadores/show` —«Referencias y precios de
corte»—, en la columna principal, antes de «Historial») y `partials/tarifa-orden.blade.php` (tarjeta «Tarifas por
referencia» en `ordenes-confeccion/show` y `ordenes-corte/show`).

Modificados: `OrdenConfeccionService` (`create`, `distribuir`) y `OrdenCorteService` (`persistirOrden`, `reportarResultado`
para adicionales, `agregarDetalle`) llaman `OrdenTarifaService::sincronizar()` en su transacción; `TallerController`,
`CortadorController`, `OrdenCorteController::show`, `OrdenConfeccionController::show`; modelos de orden (relación `tarifas()`);
`Http/routes.php`; `Config/acl.php`; `lang/es/app.php`; CSS del paquete recompilado.

Permisos: `corte_confeccion.talleres.tarifas` y `corte_confeccion.cortadores.tarifas` administran (agregar referencia,
cambiar, anular, pactar en orden); consultar solo exige poder ver la ficha/orden. `TarifaPrestadorGate` los niega al usuario
con rol Taller Confeccion o Cortador, y el DataGrid/historial exigen `puedeAccederTaller/Cortador` (cada prestador solo ve
lo suyo). Todo se valida en backend (Form Requests + abort 403), no solo ocultando botones.

Pendiente de probar a mano (Camilo): agregar referencia (tela → referencias dependientes), cambiar tarifa (nueva vigencia
posterior; rechazo de fecha igual o anterior), anular, historial; que la tabla se refresque al guardar; aviso de tarifa
general anterior; crear distribución/orden de corte con tarifa configurada y ver que la orden la conserva; orden mixta con una
referencia sin tarifa («Sin tarifa», total parcial); pactar tarifa en la orden; cambiar la ficha no altera órdenes previas;
usuario taller/cortador solo ve lo suyo y sin controles.

### Fase 3 — archivos

Nuevos: migraciones de pagos, aplicaciones y ajustes; modelos/repositories;
`Services/CuentaPrestadorService.php` (saldos derivados, registrar pago con distribución FIFO editable,
aplicar anticipo, ajustes, reverso, anulación); `Services/HistorialPrestadorService.php`;
`Http/Controllers/Admin/CuentaPrestadorController.php`; Form Requests; partials
`cuenta-prestador.blade.php` y `historial-prestador.blade.php`.

Modificados: ambas fichas (franja: saldo y anticipo; card de cuenta; card de historial),
`resumenTaller()` / `resumenCortador()`, cancelación de órdenes (T4), DataGrids de órdenes
(columna estado de pago), `routes.php`, `acl.php`, `lang/*`.

## 7. Riesgos

- `ordenes_corte.cortador_id` apunta a admins: órdenes de un cortador sin ficha no tendrán tarifa.
- La recepción de corte es única por orden; si P1 la confirma, el importe del cortador queda fijo
  al recibirse. En confección es incremental (varios retornos).
- Calcular saldos derivados en DataGrids exige subconsultas agregadas (mismo estilo que `resumenTaller()`).
- Las órdenes no guardan `attribute_family_id` ni `tipo_tela_id`: se derivan de `product_id` y de los rollos. Un cambio de
  familia del producto en Bagisto no altera la tarifa ya conservada, pero sí cómo se agrupan las combinaciones en pantalla.
- Una tarifa configurada después de crear una orden no la alcanza (queda «Sin tarifa» hasta pactarla en la orden): decisión
  deliberada para no aplicar tarifas en silencio.
- Las 2 tarifas generales de prueba (taller 19 y cortador 1) quedan como historial sin efecto; las referencias reales deben
  cargarse desde las fichas.
