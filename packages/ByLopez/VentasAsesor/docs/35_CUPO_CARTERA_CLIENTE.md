# 35 · Cupo / límite de cartera por cliente

Estado: **en desarrollo en la rama `feature/cupo-cartera`** (worktree aislado `lpz-cupo`, base `lpz_cf_cupo`). Sin commit, sin push, sin producción. El bloqueo viene **apagado** (`VENTASASESOR_CUPO_ACTIVO`, por defecto `false`), igual que `rentabilidad.bloqueo_activo`.

## 1. Qué es (y qué no es)

`cupo_disponible = cupo_autorizado − exposicion_vigente`.

- **Cupo autorizado**: política persistida por un administrador (monto + estado `activo|bloqueado`, con historial).
- **Exposición vigente**: lo que el cliente **debe hoy** según Cartera. Siempre **derivada**; no existe un «saldo utilizado» guardado.
- **No es** el saldo a favor de D10 (crédito que ByLopez le debe al cliente) ni un plazo/vencimiento. No hay concepto de vencimiento en el sistema: V1 = límite + exposición + disponible + bloqueo + alertas.

Ejemplo: cupo $2.000.000, exposición $1.350.000 → disponible $650.000. Una obligación nueva de $800.000 se rechaza: «Excede el cupo en $150.000».

## 2. Fuente única y fórmula

La exposición sale de **`CarteraConsultaService::saldosDePedidos()`** (la misma fuente de «Cartera»). No hay una fórmula de deuda paralela: `CupoCarteraClienteService` toma los componentes que Cartera ya calcula por pedido (`SaldoPedido`) y aplica **una sola decisión propia**, declarada abajo.

Por pedido que **está en Cartera** (`enCartera = true`: Cartera activa, estado no cancelado/cerrado/fraude, `created_at ≥ fecha de corte`):

```
totalOperativo   = total − devuelto (C5) − cancelado (Logística) − ajustado (D10, ajustes activos)     ← Cartera
verificado       = pagos confirmados (incluye el crédito D10 YA aplicado) + Transactions ajenas         ← Cartera
exposicion_pedido = max(0, totalOperativo − verificado)
exposicion_cliente = Σ exposicion_pedido de todos sus pedidos en Cartera
disponible        = max(0, cupo_autorizado − exposicion_cliente)         (nunca negativo)
exceso            = max(0, exposicion_cliente − cupo_autorizado)
utilizacion       = exposicion_cliente / cupo_autorizado                 (con cupo > 0)
```

**La única diferencia con el «saldo» de Cartera** es el pago `sin_revisar`: Cartera lo resta del saldo (informa «ya se envió algo»); el cupo **no** (un comprobante sin verificar no libera crédito). Relación comprobable: `exposicion_pedido = saldo_pedido + min(sin_revisar, max(0, totalOperativo − verificado))`. Si no hay `sin_revisar`, exposición = saldo.

Pedidos con motivo de revisión (p. ej. Refund nativo, P-10): **se cuentan** y se marcan «requiere revisión» (falla cerrado: ante la duda no se libera crédito). Cartera los muestra aparte; el cupo los suma porque es un control de riesgo.

Los pedidos **fuera de Cartera** (históricos anteriores al corte, cancelados, cerrados, fraude) **no consumen**. Si Cartera está **inactiva** (sin fecha de corte) la exposición no es calculable: con el bloqueo activo se **falla cerrado** (`cupo_no_verificable`).

### Estados (derivados, nunca guardados)

Orden de precedencia: `bloqueado` > `excedido` > `agotado` > `cerca` > `activo` > `sin_cupo`.

| Estado | Condición |
|---|---|
| `sin_cupo` | sin política, o cupo 0 sin exposición ni bloqueo |
| `activo` | cupo > 0 y utilización < umbral (80 %) |
| `cerca` | utilización ≥ 80 % (umbral configurable `cupo.umbral_alerta_pct`) y disponible > 0 |
| `agotado` | exposición = cupo (disponible 0, sin exceso) |
| `excedido` | exposición > cupo (se muestra «CUPO EXCEDIDO EN $X» y Disponible $0) |
| `bloqueado` | política `bloqueado`, **independiente del monto** |

