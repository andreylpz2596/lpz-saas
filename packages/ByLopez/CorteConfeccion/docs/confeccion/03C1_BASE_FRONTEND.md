# 03C1_BASE_FRONTEND.md — Base visual y de datos: "Distribuir a Talleres"

## 0. Estado de este documento

**COMPLETADA / PENDIENTE DE VALIDACIÓN.** Fecha: 2026-09-04. Implementa
únicamente la **base** de la pantalla diseñada en `03B_FRONTEND_DISTRIBUCION.md`
— sin dividir entre talleres, sin acciones masivas, sin estado Revisar,
sin submit al endpoint `distribuir()`. `packages/Webkul/` sin cambios.

---

## 1. Objetivo

Reemplazar la vista estática de una sola columna/un solo taller de
`ordenes-confeccion/create.blade.php` por una base moderna, con estado
por fila en Vue, que consuma ya el contrato real de `VarianteConsultaService`
(Fase 04B) para SKU/nombre/color/talla/stock — dejando la estructura de
datos y el layout responsive listos para que 03C2 agregue la interacción
completa sin tener que rehacer el DOM.

---

## 2. Archivos modificados

- `src/Http/Controllers/Admin/OrdenConfeccionController.php` — `create()`
  ahora inyecta `VarianteConsultaService`, arma `$filas` (disponibilidad +
  metadata de variante ya fusionadas) y `$talleres` (activos), en vez de
  pasar `$disponibilidad` cruda a la vista.
- `src/Resources/views/admin/ordenes-confeccion/create.blade.php` —
  reescrita por completo: componente Vue `v-distribuir-confeccion` con
  estado por fila, header con totales, controles predeterminados, tarjetas
  responsive por variante. Incluye
  `@include('corteconfeccion::admin.partials.estilos')` (no lo hacía
  antes — defecto ya detectado en 03B §3).

No se crearon Controllers/Requests/rutas/migraciones nuevas. No se tocó
`ProductLookupService`, `DisponibilidadConfeccionService` ni
`VarianteConsultaService` (solo se consumen).

---

## 3. Cómo llega la metadata/stock a `create()`

```
DisponibilidadConfeccionService::porOrdenCorte($ordenCorte)
        ↓ (Collection de filas: product_id, cantidad_recibida, ...)
    pluck('product_id')
        ↓
VarianteConsultaService::porProductIds($productIds)
        ↓ (Collection indexada por product_id: sku, nombre, color, talla,
            talla_etiqueta, stock_actual)
    merge por product_id, en el Controller
        ↓
$filas  (array plano, un elemento por variante, listo para @json())
```

Ninguna consulta a tablas de Webkul ocurre en el Controller ni en la
vista — ambas fuentes (`DisponibilidadConfeccionService`,
`VarianteConsultaService`) ya encapsulan las suyas. El merge en sí es una
sola pasada en PHP (`Collection::map()`), sin N+1 adicional: `porProductIds()`
ya resuelve todas las variantes de la orden en sus 5 consultas fijas
(Fase 04B), sin importar cuántas filas tenga la recepción.

Estructura exacta de cada elemento de `$filas` (uno por variante):

```php
[
    'product_id', 'sku', 'nombre', 'color', 'talla', 'talla_etiqueta',
    'stock_actual', 'cantidad_recibida', 'cantidad_defectuosa',
    'apta_confeccion', 'ya_distribuido', 'disponible_confeccion',
]
```

`$talleres` es `[{id, nombre}, ...]`, solo activos
(`TallerRepository::findWhere(['estado' => 'activo'])`), ordenados por
nombre.

---

## 4. Estructura del estado Vue por fila

Al montar, cada elemento de `filas` (prop, de solo lectura conceptual) se
expande a un objeto de estado editable en `data()`:

```js
{
    ...fila,                                    // todo lo que mandó el Controller
    entregar: 0,                                // SIEMPRE inicia en 0
    taller_id: tallerPreseleccionadoId ?? null,  // preseleccion por ?taller_id= si aplica
    prioridad: 'normal',                         // default aprobado
}
```

Computeds a nivel de pantalla (no por fila): `totalApto`,
`totalYaDistribuido`, `totalPendiente` (esta última ya dinámica: suma de
`disponible_confeccion - entregar` por fila, nunca negativa).

Método `limitarEntregar(fila)`: clampa `entregar` a `[0, disponible_confeccion]`
en cada `input` — guardia visual únicamente, el backend sigue siendo la
autoridad final (sin cambios en `OrdenConfeccionService::distribuir()`).

