# 26 — Jornada 2026-10-27/28: F3–F6, cancelación del pendiente, C6, comisiones y demo reproducible

Estado: implementado en **dev + base de pruebas + base de demo aislada**; **nada se desplegó ni se empujó**; `packages/Webkul` y `vendor` sin cambios.
Clasificación usada en todo el documento: **IMPLEMENTADO** · **PROBADO** (con datos reales de prueba, por los mismos servicios/endpoints que usa el panel) · **SIMULADO** (lo que el navegador o un fixture reemplaza) · **PENDIENTE/BLOQUEADO**.

> Este documento reemplaza como vigente a `00_STATUS` para esta jornada. Lo ya corregido **no** se repite como pendiente.
> Actualización 2026-10-28 (tarde): se cerraron las cinco tareas pendientes de la jornada (arranque reproducible, tres pruebas críticas, Cargues/99 Envíos, regresión y paquete de evidencia). Ver §7; las limitaciones vigentes están en §5.

## 0. Código, migraciones y funcionalidades activas, por entorno

Tres cosas distintas que no deben confundirse: **código implementado** (commits locales), **migraciones aplicadas** (tablas que existen) y **funcionalidades activas** (lo que de verdad corre en ese entorno).

| | dev `lpz_saas` | pruebas `lpz_saas_testing` | demo aislada `lpz_saas_demo_*` | producción |
|---|---|---|---|---|
| Código de la jornada | sí (commits locales, sin push) | sí | sí | **no** (sin push ni despliegue) |
| Migraciones F6 `2026_10_27_100019` y motivos `…100020` | aplicadas 2026-10-28, con respaldo | aplicadas | aplicadas | no |
| Migración de cancelaciones `2026_10_28_100021` | **aplicada 2026-10-28 13:12**: respaldo previo `lpz_saas_pre_cancelaciones_20261028.sql` (7,5 MB, fuera del repositorio), migración revisada (dos `CREATE TABLE` con índices y llaves `RESTRICT`, sin tocar datos), `--path` exacto (batch 71), tablas vacías después; ninguna otra migración pendiente | aplicada | aplicada | no |
| F6 (decisión de cobro) | **activa** (la tabla existe) | activa | activa | no |
| Cancelación del pendiente | disponible (la tabla existe); ningún rol trae la clave por defecto, el Administrador (acceso total) sí la ejerce | disponible | disponible | no |
| C6 (`LOGISTICA_RECAUDO_DESDE_COBRO`) | **apagado** (variable sin valor) | lo activan las pruebas que lo necesitan | **encendido solo por entorno del proceso** (`demo.env`) | no |
| Comisiones (`VENTASASESOR_COMISIONES_FECHA_INICIO`) | **apagadas** (variable sin valor) | las activan las pruebas | **encendidas solo por entorno del proceso** | no |
| Bloqueo por rentabilidad | apagado | por prueba | apagado | no |
| Cargues · tipo «99 Envíos» | tipos propios de dev (activo desde 2026-10-15) | instancia aislada de sus pruebas | tipo y equivalencias propios de la demo, con cuenta restringida propia (`cargues_validar_demo`) | no |

Comprobado el 2026-10-28 en dev: `config('ventasasesor.comisiones.fecha_inicio')` y `config('logistica.cuentas.recaudo_desde_cobro_desde')` devuelven `null`; el `.env` compartido no contiene ninguna de las dos variables.

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
1. Cancela **todo** lo pendiente de despachar (pedido − enviado − reembolsado − cancelado). **No existe «cancelar N unidades»**: el servicio no recibe una cantidad. El ejemplo de 10 unidades con 6 despachadas es la cancelación de *todo lo pendiente* (las 4 restantes); si dos procesos coinciden, la cantidad se relee bajo el bloqueo del pedido (CVD-1: despacho de 6 + cancelación simultánea cancela exactamente 4). Cancelar solo una parte de lo pendiente sería una regla nueva y requiere decisión de negocio.
2. Libera **solo** la reserva (`product_ordered_inventories`) de esas unidades; **no toca** `product_inventories`. Pedidos anteriores a F5 (hija con cantidad 0, sin reserva) no restan nada a las reservas de otros pedidos; su compromiso legado baja solo porque `CercoInventario` ya resta `qty_canceled`.
3. Lo despachado conserva envíos, guías y entregas. Solo vuelve a inventario con **recepción física** de la devolución (C5).
4. Permiso propio `logistica.despachos.cancelar_pendiente` (**ningún rol lo trae por defecto**), motivo ≥ 5 caracteres, historial inmutable (`bylopez_lg_cancelaciones` + líneas con actor, motivo, cantidad, valor y si liberó reserva). Repetir la operación → `cancelacion_sin_pendiente` (no libera ni ajusta dos veces).
5. **Obligación del cliente:** el total del pedido no se modifica y **no se crea Refund**. El valor histórico de lo cancelado (precio efectivo − descuento + impuesto de la línea, igual que C1/C5) se resta del exigible en Cartera como `cancelado`, **aparte de `devuelto` (C5)**; una unidad cancelada no se despacha, así que no puede estar en las dos. Si lo pagado supera lo exigible queda **saldo a favor identificado**, sin reembolso. El flete cobrado no se condona.
6. **Limitación vigente — cancelación total con pagos:** si el pedido quedara **totalmente cancelado (nada despachado) con dinero ya aplicado**, se bloquea (`cancelacion_con_pagos`, CAN-7): devolver ese dinero es una decisión humana (D10, no implementada) y el sistema no simula un reembolso. Esa limitación **sigue en pie**; no se levantó en esta jornada. Una cancelación del pendiente de un pedido ya parcialmente despachado con pagos sí procede (queda saldo a favor identificado, sin reembolso).
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
- **D12 — ¿un recaudo compensado contra fletes cuenta como dinero recibido (y por tanto habilita comisión)?** **No existe aprobación documentada.** El Doc. 23 §7 la registra como «D12 *(nuevo, confirmar)*» con la respuesta «Sí (implementado)» como *propuesta del implementador*; en 04, 00_STATUS y el Doc. 23 no hay una decisión de Camilo que la confirme, y el código la aplica (`efectivo + compensado` = conciliado en `ElegibilidadComisionService::dinero`). Consecuencias: (1) los recorridos **B** (liquidación con flete compensado 12.000) y **D2** (30.000 − 15.000 de flete compensado) son **escenarios de prueba del mecanismo C6**, **no** una validación de esa política; (2) la protección que se conserva es que las comisiones están **apagadas** salvo en la demo (por entorno del proceso) y que sin liquidación cerrada y conciliada la comisión sigue bloqueada (`recaudo_en_custodia`, `recaudo_pendiente_de_conciliar`, `recaudo_sin_liquidacion_cerrada`); (3) si Camilo decide que la compensación **no** es dinero recibido, esos pedidos no se habilitarán; no se cambió nada en el código para anticipar esa decisión.
- **Transportadora nacional sin cuenta corriente:** **no existe** un mecanismo que acredite el ingreso del recaudo a ByLopez. Hoy la comisión queda bloqueada de forma explícita (`recaudo_sin_cuenta_corriente`). Para habilitarla hay dos caminos: activar la capacidad «liquida cuenta corriente» de esa transportadora (soportado; recorrido D2) o definir y construir un registro de remesas (requisito **pendiente de negocio**).

