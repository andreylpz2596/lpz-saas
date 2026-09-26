# 02_MODELO_FUNCIONAL_VENTAS_ASESOR.md

Modelo funcional **propuesto** para el módulo `ByLopez\VentasAsesor`.

**Última actualización:** 2026-09-20 (Fase 02B0)
**Estado:** PROPUESTA. Están decididas D-01, D-02, D-05, D-06, **D-16** y **D-27**; el
resto sigue pendiente y **no debe tratarse como definitivo**
(`04_DECISIONES_PENDIENTES.md`).

> **Fase 01B.** Se incorporan la propiedad comercial (`commercial_owner_type`, §5.1 y
> §14.5), el canal `tienda_fisica` y el mecanismo `pos` (§6), la regla de clientes de
> WhatsApp (§5.4) y los casos CL-23 a CL-28. Evidencia técnica en
> `06B_AUDITORIA_CLIENTES_PRECIOS_Y_PROPIEDAD_COMERCIAL.md` (`[B-§n]`).

> **Fase 02B0.** Se corrigen §10.2 (el motor es `RegistrarAtribucionService`, no
> `updateOrCreate`), §10.4 (storefront es `company` / `completa`, no `sin_asesor`), §10.6
> (captura de `Throwable` y límites de `fallo_tecnico`) y CA-02, con la evidencia de
> `08_FASE_02B0_AUDITORIA_CONEXION_PEDIDOS.md` (`[D-§n]`).

> **Fase 02B1A.** Se anotan §10.4, §10.5 y §10.6 con lo realmente implementado: clasificación
> cerrada por rutas, contexto `scoped` por `cart_id` (no tabla durable de contexto) y taxonomía
> de errores, con la evidencia de `09_FASE_02B1A_LISTENER_CONTEXTO_INCIDENCIAS.md` (`[E-§n]`).

Referencias `[A-§n]` → `01_AUDITORIA_CORE_VENTAS_BAGISTO.md`.

---

## 1. Principio rector (corregido en Fase 00B)

> `ByLopez\VentasAsesor` **puede** ofrecer interfaz, controlador y servicio
> orquestador propios para crear pedidos, pero **siempre** debe utilizar el
> carrito, productos, precios, validaciones, transformadores, repositorios,
> eventos, inventario y demás servicios oficiales de Bagisto. **Nunca**
> insertará directamente en `orders`, `order_items`, `addresses`,
> `order_payment` ni en ninguna otra tabla del core, y **nunca** mantendrá un
> segundo motor de pedidos.

### 1.1 Lo permitido y lo prohibido

| Elemento | Veredicto | Motivo |
|---|---|---|
| **Interfaz propia** del asesor | **Permitida y necesaria** | La pantalla oficial no sirve para vender por WhatsApp |
| **Servicio orquestador propio** (`CrearPedidoAsesorService`; nombre recomendado desde la Fase 01B: `CrearPedidoComercialService`) | **Permitido y necesario** | El core no expone un servicio de aplicación; la lógica vive dentro de controladores [A-§19.2]. Lo reutilizarán asesor, POS, API y n8n |
| **Controlador propio** | Permitido | Es el punto de entrada de la interfaz propia |
| **DataGrid, vistas y rutas propias** | Permitidos | Aislamiento por asesor |
| **Motor de pedidos propio** | **Prohibido** | Duplicaría totales, impuestos, inventario y estados |
| **INSERT/UPDATE directo en tablas Sales** | **Prohibido** | Rompe eventos, totales y trazabilidad |
| **Copiar controladores, modelos, migraciones o repositorios de `Webkul`** | **Prohibido** | Es el fork encubierto que esta fase evita |
| **Llamadas HTTP internas a rutas de Bagisto** | **No recomendado** | Sesión, CSRF, ACL y errores opacos; además `CartController` es una envoltura casi transparente sobre la facade [A-§19.4] |
| **Uso programático de servicios y repositorios oficiales** | **Recomendado** | Es la vía diseñada para ello |

### 1.2 Fuente de verdad

Bagisto sigue siendo la fuente única de verdad de: pedidos, ítems, clientes,
direcciones, precios, descuentos, impuestos, inventario, facturas, pagos,
envíos, cancelaciones, reembolsos, devoluciones (RMA) y estados oficiales.

`VentasAsesor` añade **solo** lo que el core no modela [A-§14.2, A-§17.10]:

1. Atribución comercial (asesor, creador, canal, mecanismo).
2. Estado logístico del envío (el core no tiene máquina de estados de envío).
3. Retorno de transportadora (concepto inexistente en el core).
4. Evidencias documentales asociadas a la venta.
5. Comisiones por entrega (reglas, eventos, liquidación, ajustes).
6. KPIs y metas.

**Regla dura:** el módulo nunca escribe en `orders.status`, nunca mueve
inventario y nunca recalcula totales.

---

## 2. Actores

| Actor | Descripción | Rol Bagisto de referencia |
|---|---|---|
| **Asesor** | Registra ventas llegadas por WhatsApp, consulta sus pedidos, adjunta evidencias, ve sus KPIs y comisiones | `ASESOR VENTAS` (id 2, existe, hoy sin usuarios) |
| **Supervisor de ventas** | Ve a todos los asesores, reasigna ventas, aprueba comisiones | *no existe todavía* |
| **Administrador** | Acceso total; define reglas de comisión; corrige atribución | `Administrador` (id 1, `permission_type=all`) |
| **Bodega / Logística** | Despacha, registra guía y estado logístico, marca entregas y retornos | `BODEGA` (id 4) |
| **Contabilidad** | Marca liquidaciones como pagadas; consulta valores | *no existe todavía* |
| **Automatización** (n8n / WhatsApp) | Origina pedidos sin usuario humano | *no existe todavía* [A-§3.4] |

---

## 3. Casos de uso

### CU-01 · Registrar una venta desde la interfaz del asesor
El asesor usa la **interfaz propia del módulo**, que invoca
`CrearPedidoAsesorService`, que a su vez orquesta el carrito y los repositorios
oficiales de Bagisto. Ver `05_DISENO_TECNICO_CREACION_PEDIDOS_ASESOR.md`.

### CU-02 · Registrar una venta desde el panel oficial
Sigue disponible el flujo administrativo del core [A-§3.2]. El módulo lo atribuye
igual, mediante el mismo listener.

### CU-03 · Atribuir automáticamente creador, asesor, canal y mecanismo
Al persistirse el pedido, el módulo registra la atribución (§5). Nunca depende de
que el asesor recuerde marcar nada.

### CU-04 · Asignar o reasignar el asesor de una venta
Cambio de `advisor_id` con historial obligatorio (valor anterior, nuevo, autor,
fecha, motivo). El pedido de Bagisto no se modifica.

### CU-05 · Consultar mis pedidos (asesor)
Listado propio, filtrado **en el servidor** por `advisor_id = usuario
autenticado`. Nunca por ocultamiento de botones ni por filtro opcional.

