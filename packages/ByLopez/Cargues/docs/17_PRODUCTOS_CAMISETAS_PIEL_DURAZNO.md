# 17 · Tipo de cargue «Productos — Camisetas piel de durazno»

**Fecha:** 2026-11-04 · **Estado:** §1–§6 plan (escrito antes de implementar); **§7–§12 IMPLEMENTADO y PROBADO** (cargas 21–24, versión 1); **§13 CIERRE DE USO REAL: versión 2 ACTIVA en dev** (niño con su eje real, descripciones, Nueva venta corregida, aviso de línea y color; cargas 25–30). **§13 corrige a §1, §7 y §11 donde se contradicen** (en especial: la familia de niño NO necesita `size`). Sin producción, sin `packages/Webkul/`, sin commit ni push.
**Alcance autorizado por Camilo:** analizar, implementar, migrar/configurar/activar en **desarrollo** y ejecutar un cargue de prueba con productos **sintéticos**. Sin producción, sin `packages/Webkul/`, sin commit ni push, sin modificaciones masivas de productos reales, sin tocar los tipos de 99 Envíos.

## 1. Modelo real (comprobado en la BD de desarrollo y en el código)

| Pieza | Cómo es hoy |
|---|---|
| Producto padre | `configurable`, SKU = **código base** (p. ej. `106DURZ-H`), super atributos **`color` + `size`** (los 77 configurables). La convención del catálogo es **un color por padre** («Camiseta Piel Durazno Negro Hombre»), pero Bagisto admite varios colores por padre y el cargue no lo impide. |
| Variante vendible | `simple` con `parent_id`; SKU propio (`106DURZ-H-S`); `visible_individually = 0`; `manage_stock = 1`. |
| Familia = público | `Camiseta_Hombre_DURZ` (hombre), `Camiseta_Mujer_DURZ` (dama), `Camisetas_nino_DURZ` (niño). **La familia de niño NO tiene el atributo `size`** (solo color, composición, cuello, manga, tela, costo, marca, peso) y no hay ninguna variante de niño en dev. |
| Atributos (select) | `color` (37 opciones con swatch), `size` (S, M, L, XL, XXL, T-Unica), `sleeve` «Manga» (Corta, Larga, Sin Manga, Mitad), `cuello` (Redondo, Cuello-V, Tipo Polo), `tipo_tela` (Piel Durazno + telas de prueba QA), `composicion` (80% poliéster / 20% spandex), `brand` (BYLOPEZ). Todos globales (ni por canal ni por idioma). Padre y variante llevan manga, cuello, tela, composición y marca; color y talla solo la variante. |
| Precio y costo | Atributos `price` y `cost` de la **variante** (`float_value`). El padre no tiene precio. **`cost` es el costo comisionable oficial** (D-COST-02, `VentasAsesor/Support/Costos/CostoComisionableCatalogo`): ≤ 0 o NULL = «sin costo». No hay otra fuente de costo para productos. |
| Precios por grupo | `product_customer_group_prices` (mayoristas VIP 1–4, Recurrente). No los carga este tipo. |
| Canal, idioma, categorías | Canal `default`, idioma `es`. Categorías: padre en «CAMISETA CABALLERO» + «PIEL DURAZNO HOMBRE» (dama y niño análogas); las variantes del catálogo actual tienen la categoría hoja. Los accesos rápidos de Nueva venta se resuelven por esas categorías. |
| Inventario | `product_inventories` por origen; el cargue **no crea stock** (sin filas de inventario ni movimientos). |
| Índices | `catalog.product.create.after` / `update.after` → `product_flat` (Flat indexer), índices de inventario, precio y búsqueda (cadena de trabajos) e invalidación de la caché del catálogo. `update.before` → reescrituras de URL (Marketing). |
| Nueva venta | `CatalogoVentaService`: productos de primer nivel con `product_flat.status = 1` en el canal por defecto; variantes = hijos reales; stock = índice de inventario; precio = `getFinalPrice` del tipo. |

**Importador de Bagisto** (`Webkul/DataTransfer`): escribe por lotes con INSERT directos y reindexa al final; exige su propio formato (columnas técnicas, `configurable_variants` en texto). Se aprovecha la idea (validar todo antes, informar por fila, reindexar), no el mecanismo: aquí cada producto se guarda por los **repositorios y eventos** de Bagisto, igual que el administrador.

## 2. Plantilla (una fila por variante)

