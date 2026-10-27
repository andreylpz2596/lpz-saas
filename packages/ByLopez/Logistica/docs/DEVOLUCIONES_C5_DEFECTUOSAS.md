# Devoluciones: efecto en Cartera (C5) e inventario NO VENDIBLE «Defectuosas»

Fecha: 2026-10-24 · Estado: implementado en dev (sin commit) · Tests: `Log12DevolucionSaldoDefectuosasTest` (C5-01, C5-02, C5-03).

## 1. Regla aprobada

```
Registrar devolución  → NO afecta Cartera
Bodega recibe y confirma físicamente → ENTONCES nace el efecto financiero (misma transacción de la recepción)
```

- **Estado físico ≠ efecto comercial.** Lo RECIBIDO (aptas **y** defectuosas) deja de ser exigible al cliente. Los **faltantes no** (no se condona lo que ByLopez no recibió).
- El pedido original **no se modifica** (total, líneas, precios, descuentos, impuestos, cantidades).
- El valor sale del **precio histórico de la línea del pedido**: `(base_total − base_discount_amount + base_tax_amount) / qty_ordered × recibidas` (`ValorDevolucionesEnvio::unitarioDeLinea`, la misma fórmula de las devoluciones y de C1; en configurables, la línea padre).

## 2. Causa de C5