### CU-06 · Consultar pedidos de todos los asesores (supervisor)
Mismo listado sin el filtro, con filtros por asesor, canal, mecanismo, estado
logístico y rango de fechas.

### CU-07 · Registrar guía y transportadora
La guía se crea en el envío oficial de Bagisto (`carrier_title`, `track_number`
[A-§4.5]). El módulo añade transportadora normalizada, estado logístico, fechas
y novedades.

### CU-08 · Actualizar estado logístico
Bodega cambia el estado del envío. Cada cambio genera historial con autor y
origen (`manual` o `api`).

### CU-09 · Registrar entrega efectiva
Evento que **causa la comisión** (D-01). Se registra por `shipment_item`, con
fecha confirmada de entrega.

### CU-10 · Registrar retorno de transportadora
El paquete no se entregó y vuelve a ByLopez. **Nunca causa comisión.**
Concepto que el core no modela [A-§17.9].

### CU-11 · Registrar devolución del cliente
Se gestiona con el **RMA oficial** de Bagisto. El módulo solo concilia el
resultado para generar el ajuste negativo de comisión.

### CU-12 · Adjuntar evidencias
Archivos clasificados por categoría, con autor, fecha y nota. Almacenamiento
privado y descarga autorizada.

### CU-13 · Calcular comisión provisional
Por periodo, sobre eventos de entrega elegibles, congelando la regla aplicada.

### CU-14 · Aprobar y pagar liquidación
Supervisor y Contabilidad, con registro de quién y cuándo.

### CU-15 · Ver dashboard de KPIs
El asesor ve los suyos; el supervisor ve los de todos.

---

## 4. Reglas de negocio

| # | Regla | Fundamento |
|---|---|---|
| RN-01 | El pedido y sus totales son de Bagisto; el módulo nunca los altera | [A-§11.4] |
| RN-02 | `created_by_admin_id` es inmutable una vez registrado | Trazabilidad |
| RN-03 | `advisor_id` es mutable solo por rol autorizado y siempre con historial | CU-04 |
| RN-04 | Un pedido sin asesor es un estado **válido**, distinto de un fallo de atribución | §10 |
| RN-05 | Un pedido puede tener varios envíos y varias guías | `Order::shipments()` es `HasMany` [A-§4.7] |
| RN-06 | El estado logístico vive por **envío**, nunca en `orders.status` | [A-§4.4] |
| RN-07 | La comisión se causa por **prenda entregada y no devuelta** | D-01 |
| RN-08 | Una liquidación pagada es **inmutable**; toda corrección es un ajuste posterior | D-02 |
| RN-09 | Cada pedido tiene **un solo** asesor propietario | D-05 |
| RN-10 | El creador administrativo puede ser distinto del asesor propietario | D-05 |
| RN-11 | El asesor se identifica por **permiso ACL propio**, no por nombre de rol | D-06 |
| RN-12 | Toda cantidad de prendas se calcula con `parent_id IS NULL` o desde `shipment_items` | [A-§16] |
| RN-13 | `product_id` nunca es la identidad histórica de una prenda; se congelan snapshots | [A-§16.3, A-§16.6] |
| RN-14 | Las evidencias son privadas; toda descarga se autoriza en el servidor | Datos personales |
| RN-15 | Toda operación sensible se autoriza en el servidor, no ocultando UI | §9 |
| RN-16 | El módulo no introduce estados nuevos en tablas del core | [A-§4.4] |
| RN-17 | Retorno de transportadora, devolución del cliente, RMA y reembolso son conceptos **distintos** y no se suman entre sí | §7 |
| RN-18 | Todas las métricas monetarias usan **moneda base** (`base_*`) | §8 |
| RN-19 | El valor de transporte no es base de comisión salvo decisión expresa | D-21 |

---

## 5. Atribución comercial

### 5.1 Datos propuestos (tabla propia, jamás columnas en `orders`)

> **Implementado en la Fase 02A** como `bylopez_va_order_attributions`, con
> `UNIQUE(order_id)` y sin tocar `orders`. Se añadieron dos campos de snapshot no
> previstos aquí: `created_by_name_snapshot` (simétrico a `advisor_name_snapshot`,
> exigido por §11.2) y `commercial_owner_source`. Detalle en
> `07_FASE_02A_MODELO_Y_MOTOR_ATRIBUCION.md` (`[C-§n]`).

> **Precisión (Fase 01).** La relación pedido–asesor es 1:1 **conceptual**: se
> materializa con `UNIQUE order_id` en esta tabla propia, nunca con una columna
> `advisor_id` en `orders`.

| Campo | Tipo | Notas |
|---|---|---|
| `order_id` | FK → `orders.id` | **UNIQUE** (1:1 con el pedido; base de la idempotencia) |
| `created_by_admin_id` | FK → `admins.id`, nullable | Quien ejecutó la creación; `null` si storefront o automatización |
| `advisor_id` | FK → `admins.id`, nullable | Dueño comercial; único (D-05) |
| `commercial_owner_type` | string | `advisor` \| `company` \| `unassigned` (D-27, Fase 01B). `company` = venta directa de ByLopez, sin asesor ni comisión |
| `commercial_owner_source` | string, nullable | Cómo se decidió: `auto_asesor`, `seleccion_explicita`, `preferencia_cliente`, `reasignacion` (propuesta 01B) |
| `advisor_name_snapshot` | string, nullable | Nombre del asesor al asignar (D-06) |
| `sales_channel` | string | Por dónde llegó el cliente (§6) |
| `creation_source` | string | Con qué mecanismo se registró (§6) |
| `external_reference` | string, nullable, indexado | Id de conversación, ejecución n8n, fila de importación o clave de idempotencia |
| `cart_id` | int, nullable | Correlación con el carrito de origen (§10) |
| `assigned_at` | timestamp, nullable | Cuándo quedó asignado el asesor actual |
| `assigned_by_admin_id` | FK → `admins.id`, nullable | Quién hizo la última asignación |
| `attribution_status` | string | `completa` \| `sin_asesor` \| `fallo_tecnico` (§10) |
| ~~`is_excluded` / `exclusion_reason`~~ | bool / string | Pedido de prueba o duplicado. **Retirados en la Fase 02A1**: decidir elegibilidad de comisión no corresponde a la atribución. Volverán, si el negocio los necesita, en la fase de comisiones (D-03, D-31, D-32) |
| `notes` | text, nullable | — |

`advisor_name_snapshot` replica un patrón ya probado en el proyecto
(`bylopez_cc_ordenes_corte.cortador_nombre_snapshot`) y protege el histórico si
el administrador cambia de nombre o queda inactivo.

### 5.2 Historial de reasignación (tabla propia)

`order_id`, `advisor_id_anterior`, `advisor_id_nuevo`, `changed_by_admin_id`,
`reason`, `created_at`. Nunca se borra.

