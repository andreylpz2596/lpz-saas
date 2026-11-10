# 16 — Automatizaciones del cargue «99 Envíos — Envíos completos» (análisis y propuesta)

**Fecha:** 2026-11-02 · **Estado:** §1–§14 análisis y propuesta; **§15 A1+A2+A3 IMPLEMENTADOS en dev** (versión 3 del tipo activa). Despacho faltante (fase B) **no implementado**. Las secciones de análisis: **No se cambió código, configuración, procedimientos, datos ni estados; no se ejecutó ningún cargue, migración, commit ni push.** Lecturas de la BD de desarrollo dentro de transacciones revertidas. Sin datos personales: clientes y pedidos por id, sin nombres, teléfonos ni direcciones.

Marcas: **[C]** comprobado en código · **[E]** evidencia en el archivo o en la BD de dev (solo lectura) · **[PROPUESTO]** · **[PENDIENTE]** decisión de Camilo.

## 1. Archivo analizado [E]

| Archivo | SHA-256 (16) | Contenido |
|---|---|---|
| `docs/ejemplos/Envios_Completos_2026-11-03.xlsx` (Excel 2007+, extensión real `.xlsx`) | `59ac16625b5e0369` | 1 hoja `Envíos Completos`, **1 133 filas × 28 columnas** (mismo formato de 28 columnas de `10`/`13`; gemelas idénticas: `estado_del_envio` = `estado` en las 1 133) |

- 1 133 guías distintas, 0 repetidas. `fecha_envio` 2025-02-11 → 2026-10-30; `fecha_actualizacion` 2025-02-14 → 2026-10-31.
- Transportadora (código → nombre): `1` interrapidisimo 1 114 · `4` coordinadora 14 · `5` envia 2 · `2` servientrega 2 · `3` tcc 1. **Equivalencia activa en dev: solo `1` → `INTER_RAPIDISIMO`.**
- Contrapago: `Si` 1 117 · `No` 16. Una sola sucursal remitente.

## 2. Los tres casos de prueba [E]

**Método:** cruce del Excel con `bylopez_lg_guias` por **transportadora efectiva (equivalencia `1` → `INTER_RAPIDISIMO`) + número de guía exacto**, y en paralelo por **teléfono del cliente** (E.164 de VentasAsesor). 99 Envíos es el proveedor (`origen_guia = 99envios`), no la transportadora.
- Cruzan por transportadora + guía **8 filas** (además, la guía de la fila 732 está como `track_number` del envío nativo 8, pedido 11, sin guía de Logística: el procedimiento la rechazaría como `GUIA_NO_REGISTRADA`).
- Los **clientes 46, 47 y 48** (creados el 2026-11-02 entre 19:16 y 20:48) son los únicos recientes con **exactamente una fila del archivo por teléfono** y nombre **idéntico** al de su ficha. Los pedidos 100, 101 y 102 son suyos y los únicos de esos clientes. Por eso se identifican como los tres casos, no por ser los últimos registros.

| Cliente | Pedido | Fila del Excel (por teléfono) | Guía del Excel | Estado del archivo | Situación en la app |
|---|---|---|---|---|---|
| 46 | `BYL-00000100-CO` (id 100), creado 2026-11-02 20:14, contra entrega, total 76 500, 9 uds en 4 líneas, **0 despachadas** | 3 | `240099900005` (envío 2026-10-30 21:12) | **Creado** (act. = envío) | **Sin despacho ni guía.** Esa guía **sí existe**, pero en el **pedido 101** (cliente 47), envío 81 |
| 47 | `BYL-00000101-CO` (id 101), creado 20:28, total 51 000, 6 uds, **6 despachadas** (envío 81, 20:31, guía `240099900005`) | 8 | `240099900004` (envío 2026-10-28) | **Reclame en oficina** (act. 2026-10-30 15:20) | Su envío lleva la guía de la fila 3 (la del cliente 46). La guía de su fila (`240099900004`) está en el **pedido 91** (cliente 45, prueba del 2026-11-01), con **entrega registrada y validada** |
| 48 | `BYL-00000102-CO` (id 102), creado 20:51, total 45 000, 6 uds, **6 despachadas** (envío 83, 20:57) | 15 | `240099900003` (envío 2026-10-27) | **Entregada** (act. 2026-10-30 00:46) | Coherente: la guía es la de su envío 83. 0 entregas, 0 validaciones, 0 pagos |

**Evidencia de que las guías de 46 y 47 están cruzadas en la app** (indicio, no prueba): `valor_comercial − valor_servicio` del Excel se acerca al total del pedido del cliente que coincide por **teléfono**, no al del pedido que hoy tiene la guía:

| Guía | VC − VS (Excel) | Pedido por teléfono | Pedido que hoy tiene la guía |
|---|---|---|---|
| `240099900005` | 103 369 − 26 828,40 = 76 540,60 | 100 → 76 500 (dif. 40,60) | 101 → 51 000 |
| `240099900004` | 73 473 − 22 326,41 = 51 146,59 | 101 → 51 000 (dif. 146,59) | 91 → 90 000 |
| `240099900003` | 67 382 − 21 880,74 = 45 501,26 | 102 → 45 000 (dif. 501,26) | 102 → 45 000 |

**Dato que falta (uno solo):** confirmar si la asociación de guías en la app es **intencional** (para probar «guía con relación no inequívoca») o un **error de captura** (la `240099900005` era del pedido 100 y la `240099900004` del 101). Mientras tanto, la propuesta trata esos dos casos como **incidencias**, nunca como despacho o entrega automáticos.

**Casos tal como los trataría la propuesta (resumen; detalle en §3–§5):**

| Caso | Acción propuesta | Datos que faltan |
|---|---|---|
| 46 | Ninguna automática: «Creado» no prueba recepción. Además, el número de guía ya pertenece a otro envío → incidencia `GUIA_EN_OTRO_PEDIDO` | Confirmación del cruce (arriba) |
| 47 | Incidencia: el envío de la guía `240099900004` (pedido 91) ya tiene entrega registrada y validada, y el proveedor dice «Reclame en oficina» → `CONTRADICCION_PROVEEDOR`; además el teléfono de la fila es de otro cliente → `GUIA_CLIENTE_DISTINTO` | Igual |
| 48 | Entrega registrada automática del envío 83 (6 uds) con fuente 99 Envíos… **pero hoy la bloquea la regla de fecha**: entregada el 2026-10-30, despacho registrado el 2026-11-02 (`FECHA_ANTERIOR_AL_DESPACHO`). Ver decisión D3 | D3 (fecha) |

La misma regla de fecha afecta a los pedidos 69, 70, 71 y 72 (despachos registrados el 15 y 17-10 de guías entregadas entre el 01 y el 08-10).

## 3. Lo que hace hoy el flujo [C]

- **«Asociar una guía» ES despachar.** En Logística una guía solo nace con `DespachoService::despachar()`, que en una transacción crea el **Shipment real de Bagisto** (`ShipmentRepository::create`: descuenta `product_inventories` y mueve el pedido), la guía (`UNIQUE(transportadora_codigo, numero_guia)`) y el PDF opcional. No existe guía sin despacho ni un «alistamiento» previo. Las unidades salen de `UnidadesDespachables` (pendiente = pedido − enviado − reembolsado − cancelado; tope por stock de la bodega; una bodega por Shipment).
- **El cargue actual no crea nada**: escribe solo `bylopez_lg_guia_estados_proveedor` + eventos (procedimiento v2) y la vista de Entregas muestra «99 Envíos: Entregada» como dato informativo (`13` §14.3).
- **Entrega registrada** (`RegistrarEntregaService` ← `RegistrarEventoEnvio`): por unidades del Shipment, idempotente (clave + huella), fecha real no futura **y no anterior a `shipments.created_at`**, permiso `logistica.entregas.registrar`, **fuente** obligatoria. La fuente `integracion_transportadora` existe pero está **reservada** (`disponible() = false`). Efectos: unidades entregadas, el envío sale de «pendientes» y pasa a «por validar»; sincroniza la cuenta corriente **solo** si la guía liquida por cuenta (INTER_RAPIDISIMO: `liquida_cuenta_corriente = 0` → sin movimientos). No toca Bagisto, stock, pagos ni comisiones.
- **Entrega validada** (`ValidarEntregaService`): ratificación del administrador; exige 0 pendientes y ninguna devolución sin recibir. Es una de las condiciones de comisión, pero `ElegibilidadComisionService` exige además cobro confirmado en Cartera y dinero recibido.
- **Retorno de transportadora** (`RegistrarRetornoTransportadoraService`): misma base; motivo obligatorio; **no toca stock**. Queda en «Devoluciones por recibir» hasta la **recepción** (`RecibirDevolucionService`), que es lo único que reingresa las aptas y reduce el saldo exigible (C5).
- **Anulación** (`AnularEventoLogisticoService`): un evento equivocado se anula con motivo; nada se edita ni se borra.

## 4. Matriz de estados del archivo y automatización propuesta [E] + [PROPUESTO]

