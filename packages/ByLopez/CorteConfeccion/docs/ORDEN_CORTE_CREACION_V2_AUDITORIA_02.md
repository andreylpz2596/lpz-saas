# ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md — Auditoría de contraste arquitectónico

Proyecto: ByLopez — Corte y Confección
Alcance: `packages/ByLopez/CorteConfeccion/`
Documento base: `docs/ORDEN_CORTE_CREACION_V2_AUDITORIA.md` (primera auditoría, en adelante **AUD-01**)
Encargo: `ORDEN_CORTE_CREACION_V2_AUDITORIA_02_CONTRASTE_ARQUITECTURA.md`
Fecha: 2026-09-22
Estado: **SOLO AUDITORÍA — NADA IMPLEMENTADO. Pendiente de aprobación explícita.**

> Método: lectura de código (ByLopez y, solo como referencia, `packages/Webkul/`) y consultas **SELECT de solo lectura** contra las dos bases locales disponibles: `lpz_saas` (desarrollo, catálogo real de camisetas) y `lpz_saas_testing` (base de tests). **No se tuvo acceso a staging ni a producción**; donde una conclusión depende de esos ambientes se indica explícitamente, y el Anexo A trae las consultas (parametrizadas por *código*, no por ID) para repetirlas allá.
>
> El único archivo creado es este documento. No se creó ni modificó ninguna migración, Model, Service, Request, Blade/Vue, test ni archivo de `packages/Webkul/`.

---

## Índice de entregables (§13 del encargo → sección)

| # | Entregable | Sección |
|---|---|---|
| 1 | Veredicto Propuesta A | §9.1 |
| 2 | Veredicto Propuesta B | §9.2 |
| 3 | Datos reales del catálogo | §2 |
| 4 | Auditoría de color | §5 |
| 5 | Auditoría de talla / super atributos | §6 |
| 6 | Algoritmo exacto | §10 |
| 7 | Modelo de datos mínimo | §11 |
| 8 | Plan de migración / compatibilidad | §12 |
| 9 | Impacto en UI | §13 |
| 10 | Riesgos / casos límite | §14 |
| 11 | Decisión final | §1 y §9.3 |
| 12 | Plan por fases revisado | §15 |
| 13 | Archivos que se tocarían | §16 |
| 14 | Tests nuevos | §17 |

---

## 1. Resumen ejecutivo

**Siete hallazgos, todos con evidencia de datos reales, cambian el diseño de AUD-01:**

1. **En este catálogo, un producto configurable NO es una línea: es una línea × UN color.** Los 77 configurables tienen 4 hijos (2 en Crop Top), y 74 de ellos tienen un solo color entre sus hijos. "Camiseta Piel Durazno Negro Hombre" (`106DURZ-H`) es un configurable; "… Rojo Hombre" (`110DURZ-H`) es otro. La premisa de la Propuesta B ("el configurable representa la línea que se desea cortar") es falsa para este catálogo.
2. **La llave `familia + color + talla` de la Propuesta A ya es ambigua HOY, no en un futuro hipotético:** hay **13 combinaciones** que devuelven 2 o 3 productos simples (§2.4). No es una colisión de modelado sino de **datos erróneos del catálogo**: 5 configurables tienen hijos etiquetados con el color de otro producto (p. ej. "Terracota Mujer" tiene sus 4 hijos marcados `Rojo`).
3. **La llave `configurable + color + talla` tiene 0 colisiones** y coincide con la semántica nativa de Bagisto (`ConfigurableOption::getOptions()` resuelve variantes siempre dentro de un configurable). Es la frontera correcta **para el último paso**. Pero como el configurable = línea × color, el configurable no se puede "elegir" antes que el color: se **deriva** de `(línea, color)`, y esa derivación también debe exigir unicidad.
4. **La relación "qué se puede cortar con qué tela" ya existe en el catálogo de Bagisto.** Todos los configurables (77/77) y todos los simples hijos (300) tienen el atributo Bagisto `tipo_tela` = opción 41 "Piel Durazno" — **la misma fila de `attribute_options` a la que apunta `Rollo.tipo_tela_id`**. AUD-01 no detectó esto. Consecuencia: ni un config PHP `tipo_tela_id → familias` ni una tabla de perfiles deben duplicar esa relación; se lee del catálogo.
5. **`Rollo.color` es texto libre y su igualdad con la etiqueta Bagisto NO es una invariante.** Nace en un `<input type="text">` de la referencia del proveedor. Los valores reales son `rojo`, `blanco`, `verde cali`, `Vino tinto`, `NEGRO`; hay una referencia `Verde botella` sin opción Bagisto; y hoy "coinciden" en SQL solo porque la colación `utf8mb4_unicode_ci` ignora mayúsculas y tildes (en PHP `'rojo' !== 'Rojo'`). Además, la opción de color ID 2 se llamaba `Verde` (seed) y fue renombrada a `Verde Cali`. Se necesita una referencia estructurada `color_option_id`.
6. **Los IDs numéricos NO son portables entre ambientes** (evidencia directa): el atributo `tipo_tela` es ID **32** en desarrollo y **4180** en testing; la opción de color ID 2 es `Verde Cali` en desarrollo y `Verde` en testing; las familias 2–5 no existen en testing. Un config PHP con IDs queda descartado.
7. **La recepción compara por índice y el formulario puede reordenar filas** (§12.2): hay un bug latente real — `AgrupadorDetallesCorte` preserva índices originales pero `StoreRecepcionCorteRequest` hace `->values()` sobre el orden del POST. Con el V2 (más filas y adicionales resueltos por línea/color/talla) la probabilidad de dispararlo sube. Debe corregirse **antes** del V2.

**Decisión recomendada (detalle en §9.3):** ni A ni B tal como están.

> **"A corregida con la frontera de B"**:
> - **Tela → líneas:** derivado del atributo Bagisto `tipo_tela` de los configurables. Sin config, sin tabla.
> - **Línea = familia de atributos Bagisto** (hoy 1 familia = 1 línea, y el negocio ya crea una familia por línea: *Crop_Top* es una familia aparte). Sin tabla de perfiles **por ahora**, con dos disparadores concretos documentados para introducirla (§7.4).
> - **Color:** nueva columna `color_option_id` (FK a `attribute_options`) en la **referencia de proveedor** (fuente) y en el **rollo** (copia, igual que ya se hace con `tipo_tela_id`). El texto `color` queda como snapshot visual.
> - **Resolución en dos niveles, ambos con unicidad obligatoria:** `(línea, color) → exactamente 1 configurable` y luego `(configurable, color, eje2) → exactamente 1 simple activo`. 0 resultados = error claro; >1 = error de integridad. Nunca por texto, nunca por SKU, nunca por nombre.
> - **Talla:** eje dinámico del configurable (color por `code = 'color'`, el otro super atributo es el eje 2), opciones tomadas de los **hijos reales**, no de la lista de opciones del atributo.
> - **Antes de salir a producción:** corregir 5 configurables del catálogo (trabajo de catálogo en el admin de Bagisto, sin código) — de lo contrario Rojo Dama, Marfil Dama, Lila/Beige Oscuro/Verde Oliva Hombre quedan bloqueados (correctamente) por ambigüedad.

---

## 2. Datos reales del catálogo (base `lpz_saas`)

### 2.1 Atributos relevantes

| id | code | tipo | is_configurable | Observación |
|---|---|---|---|---|
| 23 | `color` | select | 1 | 36 opciones, labels únicos hoy |
| 24 | `size` | select | 1 | S, M, L, XL, **XXL (0 usos)**, T-Unica |
| 31 | `grupo_de_edad_ninos` | select | 1 | 2-4, 6-8, 10-12, 14-16 |
| 32 | `tipo_tela` | select | 0 | **asignado a 77/77 configurables y 300 simples** (= "Piel Durazno", id 41) |
| 29 | `cuello` | select | 0 | todos los configurables = "Redondo" |
| 30 | `sleeve` | select | 0 | todos los configurables = "Manga Corta" |
| — | *gramaje* | — | — | **no existe como atributo Bagisto** |
| — | *género/línea* | — | — | **no existe como atributo Bagisto** (confirma AUD-01) |

`attributes.code` tiene índice único en BD (migración Bagisto `create_attributes_table`: `$table->string('code')->unique()`), así que resolver atributos por código es seguro.

### 2.2 Familias, configurables e hijos

| family_id | code | configurables | simples hijos | ejes (super atributos) | hijos por configurable |
|---|---|---|---|---|---|
| 1 | `default` | 0 | 0 (+3 simples huérfanos de prueba, inactivos, sin `tipo_tela`) | — | — |
| 2 | `Camisetas_nino_DURZ` | 4 | 16 | color + `grupo_de_edad_ninos` | 4 |
| 3 | `Camiseta_Hombre_DURZ` | **34** | 136 | color + size | 4 |
| 4 | `Camiseta_Mujer_DURZ` | **35** | 140 | color + size | 4 |
| 5 | `Crop_Top` | 4 | 8 | color + size | 2 (M, T-Unica) |

