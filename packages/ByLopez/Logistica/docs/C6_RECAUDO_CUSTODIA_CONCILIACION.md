# C6 — Recaudo real → custodia del transportador → liquidación → conciliación con ByLopez

Fecha: 2026-10-26 · Estado: implementado en dev (sin commit) · Tests: `Log13C6RecaudoCustodiaConciliacionTest` (C6-01 a C6-04).

## 1. Los conceptos (no se mezclan)

| Concepto | Qué es | De dónde sale |
|---|---|---|
| **Saldo del cliente** | Lo que el cliente todavía debe | Cartera / C5 (`CarteraConsultaService`) — C6 no lo toca nunca |
| **Esperado** | Lo que C1 esperaba cobrar por la guía. Expectativa y techo, **no es dinero** | `bylopez_lg_guias.recaudo_esperado` |
| **Cobrado** | Lo que el cliente realmente pagó al transportador | pago confirmado con `guia_id` (`bylopez_va_pagos`) |
| **En custodia** | Cobrado − conciliado: dinero del cliente aún bajo responsabilidad del transportador | derivado |
| **Efectivo** | Dinero que entró (o salió) de caja/banco de ByLopez en una liquidación | `bylopez_lg_conciliaciones_movimiento.efectivo` |
| **Compensado** | Recaudo satisfecho contra servicios/fletes que ByLopez debía al transportador. **Nunca se suma a caja** | `...conciliaciones_movimiento.compensado` |
| **Conciliado** | Efectivo + compensado aplicado al recaudo | derivado |
| **Diferencia del transportador** | Lo cobrado al cliente que aún no se concilió con ByLopez. **Nunca es deuda del cliente** | derivado (parte de la custodia que ya pasó por una liquidación cerrada) |

*Entrega ≠ cobro. Cobro ≠ dinero recibido por ByLopez. Liquidación cerrada ≠ recaudo conciliado.*

## 2. Arquitectura

1. **Pago ↔ guía.** `bylopez_va_pagos.guia_id` (nullable, FK, índice). El envío se deriva (guía ↔ shipment es 1:1). Los pagos normales (transferencia, efectivo directo) lo dejan NULL. `RegistrarPagoService` exige que la guía sea del pedido; el valor entra en la huella de idempotencia solo cuando existe.
2. **El recaudo nace del cobro.** Al **confirmar** un pago con guía de cuenta corriente, `ConfirmarPagoService` llama (misma transacción) a `RecaudoDesdeCobro::reconocer()`: movimiento `recaudo` (+) por lo **cobrado** (suma de las aplicaciones activas), `origen_tipo = 'cobro'`, `origen_id = pago_id`, clave `cobro:{pago}:recaudo` (UNIQUE). No toca Cartera.
3. **Cobro local.** `CobrarEntregaService` (drawer de Entregas) resuelve la guía del envío y la propaga: `GestionaCobroPedido::cobrar(..., guiaId)` → `CobroViaCartera` → `RegistrarPagoService` (pago con `guia_id`).
4. **Cobro reportado externo.** `CobroReportadoService` (drawer de Cuentas › «Cobro reportado», solo quien liquida): guía de la cuenta + importe + fecha + referencia → delega en `CobrarEntregaService`. Es el mismo hecho que un cobro en la entrega; **no** es una liquidación (otro hecho, otro flujo). Inicialmente manual; luego podrá alimentarse por importación o API sin cambiar esto.
5. **Conciliación.** Tabla `bylopez_lg_conciliaciones_movimiento` (`liquidacion_id`, `movimiento_id`, `efectivo`, `compensado`, `origen propuesta|manual`, `motivo`, `actor_admin_id`). `UNIQUE(liquidacion_id, movimiento_id)`: no se duplica dentro de una liquidación, pero un movimiento **sí puede conciliarse en varias liquidaciones** (parcial → resto después). La suma histórica nunca supera `|importe|` (se verifica bajo bloqueo al escribir). Inmutable (`REGISTRO_INMUTABLE`). Pedido, guía y transportador se derivan del movimiento.
6. **`CajaPedidoService`** (VentasAsesor): lectura por lote, 3 consultas agrupadas sin importar cuántos pedidos (2 si ninguno tiene recaudo C6).

## 3. Transición legado / C6 (regla inequívoca)

- Un movimiento de recaudo es **C6** si su `origen_tipo = 'cobro'`; es **legado** si es `envio` o `revision`.
- Una guía es **legada para siempre** si ya tiene algún recaudo `envio`/`revision`. Si no, es **C6** cuando existe `LOGISTICA_RECAUDO_DESDE_COBRO` (fecha, `config('logistica.cuentas.recaudo_desde_cobro_desde')`) y la guía se creó en esa fecha o después (`ModoRecaudo`). **Sin fecha, todo sigue como antes de C6.**
- En una guía C6, `GeneradorMovimientosCuenta` **no genera recaudo** desde lo esperado (ni lo pone «en revisión»); el servicio (flete) no cambia.
- Sin backfill: nada histórico se reinterpreta ni se vincula. Los pagos de custodia anteriores sin guía se muestran como «histórico pendiente de vínculo» y la comisión de esas guías conserva la lógica anterior.

