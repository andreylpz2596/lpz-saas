# 16 · Auditoría del dominio de Comisiones (COM-00)

**Fecha:** 2026-10-07 · **Tipo:** auditoría de código, datos de desarrollo (`lpz_saas`) y documentación ·
**Sin código, sin migraciones, sin cambios de datos.** · **Responsable funcional:** Camilo Gaitán

> **Actualización 2026-10-08:** COM-01, COM-02 y COM-03 implementadas en desarrollo; estado nuevo de la matriz, gaps y
> hallazgos en **§11**. El resto del documento conserva la evidencia de COM-00.
>
> Objetivo: saber **exactamente** de dónde saldría cada número de una comisión, qué falta y qué
> está decidido, antes de diseñar o implementar. El diseño está en
> `17_DISENO_FUNCIONAL_COMISIONES.md`; el roadmap en `18_ROADMAP_COMISIONES.md`; las decisiones
> abiertas en `04_DECISIONES_PENDIENTES.md` (COM-D-01 a COM-D-14).

Método: lectura de migraciones, servicios y configuración del módulo; consultas **de solo lectura**
sobre `lpz_saas` (scripts temporales fuera del repo); revisión de los Docs. 01, 02, 04, 05, 05A, 06B,
10 y `ACTUALIZACION_MODELO_COMERCIAL_POS_Y_COMISIONES.md`. No se ejecutaron pruebas.

---

## 1. Conclusión ejecutiva

1. **Ya existe una política parcialmente decidida.** D-01 (comisión solo por prenda **entregada y no
   devuelta**, fecha causante = entrega), D-02 (liquidación pagada inmutable; devoluciones como
   ajuste negativo), D-05 (un asesor por pedido) y D-27 (venta de empresa no comisiona) están
   **DECIDIDAS** desde las Fases 00B/01B. Este trabajo **no** las reabre; las usa como restricciones.
2. **La fórmula conocida** (`ACTUALIZACION…` §7) es `max(0, precio_neto_unitario − costo_unitario_congelado) × %`
   por unidad entregada. El **20 % es PROPUESTA** (D-32 pendiente), no política aprobada.
3. **SNAPSHOT DE COSTO: NO EXISTE → GAP CRÍTICO.** Ningún pedido guarda el costo con el que se vendió.
   El único costo disponible es el atributo `cost` de Bagisto, **actual y editable**, sin historial.
4. **La entrega no existe en el sistema.** Bagisto solo registra el envío (`shipments`, que descuenta
   inventario); no hay estado «entregado» ni tabla de eventos de entrega (las 23 filas de `shipments`
   tienen `status` NULL). Con D-01 decidida, **ninguna comisión puede causarse** hasta que exista el
   evento de entrega (Fase 06, Logística).
5. **El pago es por pedido; la comisión sería por unidad.** Cartera aplica dinero a pedidos
   (`bylopez_va_aplicaciones_pago.order_id`), no a ítems. Toda regla que condicione la comisión al
   pago necesita una regla de imputación.
6. **Precio neto por unidad es confiable**: `order_items.base_price` ya trae el precio del grupo del
   cliente (RT-03); en desarrollo no hay descuentos, impuestos ni reglas de carrito activas.
7. **Reembolsos y RMA: 0 filas.** RMA está inoperante (D-25). Los ajustes por devolución no tienen
   todavía disparador real.
8. **No hay contradicción que bloquee el diseño**, pero sí ambigüedades que solo Camilo puede
   resolver (condición de pago, base exacta, fuente del costo, porcentaje).

---

## 2. Fuentes auditadas y hallazgos por área

### 2.1 Identidad del asesor y atribución
Ver `15_MAPA_DOMINIO_ASESORES.md`. Beneficiario = `bylopez_va_order_attributions.advisor_id`
(`admins.id`) con `advisor_name_snapshot`; propiedad `advisor|company|unassigned`; historial inmutable
en `bylopez_va_advisor_assignment_logs`. **Gap G-A1:** reasignar no exige perfil activo.

### 2.2 Pedidos (`orders`)
- 30 pedidos en desarrollo: 13 `pending_payment`, 12 `completed`, 4 `processing`, 1 `canceled`.
- `base_grand_total` = `base_sub_total` + envío − descuento + impuesto. Suma dev: 5.848.300 =
  5.828.300 (ítems) + 20.000 (envío, solo pedidos contra entrega).
