# 01 — Auditoría backend / frontend

**Fecha:** 2026-10-13 · Solo lectura. No se ejecutó migración, instalación ni escritura en BD. No se expusieron secretos (`.env` leído solo en claves no sensibles).
Convención: **[C]** comprobado en repo/entorno, **[S]** supuesto/deducido.

## 1. Entorno comprobado

| Dato | Valor | Evidencia |
|---|---|---|
| PHP | 8.3.33 (CLI, ZTS, Windows/XAMPP) | `php -v`; `composer.json` exige `>=8.3 <8.5` [C] |
| Laravel | 12.62.0 | `php artisan --version` [C] |
| Excel | `maatwebsite/excel` 3.1.69; `phpoffice/phpspreadsheet` 1.30.6 | `composer.lock` [C] |
| Otros lectores (OpenSpout, league/csv) | **No instalados** | `vendor/` [C] |
| Motor BD dev | **MariaDB 10.4.28** | `select version()` [C] |
| Variables BD dev | `max_allowed_packet`=1 MB, `innodb_buffer_pool_size`=16 MB, `local_infile`=ON, `secure_file_priv`=NULL | consulta de solo lectura [C] |
| Producción | Motor/versión/variables **desconocidos** | [ABIERTO] |
| PHP límites (CLI) | `memory_limit`=128M, `upload_max_filesize`=2M, `post_max_size`=8M | `php -r ini_get` [C]. Los del SAPI web pueden diferir: **no verificado** |
| Cola | `QUEUE_CONNECTION=sync` en dev; tablas `jobs`, `failed_jobs`, `job_batches` existen; `retry_after`=90 (driver database) | `.env`, `config/queue.php`, `migrate:status` [C] |
| Archivos | Disco por defecto `FILESYSTEM_DISK=public` (**peligroso para datos privados**); disco `private` en `storage/app/private` | `.env`, `config/filesystems.php:40` [C] |
| Sesión/caché | `file` | `.env` [C] (los locks atómicos de `ShouldBeUnique` funcionan con `file`, pero no entre servidores) |

Sobre los límites: 2 MB de subida es insuficiente para archivos reales de envíos; hay que fijarlo con datos de volumen reales (ver `06`). Las cifras son del entorno de desarrollo y **no se extrapolan a producción**.

## 2. Convenciones de los módulos propios [C]

- Paquetes en `packages/ByLopez/{CorteConfeccion,Logistica,VentasAsesor}`; autoload PSR-4 en `composer.json:112-114`; registro del provider en `bootstrap/providers.php:4-6`. **No** están en `config/concord.php` (los ByLopez usan modelos Eloquent propios; no hay `Contracts`+`Proxy` de Concord). Un paquete nuevo requiere ambos registros (y `composer dump-autoload` al implementar).
- Provider típico (`Logistica/src/Providers/LogisticaServiceProvider.php`): `mergeConfigFrom` de `acl.php`/`menu.php` hacia `acl` y `menu.admin`, `loadMigrationsFrom`, `loadViewsFrom('logistica')`, rutas con `Route::middleware(['web', PreventRequestsDuringMaintenance::class])->group(.../Http/routes.php)`.
- Tablas con prefijo `bylopez_<abrev>_` (`bylopez_lg_*`, `bylopez_va_*`, `bylopez_cc_*`, `bylopez_geo_*`). Los nombres `tbl_carga_*` del encargo **rompen** esa convención → decisión en `06`.
- ACL: claves **planas** (la clave padre no concede hijas); si varias entradas listan la misma ruta, autoriza la última; cada clave apunta a una ruta GET sin parámetros (`Logistica/src/Config/acl.php`, comentario de cabecera) [C].
- Servicios: carpeta `Services/<Dominio>/…Service.php`, `Contracts/`, `Support/`, `DataObjects/` (Logistica) [C]. Los servicios revalidan siempre; ocultar botones no autoriza.
- Archivos privados: `Storage::disk('private')` con ruta validada contra `..` y `nosniff` (`VentasAsesor/Http/Controllers/Admin/PagosController.php:28-30`); Logistica guarda PDFs con `Str::uuid()` (`GuiaArchivoService.php:141`) [C].
- Importador propio existente: `VentasAsesor/Services/Geo/ImportadorDivipolaService.php` — dry-run por defecto, **falla cerrado**, checksum sha256, una transacción, upsert idempotente, no borra. Es el **patrón de calidad** a imitar (validar sin escribir; informe; luego persistir) [C].
- No se encontró en ByLopez un servicio de auditoría genérico ni uso de colas: `ShouldQueue` no aparece en `packages/ByLopez/*/src` [C]. `bylopez_cc_orden_corte_cantidad_audits` y `bylopez_cc_cuenta_eventos` son auditorías locales de cada dominio [C].
- Estilos: CorteConfeccion usa Tailwind propio bajo `.bylopez-scope` con build (`GUIA_VISUAL_ADMIN_BAGISTO.md` regla 3); Logistica usa `<style>` Blade con prefijo (`lg-`) sin build (`Resources/views/admin/estilos.blade.php`) [C].
- Tests: Pest, un `Feature/*Test.php` por bloque + `helpers*.php` (Logistica) [C]. Hallazgo previo en memoria del proyecto: la BD de pruebas es MariaDB en Windows y hay pruebas con commit prematuro (`project_bylopez_03f_hallazgos`) — relevante si el procedimiento hace COMMIT.

