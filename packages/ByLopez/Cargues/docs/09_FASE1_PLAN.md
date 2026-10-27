# 09 — Plan de la primera fase implementable (Fase 1)

**Estado:** **E1 IMPLEMENTADA en desarrollo (2026-10-14)**, autorizada por Camilo con R1 (lo que usa E1), R2 y la política de errores como campo obligatorio; evidencia, decisiones y prueba manual en `11`. **E2 (núcleo) y E3 (Envíos completos v1): implementadas en desarrollo el 2026-10-15** por autorización de Camilo (`13`); no habilitados: modelo `procedimiento`, reanudar y cancelar con `KILL`. · **Revisión:** 3.3 · Base: `07` rev. 3.3, evidencia de `08` y análisis del archivo de referencia en `10`.
Se divide en **entregas autorizables por separado** (§2).

> **Aprobado por Camilo para E1 (2026-10-14):** intermedia persistente aislada por carga con restricciones reales, versionado y recuperación documentados; procesamiento en la cola dedicada `cargues`; PhpSpreadsheet detrás de `LectorArchivo` y `fgetcsv` para CSV, sin dependencias nuevas; ambas políticas de errores, obligatorias al configurar cada tipo y sin selección automática. Condiciones: T6 conserva el original sin normalizar (distingue ausente, vacío y espacios); límites configurables y conservadores, validando el contenido real y limitando la descompresión; **152 filas es solo el máximo observado y el adjunto (2) no se revisó**; la cola no altera otros módulos y se documenta cómo iniciar y verificar el worker; `VALIDAR` no modifica negocio y un `CHECKSUM` no sustituye revisar el contrato; las reglas de 99 Envíos no entran al motor. **Pendiente de él:** aplicar la migración a la BD habitual de desarrollo y la revisión visual manual.

## 0. Objetivo

Entregar **de punta a punta el núcleo del motor parametrizable en modo PROCEDIMIENTO**, con la UI del mockup aprobado:
- configurar y activar un tipo, con la intermedia creada dinámicamente desde el map;
- subir un archivo, relacionar columnas y auditar cada fila en JSON;
- validar y ejecutar **en segundo plano**, con progreso consultable desde la interfaz;
- recuperar tras una caída sin duplicar efectos ni borrar evidencia;
- consolidar resultados y descargar los no cargados y los inciertos.

La fase incluye **la entrega explícita del procedimiento real "99 Envíos — Envíos completos"** y su prueba integrada (E3).

**Por qué el modo procedimiento primero:**
- concentra los riesgos ya validados en `08`;
- el primer tipo real aprobado (A12) es de procedimiento.

**Se conservan sin cambios:**
- el mockup aprobado (`mockups/cargues_nuevo_cargue_relacionar_columnas.png`);
- la intermedia dinámica creada desde `map.tabla`;
- el **modo TABLA en la Fase 2**, que reutiliza lectura, auditoría, output, procesamiento en segundo plano y UI.

El motor no contiene campos de ningún tipo. "Envíos completos" se configura como datos, y solo su procedimiento es código particular.

## 1. Requisitos, separados según lo que bloquean (rev. 3.3)