Método `aplicarTallerPredeterminado()`: al cambiar el taller predeterminado,
rellena **solo** las filas con `taller_id === null` — nunca reasigna una
fila que el usuario (o una preselección previa) ya dejó con un taller
propio, tal como aprobó 03B §6.

---

## 5. Resumen de la nueva UI

```
Distribuir Corte OC-WIL-000004                    [Revisar distribución] (deshabilitado)
Recibido en bodega: 2026-09-02
Apto: 170   Ya distribuido: 170   Pendiente: 0

┌─ Taller predeterminado ──────┐  ┌─ Fecha de entrega predeterminada ─┐
│ Sin taller predeterminado ▾  │  │ 2026-09-05                         │
└───────────────────────────────┘  └────────────────────────────────────┘

DISPONIBILIDAD PARA CONFECCION

┌────────────────────────────────────────────────────────────────┐
│ Camiseta Piel Durazno Verde Cali Hombre         STOCK ACTUAL     │
│ Verde Cali · Talla: L                                    130     │
│ SKU: 115DURZ-H-L                    Proyectado ~130 · no venta   │
│                                                                    │
│ DISPONIBLE CORTE   ENTREGAR AHORA   PENDIENTE DESPUES   TALLER   │
│      0                  [0]                0          [Selecc.▾]│
│                                                                    │
│ Recibido: 60  Defectuosa: 0  Apta: 60  Ya distribuido: 60         │
│                                          Prioridad [Normal ▾]     │
└────────────────────────────────────────────────────────────────┘
(una tarjeta por variante)
```

Verificado exactamente así contra datos reales (orden `OC-WIL-000004`,
3 variantes) — ver §9.

---

## 6. Comportamiento "Taller predeterminado"

Implementado tal como se diseñó y aprobó en 03B §6: ayuda de captura
(`<select>` en la cabecera), no persiste nada, rellena únicamente filas
sin taller propio, nunca dispara una asignación de cantidad. La
preselección por `?taller_id=` (comportamiento ya existente, cubierto por
un test previo) se preserva: inicializa tanto el taller predeterminado
como el `taller_id` de todas las filas al montar.

---

## 7. Stock actual y proyectado

- `stock_actual === null` → **"No disponible"** (nunca `0`).
- `stock_actual` numérico (incluido `0`) → se muestra tal cual.
- Proyectado = `stock_actual + entregar`, mostrado **solo si**
  `stock_actual !== null`, con la leyenda fija "no disponible para
  venta" — nunca se calcula ni se persiste en el backend (confirmado en
  04B §7: `VarianteConsultaService` nunca devuelve un campo de
  proyección).
- **"Stock bajo" NO se implementó** — no existe todavía un umbral de
  negocio definido para eso, y la instrucción de esta fase fue explícita:
  no inventarlo. Queda como decisión pendiente para cuando exista la
  regla (probablemente 03C2/03C3, o una fase de negocio dedicada).

---

## 8. Responsive implementado

Sin `<table>` en ningún punto — cada variante es una tarjeta
(`div.rounded-lg.border`) con dos niveles de grid:

- Bloque `stock actual`: `flex` con `shrink-0`, se apila naturalmente en
  pantallas angostas junto al bloque de nombre/color/talla/SKU
  (`flex-wrap`).
- Bloque `disponible / entregar / pendiente / taller`:
  `grid-cols-4` en escritorio → `max-md:grid-cols-2` en `<768px`.
- Verificado con un navegador real (ver §9): **sin overflow horizontal**
  ni en 1280px ni en 500px de ancho — el problema histórico del módulo
  (tablas con `min-w` fijo) no se repite porque nunca se usó `<table>`.

---

## 9. Tests ejecutados y resultado exacto

### 9.1 Automatizados (Pest)

```
vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/DistribuirBaseFrontendTest.php
Tests:    5 passed (19 assertions)

vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"
Tests:    100 passed (280 assertions)

vendor/bin/pint --test packages/ByLopez/CorteConfeccion
result: passed
```

Cubierto automáticamente:
- `create()` carga metadata de `VarianteConsultaService` (sku/nombre/color/talla) por fila.
- Disponibilidad (recibido/defectuosa/apta/ya_distribuido/disponible) llega correcta a la vista.
- Stock `0` llega como entero `0` (nunca `null`).
- Stock sin índice llega como `null` (nunca `0`).
- Solo talleres activos aparecen en el selector.
- La preselección por `?taller_id=` sigue funcionando (test preexistente de la Fase 03A, sin cambios, sigue en verde).

