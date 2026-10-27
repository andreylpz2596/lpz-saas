# 04A_STOCK_VARIANTE_DISENO.md — Diseño: lectura de stock y atributos de variante

## 0. Estado de este documento

**APROBADA** (fecha de diseño 2026-09-04, fecha de aprobación
2026-09-04, con 2 ajustes finales incorporados en la implementación de la
Fase 04B — ver `04B_STOCK_VARIANTE_IMPLEMENTACION.md`). Toda la evidencia
de este documento se obtuvo con consultas de **solo lectura** (`SELECT`,
vía `php artisan tinker`) contra la base de datos real y lectura de
código de `packages/Webkul/Product` y `packages/Webkul/Core` (sin
modificarlo).

**Decisiones 04A aprobadas sin cambios:**
1. Nombre del servicio: `VarianteConsultaService`.
2. Stock correcto para Bodega: `product_inventory_indices.qty` (vendible/
   indexado), nunca `product_inventories.qty` (físico).
3. Color y talla se obtienen estructuradamente vía
   `product_super_attributes → product_attribute_values → attribute_options`,
   nunca parseando `producto_nombre`.
4. El "segundo eje" (talla) se resuelve dinámicamente por `attribute_code`,
   nunca con un `attribute_id` fijo — debe soportar tanto `size`/Talla
   como `grupo_de_edad_ninos`/Grupo de edad (ambos casos reales
   confirmados en el catálogo).
5. `admin_name` es aceptable como label para esta herramienta interna;
   sin traducción por locale en esta fase.
6. `null` (dato no disponible) y `0` (stock confirmado en cero) tienen
   semánticas distintas — nunca se convierte automáticamente uno en otro.

**Ajustes finales aprobados (incorporados en 04B):**
- **Ajuste 1 — Channel:** el contrato acepta `?int $channelId = null`
  (`porProductIds(iterable $productIds, ?int $channelId = null)`). Si es
  `null`, se resuelve con `core()->getDefaultChannel()` (igual que
  proponía 04A), pero el servicio ya no queda acoplado a un único canal —
  cualquier llamador puede pedir el stock de un canal específico sin que
  la UI (03C) conozca esta lógica.
- **Ajuste 2 — Aislamiento total de Webkul:** todas las consultas
  directas a `products`, `product_flat`, `product_super_attributes`,
  `product_attribute_values`, `attribute_options` y
  `product_inventory_indices` quedan encapsuladas exclusivamente dentro
  de `VarianteConsultaService`. Verificado: ningún otro archivo del
  paquete (Controller, Blade, Vue, Request, otro Service) contiene estas
  tablas.

Implementación completa, evidencia de queries reales, tests y resultados
exactos: `04B_STOCK_VARIANTE_IMPLEMENTACION.md`.

---

## 1. Objetivo (sin cambios respecto al alcance aprobado)

Diseñar cómo `ByLopez\CorteConfeccion` obtendrá, en solo lectura y en
lote, para cada `product_id` simple: SKU, nombre, color, talla y stock
vendible — sin parsear `producto_nombre` y sin N+1 — como contrato que
consumirá la futura Fase 03C.

---

## 2. Datos reales investigados (evidencia)

Producto de muestra usado como hilo conductor (ya conocido de la Fase 01
como ejemplo real del catálogo):

```
product_id = 173   (simple)
parent_id  = 170   (configurable)
sku        = 115DURZ-H-L
attribute_family_id = 3  (código: Camiseta_Hombre_DURZ)
```

### 2.1 Ejes de variación reales (`product_super_attributes` del padre 170)

```sql
SELECT * FROM product_super_attributes WHERE product_id = 170;
```

| attribute_id | code | type | admin_name |
|---|---|---|---|
| 23 | `color` | select | Color |
| 24 | `size` | select | Talla |

### 2.2 Valor almacenado para el producto 173 (`product_attribute_values`)

```sql
SELECT * FROM product_attribute_values WHERE product_id = 173 AND attribute_id IN (23,24);
```

