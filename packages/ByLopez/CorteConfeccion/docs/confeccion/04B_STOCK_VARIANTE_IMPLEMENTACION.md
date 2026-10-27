# 04B_STOCK_VARIANTE_IMPLEMENTACION.md — Implementación: `VarianteConsultaService`

## 0. Estado de este documento

**COMPLETADA / PENDIENTE DE VALIDACIÓN.** Fecha: 2026-09-04. Implementa
el diseño aprobado en `04A_STOCK_VARIANTE_DISENO.md` (con los 2 ajustes
finales de esa aprobación: `channelId` opcional en el contrato, y
aislamiento total de las tablas de Webkul dentro de este único Service).
**No implementa 03C.** No se modificó `packages/Webkul/`.

---

## 1. Archivos creados

- `src/Services/VarianteConsultaService.php`
- `tests/Feature/VarianteConsultaServiceTest.php` (15 tests)

Ningún archivo existente fue modificado (no se tocó `ProductLookupService`,
ni ningún Controller, Request, ruta o Blade — tal como exigía el alcance).

---

## 2. Contrato implementado

```php
VarianteConsultaService::porProductIds(iterable $productIds, ?int $channelId = null): Collection
```

- **Entrada:** cualquier `iterable` de product_id (se deduplican, se
  descartan valores `<= 0`/no numéricos internamente).
- **`$channelId`:** opcional. `null` → se resuelve con
  `core()->getDefaultChannel()->id`. Si se pasa un valor, se usa
  exactamente ese canal para la consulta de stock — el servicio nunca
  queda acoplado a un único canal (Ajuste 1 de la aprobación).
- **Salida:** `Illuminate\Support\Collection` indexada por `product_id`
  (un `Collection[$productId] = [...]` por cada id solicitado, **incluyendo
  los que no se pudieron resolver** — nunca se omiten silenciosamente).

### 2.1 Estructura exacta de cada fila devuelta

```php
[
    'product_id'     => 173,
    'sku'            => '115DURZ-H-L',
    'nombre'         => 'Camiseta Piel Durazno Verde Cali Hombre', // del configurable padre
    'color'          => 'Verde Cali',
    'talla'          => 'L',
    'talla_etiqueta' => 'Talla',        // admin_name del atributo usado como "segundo eje"
    'stock_actual'   => 130,            // int|null
    'tipo_producto'  => 'simple',       // 'simple' | 'configurable' | ... | null si no existe
    'activo'         => true,           // bool|null
]
```

Verificado contra un producto real del catálogo (`product_id = 173`) vía
`tinker` — la salida coincide exactamente con la evidencia recogida en
`04A_STOCK_VARIANTE_DISENO.md` §2 (SKU, color "Verde Cali", talla "L",
`talla_etiqueta = "Talla"`, `stock_actual = 130`).

