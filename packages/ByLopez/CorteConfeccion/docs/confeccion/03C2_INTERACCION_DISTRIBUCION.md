# 03C2_INTERACCION_DISTRIBUCION.md — Interacción completa de distribución

## 0. Estado de este documento

**APROBADA (2026-09-08).** Ver §26 para el cierre final del único punto
pendiente (confirmación de navegador real del breakpoint `md` en
768-1023px). Fecha de diseño:
2026-09-05. Fecha de implementación: 2026-09-05. Este documento pasó de
"alcance general" a especificación detallada de estado Vue e
interacciones, y ahora incluye la implementación real con evidencia
(§23). `create.blade.php` fue reescrito con el componente Vue
`v-distribuir-confeccion` implementando todo lo diseñado aquí, con 3
ajustes finales de aprobación (ver §23.1). Ningún
Controller/Service/Request/ruta/migración fue tocado — el Controller ya
exponía todo lo necesario desde 03C1. `packages/Webkul/` sin cambios. No
se inició 03C3. 03A, 03B, 04A, 04B y 03C1 se leyeron como contexto
congelado y **no se reabrieron** — todo lo implementado aquí es una
extensión de ese estado, nunca una reinterpretación.

**Historial:** el hallazgo de densidad de §23.5 quedó **resuelto** con el
ajuste de la §24 (opción B: layout compacto desktop/laptop + barra de
acciones masivas `fixed`, con reducción real de ~72% de altura en
1280/1024px, medida con navegador real). La validación acotada de §25
encontró un hueco UX real en 768-991px y bajó el breakpoint de `lg` a
`md` (768px) con evidencia indirecta, pero en su momento no pudo
confirmarse con navegador real por un bloqueo de memoria del entorno
(§25.4). Esa confirmación puntual (900px y 768px) ya se ejecutó con
éxito — ver §26 — y la fase quedó **APROBADA**.

---

## 1. Objetivo (sin cambios respecto al alcance aprobado)

Definir cómo evoluciona el componente Vue de 03C1 para soportar
selección, acciones masivas, división entre talleres, fechas por Taller
y la construcción canónica del payload — sin implementar todavía el
submit real (eso es 03C3).

---

## 2. Arquitectura de estado Vue

### 2.1 Modelo de estado por fila — simple vs. dividido

**Decisión: campo explícito `modo: 'simple' | 'dividido'`, con las dos
fuentes de cantidad mutuamente excluyentes por construcción.**

```js
{
    // --- metadata de 03C1, sin cambios (viene de $filas del Controller) ---
    product_id, sku, nombre, color, talla, talla_etiqueta, stock_actual,
    cantidad_recibida, cantidad_defectuosa, apta_confeccion,
    ya_distribuido, disponible_confeccion,

    // --- estado nuevo/evolucionado en 03C2 ---
    modo: 'simple',            // 'simple' | 'dividido'
    entregar: 0,               // fuente de verdad SOLO si modo === 'simple'
    taller_id: null,           // fuente de verdad SOLO si modo === 'simple'
    prioridad: 'normal',       // fuente de verdad SOLO si modo === 'simple'
    repartos: [],              // fuente de verdad SOLO si modo === 'dividido'
                                // [{ taller_id, cantidad, prioridad }, ...]
    tocadaManualmente: false,  // ver §9
    seleccionada: false,       // puramente UI, nunca viaja al payload
}
```

**Por qué esta estructura evita doble fuente de verdad:** en vez de dejar
`entregar`/`taller_id`/`prioridad` y `repartos` coexistiendo siempre (lo
que obligaría a sincronizarlos en cada cambio y a decidir cuál "gana" si
divergen), el campo `modo` los vuelve **mutuamente excluyentes por
diseño**: cuando `modo === 'dividido'`, los tres campos simples dejan de
leerse en absoluto — ni para mostrar, ni para calcular, ni para el
payload. No existe un paso de "sincronizar" porque nunca hay dos fuentes
activas al mismo tiempo, solo una inactiva que se ignora.

Se descartó una alternativa evaluada (mantener siempre `repartos[]` como
única estructura, con longitud 1 para el caso simple) porque habría
complicado innecesariamente la UI del caso más común — la inmensa
mayoría de filas nunca se dividen (ver evidencia del catálogo real en
`04A_STOCK_VARIANTE_DISENO.md`) — sin aportar ninguna ventaja real sobre
el campo `modo` explícito.

### 2.2 Función compartida para "cuánto se está entregando" (ver también §16)

```js
function filaEntregarActual(fila) {
    return fila.modo === 'dividido'
        ? fila.repartos.reduce((sum, r) => sum + Number(r.cantidad || 0), 0)
        : Number(fila.entregar || 0);
}
```

Esta es la **única** función que calcula "cuánto se está distribuyendo
ahora" para una fila. Se reutiliza literalmente (no se reimplementa) en:
el total del header, la celda "Entregar ahora" de la tarjeta, el
constructor del payload (§14), y el futuro resumen de 03C3. Ninguna otra
parte del código vuelve a sumar `repartos` por su cuenta.

---

## 3. Convertir Simple → Dividido

Al pulsar "Dividir entre talleres" se abre el drawer (§4) con un estado
**borrador** (`repartosDraft`), separado de `fila.repartos` hasta que se
confirme — así, cerrar sin guardar (caso límite §18.12) nunca modifica
nada.

**Semilla del borrador al abrir:**

```js
repartosDraft = (fila.taller_id && fila.entregar > 0)
    ? [{ taller_id: fila.taller_id, cantidad: fila.entregar, prioridad: fila.prioridad }]
    : [{ taller_id: null, cantidad: 0, prioridad: fila.prioridad || 'normal' }]
```

- Si ya había una asignación simple válida (taller + cantidad > 0), se
  precarga como la primera línea del reparto — **nada de lo ya digitado
  se pierde**, tal como pide la preferencia del pedido.
- Si no había nada que preservar (cantidad 0 o sin taller), se arranca
  con **una fila vacía lista para escribir**, en vez de una lista
  completamente en blanco — evita un clic extra de "+ Agregar Taller"
  cuando de todos modos hay que agregar la primera línea.

**Al pulsar "Aplicar" en el drawer:**

```js
const repartosValidos = repartosDraft.filter(r => r.taller_id && r.cantidad > 0);

if (repartosValidos.length === 0) {
    // Equivalente a "Quitar división" (§3 de abajo) -- dividir en nada
    // es lo mismo que no dividir.
    aplicarQuitarDivision(fila);
} else {
    fila.repartos = repartosValidos;
    fila.modo = 'dividido';
    fila.tocadaManualmente = true;   // dividir es siempre una decision manual explicita
}
```

No se exige un mínimo de 2 repartos para considerar la fila "dividida":
si el usuario deja una sola línea con taller+cantidad válidos y pulsa
Aplicar, `modo` pasa a `'dividido'` igual (fue una decisión explícita
del usuario al abrir el drawer, no se revierte silenciosamente).

---

## 4. Dividido → Simple ("Quitar división")

**Acción explícita, nunca automática**, disponible como botón en la
tarjeta (cuando `modo === 'dividido'`) y también en el footer del drawer.
Requiere confirmación (reutilizando el patrón ya existente en el módulo:
`$emitter.emit('open-confirm-modal', { title, message, agree })`, el
mismo mecanismo que ya usan `ordenes-corte/show.blade.php` y
`recepciones-corte/create.blade.php`).

**Al confirmar:**

```js
fila.repartos = [];
fila.entregar = 0;
fila.prioridad = 'normal';
fila.taller_id = tallerPredeterminado ?? null;
fila.modo = 'simple';
fila.tocadaManualmente = false;   // reset completo: vuelve a ser candidata
                                    // para el auto-relleno de Taller predeterminado (§9)
```

**Decisión documentada:** no se intenta "adivinar" cuál de los repartos
conservar cuando hay varios — se resetea siempre a un estado limpio y
predecible. Es la alternativa más segura frente a conservar
accidentalmente una cantidad parcial que el usuario no revisó.

