# 10 — Archivo de referencia "99 Envíos — Envíos completos"

**Revisión:** 3.3 (2026-10-14) · **Estado:** análisis [V] sobre archivos reales, **en solo lectura**; propuestas [PROPUESTO]; decisiones [PENDIENTE].
Es una **referencia para configurar un tipo**. El motor sigue siendo genérico: nada de este documento entra en el código del motor.
**Sin datos personales:** este documento solo contiene estructura, formas (`9{12}` = 12 dígitos), conteos y valores de columnas no personales (estado, producto, transportadora, seguro). Los archivos reales **no se copian al repositorio**.

## 0. Origen de los archivos (salvedad)

El adjunto `Envios_Completos_2026-10-03(2).xlsx` **no llegó** a la sesión de Claude Code, y no existe con ese nombre en el equipo. Se analizaron, en copias dentro del *scratchpad*, las exportaciones del mismo reporte que sí estaban en el equipo:

| Id | Archivo (Descargas) | Filas | Columnas | Fecha de envío (rango) | SHA-256 (16) |
|---|---|---|---|---|---|
| **P** (principal) | `Envios_Completos_2026-10-03 (1).xlsx` | 152 | 28 | 2026-08-12 → 2026-09-30 | `B1669AD84B0873E6` |
| A | `Envios_Completos_2026-10-03.xlsx` | 76 | 28 | 2026-09-14 → 2026-10-02 | `355CF577F6CD3302` |
| A-csv | `Envios_Completos_2026-10-03.csv` (guardado el 2026-10-14) | 76 | 28 | igual que A | `E083A5C41D52F288` |
| H1 | `Envios_Completos_2026-08-29.xlsx` | 47 | 28 | 2026-08-12 → 2026-08-29 | — |
| H2 | `Envios_Completos_2025-07-06.xlsx` | 99 | **25** | 2025-06-11 → 2025-07-05 | — |
| H3 | `Envios_Completos_2025-06-17.xlsx` | 137 | **25** | 2025-02-11 → 2025-06-11 | — |

**Pendiente:** confirmar que el "(2)" es igual a P o a A, o reenviarlo. Si es distinto, se repiten §1–§5 con él (scripts en el *scratchpad*: `ref_estructura.php`, `ref_perfil.php`, `ref_fidelidad.php`, `ref_csv_vs_xlsx.php`, `ref_extra.php`).

## 1. Estructura del archivo [V]

- **Origen:** `docProps/app.xml` dice `Application = SheetJS`; es una exportación web, no un libro de Excel.
- **Una sola hoja**, visible: `Envíos Completos`.
- **Encabezados** en la fila 1; sin filas vacías ni celdas combinadas.
- **Contenedor:** la hoja (`sheet1.xml`, 182 304 bytes) está guardada **sin comprimir** dentro del ZIP, unos 43 bytes por celda. Por eso `max_bytes` se calcula con este formato (`09` §5).
- **Tipos de celda:** las 4284 celdas de P son `t="str"`, es decir, todo texto.
  - Sin fórmulas (`<f>` = 0), sin cadenas compartidas (`sharedStrings` no existe) y con un único estilo (`cellXfs = 1`).
  - Las fechas son **texto** `AAAA-MM-DD hh:mm:ss`: no hay números de serie de Excel ni calendario 1900/1904.
  - Los importes también son texto.
- **Cambios de estructura en el tiempo:**
  - Las exportaciones de 2025 (H2, H3) tienen **25 columnas**: no traen `transportadora`, `transportadora_nombre` ni `Aplica Contrapago`, y traen `seguro_99` donde hoy está `seguro`.
  - Es evidencia directa de que el formato del proveedor cambia. La comparación guardada (T4, con `firma_encabezados`) debe detectarlo y pedir revisión, sin fallar en silencio.
- **Volumen observado:** entre 47 y 152 filas por exportación (máximo **observado** en los archivos del equipo; no es un límite de negocio).

## 2. Columnas [V]