- `orders.status` es de Bagisto: `completed` puede significar «facturado y enviado», **no** entregado
  (D-56). No sirve como hecho generador.

### 2.3 Ítems (`order_items`)
- Columnas relevantes: `qty_ordered`, `qty_shipped`, `qty_invoiced`, `qty_canceled`, `qty_refunded`,
  `price`/`base_price`, `base_total`, `base_discount_amount`, `base_tax_amount`, `product_id`,
  `parent_id`, `additional`.
- **Configurables:** la fila padre (`type = configurable`) lleva precio y cantidad; la fila hija
  (`type = simple`, `parent_id` = padre) lleva el **SKU de la variante** con `price = 1` y
  `qty_ordered = 0` (verificado en el pedido 47). Cantidades siempre con `parent_id IS NULL` (RN-12);
  la variante se identifica por la hija o por `additional.selected_configurable_option`.
- **No existe columna de costo** ni clave de costo en `additional` (claves reales: `cart_id`,
  `product_id`, `quantity`, `selected_configurable_option`, `attributes`, `locale`).
- `collectTotals()` recalcula cantidades después de la venta [M-§8.8]: las cantidades son mutables
  (envíos, cancelaciones, reembolsos). La comisión debe congelar las suyas.

### 2.4 Precios y descuentos
- Precio de lista en el atributo `price` (p. ej. 8.500); precio por grupo en
  `product_customer_group_prices` (1.480 filas, todas `fixed`, p. ej. 6.900 «Madrugón»).
- RT-03 garantiza que el carrito usa el grupo **del cliente del carrito** → `order_items.base_price`
  es el precio neto real por unidad **antes** de descuentos de carrito.
- Descuentos: `cart_rules` y `catalog_rules` activas = 0; `base_discount_amount` = 0 en los 172 ítems.
  D-18 (asesor no modifica precios) y D-38 (descuento limitado por margen) — principio decidido,
  mecanismo pendiente.
- Impuestos: `base_tax_amount` = 0 en todos los ítems.

### 2.5 Costos
Ver §4 (gap crítico).

### 2.6 Utilidad
**No existe** en ningún lugar: ni campo, ni servicio, ni reporte. Se tendría que derivar.

### 2.7 Cartera y pagos
- Fuente única: `CarteraConsultaService` (derivado, sin guardar saldo):
  `saldo = max(0, base_grand_total − (aplicaciones activas sin_revisar + confirmadas + Transactions ajenas))`.
- `sin_revisar` **baja el saldo** pero no es dinero verificado; `verificado` = confirmado + ajenas.
- Solo entran en Cartera pedidos con `created_at` ≥ fecha de corte (`2026-10-06` en dev) y fuera de
  `canceled|closed|fraud`.
- El pago se imputa a **pedidos**, no a ítems (`aplicaciones_pago.order_id`, `invoice_id`).
- Desarrollo: 3 pagos, 3 aplicaciones, 3 Transactions.

### 2.8 Reembolsos, cancelaciones, RMA
- `refunds` = 0, `refund_items` = 0, `rma` = 0. RMA inoperante (`rma_*` vacías, D-25).
- Cancelación: `qty_canceled` por ítem y `orders.status = canceled`; Cartera los saca de saldo.
- `closed` = reembolsado por completo (Bagisto 2.4.9).
- RT-01 (idempotencia de devolución) y RT-02 (origen del reembolso) siguen abiertos.

### 2.9 Despacho y entrega
- `shipments` 23, `shipment_items` 145 (sin `parent_id`, una fila por prenda enviada; `product_id` es
  el configurable, la variante está en `sku`) [M-§8.2].
- **`shipments.status` = NULL en las 23 filas.** No hay tabla propia de estado logístico ni de eventos
  de entrega. Logística no ha empezado (D-07, D-14, D-40…D-45 pendientes).
- Bagisto crea el Shipment al **despachar** (descuenta inventario), no al entregar.

### 2.10 Históricos
- 24 pedidos anteriores sin atribución (reconciliación 02B1B no persistida). 5 atribuciones
  `unassigned/sin_asesor`; 1 `advisor`.
- Ningún pedido tiene costo congelado; los históricos **nunca** podrán tener uno verificable.