Lista **explícita y literal** (comparación exacta tras recortar espacios). Un estado no listado **solo se registra** (informativo, como hoy) y se señala `ESTADO_SIN_REGLA`. No se usa «distinto de Creado».

| Estado (literal) | Filas | Evidencia del archivo | ¿Prueba recepción? | Automatización propuesta |
|---|---|---|---|---|
| `Entregada` | 851 | 843 inter + 8 coordinadora | **Sí** (y entrega) | Despacho faltante (§5.1) si procede + **entrega registrada** (§5.2) |
| `Entregado` | 2 | solo coordinadora (sin equivalencia); 1 con act. < envío | Sí, pero dudoso | **Solo informativo** hasta que Camilo lo apruebe como sinónimo [PENDIENTE D1] |
| `Devolución ratificada` | 115 | 18 sin `valor_servicio` | **Sí** (la tuvo y la devuelve) | Despacho faltante si procede + **retorno reportado por la transportadora** (§5.3) |
| `Devolucion Regional` | 9 | — | Sí | Despacho faltante si procede; **sin retorno** (devolución en curso, no ratificada). Aviso «devolución en curso» |
| `DEVUELTA DESDE MONTERIA` | 1 | envia (sin equivalencia) | Sí | Igual que «Devolucion Regional» [PENDIENTE D1] |
| `Reclame en oficina` | 3 | — | **Sí** (en oficina de la transportadora) | Despacho faltante si procede. Sin entrega |
| `Reparto` | 1 | — | **Sí** | Despacho faltante si procede |
| `Intento de entrega` | 1 | — | **Sí** | Despacho faltante si procede |
| `Centro acopio` | 2 | — | **Sí** | Despacho faltante si procede |
| `A recibir por coordinadora` | 3 | coordinadora (sin equivalencia) | **No**: espera recogida | Informativo |
| `Creado` | 37 | act. = envío en las 37 | **No** | Informativo |
| `Confirmado por Cliente` | 7 | act. **< envío** en las 7 (confirmación previa) | **No** | Informativo |
| `Anulado` / `Anulada` | 95 / 2 | — | **No** (guía anulada) | Si la guía tiene despacho en la app → incidencia `GUIA_ANULADA_EN_PROVEEDOR` (no se anula el despacho ni se devuelve stock) |
| `Cerrado por incidencia` | 1 | coordinadora | Indeterminado | Incidencia para revisión |
| `Mercancía no despachada por el remitente` | 1 | tcc | **No** | Si hay despacho en la app → incidencia |
| *(vacío)* | 2 | servientrega | — | Hoy rechazada (campo obligatorio) |

Las columnas de despacho/entrega/retorno solo se disparan con la fila **aplicada** por el procedimiento (R8): un reporte `omitida_antigua`, `sin_cambio` o rechazado **no** genera hitos.

## 5. Propuesta por hito

### 5.1 Despacho faltante [PROPUESTO, con condiciones estrictas]

Solo para estados que prueban recepción (§4) y **solo** cuando todo esto es cierto; si falla cualquiera → **incidencia** `DESPACHO_FALTANTE_REVISION` con el motivo, sin efectos:

1. La guía (transportadora efectiva + número) **no** existe en Logística ni como `track_number` de otro envío.
2. El teléfono de la fila identifica **un** cliente (identidad de VentasAsesor, nombre idéntico con la regla estricta de `14` §15).
3. Ese cliente tiene **exactamente un** pedido despachable (`canShip()`, no cancelado/cerrado, no recogida) y ese pedido tiene **0 unidades despachadas** (sin guías previas).
4. **Cantidades y variantes = todas las unidades pendientes de ese pedido** (`UnidadesDespachables::pendientes`). El Excel **no** se usa para saber qué prendas salieron (`Producto` es texto libre).
5. Las reglas del despacho actual pasan tal cual, llamando a **`DespachoService::despachar()`**: stock suficiente en una bodega, disposición de cobro (F6), pago en validación, recaudo C1 (sugerido del servidor, nunca el del Excel), guía no duplicada. Si lanza error → incidencia con ese código.
6. [PENDIENTE D4] Pedido creado **antes** de `fecha_envio` del proveedor. (Los casos de prueba de hoy son posteriores: en producción sería señal de que no es el mismo envío.)

Rechazos a incidencia, nunca despacho: pedido con despacho parcial o varias guías, varios pedidos abiertos, cliente sin identificar o con nombre distinto, pedido anulado, stock insuficiente, guía ya usada en otro pedido, transportadora sin equivalencia.

Idempotencia: clave `99envios:despacho:<transportadora>:<guía>` en `DespachoService` (la misma clave + huella devuelve el mismo despacho) y el `UNIQUE` de la guía. Repetir el archivo no crea otro Shipment ni otro movimiento de stock.

### 5.2 Entrega reportada por 99 Envíos [PROPUESTO]

- **Paso automatizado: solo «entrega registrada».** Nunca la «entrega validada» (sigue manual: es la ratificación del administrador y una condición de comisión) [PENDIENTE D2].
- Por `RegistrarEntregaService` con fuente nueva **`proveedor_99envios`** (o habilitar `integracion_transportadora` solo para este origen; [PENDIENTE D2]), actor = administrador que ejecuta, fecha = fecha del proveedor (`fecha_actualizacion`, solo fecha), `evidencia_referencia` = `99 Envíos · cargue N · fila F · «Entregada» · <fecha y hora>`. Clave `99envios:entrega:<shipment>`.
- Unidades = **todas las pendientes del envío de esa guía** (la guía es de un solo Shipment). Si el envío ya tiene unidades entregadas o devueltas → no se completa nada: incidencia.
- **Varios envíos por pedido:** cada guía mueve **solo su envío**. El pedido figura «entregado» solo cuando todos sus envíos lo están (la derivación actual de `ConsultaEntregaService` ya lo hace).
- **Visibilidad:** sale de «Pendientes» (0 unidades pendientes) y aparece en «Entregados», «Por validar», historial y filtros, con la etiqueta **«Entregada según 99 Envíos»** (fuente) y la procedencia.
- **No produce** pagos, dinero recibido, recaudo liquidado ni comisión habilitada. Con INTER_RAPIDISIMO no genera movimiento de cuenta corriente (no liquida por cuenta).
- **Bloqueo actual de fecha [C]:** `RegistrarEventoEnvio` rechaza una fecha anterior a `shipments.created_at`. Como los despachos se registran después de la entrega real (caso 48 y pedidos 69–72), la automatización fallaría casi siempre. [PENDIENTE D3]: para la fuente del proveedor, aceptar una fecha anterior al registro del despacho pero **no anterior a la `fecha_envio` del proveedor**, guardando ambas fechas.

### 5.3 Devolución ratificada [PROPUESTO]

- Por `RegistrarRetornoTransportadoraService` con la misma fuente, motivo `«Devolución ratificada (99 Envíos)»`, fecha del proveedor y la misma evidencia; clave `99envios:retorno:<shipment>`; unidades = todas las pendientes del envío.
- **No** aumenta stock, **no** marca recibida ni inspeccionada: aparece en **«Devoluciones por recibir»** (indicador y filtro «Retornados») con la etiqueta «Reportada por 99 Envíos · pendiente de recibir en bodega». Ya no está en «Pendientes de entrega», así que no se confunde con una entrega pendiente.
- La recepción física, el reingreso de las aptas y la reducción del saldo (C5) siguen manuales.
- Si el envío ya tiene una entrega registrada → no se registra nada: incidencia `CONTRADICCION_PROVEEDOR`.
- «Devolucion Regional» y similares: sin retorno; solo aviso.

### 5.4 Contradicciones y correcciones del proveedor [PROPUESTO]

- **Nunca se revierte un efecto en silencio.** Si un reporte posterior contradice un hito ya registrado (Entregada → Devolución ratificada, Entregada → Reclame en oficina, retorno → Entregada, guía anulada con despacho) → incidencia `CONTRADICCION_PROVEEDOR` con el estado anterior y el nuevo. La corrección es manual, con `AnularEventoLogisticoService` (motivo y auditoría), y luego se registra el evento correcto.
- **No retroceso:** se mantiene la regla por `fecha_actualizacion` del procedimiento (R5/R6). Los hitos solo salen de filas aplicadas como más recientes.
- **Repetir el archivo:** claves deterministas por envío + huella. Si el mismo hito llega con otra fecha del proveedor, se marca `sin_cambio` con observación; no se crea otro evento ni da conflicto de idempotencia.
- Ya existe un caso real de contradicción en dev: pedido 91, envío 69, con entrega registrada y validada el 2026-11-01 y el proveedor en «Reclame en oficina».

## 6. Dinero: qué está definido y qué no [E] + [PENDIENTE]

Caso 48 como ejemplo (únicamente aritmética sobre los datos; no son fórmulas aprobadas):