## 3. Qué consume, qué libera (demostrado en pruebas, ver §10)

| Hecho | Efecto en la exposición | De dónde sale (Cartera) |
|---|---|---|
| Pedido confirmado con saldo pendiente | **consume** `totalOperativo − verificado` | `orders.base_grand_total` |
| Flete / domicilio cobrado en el pedido | consume (forma parte del total) | `base_grand_total` |
| Pago confirmado (transferencia, efectivo, contra entrega cobrado) | **libera** | `pagos.estado = confirmado` + aplicación activa |
| Crédito D10 **aplicado** (`credito_cliente`, confirmado) | **libera** (compensa la obligación) | `verificado` incluye la compensación |
| Crédito D10 **disponible aún sin aplicar** | no libera | no existe pago/aplicación |
| Devolución recibida en bodega (C5) | libera | `devuelto` |
| Cancelación del pendiente (Logística) / ajuste D10 autorizado (condonación) | libera | `cancelado`, `ajustado` |
| Pedido cancelado / cerrado / fraude | deja de consumir | fuera de Cartera |
| Pago `sin_revisar` | **no libera** | `sinRevisar` se ignora |
| Pago rechazado | no libera (nunca contó) | |
| Pago confirmado y luego **reversado** | vuelve a consumir | deja de ser `verificado` |
| Promesa de pago, comprobante sin verificar, captura manual sin confirmar | no liberan | no son pago |
| Cotización | no consume ni reserva | no es pedido |
| Venta en curso (borrador sin confirmar) | no consume | no es pedido |
| Saldo a favor / crédito del cliente | no es deuda | no entra |

## 4. C6 / contra entrega (cuándo deja de consumir)

El cliente deja de deber en el instante en que el **cobro queda confirmado** en Cartera (pago confirmado con `guia_id`). Es el mismo hecho que baja el saldo de Cartera, así que **cupo y Cartera no pueden discrepar**: ambos usan `verificado`.

- La **custodia** del transportador (cobrado − conciliado, `CajaPedidoService`) es dinero **que el transportador debe a ByLopez**: C6 lo dice expresamente («NUNCA es deuda del cliente»). **No consume el cupo del cliente.** El riesgo se traslada a otra contraparte (Cuentas de transportadoras), que es otro límite y queda fuera de V1.
- La conciliación / liquidación / recepción en ByLopez **no cambia** la exposición del cliente (solo la cuenta del transportador).
- Contra entrega **no cobrada**: el pedido tiene saldo → consume desde que se confirma hasta que se cobra (o se cancela / devuelve).
- La custodia se muestra como dato **informativo** por pedido; no hay interruptor para sumarla (mezclaría la deuda del cliente con la del transportador, contra C6).

## 5. D10

- El saldo a favor no se toca ni se reinterpreta.
- Crédito **ya aplicado** a un pedido es un pago `credito_cliente` confirmado: baja `exposicion_pedido` por el flujo normal de Cartera.
- Crédito **disponible sin aplicar** no se descuenta: aplicarlo es un paso explícito posterior a crear el pedido (no es automático).

## 6. `nueva_exposicion` en la creación (flujo real)

En el flujo real **no existe dinero ni crédito aplicado al confirmar**: Nueva venta solo elige el *método* de pago; los pagos y la aplicación de crédito son pasos **posteriores** al pedido. Por eso, al confirmar:

`nueva_exposicion = base_grand_total del pedido a crear` (ya neto de descuentos y con flete/domicilio incluidos).

| Escenario | Qué entra en la creación | Cuándo baja después |
|---|---|---|
| Sin pago | total completo | al confirmarse el pago |
| Pago parcial / total | total completo (el pago es posterior) | pago confirmado |
| D10 | total completo (no se descuenta crédito no aplicado) | al aplicar el crédito |
| Contra entrega | total completo | cobro confirmado |
| Flete / domicilio | dentro del total | condonación D10 autorizada |
| Descuento | ya reflejado en el total | — |
| Total 0 (muestra, garantía) | 0 → siempre permitido | — |