No se agregó ningún campo especulativo (se evaluó y se descartó "stock
proyectado" — ver §7).

---

## 3. Consultas realizadas (batch, sin N+1)

**Número real de consultas SQL: 5 (constante), verificado con datos
reales del catálogo para N=3 y N=25 product_ids — idéntico en ambos
casos.** Esto es *mejor* que la estimación original de 04A (6-7): al
implementar, se encontró que dos pares de consultas podían fusionarse
con un `JOIN` (atributos configurables + su nombre/código en una sola
consulta; valores de atributo + su label en otra sola consulta), en vez
de mantenerlas separadas como preveía el diseño original.

| # | Consulta | Tablas | Propósito |
|---|---|---|---|
| 1 | `products` LEFT JOIN `product_flat` (locale) | `products`, `product_flat` | SKU, tipo, `parent_id`, nombre propio (fallback), estado activo |
| 2 | `product_flat` (locale) `WHERE product_id IN (parent_ids)` | `product_flat` | Nombre del configurable padre |
| 3 | `product_super_attributes` JOIN `attributes` | `product_super_attributes`, `attributes` | Qué `attribute_id` es cada eje (color / el otro) por configurable, con su `code` y `admin_name` |
| 4 | `product_attribute_values` LEFT JOIN `attribute_options` | `product_attribute_values`, `attribute_options` | Valor asignado por producto+eje, ya resuelto contra su label |
| 5 | `product_inventory_indices` `WHERE product_id IN (ids) AND channel_id = ?` | `product_inventory_indices` | Stock vendible/indexado |

`core()->getDefaultChannel()` (cuando `$channelId` es `null`) se probó
por separado: en este entorno cuesta **0 consultas adicionales**, porque
el propio arranque de Bagisto ya deja el canal por defecto resuelto en
memoria antes de que el servicio lo pida. En un escenario de caché fría
(no observado, pero posible) costaría como máximo 1 consulta adicional.
Se documenta con honestidad: el número real y estable es **5**, con un
techo teórico de **6** en el peor caso de caché fría del canal — nunca
proporcional a N.

Ninguna consulta se ejecuta dentro de un `foreach` por producto — las 5
filtran siempre por `WHERE ... IN (...)` sobre colecciones de IDs ya
resueltas, y el ensamblado final por producto ocurre en PHP
(`Collection::keyBy()`/`groupBy()`), replicando el patrón ya usado en
`DisponibilidadConfeccionService::porOrdenCorte()`.

---

## 4. Resolución real de color y talla

Confirmado con la implementación funcionando contra datos reales y contra
los dos fixtures de test (adulto e infantil):

1. Se identifica el `attribute_id` de **color** buscando, entre los ejes
   (`product_super_attributes`) del configurable padre, el que tiene
   `attributes.code === 'color'`.
2. El **otro** eje (el primero que no sea `'color'`) se trata como el
   "segundo eje" — su `admin_name` real se expone en `talla_etiqueta`, y
   su valor resuelto se expone en `talla`. Esto es lo que permite que
   `talla_etiqueta` sea `"Talla"` para la línea adulta (`code = 'size'`)
   y `"grupo de edad (niños)"` para la línea infantil (`code =
   'grupo_de_edad_ninos'`) **sin ningún `if` que compare contra un
   `attribute_id` fijo** — es puramente estructural.
3. El valor (`integer_value` = `attribute_options.id`) se resuelve contra
   `admin_name` en la misma consulta (vía `LEFT JOIN`), nunca se expone
   el id crudo.

Limitación documentada (heredada de 04A, sin cambios): si un configurable
tuviera más de dos ejes, o ninguno con `code = 'color'`, el servicio solo
distingue "color" (por code) y "el primer eje no-color" como talla — no
hay evidencia en el catálogo real de un caso con 3+ ejes, así que no se
diseñó soporte para eso (no se agregó complejidad especulativa).

---

## 5. Resolución de channel

- `$channelId` explícito → se usa tal cual, sin ninguna llamada a `core()`.
- `$channelId === null` → `core()->getDefaultChannel()?->id` — **nunca**
  `core()->getCurrentChannel()` (el riesgo de depender del Host HTTP de
  la request, documentado en 04A §6, queda evitado por diseño).
- Verificado con un test que crea un segundo canal real
  (`Channel::factory()->create()`) y dos filas de `product_inventory_indices`
  para el mismo producto en canales distintos: pedir el canal por defecto
  devuelve un valor, pedir el otro canal explícitamente devuelve el otro
  valor — confirma que el parámetro realmente cambia el resultado y no
  hay ningún acoplamiento oculto al canal por defecto.

---

## 6. Comportamiento `0` vs `null` (verificado)

| Escenario | `stock_actual` |
|---|---|
| Existe fila en `product_inventory_indices` con `qty = 0` | `0` (entero, `!== null`) |
| No existe fila en `product_inventory_indices` para ese producto+canal | `null` |
| `product_id` no existe en `products` | `null` (junto con todos los demás campos) |
| `product_id` es un `configurable` (nunca indexado por Bagisto) | `null` |

Ambos casos probados explícitamente (Casos 5 y 6) y verificados con
aserciones que distinguen `toBe(0)` de `toBeNull()` — nunca se convierte
uno en otro dentro del servicio.

---

## 7. Stock proyectado — confirmación de la decisión de 04A

**No se incluyó ningún campo de stock proyectado en el contrato**, tal
como pedía el ajuste de esta fase. `VarianteConsultaService` devuelve
únicamente `stock_actual` (real/indexado). El cálculo
`stock_actual + cantidad que el usuario está enviando` queda,
explícitamente, fuera de este servicio — es responsabilidad de la capa
de presentación de la futura Fase 03C (ver `03B_FRONTEND_DISTRIBUCION.md`
§10, ya diseñado así desde antes).

---

## 8. Comportamiento defensivo ante identidad (verificado con tests)

| Caso | Comportamiento implementado | Test |
|---|---|---|
| `product_id` inexistente | Fila presente en el resultado, todos los campos `null` excepto `product_id` (se devuelve el id solicitado) | Caso 9 |
| `product_id` de un `configurable` | Sin excepción; `tipo_producto = 'configurable'`, `sku`/`nombre` sí se resuelven (existen), `color`/`talla`/`stock_actual` quedan `null` (un configurable no tiene sus propios `product_attribute_values` de eje, ni fila de inventario indexado) | Caso 10 |
| IDs duplicados en la entrada | Se deduplican antes de consultar; el resultado tiene una sola entrada por id, sin inconsistencia posible | Caso 11 |
| IDs inválidos (`0`, negativos, no numéricos) | Se descartan silenciosamente antes de cualquier consulta (no se documentó como caso de test explícito por ser trivial: `filter(fn ($id) => $id > 0)`) | — |
| Ninguna excepción global se lanza por una variante individual no resoluble dentro de un lote — el resto del lote se resuelve igual. | | Casos 9, 10 |

---

## 9. Tests ejecutados y resultado exacto

```
vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/VarianteConsultaServiceTest.php
Tests:    15 passed (50 assertions)
Duration: ~9.7s

vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"
Tests:    95 passed (261 assertions)
Duration: ~44.7s

vendor/bin/pint --test packages/ByLopez/CorteConfeccion
result: passed
```

Los 13 casos obligatorios pedidos, todos cubiertos (más 2 tests de base
de resolución de nombre/fallback):

| # | Caso pedido | Test |
|---|---|---|
| 1 | Variante adulta normal | `CASO 1` |
| 2 | Variante infantil (grupo de edad) | `CASO 2` |
| 3 | Varias variantes en una llamada | `CASO 3` |
| 4 | Sin N+1 | `CASO 4` (verificado con datos reales adicionalmente vía `tinker`, ver §3) |
| 5 | Stock = 0 | `CASO 5` |
| 6 | Sin inventory index | `CASO 6` |
| 7 | Atributo color ausente | `CASO 7` |
| 8 | Opción/label ausente | `CASO 8` |
| 9 | `product_id` inexistente | `CASO 9` |
| 10 | `product_id` configurable | `CASO 10` |
| 11 | IDs repetidos | `CASO 11` |
| 12 | Channel explícito | `CASO 12` |
| 13 | Channel null (default) | `CASO 13` |

Sin regresiones en el resto del paquete (80 tests preexistentes siguen
en verde).

---

## 10. Diferencias frente al diseño de 04A

1. **Número de consultas real: 5, no 6-7.** Se encontraron dos
   oportunidades de fusión por `JOIN` no explícitas en la estimación
   original (atributos+su código/nombre en una sola consulta; valores+su
   label en otra sola consulta). Documentado con transparencia (§3), tal
   como exigía la instrucción de esta fase.
2. **`core()->getDefaultChannel()` no costó ninguna consulta adicional**
   en la práctica (el bootstrap de Bagisto ya lo deja resuelto), a
   diferencia de lo que 04A trataba como "hasta 1 consulta más" — se
   documenta el hallazgo real sin ocultar la posibilidad de que sí cueste
   1 en un escenario de caché fría no observado.
3. **`channelId` parametrizable** — este es el Ajuste 1 aprobado, no
   estaba en el diseño original de 04A (que solo contemplaba
   `getDefaultChannel()` sin parámetro).
4. Todo lo demás (nombre del servicio, fuente de color/talla, resolución
   dinámica del segundo eje, `admin_name` como label, semántica `0`
   vs `null`, ausencia de stock proyectado) se implementó exactamente
   como fue diseñado y aprobado, sin desviaciones.

---

## 11. Riesgos pendientes

- **`product_inventory_indices` sigue siendo un valor indexado, no en
  vivo** (heredado de 04A, sin cambios) — si el job de reindexación de
  Bagisto no corre con la frecuencia esperada, `stock_actual` podría
  reflejar un estado desactualizado. No es un riesgo introducido por
  este servicio.
- **Solo se probó con `MySQL` real vía `DatabaseTransactions`** — el
  conteo de consultas (§3) se verificó tanto en tests Pest como con
  `tinker` contra datos reales; no se probó con SQLite ni otro motor.
- **Ningún consumidor real todavía** — este servicio no está conectado a
  ningún Controller/Blade (por diseño, esta fase es solo el backend). El
  primer consumidor real será la Fase 03C, todavía no autorizada.
- El límite de "solo un eje de color y un segundo eje" (§4) no se ha
  puesto a prueba contra un configurable con 3+ ejes porque no existe
  evidencia de ese caso en el catálogo — riesgo bajo, pero no verificado
  por ausencia de datos, no por diseño.