| Concepto | Dónde vive | Valor (caso 48) | ¿Definido? |
|---|---|---|---|
| Valor del pedido | `orders.base_grand_total` | 45 000 (envío 0) | Sí |
| Recaudo esperado | `bylopez_lg_guias.recaudo_esperado` (C1) | 45 000 | Sí (lo que la transportadora debe cobrar por esa guía, según ByLopez) |
| Recaudo reportado | `valor_comercial` si hay contrapago (`13` §4) | 67 382 | Sí (el procedimiento lo compara **exacto** → `DISCREPANCIA_RECAUDO` +22 382) |
| Descuentos de 99 Envíos | `valor_servicio` (literal) | 21 880,74 | **No**: composición pendiente (flete, comisión de recaudo, seguro, IVA) |
| Neto que debe consignar 99 Envíos | Cartera: `Consignado` = parte entera (VC − Flete) en «pagado» (`13` §9) | 45 501 por esa observación | **No aprobado** como regla |

- La diferencia de los tres casos (VC − VS − pedido: 40,60 · 146,59 · 501,26) **no es cero ni constante**. Las guías tienen `flete_pagador = cliente_al_recibir` y `valor_flete` vacío: el cliente paga el flete al recibir y 99 Envíos lo cobra dentro de `valor_comercial`, pero ByLopez no lo registra en el recaudo. Por eso **cualquier** guía así dará `DISCREPANCIA_RECAUDO`. [PENDIENTE D6].
- «Entregada» **no** registra pagos, dinero recibido, consignación ni comisiones.

## 7. Cartera posterior [PROPUESTO, sin implementar]

- **Cruce:** `Nº Guía` de Cartera → `bylopez_lg_guia_estados_proveedor` (proveedor `99envios`) → guía → envío → pedido. Cartera **no trae transportadora**: si el número existe en más de una transportadora del proveedor → `GUIA_AMBIGUA` (no se elige).
- **Contraste:** `Consignado` (lo que 99 Envíos dice haber pagado) contra el neto esperado, una vez aprobada la fórmula (D6); diferencias como observación, nunca ajuste automático.
- **Confirmar el cobro:** la vía existente es `CobroReportadoService` (C6), que delega en Cartera. **Hoy no aplica a estas guías:** exige `liquida_cuenta_corriente = true` en la guía y la cuenta es **por transportadora** (INTER_RAPIDISIMO = 0), mientras que quien consigna es **99 Envíos**. Consecuencia actual: `ElegibilidadComisionService` bloquea la comisión de estos pedidos («recaudo sin cuenta que pruebe la recepción»). [PENDIENTE D7]: a quién se le abre la cuenta (99 Envíos como titular del recaudo, o la transportadora con liquidación vía 99 Envíos).
- El cargue de Envíos completos deja lo necesario para ese cruce: guía, transportadora efectiva, proveedor, estado, fechas, valores reportados y procedencia. No hace falta nada nuevo en él para Cartera.

## 8. Flujo propuesto: de VALIDAR al resultado

1. **Preparar (extensión, solo lectura, `derivar`):** por fila, transportadora efectiva, guía existente/envío/pedido, unidades pendientes del envío, clasificación del estado (lista §4) y, para despacho faltante, cliente por teléfono y pedido candidato único.
2. **VALIDAR (procedimiento v3, cuenta restringida):** las reglas de v2 + clasificación del plan por fila: `estado_solo`, `despacho+entrega`, `entrega`, `retorno`, `incidencia:<código>`. Sin escrituras de negocio (como hoy). La pantalla muestra cuántas filas tendrían cada efecto **antes** de ejecutar.
3. **EJECUTAR:** el procedimiento escribe el estado del proveedor (como hoy, transacción del motor) y deja planeadas las filas con hito. La **extensión `aplicar`** procesa cada fila en su transacción con los **servicios de Logística** (despacho → entrega/retorno), revalidando todo. Un fallo marca la fila como incidencia y sigue; repetir procesa solo las pendientes.
4. **Resultado:** conteo por efecto (despachos creados, entregas registradas, retornos registrados, incidencias por código, solo estado) y descarga «Incidencias para revisión».

**Qué queda pendiente siempre:** validación de entrega, recepción e inspección de devoluciones, cobro y consignación (Cartera), comisiones.

## 9. Piezas afectadas y riesgos

| Pieza | Cambio | Riesgo concreto |
|---|---|---|
| `Cargues`: nuevo map + `sp_cg_envios_completos_v3.sql` (versión nueva del tipo; v2 intacto) | Plan por fila | La cuenta restringida necesita `SELECT` sobre más tablas (pedidos, líneas, clientes): revisar `12` |
| `Cargues`: extensión `EnviosCompletosHitos` (registro en `cargues.extensiones`) | Llama a servicios de Logística | Es la primera extensión que **crea Shipments y mueve stock**. Un error de cruce despacha el pedido equivocado → condiciones §5.1 y prueba con las guías cruzadas de los casos 46/47 |
| `Logistica`: `FuenteEventoLogistico` (fuente del proveedor) | Habilita una fuente nueva | Debe seguir prohibida en el panel manual; solo la extensión |
| `Logistica`: `RegistrarEventoEnvio::fechaValida`/regla de fecha | Excepción acotada (D3) | Ampliarla a todas las fuentes debilitaría el control manual |
| `Logistica`: `DespachoService` | Sin cambios de reglas; actor admin, clave determinista | El Shipment nace con fecha de hoy (no la del proveedor); se guarda la del proveedor como dato aparte |
| `Logistica`: `ConsultaEntregaService` + vistas de Entregas | Etiquetas de fuente y bandeja de devoluciones reportadas | Ninguno de cálculo: la derivación ya es por unidades |
| Tabla nueva de **incidencias del cargue** (o reutilizar las incidencias T7 del motor) | Revisión | Duplicar bandejas: preferir T7 + descarga |
| `VentasAsesor` (comisiones) | Ninguno | La comisión sigue bloqueada para 99 Envíos hasta D7 |

## 10. Decisiones mínimas [PENDIENTE]

| # | Decisión | Recomendación |
|---|---|---|
| D0 | Casos 46/47: ¿guías cruzadas a propósito o error de captura? | Dato necesario para la prueba |
| D1 | Lista exacta de estados (§4); ¿«Entregado» y «DEVUELTA DESDE MONTERIA» cuentan? | Aprobar la tabla; sinónimos solo con transportadora con equivalencia |
| D2 | Fuente: nueva `proveedor_99envios` o habilitar `integracion_transportadora` solo para el cargue; automatizar **solo** la entrega registrada | Fuente nueva propia; validación manual |
| D3 | Fecha de entrega anterior al registro del despacho, solo con esta fuente y ≥ `fecha_envio` | Aprobar (si no, la automatización casi nunca aplica) |
| D4 | Despacho faltante: ¿se exige pedido creado antes de `fecha_envio`? ¿automático o propuesta con un clic? | Automático solo con todas las condiciones de §5.1; incluir D4 en producción |
| D5 | Contradicciones: solo incidencia, nunca reversa | Aprobar |
| D6 | Flete que paga el cliente al recibir: ¿se registra en la guía para que el recaudo coincida? | Decidir antes de conciliar Cartera |
| D7 | Titular de la cuenta corriente del recaudo de 99 Envíos | Decidir antes del cargue de Cartera |

## 10b. Comportamiento solicitado por Camilo el 2026-11-02 [APROBADO · Entregada y Devolución IMPLEMENTADAS en §15; despacho faltante NO implementado]

- **Entregada:** registrar **y validar** automáticamente la entrega logística (reemplaza la recomendación de §5.2 y de D2 de validar a mano). El dinero sigue pendiente de conciliación. **Efecto a tener en cuenta [C]:** crear una `ValidacionEntrega` dispara `SolicitarReevaluacionComision`, y la reevaluación recalcula el mes comercial desde `orders.created_at` (`EvaluarComisionesService:94`, `LineasComisionService:55`). La comisión sigue sin habilitarse sin cobro confirmado ni dinero recibido (`ElegibilidadComisionService`, condiciones 4–5).
- **Devolución ratificada:** retorno reportado por la transportadora, pendiente de recepción física y conteo en bodega (§5.3).
- **Despacho faltante:** solo con asociación inequívoca de guía, pedido y prendas, sin duplicar stock ni Shipment (§5.1).

## 12. Fechas preparadas para prueba del pedido 102 (2026-11-02) [DESCARTADO por Camilo: NO aplicar]

> **Camilo revisó esta sección y decidió no aplicar el script ni cambiar meses de comisión.** Se reemplaza por el archivo sintético de §13. El script y el respaldo quedan solo como registro; no deben ejecutarse con `--aplicar`. D8 queda resuelta por §13.

Autorización de Camilo: ajustar en la BD de desarrollo las fechas internas de los pedidos 100–102 (pruebas creadas **después** de los eventos del Excel), sin flexibilizar validaciones. Solo el 102 es inequívoco; 100 y 101 quedan bloqueados hasta resolver las guías cruzadas (§2, D0).