> **Implementado en la Fase 02A** como `bylopez_va_advisor_assignment_logs`. Añade el
> tipo de propiedad anterior y nuevo (un cambio puede ser asesor ↔ empresa, no solo de
> asesor a asesor) y snapshots de nombre, y guarda los identificadores de administrador
> **sin** clave foránea: si alguien se elimina, el historial debe seguir diciendo quién
> era en lugar de quedar en nulo.

### 5.3 Protección

- Solo roles con `ventas_asesor.pedidos.reassign` cambian `advisor_id`.
- El cambio exige motivo.
- La reasignación se **bloquea** cuando la venta ya entró en una liquidación
  pagada (recomendación de D-04, todavía pendiente).

### 5.4 Propiedad comercial y cliente de WhatsApp (Fase 01B)

**Propiedad comercial (D-27, decidida).**

| Caso | Creador | `commercial_owner_type` | `advisor_id` | Comisión |
|---|---|---|---|---|
| Asesor vende por WhatsApp | asesor | `advisor` | el mismo asesor (automático) | Sí, según regla |
| Administrador `all` vende y se elige a sí mismo | administrador | `advisor` | su mismo `admins.id` (explícito) | Sí, si se eligió expresamente |
| Administrador registra la venta de un cliente fijo de ByLopez | administrador | `company` | `null` | **No** |
| Administrador asigna a otro asesor | administrador | `advisor` | el asesor elegido | Sí, según regla |
| Pedido sin información suficiente | usuario o integración | `unassigned` | `null` | No hasta resolver |

- Un administrador `all` **nunca** recibe la venta de forma automática.
- La **preferencia comercial del cliente** (p. ej. `company` para Rosario) solo propone
  un valor visible; el pedido guarda su propia decisión y un cambio posterior de la
  preferencia **no** reescribe pedidos anteriores.

**Cliente de WhatsApp (regla funcional; estrategia técnica en D-28).** Nombre y teléfono
obligatorios; correo opcional y validado solo si existe; documento opcional; dirección
obligatoria con despacho. El teléfono se guarda original y normalizado (`+57…`). Antes
de crear se buscan coincidencias y el asesor decide usar, corregir o crear con motivo
[B-§3.4]. Nunca se inventan correos.

> **Actualización 2026-09-25:** la estrategia técnica quedó decidida por D-RT04-01…10 (Doc. 12 §14)
> y diseñada en el Doc. 13 (RT-04B0): cliente Bagisto con correo `NULL`, grupo obligatorio y
> teléfono normalizado E.164 en un índice propio no único.
> *(2026-09-26: el «no único» fue SUPERADO por D-RT04-PHONE-01: un número normalizado pertenece a
> un solo Customer; principal en `customers.phone`. Doc. 13 §36.)*

---

## 6. Canal comercial y mecanismo de creación (corregido en Fase 00B)

Un solo campo `origin_channel` mezclaba dos preguntas distintas. Se separan:

### 6.1 `sales_channel` — por dónde llegó el cliente

`whatsapp` · `ecommerce` · `telefono` · `tienda_fisica` · `otro`

> **Fase 01B.** `tienda_fisica` sustituye al valor conceptual `presencial` para no tener
> dos nombres del mismo canal; es el que usará el POS futuro.

### 6.2 `creation_source` — con qué mecanismo se registró el pedido

`storefront` · `admin_bagisto` · `ventas_asesor` · `pos` · `api` · `n8n` ·
`importacion_excel`

### 6.3 `external_reference`

> **Precisión (Fase 01), sin cambiar decisiones.** `external_reference` **no** debe
> considerarse globalmente única por sí sola: un mismo identificador puede venir de
> n8n, de una importación o de otra integración. La Fase 12 debe evaluar la
> idempotencia sobre `creation_source + external_reference` o
> `integration_id + external_reference`.

Id de conversación de WhatsApp, id de ejecución de n8n, id externo, id de fila
de importación o clave de idempotencia. Indexado, para detectar reintentos.

### 6.4 Casos obligatorios

| Caso | `sales_channel` | `creation_source` |
|---|---|---|
| Cliente compra directamente en la tienda | `ecommerce` | `storefront` |
| Asesor recibe WhatsApp y registra manualmente | `whatsapp` | `ventas_asesor` |
| n8n recibe WhatsApp y crea el pedido | `whatsapp` | `n8n` |
| Administrador registra venta presencial | `tienda_fisica` | `admin_bagisto` o `ventas_asesor` |
| Venta en el local físico desde el POS futuro | `tienda_fisica` | `pos` |
| Pedido importado desde Excel | según dato disponible | `importacion_excel` |

**No reutilizar `orders.channel_id`**: es el canal/tienda de Bagisto, un
concepto distinto, y hoy tiene un único valor [A-§10].

---

## 7. Logística, retornos, devoluciones y reembolsos

### 7.1 Cuatro conceptos que no deben sumarse (corregido en Fase 00B)

| Concepto | Significado | ¿La prenda llegó al cliente? | Fuente canónica | Efecto en comisión |
|---|---|---|---|---|
| **Retorno de transportadora** | El paquete no se entregó y regresó a ByLopez | **No** | **Módulo** (estado logístico del envío) | **Nunca la causó**: no se descuenta, simplemente no se genera |
| **Devolución del cliente** | El cliente recibió y luego devolvió | **Sí** | **RMA** (`rma_items`) | **Ajuste negativo** si ya se comisionó |
| **RMA** | Proceso operativo de autorización de la devolución | Sí | `rma` / `rma_items` [A-§17] | Es el disparador, no la cifra |
| **Reembolso** | Movimiento financiero en Bagisto | Indiferente | `refunds` / `refund_items`, `qty_refunded` | Efecto contable, **no** duplica el ajuste |

**Error que se corrige:** la fórmula anterior sumaba
`qty_refunded + unidades de envíos devueltos`. Eso cuenta dos veces la misma
prenda, porque un RMA resuelto como `Refunded` **genera** el reembolso
[A-§17.4]: son el mismo hecho visto dos veces.

### 7.2 Fuente canónica y conciliación

| Pregunta | Fuente canónica |
|---|---|
| ¿Cuántas prendas se entregaron? | Estado logístico del módulo, sobre `shipment_items` |
| ¿Cuántas prendas volvieron sin entregarse? | Estado logístico `retornado_transportadora` |
| ¿Cuántas prendas devolvió el cliente? | `rma_items.quantity` de RMA en estado resolutivo |
| ¿Cuánto dinero se devolvió? | `refunds` / `order_items.qty_refunded` |

**Regla de conciliación:** una unidad puede aparecer en RMA **y** en reembolso.
El ajuste de comisión se genera **una sola vez**, anclado al evento de entrega
(`shipment_item` + cantidad), no a la suma de tablas. La tabla de ajustes lleva
una restricción de unicidad por `(evento_entrega, motivo, cantidad)` para
impedir el doble descuento.

**Vacío conocido:** RMA **no dispara ningún evento** [A-§17.5]. La conciliación
no puede ser reactiva; debe ser un proceso de reconciliación periódico o un
observador de modelo sobre el proxy de `RMA`. Esto es una limitación del core,
no una decisión de diseño.