| Col | Encabezado | Clase | Vacíos (P) | Forma / valores (P) | Observación |
|---|---|---|---|---|---|
| A | `codigo_sucursal` | cuenta del remitente | 0 | 1 valor constante | Igual a `codigo` (R) |
| B | `valor_servicio` | importe (texto) | **1** | `9{5}.9{2}` 116, `9{4}.9{2}` 19, `9{5}.9` 9, **`9{5}.9{12}` 4**, `9{5}` 2, `9{6}.9{2}` 1 | Las 4 de 12 decimales son artefactos de coma flotante: difieren < 1e-6 de su valor a 2 decimales. El vacío está en una fila "Devolución ratificada" |
| C | `fecha_envio` | fecha y hora (texto) | 0 | `AAAA-MM-DD hh:mm:ss`, 152 distintas | — |
| D | `numero_de_guia` | **identificador** | 0 | `9{12}` 150 + `9{11}` 2 | Igual a `numero_guia` (S). Ver §3 |
| E | `transportadora` | código | 0 | `1` → interrapidisimo (150), `4` → coordinadora (2) | Código del proveedor, **no** del catálogo de Logística |
| F | `Producto` | texto libre | 0 | `CAMISETAS` 125, `Camisetas` 23, `camisetas` 1, `camisetas base` 1, `BARRIO CARACOL` 1, `BARRIO CENTRO` 1 | Escrito a mano: no sirve como categoría. Igual a `producto` (T) |
| G | `nombre_destinatario` | **personal** | 0 | — | = U |
| H | `direccion_destinatario` | **personal** | 0 | — | = V |
| I | `telefono_destinatario` | **personal** | 0 | `9{10}` | = W |
| J | `ciudad_destino` | texto | 0 | 98 distintas | = X |
| K | `valor_comercial` | importe (texto) | 0 | `9{5}` 87, `9{6}` 64, `9{7}` 1 | = Y |
| L | `estado_del_envio` | **estado** | 0 | 11 distintos (§4) | = Z |
| M | `nombre_sucursal` | remitente | 0 | 1 valor constante | — |
| N | `fecha_actualizacion` | fecha y hora (texto) | 0 | `AAAA-MM-DD hh:mm:ss`, 149 distintas | §4 |
| O | `aplica_contrapago` | indicador | **1** | `1` (151) o vacío (1) | Su gemela AB dice `No` en esa fila |
| P | `seguro` | categoría | 0 | `Seguro 99` 136, `Desactivado` 11, `Seguro 99 Plus` 5 | — |
| Q | `valor_seguro_99` | importe (texto) | **11** | `9{4}` 140, `9{5}` 1 | Vacía **exactamente** en las 11 filas con `seguro = Desactivado` |
| R–Z | `codigo`, `numero_guia`, `producto`, `destinatario`, `direccion`, `telefono`, `ciudad`, `valor`, `estado` | **duplicadas** | — | — | **Idénticas** a A, D, F, G, H, I, J, K y L en las 152 filas |
| AA | `transportadora_nombre` | nombre | 0 | `interrapidisimo`, `coordinadora` | Relación 1:1 con E |
| AB | `Aplica Contrapago` | indicador | 0 | `Si` 151, `No` 1 | Relación con O: `1`↔`Si`, vacío↔`No` |

**Hallazgos para la configuración (no para el motor):**
1. **Encabezados que solo difieren en mayúsculas:** `Producto` (F) y `producto` (T). Una comparación sin distinguir mayúsculas los fundiría. El motor compara encabezados **exactos** y relaciona por posición (`09` F1-L5).
2. **Nueve pares de columnas idénticas.** Basta relacionar una de cada par. Si se debe verificar que las gemelas coinciden, es una regla del tipo [PENDIENTE §7, D-ENV-6].
3. **Datos personales** (G, H, I y sus gemelas): el requisito A7 obliga a guardar el JSON original de la fila en T6, así que T6 tendrá datos personales. Esto vuelve más urgente la decisión de **retención** (`06` B4).

## 3. Guía y llave de negocio [V] + [PROPUESTO]

**Evidencia en este archivo (P):**
- 0 guías vacías;
- 0 duplicadas, ni exactas ni normalizadas (mayúsculas y espacios);
- 0 con espacios y 0 con cero inicial;
- se guardan como texto, sin pérdida de precisión.
- **La longitud depende de la transportadora:** 12 dígitos en las 150 de interrapidisimo y 11 dígitos en las 2 de coordinadora.

**Evidencia entre exportaciones (cruce por guía exacta):**

| Cruce | Guías comunes | Misma `fecha_envio` | Cambió el estado | `fecha_actualizacion` retrocede |
|---|---|---|---|---|
| P ↔ A | 64 | 64 | 0 | 0 |
| P ↔ H1 | 47 (todas las de H1) | 47 | **8** | 0 |
| P ↔ H2, P ↔ H3 (2025) | 0 | — | — | — |