**Qué consulta realmente la validación [C]:**
- `RegistrarEventoEnvio`: la fecha de la entrega debe ser ≥ `DATE(shipments.created_at)` y no futura.
- El procedimiento v2 compara solo fechas del proveedor (`fecha_envio`, `fecha_actualizacion`) con las guardadas del mismo proveedor, nunca con fechas internas.
- `DespachoService` no valida fechas.
- Las **comisiones** usan `orders.created_at` para el mes comercial.

**Fechas del proveedor (Excel fila 15, no se cambian):** `fecha_envio` 2026-10-27 20:05:01 · «Entregada» 2026-10-30 00:46:41.

**Ajuste preparado** (desplazamiento constante −6 d 00:51:00; conserva los intervalos internos):

| Registro | Antes | Después |
|---|---|---|
| `orders#102` created/updated | 2026-11-02 20:51:06 / 20:51:09 | 2026-10-27 20:00:06 / 20:00:09 |
| `order_items#807–812` | created 20:51:06; updated 20:57:01–03 | 2026-10-27 20:00:06; 20:06:01–03 |
| `addresses#477, 478`, `order_payment#102` | 20:51:06 | 2026-10-27 20:00:06 |
| `invoices#100` | 20:51:09 | 2026-10-27 20:00:09 |
| `shipments#83` | 20:57:00 / 20:57:03 | 2026-10-27 20:06:00 / 20:06:03 |
| `shipment_items#470–472` | 20:57:01–02 | 2026-10-27 20:06:01–02 |
| `bylopez_lg_guias#42` | 20:57:03 | 2026-10-27 20:06:03 |

Secuencia resultante: pedido 20:00:06 < guía en 99 Envíos 20:05:01 < despacho 20:06:00 < entrega 2026-10-30. **16 filas.** Solo `created_at`/`updated_at`; cantidades, stock, importes, pagos, comisiones, el Excel y las fechas del proveedor no se tocan.

**Comprobación (transacción revertida, sin efectos):** antes, regla de fecha `false`; después, `true`; huella de negocio idéntica (stock total, importes y cantidades del pedido y sus líneas, envío, guía, factura, pagos, líneas de comisión, umbral, conteos de entregas, retornos, validaciones y envíos). La ida y vuelta (ajuste + restauración) deja valores **idénticos** al respaldo.

**Por qué NO se aplicó:** el mes comercial del pedido pasaría a **2026-10**, mientras sus 3 líneas de comisión (`estimada`) y sus 3 movimientos de umbral siguen guardados en **2026-11**. La primera reevaluación, que la provocará la validación automática pedida en §10b, movería la comisión de mes. Eso altera comisiones y no está autorizado. Decisión **D8**.

**Fuera del alcance a propósito (siguen el 2026-11-02):** cliente 48 (creado a las 20:48:06; quedaría posterior al pedido), líneas de comisión, umbral, reevaluación, costos de venta, rentabilidad, atribución, clasificación, borrador, snapshot de grupo, bitácora de asignación y notificación.

**Script y respaldo:** `docs/pruebas_cargues/fechas_pedido_102/fechas_prueba_102.php` (modos `--respaldo` · `--simular` · `--simular-restauracion` · `--aplicar` · `--restaurar`) y `fechas_prueba_102.respaldo.json` (solo ids y fechas). Guardas: BD `lpz_saas`, puerto 3306, `APP_ENV=local`, ids exactos; cada UPDATE exige el valor actual esperado y aborta todo si una fila no coincide; `--aplicar` revierte si cambia la huella de negocio.

| # | Decisión | Opciones |
|---|---|---|
| D8 | Comisiones del pedido 102 al moverlo a octubre | (a) aplicar y aceptar que las comisiones de prueba del 102 se recalculen en 2026-10; (b) aplicar moviendo también `mes_comercial`/`mes` de sus 3 líneas y 3 movimientos de umbral (alterar comisiones, con autorización expresa); (c) no mover el pedido y crear pedidos de prueba nuevos con una guía nueva del proveedor |

## 13. Archivo de prueba SINTÉTICO del pedido 102 (2026-11-02) [PREPARADO · cargue NO ejecutado]

**Ruta:** `packages/ByLopez/Cargues/docs/pruebas_cargues/SINTETICO_Envios_Completos_pedido102_guia240099900003.xlsx` (SHA-256/16 `08e4c666196a10d0`). Se identifica como **SINTÉTICO** en el nombre y en las propiedades del documento (título, asunto, descripción y palabras clave). No se agregó ninguna columna ni hoja, para no cambiar la firma de encabezados que compara el tipo.

- **Origen:** la fila 15 de `docs/ejemplos/Envios_Completos_2026-11-03.xlsx`, la única con la guía `240099900003`. El original queda **intacto** (SHA-256/16 `59ac16625b5e0369` antes y después).
- **Estructura:** misma hoja `Envíos Completos`, los mismos 28 encabezados, 1 fila de datos y todas las celdas como texto, igual que el original.
- **Verificación celda a celda:** solo difieren `fecha_envio` y `fecha_actualizacion`. Las gemelas R–Z no contienen fechas.
- **Datos personales:** la fila conserva los datos personales del original (no se pidió cambiarlos). No debe publicarse ni subirse a git.

| Campo del proveedor | Original (Excel real) | Prueba (sintético) | Motivo |
|---|---|---|---|
| `fecha_envio` (C) | 2026-10-27 20:05:01 | **2026-11-02 20:55:00** | Después del pedido (20:51:06) y antes del despacho (20:57:00), como cuando la guía se genera en 99 Envíos y luego se registra |
| `fecha_actualizacion` (N), estado `Entregada` | 2026-10-30 00:46:41 | **2026-11-02 21:30:00** | Después del despacho y anterior al momento de generar el archivo (21:58), sin fechas futuras |

**Zona horaria [C]:**
- `APP_TIMEZONE = America/Bogota` (PHP y Laravel); MariaDB `time_zone = SYSTEM`, con el sistema en America/Bogota (UTC−5).
- El motor toma las fechas tal cual vienen en el texto, sin conversión (`Normalizador::fecha`, formato `Y-m-d H:i:s`), y las guarda como `DATETIME` sin zona.
- `RegistrarEventoEnvio` compara la fecha con `CarbonImmutable::today()` (America/Bogota) y con `DATE(shipments.created_at)`.
- Las fechas del proveedor se interpretan, por tanto, como hora de Colombia. Que 99 Envíos exporte en esa zona sigue sin confirmar (`10` D-ENV-3).

**Comprobación en solo lectura** (sin crear una carga ni escribir nada):
- Guardián XLSX y lector del módulo: 1 hoja visible, 2 filas, 56 celdas; el lector devuelve 1 fila con las fechas de prueba.
- Reglas del procedimiento v2 (versión activa 2 del tipo «99 Envíos — Envíos completos»):
  - R1: el código `1` tiene equivalencia `INTER_RAPIDISIMO`.
  - R2/R3: existe exactamente 1 guía con esa transportadora y ese número (guía 42, envío 83, pedido 102).
  - R4–R7: la guía no tiene reportes previos.
  - Resultado esperado: **aplicada (insertada)** con la observación **`DISCREPANCIA_RECAUDO`** (reportado 67.382 frente a interno 45.000; diferencia +22.382).
- **Cronología:** pedido 20:51:06 < envío del proveedor 20:55:00 < despacho 20:57:00 < entregada 21:30:00 ≤ ahora.
- **Regla de fecha que usará la entrega automática:** 2026-11-02 ≥ fecha del despacho y no futura → **se cumple**, sin tocar el pedido ni el envío.

**Efecto que debe conocerse antes de ejecutar:** al ejecutar el cargue sintético, la guía 42 queda con un estado del proveedor y un evento de historial (append-only) con **fechas sintéticas**.
- Si después se carga el Excel real, su fila de la guía 42 se rechazará con `GUIA_FECHA_ENVIO_DISTINTA` (2026-10-27 frente a 2026-11-02) o quedará como `omitida_antigua`.
- Es aceptable en dev porque el pedido 102 es de prueba, pero ese reporte queda marcado para siempre como sintético por su procedencia (número de carga y archivo).

**Qué permite probar HOY el cargue con este archivo (sin implementar nada):**
- **VALIDAR:** 1 fila válida. Relación: 9 campos sugeridos, más `aplica_contrapago`, que se relaciona a mano con la columna AB.
- **EJECUTAR:** 1 aplicada (insertada) con la observación `DISCREPANCIA_RECAUDO`.
- **Logística › Entregas:** el envío 83 aparece como «Cruzada con 99 Envíos», «99 Envíos: Entregada» y «Recaudo difiere», y **sigue pendiente** (0 entregas, 0 validaciones). Sin cambios en stock, pagos, Cartera ni comisiones.
- **Repetir el mismo archivo:** 1 sin cambio.

**Qué no podía probar con la versión 2 (ya implementado en §15, salvo el despacho faltante):** la entrega registrada y validada automáticamente, el retorno por devolución ratificada y el despacho faltante.

## 14. Plan corregido para aprobar (reemplaza §11; las secciones §5.2 y D2 quedan superadas por §10b)