### 7.3 Estados logísticos propuestos (por envío)

```
preparando -> despachado -> en_transito -> entregado
                                |
                                +-> entrega_fallida -> (reintento) en_transito
                                                     -> retornado_transportadora
```

- `entregado` y `retornado_transportadora` son terminales salvo corrección
  explícita auditada.
- Un pedido con varios envíos puede tener envíos en estados distintos.
- La existencia de `preparando` depende de D-07.
- Quién puede cambiar estos estados depende de D-14.

### 7.4 Estado visible derivado (nunca persistido en `orders`)

| Situación de los envíos | Etiqueta mostrada |
|---|---|
| Sin envíos | Pendiente de despacho |
| Todos `entregado` | Entregado |
| Todos `retornado_transportadora` | Retornado |
| Alguno `en_transito` o `despachado` | En tránsito |
| Mezcla entregado + pendiente | Entrega parcial |
| Pedido `canceled` en Bagisto | Cancelado |

### 7.5 Qué aporta el core y qué falta

| Dato | Core | Módulo |
|---|---|---|
| Envío (uno o varios por pedido) | `shipments` | — |
| Líneas enviadas, sin duplicar | `shipment_items` [A-§16.3] | — |
| Transportadora (texto libre) | `shipments.carrier_title` | Catálogo normalizado |
| Guía (texto libre) | `shipments.track_number` | Validación e historial |
| **Estado logístico** | **no existe** | **tabla propia** |
| Fecha de entrega confirmada | no existe | tabla propia |
| Intentos fallidos | no existe | tabla propia |
| Retorno de transportadora | no existe | tabla propia |
| Historial de cambios | no existe | tabla propia |
| Devolución del cliente | **RMA** | solo conciliación |
| Reembolso | `refunds` | solo lectura |

---

## 8. Comisiones (corregido en Fase 00B)

### 8.1 Hecho generador

**Decidido (D-01):** la comisión se causa por **prendas efectivamente entregadas
al cliente y no devueltas**.

No generan comisión: pedido creado, facturado, despachado, envío en tránsito,
entrega fallida, retorno de transportadora, unidad cancelada, unidad devuelta,
reposición gratuita, garantía, muestra ni producto marcado como no comisionable.

La **fecha causante** es la fecha confirmada de entrega de cada envío o unidad
entregada, **no** la fecha de creación del pedido.

### 8.2 Por qué la unidad técnica es `shipment_item_id`, no `order_item_id`

Un pedido puede repartirse en varios envíos con fechas de entrega distintas.
Si la comisión se anclara a `order_item_id`, sería imposible saber qué parte de
esa línea se entregó, cuándo, y cuál queda pendiente.

`shipment_items` es la fuente correcta, verificada con datos reales [A-§16.3]:

- 144 filas, `SUM(qty) = 591`, exactamente igual a
  `SUM(qty_shipped) WHERE parent_id IS NULL`;
- **no tiene `parent_id`**: una sola fila por prenda enviada, sin duplicación de
  configurables;
- lleva el SKU y los atributos reales de la variante;
- su `order_item_id` apunta siempre a la fila padre, lo que permite subir hasta
  el pedido sin ambigüedad.

**Advertencia obligatoria:** `shipment_items.product_id` es el producto
**configurable**, no la variante. La identidad de la variante está en
`additional.selected_configurable_option` y en `sku`.

### 8.3 Evento de entrega (tabla propia)

La comisión no se calcula sobre el envío completo sino sobre el **evento de
entrega de una cantidad concreta de un `shipment_item`**:

| Campo | Notas |
|---|---|
| `shipment_id`, `shipment_item_id` | Anclaje canónico |
| `order_id`, `order_item_id` | Trazabilidad ascendente |
| `qty_entregada` | Puede ser parcial respecto de `shipment_items.qty` |
| `fecha_entrega_confirmada` | **Fecha causante** |
| `registrado_por_admin_id`, `source` (`manual`/`api`) | Auditoría |
| `estado` | `confirmada` \| `revertida` |

Una entrega revertida (corrección) no se borra: se marca y genera su ajuste.

### 8.4 Reglas de comisión

| Campo | Notas |
|---|---|
| `scope` | global · por asesor · por producto · por categoría (D-03) |
| `tipo` | valor fijo por prenda · porcentaje sobre venta · mixto |
| `valor` | monto o porcentaje |
| `base_calculo` | qué monto se toma como base (D-21 decide si incluye transporte) |
| `vigencia_desde` / `vigencia_hasta` | Permite cambiar tarifa sin reescribir historia |
| `prioridad` | Resolución cuando varias reglas aplican |
| `activa` | — |

### 8.5 Liquidación y sus líneas

**Cabecera:** `advisor_id`, `periodo_desde`, `periodo_hasta`, `estado`
(`provisional` → `aprobada` → `pagada`; además `anulada`), totales,
`aprobada_por_admin_id` / `aprobada_en`, `pagada_por_admin_id` / `pagada_en`,
historial de cambios de estado.

**El snapshot va por línea, no en la cabecera.** Cada línea congela como mínimo:

| Campo congelado | Motivo |
|---|---|
| `order_id` | Trazabilidad |
| `order_item_id` | Trazabilidad |
| `shipment_id` | Trazabilidad |
| `shipment_item_id` | **Anclaje canónico** |
| `delivery_event_id` | Referencia del evento de entrega |
| `producto_sku_snapshot`, `producto_nombre_snapshot`, `variante_snapshot` | El producto puede cambiar o borrarse [A-§16.6] |
| `qty_entregada_elegible` | Cantidad efectivamente comisionada |
| `fecha_causante` | Fecha confirmada de entrega |
| `regla_id` + `regla_snapshot` (JSON) | La tarifa puede cambiar retroactivamente |
| `valor_unitario` o `porcentaje` | Parámetro aplicado |
| `base_calculo` (valor monetario base) | Reproducibilidad |
| `comision_resultante` | Resultado |
| `sales_channel` | KPI por canal |
| `advisor_id` | Redundante a propósito, para auditoría |

### 8.6 Idempotencia: prohibición de doble comisión

> **Precisión (Fase 01), sin cambiar decisiones.** La restricción
> `UNIQUE(delivery_event_id)` descrita abajo puede ser **demasiado amplia**: si un
> evento de entrega agrupa varios `shipment_items`, impediría registrar sus varias
> líneas. La Fase 09 debe definir una identidad que admita varias líneas por
> evento —candidata `delivery_event_id + shipment_item_id`— e incluir una porción o
> secuencia inequívoca si existen entregas parciales del mismo ítem. **El índice
> final no está aprobado.**

Restricción **UNIQUE** sobre
`(delivery_event_id)` en las líneas de liquidación, más una comprobación de que

```
SUM(qty_entregada_elegible ya liquidada para ese shipment_item)
  + qty_a_liquidar  <=  qty entregada confirmada
```

