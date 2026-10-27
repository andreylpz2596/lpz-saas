# Cuentas corrientes y liquidaciones de transportadoras

**Fecha:** 2026-10-14 · **Estado:** IMPLEMENTADO EN DESARROLLO (sin commit, sin producción) · pendiente de la prueba manual de Camilo · **Responsable funcional:** Camilo Gaitán

> Si este documento contradice el código, manda el código. Mapa general: `MAPA_DOMINIO_LOGISTICA.md` §14.

## 1. Qué es y qué no es

Tres cosas distintas que nunca se mezclan:

| | Relación | Dónde vive |
|---|---|---|
| **Cartera del cliente** | cliente ↔ ByLopez | `bylopez_va_pagos` → `order_transactions` (VentasAsesor) |
| **Cuenta corriente del transportador** | transportador ↔ ByLopez (custodia / obligación) | `bylopez_lg_cuenta_movimientos` |
| **Liquidación** | cierre de un conjunto de movimientos | `bylopez_lg_liquidaciones_transportadora` |

«El cliente pagó» no significa «ByLopez tiene el dinero»: el domiciliario puede tenerlo físicamente. La cuenta corriente **no es un ingreso** ni una venta. El ingreso del cliente sigue siendo solo el de Cartera.

## 2. Quién tiene cuenta: por capacidad, nunca por nombre

- Participa la transportadora con `liquida_cuenta_corriente = true`. Hoy, en desarrollo: Jeison («05») y Brian sí, PICAP no.
- La guía guarda esa capacidad **al despachar** (`bylopez_lg_guias.liquida_cuenta_corriente`). Un cambio posterior del catálogo no reinterpreta los despachos anteriores.
- Si **Corregir guía** cambia la transportadora, manda la capacidad de la nueva: lo generado a nombre de la anterior se revierte.
- PICAP (sin capacidad) no genera movimientos ni aparece en el listado. Una transportadora que deja de liquidar pero todavía tiene movimientos sigue apareciendo, para poder cerrarla.

## 3. Convención de signo (única)

**Importe firmado en una sola columna `importe`:**

- **Positivo (+):** la transportadora debe dinero a ByLopez.
- **Negativo (−):** ByLopez debe dinero a la transportadora.

**Saldo = Σ importe de los movimientos abiertos.** Cada liquidación suma 0, así que esto coincide con el saldo histórico total. No se guarda ningún saldo mutable.

La interfaz siempre lo dice en palabras (`Support/SaldoCuenta`):

- `+41.000`: «Jeison debe entregar a ByLopez $ 41.000».
- `−20.000`: «ByLopez debe pagar a Jeison $ 20.000».
- `0`: «Cuenta al día».

## 4. Movimientos (`ConceptoMovimientoCuenta`)

| Concepto | Signo | Origen |
|---|---|---|
| `recaudo` | + | Automático por la entrega. «Requiere revisión» la confirma el administrador |
| `servicio` | − | Automático por la entrega. «Requiere revisión» la confirma el administrador |
| `costo_devolucion` | − | Solo por registro explícito del administrador (no hay tarifa aprobada) |
| `ajuste` | + o − | Solo el administrador, con motivo obligatorio |
| `pago_liquidacion` | cierra | Dinero entregado o pagado al liquidar |
| `diferencia_liquidacion` | cierra / abre | Lo que no cuadró: se cierra en la liquidación y se reabre en la cuenta siguiente |

**El «flete recibido por ByLopez» no es un concepto aparte.** Cuando el envío está cobrado en el pedido, ese dinero ya está dentro del recaudo o del pago del cliente. Lo que ByLopez le debe al domiciliario es el `servicio`. Un segundo movimiento contaría dos veces el mismo dinero.

Trazabilidad de cada movimiento:

- Datos: `transportadora_id`, `order_id`, `shipment_id`, `guia_id`, `origen_tipo` (envio · revision · ajuste · liquidacion · reverso), `origen_id`, `fecha_operativa`, actor + nombre snapshot y `liquidacion_id`.
- Ruta: **pedido → envío → entrega → movimiento → liquidación**.

## 5. Cuándo nace el dinero (regla única: `Services/Cuentas/ReglaCuentaEnvio`)

Despachado ≠ entregado ≠ recaudado. **Despachar no genera movimientos.** Los movimientos los concilia `GeneradorMovimientosCuenta::sincronizarEnvio()` a partir de los hechos reales del envío:

- **Guía:** snapshot del despacho con `recaudo_esperado`, `valor_flete` y `flete_pagador`. El `recaudo_esperado` NACE correcto por guía (C1, §5.1).
- **Entregas y devoluciones vigentes.**

La conciliación corre en la misma transacción de quien cambia esos hechos:

- `RegistrarEventoEnvio` (entrega o devolución);
- `AnularEventoLogisticoService`;
- `CorregirGuiaService` (cuando cambia la transportadora).

**Recaudo (+) = `recaudo_esperado`** (la instrucción de cobro del despacho, separada del domicilio):

- Nace solo cuando el envío queda **completamente entregado** (nada pendiente, algo entregado, ninguna devolución).
- Varias entregas parciales que completan el envío dan **un solo** recaudo.
- Todo devuelto, o con unidades aún pendientes: **0**.
- Entregado en parte y devuelto en parte: **«Requiere revisión»**. No se inventa una proporción. Se muestra un **recaudo sugerido** (2026-10-17, `Support/ValorDevolucionesEnvio`):
  - recaudo sugerido = recaudo esperado de la guía − valor de las prendas devueltas;
  - cada línea devuelta (retornos vigentes; en un configurable, la línea padre) se valora con el **pedido histórico**: `(order_items.base_total − base_discount_amount + base_tax_amount) / qty_ordered × cantidad devuelta`;
  - es el mismo precio neto de COM-02 más el impuesto de la línea. Nunca se usa el catálogo, el precio actual del grupo ni un promedio del total. El envío no se descuenta.
- Recaudo mayor que el total del pedido (el domicilio se mezcló): **«Requiere revisión»**.

**Servicio (−) = `valor_flete`** (costo real del domicilio, snapshot del despacho):

- Aplica solo si lo paga ByLopez (`bylopez` o `incluido_en_venta`, es decir, envío gratis o cobrado en el pedido).
- Se devenga con la **primera unidad entregada**. Todo devuelto: sin servicio automático.
- **Por pagar** (`cliente_al_recibir`): el cliente le paga el domicilio directo al transportador, así que no hay movimiento. Además, el recaudo es solo la mercancía: el domicilio nunca se vuelve venta de producto.
- Pagador `otro`, o sin costo registrado: **«Requiere revisión»**.

**Idempotencia y corrección sin editar:**

- Es una conciliación: calcula lo que el envío debe aportar y lo compara con lo vigente. Si coincide, no escribe nada.
- Si cambió (por ejemplo, se anuló una entrega), crea un **reverso** (`revierte_id` UNIQUE) y luego el movimiento nuevo.
- Claves deterministas UNIQUE:
  - `envio:{shipment}:{concepto}:{n}`;
  - `reverso:{movimiento}`;
  - `ajuste:{clave}`;
  - `liquidacion:{id}:pago|diferencia:*`.
- El envío se bloquea (`FOR UPDATE`).
- Refresh, doble clic, reintento o concurrencia no duplican dinero.
- Si el movimiento revertido ya estaba liquidado, el reverso entra en la cuenta siguiente.

### 5.1 Recaudo por guía (C1, `Services/Despachos/RecaudoPorGuia`)

