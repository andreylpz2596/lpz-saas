# Lectura LOCAL de comprobantes de pago y control de duplicados

Servicio reutilizable (hoy usado por la **cartera de cortadores y satélites**; pensado para **Ventas Asesor**, sin modificar ese módulo todavía).
Código: `packages/ByLopez/CorteConfeccion/src/Comprobantes/`. Plan de la cartera: `packages/ByLopez/CorteConfeccion/docs/confeccion/08_CARTERA_PRESTADORES.md`.

> Actualizado 2026-11-02. Reemplaza la propuesta anterior de un proveedor externo: **los comprobantes se procesan en el propio servidor**, sin enviar imágenes, PDF ni texto a Claude, OpenAI, Google u otro servicio, sin pago por lectura y sin credenciales externas.

## 1. Principio

La lectura **solo prepara un borrador**. Nunca registra dinero ni cambia saldos: cada módulo revisa el borrador, lo muestra al usuario y lo contabiliza **cuando el usuario confirma**, con sus propias reglas (en cartera, las reglas de arranque, duplicados y permisos se evalúan DESPUÉS de leer y sin alterar lo leído). No hay validación bancaria. No se elige al prestador por el nombre del destinatario.

Flujo: **adjuntar → lectura automática → revisar/corregir → confirmar**. Si la lectura falla o no está disponible, el archivo se conserva y los datos se completan a mano; «Ingresar manualmente» siempre está disponible.

## 2. Motor: Tesseract OCR (local)