## 3. Hallazgos priorizados

Prioridad: **A** bloquea diseño/seguridad, **M** afecta calidad, **B** mejora.

| # | Hallazgo | Evidencia | Prio |
|---|---|---|---|
| H1 | El importador nativo no tiene mapeo de columnas, ni hoja/encabezado configurables; tipos fijos en `config/importers.php` (products, customers, tax_rates) | `DataTransfer/src/Config/importers.php`; `AbstractImporter::validateColumns()` (líneas 252-270) exige que cada encabezado cumpla `^[a-z][a-z0-9_]*$` y esté en `validColumnNames`, y que existan los `permanentAttributes` → los encabezados deben llamarse **exactamente** como el campo; `ImportController::store` valida `type in config('importers')` [C] | A |
| H2 | XLSX nativo: `IOFactory::load()` sin `setReadDataOnly` carga el libro completo; usa solo `getActiveSheet()`; lee con `getCellByColumnAndRow`; si `array_filter` deja la fila vacía, `getNextRow()` devuelve `false` y `AbstractSource::next()` pone `currentRowNumber = -1` → **la iteración termina** en la primera fila vacía intermedia. `array_filter` sin callback también descarta `"0"`/`0`: una fila cuyo único valor sea 0 también corta | `Sources/XLSX.php::initialize/getNextRow`; `Sources/AbstractSource.php::next()` [C]. Que `getCellByColumnAndRow` esté marcado deprecado en 1.30.6 **no se comprobó** (el método existe en `Worksheet.php:1377`) [S] | A |
| H3 | CSV nativo: `fgetcsv($r, 4096, $delimiter)` sin entrecomillado/escape/codificación/BOM configurables. **Una línea > 4096 caracteres se parte en dos filas**: la primera queda con menos columnas (error "número de columnas") y la segunda puede tener el número correcto de columnas con datos truncados y **pasar como fila válida**. Una línea en blanco devuelve `[null]` → error de número de columnas (en CSV no corta; en XLSX sí, H2) | `Sources/CSV.php` [C]; reproducido en aislado con PHP 8.3.33 (línea de 6.000 bytes → `[4096]` y luego `[1904, 'fin']`) [C]; php.net `fgetcsv` documenta el corte en trozos de `length` y la fila en blanco como `[null]` [C] | M |
| H4 | Reporte de errores nativo: `generateErrorReport` escribe los valores leídos con `$sheet->fromArray(...)`. En PhpSpreadsheet 1.30.6, `DefaultValueBinder::dataTypeForValue` clasifica cualquier cadena de más de 1 carácter que empiece por `=` como `TYPE_FORMULA` (`Cell/DefaultValueBinder.php:61-62`), y `fromArray` usa `setValue` (`Worksheet.php:~3084`). Reproducción aislada con la **misma llamada y versión**: (a) **XLSX**: el archivo resultante contiene `<f>HYPERLINK("…","clic")</f>`, es decir, una fórmula real que Excel evaluará; (b) **CSV**: el writer precalcula (`Writer/Csv.php:127`, `preCalculateFormulas`), así que `=1+1` se exporta como `2` y `=HYPERLINK(...)` como `clic`. **Se pierde el valor original** y la librería evalúa una fórmula que viene del archivo; `@SUM(1)` queda como texto y sigue siendo peligroso al abrir el CSV en Excel; (c) `+57300` se convierte en número `57300` (**pierde el `+`**) | Código del core y de vendor [C]; reproducción en el scratchpad [C]. **Hipótesis aún no confirmada:** no se ejecutó el `generateErrorReport` de Bagisto de punta a punta ni se abrió el archivo en Excel; que Excel ejecute la fórmula al abrir es comportamiento conocido (OWASP), no probado aquí | A |
| H4b | En la fuente XLSX nativa, una celda con fórmula del archivo subido se lee con `getValue()`, que devuelve el texto de la fórmula (`=…`) y no el valor | `Sources/XLSX.php::getNextRow` [C]; que el valor llegue con `=` y se reexporte como fórmula es **[S]**, a confirmar con un XLSX con fórmulas | M |
| H5 | Disco por defecto `public`: un `Storage::put` sin disco explícito expondría datos personales | `.env` `FILESYSTEM_DISK=public` [C] | A |
| H6 | Dev en MariaDB 10.4: sin `JSON_TABLE`; `max_allowed_packet` 1 MB limita inserciones por lotes | consulta BD [C] | A |
| H7 | Cola `sync` en dev; el importador nativo rechaza `process_in_queue` con cola sync (`ImportController::start`) y exige worker | `ImportController.php:~429-440` [C]. Worker en producción [ABIERTO] | A |
| H8 | `retry_after`=90 s: un job más largo se entrega a un segundo worker y duplica trabajo. El core lo resuelve con `BoundedByRetryAfter` | `DataTransfer/src/Jobs/Concerns/BoundedByRetryAfter.php`; `config/queue.php` [C] | A |
| H9 | Sin permisos separados para configurar/ejecutar/consultar en el nativo (claves `settings.data_transfer.imports{,.create,.edit}`) | `Admin/src/Config/acl.php:1259-1280` [C] | M |
| H10 | Conexión/DDL: no hay usuario de BD de mínimo privilegio; dev usa `root` | `.env` `DB_USERNAME=root` [C]; producción [ABIERTO] | A |
| H11 | Hash de archivo no basta como idempotencia (mismo contenido puede reprocesarse legítimamente y archivos distintos pueden repetir guías) | análisis de diseño | A |
| H12 | Datos personales (teléfonos, direcciones) quedarán en JSON por fila → política de retención y acceso | requerimiento | M |