### 2.11 Reasignaciones
- `AsignacionAsesorService` (lock + motivo + log), sin ruta HTTP. Logs: 6 `initial_assignment`.
- D-04 (¿reasignar tras facturar/liquidar?) pendiente; recomendación técnica: bloquear tras
  liquidación pagada.

### 2.12 Contra entrega y transferencia
- Métodos usados: `cashondelivery` 16 pedidos, `moneytransfer` 14.
- Ambos con `order_status = pending_payment`, factura `pending`. Contra entrega: el dinero lo recauda
  la transportadora o el domiciliario y llega después; transferencia: normalmente antes del despacho.
- No hay registro del recaudo de la transportadora (es otra conciliación futura).

### 2.13 Liquidaciones
No existe nada (ni tablas ni servicios). Diseño previo en [M-§8.5–§8.7].

### 2.14 API / n8n / WhatsApp futura
No existe integración (D-08). La atribución ya soporta `creation_source = n8n|api` y
`external_reference`; un pedido automatizado nace `unassigned` salvo asesor explícito. Las comisiones
deben poder exponerse **solo lectura** por API más adelante (COM-09); nunca escritura de montos.

### 2.15 Seguridad
- ACL `ventas_asesor.comisiones.{view_own, view_all, manage, approve, pay}` **reservada desde la
  Fase 01**, sin rutas ni asignación a roles.
- D-10 (¿el asesor ve costos y márgenes?) pendiente; recomendación técnica: **no**.
- D-14 (¿quién confirma la entrega?) pendiente: si el asesor la confirma, hay **conflicto de interés**
  porque la entrega causa su comisión.

---

## 3. Matriz obligatoria de fuentes de verdad

| Concepto | Fuente de verdad | Tabla / campo | Snapshot hoy | Mutable | Riesgo | Uso futuro en comisiones |
|---|---|---|---|---|---|---|
| **Asesor** | Usuario Bagisto + perfil | `admins.id`; `bylopez_va_asesores` (`admin_id`, `activo`) | Nombre en la atribución | Perfil: nombre/teléfono/activo | Usar `activo` para historia excluiría ventas reales | Beneficiario = `admins.id`; nunca filtrar por `activo` al calcular historia |
| **Atribución** | Módulo | `bylopez_va_order_attributions.advisor_id`, `commercial_owner_type` | `advisor_name_snapshot` | Solo por reasignación auditada | Reasignar tras causar cambia el beneficiario | Congelar `advisor_id` en la línea de comisión al causar |
| **Cliente** | Bagisto | `orders.customer_id`, `customers` | Nombre/correo en `orders` | Sí (ficha) | Cambio de grupo no reprecia pedidos (correcto) | Solo informativo en reportes; el cliente no es «del asesor» |
| **Pedido** | Bagisto | `orders` (`status`, `base_grand_total`) | — | Estado y montos facturados/reembolsados | `status` no indica entrega; `grand_total` incluye envío | Agrupador; nunca base de cálculo |
| **Ítem** | Bagisto | `order_items` (padre: precio/cantidad; hija: variante) | `sku`, `name` del ítem | Cantidades (`qty_*`) | Doble conteo de configurables si no se filtra `parent_id IS NULL` | Precio neto unitario y variante; anclar al `shipment_item` |
| **Cantidad** | Bagisto + Logística futura | `qty_ordered/shipped/canceled/refunded`; `shipment_items.qty` | No | Sí | `collectTotals()` las recalcula | Cantidad **entregada elegible**, congelada por evento |
| **Precio** | Bagisto (con RT-03) | `order_items.base_price` | Sí (es el precio de venta del pedido) | No tras confirmar | Precio heredado si falla RT-03 (cerrado) | Precio neto unitario |
| **Descuento** | Bagisto (CartRule) | `order_items.base_discount_amount` | Sí | No tras confirmar | Descuento repartido por línea, no por unidad | Restar descuento unitario (`base_discount_amount / qty_ordered`) |
| **Costo** | **NO HAY fuente autoritativa** | Atributo `cost` (id 12) en `product_attribute_values` | **NO** | **Sí, sin historial** | **GAP CRÍTICO** (§4) | Snapshot propio por ítem al confirmar (COM-01) |
| **Utilidad** | No existe | — | No | — | Calcularla con costo actual reescribe historia | Derivada con costo congelado; congelada en la línea |
| **Pago** | Módulo (Cartera) | `bylopez_va_pagos`, `aplicaciones_pago` (por pedido) | Eventos inmutables | Estado `sin_revisar → confirmado|rechazado` | Pago por pedido vs comisión por unidad; `sin_revisar` no es dinero | Condición de pago (COM-D-04/05) |
| **Saldo** | Derivado | `CarteraConsultaService` | No (P-03) | Derivado | Incluye envío; `sin_revisar` lo baja | Leer `verificado` y `saldo`; no guardar |
| **Transaction** | Bagisto | `order_transactions` (nace al confirmar un pago) | Sí | No | Una por aplicación confirmada | Evidencia de dinero verificado |
| **Invoice** | Bagisto | `invoices` (`state`) | Sí | `pending → paid` | `paid` sin evidencia = revisar (no es dinero) | No es hecho generador (D-01) |
| **Refund** | Bagisto | `refunds`, `refund_items`, `qty_refunded` | Sí | No | Sin FK a RMA; duplicados posibles (RT-01/02) | Disparador de ajuste solo si hay devolución (D-24) |
| **Despacho** | Bagisto | `shipments`, `shipment_items` | Sí | No | No es entrega | Ancla canónica de la unidad comisionable |
| **Entrega** | **NO EXISTE** | — (Fase 06) | — | — | Sin entrega, D-01 no se puede cumplir | **Hecho generador** (D-01) |
| **Comisión** | **NO EXISTE** | — (COM-03/04) | — | — | — | Línea propia inmutable una vez liquidada |

