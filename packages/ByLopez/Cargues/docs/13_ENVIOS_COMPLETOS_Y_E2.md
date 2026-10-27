# 13 — «99 Envíos — Envíos completos» (procedimiento v1) y núcleo de ejecución E2

**Fecha:** 2026-10-15 · **Estado:** [IMPLEMENTADO] en desarrollo y [V] probado con datos **sintéticos** en la instancia aislada. Tipo configurado y activo en la BD de desarrollo. **El cargue real NO se ejecutó** (lo prueba Camilo desde la interfaz). Sin producción, Webkul, commit ni push.

Marcas: **[APROBADO]** decisión de Camilo · **[V]** comprobado con prueba ejecutada · **[E]** evidencia en archivos reales (solo lectura, sin datos personales) · **[PROPUESTO]** · **[PENDIENTE]**.

## 1. Archivos leídos (solo lectura)

| Archivo | Huella (SHA-256, 16) | Contenido |
|---|---|---|
| `docs/Envios_Completos_2026-10-03.xlsx` | `355CF577F6CD3302` | El mismo archivo «A» que ya analizó `10` §0. 1 hoja `Envíos Completos`, **76 filas × 28 columnas**, exportación SheetJS (todo texto) |
| `docs/Cartera_Completa_2026-09-12.xlsx` | `53E0BDD7A7B34ACE` | 1 hoja `Data`, **65 filas × 12 columnas** (§9) |
| Para contrastar: exportaciones del mismo reporte en Descargas (`10` §0: «P» 2026-10-03 (1), «H1» 2026-08-29) | — | Solo para cruzar guías e importes |

Ninguna cifra de este documento identifica personas: solo estructura, conteos e importes agregados.

## 2. Qué es cada dato del archivo [E]

**Estructura:** encabezados en la fila 1; las 9 columnas gemelas (R–Z) son idénticas a su par en las 76 filas; 76 guías distintas, 76 llaves `(transportadora, guía)` distintas; vacíos solo en `valor_servicio` (1) y `valor_seguro_99` (5, exactamente las de `seguro = Desactivado`).

| Dato | Qué representa (evidencia) |
|---|---|
| **«Entrega»** | **No es una columna, ni una fecha, ni un identificador.** Solo aparece como TEXTO del estado del proveedor: `Entregada` (48 filas) e `Intento de entrega` (1). No hay fecha de entrega: la fecha del estado es `fecha_actualizacion`. Se guarda literal y **no** se equipara a entrega registrada/validada, recepción de devolución, pago ni comisión |
| `estado_del_envio` | Texto libre del proveedor, lista abierta: `Entregada` 48 · `Admitida` 8 · `Devolución ratificada` 6 · `Reclame en oficina` 4 · `Creado` 3 · `Reclamo en oficina informado WhatsApp - Mensaje inicial` 2 · `Confirmado por Cliente` 1 · `Transito nacional` 1 · `Reparto` 1 · `Intento de entrega` 1 · `Reclamo … Recordatorio 7 días` 1 |
| `transportadora` | Código del proveedor: `1` (74, `transportadora_nombre = interrapidisimo`) y `4` (2, `coordinadora`). **No** es el catálogo interno |
| `fecha_envio` / `fecha_actualizacion` | Texto `AAAA-MM-DD hh:mm:ss` sin zona. Envío 2026-09-14 → 2026-10-02; actualización 2026-09-15 → 2026-10-03. 1 fila con actualización **anterior** al envío y 3 iguales (no se rechazan: ver `10` §4) |
| **`valor_comercial`** | **Es el valor que se recauda contra entrega**, no necesariamente el de los productos. Evidencia en Cartera (§9): en las 56 filas «pagado», `Consignado` = parte entera de (`Valor Comercial` − `Flete`), y `Valor Comercial` coincide con `valor_comercial` en las 65 guías comunes. Las 76 filas de este archivo son contrapago. Si incluye o no el flete cobrado al cliente depende de cómo se registró la venta; por eso se compara con el **recaudo esperado de la guía**, no con los productos |
| **`valor_servicio`** | **Todo lo que 99 Envíos descuenta del recaudo, no solo el transporte.** (a) Es exactamente el «Flete» de Cartera en las 65 guías comunes. (b) Dentro de una misma ciudad crece ≈ **0,0733** por cada peso de `valor_comercial` (33 pares; ≈ 0,0637 al restar el seguro): hay un componente proporcional al valor, **consistente** con una comisión de recaudo, **no confirmado**. (c) **Cambia con el tiempo:** en 4 de 5 `Devolución ratificada` vale exactamente el seguro, y 3 guías que en la exportación del 08-29 valían 21–32 mil pasaron a valer el seguro al ser devueltas. (d) Trae artefactos de coma flotante (`18213.940000000002`) |
| `valor_seguro_99` / `seguro` | `Seguro 99` 66, `Seguro 99 Plus` 5, `Desactivado` 5. El valor **no** es un porcentaje fijo del valor comercial. La página oficial habla de «Seguro Anti-devolución» y de un plan Plus con «$0 ida, $0 vuelta, $0 seguro»; no define cómo se calcula |
| `aplica_contrapago` / `Aplica Contrapago` | `1`/vacío y `Si`/`No` respectivamente (en P, vacío ↔ `No`) |

