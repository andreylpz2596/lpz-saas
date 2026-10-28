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