| Columna | Obligatoria | Nivel | Regla |
|---|---|---|---|
| Código del producto base | Sí | padre | SKU del configurable; agrupa las filas. Texto (se conservan ceros). |
| SKU de la variante | Sí | variante | Único en el archivo y en el catálogo. Texto. |
| Nombre del producto | Sí | padre | Igual en todas las filas del grupo. |
| Público | Sí | padre | Hombre · Dama · Niño (también se aceptan Caballero, Mujer, Nino). Decide la familia y las categorías. |
| Color | Sí (en la práctica) | variante | Debe existir como opción del atributo Color. **Vacío bloquea**: el color es eje de la variante (super atributo). |
| Talla | Sí | variante | Opción existente de Talla y de la lista del público (adulto: S, M, L, XL, XXL, T-Unica; niño: lista configurable). |
| Composición, Manga, Cuello | No | padre | Opción existente. Vacío = **dato pendiente** (no se inventa). |
| Tipo de tela | No | padre | Vacío = valor del tipo (**Piel Durazno**, configurable). |
| Costo | No | variante | Vacío = costo pendiente (sin costo). **0 bloquea** (en el aplicativo 0 significa «sin costo»: se pide dejarlo vacío). |
| Precio de venta | No | variante | Vacío = producto **inactivo** (no se puede vender sin precio). **0 bloquea.** |
| Peso (kg) | No | variante | Vacío = sin peso. |

Ausente (columna sin relacionar) y vacío se tratan igual: «no informado». Para un producto existente, «no informado» nunca es un conflicto: se conserva lo guardado.

## 3. Reglas de creación (por grupo = código base)

1. **Nuevo**: el código base no existe → se crea el padre y **exactamente** las variantes del archivo (nunca el producto cartesiano de colores × tallas).
2. **Agregar**: el padre existe, es configurable con color + talla, su familia es la del público y sus datos coinciden → se agregan las variantes nuevas; las existentes iguales quedan «sin cambio».
3. **Sin cambio**: todo existe y coincide.
4. **Bloqueado** (no se escribe nada del grupo; los demás grupos siguen): código base de un producto que no es configurable; SKU de variante usado por otro producto; combinación color/talla repetida en el archivo o ya existente con otro SKU; datos del archivo distintos de los guardados (nombre, público/familia, manga, cuello, tela, composición, color/talla/precio/costo de una variante existente) → **conflicto visible, sin sobrescribir**; opción desconocida; color vacío; talla que no es del público; precio o costo 0; familia sin el atributo necesario; filas del grupo inconsistentes entre sí; alguna fila del grupo rechazada por el motor (p. ej. SKU repetido o número inválido).
5. Nunca se borran variantes ausentes del archivo; nunca se agrupa por nombre; comparación de SKU exacta (sin coincidencias parciales).
6. **Opciones desconocidas**: se listan («Color «Verde Lima» no existe»). Se resuelven por el flujo administrativo existente (**Catálogo › Atributos** para crear la opción, **Catálogo › Familias** para agregar un atributo) y se vuelve a cargar el archivo. El cargue **nunca crea opciones**.

## 4. Costo, precio, estado e inventario

- Precio y costo se guardan en los atributos `price` y `cost` de la variante (la misma fuente que leen Nueva venta y Comisiones). Costo vacío = NULL (sin costo); no se escribe 0.
- **Acción del cargue** (se elige al relacionar columnas): «Crear inactivos (revisar antes de publicar)» — por defecto — o «Crear y activar los completos». Un producto con alguna variante sin precio queda **inactivo** en cualquier caso. Un producto existente nunca cambia de estado.
- No se crean filas de inventario ni movimientos; no se tocan costos ni precios existentes ni ventas anteriores.

## 5. Implementación prevista

- **Motor (genérico, pequeño):** descarga de **plantilla por tipo** (registro en `cargues.plantillas`, por tabla intermedia); **selector de acción** cuando el tipo tiene más de una; **revisión agrupada** opcional por tipo (contrato `AgrupaResultado`, registro en `cargues.resultado.agrupados`) en la validación y en el resultado.
- **Tipo:** procedimiento `sp_cg_productos_camisetas_v1` (solo clasifica: rechaza lo bloqueado y planea el resto) + extensión `productos_camisetas`:
  - `derivar` (solo lectura): analiza el archivo **completo** por grupo (opciones, catálogo, conflictos) y deja por fila el plan, el bloqueo y las observaciones;
  - `aplicar`: **una transacción por GRUPO** (padre + variantes + marcas de todas sus filas), con los repositorios y eventos de Bagisto; vuelve a analizar el grupo dentro de la transacción (el plan pudo quedar viejo); reanudable e idempotente.
- Reglas de camisetas en `Cargues/src/Extensiones/ProductosCamisetas/`; configuración en `cargues.productos_camisetas`.
- Sin migraciones de negocio: solo la intermedia `bylopez_cgi_productos_camisetas`, que crea el motor al activar el tipo.

## 6. Pruebas previstas

Pest (instancia aislada): crear producto y variantes exactas; color vacío; opción desconocida; conflicto de SKU; agregar variante; repetir sin duplicar; fallo a mitad de un grupo sin producto incompleto y reintento; sin inventario; índices y Nueva venta. En dev: flujo completo por la interfaz (subir → relacionar → validar → revisar → ejecutar → resultado → abrir productos), repetición, y comprobación en BD y navegador.