**Fuente oficial consultada:** [99envios.com](https://99envios.com) — «plataforma logística multitransportadora» (TCC, Coordinadora, Inter Rapidísimo, Servientrega, Envía), contraentrega a la wallet del vendedor y seguro anti-devolución. **No publica** la definición de valor comercial, flete, comisión de recaudo ni del cargo en devoluciones. Por eso la composición exacta de `valor_servicio` queda **[PENDIENTE]**.

## 3. Contraste con Logística [V en código]

- `bylopez_lg_guias`: **ya separa** origen y transportadora efectiva: `origen_guia = '99envios'` (de dónde salió la guía) y `transportadora_codigo` (catálogo). `UNIQUE (transportadora_codigo, numero_guia)`. `recaudo_esperado` = lo que la transportadora debe cobrar por **esa guía** (no es dinero recibido); `valor_flete` = costo final del servicio que registra la operaria. La guía es inmutable salvo `tracking`, `proveedor_referencia` y `recaudo_estado`.
- **Entregas, retornos, recepciones de devolución y validaciones de entrega** son eventos **manuales**, por unidad, con servicio, idempotencia, actor y anulación auditada. La fuente `integracion_transportadora` está **reservada** (`disponible() = false`). Escribir ahí desde un procedimiento saltaría esos invariantes y alimentaría comisiones (COM-04) y cuentas de transportadoras. **Por eso el procedimiento NO los toca**: el estado del proveedor va a tablas propias, informativas.
- El catálogo tiene `99ENVIOS` como «transportadora». En dev hay **3 guías registradas con `99ENVIOS` como transportadora** (el proveedor en lugar de la transportadora efectiva). El procedimiento **no** las cruza: las rechaza con `GUIA_CON_OTRA_TRANSPORTADORA` y dice cuál tienen.
- `coordinadora` **no existe** en el catálogo: sus filas quedan `TRANSPORTADORA_SIN_EQUIVALENCIA` hasta que se cree en Logística y se configure su equivalencia.
- **Dev hoy:** 14 guías; **ninguna de las 76 guías del archivo existe** en `bylopez_lg_guias` ni en `shipments.track_number` (§8).

## 4. Decisiones

**[APROBADO] por Camilo el 2026-10-15** (además de lo pedido en el encargo: cruce por transportadora efectiva + guía como texto, códigos por configuración, sin sustitutos silenciosos, rechazo con motivo, sin crear pedidos/despachos, estado original con fecha y procedencia, sin equiparar a entrega/pago/comisión):

| # | Decisión |
|---|---|
| ENV-A1 | Si los valores difieren pero la guía, la transportadora y las fechas están bien: **se actualiza el estado y se registra la discrepancia** (fila «aplicada» con observación). No se tocan precios, flete ni recaudo internos |
| ENV-A2 | Política de errores del tipo: **procesar las válidas** |
| ENV-A3 | `valor_comercial` se compara con el **`recaudo_esperado` de la guía** |
| ENV-A4 | `valor_servicio` **solo se registra**, sin conciliar |

**Reglas de precisión (explícitas):** decimales exactos (`DECIMAL`); diferencia = reportado − interno; «coincide» solo si es exactamente 0,00; **sin tolerancia ni redondeo**. Recaudo reportado = `valor_comercial` si aplica contrapago, `0` si no aplica, **sin dato** si falta cualquiera de los dos (no se supone). `valor_servicio` se guarda **literal** (texto), con sus artefactos, sin interpretar.

## 5. Configuración del tipo y columnas a relacionar

Archivos **versionados**: `src/Database/Procedures/sp_cg_envios_completos_v1.sql` y `sp_cg_envios_completos_v1.map.json`. El motor **no** instala el procedimiento: se instaló en dev desde ese archivo.

| Campo del tipo | Tipo | Columna del archivo | Regla |
|---|---|---|---|
| `transportadora` | identificador(10), obligatorio, **llave** | E `transportadora` | Código del proveedor; se traduce por configuración |
| `numero_de_guia` | identificador(40), obligatorio, **llave** | D `numero_de_guia` | Texto exacto (sin perder ceros) |
| `fecha_envio` | fecha_hora `Y-m-d H:i:s`, obligatorio | C | — |
| `fecha_actualizacion` | fecha_hora `Y-m-d H:i:s`, obligatorio | N | Regla contra información antigua |
| `estado_del_envio` | texto(191), obligatorio | L | Literal |
| `valor_comercial` | decimal(14,2), opcional | K | Conciliación con el recaudo |
| `valor_servicio` | texto(40), opcional | B | Literal, sin conciliar |
| `valor_seguro_99` | decimal(14,2), opcional | Q | Se registra |
| `seguro` | texto(60), opcional | P | Se registra |
| `aplica_contrapago` | booleano, opcional | **Elegir a mano**: AB `Aplica Contrapago` (`Si`/`No`, recomendado: no llega vacía) u O `aplica_contrapago` (`1`/vacío) | El motor no la sugiere porque hay dos columnas «similares» |

- Las 9 primeras se **sugieren solas** (el nombre del campo es el del encabezado). Las columnas personales (G, H, I y gemelas) **no se relacionan**: no llegan a las tablas de Logística (T6 sí guarda la fila original completa, A7).
- Llave de la intermedia `(transportadora, numero_de_guia)` con `rechazar_todas`: una guía repetida dentro del archivo rechaza todas sus filas.
- `transaccion: motor` (todo o nada), `reanudable: false`, `tablas_negocio`: las dos tablas de estado del proveedor (escritura) y `bylopez_lg_guias` y `bylopez_lg_proveedor_transportadoras` (lectura; declararlas da a la cuenta restringida el `SELECT` y las incluye en el CHECKSUM de VALIDAR).
- **Equivalencia configurada en dev:** `99envios` · `1` → `INTER_RAPIDISIMO` (evidencia: `transportadora_nombre = interrapidisimo`). `4` (coordinadora) **sin** equivalencia.

## 6. Reglas del procedimiento `sp_cg_envios_completos_v1`

Sobre las filas con `cg_estado IS NULL`, en este orden. VALIDAR aplica R1–R5 (solo marca `rechazada`, con la cuenta restringida); EJECUTAR **vuelve a aplicarlas** (el estado pudo cambiar) y sigue con R6–R8.

| Regla | Condición | Resultado (código) |
|---|---|---|
| R1 | Código de transportadora sin equivalencia **activa** | rechazada `TRANSPORTADORA_SIN_EQUIVALENCIA` |
| R2 | No existe guía con (transportadora efectiva, número) **exactos** | rechazada `GUIA_NO_REGISTRADA`, o `GUIA_CON_OTRA_TRANSPORTADORA` si el número existe con otra (se dice cuál; no se cruza) |
| R3 | Más de una guía coincide (defensivo: hay UNIQUE) | rechazada `GUIA_AMBIGUA` |
| R3b | Dos filas de la carga llevan a la misma guía | rechazadas **ambas** `GUIA_REPETIDA_EN_CARGA` |
| R4 | La guía ya tiene reportes de 99 Envíos con **otra `fecha_envio`** (posible número reutilizado) | rechazada `GUIA_FECHA_ENVIO_DISTINTA` |
| R5 | Misma `fecha_actualizacion` que la registrada pero otro estado u otros valores | rechazada `CONFLICTO_MISMA_FECHA` (no se elige ganador) |
| R6 | `fecha_actualizacion` **anterior** a la registrada | `omitida_antigua` (no se aplica) |
| R7 | Misma fecha, mismo estado y mismos valores | `sin_cambio` |
| R8 | El resto | `aplicada` (`insertada` o `actualizada`) + historial con el estado anterior. Observación `DISCREPANCIA_RECAUDO` (reportado ≠ esperado) o `RECAUDO_SIN_DATO` |

- **No retroceso** solo por la fecha del proveedor (no por un orden de estados, que no está aprobado: `10` §4).
- **Cruce exacto:** se usa el índice de `bylopez_lg_guias` (intercalación `utf8mb4_unicode_ci`) y además igualdad **byte a byte** (`BINARY`), así `«G1»` ≠ `«g1»` y `«G1 »`.
- **Escribe solo** `bylopez_lg_guia_estados_proveedor` (estado actual por guía y proveedor), `bylopez_lg_guia_estado_proveedor_eventos` (historial append-only, `UNIQUE (carga, fila)`) y las marcas de la intermedia, en la **misma transacción**. Ninguna otra tabla.
- `SQL SECURITY INVOKER` (obligatorio por `12`). Sin `COMMIT` ni DDL.

**Tablas nuevas (Logística, migración aditiva `2026_10_15_100015`):** `bylopez_lg_proveedor_transportadoras` (configuración: proveedor, código externo → transportadora del catálogo, FK, activo), `bylopez_lg_guia_estados_proveedor` y `bylopez_lg_guia_estado_proveedor_eventos` (estado literal, fecha del estado, fecha de envío, valores reportados, recaudo reportado/interno/diferencia/conciliación con CHECK de coherencia, procedencia carga + fila). Sin backfill.

## 7. Núcleo de ejecución E2 (motor genérico)

| Pieza | Qué hace |
|---|---|
| Puerta (`CargasService::ejecutar`) | Política del tipo (bloquear todo → 409; procesar válidas → número exacto de rechazadas o 422); versión del tipo aún activa; **CAS** `validada \| fallida_ejecucion → lista` (doble clic o dos pestañas: gana uno) y job en la cola `cargues` |
| `EjecutarCarga` + `EjecucionService` | Sesión dedicada de la conexión del tipo, **bloqueo con nombre**, huella del procedimiento y de la entrada, **CAS `lista → ejecutando`** (guarda conexión, bloqueo y filas elegibles), `BEGIN` → `CALL … 'EJECUTAR'` → **contrato** → `COMMIT` |
| Contrato antes del COMMIT | Toda fila elegible marcada con `aplicada \| sin_cambio \| omitida_antigua \| rechazada`; entrada sin cambios; marcas previas (rechazadas al validar) idénticas; resultado `OK` o `PARCIAL`; sesión con el bloqueo. Si algo falla: **ROLLBACK** (0 efectos y 0 marcas) y `fallida_ejecucion`, que se puede ejecutar de nuevo |
| Certeza | Conexión perdida o proceso muerto → resultado **incierto**: la carga queda `ejecutando` y solo la **recuperación** la clasifica |
| Recuperación (`Recuperar` / `cargues:recuperar {carga}`) | Nunca clasifica mientras el bloqueo esté tomado o la sesión siga en `PROCESSLIST`. Libre: todas las elegibles marcadas → se confirmó → consolida; ninguna → se revirtió → `fallida_ejecucion`; otra cosa → `RESULTADO_INCIERTO` (revisión manual) |
| Consolidación | Idempotente y por lotes: solo toca filas de T6 `pendiente`; incidencias con upsert. Observaciones del procedimiento (fila no rechazada con `cg_codigo`) → incidencia **advertencia** de la etapa `ejecucion` |
| Resultado | Aplicadas, **con observación** (discrepancias), sin cambio, antiguas, rechazadas (motor / procedimiento / ejecución), no ejecutadas; descarga de **resultado completo** (todas las filas, estado, detalle, motivo y columnas originales) y de no cargadas |
| UI | «Ejecutar cargue» activo según la política (con rechazadas pide escribir el número); progreso de ejecución; panel de resultado; «Ejecutar de nuevo» tras un fallo sin efectos; «Recuperar» si no hay noticias |

**No habilitado en esta entrega:** modelo transaccional `procedimiento` (commits por lotes), **reanudar**, **cancelar con `KILL`**. Ver `09` §2.2.

**Cuenta restringida compartida (ajuste a `12`):** la cuenta de VALIDAR es una por conexión; con dos tipos activos tiene `UPDATE` sobre cada intermedia. Se admite **solo** sobre intermedias registradas (T3) de esa conexión; cualquier otra tabla sigue bloqueando [V].

## 8. Prueba manual de Camilo (desarrollo)

**URL:** http://127.0.0.1:8000/admin/cargues (como administrador). **Tipo:** «99 Envíos — Envíos completos» (activo, procesar las válidas). Worker de la cola `cargues` iniciado (`php artisan cargues:diagnostico` → «Worker ACTIVO»).

1. Elegir la tarjeta «99 Envíos — Envíos completos» y subir `docs/Envios_Completos_2026-10-03.xlsx` (hoja `Envíos Completos`, encabezados en la fila 1).
2. Comprobar que se sugieren 9 de 10 campos y relacionar **Aplica contrapago** con la columna **AB `Aplica Contrapago`** (o O, si prefiere). «Validar archivo».
3. **Resultado esperado con los datos actuales de dev** (calculado, **no ejecutado**): **76 filas, 0 válidas, 76 rechazadas**: 74 `GUIA_NO_REGISTRADA` (ninguna guía del archivo existe en dev) y 2 `TRANSPORTADORA_SIN_EQUIVALENCIA` (coordinadora). «Ejecutar» queda deshabilitado («No hay filas válidas»). Descargar las no cargadas.
4. **Para probar la escritura:** registrar en dev (Logística › Despachos) 2 o 3 envíos con números de guía del archivo, transportadora **Inter Rapidísimo**, origen **99 Envíos**, uno con recaudo esperado = `valor_comercial` de esa fila y otro con un valor distinto. Volver a subir el archivo: esas filas pasan a **válidas** (las demás, rechazadas).
5. «Ejecutar cargue», escribir el número de rechazadas que pide. Esperado: esas filas **aplicadas** (insertadas), la del recaudo distinto **con observación** `DISCREPANCIA_RECAUDO` (reportado, esperado y diferencia en el resultado completo); la guía, sus entregas, pagos y comisiones **sin cambios**. Subir y ejecutar el **mismo** archivo otra vez: esas filas quedan **sin cambio**.

## 9. Cartera (`Cartera_Completa_2026-09-12.xlsx`) — solo identificación

- 1 hoja `Data`, 65 filas × 12 columnas: `Cód. Sucursal`, `Nº Guía`, `Valor Comercial`, `Flete`, `Consignado`, `Fecha Consig.`, `Estado Envío`, `Estado Pago`, y 4 columnas **personales** (destinatario, dirección, teléfono, ciudad).
- `Estado Envío`: `Entregada` 56, `Devolución ratificada` 8, `Devolucion Regional` 1. `Estado Pago`: `pagado` 56, `Seguro Antidevoluciones` 9.
- **Relación con Envíos completos [E]:** `Nº Guía` = `numero_de_guia`; las 65 guías están en la exportación «P» y en las 65 coinciden `Valor Comercial` = `valor_comercial`, `Flete` = `valor_servicio` (exacto) y el estado. «pagado»: `Consignado` = parte entera de (`Valor Comercial` − `Flete`). «Seguro Antidevoluciones»: `Consignado` = 0 y `Flete` = valor del seguro.
- `Fecha Consig.`: 64 como texto `AAAA-MM-DD` y 1 como número de serie de Excel (lectura a definir).
- **Relación futura [PROPUESTO, no implementado]:** un tipo «99 Envíos — Cartera» conciliaría lo **consignado** por 99 Envíos contra el recaudo esperado de cada guía y el registro de pagos de Cartera, usando el estado reportado que deja este tipo. **No** se implementó conciliación financiera, abonos, liquidaciones ni comisiones.

## 10. Pruebas ejecutadas (instancia aislada MariaDB 10.4.28; datos sintéticos)

**Suite completa de Cargues:** 144 pruebas, 5 396 aserciones, todas pasan (incluye workers reales); después, con la corrección de la cuenta compartida, `ProteccionValidarTest` 11/11, `EnviosCompletosTest` 8/8, `EjecucionTest` 9/9 y `AccesoYHttpTest` 15/15. Pint pasa. `packages/Webkul` sin cambios.

| Prueba | Resultado |
|---|---|
| Activación con SP y map **versionados**: contrato sin errores, `INVOKER`, permisos mínimos de la cuenta (sin INSERT/DELETE) | [V] |
| VALIDAR con la cuenta restringida: `GUIA_NO_REGISTRADA`, `TRANSPORTADORA_SIN_EQUIVALENCIA`, `GUIA_CON_OTRA_TRANSPORTADORA` (guía con `99ENVIOS`), fecha inválida y llave duplicada (motor); 0 escrituras; tablas intocables con el mismo CHECKSUM | [V] |
| EJECUTAR: estado literal, transportadora efectiva y proveedor separados; recaudo «coincide», «difiere» (+500 exacto), «sin dato» y sin contrapago (0 = 0); `valor_servicio` literal `18213.940000000002`; historial; observaciones; guía intacta | [V] |
| F1-R5: `orders`, `shipments`, guías, entregas, retornos, validaciones, recepciones, pagos, aplicaciones de pago, eventos de pago y reglas de comisión con el **mismo CHECKSUM** | [V] |
| F1-R3: mismo archivo dos veces → `sin_cambio`, destinos idénticos | [V] |
| F1-R4: reporte anterior → `omitida_antigua`; posterior → `actualizada` con estado anterior en el historial | [V] |
| `CONFLICTO_MISMA_FECHA` y `GUIA_FECHA_ENVIO_DISTINTA` | [V] |
| EJECUTAR re-comprueba: equivalencia desactivada entre validar y ejecutar → rechazada **en la ejecución** | [V] |
| «Bloquear todo» con rechazadas → 409 sin efectos; resultado completo descargable con estado, detalle y motivo | [V] |
| E2 genérico: aplica exactamente las válidas (efecto ⇔ marca); doble ejecución, job reentregado y job viejo → una sola ejecución; 6 violaciones de contrato → ROLLBACK total; bloqueo tomado por otra sesión → nada, luego ejecuta; recuperación (sesión viva → 409; revertida; confirmada); consolidación idempotente y `cargues:recuperar`; observaciones; progreso ofrece «Recuperar», no «Reintentar» | [V] |
| **Worker real muere durante el `CALL`**: la recuperación no clasifica mientras el servidor sigue; al terminar sin COMMIT, 0 efectos y 0 marcas → `fallida_ejecucion` | [V] |
| Worker real: ejecución completa | [V] |
| Cuenta compartida por dos tipos: `UPDATE` solo sobre intermedias registradas | [V] |

**En desarrollo (sin ejecutar el SP):** migración `100015` aplicada sola; procedimiento instalado (`INVOKER`); tipo activo; `cargues:proteccion-validar` → «cumple» en los dos tipos; las dos pantallas responden 200 y listan el tipo (render dentro de Laravel con un administrador en memoria; **no** se abrió un navegador).

## 11. Pendientes y riesgos

1. **[PENDIENTE] Composición de `valor_servicio`** (¿comisión de recaudo? ¿IVA? ¿cargo de devolución?) y su equivalencia interna; hasta entonces solo se registra.
2. **[PENDIENTE] `coordinadora`** no está en el catálogo de Logística; crearla y configurar su equivalencia es decisión de Camilo.
3. **[PENDIENTE] Guías registradas con `99ENVIOS` como transportadora** (3 en dev): corregirlas en Logística o decidir cómo tratarlas; hoy se rechazan con motivo.
4. **[PENDIENTE]** Zona horaria y significado exacto de `fecha_actualizacion` (`10` D-ENV-3); la regla actual compara fechas del mismo proveedor.
5. **[PENDIENTE]** Si el estado reportado debe mostrarse en Logística (pantalla de la guía) y si algún estado debe **sugerir** (nunca registrar solo) una entrega o devolución.
6. **[PENDIENTE]** Retención de T6 (guarda la fila original con datos personales).
7. **Riesgo dev:** `mysql.procs_priv` (permisos sobre rutinas) está **corrupta** en el MariaDB de XAMPP: los `GRANT EXECUTE` viven solo en memoria y se pierden al reiniciar MariaDB (por eso desapareció el de «Prueba E1»). Si VALIDAR dice «faltan permisos … GRANT EXECUTE», ejecutar en la BD: `REPAIR TABLE mysql.procs_priv;` y luego `php artisan cargues:proteccion-validar --aplicar`. **No se reparó** (tabla de sistema, fuera del alcance).
8. EJECUTAR corre con la cuenta de la conexión del tipo (`root` en dev). Una cuenta de ejecución con escritura solo en las tablas declaradas queda **[PROPUESTA]** para producción.
9. No probado: MySQL 8, producción, el archivo real (lo prueba Camilo), Safari/Firefox, cancelar con `KILL`, reanudar y el modelo `procedimiento`.

## 12. Prueba dirigida en desarrollo (2026-10-15, ejecutada por Claude con autorización de Camilo)

**Causa del cargue 1 «fallido»:** no fue un fallo técnico. La carga 1 terminó `validada` con **76/76 filas rechazadas** (74 `GUIA_NO_REGISTRADA`, 2 `TRANSPORTADORA_SIN_EQUIVALENCIA`), así que «Ejecutar» quedó bloqueado por «No hay filas válidas». En dev **no existía ninguna guía del Excel**: la última guía registrada era la 14 (2026-10-14 21:53) y la única de 99 Envíos/Inter Rapidísimo (id 13, `24005678133637`, 14 dígitos) no está en el archivo. Los logs no tienen errores de Cargues después de ese cargue (los dos «Maximum execution time» del 2026-10-15 son anteriores y no son de Cargues ni de Logística). **No se corrigió código**: el comportamiento era el correcto y no se debilitó ninguna validación.

**Escenario de PRUEBA (se conserva):**
- Producto propio `PRUEBA-CARGUES-99ENV` «PRUEBA CARGUES 99 ENVÍOS (no vender)», desactivado, stock 1 (el despacho descontó SU stock, no el de un producto real).
- Pedido 69 `PRUEBA-CARGUES-99ENV-0001` (invitado PRUEBA, contra entrega, 67 382).
- Envío 47 y guía 15 creados con **`DespachoService`** de Logística (sus reglas: permiso, guía única, recaudo máximo, stock): `INTER_RAPIDISIMO` · `240099900001` (fila 10 del Excel, `Entregada`) · origen `99envios` · recaudo esperado 67 382.
- Copia del Excel con solo esa fila: `docs/pruebas_cargues/Envios_Completos_2026-10-03_PRUEBA_240099900001.xlsx` (28 columnas, encabezados y valores idénticos al original: 0 celdas distintas).

**Flujo completo del módulo** (servicios de los controladores; cola `cargues` con el worker real):

| Paso | Cargue 2 | Cargue 3 (mismo archivo) |
|---|---|---|
| Relación | 9 sugeridas (E, D, C, N, L, K, B, Q, P) + `aplica_contrapago` ← AB | igual |
| Validación | `validada`: 1 válida, 0 rechazadas | igual |
| Ejecución | `completada`: **1 aplicada** (`insertada`) | `completada`: **1 sin cambio** (`ya_registrado`) |
| Estado reportado (guía 15) | antes: ninguno → después: `Entregada` del 2026-10-02 23:20:22, proveedor `99envios`, transportadora `INTER_RAPIDISIMO`, valor comercial 67 382, valor servicio `21880.74`, seguro 2 993, recaudo 67 382 vs 67 382 = **coincide**, procedencia carga 2 fila 2 | **sin cambios** (`reportes` = 1, mismo `updated_at`, procedencia carga 2) |
| Historial | 0 → **1 evento** | sigue **1** |
| Guía, entregas, retornos, validaciones; pagos, aplicaciones y reglas de comisión (CHECKSUM) | sin cambios | sin cambios |

**Dónde ver el estado reportado por 99 Envíos:** hoy **no hay una pantalla en Logística** (pendiente §11.5). Se ve en **Cargues › Historial › cargue 2 › Resultado completo**, y en la BD: `bylopez_lg_guia_estados_proveedor` (último estado por guía) y `bylopez_lg_guia_estado_proveedor_eventos` (historial). **No es** una entrega registrada ni validada (Logística › Entregas: el pedido 69 sigue con 0 entregas y 0 validaciones) ni un pago (Cartera: sin cambios).

## 13. Cargue 4: LONGITUD_EXCEDIDA en «Transportadora» (2026-10-18) — causa y corrección (versión 2 del tipo)

**Rastreo de la fila (guía 240099900002, fila 23 del archivo, existe en Logística: guía 18, INTER_RAPIDISIMO, origen 99envios).**

| Paso | Valor |
|---|---|
| Columna seleccionada | X «transportadora_nombre» (índice 23; el archivo del cargue 4 tiene 25 columnas y **no trae** la columna `transportadora` con el código, que sí traía la exportación de 28 columnas) |
| Valor leído | `interrapidisimo` (15 caracteres; las otras 2 filas: `coordinadora`, 12) |
| Campo destino | `transportadora` — «Transportadora (código de 99 Envíos)» |
| Límite configurado (v1) | 10 caracteres |

Las 76 filas se rechazaron por `LONGITUD_EXCEDIDA` en la validación del motor: un **nombre** relacionado a un campo pensado para un **código** («1»). La validación era correcta; el desajuste estaba en el diseño del campo frente a una exportación sin código. No se truncó nada ni se relajó ningún control: el límite pasó de 10 a **120** (= `nombre_externo` de la equivalencia), con ese tope también aplicado.

**Corrección (versión 2: `sp_cg_envios_completos_v2` + map v2).** `transportadora` admite el código o el nombre del proveedor y se cruza con `codigo_externo` O `nombre_externo` de `bylopez_lg_proveedor_transportadoras` (activa, igualdad EXACTA y sensible a mayúsculas; sin equivalencia → `TRANSPORTADORA_SIN_EQUIVALENCIA`). Regla nueva R1b: si el valor coincide con más de una equivalencia activa → `TRANSPORTADORA_AMBIGUA` (no se elige ninguna). El resto del procedimiento no cambia (cruce transportadora efectiva + guía). Cambio de estructura de la intermedia (varchar 10→120) por el flujo normal: se cancelaron las cargas abiertas no ejecutadas (1 y 4; **conservan archivo, filas e incidencias**) y la estructura anterior quedó archivada como `bylopez_cgi_envios_completos__a1`. v1 se conserva en el repositorio como historia.

Pruebas (instancia aislada): `EnviosCompletosTest` 10/10 (incluye nombre exacto válido, nombre sin equivalencia, mayúsculas distintas, >120 rechazado, ambigüedad).

**Verificación en dev, solo VALIDAR (no se ejecutó):** cargue 5 = mismo archivo y mismas relaciones del cargue 4 → 76 filas, 4 válidas, 72 rechazadas con motivo real (70 `GUIA_NO_REGISTRADA`, 2 `TRANSPORTADORA_SIN_EQUIVALENCIA` por coordinadora). La guía 240099900002 queda **válida** (cruce `INTER_RAPIDISIMO` + guía). Guías, pagos, aplicaciones y entregas: CHECKSUM idéntico antes/después. Observación esperada al ejecutar: el archivo trae `valor_comercial` 76.000 con contrapago = Sí y la guía tiene recaudo esperado 51.000 → quedaría aplicada con `DISCREPANCIA_RECAUDO` (decisión de Camilo: actualizar y registrar).

## 14. Cargue 6, paso «Resultado» y confirmación en Logística (2026-10-18)

### 14.1 Diagnóstico del cargue 6 (78 filas; 4 válidas, 73 rechazadas, 1 vacía)

- **Archivo distinto del anterior** (hash `fb07fe4d…`, no el de los cargues 1 y 4): tiene **2 filas más**. La **fila 78** está vacía (queda como «vacía», no se rechaza) y la **fila 79** es una **fila de totales** con solo la columna Q (`valor_seguro_99`) llena con la fórmula `=SUM(Q2:Q78)` (valor en caché 0). Por eso 76 + 2 = 78; no se borró ni se recortó ninguna fila: originales y auditoría intactos.
- **`OBLIGATORIO_VACIO` = 5 incidencias en UNA fila (la 79)**: una por cada campo obligatorio vacío —`transportadora`, `numero_de_guia`, `fecha_envio`, `fecha_actualizacion`, `estado_del_envio`— porque esa fila no trae ninguno. El motor rechazó **1 fila** (`rechazadas_motor` = 1); el «5» era el conteo de **incidencias**, que la pantalla rotulaba «Filas».
- **Corrección de la pantalla:** la tabla de códigos separa **«Filas afectadas»** (filas distintas) de **«Incidencias»** (avisos registrados), con una nota que lo explica. El resumen incluye `incidencias_total`. Las filas rechazadas se reparten: 73 = 1 (motor) + 72 (procedimiento: 70 `GUIA_NO_REGISTRADA` + 2 `TRANSPORTADORA_SIN_EQUIVALENCIA`).
- **Versión:** el cargue 6 usa la **versión 2 del tipo** (id 3, `sp_cg_envios_completos_v2`; las cargas 1–4 usaron la versión 1). «Versión 1» era un **texto de la descripción del tipo** («… Versión 1 del procedimiento»), ya retirado. Ahora la tarjeta muestra **«Versión N · procedimiento»** de la versión vigente y la cabecera de la carga muestra la versión **real con la que se creó** (`detalle.version`); el Historial también la expone.
- **Estado del cargue 6:** `validada`, **no ejecutada** (0 aplicadas, sin efecto en negocio). Quedó listo para que Camilo pulse «Ejecutar». Se canceló el cargue 5 (verificación duplicada mía) para que el único abierto sea el 6.

### 14.2 Paso «Resultado» (Cargues)

- **Validar** no presenta nada como aplicado: «Esto es solo la validación: ninguna fila se ha aplicado todavía» y las filas válidas figuran como «Válida · pendiente de ejecutar».
- **Tras ejecutar y consolidar** la pantalla muestra sola el resultado **persistido**; se alimenta de T6/T7 + el complemento, así que **recargar o abrir desde el Historial muestra lo mismo** (prueba automática y visual). Si la ejecución falla o queda pendiente/incierta se muestra el aviso y «Recuperar», nunca éxito.
- **KPIs como reparto:** aplicadas + sin cambio + antiguas + rechazadas + no ejecutadas + vacías = filas del archivo (se muestra el cuadre). «Con observación» es un **subconjunto** (línea aparte), no filas adicionales.
- **Detalle por fila** (`GET /admin/cargues/cargas/{id}/filas?estado=&q=&pagina=`, paginado de 25; solo administrador, como todo Cargues): fila de origen, identificación (transportadora y guía), resultado, motivo, y al expandir: valores leídos, avisos (código, efecto, campo) y la **comparación** que aporta el complemento del tipo. Filtros: todas / válidas / aplicadas / sin cambio / antiguas / rechazadas / no ejecutadas / vacías / con observación; búsqueda por guía, valor o número de fila.
- **Motor genérico:** `ResultadoFilasService` no conoce 99 Envíos. El detalle específico (transportadora efectiva, guía de Logística, estado anterior/nuevo o ya registrado, recaudo reportado/interno/diferencia) sale de un **complemento opcional** (`Contracts/ComplementaResultado`, registrado por tabla intermedia en `cargues.resultado.complementos`), de **solo lectura**.
- **Descargas:** «Resultado completo» (todas las filas con estado, detalle y una línea por incidencia) y «No cargadas» (solo rechazadas), CSV y XLSX, con la fila de origen y las columnas originales.
- **Fuera de alcance de esta entrega:** una vista previa del resultado *antes* de ejecutar solo existe como estado «pendiente»; el estado anterior/nuevo exacto se conoce solo tras ejecutar (hasta entonces se muestra lo ya registrado).

### 14.3 Logística › Entregas: confirmación visible del reporte de 99 Envíos

Solo **lectura** (`ReporteProveedorService`); no escribe nada ni toca entregas, inventario, pagos, comisiones ni valores internos.

- **Listado (compacto):** «Cruzada con 99 Envíos» solo si existe un reporte **aplicado y persistido** para la guía (el SP lo escribe en la misma transacción que la marca de la fila; una ejecución revertida no deja nada), el estado reportado («99 Envíos: Entregada») y un aviso «Recaudo difiere» cuando la conciliación difiere. Para guías con origen 99 Envíos **sin** reporte aplicado dice «Sin reporte de 99 Envíos»; las guías de otros orígenes no muestran nada.
- **Drawer «Ver» → sección «Estado reportado por 99 Envíos»:** proveedor (99 Envíos) **separado** de la transportadora efectiva; estado reportado y **fecha del estado** (la informa el proveedor); **último cruce aplicado en ByLopez** (otra fecha) con la referencia «Cargue #N · fila F · n reporte(s)»; recaudo reportado, recaudo interno comparado, diferencia y resultado de la comparación; valor comercial, **valor del servicio** y **seguro** tal como los informa el proveedor (no se llaman «flete»); historial de estados reportados (estado, fecha, estado anterior, fecha del cruce). Sin reporte: «Sin reporte de 99 Envíos».
- **Aviso permanente:** «Es lo que informa el proveedor. No es una entrega registrada ni validada por ByLopez, no es un pago…». El estado de **entrega** y de **validación** del envío siguen derivándose únicamente de los eventos de Logística (probado: «Entregada» según 99 Envíos con 0 entregas registradas → el envío sigue «pendiente»).
- **Permisos:** la sección usa el mismo permiso de Entregas (`logistica.entregas`); ver Logística **no** concede acceso a Cargues (la referencia al cargue es solo texto, sin enlace) ni al revés.
- **No incluido:** la página clásica de detalle del envío (`?envio=ID`, respaldo sin JavaScript) no muestra esta sección; solo el listado y el drawer.

> **Aclaración (2026-10-19) sobre §9:** «las 65 guías están en la exportación P» se refiere a `Envios_Completos_2026-10-03 (1).xlsx` (152 filas, SHA-256 `b1669ad84b0873e6…`, envíos del 2026-08-12 al 2026-09-30), que **no** está en el repositorio. El archivo del repositorio (`docs/Envios_Completos_2026-10-03.xlsx`, `fb07fe4d84c7…`; 76 guías del 2026-09-14 al 2026-10-02) **no** contiene ninguna de esas 65 guías. Ambas cifras (65/65 y 0/65) son correctas, cada una contra su archivo. Detalle con hashes en `14_CLIENTES_HISTORICOS.md` §12.1.