| # | Requisito | Bloquea | Estado |
|---|---|---|---|
| R1 | Aprobar las recomendaciones técnicas que usa la fase (`07` §14.5): prefijos `bylopez_cg_`/`bylopez_cgi_`; intermedia persistente; creación al activar + verificación al preparar; llaves por carga y `duplicados`; cambio estructural con `RENAME` y reconciliación; bloqueo con nombre con `wait_timeout` de sesión; inmutabilidad de entrada y regla de marcas; `utf8mb4_nopad_bin`; `sql_mode` en la huella; reglas de §4.4 de `07` (vacío, `NULL`, sin relacionar, `DEFAULT`) | **Iniciar el desarrollo** (E1) | **PENDIENTE** |
| R2 | Lector XLSX. Recomendación con la evidencia del archivo real en `10` §5: **PhpSpreadsheet** detrás de la interfaz `LectorArchivo`, con límite por celdas | **Iniciar el desarrollo** (E1) | **PENDIENTE** (recomendación, no decisión) |
| R3 | **Política de errores** por tipo: procesar las válidas o bloquear todo | **Activar cada tipo**. No bloquea el desarrollo: el motor implementa y prueba **las dos** (§2.1, F1-P1/P2), y la configuración la exige sin valor por defecto | **PENDIENTE: no se inventa** |
| R4 | Decisiones del procedimiento de Envíos completos (`10` §7: destino, llave, regla de no retroceso, guía reutilizada, transportadoras) | **Iniciar E3** (procedimiento real) | **PENDIENTE** |
| R5 | Verificación de **producción**: versión exacta de MariaDB/MySQL, topología, privilegios `CREATE`, `ALTER`, `DROP`, `CREATE ROUTINE`/`EXECUTE`, `wait_timeout`, `memory_limit` del worker, **worker de colas en ejecución y supervisado**, `retry_after` de la cola | **Desplegar en producción** (§6). **No bloquea** el desarrollo ni las pruebas aisladas | **PENDIENTE** |

**No bloquean nada de esta fase:** retención, catálogo de tablas (modo tabla), tipos con servicios (Clientes).

## 2. Entregas

| Entrega | Contenido | Toca datos de negocio | Requiere |
|---|---|---|---|
| **E1** | Paquete, acceso, tablas T1–T7, "Configurar tipos", intermedia dinámica, lectura, auditoría T6, validación del motor, `VALIDAR` del procedimiento, **procesamiento en segundo plano con progreso**, output de rechazadas | **No** (`VALIDAR` no modifica negocio; probado con `CHECKSUM`) | R1, R2 |
| **E2** | `EJECUTAR` con bloqueo, CAS y huellas; las dos políticas de errores; recuperación, cancelación y consolidación; output de inciertos; historial | Solo en la BD de pruebas, con el procedimiento **sintético** de `08` | E1 aprobada |
| **E3** | Procedimiento real **Envíos completos**, configuración del tipo **desde la UI** y prueba integrada con el archivo de referencia anonimizado | En la BD de pruebas; en producción solo tras R5 y la activación (R3) | E2 + R4 |

### 2.1 E1 — Configurar, leer, auditar y validar en segundo plano (primera entrega para autorizar)

**Paquete y acceso**
- `packages/ByLopez/Cargues/` (`ByLopez\Cargues`):
  - ServiceProvider + ModuleServiceProvider;
  - registro en `bootstrap/providers.php` y `config/concord.php`;
  - rutas admin, vistas, traducciones en todos los locales.
- **Solo administrador [APROBADO]:** middleware por id de rol administrador en **todas** las rutas (también progreso y descargas). No basta `permission_type = all` (`Bouncer.php:17`).

**Datos**
- Una migración del paquete: T1–T7 (`07` §3.1).
- T5 añade:
  - `ejecucion_token`, `conexion_ejecutor`, `bloqueo_nombre`, `huella_entrada`;
  - **progreso**: `etapa`, `filas_total`, `filas_procesadas`, `progreso_actualizado_en`, `job_uuid`;
  - estados de `07` §7 y §8.4.

**Configuración administrativa frente a parámetros de ejecución**

