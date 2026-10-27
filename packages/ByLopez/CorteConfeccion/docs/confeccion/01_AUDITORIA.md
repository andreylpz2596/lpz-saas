# 01_AUDITORIA.md — Auditoría técnica: Distribución y Confección

## 0. Metadatos

- **Fecha de auditoría:** 2026-09-04
- **Alcance:** `packages/ByLopez/CorteConfeccion/` (lectura). `packages/Webkul/Product` inspeccionado únicamente como referencia de inventario, sin modificaciones.
- **Cambios realizados durante esta fase:** ninguno. Solo lectura y este documento (+ `STATUS.md`).
- **Nota de integridad:** `STATUS.md` (antes de esta auditoría) afirmaba que la Fase 01 y la Fase 02 ya estaban completadas y aprobadas, pero `01_AUDITORIA.md` y `02_MODELO_DISTRIBUCION.md` existían **vacíos**. Esta auditoría se ejecuta desde cero contra el código real, tratando esas afirmaciones previas como no verificadas. Ver sección 14.

---

## 1. Archivos inspeccionados

**Documentación**
- `CLAUDE.md`
- `docs/confeccion/CONFECCION_MASTER.md`
- `docs/confeccion/STATUS.md`
- `docs/confeccion/01_AUDITORIA.md`, `02_MODELO_DISTRIBUCION.md` (vacíos)

**Modelos**
- `Models/OrdenCorte.php`, `OrdenCorteDetalle.php`, `OrdenCorteRollo.php`
- `Models/RecepcionCorte.php`, `RecepcionCorteDetalle.php`
- `Models/OrdenConfeccion.php`, `OrdenConfeccionDetalle.php`
- `Models/Taller.php`

**Migraciones**
- `2026_05_07_000002/3` (ordenes_corte / detalles)
- `2026_05_07_000004/5` (recepciones_corte / detalles)
- `2026_06_14_000003` (orden_corte_cantidad_audits)
- `2026_08_29_000001/2/3` (talleres, ordenes_confeccion, ordenes_confeccion_detalles)

**Controladores / Servicios / Requests**
- `Http/Controllers/Admin/OrdenConfeccionController.php`
- `Http/Controllers/Admin/OrdenCorteController.php` (revisión parcial, botón "Distribuir a talleres")
- `Http/Requests/StoreOrdenConfeccionRequest.php`
- `Services/OrdenConfeccionService.php`
- `Services/DisponibilidadConfeccionService.php`
- `Services/ProductLookupService.php`
- `Repositories/OrdenConfeccionRepository.php`

**Enums / Estados**
- `Enums/EstadoOrdenConfeccion.php`
- `Enums/EstadoOrdenCorte.php`
- `Support/OrdenCorteEstadoMachine.php`

**Rutas / ACL**
- `Http/routes.php`
- `Config/acl.php`

**Vistas**
- `Resources/views/admin/ordenes-confeccion/index.blade.php`, `create.blade.php`, `show.blade.php`
- `Resources/views/admin/ordenes-corte/show.blade.php` (botón de entrada al flujo)
- `Resources/views/admin/talleres/show.blade.php`

**DataGrids**
- `DataGrids/OrdenConfeccionDataGrid.php`

**Tests existentes (leídos, no ejecutados en esta fase de solo-lectura)**
- `tests/Feature/RolesYTalleresTest.php`
- `tests/Feature/TallerVistaDetalleTest.php`

**Referencia Webkul (solo lectura, sin modificar)**
- `packages/Webkul/Product/src/Models/Product.php`
- `packages/Webkul/Product/src/Type/AbstractType.php`

---

## 2. Flujo actual (end-to-end, verificado en código)

```
OrdenCorte (estado: pendiente_entrega → entregado_a_cortador → en_corte
            → reportado_por_cortador → recibido_bodega → cerrado | anulado)
   │
   ├─ hasOne → RecepcionCorte (1 recepción por orden de corte)
   │              └─ hasMany → RecepcionCorteDetalle (product_id + talla,
   │                            cantidad_recibida, cantidad_defectuosa)
   │
   └─ hasMany → OrdenConfeccion (estado: activa | anulada)   [N por orden de corte]
                    ├─ belongsTo → Taller (1 taller por OrdenConfeccion)
                    └─ hasMany  → OrdenConfeccionDetalle (product_id + talla,
                                   cantidad_entregada)
```