- Ningún hijo pertenece a una familia distinta de la de su padre (0 casos).
- Todos los configurables tienen exactamente **2** super atributos, y uno es `color`.
- Todos los hijos tienen valor de color y de eje 2 (0 faltantes); no hay filas duplicadas de `product_attribute_values`.

### 2.3 El patrón real: un configurable por color

Distribución de colores distintos entre los hijos de cada configurable:

| familia | 1 color | 2 colores |
|---|---|---|
| 2 Niño | 4 | 0 |
| 3 Hombre | 32 | **2** (`103DURZ-H`, `107DURZ-H`) |
| 4 Mujer | 34 | **1** (`125DURZN-D`) |
| 5 Crop Top | 4 | 0 |

Ejemplos (lista completa en Anexo A, consulta A3):

| SKU configurable | Nombre | Color de sus hijos |
|---|---|---|
| `106DURZ-H` | Camiseta Piel Durazno Negro Hombre | Negro |
| `109DURZ-H` | Camiseta Piel Durazno Vino Tinto Hombre | Vino Tinto |
| `106DURZ-DAMA` | Camiseta Piel Durazno Negro Mujer | Negro |
| `106DURZ-NINO` | Camiseta Piel Durazno Negro Niño | Negro |
| `106DURZ-TOP-DAMA` | CROP TOP DAMA NEGRO | Negro |

**Conclusión:** en este catálogo la "línea" (Hombre DURZ) es la **familia**, y el **configurable** es "línea × color". Esto contradice la hipótesis de la Propuesta B.

Hallazgo colateral: el prefijo numérico del SKU **no** es un código de color estable — `102DURZ-D` es Blanco y `102DURZ-DAMA` es Beige Oscuro; `124DURZ-D` es Terracota y `124DURZ-H` es Blanco; `202DURZ-D` es Verde Oliva y `202DURZ-DAMA` Curuba. Queda descartado resolver por SKU.

### 2.4 Colisiones reales de `familia + color + eje2` (Propuesta A)

| familia | color | talla | simples | SKUs (padre) |
|---|---|---|---|---|
| 3 Hombre | Beige Oscuro | S | 2 | `102DURZ-H-S` (102DURZ-H); `103DURZ-H-S` (103DURZ-H) |
| 3 Hombre | Lila | M | 2 | `201DURZ-H-M`; `103DURZ-H-M` |
| 3 Hombre | Lila | L | 2 | `201DURZ-H-L`; `103DURZ-H-L` |
| 3 Hombre | Lila | XL | **3** | `201DURZ-H-XL`; `103DURZ-H-XL`; `107DURZ-H-XL` |
| 3 Hombre | Verde Oliva | S, M, L, XL | 2 c/u | `120DURZ…-H-*` (120DURZN-H); `121DURZ-H-*` (121DURZ-H) |
| 4 Mujer | Marfil | S | 2 | `117DURZ-DAMA-S`; `125DURZ-D-S` (125DURZN-D) |
| 4 Mujer | Rojo | S, M, L, XL | 2 c/u | `110DURZ-DAMA-*`; `124DURZ-D-*` |

**Total: 13 combinaciones ambiguas.** Con `configurable + color + eje2`: **0**.

### 2.5 Causa de las colisiones: 5 configurables con hijos mal etiquetados

| Configurable | Nombre (lo que dice ser) | Color real de sus hijos |
|---|---|---|
| `103DURZ-H` | Café Hombre | S = **Beige Oscuro**; M, L, XL = **Lila** (ningún hijo "Cafe") |
| `107DURZ-H` | Azul Oscuro Hombre | S, M, L = Azul Oscuro; XL = **Lila** |
| `121DURZ-H` | Verde Manzana Hombre | los 4 = **Verde Oliva** |
| `124DURZ-D` | Terracota Mujer | los 4 = **Rojo** (no existe opción "Terracota") |
| `125DURZN-D` | Verde Menta Mujer | S = **Marfil**; M, L, XL = Verde Menta |

Impacto operativo si no se corrige: con cualquier algoritmo correcto (que bloquee ante ambigüedad), **Rojo Dama queda bloqueado** — y Rojo es el color con más rollos disponibles hoy (8 de 10 rollos rojos están disponibles). También Marfil Dama (S), Lila/Beige Oscuro/Verde Oliva Hombre, y no existe forma de cortar Café Hombre, Verde Manzana Hombre ni Terracota Dama. **Esto es corrección de catálogo en el admin de Bagisto, no código**, y es prerequisito de salida a producción del V2.

### 2.6 Otros datos de catálogo relevantes

- **Estado desincronizado:** `110DURZ-H` (Rojo Hombre) tiene `status = 0` en `product_attribute_values` (fuente) pero `status = 1` en `product_flat` (índice). Es el único caso. `VarianteConsultaService` lee `product_flat.status` (`VarianteConsultaService.php:51`), mientras que Bagisto decide vendibilidad con el valor EAV (`AbstractType::isSaleable()`, `packages/Webkul/Product/src/Type/AbstractType.php:436-447`, vía `$this->product->status`). `status` es además `value_per_channel = 1`.
- **Opciones `tipo_tela` en desarrollo:** 13 opciones, 12 son de QA (`Tela QA03C3`… `Tela SEC01`) y hay **dos opciones con el mismo label** `Tela QA03C3` (ids 2716 y 2717). Prueba que `attribute_options.admin_name` **no es único** (no hay índice único sobre él).
- **`attribute_families.code`** no tiene índice único en BD; la unicidad solo la valida el request del admin de Bagisto (`AttributeFamilyController`, regla `unique:attribute_families,code`).

---

## 3. Hipótesis crítica 1 — ¿La familia es identidad suficiente?

**Respuesta: no. Ya es ambigua hoy** (13 colisiones, §2.4), aunque la causa actual sea error de datos y no modelado.

Clasificación pedida por el encargo:

| Opción | Veredicto |
|---|---|
| Seguro estructuralmente | **No.** Nada en Bagisto impide que dos configurables de la misma familia tengan hijos con el mismo color+talla; no hay restricción de BD y la edición de variantes lo permite (§2.5 es la prueba). |
| Seguro solo por casualidad | Ni siquiera: la casualidad ya se rompió. |
| **Ya ambiguo hoy** | **Sí.** Familias 3 y 4. |

Además, aun con el catálogo limpio, la familia sigue sin ser identidad del **producto**: la familia 3 contiene 34 configurables. Lo único que la familia identifica correctamente hoy es la **línea** (Hombre DURZ), y solo porque el negocio crea una familia por línea (evidencia: `Crop_Top` es una familia propia, no un producto dentro de `Camiseta_Mujer_DURZ`). Las familias ya incluyen los atributos `cuello` y `sleeve`; si mañana se crea "Camiseta cuello V Hombre DURZ" **dentro** de `Camiseta_Hombre_DURZ`, la familia deja de identificar la línea (ver disparadores en §7.4).

**Demostración concreta de colisión** (pedida por el encargo): `familia 4 + Rojo + M` → `110DURZ-DAMA-M` **y** `124DURZ-D-M`. Una orden de corte "Dama Rojo M" con la Propuesta A tendría dos candidatos; si el algoritmo tomara "el primero", cargaría stock a "Terracota Mujer" cuando se cortó Rojo, o viceversa.

---

## 4. Hipótesis crítica 2 — ¿El configurable es la mejor frontera?

### 4.1 Cómo lo modela Bagisto (lectura de referencia)

- Un simple se relaciona con su padre por `products.parent_id`; `Configurable::createVariant()` (`packages/Webkul/Product/src/Type/Configurable.php:197-238`) crea el hijo con la misma `attribute_family_id` del padre y guarda cada super atributo como `integer_value = attribute_options.id`.
- Los super atributos se obtienen de `product_super_attributes` (`$product->super_attributes()`, `packages/Webkul/Product/src/Helpers/ConfigurableOption.php:86-97`).
- La resolución de la variante en la tienda **siempre ocurre dentro del configurable**: `ConfigurableOption::getOptions()` (`ConfigurableOption.php:104-129`) recorre `$product->variants()` y arma `index[child_id][attribute_id] = option_id`; el carrito guarda `selected_configurable_option` = ID del simple (`Configurable::prepareForCart()`, línea 384+).
- **Bagisto no tiene restricción de BD** que impida dos hijos con la misma combinación dentro de un configurable; lo evita el generador de variantes del admin, no el esquema. Por eso la unicidad debe validarse en runtime también en este nivel.

### 4.2 Comparación

| Criterio | `familia + color + eje2` | `configurable + color + eje2` |
|---|---|---|
| Colisiones hoy | **13** | **0** |
| Semántica Bagisto | La familia es un *esquema* (conjunto de atributos), no una identidad de producto | Es exactamente cómo Bagisto resuelve variantes |
| Estabilidad futura | Se rompe con cualquier segundo configurable del mismo color (error de datos o línea nueva en la misma familia) | Se rompe solo si un configurable tiene dos hijos con la misma combinación (anomalía que Bagisto ya evita en su UI) |
| Detecta catálogo corrupto | Parcialmente: `Lila S` resolvería "bien" por accidente mientras `Lila M` falla | Sí, a nivel de color completo: "Lila aparece en 3 productos de la línea Hombre" |