## 7. Implementación (lo que quedó)

### 7.1 Decisiones tomadas durante la implementación

| Tema | Decisión |
|---|---|
| Mecanismo | Extensión del tipo + repositorios/eventos de Bagisto (`CreadorCamisetas`), no SQL: un INSERT directo se saltaría `product_flat`, índices de precio/inventario/búsqueda, URL y caché del catálogo. El procedimiento solo clasifica. |
| Padre | `ProductRepository::create(configurable)` **sin** super atributos (Bagisto crearía el cartesiano color × talla) + super atributos `color` y `size` + `update` del padre recién creado (sin variantes previas que borrar). Eventos `create.before/after` y `update.before`. |
| Variantes | `ProductRepository::create(simple, parent_id)` + `update` **solo** de los atributos indicados (lo mismo que `Configurable::createVariant`). Nunca `Configurable::update` sobre un padre existente: borraría las variantes que no vinieran. Al terminar el grupo, `update.after` del padre reindexa padre y variantes. |
| Atomicidad | Una transacción por **producto base** con la marca de todas sus filas. Cualquier fallo → se revierte el grupo entero y sus filas quedan rechazadas con el motivo. Fallo de **conexión** con la base → el error sube, la carga queda «fallida_ejecucion» reanudable y el grupo sin marca (se aplica al ejecutar de nuevo). |
| Concurrencia | `SELECT … FOR UPDATE` del padre existente; la restricción única de `products.sku` frena dos altas simultáneas (`CONCURRENCIA`). |
| Plan viejo | `aplicar` vuelve a analizar el grupo con el catálogo actual dentro de la transacción; si ya no es válido, lo rechaza («Al ejecutar: …»). |
| Estado | Acción elegida en «Relacionar columnas»: **«Crear inactivos» (por defecto)** o «Crear y activar los que tengan precio». Con alguna variante sin precio, el producto queda inactivo siempre. Una variante agregada a un producto existente sigue la misma regla; el padre no cambia de estado. |
| Valores comunes | `cargues.productos_camisetas.por_defecto`: tela «Piel Durazno» y marca «BYLOPEZ» (las tienen los 77 configurables actuales). Manga, cuello y composición **sin valor común**: vacío = dato pendiente. |
| Variante de producto existente | Hereda manga, cuello, tela, composición y marca que el padre ya tiene (el análisis comprobó que el archivo no lo contradice). |
| Nombre de la variante | «nombre + color + talla»; el color se omite si el nombre ya lo trae (convención del catálogo). |
| `url_key` | Slug del nombre; si existe, se le agrega el SKU; nunca reutiliza el de otro producto. |
| Categorías | Por público y por **nombre**: hombre → CAMISETA CABALLERO + PIEL DURAZNO HOMBRE; dama → CAMISETAS DAMA + PIEL DURAZNO DAMA; niño → CAMISETA NIÑO + PIEL DURAZNO NIÑO. Variante → categoría hoja (como las actuales). Una categoría inexistente se observa, no bloquea. |
| Observaciones | Un código por fila (`PRECIO_PENDIENTE`, `COSTO_PENDIENTE`, `DATO_PENDIENTE_*`, `PRODUCTO_INACTIVO`, `VARIANTE_INACTIVA`, `CATEGORIA_NO_RESUELTA`, `EXISTENTE_SIN_*`); con varias, `DATOS_PENDIENTES` y el mensaje las enumera. Un dato vacío solo es «pendiente» para un producto nuevo. |
| Tallas de niño | Lista configurable (2…16). Hoy no existen como opciones **y la familia de niño no tiene Talla**: se bloquean con `FAMILIA_SIN_ATRIBUTO`. |

### 7.2 Códigos de bloqueo (el producto entero no se carga)

`FILA_RECHAZADA` (la fila no superó el motor) · `GRUPO_BLOQUEADO` (las demás filas del grupo) · `GRUPO_INCONSISTENTE` · `PUBLICO_DESCONOCIDO` · `FAMILIA_NO_EXISTE` · `FAMILIA_SIN_ATRIBUTO` · `OPCION_DESCONOCIDA` · `COLOR_VACIO` · `TALLA_NO_CORRESPONDE` · `PRECIO_CERO`/`PRECIO_NEGATIVO`/`COSTO_CERO`/`COSTO_NEGATIVO` · `COMBINACION_REPETIDA` · `SKU_IGUAL_A_CODIGO_BASE` · `CODIGO_BASE_NO_CONFIGURABLE` · `SUPER_ATRIBUTOS_DISTINTOS` · `CONFLICTO_DATOS` (archivo frente a catálogo, con ambos valores) · `SKU_DE_OTRO_PRODUCTO` · `COMBINACION_YA_EXISTE` · al ejecutar: `CONCURRENCIA`, `ERROR_BD`, `ERROR_INESPERADO`.