**No cubierto por Pest** (el proyecto no tiene infraestructura de test de
componentes Vue — no hay Vitest/Jest configurado, y añadirlo no estaba en
el alcance de esta fase): computeds reactivos (`totalPendiente`,
`limitarEntregar`, `aplicarTallerPredeterminado` en vivo). Se validaron
manualmente (§9.2).

### 9.2 Validación visual real (navegador headless, no simulada)

Se creó un Admin de prueba **temporal** (`qa-visual-preview@example.test`),
se levantó un servidor local (`php artisan serve`, puerto separado del
de desarrollo), y se usó automatización de navegador real (Playwright vía
skill `browser-automation`) para:

1. Iniciar sesión real como Admin.
2. Navegar a `/admin/corte-confeccion/ordenes-confeccion/create/311`
   (orden real `OC-WIL-000004`, 3 variantes reales del catálogo).
3. Confirmar: título de página correcto, **0 errores de consola**, 3
   tarjetas renderizadas, 3 inputs numéricos, 7 selects (1 predeterminado
   + 3 taller + 3 prioridad).
4. Capturar screenshot a 1280px (escritorio) y 500px (ancho reducido):
   **sin overflow horizontal en ninguno de los dos** (`scrollWidth ===
   viewportWidth` exacto en ambos casos, verificado programáticamente,
   no solo visualmente).
5. Confirmar por estilo computado que el botón "Revisar distribución"
   es realmente `disabled` (`opacity: 0.5`, `cursor: not-allowed`).

El Admin de prueba, el rol temporal, el servidor local y todos los
archivos de script/captura usados para esta verificación fueron
**eliminados** al finalizar — no queda ningún dato de prueba en la base
de datos real ni archivos temporales en el repositorio.

**Esto sí fue abierto y verificado realmente** — no es una afirmación sin
evidencia.

---

## 10. Diferencias frente a 03B

1. **Selector de Taller como `<select>` nativo poblado desde una lista ya
   cargada**, en vez de `x-corteconfeccion::record-search` (que 03B
   mencionaba como referencia). Justificación: la lista de talleres
   activos es pequeña y se conoce por completo de antemano — usar N
   componentes de búsqueda asíncrona (uno por fila) habría significado N
   llamadas AJAX potenciales sin necesidad, cuando un `<select>` ya
   resuelve el mismo caso de uso de forma más simple y sin peticiones de
   red adicionales. El componente `record-search` sigue existiendo y
   usándose donde sí aporta (búsquedas sobre catálogos grandes, como
   Talleres en otras pantallas).
2. **Botón "Revisar distribución" se incluye, pero permanentemente
   deshabilitado** en esta fase (03B no especificaba si debía omitirse o
   mostrarse deshabilitado en el estado base; se optó por mostrarlo, para
   dejar la estructura visual completa y coherente con el wireframe A de
   03B).
3. **Ningún `<form>` envuelve la pantalla todavía** — no hay nada que
   enviar hasta 03C2/03C3, así que no se agregó un `<form>` vacío ni se
   dejaron acciones POST parcialmente conectadas.

Ninguna decisión de 03B fue contradicha; estas son precisiones de
implementación dentro del alcance ya aprobado.

---

## 11. Riesgos / pendientes

- **La pantalla todavía no permite completar ninguna distribución real**
  — es una limitación intencional y temporal de esta subfase, no un
  bug. Hasta que 03C2/03C3 se implementen, la única forma de crear una
  `OrdenConfeccion` sigue siendo el endpoint `store()` (single-taller),
  que ya no tiene una vista propia enlazada — solo queda accesible
  programáticamente/por API, no desde esta pantalla. Se declara
  explícitamente para que no se asuma que el flujo está completo.
- **"Stock bajo" queda sin implementar** por ausencia de una regla de
  negocio definida (§7) — no es un olvido, es una decisión explícita de
  esta fase.
- Sin cobertura automatizada de la interacción Vue en vivo (computeds,
  clamps) — mitigado con validación visual real (§9.2), pero cualquier
  regresión futura en esa lógica no la detectaría la suite de Pest.
- El defecto histórico de overflow queda verificado como resuelto en
  esta pantalla específica con evidencia real — no se auditaron otras
  pantallas del módulo en esta fase.
