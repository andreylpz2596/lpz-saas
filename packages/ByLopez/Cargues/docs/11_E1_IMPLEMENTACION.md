# 11 — Entrega E1: implementación, evidencia y prueba manual

**Fecha:** 2026-10-14 · **Revisión del plan:** 07 rev. 3.3 / 09 rev. 3.3 · **Estado:** E1 **implementada en desarrollo**, probada en una instancia aislada. **Migración aplicada en la BD de desarrollo el 2026-10-14** (solo la de Cargues) con el escenario de prueba manual preparado (§8); **protección preventiva de `VALIDAR` en `12`**. E2 y E3 **sin autorizar y sin implementar**.

Marcas: **[IMPLEMENTADO]** existe en código y se probó · **[V]** comprobado con una prueba que se ejecutó · **[PROPUESTO]** decisión técnica tomada en la implementación, sin aprobación expresa · **[PENDIENTE]**.

## 1. Qué hace E1

Solo administrador (rol `cargues.rol_administrador_id`, por defecto 1). Todo ocurre antes de cualquier cambio de negocio.

1. **Configurar tipos** (borrador → validar → revisar contrato → activar): alta y edición en borrador; una versión activa o retirada es inmutable (un cambio crea otra versión). Política de errores obligatoria y sin valor por defecto.
2. **Intermedia dinámica** `bylopez_cgi_*` creada desde `map.tabla`, con llaves reales, cambio estructural (`__nueva` + `RENAME` + reconciliación).
3. **Nuevo cargue** con la UI del mockup: subir CSV/TXT/XLSX → hoja, fila de encabezados, formato CSV → relacionar columnas → "Validar archivo".
4. **En segundo plano** (cola `cargues`): lectura y auditoría (T6 con el original sin normalizar), normalización, duplicados, llenado de la intermedia y `CALL … 'VALIDAR'`.
5. **Resultado**: contadores, incidencias por código, descarga de no cargadas (CSV y XLSX) e historial.
6. **Puerta de "Ejecutar"** con las dos políticas de errores (calcula y responde; **no ejecuta**: eso es E2).

## 2. Decisiones de implementación (pedir corrección si alguna no gusta)