---

## 5. Drawer "Dividir entre talleres" — contenido exacto

Componente: `x-admin::drawer` (ya aprobado, 03B §8). Estructura:

```
┌─ Header ──────────────────────────────────────────┐
│ Dividir · [nombre]                              ✕ │
│ [color] · [talla_etiqueta]: [talla]                │
│ SKU: [sku]                                          │
│ Disponible total: [disponible_confeccion]           │
├─ Content ───────────────────────────────────────────┤
│ Taller ▾        Cantidad     Prioridad ▾    🗑      │
│ [Taller 1  ▾]   [   60  ]    [Urgente ▾]    🗑      │
│ [Taller 4  ▾]   [   20  ]    [Alta    ▾]    🗑      │
│                                                       │
│ [+ Agregar Taller]                                   │
│                                                       │
│ Disponible   100     Asignado   80     Pendiente  20 │
│ ⚠ (mensajes de validación inline, si aplica)         │
├─ Footer ─────────────────────────────────────────────┤
│         [Quitar división]   [Cancelar]   [Aplicar]   │
└───────────────────────────────────────────────────────┘
```

**Validaciones preventivas (bloquean "Aplicar" mientras existan):**

| Regla | Mensaje |
|---|---|
| Fila con `cantidad > 0` pero sin `taller_id` | "Cada cantidad necesita un taller." |
| Suma de `cantidad` (filas con taller válido) `>` disponible | "La suma (X) supera el disponible (Y)." |
| Dos o más filas con el mismo `taller_id` | "El mismo taller no puede aparecer dos veces en esta variante — súmalo en una sola línea." |

- "+ Agregar Taller" agrega una fila vacía; el `<select>` de esa fila
  **excluye** los `taller_id` ya usados en otras filas del borrador (
  evita la duplicación en el origen, además de la validación de
  respaldo).
- "Eliminar" (🗑) quita esa línea del borrador sin pedir confirmación
  (es un borrador, no el estado final — la confirmación real ocurre al
  "Quitar división" sobre una fila ya comprometida).
- El backend (`OrdenConfeccionService::distribuir()`) sigue validando
  todo esto de forma independiente — estas reglas son UX preventiva, no
  la única barrera (mismo principio ya aplicado en 03B/03C1).

---

## 6. Prioridad en variantes divididas

Cada entrada de `repartos[]` tiene su propia `prioridad` — **nunca** se
asume una prioridad única para toda la variante dividida.

**Etiqueta mostrada en la tarjeta principal (fuera del drawer):**

```js
function prioridadMostrada(fila) {
    if (fila.modo === 'simple') return fila.prioridad;

    const valores = new Set(fila.repartos.map(r => r.prioridad));
    return valores.size === 1 ? [...valores][0] : 'mixta';
}
```

`'mixta'` se muestra con su propio texto ("Prioridad mixta"), sin badge
de color de ninguno de los tres valores reales — para no sugerir
falsamente que la variante completa tiene una prioridad única. El detalle
exacto por taller solo se ve abriendo el drawer.

---

## 7. Selección de variantes

Cada tarjeta tiene un checkbox local (`fila.seleccionada`, puramente UI,
nunca viaja al payload). "Seleccionar todas" opera sobre las
**seleccionables actualmente visibles**:

```js
const filasSeleccionables = computed(() =>
    filasState.filter(f => f.disponible_confeccion > 0)
);
```

(las de `disponible_confeccion === 0` nunca son seleccionables — ver §19).