| id | product_id | attribute_id | integer_value | locale | channel |
|---|---|---|---|---|---|
| 4843 | 173 | 23 (color) | **2** | NULL | NULL |
| 4844 | 173 | 24 (talla) | **8** | NULL | NULL |

**Confirmado:** el valor NO es el texto visible — es `integer_value`
apuntando a `attribute_options.id`. `locale`/`channel` son `NULL` en
estos registros (el valor no varía por canal/idioma en este catálogo,
aunque la columna lo permitiría).

### 2.3 Resolución del label (`attribute_options` + `attribute_option_translations`)

```sql
SELECT * FROM attribute_options WHERE id IN (2, 8);
SELECT * FROM attribute_option_translations WHERE attribute_option_id IN (2, 8);
```

| option_id | `admin_name` | traducción `locale='es'` |
|---|---|---|
| 2 | `Verde Cali` | `Verde Cali` |
| 8 | `L` | `L` |

**Confirmado:** en este catálogo `admin_name` y la traducción `es`
coinciden siempre en la muestra revisada. `AttributeOption` es un modelo
`TranslatableModel` (`$translatedAttributes = ['label']`) — el label
"oficial" pasa por la tabla de traducciones, pero `admin_name` es un
valor plano, no traducido, siempre presente, pensado precisamente para
uso administrativo/interno (a diferencia del `label` de storefront). Ver
recomendación en §6.

### 2.4 Nombre — simple vs. configurable padre (`product_flat`)

```sql
SELECT name FROM product_flat WHERE product_id = 173; -- simple
SELECT name FROM product_flat WHERE product_id = 170; -- configurable padre
```

| Nivel | `name` |
|---|---|
| Simple (173) | `Camiseta Piel Durazno Verde Calì Hombre L` |
| Configurable padre (170) | `Camiseta Piel Durazno Verde Cali Hombre` |