---

## 4. Gap del costo histórico

### 4.1 Qué existe

| Lugar | Hallazgo | ¿Sirve como costo congelado? |
|---|---|---|
| Atributo `cost` de Bagisto | id 12, tipo `price`, **definido por el usuario**, global (sin canal ni idioma), en las 5 familias. 300 valores, todos > 0, **solo en variantes simples** (0 configurables; 3 simples sin costo). Valores: 4.900 (144), 5.500 (136), 4.500 (8), 3.900/4.000/4.200 (4 c/u). Productos con costo modificados entre 2025-10-13 y 2025-10-17 | **No**: es el valor **actual**, se edita desde el producto y Bagisto no guarda historial |
| `order_items` | Sin columna de costo; `additional` sin costo | **No** |
| `invoice_items`, `shipment_items`, `refund_items` | Sin costo | **No** |
| CorteConfeccion | Solo costo de **tela**: `bylopez_cc_rollos.valor_kilo`, `costo_total`; `compras_tela.total_factura`; `proveedor_tela_referencias.valor_kilo_referencia`. Corte, confección, talleres y recepciones **sin campos monetarios** | **No**: no hay costo unitario de prenda ni enlace prenda ↔ rollo ↔ pedido |
| Inventario | `product_inventories` sin costo; sin costo promedio ni FIFO | **No** |
| Módulo VentasAsesor | Sin snapshot de costo | **No** |

### 4.2 Resultado

> **SNAPSHOT DE COSTO: NO.**
>
> **GAP CRÍTICO.**

### 4.3 Por qué una comisión histórica no puede depender del costo actual

1. **Reescribe el pasado.** Si en marzo el costo sube de 5.500 a 6.000, todas las ventas de enero
   recalculadas con 6.000 bajan su utilidad y su comisión, aunque se vendieron con 5.500.
2. **Rompe D-02.** Una liquidación pagada debe ser reproducible e inmutable; con costo mutable, el
   mismo pedido daría otro número al recalcular.
3. **Es manipulable.** Quien edite el producto cambia comisiones ya causadas sin dejar rastro en
   comisiones.
4. **No es auditable.** Sin historial en Bagisto, no hay forma de saber qué costo regía el día de la
   venta.
5. **Ventana irrecuperable.** Cada venta confirmada sin snapshot pierde para siempre su costo verificable.
   Por eso COM-01 (snapshot) es la primera fase técnica y la más urgente del roadmap, aunque la
   comisión se cause meses después.

### 4.4 Además: el atributo `cost` no está demostrado como fuente autoritativa
D-38 ya prohibió usar `cost` como base del precio mínimo «sin demostrar que es esa fuente». Los valores
parecen **costos de referencia por línea de producto** (redondos, idénticos por tallas), no costos
reales de producción. Qué integra el costo (tela, corte, confección, empaque, transporte interno,
recaudo) sigue **pendiente (D-31)**. Recomendación: congelar el valor de `cost` **etiquetado con su
fuente** (`atributo_cost_bagisto`) mientras Camilo decide (COM-D-02); así la historia queda
protegida y se puede distinguir de una fuente futura más precisa.

