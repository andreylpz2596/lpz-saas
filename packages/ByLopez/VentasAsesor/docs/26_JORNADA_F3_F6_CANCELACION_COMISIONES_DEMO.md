# 26 — Jornada 2026-10-27/28: F3–F6, cancelación del pendiente, C6, comisiones y demo reproducible

Estado: implementado en **dev + base de pruebas + base de demo aislada**; **nada se desplegó ni se empujó**; `packages/Webkul` y `vendor` sin cambios.
Clasificación usada en todo el documento: **IMPLEMENTADO** · **PROBADO** (con datos reales de prueba, por los mismos servicios/endpoints que usa el panel) · **SIMULADO** (lo que el navegador o un fixture reemplaza) · **PENDIENTE/BLOQUEADO**.

> Este documento reemplaza como vigente a `00_STATUS` para esta jornada. Lo ya corregido **no** se repite como pendiente.

## 1. Qué cambió (resumen)

| Tema | Resultado | Dónde |
|---|---|---|
| **F3/F4** carreras de pago | Saldo con **lectura actual** bajo el bloqueo del pedido; errores no controlados → 409/500 sin trazas y con referencia en el log. No se cambió el aislamiento global. **PROBADO** con procesos PHP reales (CONC-1..6). | `CarteraConsultaService`, `RespuestaErrorNoControlado`, `tests/Concurrencia` |
| **F5** reserva de stock | El pedido confirmado reserva sus unidades por el mecanismo nativo (hijo de configurable = cantidad 1); pedidos anteriores sin reserva siguen protegidos **sin backfill**. **PROBADO** (F5-1..5, 03F-37d). | `CantidadVarianteConfigurable`, `ProtegerReservasEnDespachoLegado`, `CercoInventario` |
| **F6** instrucción de cobro | Todo saldo o pago sin confirmar exige decisión: **A** esperar · **B** cobrar al entregar · **C** crédito autorizado (permiso + motivo + historial inmutable). Ahora **también en la recogida**. **PROBADO** (Log14 F6-1..8, F1-12). | `DisposicionCobroService`, `DespachoService`, drawer |
| **Cancelación del pendiente** | Servicio propio; **no** aumenta stock físico; libera solo la reserva de lo pendiente; la cancelación nativa con unidades despachadas se **corta en el servidor**. **PROBADO** (Log15 CAN-1..8, F5-2/F5-5, recorrido F). | `CancelarPendienteService`, `BloquearCancelacionNativaConDespachos` |
| **C6** | Verificado. **Defecto corregido**: un cobro de una transportadora **sin cuenta corriente** dejaba pasar la comisión sin prueba de que el dinero llegó a ByLopez; ahora bloquea con `recaudo_sin_cuenta_corriente`. **PROBADO** (C6-05 falla sin el arreglo; recorrido D1). | `ElegibilidadComisionService::cobroSinLibro` |
| **Motivos de devolución** | Catálogo `devolucion` separado del tipo de defecto; no se reclasificó nada histórico. **PROBADO** (RD-15, recorrido E). | `CatalogoDefecto`, migración `…100020` |
| **Comisiones** | El módulo R3/R4 **no tenía pruebas automáticas**: se agregaron `Com04ComisionesFlujoTest` (COM4-1..8) y se actualizó `AclYMenuConfiguracionTest` (45 claves, doc 23 §10.7). | `tests/Feature/Com04…`, `tests/helpersCom4.php` |
| **Pagos con `verificado`** | El contrato devuelve `sin_revisar`, `verificado` y `saldo`. | `PagosPendientesDeConfirmar` |

## 2. Cancelación del pendiente: regla y contabilidad

**Hallazgo (reproducido):** `OrderRepository::cancel` de Bagisto llama `OrderItemRepository::returnQtyToProductInventory`, que **suma al stock físico lo ya despachado** (en la prueba F5-2: 40 → 50), y en pedidos autofacturados de ByLopez ni siquiera se puede ejecutar (`qty_to_cancel` descuenta lo facturado).