Ninguna cantidad entregada puede liquidarse dos veces, ni siquiera si el proceso
de cálculo se ejecuta varias veces sobre el mismo periodo.

### 8.7 Ajustes negativos (D-02, decidido)

Una liquidación pagada **nunca** se recalcula ni se reescribe. Si una prenda se
devuelve después de haberse pagado su comisión, se crea un **ajuste negativo**
en la siguiente liquidación del asesor, que referencia:

pedido · ítem original (`order_item_id`) · envío o evento de entrega
(`shipment_item_id` / `delivery_event_id`) · liquidación original · cantidad ·
valor descontado · motivo · usuario o integración que registró la devolución ·
fecha.

Restricción de unicidad por `(delivery_event_id, motivo, liquidacion_origen_id)`
para impedir el doble descuento (§7.2).

### 8.8 Por qué el snapshot es obligatorio

`OrderItemRepository::collectTotals()` **recalcula cantidades después de la
venta** [A-§6], y las tarifas pueden cambiar. Sin congelar regla, cantidades y
descripción del producto en cada línea, una devolución posterior o un cambio de
tarifa alterarían liquidaciones ya pagadas.

---

## 9. Evidencias

| Campo | Notas |
|---|---|
| `order_id` | FK |
| `shipment_id` | nullable — evidencia asociada a un envío concreto |
| `category` | `comprobante_pago`, `conversacion`, `direccion`, `despacho`, `entrega`, `novedad`, `otro` |
| `disk` / `path` | Almacenamiento **privado**, nunca `public` |
| `original_name`, `mime_type`, `size_bytes`, `hash` | Integridad y control |
| `uploaded_by_admin_id` | Autor |
| `note` | Texto libre |
| `created_at` / `deleted_at` | Borrado lógico para conservar trazabilidad |

Restricciones propuestas: formatos `jpg/jpeg/png/webp/pdf`; tamaño máximo por
definir (D-09); descarga siempre por ruta autorizada del módulo, nunca URL
pública directa.

**Riesgo explícito:** las conversaciones de WhatsApp contienen datos personales
de clientes; el almacenamiento público sería una fuga (RN-14).

---

## 10. Estrategia de atribución (corregida en Fase 00B)

La versión anterior era contradictoria: prometía "100 % de pedidos atribuidos" y
a la vez "ignorar cualquier fallo del listener". Ambas cosas no pueden ser
ciertas.

### 10.1 Principio

> Un error del módulo **nunca** debe hacer perder una venta real. Un error del
> módulo **tampoco** debe silenciarse ni presentarse como atribución correcta.

### 10.2 Mecanismos

| # | Mecanismo | Detalle |
|---|---|---|
| M-1 | **Restricción única** por `order_id` | Impide atribuciones duplicadas por reintento |
| M-2 | **Listener idempotente** | El motor oficial es `RegistrarAtribucionService`: contexto equivalente devuelve la fila y contexto distinto lanza conflicto. **No se usa `updateOrCreate`**, que sobrescribiría una atribución ya decidida [C1-§4] |
| M-3 | **Captura de contexto antes de crear** | El servicio propio conoce asesor, canal y mecanismo **antes** de llamar a `OrderRepository::create()`; no depende de adivinarlos después |
| M-4 | **Correlación por `cart_id`** | El pedido conserva `cart_id`; permite reconstruir el contexto si el listener falla |
| M-5 | **Registro crítico de fallos** | Un fallo escribe en log de nivel `error` y marca `attribution_status = fallo_tecnico` |
| M-6 | **Detección de pedidos sin atribución** | Consulta de pedidos sin fila de atribución, expuesta en la bandeja |
| M-7 | **Proceso de reconciliación** | Comando idempotente que recorre pedidos huérfanos y reintenta la atribución desde `cart_id` |
| M-8 | **Bandeja "Ventas sin asignar"** | Operativa diaria |

### 10.3 Distinción obligatoria

| `attribution_status` | Significado | Acción |
|---|---|---|
| `completa` | Creador y asesor conocidos | Ninguna |
| `sin_asesor` | Estado **legítimo**: venta de tienda virtual o pendiente de asignar | Aparece en la bandeja para asignación comercial |
| `fallo_tecnico` | El módulo no pudo determinar el contexto | Aparece en la bandeja **marcado como incidencia** y se investiga |

> **Aclarado 2026-09-25 (cierre 02B1B):** la venta de tienda virtual **no** es `sin_asesor`: es `company` + `completa` (CA-02, código `EstadoAtribucion::desdePropiedad()`). `sin_asesor` significa venta no atribuida/no resuelta (`unassigned`), incluido el histórico sin evidencia. `fallo_tecnico` es solo para errores del listener, no para falta de evidencia histórica. Ver Doc. 10 §9.

Confundir `sin_asesor` con `fallo_tecnico` es exactamente lo que produciría la
falsa afirmación de "100 % atribuido".

### 10.4 Comportamiento por origen

| Origen | Creador | Asesor inicial | `creation_source` | Cómo se captura |
|---|---|---|---|---|
| Storefront | `null` | `null`, con propiedad **`company`** y estado **`completa`** (regla A, D-27) | `storefront` | Listener. **El guard `admin` no sirve para distinguir tienda de panel**: comparten sesión [B-§4.2]; el discriminador es el nombre de la ruta [D-§5.2] |
| Panel oficial de Bagisto | `auth('admin')->id()` | el creador si es **asesor normal** (`custom` con el permiso marcador); **nunca** automático para `all` (D-27): queda `unassigned` hasta elegir | `admin_bagisto` | Listener |
| **Servicio propio** | del contexto explícito | **del contexto explícito** | `ventas_asesor` | **Antes** de crear (M-3) |
| n8n / API (futuro) | `null` | del payload o `null` | `n8n` / `api` | Contexto explícito + `external_reference` |

> **Implementado en la Fase 02B1A (2026-09-20) [E-§3].** El listener existe y clasifica el
> origen con la **lista exacta** de 9 rutas de `RutasDeCreacionDePedido`. Una ruta desconocida o
> ausente **no** es tienda: produce `fallo_tecnico` con código `listener.origen_no_determinado`.
> La ruta del panel **sin** administrador autenticado produce `listener.admin_sin_autenticar`, y
> tampoco degrada a tienda. El canal de un pedido administrativo queda `desconocido`: afirmar
> WhatsApp sin evidencia sería inventar el dato.

### 10.5 ¿Hace falta una tabla de contexto durable?

**Todavía no está justificada.** El servicio propio conoce el contexto en
memoria durante la misma petición (M-3) y `orders.cart_id` permite la
correlación posterior (M-4). Una tabla `bylopez_va_creation_contexts` ligada a
`cart_id` solo se justificaría si el negocio adopta carritos-borrador de larga
duración durante la negociación por WhatsApp (D-16). **Se decide en la Fase 02,
con la decisión D-16 resuelta, no antes.**