---

## 5. Base de cálculo: qué número usar por unidad

```text
precio_neto_unitario = order_items.base_price − (order_items.base_discount_amount / order_items.qty_ordered)
                       (fila padre, parent_id IS NULL; moneda base; sin impuesto; sin envío)
```

- Sin impuesto: hoy `tax = 0`; si en el futuro hay IVA, la base debe ser **sin** IVA (RN-18 + criterio
  contable; confirmar en COM-D-01).
- Sin envío: el envío vive en `orders.base_shipping_amount` y no se reparte a ítems (RN-19, D-21).
- `grand_total` **nunca** es base comisionable.

---

## 6. Regla del 20 %

| Evidencia | Texto | Lectura |
|---|---|---|
| `ACTUALIZACION…` §7 | «La dirección de negocio **aceptada** es una comisión inicial equivalente al 20 % de la utilidad bruta positiva…» y «El 20 % **no debe marcarse como política definitiva** hasta validar márgenes reales» | Dirección aceptada, no política |
| D-32 | «**Propuesta registrada:** 20 %… No es política definitiva hasta validar márgenes reales.» Estado: **pendiente** | Propuesta |
| Doc. 06B §8.1 | «Propuesta: 20 % de la utilidad bruta positiva por unidad (D-32, pendiente)» | Propuesta |
| Doc. 04 índice | D-32 · «20 % (propuesta)» · pendiente | Propuesta |
| Código | No existe ningún porcentaje en código ni configuración | — |

**Estado real: PROPUESTA** (dirección aceptada, pendiente de validar con márgenes reales). Se registra
como **COM-D-03** (vinculada a D-32). Con datos reales de desarrollo, el 20 % equivale a:

| Precio neto | Costo (`cost` actual) | Utilidad | Comisión 20 % | % del precio |
|---:|---:|---:|---:|---:|
| 8.500 (lista) | 5.500 | 3.000 | 600 | 7,1 % |
| 6.900 (grupo) | 5.500 | 1.400 | 280 | 4,1 % |
| 6.900 (grupo) | 4.900 | 2.000 | 400 | 5,8 % |

---

## 7. Contradicciones y ambigüedades encontradas

| # | Contradicción / ambigüedad | Evidencia | Bloquea diseño | Tratamiento |
|---|---|---|---|---|
| C-1 | D-01 dice «entregadas y no devueltas»; `ACTUALIZACION` §7 dice «entregadas, **conciliadas** y no devueltas». «Conciliadas» no está definido (¿pago verificado? ¿recaudo COD?) | Doc. 04 D-01; ACTUALIZACION §7 | No | **COM-D-04** (condición de pago) |
| C-2 | Doc. 02 §8.4 propone reglas «valor fijo por prenda · porcentaje sobre venta · mixto»; la dirección posterior es «% de utilidad» | M-§8.4 vs ACTUALIZACION §7 | No | El diseño soporta % de utilidad; las demás quedan como alternativas (COM-D-01) |
| C-3 | D-06 «no se crea tabla de perfiles» vs D-60 (`bylopez_va_asesores`) | Doc. 04 | No | Anotado: superada solo esa frase (Doc. 15 §2) |
| C-4 | Reasignar no exige perfil activo (G-A1) | `AsignacionAsesorService` | No | Corregir antes de exponer reasignación |
| C-5 | D-10 recomienda que el asesor **no** vea márgenes; la UX pedida para «Mis comisiones» muestra Utilidad y % | D-10 vs pedido COM-00 | No | **COM-D-12**; el mock deja esas columnas condicionadas |
| C-6 | D-14: si el asesor confirma la entrega, causa su propia comisión | D-14 | No | **COM-D-11** |
| C-7 | Unidad comisionable por `shipment_item` [M-§8.2] vs pago imputado por pedido | M-§8 vs PAG | No | Regla de imputación (COM-D-05) |
| C-8 | `max(0, …)` **por unidad** (§7) vs neteo por pedido: una línea con pérdida no resta de otra con ganancia | ACTUALIZACION §7 | No | **COM-D-07** |

Ninguna impide diseñar; todas quedan como decisiones explícitas.

---

## 8. Gaps