**Objetivo aprobado:**
- **Entregada:** registrar **y validar** automáticamente la entrega logística; el dinero queda pendiente de conciliación.
- **Devolución ratificada:** retorno reportado por la transportadora, pendiente de recepción física y conteo en bodega.
- **Despacho faltante:** solo con asociación inequívoca de guía, pedido y prendas, sin duplicar stock ni Shipment.

| Fase | Qué se implementa | Reglas | Prueba |
|---|---|---|---|
| **A1 — Entregada** | Versión 3 del tipo (`sp_cg_envios_completos_v3`; la v2 queda intacta) que planifica `entrega` solo en filas **aplicadas** (R8) con estado literal `Entregada`, guía existente y envío con **todas** sus unidades pendientes. Extensión `aplicar`, una transacción por fila: `RegistrarEntregaService` (todas las unidades pendientes del envío) y luego `ValidarEntregaService` | Fuente propia del proveedor, no disponible en el panel manual; fecha = fecha del proveedor; evidencia = cargue, fila, estado y fecha; claves fijas `99envios:entrega:<envío>` y `99envios:validacion:<envío>`; actor = administrador que ejecuta. Si la validación no procede (por ejemplo, una devolución vigente), queda la entrega registrada con una incidencia y no se fuerza | Archivo sintético §13 sobre el pedido 102: 1 entrega y 1 validación; el envío sale de «Pendientes» y aparece en «Entregados» e historial; repetir = sin cambio; stock, pagos y Cartera intactos; comisión del 102 reevaluada pero no habilitada (sin cobro ni dinero) |
| **A2 — Devolución ratificada** | Plan `retorno` con estado literal `Devolución ratificada`: `RegistrarRetornoTransportadoraService` con motivo «Devolución ratificada (99 Envíos)» | Sin stock ni recepción; aparece en «Devoluciones por recibir» como «Reportada por 99 Envíos»; con una entrega previa → incidencia | Un archivo sintético nuevo sobre un pedido de prueba despachado: 1 retorno, stock sin cambio, recepción manual después |
| **A3 — Controles** | Incidencias en el resultado (T7) y descarga «Incidencias para revisión» | No retroceso por fecha del proveedor; contradicciones solo como incidencia (D5), nunca anular en silencio | Reporte antiguo → sin hito; contradicción → incidencia |
| **B — Despacho faltante** | Plan `despacho` (§5.1) con `DespachoService` | Solo si la guía no existe, hay un único cliente por teléfono con nombre idéntico y un único pedido despachable sin nada despachado, y se despachan todas sus unidades pendientes. Cualquier duda → incidencia. **Bloqueado para los pedidos 100 y 101 hasta D0** | Después de D0 |

**Decisiones que siguen abiertas:**
- **D0:** guías de los pedidos 100 y 101.
- **D1:** lista literal de estados (§4).
- **D3:** fecha de entrega anterior al registro del despacho. Hace falta para datos reales (pedidos 69–72), no para el archivo sintético.
- **D4:** condiciones del despacho faltante.
- **D5:** contradicciones solo como incidencia.
- **D6:** flete que paga el cliente al recibir.
- **D7:** titular de la cuenta de recaudo de 99 Envíos.

**Decisiones resueltas:** D2 (registrar y validar, §10b) y D8 (archivo sintético en lugar de mover fechas).

## 15. A1 + A2 + A3 IMPLEMENTADOS en desarrollo (2026-11-02) [IMPLEMENTADO · PROBADO en instancia aislada · prueba manual pendiente de Camilo]

Autorización de Camilo: implementar en dev A1, A2 y A3 de §14. **No** se implementó el despacho faltante (B), **no** se reasignaron las guías de los pedidos 100/101, **no** se flexibilizó ninguna fecha, **no** se cambiaron reglas financieras y **no** se ejecutó ningún archivo sobre el pedido 102. Sin producción, Webkul, commit ni push.

### 15.1 Qué hace la versión 3 del tipo

| Estado literal del archivo (fila que es el reporte VIGENTE de su guía) | Efecto |
|---|---|
| `Entregada` | Entrega **registrada** de las unidades pendientes de **ese** envío y entrega **validada** (`RegistrarEntregaService` + `ValidarEntregaService`). Si la validación no procede, la entrega queda registrada y la fila lo dice (`VALIDACION_PENDIENTE`) |
| `Devolución ratificada` | **Retorno de transportadora** de las unidades pendientes (`RegistrarRetornoTransportadoraService`), motivo «Devolución ratificada (99 Envíos)». Queda en «Devoluciones por recibir»: **sin** recepción, conteo ni reingreso de stock |
| Cualquier otro estado | Solo el estado reportado, como en v2 |
| Reporte antiguo (R6), rechazado o superado por otro posterior | Ningún hito |

- **Fuente nueva de Logística:** `reporte_proveedor` («Reporte de 99 Envíos (cargue)»). El panel manual no puede usarla, porque la fuente de los registros del panel la decide `FuentePanel`.
- **Datos del registro:** fecha del hito = fecha del estado del proveedor (sin hora); evidencia = estado, fecha y hora del proveedor, número de cargue, fila y guía.
- **Actor:** el administrador que creó la carga (debe seguir siendo administrador activo; si no, no se aplica nada y la carga queda reanudable).
- **Reglas de Logística sin cambios:** permiso, unidades pendientes bajo bloqueo del envío, **fecha no anterior al despacho ni futura**, idempotencia y cuenta corriente.
- **Contradicciones:** «Entregada» con una devolución vigente, o «Devolución ratificada» con una entrega vigente, generan la incidencia `CONTRADICCION_PROVEEDOR`. No se registra ni se revierte nada; el estado reportado sí se actualiza, porque es lo que informa el proveedor.
- **Hito anulado a mano en Logística:** genera `HITO_ANULADO_EN_LOGISTICA` y no se vuelve a registrar.
- **Varios envíos por pedido:** cada guía mueve **solo su envío**. El pedido figura entregado solo cuando lo están todos sus envíos (derivación existente).
- **Dinero:** no se crean pagos, aplicaciones, movimientos de Cartera ni dinero recibido.
  - Validar dispara la **reevaluación de comisiones existente**, que sigue exigiendo cobro confirmado y dinero recibido (`ElegibilidadComisionService`): no se habilita ninguna comisión.
  - Con INTER_RAPIDISIMO (`liquida_cuenta_corriente = 0`) no hay movimiento de cuenta corriente.

### 15.2 Idempotencia, repetición y recuperación
- **Claves fijas por envío:** `99envios:entrega:<envío>`, `99envios:validacion:<envío>` y `99envios:retorno:<envío>`. Se consultan **antes** de registrar: repetir el archivo, otra carga con el mismo reporte o la reejecución nunca duplican un hito.
- **«Sin cambio» del reporte no bloquea la recuperación:** en v3, una fila «Entregada» o «Devolución ratificada» ya registrada con la misma fecha (R7) queda **planeada** (`ya_registrado`) para la extensión, que completa solo lo que falte. Ejemplo: la validación que falló la primera vez. Si no falta nada, la fila termina como `sin_cambio:hito_ya_registrado`.
- **Error inesperado durante la aplicación:**
  - La fila se revierte entera y la carga queda `fallida_ejecucion` con el código `APLICACION_INTERRUMPIDA`.
  - Las filas ya aplicadas quedan marcadas.
  - «Ejecutar de nuevo» procesa solo las pendientes. El estado reportado que ya se había guardado se reconoce como aplicado por esa misma carga y fila, sin crear otro evento.
- **Recuperación del motor (`Recuperar`):** con extensión, si las filas sin marca tienen plan, el aviso ya no dice «no se aplicó nada», porque el procedimiento sí confirmó su parte.

### 15.3 Resultado visible
- **Cargues › Resultado (y al abrir desde el Historial):**
  - **Detalle por fila:** `entrega_registrada_y_validada`, `entrega_registrada_validacion_pendiente`, `validacion_completada`, `retorno_registrado_pendiente_recepcion`, `hito_ya_registrado`, `contradiccion`, `entrega_no_registrada`, `retorno_no_registrado`, `…_anulado_en_logistica` o `reporte_superado`.
  - **Línea nueva «Hito logístico (Logística)»:** «entrega registrada #N del AAAA-MM-DD · validada #M», «… · validación PENDIENTE (vuelva a cargar el archivo o valide en Logística › Entregas)» o «devolución reportada #N del … · pendiente de recepción física en bodega (stock sin cambios)».
  - **Incidencias:** quedan como **advertencias** de la etapa de ejecución (descargables).
- **Logística › Entregas:**
  - **Entregada:** el envío sale de «Pendientes» y aparece en «Entregados», con la validación confirmada. En el detalle se ven la fuente «Reporte de 99 Envíos (cargue)» y la evidencia.
  - **Devolución ratificada:** el envío aparece en «Retornados» y suma a «Devoluciones por recibir».

### 15.4 Archivos

