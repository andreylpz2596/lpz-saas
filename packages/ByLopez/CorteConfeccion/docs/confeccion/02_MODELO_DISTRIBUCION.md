# 02_MODELO_DISTRIBUCION.md — Diseño del modelo de distribución multi-taller

## 0.0 Estado: APROBADA (con correcciones obligatorias)

Este documento fue revisado y **aprobado** con 7 decisiones finales
vinculantes, que corrigen/precisan puntos de la propuesta original de
esta misma fase. Las correcciones quedan incorporadas en el cuerpo del
documento (marcadas como "**[APROBADO]**" en cada sección afectada) y
resumidas aquí para referencia rápida:

1. **Arquitectura:** confirmado sin tabla `DistribucionConfeccion` (§14).
2. **Identidad de variante:** `product_id` como clave operativa, **con
   validación obligatoria de servidor** de que sea un producto Bagisto
   `simple` (no `configurable`) — antes esto era una recomendación a
   futuro, ahora es un requisito bloqueante de la Fase 03A (§2, §3.3, §18).
3. **Prioridad:** `OrdenConfeccionDetalle.prioridad` como columna
   `string`/`varchar` con `DEFAULT 'normal'` + PHP Backed Enum para el
   dominio — explícitamente **sin** `ENUM` nativo de MySQL (§10).
4. **Concurrencia:** una sola `DB::transaction()` + `lockForUpdate()`
   sobre `RecepcionCorte`, con el orden exacto: bloquear primero,
   recalcular disponibilidad después del bloqueo, nunca antes (§8, §9).
5. **Anulación:** confirmado que anular libera cantidades al disponible;
   `DisponibilidadConfeccionService` deja de contar detalles de
   `OrdenConfeccion` anuladas (§4). Limitación (mercancía ya entregada
   físicamente) queda documentada, no resuelta — sin nuevos estados en
   esta fase.
6. **Taller predeterminado:** confirmado que no se persiste (§11).
7. **Stock Bagisto:** confirmado solo-lectura, no implementado en esta
   fase ni en la Fase 03A (§13).

Próxima fase autorizada: **03A_MOTOR_DISTRIBUCION** (backend únicamente).
Ver `03A_MOTOR_DISTRIBUCION.md`.

---

## 0. Metadatos

- **Fecha:** 2026-09-04
- **Tipo de fase:** Diseño y documentación únicamente. **Cero código de
  producción cambiado.**
- **Verificación realizada durante esta fase:** además de releer
  `CONFECCION_MASTER.md` / `01_AUDITORIA.md` / `STATUS.md`, se ejecutaron
  consultas **de solo lectura** (`SELECT`, vía `php artisan tinker`) contra
  la base de datos real para resolver la duda de identidad de variante
  planteada en la auditoría. No se escribió ni modificó ningún registro.
- **Archivos tocados en esta fase:** este documento y `STATUS.md`. Ningún
  Model/Service/Controller/Request/Blade/ruta/migration.

---

## 1. Arquitectura actual reutilizada (sin cambios)

Confirmado en `01_AUDITORIA.md` y no se propone tocar:

```
OrdenCorte  1───N  OrdenConfeccion  N───1  Taller
                        │
                        └─ 1───N  OrdenConfeccionDetalle
```

- `OrdenConfeccion.taller_id` sigue siendo un único taller por orden — esto
  ya es correcto (CONFECCION_MASTER.md §19) y **no se toca**.
- `RecepcionCorte` sigue **sin** relación directa a `Taller` — correcto,
  no se crea esa relación falsa.
- No se propone ninguna tabla nueva de "asignación" intermedia: la
  asignación sigue siendo la fila `OrdenConfeccionDetalle` (ver §12 para la
  discusión explícita de si hace falta una cabecera `Distribucion`).

Esta fase busca cambios **mínimos y aditivos** sobre este esqueleto, no un
reemplazo.

---

## 2. Problemas confirmados (heredados de 01_AUDITORIA + nueva evidencia)

1. `DisponibilidadConfeccionService` suma `cantidad_entregada` de órdenes
   `anuladas` como si estuvieran vigentes → cancelar no libera cantidad.
2. `StoreOrdenConfeccionRequest`/`OrdenConfeccionService::create()` solo
   aceptan **un** `taller_id` por request → una distribución 60/20/20
   exige dos operaciones manuales secuenciales, sin resumen conjunto.
3. **Nuevo, confirmado con datos reales:** la columna `talla` en
   `RecepcionCorteDetalle` y `OrdenConfeccionDetalle` está **vacía en el
   100% de los registros existentes** (0/3 y 0/3). En `OrdenCorteDetalle`
   solo está poblada en 3 de 14 filas (21%). No es una columna confiable
   como parte de una clave de agrupación (ver §3).
4. **Nuevo, confirmado con datos reales:** `OrdenCorteDetalle.variant_id`
   está en `NULL` en el 100% de los registros (14/14) y **no se lee en
   ningún punto del código** (ni en servicios, ni en blades, ni en el
   cálculo de disponibilidad) — es una columna que se escribe pero nunca
   se consume. Es código/esquema muerto en la práctica actual.
5. **Nuevo, confirmado:** ningún punto del módulo valida que el
   `product_id` elegido corresponda a un producto Bagisto de tipo
   `simple` (variante vendible). El buscador usado en la creación de
   `OrdenCorteDetalle` (`admin.catalog.products.search`, del core de
   Webkul) no filtra por `type`, así que en teoría un operador podría
   seleccionar el producto **configurable** padre en vez de una talla
   específica. En los datos reales actuales esto no ha ocurrido (ver §3),
   pero no hay ninguna barrera de código que lo impida.
   **[APROBADO — deja de ser una recomendación a futuro y pasa a ser
   requisito bloqueante de la Fase 03A]:** el nuevo servicio de
   distribución debe validar, para cada `product_id` recibido, que su
   `products.type` no sea `configurable`, y rechazar toda la operación
   con `ValidationException` si algún `product_id` del payload es un
   producto padre. No basta con confiar en que los datos históricos son
   correctos (ver Caso 7 en `03A_MOTOR_DISTRIBUCION.md`).
6. No hay lectura de stock de Bagisto en ningún punto (confirmado, sin
   cambios respecto a 01_AUDITORIA).

---

