# 28 · D10 V1 — Saldo a favor (crédito) del cliente

Estado: implementado en **dev/QA** (`lpz_saas`, `lpz_saas_jornada` y `lpz_saas_testing`); **no** en producción, sin backfill, sin commit. Contrato técnico: spike PAG-I (aprobado). Migraciones aplicadas en `lpz_saas` el 2026-11-03 (lote 77, solo estas dos, por ruta de archivo).

## Fuentes del crédito en V1 (cierre 2026-11-03)

Solo generan crédito utilizable: **(1)** devoluciones físicas confirmadas y soportadas por C5 y **(2)** ajustes económicos expresamente autorizados (condonación de flete). **La cancelación de un pedido pagado NO es fuente en V1** (V2): `cancelado` (cancelación del pendiente, `bylopez_lg_cancelaciones`) sigue reduciendo el *total operativo* de Cartera —semántica heredada que no se toca— pero ya no entra en la generación del crédito. Además, `CancelarPendienteService` ya rechaza cancelar con pagos (`cancelacion_con_pagos`). Prueba: `D10ComisionesD5D6Test` › «D10-fuentes».

## Principio

`credito_cliente` **satisface una obligación, no es dinero nuevo**. Pedido B = 50.000, crédito 20.000, transferencia 30.000 → obligación satisfecha 50.000 · dinero nuevo 30.000 · crédito consumido 20.000 · recaudo/custodia/C6 por el crédito = 0.

## Cálculo (derivado; solo el consumo se persiste)

```
economico  = min(devuelto + ajustado, max(0, verificado − totalOperativo))      (totalOperativo = total − devuelto − cancelado − ajustado)
recibido   = pagos confirmados que no son crédito ni están en custodia sin conciliar + Transactions ajenas + custodia ya conciliada (efectivo + compensado)
generado   = min(economico, recibido)            ← solo dinero verificado y RECIBIDO genera crédito utilizable
usado      = Σ(aplicación + reembolso) sin reverso   (ledger inmutable)
disponible = max(0, generado − usado)
```

Fuente única: `Services/Credito/CreditoClienteService`. `order_origen_id` basta (el crédito es agregado por pedido); `recepcion_origen_id` es solo metadata informativa.

## Tablas (migraciones aditivas, sin backfill)

- `bylopez_va_creditos_cliente_movimientos` — ledger inmutable: `aplicacion`, `reembolso`, `reverso_aplicacion`; `revierte_id` UNIQUE, `idempotency_key` UNIQUE, CHECK monto > 0.
- `bylopez_va_ajustes_obligacion` — condonación de flete (pedido original intacto); se reversa, no se edita ni se borra.

## Operaciones (todas: permiso + alcance `view_all` en el servidor, idempotentes, auditadas)

| Servicio | Qué hace |
|---|---|
| `AplicarCreditoService` | Pago `credito_cliente` nacido `confirmado` (sin Transaction, `guia_id` NULL) + aplicación + ledger. Bloquea origen y destino por id; relee con lectura bloqueante; valida disponible, destino en Cartera sin revisión, y **C1** (recaudo comprometido en guías del destino ≤ exigible tras aplicar). |
| `ReversarAplicacionCreditoService` | PAG-I: pago `reversado` (monto intacto), aplicación `anulada_en`, evento `reversado`, fila `reverso_aplicacion`; factura vuelve a `pending` si deja de estar satisfecha. Motivo obligatorio. |
| `ReembolsarCreditoService` | Salida real de dinero (sin Bagisto Refund): solo dinero recibido; en custodia → bloqueado. **No se reversa en V1.** |
| `AjusteObligacionService` | Condonar flete (causa `error_bylopez` / `transportadora` / `decision_administrativa`, motivo obligatorio, tope = flete − ya condonado) y reversarlo si no deja crédito consumido sin respaldo. |
| `SatisfaccionFacturaService` | Factura `paid` cuando dinero real + compensación ≥ total (sin Transaction por el crédito). `invoice.state = paid` ≠ «todo fue dinero recibido»: Cartera separa `creditoAplicado`. |

## Protecciones

- Cancelar con crédito activo: `BloquearCancelacionConCredito` en `sales.order.cancel.before` (incluye la cancelación nativa). No se auto-reversa.
- Anular recepción C5 que deje crédito consumido sin respaldo: contrato `GuardaAnulacionRecepcion` (Logistica) → bloquea (`recepcion_con_credito_consumido`). Una recepción con aptas ya reingresadas ya no era anulable (regla previa).
- `ConfirmarPagoService` rechaza el medio `credito_cliente`; el medio está `activo=false` (no seleccionable).
- `PanelMensualService.recaudo_directo` excluye el crédito; se muestra aparte (`credito_aplicado`). `ConsultaAsesoresService.cartera_verificado` resta la compensación.

## Comisiones

