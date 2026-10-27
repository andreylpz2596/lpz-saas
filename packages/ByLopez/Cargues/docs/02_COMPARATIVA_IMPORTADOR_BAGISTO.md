# 02 — Importador nativo de Bagisto vs. requerimiento de Cargues

Referencia de **solo lectura**: `packages/Webkul/DataTransfer/` y `packages/Webkul/Admin/src/{Http/Controllers/Settings/DataTransfer,Resources/views/settings/data-transfer}`. No se modifica (CLAUDE.md).

## 1. Qué hay realmente [C]

| Pieza | Clase / archivo | Qué hace |
|---|---|---|
| Registro de tipos | `DataTransfer/src/Config/importers.php` | Mapa `type → importer FQCN + sample_paths` (products, customers, tax_rates) |
| Orquestador | `Helpers/Import.php` (1157 líneas) | Estados `pending→validating→validated→(downloading)→processing→(linking→indexing)→completed`; estrategia `skip-errors`/`stop-on-errors`; `ACTION_APPEND`/`ACTION_DELETE`; transacción por lote (`DB::beginTransaction` en `start/link/index`); eventos `data_transfer.imports.{started,linking,indexing,completed}`; `claimForProcessing()` para no despachar dos veces; estado de validación en archivos JSON bajo `private` |
| Importadores | `Helpers/Importers/AbstractImporter.php` (+ Product/Customer/TaxRate) | Contrato `validateRow`, `importBatch`; `BATCH_SIZE = 100`; `saveValidatedBatches()` agrupa filas válidas en `import_batches.data` (JSON); validación por ventanas y en cola |
| Fuentes | `Helpers/Sources/{CSV,XLS,XLSX,XML}.php` | Iterador por fila; `generateErrorReport()` |
| Jobs | `Jobs/Import/{ValidateChunk,ImportBatch,LinkBatch,IndexBatch,DownloadImages,…}.php` | `Bus::batch`; `tries=1` y fragmentos idempotentes en validación; `BoundedByRetryAfter` y `RetriesOnDeadlock` en ejecución |
| Modelo | migraciones `imports`, `import_batches` | `imports`: state, type, action, validation_strategy, allowed_errors, contadores, `errors` JSON, `field_separator`, `file_path`, `error_file_path`, `summary` JSON, `started_at/completed_at`. `import_batches`: state, `data` JSON, `summary` JSON, FK cascade |
| Controlador | `Admin/.../ImportController.php` | `store` (valida extensión+MIME, guarda en `private` bajo `imports/{id}/`), `import`, `validate*`, `start`, `link`, `indexData`, `stats`, `downloadErrorReport`, muestras |
| Vista | `import.blade.php` (918 líneas) | Componente Vue `v-import` con stepper, barra de progreso (`stats.progress`), contadores (creados/actualizados/eliminados), tabla de errores, descarga de reporte |
| ACL | `Admin/src/Config/acl.php:1259-1280` | `settings.data_transfer.imports{,.create,.edit}` |

## 1.1 Verificación de afirmaciones (revisión 2026-10-13)

| Afirmación | Evidencia | Estado |
|---|---|---|
| Tipos fijos por config | `Config/importers.php`; `ImportController::store` → `'type' => 'required|in:'.implode(',', array_keys(config('importers')))` | Confirmado |
| Sin mapeo de columnas | `AbstractImporter::validateColumns()` 252-270: encabezado debe cumplir `^[a-z][a-z0-9_]*$` y estar en `validColumnNames`; faltan `permanentAttributes` → error | Confirmado |
| Solo hoja activa, encabezado en fila 1 | `Sources/XLSX.php::initialize()` → `getActiveSheet()`; `columnNames = getNextRow()` con `currentRowNumber = 1` | Confirmado |
| Libro completo en memoria | `IOFactory::load(...)` sin `setReadDataOnly` ni filtro | Confirmado en código; **consumo no medido** |
| XLSX corta en la primera fila vacía | `XLSX::getNextRow()` devuelve `false` si `array_filter` vacío → `AbstractSource::next()` pone `-1` y `valid()` = false | Confirmado (lectura de código; no ejecutado con un XLSX) |
| CSV: línea > 4096 se parte | `CSV.php` `fgetcsv($r, 4096, …)`; reproducción aislada y php.net | Confirmado |
| CSV: sin entrecomillado/codificación configurables | `CSV.php` solo pasa `delimiter` | Confirmado |
| Exporte de errores reintroduce fórmulas (XLSX) | `fromArray` + `DefaultValueBinder` (`=…` → fórmula); reproducción aislada genera `<f>` | **Confirmado a nivel de librería**; el método `generateErrorReport` del core **no se ejecutó** de punta a punta → hipótesis fuerte para el flujo completo |
| Exporte CSV evalúa fórmulas y altera valores | `Writer/Csv.php:127` `preCalculateFormulas`; reproducción: `=1+1` → `2`, `+57300` → `57300` | Confirmado a nivel de librería; mismo matiz |
| Excel ejecuta la fórmula al abrir el reporte | OWASP CSV Injection | **Hipótesis** (no probado en Excel) |
| Una fórmula del archivo subido llega como texto `=…` | `XLSX::getNextRow()` usa `getValue()` | Hipótesis a confirmar con un XLSX con fórmulas |
| `claimForProcessing` evita doble despacho | `Helpers/Import.php:699-723` (comentario y evento) | Confirmado en código; implementación interna no auditada línea a línea |
| Lote de 100 filas | `AbstractImporter::BATCH_SIZE = 100`, `saveValidatedBatches()` | Confirmado |
| Exige worker si `process_in_queue` | `ImportController::start()` rechaza con `queue.default == 'sync'` | Confirmado |