## 3. Identidad definitiva de la variante (resuelta con evidencia real, no supuestos)

### 3.1 Evidencia recolectada

Se consultó la base de datos real (`lpz_saas`) en modo solo-lectura:

```
products: 377 filas totales
  - type = 'configurable': 77   (parent_id = NULL)
  - type = 'simple':      300   (parent_id = id de un configurable)
```

Muestra real de `bylopez_cc_recepciones_corte_detalles` cruzada contra `products`:

| product_id | producto_sku | producto_nombre | products.type | products.parent_id |
|---|---|---|---|---|
| 173 | 115DURZ-H-L | Camiseta Piel Durazno Verde Calì Hombre L | simple | 170 |
| 327 | 115DURZ-DAMA-M | Camiseta Piel Verde Cali Mujer M | simple | 325 |
| 172 | 115DURZ-H-M | Camiseta Piel Durazno Verde Cali Hombre M | simple | 170 |

El configurable padre (`id=170`) es:

```
sku = 115DURZ-H, type = configurable, parent_id = NULL
```

Es decir: el configurable `115DURZ-H` agrupa **género + color + tela**
("Camiseta Piel Durazno Verde Cali Hombre"), y cada **talla** (L, M, XL...)
es un producto `simple` hijo distinto, con su propio `product_id` y su
propio SKU (`115DURZ-H-L`, `115DURZ-H-M`, ...). Un color diferente
("Verde Cali" vs otro color) es un **configurable padre distinto**, con
su propia familia de hijos `simple`.

Muestra real de `bylopez_cc_ordenes_corte_detalles` (14 filas, todas):
confirma el mismo patrón — cada `product_id` distinto corresponde a un
SKU de talla específica (`102DURZ-D-L` vs `102DURZ-D-XL`, `124DURZ-H-XL`
vs `124DURZ-H-L` vs `124DURZ-H-M` vs `124DURZN-H-S`, etc.), y
`variant_id` es `NULL` en las 14.

### 3.2 Respuesta a la pregunta obligatoria

> ¿Hombre Verde Cali L y Negro Hombre L tienen `product_id` distintos?

**Sí, siempre**, porque cada color es un configurable padre distinto y
cada talla dentro de un color es un `simple` hijo distinto. No existe en
los datos reales ningún caso donde dos combinaciones color+talla
compartan `product_id`.

> ¿`product_id` puede corresponder al producto configurable padre?

**Técnicamente sí es posible** (el buscador no lo impide, ver §2.5), pero
**en la práctica actual, el 100% de los registros de corte, recepción y
confección usan el `product_id` del hijo `simple`** (talla específica),
nunca el del configurable. Es una convención respetada por los usuarios,
no una garantía de código.

> ¿Para qué se usa `variant_id` y por qué no se propagó?

No se usa para nada hoy: se declaró como columna adicional (migración
`2026_06_14_000001`) probablemente pensando en un escenario donde
`product_id` apuntaría al configurable y `variant_id` a la talla
específica — pero esa idea nunca se completó, porque en la práctica
`product_id` ya terminó siendo el id del `simple` (talla específica), lo
cual volvió `variant_id` redundante desde el principio. Por eso nunca se
propagó a `RecepcionCorteDetalle` ni a `OrdenConfeccionDetalle`, y por eso
nunca se lee: sobra.

### 3.3 Clave estable recomendada

**`product_id` (referido siempre al producto Bagisto tipo `simple`,
hijo de un configurable) es, por sí solo, una clave de variante
completa y estable** (identifica color + talla + género de forma
única), porque así es como Bagisto modela variantes: cada combinación
comprable de atributos es su propia fila en `products`.

Se recomienda:

1. **Usar `product_id` como única clave de agrupación** en el cálculo de
   disponibilidad y en el nuevo payload de distribución — **no**
   `product_id + talla`. Concatenar con `talla` no aporta seguridad
   adicional (porque `product_id` ya es único por variante) y sí
   introduce riesgo real: si dos filas del mismo `product_id` llegan con
   `talla` distinta por inconsistencia de captura (una vacía, otra no),
   agrupar por la combinación las trataría como variantes diferentes y
   fragmentaría el disponible incorrectamente. Agrupar solo por
   `product_id` es estrictamente más seguro con los datos reales
   observados.
2. **Mantener la columna `talla`** en `RecepcionCorteDetalle` y
   `OrdenConfeccionDetalle` tal cual está, solo como dato **informativo/
   legacy para mostrar en pantalla** (fallback de visualización si el
   nombre del producto no es suficientemente claro), sin que participe en
   ningún cálculo de disponibilidad. Esto es compatible hacia atrás: no
   requiere migración porque no se propone borrar ni renombrar la
   columna.
3. **No usar `variant_id`** en el nuevo diseño. No se propone eliminarlo
   en esta fase (sería un cambio de modelo fuera del alcance de "solo
   diseño"), pero se dejará registrado como candidato a limpieza en una
   fase de mantenimiento posterior, ya que hoy es una columna sin lector.
4. **[APROBADO, requisito obligatorio de la Fase 03A, ya no opcional]**
   Cerrar el riesgo del §2.5 agregando una validación de servidor que
   rechace con `ValidationException` cualquier `product_id` cuyo
   `products.type === 'configurable'`, específicamente en el nuevo
   Service/Request de distribución multi-taller (§6, §8). Esto convierte
   la convención actual (funciona "porque así se ha usado") en una
   garantía real de integridad de datos. Nota de alcance: esta validación
   se exige para el flujo nuevo de distribución (Fase 03A); extenderla
   también a `StoreOrdenCorteRequest`/`StoreRecepcionCorteRequest`
   (creación de corte y recepción) no fue pedido en las decisiones
   aprobadas y queda fuera del alcance de esta fase.

---

## 4. Semántica definida de "anulada" — [APROBADO, temporal]

### 4.1 Qué representa hoy en el código

`OrdenConfeccionService::cancel()` (línea 74-83 de `OrdenConfeccionService.php`)
únicamente ejecuta `$orden->update(['estado' => 'anulada'])`. No hay
ninguna otra consecuencia: no se tocan los `OrdenConfeccionDetalle`, no se
pregunta si la mercancía salió físicamente de Bodega, no hay confirmación
adicional en el controlador (`OrdenConfeccionController::cancel()` no
agrega lógica propia) ni en la vista.

### 4.2 Los dos casos del enunciado

**Caso A — nunca salió de Bodega** (orden creada por error, o cambio de
decisión antes de despachar físicamente): la cantidad **debería** volver
al pool disponible. Hoy no vuelve (bug confirmado en 01_AUDITORIA).

**Caso B — ya se entregó físicamente al Taller** y luego alguien anula
administrativamente el registro: la cantidad **no debería** volver a
aparecer como disponible en Bodega, porque físicamente ya no está ahí —
debería en cambio dispararse algún proceso de "recuperar/reingresar a
Bodega" (que hoy no existe, ver 01_AUDITORIA §7 sobre estados de
producción).