**Hallazgo importante:** el nombre del **configurable padre YA incluye
el color** ("Verde Cali"). No existe, ni en el simple ni en el padre,
un nombre "base" limpio sin color (ej. no existe en ningún campo
estructurado un valor equivalente a solo "Camiseta Piel Durazno
Hombre"). El padre es estrictamente mejor que el simple como "nombre"
(no incluye la talla), pero **sigue incluyendo el color**. No existe
alternativa estructurada más limpia sin modificar el catálogo. Ver
decisión en §5.

### 2.5 Edge case real descubierto: no todos los configurables usan el mismo "segundo eje"

```sql
SELECT DISTINCT attribute_id FROM product_super_attributes;
-- => 23, 24, 31
SELECT * FROM attributes WHERE id = 31;
-- => code: grupo_de_edad_ninos, admin_name: "grupo de edad (niños)", type: select
```

| attribute_id | Nº de configurables que lo usan |
|---|---|
| 23 (`color`) | 77 (el 100% de los configurables) |
| 24 (`size` / Talla) | 73 |
| 31 (`grupo_de_edad_ninos`) | 4 |

Los 4 configurables que usan `attribute_id=31` usan **`color` + `grupo
de edad (niños)`** como sus dos ejes — **no** usan `size`/Talla en
absoluto. Confirmado consultando sus `product_super_attributes`
completos (los 4 muestran exactamente `[23, 31]`, nunca `[23, 24]`).

**Esto invalida cualquier diseño que asuma "el segundo eje siempre es
`attribute_id=24` (Talla)".** Es una línea de producto real (infantil)
con una semántica de variación distinta. El diseño (§8) debe resolver el
"segundo eje" dinámicamente por producto, no con un ID fijo.

---

## 3. Color y talla estructurados — respuesta definitiva

**Sí existen completamente estructurados**, vía:

```
products (simple, product_id)
  → product_super_attributes (del parent_id) → qué attribute_id es cada eje
  → product_attribute_values (product_id del simple + attribute_id) → integer_value
  → attribute_options (integer_value) → admin_name (o attribute_option_translations.label)
```

`color` se identifica **por código de atributo** (`attributes.code = 'color'`),
no por posición ni por convención de nombre de columna — es la única
forma robusta, porque el "segundo eje" varía (§2.5).

**No asumir que el valor almacenado es el texto visible** — queda
confirmado y documentado explícitamente: siempre pasa por
`attribute_options`.

---

## 4. Nombre base del producto — respuesta definitiva

**No existe un nombre base limpio (sin color) en ningún campo
estructurado de este catálogo** (§2.4). Alternativas evaluadas:

| Alternativa | Evaluación |
|---|---|
| Parsear `producto_nombre` con strings | **Descartado explícitamente** por decisión del usuario, y además sería frágil (la muestra ya tiene una inconsistencia de tildes: "Calì" en el simple vs. "Cali" en el padre). |
| Nombre del `configurable` padre | **Mejor alternativa estructurada disponible.** No requiere parseo, es un campo real (`product_flat.name` del padre), y ya excluye la talla (que sí "ensucia" el nombre del simple). Sigue incluyendo el color, pero el color de todos modos se muestra aparte como su propio dato estructurado (§3) — la redundancia visual (nombre largo + chip de color) es aceptable y mucho más segura que inventar un parser. |
| SKU del padre como "familia" (`115DURZ-H`) | Útil como agrupador técnico secundario, no como "nombre" legible para Bodega. |

**Decisión de diseño:** el campo `nombre` del contrato (§8) será el
`product_flat.name` del **configurable padre**, resuelto en el locale
activo (§11). El wireframe de `03B_FRONTEND_DISTRIBUCION.md` §5 mostraba
un ejemplo simplificado ("Camiseta Hombre"); con datos reales el nombre
será más largo y seguirá incluyendo el color — esto no invalida el
diseño visual (el color sigue mostrándose también como chip separado
para escaneo rápido), solo ajusta la expectativa de longitud del texto.

---

## 5. Stock vendible — respuesta definitiva

### 5.1 Qué hace realmente `Product::totalQuantity()`

Cadena confirmada en código (`packages/Webkul/Product/src/Type/AbstractType.php`):

```
Product::totalQuantity()
  → AbstractType::totalQuantity()
  → AbstractType::getInventoryIndex()
      → $this->product->inventory_indices
            ->where('channel_id', core()->getCurrentChannel()->id)
            ->first()
  → devuelve ->qty
```

### 5.2 Qué es exactamente ese `qty` (confirmado en el indexador real)

`packages/Webkul/Product/src/Helpers/Indexers/Inventory.php::getQuantity()`:

```
qty = SUM(product_inventories.qty  -- solo de inventory_sources ACTIVAS del canal)
      - ordered_inventories.qty    -- cantidad ya reservada/comprometida en ese canal
```

Es decir: `product_inventory_indices.qty` **ya es el stock vendible
(disponible para prometer), no el stock físico bruto** — ya descuenta lo
comprometido. Es exactamente el "inventario vendible real" que pide
CONFECCION_MASTER.md §11.

**Importante:** es un **valor indexado/precalculado** por un job de
reindexación (`reindexFull()`/`reindexBatch()`), no calculado en vivo en
cada lectura. Para esta pantalla (una herramienta de decisión, no un
checkout) esto es aceptable — el propio master doc ya trata el stock
como dato informativo, no como fuente de verdad transaccional.

### 5.3 Qué pasa con productos `configurable`

`reindexFull()` filtra explícitamente
`whereIn('type', ['simple', 'virtual'])` — **los productos `configurable`
nunca tienen fila en `product_inventory_indices`**. Confirma, con
evidencia adicional, por qué `product_id` debe ser siempre `simple`
(Decisión 2, Fase 02): un `configurable` no solo mezclaría color/talla
en el cálculo de disponibilidad — **tampoco tiene stock indexado en
absoluto.**

### 5.4 Stock físico vs. stock vendible — diferencia confirmada con datos reales

| | Tabla | Qué mide | Ejemplo real (producto 173) |
|---|---|---|---|
| Físico | `product_inventories` (por `inventory_source_id`) | Cantidad física por almacén, sin descontar reservas | `qty = 130` (fuente 1) |
| Vendible/indexado | `product_inventory_indices` (por `channel_id`) | Físico de fuentes activas del canal **menos** lo ya reservado/ordenado | `qty = 130` (coincide porque no había reservas activas al momento de la consulta) |

**Decisión:** "Stock actual" en la UI (03B) debe leer
**`product_inventory_indices.qty`** (vendible), nunca
`product_inventories.qty` (físico) — son iguales solo quando no hay
reservas activas, y coincidir hoy no garantiza que sigan coincidiendo
mañana.

---

## 6. Channel — respuesta definitiva

### 6.1 Riesgo real confirmado en `core()->getCurrentChannel()`

Código real (`packages/Webkul/Core/src/Core.php::getCurrentChannel()`):

```php
$hostname = request()->getHttpHost();
$this->currentChannel = $this->channelRepository
    ->findWhereIn('hostname', [$hostname, "http://$hostname", "https://$hostname"])
    ->first();

if (! $this->currentChannel) {
    $this->currentChannel = $this->channelRepository->first();
}
```

**Depende del Host HTTP de la request actual.** El Admin de Bagisto no
necesariamente se sirve desde el mismo host configurado como
`hostname` de un canal de storefront — es semánticamente incorrecto que
una pantalla del Admin dependa de a qué dominio le llegó la petición
para decidir "de qué canal es el stock". Hoy **funciona por accidente**:
como solo existe 1 canal, el `->first()` de respaldo siempre cae en el
canal correcto sin importar el host.

### 6.2 Alternativa correcta, ya existente en Bagisto y verificada

`Core::getDefaultChannel()`:

```php
$this->defaultChannel = $this->channelRepository
    ->findOneByField('code', config('app.channel'));
// fallback: ->first()
```

**No depende de la request HTTP** — depende de `config('app.channel')`,
un valor de configuración de la aplicación, determinístico. Verificado
en este entorno:

```
config('app.channel')              = 'default'
core()->getDefaultChannel()->code  = 'default'
core()->getDefaultChannel()->id    = 1
```

**Decisión:** el nuevo servicio debe resolver el canal con
`core()->getDefaultChannel()->id`, nunca `getCurrentChannel()`. No es
sobre-diseño multi-canal — es simplemente usar la fuente correcta que
Bagisto ya ofrece para "el canal de la tienda" en un contexto que no es
navegación de storefront.

---

## 7. Inventory sources — respuesta definitiva

- Instalación real: **1 sola fuente de inventario activa** (confirmado
  en la Fase 01, sin cambios).
- `product_inventory_indices.qty` **ya sale sumado** por todas las
  fuentes activas del canal (§5.2) — no hace falta que nuestro servicio
  sume nada por fuente.
- **Decisión confirmada:** mostrar **un solo** "Stock actual" (el índice
  ya agregado). No se diseña stock por almacén — no hay necesidad
  funcional real hoy, y el propio master doc no lo pide.

---

## 8. Estrategia batch (sin N+1) — respuesta definitiva

Para una lista `product_ids = [p1, p2, ..., pN]`, la resolución completa
requiere un número **fijo y pequeño** de consultas, independiente de N:

```
1. products + product_flat (locale actual)     WHERE id IN (product_ids)
   → sku, nombre_simple (no se usa como "nombre" final, ver §4), parent_id, type

2. product_flat (locale actual)                WHERE product_id IN (parent_ids distintos)
   → nombre del configurable padre (§4)

3. product_super_attributes                    WHERE product_id IN (parent_ids distintos)
   → qué attribute_id es "color" (code='color') y cuál es "el otro eje" por cada padre (§2.5)

4. attributes                                  WHERE id IN (attribute_ids distintos hallados en 3)
   → code/admin_name de cada eje (para saber cuál es 'color' y cómo etiquetar el otro)

5. product_attribute_values                    WHERE product_id IN (product_ids)
                                                  AND attribute_id IN (attribute_ids de 3)
   → integer_value (option_id) por producto y eje

6. attribute_options (+ admin_name)            WHERE id IN (option_ids distintos hallados en 5)
   → labels de color/talla

7. product_inventory_indices                   WHERE product_id IN (product_ids)
                                                  AND channel_id = core()->getDefaultChannel()->id
   → stock vendible
```

**Objetivo: 6-7 consultas SQL en total**, sin importar si N=3 o N=50 —
ninguna dentro de un `foreach` por producto. Todo el ensamblaje final
(unir estas colecciones por `product_id`/`attribute_id`/`option_id`) se
hace en PHP con `Collection::keyBy()`/`groupBy()`, exactamente el mismo
patrón que ya usa
`DisponibilidadConfeccionService::porOrdenCorte()` para resolver todas
las filas de una orden de corte de una sola vez — no se inventa un
patrón nuevo, se replica el ya usado y ya probado en el módulo.

No se encontró en Bagisto un repositorio/helper único que ya resuelva
esto combinado (ver comparación de alternativas, §13) — de ahí que el
diseño sea un servicio propio con consultas dirigidas.

---

## 9. Contrato propuesto del futuro servicio

**Nombre propuesto: `VarianteConsultaService`** (no `StockVarianteReadService`).
Justificación del cambio de nombre: el servicio resuelve **más que
stock** — también SKU/nombre/color/talla — así que un nombre centrado
solo en "Stock" subrepresentaría su responsabilidad real. Sigue el
patrón de nombres en español ya usado en el módulo
(`DisponibilidadConfeccionService`, `TallerService`), a diferencia de
`ProductLookupService` (más antiguo, en inglés) — se prefiere consistencia
con el patrón más reciente del paquete.

**No reemplaza a `ProductLookupService`.** Son responsabilidades
distintas: `ProductLookupService::snapshot()` captura un valor
**inmutable en el momento de creación** de un detalle (`OrdenCorteDetalle`,
`RecepcionCorteDetalle`, `OrdenConfeccionDetalle`) — sigue existiendo
igual. `VarianteConsultaService` es de **solo lectura para mostrar en
pantalla en el momento de decidir la distribución** — nunca se usa para
persistir nada.

**Firma propuesta:**

```php
VarianteConsultaService::porProductIds(iterable $productIds): Collection
// Collection<int, array> indexada por product_id
```

**Forma de cada elemento (propuesta, no congelada):**

```php
[
    'product_id'     => 173,
    'sku'            => '115DURZ-H-L',
    'nombre'         => 'Camiseta Piel Durazno Verde Cali Hombre', // del configurable padre, ver §4
    'color'          => 'Verde Cali',   // null si no aplica/no definido
    'talla'          => 'L',            // null si no aplica/no definido
    'talla_etiqueta' => 'Talla',        // admin_name del atributo usado como "segundo eje"
                                         // normalmente "Talla", pero ver el caso real de
                                         // "grupo de edad (niños)" (§2.5) — la UI debe
                                         // rotular con este valor, no asumir siempre "Talla"
    'stock_actual'   => 130,            // int|null — null = dato no disponible, 0 = confirmado sin stock (§10)
]
```

El campo `talla_etiqueta` es la respuesta directa al edge case real de
§2.5: sin él, la UI mostraría incorrectamente "Talla: 2-4 años" para las
4 variantes infantiles, cuando en realidad ese valor es un grupo de
edad, no una talla.

**El contrato:**
- Oculta por completo las tablas de Webkul — Blade/Vue/Controller nunca
  tocan `products`, `attributes`, `attribute_options`, etc.
- Es de solo lectura — no escribe nada, en ninguna tabla.
- Resuelve N variantes en una sola llamada (§8).
- `stock_actual` es el único dato "vivo" de Bagisto; todo lo demás
  (`sku`, `nombre`, `color`, `talla`) es prácticamente estático salvo que
  el catálogo cambie.

---

## 10. Fallbacks ante datos incompletos

| Caso | `sku`/`nombre` | `color`/`talla` | `stock_actual` |
|---|---|---|---|
| `product_id` no existe en `products` | `null` | `null` | `null` |
| Producto inactivo (`status=0`) | Se muestra igual (existió al momento de la recepción) | Se muestra igual si está definido | Se muestra igual (puede ser 0 o el último valor indexado) |
| Simple sin `parent_id` (huérfano, sin configurable) | Se muestra (`sku`/nombre del propio simple, no hay padre del que tomar nombre "limpio") | `null` (no hay `product_super_attributes` que resolver) | Se resuelve igual (el índice de inventario no depende de tener padre) |
| Atributo color/talla no definido para ese producto | — | `null` | — |
| `option_id` sin `admin_name`/traducción | — | `"No definido"` (fallback explícito, nunca cadena vacía silenciosa) | — |
| Sin fila en `product_inventory_indices` para ese canal | — | — | `null` |

**Regla explícita, no ambigua:** `null` significa **"dato no
disponible"** (no se pudo resolver); `0` significa **"se resolvió, y el
valor confirmado es cero"**. Nunca se convierte un `null` en `0` dentro
del servicio — esa distinción se preserva hasta la UI (que ya la maneja
en 03B §10.3: `—` para no disponible, número plano para valores
confirmados incluyendo cero).

---

## 11. Locale

- `product_flat` ya se filtra por `locale` en el patrón existente del
  módulo (`ProductLookupService` ya hace
  `where('product_flat.locale', app()->getLocale())`) — el nuevo
  servicio replica exactamente ese mismo patrón, sin inventar uno nuevo.
- **Decisión para labels de atributo (color/talla):** usar
  `attribute_options.admin_name` en vez de la traducción
  (`attribute_option_translations.label`). Motivo: `admin_name` es un
  valor único, no dependiente de `app()->getLocale()`, pensado para uso
  administrativo — evita que el nombre de un color cambie según el
  idioma configurado para el panel de un administrador puntual, lo cual
  sería una inconsistencia rara para una herramienta operativa interna
  de Bodega (no una vitrina multi-idioma). En la muestra real revisada,
  `admin_name` y la traducción `es` coincidían siempre, así que esta
  decisión no cambia el resultado visible con los datos actuales — solo
  lo hace robusto ante configuraciones de locale distintas a futuro.
- No se asume que el locale del Admin sea siempre `'es'` — se sigue
  resolviendo con `app()->getLocale()` para `product_flat` (nombre), tal
  como ya hace el módulo.

---

## 12. Comparación de alternativas

| | A. `Product`/`totalQuantity()` por producto | B. `ProductRepository` + eager loading | **C. Consultas dirigidas propias (ByLopez)** | D. Otra API oficial de Bagisto |
|---|---|---|---|---|
| N+1 | Alto si se llama en `foreach` (una consulta de índice por producto como mínimo) | Bajo para las relaciones cargadas, pero sigue trayendo el modelo `Product` completo (imágenes, SEO, precios...) sin necesidad | **Ninguno** — 6-7 consultas fijas (§8) | No se encontró una API combinada ya construida |
| Riesgo de canal | Alto — usa `getCurrentChannel()` internamente, sin forma de inyectar otro canal | Igual que A si se sigue llamando `totalQuantity()` sobre los modelos cargados | **Ninguno** — se fija `channel_id` explícitamente (`getDefaultChannel()`) | N/A |
| Acoplamiento a Webkul | Alto (depende del comportamiento interno de `AbstractType`) | Medio | Medio, pero **acoplado al esquema, no al comportamiento** — más fácil de razonar y testear | N/A |
| Resuelve color/talla | No (no es su propósito) | Parcialmente (trae `attribute_values`, pero hay que resolver el "eje" correcto en PHP igual, ver §2.5) | Sí, completo, incluyendo el edge case de §2.5 | N/A |
| Peso/rendimiento | Malo en lote (carga el modelo completo por producto) | Medio (carga más de lo necesario) | **Bueno** — solo las columnas necesarias, vía `DB::table` | N/A |
| Facilidad de test | Difícil de aislar (efectos globales de `core()`) | Media | **Alta** — mismo patrón ya usado y ya testeado en `ProductLookupService`/`DisponibilidadConfeccionService` | N/A |
| ¿Existe ya construida? | Sí, pero no resuelve el caso completo | Sí, parcialmente | No — hay que construirla (Fase 04B) | No se encontró |

**Recomendación explícita: Opción C** (consultas dirigidas propias,
`DB::table`, sin cargar modelos Eloquent completos de `Product`). Es la
única que resuelve **simultáneamente** los tres requisitos obligatorios:
cero N+1, canal correcto y explícito, y el edge case real de atributos
variables (§2.5) — ninguna de las otras tres lo logra sin trabajo
adicional equivalente a construir C de todos modos.

---

## 13. Stock proyectado — dónde calcularlo

**No se persiste, no lo calcula este servicio.** `VarianteConsultaService`
solo devuelve `stock_actual`. El **stock proyectado**
(`stock_actual + cantidad que el usuario está por entregar`) depende de
un valor que vive en el estado del formulario de 03C (lo que Bodega está
escribiendo en "Entregar ahora" en ese momento) — por eso debe calcularse
en el **frontend** (propiedad computada del componente Vue de 03C), no
en el backend: reaccionar en vivo mientras el usuario edita una cantidad
no puede depender de un round-trip al servidor. El backend nunca conoce
ni necesita conocer el "stock proyectado" como concepto.

---

## 14. Archivos que eventualmente cambiarían en 04B (referencia, no creados ahora)

| Archivo | Acción |
|---|---|
| `src/Services/VarianteConsultaService.php` | Crear |
| `tests/Feature/VarianteConsultaServiceTest.php` (o `Unit/`) | Crear |
| `src/Http/Controllers/Admin/OrdenConfeccionController.php` | Modificar en 03C (no en 04B) para inyectar el nuevo servicio al armar el view-model de `create()` |
| `src/Services/ProductLookupService.php` | **Sin cambios** — responsabilidad distinta (§9) |
| `packages/Webkul/*` | Ninguno — solo lectura |

---

## 15. Tests necesarios (para 04B, diseño de qué debe cubrirse)

- Resolver color/talla para una variante con ambos atributos definidos
  (caso feliz, ejemplo real del §2).
- Resolver el caso de la línea infantil (`grupo_de_edad_ninos` en vez de
  `size`) y confirmar que `talla_etiqueta` refleja el admin_name correcto
  en vez de asumir "Talla".
- Producto sin atributo color/talla definido → `null`, no excepción.
- `product_id` inexistente dentro de una lista mixta → esa entrada
  devuelve `null`s, el resto de la lista se resuelve igual.
- Producto sin fila de inventario indexado → `stock_actual = null`,
  distinguible de un índice real en `0`.
- **Conteo de queries fijo**: pasar 3 product_ids y 30 product_ids y
  verificar que el número de consultas SQL ejecutadas es el mismo en
  ambos casos (aserción de conteo de queries, ej. con
  `DB::listen()`/`assertQueryCount` según se disponga en el proyecto) —
  es la prueba concreta de que no hay N+1.
- Confirmar que el canal usado es `core()->getDefaultChannel()->id` y no
  el resultado de `getCurrentChannel()` (test que cambie el host de la
  request simulada y confirme que el resultado no varía).

---

## 16. Riesgos

- **El nombre mostrado seguirá siendo largo y con el color incluido**
  (§4) — no se logra la limpieza visual ideal del wireframe original de
  03B sin parsear texto, lo cual queda descartado por decisión expresa.
  Riesgo puramente visual, no de datos.
- **El "segundo eje" no siempre es Talla** (§2.5) — si 04B no implementa
  `talla_etiqueta` correctamente, la UI mostraría una etiqueta incorrecta
  para las 4 variantes de la línea infantil. Mitigado por el diseño de
  contrato (§9), pero requiere que 03C efectivamente use
  `talla_etiqueta` en vez de un texto fijo `"Talla"`.
- **`product_inventory_indices` es un valor indexado, no en vivo** — si
  el job de reindexación de Bagisto no corre con la frecuencia esperada,
  "Stock actual" podría estar desactualizado. Es un riesgo heredado del
  propio Bagisto, no introducido por este diseño; se documenta para que
  no se interprete como un valor transaccionalmente exacto.
- **Dependencia de `admin_name` en vez de `label` traducido** (§11) — si
  en el futuro se requiere de verdad soporte multi-idioma real para
  Bodega (poco probable dado que es una herramienta interna), habría que
  revisar esta decisión.

---

## 17. Matriz de decisiones

| Decisión | Alternativas | Recomendación | Motivo |
|---|---|---|---|
| Fuente de color/talla | (a) parsear `producto_nombre`; (b) atributos estructurados vía `product_attribute_values`/`attribute_options` | **(b)** | Confirmado con datos reales; (a) además sería frágil (inconsistencia de tildes ya observada) |
| "Segundo eje" (talla) | (a) asumir siempre `attribute_id` fijo; (b) resolver dinámicamente por `product_super_attributes` del padre | **(b)** | Evidencia real: 4 configurables usan un eje distinto (`grupo_de_edad_ninos`) |
| Nombre del producto | (a) nombre del simple; (b) nombre del configurable padre; (c) parser de texto | **(b)** | Excluye la talla sin parsear; (c) descartado por decisión expresa |
| Label de atributo (color/talla) | (a) `attribute_option_translations.label` (traducido); (b) `attribute_options.admin_name` | **(b)** | Valor único, no depende del locale del admin que esté trabajando, coincide con (a) en los datos reales |
| Stock a mostrar | (a) `product_inventories` (físico); (b) `product_inventory_indices` (vendible) | **(b)** | Es lo que pide CONFECCION_MASTER.md §11; ya descuenta reservas |
| Resolución de canal | (a) `core()->getCurrentChannel()`; (b) `core()->getDefaultChannel()` | **(b)** | (a) depende del Host HTTP de la request, semánticamente incorrecto para el Admin; (b) es determinístico vía config |
| Estrategia de acceso a datos | (a) `Product`/`totalQuantity()` por producto; (b) `ProductRepository` + eager loading; (c) consultas dirigidas propias; (d) API oficial combinada | **(c)** | Única que resuelve N+1, canal explícito y el edge case de atributos a la vez (§12) |
| Nombre del servicio | `StockVarianteReadService` (tentativo original) vs. `VarianteConsultaService` | **`VarianteConsultaService`** | Su responsabilidad incluye más que stock (también atributos); nombre debe reflejarlo |
| Relación con `ProductLookupService` | Reemplazar vs. convivir | **Convivir** (responsabilidades distintas: snapshot inmutable en escritura vs. lectura para mostrar) | Evita tocar código que ya funciona sin necesidad |
| Cálculo de stock proyectado | Backend vs. frontend | **Frontend** | Depende de un valor que el usuario edita en vivo (Entregar ahora), sin round-trip |

---

## 18. Respuesta a la pregunta de aprobación (§16 del pedido)

> Para una lista de N `product_id` simples, ¿cómo obtendremos en batch
> product_id, SKU, nombre, color, talla y stock vendible, sin parsear
> nombres y sin N+1?

**Respuesta sin ambigüedad:** con 6-7 consultas SQL fijas (§8), cada una
filtrando por `WHERE ... IN (...)` sobre los IDs relevantes (nunca dentro
de un `foreach` por producto), ensambladas en PHP por `product_id` — el
mismo patrón de "resolver todo de una orden de corte en una sola pasada"
que ya usa y ya prueba `DisponibilidadConfeccionService`. Color y talla
salen de atributos estructurados (`product_attribute_values` →
`attribute_options.admin_name`), resolviendo dinámicamente cuál
`attribute_id` es el "segundo eje" por producto (no asumido fijo, por el
edge case real de §2.5). El nombre sale del `product_flat.name` del
configurable padre (mejor alternativa estructurada disponible, sin
parseo). El stock sale de `product_inventory_indices`, filtrado por el
canal resuelto de forma determinística con `core()->getDefaultChannel()`.