> **Fase 01B.** D-16 está decidida: durante la conversación existe un carrito oficial o
> borrador y el pedido nace solo al confirmar. La Fase 02 debe evaluar si el contexto
> (asesor, propiedad comercial, canal) necesita persistir ligado a `cart_id` mientras
> dura la negociación.

> **Resuelto en la Fase 02B1A (2026-09-20) [E-§5].** Se implementó el contexto **en memoria de
> la petición** (`ContextoComercialDePeticion`, registrado `scoped`, indexado por `cart_id`),
> **no** una tabla durable. La clave es el `cart_id` y no el `order_id` porque cada reintento de
> `OrderRepository` crea un pedido con id distinto. El listener **consulta sin consumir**; quien
> registró el contexto lo retira en su `finally` mediante `durante()`.
>
> Lo que **sí** se creó durable es otra cosa: `bylopez_va_attribution_incidents`, una cola
> **operativa** de revisión (Fase 02B1A [E-§6]), que no transporta contexto ni sustituye al
> historial inmutable de propiedad comercial.

### 10.6 Comportamiento ante fallo

El listener se envuelve en `try/catch` de **`Throwable`**, no solo de `Exception`, y
**nunca relanza**.

> **Medido en la Fase 02B0 [D-§3].** Con un listener que lanza siempre, el evento se
> disparó **3 veces**, se crearon y revirtieron **tres pedidos distintos** y **no
> sobrevivió ninguno**: la excepción llega al comprador y **la venta se pierde**. Con un
> listener que falla una sola vez, el evento se disparó **dos veces con pedidos
> distintos**. Por eso la regla no admite excepciones.

Ante fallo: no se propaga, se registra en log técnico seguro y se persiste, **si la base
lo permite**, una fila con `attribution_status = fallo_tecnico`. **No puede suponerse que
siempre sea posible**: si el problema es precisamente de base de datos, esa escritura
también fallará y el pedido quedará como huérfano detectable por M-6.

Un **conflicto** (ya existe una atribución incompatible) **nunca** se convierte en
`fallo_tecnico` ni se sobrescribe: la atribución se conserva intacta y la incidencia va a
reconciliación [D-§8].

> **Implementado en la Fase 02B1A [E-§8].** La taxonomía quedó cerrada, de lo específico a lo
> general: conflicto → incidencia `atribucion_conflicto` con **solo nombres de campo**; historial
> incoherente → `historial_incoherente`; dominio o contexto inválido → `contexto_invalido` más
> intento protegido de `fallo_tecnico`; **error de base o conexión → solo log seguro**, sin
> intentar ninguna otra escritura por la misma conexión; error inesperado → `listener_error` por
> operación protegida.
>
> Y el límite honesto que ya anticipaba esta sección: `catch (Throwable)` evita que el listener
> tumbe la venta, pero **no resucita una transacción que la base ya invalidó**.

---

## 11. Modelos, proxies e integridad referencial (nuevo en Fase 00B)

### 11.1 No sustituir modelos del core sin necesidad

La auditoría menciona los proxies de Concord como punto de extensión [A-§11.1].
**Eso no autoriza a sustituir `Order`, `OrderItem` o `Shipment`** solo para
añadir relaciones cómodas: la sustitución afecta a todo el core y complica cada
merge con `upstream/2.4`.

**Preferir, en este orden:**

1. Modelos propios con `belongsTo` hacia los **proxies** del core
   (`OrderProxy::modelClass()`), que es la forma correcta de referenciarlos.
2. Repositorios y servicios propios que encapsulen las consultas.
3. Eventos existentes.
4. DataGrids propios.

La sustitución de un modelo del core solo se considerará si una fase futura
demuestra que es indispensable, y tras evaluar su impacto en actualizaciones.

### 11.2 Estrategia de claves foráneas

| FK | Regla | Motivo |
|---|---|---|
| `order_id` | `restrict` sobre borrado, o conservación de snapshots suficientes | Un pedido comisionado no debe desaparecer. **Atención:** `rma.order_id` usa `CASCADE` [A-§17.1]; el módulo no debe imitarlo |
| `order_item_id` | `restrict` + snapshot de SKU y nombre | Trazabilidad de la prenda |
| `shipment_id`, `shipment_item_id` | `restrict` + snapshot | Anclaje de la comisión |
| `advisor_id` | `nullOnDelete` + `advisor_name_snapshot` | Un asesor puede darse de baja sin destruir el histórico |
| `created_by_admin_id` | `nullOnDelete` + snapshot de nombre | Igual |
| `assigned_by_admin_id`, `aprobada_por_admin_id`, `pagada_por_admin_id` | `nullOnDelete` + snapshot | Auditoría financiera |
| `commission_rule_id` | `restrict` + `regla_snapshot` JSON | La regla nunca se borra si fue aplicada |

**Ninguna FK del módulo usa `cascade` cuando pueda destruir historial financiero
o de auditoría.**

### 11.3 Identidad histórica de una prenda

`product_id` **no** es identidad histórica: los productos cambian de SKU, se
eliminan, y ya existe al menos un pedido con `sku = temporary-sku-29ae88`
[A-§16.6]. Cada línea de comisión congela SKU, nombre y descripción de variante.

---

## 12. ACL propia y aislamiento (corregido en Fase 00B)

### 12.1 Riesgo actual

El rol `ASESOR VENTAS` tiene hoy 18 permisos del core, incluidos
`sales.orders.view` (todos los pedidos), `sales.orders.cancel`,
`sales.refunds.create`, `sales.invoices.create` y `sales.shipments.create`
[A-§18]. Es un **riesgo crítico de autorización horizontal**: ninguna ruta del
core filtra por propietario, porque el concepto no existe.

### 12.2 Claves ACL propuestas

```
ventas_asesor
ventas_asesor.operar_como_asesor        <- identifica al asesor (D-06)
ventas_asesor.pedidos
ventas_asesor.pedidos.create
ventas_asesor.pedidos.view_own
ventas_asesor.pedidos.view_all
ventas_asesor.pedidos.assign            <- asignar pedido sin asesor
ventas_asesor.pedidos.reassign          <- cambiar asesor ya asignado
ventas_asesor.logistica.view
ventas_asesor.logistica.manage          <- guía, transportadora y estado
ventas_asesor.evidencias.create
ventas_asesor.evidencias.delete
ventas_asesor.comisiones.view_own
ventas_asesor.comisiones.view_all
ventas_asesor.comisiones.manage         <- reglas y cálculo
ventas_asesor.comisiones.approve
ventas_asesor.comisiones.pay
ventas_asesor.kpis.view_own
ventas_asesor.kpis.view_all
```

**Depuración aplicada respecto de la propuesta inicial:**
- `ventas_asesor.evidencias.view` se elimina: la visibilidad de la evidencia
  sigue a la del pedido (`view_own` / `view_all`).
- `ventas_asesor.logistica.manage` absorbe el registro de guía y el cambio de
  estado: separarlos no aporta ningún caso real.