**Decisión sobre filas divididas:** siguen siendo seleccionables
normalmente (su checkbox no se deshabilita) — la protección no está en
impedir seleccionarlas, sino en que las acciones masivas las **excluyan
silenciosamente del efecto** y **avisen explícitamente** que fueron
excluidas (§8). Se prefirió esto sobre deshabilitar su checkbox porque:
deshabilitar generaría una pregunta inmediata ("¿por qué no puedo
marcarla?") sin contexto en el momento de la selección, mientras que
excluirla y avisar **en el momento de ejecutar la acción** da la
explicación exactamente cuando es relevante ("qué habría pasado si
hubiera tenido efecto"). Esta es una preferencia de diseño razonada, no
una certeza absoluta — queda marcada como punto a confirmar (ver
respuesta final, punto 10).

---

## 8. Acciones masivas — semántica exacta

Barra contextual (aprobada en 03B §11 / 03C1, mecanismo local, no el
remoto del DataGrid). Aparece solo con `≥1` fila seleccionada.

### A. Asignar Taller
```js
filasSeleccionadas.filter(f => f.modo === 'simple').forEach(f => {
    f.taller_id = tallerElegido;
    f.tocadaManualmente = true;
});
```
No toca `entregar` ni `prioridad`.

### B. Prioridad
```js
filasSeleccionadas.filter(f => f.modo === 'simple').forEach(f => {
    f.prioridad = prioridadElegida;
});
```
No toca `entregar` ni `taller_id`, y **no** marca `tocadaManualmente`
(ese flag solo rastrea decisiones sobre el Taller, ver §9 — cambiar solo
la prioridad no es una decisión de taller).

### C. Asignar todo disponible
```js
const sinTaller = [];

filasSeleccionadas.filter(f => f.modo === 'simple').forEach(f => {
    if (!f.taller_id) { sinTaller.push(f); return; }
    f.entregar = f.disponible_confeccion;
});

if (sinTaller.length) {
    mostrarAviso('Selecciona un Taller predeterminado o asigna Taller antes de distribuir.');
}
```
Nunca elige un taller arbitrariamente — las filas sin taller quedan
intactas (`entregar` sin cambios) y se informa cuántas/cuáles.

### D. Limpiar asignación
```js
filasSeleccionadas.filter(f => f.modo === 'simple').forEach(f => {
    f.entregar = 0;
    f.prioridad = 'normal';
    f.taller_id = tallerPredeterminado ?? null;
    f.tocadaManualmente = false;
});
```
No borra `sku`/`nombre`/`color`/`talla`/`stock_actual` ni ningún dato de
metadata — solo el estado de asignación.

---

## 9. Acciones masivas y filas divididas (crítico)

**Las cuatro acciones (A-D) siempre filtran por `modo === 'simple'`
antes de aplicar nada** — nunca se toca `repartos[]` desde una acción
masiva, bajo ninguna circunstancia.

Después de ejecutar cualquiera de las cuatro, si alguna fila
seleccionada tenía `modo === 'dividido'`:

```
"2 variantes divididas no fueron modificadas. Edítalas desde su división."
```

(mensaje genérico, mismo texto para las cuatro acciones — el número
siempre refleja cuántas divididas había en la selección de esa
ejecución puntual). Ninguna acción masiva puede, bajo ningún flujo,
sobrescribir `repartos[]`.

---

## 10. Taller predeterminado — detección de "tocada manualmente"

**Refinamiento respecto a la regla de 03C1** (03C1 rellenaba solo filas
con `taller_id === null`). Con acciones masivas y división ya en juego,
un `null`-check ya no alcanza para distinguir "nunca se decidió nada"
de "se decidió explícitamente algo" — por eso se introduce el flag
`tocadaManualmente` (§2.1), que se activa exactamente en estos momentos:

| Evento | `tocadaManualmente` |
|---|---|
| Usuario cambia el `<select>` de Taller de una fila directamente | `true` |
| Acción masiva "Asignar Taller" (§8.A) | `true` |
| Fila pasa a `modo: 'dividido'` (§3) | `true` |
| "Limpiar asignación" (individual o masiva, §8.D) | `false` |
| "Quitar división" (§4) | `false` |
| Fila recién cargada desde el Controller | `false` |

`aplicarTallerPredeterminado()`:

```js
filasState
    .filter(f => f.modo === 'simple' && !f.tocadaManualmente)
    .forEach(f => { f.taller_id = tallerPredeterminado; });
```

Las filas divididas **nunca** se tocan aquí, sin excepción — el filtro
`modo === 'simple'` ya las excluye estructuralmente.

**Nota sobre el alcance de este refinamiento:** no contradice la regla
congelada de 03C1 ("Taller predeterminado no asigna cantidades, no
persiste, solo rellena filas sin taller") — la extiende para que siga
siendo cierta también en presencia de división y acciones masivas, que
03C1 no contemplaba. Se marca igualmente como punto a confirmar en la
respuesta final.

---

## 11. Fecha de entrega por Taller

### 11.1 Modelo de estado (simplificado respecto a la estructura sugerida)

En vez de guardar `{ fecha_entrega, personalizada }` para **cada**
taller usado (lo que obligaría a "sincronizar" manualmente cada entrada
sin override cuando cambia la fecha predeterminada — exactamente el tipo
de doble fuente de verdad que §2.1 evita para las filas), se propone un
**mapa disperso de solo overrides**:

```js
configuracionTalleres: {
    // SOLO contiene entradas para talleres con fecha personalizada
    4: '2026-09-12',
}
```

```js
function fechaEfectiva(tallerId) {
    return configuracionTalleres[tallerId] ?? fechaPredeterminada;
}
```

Con esto, §12 (cambiar la fecha predeterminada) queda resuelto **sin
ningún paso de sincronización**: un taller sin entrada en
`configuracionTalleres` automáticamente adopta cualquier valor nuevo de
`fechaPredeterminada` porque `fechaEfectiva()` lo lee en el momento, no
antes. Un taller con override simplemente ignora `fechaPredeterminada`
hasta que el usuario pulse "Usar predeterminada" (que solo hace
`delete configuracionTalleres[tallerId]`).

### 11.2 Sección "Fechas por Taller"

Visible **solo si** `talleresUtilizados.length > 0` (§13). Por cada
taller usado:

```
Taller 1     10/09/2026                    [Personalizar]
Taller 4     12/09/2026 [personalizada]    [Usar predeterminada]
```

"Personalizar" revela un `<input type="date">`; al confirmarlo, escribe
en `configuracionTalleres[tallerId]`. Compacta a propósito: no ocupa
espacio para talleres que no la necesitan, y nunca aparece si todavía no
hay ningún taller con cantidad asignada.

---

## 12. Observaciones

**Decisión: una sola observación general, opcional, aplicada a todas las
asignaciones del payload — sin override por Taller en esta fase.**

```js
observacionGeneral: null,  // string|null, un solo campo de estado global
```

Se difiere explícitamente el override por Taller a 03C3 (o a una
revisión posterior si el uso real lo pide) — no se identificó, revisando
`CONFECCION_MASTER.md` ni las fases previas, un caso de uso concreto que
lo exija ya, y agregar un campo de texto más a la ya recién creada
sección "Fechas por Taller" (§11) la sobrecargaría sin necesidad
comprobada. El campo del backend ya existe y no requiere cambios:
`DistribuirConfeccionRequest` (Fase 03A) ya acepta
`asignaciones.*.observacion` (`nullable`) — 03C2 solo necesita que el
constructor del payload (§14) lo rellene con este valor único.

---

## 13. Talleres realmente utilizados

**Regla:** un `taller_id` cuenta como usado si y solo si existe al menos
una cantidad `> 0` apuntándole — nunca por solo tener el `<select>` en
ese valor.

```js
const talleresUtilizados = computed(() => {
    const ids = new Set();

    filasState.forEach(fila => {
        if (fila.modo === 'simple') {
            if (fila.taller_id && fila.entregar > 0) ids.add(fila.taller_id);
        } else {
            fila.repartos.forEach(r => {
                if (r.taller_id && r.cantidad > 0) ids.add(r.taller_id);
            });
        }
    });

    return [...ids];
});
```

Esta es la **misma** lógica de recorrido que usa el constructor del
payload (§14) — no una reimplementación paralela. Afecta directamente:
- el futuro contador "N" de órdenes a generar (03C3);
- qué filas aparecen en "Fechas por Taller" (§11.2);
- las claves del payload;
- el futuro resumen agrupado por Taller (03C3).

---

## 14. Construcción canónica del payload

```js
function construirAsignaciones(filasState, configuracionTalleres, fechaPredeterminada, observacionGeneral) {
    const detallesPorTaller = new Map();   // taller_id -> [{product_id, cantidad, prioridad}]

    function agregar(tallerId, detalle) {
        if (!detallesPorTaller.has(tallerId)) detallesPorTaller.set(tallerId, []);
        detallesPorTaller.get(tallerId).push(detalle);
    }

    filasState.forEach(fila => {
        if (fila.modo === 'simple') {
            if (fila.taller_id && fila.entregar > 0) {
                agregar(fila.taller_id, {
                    product_id: fila.product_id,
                    cantidad: fila.entregar,
                    prioridad: fila.prioridad,
                });
            }
        } else {
            fila.repartos.forEach(r => {
                if (r.taller_id && r.cantidad > 0) {
                    agregar(r.taller_id, {
                        product_id: fila.product_id,
                        cantidad: r.cantidad,
                        prioridad: r.prioridad,
                    });
                }
            });
        }
    });

    return [...detallesPorTaller.entries()].map(([tallerId, detalles]) => ({
        taller_id: tallerId,
        fecha_entrega: configuracionTalleres[tallerId] ?? fechaPredeterminada,
        observacion: observacionGeneral || null,
        detalles,
    }));
}
```

**Por qué nunca puede haber dos bloques del mismo taller:** la
acumulación usa un `Map` **indexado por `taller_id`** desde el primer
detalle que se agrega — no es una deduplicación posterior sobre una
lista ya construida, es estructuralmente imposible generar dos entradas
para el mismo taller, sin importar si el detalle vino de una fila simple
o de un reparto de una fila dividida. Esto satisface, por construcción,
la regla congelada de 03A (`taller_id` único por operación, §7.11) antes
de que el payload siquiera llegue al backend — el backend lo sigue
validando de todos modos (defensa en profundidad ya establecida).

Verificación contra el ejemplo del pedido (L: 60→Taller1, 20→Taller4;
M: 40→Taller4): produce exactamente
`[{taller_id:1, detalles:[{product_id:L,cantidad:60,...}]}, {taller_id:4, detalles:[{product_id:L,cantidad:20,...},{product_id:M,cantidad:40,...}]}]`
— dos bloques en total, Taller 4 con sus dos detalles juntos en uno solo.

---

## 15. Cantidades 0

Confirmado y consistente en todo el diseño anterior: `cantidad === 0` (o
`entregar === 0`) significa **"no distribuir"**, nunca un error.
Explícitamente:

- No aparece en el payload (§14, los `if` de cantidad `> 0` lo excluyen).
- No cuenta para `talleresUtilizados` (§13).
- No se resalta en rojo ni se bloquea en la tarjeta principal.
- Dentro del drawer, una línea en 0 simplemente no se incluye al
  "Aplicar" (§3) — no impide guardar el resto.

---

## 16. Totales — una sola lógica compartida

Computeds a nivel de componente raíz, todos construidos sobre
`filaEntregarActual()` (§2.2) — nunca una fórmula alternativa en otro
lugar:

```js
totalApto            = Σ fila.apta_confeccion
totalYaDistribuido   = Σ fila.ya_distribuido                    // historico, sin cambios por esta sesión
totalDistribuirAhora = Σ filaEntregarActual(fila)                // NUEVO en 03C2 (03C1 no lo necesitaba sin division)
totalPendiente       = Σ max(fila.disponible_confeccion - filaEntregarActual(fila), 0)
```

`totalDistribuirAhora` es un total nuevo que 03C1 no exponía de forma
explícita (su `totalPendiente` ya restaba `entregar`, pero no mostraba
el "cuánto se va a distribuir" como cifra propia) — se agrega aquí
porque `CONFECCION_MASTER.md` §20 y el propio wireframe de resumen de
03B ya anticipan esa cifra como una línea independiente ("Total
Distribuir"), y el header es el lugar natural para que esté disponible
desde ya, reutilizando la misma función. Se marca como punto a confirmar
en la respuesta final por ser un agregado no pedido explícitamente en el
alcance original de 03C1.

---

## 17. Densidad — 20-40 variantes (registro del riesgo, sin cambiar el diseño)

**No se cambia el patrón de tarjetas ahora.** Se define la prueba
obligatoria futura y los criterios concretos que la resolverían:

**Prueba obligatoria (antes de congelar el frontend definitivo, en
03C2/03C3 al implementar):** abrir la pantalla con una orden de corte
real o de prueba con 20-40 variantes, en escritorio/laptop (1366×768 y
1920×1080), y medir/observar:

| Criterio | Umbral de referencia que justificaría un modo compacto |
|---|---|
| Alto total de página vs. viewport | Si superar ~4-5 "pantallas" de scroll para llegar al final (≈3000-4000px con tarjetas de ~150-200px cada una) |
| Tiempo para ubicar y editar una variante específica (no la primera) | Si toma notablemente más que un vistazo + un scroll (referencia subjetiva: >10-15s en la prueba manual) |
| Selección masiva de un rango amplio | Si seleccionar ~15 de 30 filas obliga a perder de vista la barra contextual o el checkbox "Seleccionar todas" durante el scroll |
| Comparación visual entre filas | Si comparar disponible/entregar entre dos variantes lejanas en la lista requiere memorizar valores en vez de verlos ambos a la vez |

**Si la prueba confirma el problema:** la alternativa a evaluar (no a
construir todavía) es un **modo compacto exclusivo de escritorio/laptop
ancho** (una fila por variante, más parecida a un grid denso),
**manteniendo tarjetas en mobile** (donde sí tienen sentido). Esto es
viable sin rediseñar el modelo de datos porque **todo el estado
definido en este documento es agnóstico de layout** — `modo`,
`repartos`, `tocadaManualmente`, `configuracionTalleres` y los
computeds de §16 no asumen ninguna estructura visual particular; cambiar
de tarjetas a un grid denso en escritorio sería un cambio de plantilla,
no de estado. Esto se señala como una ventaja de diseño explícita, no
una casualidad.

---

## 18. Casos límite obligatorios

| # | Caso | Comportamiento definido |
|---|---|---|
| 1 | Fila sin Taller + cantidad 0 | Estado inicial normal; no aparece en payload ni en talleresUtilizados; sin error visual. |
| 2 | Fila con Taller + cantidad 0 | Igual que el caso 1 — tener taller sin cantidad no la vuelve "usada" (§13, §15). |
| 3 | Cantidad mayor al disponible | `limitarEntregar()` (ya en 03C1) la clampa en vivo; en el drawer, la validación de suma bloquea "Aplicar" (§5). |
| 4 | Dividir sin consumir todo | Válido y esperado — "Pendiente" en el drawer muestra el resto; esas unidades simplemente no entran al payload. |
| 5 | Dividir consumiendo todo | Válido — "Pendiente" muestra 0 en el drawer. |
| 6 | Repetir mismo Taller en el drawer | Bloqueado por el `<select>` que excluye talleres ya usados en el borrador + validación de respaldo (§5). |
| 7 | Cambiar Taller predeterminado tras editar filas | Solo afecta filas `simple` con `tocadaManualmente === false` (§10) — una fila editada manualmente o dividida nunca se toca. |
| 8 | Acción masiva con filas divididas seleccionadas | Se excluyen del efecto; mensaje explícito de cuántas quedaron sin modificar (§9). |
| 9 | Taller inactivo desaparece mientras la pantalla está abierta | 03C2 no implementa detección en vivo (no hay mecanismo de refresco de datos en esta fase) — el backend lo rechazará en el submit real (03C3); se documenta como limitación conocida, no como bug de esta fase. |
| 10 | Cambiar fecha predeterminada con overrides existentes | Talleres sin entrada en `configuracionTalleres` adoptan el nuevo valor automáticamente (vía `fechaEfectiva()`, §11.1); los que tienen override no se tocan. |
| 11 | Borrar todos los repartos del drawer | Al "Aplicar" con `repartosValidos.length === 0`, equivale a "Quitar división" (§3) — la fila vuelve a `modo: 'simple'` limpia. |
| 12 | Cerrar drawer sin guardar | El borrador (`repartosDraft`) se descarta; `fila.repartos`/`fila.modo` quedan exactamente como estaban antes de abrir el drawer. |
| 13 | Stock `null` | Sin cambios respecto a 03C1: se muestra "No disponible", nunca bloquea la distribución (el stock es informativo, no una restricción, per `CONFECCION_MASTER.md` §11-13). |
| 14 | Stock `0` | Se muestra `0` explícitamente (nunca "No disponible") — sin cambios respecto a 03C1/04B. |
| 15 | Disponible `0` | Ver §19 — fila no seleccionable, sin acciones de distribución habilitadas. |
| 16 | Variante ya totalmente distribuida | Mismo tratamiento que disponible `0` (es la misma condición: `disponible_confeccion === 0`). |

---

## 19. UX de disponible = 0

Cuando `fila.disponible_confeccion === 0`:

- El input "Entregar ahora" permanece deshabilitado (ya así desde 03C1).
- El botón "Dividir entre talleres" se deshabilita (dividir 0 entre
  talleres no tiene sentido).
- La fila **no** entra en `filasSeleccionables` (§7) — su checkbox
  aparece deshabilitado, y "Seleccionar todas" la ignora.
- La tarjeta se sigue mostrando completa (metadata, stock, historial de
  recibido/defectuosa/apta/ya distribuido) — es información de
  trazabilidad válida, no se oculta.

---

## 20. Criterios de aceptación

1. Dividir una variante con una asignación simple previa conserva
   exactamente esa cantidad/taller/prioridad como primera línea del
   drawer.
2. "Quitar división" nunca conserva cantidades parciales sin
   confirmación explícita del usuario.
3. El drawer nunca permite "Aplicar" con: taller duplicado, una cantidad
   `> 0` sin taller, o una suma que exceda el disponible.
4. Ninguna acción masiva modifica jamás `repartos[]` de una fila
   dividida; toda ejecución que excluya divididas lo informa
   explícitamente.
5. Cambiar el Taller predeterminado nunca sobreescribe una fila con
   `tocadaManualmente === true` ni una fila `dividido`.
6. El payload construido (§14) nunca contiene dos bloques con el mismo
   `taller_id`, sin importar cuántas filas simples o repartos apunten a
   ese taller.
7. Ninguna fila/reparto con cantidad `0` aparece en el payload ni cuenta
   para `talleresUtilizados`.
8. Cambiar la fecha predeterminada actualiza automáticamente todo taller
   sin override y nunca toca uno con fecha personalizada.
9. Una variante con `disponible_confeccion === 0` nunca es seleccionable
   ni ofrece acciones de distribución activas.

---

## 21. Tests previstos

**Backend:** ninguno nuevo — 03C2 no modifica Controller/Service/Request
(el payload que construye ya es compatible con el
`DistribuirConfeccionRequest` congelado desde 03A). Los tests backend de
03A/04B/03C1 siguen siendo la cobertura vigente, sin cambios.

**Frontend (para cuando se implemente, no en esta fase de diseño):** el
proyecto no tiene infraestructura de test de componentes Vue (mismo
límite ya declarado en 03C1 §9.1). Estrategia recomendada, replicando lo
que sí funcionó en 03C1:

- Extraer como funciones JS puras (fuera del objeto de opciones de Vue,
  o expuestas de forma verificable) al menos: `filaEntregarActual()`,
  `talleresUtilizados`, `fechaEfectiva()` y `construirAsignaciones()` —
  así pueden probarse de forma aislada (por ejemplo, invocándolas vía
  `page.evaluate()` en un script de `browser-automation`, sin depender
  de un runner de tests de componentes).
- Validación visual real (navegador headless, mismo patrón que 03C1
  §9.2): dividir una variante, aplicar acciones masivas con una fila
  dividida en la selección, cambiar Taller predeterminado después de
  editar filas, y verificar el DOM/estado resultante — no simulada, con
  evidencia real antes de dar la implementación por completada.

---

## 22. Riesgos

- **Complejidad de estado creciente:** `modo`, `repartos`,
  `tocadaManualmente` y `configuracionTalleres` amplían
  considerablemente la superficie de estado del componente respecto a
  03C1. Mitigado parcialmente por diseñar los cálculos clave como
  funciones puras reutilizables (§21), pero sigue siendo más estado que
  gestionar manualmente sin un test harness de componentes.
- **"Prioridad mixta" podría no distinguirse visualmente lo suficiente**
  de un valor real si no se le da un tratamiento propio al implementar —
  pendiente de pulido visual en 03C3.
- **Densidad (§17)** sigue siendo un riesgo abierto y explícitamente no
  resuelto en esta fase — la prueba de 20-40 variantes queda como
  bloqueante antes de considerar el frontend definitivo.
- **Selección visual de filas divididas (§7):** la decisión de permitir
  seleccionarlas y solo excluirlas al ejecutar la acción es una
  preferencia razonada, no una certeza — si en el uso real genera
  confusión, la alternativa (deshabilitar su checkbox directamente) es
  un cambio menor y localizado.
- Todo lo ya conocido de fases anteriores (concurrencia real no
  probada, `product_inventory_indices` como valor indexado no en vivo,
  ausencia de "stock bajo") sigue vigente sin cambios — ver
  `03A_MOTOR_DISTRIBUCION.md` §7.8 y `04B_STOCK_VARIANTE_IMPLEMENTACION.md` §11.

---

## 23. Implementación real (2026-09-05)

### 23.1 Ajustes finales de aprobación, tal como quedaron implementados

1. **`tallerTocadoManualmente`** (nombre final, en vez del genérico
   `tocadaManualmente` de §2 y siguientes): arranca en `false`; solo pasa
   a `true` cuando el usuario cambia el `<select>` de Taller de una fila
   `modo==='simple'` (evento `@change`). Cambiar cantidad o prioridad NO
   la activa. "Taller predeterminado" (`aplicarTallerPredeterminado()`)
   filtra `modo==='simple' && !tallerTocadoManualmente`, nunca toca filas
   divididas. "Limpiar asignación" (`limpiarSeleccionadas()`) y "Quitar
   división" (`quitarDivision()`) resetean siempre a `entregar=0,
   prioridad='normal', taller_id=tallerPredeterminado ?? null,
   tallerTocadoManualmente=false`.
2. **Checkbox de filas divididas deshabilitado desde el render inicial**:
   `:disabled="fila.modo === 'dividido' || fila.disponible_confeccion <= 0"`
   en el `<input type="checkbox">` de cada tarjeta, con badge "Dividida"
   siempre visible junto al nombre cuando `modo==='dividido'`. El
   computed `filasSeleccionables` (usado por "Seleccionar todas") filtra
   `modo==='simple' && disponible_confeccion>0` — una fila dividida nunca
   puede marcarse, ni individualmente ni por "Seleccionar todas". No
   existe ningún mensaje de "N divididas ignoradas" en el código: es
   innecesario, porque estructuralmente nunca pueden llegar seleccionadas
   a una acción masiva.
3. **Confirmación condicional**: `asignarTallerMasivo()`,
   `asignarPrioridadMasiva()` y `asignarDisponibleMasivo()` mutan el
   estado local directamente, sin diálogo. `limpiarSeleccionadas()`
   calcula `conCantidad = objetivo.filter(f => Number(f.entregar||0) > 0)`
   y solo emite `open-confirm-modal` si `conCantidad.length > 0`; si
   todas las filas seleccionadas ya tienen `entregar===0`, ejecuta el
   reset directo sin modal. `solicitarQuitarDivision(fila)` siempre
   confirma si `fila.repartos.length > 0` (verificado con evidencia real
   en §23.3) — es coherente con el diseño original, que nunca condicionó
   esta confirmación a las cantidades sino a la existencia de la
   división en sí.

### 23.2 Archivos modificados

- `packages/ByLopez/CorteConfeccion/src/Resources/views/admin/ordenes-confeccion/create.blade.php`
  — reescrito por completo (Blade + componente Vue
  `v-distribuir-confeccion`, `data()`/`computed`/`methods` con todo lo
  descrito en §2–§21 de este documento).
- `packages/ByLopez/CorteConfeccion/docs/confeccion/03C2_INTERACCION_DISTRIBUCION.md`
  (este documento) y `STATUS.md` — actualizados.
- Ningún Controller, Service, Request, ruta, migración, ni archivo bajo
  `packages/Webkul/` fue creado o modificado.
- Build de Tailwind del paquete (`npm run build` en
  `packages/ByLopez/CorteConfeccion`) regenerado para incluir las nuevas
  clases usadas (badges, grid del drawer, estados disabled, etc.).

### 23.3 Validación real con navegador (browser-automation, login real)

Se creó una orden de corte temporal real (con recepción de 3 variantes,
disponibles 100/80/70) y dos Talleres temporales, todo eliminado al
finalizar. Se ejecutaron scripts Playwright reales (no simulados) contra
`php artisan serve` en un puerto temporal, con snapshots/lecturas del DOM
después de cada interacción. Resultados verificados con evidencia real:

- **AJUSTE 1:** al escribir cantidad y elegir Taller manualmente en una
  fila simple, y luego cambiar "Taller predeterminado" a otro Taller, la
  fila tocada manualmente **conserva su Taller** (`inputValue()` antes y
  después idéntico); las filas no tocadas sí siguen al predeterminado.
- **Acciones masivas:** seleccionar 2 filas y "Asignar disponible" pone
  `entregar = disponible_confeccion` en ambas solo si ya tienen Taller
  (si no, aparece el aviso "Selecciona un Taller predeterminado o asigna
  Taller antes de distribuir", sin bloquear la acción).
- **Drawer / dividir:** abrir el drawer sobre una fila, agregar un
  segundo Taller con cantidad y prioridad "alta", aplicar → la fila pasa
  a `modo==='dividido'`, muestra el badge "Dividida", el checkbox queda
  deshabilitado (`isDisabled()===true`), la columna Taller muestra
  "N talleres", y la prioridad se muestra como "Prioridad mixta"
  (confirmado por accessibility snapshot real, no supuesto).
- **Totales de header:** con `entregar=60` en una fila simple y una
  fila dividida con repartos 30+20, el header mostró
  `Distribuir ahora: 80` y `Pendiente: 170` — coincide exactamente con
  la suma esperada (`disponible total 250 - 80`).
- **Fechas por Taller:** al dividir entre dos talleres, la sección
  "Fechas por taller" mostró ambos talleres con la fecha predeterminada,
  cada uno con su propio botón "Personalizar" — confirmado por
  accessibility snapshot.
- **Payload canónico (Map por `taller_id`) — el caso más crítico:** con
  una fila simple (`product_id=2445`, Taller A, cantidad 60) y una fila
  dividida (`product_id=2447`, repartos Taller A cantidad 30 + Taller B
  cantidad 20 alta), el payload generado (`construirAsignaciones()`,
  leído del elemento oculto `#distribuir-payload-preview`) fue:
  ```json
  [
    { "taller_id": 483, "detalles": [
        { "product_id": 2445, "cantidad": 60, "prioridad": "normal" },
        { "product_id": 2447, "cantidad": 30, "prioridad": "normal" }
    ] },
    { "taller_id": 484, "detalles": [
        { "product_id": 2447, "cantidad": 20, "prioridad": "alta" }
    ] }
  ]
  ```
  Un único bloque para el Taller 483 con **ambos** `product_id`
  (2445 de la fila simple y 2447 del reparto de la fila dividida) — la
  garantía estructural del `Map` funciona exactamente como se diseñó:
  nunca dos bloques del mismo taller, sin importar si el origen es una
  fila simple o un reparto.
- **"Seleccionar todas":** con una fila dividida presente, marcar
  "Seleccionar todas" dejó marcadas únicamente las filas simples con
  disponible > 0; la fila dividida permaneció sin marcar (estructuralmente
  imposible de marcar, por §23.1.2).
- **"Limpiar asignación" con confirmación (AJUSTE 3):** con una fila
  seleccionada en `entregar=60`, el botón mostró el modal de
  confirmación (`confirmLimpiar1Visible=true`); al aceptar, la fila
  volvió a `entregar=0`, `taller_id=null`.
- **"Limpiar asignación" sin confirmación (AJUSTE 3):** repitiendo la
  acción con todas las filas seleccionadas ya en `entregar=0`, el modal
  **no apareció** (`confirmLimpiar2Visible=false`) — se limpió
  directamente.
- **"Quitar división" con confirmación:** sobre una fila dividida con 2
  repartos, el modal mostró exactamente el texto real de la aplicación
  ("Quitar division" / "Se eliminaran las asignaciones a los talleres
  configurados para esta variante. ¿Confirmas?" / botones "Cancelar" y
  "Aceptar"); al aceptar, la fila volvió a `modo==='simple'`,
  `entregar=0`, `taller_id=null` (el predeterminado vigente), checkbox
  habilitado de nuevo, badge "Dividida" ya no visible.
- **0 errores de consola** en ninguna de las ejecuciones.

Nota metodológica: la primera ronda de scripts de validación falló
repetidamente por un **error del propio script de prueba** (buscaba el
texto "Quitar división" con tilde, pero el texto real de la aplicación
es "Quitar division" sin tilde), lo que dejaba el modal de confirmación
sin resolver y bloqueaba la siguiente lectura del DOM — no era un bug de
la aplicación. Se diagnosticó comparando snapshots reales del DOM contra
lo que el script asumía, y se corrigió el script, no la aplicación.

### 23.4 Tests backend

03C2 no modifica ningún Controller/Service/Request — no hay superficie
backend nueva que cubrir con Pest. Se re-ejecutó la suite completa del
paquete como regresión:
- `vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"`
  → **100 passed (280 assertions)**, sin regresiones.
- `vendor/bin/pint --test packages/ByLopez/CorteConfeccion` → **passed**.

La validación de la lógica de interacción (cálculos derivados, mutación
de estado, construcción del payload) se hizo exclusivamente con
navegador real (§23.3), tal como se indicó para esta fase (sin construir
un harness de tests de componentes Vue).

### 23.5 Prueba de densidad real (20-40 variantes) — hallazgo real, sin corregir

Fixture temporal real: 30 variantes (`product_id` reales de 9
configurables distintos del catálogo sembrado, 20 unidades cada uno),
eliminado al finalizar la prueba. Navegador real, anchos probados:
1280px, 1366px, 800px y 390px.

**Sin problemas:**
- Sin overflow horizontal en ningún ancho (1280/800/390 verificado por
  `scrollWidth > clientWidth`).
- Tarjetas legibles en los 4 anchos; en 390px la barra de acciones
  masivas envuelve correctamente en 2 líneas sin romperse.
- El drawer "Dividir entre talleres" abierto sobre una fila intermedia
  (fila 16 de 30) funcionó sin ningún problema de layout ni de cálculo.

**Problema real encontrado:** el header de totales y la barra de
acciones masivas usan `position: static` (confirmado con
`getComputedStyle`). La página mide **~12 700px** de alto con 30
variantes (390px de ancho). Se verificó con `getBoundingClientRect` que
tras un scroll de 2000px (menos de 1/6 del alto total), la barra de
acciones masivas pasa de `y=330` a `y=-1670` — queda completamente fuera
de la pantalla. Un usuario que seleccione filas dispersas y baje a
revisar el resto de la lista **pierde el acceso** al conteo de
seleccionadas y a los botones de acción masiva (y a los totales del
header) sin volver a subir manualmente. No es un problema de legibilidad
ni de overflow — es un problema de alcance/usabilidad con volumen real.

**No se corrigió.** Tal como se instruyó explícitamente, se documenta el
hallazgo con evidencia y se proponen 3 opciones, sin implementar ninguna:

- **A) Mantener tarjetas tal cual.** Aceptar la limitación. El flujo
  "seleccionar y aplicar la acción masiva antes de bajar a revisar el
  resto" ya es posible hoy sin cambios de código, pero no es intuitivo
  ni está comunicado en la interfaz.
- **B) Modo compacto de escritorio.** Un layout más denso tipo
  tabla/grid en `>=1024px` (conservando las tarjetas en móvil), que
  reduzca el alto total de la página y con ello el problema de alcance.
  Es un cambio de mayor alcance — afecta directamente el patrón visual
  ya congelado en 03C1/03B.
- **C) Ajuste mínimo dirigido.** Volver `sticky`/`fixed` únicamente el
  header de totales y la barra de acciones masivas, sin tocar las
  tarjetas ni el resto del layout. Cambio de CSS acotado y de bajo
  riesgo, pero no reduce la longitud total de la página ni mejora la
  "velocidad para recorrer variantes" mencionada como criterio en §17.

Esta decisión queda pendiente del usuario antes de considerar 03C2
totalmente cerrada, y antes de iniciar 03C3 (que incluye "pulido
responsive").

### 23.6 Diferencias respecto al diseño original (§1–§22)

- El nombre de campo final es `tallerTocadoManualmente`, no
  `tocadaManualmente` como aparece en el resto del documento (§2 y
  siguientes) — mismo comportamiento, nombre más específico acordado en
  la aprobación final.
- §7 planteaba como alternativa razonada "permitir seleccionar
  visualmente filas divididas y excluirlas solo al ejecutar la acción".
  La aprobación final optó explícitamente por la alternativa contraria:
  deshabilitar el checkbox desde el inicio (§23.1.2) — ya no es una
  alternativa abierta, es la decisión final implementada.
- El resto de la implementación (modelo de estado, drawer, fechas por
  Taller, payload canónico, totales) coincide con lo diseñado en §2–§21
  sin desviaciones adicionales.

### 23.7 Riesgos/decisiones que requieren revisión humana (superado por §24)

1. ~~**Hallazgo de densidad (§23.5)** — requiere elegir A/B/C antes de
   cerrar la fase.~~ **Resuelto**: el usuario eligió la opción **B**
   (layout compacto de escritorio/laptop) más un ajuste de la barra de
   acciones masivas. Implementado en §24.
2. Todos los riesgos ya listados en §22 siguen vigentes sin cambios,
   excepto el de densidad (ver §24) y el de "selección visual de filas
   divididas" (ya no es una alternativa abierta, ver §23.6).

---

## 24. Ajuste de densidad (2026-09-05) — opción B implementada

Decisión del usuario tras revisar §23.5: **B) modo compacto de
escritorio/laptop** (CSS Grid/Flex, sin tabla rígida), conservando
tarjetas en móvil, más sticky/fixed dirigido **únicamente** a la barra
de acciones masivas (no al header de totales). Cambio exclusivamente de
presentación — ninguna de las siguientes piezas de lógica se tocó:
`modo` simple/dividido, `repartos[]`, `repartosDraft`,
`tallerTocadoManualmente`, `configuracionTalleres`, fechas, observación,
el payload `Map` por taller, las acciones masivas, el stock, ni las
prioridades.

### 24.1 Breakpoint elegido

Se usó el breakpoint `lg` de Tailwind (**1024px**) como corte entre
"compacto" (`>=1024px`) y "tarjetas" (`<1024px`). Se eligió `lg` en vez
de `md` (768px) tras medir el ancho de contenido real disponible bajo el
sidebar de Bagisto (`.bylopez-scope` deja **978px** de ancho de
contenido a 1280px, pero solo **722px** a 1024px) — con 7 columnas y una
celda de producto multilinea, 768px habría forzado texto ilegible o
overflow. Por eso "laptop/intermedio" en la validación se probó
concretamente en **1024px**: es el punto exacto donde el layout compacto
empieza a aplicarse.

### 24.2 Layout desktop/laptop (`>=1024px`)

Por variante, una fila de **CSS Grid** de 7 columnas (clase
`lg:grid-cols-[20px_minmax(0,1fr)_64px_150px_110px_88px_68px]`), con una
fila de encabezado (mostrada una sola vez, no repetida por variante) con
las mismas 7 columnas:

1. Checkbox (misma condición de deshabilitado que en móvil).
2. Producto: nombre (con badge "Dividida" inline si aplica) + segunda
   línea compacta con color · talla/grupo de edad · **SKU secundario**.
3. Stock: actual (negrita) + **proyectado secundario** debajo, en gris
   pequeño (mismo cálculo `stock_actual + filaEntregarActual(fila)`,
   nunca antes calculado dos veces).
4. Disponible → Entregar → Pendiente en una sola celda compacta con
   flechas (`20 → [input] → 20`); en fila dividida el valor entregado se
   muestra como texto (no input), igual que en móvil.
5. Taller: mismo `<select>` en fila simple; en fila dividida, **"N
   talleres · total asignado"** en una sola línea (antes eran dos datos
   separados en móvil — aquí se combinan por espacio, sin perder
   información).
