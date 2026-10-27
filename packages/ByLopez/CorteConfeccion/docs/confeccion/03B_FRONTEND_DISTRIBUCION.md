# 03B_FRONTEND_DISTRIBUCION.md — Diseño UX/Frontend: Distribuir a Talleres

## 0. Estado de este documento

**APROBADA.** Fecha de diseño: 2026-09-04. Fecha de aprobación:
2026-09-04. Ningún Blade/Vue/JS/CSS/Service/Controller/Request/ruta/
migration fue creado o modificado — sigue siendo exclusivamente diseño.
El backend multi-taller de la Fase 03A (`OrdenConfeccionService::distribuir()`,
`DistribuirConfeccionRequest`, endpoint
`admin.corte_confeccion.ordenes_confeccion.distribuir`) está aprobado y
no se rediseña aquí.

**Decisiones finales aprobadas (2026-09-04):**

1. **Un único componente Vue** montado sobre `create.blade.php`. Aprobado.
2. **Dos estados locales dentro de la misma pantalla** (EDITAR → REVISAR).
   Confirmado explícitamente: **no** usar navegación a una página
   independiente para la revisión — el estado Revisar (§13) es un cambio
   de vista local del mismo componente, nunca una nueva request/URL.
3. **`x-admin::drawer`** para "Dividir entre talleres" (§8). Aprobado tal
   como se diseñó, sin alternativa de modal.
4. **Barra contextual LOCAL** para acciones masivas (§11). Confirmado
   explícitamente: no usar el mecanismo remoto de mass-action del
   DataGrid, porque no existe persistencia hasta la confirmación final —
   toda acción masiva edita únicamente el estado en memoria del
   componente.
5. **Botón final: "Generar N órdenes de confección"**, con N calculado
   dinámicamente (§13/§14). Aprobado.
6. **Responsive de tres niveles** (escritorio → laptop → tarjetas en
   ancho reducido, §16), reutilizando patrones ya existentes del módulo
   (el patrón tabla→cards de `recepciones-corte/create.blade.php`) donde
   sea apropiado. Aprobado.
7. **Incluir `@include('corteconfeccion::admin.partials.estilos')`** en
   la futura Fase 03C. Confirmado que corresponde al patrón ya
   establecido del módulo: se verificó en código (auditoría de esta
   misma fase, §3) que `ordenes-corte/show.blade.php` y otras vistas del
   paquete ya lo usan, y que existe un build Tailwind propio de
   CorteConfeccion (`build-bylopez/corteconfeccion.css`) precisamente
   para esto — no es una duda abierta, es una corrección de un defecto
   real de la vista actual (§3, punto 3).

**Aclaración aprobada sobre Prioridad vs. Stock bajo (refuerzo de §9/§10.2):**
"Stock bajo" y "Prioridad: Urgente" son conceptos distintos y deben
comunicarse con tratamientos visuales distintos (ver §10.2) — nunca la
misma etiqueta para ambos. Aclaración adicional sobre la elección de
clases CSS ya existentes para los badges de Prioridad (§9): reutilizar
`label-canceled` para `Urgente` es **únicamente una reutilización de
estilo/color** (mismo tono de alerta que ya usa Bagisto), **no** un
acoplamiento conceptual entre "urgente" y "anulado/cancelado" — son
badges de dominios completamente distintos (`PrioridadOrdenConfeccion`
vs. `EstadoOrdenConfeccion`/`EstadoOrdenCorte`) que simplemente comparten
la clase CSS por conveniencia visual ya aprobada desde la Fase 03A. Si en
implementación (Fase 03C) esta coincidencia de clase genera confusión
visual real (por ejemplo, si ambas etiquetas llegan a aparecer juntas en
la misma fila y se leen ambiguas), se debe resolver con una clase CSS
propia del paquete (vía el build de CorteConfeccion, §16), nunca
reinterpretando el significado de "cancelado".

**Cambio de orden de fases (decisión del usuario, 2026-09-04):** antes de
construir la Fase 03C (implementación del frontend aquí diseñado), se
resolverán primero **04A_STOCK_VARIANTE_DISENO** y
**04B_STOCK_VARIANTE_IMPLEMENTACION**, para que 03C consuma desde el
principio el contrato real de lectura de stock/atributos de variante en
vez de placeholders (`—`) que luego habría que rehacer. Este documento
(03B) no cambia su diseño por esto: las columnas de stock siguen
reservadas visualmente tal como se diseñaron en §10; lo único que cambia
es **cuándo** se implementan (después de 04B, no en la primera versión
de 03C).

**Nota sobre Color/Talla (nuevo alcance para 04A):** el wireframe de §5
ya asume visualmente "Producto / Color · Talla / SKU" como bloque
(ejemplo: "Camiseta Hombre — Verde Cali · L — SKU: 115DURZ-H-L"), pero
este documento **no define de dónde sale técnicamente "Color"** — hoy
`RecepcionCorteDetalle`/`OrdenConfeccionDetalle` no tienen un campo color
estructurado, solo `producto_nombre` en texto libre. Queda
explícitamente prohibido resolverlo parseando `producto_nombre` con
strings; **04A_STOCK_VARIANTE_DISENO investigará y diseñará la fuente
correcta** (atributos estructurados de la variante Bagisto). El diseño
visual de §5 no cambia — solo cambia de dónde vendrá el dato que llena
esa celda.