| | **Configuración administrativa validada** (T1/T2, versionada; se valida al guardar y al activar; inmutable al activarse) | **Parámetros permitidos al ejecutar** (los elige quien carga; se validan en backend y se **congelan** en T5) |
|---|---|---|
| Qué incluye | Nombre, descripción, conexión, modo, procedimiento, acción y acciones permitidas, `map` (campos, tipos, llaves, `duplicados`, regla `vacio` por campo, `transaccion`, `reanudable`), **política de errores**, límites (`max_bytes`, `max_celdas`, `max_filas`), opciones de lectura por defecto | Archivo; hoja; fila de encabezados; delimitador, entrecomillado y codificación (solo CSV/TXT); **relación de columnas**, validada contra los campos del tipo; comparación guardada; confirmación de ejecutar y, si la política es "procesar las válidas", la confirmación de continuar con N rechazadas |
| Qué no puede hacer | — | Fijar o cambiar conexión, tabla, procedimiento, acción fuera de la lista del tipo, política de errores, tipos de campo, llaves o límites. Un parámetro desconocido se rechaza (F1-S3) |
| Quién | Administrador, en "Configurar tipos" | Administrador, en "Nuevo cargue" (mockup) |

**"Configurar tipos"**
- Alta y edición en **borrador**; activación con validación. Solo modo procedimiento; el modo tabla se muestra deshabilitado con "Fase 2".
- Validador del map y de contradicciones (`07` §3.3, §5.6), con:
  - límites de InnoDB calculados;
  - primaria solo sobre campos `nulo:false`;
  - regla `vacio` coherente con la obligatoriedad (`07` §4.4).
- **Política de errores:** campo obligatorio y **sin valor por defecto**. Sin ella el tipo se guarda como borrador pero **no se activa** (R3).
- **Al activar:**
  - verificar que el procedimiento existe y tiene la firma del contrato;
  - congelar su huella, con el `sql_mode`;
  - crear o reutilizar la intermedia;
  - en un cambio estructural, seguir `07` §5.4.

**Lectura** (interfaz `LectorArchivo`: hojas, encabezados, iterador de filas con número real)
- **CSV/TXT:** `fgetcsv`, con delimitador, entrecomillado, `escape` vacío y codificación configurables.
  - La codificación se **detecta** (UTF-8 válido o Windows-1252) y el administrador la confirma.
  - El archivo de referencia re-guardado por Excel llegó en Windows-1252 con `;` (`10` §5.2).
- **XLSX:** lector según R2.
  - **Antes de cargar** el archivo completo se lee `<dimension>` y el tamaño descomprimido de la hoja (con `ZipArchive`, sin construir celdas).
  - Si se supera `max_celdas` o `max_bytes`, se rechaza antes de agotar memoria (F1-L4).
- **Encabezados:**
  - se comparan **exactos**;
  - si dos encabezados solo difieren en mayúsculas (caso real: `Producto` y `producto`, `10` §2), se muestran **los dos** y se relacionan por posición, nunca fusionados.

**Procesamiento en segundo plano y progreso**
- **Jobs** en una cola dedicada `cargues`:
  - `LeerYAuditarCarga` (P5–P6);
  - `PrepararYValidarCarga` (P7–P8);
  - en E2, `EjecutarCarga` y `ConsolidarCarga`.
- **Atributos de los jobs:**
  - `tries = 1`: **ningún reintento automático**, porque la reanudación es explícita (§2.2);
  - `timeout` acotado;
  - `failed()` deja la carga en un estado de fallo con motivo.
- **La conexión de cola de `cargues` debe tener `retry_after` mayor que el `timeout` del job.** El valor por defecto de la cola `database` es **90 s** (`config/queue.php:42`) [C]: un job más largo se reentregaría a otro worker mientras el primero sigue vivo. El bloqueo con nombre (`07` §8.4) es la última barrera, no la primera.
- **Petición web:** solo encola y devuelve el id de la carga; nunca ejecuta la carga.
- **Desarrollo:** `QUEUE_CONNECTION=sync` [C]. Las pruebas de E1 usan la cola `database` con un worker real **en la BD de pruebas**.
- **Progreso:**
  - lo escribe el job en T5 cada N filas o cada 2 s, en una conexión que **no** participa de la transacción del lote;
  - la UI lo consulta con `GET /admin/cargues/cargas/{id}/progreso` (JSON: etapa, procesadas/total, estado, última actualización, mensaje) cada 2–3 s mientras la etapa esté activa;
  - si `progreso_actualizado_en` supera el umbral sin cambios, se muestra "sin noticias del proceso" y se ofrece revisar el estado, sin cambiar nada automáticamente;
  - en el modelo `motor`, el procedimiento es una sola sentencia: no hay progreso por fila, y la UI muestra "ejecutando procedimiento" con el tiempo transcurrido;
  - en el modelo `procedimiento`, el progreso se lee contando las marcas confirmadas (`cg_estado IS NOT NULL`) desde otra sesión;
  - la UI también ofrece cerrar la página y volver: el historial muestra las cargas en curso.