Una venta **ya pagada y verificada** no suma exposición **una vez que el pago está confirmado** (su exposición es 0: prueba CUPO-16). Pero el pago no puede existir antes que el pedido, así que la creación de una venta de contado a un cliente **sin cupo** exige el camino excepcional (§8) o que un administrador le asigne cupo. No se acepta `sin_revisar` como pagado ni se abre un atajo.

## 7. Bloqueo transaccional (concurrencia)

Punto único: el **cerco de creación de pedidos** (`CercoCreacionPedido`, primer listener de `checkout.order.save.before`, dentro de la transacción de `OrderRepository`), que cubre tienda, panel, Nueva venta y autoservicio. Orden de bloqueos, global: **borrador → products (ascendente) → fila del cliente (`customers`)**.

1. Con el bloqueo apagado: no se consulta nada.
2. `customers … FOR UPDATE` del cliente del pedido: serializa **toda** confirmación del mismo cliente y toda edición de su cupo (que toma el mismo bloqueo), exista o no una fila de cupo.
3. Lecturas **después** del bloqueo: la instantánea REPEATABLE READ nace en la primera lectura no bloqueante, que ocurre tras obtener el bloqueo, por lo que ve lo confirmado por la venta anterior. Prueba real con dos procesos (§10).
4. Se recalcula la exposición, se suma la `nueva_exposicion` y solo se continúa si cabe.

Residual conocido: un pago **reversado** que se confirme entre la lectura y el commit del pedido podría dejar la exposición por encima del cupo; no se serializa contra pagos porque un pago solo **libera** (el reverso es una corrección administrativa posterior, que produce `excedido` y bloquea las ventas nuevas).

## 8. Excepción «Autorizar exceso»

(Se completa al cierre con la decisión de costo real.)

## 9. Datos propios (migraciones aditivas, sin backfill)

- `bylopez_va_clientes_cupo_cartera`: **una fila por cliente** (política vigente): `customer_id` (único, FK `customers` RESTRICT), `cupo_autorizado`, `estado` (`activo|bloqueado`), `vigencia_desde`, `motivo`, `creado_por`, `actualizado_por`, timestamps. CHECK `cupo_autorizado ≥ 0`.
- `bylopez_va_clientes_cupo_cartera_historial`: **solo de adición**, nunca se borra: `customer_id`, `cupo_anterior/nuevo`, `estado_anterior/nuevo`, `motivo` (obligatorio), `actor_admin_id`, `exposicion_al_cambiar` (foto informativa), `created_at`. El modelo rechaza `update()` y `delete()`.
- Quitar el cupo = cupo 0 o `bloqueado`; la historia no se toca.
- **No** hay columna de saldo utilizado.

## 10. Permisos (ACL)

Hojas: `ventas_asesor.cartera.cupo.ver` · `.editar` · `.historial` · `.autorizar_exceso` (la agrupadora no autoriza nada). **Editar** y **autorizar exceso** exigen además ser el **administrador autorizado** (`esAdministradorAutorizado`, mismo criterio que D10: un rol `permission_type = all` que no sea el administrador **no** puede). Ver/historial: la clave; un rol `all` que no sea el administrador autorizado tampoco ve. Las cifras detalladas por pedido solo con alcance de Cartera «todos».

Asignación recomendada en producción: ADMIN → todas · ASESOR VENTAS → `cupo.ver` · BODEGA → ninguna.

## 11. Mora / plazo (análisis, no implementado)

No existen plazos ni fechas de vencimiento por pedido: «vencido» **no es calculable de forma fiable**, así que V1 solo muestra la **antigüedad real** del pedido más antiguo con saldo (dato objetivo). Recomendación para una fase posterior: definir primero un plazo por cliente o por método de pago (fecha de vencimiento persistida en el pedido o derivada de una regla oficial), y solo entonces añadir la política «no vender a crédito con saldo vencido mayor a X días», que consultaría la misma fuente de exposición (la antigüedad por pedido ya viene en el detalle). Mezclarlo con el cupo en V1 daría un número engañoso.

## 12. Riesgos y límites

(Se completa con la evidencia al cierre.)
