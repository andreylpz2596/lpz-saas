# 05 · Auditoría de logística, transportadoras y recaudos

Cobertura: estructura, restricciones, catálogo, documentación (`C6`, `CUENTAS_TRANSPORTADORAS`, `DESPACHO_V1_ARCHIVOS`, docs 10/13/15 de Cargues). No se ejecutó ningún flujo. Leyenda: [C] / [D] / [S].

## 1. Qué es real y qué es catálogo [C]

| Elemento | Realidad |
|---|---|
| Transportadoras sembradas | `BYLOPEZ` (propia), `99ENVIOS`, `INTER_RAPIDISIMO`, `DOMICILIARIO`, `RECOGIDA_BODEGA`, `OTRA` |
| Coordinadora, Servientrega, Envía | **no existen en catálogo** (Servientrega solo en un comentario de `EnvioNacional`); se manejarían con `OTRA` |
| APIs de transportadoras | **ninguna**: sin clientes HTTP en Logística/Cargues/VentasAsesor. Guías internas y externas se registran a mano o por cargue |
| FedEx | no instalado (memoria) |
| 99 Envíos | tratado como **intermediario** en el estado del proveedor (`estados_proveedor`, `FuenteEventoLogistico::reporte_proveedor`: «no es una entrega registrada ni validada por ByLopez»), pero **sembrado como `tipo=transportadora`** igual que Inter |

## 2. Despacho, guías, entregas

- Guías: `UNIQUE(transportadora_codigo, numero_guia)`, `UNIQUE(idempotency_key)`; consecutivo de guía interna (GI) propio; corrección auditada (`CorregirGuiaService` con `forceFill` encapsulado) [C].
- PDF de guía: solo PDF validado por contenido (firma `%PDF-` + `finfo`), disco `private`, reemplazo con historial (`vigente`/`reemplazado_en`), descarga autorizada por servicio de Logística, cabeceras `nosniff`/`no-store` [C].
- Entregas, retornos, recepciones de devolución, validaciones de entrega, eventos de stock: todos con `idempotency_key` UNIQUE [C]. 29 `lockForUpdate` + 22 transacciones [C].
- Pedido entregado incompleto / descuento doble de inventario / dos envíos por reintento: protegidos por diseño (UNIQUE + FOR UPDATE) [D]; **no re-probado hoy**.

## 3. Cuentas de transportadoras y recaudo

- Libro firmado (movimientos que suman 0 por liquidación), liquidaciones inmutables (`LIQ`), conciliación efectivo/compensado, «cobro reportado» separado del recaudo [D, docs C6/CUENTAS].
- Tabla de doble conteo (doc C6 §): doble pago/recaudo/liquidación/conciliación/compensación cubiertos por UNIQUE + sumas bajo bloqueo [D].
- Tope C1: Σ recaudo ≤ exigible por guía/pedido [D].

## 4. CONTROL CRÍTICO: deuda contada dos veces (99 Envíos ↔ transportadora física) — validado en la 2.ª etapa

**Conclusión: no existe una ruta de código que duplique una deuda entre 99 Envíos y una transportadora física. AUD-006 queda DESCARTADO como riesgo de duplicación** (se baja de P1-sospecha a P3 y se reemplaza por dos hallazgos reales de otra naturaleza, AUD-026 y AUD-027). Método: lectura del código que calcula cada cifra y consulta de lectura sobre los datos de dev; no se ejecutó ni modificó nada.

### 4.1 Dónde se calcula cada cifra (archivo y método)