- **Tesseract 5.x** (Apache 2.0, mantenido; <https://tesseract-ocr.github.io/tessdoc/Installation.html>), español (`spa.traineddata`, modelo `tessdata_best`), llamado por línea de comandos con `symfony/process` (ya incluido en Laravel 12). Sin dependencias PHP nuevas: no se actualizó Laravel ni se tocó Bagisto.
- **PDF**: **poppler** (`pdftotext`, `pdftoppm`, GPL). Primero se extrae la capa de texto; si el PDF no tiene texto (escaneado) se convierten las páginas a imagen (200 dpi) y se aplica OCR. Una operación por página.
- Por qué no un servicio externo: privacidad (los comprobantes tienen nombres, teléfonos y cuentas), costo por lectura y dependencia de credenciales. La exactitud medida con comprobantes reales (sección 7) es suficiente para un **borrador revisable**; no para registrar sin revisión.
- El adaptador de Claude visión (`LectorClaudeVision`) sigue en el código pero **desactivado por defecto** (`CC_COMPROBANTES_LECTOR=anthropic` + `CC_COMPROBANTES_ACTIVO=true` + credencial). No se usa y no está aprobado.

## 3. Instalación en un servidor

**Windows (desarrollo)**
```
winget install --id tesseract-ocr.tesseract
winget install --id oschwartz10612.Poppler          # solo si se leerán PDF
```
**Linux (producción, Debian/Ubuntu)**
```
apt install tesseract-ocr poppler-utils
```
**Idioma español** (la instalación del sistema suele traer solo inglés). Descargar `spa.traineddata` de <https://github.com/tesseract-ocr/tessdata_best> y dejarlo, junto con `eng.traineddata` y la carpeta `configs/` de la instalación de Tesseract, en `storage/app/tessdata/` (ruta configurable con `CC_TESSDATA`). Sin `spa.traineddata` se lee con `eng` (peor en tildes y ñ). La carpeta `configs/` (archivo `tsv`) permite medir la confianza por palabra.

**Variables (todas opcionales)** — `src/Config/comprobantes.php`
| Variable | Por defecto | Uso |
|---|---|---|
| `CC_COMPROBANTES_LECTOR` | `tesseract` | `tesseract` \| `anthropic` \| `ninguno` |
| `CC_TESSERACT_BIN` | autodetecta (`where`/`which`, ruta típica) | ruta de `tesseract` |
| `CC_TESSDATA` | `storage/app/tessdata` | carpeta con `spa.traineddata` |
| `CC_POPPLER_DIR` | autodetecta | carpeta con `pdftotext` y `pdftoppm` |
| `CC_COMPROBANTES_MAX_MB` | 10 | tamaño máximo por archivo |
| `CC_COMPROBANTES_MAX_ARCHIVOS` | 20 | archivos por abono |
| `CC_COMPROBANTES_MAX_PAGINAS` | 10 | páginas de un PDF |
| `CC_COMPROBANTES_TIMEOUT` / `CC_COMPROBANTES_TIEMPO_MAX` | 60 / 120 s | por proceso / por archivo |

Si Tesseract no está instalado, el lector responde «no disponible» y el registro manual sigue funcionando (el adjunto se guarda igual). Los PDF sin poppler se guardan sin leer, con un mensaje que dice qué falta.

## 4. Cómo funciona

1. `ComprobantesService::adjuntar()` guarda el archivo **original** en disco privado (`local`, `cartera_soportes/AAAA/MM/<sha256>.<ext>`), con huella SHA-256. El mismo archivo (aunque se renombre) reutiliza el soporte.
2. `ComprobantesService::leer($soporte, $forzar = false)`: si el mismo archivo ya tiene una lectura utilizable hecha con las reglas vigentes (`lectura_proveedor` = `tesseract:<versión del parser>`), **se reutiliza sin ejecutar el OCR otra vez**. La relectura es explícita («Volver a leer», `$forzar = true`). Subir `ParserTextoComprobante::VERSION` cuando cambien las reglas obliga a releer las lecturas viejas.
3. `LectorTesseract` ejecuta el OCR (hasta tres pasadas: segmentación 4, segmentación 6 y, si aún faltan datos clave, imagen ampliada ×2; **solo se completan campos vacíos, nunca se reemplaza lo ya leído**). Con el TSV de Tesseract mide la confianza por palabra.
4. `ParserTextoComprobante` interpreta el texto **por etiquetas y contexto, no por posiciones**.
5. El resultado es un `BorradorOperacion` (o varios, uno por página de un PDF). `DeduplicadorOperaciones` lo compara con el lote y con todo lo ya registrado.
6. El cliente envía **un archivo por petición** (progreso «Leyendo comprobante… (2 de 4)»; un OCR lento no agota el tiempo de una sola petición).

Un archivo es **datos, no instrucciones**: el texto leído solo pasa por el parser; nunca se ejecuta.

## 5. Campos del borrador (`BorradorOperacion`)

`valor` (decimal exacto, formatos colombianos), `moneda`, `fecha` (AAAA-MM-DD, la del comprobante; **nunca la de hoy**), `hora` (HH:MM o HH:MM:SS), `medio`, `banco_origen`, `banco_destino`, `referencia` (**ID/referencia de la transacción**, texto: se conservan los ceros iniciales), `autorizacion` (**código de autorización/aprobación**, aparte del ID), `remitente`, `contraparte` (destinatario), `direccion` (egreso|ingreso), `soporte_id`, `pagina`, `advertencias`.

- Lo que no se detecta queda **vacío y señalado** («valor no legible», «fecha no legible», «referencia ilegible», «medio no identificado»). No se rellena con fecha de hoy, «Nequi» ni ningún valor por defecto; no se deduce un banco de origen que no aparece.
- **Confianza**: si el OCR no vio con seguridad una referencia o una autorización (< 85 %), se advierte «verifica la referencia… (caracteres parecidos 0/O, 1/I, 5/S, 8/B)».
- Si el comprobante solo trae un código de aprobación (p. ej. DaviPlata), queda en `autorizacion` y **sirve de identificador de la operación** para los duplicados.
- **Huella de la operación**: banco/medio + identificador normalizado + fecha + importe + moneda + dirección; `null` si falta contexto (una referencia aislada no es identificador único: da «posible duplicado»).

## 6. Reglas de interpretación y cómo agregar un formato nuevo

Todo está en `ParserTextoComprobante` (listas de constantes al inicio). **No hace falta crear una plantilla por comprobante**: un formato desconocido devuelve datos parciales y señala lo pendiente.

- **Importe**: etiquetas `ETIQUETAS_VALOR` («¿Cuánto?», «Valor», «Valor de venta», «Valor transferido»…). Si no hay etiqueta, se toman líneas que son *solo* un importe con símbolo `$`/`COP` (p. ej. el importe grande bajo el título); un importe repetido **se cuenta una vez**, y si hay importes distintos se avisa. Nunca es el importe: costo de la transacción, comisión, saldo, cupo, límite, GMF/IVA, número de cuenta, teléfono o llave (`NO_VALOR`).
- **Identificador / autorización**: `ETIQUETAS_REFERENCIA` («Referencia», «ID de transacción», «Comprobante No.»…) y `ETIQUETAS_AUTORIZACION` («Número de aprobación», «Cód. Autorización»…), por separado. Un teléfono nunca es referencia.
- **Fecha y hora**: etiqueta («Fecha», «Fecha y hora de la transacción») o, si el OCR la perdió, la primera línea que sea una fecha completa; formatos `30 de septiembre de 2026`, `Octubre 19 de 2026`, `26/10/2026`, `2026-10-30`; horas `08:59 a. m.`, `12:18 p.m.`, `10:10:40`.
- **Origen / destino**: secciones «Origen» y «Destino» (Dueño, Banco/Entidad); «Desde», «Banco destino», «Para», «Beneficiario»; DaviPlata («Pasaste Plata a otro DaviPlata» → «A <nombre>»). La entidad que *recibe* no se toma como medio de pago.
- **Medio**: lista `MEDIOS` (Nequi, DaviPlata, Bancolombia, Davivienda, BBVA…), el primero que aparece; tolera una letra mal leída (`Negui` → Nequi).

**Para sumar un formato**: (1) correr `php packages/ByLopez/CorteConfeccion/tests/Demo/evaluar_comprobantes.php <carpeta> <esperados.json>` y ver el texto/campos; (2) agregar la etiqueta o el medio faltante a la constante correspondiente; (3) subir `VERSION`; (4) agregar un caso con datos inventados en `tests/Feature/CarteraLecturaFormatosTest.php`; (5) volver a evaluar con el comprobante real.

## 7. Resultados con comprobantes reales (OCR real, sin simulación)

Equipo de desarrollo (Windows, Tesseract 5.5.3 + `tessdata_best` español). Los valores de esta tabla son ficticios (los comprobantes reales no se versionan); los resultados de lectura son los medidos. Esperados verificados a mano contra cada imagen; el extractor no los conoce (`tests/Demo/evaluar_comprobantes.php`). Evidencia: `packages/ByLopez/CorteConfeccion/docs/confeccion/evidencia_2026-10-29/lectura_local_2026-11-02/`.

| Comprobante | Segundos | Resultado |
|---|---|---|
| **Nequi** $50.000 · 05/01/2026 · 09:15 · M00000001 | 1,7 | todos los campos correctos (referencia marcada «verifica»: confianza baja aunque acertó) |
| **DaviPlata** $200.000 · 06/01/2026 · 11:30 · aprobación 000001 | 2,5 | todos correctos; la aprobación queda en `autorizacion`, sin inventar referencia |
| **Corresponsal Nequi** $120.000 · 07/01/2026 10:00:00 · ID 0000000002 · autorización M00000002 | 8,0 | todos correctos; ID y autorización por separado (la foto de pantalla perdió la palabra «Fecha»: se tomó la fecha suelta) |
| **Bold → DaviPlata** $80.000 · 08/01/2026 09:00:00 | 8,7 | importe contado **una vez**; origen Bold y destino DaviPlata, remitente y destinatario distintos; **error**: una ID alfanumérica (p. ej. `AB8CD8EF12`) se leyó con 8↔S (`AB8CDSEF12`) (8↔S), **marcada para verificar** |
| Foto de otra pantalla Nequi (reflejo y ángulo) $25.000 · 09/01/2026 18:00 | 5,3 | valor, fecha, hora, destino y entidad correctos; **referencia no leída** (queda pendiente de revisión) |
| Foto de una tela (sin texto) | 7,2 | ninguna operación propuesta |

Aciertos por campo (6 imágenes con esperados): valor 5/5, fecha 5/5, hora 5/5, autorización 5/5, medio 5/5, banco de origen 4/4, banco de destino 5/5, remitente 4/4, destinatario 5/5, **referencia 3/5** (los dos errores quedaron señalados: uno con advertencia de confianza, otro como pendiente).

Recorrido de navegador (adjuntar → leer → corregir → confirmar, duplicados, imagen poco legible y PDF) sobre la base demo aislada: **33/33 pasos** en Chromium 1440 px, Chromium 390 px y WebKit con perfil iPhone. Con los cuatro comprobantes a la vez: 15–26 s en total (OCR en frío) y total automático $ 450.000 (suma de los cuatro importes); la fecha anterior al arranque no impide leer: el rechazo llega al confirmar, con el mensaje de la regla y sin tocar la fecha. PDF con texto y PDF escaneado: leídos. Lo creado se revierte y se purga (`tests/Demo/consulta_demo_comprobantes.php purgar|limpiar`).

## 8. Limitaciones conocidas

- El OCR confunde caracteres parecidos en códigos alfanuméricos (0/O, 8/S, 1/I): por eso la **revisión humana es obligatoria** y las referencias dudosas se marcan. No se "corrige" automáticamente (no hay forma segura de saberlo).
- Fotos de pantalla con reflejos, ángulo o texto muy pequeño dan datos parciales. Imágenes rotadas 90° no se enderezan.
- Un comprobante por imagen y por página de PDF (una imagen con varias transferencias, p. ej. un extracto, no se separa).
- Formatos no vistos pueden dejar campos vacíos hasta agregar la etiqueta (sección 6). Solo COP (si no se ve `$`/COP se avisa «moneda no indicada»).
- Tiempo: 2–9 s por imagen en el equipo de desarrollo (hasta 13 s en imágenes sin texto, que agotan las tres pasadas). El rendimiento en producción con volumen **no está medido**.
- Los PDF necesitan poppler; sin él se guardan sin leer.

## 9. Duplicados y consolidados (cartera)

- **Soporte ya cargado** (misma huella de archivo) ≠ **transferencia ya registrada** (misma huella de operación): subir un archivo repetido no registra nada; si además la transferencia ya existe se muestra «Ya está en AB-xxxx».
- Una **imagen distinta de la misma transferencia** (recorte, otro formato, otra captura) se detecta por la huella de la operación (probado con JPG reescalado, PNG y WEBP).
- Coincidencia total = «Duplicado» (bloquea la doble contabilización); parcial = «Posible duplicado» (exige un motivo auditado). Se compara dentro del lote y contra todo lo registrado (todos los prestadores, incluidos consolidados). Un movimiento reversado conserva el vínculo y exige una corrección explícita con motivo.
- La validación se repite en el servidor al guardar; un índice único (`huella_activa`) impide la doble contabilización aunque haya doble clic o dos usuarios a la vez. El enlace al movimiento previo se muestra según los permisos de quien consulta.
- Consolidado: total manual con número de transferencias, o detalle por operación (el total es la suma de las operaciones incluidas; las descartadas no entran). Sin detalle no se pueden comparar referencias individuales.

## 10. Permisos y privacidad

Soportes en disco privado (`local`), descargables solo por `CarteraController::soporte` con permiso sobre la cuenta; un prestador nunca ve soportes ni cuentas ajenas. Releer un soporte solo se permite sobre soportes de la misma cuenta. Nada sale del servidor.

## 11. Pruebas

- `tests/Feature/CarteraLecturaFormatosTest.php`, `CarteraLecturaLocalTest.php`: parser con formatos inventados, lectura OCR real de imágenes generadas, PDF con texto y PDF escaneado, reutilización de lecturas.
- `tests/Feature/CarteraComprobantesTest.php`, `CarteraHttpTest.php`: duplicados, concurrencia lógica, permisos, lector no disponible (estas usan el adaptador externo con `Http::fake`: **no son evidencia de OCR**).
- `tests/Demo/evaluar_comprobantes.php` y `recorrido_comprobantes.cjs`: evidencia con comprobantes reales y navegador real.

## 12. Para Ventas Asesor (futuro, sin implementar)

Reutilizar `ComprobantesService` + `LectorTesseract` + `DeduplicadorOperaciones` con `direccion = ingreso` y su propia implementación de `FuenteOperacionesExistentes` (consulta de pagos ya registrados). Cada módulo muestra el borrador y confirma con sus reglas; el servicio no contabiliza. No se modificó Ventas Asesor.