- Las exportaciones **se solapan**: la misma guía llega en varias cargas. Es el caso normal, no un error. El procedimiento debe tratar los reprocesos (A12).
- En todos los cruces, una guía común conserva su `fecha_envio`.

**Lo que esto NO demuestra:** que una guía sea única en la historia.
- Son 5 archivos, dos años distintos y solo dos transportadoras. Que no haya colisiones aquí no garantiza que un número no se reutilice en el futuro ni que dos transportadoras no compartan un número.

**Propuesta de llave [PROPUESTO]: `(transportadora, numero_de_guia)`**, no la guía sola:
- el formato de la guía cambia por transportadora, lo que sugiere numeraciones independientes;
- Logística ya protege la guía con `UNIQUE (transportadora_codigo, numero_guia)` en `bylopez_lg_guias` [C] (`Logistica/.../2026_10_09_100004_create_bylopez_lg_guias_tables.php:58`);
- **protección contra la reutilización:** si una llave ya conocida llega con **otra `fecha_envio`**, no se actualiza: la fila se rechaza con un motivo propio (p. ej. `GUIA_REUTILIZADA`), en lugar de pisar el envío anterior. Pendiente D-ENV-4.
- En la intermedia: `map.llaves.unicas = [[transportadora, numero_de_guia]]`, con intercalación exacta (`utf8mb4_nopad_bin`). Un duplicado **dentro** de la misma carga se resuelve con `map.duplicados`.

## 4. Estado y fechas: no actualizar con información antigua [V] + [PROPUESTO]

**Estados observados** (texto literal del proveedor; **no se inventan equivalencias**):
- **En P (11):** `Entregada` 110 · `Devolución ratificada` 20 · `Creado` 9 · `Reclame en oficina` 4 · `Reclamo en oficina informado WhatsApp - Mensaje inicial` 2 · `Confirmado por Cliente` 2 · `Admitida` 1 · `Intento de entrega` 1 · `Reclamo en oficina informado WhatsApp - Recordatorio 7 días` 1 · `Devolucion Regional` 1 · `Anulada` 1.
- **Solo en H1:** `Centro acopio`, `Telemercadeo`, `Transito nacional`, `Reclamo en oficina informado WhatsApp - Recordatorio 24h`, `Reclamo en oficina informado WhatsApp - Recordatorio 48h`.
- La lista **no es cerrada**: aparecen estados nuevos entre exportaciones. Hay variantes de escritura (`Reclame` / `Reclamo`, con y sin tilde) que **no** se unifican sin decisión.

**Transiciones observadas H1 → P** (8 guías; la misma guía, 5 semanas después):

| Antes (H1) | Después (P) |
|---|---|
| `Creado` | `Devolución ratificada` |
| `Admitida` | `Entregada` |
| `Centro acopio` | `Entregada` |
| `Telemercadeo` | `Devolución ratificada` |
| `Transito nacional` | `Entregada` |
| `Reclamo … Recordatorio 24h` | `Devolucion Regional` |
| `Reclamo … Recordatorio 48h` | `Entregada` |
| `Reclamo … Recordatorio 48h` | `Devolución ratificada` |

En las 8, `fecha_actualizacion` **avanzó**. En ningún cruce cambió el estado con la misma `fecha_actualizacion`.

**Fechas:**
- `fecha_envio` y `fecha_actualizacion`: 100 % con formato `AAAA-MM-DD hh:mm:ss` y sin zona horaria.
- En P, `fecha_actualizacion < fecha_envio` en **2 filas**, ambas `Confirmado por Cliente` (unas 5 h antes). La fecha de actualización no siempre es posterior al envío, y su significado exacto debe confirmarse.
- 9 filas `Creado` tienen `fecha_actualizacion = fecha_envio`.
- 3 valores de `fecha_actualizacion` se repiten entre guías distintas. No afecta, porque la comparación es por guía.

**Regla de no retroceso propuesta [PROPUESTO], sin orden de estados:**

| Comparación por llave (`fecha_actualizacion` entrante vs. la guardada) | Resultado de la fila |
|---|---|
| Llave nueva | `aplicada` (inserción) |
| Entrante **>** guardada | `aplicada` (actualización + historial) |
| Entrante **=** guardada y mismo estado | `sin_cambio` |
| Entrante **=** guardada y **otro** estado | `rechazada` con motivo `CONFLICTO_MISMA_FECHA` (no observado; no se elige un ganador) |
| Entrante **<** guardada | `omitida_antigua` |
| `fecha_actualizacion` vacía o no interpretable | `rechazada` |