6. Prioridad: mismo `<select>` en fila simple; "Mixta" (antes "Prioridad
   mixta", acortado por espacio) en fila dividida.
7. Acciones: "Dividir"/"Editar" y "Quitar" (antes "Dividir entre
   talleres"/"Editar división"/"Quitar división" — mismo `@click`,
   mismas condiciones `:disabled`/`v-if`, solo texto abreviado por
   espacio).

El contenedor exterior pasa de tarjetas individuales con borde y
`space-y-3` a un único bloque con `divide-y` a partir de `lg:`
(la versión `<1024px` no se modificó: sigue siendo `space-y-3` con cada
tarjeta con su propio `rounded-lg border p-4`).

### 24.3 Layout móvil (`<1024px`)

**Sin cambios.** La tarjeta existente (aprobada en 03C1/03C2) se dejó
exactamente igual, envuelta ahora en un contenedor `lg:hidden` para que
solo se oculte a partir de 1024px. Se verificó con captura de pantalla a
390px que el resultado es **pixel-identico** al de antes del ajuste.

### 24.4 Barra de acciones masivas: de `sticky` a `fixed`

Primer intento: `position: sticky` con `top-[61px]` (61px = altura real
medida del header fijo de Bagisto, `<header class="sticky top-0 z-...">`,
para no quedar tapada por él). **No funcionó**: se verificó con
`getBoundingClientRect()` que la barra se iba de pantalla igual que
antes (de `y=330` a `y=-475` tras 900px de scroll).

Causa raíz encontrada (inspección real del DOM, no supuesta): un
`<div class="w-full overflow-x-hidden">` del layout **core de Bagisto**
(`packages/Webkul/Admin`, no tocado) tiene `overflow: hidden auto`
computado, dos niveles arriba de `.bylopez-scope`. Cualquier ancestro
con `overflow` distinto de `visible` rompe `position: sticky` de sus
descendientes cuando el scroll real ocurre en la ventana y no dentro de
ese contenedor — un comportamiento estándar del modelo de cajas de CSS,
no un bug de Bagisto ni algo corregible sin tocar ese `<div>` (prohibido
por alcance).

**Solución aplicada:** `position: fixed` en vez de `sticky`, sin
`top`/`left` explícitos — un elemento `fixed` sin offsets conserva su
posición estática de layout (calculada una vez, incluye ya el ancho del
sidebar) pero deja de moverse con el scroll, sin depender de ningún
ancestro. Verificado con `getBoundingClientRect()`: `top`, `left` y
`width` **idénticos** antes y después de un scroll de 900px
(`top:330, left:286, width:705` en ambos casos). Confirmado también
visualmente por captura: la barra queda flotando sobre la lista,
legible, sin invadir el sidebar ni desbordar a la derecha, y sin
convertirse en una franja fija de gran altura — solo la barra en sí
(~50px), no el header de totales (que se mantiene en flujo normal,
exactamente como antes, cumpliendo "no hacer sticky múltiples
cabeceras").

### 24.5 Validación real — antes/después (30 variantes, mismo fixture temporal descartable)

| Ancho | Altura ANTES | Altura DESPUÉS | Reducción |
|---|---|---|---|
| 1280px (desktop) | 6.711 px | 1.861 px | **~72,3%** |
| 1024px (laptop/intermedio) | 6.760 px | 1.910 px | **~71,7%** |
| 500px (móvil) | 12.537 px | 12.537 px | 0% (esperado: tarjetas sin cambios) |
| 390px (móvil) | 12.578 px | 12.578 px | 0% (esperado: tarjetas sin cambios) |

**Aclaración importante sobre el número "~12.700px" citado como
problema:** ese valor (documentado en §23.5) se midió realmente a
**390px de ancho** (móvil), no en desktop. La altura real en desktop
ANTES del ajuste era ~6.700px, no ~12.700px — la cifra de 390px se
mantiene igual después porque el ajuste de densidad es exclusivamente
para escritorio/laptop; el layout de tarjetas en móvil se conserva tal
cual, por decisión explícita del usuario.

**Cero overflow horizontal** verificado en los 4 anchos (1280/1024/500/390)
antes y después.

**Interacción validada con navegador real tras el ajuste (30 variantes):**
- Fila simple: cantidad + taller establecidos correctamente en el layout
  compacto.
- "Seleccionar todas" + barra de acciones masivas: aparece "30
  seleccionadas", `position: fixed` confirmado, `top/left/width`
  idénticos antes y después de 900px de scroll (barra siempre visible).
- Drawer "Dividir entre talleres" abierto sobre la variante 16 de 30 (a
  mitad de lista): añadir un segundo taller (10 + 5 unidades, prioridad
  alta en el segundo) y aplicar. Resultado en la fila compacta: badge
  "Dividida" visible, checkbox deshabilitado, celda de Taller
  `"2 talleres · 15"` (total correcto), Prioridad `"Mixta"` — todo
  confirmado leyendo el DOM real, no supuesto.
- **0 errores de consola** en todas las ejecuciones (carga inicial,
  interacción, scroll, drawer).
- Disponible = 0: la condición `:disabled="fila.disponible_confeccion <=
  0"` en checkbox/input/botón "Dividir" del layout compacto es
  **exactamente la misma expresión** ya validada en la tarjeta móvil en
  03C2 (§23.3) — no se reimplementó ni se derivó de nuevo, se reutilizó
  literalmente sobre el mismo objeto `fila`, por lo que el comportamiento
  es idéntico por construcción. No se armó un fixture adicional solo
  para volver a fotografiar el mismo camino de código ya probado.

### 24.6 Archivos modificados en este ajuste

- `packages/ByLopez/CorteConfeccion/src/Resources/views/admin/ordenes-confeccion/create.blade.php`
  — layout compacto `lg:` añadido, tarjeta móvil sin cambios envuelta en
  `lg:hidden`, barra de acciones masivas cambiada de posición normal a
  `fixed`. Ningún `data()`/`computed`/`methods` del componente Vue fue
  modificado.
- Build de Tailwind del paquete regenerado (`npm run build`) para incluir
  las nuevas clases (`lg:grid-cols-[...]`, `fixed`, etc.).
- Este documento y `STATUS.md`.
- Ningún Controller/Service/Request/ruta/migración/`packages/Webkul/`
  tocado.

### 24.7 Riesgos/decisiones pendientes tras el ajuste

- El breakpoint `lg` (1024px) es una decisión de implementación, no una
  cifra pedida explícitamente por el usuario — queda documentada aquí
  para que, si en el uso real alguien abre la pantalla en un ancho
  intermedio (ej. 900px, un "netbook" o ventana no maximizada) y
  encuentra que preferiría el modo compacto ahí también, se sepa
  exactamente qué valor mover.
- Los textos abreviados en el layout compacto ("Dividir"/"Editar"/
  "Quitar" en vez de las frases completas de móvil, "Mixta" en vez de
  "Prioridad mixta") son una decisión de densidad, no revisada con el
  usuario campo por campo — si alguno resulta ambiguo en el uso real,
  es un ajuste de texto menor y localizado.
- El resto de riesgos de §22/§23.7 (complejidad de estado, concurrencia
  no probada en 03A, ausencia de "stock bajo") siguen vigentes sin
  cambios.

---

## 25. Validación final acotada (2026-09-06) — breakpoint bajado a `md` (768px), **sin confirmación final de navegador**

### 25.1 Motivo

Antes de aprobar 03C2, se pidió verificar si el breakpoint `lg` (1024px)
de la §24 dejaba un "hueco UX" en anchos de laptop/intermedio
(992/900/768px), ya que por debajo de 1024px la pantalla vuelve a
tarjetas.

### 25.2 Evidencia real recolectada (navegador real, 30 variantes, mismo tipo de fixture temporal que en §24, ya eliminado)

Matriz completa medida con el breakpoint `lg` (1024px) todavía vigente:

| Ancho | Layout mostrado | Altura total | Overflow horizontal | Filas ~visibles/viewport |
|---|---|---|---|---|
| 1280px | compacto | 1.861px | no | ~17 |
| 1024px | compacto | 1.910px | no | ~17 |
| 992px | **tarjeta** | **6.735px** | no | ~4 |
| 900px | **tarjeta** | **6.735px** | no | ~4 |
| 768px | **tarjeta** | **6.752px** | no | ~4 |
| 500px | tarjeta | 12.537px | no | ~2 |
| 390px | tarjeta | 12.578px | no | ~2 |

**Confirmado: sí existe el hueco.** Entre 768 y 1023px la pantalla vuelve
a medir ~6.700px (casi igual de larga que el problema original resuelto
en §24 para 1280/1024px), con solo ~4 filas visibles por pantalla en vez
de ~17.

**Hallazgo adicional que justifica la corrección:** capturas de pantalla
reales a 992px y 768px muestran que el sidebar de Bagisto **ya colapsa a
modo ícono** (sin texto, ~64px) en ese rango — visualmente confirmado,
no solo asumido. Esto significa que el ancho de contenido disponible en
768-991px es en realidad **mayor** que a 1024px con el sidebar expandido
(que ya se probó exitosamente con el layout compacto en §24: 722px de
contenido). Por lo tanto, no hay una razón de ancho para mantener
tarjetas en ese rango — al contrario, el layout compacto debería caber
con más holgura ahí que a 1024px.

### 25.3 Cambio aplicado

Se bajó el breakpoint de la fila compacta, el encabezado de columnas, el
contenedor `divide-y` y el reseteo de la tarjeta móvil de `lg` (1024px)
a **`md` (768px)** — 5 clases Tailwind cambiadas en
`create.blade.php`, ningún otro cambio. CSS del paquete reconstruido
(`npm run build`). **Cero cambios de lógica Vue, Controller, Service,
Request, ruta, migración o `packages/Webkul/`.**

### 25.4 Bloqueo real de entorno — sin confirmación de navegador para el nuevo breakpoint

Justo después de aplicar el cambio, el entorno de esta máquina quedó sin
memoria suficiente para ejecutar un navegador real: **Chrome se cerraba
con `Segmentation fault` (exit code 139)**, confirmado lanzando el
binario directamente (`chrome.exe --headless ... about:blank`), sin
Playwright de por medio — es decir, no es un problema del script de
prueba ni de la aplicación, es el sistema operativo sin RAM disponible
(~2.1-2.4GB libres de 8GB totales, sin mejorar tras ~19 minutos de
espera y un intento del usuario de liberar memoria).

Se consultó al usuario con dos rondas de espera real (no simulada) y se
decidió: **conservar el cambio de breakpoint a `md` (768px)**, respaldado
por la evidencia indirecta de la §25.2 (matriz completa + colapso de
sidebar confirmado visualmente), **pero sin poder confirmar con
navegador real que el layout compacto efectivamente renderiza sin
overflow ni truncamientos ambiguos en el rango 768-1023px con el
breakpoint ya bajado**. Esto es una diferencia real y declarada respecto
al resto de esta fase: en §23 y §24 cada afirmación de "sin overflow"
viene de una medición real después del cambio; aquí, para 768-1023px
específicamente, la afirmación de que "cabe bien" se apoya en evidencia
indirecta (ancho de contenido medido en un escenario comparable + el
mismo template de grid ya validado a 1024px), no en una captura o
medición tomada después de bajar el breakpoint.

### 25.5 Qué sigue validado sin cambios

Todo lo de §23 y §24 (interacción, payload, sticky→fixed, reducción de
altura en 1280/1024px) sigue siendo válido tal cual — nada de eso se
tocó ni se puso en duda. El único punto sin re-confirmar es
específicamente el renderizado visual del layout compacto en el rango
768-1023px tras el cambio de breakpoint.

### 25.6 Tests y suites ejecutados en esta ronda

- `vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"` → **104 passed (296 assertions)**, sin regresiones (esperado: cambio puramente CSS).
- `vendor/bin/pint --test packages/ByLopez/CorteConfeccion` → **passed**.
- Navegador real: **no se pudo ejecutar** para el nuevo breakpoint (ver §25.4). Sí se ejecutó exitosamente antes del bloqueo, produciendo la matriz de §25.2 (con el breakpoint todavío en `lg`).

### 25.7 Resultado (en su momento): 03C2 sigue **sin aprobarse**

Por decisión explícita del usuario, **03C2_INTERACCION_DISTRIBUCION NO
se marca como APROBADA en esta ronda** — queda en
**COMPLETADA / PENDIENTE DE VALIDACIÓN FINAL**, exactamente por la razón
declarada en §25.4: falta confirmar con navegador real que el layout
compacto en 768-1023px no tiene overflow ni truncamientos ambiguos.
Cuando el entorno lo permita, la validación pendiente es puntual: repetir
la matriz de anchos de §25.2 con el breakpoint ya en `md`, prestando
atención especial a 768/900/992px.

---

## 26. Cierre visual pendiente — confirmado (2026-09-08) → **03C2 APROBADA**

Con el entorno ya utilizable de nuevo, se ejecutó la validación puntual
que quedó pendiente en §25: breakpoint `md` (768px) en **900px y
768px**, contra dos órdenes reales existentes (sin crear fixtures
nuevos, per instrucción explícita de no rehacer fixtures masivos si no
son necesarios):

- `OC-WIL-000004` (id 311) — usada para confirmar overflow/layout con
  datos reales ya distribuidos.
- `OC-WIL-000005` (id 1624) — la misma orden de la captura de pantalla
  que motivó la Fase 03C3; tenía una variante con disponible real (144),
  usada para probar interacción.

**Resultado, ambos anchos:**

| Ancho | Layout | Overflow horizontal |
|---|---|---|
| 900px | compacto | no |
| 768px | compacto | no |

Interacción probada en la fila con disponible > 0 a 900px:
- **Cantidad**: editable, valor aplicado correctamente (20).
- **Taller**: `<select>` con opciones reales pobladas.
- **Prioridad**: cambiada a "alta" y aplicada correctamente.
- **Acción "Dividir"**: abre el drawer sin errores (`text=Dividir ·`
  visible).
- **Barra masiva**: "Seleccionar todas" activa correctamente la barra de
  acciones masivas.
- **Consola**: 0 errores en toda la sesión de prueba.

Captura visual a 768px confirma legibilidad: nombre de producto
truncado con "…" de forma controlada, las 6 columnas (Producto, Stock,
Disp.→Entregar→Pend., Taller, Prioridad, Acciones) totalmente visibles y
alineadas, sin overflow.

### 26.1 Resultado final

**03C2_INTERACCION_DISTRIBUCION = APROBADA** (2026-09-08). El único punto
que quedaba pendiente (confirmación de navegador real del breakpoint
`md` en 768-1023px, §25.4) queda cerrado con evidencia real. No se
encontró ningún problema nuevo. No se modificó código en esta validación
— solo se leyeron datos reales existentes y se limpiaron los fixtures
mínimos de sesión (1 admin/rol de login temporal, ya eliminados).
