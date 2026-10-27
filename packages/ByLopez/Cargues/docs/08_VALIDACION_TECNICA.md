# 08 — Validación técnica acotada (revisión 3.2)

**Fecha:** 2026-10-13 · **Alcance:** pruebas con datos sintéticos en un entorno desechable aislado. **No se implementó el módulo**, no se tocó la base del proyecto (`lpz_saas`), ni `composer.json`/`composer.lock`, ni `packages/Webkul/`.

> **Rev. 3.3 (2026-10-14):** la lectura, memoria y fidelidad con el **archivo real** de Envíos completos, y su formato escalado a 100k filas × 28 columnas, están en `10` §5. Ese análisis no usó bases de datos.

Este documento separa:
- **[COMPROBADO]**: ejecutado aquí, con el resultado que se cita;
- **[DOC]**: afirmado por la documentación oficial, no ejecutado;
- **[PROPUESTO]**: recomendación derivada, no aprobada.

## 1. Entorno y aislamiento [COMPROBADO]

| Elemento | Valor |
|---|---|
| Instancia **P** (conexión principal: T3, T5–T7) | `mysqld` MariaDB **10.4.28** propio, `127.0.0.1:3399`, datadir en el *scratchpad* de la sesión |
| Instancia **T** (conexión del tipo: intermedia, destinos, procedimiento) | Otro `mysqld` 10.4.28, `127.0.0.1:3400`, datadir separado en el *scratchpad* |
| Base de dev (`3306`, `C:\xampp\mysql\data`) | **No se escribió.** Solo se leyeron variables (`wait_timeout`, etc.) |
| PHP | 8.3.33 CLI (ZTS, Windows), `memory_limit` 128M por defecto; las mediciones usan `-d memory_limit` explícito |
| PhpSpreadsheet | 1.30.6 del `vendor/` del proyecto, **solo cargado** (sin modificar) |
| OpenSpout | **5.3.0 (MIT)** instalado con `composer` en un directorio aparte del *scratchpad*; `composer.json`/`.lock` del proyecto sin cambios (fecha de modificación 2026-10-10, sin la cadena `openspout`) |
| Equipo | Intel i5-4210U 1,7 GHz (2 núcleos). Las cifras de tiempo **solo sirven para comparar lectores entre sí en este equipo** |

**Guardia de aislamiento:** cada conexión de prueba verifica antes de escribir que `@@port` sea 3399/3400, que `@@datadir` esté en el *scratchpad* y que no exista ninguna base cuyo nombre contenga `lpz` o `bagisto`; si no se cumple, aborta (código 99).

Los scripts y registros están en el *scratchpad* de la sesión (`cg/scripts`, `cg/out`). **No se incorporan al repositorio** (solo se autorizan MD). Son temporales; este documento conserva lo necesario para reproducir cada prueba.

## 2. Resumen de resultados

| # | Prueba | Resultado |
|---|---|---|
| V1 | Creación dinámica desde el map con PK, UNIQUE e índices; aislamiento por carga | **OK** (20/20 aserciones) |
| V2 | Duplicados con `rechazar_todas`, `primera` y `ultima`; originales en T6; output de rechazadas | **OK**, más un hallazgo de intercalación (§3.3) |
| V3 | Procedimiento sintético que escribe dos tablas con marcas en la misma transacción | **OK** |
| V4 | Seis escenarios de caída real del proceso PHP y reanudación | **OK** en los seis |
| V5 | Consolidación de la auditoría en **otro servidor** tras una caída y una consolidación interrumpida | **OK**; idempotente |
| V6 | Cambio de estructura con carga abierta; atomicidad del `RENAME`; reconciliación; huella del procedimiento | **OK**, más dos hallazgos (§3.6) |
| V7a | Fidelidad XLSX celda a celda (calendarios 1900 y 1904) | PhpSpreadsheet completo: **100 %**. OpenSpout: 3 diferencias. PhpSpreadsheet `readDataOnly`: pierde fechas |
| V7b | Memoria y tiempo XLSX con 10k, 50k y 100k filas | Ningún lector gana en todo: PhpSpreadsheet es rápido pero su memoria crece con las celdas (1052 MB con 2M); OpenSpout tiene memoria constante pero su tiempo depende de las cadenas únicas frente al `memory_limit` (§3.8) |
| V7c | CSV: fidelidad, memoria y tiempo | `fgetcsv` y OpenSpout: 100 %, 16 MB; `fgetcsv` entre 3 y 6 veces más rápido |
| — | `TRUNCATE` real con respaldo y `LOCK TABLES` (S2) | **No ejecutada.** Sigue pendiente de validación (§4) |