## 4. Lectura de archivos: opciones

| Opción | Memoria | Pros | Contras | Veredicto |
|---|---|---|---|---|
| `IOFactory::load` completo (nativo) | Alta (celdas objeto ≈ cientos de bytes c/u) | simple | puede agotar 128M con decenas de miles de filas | descartado como base |
| PhpSpreadsheet + `IReadFilter` por ventanas + `setReadDataOnly(true)` + `listWorksheetInfo` | Las **celdas** quedan acotadas por la ventana, pero el **XML de la hoja y las cadenas compartidas se cargan completos en cada ventana** (ver §4.1) | ya instalado; hojas por nombre; `listWorksheetInfo` usa `XMLReader` (streaming) | memoria base proporcional al tamaño de la hoja; tiempo ≈ ventanas × parseo completo | **Candidato [PROPUESTO], no validado** con archivos grandes |
| Laravel Excel `WithChunkReading` | por chunk | integra colas | internamente usa PhpSpreadsheet con filtro; su doc advierte que sin chunk carga la hoja completa; abstracción extra sin control fino de tipos de celda | reserva; no aporta sobre el uso directo |
| OpenSpout (streaming real) | baja según su documentación (no medido aquí) | librería mantenida, pensada para archivos grandes | **no instalado**; añadir dependencia requiere aprobación; compatibilidad de versión con PHP 8.3 por verificar | **Candidato** a comparar (§4.2) |
| Lector propio `ZipArchive` + `XMLReader` | baja en teoría (streaming) | sin dependencia nueva | **costo de mantenimiento propio**: fechas por estilos/numFmt, época 1904, cadenas compartidas e inline, texto enriquecido, valores en caché de fórmulas, celdas con errores; riesgo de fallos de fidelidad | **Candidato** a comparar; **no se elige solo por evitar una dependencia** |
| CSV con `SplFileObject`/`fgetcsv` (longitud 0) | constante | simple, rápido | hay que fijar delimitador, `enclosure`, `escape` y codificación | **[PROPUESTO]** para CSV/TXT |
| `LOAD DATA LOCAL INFILE` | mínima | muy rápido | solo texto (no XLSX); requiere `local_infile` en servidor **y** cliente (`PDO::MYSQL_ATTR_LOCAL_INFILE`), con implicaciones de seguridad documentadas; sin número de fila de origen ni validación por celda; interpreta la codificación con `character_set_database`; con `LOCAL` equivale a `IGNORE` en errores/duplicados; hostings compartidos suelen bloquearlo | **No para el MVP.** Reevaluar solo con medición de rendimiento y aprobación de operaciones |