## 4. Guion reproducible de la demo

Entorno aislado: una base `lpz_saas_demo_*` (hoy `DB_DATABASE` de `tests/Demo/demo.env`), `MAIL_MAILER=log`, ninguna integración externa. **Todos** los procesos (servidor, scheduler, worker, recorridos, verificadores) reciben la **misma** configuración desde `demo.env` — no dependen de variables puestas solo en el comando anterior —; el `.env` compartido no se toca. `demo.sh` se puede lanzar desde cualquier directorio: `artisan`/`php` corren siempre desde la raíz del proyecto y el servidor desde `public/`.

Protecciones de `demo.sh` (probadas con entornos falsos): rechaza una base que no empiece por `lpz_saas_demo_`, la que coincide con la del `.env` compartido, una conexión que no existe, un host no local y la existencia de `bootstrap/cache/config.php` (una configuración cacheada ignora las variables del proceso). La clave de los usuarios demo sale de `DEMO_PASSWORD` o del archivo que indique `DEMO_CLAVE_ARCHIVO` (nunca de un archivo versionado); la clave de la cuenta restringida de Cargues la genera `demo.sh cuenta-cargues` en `storage/logs/demo/cuenta_cargues.env` (ignorado por git).

```bash
D=packages/ByLopez/VentasAsesor/tests/Demo
export DEMO_CLAVE_ARCHIVO=<archivo local con la clave; no versionado>
# 1) Crear la base aislada de demo.env (estructura + catalogo del origen; sin pedidos ni clientes; no sobrescribe una existente)
bash $D/demo.sh crear-base
# 2) Usuarios, productos DEMO, clientes, acuerdos (fixture fechada al inicio del mes) y fixture 998
bash $D/demo.sh preparar
# 3) Cargues: cuenta restringida propia de la demo + tipo «99 Envios» + equivalencias (reutiliza el modulo tal cual)
bash $D/demo.sh preparar-cargues
# 4) Servidor (127.0.0.1:8020) + scheduler (cada 5 min) + worker de la cola `cargues`, con la MISMA configuracion; verifica la config efectiva
bash $D/demo.sh iniciar --con-worker
# 5) Ensayo general: PREP, recorridos A-J y preparacion visual V (cada uno imprime su conciliacion y falla si un numero no coincide)
bash $D/demo.sh ensayo            # o uno por vez: bash $D/demo.sh recorridos H
# 6) Comprobar / parar
bash $D/demo.sh estado ; bash $D/demo.sh verificar ; bash $D/demo.sh parar
```

Comprobación de la configuración efectiva (`demo.sh verificar`, también al final de `iniciar`): la base conectada, `mail.default=log`, `queue.default=sync`, `ventasasesor.comisiones.fecha_inicio`, `logistica.cuentas.recaudo_desde_cobro_desde`, «config cacheada: no», y que el **servidor** en marcha autentica a `demo.admin` (usuario que solo existe en la base demo), de modo que el servidor atiende la misma base. **Reevaluación programada** (`probar_scheduler.php`): se marca un pedido en la bandeja con la reevaluación automática apagada *solo en ese script*, y el `schedule:work` del proceso la toma sin botón ni comando manual (marcado 13:12:25, procesada 13:15:10, `intentos=0`; salida en `storage/logs/demo/scheduler_prueba_20261028.txt`).

Roles y accesos (todos `@bylopez.test`): `demo.admin` (Administrador), `demo.asesora` (ASESOR VENTAS), `demo.umbral` (ASESOR VENTAS exclusiva del ejemplo 998+5), `demo.bodega` (BODEGA). La clave la define quien prepara la demo.

| Recorrido | Qué demuestra | Cifras esperadas |
|---|---|---|
| **A** local / transferencia | venta mixta → reserva → pago reportado (no verificado) → confirmación → despacho → entrega → validación → comisión automática | reserva 3 · pago 30.000 · F6 bloquea antes de confirmar |
| **B** local / contraentrega parcial *(escenario de prueba de C6; no valida D12)* | 2 guías (C1 20.000 + 20.000) → entregas → cobro → custodia 40.000 → liquidación con diferencia y compensación del flete → conciliación | efectivo 27.000 + 1.000; flete 12.000 compensado |
| **C** nacional / transferencia | Medellín (fuera de cobertura), guía ficticia Inter Rapidísimo, pago antes del despacho | recaudo 0 |
| **D** nacional / contraentrega | D1 sin cuenta corriente (bloqueo explícito) · D2 con cuenta corriente (cobro reportado → custodia → liquidación → conciliado) *(D2 es escenario de prueba; no valida D12)* | D2: 30.000 − 15.000 de flete = 15.000 efectivo |
| **E** devolución | rechazo comercial, 2 aptas + 1 defectuosa, C5, faltante | físico +2 · no vendible 1 · a favor 30.000 · faltante no ingresa |
| **F** cancelación del pendiente | 10 uds, despacho 6, cancelar todo lo pendiente (4) | reserva 4→0 · físico sin cambio · exigible 60.000 |
| **G** recogida | pago completo / pago pendiente (cobrar al retirar) / crédito autorizado | sin guía ni movimientos de transportadora |
| **H** umbral y pago al asesor | 998 + 5 → 3 comisionan → **$1.200** → abonos $500 (saldo $700) y $700 (saldo $0) | exacto |
| **I** Cargues · 99 Envíos | archivo ficticio de 8 filas por HTTP con el worker real: 4 aplicadas (una con discrepancia de recaudo, una con estado nuevo literal, una de un pedido pendiente), 4 rechazadas con motivo; el mismo archivo otra vez = sin cambio; «Entregada» del proveedor no registra entrega, no confirma pagos, no mueve custodia y no habilita comisión | CHECKSUM de guías, entregas, pagos, comisiones y cuentas idéntico antes/después |
| **J** F6 tras devolución | decisión «cobrar al entregar» → devolución registrada (sin efecto) → recibida (C5: saldo 100.000 → 80.000) → decisión anterior invalidada, importe anterior no cobrable; despacho parcial: las pendientes exigen decidir de nuevo | cobro de 100.000 rechazado, de 80.000 aceptado; guía nueva 40.000 |