| # | Decisión | Estado |
|---|---|---|
| D1 | La intermedia se crea con `CREATE` (llave `cg_id`) y luego se ajustan las llaves: MySQL/MariaDB exigen que la columna autoincremental sea una llave **dentro** del `CREATE` y Laravel emite los índices aparte. El resultado final es el de `08` §3.1 [V]. Si un paso falla se **descarta** la tabla parcial | [PROPUESTO] |
| D2 | **Llaves únicas y primaria solo con** `identificador`, `entero`, `decimal`, `fecha`, `fecha_hora`, `booleano`. `texto` no: la BD lo compararía sin distinguir mayúsculas ni acentos y PHP no [V, `08` §3.3]. Los `identificador` se comparan exactos (`utf8mb4_nopad_bin`) | [PROPUESTO] |
| D3 | Una fila **completamente vacía** se conserva en T6 con su número y estado `vacia`: no se rechaza ni cuenta como error | [PROPUESTO] |
| D4 | `vacio` en modo procedimiento admite `rechazar`, `nulo`, `ignorar` (`default` no existe: la intermedia no tiene valores por defecto). Si el campo es obligatorio solo vale `rechazar`. Sin declarar: `rechazar` si es obligatorio, `nulo` si no | [PROPUESTO] |
| D5 | Un identificador que llega como **número** se acepta con una **advertencia** (`IDENTIFICADOR_NUMERICO`): el archivo ya pudo perder sus ceros. No rechaza | [PROPUESTO] |
| D6 | Un decimal con más decimales que la escala se **rechaza** (nunca se redondea); un número de Excel se lee con su representación más corta y sin el formato regional. El separador de miles solo vale entre grupos de 3 cifras (`1.5` con `.` de miles no es 15) | [PROPUESTO] |
| D7 | Booleanos por defecto: verdadero `1,true,verdadero,si,sí,s,x,yes,y`; falso `0,false,falso,no,n` (configurable en `formato`) | [PROPUESTO] |
| D8 | Duplicados `rechazar_todas` se evalúa por grupo; `primera`/`ultima` en orden. La restricción real de la BD es la garantía final: lo que se escape se rechaza como `LLAVE_DUPLICADA_BD`, nunca con `INSERT IGNORE` | [V] |
| D9 | Las incidencias se escriben con `upsert` (idempotente) y no con `INSERT IGNORE`, que truncaba valores en silencio (lo detectó una prueba) | [V] |
| D10 | Menú sin clave ACL (una clave se podría asignar a roles personalizados) y solo para el administrador: se inyecta en el layout por request. Un rol `all` que no es administrador recibe 403, y uno personalizado también (el middleware va **antes** del `admin` de Bagisto) | [V] |
| D11 | Vista previa de un XLSX que no cabe en la memoria del proceso web se genera en la cola (`GenerarVistaPreviaCarga`); la UI espera sola | [IMPLEMENTADO] |
| D12 | `composer.json`: se agregaron solo las dos líneas PSR-4 (`ByLopez\Cargues\` y `\Tests\`). **Ninguna dependencia nueva**; `composer.lock` no lo modifiqué | [IMPLEMENTADO] |

## 3. Contrato del procedimiento: lo que se revisa y lo que NO se puede garantizar

`VALIDAR` no debe modificar el negocio. **Un `CHECKSUM` no sustituye revisar el contrato**, así que hay capas:

| Capa | Cuándo | Qué hace | Límite |
|---|---|---|---|
| **Cuenta restringida y privilegios efectivos** (`12`) | Cada `VALIDAR` y al activar | `VALIDAR` corre con una cuenta de BD distinta, con solo `SELECT` en lo declarado y `UPDATE` en su intermedia; se leen sus `GRANT`, el `SQL SECURITY`/`DEFINER` del procedimiento y de toda rutina ejecutable, y los triggers/FK de la intermedia. **El servidor niega** la escritura (también en tablas no declaradas y con `COMMIT` interno) [V] | Protege `VALIDAR`, no `EJECUTAR` (E2). Solo MariaDB 10.4.28 probado; producción sin verificar |
| Revisión estática | Al activar (y al "Revisar contrato") | Busca DDL, archivos, escritura en tablas que no son la intermedia ni están en `map.tablas_negocio`, `COMMIT`/`ROLLBACK` en el modelo `motor`. **Errores bloquean.** Advierte de SQL dinámico, `CALL` anidado, ausencia de `VALIDAR` o de `p_carga_id` | Es heurística (expresiones regulares): no prueba nada |
| **Confirmación humana** | Antes de activar | Quien activa confirma haber leído el procedimiento. Queda ligada a la **huella** de su definición + `sql_mode`; si cambia, hay que confirmar de nuevo | Depende de que se lea |
| Huella congelada | Antes de cada `VALIDAR` | Si cambió la definición o el `sql_mode`, no se ejecuta | — |
| Vigilancia en ejecución | Durante `VALIDAR` | Dentro de una transacción compara `CHECKSUM` de las tablas de negocio **declaradas** y la huella de los **datos de entrada**; exige que solo marque `rechazada` y que la sesión conserve el bloqueo; ante cualquier violación hace `ROLLBACK` y la carga falla | Un `COMMIT` interno (modelo `procedimiento`) **no se puede deshacer**; solo se detecta [V]. Una escritura en una tabla **no declarada** no se detecta en ejecución |
| Bloqueo con nombre | `VALIDAR` | `GET_LOCK('cg:<esquema>:<carga>', 0)` en sesión dedicada; evita solaparse con otra validación o con E2 | Por servidor (no se replica en clúster) |

**[IMPLEMENTADO]** la protección preventiva (antes solo propuesta): detalle, qué cierra y qué no, y configuración en **`12_PROTECCION_VALIDAR.md`**. Una cuenta con `SELECT` **no** limita a un procedimiento `SQL SECURITY DEFINER` (corre con los privilegios de su definidor): por eso se evalúan los privilegios efectivos y se exige `INVOKER`. Los límites de la vigilancia posterior (un `COMMIT` interno no se deshace; una tabla no declarada no se ve) **quedan cerrados mientras la protección esté activa**; sin cuenta configurada y con `CARGUES_VALIDAR_EXIGIR_CUENTA=false` vuelven a aplicar.

## 4. Límites configurables (`config/cargues.php`, y por tipo solo a la baja)

Conservadores y ajustables; **no son límites de negocio**.

| Parámetro | Valor | Nota |
|---|---|---|
| `max_bytes` | 20 MB | Se rechaza **al subir**, antes de leer |
| `max_celdas` (XLSX) | 300 000 | Se mide sobre el contenido **real** |
| `max_filas` | 200 000 | |
| `max_columnas` | 150 | |
| Hoja XLSX descomprimida | 40 MB | Se mide al descomprimir en streaming, no con lo que declara el ZIP |
| Razón de compresión | 200:1 | Contra bombas de compresión |
| Memoria | la mitad del `memory_limit` menos 60 MB, a 700 B por celda | Si no cabe, el XLSX se rechaza antes de cargar |

El archivo no se confía a `<dimension>`: se cuentan `<c>` y `<row>` reales y se comprueba que los bytes leídos coincidan con el tamaño declarado. **Las 152 filas del archivo de referencia son solo el máximo observado en las exportaciones del equipo; no son un límite de negocio.** El adjunto "(2)" no llegó y **no se revisó**.

## 5. Cola `cargues`: configuración, inicio del worker y verificación

**No altera otros módulos:** solo agrega la conexión `queue.connections.cargues` (driver `database`, tabla `jobs`, cola `cargues`). `queue.default` (hoy `sync` en dev) y las demás conexiones no se tocan [V].

| Parámetro | Valor |
|---|---|
| Intentos | `tries = 1`: **ningún** reintento automático (reanudar es explícito) |
| `timeout` del job | 900 s (`CARGUES_JOB_TIMEOUT`) |
| `retry_after` de `cargues` | 1500 s (`CARGUES_QUEUE_RETRY_AFTER`), **mayor** que el timeout. `cargues:diagnostico` lo verifica |
| Reentrega | si ocurre igual, el job duplicado no procesa: el CAS por `job_uuid` lo detiene y, si la carga muestra progreso reciente, **no** la marca como fallida [V] |

**Iniciar el worker** (dejarlo supervisado en producción; en dev, una terminal):

```
php -d memory_limit=512M artisan queue:work cargues --queue=cargues --tries=1 --timeout=900 --memory=512 --sleep=3
```

En desarrollo el worker se dejó iniciado **desligado del terminal** (`Start-Process`, ventana oculta; salidas en el *scratchpad* de la sesión). Para detenerlo: `Stop-Process -Id <pid>` (el pid sale de `cargues:diagnostico`: «Worker ACTIVO (pid …)»). **Un worker no recarga el código:** tras cualquier cambio en el paquete hay que reiniciarlo (o `php artisan queue:restart`, que solo funciona con cache compartida).

**Verificar que funciona:**

```
php artisan cargues:diagnostico            # configuración, cola y job de prueba (espera hasta 15 s)
php artisan cargues:diagnostico --sin-ping # solo configuración y estado de la cola
```

Muestra la conexión, `timeout` y `retry_after` (error si no cumple), jobs en espera/en proceso/fallidos y, con la cache compartida entre procesos (`file`, `redis`), **"Worker ACTIVO (pid …)"** si un worker procesó el job de prueba; si no, sale con código 1. Probado con y sin worker [V]. La UI también avisa "en cola desde hace N s" y, pasados 45 s, que quizá no haya worker.

## 6. Pruebas ejecutadas (instancia aislada)

Entorno: MariaDB 10.4.28 propia, puerto **3401**, datadir en el *scratchpad*, bases `lpz_saas_testing` (principal, migrada y con las semillas base de Bagisto) y `cg_tipo_test` (conexión del tipo). `CarguesTestCase` aborta sin escribir si el puerto es 3306, el datadir no está en un *scratchpad*, falta `CARGUES_PRUEBAS_AISLADAS=1`, existe una base `lpz_saas`/`bagisto` o la base conectada no es `lpz_saas_testing`. **Nunca se tocó la BD habitual de desarrollo** (solo lecturas: tablas `jobs`/`roles`).

**Resultado final: 125 pruebas, 5 232 aserciones, todas pasan** (≈ 9,7 min, incluidos workers reales; las 10 nuevas son `ProteccionValidarTest`, ver `12` §5; las 115 anteriores no cambiaron). Corrida el 2026-10-14 tras el cambio de `VALIDAR`. `vendor/bin/pint --test` pasa. `packages/Webkul/` sin cambios.

| Criterio de `09` | Prueba | Resultado |
|---|---|---|
| F1-S1 | **toda** ruta del módulo (24, descubiertas por nombre) da 403 a un rol `all` que no es administrador y a un rol personalizado; sin sesión redirige; administrador deshabilitado no entra | ✓ |
| F1-S2 | el ítem de menú solo está en el HTML del administrador; "Configurar tipos" solo existe para él | ✓ |
| F1-S3 | `conexion`, `procedimiento`, `tabla`, `tabla_destino`, `politica_errores`, `tipos`, `limites`, `map`, `sql` → 422 sin crear carga ni archivo; acción fuera de lista → 422; en `lectura`, `validar`, `ejecutar` y `configuracion` también | ✓ |
| F1-C1 | tipo de 5 campos activa **sin migración**; índices exactos con `cg_carga_id` al inicio; `cg_ausentes`; dos cargas con la misma guía conviven | ✓ |
| F1-C2 / C2b | nombre inválido/reservado/`cg_`, primaria nulable, texto en llave única, `UNIQUE` largo (que 10.4 aceptaría como HASH), tabla existente no registrada, procedimiento inexistente o con otra firma, obligatorio con regla ≠ `rechazar`: **se rechazan sin DDL**. Sin política: borrador, no activa | ✓ |
| F1-C3 | misma huella reutiliza la tabla y T3 no cambia | ✓ |
| F1-C4 / C5 | carga abierta bloquea (tabla idéntica por `CHECKSUM`); falla de `__nueva` no cambia nada; éxito deja `__a1` idéntica; reconciliación tras RENAME, antes de RENAME y estado parcial (intervención manual y bloqueo) | ✓ |
| F1-C6 | cambio del cuerpo **o solo del `sql_mode`** del procedimiento bloquea la validación | ✓ |
| F1-L1 | XLSX 1900 y 1904: **32 comprobaciones por calendario** (el conjunto de `08` §3.8 se reescribió con la semántica de `FilaLeida`: no son las 33/34 celdas de entonces); exportación web de 152 × 28 celdas leída **exacta**; el calendario 1904 no contamina | ✓ |
| F1-L2 | CSV UTF-8 con BOM y Windows-1252 con `;`, comillas, salto dentro de campo y 5 000 caracteres; byte inválido en UTF-8 se rechaza con su registro | ✓ |
| F1-L3 | T6 conserva `0012345678`, `+57…`, `=1+1`, espacios, `""` y celdas ausentes (original) y lo normalizado va aparte | ✓ |
| F1-L4 | límite de celdas sobre lo **real** aunque `<dimension>` mienta o falte; bomba de compresión; tope sobre bytes **reales**; ZIP manipulado; nombres inseguros y macros; estimación de memoria; `max_bytes` al subir sin dejar archivo | ✓ |
| F1-L5 | `Producto` y `producto` son dos columnas y no se sugiere ninguna | ✓ |
| F1-L6 | sin relacionar, vacía/ausente/espacios, `NULL` como texto, reglas `rechazar`/`nulo`/`ignorar` (unitarias y de punta a punta) | ✓ |
| F1-D1 / D2 | las 12 filas de `08` §3.2 con las tres políticas (3,5,7,8,10 · 5,8,10 · 3,5,7), T6 = intermedia + rechazadas; `G100`/`g100` distintas y `G200`/`G200 ` duplicadas; la restricción real rechaza lo que se escape | ✓ |
| F1-V1 | `VALIDAR` correcto no cambia el negocio; **y** se detectan y deshacen: escribir una tabla declarada, cambiar la entrada, marcar otro estado, `ERROR`, resultado inválido, `SIGNAL`; límite del `COMMIT` interno documentado | ✓ |
| F1-B1 | "Validar" responde en < 1 s con el id (10 000 filas) y deja 0 filas leídas y 1 job en la cola `cargues` | ✓ |
| F1-B2 | worker real, 20 000 filas: progreso monótono por etapa hasta 100 %, etapa final `finalizado` (se corrigieron dos carreras que mostró la prueba) | ✓ |
| F1-B3 | worker muerto a mitad de la lectura: "sin noticias" tras el umbral, reintento solo si está vencido (CAS), 60 000 filas sin duplicar | ✓ |
| F1-B4 | `retry_after` = 1 s con dos workers: entrega duplicada registrada como fallida **sin** marcar la carga; 60 000 filas exactas | ✓ |
| F1-B5 | dos cargas de dos tipos en paralelo con dos workers, sin mezclar totales | ✓ |
| F1-P1 / P2 | bloquear todo → 409 aunque se llame directo; procesar válidas → 422 sin el número **exacto** de rechazadas; nada cambia | ✓ |
| F1-U1 / U2 | **abierta en Chrome headless** contra la instancia aislada: flujo completo sin errores de consola ni de red; sin desbordamiento horizontal a 390 px; modo oscuro con corrección de los pasos (capturas en `capturas_e1/`). **La revisión visual formal sigue siendo tuya** | ver §7 |
| F1-Q1 | Pint pasa; `packages/Webkul/` sin cambios. `bagisto:translations:check` **no cubre** los paquetes ByLopez (ni Logística) y ya falla de base (293 comprobaciones de Webkul); `es` y `en` de Cargues tienen las mismas 217 claves | parcial |

También comprobado a mano (no en Pest): `cargues:diagnostico` con y sin worker.

## 7. Qué NO está probado

- **Visual y de uso reales:** las capturas son de un navegador headless con datos sintéticos; no equivalen a tu revisión. No se probó Safari/Firefox ni el drag-and-drop con un archivo real de Excel, ni XLSX grandes desde el navegador.
- **Archivo real de Envíos completos / el adjunto "(2)":** no se usó. Las pruebas usan datos sintéticos.
- **MySQL 8:** solo MariaDB 10.4.28. La intercalación exacta usa `utf8mb4_0900_bin` en MySQL 8 sin verificarla.
- **Producción (R5):** versión, topología, privilegios, `memory_limit` y worker supervisado, sin verificar.
- **Escritura en una tabla no declarada** y **COMMIT interno** durante `VALIDAR`: cerrados por la cuenta restringida (`12`), solo si está configurada. Sin verificar en MySQL 8 ni producción; `EJECUTAR` (E2) no queda cubierto.
- **E2 (ejecutar, recuperar, consolidar) y E3 (procedimiento real):** sin implementar. Las columnas `ejecucion_token`, `conexion_ejecutor`, `bloqueo_nombre` y `huella_entrada` existen pero `huella_entrada` solo se usa para vigilar `VALIDAR`.
- **Retención:** T6 guarda el original completo de cada fila (con datos personales cuando el archivo los trae); no hay limpieza automática [PENDIENTE].
- Memoria con 200 000 filas: la detección de duplicados guarda las llaves en memoria (≈100 MB en ese extremo); medido hasta 60 000 filas.

## 8. Tu prueba manual (en desarrollo, con datos sintéticos)

**Escenario ya preparado el 2026-10-14 en la BD de desarrollo** (verificado `APP_ENV=local`, `127.0.0.1`, base `lpz_saas`). Se hizo **solo** esto, y **no se limpia**:

- migración **únicamente** de Cargues (7 tablas `bylopez_cg_*`; ninguna otra migración pendiente ni ejecutada);
- procedimiento sintético `sp_cg_prueba_e1` (`SQL SECURITY INVOKER`);
- cuenta de BD restringida `cargues_validar@127.0.0.1` y sus credenciales en el `.env` local (ver `12`);
- tipo **«Prueba E1 (sintético)»** activo, con política `procesar_validas` **elegida solo para esta prueba** (no es una decisión para los tipos reales), intermedia `bylopez_cgi_prueba_e1`; la revisión del contrato quedó registrada como «Preparación automática de la prueba E1 (Claude)»;
- worker de la cola `cargues` (ver §5 para iniciarlo/detenerlo).

Cinco pasos:

1. Entrar como **administrador** (rol 1) a **http://127.0.0.1:8000/admin/cargues** (servidor `php artisan serve`) → debe verse la tarjeta «Prueba E1 (sintético)» con «Activo».
2. Elegir la tarjeta y subir **`packages/ByLopez/Cargues/docs/ejemplos/envios_ejemplo_sintetico.csv`**.
3. Comprobar **«5 de 5 campos relacionados»** y pulsar **Validar archivo** (el avance se ve solo; si dice «en cola desde hace N s», el worker no está corriendo).
4. **Resultado esperado: 40 filas, 36 válidas, 4 rechazadas** (3 por el motor: estado vacío y la guía repetida dos veces; 1 por el procedimiento: sin teléfono).
5. Descargar el CSV/XLSX de **no cargadas** y abrir el **Historial**. Con `procesar_validas`, *Ejecutar cargue* pide escribir el número exacto de rechazadas, y aun así responde que la ejecución no está disponible (es E2: **no cambia nada**).

Extras opcionales: otro rol (no administrador) → 403 y sin menú; detener el worker y validar → «en cola desde hace N s»; `php artisan cargues:proteccion-validar` → «Protección de VALIDAR: cumple».

Para repetir en **otra** base, los pasos manuales son: `php artisan migrate --path=packages/ByLopez/Cargues/src/Database/Migrations`; ejecutar `docs/ejemplos/sp_cg_prueba_e1.sql`; crear la cuenta restringida (`12` §2) y sus variables `.env`; crear el tipo con `docs/ejemplos/tipo_prueba_e1.map.json` (nombre, conexión `mysql`, procedimiento `sp_cg_prueba_e1`, acción `actualizar_estado`, **elegir** la política) → *Guardar borrador* → *Validar configuración* → *Revisar contrato* → *Activar*.

**Limpieza (solo cuando tú lo decidas; no se ejecutó):** `DROP TABLE bylopez_cgi_prueba_e1; DROP PROCEDURE sp_cg_prueba_e1; DROP USER 'cargues_validar'@'127.0.0.1';`, quitar las dos variables `CARGUES_VALIDAR_*` del `.env` y, para deshacerlo todo, `php artisan migrate:rollback --path=packages/ByLopez/Cargues/src/Database/Migrations` (borra las 7 tablas del módulo).

## 9. Siguiente paso

Revisión visual y prueba manual de E1 (§8). Con tu visto bueno: autorizar **E2** (ejecutar, recuperar, consolidar, con procedimiento sintético) y cerrar R4 (decisiones D-ENV de `10` §7) para **E3**.