| # | Gap | Severidad | Dónde se resuelve |
|---|---|---|---|
| G-1 | Sin snapshot de costo | **Crítica** | COM-01 |
| G-2 | Sin fuente autoritativa de costo (qué integra) | Alta | COM-D-02 / D-31 |
| G-3 | Sin evento de entrega | **Crítica para causar** | Fase 06 (Logística) |
| G-4 | RMA inoperante; reembolsos sin origen trazable | Alta para ajustes | Fase 07, RT-01, RT-02, D-25 |
| G-5 | Pago por pedido, comisión por unidad | Media | COM-D-05 |
| G-6 | Recaudo contra entrega sin conciliación (dinero de la transportadora) | Media | Logística / PAG-H |
| G-7 | Reasignación sin perfil activo y sin UI | Media | ASESOR / Fase 05 |
| G-8 | Sin reglas de comisión ni vigencias | Alta | COM-03 |
| G-9 | Sin periodo de liquidación | Media | COM-D-10 / D-15 |
| G-10 | Reversión de pagos confirmados sin mecánica (PAG-I) | Media | PAG-I antes de COM-08 |

---

## 9. Riesgos

| # | Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|---|
| R-1 | Seguir vendiendo sin snapshot de costo | Alta (pasa hoy) | Alto | Priorizar COM-01 |
| R-2 | Pagar comisión sobre dinero no recaudado (contra entrega, crédito) | Media | Alto | Condición de pago verificado (COM-D-04) |
| R-3 | Asesor confirma su propia entrega | Media | Alto | Separar quién confirma (COM-D-11) |
| R-4 | Doble comisión por reintentos o recálculos | Media | Alto | Idempotencia por `(evento_entrega, shipment_item)` [M-§8.6] |
| R-5 | Doble ajuste por devolución (RMA + reembolso) | Media | Medio | RT-01/RT-02; unicidad del ajuste [M-§7.2] |
| R-6 | Reasignación después de causar o pagar | Baja | Alto | Bloqueos por estado (D-04, COM-D-09) |
| R-7 | Comisiones visibles a otros asesores | Baja | Medio | `view_own` en servidor (mismo patrón que Cartera) |
| R-8 | El asesor ve el costo y deduce márgenes de la empresa | Media | Medio | COM-D-12 / D-10 |
| R-9 | Ajuste negativo a un asesor que ya no tiene liquidaciones futuras | Baja | Medio | COM-D-14 |
| R-10 | Recalcular históricos con reglas o costos nuevos | Media | Alto | Snapshot de regla y costo por línea; reglas con vigencia |

---

## 10. Recomendación técnica

1. **No implementar el motor de comisiones todavía**: depende de la entrega (Fase 06), que no existe.
2. **Sí implementar pronto COM-01**: snapshot inmutable del costo por ítem al confirmar el pedido,
   con su fuente etiquetada. Es independiente de Logística y detiene la pérdida de información.
3. **Calcular «comisión potencial» solo como dato derivado** (no persistido) mientras no haya entrega,
   para dar visibilidad sin crear obligaciones de pago.
4. Persistir líneas de comisión **solo al causar** (entrega + condición de pago decidida), inmutables,
   con snapshot completo (precio, descuento, costo, utilidad, %, regla, asesor, evento).
5. Liquidaciones con líneas propias, sin reescribir nunca una pagada; correcciones como ajustes (D-02).
6. Reutilizar la ACL reservada `ventas_asesor.comisiones.*`; el asesor nunca edita montos.
7. Mantener comisiones como **consumidor de solo lectura** de pedidos, pagos y logística: no altera
   `orders`, `order_items`, pagos ni Transactions.

---

## 11. Actualización 2026-10-08 — COM-01, COM-02 y COM-03 implementadas (desarrollo, sin commit)

Decisiones aplicadas: COM-D-01…14 (Doc. 04, sección COM-D), con la **precisión de COM-D-02**: costo **capturado** ≠
costo **autoritativo**.

### 11.1 Estado de la matriz (§3) después de COM-01…03