- No se usa un **orden de estados** ("Entregada" gana a "Admitida") porque no está aprobado y los datos muestran que el proveedor no sigue un orden fijo (`Creado` → `Devolución ratificada`).
- Las fechas solo se comparan con fechas **del mismo proveedor**. Se asume una única zona horaria para todo el archivo [S, por confirmar].

## 5. Lectura, memoria y fidelidad [V]

### 5.1 Archivo real (P, 152 filas × 28 columnas)

| Lector | Celdas no vacías | Igual al XML crudo | Tiempo | Pico de memoria |
|---|---|---|---|---|
| XML crudo (`ZipArchive` + DOM, referencia) | 4271 | — | 0,02 s | 16 MB |
| PhpSpreadsheet 1.30.6 | 4271 | **4271/4271** | 0,24 s | 16 MB |
| OpenSpout 5.3.0 (*scratchpad*) | 4271 | **4271/4271** | 0,25 s | 16 MB |

- Las tres huellas de celdas coinciden.
- Las tres diferencias de fidelidad de OpenSpout (`08` §3.8: texto `=…`, fórmula sin caché, error) **no aparecen** en este formato, porque no tiene fórmulas.
- Tampoco aplican la caché de cadenas compartidas de OpenSpout ni el calendario 1904 de PhpSpreadsheet.

### 5.2 CSV re-guardado por Excel (A-csv)
- **Windows-1252** (no es UTF-8 válido), separador `;`, sin BOM, 76 filas × 28 columnas.
- Decodificado como Windows-1252 y comparado **por guía** con A: **0 diferencias** en las 76 × 28 celdas. Excel no alteró guías, fechas ni importes, porque SheetJS las había escrito como texto.
- Conclusión: con este archivo, el CSV exige detectar y confirmar la codificación (`09` §2.1).

### 5.3 Escalado con el mismo formato (sintético)
Archivos **sintéticos** que clonan el contenedor de P (mismos `styles`, `workbook` y relaciones, celdas `t="str"`, 28 columnas, sin cadenas compartidas), sin ningún dato real. Ejecución secuencial en el mismo equipo i5-4210U: los tiempos sirven para comparar, no son una estimación de producción.

| Filas | Celdas | OpenSpout (128M) | PhpSpreadsheet (2048M) | PhpSpreadsheet `readDataOnly` |
|---|---|---|---|---|
| 1 000 | 28 000 | 1,8 s · 16 MB | 1,0 s · 28 MB | 1,1 s · 28 MB |
| 10 000 | 280 000 | 17,4 s · 16 MB (primeras 1000 filas en 1,5 s) | 12,2 s · **176 MB** | 13,4 s · 176 MB |
| 50 000 | 1 400 000 | 58,3 s · 16 MB | 108,6 s · **774 MB** | 101,9 s · 774 MB |
| 100 000 | 2 800 000 | 184,3 s · 16 MB (primeras 1000 filas en 4,1 s) | 199,6 s · **1538 MB** | no medido (en 50k era igual a la lectura completa) |

- La huella de las filas coincide entre los lectores en cada tamaño.
- **PhpSpreadsheet:** entre ~0,55 y ~0,63 KB por celda en este formato. `readDataOnly` no ahorra memoria aquí, porque no hay estilos que descartar.
- **OpenSpout:** memoria constante; más lento que PhpSpreadsheet hasta unas 10k filas y más rápido por encima (sin cadenas compartidas no sufre la caché en disco de `08` §3.8).

### 5.4 Base de Laravel
Arrancar el kernel de consola de esta aplicación (Laravel 12 + Bagisto + paquetes ByLopez) ocupa **42 MB**. Se midió con la conexión a la BD apuntando a un destino inexistente, para no tocar ninguna base; el arranque no la necesitó.

### 5.5 Recomendación [PROPUESTO] (decisión R2 de `09`)
- **PhpSpreadsheet 1.30.6** para la Fase 1, detrás de la interfaz `LectorArchivo`:
  - ya está instalado: **ninguna dependencia nueva** ni cambios en `composer.json`;
  - fidelidad 100 % en el archivo real y en los fixtures de `08` §3.8, incluidos los casos que OpenSpout no cubre;
  - el máximo observado (152 filas) está muy por debajo del límite de memoria propuesto; ese número no es un límite de negocio.