Punto de entrada real: `ordenes-corte/show.blade.php:44` muestra el botón
**"Distribuir a talleres"** si `$orden->recepcion` existe (no valida el
`estado` de la orden de corte, ver §12.3). Lleva a
`GET /corte-confeccion/ordenes-confeccion/create/{ordenCorteId}`.

---

## 3. Mapa de modelos y relaciones

| Modelo | Tabla | PK | FKs | Relaciones Eloquent | Campos clave | Estados | Cantidades | Dinero/pago |
|---|---|---|---|---|---|---|---|---|
| `OrdenCorte` | `bylopez_cc_ordenes_corte` | id | `rollo_id`, `cortador_id`, `creado_por_id`, `reportado_por_id` | `rollo()` BT, `detalles()` HM, `rollosCorte()` HM, `recepcion()` **HasOne**, `ordenesConfeccion()` HM, `creadoPor()`/`reportadoPor()` BT | `codigo`, `color`, `genero_linea`, `peso_*`, `total_piezas_cortadas` | `EstadoOrdenCorte` (7 valores) | a nivel de peso (kg) y piezas totales | ninguno |
| `OrdenCorteDetalle` | `bylopez_cc_ordenes_corte_detalles` | id | `orden_corte_id`, `product_id` | `ordenCorte()` BT, `product()` BT | `variant_id`, `genero_linea`, `talla`, `es_adicional` | — | `cantidad_proyectada`, `cantidad_real_cortada` | ninguno |
| `RecepcionCorte` | `bylopez_cc_recepciones_corte` | id | `orden_corte_id` (único: hasOne) | `ordenCorte()` BT, `detalles()` HM | `fecha_recepcion`, `recibido_por`, `peso_*` | — (no tiene estado propio) | pesos de sobrante/retasos/desperdicio | ninguno |
| `RecepcionCorteDetalle` | `bylopez_cc_recepciones_corte_detalles` | id | `recepcion_corte_id`, `product_id` (nullable) | `recepcionCorte()` BT, `product()` BT | `producto_sku`, `producto_nombre`, `talla` (**sin campo `color` estructurado**) | — | `cantidad_recibida`, `cantidad_defectuosa` (default 0) | ninguno |
| `OrdenConfeccion` | `bylopez_cc_ordenes_confeccion` | id | `orden_corte_id` (cascadeDelete), `taller_id` (restrictDelete) | `ordenCorte()` BT, `taller()` BT, `detalles()` HM | `codigo` (único, `OF-######`), `fecha_entrega`, `observacion` | `EstadoOrdenConfeccion`: **solo `activa` / `anulada`** | ninguna directa (se agrega desde detalles) | ninguno |
| `OrdenConfeccionDetalle` | `bylopez_cc_ordenes_confeccion_detalles` | id | `orden_confeccion_id` (cascadeDelete), `product_id` (nullable) | `ordenConfeccion()` BT, `product()` BT | `producto_sku`, `producto_nombre`, `talla` | — | `cantidad_entregada` (unsigned, default 0) | ninguno |
| `Taller` | `bylopez_cc_talleres` | id | `usuario_id` → `Admin` | `usuario()` BT, `ordenesConfeccion()` HM | `nombre`, `nit`, `contacto`, `telefono`, `direccion` | `estado`: `activo`/`inactivo` (string libre, sin enum) | — | ninguno |

**Relación clave que confirma el diseño ya correcto para "una recepción → varios talleres":**
`OrdenCorte::ordenesConfeccion()` es `hasMany`, y `RecepcionCorte` **no** tiene ni referencia directamente a `Taller`. Es decir, el código **ya evita** la relación falsa `RecepcionCorte → Taller` que el master doc (§26) advierte no crear. Esto es correcto.

**Producto/Variante:** ambos `*Detalle` usan `product_id` (columna simple, sin `variant_id` salvo en `OrdenCorteDetalle`) + `talla` (string libre) para identificar la unidad distribuible. No hay tabla intermedia de "asignación" independiente: la asignación *es* la fila `OrdenConfeccionDetalle`.