**Veredicto:** el configurable es la frontera correcta **para el último paso**. Pero, como el configurable de este catálogo ya fija el color (§2.3), el configurable **no puede elegirse sin conocer el color**: se deriva de `(línea, color)`. El algoritmo correcto es en dos niveles (§10), y ambos niveles exigen unicidad. Una ventaja adicional: el algoritmo funciona igual si en el futuro el negocio pasa a "un configurable por línea con todos los colores" (el modelo que Bagisto está pensado para soportar) — en ese caso `(línea, color)` simplemente devuelve siempre el mismo configurable.

---

## 5. Hipótesis crítica 3 — Auditoría de color

### 5.1 Cómo nace `Rollo.color` (trazado completo)

| Paso | Archivo:línea | Qué hace |
|---|---|---|
| 1. Referencia de proveedor | `views/admin/proveedores-tela/referencia-form.blade.php:85-93`, `show.blade.php:800-813`, `form.blade.php:255` | **`type="text"`**, el usuario escribe el color libremente |
| 2. Validación referencia | `StoreProveedorTelaRequest.php:224` | `'referencias.*.color' => ['nullable','string','max:255']` + obligatorio en `after()` (l. 271-275). **Sin relación con `attribute_options`** |
| 3. Deduplicación referencia | `StoreProveedorTelaRequest.php:360-367`, `ProveedorTelaService.php:149-156` | Clave `lower(trim(color))` — reconoce el problema de mayúsculas, pero solo para duplicados |
| 4. Compra de tela | `StoreCompraTelaRequest.php:108,157-159` | `rollos.*.color` texto; exige `$referencia->color !== $rollo['color']` → error (comparación PHP **estricta y sensible a mayúsculas**) |
| 5. Creación del rollo | `CompraTelaService.php:176` | `'color' => $referenciaTela?->color ?? $rolloData['color']` — copia el texto de la referencia |
| 6. Alta manual de rollo | `StoreRolloRequest.php:19-20`, `RolloService::create()` | `color` y `tipo_tela` **texto libre**, sin referencia ni `tipo_tela_id` |
| 7. Edición de rollo | `UpdateRolloRequest.php:48`, `RolloService::update()` (l. 22-42) | `color` editable **en cualquier estado, incluso `reservado_en_corte`** (solo se bloquean los pesos) |

Conclusión: el color **nunca** se origina en una opción de Bagisto. Es texto libre en origen (referencia) y en el camino alternativo (alta/edición manual).

### 5.2 Valores reales y comparación contra las opciones Bagisto

Rollos (25 en total):

| tipo_tela_id | `Rollo.color` | rollos | usables | Opción Bagisto equivalente |
|---|---|---|---|---|
| NULL | `NEGRO` | 1 (alta manual, id 1, `reservado_en_corte`) | 0 | Negro (4) — solo por colación |
| 41 | `blanco` | 8 | 2 | Blanco (5) — solo por colación |
| 41 | `rojo` | 10 | 8 | Rojo (1) — solo por colación |
| 41 | `verde cali` | 2 | 0 | Verde Cali (2) — solo por colación |
| 41 | `Vino tinto` | 4 | 4 | Vino Tinto (49) — solo por colación |

Referencias (8): `verde cali`, `rojo`, `blanco`, `Vino tinto`, `VERDE PETROLEO`, `BEIGE CLARO`, **`Verde botella` (sin opción Bagisto)**, `QTAR` (QA).

### 5.3 ¿Es `where admin_name = Rollo.color` una invariante? No.

Pruebas ejecutadas (solo lectura) sobre la colación real de las tres columnas (`utf8mb4_unicode_ci`):

| Comparación | Resultado SQL (`unicode_ci`) | Resultado en PHP / `utf8mb4_bin` |
|---|---|---|
| `'rojo' = 'Rojo'` | 1 (igual) | **distinto** |
| `'Cafe' = 'Café'` | 1 (igual) | **distinto** |
| `'rojo ' = 'Rojo'` (espacio final) | 1 (igual, PAD SPACE) | **distinto** |
| `' rojo' = 'Rojo'` (espacio inicial) | **0** | distinto |
| `'Verde botella'` vs cualquier opción | sin match | sin match |

Casos adicionales que rompen la igualdad textual, **todos presentes en los datos**:

- **Opción renombrada:** la opción de color ID 2 es `Verde` en la base de tests (seed original de Bagisto) y `Verde Cali` en desarrollo → alguien la renombró. Un rollo guardado como "Verde" antes del cambio dejaría de coincidir.
- **Label vs traducción:** la opción 57 tiene `admin_name = 'Verde Pastel'` pero traducción `es = 'Verde Paste'`. Según qué campo se compare, el resultado cambia.
- **Alias:** el nombre comercial del producto dice "Gris" / "Petróleo" / "Amarillo Pastel" y la opción es `Gris Rato` / `Verde Petroleo` / `Amarillo Paste`.
- **Labels no únicos:** no hay índice único sobre `attribute_options.admin_name` (en `tipo_tela` ya hay dos opciones `Tela QA03C3`).

**Veredicto:** la igualdad textual hoy "funciona" en SQL por un efecto lateral de la colación, no por diseño; en PHP falla; y cualquier renombre la rompe en silencio. Se descarta como solución, como exige el encargo.

### 5.4 Alternativa evaluada: referencia estructurada

**¿Hace falta?** Sí. Sin un `attribute_option_id` de color en el lado de la tela no existe forma determinista de pasar de "rollo" a "variante Bagisto".

**¿Dónde?** La fuente del color es la **referencia de proveedor**; el rollo la copia. Recomendación, siguiendo el patrón que el módulo **ya usa con `tipo_tela_id`** (existe en la referencia y se copia al rollo en `CompraTelaService.php:173`):

- `bylopez_cc_proveedor_tela_referencias.color_option_id` — maestro (lo elige el usuario de un select de colores Bagisto).
- `bylopez_cc_rollos.color_option_id` — copia al crear el rollo desde la compra; es lo que lee el resolvedor (un salto, funciona también si algún rollo no tiene referencia).
- `color` (texto) se mantiene en ambas tablas como **snapshot visual**, rellenado desde la etiqueta de la opción al guardar. Las vistas deberían mostrar la etiqueta actual de la opción y caer al snapshot si no hay opción.

**¿Aporta valor real?** Sí: resolución determinista, inmune a renombres, mayúsculas, tildes, alias y traducciones; habilita filtros/reportes por color sin normalizar texto; permite mostrar el swatch del color.

**¿Rompe algo?** No, si la columna es `nullable` y el texto se conserva: nada del flujo actual lee la columna nueva. Riesgos a cubrir:
- La validación `StoreCompraTelaRequest.php:157-159` (color del rollo = color de la referencia) debe pasar a comparar `color_option_id`, o desaparecer porque el color ya no se envía desde el cliente sino que se copia de la referencia.
- Editar el color de un rollo (`UpdateRolloRequest`) debe editar el ID (select), no el texto, o quedar bloqueado cuando el rollo tiene referencia (decisión D-4, §18).
- FK con `restrictOnDelete` (igual que `tipo_tela_id` hoy): borrar en Bagisto una opción de color ya usada por un rollo fallará. Es el comportamiento deseado para preservar historia, y es consistente con lo existente.

**¿Otra relación ya disponible?** No. Se revisó: la referencia no tiene ningún vínculo con `attribute_options` de color; el atributo `familia_colores` (id 33) solo tiene 2 opciones sin uso ("colores pastel", "colores oscuros"). La única relación estructurada existente es la de `tipo_tela`.

**Migración de rollos existentes y fallback histórico:** ver §12.1.

---

## 6. Hipótesis crítica 4 — Talla / super atributos dinámicos

El patrón correcto ya existe y está probado en `VarianteConsultaService.php:71-83,139-140` (color por `code = 'color'`, el eje 2 es "el otro super atributo"). Evidencia de que no se puede fijar: Niño usa `grupo_de_edad_ninos`, Crop Top solo tiene M y T-Unica, y la opción XXL existe en el atributo `size` pero **no tiene ningún hijo**.

Respuestas:

1. **Detectar color:** `product_super_attributes` del configurable ⨝ `attributes` donde `code = 'color'`. Nunca por `attribute_id` fijo (23 en dev, puede diferir en otro ambiente).
2. **Detectar el eje 2:** el único super atributo con `code <> 'color'`. Su etiqueta visible = `attributes.admin_name` (p. ej. "Talla", "grupo de edad (niños)").
3. **Listar opciones realmente disponibles:** tomar los `integer_value` del eje 2 **en los hijos simples activos del configurable resuelto para ese color**, no `attribute_options` del atributo. Ordenar por `attribute_options.sort_order`. Así XXL aparece automáticamente el día que exista un hijo XXL, y nunca antes.
4. **Evitar tallas inexistentes para un color:** la matriz se construye por celda `(línea, color, talla)`; cada celda existe solo si hay un hijo con esa combinación. Las columnas de talla de una línea son la unión de tallas de los colores seleccionados; las celdas sin hijo se muestran deshabilitadas con el motivo ("no existe en catálogo", "inactiva", "ambigua"). Nada se "adivina".
5. **Más de 2 super atributos:** en el V2 el configurable se **excluye con motivo explícito** ("configurable con 3 ejes no soportado"), es decir, falla cerrado. Si algún día se necesita, el algoritmo se generaliza a "el color lo fija el rollo; el usuario elige una opción por cada eje restante" y la selección pasa a ser un mapa `attribute_id → option_id`. No se recomienda construirlo hoy: no hay ningún caso en el catálogo (77/77 tienen 2 ejes).