**El sistema hoy no puede distinguir A de B**, porque no existe ningún
estado ni flag entre "activa" (=entregada, según nombre pero sin garantía
real de entrega física) y "anulada". No hay un paso de "confirmar entrega
física" ni de "retorno". La única fuente de verdad es un booleano
implícito (`activa`/`anulada`).

### 4.3 Regla funcional aprobada para esta etapa (temporal)

> **[APROBADO]** Mientras no exista un flujo de retorno/entrega más
> avanzado, **anular una `OrdenConfeccion` representa "esta asignación
> nunca debió descontarse" y debe devolver sus cantidades al pool
> disponible.**

**Consecuencias de adoptar esta regla ahora:**

- Es estrictamente mejor que el comportamiento actual (que siempre pierde
  la cantidad, sin excepción) — nunca es peor que el bug de hoy.
- Cubre correctamente el Caso A (el más común: correcciones antes de
  despacho).
- Para el Caso B, la regla es **operativamente incorrecta si se abusa**:
  si Bodega ya entregó físicamente y alguien anula solo para "liberar"
  cantidad sin haber recuperado la mercancía, el sistema mostraría más
  disponible del que realmente existe en Bodega. **Esto no lo puede
  resolver el software todavía** porque no existe el concepto de "retorno
  físico confirmado" (CONFECCION_MASTER.md §24, fuera de alcance de esta
  fase).
- **[APROBADO]** Por eso, junto con el cambio de cálculo, la acción
  "Anular" en la UI deberá mostrar en el futuro un mensaje explícito del
  tipo *"Esta acción asume que las prendas nunca salieron de Bodega y
  libera la cantidad para volver a distribuirla. Si la mercancía ya fue
  entregada físicamente al taller, no la anules: esa función llegará con
  el flujo de retorno de taller."* — un control **procedimental**, no
  técnico, hasta que exista un estado de producción real. **Esta
  advertencia de UI queda fuera del alcance de la Fase 03A** (backend/
  motor de distribución) y se implementará junto con el rediseño de
  pantalla en una fase 03B posterior. Tampoco se crean estados nuevos de
  `OrdenConfeccion` para resolver esto — la distinción Caso A/Caso B sigue
  sin ser posible técnicamente hasta la fase de "retorno de taller"
  (CONFECCION_MASTER.md §24), fuera de alcance.

### 4.4 Cómo debería calcularse disponibilidad bajo esta regla

```
entregado_talleres(product_id) =
    SUM(OrdenConfeccionDetalle.cantidad_entregada)
    JOIN OrdenConfeccion
    WHERE OrdenConfeccion.orden_corte_id = :ordenCorteId
      AND OrdenConfeccion.estado != 'anulada'   -- único cambio respecto a hoy
    GROUP BY OrdenConfeccionDetalle.product_id
```

Este es el **único cambio de lógica** que se propone para el cálculo de
disponibilidad (además de dejar de usar `talla` en el agrupamiento, §3.3).
No requiere migración: es un cambio de filtro en una consulta ya
existente (`DisponibilidadConfeccionService`), a aplicar en la fase de
implementación, no ahora.

**No se decide ni se aprueba nada de esto todavía** — queda documentado
para tu revisión explícita, tal como pediste.

---

## 5. Aclaración de `cantidad_entregada = 0` (contradicción resuelta)

Se verificó el flujo real completo:

1. `create.blade.php` (línea 76-86) genera **una fila por cada variante
   con recepción**, con un `<input type="number">` que **por defecto vale
   `0`** (`old("detalles.$i.cantidad_entregada", 0)`) — es decir, hoy el
   HTML sí envía una fila por cada variante, incluso las que quedan en 0.
2. `StoreOrdenConfeccionRequest::prepareForValidation()` (línea 9-17)
   filtra las filas **antes** de validar:
   ```php
   collect($this->input('detalles', []))
       ->filter(fn ($d) => ! empty($d['product_id']) && ! empty($d['cantidad_entregada']))
   ```
   La clave está en `empty($d['cantidad_entregada'])`: en PHP,
   `empty("0")` es **`true`** (es una de las conversiones "falsy" clásicas
   de PHP). Como el valor llega del formulario HTML como el string
   `"0"`, esta fila **se descarta automáticamente** antes de que la regla
   `gt:0` siquiera la evalúe.
3. Por lo tanto: **no hay contradicción real.** El payload que
   efectivamente llega a `OrdenConfeccionService::create()` **nunca**
   contiene filas con `cantidad_entregada = 0`; ya vienen filtradas. La
   regla `'detalles.*.cantidad_entregada' => ['required','integer','gt:0']`
   solo actúa como cinturón de seguridad adicional sobre lo que sobrevivió
   al filtro (por ejemplo, si llegara un valor negativo).
4. **Riesgo menor detectado (no un bug, una fragilidad):** depender de
   `empty()` para este filtro es un patrón fráil de PHP — funciona hoy
   porque el valor siempre es un string numérico, pero es menos explícito
   que comprobar `(int) $d['cantidad_entregada'] > 0`. Se recomienda, en
   la fase de implementación, hacer el filtro explícito por claridad, sin
   que esto cambie el comportamiento actual.

**Conclusión para el nuevo payload:** se mantiene el mismo principio —
**solo se envían/persisten asignaciones con cantidad mayor a cero**; las
filas en 0 nunca deben convertirse en filas de `OrdenConfeccionDetalle`.
El nuevo diseño de payload (§6) debe preservar (y hacer explícito) este
filtro para cada taller de la distribución, no solo para un taller único
como hoy.