**Inventory Source / stock Bagisto:** no hay ninguna relación ni consulta hacia `product_inventories` / `product_inventory_indices` en todo el paquete (confirmado por grep, ver §7). El módulo no conoce el stock de Bagisto.

---

## 4. Auditoría de la pantalla actual "Distribuir a talleres"

| Pregunta | Respuesta verificada |
|---|---|
| **Ruta exacta** | `GET corte-confeccion/ordenes-confeccion/create/{ordenCorteId}` → `admin.corte_confeccion.ordenes_confeccion.create`. Submit: `POST corte-confeccion/ordenes-confeccion/create` → `...store`. (`routes.php:78-79`) |
| **Controller/action** | `OrdenConfeccionController::create(int $ordenCorteId)` y `::store(StoreOrdenConfeccionRequest $request)` |
| **Blade/Vue** | Blade puro (`ordenes-confeccion/create.blade.php`), sin componente Vue. Un único `<form>` con inputs nativos (`<input type="number">` por fila). |
| **Service que calcula cantidades** | `DisponibilidadConfeccionService::porOrdenCorte()` |
| **Cómo se obtiene "Ya entregado"** | `SUM(OrdenConfeccionDetalle.cantidad_entregada)` agrupado por `product_id+talla`, iterando **todas** las `OrdenConfeccion` de esa `OrdenCorte` — **sin filtrar por `estado`** (ver hallazgo crítico §6). |
| **Cómo se obtiene "Disponible"** | `apta - entregado`, con `max(..., 0)` (nunca negativo) |
| **Cómo se genera la Orden de Confección** | `OrdenConfeccionService::create()`: valida taller activo + recepción existente + cada detalle contra `disponiblePara()`, y crea **una sola** `OrdenConfeccion` con **un solo `taller_id`** para todos los detalles del submit, dentro de una transacción. |
| **¿Soporta solo un Taller por operación?** | **Sí.** `taller_id` es un campo único a nivel de request (`StoreOrdenConfeccionRequest.php:28`), no por fila. Una sola llamada a `store()` = un solo taller. |
| **¿Una misma variante puede repartirse?** | Sí, pero **no en una sola operación**: hay que enviar el formulario dos veces (una por taller), recargando la pantalla entre una y otra para que "Disponible" se recalcule con lo ya entregado al primer taller. |
| **¿Permite dejar cantidad sin distribuir?** | Sí: el input `cantidad_entregada` acepta 0 y no hay validación que exija consumir todo lo disponible. |

---

## 5. Integridad de cantidades

**Fórmulas verificadas en `DisponibilidadConfeccionService` (coinciden exactamente con CONFECCION_MASTER.md §6):**

```
apta_confeccion       = max(cantidad_recibida - cantidad_defectuosa, 0)
entregado_talleres    = SUM(cantidad_entregada) de TODAS las OrdenConfeccionDetalle
                        de TODAS las OrdenConfeccion de esa OrdenCorte,
                        agrupado por (product_id, talla)
disponible_confeccion = max(apta_confeccion - entregado_talleres, 0)
```

Nada se persiste como cantidad derivada (correcto, cumple "no duplicar datos").

**Validaciones existentes:**
- `OrdenConfeccionService::create()` (líneas 37-49): por cada detalle, si `cantidad_entregada > disponible` → `ValidationException`. Esto se re-verifica en el servidor (no solo en el `max` del `<input>` del blade), así que un usuario no puede burlar el límite manipulando el HTML.
- Todo el `create()` corre dentro de `DB::transaction()` → atómico para una orden.
- No hay chequeo de `cantidad_entregada < 0` explícito, pero la regla de validación `StoreOrdenConfeccionRequest` (`'gt:0'`) ya excluye negativos y ceros antes de llegar al service.

### 5.1 Hallazgo crítico: cancelar una OrdenConfeccion NO libera la cantidad

`DisponibilidadConfeccionService::porOrdenCorte()` (línea 26-29) suma
`cantidad_entregada` de **todas** las `ordenesConfeccion` de la orden de
corte, **sin excluir las que están en estado `anulada`**:

```php
$entregadoPorClave = $ordenCorte->ordenesConfeccion
    ->flatMap(fn ($ordenConfeccion) => $ordenConfeccion->detalles)
    ->groupBy(...)
```

`OrdenConfeccionService::cancel()` solo cambia `estado` a `anulada`; no
toca ni borra los `OrdenConfeccionDetalle`. Consecuencia real: **si Bodega
anula una Orden de Confección, esas unidades quedan "perdidas" para
siempre** — siguen contando como "ya entregado" y nunca vuelven a aparecer
como disponibles para redistribuir a otro taller.

Esto **contradice directamente** CONFECCION_MASTER.md §29: *"Cancelaciones/
reversiones deben mantener integridad de cantidades"* y §3: *"Las
cantidades no distribuidas deben permanecer disponibles para una
distribución futura"* (una anulación debería devolver la cantidad al pool
de disponibles). No hay test que cubra este escenario (ver §11).

---

## 6. Auditoría de inventario Bagisto (solo lectura, sin modificar Webkul)

- El módulo **no consulta stock de Bagisto en ningún punto** (`ProductLookupService` solo trae `id/sku/name` de `products`+`product_flat`; confirmado por grep de `inventory|qty|stock` en todo el paquete: cero resultados fuera de la documentación).
- Referencia oficial identificada en Webkul:
  - `Product::totalQuantity()` → delega a `AbstractType::totalQuantity()` (`packages/Webkul/Product/src/Type/AbstractType.php:531`) → lee `getInventoryIndex()->qty`, que consulta la relación `inventory_indices` (tabla `product_inventory_indices`) **filtrada por canal actual** (`core()->getCurrentChannel()->id`). Este es el **stock vendible/indexado** (ya descuenta reservas/ordenados).
  - Separadamente existe `Product::inventory_sources()` (BelongsToMany con pivot `qty`, tabla `product_inventories`), que es el **stock físico por almacén/fuente**, sin descontar ventas pendientes.
  - `Product::haveSufficientQuantity(int $qty)` también delega al type instance; útil como validación de "stock suficiente" si se necesitara en el futuro.
- **Forma más segura de solo-lectura sugerida (no implementada):** inyectar `ProductRepository`/`Product` model de Webkul y leer `$product->totalQuantity()` (o consultar `product_inventory_indices` filtrando por canal) desde `ProductLookupService` o un servicio nuevo, sin escribir nada en esas tablas. Esto es una recomendación para una fase futura, no un cambio de esta fase.

---

## 7. Estados de Orden de Confección

**Estados reales (`EstadoOrdenConfeccion` enum):**

```
activa   (default al crear)
anulada  (terminal, solo alcanzable desde 'activa'; ver OrdenConfeccionService::cancel())
```

No existe ningún otro estado. **No hay State Machine** para `OrdenConfeccion`
(a diferencia de `OrdenCorte`, que sí tiene `OrdenCorteEstadoMachine`).
Las únicas transiciones son:
- creación → `activa`
- `activa` → `anulada` (bloqueada si ya está `anulada`, `OrdenConfeccionService::cancel()` línea 76-78)

**Comparación contra el requisito funcional (CONFECCION_MASTER.md §21):**

| Estado requerido | ¿Existe? |
|---|---|
| Entregada al taller | Parcialmente — `activa` cumple ese rol implícitamente, pero no se llama así ni es un paso explícito |
| En confección (opcional) | **No existe** |
| Terminada | **No existe** |
| Recibida en Bodega (retorno) | **No existe** |
| Anulada | Sí (`anulada`) |

**Conclusión:** el requisito §21-24 (estados de producción + retorno del
taller) está **0% implementado**. Todo lo que hoy existe es "entregada
(activa)" vs "anulada". No hay campos para `cantidad_confeccionada`,
`cantidad_defectuosa_retorno`, `cantidad_faltante` ni fecha de recepción
en bodega de vuelta.

---

## 8. Auditoría de pagos

Búsqueda (`grep -i "pago|payment|valor_unitario|valor_total|comprobante"`)
en todo `packages/ByLopez/CorteConfeccion/src` y `tests`: **cero
resultados**. Los únicos archivos que mencionan pagos son los propios
documentos de reglas (`CONFECCION_MASTER.md`, `STATUS.md`).