**Auditoría y validación**
- T6 con el JSON original de **todas** las filas, sin convertir `""` en `null` (`07` §4.4).
- Normalización y validación del motor (T7).
- Duplicados según `map.duplicados`.
- Llenado de la intermedia con `cg_ausentes`.
- `CALL … 'VALIDAR'`.
- Resumen y output de rechazadas.

**UI (mockup):** pasos 1–4 y "Validar" del flujo; el botón "Ejecutar" llega en E2.

**Las dos políticas de errores** se implementan como **cálculo y presentación** en E1:
- **bloquear todo:** "Ejecutar" deshabilitado si hay rechazadas;
- **procesar las válidas:** "Ejecutar" habilitado con una confirmación que muestra N rechazadas.

Su efecto en la ejecución se prueba en E2.

### 2.2 E2 — Ejecutar, recuperar y consolidar
- `EjecutarCarga`:
  - confirmación;
  - bloqueo con nombre, CAS y huella de entrada (`07` §8.4);
  - comprobación de la huella del procedimiento;
  - `CALL … 'EJECUTAR'` según `map.transaccion`;
  - la política de errores del tipo decide si se envían las válidas o nada.
- **Recuperación:**
  - el comando `php artisan cargues:recuperar {carga}` y su botón **encolan** la recuperación;
  - no se clasifica nada mientras el bloqueo siga tomado;
  - con el bloqueo libre: consolida, comprueba las huellas y clasifica.
- **Cancelar** = `KILL CONNECTION` de la sesión ejecutora, con confirmación reforzada.
- **Reanudar:** solo si `reanudable: true`.
- **Consolidación:** idempotente y por lotes.
- **Resultado:** output de no cargados y de inciertos; historial.

### 2.3 E3 — Procedimiento real "99 Envíos — Envíos completos"
**Entregables explícitos:**
1. **Procedimiento** (nombre propuesto `sp_cg_envios_completos`) con el contrato de `07` §8.1, sus acciones (`map.acciones`) y su modelo transaccional declarado.
   - Su script versionado queda en el paquete (`Database/Procedures/`), separado del motor.
   - El motor **no lo instala automáticamente**: un administrador lo instala en la conexión del tipo y el motor solo verifica firma y huella al activar.
2. **Configuración del tipo** (map) **hecha desde la UI**, sin código del motor. Se exporta a JSON como evidencia.
3. **Fixture anonimizado** `tests/fixtures/reales/envios_completos_2026-10-03_anonimizado.xlsx`, con:
   - la misma estructura SheetJS que el archivo real (`10` §2);
   - nombres, direcciones y teléfonos sustituidos;
   - guías, estados y fechas conservados o sustituidos de forma coherente;
   - una nota de origen.
4. **Prueba integrada** (Pest, BD de pruebas): carga del fixture por la UI/servicios hasta el resultado, con los criterios F1-R* (§4).

**Reglas del procedimiento:** no se fijan aquí. Dependen de R4 (`10` §7): tabla destino, llave, regla de no retroceso, guía reutilizada y relación de transportadoras. Lo ya aprobado (A12):
- actualiza el estado del envío por guía;
- no toca estados comerciales ni recaudos;
- resuelve inserciones, actualizaciones y reprocesos sin retroceder.

## 3. Fuera de la Fase 1