---

## 6. Estructura de payload propuesta (conceptual, no vinculante a JSON)

Extiende el modelo actual **sin romperlo**: sigue siendo "N llamadas a
crear una `OrdenConfeccion`", solo que ahora se agrupan y ejecutan **en
una sola operación de servidor**.

```
distribucion:
  orden_corte_id: 123
  observacion_general: "Distribución lote agosto"   # opcional, informativa

  asignaciones:
    - taller_id: 1
      fecha_entrega: 2026-09-10
      observacion: null
      detalles:
        - product_id: 173        # Hombre Verde Cali L (simple)
          cantidad: 60
          prioridad: urgente      # opcional, default 'normal'

    - taller_id: 4
      fecha_entrega: 2026-09-12
      observacion: null
      detalles:
        - product_id: 173        # mismo product_id, otro taller
          cantidad: 20
          prioridad: urgente

  # Hombre L: 20 restantes, Hombre M: 80, Hombre S: 70
  # => simplemente NO aparecen en ninguna "asignacion.detalles".
  # No se envía ninguna fila con cantidad 0 para representarlas.
```

**Reglas de forma del payload:**

- `asignaciones` es una lista de 1..N bloques, cada uno con **un solo
  `taller_id`** (se conserva la regla ya correcta de "una OrdenConfeccion
  = un taller", solo que ahora el request puede traer varias a la vez).
- Cada `detalle` dentro de una asignación es `{product_id, cantidad,
  prioridad?}`. `talla` **no** se envía en el payload de distribución:
  se deriva del `product_id` en el momento de persistir el snapshot
  (igual que hoy hace `ProductLookupService::snapshot()`), no se vuelve a
  pedir al usuario.
- Una variante puede aparecer en más de una `asignacion` (para
  distribuirla entre varios talleres), tal como exige el caso 60/20/20.
- Cantidades en 0 o ausentes **nunca se incluyen** — el frontend solo
  agrega al payload las celdas donde el usuario puso una cantidad > 0
  (esto es responsabilidad de la UI que se diseñe en Fase 03, no de este
  documento).

Este payload es **conceptual**: no se define aquí si viaja como un solo
`POST` con arrays anidados (como ya hace el formulario Blade actual) o
como una llamada por otro mecanismo. Esa decisión de transporte
(Blade+inputs anidados vs. componente Vue con JSON) es una decisión de
Fase 03 (UI), no de esta fase de modelo.

---

## 7. Algoritmo de disponibilidad (actualizado)

```
función calcularDisponibilidad(ordenCorte):
    recepcionDetalles = ordenCorte.recepcion.detalles   # sin cambios

    entregadoPorProducto = ordenCorte.ordenesConfeccion
        .where(estado != 'anulada')                      # <- único cambio (§4.4)
        .flatMap(detalles)
        .groupBy(product_id)                              # <- ya no + talla (§3.3)
        .sum(cantidad_entregada)

    para cada detalle en recepcionDetalles:
        apta = max(cantidad_recibida - cantidad_defectuosa, 0)
        entregado = entregadoPorProducto[detalle.product_id] ?? 0
        disponible = max(apta - entregado, 0)
        emitir fila { product_id, sku, nombre, talla (informativa),
                       recibida, defectuosa, apta, entregado, disponible }
```

Idéntico en espíritu al actual `DisponibilidadConfeccionService`; los
únicos dos cambios están marcados arriba. Sigue sin persistir ningún
valor derivado.

---

## 8. Algoritmo multi-taller (una operación → N órdenes) — [APROBADO]

Servicio propuesto: **evolucionar `OrdenConfeccionService`** agregando un
método nuevo (p. ej. `distribuir(array $data): Collection<OrdenConfeccion>`),
en vez de crear una clase paralela `DistribucionConfeccionService`.
Motivo: la lógica de validación de disponibilidad/taller/cantidad ya vive
ahí y es la misma que necesita cada asignación individual; separarla en
otra clase obligaría a duplicar esas validaciones o a inyectar una en la
otra sin necesidad real. El método `create()` actual (single-taller)
puede conservarse tal cual para compatibilidad, o convertirse en un caso
particular de `distribuir()` con una sola asignación — esa decisión de
refactor concreto se toma en Fase 03, no aquí.

Algoritmo:

```
función distribuir(ordenCorteId, asignaciones[]):
    1. cargar OrdenCorte; 404 si no existe.
    2. validar que OrdenCorte.recepcion exista.
    3. (opcional, cierra riesgo #2 de 01_AUDITORIA) validar que
       OrdenCorte.estado no sea 'anulado'.
    4. dentro de DB::transaction():
        a. lockForUpdate() sobre la fila de RecepcionCorte de esta orden
           (ver §9 — aquí se resuelve la concurrencia).
        b. recalcular disponibilidad EN SERVIDOR (nunca confiar en lo que
           mandó el navegador) usando el algoritmo de §7.
        c. validar cada Taller referenciado: existe y está activo.
        d. [APROBADO, obligatorio] validar que cada `product_id` del
           payload corresponda a un producto Bagisto `type != 'configurable'`;
           si alguno es un producto padre, `ValidationException` y se
           aborta toda la operación (Caso 7, §21 de este documento y
           `03A_MOTOR_DISTRIBUCION.md`).
        e. construir un mapa product_id => suma_solicitada_en_todas_las_asignaciones
           iterando TODAS las asignaciones del payload.
        f. para cada product_id en ese mapa:
               si suma_solicitada > disponible_actual: ValidationException
               (esta es la comprobación que impide que, por ejemplo,
               Taller 1 pida 70 y Taller 4 pida 40 de un disponible de 100
               — 110 > 100 se rechaza ANTES de crear nada)
        g. para cada asignación (agrupada por taller_id):
               crear 1 OrdenConfeccion (mismo generarCodigo() de hoy)
               crear sus OrdenConfeccionDetalle (solo cantidades > 0,
               con su `prioridad` correspondiente)
        h. si cualquier paso lanza excepción: la transacción completa
           hace ROLLBACK — ninguna de las N órdenes queda creada
           (incluye el caso "Taller 1 se creó, Taller 4 falla": al estar
           ambas dentro de la misma transacción, un fallo en la segunda
           revierte también la primera).
    5. commit; devolver la colección de OrdenConfeccion creadas.
```

**Orden crítico confirmado (Decisión 4):** el bloqueo (paso 4.a) ocurre
**antes** de recalcular disponibilidad (paso 4.b). Nunca se calcula una
disponibilidad "definitiva" antes de adquirir el lock — cualquier lectura
previa al lock (por ejemplo, la que pinta la pantalla en el `GET` inicial)
es solo orientativa para el usuario y se descarta por completo dentro de
la transacción.

El paso **(e)** es la pieza nueva que no existe hoy: hoy la validación es
"esta cantidad no excede el disponible" evaluada una asignación a la vez,
en requests separados; con múltiples asignaciones en el mismo payload hay
que sumarlas **antes** de comparar contra el disponible, porque dos
asignaciones del mismo payload podrían (por error de UI) pedir cantidades
que individualmente parecen válidas pero que juntas exceden lo
disponible.

---

## 9. Estrategia de concurrencia

**Escenario de la auditoría:** dos usuarios de Bodega abren la misma
`OrdenCorte`, ambos ven Disponible L=100; Usuario A distribuye 70,
Usuario B intenta distribuir 60 antes de que A confirme. Sin bloqueo,
ambas requests podrían leer "100 disponible" y ambas pasar su propia
validación, sumando 130 repartidas sobre un disponible real de 100.

**Por qué una simple revalidación en servidor (como ya hace hoy
`create()`) no es suficiente por sí sola:** revalida correctamente
*dentro* de una sola request, pero si dos requests corren en paralelo,
ambas pueden leer el mismo estado "antes" de que la otra escriba, y
ambas pasan la validación de forma independiente (clásica condición de
carrera "leer-luego-escribir").

**Solución recomendada:** `SELECT ... FOR UPDATE` (Eloquent
`lockForUpdate()`) sobre la fila de `RecepcionCorte` correspondiente a la
`OrdenCorte`, adquirido **al inicio** de la transacción de `distribuir()`,
antes de recalcular disponibilidad. Esto obliga a que la segunda request
espere a que la primera transacción termine (commit o rollback) antes de
poder leer/calcular disponibilidad, serializando las dos operaciones sobre
la misma orden de corte. Como el disponible se recalcula leyendo
`ordenesConfeccion.detalles` ya comprometidos (paso 4.b del algoritmo),
el Usuario B, al ejecutarse después del commit de A, verá
automáticamente Disponible=30 (100-70) y su intento de pedir 60 fallará
correctamente con `ValidationException`.

**Por qué bloquear `RecepcionCorte` y no cada `RecepcionCorteDetalle` por
separado:** una distribución típica toca varias variantes de la misma
recepción a la vez; bloquear la fila padre serializa toda la operación
sobre esa orden de corte con un solo lock (más simple, y el volumen de
recepciones concurrentes sobre la *misma* orden de corte es bajo — no es
una tabla de alto tráfico como carritos de compra). Bloquear por detalle
individual añadiría complejidad de orden de bloqueos (riesgo de deadlock
si dos requests bloquean las mismas filas en orden distinto) sin beneficio
real aquí.

**Confirmación explícita pedida:** la integridad queda garantizada por
el servidor (transacción + lock), no por deshabilitar botones en el
frontend — el frontend puede (y debe) seguir mostrando validaciones
optimistas para UX, pero nunca es la fuente de verdad.

**[APROBADO]** Regla de orden confirmada: `lockForUpdate()` primero,
recálculo de disponibilidad después, dentro de la misma transacción. No
se implementa en esta fase; queda como diseño a ejecutar en Fase 03A.

---

## 10. Diseño de prioridad — [APROBADO, con corrección]

**Ubicación técnica confirmada:** `OrdenConfeccionDetalle.prioridad`
(coincide con la preferencia funcional planteada) — porque la prioridad
es información operativa de **qué tan urgente es confeccionar esa
variante para ese taller**, no de la orden completa. Esto ya es
consistente con el ejemplo del propio enunciado (Hombre L urgente, Hombre
M normal, Dama S alta, dentro del mismo taller/orden).

- **Tipo confirmado (corrección respecto a la propuesta inicial):**
  **columna física `string`/`VARCHAR`** con `DEFAULT 'normal'` —
  explícitamente **sin** usar el tipo `ENUM` nativo de MySQL. La
  propuesta original de esta fase ya apuntaba a este mismo diseño físico
  (columna `string`), pero se deja constancia explícita de la corrección
  porque la decisión aprobada lo exige sin ambigüedad. El dominio de
  valores válidos (`normal|alta|urgente`) se modela y valida en PHP con
  un **Backed Enum** (mismo patrón que `EstadoOrdenConfeccion`/
  `EstadoOrdenCorte`), nunca como una restricción a nivel de esquema SQL.
  Esto permite agregar/renombrar valores de prioridad en el futuro con
  solo un cambio de código PHP, sin migración de esquema.
- **Valores:** `normal`, `alta`, `urgente` (exactamente los 3 del master
  doc, sin agregar valores no solicitados).
- **Default:** `normal` — así toda orden nueva sin selección explícita
  queda en el valor neutro, y no se fuerza al usuario a elegir en cada
  fila.
- **Migration necesaria (a ejecutar en Fase 03, no ahora):** una
  migración **aditiva**: `ALTER TABLE bylopez_cc_ordenes_confeccion_detalles
  ADD COLUMN prioridad VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER
  cantidad_entregada`. Al tener `DEFAULT 'normal'`, todas las filas
  existentes quedan automáticamente en `normal` sin necesidad de un paso
  de backfill manual — compatible hacia atrás sin tocar datos históricos.
- **Índice:** no se ve necesidad real de indexar `prioridad` por sí sola
  (no se filtran/ordenan datagrids masivos por prioridad todavía; si en
  Fase 03 se agrega un filtro "ver solo urgentes" en el DataGrid del
  Taller, se puede indexar en ese momento, no antes — evitar índices
  especulativos).
- **Sugerencia visual (no vinculante, es UI/Fase 03):** usar el mismo
  patrón de `badgeHtml()` que ya usan los enums de estado existentes.

---

## 11. Decisión sobre Taller predeterminado — [APROBADO]

**Confirmado: NO persistir.** Es exclusivamente una ayuda de
interfaz para pre-rellenar el `taller_id` de cada fila/asignación antes
de que el usuario decida enviar. Una vez enviado el formulario, lo único
que importa y se persiste es el `taller_id` real de cada
`OrdenConfeccion` creada — el "taller predeterminado" no tiene ningún
significado después de ese punto (no es un dato de negocio, es un valor
de conveniencia transitorio del formulario). No se identificó ninguna
razón real (reporting, auditoría, valor por Taller repetible entre
distribuciones) que justifique guardarlo. Si en el futuro se quisiera
recordar "el último taller usado para esta orden de corte" como
conveniencia entre sesiones, sería un dato de preferencia de UI (p. ej.
`localStorage` o una tabla de preferencias de usuario genérica), nunca
una columna en `ordenes_confeccion`.

---

## 12. Acciones masivas — soporte de backend necesario

Las acciones (seleccionar filas, asignar taller a seleccionados, asignar
prioridad a seleccionados, "asignar todo disponible", limpiar asignación)
son **100% comportamiento de UI/JavaScript que termina rellenando el
mismo payload de §6** — ninguna de ellas necesita un endpoint ni una
regla de negocio propia en el backend:

- "Asignar Taller a seleccionados" → solo cambia qué bloque
  `asignaciones[].taller_id` recibe cada fila antes de enviar.
- "Prioridad a seleccionados" → solo cambia `detalles[].prioridad` antes
  de enviar.
- "Asignar todo disponible" → solo copia el valor ya visible de
  `disponible` (que ya vino del servidor en el `GET` de la pantalla) al
  campo `cantidad` de esas filas.
- "Limpiar asignación" → solo quita filas/pone `cantidad=0` (y por lo
  tanto esas filas no se incluyen en el payload final, según §5).

**Lo único que el backend debe garantizar** es lo que ya diseña §8: sin
importar qué combinación arme el frontend con estos atajos, `distribuir()`
vuelve a validar taller activo, cantidades > 0, y suma total por
`product_id` contra el disponible recalculado en servidor. **No se
duplica ninguna regla de negocio en JavaScript** — el frontend solo arma
el payload, el servidor decide si es válido.

---

## 13. Arquitectura de lectura de stock Bagisto — [APROBADO conceptualmente, NO se implementa en Fase 03A]

### 13.1 Verificación adicional realizada esta fase

- El `product_id` que usa el módulo ya es el del `simple` (variante
  exacta), así que `Product::totalQuantity()` se puede invocar
  directamente sobre ese id sin necesidad de resolver el configurable
  padre — es exactamente el nivel al que Bagisto indexa stock por canal.
- Instalación real: **1 solo canal** (`default`, id=1) y **1 sola
  inventory source**. Se comprobó para un producto de muestra (id=173)
  que `product_inventory_indices.qty` (130) coincide con
  `product_inventories.qty` (130) — no hay reservas/ventas pendientes
  descontando en este momento, pero el diseño no debe asumir que
  siempre coincidirán.
- `core()->getCurrentChannel()` (usado internamente por
  `AbstractType::getInventoryIndex()`) depende del contexto de request
  del Admin; para un servicio de solo-lectura conviene no depender de ese
  helper implícito sino resolver explícitamente el canal necesario (hoy
  hay uno solo, pero el servicio debe quedar preparado para más).

### 13.2 Decisión de diseño

**Nuevo servicio dedicado: `StockConsultaService`** (nombre tentativo),
en vez de sobrecargar `ProductLookupService` (que hoy solo hace
lookup/snapshot de nombre+SKU, sin ninguna dependencia de inventario) o
crear una dependencia dispersa hacia modelos de Webkul desde varios
servicios distintos. Motivo: aísla en un único punto toda la dependencia
hacia `Webkul\Product`/inventario, de modo que si el mecanismo de stock
cambia (multi-canal, multi-fuente, reservas) solo hay que tocar un
archivo dentro de `ByLopez\CorteConfeccion`.

Responsabilidad única propuesta: `stockDe(int $productId): int`, delegando
en `Product::totalQuantity()` (o consultando `product_inventory_indices`
directamente si se necesita más control sobre el canal). **Sin
escritura, sin caché de stock en tablas propias**, cumpliendo
explícitamente CONFECCION_MASTER.md §11 ("no copiar stock").

No se crea en esta fase (es diseño, no implementación).

---

## 14. ¿Necesitamos una tabla "Distribucion"? — [APROBADO: Opción A]

| | **Opción A — Sin tabla nueva** | **Opción B — Cabecera `DistribucionConfeccion`** |
|---|---|---|
| Qué persiste | Solo las N `OrdenConfeccion` resultantes (como hoy) | Una fila "operación" + N `OrdenConfeccion` enlazadas a ella |
| Ventajas | Cero migraciones nuevas de estructura; reutiliza 100% el modelo ya probado; menos superficie de código | Permite reconstruir "qué se decidió en una sola operación" como una unidad; reversión conjunta futura; quién ejecutó la operación como concepto propio, no solo por-orden |
| Desventajas | Si en el futuro se necesita "deshacer toda la distribución de hoy" o "ver el resumen exacto que vio Bodega al confirmar", hay que reconstruirlo indirectamente (por `orden_corte_id` + rango de tiempo + usuario) | Tabla nueva + FK nueva; una entidad más que mantener, documentar y testear; hoy no hay ningún requisito funcional que pida "ver el historial de operaciones de distribución" como cosa distinta de "ver las órdenes de confección" |
| Costo de implementación | Bajo | Medio (migration + modelo + relación + ajustar el service para crear la cabecera primero) |
| Necesidad real hoy | El propio `codigo` (`OF-######`) y `created_at` de cada `OrdenConfeccion`, combinados con `orden_corte_id`, ya permiten agrupar visualmente "las órdenes que salieron juntas de esta orden de corte" (todas las creadas en el mismo rango de segundos, o simplemente todas las de esa `orden_corte_id`) sin necesidad de una tabla dedicada | No se identificó ningún requisito de CONFECCION_MASTER.md que exija explícitamente una entidad "Distribución" separada de las Órdenes de Confección resultantes |

**Recomendación explícita: Opción A (sin tabla nueva).** Justificación:
CONFECCION_MASTER.md nunca pide "ver el historial de distribuciones" como
concepto propio — pide que la distribución genere correctamente N
órdenes agrupadas por taller (§19) y que haya un resumen **antes** de
confirmar (§20), lo cual es un requisito de **pantalla** (mostrar el
resumen previo a enviar el formulario), no de **persistencia** (no hace
falta guardar ese resumen como entidad; una vez confirmado, lo que importa
es que existan las `OrdenConfeccion` correctas). Agregar una tabla
`Distribucion` ahora sería resolver un problema hipotético ("y si después
alguien quiere revertir todo junto") que el propio master doc no plantea
todavía, violando la instrucción explícita de "evitar complejidad
innecesaria". Si en una fase futura aparece un requisito real de
auditoría/reversión agregada, se puede añadir entonces sin que este
diseño lo bloquee (es una adición aditiva, no un cambio retroactivo).

---

## 15. Migrations potencialmente necesarias (para Fase 03, NO ejecutadas ahora)

Todas aditivas, ninguna destructiva:

1. `ALTER TABLE bylopez_cc_ordenes_confeccion_detalles ADD COLUMN
   prioridad VARCHAR(20) NOT NULL DEFAULT 'normal'` (§10).
2. Ninguna migración estructural para el cambio de disponibilidad (§4.4,
   §7) — es un cambio de consulta, no de esquema.
3. Ninguna migración para el payload multi-taller (§6, §8) — reutiliza
   las tablas existentes.
4. Ninguna migración para stock (§13) — es solo lectura contra tablas de
   Webkul, no se agrega nada en ByLopez.
5. (Fuera de alcance de esta fase, mencionado para el registro) si en el
   futuro se decide cerrar el riesgo de "producto configurable como
   product_id" (§3.3 punto 4) con un chequeo en base de datos en vez de
   solo en el Request, no se requeriría migración tampoco (sería una regla
   de validación en PHP, no una constraint SQL, dado que `products.type`
   vive en otra tabla de otro paquete).

---

## 16. Services/Requests que cambiarían (en Fase 03, no ahora)

- `DisponibilidadConfeccionService`: cambiar agrupación a solo
  `product_id` y excluir `estado = 'anulada'` (§4.4, §7).
- `OrdenConfeccionService`: agregar `distribuir()` (§8); `cancel()`
  necesitaría revisarse si se adopta la regla de §4.3 (aunque el cálculo
  de disponibilidad ya la resolvería solo con el cambio de query, sin
  tocar `cancel()` en sí).
- Nuevo `StoreDistribucionConfeccionRequest` (o renombrar/generalizar
  `StoreOrdenConfeccionRequest`) para validar el array de `asignaciones`
  en vez de un único `taller_id`+`detalles` plano.
- Nuevo `StockConsultaService` (§13).
- `OrdenConfeccionController`: nuevo método (o adaptar `store()`) que
  reciba el payload de §6 y llame a `distribuir()`.
- `create.blade.php`: rediseño de UI (fuera de alcance de este documento
  de modelo — es Fase 03).

---

## 17. Compatibilidad hacia atrás

- Las 3 `OrdenConfeccion` existentes y sus detalles **no se tocan**: el
  cambio de disponibilidad (§4.4/§7) solo afecta cómo se **calculan**
  cifras hacia adelante, no reescribe datos históricos.
- La columna `prioridad` nueva (§10) llega con `DEFAULT 'normal'`, así
  que las filas existentes quedan automáticamente clasificadas sin
  backfill manual.
- `talla` y `variant_id` se conservan sin cambios de esquema (§3.3) —
  se deja de usar `talla` en el cálculo pero sigue existiendo y
  mostrándose donde ya se muestra.
- El método `create()` actual (single-taller) puede seguir existiendo
  intacto o convertirse en un caso particular de `distribuir()` — en
  cualquier caso, ninguna `OrdenConfeccion` creada con el flujo viejo deja
  de ser válida con el flujo nuevo, porque el esquema no cambia.

---

## 18. Casos límite a cubrir

1. Distribuir 0 asignaciones (payload vacío) → rechazar con
   `ValidationException`, igual que hoy exige `detalles: min:1`.
2. Dos asignaciones del mismo payload apuntando al **mismo** `taller_id`
   (el usuario repite el taller dos veces en el formulario) → decidir en
   Fase 03 si se fusionan en una sola `OrdenConfeccion` o se rechazan
   como error de formulario; este documento no fuerza una respuesta
   porque depende del diseño de UI, pero **recomienda fusionar** (sumar
   sus detalles) para no crear dos órdenes redundantes del mismo taller
   el mismo día.
3. La suma de una misma variante entre todas las asignaciones excede el
   disponible → rechazo total de la operación (§8 paso 4.f), no un
   rechazo parcial silencioso.
4. Taller inactivo en cualquiera de las asignaciones → rechazo total de
   la operación completa (no crear las demás órdenes válidas y omitir
   solo la del taller inactivo) — coherente con "todo o nada" de §8.
5. **[APROBADO — ya no es un riesgo abierto, es comportamiento
   obligatorio]** `product_id` que resuelve a un producto `configurable`
   (§2.5/§3.3): la Fase 03A **debe** rechazar la operación completa con
   `ValidationException` (§8 paso 4.d), sin crear ninguna `OrdenConfeccion`
   — caso de prueba obligatorio (Caso 7 de `03A_MOTOR_DISTRIBUCION.md`).
6. Anular una `OrdenConfeccion` que es la única fuente de "ya entregado"
   de una variante → tras el cambio de §4.4, el disponible de esa
   variante debe volver exactamente a su valor previo a esa orden (ni más
   ni menos) — caso de prueba directo (§19).
7. Concurrencia: dos distribuciones simultáneas sobre la misma
   `OrdenCorte` que en conjunto exceden el disponible → la segunda debe
   fallar tras obtener el lock, nunca antes ni de forma silenciosa (§9).

---

## 19. Tests necesarios (para Fase 03, diseño de qué debe cubrirse)

- Caso funcional principal completo: recepción 100/80/70, distribuir
  60→Taller1 y 20→Taller4 en una sola llamada a `distribuir()`, verificar
  que quedan exactamente 2 `OrdenConfeccion`, cantidades correctas, y
  disponible remanente L=20, M=80, S=70.
- Exceso agregado: 70 a Taller1 + 40 a Taller4 sobre un disponible de 100
  → debe rechazar sin crear ninguna orden.
- Rollback: forzar fallo en la segunda asignación (p. ej. taller inactivo)
  → verificar que la primera tampoco quedó persistida.
- Anulación libera disponible: crear orden, anularla, verificar que
  `disponiblePara()` vuelve a su valor original.
- Concurrencia: test que abra dos transacciones (o simule con
  `lockForUpdate` y una segunda consulta bloqueada) para confirmar que el
  lock efectivamente serializa — al menos un test de regresión que
  documente la intención, aunque simular concurrencia real en Pest es
  limitado.
- Prioridad: default `normal` en órdenes creadas sin especificarla;
  órdenes existentes (creadas antes de la migración) siguen leyendo
  `normal` sin error.
- Filtro de cantidad 0: enviar una asignación con una fila en 0 → no debe
  crear ningún `OrdenConfeccionDetalle` para esa fila.
- Identidad de variante: dos `product_id` distintos para la misma talla
  pero diferente color nunca deben mezclarse en el cálculo de
  disponibilidad (ya cubierto implícitamente por agrupar por
  `product_id`, pero merece un test explícito con datos ficticios de dos
  colores).

---

## 20. Recomendación final

Evolucionar sin reemplazar: mantener `OrdenCorte → OrdenConfeccion →
OrdenConfeccionDetalle` y `OrdenConfeccion belongsTo Taller` tal cual
están. Los cambios necesarios son deliberadamente pequeños:

1. Corregir el cálculo de disponibilidad (excluir `anulada`, agrupar solo
   por `product_id`) — esto solo es un cambio de consulta.
2. Agregar un método de distribución multi-taller transaccional con lock
   sobre `RecepcionCorte`.
3. Agregar `prioridad` a `OrdenConfeccionDetalle` (migración aditiva
   trivial).
4. Aislar la lectura de stock en un servicio nuevo, sin tocar Webkul.
5. No crear tabla `Distribucion`, no persistir taller predeterminado, no
   duplicar reglas de negocio en JavaScript.

Ningún dato existente se pierde ni se reescribe. El caso 60/20/20 queda
cubierto de punta a punta: dato, cálculo, atomicidad y concurrencia.

---

## 21. Matriz de decisiones — TODAS APROBADAS

| Decisión | Alternativas consideradas | Recomendación / Decisión final | Motivo |
|---|---|---|---|
| Clave de variante | (a) `product_id` solo; (b) `product_id`+`talla`; (c) `variant_id` | **(a) `product_id` solo** — **APROBADO** | Confirmado con datos reales: `product_id` ya es el `simple` hijo único por color+talla; `talla` está vacía en 100% de recepción/confección; `variant_id` está en NULL en 100% de los casos y nunca se lee |
| Validación de tipo de `product_id` | (a) confiar en la convención actual (sin validar); (b) rechazar con `ValidationException` si `products.type === 'configurable'` | **(b) rechazar** — **APROBADO, obligatorio en Fase 03A** | No basta con que los datos existentes sean correctos; debe garantizarse en servidor (Caso 7) |
| Tabla `Distribucion` (cabecera) | (a) sin tabla nueva; (b) cabecera `DistribucionConfeccion` | **(a) sin tabla nueva** — **APROBADO** | Ningún requisito del master doc pide historial de "operaciones" como entidad separada de las `OrdenConfeccion` resultantes; evita complejidad no solicitada |
| Ubicación de prioridad | (a) `OrdenConfeccion` (por orden completa); (b) `OrdenConfeccionDetalle` (por variante) | **(b) `OrdenConfeccionDetalle`** — **APROBADO** | El propio ejemplo funcional pide prioridades distintas para variantes del mismo taller/orden |
| Tipo físico de columna `prioridad` | (a) `ENUM` nativo de MySQL; (b) columna `string`/`VARCHAR` + PHP Backed Enum | **(b) `string` + Backed Enum** — **APROBADO, corrección explícita** | Evita acoplar los valores válidos al esquema físico; permite evolucionar el dominio sin migración de tipo |
| Estrategia de transacción | (a) una transacción por taller; (b) una única transacción para todas las asignaciones del payload | **(b) una única transacción** — **APROBADO** | Requisito explícito: si falla una asignación, ninguna debe quedar creada (todo o nada) |
| Estrategia de concurrencia | (a) sin lock, solo revalidar; (b) `lockForUpdate` sobre `RecepcionCorte`; (c) lock por `RecepcionCorteDetalle` | **(b) `lockForUpdate` sobre `RecepcionCorte`, adquirido antes de recalcular disponibilidad** — **APROBADO** | (a) no evita la condición de carrera entre requests paralelas; (c) añade complejidad/riesgo de deadlock sin beneficio real dado el bajo volumen concurrente esperado sobre una misma orden de corte |
| Semántica de "anulada" | (a) dejar como está (pierde cantidad); (b) anular siempre libera cantidad; (c) exigir un nuevo estado "retorno" antes de poder anular | **(b) anular siempre libera cantidad**, con aviso procedimental en UI (diferido a Fase 03B) — **APROBADO, temporal** | (a) contradice el master doc explícitamente; (c) requiere el flujo de retorno de taller, fuera de alcance de esta fase; (b) es la mejora segura y mínima disponible hoy, documentando su límite conocido (Caso B) |
| Taller predeterminado | (a) persistir en `ordenes_confeccion`; (b) solo comportamiento de UI, no persistido | **(b) no persistir** — **APROBADO** | No es un dato de negocio; solo ayuda a rellenar el formulario |
| Servicio de stock | (a) ampliar `ProductLookupService`; (b) nuevo `StockConsultaService` | **(b) nuevo servicio dedicado** — **APROBADO conceptualmente, no se implementa en Fase 03A** | Aísla la dependencia hacia inventario de Webkul en un solo punto, sin mezclar responsabilidades con el lookup de nombre/SKU existente |
| Payload multi-taller | (a) seguir con un `taller_id` único por request (repetir llamadas); (b) `asignaciones[]` con un taller por bloque en una sola request | **(b) `asignaciones[]`** — **APROBADO** | Es el único diseño que permite cumplir el requisito explícito de "una sola operación → N órdenes con resumen previo" (CONFECCION_MASTER.md §19-20) |