**Regla implementada** (`CancelarPendienteService`):
1. Cancela **todo** lo pendiente de despachar (pedido − enviado − reembolsado − cancelado). No hay cancelación parcial de un pendiente.
2. Libera **solo** la reserva (`product_ordered_inventories`) de esas unidades; **no toca** `product_inventories`. Pedidos anteriores a F5 (hija con cantidad 0, sin reserva) no restan nada a las reservas de otros pedidos; su compromiso legado baja solo porque `CercoInventario` ya resta `qty_canceled`.
3. Lo despachado conserva envíos, guías y entregas. Solo vuelve a inventario con **recepción física** de la devolución (C5).
4. Permiso propio `logistica.despachos.cancelar_pendiente` (**ningún rol lo trae por defecto**), motivo ≥ 5 caracteres, historial inmutable (`bylopez_lg_cancelaciones` + líneas con actor, motivo, cantidad, valor y si liberó reserva). Repetir la operación → `cancelacion_sin_pendiente` (no libera ni ajusta dos veces).
5. **Obligación del cliente:** el total del pedido no se modifica y **no se crea Refund**. El valor histórico de lo cancelado (precio efectivo − descuento + impuesto de la línea, igual que C1/C5) se resta del exigible en Cartera como `cancelado`, **aparte de `devuelto` (C5)**; una unidad cancelada no se despacha, así que no puede estar en las dos. Si lo pagado supera lo exigible queda **saldo a favor identificado**, sin reembolso. El flete cobrado no se condona.
6. Si el pedido quedara **totalmente cancelado con dinero ya aplicado**, se bloquea (`cancelacion_con_pagos`): devolver ese dinero es una decisión humana (D10, no implementada).
7. Cancelación **nativa**: con unidades despachadas el evento `sales.order.cancel.before` aborta antes de cambiar nada (botón, endpoint `admin/sales/orders/cancel/{id}` y cualquier llamada de código). Sin despachos conserva el comportamiento nativo (libera reserva; no hay físico que reintegrar).
8. El estado del pedido se recalcula por el mecanismo nativo; las comisiones se enteran (el libro del umbral recibe el movimiento `cancelacion` y la cascada reevalúa los pedidos posteriores del asesor y mes).

Migración aditiva `2026_10_28_100021_create_bylopez_lg_cancelaciones_pendiente_tables` — aplicada **solo** en `lpz_saas_testing` y la demo. **En dev queda pendiente** (no estaba en la lista autorizada); sin ella el panel oculta el botón y el servicio responde `cancelacion_no_disponible`.

## 3. Decisiones que quedan documentadas (no se inventaron)

- **Costo adicional** (guía): `costo_adicional = costo final − tarifa sugerida`. Es un **ajuste** respecto de la tarifa, no una tarifa. Negativo = descuento; apartarse de la sugerida exige **motivo**; el costo final nunca puede ser < 0. No cambió.
- **Moneda/exportaciones:** el formato visual `$ 8.500` se mantiene. No existen exportaciones con importes en estos módulos; si se agregan, deben marcar COP en el encabezado.
- **F5 legado:** sin backfill. Pedidos anteriores a F5 se protegen en el despacho y en el cerco; no se les inventa reserva.
- **F6 sin migración:** la regla queda inactiva (`DisposicionCobroService::activa()`); en dev ya se aplicó (autorizado) tras respaldo.
- **C6** apagado en los entornos habituales (`LOGISTICA_RECAUDO_DESDE_COBRO` sin valor); solo se activa por variable de proceso en la demo.
- **Comisiones y bloqueos de rentabilidad** apagados en los entornos habituales; solo se activan en la demo por variable de proceso (`VENTASASESOR_COMISIONES_FECHA_INICIO`).
- **Políticas sin aprobar (bloqueo explícito, nunca se habilitan):** D3 pedido mixto que cruza el umbral con bases distintas (`politica_pendiente_pedido_mixto`), D5 desplazamiento del tramo por cancelación/devolución de un pedido anterior (`politica_pendiente_desplazamiento`), cambio de umbral a mitad de mes (`politica_pendiente_umbral_cambio_mes`), devolución recibida o reembolso (`devolucion_sin_politica`), crédito/costo provisional (`costo_sin_estado`).
- **Transportadora nacional sin cuenta corriente:** **no existe** un mecanismo que acredite el ingreso del recaudo a ByLopez. Hoy la comisión queda bloqueada de forma explícita (`recaudo_sin_cuenta_corriente`). Para habilitarla hay dos caminos: activar la capacidad «liquida cuenta corriente» de esa transportadora (soportado; recorrido D2) o definir y construir un registro de remesas (requisito **pendiente de negocio**).