| Archivo | Cambio |
|---|---|
| `Cargues/src/Database/Procedures/sp_cg_envios_completos_v3.sql` + `.map.json` | Nuevos. Generados desde v2 con reemplazos exactos: R1–R6 y la escritura del estado (R8a–c) idénticos; R7/R8d dejan planeadas las filas con hito. El map v3 = v2 + `extension: envios_completos_hitos`. La v2 queda en el repositorio como historia |
| `Cargues/src/Extensiones/EnviosCompletos/EnviosCompletosHitosExtension.php` | Nueva extensión (aplica fila por fila con los servicios de Logística) |
| `Cargues/src/Extensiones/Concerns/AplicaPorFila.php` | Nuevo trait: `marcar` y `exigirMismaBase`, antes solo en Clientes históricos (que ahora lo usa) |
| `Cargues/src/Config/cargues.php` | Registro `envios_completos_hitos` |
| `Cargues/src/Services/Cargas/EjecucionService.php` | Mensaje de `Recuperar` con extensión y plan confirmado |
| `Cargues/src/Services/Cargas/Complementos/EnviosCompletosResultado.php` + lang es/en | Línea «Hito logístico» |
| `Cargues/src/Services/Cargas/Complementos/ClientesHistoricosResultado.php` + lang es/en | **Corrección de la entrega anterior:** la clave `res.sin_equivalencia` estaba **duplicada**, así que Clientes históricos mostraba «4» («:valor» sin equivalencia activa); ahora usa `res.sin_equivalencia_corta` |
| `Logistica/src/Enums/FuenteEventoLogistico.php` + lang es/en | Fuente `reporte_proveedor` |
| `.gitignore` | `/packages/ByLopez/**/*.xlsx`, `*.xls` y `*.xlsm`: las exportaciones reales y sus copias de prueba tienen datos personales. El CSV sintético de ejemplo sigue versionable |

### 15.5 Pruebas (instancia MariaDB aislada, puerto 3401, datadir en el scratchpad; datos sintéticos)
`EnviosCompletosHitosTest`: **6/6** (71 aserciones):
1. Entregada → 1 entrega (3 unidades, fuente `reporte_proveedor`, fecha y evidencia del proveedor) y 1 validación. El envío sale de «Pendientes» y entra en «Entregados». Otro envío con «Reclame en oficina» solo cambia su estado. **Sin cambios** (CHECKSUM) en stock, pedidos, líneas, envíos, guías, facturas, pagos, aplicaciones, eventos de pago, líneas de comisión, umbral, cuenta corriente y recepciones. Repetir el archivo: `sin_cambio:hito_ya_registrado`, los mismos conteos y 0 eventos nuevos.
2. Validación fallida (simulada): entrega registrada, validación 0, fila `aplicada:entrega_registrada_validacion_pendiente/VALIDACION_PENDIENTE` y resultado «validación PENDIENTE». Al repetir el archivo (reporte «ya registrado») se completa **solo** la validación: 1 entrega y 1 validación. Una tercera vez: `sin_cambio:hito_ya_registrado`.
3. Recuperación: error inesperado en la segunda fila → carga `fallida_ejecucion` (`APLICACION_INTERRUMPIDA`); la primera fila queda aplicada y la segunda se revierte entera. «Ejecutar de nuevo» completa la segunda: 1 entrega y 1 validación por envío, 2 eventos de estado (sin duplicar).
4. Devolución ratificada → 1 retorno (3 unidades, fuente y motivo del proveedor), 0 recepciones, stock intacto, «Devoluciones por recibir» +1; el envío sale de «Pendientes» y entra en «Retornados». Repetir: sin cambio.
5. Contradicciones y reportes antiguos: el reporte anterior queda `omitida_antigua` sin hitos ni retroceso; el posterior contradictorio genera `CONTRADICCION_PROVEEDOR` (advertencia), sin registrar ni revertir nada. Un retorno anulado a mano no se vuelve a registrar (`HITO_ANULADO_EN_LOGISTICA`).
6. Fecha del proveedor anterior al despacho: el estado se registra y la entrega no (`ENTREGA_NO_REGISTRADA` con `fecha_anterior_al_despacho`); la regla **no** se flexibilizó.

### 15.6 Regresión y activación en dev
- **Instancia aislada nueva:** `scratchpad/cg/db4`, puerto 3401, con una copia de `lpz_saas_testing` del servidor local. La anterior (`db3`) quedó inservible porque la limpieza del directorio temporal borró sus tablas de sistema.
- **Suite completa de Cargues: 172/172** (5.692 aserciones; 812 s, con workers reales). Incluye Clientes históricos con el trait nuevo, Ejecución y recuperación del motor, Envíos completos v2, Protección de VALIDAR y Acceso HTTP.
- **Logística: 56/56** (493 aserciones) en `Log01EntregaCanonica`, `Log07RecepcionDevolucion`, `Log08ValidacionEntrega`, `Log09CuentasTransportadora` y `Log10ReporteProveedor`.
- **Pint:** los archivos nuevos pasan. `ClientesHistoricosExtension`, `ClientesHistoricosResultado` y `EjecucionService` ya traían avisos de estilo (nombres completos, operadores); no se reformatearon.
- **Activación en dev** (por los servicios del módulo: borrador → contrato → activar):
  - El tipo 2 «99 Envíos — Envíos completos» queda en la **versión 3 activa** (id 9, `sp_cg_envios_completos_v3`, `SQL SECURITY INVOKER`, huella `1d45564d8414d327`), con el map congelado `extension = envios_completos_hitos` → `EnviosCompletosHitosExtension`. Las versiones 1 y 2 quedan retiradas.
  - `cargues:proteccion-validar` → «cumple».
  - Worker de la cola `cargues` reiniciado con el código nuevo (`queue:work cargues --queue=cargues`, pid 161924, `memory_limit=512M`); `cargues:diagnostico` → «Worker ACTIVO».
- **Efecto de la activación:** la **carga 6** (validada con v2, nunca ejecutada) ya no puede ejecutarse (versión retirada); si se necesita, hay que crearla de nuevo con la v3. No se ejecutó.
- **Sin efectos de negocio en dev:** pedidos 69, 100, 101 y 102 con 0 entregas, 0 validaciones y 0 retornos; ninguna carga nueva (la última es la 19).

### 15.7 Prueba manual de Camilo (dev)

**Tipo:** «99 Envíos — Envíos completos», **versión 3** (`sp_cg_envios_completos_v3`, política «procesar las válidas»). URL: `http://127.0.0.1:8000/admin/cargues`. Hace falta el worker de la cola `cargues` activo y reiniciado con el código nuevo.

**Archivos (SINTÉTICOS, solo para desarrollo; ignorados por Git):**

| Prueba | Archivo | Caso |
|---|---|---|
| Entrega | `docs/pruebas_cargues/SINTETICO_Envios_Completos_pedido102_guia240099900003.xlsx` | Pedido 102 · envío 83 · guía `240099900003` · 6 uds · «Entregada» 2026-11-02 21:30:00 (§13) |
| Devolución | `docs/pruebas_cargues/SINTETICO_Envios_Completos_pedido69_devolucion_guia240099900001.xlsx` | Pedido de prueba 69 `PRUEBA-CARGUES-99ENV-0001` (producto de prueba desactivado) · envío 47 · guía `240099900001` · 1 ud · «Devolución ratificada» 2026-11-02 21:45:00. Copia de la fila 94 del Excel real con **estado y fecha del estado sintéticos** (en el real, esa guía figura «Entregada» del 2026-10-02); `fecha_envio` igual al original (SHA-256/16 `454d8011be3cf380`) |

> **Efecto irreversible en dev:** ejecutar estos archivos deja en esas guías un estado y un evento del proveedor (historial append-only) con fechas sintéticas, y una entrega y validación (pedido 102) o un retorno (pedido 69) registrados con fuente «Reporte de 99 Envíos (cargue)». Después **no se podrá reutilizar la fila original** del Excel real para el mismo escenario: esa fila quedará `omitida_antigua` (fecha anterior) o rechazada `GUIA_FECHA_ENVIO_DISTINTA` (pedido 102: envío 2026-10-27 frente al sintético 2026-11-02). Los hitos solo se deshacen con la anulación auditada de Logística.

**Campos a relacionar** (los mismos que en v2): se sugieren 9 (E `transportadora`, D `numero_de_guia`, C `fecha_envio`, N `fecha_actualizacion`, L `estado_del_envio`, K `valor_comercial`, B `valor_servicio`, Q `valor_seguro_99`, P `seguro`) y **`aplica_contrapago` se relaciona a mano con la columna AB «Aplica Contrapago»**.

**Pasos y resultado esperado (cada archivo por separado):**
1. Nuevo cargue → tipo «99 Envíos — Envíos completos» → subir el archivo → relacionar como arriba → «Validar archivo». Esperado: **1 válida, 0 rechazadas**.
2. «Ejecutar cargue». Esperado en **Resultado**:
   - entrega: `aplicada · entrega_registrada_y_validada`, observación `DISCREPANCIA_RECAUDO` (67.382 frente a 45.000) y la línea «Hito logístico: entrega registrada #… del 2026-11-02 · validada #…»;
   - devolución: `aplicada · retorno_registrado_pendiente_recepcion` y «Hito logístico: devolución reportada #… del 2026-11-02 · pendiente de recepción física en bodega (stock sin cambios)». Recaudo 67.382 frente a 67.382: coincide.
