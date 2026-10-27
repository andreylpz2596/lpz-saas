# 21 · Auditoría del flujo completo de comisiones (venta → entrega validada → cobro → comisión → liquidación → pago → KPIs)

**Fecha:** 2026-10-14 · **Tipo:** auditoría y diseño, **sin implementación** · **Responsable funcional:** Camilo Gaitán

> **Actualización 2026-10-19:** implementado en desarrollo (Doc. 23): «Vender para» (P5), habilitación por pedido completo con
> recaudo liquidado (P1 = A), líneas, liquidaciones y abonos. El plan vigente es R1–R4.
>
> **Actualización 2026-10-15:** Camilo aprobó (Doc. 22 §0) el % de comisión **por asesor** con vigencias (reemplaza el 20 %
> global), el % **vigente al confirmar** (resuelve P4), la habilitación con **todo el pedido validado + cobro confirmado + dinero
> recibido por ByLopez** (resuelve P1 = A y reemplaza la recomendación P3 «por envío») y los **abonos con saldo pendiente**
> (reemplaza P11). Las cuentas de transportadora ya están aplicadas en desarrollo: el «dinero recibido» se toma del movimiento
> de `recaudo` liquidado. El plan C1–C4 queda **reemplazado** por R1–R4 del `22_PRECIO_MINIMO_RENTABILIDAD_Y_COMISIONES.md`
> §12, que pone primero la base de costos y el precio mínimo.

> Este documento **no repite** los Docs. 16 (auditoría COM-00), 17 (diseño funcional) y 19 (costos). Registra lo que cambió
> desde el 2026-10-08 (validación de entrega, cobro contra entrega desde Logística, cuentas de transportadora en curso), lo
> que el **código y los datos reales** muestran frente a esos documentos, y el plan para cerrar el flujo. Donde el Doc. 17 ya
> lo resuelve se remite a él.
>
> **Cómo se obtuvo la evidencia:** lectura de código y migraciones; consultas `SELECT` agregadas en `lpz_saas` (desarrollo,
> 2026-10-14), sin datos personales. **No** se ejecutaron tests, simulaciones ni navegador, **no** se aplicaron migraciones
> ni se modificó código o datos. Todo lo marcado «implementado» significa **existe en código**; no está verificado
> funcionalmente salvo que se diga que Camilo lo probó.

---

## 1. Estado real