### 4.1 Limitaciones de PhpSpreadsheet por ventanas (comprobadas en el código 1.30.6)

- `Reader/Xlsx.php:812-813`: al cargar una hoja se hacen `loadZipNoNamespace(...)` **y** `loadZip(...)` del XML completo (`simplexml_load_string`). El filtro (`:861`, `getReadFilter()->readCell`) se aplica **después**, celda por celda. Una ventana de 1.000 filas igual descomprime y parsea la hoja entera, dos veces.
- `Reader/Xlsx.php:742`: la tabla de cadenas compartidas se carga entera en cada ventana.
- Con N ventanas, el tiempo crece aproximadamente con N × tamaño de la hoja (≈ cuadrático en filas). No hay lectura en streaming de celdas.
- Por celda cargada, la doc oficial estima ≈ 1 KB (1,6 KB en PHP de 64 bits) ("Memory saving"). La caché de celdas PSR-16 reduce memoria a costa de tiempo.
- `listWorksheetInfo` (`:281`) sí usa `XMLReader` y sirve para obtener hojas, filas y columnas sin cargar celdas.
- **Conclusión:** sirve para archivos moderados; **no está demostrado** para archivos grandes. No se fijan límites de filas ni tamaño hasta medirlos.

### 4.2 Prueba de memoria y tiempo

> **Ejecutada el 2026-10-13** con datos sintéticos en un entorno aislado. Resultados en **`08` §3.8–3.9** (fidelidad, memoria y tiempo de PhpSpreadsheet, OpenSpout y `fgetcsv`). Lo que sigue es el diseño original de la prueba; la elección del lector sigue **pendiente**.

| Aspecto | Plan |
|---|---|
| Datos | XLSX y CSV **sintéticos** con la forma de los archivos de referencia de los primeros tipos (mismas columnas y tipos), de 1k, 10k, 50k y 100k filas; variante con muchas cadenas únicas (la tabla de cadenas compartidas crece) |
| Variantes | Tres lectores en igualdad de condiciones: (a) PhpSpreadsheet con ventanas de 500/1.000/2.000/5.000 filas, `setReadDataOnly(true)`, con y sin caché de celdas; (b) OpenSpout, en un entorno aislado fuera del proyecto, sin añadirlo al `composer.json`; (c) prototipo de lector propio `ZipArchive` + `XMLReader`, también aislado |
| Métricas | **Rendimiento:** `memory_get_peak_usage(true)`, tiempo total, filas/s, tiempo hasta la vista previa. **Fidelidad:** comparación celda a celda contra un resultado esperado (ceros a la izquierda, teléfonos largos, fechas con formato y sin él, época 1904, decimales, fórmulas con valor en caché, cadenas compartidas, inline y enriquecidas, celdas vacías intermedias). **Mantenimiento:** líneas de código propio, casos no soportados, dependencia y su ritmo de versiones |
| Entornos | CLI del worker y SAPI web con `memory_limit` real de producción (desconocido hoy) |
| Criterio | Se elige el lector con **fidelidad del 100 %** en el conjunto de casos y, entre los que la cumplan, el de mejor relación rendimiento/mantenimiento. Un lector propio solo se justifica si los otros fallan en fidelidad o rendimiento medidos, no por evitar una dependencia. Después se fijan `max_filas`/`max_bytes` por tipo con margen (p. ej. pico ≤ 50 % del `memory_limit`) |
| Entregable | Tabla de resultados en este documento; hasta entonces, sin cifras de capacidad |