Hallazgo C1 de la jornada 2026-10-21: en un despacho parcial cada guía heredaba el **total del pedido** como `recaudo_esperado` (#87: dos guías de $ 401.200 → $ 802.400 en el libro). Causa: `SugiereRecaudo::recaudoSugerido()` es del pedido, no de la guía, y el drawer lo copiaba a cada despacho.

Una sola regla para el drawer y para el servidor (que siempre la vuelve a aplicar):

```
exigible     = saldo que el cliente aún debe y pueden cobrar las transportadoras (SugiereRecaudo::saldoExigible)
comprometido = Σ recaudo_esperado de las guías que el pedido ya tiene
disponible   = exigible − comprometido                      ← techo de ESTA guía
mercancía    = valor HISTÓRICO de sus unidades: (base_total − base_discount_amount + base_tax_amount) / qty_ordered × cantidad
flete        = grand_total − Σ líneas (lo que el cliente pagó de domicilio en el pedido); SOLO en la primera guía
sugerido     = min(disponible, mercancía + flete)           (0 si el pedido no es contra entrega)
```

- **Despacho único** (una guía con todo lo pendiente, el caso normal): sugerido = todo el saldo exigible, igual que antes.
- **Precio:** siempre el de la línea del pedido (padre en configurables); nunca catálogo, precio de grupo actual ni promedio.
- **Flete:** el domicilio cobrado al cliente se recauda **una sola vez**. El costo del servicio de cada viaje (`valor_flete` de la guía) es otro concepto y no entra.
- **Pagos previos:** `saldoExigible` (Cartera: saldo + lo que ya cobraron las transportadoras, para que registrar ese cobro no cambie el techo; sin Cartera: total − Transactions). Un anticipo de $ 40.000 en un pedido de $ 112.000 deja $ 72.000 repartibles entre las guías.
- **Excepción:** el usuario puede «Ajustar valor a cobrar»; si difiere de lo calculado, **motivo obligatorio** (servidor). Sin valor escrito (`null`) el servidor aplica el suyo. Aunque haya motivo, **Σ guías ≤ exigible** (`recaudo_supera_saldo`); una excepción que lo supere es decisión pendiente, no se permite.
- **Trazabilidad** (columnas aditivas en `bylopez_lg_guias`, migración `2026_10_23_100016`): `recaudo_sugerido` (lo que calculó el servidor; NULL en guías anteriores, que no se reinterpretan), `motivo_recaudo`; quién = `actor_admin_id`; modificada = `recaudo_esperado ≠ recaudo_sugerido`. La idempotencia incluye el valor escrito y el motivo.
- **No incluido:** devoluciones/no entregadas que reducen el saldo de Cartera (C5) y cobro automático en Cartera al liquidar (C6). Una guía registrada sigue comprometiendo su recaudo hasta que se decida esa regla. **Sin backfill:** las guías con recaudo duplicado ya registradas (p. ej. #87) no se corrigen.

## 6. Casos

| Caso | Movimientos | Saldo |
|---|---|---|
| A. Contraentrega $ 51.000, servicio $ 10.000 | recaudo +51.000 · servicio −10.000 | +41.000 → «Jeison debe entregar a ByLopez $ 41.000» |
| B. El cliente ya pagó a ByLopez | servicio −10.000 | −10.000 → «ByLopez debe pagar a Jeison $ 10.000» |
| C. Envío gratis | servicio −10.000 | −10.000 |
| D. Envío por pagar | recaudo +mercancía (el domicilio lo cobra y conserva el transportador) | +mercancía |
| E. PICAP | ninguno | — |
| F. Entregas parciales 2 + 3 | servicio en la 1.ª; recaudo una vez al completar | sin doble recaudo |
| F'. Parcial con devolución y recaudo | servicio; el recaudo queda en «Requiere revisión» hasta que el administrador confirme el valor cobrado | — |
| G. Devolución | ninguno automático; costo de devolución solo por registro explícito | — |

**Datos reales de desarrollo** (simulación del comando, nada escrito):

| Transportadora | Envío | Movimientos | Neto |
|---|---|---|---|
| Jeison | 31 | +81.800 · −10.000 | +71.800 |
| Jeison | 36 | +620.000 · −10.000 | +610.000 |
| **Jeison (total)** | | | **+681.800** |
| Brian | 35 | +68.000 · −12.000 | +56.000 |
| Brian | 42 | +39.600 · −12.000 | +27.600 |
| Brian | 46 (GI-000001, por pagar) | +101.600 | +101.600 |
| **Brian (total)** | | | **+185.200** |

## 7. Liquidación (`LiquidarCuentaService`, solo administrador)

- Cierra **los movimientos abiertos que el usuario revisó** (sus ids) con el valor efectivamente entregado o pagado. Todo se recalcula en el servidor bajo transacción:
  - la transportadora se bloquea `FOR UPDATE` (dos liquidaciones de la misma cuenta se serializan);
  - los movimientos también se bloquean, y cada uno debe seguir abierto y ser de esa cuenta.
- Si otro usuario ya los liquidó, responde `movimientos_ya_liquidados`. La misma clave devuelve la misma liquidación.
- **Cuadre sin doble conteo.** Los movimientos de una liquidación siempre suman 0:
  - saldo esperado `S` = Σ de los incluidos;
  - pago `P` = −valor si `S > 0`, o +valor si `S < 0`;
  - diferencia `D = S + P`;
  - si `D ≠ 0`, se registra `diferencia_liquidacion` −D (liquidada) y +D (**abierta**, en la cuenta siguiente), con **observación obligatoria**;
  - nada anterior se modifica.
- Ejemplo: debía entregar $ 420.000 y entregó $ 410.000.
  - Esperado $ 420.000 · Recibido $ 410.000 · Diferencia $ 10.000.
  - La liquidación se cierra y la cuenta queda «debe entregar $ 10.000».
- **LIQ-000001:** contador `bylopez_lg_consecutivos` (`liquidacion_transportadora`) bloqueado + UNIQUE.
- La liquidación guarda:
  - período, cantidad de movimientos, totales (recaudos, servicios, ajustes), saldo esperado, sentido, valor, diferencia;
  - observación, referencia (texto), resumen JSON;
  - quién la cerró y cuándo.
- **Inmutable:** el modelo bloquea `update` y `delete` (`liquidacion_cerrada`). Una diferencia posterior se corrige con un **ajuste**, que entra en la cuenta siguiente.
- **Comprobante:** solo referencia en texto. No se reutilizó `GuiaArchivoService` porque es exclusivo de guías y solo acepta PDF. Un adjunto privado (imagen/PDF) queda pendiente.

## 8. Ajustes y revisiones (`AjusteCuentaService`, solo administrador)

- **Ajuste:** a favor de ByLopez (+) o del transportador (−). Valor > 0, motivo obligatorio, referencia opcional, idempotente. Nunca se edita un saldo.
- **Costo de devolución** (−): ligado a una guía de esa transportadora **con devolución registrada**.
- **Confirmar revisión** (recaudo o servicio de un envío «Requiere revisión»):
  - registra el valor real; puede ser 0, y la decisión queda registrada;
  - con recaudo sugerido, el campo viene precargado; si el valor coincide basta confirmar, y si cambia el **motivo es obligatorio** (validado también en el servidor). Nunca se confirma solo;
  - usa la misma clave del envío que el generador, así que no se duplica;
  - el recaudo no puede superar el esperado;
  - mientras el envío siga ambiguo, la resincronización no pisa esa decisión.

## 9. Permisos (`Config/acl.php`)

| Clave | Autoriza | Rol sugerido |
|---|---|---|
| `logistica.cuentas` | Ver listado, cuenta, movimientos, cuadre e histórico | BODEGA y ADMIN |
| `logistica.cuentas.liquidar` | Cerrar liquidaciones (quedan a nombre de quien confirma) | ADMIN |
| `logistica.cuentas.ajustar` | Ajustes, costo de devolución y resolver revisiones | ADMIN |

Los servicios validan siempre. **En desarrollo no se modificó ningún rol:** el Administrador (`all`) lo tiene todo; a BODEGA hay que asignarle `logistica.cuentas` en Configuración › Roles.

## 10. Interfaz

- **Ruta:** Logística › **Cuentas de transportadoras** (`/admin/logistica/cuentas`).
- **Listado:**
  - 4 KPI: transportadoras con saldo, por entregar a ByLopez, por pagar a transportadoras y movimientos pendientes.
  - Aviso de envíos por revisar.
  - **Escritorio:** tabla con transportadora, último movimiento, recaudado, servicios, saldo, situación (en palabras + color de apoyo) y «Ver cuenta».
  - **Móvil:** una tarjeta por transportador (situación, saldo grande, pendientes y «Ver cuenta»), sin tablas horizontales.
- **Drawer amplio** (sin navegar):
  - cabecera con nombre, badge «Cuenta corriente» y período abierto;
  - saldo grande en palabras;
  - resumen (recaudado, servicios, ajustes y total);
  - «Requiere revisión», con confirmación inline para el administrador;
  - movimientos agrupados por envío (pedido · guía), con filtros (estado, tipo, desde, hasta, pedido o guía);
  - pie fijo con «Registrar ajuste» (administrador) y «Cuadrar / Liquidar».
- **Cuadrar:**
  - movimientos a incluir y total neto;
  - valor entregado precargado con el saldo, y cuadre esperado / recibido / diferencia en vivo;
  - observación obligatoria si hay diferencia;
  - BODEGA ve el cuadre, pero «Solo un administrador puede cerrar la liquidación».
- **Histórico:**
  - cada cierre (LIQ, período, movimientos, recaudos, servicios, neto, valor, diferencia trasladada, quién y cuándo) con «Ver detalle» de solo lectura;
  - montos con el formato de ByLopez («$ 41.000»).
- **Moneda:** la base COP no tiene símbolo configurado y Bagisto devolvería «COP». `Support/Moneda` muestra «$», sin tocar la configuración de monedas. En el navegador se usa `ByLopezMoneda.formatear()` con el mismo prefijo, y los campos usan `<v-moneda>` (envían el valor crudo).

## 11. Relación con Cartera

- La cuenta corriente **solo lee** hechos de Logística. No llama a Cartera ni escribe pagos ni facturas.
- El cobro contra entrega en Cartera (`CobrarEntregaService` → `contraentrega_recaudo`) sigue siendo el **único** registro del dinero del cliente.
- El `recaudo` de la cuenta es la **custodia** del domiciliario sobre ese mismo dinero, no un segundo ingreso.
- Ambos se cruzan por `order_id`.
- **Pendiente** (sin implementar): enlazar la liquidación con un depósito o comprobante y mostrar en el movimiento si Cartera ya registró el cobro.

## 12. Devoluciones

Registrar o recibir una devolución no mueve dinero. La mercancía (inventario) y el dinero (cuenta) van separados. Un costo de devolución existe solo por registro explícito.

## 13. Datos históricos y comando

- No hay backfill automático. `php artisan logistica:cuentas:sincronizar` **simula** por defecto (transacción revertida) y muestra lo que generaría.
- `--aplicar` escribe; `--envio=ID` limita a ciertos envíos. Es idempotente.
- En desarrollo **no se aplicó**: decide Camilo, porque esos envíos pudieron cuadrarse ya a mano.

## 14. Archivos

**Nuevos:**

- `Database/Migrations/2026_10_14_100014_create_bylopez_lg_cuentas_transportadora_tables.php`
- `Enums/ConceptoMovimientoCuenta.php`, `Enums/EstadoMovimientoCuenta.php`
- `Models/MovimientoCuenta.php`, `Models/LiquidacionTransportadora.php`
- `Services/Cuentas/ReglaCuentaEnvio.php`, `GeneradorMovimientosCuenta.php`, `AjusteCuentaService.php`, `LiquidarCuentaService.php`, `ConsultaCuentasService.php`
- `Support/Moneda.php`, `Support/SaldoCuenta.php`
- `Http/Controllers/Admin/CuentasTransportadoraController.php`
- `Console/SincronizarCuentasTransportadora.php`
- `Resources/views/admin/cuentas/index.blade.php`, `componente.blade.php`
- `tests/Feature/Log09CuentasTransportadoraTest.php`

**Modificados:**

- `Services/Entregas/RegistrarEventoEnvio.php`, `AnularEventoLogisticoService.php`
- `Services/Guias/CorregirGuiaService.php`
- `Exceptions/OperacionLogisticaInvalidaException.php`
- `Http/Controllers/Concerns/ResponderOperacionesLogisticas.php`
- `Http/routes.php`
- `Config/acl.php`, `Config/menu.php`
- `Support/AutorizacionLogistica.php`
- `Providers/LogisticaServiceProvider.php`
- `Resources/views/admin/estilos.blade.php`
- `Resources/lang/{es,en}/app.php`

Webkul, CorteConfeccion (solo se incluye su parcial de moneda), inventario, precios, comisiones y Cartera: sin cambios.

## 15. Pruebas

C1: `Log11RecaudoPorGuiaTest` (C1-01 despacho parcial automático con precios históricos, flete una vez, pago previo y tres guías; C1-02 modificación con motivo, tope del saldo, trazabilidad e idempotencia), en `lpz_saas_testing`.

`Log09CuentasTransportadoraTest`: **15/15**, en `lpz_saas_testing`.

| Prueba | Cubre |
|---|---|
| CC-1 | Contraentrega +51.000 / −10.000 = +41.000, y despachar no genera dinero |
| CC-2 | Cliente ya pagó: −10.000 |
| CC-3 | Envío gratis −10.000 y por pagar solo mercancía |
| CC-4 | PICAP: 0 movimientos y fuera del listado |
| CC-5/6 | Reintento y resincronización sin duplicar |
| CC-7 | Dos parciales: un solo recaudo |
| CC-8/9/10 | Liquidar, segundo usuario rechazado, liquidación inmutable |
| CC-11 | Ajuste posterior entra en la siguiente liquidación |
| CC-12 | BODEGA ve, pero no ajusta ni liquida |
| CC-13/14 | Diferencia trasladada con observación; sentidos en palabras |
| CC-15 | Devolución sin dinero; costo de devolución explícito |
| CC-16 | Anulación con reverso |
| CC-17 | Requiere revisión + confirmación idempotente |
| CC-18 | Corrección de transportadora |
| CC-19 | HTTP: listado y contexto JSON |
| CC-20 | 3 devueltas × $ 8.000 = $ 24.000; $ 80.000 → sugerido $ 56.000, aunque el catálogo cambie; confirmar sin motivo |
| CC-21 | Líneas con precios distintos, descuento, impuesto y devolución parcial de una línea |
| CC-22 | Valor distinto al sugerido sin motivo → rechazado; con motivo se registra |

**Regresiones:** Log01 (10), Log03 (8), Log07 (14) y Log08 (7), todas en verde.

**No ejecutadas:** Log02, Log04, Log05 y Log06. Log06 ya estaba afectado por la guía interna GI (§13).

El JavaScript del drawer se verificó solo con `node --check`; **no se probó en un navegador**.

## 16. Pendiente / riesgos

- Adjunto privado del comprobante de liquidación.
- Indicador de cobro registrado en Cartera junto al recaudo.
- Liquidación parcial (por rango de fechas): hoy se liquidan todos los abiertos revisados.
- Tarifas de devolución, si se aprueba una regla.
- **Límite de 300 movimientos visibles por filtro:** la liquidación igual incluye todos los abiertos.
- Asignar `logistica.cuentas` a BODEGA en producción.
- Aplicar la migración en producción.