---

## 1. Objetivo UX

Rediseñar la pantalla "Distribuir a talleres" para que Bodega pueda, en
pocos pasos y sin ambigüedad, decidir **qué** confeccionar, **cuánto**,
**para qué Taller** y **con qué prioridad**, dejando el resto
correctamente pendiente — sin excel mental, sin recalcular a mano, sin
miedo a distribuir de más.

No es un formulario técnico de captura de datos: es una **herramienta de
decisión operativa** que debe reducir el trabajo manual de Bodega frente
al proceso actual (que hoy exige repetir toda la operación una vez por
Taller, sin visión de conjunto).

---

## 2. Usuario principal

**Rol Bodega** (también usable por Admin). Perfil operativo, no técnico:
trabaja con listas físicas de prendas por producto/color/talla, conoce
sus talleres de memoria, necesita velocidad durante el día a día y
claridad cuando algo no cuadra. No se diseña para el Taller de Confección
ni para roles de solo lectura — esos consumen el resultado (vista de
Taller, ya existente), no esta pantalla.

---

## 3. Problemas de la pantalla actual (evidencia real, verificada en código)

`Resources/views/admin/ordenes-confeccion/create.blade.php` hoy:

1. **Sin componente Vue.** A diferencia de `ordenes-corte/create.blade.php`
   (que sí usa un componente rico `v-orden-corte-form`), esta pantalla es
   un `<form>` HTML estático con un `<input type="number">` por fila. Cero
   interactividad: no hay totales dinámicos, no hay "asignar todo
   disponible", no hay selección múltiple.
2. **Un solo Taller para todo el formulario.** `taller_id` es un campo
   único (input hidden de `x-corteconfeccion::record-search`) que aplica
   a todas las filas — exactamente la limitación que la Fase 03A ya
   resolvió en el backend (`distribuir()`), pero que la pantalla actual
   ni siquiera expone.
3. **No incluye la hoja de estilos propia del paquete.** A diferencia de
   `ordenes-corte/show.blade.php` (que sí hace
   `@include('corteconfeccion::admin.partials.estilos')`),
   `ordenes-confeccion/create.blade.php` **no la incluye**. Esto importa
   técnicamente (ver §15/§19): cualquier clase Tailwind usada ahí que no
   exista ya, literalmente, en algún blade de `packages/Webkul/Admin`, no
   se compila y no tiene efecto — es la causa más probable de que el
   contenido se saliera del viewport en pantallas anteriores del módulo.
4. **Tabla de ancho fijo sin estrategia responsive.**
   `min-w-[980px]` sin ninguna versión compacta/apilada — en pantallas
   angostas el usuario debe hacer scroll horizontal para ver "Entregar
   ahora", la columna más importante para actuar.
5. **Sin resumen antes de confirmar.** El submit es directo; no hay
   ningún paso de revisión, y con un solo Taller por operación tampoco
   había necesidad de "agrupar por Taller" antes de enviar.
6. **Sin prioridad, sin taller predeterminado, sin acciones masivas.**
   Ninguno de estos conceptos existe en la vista (son nuevos en el
   backend de la Fase 03A, todavía sin superficie visual).

---

## 4. Arquitectura de la información

Tres niveles claramente separados, siempre visibles en ese orden:

```
1. CONTEXTO         -> qué orden de corte se está distribuyendo, totales globales
2. DECISIÓN         -> tabla de variantes: cuánto, a quién, con qué prioridad
3. CONFIRMACIÓN     -> resumen agrupado por Taller antes de generar las órdenes
```

Un único componente Vue (mismo patrón ya usado en
`v-orden-corte-form`), montado sobre `create.blade.php`, con **dos
estados internos** (no dos páginas, no navegación):

- **Estado EDITAR** — tabla de variantes + acciones masivas + valores
  predeterminados.
- **Estado REVISAR** — resumen agrupado por Taller, de solo lectura
  salvo el botón "Volver y corregir".

Cambiar entre ambos es instantáneo (estado local de Vue): "Volver y
corregir" nunca pierde lo ya capturado.

---