## 5. Limitaciones y pendientes (lista exacta, vigente)

- **Verificación visual:** Chrome headless (escritorio 1440 px y viewport móvil 390 px, tema claro y oscuro); **no** hubo teléfono físico ni una persona operando. Los recorridos A–J son peticiones HTTP autenticadas por rol a los mismos endpoints del panel, **no** clics: por interfaz solo se probaron las pantallas y flujos listados en la entrega (cancelar el pendiente, decisión de cobro en recogida y tras devolución, Cargues, Entregas, Cuentas, Cartera, Comisiones).
- «500 después de guardar» se **simula en el navegador** (`page.route`); la recuperación se verifica contra la base.
- **Cancelación total con pagos** sigue bloqueada (`cancelacion_con_pagos`); la cancelación es siempre de **todo** lo pendiente, no de una cantidad elegida.
- Sin prueba de carga de 10.000 pedidos; el rendimiento se midió con 13–18 pedidos en un servidor monohilo sin opcache.
- **Políticas de negocio sin definir** (bloqueo explícito, nunca se habilitan solas): D3 pedido mixto, D5 desplazamiento del tramo, cambio de umbral a mitad de mes, D10 devolución posterior a la entrega/reembolso, remesas de transportadoras sin cuenta corriente, y **D12** (compensado = dinero recibido; sin aprobación documentada, ver §3).
- **Asesoras sin vista propia de comisiones:** reciben 401 en Comisiones (no se amplió su acceso).
- **Cargues:** probado con un archivo **ficticio**; el cargue real de Camilo sigue pendiente. La lista de estados del proveedor es abierta por decisión (un estado nuevo se guarda literal, sin efectos). `coordinadora` no tiene equivalencia. El reporte del proveedor no registra entregas ni pagos (por diseño).
- **MariaDB 10.4 de XAMPP:** `mysql.procs_priv` está corrupta (doc. Cargues 12): los `GRANT EXECUTE` a la cuenta restringida viven solo en memoria y se pierden al reiniciar el motor; tras reiniciarlo hay que repetir `demo.sh preparar-cargues` (idempotente).
- **Concurrencia:** el montaje de CONC-4 tenía una falla propia (ver §7.3) ya corregida; el servidor de pruebas es de un solo hilo y la medición bajo carga depende de la máquina.
- **Regresión:** ver §6.1 (lista de fallos actuales, con causa demostrada o hipótesis).
## 6. Resultados de pruebas — primera corrida de la jornada (2026-10-28 mañana; **histórico**, superado por §6.1 y §7)

> Los números de esta sección son de la mañana. Los vigentes (tras las correcciones de la tarde) están en §6.1.

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

## 6.1 Regresión final (2026-10-28, tarde, después de la última corrección)

| Batería | Resultado actual | Notas |
|---|---|---|
| Logística completa (`packages/ByLopez/Logistica/tests`) | **145 pasan, 0 fallan** (incluye Log15 cancelación, Log16 F6 tras devolución) | junit en `storage/logs/regresion/logistica_20261028.xml` |
| Log17 (clasificación 409 de bloqueos agotados) | 2 de 2 pasan | |
| VentasAsesor Feature + Unit (incluye Pag*, comisiones Com01–Com04, ACL) | **835 pasan, 42 fallan** (877 pruebas, 6.824 s) | antes: 834 / 43; la diferencia es el fallo propio de la cota de consultas (corregido) |
| Concurrencia real (ConfirmacionConcurrente, PagosConcurrentes con CONC-4b, CancelarVsDespachar) | **19 de 19 pasan** | una suite a la vez |
| Recorridos HTTP A–H sobre base limpia | **255 verificaciones [OK]**, 0 fallos | `ensayo_final_20261028.txt` |
| Recorrido I (Cargues) / J (F6 tras devolución) | 29 [OK] / 47 [OK], 0 fallos | I falló una vez por una falla del propio recorrido (números de guía fijos que no existían en la base nueva); corregido, el fallo queda en el registro |

**Número final de fallos: 42** (no 43). Las 42 son pruebas distintas, sin duplicados; la tabla siguiente las agrupa por archivo. Para no llamar «previo» a nada sin evidencia, los **mismos 15 archivos** se corrieron contra el **respaldo** (`respaldo/pre-jornada-2026-10-26`, extraído con `git archive` a una carpeta aislada **fuera del repositorio**, con su copia de `vendor`; el directorio de trabajo compartido no se tocó): **las 42 fallan igual en el respaldo y 0 fallan solo en el código actual** (`comparacion_actual_vs_respaldo.txt`). En el respaldo fallaban además 2 pruebas de `PagDAbonoClienteTest` por la cuenta global de 6.360 pagos; hoy pasan, es decir, dependen del estado de la base de pruebas, no del código.

Separación de causas: **fallos de aplicación** — ninguno demostrado; **expectativas desactualizadas** — la gran mayoría (envío local por origen, ACL/menú, conteos de migraciones, textos «hasta PAG-C»); **contaminación de datos** — `PagoComprobanteTest` (cuenta global); **por investigar** — Com03 (COM03-1), Fase03gdPantalla (500), MonedaMascara (M-2) y `Rt04b1aGuardNotificaciones` (doble `ShippedNotification`; posible doble aviso, revisar antes de producción). Ninguna verificación se debilitó para obtener verdes.