Líneas distintas pueden tener ejes distintos (Hombre = size, Niño = grupo de edad): la UI muestra una matriz por línea, cada una con sus propias columnas.

---

## 7. Hipótesis crítica 5 — ¿Config PHP o Perfil de Corte persistido?

### 7.1 Pregunta principal

> ¿La relación "qué producto/línea puede fabricarse con qué tipo de tela" es configuración técnica estable o dato maestro del negocio?

**Es dato maestro — y ya existe, en el lugar correcto: el catálogo de Bagisto.** Cada producto lleva su `tipo_tela` (§2.1), la misma opción que el rollo. Quien crea un producto nuevo ya declara con qué tela se hace. Duplicar esa relación en ByLopez (config o tabla) crea una segunda fuente de verdad que se desalinea en cuanto alguien cree un producto nuevo y olvide actualizar la otra. La regla de gobierno de datos que se desprende es simple: **"un producto se puede cortar de la tela T si y solo si tiene `tipo_tela = T` en Bagisto."**

Lo que el catálogo **no** tiene es el concepto de "línea" como algo distinto de la familia. Hoy no hace falta (§3), pero es donde un perfil podría aportar en el futuro.

### 7.2 Diseño 1 — Config PHP (`tipo_tela_id => [attribute_family_id, …]`) — descartado

| Riesgo | ¿Se confirma? |
|---|---|
| IDs hardcodeados por ambiente | **Sí**, con evidencia directa (§8) |
| Cambios de catálogo requieren deploy | Sí, y además duplica un dato que Bagisto ya tiene |
| No representa el configurable | Correcto, pero ese no es el problema: el configurable no se debe guardar (ver 7.3) |
| Activar/desactivar líneas | Requiere deploy |
| Escalabilidad | Cada familia nueva = cambio de código |
| Además | La llave que alimenta (`familia + color + talla`) es ambigua hoy |

### 7.3 Diseño 2 — Perfil de Corte tal como se plantea — descartado en esa forma

Campo por campo:

| Campo propuesto | Veredicto |
|---|---|
| `tipo_tela_id` | **Duplica** el atributo Bagisto `tipo_tela` del producto. No guardar. |
| `configurable_product_id` | **Incorrecto para este catálogo:** fijaría UN color. Para cubrir los 34 colores de Hombre harían falta 34 perfiles, que no es un perfil sino una copia manual del catálogo, y se rompe cada vez que se crea un color nuevo. No guardar. |
| `attribute_family_id` | Es el único dato que no es derivable **si** la línea deja de coincidir con la familia. Hoy es derivable. |
| `linea` / `nombre` | Cosmético hoy (la familia ya tiene `name`: "Camiseta Hombre DURZ"). |
| `activo`, `orden` | Útiles solo si el negocio necesita habilitar/ocultar líneas para corte independientemente del estado del producto (decisión D-1). |

### 7.4 Recomendación: sin tabla ahora, con disparadores explícitos

**Línea de corte = familia Bagisto**, filtrada por los configurables activos con `tipo_tela = T` y ejes soportados. Cero tabla, cero config, cero CRUD. El resolvedor recibe la línea como un objeto de valor (`LineaCorte` = familia [+ filtros futuros]) para que agregar una tabla después sea un cambio **aditivo**.

Introducir la tabla `bylopez_cc_lineas_corte` **solo** cuando ocurra uno de estos disparadores:

1. Aparece una **segunda línea legítima dentro de la misma familia** (p. ej. cuello V o manga larga en `Camiseta_Hombre_DURZ`). Síntoma visible: el resolvedor empezará a reportar `color_ambiguo` para colores que existan en ambas líneas. No hay corrupción silenciosa; la creación se bloquea con mensaje claro.
2. El negocio necesita **habilitar/deshabilitar líneas para corte** sin tocar el estado del producto en la tienda.

Forma de esa tabla cuando llegue el momento: `id, nombre, attribute_family_id, filtros (atributo → opción, p. ej. cuello = Redondo), activo, orden`. **Nunca** `configurable_product_id` ni `tipo_tela_id`: ambos se derivan. La pertenencia de configurables a la línea se calcula, no se enumera, así los colores nuevos entran solos.

Alternativa evaluada y descartada: perfil con lista explícita de configurables (pivote perfil ↔ configurable). Es 100 % determinista, pero exige mantener 77 filas hoy y agregar una por cada color nuevo; el olvido genera errores de "no existe variante" aunque el catálogo sí la tenga. Mantenimiento peor que el problema que resuelve.

---

## 8. IDs entre ambientes

Evidencia (dos bases locales; no se tuvo acceso a staging/producción):

| Elemento | `lpz_saas` (dev) | `lpz_saas_testing` |
|---|---|---|
| atributo `tipo_tela` | id **32** | id **4180** |
| atributo `color` | id 23 | id 23 (coincide por el seed de Bagisto) |
| atributo `grupo_de_edad_ninos` | id 31 | **no existe** |
| opción color id 2 | `Verde Cali` | `Verde` |
| familias 2–5 | existen | **no existen** |
| opciones `tipo_tela` | 13 (12 de QA) | 16 |

El catálogo de camisetas se creó a mano en el admin. **No hay ninguna garantía** de que producción tenga los mismos IDs que desarrollo (salvo que producción se haya creado copiando la base, y eso tampoco se sostiene en el tiempo).

| Identificador alternativo | ¿Único en BD? | ¿Estable? | Veredicto |
|---|---|---|---|
| `attributes.code` | Sí (índice único) | Sí | **Usar** para localizar `color`, `tipo_tela`, `status` |
| `attribute_families.code` | No (solo validación del admin) | Sí en la práctica | Aceptable para seeds futuros, no para identidad |
| SKU del configurable | Sí | Sí, pero fija un color y la numeración es inconsistente (§2.3) | No usar como llave de línea |
| Label de opción | **No** | **No** (renombres probados) | Descartado |
| FK a `attribute_options` en la misma BD | — | Sí por construcción | **Usar** (`color_option_id`, igual que `tipo_tela_id`) |
| Relación derivada del catálogo | — | Sí | **Usar** (tela → configurables) |

**El diseño recomendado no contiene ningún ID numérico de configuración.** Los atributos se buscan por código; el color y la tela se guardan como FK dentro de la misma base; la línea y el configurable se derivan en cada ambiente de sus propios datos. El único paso textual es el backfill inicial del color de las referencias (§12.1), que se ejecuta una vez por ambiente, con regla estricta y resultado revisable.

---

## 9. Veredictos

### 9.1 Propuesta A (AUD-01)

| | |
|---|---|
| **Conservar** | Cambiar la compatibilidad de rollos a solo `tipo_tela_id`. Detección dinámica del eje 2. Bloquear ante ausencia o ambigüedad. No devolver nunca un configurable. No crear un atributo "género" en Bagisto. Retirar `cantidad_proyectada`. Capturar sobrante por rollo. |
| **Corregir** | (a) Tela → líneas **no** requiere config: se deriva del atributo `tipo_tela` del catálogo (AUD-01 §4 afirmaba que no existía ninguna relación; existe a nivel de producto). (b) La resolución pasa por el configurable, en dos niveles con unicidad. (c) El color se resuelve por `color_option_id`, no por texto. (d) Compatibilidad: además de `tipo_tela_id` igual, exigir `tipo_tela_id` **no nulo** (hoy `null === null` pasa en backend `OrdenCorteService.php:356` y en frontend `create.blade.php:450`). (e) `cantidad_proyectada`: **sin** migración a `nullable` (§11 de este documento). |
| **Descartar** | La llave `familia + color + talla`. El config PHP con IDs numéricos. |

### 9.2 Propuesta B (Perfil de Corte)

| | |
|---|---|
| **Tiene sentido** | Resolver dentro del configurable (frontera Bagisto). Tratar "línea" como concepto de negocio explícito. Si se persiste algo, hacerlo en BD (no en config). |
| **No tiene sentido (hoy)** | Guardar `configurable_product_id` (fija un color). Guardar `tipo_tela_id` (duplica el catálogo). Crear una tabla + CRUD cuando la línea coincide 1:1 con la familia y ninguna regla de negocio lo exige todavía. |
| **Forma correcta si llega** | Tabla fina de líneas con familia + filtros de atributo, pertenencia derivada (§7.4). |

### 9.3 Decisión final recomendada