- `ventas_asesor.comisiones.manage` cubre reglas y cálculo provisional;
  `approve` y `pay` quedan aparte porque son las que mueven dinero.

### 12.3 El asesor no necesita permisos amplios del core

Para operar la interfaz propia, un asesor **no** debe tener
`sales.orders.view`, `sales.orders.cancel`, `sales.invoices.*`,
`sales.shipments.*`, `sales.refunds.*` ni `sales.transactions.*`.

**Salvedad conocida:** la creación de pedidos por el flujo oficial del core
exige `sales.orders.create` [A-§9]. Si el asesor debe conservar el flujo oficial
como alternativa, ese permiso es inevitable; si opera solo por la interfaz
propia, no lo necesita. Es una decisión de despliegue, no de diseño.

### 12.4 Aislamiento horizontal

Toda consulta del módulo aplica el filtro por asesor **en el servidor**. Conocer
un `order_id` o manipular la URL nunca debe permitir consultar ventas ajenas.
Esto se verifica con pruebas HTTP reales, no revisando la interfaz.

---

## 13. Matriz de permisos propuesta

`A` = Asesor · `S` = Supervisor · `Ad` = Administrador · `B` = Bodega ·
`C` = Contabilidad

| Operación | A | S | Ad | B | C |
|---|---|---|---|---|---|
| Crear pedido desde la interfaz propia | Sí | Sí | Sí | No | No |
| Ver **sus** pedidos | Sí | Sí | Sí | — | — |
| Ver pedidos de **todos** los asesores | No | Sí | Sí | Sí (operativo) | Sí (valores) |
| Asignar asesor a un pedido sin asesor | *pendiente* | Sí | Sí | No | No |
| **Reasignar** asesor ya asignado | No | Sí | Sí | No | No |
| Registrar/editar guía y transportadora | No | *pendiente* | Sí | Sí | No |
| Cambiar estado logístico | No | *pendiente* | Sí | Sí | No |
| Confirmar entrega | *pendiente* (D-14) | Sí | Sí | Sí | No |
| Registrar retorno de transportadora | No | Sí | Sí | Sí | No |
| Adjuntar evidencias | Sí | Sí | Sí | Sí | No |
| Eliminar evidencias | No | Sí | Sí | No | No |
| Definir reglas de comisión | No | No | Sí | No | No |
| Calcular liquidación provisional | No | Sí | Sí | No | Sí |
| Aprobar comisión | No | Sí | Sí | No | *pendiente* |
| Marcar liquidación como pagada | No | No | Sí | No | Sí |
| Ver **su** comisión | Sí | Sí | Sí | No | Sí |
| Ver comisión de **otros** | No | Sí | Sí | No | Sí |
| Ver costos y márgenes | *pendiente* (D-10) | *pendiente* | Sí | No | Sí |
| Definir metas | No | Sí | Sí | No | No |

Cada "Sí" implica una comprobación de autorización **en el servidor** (RN-15).

---

## 14. KPIs (corregido en Fase 00B)

### 14.1 Reglas transversales

- Todas las métricas monetarias usan **moneda base** (`base_grand_total`,
  `base_sub_total`, `base_shipping_amount`…), nunca la moneda de presentación.
- Todas las métricas de prendas aplican `parent_id IS NULL` o parten de
  `shipment_items` (RN-12, [A-§16.5]).
- El **valor de transporte** se reporta aparte y no se cuenta como prenda
  vendida ni como base de comisión (RN-19, D-21).
- Cada KPI declara su unidad, su fecha y sus estados incluidos y excluidos.

### 14.2 Métricas de prendas

| KPI | Unidad | Fórmula | Fecha | Excluye |
|---|---|---|---|---|
| Prendas vendidas | prendas | `SUM(order_items.qty_ordered) WHERE parent_id IS NULL` | `orders.created_at` | pedidos excluidos (prueba/duplicado) |
| Prendas facturadas | prendas | `SUM(order_items.qty_invoiced) WHERE parent_id IS NULL` | `invoices.created_at` | — |
| Prendas despachadas | prendas | `SUM(shipment_items.qty)` | `shipments.created_at` | — |
| Prendas entregadas | prendas | `SUM(qty_entregada)` de eventos de entrega `confirmada` | `fecha_entrega_confirmada` | retornos |
| Prendas en tránsito | prendas | `SUM(shipment_items.qty)` de envíos `despachado`/`en_transito` | — | — |
| Prendas retornadas por transportadora | prendas | eventos de retorno del módulo | fecha de retorno | **no se suma a devoluciones** |
| Prendas devueltas por el cliente | prendas | `SUM(rma_items.quantity)` de RMA resolutivos | fecha de resolución RMA | **no se suma a retornos** |
| Prendas canceladas | prendas | `SUM(qty_canceled) WHERE parent_id IS NULL` | fecha de cancelación | — |

### 14.3 Métricas monetarias separadas

No existe un KPI llamado "ventas netas". Se reportan magnitudes distintas:

| KPI | Unidad | Definición | Fuente |
|---|---|---|---|
| **Valor pedido** | COP | `SUM(orders.base_grand_total)` | `orders` |
| **Valor de mercancía** | COP | `SUM(orders.base_sub_total)` (sin transporte ni impuestos) | `orders` |
| **Ingreso por transporte** | COP | `SUM(orders.base_shipping_amount)` | `orders` — se reporta aparte |
| **Valor facturado** | COP | `SUM(invoices.base_grand_total)` | `invoices` |
| **Valor despachado** | COP | `SUM(shipment_items.base_total)` | `shipment_items` |
| **Valor entregado** | COP | valor de las prendas con evento de entrega confirmada | módulo + `shipment_items` |
| **Valor cancelado** | COP | proporción cancelada de las líneas | `order_items` |
| **Valor devuelto** | COP | `SUM(orders.base_grand_total_refunded)` | `orders` / `refunds` |
| **Valor neto comisionable** | COP | base de cálculo de las líneas de comisión elegibles | módulo |

**Advertencias que el dashboard debe hacer explícitas:**

- En contraentrega, "valor pedido" **no** es dinero cobrado. Un pedido
  despachado y retornado tiene valor pedido pero ingreso cero.
- La cancelación parcial reduce prendas y valor, pero `base_grand_total` del
  pedido **no** se reescribe: hay que calcular sobre líneas, no sobre cabecera.
- Un reembolso parcial no anula el pedido.
- Los productos gratuitos aportan prendas pero no valor.
- Qué se muestra como cifra principal es la decisión **D-22**, todavía abierta.

### 14.5 Métricas por propiedad comercial (Fase 01B)

Toda métrica de ventas debe poder separarse por estos ejes, que **no** son
intercambiables:

| Métrica | Criterio |
|---|---|
| Pedidos creados por usuario | `created_by_admin_id` (operativo; no implica propiedad) |
| Ventas de asesor | `commercial_owner_type = advisor`, agrupado por `advisor_id` |
| Ventas directas de empresa | `commercial_owner_type = company` |
| Ventas sin asignar | `commercial_owner_type = unassigned` |
| Ventas comisionables | `advisor` y líneas elegibles (D-01, D-03) |
| Ventas no comisionables | `company`, `unassigned` y líneas excluidas |