| Archivo de prueba | Fallos | Clase | Causa (demostrada o hipótesis) | ¿Falla igual en el respaldo? | Impacto | Acción |
|---|---|---|---|---|---|---|
| `AccesoInicioTest.php` | 2 | expectativa desactualizada | El menú y la ACL ya no se publican con la forma que asume la prueba (`Undefined array key "name"`; el archivo de ACL menciona el rol «ASESOR VENTAS»). Hipótesis: cambios de menú/ACL de jornadas anteriores sin actualizar la prueba. | 2 de 2 | Ninguno funcional demostrado; la prueba no protege lo que dice. | Reescribir la prueba con la ACL vigente (fuera del alcance de esta jornada; no se debilitó). |
| `CierreAuditado02A2Test.php` | 1 | expectativa desactualizada | El inventario de llaves foráneas del esquema cambió (arreglo distinto del esperado). | 1 de 1 | Ninguno funcional demostrado. | Actualizar el inventario esperado tras revisar las llaves nuevas. |
| `Com03ReglasComisionTest.php` | 1 | por investigar | COM03-1: `Failed asserting that true is false`. Falla igual en el respaldo. | 1 de 1 | Cubre la regla vigente de comisión; el flujo completo está cubierto por Com04 (pasa). | Investigar en una jornada propia; no se tocó. |
| `F1DomiciliarioNuevaVentaTest.php` | 1 | expectativa desactualizada | Espera la tarifa de domicilio por domiciliario+zona del valor genérico; desde «envío local por origen» la tarifa y la disponibilidad salen de bodega+destino+zonas del origen. | 1 de 1 | Ninguno: la regla vigente tiene sus propias pruebas (Log06 F1-*, pasan). | Reescribir con la regla por origen. |
| `Fase03dEntregaBorradorTest.php` | 10 | expectativa desactualizada | `metodo_entrega_no_disponible` / `precio` nulo: la prueba arma un domicilio local con la configuración genérica (core_config) que ya no habilita el método (local = origen + destino + zonas del origen). | 10 de 10 | Ninguno funcional demostrado (el flujo real se ejerce en los recorridos A–J). | Reescribir con zonas locales por origen. |
| `Fase03fConfirmacionVentaTest.php` | 4 | expectativa desactualizada (3) · orden de listeners (1) | Tres usan domicilio local con la configuración genérica (mismo motivo que Fase03d). `03F-45` compara el orden de listeners de `sales.order.save.*` y ya difiere (otros listeners registrados después de GRP-01). | 4 de 4 | Ninguno demostrado; el orden efectivo lo cubren F5/03F-37 (pasan). | Actualizar la lista esperada de listeners y la configuración local. |
| `Fase03gbNuevaVentaTest.php` | 4 | expectativa desactualizada | Mismo motivo: `metodo_entrega_no_disponible` con domicilio local por configuración genérica. | 4 de 4 | Ninguno funcional demostrado. | Reescribir con zonas locales por origen. |
| `Fase03gdEntregaDireccionPagoTest.php` | 7 | expectativa desactualizada | HTTP 422 al elegir domicilio local (métodos no disponibles con la configuración genérica que arma la prueba); incluye las pruebas de dirección C/D, L, H, N. | 7 de 7 | Ninguno funcional demostrado. | Reescribir con zonas locales por origen. |
| `Fase03gdPantallaNuevaVentaTest.php` | 2 | por investigar | HTTP 500 al renderizar la pantalla de Nueva venta en la prueba; falla igual en el respaldo. Los recorridos A–J la usan por API, y la matriz visual la abrió sin error (200). | 2 de 2 | Posible dependencia de datos del entorno de pruebas; la pantalla real responde 200 en la demo. | Investigar el 500 en el entorno de pruebas (jornada propia). |
| `IntegridadAtribucionTest.php` | 1 | expectativa desactualizada (demostrado) | «ninguna migración del módulo altera tablas del core»: se esperan 8 migraciones y hoy hay 33 (el módulo creció). | 1 de 1 | Ninguno: la regla (no tocar el core) se cumple; `packages/Webkul` sin cambios. | Actualizar el conteo/lista esperada. |
| `MonedaMascaraVistasTest.php` | 1 | por investigar | M-2: el valor crudo guardado difiere del enviado (arreglos distintos). Falla igual en el respaldo. | 1 de 1 | Posible efecto de la configuración de moneda/máscara del entorno de pruebas. | Investigar (jornada propia). |
| `PagBModeloPersistenteTest.php` | 3 | expectativa desactualizada | Textos «hasta PAG-C solo existe registrar…», unicidad de códigos de medio de cobro y ACL de cartera: el módulo avanzó (PAG-C…PAG-I existen) y las listas esperadas quedaron viejas. | 3 de 3 | Ninguno: los servicios de pago están cubiertos por PagC/E/F/G (pasan). | Actualizar las listas esperadas. |
| `PagDAbonoClienteTest.php` | 1 | expectativa desactualizada | ACL y alcance (`abono_general`, `view_own` / `view_all`): la pantalla de Cartera devolvió HTML distinto del esperado. Falla igual en el respaldo. | 1 de 1 | Ninguno funcional demostrado; las pruebas de abono por servicio pasan. | Actualizar con la ACL vigente. (En el respaldo fallaban además 2 pruebas de este archivo por la cuenta global de 6.360 pagos; hoy pasan: dependen del estado de la base.) |
| `PagoComprobanteTest.php` | 3 | contaminación de datos (demostrada) | Cuenta global: `Failed asserting that 6360 is identical to 0` — la prueba cuenta pagos de TODA la base y `lpz_saas_testing` conserva 6.360 pagos clonados de la auditoría E2E. | 3 de 3 | Ninguno funcional; ver acción. | Contar solo lo creado por la prueba (no se debilitó la verificación). |
| `Rt04b1aGuardNotificacionesTest.php` | 1 | por investigar | `ShippedNotification` encolada 2 veces en vez de 1. Falla igual en el respaldo (antes de los listeners de esta jornada). | 1 de 1 | Posible doble aviso de envío al cliente: revisar antes de producción. | Investigar en una jornada propia (no se amplió el alcance). |
| **Total** | **42** | | | | | |


## 7. Cierre de las cinco tareas pendientes (seguimiento 2026-10-28, tarde)

### 7.1 Arranque reproducible y entorno

Corregido respecto del guion anterior: (a) `schedule:work` se lanzaba desde `public/` (donde no existe `artisan`) y con variables puestas solo en el comando anterior; ahora todo sale de la raíz con `demo.env`; (b) la base no estaba protegida; (c) la demo compartía caché de archivos, caché de respuestas y la cookie de sesión del dev (`127.0.0.1:8000` y `:8020` comparten host): ahora `CACHE_STORE=array`, `RESPONSE_CACHE_ENABLED=false` y `SESSION_COOKIE=bylopez_demo_session`. Probado: `DB_DATABASE=lpz_saas` → «BLOQUEADO» (código 2); una base `lpz_saas_demo_*` inexistente → «BLOQUEADO» (código 2); sin config cacheada. Scheduler: `probar_scheduler.php` (ver §4). **Migración de cancelaciones en dev:** respaldo previo, migración revisada, aplicada con `--path`, comprobada (§0); comisiones y C6 siguen apagados en dev (`null`).

### 7.2 Tres pruebas críticas