## 5. Wireframe principal (estado EDITAR, vista de escritorio)

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Distribuir Corte OC-WIL-000004                                            │
│ Recibido en bodega: 02/09/2026                                            │
│                                                                             │
│  Apto: 250      Ya distribuido: 0      Pendiente: 250                     │
├──────────────────────────────────────────────────────────────────────────┤
│ Taller predeterminado: [Buscar taller...        ▾]   Fecha entrega: [——]  │
│ (ayuda de captura — no se guarda como campo, ver §6)                      │
├──────────────────────────────────────────────────────────────────────────┤
│ ☐  Producto              Stock  Disp.  Entregar   Taller        Prior.    │
├──────────────────────────────────────────────────────────────────────────┤
│ ☐  Camiseta Hombre        10     100    [   0 ]   [Seleccionar ▾] Normal ▾│
│    Verde Cali · L                              [Dividir entre talleres]  │
│    SKU: 115DURZ-H-L                                                       │
├──────────────────────────────────────────────────────────────────────────┤
│ ☐  Camiseta Hombre        48      80    [   0 ]   [Seleccionar ▾] Normal ▾│
│    Verde Cali · M                                                         │
│    SKU: 115DURZ-H-M                                                       │
├──────────────────────────────────────────────────────────────────────────┤
│ ☐  Camiseta Hombre        55      70    [   0 ]   [Seleccionar ▾] Normal ▾│
│    Verde Cali · S                                                         │
│    SKU: 115DURZ-H-S                                                       │
└──────────────────────────────────────────────────────────────────────────┘
                                                    [ Revisar distribución ]