3. **Logística › Entregas:**
   - pedido 102: el envío 83 ya no está en «Pendientes», está en «Entregados» y validado; en «Ver», la entrega de 6 unidades con fuente «Reporte de 99 Envíos (cargue)», la evidencia y la validación;
   - pedido 69: el envío 47 está en «Retornados» y en «Devoluciones por recibir», con 0 recepciones; el stock del producto de prueba no cambia hasta que Bodega reciba e inspeccione.
4. **Sin efectos financieros:** sin pagos nuevos en Cartera; las comisiones del pedido 102 siguen sin habilitarse (estimadas, mes 2026-11).
5. **Repetir el mismo archivo:** `sin_cambio · hito_ya_registrado`, sin entregas, validaciones ni retornos nuevos.

**Sigue pendiente:** el **despacho automático** (despacho faltante, fase B) **no está implementado**; los pedidos 100 y 101 siguen bloqueados hasta confirmar sus guías (D0).

## 16. Diagnóstico tras el cargue 20 y propuesta conjunta de DESPACHOS + ENTREGAS (2026-11-03) [DIAGNÓSTICO E · PROPUESTO]

Solo análisis: no se cambió código, configuración ni datos. Lecturas de dev, sin datos personales. No toca el hilo de cuentas de transportadoras ni la conciliación de 99 Envíos.

### 16.1 Cargue 20 [E]
- Archivo **real completo** `Envios_Completos_2026-11-03.xlsx` (hash `59ac16625b5e0369`, 1.133 filas), versión 3 del tipo, `completada`. **No** se usó el archivo sintético del pedido 102.
- **Resultado:** 1.106 `GUIA_NO_REGISTRADA` · 17 `TRANSPORTADORA_SIN_EQUIVALENCIA` · 1 `CONFLICTO_MISMA_FECHA` · 7 aplicadas, de ellas 4 con `ENTREGA_NO_REGISTRADA`.
- **`aplica_contrapago` quedó SIN RELACIONAR** (la relación lo trae `null`), y por eso:
  - las 7 aplicadas llevan `RECAUDO_SIN_DATO`, sin conciliación de recaudo;
  - la fila 94 (pedido 69, guía `240099900001`) se rechazó con `CONFLICTO_MISMA_FECHA`: misma fecha que el reporte del 2-10, pero ese traía contrapago = 1 y este lo trae vacío.
  - **No es un defecto del código** (es el comportamiento acordado), pero es una debilidad de la interfaz: un campo opcional del que depende la conciliación puede quedar vacío sin aviso. Ver D-H.

### 16.2 Pedido 102 / envío 83 [E] — no es un defecto: es la decisión D3 sin tomar
- Fila 15 aplicada: estado «Entregada» del **2026-10-30 00:46** guardado; detalle `entrega_no_registrada` y mensaje «No se registró la entrega: La fecha no puede ser anterior al despacho (fecha_anterior_al_despacho). No se validó nada».
- **Causa:** el despacho se registró el **2026-11-02 20:57**, después de la entrega real. `RegistrarEventoEnvio` exige fecha ≥ `DATE(shipments.created_at)`, y esa regla **no se flexibilizó** (§15.1).
- **Lo que se ve en Logística** (6 unidades pendientes y el botón «Registrar entrega») es coherente con eso. Lo que falta es que **Logística diga por qué**: hoy solo el resultado del cargue muestra el motivo, mientras Logística muestra «99 Envíos: Entregada» sin explicar el bloqueo (ver D-G).
- **El mismo bloqueo afecta a los pedidos 70, 71 y 72** (filas 104, 105 y 107: entregados entre el 01 y el 08-10, con despachos registrados el 17-10).

### 16.3 Pedido 100 [E] — no implementado, no es un defecto
- Sin despacho, sin guía, 9 unidades pendientes. El despacho faltante (fase B) **no está implementado** (§10b, §15).
- El «origen: 99 Envíos» que muestra el formulario de Despachos **no viene del cargue**: es la preferencia del navegador guardada en el último despacho (`leerPreferencia('origen')`, `despachos/componente.blade.php:1551`). Hoy el cargue no sugiere ni autocompleta nada en Despachos.
- **Su guía candidata** por teléfono, `240099900005` (fila 3, «Creado», act. = envío), **ya pertenece al envío 81 del pedido 101**. No se reasignó.

### 16.4 Conflictos de guías 91 / 100 / 101 (D0 sigue abierto) [E]

| Pedido | Despacho actual | Guía | Último reporte del proveedor (cargue 20) | Situación |
|---|---|---|---|---|
| 91 (cliente 45) | envío 69, 12 uds | `240099900004` | «Reclame en oficina» del 30-10 15:20 | Entrega registrada y **validada a mano el 01-11**; el proveedor informa después un estado de custodia. **No se marcó contradicción**, porque v3 solo la evalúa en «Entregada»/«Devolución ratificada» (ver D-F) |
| 100 (cliente 46) | sin despacho | — | — | Guía candidata por teléfono = la del envío 81 |
| 101 (cliente 47) | envío 81, 6 uds | `240099900005` | «Creado» del 30-10 21:12 | Por teléfono, su fila sería la 8 (`240099900004`), hoy en el pedido 91 |

**Defecto latente encontrado [C]:** el estado reportado por el proveedor se guarda por `guia_id` (`bylopez_lg_guia_estados_proveedor`; el SP v3 cruza `e.guia_id = g.id` en 6 puntos y `ReporteProveedorService` también). `CorregirGuiaService` cambia el número de la guía, pero **no toca** ese estado. Si se corrigen las guías 91/101/100:
- el reporte «Creado» de `240099900005` seguiría pegado a la guía 40 aunque ahora se llame `240099900004`;
- el siguiente cargue rechazaría la fila de `240099900004` con `GUIA_FECHA_ENVIO_DISTINTA` (10-28 frente a 10-30);
- Logística mostraría el estado de otra guía.

Hay que resolverlo **antes** de corregir esas guías (alcance §16.8, punto 1). No se ha producido todavía.

### 16.5 El archivo no identifica prendas ni pedidos [E]
- Ninguna columna trae cantidad, SKU, talla ni número de pedido. `Producto` es texto libre: 16 valores distintos, «CAMISETAS» en 1.113 de 1.133 filas, además de «BARRIO CARACOL», «CAMISETAS POR FAVOR LLAMAR» o «TABLETA OBJETO DELICADO». 0 filas con talla o código aparente.
- **Conclusión:** el Excel **no** permite decidir qué prendas salieron. Para registrar un despacho faltante, las cantidades tienen que venir de ByLopez: preparación de Bodega o confirmación explícita.

### 16.6 Matriz de escenarios

| # | Escenario | Datos necesarios | Acción automática | Excepción manual |
|---|---|---|---|---|
| 1 | Guía ya despachada en Logística y «Entregada» | Guía = transportadora efectiva + número; envío con unidades pendientes; fecha del proveedor válida | Entrega de las pendientes de **ese envío** + validación **(ya implementado, §15)** | Fecha anterior al despacho → incidencia visible en Cargues **y** Logística (D-C, D-G) |
| 2 | Pedido de 6, envío de 5, «Entregada» | Igual que 1 | Entrega y validación de las **5 de ese envío**. La unidad restante sigue **pendiente de despacho** (`qty_shipped` 5 de 6); el pedido **no** queda entregado ni habilita comisión (exige todo despachado) | Ninguna. Ya lo hace la derivación por envío; falta una prueba dedicada (§16.8) |
| 3 | Guía despachada y «Devolución ratificada» | Igual que 1 | Retorno de las pendientes del envío, sin stock **(implementado)** | Recepción e inspección en Bodega |
| 4 | Estado de custodia (Reclame en oficina, Reparto, Intento de entrega, Centro acopio) con despacho existente | Guía | Solo el estado. Si ya hay **entrega registrada**, incidencia `CONTRADICCION_PROVEEDOR` informativa (caso 91) | Revisión |
| 5 | Guía SIN despacho, con **referencia explícita** del pedido (D-A) y **preparación de Bodega** (D-B) | Referencia `BYL-…` en el archivo; preparación con unidades exactas; estado que pruebe custodia | **Despacho** por `DespachoService` con las unidades preparadas (1 Shipment, stock una vez); después, hito según el estado | Stock insuficiente, pedido anulado, guía ya usada en otro pedido o referencia de un pedido de otro cliente → incidencia |
| 6 | Guía SIN despacho, con referencia explícita y **sin preparación** | Referencia; pedido con **todo** pendiente y un solo despacho previsto | **No** se despacha solo: queda como **candidata** «todas las pendientes (N uds)» en la bandeja de confirmación agrupada | Confirmación agrupada (D-B) |
| 7 | Guía SIN despacho, **sin referencia**; coincide por teléfono/nombre con un pedido abierto | Teléfono E.164 + nombre estricto; pedido sin despacho | Solo **candidata** para revisión, nunca identidad comprobada | Confirmación agrupada |
| 8 | Guía SIN despacho cuyo número **ya está en otro pedido**, o candidata con dos pedidos posibles | — | Nada | Revisión (casos 100/101/91) |
| 9 | «Creado», «Confirmado por Cliente», «A recibir por coordinadora» | — | Solo el estado; no prueban custodia | — |
| 10 | Anulado/Anulada, «Mercancía no despachada por el remitente» con despacho existente | — | Incidencia `GUIA_ANULADA_EN_PROVEEDOR` sin revertir | Revisión |
| 11 | Estado desconocido o sinónimo no aprobado («Entregado», «DEVUELTA DESDE MONTERIA», vacío) | — | Solo el estado (vacío → rechazada) | Aprobar la lista (D1) |
| 12 | «Entregada» con devolución vigente, o «Devolución ratificada» con entrega vigente | — | Incidencia `CONTRADICCION_PROVEEDOR` sin revertir **(implementado)** | Anulación auditada en Logística |