Reglas de lectura **[PROPUESTO]**:
- **Delimitador** (separa campos) ≠ **entrecomillado** (encierra un campo que contiene el delimitador/saltos). Son dos ajustes distintos en CSV/TXT; XLSX no muestra ninguno de los dos (coincide con el mockup).
- **Codificación:** detectar BOM (UTF-8, UTF-16 LE/BE); si no hay BOM, validar UTF-8 con `mb_check_encoding` y, si falla, **proponer** Windows-1252 y pedir confirmación visible. Nunca adivinar en silencio.
- **Hojas:** listar con `listWorksheetInfo`; el operador elige; recordar nombre, no índice, en el snapshot.
- **Encabezados:** fila elegida por el operador; duplicados se desambiguan por índice (`Nombre`, `Nombre (2)`) y se marcan **ambiguos**; encabezados vacíos se nombran `Columna C`.
- **Filas vacías:** se cuentan, se omiten y **conservan su número de fila de origen**; no cortan la lectura.
- **Texto vs número:** guías y teléfonos se leen como **texto**. En XLSX una celda numérica llega ya sin ceros a la izquierda y, si tiene más de 15 dígitos, ya perdió precisión en Excel. Regla: tomar el valor tal como está almacenado, no completar ceros ni precisión, y emitir **advertencia** `IDENTIFICADOR_NUMERICO` cuando un campo declarado "identificador" llega como número. Convertir sin notación científica solo si el valor entero es exacto.
- **Fechas:** serial de Excel solo se convierte si la celda tiene formato de fecha **y** el campo destino es fecha; se conserva el valor crudo; se respeta la época 1900/1904 del libro. Fechas en texto: formatos explícitos por tipo (p. ej. `d/m/Y`), nunca `strtotime` libre.
- **Decimales:** separador decimal/miles declarado por campo del tipo (`es-CO` suele ser `1.234,56`); ambiguo → error, no suposición.
- **Fórmulas:** no se calculan ni ejecutan; se lee el valor en caché (`getOldCalculatedValue`) y se marca advertencia `FORMULA_EN_CELDA`. Sin celdas calculadas en cache → vacío + advertencia. **Rechazar** `.xlsm/.xlsb/.xls` (macros/formatos binarios) en el MVP; PhpSpreadsheet no ejecuta macros pero no hay razón para admitirlas.
- **Archivo corrupto / zip bomb:** abrir con `ZipArchive` y verificar tamaño descomprimido total y número de entradas **antes** de pasar a PhpSpreadsheet; capturar excepciones y mapear a "Archivo inválido".

## 5. Estructura intermedia: comparación

| Criterio | Temporal MySQL | Persistente segregada por `carga_id` | DDL dinámico por carga |
|---|---|---|---|
| Conexión | Solo vive en **la misma conexión**; el job de lectura, el de validación y el `CALL` serían conexiones distintas; PHP-FPM/colas no la comparten | Cualquier conexión | Cualquier conexión |
| Duración | Hasta cerrar la conexión: no sobrevive a una caída ni a reintentos | Controlada por retención | Controlada, pero con huérfanas |
| Concurrencia | Aislada por sesión (ventaja) | Aislada por `carga_id` + índice; exige **nunca** consultar sin `WHERE carga_id` | Aislada por nombre |
| Transacciones | `CREATE/DROP TEMPORARY TABLE` no confirman implícitamente (doc MySQL), pero tampoco son reversibles | DML transaccional normal | `CREATE/ALTER/DROP/TRUNCATE` hacen **commit implícito** |
| Permisos | `CREATE TEMPORARY TABLES` | solo DML en tablas fijas | `CREATE/DROP` en runtime = privilegio fuerte |
| Auditoría | Se pierde al cerrar | Queda trazable para depurar | Se pierde si se limpia |
| Limpieza | Automática | `DELETE … WHERE carga_id=? LIMIT n` por lotes + job de retención | `DROP` + control de huérfanas |