```

Notas de diseño:

- El checkbox de fila solo existe para habilitar acciones masivas (§12);
  no representa "incluir/excluir" — la fila se incluye en el payload
  únicamente si `Entregar` > 0 (§7).
- "Stock" muestra el valor futuro de Bagisto (§10); hoy, mientras no
  exista, se muestra como `—` (ver §10.3).
- El botón principal dice **"Revisar distribución"**, no "Guardar":
  lleva al estado REVISAR (§13), nunca envía directamente al backend.

---

## 6. Comportamiento de "Taller predeterminado"

- Campo de ayuda visual en la cabecera (mismo componente
  `x-corteconfeccion::record-search` ya usado hoy para elegir Taller).
- Al seleccionarlo, **rellena el selector de Taller de cada fila que
  todavía no tenga uno asignado**, sin tocar filas ya asignadas
  manualmente a otro Taller.
- **No dispara ninguna asignación de cantidad.** Elegir el taller
  predeterminado nunca cambia ningún valor de "Entregar" — solo prepara
  el destino para cuando el usuario decida una cantidad.
- Cambiar el taller predeterminado **después** de haber rellenado filas
  no las reasigna retroactivamente (evita sorpresas): solo afecta filas
  aún sin Taller en ese momento.
- No se persiste en ningún campo de `OrdenConfeccion` — es puramente
  estado del formulario (Decisión 6 de la Fase 02, ya aprobada).

---

## 7. Cantidad a distribuir / pendientes

- Todo input "Entregar" nace en `0`.
- `max` visual = Disponible de esa fila (el navegador ya limita, pero el
  backend es la fuente de verdad — ver §16).
- Debajo de cada input, texto pequeño y siempre visible:
  `Pendiente después: 40` (se recalcula en vivo mientras el usuario
  escribe).
- Dejar una fila en `0` es un estado **válido y silencioso**: no se
  resalta como error, no se advierte, no se incluye en el payload final
  (igual que hoy filtra `StoreOrdenConfeccionRequest`, y como ya filtra
  explícitamente `DistribuirConfeccionRequest` de la Fase 03A).
- Acciones explícitas (nunca automáticas) para llenar cantidades:
  **"Asignar todo disponible"** (por fila, botón secundario pequeño junto
  al input) y, para selección múltiple, la misma acción desde la barra
  contextual (§12).

---

## 8. Interacción "Dividir entre talleres"

**Patrón elegido: Drawer lateral derecho** (`x-admin::drawer`, componente
ya existente y funcional en Bagisto Admin, ancho configurable).

**Por qué drawer y no modal ni expansión inline:**

| Opción | Problema |
|---|---|
| Expansión inline (la fila crece hacia abajo) | Con decenas de filas, empuja todo el resto de la tabla y el usuario pierde la referencia visual de dónde estaba — falla el requisito "usable con decenas de variantes". |
| Modal centrado | Compite visualmente por el mismo centro de pantalla que el paso de Revisión (§13); se siente pesado para una micro-tarea de una sola variante; en pantallas angostas dejaría muy poco espacio para listar varios talleres divididos. |
| **Drawer lateral** | Mantiene la tabla principal visible (atenuada) detrás, así el usuario nunca pierde el contexto de qué fila está dividiendo; da espacio vertical suficiente para agregar varias filas "Taller → cantidad" sin sentirse apretado; es exactamente el patrón ya construido y usado en Bagisto Admin, cero componente nuevo que inventar. |

**Contenido del drawer** (ejemplo con el caso de referencia, Hombre L):

```
┌───────────────────────────────┐
│ Dividir · Verde Cali · L    ✕ │
├───────────────────────────────┤
│ Disponible: 100                │
│                                 │
│ Taller 1        [ 60 ]  Urgente▾│
│ Taller 4        [ 20 ]  Urgente▾│
│ [+ Agregar taller]              │
│                                 │
│ Asignado: 80                    │
│ Sin distribuir: 20              │
├───────────────────────────────┤
│        [Cancelar] [Aplicar]    │
└───────────────────────────────┘
```

- "Asignado" y "Sin distribuir" se recalculan en vivo; **el input nunca
  permite superar el Disponible restante** (se limita visualmente fila a
  fila, y la suma total también se valida antes de habilitar "Aplicar").
- El backend vuelve a validar todo esto de forma independiente
  (`distribuir()` ya lo hace, Fase 03A) — el drawer es UX preventiva, no
  la única barrera.
- "Aplicar" cierra el drawer y actualiza la fila de la tabla principal
  para reflejar que esa variante ahora tiene múltiples asignaciones (ver
  representación en tabla, §8.1).

### 8.1 Cómo se ve en la tabla una variante ya dividida

Cuando una fila tiene más de una asignación, la celda de Taller dejar de
mostrar un único selector y pasa a mostrar un resumen compacto:

```
│ Camiseta Hombre   10  100  [80]  2 talleres ▾    Urgente        │
│ Verde Cali · L                   (60 T1 · 20 T4)                 │
```

Click en "2 talleres" reabre el mismo drawer con los valores ya
cargados, para editar sin perder nada.

---

## 9. Prioridad

- Selector compacto por fila (`Normal` por defecto), o por asignación
  individual dentro del drawer de división (cada Taller de una misma
  variante puede tener su propia prioridad, ej. L→Taller1 Urgente,
  L→Taller4 Normal).
- Representación visual: reutiliza el patrón de badge ya construido en
  el backend (`PrioridadOrdenConfeccion::badgeHtml()`, ya implementado en
  la Fase 03A): `label-info` (Normal), `label-pending` (Alta),
  `label-canceled` (Urgente) — mismas clases que ya usan
  `EstadoOrdenCorte`/`EstadoOrdenConfeccion`, sin inventar un esquema de
  color nuevo.
- **No pintar toda la fila** del color de la prioridad — el badge va
  únicamente en su propia columna, para no competir visualmente con el
  resto de datos de la fila (principio "sin exceso de color", §18).

---

## 10. Stock actual y stock proyectado

### 10.1 Diferenciación conceptual (obligatoria, visual)

| | Stock actual | Stock proyectado |
|---|---|---|
| Significado | Inventario vendible real de Bagisto | Stock actual + cantidad ya enviada a confección |
| Estilo | Texto normal, columna propia | Texto secundario/muted, con un icono de "proyección" (ej. `~` delante del número) y tooltip: *"Informativo: no es inventario real todavía"* |
| Nunca | No se etiqueta como "disponible para vender" | No se muestra como si fuera stock actual |

Ejemplo de celda:

```
Stock actual
10
~70 proyectado
```

### 10.2 Sugerencia de prioridad por stock bajo — sin confundir conceptos

Cuando el stock actual sea bajo, se muestra una **etiqueta textual sutil
junto al número de stock** (no un badge de color, para no parecerse a
Prioridad):

```
Stock actual
10 · Stock bajo
```

Esto es **solo informativo**. El selector de Prioridad de esa fila sigue
en su valor actual (por defecto `Normal`) — nunca se cambia
automáticamente. Si se quiere ayudar más, un tooltip en el propio badge
de Prioridad puede decir *"Sugerido: Urgente, por stock bajo"* — pero la
decisión de cambiarlo siempre la toma Bodega haciendo click.

### 10.3 Cómo convive con que el stock no existe todavía

Mientras la Fase 04 (lectura de stock) no exista, ambas columnas
muestran `—` (guion largo, sin badge, sin tooltip) en vez de `0` (para no
sugerir falsamente "sin stock"). El espacio de las columnas queda
reservado desde ya en la tabla, así que cuando la Fase 04 entregue datos
reales, **solo se rellena el valor — no se rediseña la tabla**.

---

## 11. Acciones masivas

**Patrón elegido: barra contextual flotante, visible únicamente cuando
hay ≥1 fila seleccionada** (no una barra de botones permanente).
Visualmente inspirada en la barra de mass-actions que Bagisto Admin ya
usa en sus DataGrids (`components/datagrid/toolbar/mass-action.blade.php`:
mismo lenguaje de "N seleccionadas" + dropdown), pero el mecanismo es
**local** (edita el estado del formulario en memoria) y no una llamada
remota — porque aquí no hay registros persistidos todavía, todo vive en
el navegador hasta el submit final.

```
┌──────────────────────────────────────────────────────────┐
│ 3 seleccionadas   [Taller: Taller 4 ▾] [Prioridad: Alta ▾]│
│                   [Asignar disponible]  [Limpiar]   [✕]   │
└──────────────────────────────────────────────────────────┘
```

- Aparece pegada arriba de la tabla (sticky) al marcar la primera fila;
  desaparece al desmarcar todas.
- Cada acción se aplica de inmediato a las filas seleccionadas y queda
  reflejada en la tabla (sin confirmación adicional, son reversibles
  editando de nuevo antes de enviar).
- "Limpiar" pone `Entregar = 0` y quita el Taller de las filas
  seleccionadas (vuelven a estado pendiente).
- El backend no necesita ningún endpoint nuevo para esto — todo llega
  ya resuelto dentro del payload final de `distribuir()`.

---

## 12. Resumen antes de generar (estado REVISAR)

```
┌──────────────────────────────────────────────────────────────┐
│ Revisar distribución                                    ✕/atrás│
├──────────────────────────────────────────────────────────────┤
│ Taller 1 — 60 prendas                        Entrega: 10/09  │
│   Verde Cali · L        60      Urgente                       │
│                                                                 │
│ Taller 4 — 80 prendas                        Entrega: 10/09  │
│   Verde Cali · L        20      Urgente                       │
│   Verde Cali · M        60      Normal                        │
├──────────────────────────────────────────────────────────────┤
│ Sin distribuir (queda pendiente en Bodega)                     │
│   Verde Cali · L   20     Verde Cali · M   20    Verde Cali · S 70│
├──────────────────────────────────────────────────────────────┤
│ Apto 250      Distribuir 140      Pendiente 110                │
├──────────────────────────────────────────────────────────────┤
│                    [Volver y corregir]  [Generar 2 órdenes]    │
└──────────────────────────────────────────────────────────────┘
```

- Agrupado por Taller (coincide 1:1 con las `OrdenConfeccion` que se van
  a crear — "Taller 1" y "Taller 4" del ejemplo son, literalmente, las 2
  órdenes que anuncia el botón).
- La sección "Sin distribuir" existe siempre que quede algo pendiente —
  refuerza que **no es un error**, es información.
- Totales (Apto/Distribuir/Pendiente) como cierre, en una sola línea, sin
  necesidad de tarjetas separadas.
- Botón primario: **"Generar 2 órdenes"** (cuenta dinámica de talleres
  con al menos una asignación) — más semántico que "Guardar" o
  "Confirmar" porque anticipa exactamente la consecuencia (§14).
- Botón secundario "Volver y corregir": regresa al estado EDITAR con
  todo intacto.

---

## 13. Confirmación — texto del botón

**Elegido: "Generar N órdenes de confección"** (con N dinámico, calculado
como cantidad de talleres distintos con al menos una asignación > 0).

Por qué no "Confirmar distribución": es correcto pero más abstracto —
"Generar N órdenes" además comunica de forma implícita "esto va a crear
N registros nuevos", ayudando a que el usuario entienda la escala de la
acción antes de hacer click (relevante porque es una operación que, a
diferencia de antes, ahora puede crear varias órdenes a la vez).

Caso borde: si el usuario llega a Revisar con una sola asignación, el
botón dice "Generar 1 orden de confección" (singular correcto).

---

## 14. Anulación (solo mensaje a futuro, sin pantalla nueva en esta fase)

No se diseña la pantalla de anulación en 03B. Se documenta el mensaje que
deberá mostrarse cuando esa acción exista:

> **Anular esta orden de confección**
> Esto devuelve las cantidades asignadas nuevamente al disponible para
> distribuir. Solo debe hacerse si las prendas **no salieron físicamente
> hacia el Taller**, o si ya fueron recuperadas de vuelta en Bodega.

Sin estados nuevos, sin cambios de flujo — es texto de advertencia sobre
la acción "Anular" que ya existe hoy en `ordenes-confeccion/show.blade.php`
(no se toca esa vista en esta fase; queda documentado para cuando se
haga).

---

## 15. Manejo de errores del backend

Distinguir dos niveles:

**A. Error de campo específico** (Laravel validation con clave
identificable, ej. `asignaciones.1.detalles.0.cantidad`): resaltar el
input exacto en rojo con el mensaje debajo, igual que ya hace
`x-admin::form.control-group.error` en el resto del módulo.

**B. Error de operación completa** (clave genérica como `asignaciones`,
`orden_corte_id`, `detalles`): banner arriba de la tabla, no un input
resaltado, porque no hay una única celda a la que atribuirlo. Mensajes
específicos por causa conocida, en vez de mostrar el string técnico
crudo:

| Causa backend (Fase 03A) | Mensaje al usuario |
|---|---|
| `cantidad-excede-disponible` (alguien más distribuyó mientras tanto) | **"La disponibilidad cambió. Actualizamos las cantidades para que puedas revisar la distribución."** + recarga automática de la tabla de disponibilidad + vuelve a estado EDITAR con lo que sí sigue siendo válido resaltado. |
| `taller-inactivo` | "Uno de los talleres seleccionados ya no está activo. Elige otro taller para esa fila." + resalta el/los talleres afectados. |
| `taller-duplicado-en-distribucion` | "Un mismo taller no puede aparecer dos veces en la misma distribución. Usa 'Dividir entre talleres' en la fila correspondiente en vez de crear dos asignaciones separadas." (en la UI diseñada aquí esto no debería poder ocurrir nunca desde la interacción normal, porque el selector de Taller por fila y el drawer de división son las únicas formas de asignar — ver casos límite §17). |
| `producto-configurable-no-valido` | No debería ocurrir nunca desde esta UI (las filas vienen siempre de `RecepcionCorteDetalle`, ya con `product_id` de variante simple) — si backend lo reporta igual, banner genérico: "Uno de los productos no es válido para confección. Contacta soporte." |
| Error de red / desconocido | Banner genérico neutro, nunca un stack trace ni JSON crudo. |

El caso "otro usuario distribuyó mientras tanto" es el más importante
(§16 del pedido original): la experiencia debe sentirse como *"te
ayudamos a corregir"*, no como un fallo — de ahí el refresco automático
de disponibilidad en vez de solo mostrar el error y dejar al usuario con
datos obsoletos en pantalla.

---

## 16. Responsive

Tres comportamientos, no un solo layout que se encoge:

**Escritorio grande / laptop ancha (≥1280px aprox.):** tabla completa,
todas las columnas de la §5 visibles.

**Laptop / ancho medio:** SKU pasa a ser texto secundario ya colapsado
bajo el nombre del producto (como en el wireframe de §5, no una columna
propia); "Stock proyectado" se oculta detrás de un tooltip/click sobre
"Stock actual" en vez de ocupar su propia columna.

**Ancho reducido (tablet/móvil, o Admin con sidebar expandido):** la
tabla **deja de ser tabla** y pasa a lista de tarjetas, una por variante
— **este patrón ya existe y ya funciona en el propio módulo**: es
exactamente lo que hace hoy `recepciones-corte/create.blade.php`
("el formulario de recepcion muestra cantidades fisicas como cards de
producto, no como tabla con columnas", ya cubierto por test existente).
Reutilizar el mismo patrón aquí, no inventar uno nuevo:

```
┌─────────────────────────────┐
│ Camiseta Hombre               │
│ Verde Cali · L                │
│ SKU: 115DURZ-H-L               │
│ Stock: 10   Disponible: 100    │
│ Entregar: [ 60 ]                │
│ Taller: [Seleccionar ▾]          │
│ Prioridad: Normal ▾               │
│ [Dividir entre talleres]           │
└─────────────────────────────┘
```

La barra de acciones masivas (§11) en móvil se ancla abajo (como un
"action sheet"), no arriba, para no tapar el checkbox de selección.

**Requisito técnico no negociable (evita repetir el problema anterior):**
esta vista **debe** incluir
`@include('corteconfeccion::admin.partials.estilos')` (algo que hoy
`create.blade.php` no hace, ver §3) y cualquier clase Tailwind nueva que
se necesite debe pasar por el build propio de CorteConfeccion
(`build-bylopez/corteconfeccion.css`), nunca asumir que una clase
arbitraria ya está en el bundle de `packages/Webkul/Admin` — ese bundle
**solo** escanea sus propios blade files (`tailwind.config.js` de
Webkul/Admin: `content: ["./src/Resources/**/*.blade.php", ...]`,
confirmado en código). Las clases que sí están garantizadas (porque ya
aparecen en vistas de Webkul reales) pueden usarse libremente sin pasar
por el build propio; todo lo demás, sí.

---

## 17. Componentes Bagisto de referencia (verificados en código, reutilizables)

| Necesidad | Componente | Notas |
|---|---|---|
| Selector de Taller (predeterminado y por fila) | `x-corteconfeccion::record-search` | Ya existe en el propio paquete, ya usado en `create.blade.php` actual. |
| Selector de producto (no aplica aquí — las filas vienen de la recepción, no se buscan) | — | A diferencia de `ordenes-corte/create.blade.php`, esta pantalla no necesita buscador de producto. |
| Panel de división de variante | `x-admin::drawer` | Header/content/footer slots, ancho configurable, ya funcional. |
| Resumen final (si se prefiriera modal en vez de estado de página — alternativa descartada, ver §8) | `x-admin::modal` | Soporta `panelClass` para anchos mayores a los 568px por defecto. |
| Dropdown simple (ej. selector de Prioridad) | `x-admin::dropdown` | Ya usado en varias vistas del propio módulo. |
| Badges de estado/prioridad | Clases ya existentes `label-active/label-pending/label-canceled/label-info/label-closed/label-processing` | Mismas que usan `EstadoOrdenCorte`, `EstadoOrdenConfeccion` y ya `PrioridadOrdenConfeccion` (Fase 03A). |
| Control de formulario estándar | `x-admin::form.control-group*` | Mismo patrón que el resto del módulo (labels, errores, inputs). |
| Layout de página | `x-admin::layouts` | Igual que todas las vistas actuales. |
| Patrón de tabla→cards en móvil | El propio `recepciones-corte/create.blade.php` | Reutilizar el patrón ya construido, no crear uno nuevo. |
| Barra de "N seleccionadas" + dropdown | `components/datagrid/toolbar/mass-action.blade.php` (solo como referencia visual) | El mecanismo real de esta pantalla es local, no la llamada remota que usa el DataGrid — ver §11. |

---

## 18. Riesgos UX

1. **Selector de Taller por fila puede volverse repetitivo con muchas
   variantes.** Mitigado por Taller predeterminado (§6) + acción masiva
   "Asignar Taller a seleccionados" (§11).
2. **El drawer de "Dividir entre talleres" podría sentirse como un paso
   extra si el usuario solo quiere UN taller por fila.** Mitigado: el
   caso simple (un taller por fila) nunca necesita abrir el drawer — el
   selector inline de Taller ya lo resuelve; el drawer es solo para el
   caso de dividir, no el camino principal.
3. **Confusión entre "Stock bajo" y "Prioridad Urgente"** si no se
   distingue visualmente con cuidado (§10.2) — riesgo real si en
   implementación se reutiliza sin querer el mismo estilo de badge para
   ambos.
4. **Refresco automático de disponibilidad tras un error de
   concurrencia (§15) podría descartar trabajo del usuario** si no se
   preservan las filas que seguían siendo válidas. Debe preservarse todo
   lo que no fue afectado, y solo ajustar/resaltar lo que cambió.
5. **Demasiada información por fila** (stock actual, proyectado,
   disponible, entregar, pendiente, taller, prioridad) puede saturar
   visualmente. Mitigado agrupando "Disponible/Entregar/Pendiente" como
   un mini-bloque de 3 números relacionados en vez de 3 columnas sueltas
   (ver wireframe §5).

---

## 19. Casos límite

- **Ninguna variante distribuida (todo en 0):** el botón "Revisar
  distribución" debe seguir habilitado — no es un error dejar todo
  pendiente, pero el estado Revisar en ese caso mostraría "Sin
  distribuir: todo" y ningún Taller, con el botón final deshabilitado
  (no tiene sentido "Generar 0 órdenes"; en su lugar mostrar mensaje
  "No hay nada para distribuir todavía").
- **Un mismo Taller elegido dos veces desde flujos distintos** (ej. fila
  L asignada directamente a Taller 4, y luego el usuario también intenta
  dividir la fila M y elegir Taller 4 ahí): la UI debe **fusionar
  visualmente** ambas asignaciones al mismo Taller en el estado Revisar
  bajo un solo bloque "Taller 4", para que el payload final nunca
  contenga dos bloques `asignaciones` con el mismo `taller_id` (la regla
  aprobada en 03A es "distinct" y rechaza duplicados — la UI debe
  garantizar que esto nunca llegue a ocurrir, agrupando por taller_id al
  construir el payload final, no solo al mostrarlo).
- **Todas las cantidades de una variante ya fueron distribuidas
  previamente (Disponible = 0):** la fila se muestra igual (no se oculta,
  por trazabilidad visual: "Verde Cali M — ya distribuido en su
  totalidad"), con el input deshabilitado y sin checkbox de selección
  masiva.
- **Recepción con una sola variante:** el drawer de "Dividir entre
  talleres" y la barra de acciones masivas deben seguir funcionando
  igual de bien con 1 fila que con 50 (no diseñar solo para el caso
  grande).
- **Fecha de entrega distinta por Taller (§3):** predeterminada rellena
  todas las asignaciones nuevas; el estado Revisar permite editar la
  fecha de un Taller específico ahí mismo (click sobre la fecha
  mostrada junto al nombre del Taller) sin volver al estado Editar.

---

## 20. Criterios de aceptación (para cuando se implemente, no ahora)

1. El caso de referencia (100/80/70 → 60 T1 + 20 T4 de L, resto
   pendiente) se completa sin recargar la página y sin abrir más de un
   drawer.
2. Ninguna fila en 0 genera una fila persistida (ya garantizado por
   backend, pero la UI tampoco debe siquiera enviarla).
3. Es imposible, desde la interacción normal de la UI, construir un
   payload con dos asignaciones al mismo `taller_id` (ver caso límite en
   §19).
4. El botón final siempre indica la cantidad exacta de órdenes a crear,
   nunca un texto genérico.
5. La tabla es usable (sin scroll horizontal para columnas clave) en
   ancho de laptop estándar (1366px) sin sidebar colapsada.
6. En ancho móvil/tablet, cada variante es completamente operable como
   tarjeta, sin perder ninguna acción disponible en escritorio.
7. Un error de "disponibilidad cambió" nunca se muestra como JSON crudo
   ni destruye lo que el usuario ya había capturado y seguía siendo
   válido.
8. La vista incluye `partials/estilos` y no depende de ninguna clase
   Tailwind que no esté ya garantizada en el bundle de Webkul/Admin o en
   el build propio de CorteConfeccion.

---

## 21. Wireframes obligatorios (A-G)

### A. Pantalla inicial sin asignaciones

```
┌────────────────────────────────────────────────────┐
│ Distribuir Corte OC-WIL-000004                       │
│ Recibido: 02/09/2026   Apto: 250   Distribuido: 0    │
│ Pendiente: 250                                        │
├────────────────────────────────────────────────────┤
│ Taller predeterminado: [Buscar...    ▾]  Fecha: [——] │
├────────────────────────────────────────────────────┤
│ ☐ Verde Cali · L   Stock — Disp.100 Entregar[0] Taller[Seleccionar▾] Normal▾│
│ ☐ Verde Cali · M   Stock —  Disp.80 Entregar[0] Taller[Seleccionar▾] Normal▾│
│ ☐ Verde Cali · S   Stock —  Disp.70 Entregar[0] Taller[Seleccionar▾] Normal▾│
└────────────────────────────────────────────────────┘
                                [Revisar distribución] (deshabilitado)