## 4. Guion reproducible de la demo

Entorno aislado: base `lpz_saas_demo_20261028` (o la que se cree), `MAIL_MAILER=log`, `QUEUE_CONNECTION=sync`, ninguna integración externa. Las variables de activación son **del proceso**; el `.env` compartido no se toca.

```bash
# 1) Crear la base aislada (estructura + catalogo del origen; sin pedidos ni clientes)
DEMO_DB=lpz_saas_demo_20261028 bash packages/ByLopez/VentasAsesor/tests/Demo/crear_base_demo.sh
# 2) Usuarios, productos DEMO, clientes, acuerdos y fixture 998 (clave por variable de entorno; nunca en archivos versionados)
DB_DATABASE=lpz_saas_demo_20261028 MAIL_MAILER=log QUEUE_CONNECTION=sync DEMO_PASSWORD=<elija-una> php packages/ByLopez/VentasAsesor/tests/Demo/preparar_demo.php
# 3) Servidor (desde public/), con la activacion solo para este proceso
cd public && DB_DATABASE=lpz_saas_demo_20261028 MAIL_MAILER=log QUEUE_CONNECTION=sync VENTASASESOR_COMISIONES_FECHA_INICIO=2026-10-26 \
  LOGISTICA_RECAUDO_DESDE_COBRO=2026-10-28 APP_DEBUG=false php -S 127.0.0.1:8020 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
# 4) Reevaluacion automatica programada (cada 5 minutos): dejar corriendo con el MISMO entorno
php artisan schedule:work
# 5) Recorridos (HTTP real por rol). Cada uno imprime la conciliacion y falla si algun numero no coincide
DEMO_PASSWORD=... DB_DATABASE=lpz_saas_demo_20261028 VENTASASESOR_COMISIONES_FECHA_INICIO=2026-10-26 LOGISTICA_RECAUDO_DESDE_COBRO=2026-10-28 \
  php packages/ByLopez/VentasAsesor/tests/Demo/recorridos.php PREP   # confirma costos de referencia y cancela la fixture antigua
  php packages/ByLopez/VentasAsesor/tests/Demo/recorridos.php A B C D E F G H    # (uno por vez: A, B, ... o «todos»)
```

Roles y accesos (todos `@bylopez.test`): `demo.admin` (Administrador), `demo.asesora` (ASESOR VENTAS), `demo.umbral` (ASESOR VENTAS exclusiva del ejemplo 998+5), `demo.bodega` (BODEGA). La clave la define quien prepara la demo.

| Recorrido | Qué demuestra | Cifras esperadas |
|---|---|---|
| **A** local / transferencia | venta mixta → reserva → pago reportado (no verificado) → confirmación → despacho → entrega → validación → comisión automática | reserva 3 · pago 30.000 · F6 bloquea antes de confirmar |
| **B** local / contraentrega parcial | 2 guías (C1 20.000 + 20.000) → entregas → cobro → custodia 40.000 → liquidación con diferencia y compensación del flete → conciliación | efectivo 27.000 + 1.000; flete 12.000 compensado |
| **C** nacional / transferencia | Medellín (fuera de cobertura), guía ficticia Inter Rapidísimo, pago antes del despacho | recaudo 0 |
| **D** nacional / contraentrega | D1 sin cuenta corriente (bloqueo explícito) · D2 con cuenta corriente (cobro reportado → custodia → liquidación → conciliado) | D2: 30.000 − 15.000 de flete = 15.000 efectivo |
| **E** devolución | rechazo comercial, 2 aptas + 1 defectuosa, C5, faltante | físico +2 · no vendible 1 · a favor 30.000 · faltante no ingresa |
| **F** cancelación del pendiente | 10 uds, despacho 6, cancelar 4 | reserva 4→0 · físico sin cambio · exigible 60.000 |
| **G** recogida | pago completo / pago pendiente (cobrar al retirar) / crédito autorizado | sin guía ni movimientos de transportadora |
| **H** umbral y pago al asesor | 998 + 5 → 3 comisionan → **$1.200** → abonos $500 (saldo $700) y $700 (saldo $0) | exacto |