- Modo TABLA y sus acciones (Fase 2), incluidos `reemplazar` y `vaciar_y_cargar` S1/S2. `TRUNCATE` con `LOCK TABLES` sigue **pendiente de validación**.
- `LOAD DATA`, paralelización.
- Retención y limpieza automática. En Fase 1 solo hay borrado manual de las filas intermedias **de cargas cerradas**.
- Tipos con servicios (Clientes) y post-procesos PHP.
- "99 Envíos — Cartera" y otros tipos reales.
- Integración por API con 99 Envíos (Logística la reserva como `integracion_transportadora`).

## 4. Criterios de aceptación (Pest, datos sintéticos salvo E3, BD de pruebas MariaDB)

Cada criterio se demuestra con una prueba que **se ejecuta**.

Las pruebas con DDL, procedimientos con `COMMIT` o worker de cola usan una BD de pruebas propia que se recrea; no pueden ir dentro de `RefreshDatabase`.

**E1 — Acceso**
- **F1-S1:** sin el rol administrador, **403** en cada ruta del módulo, incluidas progreso y descargas, aunque el rol tenga `permission_type = all`.
- **F1-S2:** el menú y "Configurar tipos" no se renderizan para quien no es administrador.
- **F1-S3:** el request de "Nuevo cargue" con `conexion`, `procedimiento`, `tabla`, `accion` fuera de la lista, `politica_errores`, tipos o límites se **rechaza** (422) y no cambia nada. Solo se aceptan los parámetros de la columna derecha de §2.1.

**E1 — Configuración e intermedia**
- **F1-C1:** un tipo con map de 5 campos, primaria, una única y un índice se activa **sin migración**. Crea `map.tabla` con los índices de `08` §3.1, cada uno con `cg_carga_id` al inicio, y con `cg_ausentes`.
- **F1-C2:** se rechazan con motivo, sin DDL:
  - nombre inválido o reservado;
  - primaria nulable;
  - índice sobre el límite (incluido un `UNIQUE` largo que MariaDB aceptaría como `HASH`);
  - `map.tabla` igual a una tabla existente no registrada;
  - procedimiento inexistente o con otra firma;
  - un campo obligatorio con regla `vacio` distinta de `rechazar`.
- **F1-C2b:** un tipo **sin política de errores** se guarda como borrador y su activación se rechaza con el motivo "elija la política de errores".
- **F1-C3:** reactivar con la misma huella reutiliza la tabla, y T3 no cambia.
- **F1-C4/C5:** cambio estructural y reconciliación, como en `07` §5.4 y `08` §3.7.
- **F1-C6:** si cambia la definición del procedimiento (cuerpo o `sql_mode`), la ejecución se bloquea con motivo.

**E1 — Lectura y auditoría**
- **F1-L1:** fixture de fidelidad XLSX (`08` §3.8): 33/33 y 34/34 con el lector de R2. **Además**, el archivo real (152 filas, SheetJS) se lee con **4271/4271** celdas no vacías iguales al XML crudo (`10` §5.1).
- **F1-L2:** fixture CSV: 100 % de campos exactos. **Además**, el CSV re-guardado por Excel (Windows-1252, `;`) se detecta como Windows-1252 y coincide celda a celda con su XLSX de origen.
- **F1-L3:** T6 tiene una fila por fila del archivo, con el JSON original fiel: ceros a la izquierda, `+57…`, `=…` sin evaluar y `""` conservado como `""`.
- **F1-L4:** un XLSX por encima de `max_celdas` según `<dimension>`, o de `max_bytes`, se rechaza **sin cargar la hoja**, con el pico de memoria medido por debajo del 50 % del `memory_limit` del worker.
- **F1-L5:** encabezados `Producto` y `producto` aparecen como dos columnas distintas y relacionables por separado.
- **F1-L6:** las cuatro situaciones de `07` §4.4 producen lo esperado, en una prueba por regla `vacio` y por acción:
  - sin relacionar → ausente y en `cg_ausentes`;
  - vacía con `rechazar` → `rechazada`;
  - vacía con `nulo` → `NULL`;
  - vacía con `default` → `DEFAULT` sin error 1048;
  - texto `NULL` → texto.