```

### B. Taller predeterminado seleccionado

```
Taller predeterminado: [Taller 4          ▾]  Fecha: [10/09/2026]
── Todas las filas sin Taller propio ahora muestran "Taller 4" ──
☐ Verde Cali · L   Disp.100 Entregar[0] Taller[Taller 4 ▾] Normal▾
☐ Verde Cali · M   Disp.80  Entregar[0] Taller[Taller 4 ▾] Normal▾
☐ Verde Cali · S   Disp.70  Entregar[0] Taller[Taller 4 ▾] Normal▾
(ninguna cantidad cambió; solo el destino quedó pre-rellenado)
```

### C. Algunas variantes asignadas

```
☑ Verde Cali · L   Disp.100 Entregar[60] Pendiente después: 40  Taller[Taller 1▾] Urgente▾
☐ Verde Cali · M   Disp.80  Entregar[0]                        Taller[Taller 4▾]  Normal▾
☐ Verde Cali · S   Disp.70  Entregar[0]                        Taller[Seleccionar▾] Normal▾
                                                        [Revisar distribución] (habilitado)
```

### D. División de una variante entre dos talleres (drawer abierto)

```
[fondo: tabla atenuada]                 ┌───────────────────────────┐
                                          │ Dividir · Verde Cali · L  ✕│
                                          ├───────────────────────────┤
                                          │ Disponible: 100             │
                                          │ Taller 1     [60] Urgente▾  │
                                          │ Taller 4     [20] Urgente▾  │
                                          │ [+ Agregar taller]          │
                                          │ Asignado: 80  Sin dist.: 20 │
                                          ├───────────────────────────┤
                                          │      [Cancelar] [Aplicar]  │
                                          └───────────────────────────┘