## 5. Limitaciones y pendientes (lista exacta)

- Cargues/99 Envíos no se ejecutó en la demo (ver informe final): la trazabilidad del transportador se prueba por los eventos de Logística; el cargue sigue **PENDIENTE** en esta base.
- Verificación visual: Chrome headless emulando viewport móvil (390 px); **no** hubo teléfono físico ni persona operándolo.
- «500 después de guardar» se **simula en el navegador** (`page.route`); la recuperación se verifica contra la base.
- La migración de cancelaciones **no** está en dev.
- No se ejecutó una prueba de carga de 10.000 pedidos (no se hizo antes de cerrar los flujos básicos).
- Las políticas D3, D5, cambio de umbral a mitad de mes, devolución posterior a la entrega y remesas de transportadoras sin cuenta corriente siguen **sin definir**.

## 6. Resultados de pruebas (2026-10-28, base `lpz_saas_testing` y demo `lpz_saas_demo_20261028`)

| Batería | Resultado | Notas |
|---|---|---|
| Logistica completa | **141 pasan** (+ C6-05/06 y F6-7/8 nuevas, pasan) | una suite a la vez: dos en paralelo producen deadlocks falsos |
| Concurrencia real (03F-37…41, 37d, CONC-1…6) | **14 de 15 pasan** | CONC-4 falló una vez por *lock wait timeout* (1205) bajo carga (servidor demo + scheduler activos); sola **pasa**; la evidencia JSON de ambas corridas está en `storage/logs/concurrencia/` |
| Comisiones `Com04` (8) + ACL (11) | **pasan** | COM4-1 es el ejemplo exacto 998 + 5 = 1.200 |
| VentasAsesor Feature+Unit | **834 pasan, 43 fallan** | 1 fallo era mío (cota de consultas de `PagBCarteraConsultaTest`, corregida conservando la constancia); los demás son **previos y ajenos**: expectativas de la tarifa de domicilio por `core_config` (reemplazada por «envío local por origen»), orden de listeners tras GRP-01, conteos globales de pagos (6.360 clonados), textos «hasta PAG-C», menú/ACL de RMA, `ShippedNotification` doble (falla también sin mis listeners). No se comprobó uno por uno contra el respaldo: la clasificación sale de leer la causa de cada grupo |
| Recorridos A–H (HTTP, base limpia) | **255 verificaciones OK, 0 fallos** | `storage/logs/demo/ensayo_general_20261028.txt` |
| Visual (Chrome headless, 44 capturas: rol × 1440/390 × claro/oscuro) | sin desbordamiento horizontal; tema oscuro aplicado | asesora y umbral reciben **401** en Comisiones (el rol ASESOR VENTAS no tiene la clave de Comisiones: no se amplió el acceso; falta una vista propia — requisito documentado) |
| Interactivo | cancelar pendiente (500 **simulado** en el navegador → texto conservado → reintento real → 1 cancelación de 6 uds en la base) y decisión de cobro en recogida, en escritorio y móvil oscuro | no hubo teléfono físico |
| Rendimiento (instancia aislada, volumen: 13–18 pedidos, 17 líneas de comisión, 10 movimientos) | agregar referencias 3–4 s · confirmar 6,5 s · Comisiones 1,1 s (23 consultas) · cuenta 0,9 s (16 consultas) | servidor monohilo de PHP en Windows sin opcache; sin cuello de botella que impida la demo; sin prueba de 10.000 pedidos |

Pendiente en esta jornada: Cargues/99 Envíos no se ejecutó en la demo (la base de demo no trae los tipos configurados ni worker); concurrencia real de cancelar-vs-despachar (se serializa con el mismo bloqueo del pedido, sin prueba con procesos paralelos); revalidación de F6 por devolución recibida (cubierta por diseño: la huella es el saldo; probada solo con pagos).