| Concepto | Dónde nace | Qué es |
|---|---|---|
| **Recaudo esperado** | `Services/Despachos/RecaudoPorGuia::calcular()` y `contextoPedido()`; se guarda en `bylopez_lg_guias.recaudo_esperado` desde `DespachoService` (≈ líneas 160-222) | instrucción de cobro de UNA guía: `min(disponible, mercancía + flete)` |
| **Recaudo comprometido** | `RecaudoPorGuia::contextoPedido()`: `Σ recaudo_esperado` de **todas** las guías del pedido (`Guia::where('order_id')`, sin filtrar por transportadora) menos lo devuelto y recibido en bodega | techo por **pedido**, no por transportadora |
| **Recaudo reportado (99)** | `Cargues`: SP `sp_cg_envios_completos_*` (`recaudo_reportado`, `recaudo_interno`, `recaudo_diferencia`, `conciliacion_recaudo`) y `Logistica/Services/Entregas/ReporteProveedorService` | **dato informativo** del proveedor; el SP no escribe pagos, ni movimientos, ni cartera (cabecera del SP: «no crea … recaudos, Cartera ni comisiones») |
| **Dinero recibido** | `VentasAsesor/Services/Cartera/RegistrarPagoService` + `ConfirmarPagoService` → `bylopez_va_pagos` en estado `confirmado`; el cobro en entrega entra por `Logistica/Services/Entregas/CobrarEntregaService` y `Cuentas/CobroReportadoService` delegando en Cartera | único origen del dinero «recibido» |
| **Saldo pendiente** | `VentasAsesor/Services/Cartera/CarteraConsultaService::saldosDePedidos()` (recalculado bajo bloqueo en `RegistrarPagoService`, líneas ≈194-205) | `total − aplicado − devuelto − cancelado ± ajustes` |
| **Cuenta de la transportadora** | `Cuentas/GeneradorMovimientosCuenta::sincronizarEnvio()` + `ReglaCuentaEnvio::evaluar()` | libro firmado, **uno por envío (shipment)** |

### 4.2 Por qué no puede duplicarse (evidencia)

1. `bylopez_lg_guias` tiene `UNIQUE(shipment_id)` (migración `2026_10_09_100004`, línea 56): **un envío tiene una sola guía y por tanto una sola transportadora**. Un mismo envío no puede figurar bajo 99 y bajo Inter.
2. El libro de la cuenta se calcula **por envío**: la clave de idempotencia es `GeneradorMovimientosCuenta::clave(shipmentId, concepto, n)`; si una corrección cambia la transportadora de la guía, `aplicar()` **revierte** lo que quedó a nombre de la anterior (líneas ≈236-240).
3. El recaudo esperado tiene tope por pedido: `DespachoService` (línea ≈179) rechaza `RECAUDO_SUPERA_SALDO` si `recaudo > disponible`, con el pedido **bloqueado** (`pedidoBloqueado()`, `lockForUpdate`). `disponible = exigible − comprometido` suma todas las guías del pedido sin importar su transportadora.
4. Las unidades de cada guía salen de `qty_to_ship` de Bagisto (`UnidadesDespachables`): no se puede despachar dos veces la misma unidad, así que una segunda guía bajo otra transportadora solo puede llevar unidades distintas, cada una con su parte del recaudo.
5. El dinero recibido está topado por el saldo real: `RegistrarPagoService` revalida bajo bloqueo y rechaza `MONTO_EXCEDE_SALDO` («no se crean saldos a favor»).
6. `UNIQUE(transportadora_codigo, numero_guia)` y la comprobación previa `GUIA_DUPLICADA` impiden repetir el número dentro de una transportadora.
7. El «pagado/consignado» que informa 99 vive solo en tablas intermedias de Cargues (reporte); nunca marca `confirmado`, `liquidado` ni habilita comisiones.

### 4.3 Datos de dev (consulta de lectura)

| Transportadora | tipo | `liquida_cuenta_corriente` | guías | con recaudo | movimientos de cuenta |
|---|---|---|---|---|---|
| 99ENVIOS | transportadora | **0** | 3 | 3 | 0 |
| INTER_RAPIDISIMO | transportadora | **0** | 14 | 14 | 0 |
| 05 (domiciliario) | domiciliario | 1 | 13 | 10 | 19 |
| BRIAN (domiciliario) | domiciliario | 1 | 9 | 7 | 13 |
| 03, DOMICILIARIO, PICAP | varias | 0 | 1+1+1 | 3 | 0 |