**A. Cancelar contra despachar** (`CancelarVsDespacharConcurrenteTest`, procesos PHP reales, entrelazado determinista con espera observada en el motor): CVD-1 el despacho de 6 retiene el pedido y la cancelación espera y cancela **exactamente 4** (físico 50→44, reserva 10→0, 1 envío, 1 cancelación de 4, exigible 60.000, sin Refund); CVD-2 la cancelación retiene y el despacho es **rechazado** (`pedido_no_despachable`; físico 50, 0 envíos, 10 canceladas); CVD-3 cuatro rondas simultáneas con barrera: siempre uno de los dos órdenes legítimos (se observaron ambos), `enviadas + canceladas ≤ pedidas`, `físico = 50 − enviadas`, reserva 0, ≤1 envío y ≤1 cancelación. Reintentos: el despacho con la misma clave no crea otro envío y la cancelación repetida da `cancelacion_sin_pendiente`; el estado final es idéntico al anterior. Evidencia: `storage/logs/concurrencia/CVD-*.json` (incluye una corrida fallida de CVD-1 por una falla del *proceso hijo de la prueba* —no se enviaba correo falso—, conservada).

**B. F6 después de una devolución** (`Log16DisposicionTrasDevolucionTest` por servicios; recorrido J por HTTP; pantalla por Chrome): decisión «cobrar al entregar» con 100.000 → devolución **registrada** (saldo 100.000, decisión vigente, sin efecto) → **recibida** en bodega (C5: devuelto 20.000, saldo 80.000) → la decisión pasa a «anterior» y no hay vigente; cobrar el importe anterior (100.000) es rechazado por Cartera (`cobro_rechazado`, 0 pagos) y el saldo vigente (80.000) se cobra una vez; en el despacho parcial las 4 pendientes **no salen** (`disposicion_cobro_requerida`) hasta decidir de nuevo y la guía nueva lleva 40.000 (el valor de *sus* unidades). Interfaz: el drawer de Despachos muestra «La decisión anterior ya no vale porque el pago cambió. Decide…» (capturas `despacho_f6__…`). Límite: la guía original conserva su recaudo esperado de 100.000 (es inmutable); lo cobrable lo acota el saldo de Cartera.

**C. CONC-4 bajo carga.** Causa demostrada, con la evidencia `CONC-4_20261028_043132.json` (fallo original) y `CONC-4_20261028_132616.json` (reproducción con diagnóstico): el segundo proceso quedó **54 s** en `select … from orders … for update`; durante todo ese tiempo `INNODB_TRX` lo mostraba `RUNNING` (nunca `LOCK WAIT`) y la lista de procesos lo mostraba en «Statistics» con esa consulta; el montaje de la prueba solo soltaba al primer proceso al ver `LOCK WAIT`, así que lo retuvo y a los 50 s el motor abortó al segundo con 1205 (conducta correcta del motor). Clasificación: **(1)** defecto del *montaje* (detector insuficiente) → corregido con un detector de respaldo por lista de procesos, con línea de tiempo e instantáneas en la evidencia — **no** se subieron timeouts; **(2)** defecto *real de la aplicación* encontrado al analizarlo: Laravel 12 relanza 1205/1213 dentro de una transacción como `DeadlockException` (hereda de `PDOException`, no de `QueryException`) y las dos clasificaciones de errores solo miraban `QueryException`, de modo que una operación de dinero o de despacho con bloqueo agotado respondía **500** en lugar de **409 «vuelve a intentarlo»** → corregido (`ConflictoDeConcurrencia`, Cartera y Logística, Log17); **(3)** sin escrituras parciales ni duplicados: estado después = 1 pago, 0 partes del combinado; CONC-4b provoca el timeout de forma determinista (espera de 2 s) y comprueba 409, 0 partes escritas, reintento con la misma clave aceptado una sola vez (+2 pagos, saldo 28.000) y repetirlo no crea nada. Resultado actual: CONC-4 y CONC-4b pasan (8 de 8 iteraciones consecutivas con la demo y el scheduler activos, más la corrida del grupo completo); el fallo original **sí se reprodujo** una vez antes de la corrección (misma condición), y no se declara resuelto por haber pasado solo.

### 7.3 Contención artificial frente a concurrencia real

Los fixtures de cada suite son únicos (cliente, pedido y producto propios, borrados por identificador, sin tablas compartidas), y se corre **una** suite a la vez. La espera que agotó el tiempo en CONC-4 era artificial (el propio montaje retenía el bloqueo); la concurrencia real del negocio —dos operaciones sobre el mismo pedido— se serializa por el bloqueo del pedido y se resuelve releyendo el estado (CONC-1/2/3/5/6, CVD-1/2/3).

### 7.4 Cargues · 99 Envíos (recorrido I)

Tipo creado, confirmado y activado por los servicios del módulo (sin cambios de código) en la base demo, con cuenta restringida **propia** de la demo (`cargues_validar_demo`: la de desarrollo tiene permisos sobre la base de desarrollo y el módulo la rechaza, por diseño) y el **worker real** de la cola `cargues` (`demo.sh iniciar --con-worker`; las cargas pasaron `validada` y `completada` por el worker). Archivo ficticio `envios_completos_FICTICIO_demo.xlsx` (8 filas, 28 columnas de la exportación real). Resultado: **4 aplicadas** (guía conocida «Entregada» con recaudo que coincide; estado nuevo sin catalogar guardado literal; Servientrega con `DISCREPANCIA_RECAUDO` 25.000 vs 20.000, sin modificar la guía; una guía de un pedido despachado sin entrega ni cobro) y **4 rechazadas** con motivo legible (`GUIA_NO_REGISTRADA`, `TRANSPORTADORA_SIN_EQUIVALENCIA`, `OBLIGATORIO_VACIO`, `FECHA_INVALIDA`); trazabilidad carga → fila → transportadora efectiva → guía (pantalla «Detalle por fila» y tabla `bylopez_lg_guia_estados_proveedor`); el **mismo archivo otra vez**: 4 «sin cambio», 4 rechazadas, 0 eventos nuevos. **Separación:** el CHECKSUM de órdenes, guías, entregas, retornos, validaciones, recepciones, pagos, aplicaciones, eventos de pago, líneas y reglas de comisión, movimientos de cuenta y conciliaciones **no cambió**; el pedido pendiente (despachado, sin entrega ni cobro) sigue con 0 entregadas, saldo íntegro, custodia 0 y la misma comisión; la comisión de D1 sigue `estimada`. Un reporte «Entregada» del proveedor no registra entrega, no confirma pagos, no mueve dinero y no habilita comisión. No hubo llamadas ni envíos reales a transportadoras. Nota: el cargue #20 de la base queda como registro del fallo del recorrido (7 de 8 filas rechazadas por usar guías que no existían en esa base).