- **D5**: una línea habilitada **no liquidada** se recalcula sola (evento `recalculada` con traza: umbral, acumulado previo, unidades del pedido).
- **D6**: línea ya en liquidación → no se reescribe; incidencia con `pagado_historico`, `correcta_actual`, `exceso`; ajuste negativo pendiente **solo por el exceso** (100.000 pagado, 75.000 correcto → −25.000). Sin exceso no hay ajuste.
- `devolucion_sin_politica` ya no se dispara por una recepción C5 (las unidades salen del libro del umbral al registrar el retorno); se conserva para Refund nativo de Bagisto y unidades reembolsadas.
- Crédito + comisión: el crédito cubre la obligación si su origen es dinero verificado y recibido; al reversar se reevalúa sola (listener existente).
- **«Parte no pagada retenida hasta decidir Mantener»** (decisión administrativa ya existente de D6, no nueva): cuando una línea ya liquidada deja de corresponder por completo o en parte, se abre una incidencia y la parte afectada que **aún no se ha pagado** queda *retenida* (no se paga) mientras la incidencia siga abierta. No se crea deuda ni ajuste negativo si no hubo sobrepago. Quien tiene `comisiones.approve` resuelve con motivo: **«Mantener»** = el hecho no afecta la comisión → la incidencia se cierra y la línea vuelve a ser pagable. Descontar o retener definitivamente se resuelve con un ajuste explícito (el negativo, solo por el exceso ya pagado). No hay contradicción financiera: retener ≠ deber.

## Interfaz (cierre 2026-11-03)

- **Cartera del cliente** (`cartera?cliente=ID`): bloque «Saldo a favor» con totales (generado / aplicado / reembolsado / disponible), una fila por pedido origen y modales Aplicar, Reembolsar y Movimientos (con reverso, destino, fecha, actor y motivo). Dice «Compensación: no es dinero recibido». Lo no utilizable (en custodia / sin recibir) aparece como aviso, no como disponible.
- **Detalle del pedido** (`pedidos?pedido=ID`): el mismo bloque en modo *pedido*: crédito originado por ese pedido, «Recibido de otros pedidos» si es destino, acciones según permiso (Aplicar, Reembolsar, Condonar flete, Movimientos). Solo aparece si hay algo que mostrar o hacer.
- **Nueva venta**: tras elegir cliente, chip «Saldo a favor disponible: $X» (informativo). El pedido **no existe** hasta confirmar, así que aplicar el crédito es un paso **explícito posterior** en el modal «Venta creada»: monto parcial, «Saldo pendiente después de aplicar» y botón. Nunca automático. Si el crédito sale de varios pedidos origen se aplican uno a uno (clave de idempotencia por origen); no existe una transacción única entre varios orígenes y no se simula: si uno falla se informa lo ya aplicado.
- **Comprobante del reembolso**: campo de archivo en el modal (pdf/jpg/jpeg/png, 5 MB: la configuración `ventasasesor.comisiones.comprobantes`), disco `private` con nombre único (nunca sobrescribe), borrado si la operación falla o es repetición idempotente, descarga por `GET cartera/credito/{movimiento}/comprobante` solo con `credito.ver` + alcance «todos» (`X-Content-Type-Options: nosniff`, `no-store`). Sin URL pública.
- Endpoint para Nueva venta: `GET cartera/credito/cliente/{cliente}` → `visible:false` (200) si el actor no puede verlo, para no provocar el aviso de 403 del panel.

## ACL (cierre 2026-11-03, endurecido en los últimos bloqueos)

**Solo el Administrador autorizado opera dinero del crédito.** En Bagisto, todo rol `permission_type = all` hereda cualquier clave (también un «BODEGA» creado como `all`, p. ej. #12071 en dev), así que el permiso por sí solo no basta. Regla positiva y única, en el servidor (`AutorizacionVentasAsesor::esAdministradorAutorizado()` / `puedeCredito()`):

- **Operar** (aplicar, reembolsar, reversar, ajustar y reversar ajuste, también el comprobante): administrador autorizado **y** la clave **y** alcance de Cartera «todos». Administrador autorizado = el rol configurado en `ventasasesor.credito.rol_administrador_id` (env `VENTASASESOR_CREDITO_ROL_ADMIN_ID`, por defecto **1**, el mismo criterio que Cargues), de tipo `all`, con el usuario activo.
- **Ver** (bloque, chip de Nueva venta, historial): la clave; un rol `all` que no sea el administrador autorizado tampoco ve. Un rol `custom` con la hoja «ver» expresamente asignada ve pero no opera.
- No depende del nombre del rol ni de ocultar botones: lo aplican los servicios, los controladores y las pantallas con la misma función. Las demás claves de Cartera y los demás módulos **no cambian**.
- Roles reales en dev: Administrador #1 → permitido; ASESOR VENTAS #2, BODEGA #4, TALLER, CORTADOR, CLIENTE FRECUENTE → denegado (sin hojas); BODEGA #12071 (`all`) → **antes podía todo, ahora denegado**.
- Producción: confirmar el id del rol administrador antes de activar (o fijar la variable de entorno).

Pruebas HTTP con Bouncer real: `D10InterfazAclComprobanteTest` (matriz de roles reales y roles `all` no administradores; custom con todas las hojas mal asignadas; administrador desactivado).

## Limitaciones conocidas

- El estado del **pedido** de Bagisto no vuelve a `pending_payment` al reversar (limitación nativa); la verdad financiera es Cartera/crédito.
- Reverso de reembolso, devoluciones comerciales post-entrega, reposición, reembolso directo del transportador y backfill: **V2**.
- Producción: nada aplicado (migraciones, configuración ni roles).