**"A corregida con la frontera de B"**, sin tabla de perfiles en la primera entrega:

1. Tela → líneas derivado del catálogo (`tipo_tela` en el configurable).
2. Línea = familia (valor `LineaCorte`, ampliable).
3. Color estructurado `color_option_id` en referencia y rollo.
4. Resolución `(línea, color) → 1 configurable → (eje2) → 1 simple activo`, fallando cerrado.
5. Corrección de los 5 configurables del catálogo antes de salir a producción.
6. Recepción por identidad de detalle antes del V2.

Razones: es el diseño más chico que es **correcto con los datos que existen hoy** (A no lo es); no introduce IDs por ambiente; no duplica información que Bagisto ya mantiene; coincide con la semántica nativa de variantes de Bagisto; y deja un camino aditivo y con disparadores claros hacia un perfil persistido.

---

## 10. Algoritmo exacto recomendado

Un único Service de solo lectura (mismo principio que `VarianteConsultaService`: ningún otro punto del módulo consulta las tablas de producto de Bagisto). Consultas por lote, sin N+1.

```
ENTRADA
  T  = tipo_tela_id común de los rollos           (bylopez_cc_rollos.tipo_tela_id, no nulo)
  C  = color_option_id de un grupo de rollos       (bylopez_cc_rollos.color_option_id, no nulo)
  F  = attribute_family_id de la línea elegida
  E  = option_id del eje 2 elegido por el usuario

0. Resolver por CÓDIGO (una vez por request):
     attrColor    = attributes.id WHERE code = 'color'
     attrTipoTela = attributes.id WHERE code = 'tipo_tela'
     attrStatus   = attributes.id WHERE code = 'status'
     canal        = core()->getDefaultChannel()->code      (no getCurrentChannel(); ver 04A_STOCK_VARIANTE_DISENO.md §6)
   Falta alguno → error de configuración (falla cerrado).

1. Configurables candidatos de la línea:
     K = products p
         WHERE p.type = 'configurable'
           AND p.attribute_family_id = F
           AND EXISTS pav(p.id, attrTipoTela, integer_value = T)

2. Ejes por configurable (product_super_attributes ⨝ attributes):
     válido(k) ⇔ ejes(k) contiene attrColor  Y  |ejes(k)| = 2
     eje2(k)   = el único eje ≠ attrColor
   k no válido → excluido, motivo 'ejes_no_soportados' (se informa, no se ignora en silencio).

3. Hijos (una sola consulta para todos los k):
     H = products s
         WHERE s.parent_id IN K(válidos) AND s.type = 'simple'
     con: color(s) = pav(s, attrColor).integer_value
          e2(s)    = pav(s, eje2(s.parent_id)).integer_value
          activo(s)= pav(s, attrStatus, channel = canal).boolean_value

4. Nivel 1 — (línea, color) → configurable:
     K_C = { k ∈ K(válidos) : ∃ s ∈ H, s.parent_id = k, color(s) = C }
     (se consideran TODOS los hijos, activos o no: la ambigüedad es estructural)
     |K_C| = 0 → error 'sin_producto_para_color'   ("La línea F no tiene producto para el color C")
     |K_C| > 1 → error 'color_ambiguo'             (listar SKUs de K_C: integridad de catálogo)
     k* = único elemento de K_C

5. Estado del configurable:
     activo(k*) por EAV en el canal; inactivo → 'producto_inactivo'   (regla sujeta a D-3)

6. Nivel 2 — (configurable, color, eje2) → simple:
     V = { s ∈ H : s.parent_id = k*, color(s) = C, e2(s) = E }
     |V| = 0 → 'talla_no_disponible'
     |V| > 1 → 'variante_duplicada'   (integridad)
     v = único elemento de V
     activo(v) = false → 'variante_inactiva'   (regla sujeta a D-3)

7. Postcondiciones (defensivas, deben cumplirse por construcción):
     v.type = 'simple'  AND  v.parent_id = k*  AND  v.attribute_family_id = F

SALIDA
  product_id = v.id
  snapshot: sku(v), nombre(k*), label color C, label eje E, etiqueta del eje (attributes.admin_name), nombre de línea (attribute_families.name)
```

Notas de diseño:

- **Estado por EAV, no por `product_flat`**, porque el índice puede estar desalineado (caso real `110DURZ-H`, §2.6) y porque es lo que usa Bagisto (`isSaleable()`). No se exige stock (`isSaleable()` también exige cantidad ≥ 1; para producir eso es irrelevante).
- **La matriz de la UI y el guardado usan el mismo código.** El servidor **re-resuelve** cada selección dentro de la transacción de creación; nunca confía en un `product_id` enviado por el cliente. Si el catálogo cambió entre la previsualización y el guardado, se detecta.
- **Encaje con el esquema real:** verificado con consultas equivalentes sobre `lpz_saas` (Anexo A4/A5). Resultados esperados con el catálogo actual: `Hombre + Negro + M` → `106DURZ-H-M`; `Hombre + Lila` → `color_ambiguo` (201DURZ-H, 103DURZ-H, 107DURZ-H); `Hombre + Azul Oscuro + XL` → `talla_no_disponible`; `Dama + Rojo` → `color_ambiguo` (110DURZ-DAMA, 124DURZ-D); `Hombre + Cafe` → `sin_producto_para_color`; `Niño + Negro + 6-8` → hijo de `106DURZ-NINO`.
- Un configurable con dos colores (`107DURZ-H`) no bloquea sus hijos correctos (Azul Oscuro S/M/L resuelven); el diagnóstico lo reporta como advertencia.

---

## 11. Modelo de datos mínimo recomendado

**Nuevo (estrictamente necesario):**

| Tabla | Columna | Tipo | Motivo |
|---|---|---|---|
| `bylopez_cc_proveedor_tela_referencias` | `color_option_id` | `unsignedInteger` nullable, FK `attribute_options.id` `restrictOnDelete` | Fuente estructurada del color (§5.4) |
| `bylopez_cc_rollos` | `color_option_id` | ídem | Copia usada por el resolvedor (mismo patrón que `tipo_tela_id`) |
| `bylopez_cc_recepciones_corte_detalles` | `orden_corte_detalle_id` | `foreignId` nullable → `bylopez_cc_ordenes_corte_detalles` | Emparejar recepción por identidad (§12.2); nullable por los históricos |

**Explícitamente NO se agrega:**

- Tabla de perfiles/líneas (§7.4).
- Archivo de config con IDs (§7.2, §8).
- Cambio de nulabilidad o default de `cantidad_proyectada` (§11.1).
- Columnas nuevas en `bylopez_cc_ordenes_corte_detalles`: `product_id` (simple) ya identifica línea+color+talla; `genero_linea` (snapshot del nombre de la línea) y `talla` (snapshot del label del eje, columna `NOT NULL` que hoy se guarda `''`) ya existen y pasan a llenarse con datos reales. El padre es derivable por `products.parent_id`. `variant_id` no se usa (AUD-01 §3.3).

### 11.1 `cantidad_proyectada` — segunda validación

- Columna: `unsignedInteger` **NOT NULL sin default** (`2026_05_07_000003_…:18`).
- El Service ya escribe `$detalle['cantidad_proyectada'] ?? 0` (`OrdenCorteService.php:102`) y fuerza `0` en adicionales (l. 170). Único bloqueo: la regla `required|integer|gt:0` (`StoreOrdenCorteRequest.php:54`).
- **Tratamiento recomendado:** órdenes nuevas guardan `0` (vía el `?? 0` existente); quitar la regla y el input; **no** migrar a `nullable` — AUD-01 §11 Fase D lo proponía, pero crearía dos estados equivalentes (`NULL` y `0`) sin ninguna necesidad. **Sin migración.**
- Vistas con significado residual: KPI "Solicitado" (`show.blade.php:7,95-96`), textos "solicitado:" (`card-resultado.blade.php:10,41,156,312,338`), `total_proyectado` (`AgrupadorDetallesCorte.php:29`). Recomendación: ocultarlos cuando la orden no tenga proyección (todas las del V2) y mostrarlos como "Proyectado (histórico)" solo en órdenes antiguas con valores > 0 — preserva la historia sin ruido (decisión D-7).
- La decisión de negocio no se reabre: `cantidad_proyectada` no participa del flujo nuevo.

---

## 12. Plan de migración y compatibilidad

### 12.1 Color de referencias y rollos

1. **Migración de esquema:** agrega las dos columnas `color_option_id` nullable (§11). No toca `color`.
2. **Backfill en la misma migración** (patrón ya usado por `2026_06_08_000001_…`, que backfillea `proveedor_tela_tipos`), con regla estricta:
   - Referencias: normalizar `lower(trim(color))` y comparar contra `lower(trim(admin_name))` de las opciones del atributo `code = 'color'`. **Asignar solo si hay exactamente 1 coincidencia.** 0 o >1 → queda `NULL`.
   - Rollos con referencia: copiar `color_option_id` **de su referencia** (no re-matchear su propio texto, que puede haber sido editado).
   - Rollos sin referencia (alta manual): mismo match estricto sobre su texto.
   - Resultado esperado en desarrollo: referencias 3, 4, 5, 3319, 3323, 3325 mapeadas; `Verde botella` (2987) y `QTAR` (3324, QA) quedan `NULL`; 24 rollos mapeados vía referencia; rollo 1 (`NEGRO`) mapeado a Negro pero inservible para el V2 por no tener `tipo_tela_id`.