### 7.5 Paquete de evidencia

ZIP único sin secretos (ver la entrega): este documento, ensayo A–J, regresión con el detalle de fallos, evidencias de CONC-4 / CVD / F6, el archivo ficticio de Cargues con su resultado y no cargadas, los scripts, las capturas principales, el ejemplo 998 + 5 y un `INDICE.md` caso → esperado → observado.


---

## 8. Seguimiento 2026-10-29 · confiabilidad de las pruebas, vista de la asesora y regresión contrastada

Estados usados: **IMPLEMENTADO** (código en commits locales) · **PROBADO** (con prueba ejecutada y resultado guardado) · **SIMULADO** (reproducido en la demo con datos ficticios, no es operación real) · **PENDIENTE** (no hecho o bloqueado por una decisión de negocio).

### 8.0 Estado por entorno

| Cambio de esta tanda | Código | dev (`lpz_saas`) | testing | demo (`lpz_saas_demo_20261028c`) | Producción |
|---|---|---|---|---|---|
| «Mis comisiones» (ruta `comisiones/mias`, servicio, vista) | IMPLEMENTADO · PROBADO (Com05, recorrido K) | Disponible; **sin comisiones activas** (`null`) y el rol real «ASESOR VENTAS» **no tiene** la hoja `view_own` hasta que se la asignen | PROBADO | PROBADO por HTTP y por Chrome (escritorio claro, móvil oscuro); el rol demo recibe la hoja solo en la base demo | No tocado |
| Redirección de «Comisiones» a «Mis comisiones» para quien solo ve lo propio | IMPLEMENTADO · PROBADO (K) | Igual | — | PROBADO | No tocado |
| Servidor/scheduler/worker informan su base real (`/__demo/entorno`, `*.entorno.json`) | IMPLEMENTADO · PROBADO | No aplica (solo demo) | No aplica | PROBADO | No aplica |
| Recorridos A–K en base limpia con comprobación estricta | IMPLEMENTADO · PROBADO | — | — | PROBADO · **SIMULADO** (datos ficticios) | — |
| Corrección del doble `ShippedNotification` (paquete `bagisto-eu/pickup`) | IMPLEMENTADO · PROBADO (Rt04b1a: 30/30) | Aplica al despliegue futuro | PROBADO | PROBADO | **No desplegado** (ver riesgo en 8.6) |
| Migración de cancelaciones | Ya aplicada en dev el 2026-10-28 13:12 (lote 71); **no se repitió** | Aplicada | Aplicada | Aplicada | No aplicada |
| Comisiones y C6 | Código existente | **Apagados** | Apagados salvo por prueba | Activos **solo** por el entorno de los procesos de la demo | Apagados |
| Políticas D3/D4/D5/D10/D12 | **PENDIENTE** (propuesta en el doc 27) | Bloqueadas | Bloqueadas | Bloqueadas | Bloqueadas |

### 8.1 Confiabilidad de las pruebas (qué estaba mal y cómo quedó)

| Punto | Qué había | Qué se hizo | Resultado |
|---|---|---|---|
| Abono mayor al saldo | El rechazo se probaba con un formulario **sin comprobante**: podía rechazarse por otra causa | El formulario ahora es válido en todo salvo el importe (con comprobante, fecha, medio, referencia y clave nueva), se exige el texto exacto «El abono supera el saldo pendiente de la liquidación.» y se comprueba **cero escrituras** (abonos, suma, estado y aplicaciones antes = después). Mismo trato para «abonar con saldo cero» | PROBADO en base limpia (recorrido H) |
| «302 = éxito» | `form()` aceptaba cualquier 302 sin mensaje de error | Ahora exige el mensaje `success` del servidor o, si la pantalla no muestra mensaje, una **comprobación de persistencia explícita** (solo se usa en «activar cuenta corriente de Inter»; la regla de Cartera y de comisiones sí muestra mensaje). Esto sacó a la luz que ese paso no mostraba mensaje y el recorrido D falló una vez; se corrigió la prueba, no la regla | PROBADO (D pasa en la segunda corrida, queda el fallo en el registro) |
| Runner visual | Terminaba con código 0 aunque el escenario fallara; `capturas_finales.sh` decía «0 fallos» con **4 timeouts** | `run_demo.cjs`: código 0 todo bien · 1 el escenario falló · 2 error inesperado del navegador (los esperados se declaran con `h.esperar()`; el 500 simulado de cancelar se declara) · 3 el servidor no confirma una base demo. `capturas_finales.sh` suma los códigos y termina distinto de cero | PROBADO (escenario inexistente → 1; aislado → 0; la segunda pasada reportó 8 fallos reales) |
| Evidencia sobrescrita | Las capturas usaban un nombre fijo | Cada ejecución usa `RUN_ID` y su carpeta `capturas/<RUN_ID>/` (más un JSON con resultado, errores esperados e inesperados y **reintentos de navegación**); los JSON de los recorridos llevan hora y sufijo aleatorio | IMPLEMENTADO |
| Cargues «ya cargado» | Se deducía por conteos globales | El caso se identifica por **sus cuatro guías** (estados y eventos medidos solo sobre ellas), cada rechazo se verifica **por fila y motivo** (5 `GUIA_NO_REGISTRADA`, 6 `TRANSPORTADORA_SIN_EQUIVALENCIA`, 7 `OBLIGATORIO_VACIO`, 8 `FECHA_INVALIDA`), y repetir el archivo no mueve el último evento ni crea estados | PROBADO: primera carga (4 aplicadas, 4 rechazadas) y repetición sobre un caso ya cargado (0 aplicadas, 4 «sin cambio») |

Dos fallos propios de esta tanda, dejados a la vista: (a) la primera repetición del recorrido I falló en el control de CHECKSUM porque la lancé **mientras corría V** en la misma base (V crea pedidos); repetida sola, pasa; (b) la primera edición del runner no marcaba `fallo = true` y por eso «el escenario inexistente» devolvía 0: lo detecté al probarlo y lo corregí.

**Dónde están las capturas:** quedaron repartidas en tres ejecuciones con `RUN_ID` propio, porque `despacho_cancelar` y `despacho_f6` consumen su pedido y no pueden repetirse: `final-20261028c` (cancelación escritorio y móvil con su 500 simulado declarado, F6 tras devolución, devolución recibida, Entregas, Cartera, móvil de Comisiones y de Cuentas; en esa pasada el agregador de códigos aún no existía, por eso se comprobó el JSON de cada escenario: `fallo=false`, 0 errores inesperados), `final-20261028c-2` (pasada con 8 fallos reales, conservada) y `final-20261028c-3` (Cargues, Cuentas, Liquidación/conciliación, Comisiones, Cartera y **Mis comisiones**, 0 fallos).