```

### E. Acciones masivas con varias filas seleccionadas

```
┌──────────────────────────────────────────────────────────┐
│ 2 seleccionadas  [Taller: Taller 4▾] [Prioridad: Alta▾]    │
│                  [Asignar disponible] [Limpiar]      [✕]   │
└──────────────────────────────────────────────────────────┘
☑ Verde Cali · M   Disp.80  Entregar[0]  Taller[Seleccionar▾] Normal▾
☑ Verde Cali · S   Disp.70  Entregar[0]  Taller[Seleccionar▾] Normal▾
```

### F. Resumen final antes de confirmar

```
┌──────────────────────────────────────────────────────┐
│ Revisar distribución                                   │
│ Taller 1 — 60 prendas         Entrega: 10/09            │
│   Verde Cali · L    60   Urgente                        │
│ Taller 4 — 80 prendas         Entrega: 10/09            │
│   Verde Cali · L    20   Urgente                        │
│   Verde Cali · M    60   Normal                         │
│ Sin distribuir: L 20 · M 20 · S 70                       │
│ Apto 250   Distribuir 140   Pendiente 110                │
│                [Volver y corregir] [Generar 2 órdenes]  │
└──────────────────────────────────────────────────────────┘
```

### G. Estado/error cuando la disponibilidad cambió

```
┌──────────────────────────────────────────────────────┐
│ ⚠ La disponibilidad cambió. Actualizamos las cantidades │
│   para que puedas revisar la distribución.               │
├────────────────────────────────────────────────────────┤
│ ☐ Verde Cali · L  Disp. 40 (antes 100)  Entregar[0]★    │
│    ★ tu valor anterior (60) ya no cabe — lo reiniciamos  │
│ ☐ Verde Cali · M  Disp.80  Entregar[60] (sin cambios)    │
│ ☐ Verde Cali · S  Disp.70  Entregar[0]  (sin cambios)    │
└────────────────────────────────────────────────────────┘
                                          [Revisar distribución]
```

---

## 22. Recomendación final

Construir esta pantalla como un único componente Vue con dos estados
(Editar/Revisar), reutilizando exclusivamente componentes ya existentes
en Bagisto Admin y en el propio paquete (`record-search`, `drawer`,
`dropdown`, badges de enum, patrón tabla→cards de `recepciones-corte`),
sin inventar mecanismos nuevos de UI. El único elemento genuinamente
nuevo es la barra de acciones masivas contextual (§11), y su
justificación es que el mecanismo remoto del DataGrid de Bagisto no
aplica a un formulario que aún no persiste nada. Antes de implementar,
corregir el defecto ya identificado (§3.3): incluir
`partials/estilos` en esta vista desde el primer commit de la Fase 03C,
para no repetir el problema de overflow de pantallas anteriores.

No implementar todavía — este documento espera aprobación.