| Concepto | Antes | Ahora |
|---|---|---|
| **Costo** | Sin snapshot (gap crítico) | Snapshot por línea al crear el pedido en `bylopez_va_costos_venta`, **inmutable**, con `fuente` y `autoridad_comision`. Hoy la única fuente es `atributo_cost_bagisto_referencia` (**sin autoridad**). Sin valor → `NULL` + `sin_costo` |
| **Utilidad** | No existía | Derivada por `UtilidadVentaService` (solo lectura): **diagnóstica** con el costo capturado; **autorizada** solo con `autoridad_comision = true` (hoy nunca) |
| **Comisión** | No existía | Reglas con vigencia (`bylopez_va_comision_reglas`, regla #1 = 20 % en desarrollo) y **comisión potencial diagnóstica** (`ComisionPotencialService`), siempre `causada = pagable = liquidable = false`. **No hay líneas causadas** |
| Entrega | No existe | **Sigue sin existir** (bloquea COM-04) |

### 11.2 Gaps (§8) actualizados

| # | Gap | Estado 2026-10-08 |
|---|---|---|
| G-1 | Sin snapshot de costo | **Mitigado desde ahora** (captura de referencia). Los pedidos anteriores siguen sin snapshot y no se rellenan (COM-D-13) |
| G-2 | Sin fuente autoritativa de costo | **ABIERTO**: COM-D-02 deja la fuente autoritativa pendiente. Mientras tanto ninguna utilidad es liquidable |
| G-3 | Sin evento de entrega | **ABIERTO — bloqueo de COM-04** |
| G-4 | RMA inoperante; reembolsos sin origen | Abierto (COM-08) |
| G-5 | Pago por pedido vs comisión por unidad | **Resuelto por decisión**: COM-D-05 exige pago total verificado del pedido (sin imputación por unidad) |
| G-6 | Recaudo contra entrega sin conciliar | Abierto (Logística / PAG-H) |
| G-7 | Reasignación sin perfil activo y sin UI | Abierto (G-A1, Doc. 15) |
| G-8 | Sin reglas ni vigencias | **Resuelto** (COM-03) |
| G-9 | Sin periodo de liquidación | **Decidido: quincenal** (COM-D-10); implementación en COM-07 |
| G-10 | Reversión de pagos confirmados (PAG-I) | Abierto |
| G-11 (nuevo) | No existe marcador de «muestra / reposición / garantía» en pedidos o productos | Abierto: COM-D-06 los excluye, pero hoy no hay dato para distinguirlos. Debe resolverse antes de COM-04 |
| G-12 (nuevo) | Un fallo del listener de COM-01 deja un pedido sin snapshot (solo log) | Mitigado: `ventas-asesor:diagnostico-costos` lista los pedidos sin snapshot desde la activación |

### 11.3 Hallazgos de implementación

- **Punto de captura verificado:** `OrderRepository::createOrderIfNotThenRetry()` abre la transacción, crea Order,
  pagos, direcciones y todas las OrderItems (padre e hijas), y al final dispara `checkout.order.save.after`. El listener
  `CapturarCostoAlCrearPedido` corre ahí (tercero, después del enlace con el borrador y la atribución, antes de los
  listeners del core). Cubre Nueva venta, panel nativo y tienda.
- **Configurables confirmados por prueba real:** la fila padre lleva precio y cantidad; la hija lleva el SKU y el
  `product_id` de la variante. El snapshot es uno solo, sobre la fila padre, con el costo de la **variante** (nunca el
  del configurable).
- **Datos de desarrollo al cerrar:** 30 pedidos, 0 snapshots (no hubo pedidos nuevos desde la migración), 1 regla.

---

## 12. Actualización 2026-10-08 (segunda ronda) — D-COST, costos cerrados y auditoría de Logística

### 12.1 Costo comisionable (D-COST-01…07)
- El `cost` de Bagisto es el **costo comisionable oficial**: los snapshots nuevos nacen `cost_bagisto_comisionable` con
  `autoridad_comision = true` (sin valor: `sin_costo`, sin autoridad). Había **0** snapshots al cambiar: nada se
  reinterpretó. **G-2 queda RESUELTO por decisión** (la fuente autoritativa es el costo comisionable fijado por la
  empresa, no el costo productivo).
- La matriz §3 cambia en **Costo** (fuente oficial con autoridad) y **Utilidad** → se llama **margen comisionable**.
- Control de costos, comparación con CorteConfeccion y actualización masiva: `19_CONTROL_COSTOS_Y_RENTABILIDAD.md`.

### 12.2 Auditoría de Logística / entrega (Bloque 23, solo lectura)

| Pieza | Qué hay hoy | ¿Sirve como «entrega confirmada»? |
|---|---|---|
| `orders.status` | `completed` = facturado y enviado por completo (Bagisto) | **No** (D-56) |
| `shipments` (23 en dev) | Se crea al **despachar** y descuenta inventario. `status` NULL en las 23; `carrier_code` vacío en las 23; `carrier_title` texto libre (BYLOPEZ 13, BRAYAN 3, INGRI, PICAP, INTERRAPIDISMO, «RECOGE EN BODEGA»…); `track_number` con valor en 22 (D-43 advirtió números ficticios) | **No**: es despacho, no entrega |
| `shipment_items` (145) | Una fila por prenda despachada (ancla de la unidad, [M-§8.2]) | Ancla, no hecho |
| `ByLopez/Logistica` | Solo carriers (`domicilio_local`, `envio_nacional`) y `PuntosRecogida`; **sin tablas, sin guías, sin transportadoras, sin estados** | **No** |
| `bagisto-eu/pickup` | `pickup_orders` guarda la **cita** de recogida (fecha y franja) — 0 filas en dev; su listener de Shipment solo envía correo | **No**: cita ≠ retiro efectivo |
| 99envíos / tracking | **No existe integración** (solo mencionada en un comentario de `EnvioNacional`) | **No** |
| RMA / devoluciones | 0 filas; inoperante (D-25) | — |

**Conclusión:** hoy **no existe ninguna fuente de entrega**. Diseño de la entrega canónica en el Doc. 17 §14. Su
implementación depende de decisiones abiertas (D-44 diferida, D-07, D-42, COM-D-11 detallado: LOG-D-01…04 en el Doc. 04).

### 12.3 G-11 — muestras, reposiciones y garantías (auditoría)
- Bagisto no tiene un tipo de pedido ni un marcador de ítem para estos casos. `order_items.additional` es reescrito por
  el carrito y no es un dato estructurado fiable; un texto libre en comentarios no es auditable.
- D-26 (pendiente) recomendaba la reposición como **pedido nuevo de importe cero**. Con precio 0, el margen comisionable
  ya es `max(0, 0 − costo) = 0`: no comisiona sin marcador. Pero una muestra o garantía cobrada con descuento parcial sí
  generaría margen.
- Requiere decisión de Camilo (COM-D-15): opciones en el Doc. 17 §15.

## 13. Actualización 2026-10-08 (tercera ronda) — G-11 y entrega RESUELTOS en desarrollo

| Gap | Estado anterior | Estado ahora (desarrollo, sin commit) |
|---|---|---|
| **Entrega** (D-01) | No existía fuente; el Shipment es despacho | **RESUELTO (LOG-01):** evento propio `bylopez_lg_entregas` / `_items` en `ByLopez/Logistica`, por envío y por unidad, idempotente, inmutable con anulación auditada. Retorno de transportadora como evento separado. Lectura para COM-04: `ConsultaEntregaService::unidadesEntregadas()`, solo entregas vigentes |
| **G-11** muestras / reposiciones / garantías | Sin marcador estructurado | **RESUELTO (COM-D-15):** `bylopez_va_clasificaciones_pedido` 1:1 con `orders`, escrita en la transacción del pedido de Nueva venta. Solo `venta_normal` comisiona. Históricos y otros canales sin fila = no comisionables |
| Costo con autoridad | Resuelto por D-COST-02 | Sin cambios |
| Pago total verificado | Cartera (D-59) | Sin cambios |

Con esto existen en desarrollo las cuatro precondiciones del punto de control de COM-04:
1. Costo comisionable autoritativo.
2. Entrega canónica.
3. Pago total verificado.
4. Exclusión de muestras, reposiciones y garantías.

**COM-04 no se inicia** hasta la prueba manual de Camilo sobre LOG-01 y G-11.

**Hallazgos de la auditoría final de Logística (bloque 1):**
- `shipment_items.order_item_id` (sin FK en Bagisto) apunta a la línea **padre** del configurable: es el mismo ancla que el snapshot de costo (COM-01).
- Bagisto **nunca** escribe `shipments.carrier_code` y su formulario de despacho no tiene hooks de vista. El código se completa con un listener de `sales.shipment.save.after`, solo por coincidencia exacta.
- El rol BODEGA (#4) no tenía permisos de ventas ni de logística: la entrega tiene pantalla propia, independiente de `sales.*`.