| Pieza | Estado | Respaldo (código / esquema) | Observación |
|---|---|---|---|
| Atribución comercial (propietario de la venta) | **Implementado** | `bylopez_va_order_attributions` (UNIQUE `order_id`), `ResolverAtribucionService`, listener `AtribuirPedidoCreado` | Asesor = `advisor_id` (= `admins.id`) con `commercial_owner_type = advisor` |
| Historial de asignación | **Implementado** | `bylopez_va_advisor_assignment_logs`, `AsignacionAsesorService` (lock + motivo) | Solo `initial_assignment` en datos (27) |
| Reasignación | **Parcial** | Servicio sí; **sin ruta HTTP ni pantalla** | Nadie puede reasignar hoy desde la UI |
| Venta creada por admin para otro asesor | **Parcial** | `ResolverAtribucionService::resolverSeleccion()` lo soporta | **Ningún controlador pasa `SeleccionPropiedadComercial`**: la UI nunca la envía |
| Perfil de asesor | **Implementado** | `bylopez_va_asesores` (1:1 con `admins`, `activo`) | 2 perfiles activos en dev |
| Clasificación comercial (G-11) | **Implementado** | `bylopez_va_clasificaciones_pedido`, `TipoOperacionPedidoService` | Solo pedidos de Nueva venta; 30 de 51 pedidos sin fila |
| Snapshot de costo (COM-01/01b) | **Implementado** | `bylopez_va_costos_venta`, `CapturarCostoVentaService` vía `CapturarCostoAlCrearPedido` (`checkout.order.save.after`) | Captura **fuera** de la transacción del pedido; si falla, solo se registra en el log |
| Utilidad comisionable (COM-02) | **Implementado, solo lectura** | `UtilidadVentaService` | Usa `float`; cantidad = `qty_ordered − qty_canceled` (no entregada) |
| Reglas con vigencia (COM-03) | **Implementado** | `bylopez_va_comision_reglas` (regla #1 `COM-GLOBAL-INICIAL`, 20 %, alta por CLI), `ReglasComisionService` | Sin pantalla |
| Comisión potencial | **Implementado, diagnóstica** | `ComisionPotencialService` | Toma la regla vigente **ahora**, no la del hito |
| Entrega registrada (LOG-01) | **Implementado** | `bylopez_lg_entregas` / `_items` (por `shipment_item`, `fecha_entrega`) | Probada manualmente por Camilo (según su reporte) |
| **Entrega validada** | **Implementado** | `bylopez_lg_validaciones_entrega`, `ValidarEntregaService`, permiso `logistica.entregas.validar` | **Por envío (Shipment)**, no por pedido. 4 vigentes en dev |
| Cobro contra entrega desde Logística | **Implementado** | `CobrarEntregaService` → `CobroViaCartera` → `RegistrarPagoService` + `ConfirmarPagoService` | Registra **y confirma** con el mismo actor si tiene permiso |
| Cartera (saldo, pagos, aplicaciones) | **Implementado** | `CarteraConsultaService` (derivado), `bylopez_va_pagos`, `_aplicaciones_pago`, `_pagos_eventos` | Sin saldo a favor ni sobrepago; reembolsos → `requiere_revision` |
| Reverso de pago confirmado (PAG-I) | **Inexistente** | Solo la clave ACL `ventas_asesor.cartera.reversar` | No hay servicio |
| Cuentas de transportadora (custodia del recaudo) | **En curso (otra sesión)** | Migración **pendiente** `2026_10_14_100014_create_bylopez_lg_cuentas_transportadora_tables`, `Services/Cuentas/*` | Libro firmado + liquidaciones LIQ-000001. **No aplicada**; no se tocó |
| Recaudo esperado en guía | **Implementado** | `bylopez_lg_guias.recaudo_esperado`, `recaudo_estado` (`pendiente_liquidar`; `liquidado` reservado) | **Nunca** pasa a `liquidado` hoy |
| Devoluciones post-entrega (RMA) / reembolsos | **Inexistente** como flujo propio | Fase 07 bloqueada; `refunds` = 0 en dev | |
| Causación / líneas de comisión (COM-04) | **Inexistente** | Ninguna tabla `comision_lineas`; nada en VentasAsesor lee `validaciones_entrega` | |
| Liquidaciones y pago al asesor (COM-07) | **Inexistente** | Solo claves ACL `comisiones.approve` / `.pay` | |
| Ajustes (COM-08), «Mis comisiones», bandeja admin, KPIs | **Inexistente** | Claves ACL `comisiones.*`, `kpis.view_own/view_all` sin rutas | |

---

## 2. Hallazgos por bloque

### 2.1 Atribución comercial

- **Fuente del asesor propietario:** `bylopez_va_order_attributions.advisor_id` cuando `commercial_owner_type = 'advisor'`.
  Es la **única** fuente válida para la comisión. `created_by_admin_id` es quien creó el pedido; no decide el beneficiario.
- **Creador ≠ asesor ≠ empresa ≠ sin asignar:** `advisor` (asesor), `company` (venta directa ByLopez), `unassigned`
  (sin decidir; **nunca se infiere**). En dev: 10 `advisor` (todos con creador = asesor), 17 `unassigned` **todos con
  creador administrador**, **0** `company` y **24 pedidos sin fila de atribución** (históricos o canales no conectados).
- **Venta creada por admin para otro asesor:** el dominio lo soporta (`resolverSeleccion`, exige
  `ventas_asesor.pedidos.assign` y que el destino sea asesor), pero **la UI no lo ofrece**. Resultado actual: todo pedido
  creado por un administrador queda `unassigned` y **no comisionaría**, aunque se hiciera para un asesor.
- **Históricos sin evidencia:** regla D → `unassigned` / `sin_contexto`; no hay backfill. Reconciliación (Doc. 10) no
  asigna asesores por inferencia.
- **Reasignación:** `AsignacionAsesorService` exige `pedidos.assign` (sin dueño) o `pedidos.reassign` (con dueño), motivo
  y deja una fila de log con anterior/nuevo y snapshots; bloquea la fila. **Falta:** pantalla, y una guarda que impida
  reasignar cuando ya existe comisión habilitada/liquidada (COM-D-09 dice que entonces es un ajuste).
- **Identidad que conserva la comisión:** `advisor_id` + `advisor_name_snapshot` de la atribución **al habilitar**
  (COM-D-09). Desactivar el perfil no cambia nada (Doc. 17 §6). Riesgo: `AutorizacionVentasAsesor::esAsesor()` se basa en el
  permiso marcador; el cálculo **no** debe exigir perfil activo para causar ventas pasadas.
- **No atribuir por quien opera:** quien cobra (`bylopez_va_pagos.registrado_por_admin_id`), despacha, registra o valida
  la entrega (`actor_admin_id`) **nunca** es beneficiario. El motor debe leer solo la atribución.

### 2.2 Entrega y cumplimiento

- **La validación pertenece al ENVÍO** (`shipment_id`, UNIQUE vigente por envío mediante `vigente_unica`), con foto de
  `entregadas`/`devueltas` **agregadas** (no por línea). El detalle por unidad está en `bylopez_lg_entrega_items`
  (`shipment_item_id`, `order_item_id`, `cantidad`) y en retornos/recepciones.
- **Condiciones de validar** (`ValidarEntregaService`, bajo bloqueo del Shipment): ≥ 1 unidad entregada · 0 pendientes en
  ese envío · ninguna devolución vigente sin recibir en Bodega. Idempotente por `idempotency_key` + huella y por la UNIQUE.
- **Pedido con varios envíos:** no hay consolidación por pedido. «Entrega completa del pedido» no existe como concepto.
  Debe derivarse: todas las unidades vendidas (`qty_ordered − qty_canceled` por ítem padre) están en envíos **validados**
  como entregadas o devueltas. Hoy en dev **0** pedidos tienen más de un envío y **40** líneas tienen unidades sin enviar.
- **Anulación de la validación:** permitida con motivo (mismo permiso `validar`); mientras está vigente, Logística impide
  anular entregas/retornos/recepciones del envío (`ENVIO_VALIDADO`). **Logística no sabe si hay comisión**: anular una
  validación usada por una comisión ya liquidada o pagada no se bloquea ni se avisa. → Riesgo R-04.
- **Cambios posteriores:** con la validación vigente no puede registrarse una nueva entrega (no quedan pendientes). Una
  **devolución del cliente después de entregar** no tiene flujo (RMA inexistente): el retorno de transportadora es para
  unidades no entregadas.
- **Eventos duplicados / fuera de orden:** la validación y el cobro son idempotentes por clave. El orden cobro→validación o
  validación→cobro es libre: **ambos órdenes deben dar el mismo resultado** en la comisión (se evalúa el estado, no el
  evento).
- **`completed` de Bagisto NO acredita lo necesario:** `OrderRepository::isInCompletedState()` = todo facturado (en
  **cualquier** estado de factura) y todo enviado. No mira entrega, validación ni dinero. En dev: **18 de 19** pedidos
  `completed` no tienen validación y **13** ni siquiera entrega registrada. **Nunca** usar `orders.status` para comisión.
- **Cumplimiento del asesor ≠ cumplimiento logístico:** no existe fecha compromiso de entrega en pedidos ni borradores
  (búsqueda sin resultados). Un KPI de «entregas a tiempo» **no es calculable** hoy y, si se crea, no debe imputarse al
  asesor (el retraso es de Bodega/transportadora).

### 2.3 Cartera y dinero real

- **Fuente oficial:** `CarteraConsultaService::saldosDePedidos()` (derivado, sin persistir): `aplicado_total`,
  `verificado` (aplicaciones confirmadas + Transactions ajenas), `sin_revisar`, `saldo = max(0, base_grand_total −
  aplicado_total)`, `requiere_revision`, `en_cartera`/`motivo_fuera`. «Pagado total verificado» se define como:
  `en_cartera ∧ ¬requiere_revision ∧ saldo = 0 ∧ sin_revisar = 0`.
- **Aplicación multipedido:** sí (`RegistrarAbonoClienteService`; 1 pago con > 1 aplicación en dev). Sin sobrepago ni saldo
  a favor del cliente (se rechaza `MONTO_EXCEDE_SALDO`). Anticipos de clientes: **no existen**.
- **Reembolsos / notas de crédito:** cualquier `refund` o `base_grand_total_refunded > 0` → `requiere_revision`. Bagisto no
  tiene notas de crédito separadas del `refund`. Facturas múltiples: soportadas por Bagisto, 0 en dev.
- **Pedidos anteriores al corte** (`VENTASASESOR_CARTERA_FECHA_CORTE = 2026-10-06`): `en_cartera = false` → nunca
  «pagado verificado» → no comisionan (coherente con COM-D-13).
- **Coherencia factura/pedido:** solo `ConfirmarPagoService` pasa la factura a `paid` vía repositorios de Bagisto; ninguna
  pieza fuerza estados a mano. Correcto.
- **CRÍTICO — dinero cobrado por el domiciliario ≠ dinero recibido por ByLopez:**
  - DESP-D-04 / Doc. Logística §11.4: el recaudo contra entrega **no** se registra en Cartera; la guía queda
    `pendiente_liquidar` («la transportadora DEBE recaudar $X»).
  - Doc. Logística §13.1 (2026-10-14): desde el drawer de Entregas se **registra y confirma** en Cartera un pago con medio
    `contraentrega_recaudo` / `efectivo`. Eso deja la factura `paid` y el pedido cerrado **aunque el dinero siga en manos del
    domiciliario**.
  - Evidencia en dev: **5 pedidos** con pago **confirmado** en Cartera y guía `pendiente_liquidar`; 1 pago confirmado con
    medio `contraentrega_recaudo`; recaudo esperado pendiente total ≈ $2,17 M en 13 guías; ninguna guía `liquidado`.
  - El registro de que el transportador entregó el dinero a ByLopez **está en construcción** en otra sesión (cuentas de
    transportadora: movimiento `recaudo` abierto → `liquidado` dentro de una liquidación LIQ). Hoy no existe.
  - Conclusión: con el código actual, «pedido totalmente pagado/verificado» (COM-D-04) se cumple **cuando el cliente pagó
    al domiciliario**, no cuando ByLopez tiene el dinero. → Pregunta bloqueante P1.
- **Revisión por un solo actor:** `CobroViaCartera::cobrar()` registra y, si el mismo actor puede revisar, confirma en el
  acto. No hay segundo par de ojos. Aceptable si es decisión; señalarlo.

### 2.4 Costos y utilidad

- **Fuente del costo:** atributo `cost` de Bagisto de la variante vendida (tipo `price`, no por canal ni idioma), congelado
  al crear el pedido en `bylopez_va_costos_venta` (D-COST-02/06). Cobertura actual: 306 de 307 productos simples con
  `cost > 0`. Bitácora de cambios masivos: 2.
- **Conservación histórica:** el snapshot no cambia si después cambia `cost` (D-COST-06). **172 líneas padre sin
  snapshot** (pedidos anteriores al primer snapshot, 2026-10-09): quedan fuera (COM-D-13); no se reconstruyen.
- **Costo productivo** (tela, corte, confección, transporte productivo): solo **referencia parcial (tela)** desde
  CorteConfeccion (Doc. 19); no entra a la comisión (D-COST-07). Las tarifas de taller/cortador del Doc.
  `CorteConfeccion/docs/confeccion/05_*` todavía no alimentan ningún costo. **No hay inventario por lotes con costo.**
- **Costo cero legítimo vs desconocido:** la BD prohíbe 0 (`CHECK costo_unitario > 0`); sin valor = `sin_costo` (no
  comisiona). Un producto realmente de costo 0 no es representable. → P9.
- **Riesgo de captura:** el snapshot se hace en una transacción **posterior** al pedido; si falla, el pedido queda sin
  costo y solo se registra en el log. Hace falta un diagnóstico visible (existe `DiagnosticoCostosVentaService`) y una vía
  de recuperación auditada **solo con el costo del momento** (si no se conoce, queda sin comisión).
- **¿Hoy se puede calcular una utilidad comisionable fiable por venta?** Sí, **para pedidos posteriores al 2026-10-09 con
  snapshot autorizado**, como `precio neto − costo comisionable`. **No** es utilidad real de la empresa (D-COST-01).

**Base comisionable (vigente por COM-D-01 y D-COST, con lo que el código hace hoy):**

| Concepto | ¿Entra? | Estado |
|---|---|---|
| Venta neta de mercancía (`base_price` del ítem padre) | Sí | Implementado |
| Descuentos por línea y generales | Sí, ya distribuidos por Bagisto en `base_discount_amount` de cada ítem (en dev, 0 pedidos con diferencia entre descuento de pedido y suma de ítems) | Implementado |
| Impuestos | No (0 pedidos con IVA en dev; `base_price` sin impuesto) | Implementado; revisar si algún día se activa IVA incluido |
| Devoluciones / notas de crédito | No en COM-02; como ajuste (COM-08) | Pendiente |
| Flete cobrado al cliente | No (COM-D-01: sin envío) | Implementado |
| Flete asumido por ByLopez (`flete_pagador = bylopez`, 4 guías) | **No definido** | → P6 |
| Empaques, comisiones de pasarela, otros directos | No registrados en ninguna fuente | Fuera (D-COST-01) |
| Esta misma comisión | No: la base es **anterior** a la comisión (no circular) | Regla técnica |

Ejemplo (D-COST-02): precio neto 8.500 − costo 5.000 = margen 3.500 → 20 % = 700. Si ByLopez asume un flete de 6.000 en un
pedido de 2 unidades y se decidiera descontarlo: margen (3.500 × 2) − 6.000 = 1.000 → comisión 200. Sin descontarlo: 1.400.

### 2.5 Reglas de comisión

- **Documentadas como decididas por Camilo el 2026-10-08 (Doc. 04 §COM-D):** COM-D-01, 03 (20 % sobre utilidad positiva,
  configurable y con vigencia), 04 (entrega **y** pago total verificado), 05 (sin proporcional), 06 (global; muestras,
  reposiciones y garantías no), 07 (`max(0, …)` por unidad, sin neteo), 08, 09, 10 (quincenal), 11, 12, 13, 14, 15 y
  D-COST-01…07. Por instrucción de esta auditoría se tratan como **«documentadas, pendientes de reconfirmar»** (P2).
- **Contradicción código-documento:** `ComisionPotencialService` usa la regla vigente **en la fecha de consulta**
  (`now()`), y su comentario dice que la real usará la de la **entrega**; no hay decisión explícita sobre la fecha que fija
  el porcentaje. → P4.
- **Por línea vs por pedido:** COM-D-07 fija por unidad sin neteo. Ejemplo: línea A gana 2 × 1.000, línea B pierde 1 ×
  (−1.500) → base comisionable 2.000 (B aporta 0); con neteo sería 500.
- **Estados:** ver §5.2 (persistidos vs derivados).
- **Precisión:** COM-02/03 y Cartera calculan con `float` y `round(…, 2)`. Para importes de comisión se propone
  `brick/math` (ya en `vendor/`), `decimal(14,2)` en BD, cálculo por unidad con 4 decimales y redondeo **HALF_UP a 2 por
  línea**; totales = suma de líneas redondeadas. COP no usa centavos en la práctica, pero se conserva la escala 2 de Cartera.

### 2.6 Liquidaciones y pagos al asesor

- **Reutilizable:** el diseño de Doc. 17 §7.5/§9 (borrador → cerrada → pagada, UNIQUE de línea en una sola liquidación,
  pagada inmutable); el patrón de **cuentas de transportadora** (libro firmado de movimientos + liquidación inmutable con
  consecutivo, idempotencia, reverso por movimiento) es el modelo más cercano para el **saldo con el asesor**.
- **No es Cartera:** la obligación con el asesor es otra cuenta (ByLopez debe al asesor). Nunca se registra en
  `bylopez_va_pagos`.
- **Falta todo:** tablas, servicios, comprobante privado (reutilizar el patrón de archivos privados de guías: endpoint
  autenticado, sin URL pública), pagos parciales (se propone dejarlos fuera: una liquidación = un pago), anticipos al
  asesor (fuera), fecha de inicio y marca de «pagada fuera del sistema» para conciliar históricos.

### 2.7 Integridad y permisos

- **ACL existente sin uso:** `ventas_asesor.comisiones` (`view_own`, `view_all`, `manage`, `approve`, `pay`) y
  `ventas_asesor.kpis` (`view_own`, `view_all`). **Faltan** claves separadas para **calcular/recalcular**, **revertir**,
  **ajustar** y **exportar** (hoy todo caería en `manage`).
- **Aislamiento del asesor:** debe aplicarse en el servicio (como Cartera con `view_own`), nunca solo en la vista; los
  comprobantes y exportaciones pasan por endpoints autorizados.
- **Costos al asesor:** COM-D-12 decidido (no ve costo, utilidad ni margen). La bandeja de Bodega ya los oculta (DESP-D-01).
- **Concurrencia:** la evaluación de un pedido debe bloquear `orders` (o una fila de control por pedido) y apoyarse en
  UNIQUE `(order_item_id, tipo='causacion')` vigente; reintentos = no-op.
- **Compatibilidad:** 180 archivos sin commit en VentasAsesor (COM-01…03, Cartera, costos) y la migración pendiente de
  cuentas de transportadora de **otra sesión**. COM-04 no debe aplicar ni alterar esa migración; debe depender de un
  **contrato** (como `GestionaCobroPedido`) para preguntar «¿el recaudo de este pedido ya entró a ByLopez?».

---

## 3. Contradicciones y riesgos (por impacto)

| Id | Impacto | Hallazgo | Consecuencia | Recomendación |
|---|---|---|---|---|
| R-01 | **Crítico** | Cobro contra entrega confirmado en Cartera mientras la guía sigue `pendiente_liquidar` (5 pedidos en dev). DESP-D-04 dice que el recaudo no va a Cartera; Logística §13.1 lo registra | Se habilitaría comisión sobre dinero que ByLopez no tiene | P1: exigir recaudo liquidado para pagos contra entrega |
| R-02 | **Crítico** | Pedidos creados por admin quedan `unassigned` (17 en dev) y no hay UI para elegir asesor ni para reasignar | Ventas reales de asesores sin comisión, o presión para «arreglar» datos a mano | P5: selector «vender para» + pantalla de reasignación auditada |
| R-03 | **Alto** | `orders.status = completed` ≠ entregado ni pagado (18 de 19 sin validación) | Si alguien usa `completed`, paga comisiones indebidas | Prohibido por diseño; solo validación + Cartera |
| R-04 | **Alto** | Anular una validación no sabe si hay comisión liquidada/pagada | Comisión pagada sin entrega vigente, sin ajuste | Contrato que Logística consulte antes de anular (aviso) y ajuste automático en Comisiones |
| R-05 | **Alto** | Validación por envío; no hay noción de «pedido entregado completo» | Habilitar con un envío validado y otro pendiente | Elegibilidad por unidad validada (§5.3) |
| R-06 | **Alto** | Sin flujo de devolución post-entrega ni reverso de pago (PAG-I) | No hay evento que genere ajuste negativo | Mientras no existan: pedido con refund → «requiere revisión» y bloqueo de habilitación; ajuste manual auditado |
| R-07 | **Medio** | Regla tomada con `now()` en el diagnóstico; fecha que fija % no decidida | Cambiar la regla altera comisiones no liquidadas | P4: fijar la fecha y congelar `regla_id` en la línea |
| R-08 | **Medio** | Snapshot de costo fuera de la transacción del pedido; falla silenciosa | Pedidos sin costo → sin comisión sin que nadie lo note | Bandeja «pendientes de costo» + recuperación auditada |
| R-09 | **Medio** | 30 pedidos sin clasificación (tienda/panel) | Ambigüedad: ¿comisionan? | P8: sin clasificación = no comisiona hasta clasificar |
| R-10 | **Medio** | Cálculos monetarios en `float` | Diferencias de redondeo entre líneas, totales y liquidación | `brick/math` + decimal(14,2) en Comisiones |
| R-11 | **Medio** | Registro y confirmación del cobro por la misma persona | Sin control de 4 ojos sobre el hecho que habilita comisión | Aceptar explícitamente o separar permisos |
| R-12 | **Bajo** | Costo 0 no representable | Productos de costo 0 nunca comisionan | P9 |
| R-13 | **Bajo** | Sin fecha compromiso de entrega | KPI «a tiempo» imposible | No construirlo hasta tener la fecha |
| R-14 | **Bajo** | 24 pedidos sin fila de atribución | KPIs con universo incompleto | Mostrar «sin atribución» como categoría propia |

---

## 4. Fuentes de datos y puntos de integración

| Hecho | Fuente única | Servicio / contrato | Quién lo consume |
|---|---|---|---|
| Asesor beneficiario | `bylopez_va_order_attributions` | `AsignacionAsesorService` (escritura), lectura directa | Motor de comisiones |
| Comisionable por tipo de operación | `bylopez_va_clasificaciones_pedido` (`venta_normal`) | `TipoOperacionPedidoService` | Motor |
| Precio neto y cantidades vendidas | `order_items` padre (`base_price`, `base_discount_amount`, `qty_ordered`, `qty_canceled`) | `UtilidadVentaService` | Motor |
| Costo comisionable congelado | `bylopez_va_costos_venta` (`autoridad_comision = 1`) | `CapturarCostoVentaService` | Motor |
| Regla y porcentaje | `bylopez_va_comision_reglas` | `ReglasComisionService::vigenteEn()` | Motor |
| Unidades entregadas por ítem | `bylopez_lg_entrega_items` vigentes | `ConsultaEntregaService::unidadesEntregadas()` | Motor (vía contrato) |
| Entrega validada por envío | `bylopez_lg_validaciones_entrega` (`estado = confirmado`) | Nuevo contrato de solo lectura `ConsultaValidacionesEntrega` (Logística lo implementa) | Motor |
| Pago total verificado | Cartera (derivado) | `CarteraConsultaService::saldosDePedidos()` | Motor |
| Recaudo entregado a ByLopez | Cuentas de transportadora (en curso) | Nuevo contrato `RecaudoLiquidadoDePedido` (Logística) | Motor (si P1 = A) |
| Anulaciones posteriores | Validaciones anuladas, pagos rechazados, refunds | Lectura en cada reevaluación | Motor → ajustes |
| Obligación con el asesor | Nueva cuenta de comisiones (§5) | Servicios nuevos | Liquidación, «Mis comisiones», KPIs |

**Dirección de dependencias:** VentasAsesor (Comisiones) **lee** Logística por contratos; Logística **no** conoce Comisiones,
salvo un contrato opcional `ComisionDependeDeEnvio` para avisar antes de anular una validación (R-04).

---

## 5. Modelo mínimo propuesto (delta sobre Doc. 17 §7)

### 5.1 Unidad de cálculo

Una **línea de comisión por ítem padre del pedido** (`order_item_id`), con la cantidad habilitada acumulable por envío
validado. Para no duplicar: UNIQUE `(order_item_id, shipment_id, tipo='causacion')` vigente. Esto sustituye la clave
`evento_entrega_id` del Doc. 17 §7.3, porque el hito real es la **validación por envío**.

### 5.2 Estados

| Estado | Persistido | Cuándo |
|---|---|---|
| `estimada` | No (derivado) | Venta de asesor `venta_normal` con snapshot; nada validado |
| `pendiente_entrega` | No | Hay unidades sin validar |
| `pendiente_cobro` | No | Unidades validadas, pedido sin pago total verificado (o con pagos `sin_revisar` / `requiere_revision`) |
| `pendiente_recaudo` | No | Pagado en Cartera con medio contra entrega, recaudo aún no liquidado (solo si P1 = A) |
| `no_comisionable` | No | Empresa / sin asignar / muestra / sin costo / anterior al inicio, con motivo |
| **`habilitada`** | **Sí** | Línea con snapshot completo (asesor, cantidad, precio neto, costo, regla, %, monto, evidencias: `validacion_id`, fecha de último pago verificado, liquidación de recaudo) |
| **`en_liquidacion`** | Sí (vía `liquidacion_id` en borrador/cerrada) | Incluida en una liquidación |
| **`pagada`** | Sí (vía liquidación pagada) | Inmutable |
| **`anulada`** | Sí | Revertida **antes** de liquidar (validación anulada, pago rechazado, reasignación) |
| **ajuste** (tipo de línea) | Sí | Monto negativo o positivo con `linea_origen_id`, motivo, usuario, fecha, cuando la original ya está liquidada |

«Parcialmente pagada» no aplica a la línea: es un estado de la **liquidación** solo si se aprueban pagos parciales (P11).

### 5.3 Elegibilidad (función pura, se reevalúa siempre igual)

```
habilitable(unidades de un order_item en un envío) =
  atribución.tipo = advisor
  ∧ clasificación = venta_normal
  ∧ pedido.created_at ≥ fecha de inicio de comisiones
  ∧ snapshot de costo con autoridad
  ∧ envío con validación vigente  (unidades = entregadas del ítem en ese envío − devueltas)
  ∧ Cartera: en_cartera ∧ ¬requiere_revision ∧ saldo = 0 ∧ sin_revisar = 0
  ∧ (si P1 = A y el pedido tiene recaudo contra entrega) recaudo liquidado
```

Monto de la línea = `max(0, precio_neto_unitario − costo_unitario) × unidades × porcentaje`, con `brick/math`,
redondeo HALF_UP a 2 decimales.

### 5.4 Tablas

- `bylopez_va_comision_lineas` y `bylopez_va_comision_eventos`: las del Doc. 17 §7.3/§7.4, con `shipment_id` +
  `validacion_id` en lugar de `evento_entrega_id`, importes `decimal(14,2)` y snapshots de evidencia.
- `bylopez_va_comision_liquidaciones`: la del Doc. 17 §7.5 (quincenal, `periodo_desde/hasta` propios, consecutivo
  `LQC-000001`, `idempotency_key`).
- `bylopez_va_comision_pagos`: pago al asesor (fecha, medio, referencia, comprobante privado, actor), 1:1 con la
  liquidación si no se aprueban pagos parciales.
- **No** se crea una «cartera del asesor» aparte: el saldo con el asesor = Σ líneas habilitadas/ajustes no pagados.

### 5.5 Disparo

Un servicio idempotente `EvaluarComisionPedidoService::evaluar(orderId)` que lee todas las fuentes y crea/anula líneas.
Se invoca: (a) al validar o anular una validación, (b) al confirmar o rechazar un pago, (c) al liquidar un recaudo, (d) por
un comando de conciliación nocturno o manual. Al ser idempotente, el orden de los eventos no importa.

---

## 6. Vistas y KPIs

### 6.1 Vistas (componentes Bagisto: DataGrid, drawer/modal, accordion; mismo patrón que Cartera y Entregas)

| Vista | Permiso | Contenido |
|---|---|---|
| **Comisiones (admin)** | `comisiones.view_all` | DataGrid por línea/pedido: asesor, pedido, estado (§5.2), unidades, monto, motivo de no comisionable. Filtros: asesor, quincena, estado. Acciones: «Reevaluar» (`comisiones.calcular`) |
| **Detalle de cálculo por pedido** | `view_all`; asesor sin costos | Drawer: líneas, precio neto, costo (solo admin), %, monto; **evidencias**: validación (quién, cuándo, envío), pagos verificados, recaudo liquidado; historial de eventos |
| **Liquidaciones** | `approve` / `pay` | Crear borrador por asesor y quincena → revisar → cerrar → registrar pago con comprobante privado |
| **Mis comisiones** | `comisiones.view_own` | Sus ventas, unidades, comisión y estado; sus liquidaciones y pagos. Sin costo ni margen (COM-D-12) |
| **Panel de cumplimiento y rentabilidad** | `kpis.view_all` / `view_own` | KPIs de §6.2 |

### 6.2 KPIs

> **Cohortes vs período:** los KPIs de **ventas** usan la fecha de venta (`orders.created_at`); los de **comisión**
> usan la fecha del hito (habilitación / liquidación / pago). Nunca se suman entre sí; el panel separa ambas secciones.

| KPI | Fuente | Fórmula | Fecha | Filtros | Datos incompletos |
|---|---|---|---|---|---|
| Ventas netas atribuidas | `order_items` padre + atribución `advisor` | Σ (`base_price` − desc. unit.) × (`qty_ordered` − `qty_canceled`) | Venta | Asesor, período, clasificación | Sin atribución / `unassigned` en fila aparte |
| Entregas validadas / pendientes | `validaciones_entrega` vs envíos y unidades sin envío | Unidades en envíos validados / unidades vendidas | Validación | Asesor, período | Pedidos sin envío = «sin despachar» |
| Entregas a tiempo | — | **No calculable** (sin fecha compromiso) | — | — | No mostrar |
| Cobros confirmados y saldo | Cartera (derivado) | Σ verificado; Σ saldo | Fecha de pago confirmado | Asesor, período | Fuera de Cartera (antes del corte) y `requiere_revision` aparte |
| Recaudo en poder de transportadora | Cuentas de transportadora | Σ movimientos `recaudo` abiertos | Envío | Transportadora | Hasta que exista: «recaudo esperado» de guías |
| Margen comisionable y % | COM-02 con snapshot autorizado | Σ margen / ventas netas | Venta | Asesor, período | Líneas sin costo excluidas y contadas aparte |
| Ventas pendientes de costo | `costos_venta` faltante o `sin_costo` | Cantidad y valor | Venta | Período | — |
| Comisión estimada / habilitada / en liquidación / pagada | Derivado + líneas | Σ por estado (estados excluyentes) | Estimada: venta; habilitada: habilitación; liquidación/pagada: fecha de la liquidación | Asesor, quincena | Estados excluyentes: nunca sumar estimada con habilitada |
| Saldo por pagar al asesor | Líneas habilitadas + ajustes no pagados | Σ | Corte del día | Asesor | — |
| Devoluciones y ajustes | Líneas `ajuste` / anuladas | Σ y cantidad | Fecha del ajuste | Asesor, período | Devoluciones sin flujo RMA no aparecen |
| Metas comerciales | — | **No existen parámetros de metas** | — | — | No inventar; requiere decisión y tabla de metas |

---

## 7. Casos que el diseño debe resolver (resultado esperado; no ejecutados)

| # | Caso | Resultado esperado |
|---|---|---|
| 1 | Entregada (validada) y cobrada (verificada) completa | Líneas `habilitada` por las unidades del envío; entran a la quincena de habilitación |
| 2 | Entregada sin cobrar | `pendiente_cobro`; 0 habilitado |
| 3 | Cobrada sin entregar | `pendiente_entrega`; al validar → `habilitada` (orden indiferente) |
| 4 | Pago `sin_revisar` | `pendiente_cobro` hasta confirmar; si se rechaza, sigue pendiente |
| 5 | Cobro parcial | `pendiente_cobro` (COM-D-05: sin proporcional) |
| 6 | Varios envíos / facturas | Cada envío validado habilita **sus** unidades cuando el pedido está totalmente cobrado; el resto queda pendiente |
| 7 | Devolución parcial (antes de validar) | Las unidades devueltas no se habilitan (validación = entregadas − devueltas) |
| 7b | Devolución total | 0 unidades; `no_comisionable` con motivo |
| 8 | Anulación de validación | Sin liquidar: líneas del envío → `anulada` (evento). En liquidación cerrada o pagada: **ajuste** negativo y aviso en Logística |
| 9 | Reverso de cobro (PAG-I, cuando exista) | Igual que 8, con origen «pago reversado» |
| 10 | Comisión pagada y devolución posterior | Ajuste negativo en la siguiente quincena; si no hay más liquidaciones, saldo a cargo (COM-D-14) |
| 11 | Producto sin costo / costo cambiado después | Sin costo: `no_comisionable` («pendiente de costo»). Cambio posterior: no afecta (snapshot) |
| 12 | Líneas con utilidad + y − | Por unidad, sin neteo: la línea negativa aporta 0 |
| 13 | Venta empresa / sin asesor | `no_comisionable`; si se reasigna a asesor antes de habilitar, comisiona normalmente |
| 14 | Cambio o desactivación de asesor | Reasignación antes de habilitar: nuevo beneficiario. Después: ajuste (COM-D-09). Inactivo: conserva y cobra lo suyo |
| 15 | Operación repetida o simultánea | Clave idempotente + UNIQUE + bloqueo por pedido: una sola línea |
| 16 | Pedido histórico comisionado fuera del sistema | Antes de la fecha de inicio: nunca entra. Después: marca «pagada fuera» auditada para excluirlo |
| 17 | Recaudo en poder del domiciliario | Si P1 = A: `pendiente_recaudo` hasta la liquidación del recaudo; si P1 = B: habilitada al confirmar en Cartera |

---

## 8. Plan (máximo cuatro fases; backend y frontend juntos)

| Fase | Contenido | Criterios de aceptación manual (Camilo) |
|---|---|---|
| **C1 · Habilitación** | Contratos de lectura en Logística (validaciones; recaudo liquidado, si P1 = A); `EvaluarComisionPedidoService` idempotente; tablas `comision_lineas` y `comision_eventos`; disparos (validar/anular, confirmar/rechazar pago, comando de conciliación); bandeja admin «Comisiones» + drawer de detalle con evidencias; claves ACL `comisiones.calcular` | Validar y cobrar un pedido de asesor → aparece `habilitada` con el monto esperado. Cobrar antes de validar → mismo resultado. Doble clic o comando repetido → una sola línea. Pedido empresa / sin costo / muestra → motivo visible. Anular la validación sin liquidar → línea `anulada` con historial |
| **C2 · Liquidación y pago** | Liquidaciones quincenales (borrador → cerrada → pagada), pago con comprobante privado, «Mis comisiones» del asesor | Borrador solo con líneas habilitadas del asesor y la quincena; una línea no entra en dos liquidaciones; cerrada congela totales; pagada inmutable; el asesor ve solo lo suyo, sin costos |
| **C3 · Ajustes y correcciones** | Ajustes manuales auditados (positivo/negativo), ajuste automático por anulación posterior a la liquidación, pantalla de reasignación y selector «vender para» en Nueva venta (si P5), aviso en Logística antes de anular una validación comisionada | Anular una validación ya liquidada → ajuste negativo en la siguiente quincena; reasignar antes de habilitar cambia el beneficiario; después crea ajuste |
| **C4 · KPIs y cierre** | Panel de cumplimiento y rentabilidad (§6.2), exportación autorizada, correcciones de la revisión manual, actualización de docs | Totales del panel = suma de las líneas; estados no se suman entre sí; el asesor no ve márgenes |

---

## 9. Preguntas bloqueantes (prioridad)

| # | Pregunta | Recomendación | Ejemplo |
|---|---|---|---|
| **P1** | Para pagos **contra entrega**, ¿la comisión se habilita cuando el cliente pagó al domiciliario (Cartera confirmado) o cuando ByLopez recibió el dinero (recaudo liquidado en la cuenta de la transportadora)? | **A: cuando ByLopez lo recibió.** Mientras tanto, `pendiente_recaudo` | Pedido $100.000 COD, domiciliario cobra el 3, liquida a ByLopez el 20 → habilitada el 20, entra a la quincena 16–31 |
| **P2** | ¿Reconfirmas como vigentes COM-D-01…15 y D-COST (20 % sobre utilidad positiva por unidad, sin neteo, pago total verificado, quincenal)? | Sí, tal como están en el Doc. 04 | — |
| **P3** | ¿La habilitación es por **envío validado** (parcial) o solo cuando **todo el pedido** está validado? | Por envío, siempre que el pedido esté cobrado completo | 5 unidades, 3 entregadas y validadas, 2 pendientes, pagado todo → se habilitan 3 |
| **P4** | ¿Qué fecha fija el porcentaje y la regla: la **venta**, la validación o la habilitación? | **Venta** (el asesor sabe cuánto gana al vender) | Venta el 1 con 20 %; el 10 cambia a 18 %; se valida el 12 → 20 % |
| **P5** | Ventas creadas por un administrador para un asesor: ¿se agrega «Vender para» en Nueva venta (permiso `pedidos.assign`) y una pantalla de reasignación? ¿Quién puede? | Sí, ambas, solo Administración, con motivo | 17 pedidos de dev hoy quedarían sin comisión |
| **P6** | ¿El flete que paga ByLopez se descuenta de la base comisionable? | No por ahora (COM-D-01 excluye envío); medirlo en rentabilidad | Ver §2.4 (1.400 vs 200) |
| **P7** | ¿A qué quincena va una línea: fecha de habilitación o fecha de venta? | Fecha de **habilitación** | Venta el 28, habilitada el 3 → quincena 1–15 del mes siguiente |
| **P8** | Pedidos sin clasificación (tienda, panel): ¿comisionan? | No, hasta que alguien con permiso los clasifique como `venta_normal` | — |
| **P9** | ¿Existe costo 0 legítimo? | No por ahora; sin costo = no comisiona | — |
| **P10** | Devolución después de entregar (sin flujo RMA): ¿qué hacer mientras no exista? | Bloquear la habilitación si hay refund (`requiere_revision`); ajuste manual auditado | — |
| **P11** | Pago al asesor: ¿una liquidación = un pago, o pagos parciales? ¿Anticipos? | Un pago por liquidación; sin anticipos | — |
| **P12** | Fecha de inicio de comisiones y tratamiento de lo pagado fuera | Fecha de activación en producción; antes, nada; después, marca «pagada fuera» | — |
| **P13** | Registro y confirmación del cobro por la misma persona: ¿se acepta? | Aceptar para Administración; Bodega solo registra | — |

---

## 10. Primera tarea para Sonnet (cuando P1–P5 estén respondidas)

> Implementa la **fase C1** del `VentasAsesor/docs/21_AUDITORIA_FLUJO_COMISIONES_E2E.md` (§5, §6.1 bandeja y detalle,
> §8 C1), backend y frontend juntos, sin tocar `packages/Webkul/` ni la migración pendiente de cuentas de transportadora:
> 1. En Logística, contratos de solo lectura `ConsultaValidacionesEntrega` (unidades entregadas − devueltas por
>    `order_item_id` en envíos con validación vigente) y, si P1 = A, `RecaudoLiquidadoDePedido` (con un adaptador «nunca
>    liquidado» mientras no se aplique la migración de cuentas).
> 2. En VentasAsesor, migraciones `comision_lineas` / `comision_eventos` (decimal(14,2), UNIQUE vigente por
>    `(order_item_id, shipment_id)`, eventos append-only) y `EvaluarComisionPedidoService` idempotente con `brick/math`,
>    bloqueo por pedido y la elegibilidad del §5.3; la regla según P4.
> 3. Disparos: tras validar/anular validación, confirmar/rechazar pago y comando `ventas-asesor:comisiones:conciliar`.
> 4. Bandeja «Comisiones» (`comisiones.view_all`) y drawer de detalle con evidencias; claves ACL nuevas con
>    autorización en el servidor.
> 5. No crear ni ejecutar tests; `php -l` y Pint en lo tocado; actualizar Docs. 18 y 21 y `00_STATUS.md`.
