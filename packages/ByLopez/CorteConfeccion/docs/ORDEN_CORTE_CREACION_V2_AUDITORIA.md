# ORDEN_CORTE_CREACION_V2_AUDITORIA.md

Proyecto: ByLopez — Corte y Confección
Alcance: `packages/ByLopez/CorteConfeccion/`
Fecha de auditoría: 2026-09-22
Estado: **AUDITORÍA COMPLETA — SIN IMPLEMENTAR. Pendiente de aprobación explícita.**

> Ningún archivo de código, migración, Blade/Vue, Service, Model ni test fue modificado para producir este documento. Todas las citas de código son lecturas directas del estado actual del repositorio. `packages/Webkul/` no fue tocado ni se propone tocarlo.

---

## 0. Relación con la auditoría previa (04A_ORDEN_CORTE_MODELO_FUNCIONAL.md)

Antes de leer el resto del documento, un hallazgo importante: **este mismo rediseño ya se auditó una vez**, en `packages/ByLopez/CorteConfeccion/docs/04A_ORDEN_CORTE_MODELO_FUNCIONAL.md` (fecha 2026-09-07), y quedó **pausado explícitamente** — tanto ese documento como `docs/confeccion/STATUS.md` dicen textualmente: *"No iniciar 04B hasta que el usuario confirme las decisiones abiertas del documento."*

Ese documento 04A ya identificó correctamente:
- La regla exacta de `validarCompatibilidadRollos()` (mismo archivo/líneas que esta auditoría cita abajo).
- El mismo riesgo de `cantidad_proyectada` (campo sin consumidores reales fuera de la propia orden).
- El mismo riesgo del peso sobrante/merma como "un solo valor por toda la orden, no por grupo" — lo marcó como *"el riesgo más serio; no tiene una solución de 'cambio mínimo' limpia, requiere decisión de negocio"* (§12.1 de 04A).

Esta auditoría **retoma y resuelve** esas mismas preguntas abiertas, pero con dos diferencias importantes respecto al enfoque de 04A:
1. El usuario ahora pide explícitamente **no** introducir una capa de "grupos/lotes" salvo necesidad técnica demostrada — 04A sí proponía una clave de agrupación persistente. Esta auditoría evalúa esa necesidad con evidencia de código nueva (§8) y llega a una recomendación **más simple** que la de 04A (ver §9).
2. El usuario pide auditar específicamente cómo se resuelve hoy género/línea y si existe una relación con `attribute_family_id` — algo que 04A no exploró (04A solo tocaba la compatibilidad de rollos y `cantidad_proyectada`, no la resolución de variantes).

Recomendación de proceso: si esta auditoría se aprueba, debe tratarse como el sucesor que **cierra** las decisiones abiertas de 04A, y `docs/confeccion/STATUS.md` debe actualizarse en ese momento (no en esta fase) para reflejarlo.

---

## 1. Resumen ejecutivo

**Qué pasa hoy:** crear una Orden de Corte obliga a buscar cada producto (variante simple de Bagisto) uno por uno en un autocomplete de catálogo genérico (el mismo que usa Bagisto para buscar productos en cualquier pantalla admin), escribiendo o reconociendo el SKU/nombre exacto. No hay ningún campo de género/línea ni de talla en esa pantalla de creación — el operador solo eligeun rollo (de una lista `<select>`, no autocomplete), un producto, y una `cantidad_proyectada` numérica obligatoria. Para pedir "Hombre S/M/L/XL + Dama M/L" con dos colores, hoy hay que repetir la búsqueda de producto 12 veces (una por cada combinación color×talla×género), sabiendo de memoria o copiando el SKU exacto de cada una.

**Por qué pasa esto (causa raíz, no síntoma):** el sistema **nunca tuvo, en ningún punto del código, un mecanismo que resuelva "dame el producto simple exacto para tipo_tela+línea+color+talla"**. Existe `VarianteConsultaService`, pero solo funciona en la dirección contraria: dado un `product_id` ya conocido, describe sus atributos. No existe el camino inverso. Construirlo es, por tanto, trabajo nuevo, no una reconexión de algo que ya exista escondido.

**El otro hallazgo estructural:** "género/línea" (Hombre/Dama/Niño) **no existe como atributo estructurado de Bagisto en ningún lugar de este proyecto**. Hoy vive en exactamente tres formas, ninguna confiable para resolución automática:
1. Como subcadena de texto libre dentro del `name` del producto Bagisto (ej. `"Camiseta Piel Durazno Verde Calì Hombre L"`).
2. Como subcadena de texto libre dentro del `code` de un `attribute_family` (ej. `Camiseta_Hombre_DURZ`).
3. Como una columna de texto libre propia de ByLopez, `genero_linea`, sin ninguna FK ni validación (`nullable|string|max:255`), que hoy **ni siquiera se captura en la pantalla de creación** — solo se rellena manualmente en fixtures de test y en el formulario de "adicionales" del reporte del cortador.

Ninguna de las tres formas es apta para resolución automática sin violar la instrucción explícita del usuario de "no parsear nombres de producto para obtener color, talla o género". Esto significa que **construir el flujo pedido exige, sí o sí, una relación explícita nueva** (tipo_tela + línea/género → algo consultable) — no es una elección estética, es un requisito técnico duro. Se detalla en §4 y §9.

**Buenas noticias, con evidencia real:**
- La regla de compatibilidad a cambiar es una función de 14 líneas (`validarCompatibilidadRollos()`), sin ningún test que la cubra hoy — cambiarla es de bajo riesgo de regresión (nada se rompe porque nada la prueba), aunque exactamente por eso se necesitan tests **nuevos**.
- `cantidad_proyectada` está confirmado, con lectura completa del servicio, como un campo de **solo escritura**: se guarda al crear, nunca se lee ni compara en `entregar()`, `cambiarEstado()`, `reportarResultado()`, `cerrar()`, ni en `RecepcionCorteService`. Retirarlo de la UX/validación es seguro.
- La distribución a talleres (`OrdenConfeccionService::distribuir()`) **nunca lee `cantidad_proyectada` ni `cantidad_real_cortada` directamente** — lee exclusivamente `RecepcionCorteDetalle` (lo que bodega contó físicamente). Es decir, el invariante que pide el usuario ("creación define qué; reporte define cuánto; recepción compara; distribución consume recepción") **ya es exactamente como funciona el código hoy**, de forma estructural, no accidental.
- La función "productos adicionales por aprovechamiento" ya existe, completa, bajo el nombre `es_adicional`/`adicionales` (no "aprovechamiento" literal) — es independiente de todo lo anterior y no se ve afectada por este rediseño.