3. **Pendientes visibles, no silenciosos:** las referencias con `color_option_id NULL` muestran un aviso "Color sin vincular al catálogo" en la ficha del proveedor, donde el usuario lo elige del select.
4. **Nunca** se hace match textual en runtime. El texto solo se usa en este backfill único y auditable.

**Históricos:**
- Órdenes ya creadas/cerradas: **no se ven afectadas**; sus detalles tienen `product_id` y snapshots propios, no se re-resuelven.
- Rollos sin `color_option_id` o sin `tipo_tela_id`: siguen visibles y utilizables en todo lo existente, pero **no seleccionables** en la creación V2 (con motivo explícito en la UI).

### 12.2 Recepción por índice — clasificación: **corregir ANTES del V2**

Payload actual por fila: `product_id`, `talla`, `cantidad_recibida`, `cantidad_defectuosa`, `observacion`. **No existe `orden_corte_detalle_id`** ni en el payload ni en `bylopez_cc_recepciones_corte_detalles` (§11).

Emparejamiento actual, en **tres** lugares y con **tres** órdenes distintos:

| Lugar | Cómo empareja |
|---|---|
| `StoreRecepcionCorteRequest.php:52-61` | `pluck('cantidad_real_cortada')` con `orderBy('id')`, por índice |
| `RecepcionCorteService.php:74-87` | `$orden->detalles` (relación **sin `orderBy`**, `OrdenCorte.php:58-60`), por índice |
| `RecepcionCorteController.php:66-78` | `$orden->detalles->values()[$index]` |

**Bug latente demostrable:** `card-recepcion.blade.php:126,181,193` agrupa **todos** los detalles (solicitud + adicionales) con `AgrupadorDetallesCorte::agrupar()`, que preserva a propósito el índice original (`AgrupadorDetallesCorte.php:18-23`) para generar `name="detalles[$i]"`. Pero si un mismo `product_id + genero_linea` aparece en índices no contiguos (p. ej. solicitud A en índice 0, solicitud B en 1, **adicional de A** en 2), el HTML sale en orden 0, 2, 1. PHP conserva ese orden al parsear el POST y `StoreRecepcionCorteRequest.php:14-17` hace `->values()` → la fila 2 pasa a ser la 1. Resultado: la cantidad recibida de "A adicional" se compara con la reportada de "B". Consecuencias: errores falsos de "observación requerida"/"no autorizado", o una diferencia real que **no** dispara el control de autorización. Los datos guardados en `recepciones_corte_detalles` sí quedan con el `product_id` correcto; lo que falla es el control.

Hallazgos relacionados:
- La vista de solo lectura (`card-recepcion.blade.php:36-40`) empareja por "primer detalle con mismo `product_id` y `talla`", lo que da el reportado equivocado para duplicados.
- La pantalla alternativa `recepciones-corte/create.blade.php:60-62` (ruta activa `recepciones_corte.create`) permite **cambiar el `product_id` de cada fila** con un `<select>` de todo el catálogo (limitado a 500 por `ProductLookupService.php:93`), y el backend no valida que ese producto pertenezca a la orden. Luego `DisponibilidadConfeccionService` distribuye por el `product_id` de la recepción.

**Por qué antes del V2:** el V2 multiplica las filas por orden (colores × líneas × tallas) y, cuando los adicionales se resuelvan por línea/color/talla (§15, F5), es natural que el cortador agregue "más Negro M" como adicional de un producto ya solicitado, que es exactamente el disparador. El arreglo es pequeño y aislado: enviar `orden_corte_detalle_id` oculto por fila, validar que pertenece a la orden y que no se repite, comparar por ID en los tres lugares, derivar `product_id`/`talla` del detalle (no del cliente) y persistir el ID en la recepción. Históricos: `NULL` → la vista de solo lectura mantiene el emparejamiento actual como fallback.

### 12.3 Ambientes

No hay datos de configuración que trasladar entre ambientes. Por cada ambiente: correr migraciones (backfill incluido), revisar referencias sin color vinculado y correr el diagnóstico de catálogo (§15, F2) antes de habilitar la creación V2.

---

## 13. Impacto en UI

**Creación (reemplaza `create.blade.php`):**

1. *Datos generales* (cortador, fechas, observación): sin cambios.
2. *Rollos:* misma selección; compatibilidad = mismo `tipo_tela_id` no nulo. Rollos sin `tipo_tela_id` o sin color vinculado aparecen deshabilitados con el motivo. Resumen por color: chip con swatch, cantidad de rollos y kg.
3. *Líneas:* casillas con las líneas disponibles para esa tela (derivadas del catálogo; hoy: Hombre, Mujer, Niño, Crop Top).
4. *Matriz por línea:* filas = colores de los rollos; columnas = opciones del eje de esa línea (tallas o grupos de edad), ordenadas por `sort_order`. Cada celda es una casilla "cortar"; las no resolubles aparecen deshabilitadas con el motivo en tooltip ("no existe", "inactiva", "color ambiguo en catálogo: 110DURZ-DAMA / 124DURZ-D"). Atajos "toda la fila" / "toda la columna".
5. *Previsualización:* lista de SKUs resultantes agrupados por línea y color, con el total de variantes. Los errores bloqueantes, resumidos arriba.
6. *Guardar:* el payload envía **selecciones** `(línea, color_option_id, eje_option_id)`, no `product_id`; el servidor resuelve.

Sin input de cantidad. La cantidad real la reporta el cortador (sin cambios).

**Reporte del cortador:** las filas originales no cambian (ya se muestran resueltas y solo se edita la cantidad). Los **adicionales** hoy usan el buscador genérico de todo el catálogo (permite configurables y productos de otra tela); en F5 pasan a un selector línea × color (de los rollos de la orden) × talla con el mismo resolvedor. Se ocultan los textos "solicitado:" en órdenes V2.

**Recepción:** filas con `orden_corte_detalle_id` oculto; producto y talla no editables; la pantalla alternativa `recepciones-corte/create` se alinea o se redirige a la ficha de la orden. El sobrante por rollo o por grupo físico se agrega en F3.

**Proveedores/referencias:** el color pasa de texto libre a select de colores Bagisto (con swatch). **Rollos:** el color se muestra desde la opción; la edición manual depende de D-4.

---

## 14. Riesgos y casos límite (§9 del encargo)

| # | Caso | Comportamiento con el diseño recomendado | Evidencia / estado hoy |
|---|---|---|---|
| 1 | Dos configurables en la misma familia | Normal (uno por color). Solo es problema si comparten color → `color_ambiguo` | 34 y 35 configurables en fam. 3 y 4 |
| 2 | Mismo color/talla en configurables distintos | `color_ambiguo` con SKUs; bloquea ese color en esa línea hasta corregir el catálogo | **13 combinaciones reales** (§2.4) |
| 3 | Color del rollo sin opción Bagisto | `color_option_id NULL` → rollo no seleccionable; tampoco existe producto de ese color | Referencia real `Verde botella` |
| 4 | Opción Bagisto renombrada tras crear el rollo | Sin efecto (resolución por ID); la vista muestra el label nuevo | Opción 2: `Verde` → `Verde Cali` |
| 5 | Variante simple inactiva | `variante_inactiva`; celda deshabilitada (D-3) | 297/297 hijos activos hoy |
| 6 | Variante eliminada/desvinculada | Ya no es hijo → `talla_no_disponible`. Órdenes previas conservan `product_id` + snapshot (no hay FK en `ordenes_corte_detalles.product_id`) | — |
| 7 | Configurable sin todas las combinaciones | Celda deshabilitada "no existe" | `107DURZ-H` sin Azul Oscuro XL |
| 8 | Niño con `grupo_de_edad_ninos` | Eje detectado dinámicamente; columnas 2-4…14-16 con etiqueta del atributo | Familia 2 |
| 9 | Nueva talla XXL | Aparece sola cuando exista un hijo XXL; nunca antes | Opción XXL existe con 0 usos |
| 10 | Perfil activo cuyo configurable ya no existe | No aplica: no se guarda configurable. Si la familia deja de tener configurables, la línea desaparece de la lista | — |
| 11 | Rollos históricos con color solo en texto | Backfill estricto; los no mapeados quedan fuera del V2 con aviso | 25 rollos, todos mapeables salvo casos QA |
| 12 | Mismo `tipo_tela_id` con varias líneas | Normal: el usuario elige varias | Tela 41 → 4 familias |
| 13 | Familia con varios configurables pero solo uno válido para corte | Válido = activo + `tipo_tela` = T + 2 ejes. Excluir un configurable activo **solo** para corte es el disparador 2 de §7.4 | No ocurre hoy |
| 14 | Configurable con >2 super atributos | Excluido con motivo `ejes_no_soportados` (falla cerrado) | 0 casos |
| + | Rollo con `tipo_tela_id NULL` | Rechazado (hoy `null == null` lo deja pasar) | Rollo 1 (`reservado_en_corte`) |
| + | Estado desalineado `product_attribute_values` vs `product_flat` | Se lee EAV | `110DURZ-H` |
| + | Configurable sin atributo `tipo_tela` | No aparece como candidato; el diagnóstico lo lista | `tipo_tela` no es obligatorio en Bagisto |
| + | Catálogo cambia entre previsualizar y guardar | El servidor re-resuelve en la transacción | — |
| + | Mezcla de gramajes en una orden | No afecta la variante (gramaje no es atributo Bagisto). Sí afecta `peso_promedio_pieza` (costo mezcla 170 y 175 g) | Referencias con 170/175 |
| + | **Mezclar colores antes de corregir el sobrante** | **Corrompe `Rollo.peso_disponible`** (AUD-01 §8). Por eso F3 va antes de F4 | `OrdenCorteService.php:402-428` |

