# 27 · Propuestas de política pendientes (comisiones y dinero de transportadoras)

**Estado: PROPUESTA. Nada de esto está implementado ni activado.** Cada punto sigue bloqueado en el sistema (la línea queda «política pendiente» y no se habilita) hasta una **aprobación específica** de Camilo Gaitán. Este documento solo da una recomendación concreta con ejemplo numérico y el efecto sobre comisiones **ya pagadas**, para que se pueda aprobar, cambiar o rechazar.

Fórmula aprobada (no cambia en ninguna propuesta): **(venta neta del producto − costo del producto) × porcentaje histórico**. Los gastos comerciales se consideran después, para rentabilidad. Todos los ejemplos usan: precio neto $10.000, costo $6.000 → base $4.000 por unidad, 10 %, es decir **$400 por unidad comisionable**.

Referencias: decisiones D3, D4, D5, D6, D10 y D12 del documento 23 (§ Decisiones pendientes). Lo que hace hoy el sistema está en la columna «Hoy».

---

## 1. Pedido mixto que cruza el umbral (D3)

**Problema.** El umbral mensual decide cuántas unidades del mes comisionan (las primeras N no comisionan). Si un pedido tiene prendas de bases distintas y el umbral se cruza *dentro* del pedido, hay que decidir **cuáles** unidades son las que comisionan.

**Ejemplo.** Umbral 10; la asesora lleva 7 unidades en el mes (faltan 3). Pedido nuevo: 4 u. de A (base $4.000) y 4 u. de B (base $6.000). Computan 8 → comisionan 5.

| Regla | A | B | Comisión del pedido |
|---|---|---|---|
| **Recomendada: proporcional a las unidades, resto mayor, desempate por ítem** | 3 | 2 | 3×400 + 2×600 = **$2.400** |
| Las 3 libres se consumen primero en el orden de las líneas (A, luego B) | 1 | 4 | 1×400 + 4×600 = $2.800 |
| Las 3 libres se consumen primero en lo de mayor base (B) | 4 | 1 | 4×400 + 1×600 = $2.200 |

**Recomendación:** proporcional. Es la única que no depende del orden en que se digitó el pedido ni favorece a una parte; queda en el medio del rango ($2.200–$2.800).

**Efecto sobre comisiones pagadas:** **ninguno.** El pedido mixto que cruza el umbral hoy está bloqueado (`politica_pendiente_pedido_mixto`), así que no hay nada habilitado ni pagado. Al aprobarla rige **hacia adelante** y libera solo líneas bloqueadas; no recalcula nada ya liquidado.

**Hoy:** se previsualiza el reparto proporcional pero la línea queda bloqueada.

## 2. Cancelación o devolución que desplaza el tramo (D5)

**Problema.** Si se cancela (o devuelve) mercancía de un pedido anterior, las unidades acumuladas bajan y un pedido posterior puede dejar de superar el umbral.

**Ejemplo.** Umbral 10. Pedido 1: 6 u.; pedido 2: 6 u. → acumulado 12, comisionan 2 (todas del pedido 2): 2×400 = **$800**. Se cancelan 2 u. del pedido 1 → acumulado 10 → el pedido 2 ya no supera el umbral: comisiona **0**.

**Recomendación (según en qué punto esté el pedido 2):**

| Estado de la comisión de $800 | Qué hacer |
|---|---|
| Aún no liquidada | Recalcular a $0 y dejar el evento en el historial. |
| Liquidada, sin pagar | Abrir una **incidencia**, retener los $800 y proponer un **ajuste de −$800** que administración aprueba. |
| Ya pagada | Proponer el ajuste de −$800; **si se aprueba, se compensa en la siguiente liquidación** de esa asesora. Nunca se reclama en efectivo ni se reescribe el abono ya hecho. |

**Efecto sobre comisiones pagadas:** la asesora cobraría **$800 menos** en su siguiente liquidación (tope: lo que se le pagó por esa línea). Si no hubiera más liquidaciones, el ajuste queda **pendiente a favor de ByLopez**, visible, sin cobro automático.

**Hoy:** antes de liquidar la línea conserva lo congelado y queda **en revisión** (no liquidable); ya liquidada abre incidencia y retiene lo no pagado. No hay ajuste automático.

## 3. Cambio de umbral a mitad de mes (D4)

**Problema.** El acuerdo de la asesora puede cambiar el umbral dentro del mes. ¿Cuál se usa para las ventas de ese mes?

**Ejemplo.** Umbral 10 el día 1; desde el día 15 pasa a 6. Ventas: 7 u. el día 5 y 5 u. el día 20 (12 en total).

| Regla | Comisionan | Comisión |
|---|---|---|
| **Recomendada: rige el umbral vigente al inicio del mes; el nuevo, desde el mes siguiente** | 12 − 10 = 2 | 2×400 = **$800** |
| El umbral nuevo aplica a todo el mes | 12 − 6 = 6 | 6×400 = $2.400 (+$1.600) |
| Cada tramo con su umbral y acumulado reiniciado | 7 < 10 → 0; 5 < 6 → 0 | $0 |

**Recomendación:** umbral del inicio del mes. Es predecible para la asesora, no obliga a recalcular nada que ya se pagó y evita premiar o castigar retroactivamente.

**Efecto sobre comisiones pagadas:** con la regla recomendada, **ninguno** (el cambio nunca toca el mes en curso). Con la segunda regla, si ya se pagaron $800 el día 25, se deberían **$1.600 más**; con un umbral nuevo *mayor* (p. ej. 15) habría que **recuperar** los $800 pagados. Por eso se desaconseja aplicar el cambio al mes en curso.