**El hallazgo nuevo más importante de esta auditoría (no estaba en 04A con esta precisión):** se leyó el código exacto de `actualizarRollosAlCerrar()` (líneas 402-428) y se confirma que el peso sobrante/merma que bodega reporta **hoy es un solo número para toda la orden**, que luego se reparte mecánicamente entre los rollos seleccionados en el orden en que se cargaron (un "reparto en cascada" ciego, sin relación con qué rollo realmente tuvo qué sobrante). Mientras todos los rollos de una orden son físicamente idénticos (regla actual), este reparto ciego es inofensivo porque no importa a cuál rollo "le toque" el sobrante — son intercambiables. **En cuanto se permita mezclar colores/gramajes distintos en una orden, este mismo reparto ciego deja de ser inofensivo**: puede asignarle sobrante de tela negra a un rollo vino tinto, corrompiendo silenciosamente `Rollo.peso_disponible` y cualquier reporte futuro de rendimiento por color/rollo. Este es el único hallazgo de esta auditoría que constituye una **necesidad técnica real** para algo más que solo la validación de compatibilidad — ver §8-9 para el detalle y la solución mínima propuesta (que no requiere una tabla nueva de "grupos").

---

## 2. Mapa actual extremo a extremo

```
Proveedor de tela → Compra de tela → Rollos (tipo_tela_id, color, gramaje, referencia)
        │
        ▼
[CREAR Orden de Corte] ── OrdenCorteService::create()
  · Selecciona N rollos (deben compartir tipo_tela_id + color + gramaje HOY)
  · Por cada detalle: busca 1 producto Bagisto (autocomplete generico) + cantidad_proyectada (obligatoria)
  · Escribe: OrdenCorte (rollo_id=primer rollo, color=primer rollo), OrdenCorteRollo (1 fila por rollo),
             OrdenCorteDetalle (1 fila por producto, cantidad_real_cortada=0)
        │
        ▼
[ENTREGAR] → estado EntregadoACortador (peso_entregado = suma peso_inicial_usado de los rollos)
        │
        ▼
[REPORTAR RESULTADO] ── OrdenCorteService::reportarResultado()
  · Actualiza cantidad_real_cortada por detalle existente (nunca toca cantidad_proyectada)
  · Permite agregar "adicionales" (es_adicional=true, cantidad_proyectada=0 forzado) — prendas no
    solicitadas originalmente, por aprovechamiento de tela sobrante
        │
        ▼
[RECEPCIÓN DE BODEGA] ── RecepcionCorteService::create()
  · Compara cantidad_real_cortada (reportado) vs cantidad_recibida (contado por bodega), por posición
    de índice, no por product_id — exige autorización especial si difieren
  · Captura peso_sobrante_usable + merma como UN SOLO NÚMERO por toda la orden
        │
        ▼
[CERRAR] ── OrdenCorteService::cerrar()
  · total_piezas_cortadas = suma cantidad_real_cortada (nunca cantidad_proyectada)
  · peso_consumido_total = peso_base - peso_sobrante_total - merma_total (agregado de toda la orden)
  · actualizarRollosAlCerrar(): reparte ese sobrante único en cascada entre los rollos, en el orden
    en que están cargados — SIN relación con el color/gramaje real de cada rollo
        │
        ▼
[DISTRIBUCIÓN A TALLERES] ── OrdenConfeccionService::distribuir() + DisponibilidadConfeccionService
  · cantidad_disponible_confeccion = (RecepcionCorteDetalle.cantidad_recibida - cantidad_defectuosa)
                                      - SUM(OrdenConfeccionDetalle.cantidad_entregada ya distribuido)
  · NUNCA lee cantidad_proyectada ni cantidad_real_cortada directamente — todo pasa por RecepcionCorteDetalle
        │
        ▼
[CONFECCIÓN / RETORNOS / INVENTARIO] — sin cambios relevantes para esta auditoría (fuera de alcance)
```

**Conclusión del mapa:** el único punto de la cadena que necesita cambiar para lograr el flujo pedido por el usuario es el bloque **[CREAR Orden de Corte]**. Todo lo que viene después (entregar, reportar, recibir, cerrar, distribuir) ya opera sobre `product_id` (variante simple) + cantidades, sin importar cómo se llegó a ese `product_id` — así que un rediseño de la pantalla de creación es, en principio, un cambio **aislado**, con una única excepción real: el reparto de peso sobrante en `cerrar()` (ver arriba y §8).

---

## 3. Hallazgos con archivo, clase, método y línea

### 3.1 Regla de compatibilidad de rollos (la que hay que cambiar)

`packages/ByLopez/CorteConfeccion/src/Services/OrdenCorteService.php:351-364`:

```php
protected function validarCompatibilidadRollos(Collection $rollos): void
{
    $base = $rollos->first();

    $incompatible = $rollos->contains(function ($rollo) use ($base) {
        return (string) $rollo->tipo_tela_id !== (string) $base->tipo_tela_id
            || strtolower(trim((string) $rollo->color)) !== strtolower(trim((string) $base->color))
            || trim((string) $rollo->gramaje) !== trim((string) $base->gramaje);
    });

    if ($incompatible) {
        throw ValidationException::withMessages(['rollo_ids' => trans('corteconfeccion::app.errors.rollos-incompatibles')]);
    }
}
```

Llamada desde `create()` en la línea 46. Mensaje de error: `src/Resources/lang/es/app.php` clave `rollos-incompatibles` = *"Los rollos seleccionados deben tener el mismo tipo de tela, color y gramaje."*

Duplicado en el frontend: `src/Resources/views/admin/ordenes-corte/create.blade.php`, función `compatibleWithSelection()` (según cita ya existente en `docs/04A_ORDEN_CORTE_MODELO_FUNCIONAL.md §2.3`, líneas ~443-453) — misma comparación en JS, mismo mensaje. **Debe cambiarse en ambos lados**, o el frontend bloqueará combinaciones que el backend ya aceptaría.

**Cambio necesario:** eliminar las dos comparaciones de `color` y `gramaje`, dejar solo `tipo_tela_id`. Es un cambio de 2 líneas en el backend + el equivalente en el frontend.