### 14.1 Peso sobrante — separado de la variante

Confirmado: **el gramaje no define la variante** (no es atributo Bagisto; los ejes son solo color + talla/edad), así que no hay que dividir "Hombre Negro M" por gramaje. El sobrante es un problema de **inventario físico de rollos**, no de identidad de producto. Opciones:

- **Por rollo** (recomendada si bodega puede pesar cada rollo): usa `bylopez_cc_orden_corte_rollos.peso_sobrante` que ya existe; `actualizarRollosAlCerrar()` aplica lo capturado en vez de la cascada.
- **Por grupo físico** (color + referencia + gramaje) si solo se puede pesar por grupo: se captura un valor por grupo y se reparte **dentro** del grupo (entre rollos intercambiables), nunca entre grupos. El grupo se calcula al vuelo en la recepción; no es una entidad persistida y no participa de la resolución de variantes.
- **Fallback histórico:** órdenes sin desglose conservan la cascada actual.

Pregunta operativa abierta desde AUD-01 (D-5).

---

## 15. Plan de implementación por fases (revisado)

Cada fase se implementa, se prueba y se detiene para revisión (regla vigente, `docs/confeccion/CONFECCION_MASTER.md §31`).

| Fase | Contenido | Depende de | Cambia comportamiento visible |
|---|---|---|---|
| **F0** | Recepción por `orden_corte_detalle_id` (§12.2) | — | Mínimo (mismo formulario) |
| **F1** | Color estructurado: columnas + backfill + select en referencias + copia en compra + tratamiento de rollos manuales (D-4) | — | Sí, en proveedores/referencias |
| **F2** | Resolvedor de variantes (solo lectura, sin UI) + diagnóstico de catálogo (lista `color_ambiguo`, configurables sin `tipo_tela`, configurables con 2 colores, estados desalineados). **En paralelo, el negocio corrige los 5 configurables del catálogo** | F1 | No |
| **F3** | Sobrante por rollo / grupo físico en recepción y cierre (AUD-01 Fase E) | F0 | Sí, en recepción |
| **F4** | Creación V2: compatibilidad solo `tipo_tela_id` no nulo, matriz línea × color × talla, selección resuelta en servidor, retiro de `cantidad_proyectada` (regla, input y vistas), `OrdenCorte.color` = resumen de colores (hoy es el color del primer rollo, `OrdenCorteService.php:69`) | F1, F2, F3 | Sí |
| **F5** | Adicionales del cortador vía resolvedor | F0, F2 | Sí |

Cambio importante respecto a AUD-01: **la relajación de compatibilidad no puede publicarse sola** (AUD-01 Fase A) — permitir mezclar colores sin F3 corrompe el peso disponible de los rollos al cerrar. Por eso va dentro de F4, después de F3.

---

## 16. Archivos que se tocarían (tras aprobación)

Rutas relativas a `packages/ByLopez/CorteConfeccion/`. **Cero archivos de `packages/Webkul/`.**

**F0 — Recepción**
- `src/Database/Migrations/<nueva>_add_orden_corte_detalle_id_to_bylopez_cc_recepciones_corte_detalles.php` (nuevo)
- `src/Models/RecepcionCorteDetalle.php`
- `src/Http/Requests/StoreRecepcionCorteRequest.php`
- `src/Services/RecepcionCorteService.php`
- `src/Http/Controllers/Admin/RecepcionCorteController.php`
- `src/Resources/views/admin/ordenes-corte/partials/card-recepcion.blade.php`
- `src/Resources/views/admin/recepciones-corte/create.blade.php`

**F1 — Color estructurado**
- `src/Database/Migrations/<nueva>_add_color_option_id_to_referencias_y_rollos.php` (nuevo, con backfill)
- `src/Models/ProveedorTelaReferencia.php`, `src/Models/Rollo.php`
- `src/Http/Requests/StoreProveedorTelaRequest.php` (+ `UpdateProveedorTelaRequest` hereda)
- `src/Services/ProveedorTelaService.php`
- `src/Http/Controllers/Admin/ProveedorTelaController.php`
- `src/Resources/views/admin/proveedores-tela/form.blade.php`, `referencia-form.blade.php`, `show.blade.php`
- `src/Http/Requests/StoreCompraTelaRequest.php`, `src/Services/CompraTelaService.php`
- según D-4: `src/Http/Requests/StoreRolloRequest.php`, `UpdateRolloRequest.php`, `src/Services/RolloService.php`, `src/Resources/views/admin/rollos/form.blade.php`
- `src/Resources/lang/es/app.php`, `src/Resources/lang/en/app.php`

**F2 — Resolvedor y diagnóstico**
- `src/Services/ResolvedorVarianteCorteService.php` (nuevo)
- diagnóstico: método del mismo Service expuesto como comando artisan de solo lectura `src/Console/Commands/DiagnosticarCatalogoCorte.php` (nuevo) + registro en `src/Providers/CorteConfeccionServiceProvider.php`. Alternativa sin comando: mostrarlo solo en la pantalla de creación.

**F3 — Sobrante**
- `src/Http/Requests/StoreRecepcionCorteRequest.php`, `src/Services/RecepcionCorteService.php`
- `src/Services/OrdenCorteService.php` (`actualizarRollosAlCerrar()`)
- `src/Resources/views/admin/ordenes-corte/partials/card-recepcion.blade.php`

**F4 — Creación V2**
- `src/Resources/views/admin/ordenes-corte/create.blade.php` (reescritura)
- `src/Http/Controllers/Admin/OrdenCorteController.php` (`create()`, `store()`, endpoint de opciones/matriz)
- `src/Http/routes.php` (GET de la matriz) y `src/Config/acl.php` (asociarlo a `corte_confeccion.ordenes_corte.create`)
- `src/Http/Requests/StoreOrdenCorteRequest.php` (`seleccion[]`, sin `cantidad_proyectada`)
- `src/Services/OrdenCorteService.php` (`validarCompatibilidadRollos()`; guarda `type = simple` en `create()` para cualquier llamador; construcción de detalles desde la selección resuelta; resumen de color)
- `src/Resources/views/admin/ordenes-corte/show.blade.php`, `partials/card-resultado.blade.php`, `src/Support/AgrupadorDetallesCorte.php` (proyectado solo histórico; `card-solicitud.blade.php` no muestra proyectado y no cambia)
- `src/Resources/lang/es/app.php`, `src/Resources/lang/en/app.php`

**F5 — Adicionales**
- `src/Resources/views/admin/ordenes-corte/partials/card-resultado.blade.php`
- `src/Http/Requests/ReportarResultadoCorteRequest.php`
- `src/Services/OrdenCorteService.php` (`reportarResultado()`)

Contrato de `OrdenCorteService::create()`: se mantiene `detalles[]` con `product_id` como contrato interno (los fixtures existentes usan `Product::factory()->simple()` sin padre, así que una guarda "solo `simple`" es compatible; una guarda "debe tener padre / tela" **no** va en `create()` sino en el camino V2, para no romper esos tests).

---

## 17. Tests nuevos necesarios

Patrón de fixtures reutilizable: los helpers `vc_crearAtributoSelect`, `vc_crearOpcion`, `vc_crearVarianteConEjes` de `tests/Feature/VarianteConsultaServiceTest.php`.

**Resolvedor (F2)**
1. Resuelve `(línea, color, talla)` → simple único con snapshot correcto.
2. Dos configurables de la misma familia con el mismo color → `color_ambiguo` con ambos SKUs (réplica de `110DURZ-DAMA`/`124DURZ-D`).
3. Mismo color+talla en familias distintas → no interfieren.
4. Opción de color renombrada después de crear el rollo → sigue resolviendo.
5. Hijo inactivo (EAV) → `variante_inactiva`, aunque `product_flat` diga activo.
6. Configurable inactivo → `producto_inactivo`.
7. Combinación inexistente → `talla_no_disponible`.
8. Eje `grupo_de_edad_ninos` detectado y rotulado con el `admin_name` del atributo.
9. Opción XXL sin hijos no aparece en las opciones; con hijo, aparece.
10. Configurable con 3 super atributos → excluido con motivo.
11. Configurable sin `tipo_tela` o con otra tela → no es candidato.
12. Varias líneas para la misma tela → todas listadas.
13. Nunca devuelve un configurable.
14. Número de consultas constante respecto a la cantidad de celdas (como `VarianteConsultaServiceTest` CASO 4).