> **ANTECEDENTE: la recomendación original de esta sección fue reemplazada.** Aquí se recomendaba una tabla de staging tipada y **fija por tipo**, provisionada por migración o rutina revisada. **No implementar.** El requisito aprobado es una intermedia **definida en `map.tabla` y creada dinámicamente por el motor**. Mecanismo vigente en `07` §5.

Lo que sigue vigente de esta comparación:
- Las tablas `TEMPORARY` no sirven para validar y ejecutar en peticiones distintas.
- La auditoría por fila (`bylopez_cg_carga_datos`, JSON original) no depende de DDL.
- `JSON_TABLE` no existe en MariaDB 10.4.
- La DDL hace commit implícito y nunca debe ejecutarse dentro de una transacción de negocio.

## 6. Seguridad y capacidad [PROPUESTO]

- Acceso **[APROBADO]: solo administrador**, en frontend y backend (`07` §10). Se recomienda identificar el rol administrador por id en la configuración, porque Bagisto concede todo a cualquier rol con `permission_type = all` (`packages/Webkul/User/src/Bouncer.php:17`) y en dev hay varios. Sustituye la propuesta anterior de cinco claves de permiso.
- Destinos e identificadores SQL: **sin clases programadas por destino**. Se validan contra la configuración y la estructura real:
  - la conexión debe estar autorizada en `config/database.php` y en la lista del módulo;
  - la tabla destino, contra el catálogo de tablas autorizadas y `information_schema`;
  - el procedimiento, contra `information_schema.ROUTINES/PARAMETERS`;
  - la intermedia, con el validador del map.

  Nunca se interpola texto libre del JSON en SQL (`07` §3.3 y §5.6).
- Conexiones y transacciones: las tablas del módulo viven en la conexión principal; la intermedia, los destinos y el procedimiento, en la conexión del tipo. Una transacción local **no** es garantía entre conexiones (`07` §3.5).
- Archivos: disco `private` **explícito**, nombre `uuid` (el nombre original solo como metadato), validar extensión + MIME + firma (magic bytes `PK` para xlsx), tope de tamaño/filas/columnas por tipo, retención configurable y job de limpieza; borrar contenido de filas (datos personales) tras la retención y conservar cabecera, contadores y resumen de errores.
- Errores: mensaje comprensible + código estable (`COL_FALTANTE`, `VALOR_INVALIDO`…) para el operador; excepción técnica y SQL en el log con `carga_id` y en el detalle de la carga (solo administrador).
- Exportes de errores (ver H4): **nunca** `fromArray`/`setValue` con datos del archivo. Escribir con `setCellValueExplicit(..., DataType::TYPE_STRING)` en XLSX. En CSV, desactivar `preCalculateFormulas` o escribir sin PhpSpreadsheet, y neutralizar las celdas que empiecen por `=`, `+`, `-`, `@`, tabulador, CR o LF (OWASP recomienda anteponer un tabulador dentro del campo entrecomillado; anteponer `'` puede perderse si Excel guarda y reabre el archivo). Esto no debe alterar el valor mostrado de guías y teléfonos: la neutralización solo aplica al exporte, no a lo almacenado. Además `Content-Disposition`, `X-Content-Type-Options: nosniff`, ruta validada y descarga auditada.
- Capacidad: ver datos de §1. Tamaño de lote en inserciones: por **bytes** (≤ ~512 KB con `max_allowed_packet` 1 MB), no solo por filas.

## 7. Frontend: componentes reales reutilizables [C]