**Límite de las capturas:** `php -S` atiende una conexión a la vez y Chrome deja sockets ociosos; algunas navegaciones superaban 30 s sin respuesta. El runner **reintenta una vez** y deja el reintento en el JSON (5 en la última pasada). No se subió el tiempo de espera. No sustituye una prueba con un servidor real.

### 8.2 Entorno y automatización

- `/__demo/entorno` (solo 127.0.0.1) lo sirve el propio proceso del servidor; scheduler y worker escriben `pids/<nombre>.entorno.json` **desde su proceso** al arrancar. `demo.sh estado` y `verificar` comparan base consultada (`SELECT DATABASE()`), caché de configuración, caché, cola, correo y fechas de activación con `demo.env`; los recorridos y el runner **se niegan a correr** si el servidor no confirma la misma base. Autenticar a `demo.admin` ya no se usa como prueba de la base.
- `demo.sh` funciona desde cualquier directorio (probado desde `/tmp`) y detecta `bootstrap/cache/config.php`.
- Scheduler: `probar_scheduler.php` con el proceso nuevo — pendiente marcada 22:33:57, procesada por el scheduler a las 22:35:12, **sin botón manual**.
- Migración de cancelaciones: ya aplicada en dev (lote 71, 2026-10-28 13:12 con respaldo previo); verificada con `migrate:status` y **no repetida**.

### 8.3 «Mis comisiones» (vista de la asesora)

Ruta `admin.ventas_asesor.comisiones.mias` (clave ACL `ventas_asesor.comisiones.view_own`), servicio `MisComisionesService`, vista en el diseño de Bagisto (`x-admin::layouts`, tarjetas, píldoras, claro/oscuro).

- **Etapas que no se solapan.** Cada peso de cada línea del mes está en **una sola etapa**: estimada bloqueada · habilitada (por liquidar) · liquidada por pagar · pagada. Su suma es la comisión del mes. Ejemplo 998 + 5: $1.200 aparece bloqueado, luego habilitado, luego $700 por pagar + $500 pagado, luego $1.200 pagado; el total del mes **siempre** es $1.200 (no $2.400).
- **Bloqueos con motivo comprensible:** cada línea bloqueada muestra los motivos del servicio de elegibilidad (unidades sin despachar, envío sin validar, saldo pendiente, recaudo en custodia, política pendiente…) con su texto; las primeras unidades del umbral muestran «aún estás dentro de las primeras unidades del umbral».
- **Sin costos:** ni costo, ni base, ni margen aparecen (verificado en la página renderizada).
- **Aislamiento en servidor:** el servicio no recibe ningún identificador de asesor; usa al usuario autenticado. `?asesor=` y `?pedido=` ajenos no cambian a quién se consulta (404 en el detalle ajeno). Aprobar, liquidar, abonar, reevaluar y cambiar acuerdos responden 401 (Bouncer) o 403 (servicio) y **no escriben nada** (PROBADO en Com05 MC-3 y en el recorrido K).
- Fórmula y política sin cambios: (venta neta − costo) × porcentaje histórico; las políticas pendientes siguen bloqueando.
- Límite: la traducción al inglés copia el texto en español (el módulo solo opera en español); los demás idiomas de Bagisto no se tradujeron.
- En dev el rol real «ASESOR VENTAS» **no se modificó**: darle la hoja `view_own` es una decisión de administración (en la demo se le agrega solo en la base demo).

### 8.4 Políticas pendientes

Documento `27_PROPUESTAS_POLITICAS_COMISIONES_Y_DINERO_TRANSPORTADORAS.md`: recomendación, ejemplo numérico y efecto sobre comisiones pagadas de D3, D5, D4, D10/D6 y D12, más cómo se acredita hoy el dinero de transportadoras sin cuenta corriente (bloquea; propuesta de remesa manual con soporte) y la compensación de fletes (D12, **sin aprobación documentada**: los recorridos B y D2 son escenarios de prueba). Nada se activó.

### 8.5 Validación funcional (una prueba puntual por cambio)

| Cambio | Prueba | Resultado |
|---|---|---|
| Abono / comprobante / cero escrituras | Recorrido H (base limpia) | PROBADO |
| 302 con persistencia | Recorridos A–K | PROBADO |
| Runner visual / evidencia única | Escenario inexistente → 1; páginas → 0; `RUN_ID` por carpeta | PROBADO |
| Cargues por guías del caso | Recorrido I (primera carga y repetición) | PROBADO |
| Entorno y scheduler | `demo.sh verificar`, `probar_scheduler.php` | PROBADO |
| Mis comisiones | Com05 (3), recorrido K (18 verificaciones), capturas | PROBADO |
| Local/nacional, transferencia/contraentrega, recogida, pago reportado → confirmado/rechazado, parciales sin recaudo duplicado, entrega → validación → custodia → liquidación → conciliación | Recorridos A–G (base limpia, ver 8.6) | PROBADO · SIMULADO |
| Devolución registrada vs recibida e invalidación de F6 | Recorrido J + Log16 | PROBADO |
| Cancelación contra despacho (procesos paralelos) | `CancelarVsDespacharConcurrenteTest` | ver 8.6 |
| CONC-4 bajo carga y reintento | `PagosConcurrentesTest` | ver 8.6 |
| Comisión 998 + 5 → $1.200, abonos $500/$700, reintento, exceso | Recorrido H + Com04 (8) | PROBADO |
| Permisos por rol | Com05 MC-3, recorrido K, F (cancelar), ACL unit (11 + 32) | PROBADO |
| Escritorio / móvil / claro / oscuro | Capturas `final-20261028c-*` (desbordamiento horizontal 0 px) | PROBADO en Chrome headless |
| **Safari en iPhone real** | — | **NO realizado** (ver 8.8) |

### 8.6 Regresión contrastada (2026-10-29)

Se corrió con la demo encendida (servidor, scheduler y worker en otra base).