**Color (F1)**
15. Referencia guarda `color_option_id` y snapshot; rechaza un ID de opción de otro atributo (p. ej. de `size`).
16. La compra copia `color_option_id` al rollo.
17. Backfill: 1 coincidencia → asigna; 0 o >1 → `NULL`; rollo toma el ID de su referencia, no de su texto.

**Creación (F4)**
18. Rollos de 2 colores, misma tela → orden creada con los `product_id` correctos, `talla`/`genero_linea` rellenos y `cantidad_proyectada = 0`.
19. Tela distinta → bloquea. `tipo_tela_id NULL` → bloquea. Rollo sin color vinculado → bloquea.
20. Selección ambigua o inexistente → la transacción completa se revierte y ningún rollo queda reservado.
21. `product_id` manipulado en el payload → ignorado (el servidor resuelve).
22. `create()` rechaza un `product_id` configurable.

**Recepción (F0)**
23. Mismo producto en solicitud y adicional (filas fuera de orden en el POST) → comparación correcta por `orden_corte_detalle_id`.
24. `orden_corte_detalle_id` de otra orden o repetido → rechazado.
25. `product_id` manipulado → se usa el del detalle.
26. Recepción histórica sin `orden_corte_detalle_id` → la vista de solo lectura sigue funcionando.

**Sobrante (F3):** los de AUD-01 §13 (colores mezclados, sobrante por rollo, fallback histórico).

**Tests existentes afectados:** los que envían `detalles[].cantidad_proyectada` al Request/Service siguen funcionando (el Service ya aplica `?? 0`); los que postean el formulario de recepción deberán enviar `orden_corte_detalle_id` (F0).

---

## 18. Decisiones pendientes del usuario

| # | Decisión | Recomendación |
|---|---|---|
| D-1 | ¿Línea = familia por ahora (sin tabla), o tabla de líneas desde el inicio? | Familia, con los disparadores de §7.4 |
| D-2 | Referencias cuyo color no existe en Bagisto (`Verde botella`): ¿crear la opción en Bagisto o dejarlas sin vincular? | Crear la opción solo cuando exista producto de ese color; mientras tanto, sin vincular |
| D-3 | Productos inactivos: ¿bloquear o permitir con advertencia (producción previa al lanzamiento)? | Bloquear (falla cerrado); cambiar a advertencia solo si el negocio produce antes de activar |
| D-4 | Alta y edición manual de rollos (sin referencia ni `tipo_tela_id`, color editable en cualquier estado) | Crear rollos solo vía compra; en edición, color por select y bloqueado si el rollo tiene referencia |
| D-5 | Sobrante: ¿bodega pesa rollo por rollo o por grupo? (abierta desde AUD-01) | Por rollo si es operativamente viable |
| D-6 | ¿Quién corrige los 5 configurables del catálogo y el estado de `110DURZ-H`, y cuándo? | Antes de habilitar F4 en producción |
| D-7 | `cantidad_proyectada` en órdenes históricas: ¿mostrar como "histórico" u ocultar siempre? | Mostrar solo si > 0 |

---

## 19. Confirmaciones de cierre

- `packages/Webkul/`: **0 archivos modificados.** Se leyeron solo como referencia `Product/src/Type/Configurable.php`, `Product/src/Helpers/ConfigurableOption.php`, `Product/src/Type/AbstractType.php`, `Attribute/src/Database/Migrations/*create_attributes_table.php` y `Admin/src/Http/Controllers/Catalog/AttributeFamilyController.php`.
- Base de datos: solo `SELECT` e `information_schema`/`SHOW INDEX`. Ninguna escritura.
- Único archivo creado: este documento. Nada implementado. Se espera aprobación explícita antes de cualquier fase.

---

## Anexo A — Consultas de solo lectura para repetir en staging/producción

Todas localizan atributos por **código**, no por ID.

```sql
-- A1. Configurables e hijos por familia
SELECT af.id, af.code, COUNT(DISTINCT c.id) configurables, COUNT(s.id) hijos
FROM attribute_families af
LEFT JOIN products c ON c.attribute_family_id = af.id AND c.type = 'configurable'
LEFT JOIN products s ON s.parent_id = c.id
GROUP BY af.id, af.code;

-- A2. Colisiones familia + color + eje2 (Propuesta A)
SELECT s.attribute_family_id fam, col.admin_name color, t.admin_name eje2, COUNT(*) n,
       GROUP_CONCAT(CONCAT(s.sku, ' (', c.sku, ')') SEPARATOR '; ') simples
FROM products s
JOIN products c ON c.id = s.parent_id
JOIN attributes ac ON ac.code = 'color'
JOIN product_attribute_values pc ON pc.product_id = s.id AND pc.attribute_id = ac.id
JOIN attribute_options col ON col.id = pc.integer_value
JOIN product_super_attributes psa ON psa.product_id = c.id AND psa.attribute_id <> ac.id
JOIN product_attribute_values pt ON pt.product_id = s.id AND pt.attribute_id = psa.attribute_id
JOIN attribute_options t ON t.id = pt.integer_value
WHERE s.type = 'simple'
GROUP BY s.attribute_family_id, pc.integer_value, pt.integer_value
HAVING COUNT(*) > 1;

-- A3. Colores de los hijos por configurable (detecta configurables mal etiquetados)
SELECT c.attribute_family_id fam, c.sku, pf.name,
       GROUP_CONCAT(DISTINCT ao.admin_name ORDER BY ao.admin_name SEPARATOR ' | ') colores_hijos
FROM products c
JOIN attributes ac ON ac.code = 'color'
LEFT JOIN product_flat pf ON pf.product_id = c.id
LEFT JOIN products s ON s.parent_id = c.id
LEFT JOIN product_attribute_values pav ON pav.product_id = s.id AND pav.attribute_id = ac.id
LEFT JOIN attribute_options ao ON ao.id = pav.integer_value
WHERE c.type = 'configurable'
GROUP BY c.id ORDER BY fam, c.sku;

-- A4. Nivel 1: (familia, color) con != 1 configurable
SELECT c.attribute_family_id fam, ao.admin_name color, COUNT(DISTINCT c.id) configurables,
       GROUP_CONCAT(DISTINCT c.sku) skus
FROM products c
JOIN attributes ac ON ac.code = 'color'
JOIN products s ON s.parent_id = c.id
JOIN product_attribute_values pav ON pav.product_id = s.id AND pav.attribute_id = ac.id
JOIN attribute_options ao ON ao.id = pav.integer_value
WHERE c.type = 'configurable'
GROUP BY c.attribute_family_id, pav.integer_value
HAVING COUNT(DISTINCT c.id) > 1;

-- A5. Nivel 2: colisiones configurable + color + eje2 (esperado: 0)
SELECT s.parent_id, pc.integer_value, pt.integer_value, COUNT(*) n
FROM products s
JOIN attributes ac ON ac.code = 'color'
JOIN product_attribute_values pc ON pc.product_id = s.id AND pc.attribute_id = ac.id
JOIN product_super_attributes psa ON psa.product_id = s.parent_id AND psa.attribute_id <> ac.id
JOIN product_attribute_values pt ON pt.product_id = s.id AND pt.attribute_id = psa.attribute_id
GROUP BY s.parent_id, pc.integer_value, pt.integer_value
HAVING COUNT(*) > 1;

-- A6. Tela en el catálogo
SELECT ao.id, ao.admin_name, p.type, COUNT(*)
FROM attributes a
JOIN product_attribute_values pav ON pav.attribute_id = a.id
JOIN attribute_options ao ON ao.id = pav.integer_value
JOIN products p ON p.id = pav.product_id
WHERE a.code = 'tipo_tela'
GROUP BY ao.id, ao.admin_name, p.type;

-- A7. Colores de rollos/referencias vs opciones Bagisto (coincidencia normalizada)
SELECT r.color, COUNT(*) rollos,
       (SELECT GROUP_CONCAT(ao.id) FROM attribute_options ao JOIN attributes a ON a.id = ao.attribute_id
         WHERE a.code = 'color' AND LOWER(TRIM(ao.admin_name)) = LOWER(TRIM(r.color))) opciones
FROM bylopez_cc_rollos r GROUP BY r.color;

-- A8. Estado desalineado EAV vs product_flat
SELECT p.id, p.sku, pav.boolean_value status_eav, pf.status status_flat
FROM products p
JOIN attributes a ON a.code = 'status'
JOIN product_attribute_values pav ON pav.product_id = p.id AND pav.attribute_id = a.id
JOIN product_flat pf ON pf.product_id = p.id AND pf.channel = pav.channel
WHERE pav.boolean_value <> pf.status;
```