**E1 — Duplicados y validación**
- **F1-D1:** el conjunto de 12 filas de `08` §3.2 se resuelve como allí.
- **F1-D2:** `G100`/`g100` reciben un trato coherente entre detección y restricción.
- **F1-V1:** `VALIDAR` no cambia ninguna tabla de negocio (`CHECKSUM` antes = después).

**E1 — Segundo plano y progreso**
- **F1-B1:** "Validar archivo" responde en < 1 s con el id de la carga y encola el job. La petición no lee el archivo completo, lo que se comprueba con un archivo de 10k filas.
- **F1-B2:** durante el job, `GET …/progreso` devuelve `filas_procesadas` monótona creciente hasta `filas_total`, y la etapa final correcta.
- **F1-B3:** si el worker se mata a mitad de `LeerYAuditarCarga`, la carga no queda "en curso" para siempre: tras el umbral se muestra "sin noticias". Al relanzarla, T6 queda sin filas duplicadas, por `UNIQUE (carga_id, fila_origen)`.
- **F1-B4:** con `retry_after` mal configurado (menor que la duración del job), una segunda entrega del mismo job **no** procesa en paralelo: el bloqueo con nombre o el CAS la detienen. Se prueba forzando la reentrega.
- **F1-B5:** dos cargas de tipos distintos avanzan en paralelo con dos workers, sin mezclar progreso.

**E1 — Política de errores (cálculo)**
- **F1-P1:** con "bloquear todo" y ≥ 1 rechazada, "Ejecutar" no está disponible, y el endpoint de ejecutar responde 409 aunque se llame directamente.
- **F1-P2:** con "procesar las válidas", se exige la confirmación con el número exacto de rechazadas, y sin ella el endpoint responde 422.

**E2 — Ejecución, recuperación y consolidación** (procedimiento sintético de `08` §3.4)
- **F1-E1:** el resultado es el calculado por separado, y se cumple el invariante efecto⇔marca.
- **F1-E1b:** con la misma carga sintética con 19 rechazadas:
  - "bloquear todo" deja 0 efectos y 0 marcas de ejecución;
  - "procesar las válidas" aplica exactamente las válidas.
- **F1-E2:** doble clic, dos pestañas o un job reentregado producen una sola ejecución.
- **F1-E3:** caídas S1–S6 de `08` §3.5, ahora con el **worker** como proceso que muere.
- **F1-E4:** tras reanudar, el resultado es igual al de una ejecución sin caídas, y las marcas previas quedan idénticas.
- **F1-E5:** una consolidación repetida es idempotente.
- **F1-E6:** con `reanudable: false`, no se ofrece reanudar.

**E3 — Envíos completos** (fixture anonimizado, BD de pruebas con destinos sintéticos según R4)
- **F1-R1:** el tipo se configura y activa **desde la UI**, sin código del motor; el map exportado se guarda como evidencia.
- **F1-R2:** el fixture de 152 filas se lee, se audita (152 filas en T6) y se valida. Cada fila queda en un estado final, y los contadores suman 152.
- **F1-R3:** ejecutar dos veces el mismo archivo deja los destinos con la misma huella que una sola ejecución (AC-C3d). La segunda vez, las filas quedan `sin_cambio` u `omitida_antigua`, nunca `aplicada`.
- **F1-R4 (no retroceso):** se carga primero el fixture del 2026-10-03 y luego uno anterior derivado del 2026-08-29. Ningún envío vuelve a un estado anterior, y las filas antiguas quedan `omitida_antigua`.
- **F1-R5:** las tablas de estados comerciales, recaudos y cartera tienen el mismo `CHECKSUM` antes y después (A12).
- **F1-R6:** una guía del fixture modificada para que llegue con otra `fecha_envio` se trata según la regla aprobada en R4, nunca como una actualización silenciosa.