## 4. Liquidación (algoritmo)

1. Bloqueo e idempotencia como antes (`idempotency_key` + huella; la huella incluye la reasignación manual).
2. **Hojas** conciliables: cada movimiento abierto (recaudo, servicio, costo de devolución, ajuste); un `diferencia:apertura` se **expande** a las hojas de la liquidación que lo originó que siguen pendientes (la diferencia queda ligada a sus guías/pedidos) solo si la suma cuadra con el importe; si no (liquidación anterior a C6 o entrega de más) la apertura es su propia hoja.
3. **Compensación propuesta:** cada servicio/crédito se salda primero contra el recaudo de **su misma guía**, luego FIFO (`fecha_operativa`, `id`). Total = mín(Σ obligaciones, Σ créditos).
4. **Efectivo:** lo entregado se asigna FIFO a lo que falte, sin exceder el pendiente (si ByLopez paga, a sus créditos).
5. **Pendiente del transportador** = lo que falta. FIFO solo decide a qué recaudos queda atribuido técnicamente; **no reabre Cartera ni es deuda del cliente**.
6. **Reasignación manual** (solo antes de confirmar): motivo obligatorio, mismos totales (efectivo y compensado), cada fila ≤ restante; origen `manual`. Después no se edita.
7. El libro se escribe como siempre (`pago_liquidacion` = −efectivo, diferencia cierre/apertura; la liquidación suma 0) + las filas de conciliación. `resumen.conciliacion` guarda los totales (efectivo, compensado, conciliado, pendiente).

## 5. Comisión

`ElegibilidadComisionService::dinero()`: si el recaudo de la guía nació de un cobro (movimientos `cobro`), la prueba es **conciliado ≥ cobrado** (efectivo + compensación válida). Motivos: `recaudo_en_custodia` (sin liquidar) y `recaudo_pendiente_de_conciliar` (liquidado con faltante: bloquea solo los recaudos atribuidos al pendiente, no toda la liquidación). Una guía C6 sin cobro del transportador no genera motivo de custodia (el pago lo cubre Cartera). El resto de candados no cambia (entrega validada, Cartera sin saldo, devolución, umbral, margen). La conciliación dispara la reevaluación automática (`ConciliacionMovimiento` en `SolicitarReevaluacionComision`).

## 6. C5 + C6 / flete

Independientes: C5 cambia la **obligación** del cliente; C6 sigue el **dinero**. Si el cliente pagó 79.000 al transportador y luego se confirma una devolución de 20.000, Cartera muestra saldo $0 y saldo a favor $20.000, y la custodia sigue en 79.000 hasta liquidar. El flete no se condona (D10).

## 7. Idempotencia e inmutabilidad

| Riesgo | Protección |
|---|---|
| Doble pago | `UNIQUE(mecanismo, idempotency_key)` |
| Doble recaudo | clave `cobro:{pago}:recaudo` UNIQUE + el pago solo se confirma una vez |
| Doble liquidación | `idempotency_key` + huella; movimientos `FOR UPDATE`; ya liquidados → `movimientos_ya_liquidados` |
| Doble conciliación | `UNIQUE(liquidacion_id, movimiento_id)` + Σ ≤ \|importe\| verificado bajo bloqueo |
| Doble compensación | cada crédito se concilia una sola vez por el mismo UNIQUE y la verificación de Σ |
| Doble reevaluación | cola de reevaluación existente con deduplicación |
| Edición | movimientos y conciliaciones inmutables; corregir = ajuste/reverso/nueva operación |

**Anular un cobro ya conciliado:** hoy un pago confirmado no tiene reverso en Cartera (`reversado` es capacidad futura) y el rechazo solo aplica a pagos sin revisar (antes de existir el recaudo). `RecaudoDesdeCobro::cobroConciliado()` queda como guarda para cuando exista el reverso: mientras sea verdadero se exige una corrección financiera trazable.

## 8. `guias.recaudo_estado`

Sigue **sin ser fuente de verdad**: nunca se actualizó (todas `pendiente_liquidar`) y como caché sería frágil. El estado del recaudo sale de `CajaPedidoService` y de las conciliaciones. La columna no se elimina ni se usa.

## 9. Migraciones

- `VentasAsesor/…/2026_10_26_000001_add_guia_id_to_bylopez_va_pagos_table.php`
- `Logistica/…/2026_10_26_100018_c6_origen_cobro_y_conciliaciones_movimiento.php` (CHECK `origen_tipo` + `cobro`; tabla de conciliaciones)

Aditivas, sin backfill.

## 10. Fuera de alcance

D10 (destino del saldo a favor, política de flete), integración API de 99envíos, importación masiva de reportes, módulo de correcciones financieras, vincular pagos históricos a guías.