## 2. Qué cubre, qué falta

| Requisito Cargues | Soporte nativo | Faltante | Recomendación |
|---|---|---|---|
| Tipos parametrizables en BD | No (config PHP + clase por tipo) | Registro de tipos en BD con versión | Tablas propias de tipo/versión (`03`) |
| Subir archivo y conservar **todas** las filas | Guarda el archivo; filas válidas van a `import_batches.data`; las inválidas no se persisten | Fila original + normalizada + errores por fila | Tabla de filas propia |
| Relacionar columnas (mapeo) | **No** (columnas por nombre exacto) | Todo | Motor de mapeo + plantillas versionadas |
| Hoja y fila de encabezado | No (hoja activa, fila 1) | Todo | Selector en UI + `listWorksheetInfo` |
| CSV con delimitador/entrecomillado/codificación | Solo delimitador | Entrecomillado, escape, BOM, codificación | Lector propio |
| XLSX eficiente | Carga completa en memoria; corta en primera fila vacía | Lectura acotada; filas vacías | PhpSpreadsheet con filtro **como candidato**; validar con la prueba de `01` §4.2 (el filtro no evita parsear la hoja completa) |
| Validar sin aplicar cambios | Sí (fase de validación separada) | Validación contra reglas de negocio de ByLopez | Reglas por tipo en el paquete propio |
| Ejecutar con confirmación explícita | Sí (botón tras validar) | Revalidar condiciones antes de ejecutar | Huella de validación + recheck |
| Modo TABLA genérico | No (importadores específicos) | Escritura genérica sobre la estructura real de una tabla autorizada | Motor genérico de acciones (`07` §6), **sin clases programadas por destino** |
| Modo PROCEDIMIENTO | No | Todo | Contrato `CALL` (ver `03`) |
| Lotes y colas | Sí (`Bus::batch`, 100 filas) | Lotes por bytes; reanudación por fila | Reutilizar el patrón, no el código |
| Idempotencia/doble clic | Parcial (`claimForProcessing`) | CAS por estado, marcas por fila, bloqueo con nombre | Ver `07` §8 |
| Errores descargables | Sí | Protección contra fórmulas; permiso específico | Exportador propio |
| Historial | Listado `imports` (DataGrid) | Usuario/tipo/archivo/contadores/estado enriquecidos | DataGrid propio |
| Permisos separados | Solo ver/crear/editar | configurar / ejecutar / errores | ACL propia |
| Retención y datos personales | Borra por `purgeImportFiles` al eliminar | Política temporal, borrado de filas | Job de retención |
| Progreso real por etapas | Sí (batches completados / total) | Etapas de lectura y validación propias | Métricas por etapa |

## 3. Reutilizar vs. desarrollar

**Tomar como patrón (copiar idea, no código):** máquina de estados con constantes; `claimForProcessing` (reclamo atómico); `BoundedByRetryAfter` (timeout < `retry_after`); validación por ventanas con fragmentos idempotentes (`hasValidationFragment`); resumen de filas inválidas (`summarizeRowNumbers`); stepper y panel de progreso de `import.blade.php`; almacenamiento bajo `private/imports/{id}/` y limpieza por directorio.

**No reutilizar:**
- `Webkul\DataTransfer\Helpers\Import` y sus importadores: acoplados a `config('importers')`, a `Import`/`ImportBatch` de Concord y a tipos fijos. Enchufar un tipo ahí obligaría a escribir una clase por fuente y no daría mapeo, hoja, encabezado ni procedimientos.
- `Sources\XLSX`: memoria y corte por fila vacía (H2).
- `generateErrorReport`: reintroduce fórmulas en XLSX y altera valores en CSV (H4, confirmado a nivel de librería).

**Desarrollar en `ByLopez\Cargues`:** lectores (XLSX/CSV), normalización por tipos, mapeo y sugerencias, validación por tipo, motor genérico de acciones de tabla, contrato de procedimiento, intermedias dinámicas, máquina de estados, jobs, historial, acceso de administrador, vistas.

**Sin límite al importador actual:** el nativo sigue siendo una vía para productos, clientes y tasas. Cargues es un motor genérico y configurable. Si un tipo crea entidades con servicios y eventos (p. ej. clientes), las opciones para no omitir invariantes están en `07` §10 (decisión pendiente).

## 4. Riesgo de acoplarse a Webkul

Cualquier actualización de Bagisto (el repo está en rama `upgrade/bagisto-2.4.9`) puede cambiar `DataTransfer`. Depender solo de contratos estables (repositorios, eventos) mantiene el aislamiento exigido por CLAUDE.md.