**UI y calidad**
- **F1-U1:** comparación con el mockup (`04` §1.1); las diferencias se documentan.
- **F1-U2:** claro y oscuro; móvil sin scroll horizontal; estados no solo por color; barra de progreso con texto.
- **F1-Q1:** `pint --test`; `bagisto:translations:check`; `packages/Webkul/` sin cambios.

## 5. Límites iniciales propuestos (con margen para Laravel)

Detalle y mediciones en `10` §5. [PROPUESTO], configurables por tipo en `config/cargues.php`.

| Parámetro | Valor propuesto | Razón |
|---|---|---|
| `memory_limit` del worker `cargues` | 512M | Independiente del `memory_limit` de la web. Laravel + Bagisto arrancan con **42 MB** medidos (`10` §5.4) |
| `max_celdas` XLSX (PhpSpreadsheet) | **300 000** (≈ 10 700 filas × 28 columnas) | Con la peor cifra medida en el formato real (~0,63 KB por celda): ~185 MB + 42 MB de base ≈ 230 MB, por debajo del 50 % de 512M |
| `max_bytes` XLSX | 20 MB | El archivo real (SheetJS) guarda la hoja **sin comprimir**, a ~43 bytes por celda: 300 000 celdas ≈ 13 MB |
| Tamaño descomprimido de la hoja | 40 MB | Contra archivos muy comprimidos ("zip bomb"); se lee del índice del ZIP sin descomprimir. 300 000 celdas sintéticas ocupan ~13 MB descomprimidas |
| `max_filas` CSV | 200 000 | `fgetcsv` en streaming, memoria constante |
| `timeout` de los jobs | 900 s para leer y validar | Lectura medida + auditoría con margen; se revisa con I1/I2 |
| `retry_after` de la conexión de cola `cargues` | `timeout` + 60 s como mínimo | Evitar la reentrega mientras el job sigue vivo |

**152 filas es solo el máximo observado** en las exportaciones del equipo (`10` §1); **no es un límite de negocio** y los límites de arriba son de memoria y de tiempo, configurables. Si un tipo necesita más, se cambia el lector (opción OpenSpout de `08` §3.8) sin tocar el resto.

## 6. Despliegue en producción (requisito R5, no de desarrollo)

Antes de activar un tipo en producción:
- **Base de datos:** versión exacta, topología, privilegios y `wait_timeout` (`07` §8.4, §5.4).
- **Worker de la cola `cargues`:** en ejecución y supervisado, con `--queue=cargues`, `--memory`, `--timeout` y `retry_after` coherentes.
- **Procedimiento del tipo:** instalado y verificado con su huella.
- **Política de errores:** elegida (R3).

Sin estos puntos, el código puede desplegarse con el módulo **sin tipos activos**.

## 7. Riesgos de la fase

| Riesgo | Mitigación |
|---|---|
| Producción sin DDL atómico (< 10.6.1) | Reconciliación con intervención manual (F1-C5); R5 |
| Job reentregado por `retry_after` corto | `retry_after` > `timeout`, `tries = 1`, bloqueo con nombre (F1-B4) |
| Sin worker en producción: la carga queda encolada sin avanzar | R5; la UI muestra "en cola" con su antigüedad |
| Lectura XLSX pesada | `max_celdas` comprobado antes de cargar; interfaz para cambiar de lector |
| Procedimiento que no cumple el contrato | Estado `incierta`; revisión del procedimiento antes de activarlo |
| Reglas de Envíos completos sin decidir | E3 no inicia sin R4; E1 y E2 no dependen de ellas |
| Solapamiento con Logística (LOG-D-03 excluye el tracking intermedio; integración 99 Envíos reservada) | Decisión de destino en R4 (`10` §7), coordinada con `Logistica/docs/MAPA_DOMINIO_LOGISTICA.md` |