| Batería | Resultado |
|---|---|
| Logística completa (`tests/`) | **147 pasan, 0 fallan** en la segunda corrida (la primera, antes de las correcciones de esta tanda, tuvo 1 fallo intermitente por deadlock en `STK-6`, que pasó 3 de 3 aislado y pasó en la segunda corrida completa) |
| VentasAsesor Unit | **140 pasan, 0 fallan** |
| VentasAsesor Feature (incluye Pag*, Com01–Com05, Fase03*, Rt04*) | 696 pasan, 44 fallan en la corrida larga; ver abajo |
| Concurrencia (una suite a la vez) | **19 de 19 pasan**: ConfirmacionConcurrente 9, PagosConcurrentes 7 (incluye CONC-4 y CONC-4b), CancelarVsDespachar 3 (CVD-1/2/3). CONC-4 se repitió 6 veces seguidas, todas OK (`conc4_bucle/bucle_20261029.txt`); además de las 14 de ayer y del fallo original **reproducido** con su diagnóstico (doc 26 §7.2) |
| Recorridos A–K en base limpia | A 17 · B 36 · C 19 · D 46 (incluye 1 fallo de la primera corrida, ver 8.1) · E 41 · F 36 · G 37 · H 37 · I 37 (más 30 en la repetición sobre caso ya cargado) · J 47 · K 18 · PREP 2 — todos `[OK]` |

**Contraste de los 44 fallos** (no se llamó «preexistente» a ninguno solo por el nombre):

- **39 persisten por causas que se compararon**: contra la corrida de ayer coinciden las **causas** en las 31 cabeceras agrupadas (mismo tipo de error y mismo texto) y ayer se corrieron los mismos archivos contra el **respaldo `respaldo/pre-jornada-2026-10-26` en una copia aislada fuera del repositorio**, donde fallaban las mismas pruebas con la misma causa (`comparacion_actual_vs_respaldo.txt`). Siguen siendo expectativas desactualizadas, contaminación de datos (6.360 pagos clonados) y pruebas por investigar, con la tabla `tabla_regresion.md` (causa → impacto → acción).
- **2 se corrigieron con causa demostrada** (`Fase03gdPantallaNuevaVentaTest`): el rol de prueba no tenía la clave agrupadora `ventas_asesor.pedidos`; sin ella el menú de Bagisto no se puede armar (`Undefined array key "key"` en `Webkul\Core\Menu`) y **toda** página responde 500. La pantalla de Roles siempre marca la agrupadora. Se añadió al rol de la prueba; no se tocó ninguna aserción. El mismo mecanismo explica `AccesoInicioTest` («el menú muestra el módulo…»).
- **1 era un defecto real y se corrigió** (`Rt04b1aGuardNotificacionesTest`): el paquete de terceros `bagisto-eu/pickup` escucha también `sales.shipment.save.after` y envía **otra vez** `ShippedNotification` en envíos normales, además del aviso del Shop: **cada despacho podía llegar duplicado al cliente**. Ya ocurría en el respaldo. La subclase de ByLopez del listener del paquete ahora solo conserva el aviso de recogida; `vendor` no se tocó. Prueba: Rt04b1a 30/30 y comprobación de la cola (dos mails para la misma orden antes, uno después). **Riesgo:** en producción el duplicado sigue ocurriendo hasta que este código se despliegue.
- **2 nuevos fallos de la corrida larga que no se reproducen solos**: `Fase03cSolicitudBorradorTest` («carrito VACIO») falló con `Deadlock 1213` y en Logística `Log05 STK-6` también falló con `Deadlock 1213`; **ambos pasan 3 de 3 ejecutados aislados**. Origen **no demostrado** (contención durante la corrida de 2 h; no se descarta otro proceso sobre `lpz_saas_testing`). Por política de esta jornada los deadlocks ya se responden 409 en las rutas de Cartera y Logística; en las pruebas son del montaje. **Quedan abiertos como intermitentes.**
- **1 sensible a la carga**: `Fase03gbNuevaVentaTest · 03G-B2` mide tiempo (< 700 ms): con la demo encendida falló 2 de 3 (779 y 791 ms) y con la demo detenida pasó 3 de 3. Se recomienda no correrlo con otros procesos en marcha.
- **JUnit incompleto:** el XML de esta corrida solo recogió 194 de los 740 casos de Feature (Pest dejó de escribirlo); las cifras de esta sección salen del **texto completo** de la corrida (`va_feature_20261029.txt`). El XML se guarda como está.

### 8.7 Dinero y comisiones sin configuración artificial

- Ningún recorrido ni prueba cambia fechas de comisiones, activa C6 por configuración compartida ni toca los acuerdos reales. Las comisiones y C6 solo existen para los procesos que arrancan con `demo.env`.
- Honesto sobre D2: el recorrido activa «liquida cuenta corriente» de Inter Rapidísimo **por la pantalla de administración** de la base demo para representar una transportadora **con** cuenta; el recorrido D1 usa Servientrega **sin** cuenta y demuestra el bloqueo `recaudo_sin_cuenta_corriente`. Esa bandera no es prueba de dinero; el dinero se concilia en liquidación cerrada.

### 8.8 Pasos breves para validar Safari en un iPhone real (**no realizados**)

1. Levantar la demo en la computadora (`demo.sh iniciar --con-worker`) y abrir el puerto en la red local o usar la URL pública de pruebas que Camilo prefiera (la demo escucha solo en 127.0.0.1; **no se expuso a Internet**).
2. En Safari del iPhone: entrar como `demo.asesora`/`demo.umbral`, abrir **Comisiones → Mis comisiones**: comprobar que no hay scroll horizontal y que las cuatro etapas y los motivos se leen.
3. Como `demo.admin`, abrir **Logística → Despachos**, tocar un pedido (drawer lateral) y desplazar: el drawer debe desplazarse por dentro y el fondo no debe moverse; repetir con el drawer **Cancelar lo pendiente** y con un modal de confirmación.
4. Girar el teléfono (vertical/horizontal) con el drawer abierto y confirmar que el contenido sigue visible.
5. Anotar el modelo de iPhone, la versión de iOS y capturas de cualquier salto de scroll. La auditoría de scroll (`project_ui_audit_scroll`, causa del contenedor `overflow-x-hidden` de Bagisto) **sigue sin corrección** y es el principal sospechoso.

### 8.9 Pendiente o bloqueado

- **Decisión de negocio:** D3, D4, D5, D6, D10 y D12 (doc 27); remesa manual para transportadoras sin cuenta corriente; si la hoja `view_own` se asigna al rol real de asesoras en dev/producción.
- **Técnico:** 39 fallos por expectativas desactualizadas o datos contaminados (tabla); deadlocks intermitentes sin origen demostrado; prueba de Safari real; duplicado de aviso de envío corregido pero **no desplegado**; el menú de Bagisto se rompe con un rol guardado con una hoja y sin su agrupadora (comportamiento del núcleo, no modificable aquí).
- **Evidencia:** el cargue real de Camilo con el archivo oficial de 99 Envíos sigue pendiente (todo lo de Cargues es con archivo ficticio).