- **Límites:** `memory_limit` del worker 512M, `max_celdas` 300 000, `max_bytes` 20 MB y hoja descomprimida ≤ 40 MB. Se comprueban **antes** de cargar la hoja, leyendo `<dimension>` y el índice del ZIP (`09` §5).
- **OpenSpout** queda como segunda implementación de la misma interfaz si un tipo futuro supera ese límite. Habría que aprobar la dependencia y cubrir sus 3 diferencias de fidelidad.
- **CSV:** `fgetcsv` (`08` §3.9) con detección de codificación.

## 6. Normalización sugerida para la configuración del tipo [PROPUESTO]

Es configuración del tipo (map), no código del motor.

| Campo | Tipo en el map | Regla |
|---|---|---|
| `numero_de_guia` | `identificador`, 20, `nopad_bin` | Obligatorio; solo dígitos [S, por confirmar con más transportadoras] |
| `transportadora` | `identificador`, 10 | Obligatorio; parte de la llave |
| `fecha_envio`, `fecha_actualizacion` | `fechahora`, formato `Y-m-d H:i:s` | Obligatorios; texto no interpretable → `rechazada` |
| `estado_del_envio` | `texto`, 100 | Obligatorio; se guarda literal |
| `valor_servicio` | `decimal(12,2)` | Si tiene más de 2 decimales y difiere < 1e-6 de su redondeo, se redondea (artefacto de coma flotante); si no, `rechazada`. Celda vacía según su regla `vacio` [PENDIENTE] |
| `valor_seguro_99` | `decimal(12,2)` | Celda vacía: regla `vacio` [PENDIENTE] (`07` §4.4) |
| `aplica_contrapago` | `bool` | Vacía: regla `vacio` [PENDIENTE] |
| Columnas personales | — | Solo se relacionan si el destino aprobado (D-ENV-1) las necesita. T6 las guarda de todos modos (A7) |

## 7. Decisiones pendientes del procedimiento real (R4 de `09`)

> **Actualización 2026-10-15:** el procedimiento v1 se implementó con las decisiones de Camilo; el estado de cada D-ENV y el análisis de importes (`valor_comercial` = valor a recaudar; `valor_servicio` = todo lo que descuenta 99 Envíos, cambia con el tiempo) están en `13` §2–§4 y en `06` B.7. El archivo `docs/Envios_Completos_2026-10-03.xlsx` es el «A» de §0.

| Id | Decisión | Evidencia y contexto |
|---|---|---|
| **D-ENV-1** | **Dónde se guarda el estado del envío:**<br>(a) tablas propias del estado según el proveedor (actual + historial), informativas, sin generar eventos de Logística;<br>(b) además, generar entregas o retornos en Logística | `bylopez_lg_guias` no tiene columna de estado y es inmutable salvo `tracking`, `proveedor_referencia` y `recaudo_estado` [C]. Logística excluye el "tracking intermedio" (LOG-D-03) y reserva la fuente `integracion_transportadora` [C]. (b) afectaría las entregas que lee COM-04 (comisiones): alto impacto. **No se elige** |
| **D-ENV-2** | Llave `(transportadora, numero_de_guia)` | §3 |
| **D-ENV-3** | Regla de no retroceso por `fecha_actualizacion` (tabla de §4) y significado y zona horaria de esa fecha | §4: 2 filas con actualización anterior al envío |
| **D-ENV-4** | Llave conocida con otra `fecha_envio`: ¿rechazar como posible guía reutilizada? | §3 |
| **D-ENV-5** | Relación entre el código del proveedor (`1`, `4`) y el catálogo de transportadoras de Logística (`INTER_RAPIDISIMO`, `99ENVIOS`…). **coordinadora no está** en los valores iniciales del catálogo | Solo si D-ENV-1 enlaza con `bylopez_lg_guias` |
| **D-ENV-6** | ¿Verificar que las columnas gemelas coinciden (rechazar la fila si difieren)? ¿Qué hacer con celdas vacías en `valor_servicio`, `valor_seguro_99` y `aplica_contrapago`? | §2 |
| **D-ENV-7** | ¿Hace falta enlazar con el pedido o despacho de ByLopez? ¿Qué hacer si la guía no existe en `bylopez_lg_guias`? | No se cruzaron guías reales con la BD (no se usan bases de negocio) |

Lo ya aprobado no se reabre (A12): actualiza el estado por guía, no toca estados comerciales ni recaudos, y no retrocede información.