### 4.4 Lo que sí quedó confirmado (no es duplicación)

- **AUD-026 (P2, funcional/decisión)** — Ni 99 ni Inter participan hoy en el libro de cuentas: `liquida_cuenta_corriente=0`. Consecuencias reales: (a) `Cuentas/CobroReportadoService::registrar()` rechaza guías de 99 con `guia_no_es_de_la_cuenta` (exige `liquida_cuenta_corriente === true`), aunque su cabecera lo describe como flujo de «99envios»; (b) el recaudo de esas 17 guías (`pendiente_liquidar`) **no tiene seguimiento de saldo por transportadora**: solo es una instrucción en la guía, y el dinero solo aparece cuando alguien lo registra en Cartera. No hay doble cuenta: hay **ausencia** de cuenta. Si el negocio quiere controlar lo que 99/Inter deben, hay que decidir cómo (activar la capacidad en ambas **no** duplica porque el libro es por envío, pero sí exige definir quién es el deudor: 99 o la transportadora física).
- **AUD-027 (P3, modelo)** — `99ENVIOS` está sembrado como `tipo=transportadora` (migración `2026_10_08_100001`); el hecho de que sea intermediario solo se modela en el estado del proveedor (`estados_proveedor`, `FuenteEventoLogistico::reporte_proveedor`). Si en el futuro ambos deben ser deudores con cuenta, conviene un campo «transportadora efectiva» antes de activar `liquida_cuenta_corriente`, para que el deudor sea **uno solo** por envío.
- Tabla `guias`: el mismo número de guía puede existir una vez bajo `99ENVIOS` y otra bajo `INTER_RAPIDISIMO` (índice por par). Solo sería un problema operativo (rastreo), no financiero, por los puntos 1-4.

### 4.5 Prueba necesaria (Fase 5, solo en BD de pruebas exclusiva)
Pedido con 2 unidades: despachar 1 con guía `99ENVIOS` y 1 con guía `INTER_RAPIDISIMO` (mismo número) → la suma de `recaudo_esperado` ≤ exigible; intento de recaudo excedente → `RECAUDO_SUPERA_SALDO`; corregir la transportadora de una guía → el movimiento se revierte y no queda doble. Caso concurrente: dos despachos del mismo pedido a la vez → el segundo espera el bloqueo y respeta el tope.

## 5. Cartera de 99 Envíos

Conciliación de pagos de 99 **no implementada** (doc 15: «análisis y flujo; NO implementado»). Hoy: 65 guías del archivo sin vínculo interno (dev, 2026-10-19). Impacto: los «pagado/consignado» de 99 no se cruzan con Cartera; la verificación es manual. Decisiones pendientes: composición de flete/seguro, qué se compara, tratamiento de «sin vínculo» (doc 15 §5).

## 6. Hallazgos

| ID | Prioridad | Estado | Resumen |
|---|---|---|---|
| AUD-006 | **P3 (descartado como duplicación)** | [C] validado | No existe ruta de duplicación 99↔física (§4.2); sustituido por AUD-026 y AUD-027 |
| AUD-026 | P2 | [C] | 99 e Inter no tienen cuenta corriente (`liquida_cuenta_corriente=0`): sin libro de saldo ni «cobro reportado» para 17 guías con recaudo; decisión de negocio |
| AUD-027 | P3 | [C] | 99ENVIOS sembrado como `transportadora`; falta «transportadora efectiva» antes de activar cuentas |
| AUD-011 | P2 | [C] | Conciliación 99 no implementada |
| AUD-008 | P2 | [C] | `cuentas.propuesta` y `cuentas.cobro` sin ACL: solo roles `all` los alcanzan; roles personalizados con permiso de cuentas reciben 401 |
| AUD-018 | P3 | [C] | `despachos/componente.blade.php` 2136 líneas, `entregas/componente` 1055 |
| AUD-017 | P3 | [C] | Disco `private` con `serve=true` (rutas firmadas de Laravel); fijar `false` en producción |