**Prenda nunca despachada frente a despachada y no recibida:**
- *Nunca despachada* = unidad del pedido sin Shipment (`qty_shipped < qty_ordered`): es despacho pendiente, y un reporte de entrega **no** la toca.
- *Despachada y no recibida* = unidad del envío sin entrega ni retorno.
  - Con «Entregada» del proveedor, la guía completa se da por entregada: el proveedor informa por paquete, no por prenda.
  - Si el cliente reclama una prenda faltante, la corrección es manual: anular la entrega automática y registrar la entrega parcial y la novedad.

### 16.7 Propuesta recomendada

**Tres acciones separadas:**
1. **Sugerir:** el cargue calcula, sin escribir negocio, candidatas guía → pedido → unidades y las muestra en una bandeja «Guías sin despacho» (en Logística › Despachos), con el motivo de cada coincidencia (referencia explícita, teléfono, nombre, valor aproximado). Al abrir el despacho de un pedido con candidata, el formulario se **autocompleta** con transportadora efectiva, número y origen 99 Envíos, pero **no** guarda nada.
2. **Confirmar:** Bodega o el administrador confirman **en grupo** (casillas + «Confirmar seleccionadas») la asociación guía–pedido–unidades. Las candidatas con referencia explícita y preparación vienen ya marcadas; las demás, no.
3. **Ejecutar:** cada confirmación llama a **`DespachoService::despachar()`** con clave fija `99envios:despacho:<transportadora>:<guía>` (+ `UNIQUE` de la guía): 1 Shipment y stock descontado una sola vez, con sus reglas actuales (F1, F6, C1). Después, si el estado ya prueba entrega o devolución, se aplica el hito de §15 en la misma pasada.

**Vinculación de guías:**
- **Identidad comprobada** solo con una **referencia explícita** del pedido (D-A) o una asociación ya confirmada.
- Teléfono, nombre o dirección = **candidata**.
- Nunca se toma una guía ya asociada a otro pedido.

**Cantidades:** **preparación previa por Bodega** (D-B). Al alistar el paquete se registran pedido y unidades exactas como «despacho preparado» (sin Shipment ni stock).
- Si el cargue trae su guía con referencia explícita, el despacho se ejecuta automáticamente con esas unidades.
- Sin preparación, solo hay candidata «todas las pendientes» para confirmar en grupo.
- Así se automatizan los casos preparados y solo se confirman en bloque las excepciones.

**Fechas (D-C = D3):** para la fuente `reporte_proveedor` y **solo** guías de origen 99 Envíos, aceptar una fecha de entrega o retorno **anterior al registro del despacho** siempre que sea ≥ `fecha_envio` del proveedor y no futura. Se guardan la fecha real del proveedor y el momento del registro. Las demás fuentes conservan la regla actual (sin flexibilización global). Sin esto, los despachos registrados tarde (102, 70–72) y todos los despachos automáticos del punto 5 quedarían bloqueados, porque el Shipment nace hoy.

**Valores:** `valor_servicio` sigue solo registrado (no pasa a costo de flete). Las diferencias de recaudo siguen visibles. «Entregada» no registra dinero ni habilita comisiones (lo concilia Cartera, en otro hilo).

### 16.8 Alcance de implementación tras aprobar y pruebas puntuales
1. **Corrección de guía con reportes del proveedor** (defecto latente §16.4): al corregir el número, el estado del proveedor de esa guía se **desvincula** (historial conservado, marcado «de la guía anterior <número>») para que el próximo cargue cruce por el número nuevo. Prueba: corregir 754450 → 578220 y recargar sin `GUIA_FECHA_ENVIO_DISTINTA`.
2. **D-C fechas** acotado a la fuente del proveedor. Pruebas: 102 (sintético o real) → entrega validada con fecha 30-10; fecha anterior a `fecha_envio` → incidencia; panel manual con fecha anterior al despacho → sigue rechazado.
3. **D-G motivo visible en Logística:** en el envío, «Entrega automática no registrada: fecha anterior al despacho (cargue 20, fila 15)», leído del resultado del cargue; el botón manual sigue disponible. Prueba de render.
4. **D-F contradicción en estados de custodia** posteriores a una entrega registrada (caso 91). Prueba.
5. **D-H «Aplica contrapago» obligatorio** en la relación (map v4: el campo no queda sin columna) o aviso bloqueante. Prueba: validar sin relacionarlo → error claro.
6. **Fase B (despacho):** tabla de **despachos preparados**, bandeja de candidatas con confirmación agrupada, autocompletado del formulario y ejecución por `DespachoService`. Pruebas:
   - preparado + referencia → 1 Shipment y stock una vez; repetir = 0;
   - 6 pedidas / 5 preparadas → envío de 5, entregadas 5, 1 pendiente de despacho, pedido no completo;
   - sin preparación → candidata no ejecutada;
   - teléfono solo → candidata;
   - guía de otro pedido → incidencia;
   - stock insuficiente → incidencia sin efectos;
   - pedido anulado → incidencia.
7. Prueba dedicada del caso «6 pedidas / 5 despachadas / Entregada» sobre un despacho manual existente (hoy cubierto por la lógica por envío, sin prueba específica).

### 16.9 Decisiones mínimas de Camilo

| # | Pregunta | Ejemplo |
|---|---|---|
| D0 | ¿Las guías de 91/100/101 se cruzaron por error de captura? Si es así, ¿cuál es la guía real del pedido 91? | «Sí: la 754450 es del 100, la 578220 del 101 y la del 91 es otra» → corrección auditada tras el punto 1 |
| D-A | ¿El equipo escribirá el pedido en el campo de contenido/Producto de 99 Envíos? | «BYL-00000100 CAMISETAS» → identidad comprobada |
| D-B | ¿Preparación previa por Bodega (unidades exactas) o confirmación agrupada de «todas las pendientes»? | Preparación: Bodega alista 5 de 6 → al llegar la guía, despacho automático de 5 |
| D-C | ¿Se acepta la fecha de entrega del proveedor anterior al registro del despacho, solo para 99 Envíos y ≥ su fecha de envío? | Pedido 102: entregada 30-10, despacho registrado 02-11 → se acepta con ambas fechas guardadas |
| D1 | ¿Lista de estados de custodia? | Reclame en oficina, Reparto, Intento de entrega, Centro acopio (sí); A recibir por coordinadora, Confirmado por Cliente (no) |
| D-F | ¿Un estado de custodia posterior a una entrega registrada es incidencia? | Pedido 91 → «Contradicción: entregado el 01-11, el proveedor dice Reclame en oficina» |
| D-G | ¿Mostrar en Logística el motivo del hito no registrado? | Envío 83 → «Entrega automática no registrada: fecha anterior al despacho» |
| D-H | ¿«Aplica contrapago» obligatorio al relacionar columnas? | Sin relacionarlo, no se puede validar |

## 11. Plan breve (después de la aprobación) — SUPERADO por §14

1. **Fase A (entregas y retornos sobre guías existentes):** fuente nueva, excepción de fecha (D3), procedimiento v3 + extensión solo para `entrega`/`retorno`/`incidencia`. Pruebas: repetir el archivo (0 eventos nuevos), reporte antiguo (sin hito), contradicción (incidencia), varios envíos por pedido (solo el suyo), anulación manual y nuevo cargue.
2. **Fase B (despacho faltante):** condiciones §5.1 sobre `DespachoService`. Pruebas: pedido único (despacha todo lo pendiente, stock baja una vez), parcial, varios pedidos, stock insuficiente, anulado, guía usada en otro pedido (casos 46/47).
3. **Prueba manual de Camilo (dev):** subir el archivo 2026-11-03, revisar el plan por efecto en VALIDAR, ejecutar, comprobar en Entregas (pendientes, por validar, devoluciones por recibir), repetir el archivo, y en Cartera/comisiones que nada cambió.