- Layout y bloques Admin: `x-admin::layouts`, `x-admin::tabs`, `x-admin::modal`, `x-admin::drawer`, `x-admin::accordion`, `x-admin::form` (+ `form.control-group`), `x-admin::button`, `x-admin::datagrid`, `x-admin::table`, `x-admin::flash-group`, `x-admin::shimmer`, `x-admin::dropdown` (`packages/Webkul/Admin/src/Resources/views/components/`).
- Stepper con estados done/active/pending y spinner: `packages/Webkul/Admin/src/Resources/views/settings/data-transfer/imports/import.blade.php` (líneas ~85-160) como referencia de estructura y accesibilidad a revisar.
- Buscador con autocompletado propio: `CorteConfeccion/src/Resources/views/components/record-search.blade.php` (contexto en `docs/autocomplete-select-context.md`).
- Tarjetas/tablas/píldoras/botones con prefijo propio sin build: `Logistica/src/Resources/views/admin/estilos.blade.php` (`lg-panel`, `lg-table`, `lg-pill--*`, `lg-btn`, `lg-input`, `lg-caption`). Para Cargues: clonar el patrón con prefijo `cg-`, **sin** introducir otro sistema visual.
- Iconos: clases `icon-*` de `packages/Webkul/Admin/src/Resources/assets/css/app.css`. Los iconos del mockup (recibido) están listados en `04` §2.

## 8. Fuentes oficiales consultadas (2026-10-13)

| Fuente | Qué respalda |
|---|---|
| https://phpspreadsheet.readthedocs.io/en/stable/topics/reading-and-writing-to-file/ | `setReadFilter`/`IReadFilter` (filtrar no renumera celdas), `setReadDataOnly(true)`, usar `rangeToArray` con rangos filtrados |
| https://dev.mysql.com/doc/refman/8.0/en/load-data.html | `LOAD DATA` lee solo texto; `LOCAL` exige `local_infile` y consideraciones de seguridad; codificación por `character_set_database`; `LOCAL` actúa como `IGNORE` |
| https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html | `CREATE/DROP TEMPORARY TABLE` no confirman pero no son reversibles; `CREATE/ALTER/TRUNCATE TABLE` confirman implícitamente |
| https://laravel.com/docs/12.x/queues | `ShouldBeUnique`, `WithoutOverlapping`, `Bus::batch`, `timeout` < `retry_after`, `afterCommit`, límites del driver database |
| https://docs.laravel-excel.com/3.1/imports/chunk-reading.html | `WithChunkReading`+`WithBatchInserts`; sin chunk se carga la hoja completa en memoria |
| https://phpspreadsheet.readthedocs.io/en/latest/topics/memory_saving/ | ≈ 1 KB por celda (1,6 KB en 64 bits); caché de celdas PSR-16 a cambio de velocidad |
| https://www.php.net/manual/en/function.fgetcsv.php | `length` corta líneas largas en trozos; `0`/`null` sin límite; línea en blanco = `[null]`; depender del `escape` por defecto está deprecado desde PHP 8.4 → pasarlo explícito (`""`) |
| https://community.owasp.org/attacks/CSV_Injection | Caracteres iniciales peligrosos (`= + - @`, tab, CR, LF, variantes de ancho completo) y mitigación con tabulador dentro del campo entrecomillado |
| Bagisto devdocs (https://devdocs.bagisto.com/) | **No consultada**: se usó el código instalado (Bagisto 2.4.x) como fuente de verdad. |

Reproducciones aisladas (2026-10-13): scripts PHP en el scratchpad de la sesión, fuera del repo, sin BD. Usaron el `vendor/` del proyecto en modo lectura (fgetcsv 4096, línea en blanco, `fromArray` + writers CSV/XLSX). Ningún archivo del proyecto cambió.

Diferencias doc ↔ instalado: la doc de MySQL es 8.0 y dev es MariaDB 10.4.28 (diferencias de `JSON_TABLE`, `LOAD DATA`, procedimientos); la doc de Laravel Excel no detalla si reabre el archivo por chunk — **verificar con una prueba** antes de elegirlo. La doc de PhpSpreadsheet consultada no aclara `getCalculatedValue` vs `getValue` para lectura; el uso de `getOldCalculatedValue` es **[SUPUESTO]** a probar con un archivo con fórmulas.