**Hoy:** el mes se marca (`politica_pendiente_umbral_cambio_mes`) y no se habilita.

## 4. Devolución posterior al pago de la comisión (D10, con D6)

**Problema.** El cliente devuelve mercancía **después** de entregada y de pagada la comisión del pedido.

**Ejemplo.** Pedido de 3 u. entregado, cobrado y comisionado: 3×400 = **$1.200**, ya pagado. El cliente devuelve 1 prenda y Bodega la **recibe**.

**Recomendación:**

1. **Solo cuenta la devolución RECIBIDA en bodega** (con su recepción confirmada). Una devolución apenas registrada o en tránsito no cambia nada (mismo criterio que ya usa el saldo del cliente y la decisión de cobro F6).
2. Se propone un **ajuste de −$400** (1 u. × $400) que administración aprueba; se **compensa en la siguiente liquidación**. Si no hay más liquidaciones queda pendiente a favor de ByLopez, sin cobro automático.
3. Las unidades devueltas **dejan de computar** para el umbral del mes de la venta; si eso desplaza el tramo, se aplica la regla del punto 2 (D5).
4. Pregunta abierta para Camilo: si la devolución es por **defecto de fábrica** (no por decisión del cliente), ¿la comisión se descuenta igual? Esta propuesta **no distingue** el motivo para no inventar una excepción.

**Efecto sobre comisiones pagadas:** la asesora cobra **$400 menos** en la siguiente liquidación; lo ya pagado no se reescribe. Con devolución total del pedido: −$1.200.

**Hoy:** el pedido queda con `devolucion_sin_politica`; si estaba liquidado abre una incidencia que retiene lo no pagado. No descuenta nada por sí solo.

---

## 5. Dinero de transportadoras sin cuenta corriente y compensación de fletes

### Cómo se acredita hoy (comportamiento verificado en el código)

- **Con cuenta corriente** (la transportadora liquida con ByLopez): el recaudo de cada guía nace del *cobro*, entra al libro de la transportadora y se concilia en una **liquidación cerrada** (efectivo + compensado contra fletes). Solo entonces cuenta como dinero recibido.
- **Sin cuenta corriente:** no existe un registro que pruebe que el dinero llegó. El pedido queda bloqueado con `recaudo_sin_cuenta_corriente` y **la comisión no se habilita**. No se resuelve cambiando la bandera de la transportadora: eso no es una prueba de dinero. Los recorridos de la demo no lo hacen para habilitar comisiones (la bandera de Inter se activa en el recorrido D2 como parte del escenario de «transportadora con cuenta», y el recorrido D1 demuestra el bloqueo con Servientrega, sin cuenta).

### Propuesta (sin implementar)

**Remesa manual con soporte.** Un movimiento de ingreso registrado por administración (quién consignó, fecha, valor, comprobante) enlazado a las guías que cubre. Mientras no exista, la comisión sigue bloqueada. Es la forma de dar prueba de dinero a transportadoras sin cuenta corriente sin tocar su configuración. Pide una decisión de negocio (quién registra, qué soporte exige) y un desarrollo propio: **no se hizo en esta jornada**.

### Compensación de fletes (D12) — sin aprobación documentada

**Ejemplo.** Recaudo $100.000; fletes $120.000 → ByLopez paga $20.000 y el recaudo queda saldado en una liquidación cerrada y conciliada.

| Interpretación | Resultado |
|---|---|
| **Recomendada:** el recaudo **compensado** cuenta como dinero recibido (la deuda de la transportadora con ByLopez quedó extinguida contra una deuda real de ByLopez) | La comisión puede habilitarse |
| Solo cuenta el **efectivo** que ByLopez recibió | En el ejemplo ($0 de efectivo) la comisión no se habilitaría aunque el pedido esté pagado y entregado |

**Efecto sobre comisiones pagadas:** ninguno si se aprueba lo que ya está implementado (la regla ya funciona así). Si se decide la segunda interpretación, las comisiones **ya habilitadas** por compensación deberían revisarse una por una (incidencia) antes de seguir liquidándolas.

**Estado:** el documento 23 (§7) marca D12 como «nuevo, confirmar»; **no hay aprobación documentada**. Por eso los recorridos B y D2 de la demo (flete compensado) se clasifican como **escenario de prueba de una regla pendiente de confirmar**, no como validación de una política aprobada.

---

## 6. Resumen para decidir

| # | Decisión | Recomendación | Pagado cambia |
|---|---|---|---|
| D3 | Pedido mixto que cruza el umbral | Proporcional, resto mayor, desempate por ítem | No |
| D5 | Tramo desplazado por cancelación/devolución | Recalcular antes de liquidar; incidencia + ajuste aprobado compensado después | Sí, solo vía ajuste aprobado en la siguiente liquidación |
| D4 | Umbral cambia a mitad de mes | Rige el del inicio del mes | No |
| D10/D6 | Devolución tras el pago | Solo devolución recibida; ajuste aprobado compensado después | Sí, solo vía ajuste aprobado |
| D12 | Compensado = dinero recibido | Sí (ya implementado) | No |
| — | Transportadora sin cuenta corriente | Remesa manual con soporte (por diseñar) | No |

Mientras no haya una aprobación específica de cada punto, **el sistema mantiene los bloqueos** y no habilita ninguna comisión afectada.