## 3. Detalle por prueba

### 3.1 V1: intermedia creada desde el map [COMPROBADO]

Map de prueba: `guia` (identificador, `nulo:false`), `telefono` (identificador), `estado` (opción), `valor` (decimal), `fecha_estado` (fecha_hora). Llaves: primaria `[guia]`, única `[[telefono]]`, índice `[[estado]]`.

Índices obtenidos de `information_schema.STATISTICS`:

| Índice | Columnas |
|---|---|
| `PRIMARY` | `cg_carga_id, guia` |
| `cg_uq_id` (UNIQUE) | `cg_id` (autoincremental indexado, obligatorio en InnoDB) |
| `cg_uq_fila` (UNIQUE) | `cg_carga_id, cg_fila_origen` |
| `cg_uq_0` (UNIQUE) | `cg_carga_id, telefono` |
| `cg_ix_0` | `cg_carga_id, estado` |
| `cg_ix_estado` | `cg_carga_id, cg_estado` |

Comprobado:
- Misma guía y mismo teléfono en cargas 1 y 2: **aceptado** (la unicidad es por carga).
- Guía o teléfono repetidos en la misma carga: **error 1062**.
- Dos teléfonos `NULL` en la misma carga: **aceptado** (semántica de `UNIQUE` con `NULL`).
- Guía `NULL`: **error 1048**.
- `DELETE … WHERE cg_carga_id = 1` no toca la carga 2.
- Huella de estructura estable ante el orden de los campos y las etiquetas; cambia con la longitud de un campo.
- Un map con un nombre de campo malicioso (`` guia`; DROP TABLE x; -- ``) y una primaria sobre un campo nulable se **rechaza** antes de generar DDL.

### 3.2 V2: duplicados sin pérdida de evidencia [COMPROBADO]

Doce filas con la guía `G101` en las filas 3, 5 y 10, y el teléfono `…04` en las filas 7 y 8.

| Política | Rechazadas | T6 | Intermedia | Output |
|---|---|---|---|---|
| `rechazar_todas` | 3, 5, 7, 8, 10 | 12 | 7 | 5 filas |
| `primera` | 5, 8, 10 | 12 | 9 | 3 filas |
| `ultima` | 3, 5, 7 | 12 | 9 | 3 filas |

- Ninguna fila aceptada chocó con la restricción real.
- El output de rechazadas incluye `fila_origen`, `estado_final`, `etapa`, `codigo`, `motivo` y **todas las columnas originales con su encabezado y orden**. Se comparó cada fila con el original: idénticas.
- La celda `=HYPERLINK("http://x")` sale neutralizada como `'=HYPERLINK…` en el CSV.
- `0012345` y `12345` se conservan como guías distintas.
- **Garantía final:** una inserción duplicada que escapa a la detección da error 1062. Se revierte solo esa fila con `SAVEPOINT` y la carga conserva sus 7 filas aceptadas.

### 3.3 Hallazgo: intercalación de los identificadores [COMPROBADO]

Con la intercalación por defecto `utf8mb4_unicode_ci`, la BD considera **iguales** `G100`, `g100` y `G100 ` (espacio final). Una detección de duplicados en PHP con comparación exacta no los ve como duplicados, y la restricción los rechaza después. `G100\t` (tabulador) sí es distinto.

| Intercalación | `'G100' = 'g100'` | `'G100' = 'G100 '` |
|---|---|---|
| `utf8mb4_unicode_ci` | 1 | 1 |
| `utf8mb4_bin` | 0 | 1 (*PAD SPACE*) |
| `utf8mb4_nopad_bin` | 0 | 0 |

**[PROPUESTO]**
- Los campos de tipo `identificador` se crean con `utf8mb4_nopad_bin`: comparación exacta, la misma que hace PHP.
- Si un tipo necesita comparar sin distinguir mayúsculas, que lo declare en el map y la detección en PHP normalice igual que la intercalación elegida.

En ambos casos, la detección previa y la restricción deben usar **la misma regla**.

### 3.4 V3: procedimiento sintético sobre dos tablas [COMPROBADO]

`sp_cg_prueba_lotes(p_carga_id, p_accion, p_modo, OUT p_resultado, OUT p_mensaje)`. Firma del contrato `07` §8.1, modelo `procedimiento`. Lotes de 200 filas, y cada lote:
1. bloquea sus filas (`SELECT … FOR UPDATE`);
2. marca `omitida_antigua` y `sin_cambio` (regla "no retroceder");
3. inserta en `envios_historial` (tabla 1);
4. hace upsert en `envios` (tabla 2);
5. marca `aplicada`;
6. hace `COMMIT`.

Efecto y marca van en la misma transacción. Una variante `sp_cg_prueba_motor` (modelo `motor`) no tiene `COMMIT` interno.

Con 2000 filas y 500 envíos preexistentes, el resultado esperado se calculó **por separado en PHP**: aplicada 1881 (1485 insertadas, 396 actualizadas), omitida_antigua 50, sin_cambio 50, rechazada 19.

- `VALIDAR` marca solo las 19 rechazadas; `CHECKSUM TABLE envios, envios_historial` es igual antes y después: **no toca el negocio**.
- `EJECUTAR` (0,39 s) produce exactamente el resultado esperado.
- **Invariante efecto⇔marca**, verificado tras cada escenario:
  - cada fila `aplicada` tiene exactamente una fila de historial, y el envío coincide;
  - ninguna fila sin marca tiene efecto;
  - no hay historial duplicado.
- Un segundo `EJECUTAR` sobre la misma carga no cambia nada, porque solo procesa `cg_estado IS NULL`.

### 3.5 V4: caídas reales y reanudación [COMPROBADO]

Un proceso PHP hijo toma `GET_LOCK('cg_carga_<id>', 0)`, registra su `CONNECTION_ID()` en la cabecera (instancia P) y ejecuta el `CALL` (instancia T). El orquestador lo mata con `proc_terminate` (*TerminateProcess*), observa el bloqueo y la sesión cada 100 ms, clasifica y reanuda.

En todos los escenarios:
- durante la ejecución, `IS_FREE_LOCK = 0` e `IS_USED_LOCK` devuelve el id de la sesión ejecutora;
- un segundo ejecutor recibe `GET_LOCK = 0`;
- tras reanudar, el resultado es idéntico al de una ejecución sin caídas;
- las marcas previas a la caída quedan **byte a byte iguales** (no se borran ni se reescriben);
- el invariante efecto⇔marca se cumple.

| Esc. | Situación | Qué pasó en el servidor | Clasificación tras liberar el bloqueo |
|---|---|---|---|
| S1 | Modelo lotes; PHP muere en pleno `CALL` (pausa `SLEEP` entre efecto y `COMMIT`) | **El procedimiento siguió ejecutándose hasta terminar** (de 793 a 1981 filas procesadas tras la muerte de PHP). El bloqueo siguió tomado 3,4 s y se liberó al acabar la sesión | 0 filas sin marca; nada que clasificar |
| S2 | Igual, con trabajo de CPU | Igual: siguió 4,7 s hasta terminar | Igual |
| S6 | PHP muere y el administrador ejecuta `KILL CONNECTION` | El lote en curso se **revirtió**; bloqueo libre en menos de 1 s | 990 filas sin marca → `no_ejecutada`. Ninguna tenía efecto. Se reanudó y completó |
| S3 | Modelo motor; PHP muere tras el `CALL` y antes del `COMMIT` | El servidor detectó el cierre del socket, **revirtió todo** y liberó el bloqueo en 0,27 s | 0 marcas y destinos idénticos a la base (`CHECKSUM`) → 1981 `no_ejecutada`. Se reejecutó |
| S4 | Modelo motor; PHP muere tras el `COMMIT` y antes de consolidar | Efectos y marcas confirmados | Consolidación (V5) |
| S5 | **Cliente colgado** que no cierra el socket (equivale a una red caída sin aviso), con `wait_timeout = 5` en la sesión | Bloqueo y transacción retenidos hasta que el servidor cerró la sesión por `wait_timeout` (5,0 s); luego rollback y bloqueo liberado. Al despertar, el `COMMIT` de PHP falló con **`2006 MySQL server has gone away`** | 1981 `no_ejecutada`. PHP **no** puede asumir que confirmó |

**Consecuencias:**
- La muerte de PHP **no** detiene el procedimiento (S1 y S2). Por eso no se clasifica nada mientras el bloqueo siga tomado.
- Un cliente colgado retiene el bloqueo hasta `wait_timeout`, que en dev vale **28800 s (8 h)**, el valor por defecto [COMPROBADO en 3306, solo lectura].

### 3.6 V5: consolidación en otra conexión [COMPROBADO]

Auditoría (T5–T7) en la instancia P y marcas en la instancia T: **dos servidores**, así que no hay transacción común posible.
- Tras S4, las 2000 filas de T6 están sin estado aunque el negocio está confirmado.
- Una consolidación interrumpida a propósito tras 2 lotes deja 998 filas consolidadas.
- Al reconsolidar, el resultado converge al esperado. Una tercera consolidación produce **la misma huella** de T6 y T7 (idempotente).
- Las incidencias no se duplican: 19, gracias a la clave única `(carga_dato_id, etapa, codigo)` y al upsert.
- La consolidación no reejecuta el negocio: `CHECKSUM` de los destinos igual.

### 3.7 V6: cambio de estructura [COMPROBADO]

- **Carga abierta (502, `preparada`):** la activación de la v2 se **bloquea**; la tabla y T3 quedan intactas, y la carga 502 sigue operando sobre la v1.
- **Falla al crear `__nueva`** (columna duplicada, saltando el validador a propósito): error 1060. No queda `__nueva`, la vigente sigue intacta y T3 sin cambios.
- **Atomicidad en operación normal** de `RENAME TABLE a TO b, c TO d`:
  - si el **segundo** par falla (origen inexistente, 1146; o destino existente, 1050), el **primer** par no queda aplicado;
  - un lector concurrente en otro proceso hizo 6887 consultas durante 200 intercambios de tres vías (18,7 ms cada uno) y **nunca** recibió 1146 ("la tabla no existe").
  - **Hallazgo:** sí recibió 24 errores **1615** (*Prepared statement needs to be re-prepared*) con sentencias preparadas en el servidor. Son transitorios y se pueden reintentar.
- **Activación real:**
  - la v1 quedó archivada como `__a1`, con los históricos de las cargas 501 y 502 **idénticos** (`CHECKSUM`);
  - la vigente es la v2, vacía, con la columna nueva;
  - T3 registra `archivada`/`activa`.
- **Reconciliación tras la caída del *proceso*:**
  - después del `RENAME` y antes de registrar en T3, detecta "RENAME aplicado" y completa el registro;
  - después de crear `__nueva` y antes del `RENAME`, detecta "no aplicado", descarta `__nueva` y la versión sigue en borrador.
- **Renombrado parcial** (vigente ausente, archivo y `__nueva` presentes), simulado a mano: es el estado que podría dejar una caída del *servidor* en versiones anteriores a 10.6. La reconciliación lo detecta y exige **intervención manual**; no adivina.
- **Hallazgo:** en MariaDB 10.4, un `UNIQUE` sobre `VARCHAR(1000) utf8mb4` **no da error 1071** (MySQL sí): crea una clave única larga de tipo `HASH`. El validador debe aplicar sus propios límites (`07` §5.6) en vez de confiar en que el motor rechace la DDL.
- **Huella del procedimiento:**
  - recrearlo con el mismo cuerpo conserva la huella de `SHOW CREATE PROCEDURE`;
  - cambiar el cuerpo la cambia;
  - el `sql_mode` con que se creó (`information_schema.ROUTINES.SQL_MODE`) cambia el comportamiento sin cambiar el cuerpo, así que **[PROPUESTO]** la huella incluye el `sql_mode`.

**Atomicidad del `RENAME` ante una caída del servidor, según la versión [DOC, no ejecutado]:** según la documentación de MariaDB (*Atomic DDL*), el DDL atómico y seguro ante caídas, incluido `RENAME TABLE`, existe **desde MariaDB 10.6.1**. Antes, "durante un renombrado múltiple, solo algunas tablas quedaban renombradas". Dev usa **10.4.28**, así que un renombrado múltiple puede quedar a medias si cae el servidor. La versión de producción no está verificada. Por eso se mantiene la reconciliación con intervención manual en el estado inconsistente.

### 3.8 V7a/V7b: lectura XLSX [COMPROBADO]

**Fidelidad (V7a).** Archivo construido a mano con la estructura que escribe Excel. Contiene:
- hoja objetivo **no activa**, encabezado en la fila 3, fila 2 y fila 5 vacías, encabezado "Guía" duplicado;
- cadenas compartidas, en línea y enriquecidas;
- fechas con formatos estándar y propios;
- fórmula con valor en caché y fórmula sin él;
- texto que empieza por `=`, error `#N/A`, número de 19 dígitos, `0.1+0.2`, 5000 caracteres, espacios iniciales y finales;
- una versión con calendario 1904.

| Lector | 1900 | 1904 | Diferencias |
|---|---|---|---|
| PhpSpreadsheet 1.30.6, lectura completa (`readDataOnly=false`) | **33/33** | **34/34** | Ninguna |
| PhpSpreadsheet `readDataOnly=true` | 30/33 | 30/34 | **Pierde todas las fechas**: devuelve el número de serie (sin formatos no se distingue una fecha) |
| OpenSpout 5.3.0 | 30/33 | 31/34 | (1) Un **texto** que empieza por `=` sale como `FormulaCell` con valor calculado `NULL`; el texto se recupera con `getValue()`. (2) Una fórmula **sin** valor en caché sale como `0.0` en vez de "desconocido". (3) Una celda de error pierde su código (`#N/A` → vacío) |

Comunes a ambos:
- numeran correctamente las filas vacías (2 y 5) y leen la hoja no activa;
- el serial 60 del calendario 1900 (Excel muestra 29/02/1900, fecha que no existe) sale como 1900-02-28 en los dos;
- PhpSpreadsheet 1.30 guarda el calendario 1904 en **estado estático global** (`Reader/Xlsx.php:759-763`, `Shared\Date::setExcelCalendar`). En un worker que lea varios archivos, el último calendario leído afecta a las conversiones siguientes; se debe fijar por archivo.

**Rendimiento (V7b).** Archivos sintéticos con la estructura de Excel: cadenas compartidas, 100.023 cadenas únicas cada 10k filas, fechas con formato 14. Un proceso por medición, en secuencia, `memory_limit = 3072M` solo para poder medir. Se mide el tiempo hasta la vista previa (20 filas), el tiempo total y la memoria pico. La "huella" compara la salida normalizada entre lectores (iguales = mismos valores).

Medición con `memory_limit = 3072M` (solo para poder medir), 20 columnas:

| Archivo | Lector | Tiempo total | Hasta la vista previa (20 filas) | Memoria pico | Huella de salida |
|---|---|---|---|---|---|
| 10.000 filas (200k celdas, 100k cadenas únicas) | OpenSpout | 30,4 s | 3,4 s | 18 MB | `f27b20ec` |
| | PhpSpreadsheet completo | 28,3 s | 20,0 s | 124 MB | `f27b20ec` |
| | PhpSpreadsheet por ventanas de 5000 | 38,3 s | 14,9 s | 80 MB | `f27b20ec` |
| 50.000 filas (1M celdas, 500k únicas) | OpenSpout | 596,6 s ⚠ | 18,6 s | 16 MB | `2e1f1c4e` |
| | PhpSpreadsheet completo | 50,7 s | 34,2 s | **534 MB** | `2e1f1c4e` |
| | PhpSpreadsheet por ventanas | 195,0 s | 21,1 s | 134 MB | `2e1f1c4e` |
| 100.000 filas (2M celdas, 1M únicas) | OpenSpout | 710,0 s ⚠ | 16,0 s | 16 MB | `3d47eb3c` |
| | PhpSpreadsheet completo | 146,9 s | 105,0 s | **1052 MB** | `3d47eb3c` |
| | PhpSpreadsheet por ventanas | 978,7 s | 43,9 s | 202 MB | `3d47eb3c` |

Con el `memory_limit` **real de 128M**, archivo de 10.000 filas, iteración pura:
- **OpenSpout:** 174,4 s, 16 MB.
- **PhpSpreadsheet completo:** 22,9 s, 124 MB, justo por debajo del límite.

Lectura de las cifras:
- **Consistencia:** en cada tamaño los tres modos producen **la misma huella** de salida normalizada, es decir, los mismos valores.
- **⚠ OpenSpout y la caché de cadenas compartidas** (`Reader/XLSX/Manager/SharedStringsCaching/CachingStrategyFactory.php:84-101`). Las guarda **en memoria solo si `cadenas_únicas × 12 KB < memory_limit`**; si no, usa **archivos temporales** de 10.000 cadenas, de acceso lento.
  - Con 3072M, el archivo de 10k (100k únicas) cupo en memoria (30 s); los de 50k y 100k no (597 s y 710 s).
  - Con 128M, el umbral es de **≈ 10.900 cadenas únicas**: el mismo archivo de 10k pasó de 30 s a 174 s.
  - Su memoria es constante (16–18 MB), pero el tiempo depende de cuántas cadenas distintas tenga el archivo.
  - Estos archivos sintéticos tienen una proporción muy alta de cadenas únicas (5 de cada 20 celdas). Un archivo real con más repetición estaría más cerca del caso en memoria; **no medido** con hojas reales.
- **PhpSpreadsheet completo:** el más rápido en total, pero su memoria crece con las celdas: ~0,5 KB por celda en estas mediciones (124 MB con 200k celdas, 1052 MB con 2M). Con 128M solo cabe hasta ~200k celdas.
- **PhpSpreadsheet por ventanas:** reduce la memoria (202 MB con 2M celdas), pero el tiempo crece de forma casi cuadrática: cada ventana vuelve a recorrer la hoja completa (978 s con 100k filas).
- **Vista previa:** OpenSpout entrega las primeras filas sin leer la hoja entera. PhpSpreadsheet tiene que cargar toda la hoja (o la ventana) antes.
- **Referencia en el mismo equipo:** `XMLReader` solo, sin interpretar valores, recorre las 200.020 celdas en **1,77 s**. Los dos lectores tardan más de 10 veces eso.
- Una primera serie de mediciones corrió en paralelo con otro proceso pesado y **se descartó**. Los archivos con 60 columnas en XLSX **no se midieron** por duración. Los tiempos son de un i5-4210U y solo sirven para comparar; **no son una estimación de producción**.

### 3.9 V7c: lectura CSV [COMPROBADO]

**Fidelidad.** Seis filas:
- encabezados con tildes;
- `0012345678`, `+573001234567`;
- salto de línea dentro de un campo;
- `;` y comillas dentro de un campo;
- una fila vacía;
- un campo de 5000 caracteres (línea > 4096);
- `=1+1` como texto;
- `\"`, `ñandú – Bogotá`.

Se probó en UTF-8 con BOM y en Windows-1252.

| Lector | UTF-8 BOM | Windows-1252 |
|---|---|---|
| `fgetcsv($h, 0, ';', '"', '')` + filtro `convert.iconv.Windows-1252/UTF-8` + omitir el BOM | **24/24** | **24/24** |
| OpenSpout CSV (`ENCODING`, `FIELD_DELIMITER`) | **24/24** | **24/24** |

Claves:
- la longitud `0` evita el corte a 4096 del importador nativo (`02` §1.1);
- con `escape` vacío, `\"` se conserva literal.

**Rendimiento** (`memory_limit = 128M`, iteración pura):

| Archivo | `fgetcsv` | OpenSpout CSV | Memoria (ambos) |
|---|---|---|---|
| 10.000 × 20 (3,5 MB) | 0,58 s | 1,89 s | 16 MB |
| 50.000 × 20 (17,8 MB) | 2,35 s | 13,02 s | 16 MB |
| 100.000 × 20 (35,8 MB) | 9,48 s | 32,91 s | 16 MB |
| 50.000 × 60 (53,9 MB) | 5,87 s | 22,40 s | 16 MB |

Memoria constante en ambos. Para CSV, `fgetcsv` (nativo, sin dependencia) es entre 3 y 6 veces más rápido con la misma fidelidad en este conjunto.

## 4. Lo que **no** se ejecutó

- `TRUNCATE` real con respaldo y `LOCK TABLES` (estrategia S2): **pendiente de validación**. No bloquea el núcleo; S2 no entra en la primera fase (`09`).
- Caída del **servidor** MariaDB durante un `RENAME` (solo documentación, §3.7).
- Prueba con archivos generados por **Excel real** y con las hojas de referencia de Camilo: los archivos de esta prueba son sintéticos.
- MySQL 8 y la versión de producción: todo se ejecutó en MariaDB 10.4.28.
- Llenado intermedio por `INSERT … SELECT JSON_VALUE` frente a PHP, y `LOAD DATA` (I2), ni el tamaño de lote de T6 (I1).
- Lector propio: **no se construyó**, por instrucción. Primero se evaluaron las soluciones existentes.

## 5. Hallazgos que cambian o precisan el plan

| # | Hallazgo | Evidencia | Cambio en `07` |
|---|---|---|---|
| H-V1 | PHP muerto ≠ procedimiento detenido | S1, S2 | §8.4: nunca clasificar con el bloqueo tomado (ya estaba); se añade el ciclo de vida del bloqueo |
| H-V2 | Un cliente colgado retiene el bloqueo hasta `wait_timeout` (8 h en dev) | S5 | §8.4: `SET SESSION wait_timeout` acotado en la sesión ejecutora [PROPUESTO] |
| H-V3 | `KILL CONNECTION` revierte solo el lote en curso; sus filas quedan sin marca y sin efecto | S6 | §8.4: la cancelación explícita queda validada |
| H-V4 | Detección y restricción con reglas de comparación distintas | V2c | §5.2: `identificador` con `utf8mb4_nopad_bin` [PROPUESTO] |
| H-V5 | `UNIQUE` largo en MariaDB 10.4 se crea como `HASH` sin error | V6 | §5.6: el validador aplica sus límites |
| H-V6 | Error 1615 transitorio durante un `RENAME` | V6 | §5.4: reintento acotado en lectores con sentencias preparadas |
| H-V7 | `sql_mode` afecta al procedimiento sin cambiar su cuerpo | V6b | §8.3: la huella incluye el `sql_mode` |
| H-V8 | Insertar un `NULL` explícito en una `NOT NULL DEFAULT` da 1048; escribir una columna generada da 1906 | consulta en la instancia T | §4.3: celda vacía → `DEFAULT`; generadas excluidas |
| H-V9 | Fidelidad XLSX: diferencias concretas por lector | V7a | §11.2 |
| H-V10 | OpenSpout pasa a caché de cadenas en disco cuando `cadenas_únicas × 12 KB ≥ memory_limit` (≈ 10.900 cadenas con 128M) y se vuelve ~6 veces más lento | V7b | §11.2: la elección del lector depende del perfil real de los archivos y del `memory_limit` de producción |
| H-V11 | PhpSpreadsheet: ~0,5 KB por celda; con 128M cabe hasta ~200k celdas | V7b | Límites `max_filas`/`max_bytes` por tipo |
| H-V12 | CSV: `fgetcsv` nativo cubre la fidelidad y es el más rápido | V7c | §11.2: no hace falta una dependencia para CSV |