**Conclusión:** no existe ningún campo, tabla, servicio ni estado de pago
para `OrdenConfeccion` o `Taller`. Requisito §7/§23 del master doc: **no
implementado, 0%**.

---

## 9. Caso funcional principal (60 / 20 / 20) — ¿el modelo actual lo soporta?

**A nivel de esquema de datos: SÍ, sin pérdida ni duplicación.**
`OrdenCorte hasMany OrdenConfeccion` permite crear:
- `OrdenConfeccion A` → `taller_id=1`, detalle `{Hombre L: 60}`
- `OrdenConfeccion B` → `taller_id=4`, detalle `{Hombre L: 20}`

y `DisponibilidadConfeccionService` calcula correctamente
`disponible = 100 - 60 - 20 = 20` para "Hombre L", sin tocar "Hombre M" (80
disponibles) ni "Hombre S" (70 disponibles), porque el agrupamiento es por
`(product_id, talla)` y no por operación.

**A nivel de flujo/operación (UI + Controller + Service): NO, tal como
está descrito en el master doc (§19-20, "una sola operación genera
múltiples órdenes con resumen previo").** Limitación técnica exacta:

> `StoreOrdenConfeccionRequest::rules()` línea 28 define `taller_id` como
> `required|integer` **singular**, no como un array por fila de detalle.
> `OrdenConfeccionService::create()` línea 24-25 carga **un** `Taller` y lo
> aplica a todos los `$detalles` del payload. No existe ningún punto del
> código que itere sobre varios `taller_id` en una misma request ni que
> agrupe detalles por taller antes de persistir.

Para lograr el caso 60/20/20 hoy, un usuario de Bodega debe:
1. Abrir `create/{id}`, elegir Taller 1, poner `60` en la fila "Hombre L", dejar el resto en 0, enviar.
2. Volver a abrir `create/{id}` (la disponibilidad ya bajó a 40 para Hombre L), elegir Taller 4, poner `20`, enviar.
3. El resultado final en base de datos es correcto (dos órdenes, cantidades exactas), pero **no hay una única pantalla de resumen que muestre ambos talleres a la vez antes de confirmar**, ni "taller predeterminado", ni selección múltiple de filas, ni prioridad — todo eso está ausente (ver matriz §10).

---

## 10. Matriz de comparación contra CONFECCION_MASTER.md

| Requisito funcional (sección) | Estado actual | Evidencia | Cambio requerido |
|---|---|---|---|
| Dividir variante entre talleres en una sola operación (§4,5,19) | Parcial (posible con N envíos manuales secuenciales, no en 1 operación) | `StoreOrdenConfeccionRequest.php:28`, `OrdenConfeccionService.php:24-25` | Lógica + UI (request/servicio deben aceptar detalle por taller y crear N órdenes en una transacción) |
| Dejar cantidad pendiente en bodega (§3) | Soportado | `disponible_confeccion` nunca obliga a distribuir todo; input acepta 0 | Ninguno |
| Taller predeterminado aplicado a filas, override individual (§7) | No soportado | No existe en `create.blade.php`; un solo `<select>` de taller para todo el formulario | UI + Lógica |
| Selección/acciones masivas (§9) | No soportado | Ausente en `create.blade.php` | UI + Lógica |
| "Dividir entre talleres" por fila (§10) | No soportado | Ausente | UI + Lógica |
| Resumen antes de confirmar, multi-taller (§20) | No soportado | El formulario envía directo sin paso de confirmación agregada | UI + Lógica |
| Stock actual de Bagisto visible (§11) | No soportado | `ProductLookupService` no consulta inventario; confirmado sin referencias a `inventory_indices`/`inventory_sources` en el paquete | Integración Bagisto (solo lectura) |
| Stock proyectado informativo (§13) | No soportado (depende de §11) | — | UI + Integración Bagisto |
| Prioridad de confección (§14-16) | No soportado | Sin campo en migraciones ni en `OrdenConfeccionDetalle` | Modelo/DB + UI |
| Fecha de entrega predeterminada con override por operación (§17) | Soportado a nivel de orden (1 fecha por `OrdenConfeccion`, es decir, por taller/operación) | `fecha_entrega` en `OrdenConfeccion` | Ninguno (ya granular por operación=taller) |
| Observación general y de orden (§18) | Observación de orden: sí. Observación general de "la distribución" (previa a split): no aplica porque no existe pantalla de resumen unificada | `create.blade.php:33-37` | Pendiente de decisión (depende de si se construye el resumen multi-taller) |
| Una OrdenConfeccion nunca mezcla talleres (§19) | **Ya cumplido por diseño** | `OrdenConfeccion belongsTo Taller` (1 a 1) | Ninguno |
| Integridad: no perder/duplicar cantidades en distribución normal (§29) | Cumplido en el camino feliz | `DisponibilidadConfeccionService` + validación server-side en `create()` | Ninguno |
| Integridad: cancelación mantiene cantidades (§29) | **Incumplido** — cancelar no libera cantidad | `DisponibilidadConfeccionService.php:26-29` no filtra `estado != anulada` | Lógica (bug real, no solo gap funcional) |
| Estado "En confección" opcional (§21) | No existe ningún estado de producción más allá de activa/anulada | `EstadoOrdenConfeccion.php` (2 valores) | Estados (nueva state machine) |
| Estado "Terminada" (§21) | No existe | ídem | Estados |
| Retorno del taller: confeccionada/defectuosa/faltante/recibida bodega (§24) | No existe | Sin campos en `OrdenConfeccionDetalle` ni tabla de retorno | Modelo/DB + Estados + Lógica |
| Estado producción independiente de estado de pago (§23) | No aplica todavía (no hay ninguno de los dos conceptos de producción avanzada ni pago) | — | Pendiente de decisión (bloqueado por §21 y §7) |
| Pago al taller (valor, estado, fecha, comprobante) (§7) | No existe | Grep sin resultados en código | Modelo/DB + nuevo Service, pendiente de decisión de alcance |
| Vista de Taller estilo Cliente (§25) | Mayormente cumplido (nombre, estado, contacto, dirección, crear confección, listado de órdenes, trazabilidad de origen vía columna "Corte de origen") | `talleres/show.blade.php` | UI menor (falta separar "activas" vs "terminadas" — bloqueado por §21; falta estado de pago — bloqueado por §7) |
| Trazabilidad OrdenConfeccion → OrdenCorte → RecepcionCorte (§26) | Cumplido | `OrdenConfeccion belongsTo OrdenCorte`; `OrdenCorteDataGrid`/`OrdenConfeccionDataGrid` enlazan a la orden de corte de origen | Ninguno |
| No crear relación falsa RecepcionCorte→Taller (§26) | Cumplido (nunca se creó) | — | Ninguno |
| Producto/Color/Talla separados en UI (§28) | Parcial — Talla es columna propia; Color **no** es campo estructurado (va embebido en `producto_nombre` en texto libre) | `RecepcionCorteDetalle`/`OrdenConfeccionDetalle` sin columna `color` | UI/Lógica, posiblemente Modelo si se requiere color estructurado real |

---

## 11. Deuda técnica detectada

1. **`DisponibilidadConfeccionService` no excluye órdenes `anulada`** al sumar "ya entregado" → cancelar una Orden de Confección congela esa cantidad para siempre (§5.1). Es el hallazgo más urgente porque es una discrepancia de negocio silenciosa, no solo una funcionalidad faltante.
2. `OrdenConfeccionService::create()` no valida el `estado` de la `OrdenCorte` (solo exige que `recepcion` exista). Si una orden de corte fuera anulada después de `recibido_bodega` (transición permitida por `OrdenCorteEstadoMachine`, ya que "anulado" es alcanzable desde cualquier estado no terminal), el botón "Distribuir a talleres" seguiría visible y el `create()` no lo impediría. Riesgo bajo en probabilidad, pero real.
3. `Taller.estado` es un string libre (`'activo'/'inactivo'` comparado por igualdad de string en varios lugares: `OrdenConfeccionService.php:27`, `talleres/show.blade.php:11`) en vez de un enum como `EstadoOrdenCorte`/`EstadoOrdenConfeccion`. Inconsistencia de patrón, no un bug.
4. No existe auditoría de cambios/anulaciones para `OrdenConfeccion` equivalente a `bylopez_cc_orden_corte_cantidad_audits` (que sí existe para `OrdenCorteDetalle`). Si se llegan a permitir ediciones o reversiones de cantidades en confección, hoy no quedaría rastro.
5. `01_AUDITORIA.md` y `02_MODELO_DISTRIBUCION.md` vacíos mientras `STATUS.md` afirmaba fases "completadas"/"aprobadas" — ver §14.

---

## 12. Riesgos

- **Riesgo de negocio (alto):** operar el flujo real con múltiples talleres hoy exige que Bodega repita manualmente la operación por cada taller, sin un resumen agregado antes de confirmar. Aumenta la probabilidad de error humano (double-check manual de cuánto ya se envió).
- **Riesgo de integridad (alto):** el bug de cancelación (§5.1) puede generar pérdida silenciosa de cantidad disponible sin ningún mensaje de error — Bodega no tendría forma de saber por qué "falta" tela apta para confeccionar.
- **Riesgo de alcance (medio):** los requisitos de estados de producción (§21-24) y pagos (§7) requieren diseño de modelo nuevo; no deben mezclarse con la Fase 03 (distribuir a talleres) sin antes decidir su propia fase, tal como indica el propio master doc (§31).

---

## 13. Conclusión

El **modelo de datos** actual (tablas `bylopez_cc_ordenes_confeccion` /
`_detalles`, más `RecepcionCorteDetalle`) ya es capaz de representar sin
pérdida ni duplicación el caso de referencia 60/20/20 del master doc,
porque el cálculo de disponibilidad agrega correctamente por
`(product_id, talla)` a través de todas las órdenes de confección de una
misma orden de corte, y cada `OrdenConfeccion` ya está atada a un único
taller (cumple §19 por diseño).

Lo que **no existe** es la experiencia de una sola operación de
distribución multi-taller con resumen previo, taller predeterminado,
acciones masivas, prioridad y visibilidad de stock — todo eso vive
únicamente en `CONFECCION_MASTER.md` como requisito, no en código. Tampoco
existen los estados de producción posteriores a la entrega (`en
confección`, `terminada`, `recibida en bodega`) ni nada de la capa de
pagos.

Se detectó además un **bug de integridad real** (no solo una funcionalidad
pendiente): anular una Orden de Confección no libera la cantidad
distribuida, violando la regla de negocio explícita del master doc §29.

---

## 14. Nota sobre STATUS.md previo (discrepancia encontrada)

Antes de esta auditoría, `STATUS.md` indicaba `Última fase validada: Fase
02 — Modelo de distribución` con una lista de "Decisiones aprobadas". Sin
embargo:

- `01_AUDITORIA.md` y `02_MODELO_DISTRIBUCION.md` estaban **vacíos** —
  no hay ningún documento que respalde que esas fases se ejecutaron con
  evidencia real contra el código.
- Las "decisiones aprobadas" que listaba (una recepción puede
  distribuirse entre varios talleres, una variante puede dividirse, las
  cantidades no distribuidas permanecen disponibles, OrdenConfeccion es
  la unidad de trabajo del taller, "en confección" es opcional, estado de
  producción y pago son independientes) **coinciden textualmente con
  reglas de negocio de CONFECCION_MASTER.md**, no con hallazgos técnicos
  propios verificados contra el código.
- Al auditar el código real: la mayoría de esas decisiones son válidas
  **como reglas de negocio** y, a nivel de *esquema de datos*, son
  compatibles con lo ya construido (ver §9-10). Pero la
  **implementación** de varias de ellas (estados de producción, "en
  confección" opcional, multi-taller en una sola operación) **no existe
  en el código todavía** — están pendientes desde cero, no "aprobadas y
  pendientes de construir según un diseño ya definido".

No se modificó `STATUS.md` para invalidar esas decisiones de negocio (siguen
siendo válidas como dirección), pero se retoma el estado a `01_AUDITORIA —
completada` para reflejar que no hay evidencia técnica escrita de una Fase
02 real, y para que la Fase 02 se ejecute (o se re-ejecute) dejando
evidencia esta vez. Este archivo (`01_AUDITORIA.md`) es esa evidencia para
la Fase 01.