**Cobertura de test actual: cero.** Se buscó en las 24 archivos de `tests/` cualquier referencia a `rollos-incompatibles`, `validarCompatibilidadRollos`, o un payload de creación con 2+ `rollo_ids` de distinto color/gramaje/tipo_tela — no hay ninguna. Esto significa: cambiar la regla no rompe ningún test existente, pero tampoco hay ninguno que hoy demuestre que funciona — hay que escribir tests nuevos para ambos comportamientos (mismo tipo_tela permite mezclar; tipo_tela distinto sigue bloqueando).

### 3.2 FK legado `rollo_id` singular (efecto colateral de permitir mezclar colores)

`OrdenCorteService.php:66`: al crear, `'rollo_id' => $primerRollo->id` — se guarda solo el **primer** rollo seleccionado.
`OrdenCorteService.php:69`: `'color' => $primerRollo->color` — el color a nivel de toda la orden es una foto del primer rollo, no un resumen real.

Esta columna nunca se eliminó (confirmado: ninguna migración la dropea; sigue siendo `NOT NULL` desde la migración original) y se sigue usando como *fallback* en `pesoReservadoOrden()` (`OrdenCorteService.php:380-387`) cuando `rollosCorte` está vacío.

**Por qué importa para este rediseño:** hoy, como todos los rollos de una orden son del mismo color (regla vigente), `OrdenCorte.color` es correcto por casualidad — siempre coincide con el de *cualquier* rollo de la orden. En cuanto se permita mezclar colores, `OrdenCorte.color` pasará a mostrar arbitrariamente el color de "el rollo que se seleccionó primero", que ya no representa a toda la orden. Ya estaba documentado como riesgo en `docs/AUDITORIA_MODULO_CORTE_CONFECCION.md §5` (riesgo #2) desde 2026-08-27, antes de que existiera la idea de mezclar colores — se vuelve más grave ahora, no menos.

**Recomendación:** no es necesario eliminar la columna (rompería el fallback de `pesoReservadoOrden()` y el histórico), pero sí dejar de tratar `OrdenCorte.color` como un resumen fiable en cualquier vista nueva — usar en su lugar un resumen real derivado de `rollosCorte` (ej. lista de colores únicos), igual que ya se hace con `genero_linea` vía `resumenGeneroLinea()` (línea 366-374, ver 3.4).

### 3.3 Identidad de producto: `product_id` vs `variant_id`

`StoreOrdenCorteRequest.php:50-51`:
```php
'detalles.*.product_id' => ['required', 'exists:products,id'],
'detalles.*.variant_id' => ['nullable', 'exists:products,id'],
```

Ambos validan solo contra la tabla `products` genérica — **ninguno de los dos verifica en el request que sea un producto `simple`** (no configurable). Esa validación existe, pero en otro punto de la cadena, no en Orden de Corte: `OrdenConfeccionService.php:382-402` y `InventarioMovimientoService.php:225-227` rechazan explícitamente `type === 'configurable'`, con el mensaje `producto-configurable-no-valido` (`src/Resources/lang/es/app.php:86`) — pero eso ocurre en Confección/Inventario, **no existe un guard equivalente en `OrdenCorteService::create()` ni en su Request**. Hoy es posible, en teoría, guardar un `OrdenCorteDetalle.product_id` apuntando a un producto configurable (padre) sin que nada lo impida en este punto — riesgo preexistente, no introducido por este rediseño, pero que el nuevo mecanismo de resolución automática de variantes (§9) debe evitar por construcción (nunca debe poder devolver un `product_id` configurable).

`variant_id` (columna `OrdenCorteDetalle.variant_id`, fillable, nullable) se escribe en `create()` (`OrdenCorteService.php:99`) pero:
- No tiene relación Eloquent definida en el modelo (`OrdenCorteDetalle.php` solo define `product()`, no `variant()`).
- No se lee en ningún otro método del servicio, ni en `RecepcionCorteService`, ni en `DisponibilidadConfeccionService`, ni en `OrdenConfeccionService::distribuir()`.
- Es, en la práctica, un campo muerto — capturado pero nunca consumido.

**Recomendación:** no usar `variant_id` para nada nuevo (mantiene la ambigüedad de qué significa vs `product_id`); si el V2 introduce el concepto de "producto configurable padre elegido + variante simple resuelta", usar dos nombres explícitos y sin ambigüedad (ver §9), no reutilizar esta columna heredada.

### 3.4 `genero_linea`: dónde vive y cómo se calcula el resumen

- Columna en `OrdenCorte` (nivel orden, resumen) y en `OrdenCorteDetalle` (nivel detalle) — ambas `string nullable`, sin FK.
- `OrdenCorteService::resumenGeneroLinea()`, líneas 366-374:
  ```php
  collect($detalles)->pluck('genero_linea')->filter()->map('trim')->unique()->implode(' / ') ?: 'Mixto'
  ```
  Es puramente textual: junta los valores únicos no vacíos de los detalles con `" / "`, o devuelve literalmente `'Mixto'` si todos vienen vacíos.
- **La pantalla de creación actual (`create.blade.php`) no tiene ningún campo para capturar `genero_linea` por detalle** — confirmado por lectura completa del archivo (538 líneas): el array `oldDetalles` y el payload real solo llevan `product_id`, `cantidad_proyectada`, `observacion`. Esto significa que, en el uso real de la pantalla actual (no en tests), toda orden creada hoy termina con `genero_linea = null` por detalle y `'Mixto'` a nivel de orden — el campo existe en BD y en el Service, pero está huérfano de UI.
- El único lugar del código con un `<input>` real para `genero_linea` es el formulario de "adicionales" del reporte del cortador (`partials/card-resultado.blade.php:219-225`), no la creación.

### 3.5 `AgrupadorDetallesCorte` — ¿ya existe un concepto de "grupo"?

`src/Support/AgrupadorDetallesCorte.php` (clase de soporte, no persistida):
- Línea 23: agrupa `OrdenCorteDetalle` por clave `product_id . '|' . trim(genero_linea)` — es decir, agrupa filas que ya existen, para presentarlas juntas en las tarjetas de "Solicitud" y "Resultado" (`card-solicitud.blade.php`, `card-resultado.blade.php`).
- Línea 27-29: por cada grupo calcula `genero_linea` (del primer elemento), `total_proyectado` (suma de `cantidad_proyectada`).

**Esto NO es el "grupo de tela" que pide este rediseño.** Es una agrupación de visualización sobre `product_id` (que ya identifica color+talla+género de forma completa, según `docs/confeccion/02_MODELO_DISTRIBUCION.md §3.3`), no una agrupación sobre rollos/color/gramaje. El propio `docs/04A_ORDEN_CORTE_MODELO_FUNCIONAL.md §3 punto 5` ya advertía sobre este riesgo de colisión de nombres si se introduce un nuevo "grupo" — advertencia que sigue vigente si se decide crear cualquier concepto nuevo llamado "grupo".

**Conclusión:** no existe hoy ninguna estructura, ni persistida ni de soporte, que agrupe *rollos* por compatibilidad física dentro de una orden — solo existe agrupación de *detalles de producto* por producto+género. Esto confirma que, si se necesita algo así (ver §8), habría que construirlo desde cero; no es una reutilización.

---

## 4. Familias de atributos — explicación detallada

**Pregunta central del usuario:** ¿"Hombre/Dama/Niño" existe como atributo estructurado real, o solo está implícito en nombre/familia/SKU?

**Respuesta con evidencia:** está implícito, nunca estructurado, y de dos formas distintas simultáneamente:

1. **Como parte del código de la familia de atributos de Bagisto.** Evidencia real de base de datos citada en `docs/confeccion/04A_STOCK_VARIANTE_DISENO.md:67`: existe una familia `attribute_family_id = 3` cuyo `code` es literalmente `Camiseta_Hombre_DURZ`. Es decir, alguien ya nombró una familia completa incluyendo el género en el código — pero eso es una convención de nomenclatura humana, no una relación de base de datos consultable (`AttributeFamily` en Bagisto es solo `code` + `name` + grupos de atributos, sin ningún campo de "género").
2. **Como subcadena del nombre del producto (`product_flat.name`) y del SKU.** Ejemplo real, mismo documento: producto simple `115DURZ-H-L`, nombre `"Camiseta Piel Durazno Verde Calì Hombre L"` — la `H` en el SKU y la palabra "Hombre" en el nombre son las únicas señales, ambas texto libre.

**Lo que definitivamente NO existe (confirmado por grep exhaustivo en `packages/Webkul/Attribute` y `packages/Webkul/Product`, cero coincidencias):**
- Ningún atributo Bagisto con código `gender`/`genero`.
- Ninguna familia de atributos con nombre `Hombre`/`Dama`/`Mujer`/`Niño` como concepto genérico reutilizable (solo existen como parte de nombres compuestos de familias específicas, una por línea de producto).
- Ninguna relación explícita `tipo_tela_id → attribute_family_id` en ningún config ni modelo de ByLopez (grep de `tipo_tela` en `src/Config/` y `src/Models/`: cero coincidencias que la mencionen).

**Cómo se resuelve color/talla hoy (para contraste, porque SÍ está bien resuelto):** `VarianteConsultaService.php`, líneas 71-83 y 139-140:
```php
$ejeColor = $ejesConfigurable->firstWhere('code', 'color');
$ejeTalla = $ejesConfigurable->first(fn ($eje) => $eje->code !== 'color');
```
Es decir, color se identifica por el código real del atributo Bagisto (`attributes.code === 'color'`), y "talla" es "lo que sea que sea el otro eje de variación configurable" — deliberadamente genérico, porque una línea infantil usa `grupo_de_edad_ninos` en vez de `size` (comentario explícito en el propio archivo, líneas 65-70). **Esto es exactamente el patrón correcto y ya probado** que se necesitaría para género, si género fuera un eje de variación Bagisto — pero no lo es, porque género nunca se modeló como un `super_attribute` de ningún configurable en este catálogo.

**Conclusión — las tres opciones que pedía auditar la sección 5 del encargo, evaluadas:**

| Opción | ¿Existe hoy? | Viable para V2 |
|---|---|---|
| Relación explícita `tipo_tela + línea/género → attribute_family_id` | No existe ninguna tabla/config así | Sí, es la más simple de construir — ver §9 |
| Atributo estructurado de línea/género en Bagisto | No existe (cero families ni attributes con ese propósito genérico) | Requeriría modificar `packages/Webkul/` (crear el atributo) — **prohibido por regla del proyecto** |
| Perfil de producción propio de ByLopez que apunte a la familia Bagisto | No existe ninguna tabla así hoy | Es una variante más pesada de la opción 1; ver §9 para por qué no se recomienda todavía |

La segunda opción queda descartada de inmediato: crear un atributo Bagisto nuevo tocaría `packages/Webkul/Attribute`, violando la restricción absoluta del proyecto. Quedan la 1 y la 3, comparadas en §9.

---

## 5. Mapa real de datos

```
bylopez_cc_rollos
  id, tipo_tela_id (FK → attribute_options.id, vía Attribute 'tipo_tela'), color, gramaje,
  referencia (vía proveedor_tela_referencia_id), peso_disponible, estado

bylopez_cc_ordenes_corte
  id, codigo, rollo_id (FK legado, primer rollo — NOT NULL, nunca eliminado),
  cortador_id, color (snapshot del primer rollo), genero_linea (resumen textual),
  fecha_entrega, fecha_solicitud, peso_entregado, peso_total_rollos,
  peso_sobrante_total (UN SOLO número para toda la orden), merma_total (idem),
  peso_consumido_total, total_piezas_cortadas, peso_promedio_pieza,
  estado, creado_por_id, reportado_por_id, reportado_en

bylopez_cc_orden_corte_rollos  (pivote real multi-rollo)
  id, orden_corte_id, rollo_id, peso_inicial_usado, peso_sobrante, peso_consumido
  -- estas dos últimas columnas YA EXISTEN por rollo, pero hoy se calculan con un
  -- reparto mecánico (ver §8), no con una captura real por rollo

bylopez_cc_ordenes_corte_detalles
  id, orden_corte_id, product_id (FK products.id, se espera simple, sin guard propio en este punto),
  variant_id (FK products.id, nullable, sin relación Eloquent, no leído en ningún otro método),
  producto_sku, producto_nombre (snapshot), genero_linea (texto libre, sin FK, sin UI en creación),
  talla (texto libre), cantidad_proyectada (a retirar de UX, ver §6),
  cantidad_real_cortada (el dato real de negocio), es_adicional (bool, feature de "aprovechamiento")

products (Bagisto, packages/Webkul, solo lectura)
  attribute_family_id → attribute_families (code, name — sin campo de género)
  cada 'simple' es hijo de un 'configurable' vía parent_id
  product_super_attributes: qué atributos varían dentro de esa familia (ej. color, size|grupo_de_edad_ninos)
  product_attribute_values: valores reales por producto simple (vía attribute_options para atributos select)
```

---

## 6. Auditoría de `cantidad_proyectada`

**Regla de negocio objetivo del usuario:** deja de solicitarse al crear; el dato relevante es `cantidad_real_cortada`.

**Uso exhaustivo confirmado (grep completo de `src/`, sin exclusiones):**

| Capa | Archivo:línea | Qué hace |
|---|---|---|
| Migración | `2026_05_07_000003_...detalles_table.php:18` | Define la columna `unsignedInteger`, sin default |
| Migración | `2026_06_14_000001_add_multi_rollo...php:35` | Solo la usa como ancla posicional (`->after('cantidad_proyectada')`) para otra columna |
| Modelo | `OrdenCorteDetalle.php:22,29` | `$fillable` + cast a integer |
| Request | `StoreOrdenCorteRequest.php:24,54` | Filtro de fila no vacía + regla **`required\|integer\|gt:0`** ← el único punto que bloquea si se retira sin cambiar nada más |
| Request | `ReportarResultadoCorteRequest.php` | **Cero** referencias — no participa en el reporte del cortador |
| Service | `OrdenCorteService.php:102` | Se escribe al crear, desde el payload |
| Service | `OrdenCorteService.php:170` | Se fuerza a `0` para filas "adicionales" |
| Service (resto) | `entregar()`, `cambiarEstado()`, `reportarResultado()` (lógica), `cerrar()` | **Cero** lecturas o comparaciones — confirmado por lectura completa de las 429 líneas del archivo |
| `RecepcionCorteService.php` | — | **Cero** referencias en las 105 líneas del archivo |
| `DisponibilidadConfeccionService.php` / `OrdenConfeccionService::distribuir()` | — | **Cero** referencias |
| Soporte | `AgrupadorDetallesCorte.php:29` | Suma para `total_proyectado` (solo display) |
| Vistas | `create.blade.php` (input), `show.blade.php:7` (`$totalSolicitado`), `card-resultado.blade.php` (columna "solicitado" en 4 puntos) | Solo lectura/formulario, nada de lógica de negocio |
| Tests | ~10 archivos | Solo como campo de fixture obligatorio para poder llamar `create()`; **dos** aserciones reales (`OrdenCorteResultadoYRecepcionTest.php:134,159,162`) confirman que el campo **no se toca** al reportar resultado — aserciones que seguirán siendo válidas aunque el campo pase a ser opcional/oculto |

**Conclusión:** confirmado, con lectura de código (no solo de docs), que `cantidad_proyectada` no tiene ningún consumidor de negocio fuera de la propia fila en la que se creó. Es seguro retirarla de la UX y de la validación obligatoria. El plan técnico exacto está en §11.

---

## 7. Auditoría del reporte del cortador (incluye "productos adicionales")

**Cómo se muestran hoy las variantes al cortador:** `card-resultado.blade.php` recibe las filas ya resueltas desde `show.blade.php` (los `OrdenCorteDetalle` de la orden, ya con `product_id`/`producto_sku`/`talla`/`genero_linea` fijados en la creación) — el cortador **no vuelve a buscar productos** para las filas originales, solo edita la cantidad. Esto ya cumple exactamente el criterio de aceptación #8 del usuario ("El cortador reporta cantidades sobre las variantes ya generadas").

**Cómo se guarda `cantidad_real_cortada`:** `OrdenCorteService::reportarResultado()` (líneas 124-188, citado completo abajo), actualiza únicamente esa columna por cada `detalle_id` recibido — nunca permite cambiar `product_id`/`talla`/`genero_linea` de una fila existente (confirmado por el propio código: el `update()` de la línea 153 solo incluye `cantidad_real_cortada`).

```php
public function reportarResultado(OrdenCorte $orden, array $data): OrdenCorte
{
    return DB::transaction(function () use ($orden, $data) {
        $orden->load('detalles');
        // ... guards de estado y permisos ...
        $detallesPorId = $orden->detalles->keyBy('id');

        foreach ($data['resultados'] ?? [] as $resultado) {
            $detalleOrden = $detallesPorId->get((int) ($resultado['detalle_id'] ?? 0));
            if (! $detalleOrden) { throw ValidationException::withMessages([...]); }
            $valorAnterior = (int) $detalleOrden->cantidad_real_cortada;
            $valorNuevo = (int) ($resultado['cantidad_real_cortada'] ?? 0);
            $detalleOrden->update(['cantidad_real_cortada' => $valorNuevo]);
            $this->cantidadCortadaAuditService->record($detalleOrden, $valorAnterior, $valorNuevo);
        }

        foreach ($data['adicionales'] ?? [] as $adicional) {
            $cantidad = (int) ($adicional['cantidad_real_cortada'] ?? 0);
            if ($cantidad <= 0) { continue; }
            $detalleAdicional = $orden->detalles()->create(array_merge(
                $this->productLookupService->snapshot((int) ($adicional['product_id'] ?? 0)),
                ['genero_linea' => ..., 'talla' => ..., 'cantidad_proyectada' => 0,
                 'cantidad_real_cortada' => $cantidad, 'es_adicional' => true, 'observacion' => ...]
            ));
            $this->cantidadCortadaAuditService->record($detalleAdicional, 0, $cantidad);
        }
        // ... cambia estado, guarda reportado_por_id/reportado_en ...
    });
}
```

**"Productos adicionales por aprovechamiento" — nombre real en código: `es_adicional`/`adicionales` (la palabra "aprovechamiento" no existe en ningún archivo del paquete; solo aparece una vez como texto de ayuda al usuario: `card-resultado.blade.php:167`, *"Puedes agregar prendas adicionales si aprovechaste espacio sobrante de tela."*).**

Cómo funciona, completo:
- El cortador busca un producto nuevo (mismo autocomplete genérico de Bagisto) directamente en el formulario de "adicionales" (`card-resultado.blade.php`, líneas 100-112, 162-254).
- Al guardar, se crea una fila `OrdenCorteDetalle` nueva con `es_adicional = true`, `cantidad_proyectada = 0` (forzado, nunca lo pide al usuario), y la cantidad real que se cortó.
- `show.blade.php` separa visualmente `$detallesSolicitud` (lo pedido) de `$detallesAdicionales` (lo extra), con comentario explícito en el código: *"nunca mezclar solicitado / reportado / adicionales"* (línea 92).
- Esta fila adicional entra después en `RecepcionCorteDetalle`/`distribuir()` exactamente igual que cualquier otra — no hay tratamiento especial más adelante en la cadena.

**Relación con este rediseño:** ninguna. El mecanismo de "adicionales" no depende de `cantidad_proyectada` (siempre es 0 ahí), no depende de la regla de compatibilidad de rollos, y seguiría funcionando exactamente igual después del cambio — es información de contexto pedida por el usuario, no algo que requiera modificarse.

---

## 8. Impactos y riesgos del cambio de compatibilidad

**Riesgo 1 — bajo, ya cubierto arriba (§3.1/3.2):** cambiar la validación en sí es trivial; el snapshot `OrdenCorte.color` deja de ser representativo de toda la orden. Mitigación: no depender de esa columna para mostrar "el color de la orden" en ninguna vista nueva; usar un resumen real (lista de colores de `rollosCorte`).

**Riesgo 2 — real, con evidencia de código, es el hallazgo central de esta auditoría:**

`OrdenCorteService::actualizarRollosAlCerrar()`, líneas 402-428 (código completo, leído directamente):

```php
protected function actualizarRollosAlCerrar(OrdenCorte $orden): void
{
    $orden->loadMissing('rollosCorte.rollo');
    $sobrantePorAsignar = (float) $orden->peso_sobrante_total;   // ← UN SOLO número, para toda la orden

    foreach ($orden->rollosCorte as $ordenRollo) {
        if (! $ordenRollo->rollo) { continue; }
        $pesoInicialUsado = (float) $ordenRollo->peso_inicial_usado;
        $pesoSobrante = min($pesoInicialUsado, $sobrantePorAsignar);   // ← reparto en cascada, ciego
        $pesoConsumido = max($pesoInicialUsado - $pesoSobrante, 0);
        $sobrantePorAsignar = max($sobrantePorAsignar - $pesoSobrante, 0);

        $ordenRollo->update(['peso_sobrante' => $pesoSobrante, 'peso_consumido' => $pesoConsumido]);
        $ordenRollo->rollo->update([
            'peso_disponible' => $pesoSobrante,
            'estado' => $this->rolloService->estadoPorPeso($pesoSobrante, (float) $ordenRollo->rollo->peso_inicial),
        ]);
    }
}
```

Y el origen de ese número único: `RecepcionCorteService::create()` (líneas ~55-95) recibe de bodega **un solo campo** `peso_sobrante_usable` para toda la orden, lo guarda tal cual en `OrdenCorte.peso_sobrante_total` (sin desglose por rollo).

**Qué significa exactamente:** hoy, con la regla vigente (todos los rollos de una orden son física y visualmente idénticos: mismo tipo de tela, color y gramaje), este reparto en cascada es matemáticamente correcto en efecto neto (el peso total consumido de la orden es correcto) e inofensivo en detalle (no importa a cuál rollo "le toca" el sobrante en el reparto, porque son intercambiables — el kilo de tela vino tinto es indistinguible de otro kilo de la misma tela vino tinto). **Este reparto ciego probablemente nunca se pensó como una limitación porque la regla de compatibilidad lo hacía irrelevante.**

En cuanto se permita mezclar colores/gramajes en una misma orden (el objetivo de este rediseño), el mismo código seguiría ejecutándose sin cambios — y empezaría a asignar sobrante de un color al `peso_disponible` de un rollo de otro color, de forma silenciosa (sin excepción, sin advertencia, un número plausible pero incorrecto). Esto corrompería:
- `Rollo.peso_disponible`/`estado` de rollos específicos — afecta si ese rollo aparece luego como "disponible" para una futura orden.
- Cualquier reporte futuro de rendimiento por color/rollo/tipo de tela (ya está en el roadmap de `docs/AUDITORIA_MODULO_CORTE_CONFECCION.md §14`, Fase 5).

**Esta es la única necesidad técnica real, independiente y demostrada con código que justificaría tocar algo más que la sola validación de compatibilidad — tal como pedía explícitamente la sección 3 del encargo del usuario.**

**La solución NO requiere una tabla nueva de "grupos/lotes".** La pieza que falta ya existe estructuralmente: `bylopez_cc_orden_corte_rollos` (el pivote) **ya tiene** columnas `peso_sobrante`/`peso_consumido` por rollo — hoy calculadas con el reparto ciego, no capturadas de la realidad. La solución mínima es: capturar el sobrante/merma **por rollo** (o por sub-grupo físicamente homogéneo, si bodega no puede medir rollo por rollo) en el momento de la recepción, en lugar de un solo número por orden, y que `actualizarRollosAlCerrar()` simplemente **aplique** esos valores en vez de inventarlos con una cascada. Esto es una extensión del formulario/Request de `RecepcionCorteService`, no una tabla nueva. Detalle en §9/§11.

**Riesgo 3 — menor:** ninguna prueba existente cubre hoy la regla de compatibilidad (§3.1), así que no hay riesgo de romper tests al cambiarla — pero tampoco hay ninguna red de seguridad; hay que escribirla desde cero (§13).

---

## 9. Alternativas técnicas

### Para la resolución de variantes (tipo_tela + línea/género + color + talla → producto simple)

**Opción 1 — Config explícito `tipo_tela_id → attribute_family_id[]` (recomendada).**
Un array de configuración simple en `src/Config/` (mismo patrón que `acl.php`/`menu.php`, ningún archivo nuevo de infraestructura pesada) que declare, por cada `tipo_tela_id`, qué `attribute_family_id` de Bagisto son válidas para cortar con esa tela. El usuario, al elegir tipo de tela (ya fijado por los rollos), ve solo las familias relevantes (ej. "Camiseta Hombre Durazno", "Camiseta Dama Durazno") en vez de las ~decenas de familias del catálogo completo. Dentro de cada familia elegida, se pide talla (y color, ya conocido del rollo), y se resuelve el `product_id` simple reusando el mismo patrón de joins que ya usa `VarianteConsultaService` (`product_super_attributes` + `product_attribute_values` + `attribute_options`), pero en dirección inversa: family + color + talla → product_id. Es la extensión más chica posible del patrón que ya existe y ya está probado (`VarianteConsultaServiceTest.php`).
Costo: un archivo de config a mantener a mano (bajo, similar a mantener `acl.php`), y un método nuevo (la mitad inversa de `VarianteConsultaService`).
Riesgo: si el catálogo tiene ambigüedad (dos familias distintas ofrecen la misma talla+color para el mismo tipo de tela), la resolución debe detectarlo y bloquear — exactamente lo que pide el criterio de aceptación #7 del usuario.

**Opción 2 — "Perfil de producción" ByLopez como tabla propia (más pesada).**
Una tabla nueva `bylopez_cc_perfiles_produccion` (o similar) que además de mapear tipo_tela+género→familia, guarde reglas de tallas permitidas, nombre visible, etc. Es funcionalmente un superconjunto de la Opción 1.
Costo: una migración + modelo + CRUD administrativo para mantenerla — la Opción C ("Recetas/Perfiles completos") que el propio encargo del usuario ya marca como *"demasiado alcance para resolver primero el problema actual"* (§8 del encargo). Se descarta para esta fase por la misma razón que da el usuario, no por criterio propio.

**Decisión recomendada: Opción 1.** Resuelve el problema real (no inventar/parsear, resolución determinista, detección de ambigüedad) con la pieza más chica posible, reutilizando un patrón de código ya existente y probado, sin nueva tabla ni nuevo CRUD administrativo. Puede evolucionar a la Opción 2 más adelante si aparece una necesidad real (ej. reglas de talla por línea) — no antes.

### Para el peso sobrante por rollo (§8)

**Opción A — Reparto ciego actual, sin cambios.** Descartada: es la causa raíz del riesgo 2 de §8 en cuanto se permita mezclar colores.

**Opción B (recomendada) — Capturar sobrante/merma por rollo en la recepción, aplicar sin repartir.**
Extender el formulario/Request de `RecepcionCorteService` para pedir el sobrante por cada rollo de la orden (usa el pivote `OrdenCorteRollo` que ya tiene las columnas correctas), y que `actualizarRollosAlCerrar()` simplemente escriba esos valores capturados en vez de calcular una cascada. Ningún modelo/tabla nuevo — solo cambia qué datos entran y qué hace el método existente con ellos.

**Opción C — Tabla de "grupos de tela" persistida (la que proponía 04A).**
Agrupar rollos por `tipo_tela_id+referencia+gramaje` en una entidad propia, y repartir el sobrante por grupo en vez de por rollo individual. Es más trabajo que la Opción B para resolver el mismo problema con menos precisión (por grupo, no por rollo real) — se descarta a favor de B, que es más simple y más preciso a la vez.

**Decisión recomendada: Opción B.**

---

## 10. Decisión recomendada (resumen)

1. Cambiar `validarCompatibilidadRollos()` (y su duplicado en frontend) para exigir solo `tipo_tela_id` igual — eliminar las comparaciones de `color` y `gramaje`.
2. Construir la resolución de variantes vía la Opción 1 de §9: config `tipo_tela_id → attribute_family_id[]` + un método nuevo (inverso de `VarianteConsultaService`) que resuelva familia+color+talla → `product_id` simple, bloqueando ante ambigüedad o ausencia.
3. Rediseñar `create.blade.php` para el flujo pedido (rollos → línea/género+tallas → previsualización de variantes resueltas → confirmar), sin tocar la lógica de negocio de `entregar()`/`reportarResultado()`/`cerrar()`/recepción/distribución, que ya son correctas para el invariante pedido.
4. Retirar `cantidad_proyectada` de la UX y de la validación obligatoria (hacerla opcional/con default 0), sin eliminar la columna ni el histórico.
5. Capturar el peso sobrante por rollo en la recepción (Opción B de §8), en vez de mantener el reparto ciego — es la única pieza que exige tocar algo fuera de la pantalla de creación.
6. No introducir ninguna tabla nueva de "grupos/lotes" — ni para variantes (Opción 1 la resuelve sin tabla) ni para peso sobrante (Opción B la resuelve sin tabla).

---

## 11. Plan de implementación por fases (para cuando se apruebe)

**Fase A — Compatibilidad de rollos.**
- `OrdenCorteService::validarCompatibilidadRollos()`: quitar comparación de `color`/`gramaje`.
- `create.blade.php`, función `compatibleWithSelection()`: mismo cambio en JS.
- Tests nuevos: mezcla de dos colores/gramajes con mismo `tipo_tela_id` → éxito; tipo_tela distinto → sigue bloqueando.

**Fase B — Resolución de variantes.**
- Nuevo config `src/Config/tipo_tela_familias.php` (o similar) mapeando `tipo_tela_id → attribute_family_id[]`.
- Nuevo método (ej. `VarianteResolverService::resolver(int $attributeFamilyId, string $color, string $talla): ?int`) — reutiliza el patrón de joins de `VarianteConsultaService`, en dirección inversa. Debe: devolver `null` si no hay match, lanzar/marcar ambigüedad si hay más de un match, nunca devolver un `product_id` de tipo `configurable`.
- Tests nuevos: resolución exitosa, variante inexistente (bloquea), ambigüedad (bloquea), variante inactiva.

**Fase C — Rediseño de `create.blade.php` (UX de los 6 pasos descritos en el encargo).**
- Paso 2 (rollos): sin cambios estructurales más que reflejar la nueva regla de compatibilidad.
- Paso 3-4 (línea/género + tallas → previsualización): nueva UI que use el servicio de la Fase B; agrupación visual por color heredado del rollo, no por input manual.
- Paso 5 (validación): bloquear creación si hay variantes faltantes/ambiguas, con mensaje claro de cuáles.
- Mantener sin tocar: el paso de "Datos generales" (cortador/fecha) y el guardado final (`OrdenCorteService::create()` sigue recibiendo, al final, el mismo shape de `detalles[]` que ya acepta hoy — el cambio es solo en cómo el usuario los construye, no en el contrato del Service).

**Fase D — Retiro funcional de `cantidad_proyectada`.**
- Migración: `cantidad_proyectada` pasa a `nullable()->default(0)` (no se elimina la columna).
- `StoreOrdenCorteRequest`: regla pasa de `required|integer|gt:0` a `nullable|integer|gte:0`.
- `create.blade.php`: quitar el input.
- Dejar intactos, sin cambio funcional: `AgrupadorDetallesCorte::total_proyectado`, `show.blade.php`'s `$totalSolicitado`, las columnas "solicitado" de `card-resultado.blade.php` — seguirán funcionando (mostrarán 0 para órdenes nuevas), sin decisión adicional necesaria salvo que el usuario pida además ocultarlos (pregunta abierta, ver §14).

**Fase E — Peso sobrante por rollo.**
- `StoreRecepcionCorteRequest`/`RecepcionCorteService::create()`: aceptar sobrante/merma por rollo (usa `OrdenCorteRollo`), en vez de un solo campo de orden.
- `actualizarRollosAlCerrar()`: aplicar los valores capturados directamente, eliminar el reparto en cascada.
- Mantener por compatibilidad: si una orden antigua no tiene captura por rollo (histórico), usar el comportamiento actual como *fallback* — no romper el cierre de órdenes ya en curso.
- Tests nuevos: sobrante correcto por rollo con colores mezclados; fallback para datos históricos sin desglose.

**Regla de ejecución (ya vigente en el proyecto, `docs/confeccion/CONFECCION_MASTER.md §31`):** cada fase se implementa, se prueba y se detiene para revisión — no se avanza automáticamente a la siguiente sin aprobación.

---

## 12. Lista exacta de archivos que se tocarían (Fases A-E, cuando se apruebe)

- `src/Services/OrdenCorteService.php` — `validarCompatibilidadRollos()`, `actualizarRollosAlCerrar()`.
- `src/Http/Requests/StoreOrdenCorteRequest.php` — regla de `cantidad_proyectada`.
- `src/Http/Requests/StoreRecepcionCorteRequest.php` — nuevo desglose de sobrante por rollo.
- `src/Services/RecepcionCorteService.php` — lectura del nuevo desglose.
- `src/Resources/views/admin/ordenes-corte/create.blade.php` — rediseño de UI (rollos/líneas-tallas/previsualización), quitar `compatibleWithSelection()` de color/gramaje, quitar input de `cantidad_proyectada`.
- `src/Resources/views/admin/ordenes-corte/partials/card-recepcion.blade.php` — nuevo desglose de sobrante por rollo, si se decide mostrarlo aquí.
- Nuevo: `src/Config/` (mapeo tipo_tela → familias).
- Nuevo: un Service (o método en uno existente) para la resolución inversa de variantes.
- Migración nueva: `cantidad_proyectada` nullable/default 0.
- `src/Database/Migrations/` — posible migración menor si el desglose de sobrante por rollo requiere columnas nuevas en `bylopez_cc_orden_corte_rollos` más allá de las que ya existen (a confirmar en Fase E, probablemente no hace falta: `peso_sobrante`/`peso_consumido` ya existen ahí).

**Cero archivos de `packages/Webkul/`.**

---

## 13. Tests existentes afectados y tests nuevos necesarios

**Afectados (revisar, no necesariamente cambiar):**
- `tests/Feature/OrdenCorteResultadoYRecepcionTest.php` — sus ~10 fixtures que pasan `cantidad_proyectada` como valor fijo seguirán funcionando igual (el campo sigue existiendo, solo deja de ser obligatorio); sus 2 aserciones sobre el campo (líneas 134, 159, 162) seguirán siendo válidas.
- `tests/Feature/OrdenCorteVistaEstadosTest.php` — mismo caso, fixture-only.
- Ningún test de `OrdenCorteDataGridAccionesTest.php` se ve afectado (no toca ninguno de los temas de este rediseño).

**Nuevos, obligatorios:**
- Compatibilidad: mezclar 2+ `tipo_tela_id` iguales con color/gramaje distintos → éxito; `tipo_tela_id` distinto → sigue bloqueando (Fase A).
- Resolución de variantes: éxito, ausencia (bloquea), ambigüedad (bloquea), variante inactiva (Fase B).
- Creación end-to-end con el flujo nuevo: rollos de 2 colores + 2 líneas/géneros + varias tallas → cantidad correcta de `OrdenCorteDetalle` generados, cada uno con el `product_id` correcto (Fase C).
- `cantidad_proyectada` ausente del payload → creación exitosa con `0`/`null` (Fase D).
- Peso sobrante por rollo: colores mezclados, sobrante distinto por rollo, verificar que `Rollo.peso_disponible` de cada uno reflejary lo capturado y no un reparto ciego; caso histórico sin desglose sigue cerrando con el comportamiento actual (Fase E).

---

## 14. Preguntas o decisiones pendientes (requieren respuesta del usuario antes de implementar)

1. **Nombre y ubicación del nuevo mapeo `tipo_tela → familias`** (§9, Opción 1): ¿un archivo de config simple en `src/Config/`, o preferís que sea editable desde el admin (CRUD)? La auditoría recomienda config simple para esta fase, editable a mano por quien mantiene el catálogo — más liviano, sin UI nueva.
2. **Destino final de `cantidad_proyectada` en las vistas de "solicitado"** (§6, Fase D): ¿se deja el número en 0 sin más (mínimo cambio), o se prefiere ocultar directamente esas columnas/tarjetas de "solicitado" en las vistas nuevas ya que dejará de tener sentido pedirlo? No afecta lógica de negocio en ningún caso, es una decisión puramente visual.
3. **Peso sobrante por rollo (§8/§9, Fase E): ¿bodega puede medir sobrante rollo por rollo en la práctica física real, o solo puede dar un número por grupo de color/gramaje?** Esto determina si la captura nueva debe ser por rollo individual (más preciso, recomendado si es viable operativamente) o por grupo de compatibilidad (intermedio). Es una pregunta operativa, no técnica — la auditoría no puede resolverla sola.
4. **¿Se requiere migrar/re-etiquetar las Órdenes de Corte ya cerradas antes de este cambio?** No es necesario técnicamente (el fallback de Fase E cubre el histórico sin desglose), pero si el usuario quiere reconstruir el desglose por rollo de órdenes pasadas para reportes, eso sería trabajo adicional fuera de este alcance — se deja explícitamente fuera salvo que se pida.
5. **Confirmar que el criterio de "ambigüedad bloquea"** (criterio de aceptación #7 del usuario) debe aplicarse también si dos familias distintas mapeadas al mismo `tipo_tela_id` ofrecen la misma talla+color — la auditoría asume que sí (bloquear siempre ante más de un match posible), pero se deja como confirmación explícita antes de codificar la Fase B.

---

## 15. Confirmaciones de cierre de esta auditoría

- `packages/Webkul/` = **0 archivos leídos con intención de modificar, 0 archivos modificados.** Se leyeron `packages/Webkul/Attribute/src/Models/AttributeFamily.php` y `packages/Webkul/Product/src/Models/Product.php` únicamente como referencia de solo lectura, tal como permite la regla del proyecto.
- No se creó ninguna migración, modelo, Service, componente Vue, ni se modificó ningún test. El único archivo creado por esta fase es este mismo documento.
- No se implementa nada de lo descrito en §10-§13 hasta aprobación explícita, punto por punto o en bloque, del usuario.