Un pedido creado por un administrador cuenta en «pedidos creados» de ese administrador,
pero solo cuenta en «ventas de asesor» si se le eligió expresamente como asesor.

### 14.4 Métricas derivadas

| KPI | Fórmula |
|---|---|
| Ticket promedio | Valor entregado ÷ pedidos con al menos una entrega |
| Tasa de entrega | Prendas entregadas ÷ prendas despachadas |
| Tasa de retorno logístico | Prendas retornadas ÷ prendas despachadas |
| Tasa de devolución | Prendas devueltas ÷ prendas entregadas |
| Comisión provisional / aprobada / pagada | `SUM` de liquidaciones por estado |
| Cumplimiento de meta | Métrica del periodo ÷ meta del periodo (D-11) |

---

## 15. Casos límite contemplados

| # | Caso | Tratamiento |
|---|---|---|
| CL-01 | Administrador crea el pedido para un asesor | `created_by_admin_id` ≠ `advisor_id`; ambos se guardan (RN-10) |
| CL-02 | n8n crea el pedido y se asigna después | `creation_source = n8n`, `sales_channel = whatsapp`, `advisor_id` posterior |
| CL-03 | Cambio de asesor después de facturar | Permitido con historial; se bloquea tras liquidación pagada (D-04) |
| CL-04 | Pedido con varios asesores | **No se soporta** (D-05 decidida): un solo propietario |
| CL-05 | Pedido parcialmente cancelado | `qty_canceled` reduce prendas; el pedido sigue vivo |
| CL-06 | Pedido parcialmente enviado | Varios envíos; estado derivado "Entrega parcial" |
| CL-07 | Pedido con varias guías | Soportado nativamente (RN-05) |
| CL-08 | Un mismo `order_item` repartido en dos envíos con fechas distintas | Cada `shipment_item` genera su propio evento de entrega y su propia línea de comisión (§8.2) |
| CL-09 | Devolución después de pagar comisión | Ajuste negativo en el periodo siguiente (§8.7) |
| CL-10 | Cambio retroactivo de tarifa | El snapshot por línea protege lo liquidado (§8.5) |
| CL-11 | Producto gratuito, garantía o muestra | Marca de "no comisionable" (D-03) |
| CL-12 | Pedido duplicado | Marca de exclusión con motivo, fuera de KPIs y comisión. **Sin implementar**: la Fase 02A1 retiró `is_excluded` por prematuro |
| CL-13 | Pedido de prueba | Igual que CL-12, con motivo distinto |
| CL-14 | Pedido sin asesor | `attribution_status = sin_asesor`: estado válido (§10.3) |
| CL-15 | Pedido de tienda virtual atendido luego por un asesor | `sales_channel = ecommerce`, `creation_source = storefront`; se asigna `advisor_id` con historial |
| CL-16 | Asesor eliminado o desactivado | `nullOnDelete` + `advisor_name_snapshot` (§11.2) |
| CL-17 | Evidencia eliminada o reemplazada | Borrado lógico e historial |
| CL-18 | Guía corregida | Historial con valor anterior, nuevo, autor y motivo |
| CL-19 | Dos usuarios editando el seguimiento a la vez | Control de concurrencia por versión, rechazo del segundo cambio con aviso — patrón ya usado en Corte y Confección |
| CL-20 | Paquete despachado que nunca se entrega | `retornado_transportadora`: **nunca** causó comisión (§7.1) |
| CL-21 | Reintento de n8n con el mismo `external_reference` | Rechazado por idempotencia; no crea un segundo pedido |
| CL-22 | Producto configurable | Cantidades desde el padre o desde `shipment_items`; identidad desde `additional` (RN-12, RN-13) |
| CL-23 | Administrador `all` crea un pedido sin elegir propiedad | `unassigned`; nunca se le atribuye automáticamente (D-27) |
| CL-24 | Venta a cliente fijo de ByLopez (p. ej. Rosario) | `company`, sin asesor ni comisión; el creador sigue registrado |
| CL-25 | Se cambia la preferencia comercial del cliente | Los pedidos anteriores conservan su propiedad histórica |
| CL-26 | Cambio de cliente durante la creación del pedido | Recalcular todos los ítems con el grupo del nuevo cliente; bloquear confirmación ante inconsistencia (RT-03) |
| CL-27 | Cliente de WhatsApp sin correo | No se inventa correo; estrategia D-28 sujeta a RT-04 *(2026-09-25: D-RT04-02, correo `NULL`; Doc. 13)* |
| CL-28 | Teléfono ya registrado a otro cliente | Mostrar coincidencia; usar, corregir o crear con motivo (D-29) |

---

## 16. Criterios de aceptación iniciales

| # | Criterio |
|---|---|
| CA-01 | Todo pedido creado desde el panel o desde la interfaz propia queda con `created_by_admin_id` correcto, verificado en BD |
| CA-02 | Todo pedido del storefront queda con `created_by_admin_id = null`, `creation_source = storefront`, `commercial_owner_type = company` y `attribution_status = completa` (corregido en la Fase 02B0; antes decía `sin_asesor`) |
| CA-03 | Un asesor autenticado nunca obtiene datos de pedidos de otro, verificado por HTTP real |
| CA-04 | Toda reasignación produce exactamente una fila de historial con autor y motivo |
| CA-05 | Un pedido con dos envíos permite dos guías y estados logísticos independientes |
| CA-06 | Las prendas contadas coinciden con `parent_id IS NULL` y con `shipment_items` en pedidos con producto configurable |
| CA-07 | Una liquidación aprobada no cambia de valor al modificar la regla después |
| CA-08 | Una misma cantidad entregada no puede liquidarse dos veces (§8.6) |
| CA-09 | Un retorno de transportadora no genera comisión ni ajuste |
| CA-10 | Una devolución posterior al pago genera exactamente un ajuste negativo, no dos |
| CA-11 | Las evidencias no son accesibles por URL pública directa |
| CA-12 | Cada KPI coincide con su consulta SQL manual equivalente |
| CA-13 | Los pedidos previos al módulo siguen funcionando y visualizándose sin error |
| CA-14 | Totales, inventario y estados de Bagisto son idénticos con el módulo activo y sin él |
| CA-15 | `packages/Webkul/` permanece sin cambios |

---

## 17. Lo que este modelo NO propone

- No propone una tabla de pedidos propia ni un segundo motor de pedidos.
- No propone recalcular totales, impuestos ni inventario.
- No propone estados nuevos dentro de `orders.status`.
- No propone tablas de devoluciones propias: RMA ya resuelve ese dominio
  [A-§17.10].
- No propone sustituir modelos del core (§11.1).
- No propone (todavía) integración con transportadoras, pagos a terceros ni
  estados de producción.