### 7.3 Archivos

| Archivo | Cambio |
|---|---|
| `src/Extensiones/ProductosCamisetas/` (`AnalizadorCamisetas`, `CatalogoCamisetas`, `CreadorCamisetas`, `ProductosCamisetasExtension`, `RechazoGrupo`) | **Nuevos.** Reglas por grupo, lectura del catálogo, escritura por Bagisto, derivar/aplicar. |
| `src/Database/Procedures/sp_cg_productos_camisetas_v1.sql` + `.map.json` | **Nuevos.** SP que solo clasifica (`SQL SECURITY INVOKER`); map con 13 campos, 17 derivados, llave `sku_variante`, acciones `crear_inactivos`/`crear_activos`. |
| `src/Services/Cargas/Complementos/ProductosCamisetasResultado.php` | **Nuevo.** Detalle por fila + revisión agrupada con enlaces. |
| `src/Contracts/AgrupaResultado.php` | **Nuevo (motor genérico).** Revisión agrupada opcional por tipo. |
| `src/Services/Cargas/CargasService.php` | Motor: `plantillas`, `agrupado`, `acciones_etiquetas` en los tipos activos; `plantilla()` y `agrupado()`. |
| `src/Http/Controllers/Admin/CarguesController.php`, `src/Http/routes.php` | `GET cargues/tipos/{tipo}/plantilla.{xlsx,csv}` y `GET cargues/cargas/{carga}/agrupado` (solo administrador). |
| `src/Resources/views/admin/index.blade.php` | Paso 1: descarga de plantilla; paso 2: selector de acción; validación y resultado: revisión agrupada (filtro, motivos, pendientes, «Abrir producto»). |
| `src/Resources/lang/{es,en}/app.php` | Textos nuevos en los dos idiomas. |
| `src/Config/cargues.php` | Extensión, complemento, agrupado, plantillas, etiquetas de acciones, bloque `productos_camisetas`. |
| `docs/plantillas/` | Plantilla XLSX (hoja «Productos» con SKU como texto + «Instrucciones») y CSV (punto y coma, UTF-8), ejemplos `EJEMPLO-…`; `generar_plantillas.php` las regenera desde el map. |
| `.gitignore` | Excepción para `Cargues/docs/plantillas/*.xlsx` (solo sintéticos). |
| `tests/helpersProductos.php`, `tests/Feature/ProductosCamisetasTest.php` | **Nuevos.** |

**Migraciones:** ninguna de negocio; solo la intermedia `bylopez_cgi_productos_camisetas`, creada por el motor al activar. **Tipos de 99 Envíos y su lógica: sin cambios.**

## 8. Activación en desarrollo (2026-11-04)

- Base verificada antes de escribir (`lpz_saas`, puerto 3306, `APP_ENV=local`; el script aborta si no).
- `optimize:clear`; procedimiento instalado (`INVOKER`); tipo creado, contrato confirmado (0 hallazgos; solo escribe su intermedia) y activado por los servicios del módulo: **tipo 4 «Productos — Camisetas piel de durazno», versión 1 activa**, «procesar las válidas», acción por defecto «Crear inactivos», huella `1c52975d4902a025`.
- Worker `cargues` reiniciado con el código final; `cargues:diagnostico` → «Worker ACTIVO».

## 9. Pruebas

### 9.1 Pest (instancia aislada, puerto 3401) — `ProductosCamisetasTest` 8/8 (132 aserciones)

1. Activación (SP/map/extensión, contrato, solo intermedia, plantillas/agrupado/2 acciones).
2. Crear **exactamente** Negro/S, Negro/M, Blanco/L (no 6): super atributos, precio y costo en la variante (costo vacío = NULL), atributos, tela común, nombre, peso, estado, categorías, `product_flat`, índice de precio, **0 inventario**, pedidos/reservas/opciones intactos; aparece en Nueva venta; enlace. VALIDAR no escribe.
3. Inactivos y sin precio → no salen en Nueva venta; pendientes sin inventar.
4. Bloqueos (color vacío, color/talla desconocidos, SKU ajeno, precio 0, familia sin talla, nombres distintos, combinación repetida, público desconocido): cada producto con su motivo, el válido se carga, la opción no se crea, descarga con originales y motivo, SKU ajeno sin reasignar.
5. Agregar sin tocar la variante ausente; repetir = sin cambio y conteos iguales; precio distinto y combinación existente = conflicto sin sobrescribir; nombre distinto = conflicto.
6. Fallo a mitad de un producto → revertido entero, los demás cargados; el mismo archivo después lo crea sin duplicar.
7. Caída de conexión a mitad → lo aplicado queda, carga reanudable con 2 pendientes; al reejecutar solo esas, sin duplicar.
8. Plantilla XLSX/CSV descargable; la CSV se reconoce sola (13/13) y valida.