`CarteraConsultaService::componer()` calculaba `saldo = max(0, total − aplicado)` y no conocía las devoluciones recibidas: una prenda que Bodega ya había recibido seguía apareciendo como deuda del cliente (#87 quedó con $ 34.500 «pendientes»).

## 3. Arquitectura

| Pieza | Qué hace |
|---|---|
| `bylopez_lg_recepcion_devolucion_items.valor_unitario / valor_devuelto` | **Ajuste trazable**: snapshot del valor histórico de lo recibido, escrito en la misma transacción de la recepción. NULL en recepciones anteriores (sin backfill: no afectan Cartera). |
| `CarteraConsultaService::devueltoDePedidos()` | Una consulta agrupada por lote: Σ `valor_devuelto` de recepciones **confirmadas**. Anular la recepción revierte el efecto solo. No hay segunda escritura que se pueda perder ni duplicar. |
| `SaldoPedido` | Agrega `devuelto`, `unidadesDevueltas`, `totalOperativo` (total − devuelto) y `saldoAFavor`. `saldo = max(0, totalOperativo − aplicado)`. |
| Idempotencia | Una recepción vigente por devolución (UNIQUE), `idempotency_key` + `huella`, clave determinista por línea en el libro de inventario. Reintentar no duplica stock, defectuosas, reducción ni saldo a favor. |

### Ejemplos (pedido $ 100.000 salvo indicación)

| Caso | Pagado | Devolución confirmada | Saldo por cobrar | Saldo a favor |
|---|---|---|---|---|
| Debe | 40.000 | 20.000 | **40.000** | 0 |
| No había pagado (pedido 80.000) | 0 | 24.000 | **56.000** | 0 |
| Pagó todo | 100.000 | 20.000 | **0** | **20.000** |
| Pago parcial mayor que la nueva obligación | 90.000 | 20.000 | **0** | **10.000** |

`saldoAFavor = min(devuelto, max(0, aplicado − totalOperativo))`. Solo identifica el valor: **no** reembolsa, no cruza con otros pedidos y no crea pagos, aplicaciones, transacciones ni Refund (D10).

### Integraciones mínimas (sin reescribir otros módulos)

- **Una sola fuente de saldo:** `componer()`. Pedidos, Cartera del cliente, registro de pagos (tope del monto), bandeja y `SugiereRecaudoDesdeCartera` leen el mismo `saldo`.
- **C1 (recaudo por guía):** `RecaudoPorGuia` descuenta del recaudo comprometido de cada guía lo que Bodega ya recibió devuelto de ella; si no, `exigible` (que ya baja por C5) se descontaría dos veces. Las guías, su suma máxima y el flete no cambian.
- **Comisiones:** `ElegibilidadComisionService` trata una devolución recibida como `devolucion_sin_politica` (igual que un Refund): sin esto, el saldo en $ 0 habilitaría comisión sobre mercancía devuelta. Es una protección, no una política (D10).

## 4. Inventario NO VENDIBLE «Defectuosas»

- **Dónde:** `bylopez_lg_unidades_defectuosas` (no una bodega artificial). Existen físicamente, son de ByLopez, **no suman a `product_inventories`** (stock vendible) y no se pierden. Una fila por grupo de unidades de una línea recibida (motivo + origen + detalle); la suma de `cantidad` por línea = `novedad`.
- **Recepción:** por línea, recibidas / aptas / defectuosas (novedad) / faltantes (CHECK: `aptas + novedad = recibidas`, `faltantes = esperadas − recibidas`). Solo las aptas reingresan.
- **Motivo del defecto** (catálogo `bylopez_lg_catalogo_defectos`, tipo `motivo`): mancha, suciedad, costura, rota, defecto de tela, estampado / personalización, color afectado, talla / etiqueta, otro (**exige descripción**).
- **Origen probable** (mismo catálogo, tipo `origen`; **motivo ≠ responsabilidad**): por determinar (predeterminado), cliente, transporte, confección, corte, tela / proveedor, bodega, otro. Bodega no está obligada a acusar a nadie.
- **Catálogo parametrizable:** el código es estable; nombre, orden y `activo` se cambian sin migrar. Lo histórico conserva su código aunque se desactive.
- **Observación opcional** por línea y detalle por grupo.
- **Estado:** `pendiente_revision` (inicial). La tabla ya admite `recuperada` y `perdida` (CHECK); **moverlas no está construido**.
- **Compatibilidad:** un llamador sin estructura (`defectos` ausente) registra la novedad como motivo «otro / por determinar» con el texto de la línea como detalle: nada se pierde.
- **Consulta:** Logística › Entregas › pestaña **Defectuosas** (`?vista=defectuosas`, misma clave ACL de lectura de Entregas). Fecha, pedido, devolución/recepción, producto, variante, color, talla, cantidad, motivo, origen, observación, estado y quién recibió; filtros por fecha, motivo, origen y estado; KPI de unidades pendientes. Solo cuenta recepciones confirmadas.
- **Base para pérdidas futuras:** se guardan variante física, pedido, envío, bodega, motivo, origen, fecha y actor: defectuosas por mes / referencia / variante / origen / motivo, recuperadas, pérdida y su costo se pueden calcular después sin perder datos. **No hay movimientos financieros por pérdida.**

## 5. Visual: «Saldo real por cobrar» (detalle del pedido › Pago / Cartera)

El número protagonista es siempre lo que realmente falta cobrar; el total histórico es dato secundario (para que nadie vuelva a cobrar el total original):

```
SALDO REAL POR COBRAR
$ 19.500
Pendiente de pago — Debe $ 19.500
Pagado: $ 30.000 · Devoluciones confirmadas: −$ 14.600
Total original del pedido: $ 64.100 · Mercancía devuelta: 2 unidades · $ 14.600
```

Con saldo a favor: «Saldo por cobrar $ 0» + «Saldo a favor — $ X» (nunca «Debe −X»). El formulario de recepción pide el motivo del defecto (y origen) por línea y no deja confirmar sin clasificarlo.

## 6. Fuera de alcance / pendiente

- **C6** (dinero cobrado por el domiciliario → custodia → liquidación → Cartera): no se tocó. El techo de C1 sigue dependiendo de que el cobro del transportador use el medio `contraentrega_recaudo`.
- **D10** (qué se hace con el saldo a favor: reembolso, nota crédito, aplicación a otra compra) y la política de comisión sobre mercancía devuelta.
- **Gestión de defectuosas:** pasar a `recuperada` / `perdida` (y reingresar lo recuperado al stock vendible), costo económico de la pérdida, reportes analíticos.
- Las recepciones anteriores a esta migración no reducen Cartera (valor NULL). Reevaluar si se quiere regularizarlas es decisión aparte.
- **Devolución total (cierre C5, 2026-10-24):** el estado se DERIVA, no se guarda ni se toca `orders.status`. `SaldoPedido::devolucionTotal()` = hay devuelto y `totalOperativo ≤ 0`. Sin pagos el estado de pago es `devuelto` («Devolución total», ya no «Pendiente») y el bloque dice «DEVOLUCIÓN TOTAL — sin saldo por cobrar»; pagado y devuelto por completo sigue «Pagado» con «Saldo a favor» + «Devolución total confirmada por Bodega. No se ha reembolsado dinero». No se crea Refund: un Refund nativo reingresaría el stock otra vez (incluidas las defectuosas), movería `qty_refunded`/`base_grand_total_refunded`, cerraría el pedido (`closed`) y activaría correos y el bloqueo de comisión por reembolso.
- **Flete:** el total del pedido incluye el domicilio y la mercancía devuelta no lo incluye; un pedido con flete devuelto por completo queda con saldo = flete y NO es «devolución total» (decisión de negocio pendiente para D10).