**Regresión de Cargues completa: 180/180** (172 previas + 8). Pint en los archivos nuevos.

### 9.2 Desarrollo, por la interfaz real (navegador headless con sesión de administrador)

Archivos sintéticos (no versionados) en `docs/pruebas_cargues/`: `SINTETICO_Productos_Camisetas_A_crear.xlsx` (8 filas), `_B_agregar.xlsx` (2), `_C_conflicto.xlsx` (1). Productos `PRUEBA-CARGUE-CAM-…`.

| Carga | Archivo | Vista previa | Resultado |
|---|---|---|---|
| **21** | A · Crear inactivos | 2 nuevos, 4 variantes nuevas, 4 bloqueados, opción desconocida «Verde Lima» (fila 7), pendientes de D01 | **2 productos (#6147 H01, #6151 D01), 4 variantes**; no cargados: N01 `FAMILIA_SIN_ATRIBUTO`, H04 `SKU_DE_OTRO_PRODUCTO` (106DURZ-H-S intacto en #125), H03 `OPCION_DESCONOCIDA`, H02 `COLOR_VACIO`. 4 aplicadas + 4 rechazadas = 8 |
| **22** | B | H01 existente: Negro/S sin cambio, Azul Cielo/XL nueva | 1 variante creada (#6153), 1 sin cambio; H01 con 4 variantes; «Variante agregada INACTIVA» |
| **23** | A otra vez | 0 nuevos, 4 sin cambio, 4 bloqueados | **0 creados, 4 sin cambio, 4 rechazadas: sin duplicados** |
| **24** | C | Bloqueado: «precio $ 9.000 frente a $ 8.500 (archivo frente a catálogo). No se sobrescribe» | No ejecutable (0 válidas); queda validada; precio intacto |

- BD: configurable con color+talla, familia por público, `parent_id`, precio/costo en variante, atributos en padre y variante, D01 sin composición (pendiente), categorías 7,8 / 5,6, `product_flat` e índices (precio ×7 grupos, inventario qty 0), **0 `product_inventories`**, todo inactivo.
- Navegador: ficha de #6147 con datos y 4 variantes «8500 COP · 0 Cantidad · Color/Talla»; listado del catálogo con los 7 productos inactivos.
- Nueva venta **sin publicar**: en una transacción revertida se activó H01 → aparece en la búsqueda y devuelve sus 4 variantes reales con color/talla y precio 8.500, stock 0; inactivo no aparece; tras revertir sigue inactivo.
- **Huella de 132 tablas** (pedidos, ítems, facturas, envíos, reembolsos, inventarios, reservas, carritos, clientes, precios por grupo, `bylopez_va_*`, `bylopez_lg_*`, `bylopez_cc_*`) + atributos/productos/super atributos existentes + número de opciones: **sin diferencias** tras las cargas 21–24.

**Conservado en dev:** 7 productos `PRUEBA-CARGUE-CAM-…` (ids 6147–6153, inactivos, sin stock) y cargas 21–24.

## 10. Uso

1. **Admin › Cargues** (`http://127.0.0.1:8000/admin/cargues`) → **«Productos — Camisetas piel de durazno»**.
2. **Descargar plantilla (XLSX o CSV)**; borrar las filas `EJEMPLO-…`; una fila por variante (hoja «Instrucciones»).
3. Arrastrar el archivo; con la plantilla, las 13 columnas se relacionan solas. Elegir **«Qué hacer con este archivo»**.
4. **Validar archivo** → revisar la **Revisión por producto** (nuevos, variantes nuevas, sin cambio, bloqueados con motivo, opciones desconocidas, pendientes). Nada se aplicó aún.
5. **Ejecutar cargue** (con rechazadas, escribir su número exacto). Requiere el worker de la cola `cargues`.
6. **Resultado por producto**: «Abrir producto #…» y descargas (resultado completo, pendientes, no cargadas con valores originales y motivo).
7. En Catálogo completar descripciones, fotos y pendientes, y activar.

## 11. Limitaciones y pendientes reales

- **Niño**: la familia no tiene Talla y no existen tallas infantiles → bloqueado hasta corregir el catálogo (Catálogo › Familias / Atributos).
- **Descripciones**: no se piden ni se inventan; el formulario de Bagisto las exige al guardar desde el administrador.
- **Precios por grupo** e **imágenes**: fuera de este tipo.
- **Hallazgo previo, fuera de alcance (VentasAsesor):** Bagisto no llena `product_flat.parent_id` (304/304 variantes en dev con NULL), por eso `buscarProductos` de Nueva venta también lista **variantes** activas; al elegir una, `productoVendible` la rechaza. Afecta al catálogo actual; no se corrigió.
- El análisis lee el archivo completo una vez (adecuado para miles de variantes; rigen los límites del motor).
- Producción: nada desplegado.

## 12. Decisiones para Camilo (no bloquean el uso)

- P1. ¿Agregar Talla a la familia de niño y crear tallas infantiles (cuáles)?
- P2. ¿Valores comunes de manga, cuello y composición? Hoy vacío = pendiente.
- P3. ¿Acción por defecto «Crear inactivos» (actual) o «Crear y activar»?
- P4. ¿Borrar o conservar los 7 productos `PRUEBA-CARGUE-CAM-…` de dev?

> **Resueltas por Camilo (2026-11-04):** P3 → se mantiene «Crear inactivos» por defecto; P4 → se conservan los productos de prueba, inactivos. P1 y P2 se cierran en §13.

## 13. Cierre de uso real (2026-11-04, versión 2 del tipo)

### 13.1 Niño: el eje real es «grupo de edad», no `size` (corrige §1, §7.1 y §11)

- **Evidencia (BD de desarrollo):** la familia `Camisetas_nino_DURZ` tiene el atributo `grupo_de_edad_ninos` («grupo de edad (niños)», select, configurable, global) con 4 opciones: **2-4, 6-8, 10-12, 14-16** (traducción es idéntica). Los **5 configurables reales de niño** (106DURZ-NINO, 124DURZ-NINO, 110DURZ-NINO, 115DURZ-NINO y una copia) varían por **color + grupo_de_edad_ninos**, con variantes `…-NINO-2-4`, `-6-8`, `-10-12`, `-14-16`. En la primera entrega solo se buscó `size` y por eso se concluyó, erróneamente, que el niño no tenía tallas.
- **Decisión:** **no** se agrega `size` a la familia de niño (crearía un segundo eje incoherente con sus productos actuales; nada se modificó en la familia). El eje de talla pasa a ser **por público**: `publicos.nino.atributo_talla = grupo_de_edad_ninos`; tallas de niño = **las 4 opciones existentes** (texto exacto). Una talla de adulto («S») en un producto de niño se bloquea (`TALLA_NO_CORRESPONDE`) y una de niño en adulto también; ninguna opción se crea.
- El analizador, el creador (super atributos color + eje del público) y la extensión usan ese eje. P1 queda **cerrada sin pregunta pendiente**: la lista exacta está en el catálogo.

### 13.2 Descripciones y guardado desde el formulario

- **Inconsistencia comprobada en el código instalado** (`Admin/Http/Requests/ProductForm` + `Configurable::getTypeValidationRules`): guardar un producto desde el formulario de Bagisto exige, en el padre, `name`, `url_key`, **`short_description` y `description`** (atributos `is_required`), y en cada variante **`name`, `sku`, `price` y `weight`**. La versión 1 creaba productos sin descripciones: se podían importar pero no guardar.
- **Solución:** columnas opcionales **«Descripción corta»** y **«Descripción»** (map v2, `texto_largo`). Si vienen, se guardan tal cual en el padre y sus variantes. Si faltan (producto nuevo), se arman **solo con datos confirmados**: corta = `<p>nombre</p>`; descripción = `<p>nombre</p>` + lista con Público, Tela, Manga, Cuello, Composición y Marca **que existan** (texto de las opciones del catálogo realmente asignadas). Nunca gramaje, propiedades ni frases comerciales. Observación `DESCRIPCION_GENERADA`.
- Variante agregada a un producto existente: hereda las descripciones que el producto ya tiene.
- Precio y peso: no se inventan. Si faltan, el producto se crea, pero las observaciones `PRECIO_PENDIENTE` y `PESO_PENDIENTE` avisan que Bagisto los exige para guardar desde el formulario.
- Las filas del mismo producto deben coincidir también en las descripciones (`GRUPO_INCONSISTENTE`).

### 13.3 Estado de las variantes (ajuste encontrado al probar)

Con la v1, «Crear inactivos» dejaba inactivos el padre **y** las variantes: al activar el producto en el formulario seguía sin poderse vender. Ahora: **producto nuevo** → padre según la acción (inactivo por defecto) y **variantes con precio activas** (la visibilidad la decide el padre; sin precio, inactivas). **Variante agregada a un producto existente** (que puede estar a la venta) → activa solo con «Crear y activar», como antes.

### 13.4 Nueva venta: variantes sueltas (corrección en VentasAsesor)

- **Contraste con el código instalado:** `product_flat.parent_id` existe en la tabla pero en Bagisto 2.4 **nadie la escribe**: el indexador `Flat::updateOrCreate` solo pone `type`, `sku`, `attribute_family_id` y los atributos; el importador (`DataTransfer`) y `Configurable` guardan el vínculo en **`products.parent_id`**; la tienda excluye variantes con `visible_individually`. En dev, 304/304 variantes con `product_flat.parent_id` NULL. **No es corrupción** y no se rellenó nada.
- **Causa:** `CatalogoVentaService::buscarProductos` y `::coloresDeAcceso` filtraban «primer nivel» con `product_flat.parent_id IS NULL`, que también cumplen las variantes; estas se listaban y luego `productoVendible()` las rechazaba.
- **Corrección (solo la consulta propia):** ambas filtran por `products.parent_id IS NULL` (subconsulta `productosSinPadre()`). Diff de `CatalogoVentaService`: +13/−2 (sin reformatear líneas ajenas). Sin cambios en Webkul ni en la vista de Nueva venta (que otro hilo está modificando: cotizaciones).
- **Dónde se usa:** la pantalla de Nueva venta navega por **línea → color → matriz** (`coloresDeAcceso` + `matriz`); `buscarProductos` hoy no tiene llamadas en producción (solo pruebas). En `coloresDeAcceso` las variantes se colaban cuando su color no tenía un configurable propio en la línea (p. ej. la variante Blanco de un configurable Negro + Blanco) y aparecían como un «color» que luego fallaba.
- **Restricción de diseño de Nueva venta (no se cambió):** la pantalla toma **un producto por línea y color** (el primero que devuelve el servidor), conforme a la convención «un configurable por color y línea». Un producto nuevo en un color que la línea ya tiene se crea bien, pero no se puede elegir desde el selector de color. El cargue ahora lo **avisa** (observación `COLOR_YA_EN_LINEA`, no bloquea).

### 13.5 Valores comunes

- **Marca BYLOPEZ verificada**: está en 382 de las 383 camisetas reales del catálogo vigente.
- Manga Corta, cuello Redondo y composición 80 % poliéster / 20 % spandex también están en 382/383, pero son **datos físicos**: **no se fijan**. Quedan pendientes, salvo configuración **explícita** en el `.env` con el texto exacto de una opción existente: `CARGUES_PRODUCTOS_MANGA`, `CARGUES_PRODUCTOS_CUELLO`, `CARGUES_PRODUCTOS_COMPOSICION`. Si el texto configurado no existe, se bloquea con `OPCION_DESCONOCIDA`. P2 queda cerrada así.

### 13.6 Archivos de esta iteración

| Archivo | Cambio |
|---|---|
| `Cargues/src/Extensiones/ProductosCamisetas/AnalizadorCamisetas.php` | Eje de talla por público; descripciones en la consistencia del grupo; `DESCRIPCION_GENERADA`, `PESO_PENDIENTE`, `COLOR_YA_EN_LINEA`; mensajes. |
| `Cargues/src/Extensiones/ProductosCamisetas/CreadorCamisetas.php` | Super atributo y valor de la talla según el público; descripciones en padre y variantes. |
| `Cargues/src/Extensiones/ProductosCamisetas/ProductosCamisetasExtension.php` | Eje del público; descripciones (archivo, heredadas o armadas con datos confirmados); estado de variantes (§13.3). |
| `Cargues/src/Extensiones/ProductosCamisetas/CatalogoCamisetas.php` | `coloresOcupados()` (colores que ya tiene la línea). |
| `Cargues/src/Database/Procedures/sp_cg_productos_camisetas_v1.map.json` | +2 campos opcionales (Descripción corta, Descripción). Mismo procedimiento. |
| `Cargues/src/Config/cargues.php` | Niño: `atributo_talla`, tallas 2-4…14-16; manga, cuello y composición configurables por `.env`. |
| `Cargues/docs/plantillas/*` | 15 columnas, ejemplo de niño, instrucciones (tallas de niño, descripciones, peso). |
| `Cargues/tests/…ProductosCamisetasTest.php`, `helpersProductos.php` | Ajustes + 2 pruebas nuevas (niño, descripciones). |
| `VentasAsesor/src/Services/Venta/CatalogoVentaService.php` | `productosSinPadre()` en `buscarProductos` y `coloresDeAcceso`. |
| `VentasAsesor/tests/Feature/Fase03bCatalogoVentaTest.php` | Prueba nueva: buscar por el SKU del padre no devuelve variantes (con `product_flat.parent_id` NULL, como en Bagisto 2.4). |

### 13.7 Activación (dev)

Tipo 4 **versión 2 activa** (mismo `sp_cg_productos_camisetas_v1`, map con 15 campos, contrato sin hallazgos, intermedia con las 2 columnas nuevas). La activación canceló la carga abierta 24 (validada, nunca ejecutable); conserva su archivo y sus filas. Worker `cargues` reiniciado. **Incidente:** un worker reiniciado a las 21:31 dejó de consumir trabajos sin error visible (causa no determinada); reiniciado a las 21:42 y funcionando desde entonces.

### 13.8 Pruebas

**Pest (instancia aislada):** `ProductosCamisetasTest` **10/10** (153 aserciones; incluye niño y descripciones). VentasAsesor `Fase03bCatalogoVentaTest` **12/12** (con la prueba nueva). Las suites `Fase03gbDisponibilidadTest` + `Fase03gbNuevaVentaTest` (accesos rápidos) se corrieron y se resumen en §13.9. No se repitió la suite completa de Cargues: los cambios son del tipo y su complemento.

**En navegador (interfaz real, sesión de administrador headless):**

| Paso | Resultado |
|---|---|
| Carga 25 (archivo D) | H05 #6154 (hombre, descripciones armadas) y **N02 #6157 (niño, color × grupo de edad 2-4 / 6-8, descripción del archivo)** creados; N03 bloqueado: «La talla «S» no es una talla de niño (2-4, 6-8, 10-12, 14-16)». |
| Carga 28 (archivo E) | H06 #6160: padre inactivo y variantes con precio activas. |
| **Abrir → editar → guardar** H06 (#6160) en Catálogo › Productos | Formulario sin errores de validación; guardado (redirección al listado), estado activo, 2 variantes intactas. |
| Categorías al guardar | Guardar **antes** de que cargue el árbol de categorías (asíncrono) envía la lista vacía y Bagisto las quita (ocurrió en mi prueba con H05/H06). Esperando al árbol, el guardado **conserva 7,8**. Se restauraron desde el formulario. Comportamiento de Bagisto, no del cargue. |
| Carga 29 (archivo F) | H07 #6163 (Terracota, color libre en la línea) y H08 #6166 (Negro: aviso `COLOR_YA_EN_LINEA` solo en H08). |
| H07 activado desde el formulario y Nueva venta (cliente de prueba #16, línea «Camiseta caballero») | Aparece el chip **«Terracota · SIN STOCK»** (antes inexistente en la línea), deshabilitado como cualquier color sin stock: no se inventa stock. |
| Carga 30 (F repetido) | 3 sin cambio, 0 creados; 21 productos de prueba antes y después; el estado no cambió. |
| Final | Los 7 padres de prueba quedaron **inactivos** (desactivados desde el formulario). |

**Por servicio / endpoint / BD (no es navegación de pantalla):**
- Matriz de Nueva venta (`GET …/nueva-venta/productos/{id}/matriz`, el mismo endpoint de «Ver tallas», con la sesión del navegador): H07 → 200, 2 variantes activas, precio 8.500, stock 0; H08 (inactivo) → 422 `producto_no_vendible`.
- `coloresDeAcceso('camiseta_caballero')`: devuelve H06 junto al Negro real cuando está activo; solo configurables, sin variantes.
- BD: N02 con super atributos `color` + `grupo_de_edad_ninos` y categorías 9,10; descripciones guardadas en padre y variantes.
- **Huella** (comparada contra la tomada antes de la carga 25): sin cambios en pedidos, ítems, facturas, envíos, reembolsos, reservas, carritos, clientes, precios por grupo, cartera, comisiones, logística ni productos existentes. Diferencias explicadas:
  - `bylopez_va_borradores_venta`: borradores 113–115, creados por mi prueba de Nueva venta con carritos vacíos y **descartados** con el servicio de la aplicación.
  - `product_inventories`: 6 filas qty 0 de las variantes de prueba, creadas por el **formulario de Bagisto** al guardar; el inventario no relacionado con las pruebas no cambió (304 filas).
  - `bylopez_va_cotizaciones`: tabla nueva de **otro hilo**, vacía y no tocada.
- Cargas 26 y 27: intentos interrumpidos del navegador, **cancelados**.

**Conservado en dev:** 21 productos `PRUEBA-CARGUE-CAM-…` (7 padres: H01, D01, H05, N02, H06, H07, H08), todos inactivos y sin stock; cargas 21–30.

### 13.9 Pendientes reales

- Precios por grupo (mayoristas) e imágenes: fuera del tipo.
- Nueva venta: un producto por línea y color (diseño vigente; el cargue avisa). Elegir productos sin stock no es posible (correcto).
- Categorías: esperar a que cargue el árbol antes de guardar en el formulario de Bagisto (lento en el servidor de desarrollo).
- Resultado de `Fase03gb*`: ver §13.10.

### 13.10 Regresión de Nueva venta (VentasAsesor)

`Fase03gbDisponibilidadTest` + `Fase03gbNuevaVentaTest`: **44/49**. Pasan todas las de colores, accesos rápidos, matriz, lotes y disponibilidad (las que usan `coloresDeAcceso`). Los 5 fallos **no** se deben a este cambio:
- 4 de **entrega** (`METODO_NO_DISPONIBLE` en «domicilio local» / «editar líneas»): con `CatalogoVentaService` devuelto temporalmente a `HEAD` (sin la corrección), «editar líneas: el mismo método se reaplica…» **falla igual**. Es un fallo previo de entrega/envío local en la base de pruebas, fuera de este alcance.
- 1 de **tiempo** («sin N+1… menos tiempo»): 2 393 ms frente a un límite de 700 ms; la suite tardó 50 min en una base compartida y cargada; el número de consultas sí cumplió.
