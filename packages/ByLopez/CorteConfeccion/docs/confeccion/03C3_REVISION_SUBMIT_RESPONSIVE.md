# 03C3_REVISION_SUBMIT_RESPONSIVE.md — Revisión, submit y pulido responsive final

## 0. Estado de este documento

**APROBADA.** Fecha de diseño: 2026-09-08. Fecha de implementación:
2026-09-08. Fecha de validación final: 2026-09-08. `packages/Webkul/`
sin cambios en ninguna etapa. `OrdenConfeccionService::distribuir()`
**no se tocó** — sigue exactamente igual que en 03A.
`DistribuirConfeccionRequest` tampoco se tocó. El único cambio de
backend es el aditivo documentado en §7.4/§19 (rama `wantsJson()` en
`OrdenConfeccionController::distribuir()`) más el nuevo endpoint
`contexto()` (§9 del encargo de ejecución, ver §24.2).

Los 2 gaps que quedaban pendientes tras la implementación (fecha
override sin confirmación visual, camino red/500 sin prueba de
navegador real) se cerraron en una validación final acotada
(2026-09-08): ambos confirmados correctos con evidencia real, sin
encontrar defectos — ver §25 "Validación final acotada" al final de
este documento para el reporte completo.

El diseño original (§1-§23) y el reporte de implementación (§24) se
mantienen íntegros como registro histórico; §25 documenta la validación
final que cierra la fase.

03A, 03B, 03C1 y 03C2 se leyeron como contexto aprobado y **no se
auditan de nuevo aquí**. Donde 03B ya dejó una decisión tomada (botón
"Generar N órdenes", tabla de errores por causa, sketch de "disponibilidad
cambió"), este documento la **hereda y elabora técnicamente**, no la
reabre. La única corrección respecto a 03B: su boceto de responsive de 3
niveles (§16 de 03B) quedó **superado por la implementación real** de
03C2 (compacto CSS Grid desde `md`=768px, tarjetas debajo) — 03C3 sigue
el patrón **ya implementado y aprobado**, no el boceto original (ver
§20).

### 0.1 Alcance general previo (histórico, conservado)

> Cerrar el flujo de distribución: estado REVISAR (resumen agrupado por
> Taller), confirmación semántica ("Generar N órdenes de confección"),
> envío real al endpoint `admin.corte_confeccion.ordenes_confeccion.distribuir`,
> manejo de errores del backend (incluida la disponibilidad-cambió por
> concurrencia), y pulido final del responsive ya sentado en 03C1/03C2.

---

## 1. Estado de pantalla: patrón EDITAR/REVISAR

Un único dato reactivo nuevo en el componente Vue ya existente
(`v-distribuir-confeccion`, `create.blade.php`):

```js
data() {
    return {
        // ...todo lo ya existente de 03C2, sin tocar...
        vista: 'editar', // 'editar' | 'revisar'
        enviando: false,
        errorEnvio: null,       // { tipo, mensaje, detalle }
        ordenesCreadas: null,   // resultado de exito, ver §12
    };
},
```

- `vista` controla, con `v-if`/`v-else` en el **mismo componente y el
  mismo `<div>` raíz**, cuál bloque de template se muestra — **sin
  router, sin nueva URL, sin recargar la página**, tal como pide el
  encargo.
- Todo el estado de 03C2 (`filasState`, `tallerPredeterminado`,
  `fechaPredeterminada`, `configuracionTalleres`, `observacionGeneral`,
  `gruposAbiertosSet`-equivalente si aplica, etc.) **permanece intacto en
  el mismo componente** al cambiar `vista` — no hay desmontaje/remontaje
  de `v-distribuir-confeccion`, por lo tanto no hay pérdida de estado por
  diseño. "Volver y corregir" (§17) es literalmente `this.vista =
  'editar'`.
- La vista REVISAR **no vuelve a pedir datos al servidor** para
  construirse — se calcula 100% desde el mismo `filasState` ya en
  memoria, vía los mismos computeds de 03C2 (§4/§18).

---

## 2. Condiciones exactas para habilitar "Revisar distribución"

Nuevo computed `puedeRevisar`:

```js
puedeRevisar() {
    if (this.totalDistribuirAhora <= 0) {
        return false;
    }

    for (const fila of this.filasState) {
        if (fila.modo === 'simple') {
            const cantidad = Number(fila.entregar || 0);

            if (cantidad > 0 && !fila.taller_id) {
                return false; // fila simple con cantidad > 0 sin Taller
            }

            if (cantidad > fila.disponible_confeccion) {
                return false; // cantidad > disponible (defensa; el input ya clampa esto en 03C2)
            }
        } else {
            // dividido: cada reparto necesita taller_id, y la suma no puede exceder disponible
            const tallerIds = fila.repartos.map((r) => r.taller_id).filter((id) => id !== null);

            if (new Set(tallerIds).size !== tallerIds.length) {
                return false; // division invalida: taller repetido dentro de la misma fila
            }

            if (fila.repartos.some((r) => Number(r.cantidad || 0) > 0 && !r.taller_id)) {
                return false; // cantidad sin taller dentro del reparto
            }

            if (this.filaEntregarActual(fila) > fila.disponible_confeccion) {
                return false; // suma de repartos > disponible
            }
        }
    }

    return true;
},
```

- **Reutiliza `filaEntregarActual()` y `disponible_confeccion` ya
  existentes** — ninguna fórmula nueva, tal como exige §4 del encargo.
- **No exige distribuir todo lo disponible** — un `totalDistribuirAhora`
  parcial (>0) es suficiente; dejar pendientes es válido (regla ya
  congelada en `CONFECCION_MASTER.md` §3/§8).
- El botón "Revisar distribución" reemplaza su `disabled` estático
  actual (`create.blade.php`, hoy siempre `disabled` con el tooltip de
  "próxima fase") por `:disabled="!puedeRevisar"`, sin tooltip fijo —
  opcionalmente un `title` dinámico explicando la razón concreta si está
  deshabilitado (ej. "Asigna un Taller a las filas con cantidad" cuando
  aplique), mejora menor de UX no bloqueante para la aprobación.
- El drawer de "Dividir entre talleres" ya impide guardar una división
  inválida (`erroresDraft`, 03C2) — por eso `filasState` nunca debería
  *llegar* a tener una división inválida guardada; el chequeo de arriba
  es una defensa adicional, no la única barrera.

---

## 3. Estructura del resumen (estado REVISAR)

Nuevo computed `resumenPorTaller`, construido a partir de
`construirAsignaciones()` (el **mismo** payload canónico de 03C2, sin
reconstruir nada — ver §6) más los nombres de Taller ya disponibles en
`this.talleres`:

```js
resumenPorTaller() {
    return this.construirAsignaciones().map((asignacion) => {
        const taller = this.talleres.find((t) => t.id === asignacion.taller_id);
        const totalTaller = asignacion.detalles.reduce((total, d) => total + d.cantidad, 0);

        return {
            taller_id: asignacion.taller_id,
            taller_nombre: taller ? taller.nombre : 'Taller #' + asignacion.taller_id,
            fecha_entrega: asignacion.fecha_entrega,
            observacion: asignacion.observacion,
            total: totalTaller,
            detalles: asignacion.detalles.map((d) => ({
                ...d,
                // enriquecido solo para mostrar nombre/color/talla — el
                // payload real (§6) sigue siendo el de construirAsignaciones()
                fila: this.filasState.find((f) => f.product_id === d.product_id),
            })),
        };
    });
},

sinDistribuir() {
    return this.filasState
        .filter((f) => Math.max(f.disponible_confeccion - this.filaEntregarActual(f), 0) > 0)
        .map((f) => ({
            nombre: f.nombre,
            color: f.color,
            talla: f.talla,
            pendiente: Math.max(f.disponible_confeccion - this.filaEntregarActual(f), 0),
        }));
},
```

Plantilla (agrupado por Taller, una fila compacta por variante dentro de
cada Taller — mismo espíritu que el mockup de 03B §12 y del propio
encargo):

```
Taller Confección 1                    Fecha entrega: 10/09/2026
Total: 60 prendas
  Hombre · Verde Cali · L        60        URGENTE

Taller Confección 4                    Fecha entrega: 12/09/2026
Total: 60 prendas
  Hombre · Verde Cali · L        20        ALTA
  Hombre · Verde Cali · M        40        NORMAL

SIN DISTRIBUIR
  Hombre Verde Cali L    20
  Hombre Verde Cali M    40
```

- El bloque "SIN DISTRIBUIR" es **puramente informativo** — se construye
  desde `sinDistribuir` (arriba), **nunca** se envía al backend (§6).
- La fecha mostrada por Taller es la **fecha efectiva** real
  (`fechaEfectiva(tallerId)`, ya existente en 03C2 — override o
  predeterminada), nunca una fecha distinta calculada aparte (§14 del
  encargo).
- La prioridad mostrada por detalle es la real de ese detalle
  específico (`d.prioridad`, que ya viene correcta del payload —
  `fila.prioridad` para fila simple o `reparto.prioridad` para cada
  reparto) — **nunca "Mixta" dentro de REVISAR**, porque aquí ya no
  estamos resumiendo una fila dividida como una unidad, estamos
  mostrando sus repartos ya separados uno por uno (§15 del encargo,
  resuelto por construcción: "Mixta" es un concepto de la vista EDITAR
  para resumir una fila en una sola línea; en REVISAR cada línea ya es
  un detalle atómico con su propia prioridad).

---

## 4. Totales (conciliación)

Se muestran, en la propia vista REVISAR, los mismos 4 números del header
de 03C2, **sin recalcularlos**:

```html
<span>Total apto: @{{ totalApto }}</span>
<span>Ya distribuido anteriormente: @{{ totalYaDistribuido }}</span>
<span>Distribuir ahora: @{{ totalDistribuirAhora }}</span>
<span>Pendiente después: @{{ totalPendiente }}</span>
```

`totalApto`, `totalYaDistribuido`, `totalDistribuirAhora`,
`totalPendiente` son los computeds **ya existentes y congelados** de
03C2 — se muestran también en REVISAR, no se duplica la fórmula en un
computed paralelo (cumple literalmente §4 del encargo: "NO crear una
segunda implementación distinta de las fórmulas").

---

## 5. N de órdenes — singular/plural

```js
talleresConCantidad() {
    return this.resumenPorTaller.length; // ya excluye talleres con cantidad 0
    // (construirAsignaciones() -> el Map solo agrega taller_id cuando
    // agregar() recibe un detalle con cantidad > 0 — ver 03C2 §construirAsignaciones)
},

textoBotonGenerar() {
    const n = this.talleresConCantidad;

    return `Generar ${n} orden${n === 1 ? '' : 'es'} de confección`;
},
```

- **N se deriva de `resumenPorTaller.length`**, que a su vez viene de
  `construirAsignaciones()` — el mismo `Map` que en 03C2 solo agrega una
  clave `taller_id` cuando `agregar()` recibe al menos un detalle con
  `cantidad > 0`. Un Taller seleccionado en una fila pero con
  `entregar=0` **nunca** produce una entrada en el Map, así que nunca
  cuenta hacia N — exactamente lo pedido.
- Ejemplos verificados contra la lógica: 1 taller con cantidad → "Generar
  1 orden de confección"; 2 talleres → "Generar 2 órdenes de
  confección".

---

## 6. Payload — reutilización exclusiva de 03C2

**No se construye ninguna estructura nueva.** `resumenPorTaller` (§3) es
una capa de **presentación** sobre `construirAsignaciones()`
(enriquecida con `taller_nombre`/`fila` solo para pintar la pantalla);
el **payload que se envía** al backend es literalmente:

```js
const payload = {
    orden_corte_id: this.ordenCorteId, // nueva prop, ver §6.1
    asignaciones: this.construirAsignaciones(),
};
```

Sin pasos intermedios, sin mapear de nuevo. Esto garantiza por
construcción:
- Una única asignación por `taller_id` (ya garantizado por el `Map` de
  03C2).
- Cantidades en 0 excluidas (ya garantizado: `agregar()` solo se llama
  cuando `cantidad > 0`).
- La forma exacta que espera `DistribuirConfeccionRequest`:
  `orden_corte_id`, `asignaciones[].{taller_id, fecha_entrega,
  observacion, detalles[].{product_id, cantidad, prioridad}}` —
  coincide campo por campo con `construirAsignaciones()` ya
  implementado.

### 6.1 Dato que falta hoy en el componente: `ordenCorteId`

`create.blade.php` (03C1/03C2) recibe hoy `orden-corte-codigo` (string,
solo para mostrar el título) pero **no recibe el `id` numérico** de la
`OrdenCorte` — el Controller sí lo tiene (`$ordenCorte->id`, ya cargado
en `OrdenConfeccionController::create()`) pero nunca se lo pasa al
componente Vue. **Cambio mínimo necesario en 03C3** (Blade, no
Controller/Service): agregar una prop nueva al componente:

```blade
<v-distribuir-confeccion
    ...
    :orden-corte-id="{{ $ordenCorte->id }}"
    ...
>
```

Es un cambio aditivo de una línea en `create.blade.php` — no toca
ningún archivo PHP de backend.

---

## 7. Decisión técnica del submit

### 7.1 Contrato HTTP actual (investigado, verificado en código real)

`OrdenConfeccionController::distribuir(DistribuirConfeccionRequest $request)`:

```php
public function distribuir(DistribuirConfeccionRequest $request)
{
    $ordenes = $this->ordenConfeccionService->distribuir($request->validated());

    session()->flash('success', trans('corteconfeccion::app.messages.created'));

    return redirect()->route('admin.corte_confeccion.ordenes_corte.show', $request->input('orden_corte_id'));
}
```

- **Éxito**: SIEMPRE una respuesta HTTP 302 (redirect) hacia la vista de
  la Orden de Corte de origen, con un mensaje flash de sesión. Las
  `$ordenes` creadas (colección con `id`/`codigo`/`taller`/`detalles`)
  **se descartan** — nunca llegan al cliente en ninguna forma.
- **`ValidationException`** (lanzada por `DistribuirConfeccionRequest` o
  dentro de `OrdenConfeccionService::distribuir()`): Laravel maneja esto
  de forma **automática y ya diferenciada según el tipo de request**,
  sin ningún código adicional en el Controller:
  - Si la request es una petición de formulario tradicional (`Accept:
    text/html`, el caso de un POST HTML normal): Laravel devuelve
    `redirect()->back()->withErrors($errors)->withInput()` — un 302 más
    los errores y el input viejo en la sesión flash, consumibles con
    `$errors`/`old()` en el Blade que reciba el siguiente `GET`.
  - Si la request **declara `Accept: application/json`** (que es lo que
    hacen `fetch`/`axios` por defecto): el mismo
    `ValidationException::render()` de Laravel detecta
    `$request->expectsJson()` y devuelve automáticamente
    `response()->json({message, errors}, 422)` — **sin que el
    Controller tenga que hacer nada especial.** Esto ya es así **hoy**,
    para los 5 tipos de error de `distribuir()` (`orden-sin-recepcion`,
    `taller-duplicado-en-distribucion`, `taller-inactivo`,
    `producto-configurable-no-valido`, `cantidad-excede-disponible`) —
    verificado leyendo el Service completo, no asumido.

### 7.2 Comparación A vs B

**A. POST HTML tradicional (inputs hidden dinámicos, como hoy hace
`ordenes-corte/create.blade.php` para su propio formulario)**

- Ventaja: cero código nuevo de red en el frontend, el navegador maneja
  todo.
- **Problema real y confirmado, tal como pedía el encargo documentar**:
  si `DistribuirConfeccionRequest` rechaza el payload,
  Laravel hace `redirect()->back()`. "Volver" recarga la página
  completa (`GET create/{id}`) — esto **destruye por completo la
  instancia de Vue actual**, incluyendo `filasState`, `repartos[]`,
  `configuracionTalleres`, `tallerTocadoManualmente`, y la propia
  `vista: 'revisar'`. Laravel repobla `old('asignaciones...')` con el
  array plano que se envió, pero **`old()` reconstruye inputs con
  `name=` planos, no el árbol de estado Vue** — y además la forma del
  payload (`asignaciones[].detalles[]`, agrupado **por Taller**) es
  estructuralmente distinta a `filasState` (agrupado **por variante**).
  Reconstruir `filasState` a partir de `old('asignaciones')` exigiría
  "invertir" el agrupamiwith lógica adicional no trivial (para cada
  `product_id` repetido en distintos `asignaciones[]`, inferir si era
  una fila dividida o dos filas simples, recuperar `tallerTocadoManualmente`
  que **no viaja en el payload en absoluto** porque es un concepto
  puramente de UI) — con riesgo real de reconstrucción incorrecta o
  incompleta. **Esto es exactamente el problema que el encargo pedía
  documentar si aparecía**, y aparece.
- Además, un submit HTML tradicional **no puede** devolver los
  `id`/`codigo` de las órdenes creadas para mostrarlos en el mensaje de
  éxito (§12) sin un cambio de Controller — encajaría con "el navegador
  simplemente sigue el redirect", perdiendo esa información salvo que
  se agregue a la vista destino (la propia Orden de Corte) un bloque
  nuevo "se generaron N órdenes", lo cual sí sería viable pero acopla la
  fase 03C3 a modificar `ordenes-corte/show.blade.php` también.

**B. `fetch`/`axios` desde Vue (JSON)**

- Vue ya usa `this.$axios` en este mismo paquete
  (`card-resultado.blade.php`, búsqueda de productos) — no es una
  dependencia nueva, es el patrón ya establecido en el propio módulo.
- El **camino de error ya funciona hoy sin tocar el backend**, por
  §7.1: axios envía `Accept: application/json` por defecto, Laravel
  responde 422 con `{message, errors}` automáticamente para
  **cualquiera** de los 5 errores de `distribuir()`.
- El **único cambio necesario** es en el camino de éxito, porque hoy
  SIEMPRE redirige sin importar el tipo de request — ver §7.3.
- El estado Vue (`filasState`, `vista`, todo) **nunca se pierde** en
  ningún escenario, porque no hay recarga de página en ningún punto del
  flujo de error — axios simplemente rechaza la promesa, el componente
  decide qué hacer con el error (§8-§10) sin destruir nada.

### 7.3 Decisión: **Opción B (`axios`/fetch)**

Elegida no "porque la pantalla ya usa Vue" (el encargo explícitamente
pide no elegir por esa razón sola), sino porque, comparando de forma
concreta:
- **Requiere menos complejidad neta**: el manejo de errores ya funciona
  gratis con el contrato actual; el único trabajo real es un `if`
  aditivo en el Controller para el camino de éxito (§7.4) — mucho menos
  trabajo que resolver la reconstrucción de estado de la Opción A.
- **Conserva correctamente los datos ante error** (Opción A no lo
  garantiza, ver arriba) — este criterio, explícitamente pedido por el
  encargo, es el que más pesa.
- **Maneja `ValidationException` con claridad** — ya lo hace, de fábrica,
  sin cambios.
- **Es consistente con Bagisto Admin/el propio módulo** — `$axios` ya es
  el mecanismo usado en formularios Vue de este mismo paquete para
  llamadas que no deben recargar la página.

### 7.4 Cambio mínimo de backend necesario (solo camino de éxito)

**Un único archivo, un único método, sin tocar el Service ni el
Request:**

```php
// OrdenConfeccionController::distribuir() — ÚNICO cambio propuesto
public function distribuir(DistribuirConfeccionRequest $request)
{
    $ordenes = $this->ordenConfeccionService->distribuir($request->validated());

    session()->flash('success', trans('corteconfeccion::app.messages.created'));

    if ($request->wantsJson()) {
        return response()->json([
            'ordenes' => $ordenes->map(fn ($orden) => [
                'id' => $orden->id,
                'codigo' => $orden->codigo,
                'taller_id' => $orden->taller_id,
            ])->values(),
            'redirect' => route('admin.corte_confeccion.ordenes_corte.show', $request->input('orden_corte_id')),
        ]);
    }

    return redirect()->route('admin.corte_confeccion.ordenes_corte.show', $request->input('orden_corte_id'));
}
```

- **`OrdenConfeccionService::distribuir()` no se toca** — sigue
  devolviendo exactamente la misma `Collection<OrdenConfeccion>` que ya
  devuelve hoy; el Controller solo decide **cómo responder** según el
  tipo de request, usando el método nativo de Laravel `wantsJson()`
  (ya disponible en cualquier `Request`, cero dependencia nueva).
- El camino HTML tradicional (`wantsJson()` falso) **sigue funcionando
  exactamente igual que hoy**, sin romper nada que ya dependa de él
  (aunque hoy nada lo usa vía navegador directamente, según §3 de 03A —
  el endpoint es nuevo desde 03A y sin vista propia hasta ahora).
- Esto es, literalmente, el "cambio mínimo" pedido por el encargo — un
  bloque `if` aditivo, sin lógica de negocio nueva, sin tocar
  validación, sin tocar el motor transaccional/lock (§7.5-7.6 de
  `03A_MOTOR_DISTRIBUCION.md` permanecen exactamente igual).

---

## 8. Disponibilidad cambió durante la edición (caso obligatorio)

### 8.1 Cómo se identifica

**Limitación real del contrato actual, documentada sin proponer tocar el
motor**: los 3 errores `taller-duplicado-en-distribucion`,
`taller-inactivo` y `cantidad-excede-disponible` **comparten la misma
clave de error `asignaciones`** en `ValidationException::withMessages()`
— Laravel no permite distinguirlos por clave sola. La única forma de
diferenciarlos sin tocar el Service es **comparar el texto exacto del
mensaje** devuelto (`error.response.data.errors.asignaciones[0]`) contra
las 3 cadenas ya fijas de `lang/es/app.php`:

```js
const MENSAJES = {
    disponibilidadCambio: 'La cantidad entregada supera la cantidad disponible para confeccion.',
    tallerInactivo: 'El taller seleccionado esta inactivo.',
    tallerDuplicado: 'Un mismo taller no puede recibir mas de una asignacion dentro de la misma operacion de distribucion.',
};
```

**Riesgo documentado** (no corregido aquí, motor congelado): si el texto
de esas traducciones cambiara en el futuro sin actualizar esta
comparación, la detección por causa dejaría de funcionar silenciosamente
y todo caería al banner "error genérico" de §10 — degradación
razonable (el usuario sigue viendo un error claro, solo pierde el
tratamiento especial de recarga automática), no una falla dura.

### 8.2 Flujo de UX

1. `axios` recibe 422 con mensaje que coincide con
   `disponibilidadCambio`.
2. **Se recarga la disponibilidad real desde servidor**: nueva llamada
   `GET` (endpoint a definir — puede ser el mismo `create()` en modo
   parcial, o un endpoint JSON dedicado; **si no existe hoy uno que
   devuelva solo `$filas` en JSON, este sería el segundo cambio mínimo
   de backend a evaluar en la implementación**, no diseñado en detalle
   aquí porque depende de cómo se prefiera exponerlo — opción más simple:
   reutilizar `DisponibilidadConfeccionService::porOrdenCorte()` +
   `VarianteConsultaService` detrás de una ruta JSON nueva de una sola
   línea, mismo patrón que `CompraTelaController::referencias()`).
3. Por cada `fila` en `filasState`, se compara su
   `disponible_confeccion` viejo contra el nuevo:
   - Si el nuevo disponible **alcanza** para lo que la fila ya tenía
     capturado (`filaEntregarActual(fila) <= nuevoDisponible`): **no se
     toca nada** de esa fila — ni cantidad, ni Taller, ni prioridad, ni
     modo. Se actualiza silenciosamente `fila.disponible_confeccion` al
     nuevo valor.
   - Si el nuevo disponible **ya no alcanza**: la fila se resetea con la
     **misma función `quitarDivision()`-equivalente ya existente en
     03C2** (`entregar=0`, `taller_id=tallerPredeterminado ?? null`,
     `tallerTocadoManualmente=false`, y si estaba dividida, `repartos=[]`,
     `modo='simple'`) — y se marca temporalmente (nuevo flag efímero
     `fila._pesoAjustado = true`, no persistido) para mostrar el aviso
     visual "tu valor anterior ya no cabe, lo reiniciamos" (mismo texto
     del wireframe G de 03B) directamente en esa fila, que desaparece en
     cuanto el usuario vuelve a tocarla.
4. `this.vista = 'editar'` — **siempre** se regresa a EDITAR, nunca se
   reintenta el envío automáticamente (regla de seguridad explícita del
   encargo).
5. Banner superior (no un input resaltado, porque no hay una celda
   única): *"La disponibilidad cambió mientras preparabas la
   distribución. Actualizamos las cantidades disponibles. Revisa los
   cambios antes de volver a confirmar."* — texto ya aprobado en 03B
   §15, reutilizado literal.

### 8.3 Qué se mantiene vs qué se ajusta

| Dato | Comportamiento |
|---|---|
| Filas cuyo disponible sigue alcanzando | Se mantienen intactas (cantidad, Taller, prioridad, división) |
| Filas cuyo disponible ya no alcanza | Se resetean a `entregar=0`/simple/sin Taller manual, con aviso puntual |
| `tallerPredeterminado`, `fechaPredeterminada`, `observacionGeneral`, `configuracionTalleres` | Se mantienen siempre — no forman parte del cálculo de disponibilidad por producto |
| `vista` | Vuelve a `'editar'` siempre |

---

## 9. Taller inactivo

Detección: mensaje coincide con `tallerInactivo` (§8.1). A diferencia de
"disponibilidad cambió", aquí **no sabemos por el mensaje cuál Taller
específico fue** (el backend tampoco lo dice — solo confirma que *alguno*
de los talleres de la operación ya no está activo). Diseño:

1. `this.vista = 'editar'`.
2. Se recarga la lista de `talleres` activos (mismo endpoint JSON de
   §8.2, o uno propio si se prefiere separar responsabilidades — a
   definir en implementación).
3. Por cada fila (simple o cada reparto de una dividida) cuyo
   `taller_id` **ya no aparezca** en la lista de talleres activos
   recargada: se limpia únicamente ese campo (`taller_id = null`,
   `tallerTocadoManualmente = false` para esa fila/reparto), **sin
   tocar su cantidad ni su prioridad** — el usuario solo necesita
   volver a elegir Taller para esa fila puntual.
4. Banner: *"Uno de los talleres seleccionados ya no está activo. Elige
   otro Taller para la(s) fila(s) marcada(s)."* (texto ya aprobado en
   03B §15) + resaltado visual (borde ámbar) en las filas/repartos
   afectados.
5. El resto de la captura permanece intacta — **nunca se borra toda la
   captura** por este error, tal como exige el encargo.

---

## 10. Otros errores de backend

| Causa | Clasificación | UX |
|---|---|---|
| `taller-duplicado-en-distribucion` | Recuperable, pero no debería ocurrir nunca desde esta UI (el picker de Taller por fila y el drawer de división son las únicas formas de asignar, y ya impiden repetir Taller dentro del mismo drawer — 03C2 `erroresDraft`). Si aun así ocurre: banner "Un mismo taller no puede recibir más de una asignación. Usa 'Editar división' en la fila correspondiente." + `vista='editar'`, sin borrar nada. |
| `producto-configurable-no-valido` | No debería ocurrir nunca (las filas vienen siempre de `RecepcionCorteDetalle` con `product_id` de variante simple, vía `VarianteConsultaService`). Si el backend lo reporta: banner genérico "Uno de los productos no es válido para confección. Contacta soporte." + `vista='editar'`. |
| `orden-sin-recepcion` | No debería ocurrir (si se llegó a esta pantalla, la orden ya tiene recepción por definición del flujo). Banner genérico + `vista='editar'`. |
| Error de red (timeout, sin conexión) | **Error inesperado** — banner: "No se pudo completar la operación. Revisa tu conexión e inténtalo de nuevo." Sin asumir que se creó nada. |
| HTTP 500 / excepción no controlada | **Error inesperado** — banner: "Ocurrió un error inesperado. No se creó ninguna Orden de Confección." (correcto afirmarlo: el motor es transaccional — `DB::transaction()` en `distribuir()` garantiza 0 órdenes creadas si cualquier excepción escapa dentro de la transacción, confirmado en `03A_MOTOR_DISTRIBUCION.md` §2.12/§7.6). `vista='editar'`, captura intacta. |

Regla general para **todos** los casos de esta tabla: `vista='editar'`
siempre tras un error, captura existente conservada salvo el ajuste
puntual que corresponda (§8/§9), nunca un JSON crudo ni un stack trace
en pantalla.

---

## 11. Prevención de doble submit

```js
async confirmarDistribucion() {
    if (this.enviando || !this.puedeRevisar) {
        return;
    }

    this.enviando = true;
    this.errorEnvio = null;

    try {
        const { data } = await this.$axios.post(this.distribuirUrl, {
            orden_corte_id: this.ordenCorteId,
            asignaciones: this.construirAsignaciones(),
        });

        this.manejarExito(data); // ver §12
    } catch (error) {
        this.manejarError(error); // §8-§10
    } finally {
        this.enviando = false;
    }
},
```

- Botón "Generar N órdenes" con `:disabled="enviando || !puedeRevisar"`.
- Texto dinámico mientras envía: `@{{ enviando ? 'Generando órdenes...' : textoBotonGenerar }}`.
- Botón "Volver y corregir" también deshabilitado mientras `enviando`
  (`:disabled="enviando"`) — el usuario no puede escapar de REVISAR a
  mitad de una request en vuelo, evitando un doble-envío por esa vía.
- `enviando` vuelve a `false` en el `finally`, tanto en éxito como en
  error — el único caso que lo deja permanentemente `true` sería un
  error de JS no capturado, que ya no es responsabilidad de esta lógica.

---

## 12. Experiencia de éxito

**Decisión: confirmación breve en la misma pantalla, después
redirección** (no redirección inmediata sin feedback):

```js
manejarExito(data) {
    this.ordenesCreadas = data.ordenes; // [{id, codigo, taller_id}]
    this.vista = 'exito'; // tercer estado, ver abajo

    setTimeout(() => {
        window.location.href = data.redirect;
    }, 1800);
},
```

- Se añade un tercer valor a `vista` (`'exito'`) en vez de reutilizar
  `'revisar'`, para poder mostrar un mensaje de cierre claro y
  deshabilitar cualquier interacción de edición (la operación ya se
  persistió, no tiene sentido dejar los inputs activos):

  ```
  ✓ Distribución realizada correctamente.
    Se generaron 2 órdenes de confección.
    OF-000123 · Taller 1
    OF-000124 · Taller 4

  Redirigiendo a la Orden de Corte...
  ```

- Por qué esta opción y no las dos alternativas descartadas:
  - *Redirigir inmediatamente sin feedback*: más simple, pero el usuario
    no ve confirmación de qué se creó exactamente (menos útil para
    Bodega, que sí quiere ver los códigos generados antes de irse de la
    pantalla).
  - *Quedarse indefinidamente en la pantalla sin redirigir*: el encargo
    pide explícitamente no dejar al usuario en el mismo estado editable
    después de persistir — dejarlo sin redirigir en absoluto violaría
    eso si el usuario luego refresca y encuentra el formulario "vacío"
    de forma confusa.
  - La opción elegida (mensaje breve + redirect automático a los ~1.8s)
    da lo mejor de ambas: confirmación clara + no deja al usuario varado
    en una pantalla ya inútil.
- Tras `vista='exito'`, ningún control de EDITAR/REVISAR debe volver a
  habilitarse — es un estado terminal para esa carga de página (el
  usuario ya está siendo redirigido).

---

## 13. Observación general

Sin cambios respecto a 03C2: `observacionGeneral` (único, sin override
por Taller) ya se copia literal a cada asignación dentro de
`construirAsignaciones()` (`observacion: this.observacionGeneral ||
null`). 03C3 no agrega ningún campo de observación nuevo — se
reutiliza tal cual.

---

## 14. Fechas

Sin cambios respecto a 03C2: `fechaEfectiva(tallerId)` (override en
`configuracionTalleres` o `fechaPredeterminada`) ya alimenta
`construirAsignaciones()`. La vista REVISAR (§3) muestra exactamente esa
misma fecha efectiva por Taller — nunca una fecha distinta calculada
aparte.

---

## 15. Prioridades

Ya resuelto por construcción en §3: dentro de REVISAR cada línea es un
detalle atómico (`{product_id, cantidad, prioridad}}`) con su propia
prioridad real — nunca "Mixta" (ese concepto solo existe en EDITAR para
resumir visualmente una fila dividida como una sola línea).

---

## 16. Pendiente sin distribuir

Ya diseñado en §3 (`sinDistribuir` computed) — se muestra siempre que
haya algo pendiente, con estilo neutro (no ámbar de advertencia, no
rojo de error) — es información, no un problema, tal como exige el
encargo y confirma `CONFECCION_MASTER.md` §3.

---

## 17. Retorno a EDITAR

```js
volverAEditar() {
    if (this.enviando) {
        return;
    }

    this.vista = 'editar';
    this.errorEnvio = null;
},
```

Sin recarga de página, sin tocar `filasState` ni ningún otro dato — es
literalmente un cambio de bandera, ya que ambas vistas leen del mismo
estado compartido.

---

## 18. Validación final antes de POST

Justo antes de armar el payload en `confirmarDistribucion()` (§11), se
vuelve a evaluar `this.puedeRevisar` (la misma condición de §2) como
guarda de entrada a la función — si por cualquier motivo cambió a
`false` mientras el usuario estaba mirando REVISAR (ej. edición
concurrente de otra pestaña del mismo navegador tocando el mismo
`filasState` — escenario improbable pero no imposible dentro de la
misma sesión), la función simplemente no envía nada. La **autoridad
final** sigue siendo el backend (§8-§10): incluso si `puedeRevisar` da
`true` localmente, el servidor puede rechazar por datos que cambiaron
del lado del servidor (otro usuario, no la misma pestaña) — ese caso ya
está cubierto por §8.

---

## 19. Respuesta del endpoint — contrato actual y cambio mínimo

Ya documentado en detalle en §7.1 (contrato actual) y §7.4 (cambio
mínimo propuesto, solo camino de éxito). Resumen:

| Escenario | Hoy | Tras el cambio mínimo (§7.4) |
|---|---|---|
| Éxito, request HTML | 302 redirect + flash session | Sin cambio |
| Éxito, request JSON (`wantsJson()`) | 302 redirect (inútil para axios) | 200 JSON `{ordenes: [...], redirect: url}` |
| `ValidationException`, request HTML | 302 back + errors en sesión | Sin cambio |
| `ValidationException`, request JSON | **Ya hoy**: 422 JSON `{message, errors}` (comportamiento nativo de Laravel, sin código adicional) | Sin cambio |

`OrdenConfeccionService::distribuir()` no se altera en ningún escenario.

---

## 20. Responsive de REVISAR

**No se repite el boceto de 3 niveles de 03B §16** (quedó superado por
la implementación real). Se sigue el patrón **ya implementado y
aprobado en 03C2**:

- **`>=768px` (breakpoint `md`, mismo que 03C2)**: filas compactas tipo
  grid, una por variante dentro de cada Taller — mismas 7 columnas
  reducidas de 03C2 (producto, cantidades, taller ya fijo — se muestra
  como texto en vez de `<select>`, ya no es editable en REVISAR—,
  prioridad). El agrupamiento visual es por Taller (encabezado con
  nombre + fecha + total, igual que un "grupo" en el patrón de acordeón
  ya usado en Compras de Tela y en la propia 03C2), cada Taller
  **inicia expandido** en REVISAR (a diferencia de los acordeones de
  Compras de Tela/grupos de corte, que inician cerrados) — aquí el
  usuario ya decidió revisar, tiene sentido mostrar todo de una vez
  porque son pocas líneas comparado con la lista completa de EDITAR.
- **`<768px`**: tarjetas verticales, una por Taller, con sus detalles
  apilados dentro — mismo patrón de tarjeta ya usado en el resto del
  módulo (Compras de Tela, EDITAR de 03C2).
- El bloque "Sin distribuir" y los totales (§3/§4) usan el mismo
  patrón de fila compacta con `flex-wrap` ya usado en el header de
  03C2 — se reflow solos sin breakpoint duro adicional.
- Los botones "Volver y corregir"/"Generar N órdenes" se colocan en una
  barra inferior — **reutilizando la misma técnica `position: fixed`
  sin offsets ya validada en 03C2** (§24 de `03C2_INTERACCION_DISTRIBUCION.md`)
  para evitar el mismo problema de `overflow:hidden` del layout core de
  Bagisto que rompe `sticky` — no se vuelve a intentar `sticky` aquí.
- **No se repite el problema de tarjetas gigantes para 30 variantes**:
  REVISAR muestra como máximo tantas líneas como variantes con
  `entregar>0` tengan, agrupadas por Taller — en el caso típico (una
  fracción de la disponibilidad total) esto es sustancialmente más
  corto que la lista completa de EDITAR, que ya se validó con 30
  variantes en 03C2.

---

## 21. QA end-to-end de 03C3 (diseño de tests, no implementados)

Los 12 casos pedidos, mapeados a nivel de test (Pest + navegador real
donde aplique, mismo estándar de 03C1/03C2 — nunca afirmar validación
sin ejecutarla):

| # | Caso | Nivel de test |
|---|---|---|
| 1 | Una fila simple → un Taller | Backend (payload) + navegador (flujo completo EDITAR→REVISAR→éxito) |
| 2 | L: 60 Taller1 + 20 Taller4; M: 40 Taller4 → 2 órdenes reales | Backend (verifica 2 `OrdenConfeccion`, detalles correctos por taller) + navegador |
| 3 | Cantidad parcialmente sin distribuir | Backend (disponible > entregado, orden se crea igual) + navegador ("Sin distribuir" visible) |
| 4 | Prioridades conservadas | Backend (assert `OrdenConfeccionDetalle.prioridad` por fila) |
| 5 | Fechas default + override | Backend (assert `fecha_entrega` por `OrdenConfeccion` coincide con la efectiva de cada Taller) |
| 6 | Observación general | Backend (assert `observacion` igual en todas las `OrdenConfeccion` creadas) |
| 7 | Doble submit prevenido | Navegador (clic doble rápido → verificar en BD que se creó exactamente el conjunto esperado de órdenes, no el doble) |
| 8 | Backend rechaza por disponibilidad cambiante | Backend (dos llamadas a `distribuir()` secuenciales sobre el mismo disponible, la segunda debe fallar) + navegador (simular con una segunda pestaña/sesión modificando disponibilidad antes del submit de la primera, verificar mensaje y reset de fila) |
| 9 | Taller inactivo | Backend (desactivar taller entre construir el payload y enviarlo) + navegador |
| 10 | Volver desde REVISAR conserva captura | Navegador (ida y vuelta EDITAR↔REVISAR, verificar que `filasState` no cambió) |
| 11 | Éxito redirige y disponibilidad se recalcula | Navegador (tras crear, volver a abrir la pantalla de distribución de la misma orden y confirmar que las cantidades ya entregadas se reflejan en `ya_distribuido`) |
| 12 | No se crean órdenes parciales si falla | Backend (ya cubierto conceptualmente por los tests de 03A — Caso 4 "Rollback" — 03C3 solo necesita un test de integración que confirme que, desde el endpoint HTTP con el payload real de esta UI, un fallo a mitad de la operación deja 0 órdenes) |

Backend nuevo estimado: extender
`tests/Feature/DistribucionConfeccionTest.php` (ya existe, 13 tests de
03A) con los casos de payload/UI específicos que no estén ya cubiertos
literalmente por 03A (la mayoría de la integridad transaccional ya está
probada ahí — 03C3 solo necesita probar la capa nueva: el `wantsJson()`
del Controller, y los flujos de navegador real de estado
EDITAR/REVISAR/errores).

---

## 22. No implementado en esta fase

Confirmado: ningún Blade/Vue/JS/Controller/Service/Request/ruta/
migración fue tocado para producir este documento. Sin estados de
producción, sin retorno de Taller, sin pagos, sin KPIs, sin cambios en
`packages/Webkul/`.

---

## 23. Decisiones que requieren aprobación explícita antes de implementar

1. **Opción B (axios) + el cambio mínimo de Controller de §7.4** — es la
   única modificación de backend propuesta en todo este documento;
   requiere confirmación explícita antes de tocar
   `OrdenConfeccionController::distribuir()`.
2. **Endpoint JSON nuevo para recargar disponibilidad/talleres tras un
   error de concurrencia (§8.2/§9)** — no se diseñó su forma exacta
   (reutilizar `create()` en modo parcial vs. una ruta dedicada nueva);
   debe decidirse en la implementación, con el mismo patrón ya usado por
   `CompraTelaController::referencias()` como referencia más cercana.
3. **Limitación de identificación de causa de error por texto de mensaje
   (§8.1)** — aceptar el riesgo documentado (string-matching contra
   `lang/es/app.php`) en vez de tocar el motor congelado de 03A.
4. **Tercer estado `vista: 'exito'` y el retraso de ~1.8s antes de
   redirigir (§12)** — validar que el tiempo y el patrón (mensaje breve
   + redirect automático) es el deseado, en vez de una de las dos
   alternativas descartadas.
5. **REVISAR inicia con cada Taller expandido** (§20), a diferencia del
   patrón "todo cerrado" ya usado en los acordeones de Compras de
   Tela/grupos de corte — confirmar que esta inconsistencia deliberada
   es aceptable (la razón: en REVISAR ya hay pocas líneas y el usuario
   quiere verificar todo antes de confirmar, no navegar/buscar).

---

## 24. Implementación real (2026-09-08)

Ejecutada tras la aprobación explícita del usuario, con los ajustes
indicados en el encargo de ejecución. Esta sección documenta lo
realmente construido, dónde difiere del diseño de §1-§23, el bug real
encontrado y corregido durante la validación en navegador, y la
evidencia de QA/tests.

### 24.1 Archivos modificados/creados

- `src/Http/Controllers/Admin/OrdenConfeccionController.php` — rama
  `wantsJson()` aditiva en `distribuir()` (§24.3); nuevo método
  `contexto()` + `construirContextoDistribucion()` (refactor del cuerpo
  de `create()` para reutilizar exactamente la misma lógica, sin
  duplicarla — §24.4); docblock de `distribuir()` actualizado (ya no
  dice "sin vista propia todavía").
- `src/Http/routes.php` — nueva ruta `GET contexto/{ordenCorteId}` →
  `admin.corte_confeccion.ordenes_confeccion.contexto`.
- `src/Config/acl.php` — la nueva ruta se agregó al mismo grupo de
  permiso que `create`/`store`/`distribuir`
  (`corte_confeccion.ordenes_confeccion.create`), no un permiso nuevo.
- `src/Resources/views/admin/ordenes-confeccion/create.blade.php` —
  patrón de estado `vista: 'editar' | 'revisar' | 'exito'`, resumen
  agrupado por Taller, submit JSON, reconciliación real (§24.5-§24.7),
  responsive heredado de 03C2.
- `tests/Feature/DistribucionConfeccionTest.php` — 10 tests nuevos
  agregados al final del archivo existente (§24.9), ninguno de los tests
  03A originales se tocó ni se eliminó.
- `docs/confeccion/03C3_REVISION_SUBMIT_RESPONSIVE.md` (este archivo) y
  `docs/confeccion/STATUS.md` — actualizados a "COMPLETADA / PENDIENTE DE
  VALIDACIÓN".

`OrdenConfeccionService.php` y `DistribuirConfeccionRequest.php`: **sin
cambios**, confirmado por `git diff` antes de cerrar la fase.
`packages/Webkul/`: **sin cambios**, confirmado con `git status`.

### 24.2 Patrón EDITAR/REVISAR/EXITO — final

Implementado exactamente como diseñado en §1: un único componente Vue
(`v-distribuir-confeccion`), un dato `vista` con tres valores, tres
bloques `<template v-if="vista === '...'">` dentro del mismo `<div>`
raíz. Sin navegación, sin recarga. `filasState`, `configuracionTalleres`,
`observacionGeneral`, etc. permanecen en el mismo componente durante
todo el ciclo EDITAR→REVISAR→(error)→EDITAR→REVISAR→ÉXITO, verificado
en navegador real (§24.8, casos F/H/I).

### 24.3 Contrato JSON de éxito — implementado

Exactamente el propuesto en §7.4/§8 del encargo de ejecución:

```json
{
    "success": true,
    "message": "Registro creado correctamente.",
    "ordenes": [
        { "id": 1832, "codigo": "OF-000011" }
    ],
    "redirect_url": "http://.../corte-confeccion/ordenes-corte/view/1759"
}
```

El camino HTML original (`wantsJson() === false`) se comprobó intacto
con un test dedicado (§24.9) además de los tests 03A ya existentes que
lo ejercitan.

### 24.4 Endpoint de refresh de contexto — implementado

`GET admin/corte-confeccion/ordenes-confeccion/contexto/{ordenCorteId}`
→ `OrdenConfeccionController::contexto()`. Reutiliza exactamente
`DisponibilidadConfeccionService::porOrdenCorte()` y
`VarianteConsultaService::porProductIds()` — el mismo código que
`create()`, extraído a un método protegido
`construirContextoDistribucion()` para no duplicar la lógica (pedido
explícito del encargo: "NO crear un segundo motor de disponibilidad").
Responde:

```json
{
    "filas": [ /* misma forma que $filas de create() */ ],
    "talleres": [ { "id": 804, "nombre": "..." } ]
}
```

ACL: agregado al mismo grupo `corte_confeccion.ordenes_confeccion.create`
que ya protege `create`/`store`/`distribuir` — verificado con un test
que confirma 401 para un rol sin ese permiso (Bagisto usa 401, no 403,
para esto — ver `Webkul\User\Http\Middleware\Bouncer::checkIfAuthorized()`).

### 24.5 Estrategia 422 — implementada, sin parseo de texto

Tal como exigió el encargo ("NO parsear textos de error"): el frontend
**nunca** compara el texto del mensaje de validación para decidir su
comportamiento. Ante cualquier 422:

1. Se guarda `erroresValidacion` (el objeto `errors` tal como lo devuelve
   Laravel) y se muestra en un banner amigable.
2. Se llama **siempre** a `GET contexto/{ordenCorteId}` — sin importar
   cuál haya sido la causa real del 422.
3. `reconciliarConContexto()` actualiza `disponible_confeccion` /
   `apta_confeccion` / `ya_distribuido` / `stock_actual` de cada fila, y
   la lista de Talleres activos, en un único paso.
4. Se vuelve a `vista: 'editar'`. Nunca se reenvía automáticamente.

Esto cubre **ambas** causas (disponibilidad cambiada y Taller
desactivado) con el **mismo código**, sin necesitar distinguirlas por
texto — más simple y más robusto que el diseño original de §8.1, que
sí contemplaba (como último recurso, documentado como riesgo) comparar
texto. La implementación real **no necesitó ese fallback**: la
reconciliación agnóstica a la causa es suficiente en todos los casos
probados.

### 24.6 Reconciliación de disponibilidad y Talleres — implementada, sin autoajuste

Exactamente como exigió el encargo ("NO autoajustar ni autoenviar"):

- Si `entregar` (fila simple) o la suma de `repartos` (fila dividida)
  supera el nuevo `disponible_confeccion`: **se conserva el valor
  capturado tal cual**, se guarda `_disponibleAnterior` para mostrar
  "Disponible cambió: antes X, ahora Y. Corrige la cantidad." junto al
  campo, y `puedeRevisar` (ya existente, sin fórmula nueva) bloquea el
  botón porque la condición "cantidad > disponible" sigue siendo cierta.
- Si un `taller_id` (fila simple) o el `taller_id` de un reparto ya no
  aparece en la lista de Talleres activos recibida: se pone en `null`,
  se marca `_tallerInvalido = true` (muestra "Taller ya no activo,
  reasigna"), **la cantidad y la prioridad se conservan intactas**.
  `puedeRevisar` bloquea el botón porque la condición "cantidad > 0 sin
  taller" sigue siendo cierta.

Validado en navegador real con evidencia exacta (§24.8, Casos H e I).

### 24.7 Bug real encontrado y corregido durante la validación

**Hallazgo:** el diseño original de §11 planteaba `this.talleres = data.talleres`
para refrescar la lista de Talleres tras un 422. `talleres` es un
**prop** del componente (`props: { talleres: {...} }`), no un campo de
`data()`. Vue 3 expone los props como una proxy de solo lectura;
reasignarlos directamente dispara un `console.warn` en desarrollo, pero
dentro de un `<script type="module">` (que corre siempre en **modo
estricto** de JavaScript) esa reasignación bloqueada por la proxy
**lanza una excepción real** (`TypeError`), no solo una advertencia.

Esa excepción ocurría dentro de `reconciliarConContexto()`, que se
ejecutaba dentro del `try` de `refrescarContexto()` — su `catch` la
silenciaba por diseño (para no tapar el banner de error ya mostrado),
así que **toda la reconciliación se abortaba en silencio** cada vez:
la disponibilidad, la lista de Talleres y el estado de Taller-inválido
nunca se actualizaban, aunque el endpoint de contexto respondía
correctamente y el banner de error sí aparecía (por eso el síntoma no
era obvio a primera vista: la UI parecía "funcionar a medias").

**Cómo se encontró:** validación real en navegador (patchright/Playwright)
contra una base de datos real, provocando la disponibilidad cambiante de
forma directa vía `OrdenConfeccionService::distribuir()` en tinker entre
la captura en pantalla y el submit. La UI mostraba el banner de error
pero la disponibilidad visible no cambiaba y el botón "Revisar" seguía
habilitado — comportamiento que no coincidía con el diseño y que se
investigó con diagnósticos DOM intermedios (checkpoints escritos a
elementos ocultos del DOM, dado que las propiedades de `window` no son
visibles de forma fiable desde el runner de automatización usado) hasta
aislar la línea exacta que lanzaba la excepción.

**Corrección:** se agregó un campo `talleresState` en `data()`,
inicializado como copia del prop `talleres` (mismo patrón ya usado para
`filas` → `filasState`). Todas las lecturas/escrituras internas
(`v-for` de los 4 `<select>` de Taller, `talleresUtilizados`,
`construirAsignaciones()`, `opcionesTallerDraft()`,
`reconciliarConContexto()`) se movieron a `talleresState`; el prop
`talleres` solo se usa para la siembra inicial. Verificado con evidencia
real tras la corrección (§24.8, Caso H): la disponibilidad, el Taller
inválido y el bloqueo de "Revisar" ahora se actualizan correctamente.

Este bug **no fue detectado por los tests backend** (todos pasan, porque
son tests de Laravel/HTTP, no de la interacción Vue en vivo) — solo la
validación real en navegador lo hizo evidente, confirmando una vez más
la necesidad de esa validación además de la suite Pest (ya documentado
como limitación conocida del proyecto desde 03C1 §9.2).

### 24.8 QA real en navegador — resultados

Ejecutado con el skill de automatización de navegador (patchright)
contra `php artisan serve` y la base de datos real del proyecto, con
fixtures creados y **eliminados al terminar** (Talleres, Admins, Roles,
Productos, Proveedores, Compras, Rollos, Órdenes de Corte/Confección con
prefijo `QA03C3*` — verificado con conteo en 0 tras la limpieza).

| Caso | Resultado |
|---|---|
| A — una variante → un Taller → revisar → generar | OK. 1 orden real creada (`OF-000002`), disponible restante correcto (40/40 tras distribuir 60+40 de 100/80). |
| B — L: 60 Taller1 + 20 Taller4 (vía drawer "Dividir"), M: 40 Taller4 → 2 órdenes reales | OK. Payload y resumen agrupado exactos; BD confirma 2 `OrdenConfeccion` (`OF-000003` taller A cantidad 60 prioridad alta; `OF-000004` taller B con L:20/urgente + M:40/normal fusionados en un solo bloque). |
| C — distribución parcial dejando pendiente | OK. Bloque "SIN DISTRIBUIR" mostró correctamente 20 unidades pendientes de L, sin bloquear el submit. |
| D — fecha predeterminada + override | Parcial: el mecanismo de "Personalizar" funcionó (abre el input de fecha), pero en la corrida real ambos Talleres terminaron con la misma fecha (no se cambió el valor tras personalizar). No es un defecto de la lógica (el campo es editable y el payload lo transporta por Taller) — quedó como pendiente de una corrida de QA adicional que sí cambie el valor, ver §24.10. |
| E — prioridades distintas | OK. `alta`/`urgente`/`normal` preservadas por detalle, verificado en REVISAR y en BD. |
| F — Volver y corregir conserva estado | OK. Cantidad capturada (60) se mantuvo intacta tras ir a REVISAR y volver a EDITAR. |
| G — doble click no duplica | OK. Doble click disparado sobre "Generar" — BD confirma exactamente 1 `OrdenConfeccion`, no 2. |
| H — disponibilidad cambiada entre apertura y confirmación | OK **tras corregir el bug de §24.7**. Banner de error mostrado, disponible actualizado de 100→50 en pantalla, cantidad capturada (80) preservada tal cual, mensaje "Disponible cambió: antes 100, ahora 50. Corrige la cantidad." visible, botón "Revisar" bloqueado. |
| I — Taller desactivado antes del submit | OK **tras corregir el bug de §24.7**. Taller quitado de la lista de opciones activas, `taller_id` puesto en `null`, cantidad (50) preservada, mensaje "Taller ya no activo, reasigna" visible, botón "Revisar" bloqueado hasta reasignar. Tras reasignar a otro Taller activo, el submit tuvo éxito. |
| J — 422 vuelve a EDITAR y conserva captura razonablemente | OK. Verificado en los Casos H e I: la captura (cantidad, prioridad) nunca se perdió, solo se corrigió lo estrictamente inválido (disponible/Taller). |
| K — éxito sale de la pantalla | OK. Pantalla de éxito con código(s) de orden generada, redirect automático a la Orden de Corte confirmado (`page.waitForURL` exitoso en cada corrida). |
| L — 0 errores de consola | OK en todas las corridas. Las únicas entradas registradas por el navegador durante las pruebas fueron los propios 422 esperados (network-level, no excepciones JS) al probar deliberadamente los caminos de error. |

**No probado en navegador** (evaluado solo por revisión de código, tal
como exige el estándar del proyecto de no afirmar una prueba que no se
ejecutó): el camino de error de red/500 genuino (`resultadoIncierto`,
§15 del encargo) — reproducir una desconexión real o un 500 del servidor
de forma controlada durante una prueba de navegador no era practicable
en el tiempo disponible de esta fase; el código de `manejarError()` para
ese caso se revisó manualmente y sigue la lógica descrita en §15/§10 de
este documento (banner de acción segura, sin reenvío automático), pero
**no tiene evidencia de ejecución real**.

Responsive de REVISAR verificado en 900px y 768px (mismo patrón que
03C2): cero overflow horizontal en ambos anchos (`scrollWidth ===
clientWidth`).

### 24.9 Tests backend — números exactos

```
vendor/bin/pint --test packages/ByLopez/CorteConfeccion
{"tool":"pint","result":"passed"}

vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"
Tests:    118 passed (371 assertions)
Duration: ~52s
```

Los 10 tests nuevos de esta fase (dentro de `DistribucionConfeccionTest.php`):

1. GET `create` de distribución renderiza sin errores de Blade.
2. Request sin `Accept: application/json` conserva el redirect HTML
   original + flash de sesión.
3. `distribuir()` responde JSON `{success, message, ordenes, redirect_url}`
   con evidencia real en BD (2 órdenes, taller/fecha/observación/
   cantidad/prioridad correctos, disponibilidad restante correcta) —
   escenario multi-taller equivalente al Caso B.
4. `distribuir()` responde 422 en el formato JSON estándar de Laravel
   (`{message, errors}`) y no crea ninguna orden (rollback confirmado).
5. `contexto()` devuelve disponibilidad real por `product_id` tras una
   distribución previa.
6. `contexto()` solo devuelve Talleres activos.
7. `contexto()` exige el permiso de crear órdenes de confección (401 sin
   él).

(Los ítems 8-12 del checklist original del encargo —prioridades,
fechas, observación, rollback, disponibilidad restante— quedaron
cubiertos dentro del mismo test #3, que verifica todos esos campos en un
único escenario multi-taller real, en vez de un test por campo — se
consideró más representativo del flujo real y evita fixtures
redundantes.)

Ningún test de 03A (`DistribucionConfeccionTest.php` original, 13 tests)
se modificó ni se eliminó.

### 24.10 Diferencias respecto al diseño original (§1-§23)

1. **§8.1 (identificación de causa por texto)**: la implementación real
   **no necesitó** el string-matching contra `lang/es/app.php` que el
   diseño dejaba como último recurso — la reconciliación agnóstica a la
   causa (§24.5) cubre ambos escenarios (disponibilidad y Taller
   inactivo) con el mismo código, sin distinguir la causa en absoluto.
   Esto es una simplificación real respecto al diseño, no una
   desviación que reduzca robustez.
2. **`talleresState` vs `this.talleres` (§11, §24.7)**: el diseño
   original no anticipó que `talleres` era un prop de solo lectura; la
   implementación real corrige esto con un patrón ya usado en el propio
   diseño para `filas`/`filasState`. Documentado como aprendizaje para
   futuras fases: cualquier prop que la fase necesite mutar debe
   diseñarse desde el inicio con su copia mutable en `data()`.
3. **Fecha por Taller (Caso D, §24.8)**: funcionalmente completo pero
   sin una corrida de QA que confirme visualmente el cambio de valor de
   fecha tras "Personalizar" — pendiente puntual, no bloqueante (la
   lógica es la misma ya validada en 03C2 para "Fechas por taller").
4. **Camino de red/500 genuino (§15, §24.8)**: implementado y revisado
   por código, sin evidencia de ejecución real en navegador (ver
   limitación explícita en §24.8).

### 24.11 Riesgos pendientes

- El Caso D (fecha por Taller) y el camino `resultadoIncierto`
  (red/500) quedan como puntos de QA real pendientes, no como defectos
  conocidos.
- La limitación de "no distinguir la causa del 422 por texto" ya no
  aplica (§24.10.1) — se elimina como riesgo respecto al diseño
  original.
- Ningún otro riesgo nuevo detectado. `packages/Webkul/` permanece sin
  cambios, confirmado.

---

## 25. Validación final acotada (2026-09-08) — cierre de los 2 gaps pendientes

Ejecutada exclusivamente para cerrar los 2 gaps documentados en §24.10
(fecha override sin confirmación visual, camino red/500 sin prueba de
navegador real) antes de decidir si 03C3 podía marcarse APROBADA. Sin
nueva funcionalidad, sin refactor, sin tocar Webkul. Fixture temporal
(`QAFINAL*`: 1 Orden de Corte, 2 productos, 2 Talleres, proveedor/compra/
rollo, admins/roles) creado y **eliminado al terminar** — confirmado en
0 tras la limpieza.

### 25.1 Gap 1 — Fecha override: CONFIRMADO CORRECTO

Escenario: Taller 1 y Taller 2 activos, fecha predeterminada
`10/09/2026`, override de Taller 2 a `12/09/2026`.

- **EDITAR**: bloque "Fechas por Taller" mostró correctamente
  `QAFINAL Taller 1 → 2026-09-10` (predeterminada, sin override) y
  `QAFINAL Taller 2 → [personalizada] / Usar predeterminada` tras
  personalizar a `2026-09-12`.
- **Payload en memoria** (antes de Revisar): `taller_id 890 →
  fecha_entrega "2026-09-10"`, `taller_id 891 → fecha_entrega
  "2026-09-12"`.
- **REVISAR**: `QAFINAL Taller 1 — Entrega: 2026-09-10` /
  `QAFINAL Taller 2 — Entrega: 2026-09-12`, exacto.
- **BD, tras confirmar**: `OrdenConfeccion` Taller 1 (id 890,
  `OF-000003`) → `fecha_entrega = 2026-09-10`; `OrdenConfeccion` Taller 2
  (id 891, `OF-000004`) → `fecha_entrega = 2026-09-12`. Verificado
  directamente con `->fecha_entrega->toDateString()`.

**Conclusión:** el gap de §24.10.3 no era un defecto — la corrida
anterior simplemente no había cambiado el valor del campo de fecha tras
personalizar. Con un cambio de valor real, el mecanismo funciona
exactamente como se diseñó en 03C2/03C3, sin ningún ajuste de código.

### 25.2 Gap 2 — Network error y HTTP 500: CONFIRMADOS CORRECTOS

Simulados con interceptación de red real del navegador (Playwright
`page.route()` sobre la ruta `**/ordenes-confeccion/distribuir`), sin
modificar código de producción ni el backend real.

**Network error** (`route.abort('failed')`):
- 1 único POST realizado (contado explícitamente).
- Banner `resultadoIncierto` visible con el texto exacto: "No fue
  posible confirmar el resultado de la operación. No vuelvas a enviar
  todavía. Revisa las órdenes de confección del corte." — **nunca**
  afirma "0 órdenes creadas".
- Enlaces de navegación segura presentes: "Volver a la Orden de Corte" /
  "Ver órdenes generadas".
- El bloque `resumen-por-taller` quedó oculto (reemplazado por el
  banner).
- Botón "Generar" quedó **deshabilitado** (no permite un segundo submit
  ciego); "Volver y corregir" permaneció habilitado (navegación
  inofensiva).
- Sin éxito falso, sin redirect. Esperados 2s adicionales tras el
  fallo: **ningún segundo POST** se disparó por sí solo.

**HTTP 500** (`route.fulfill({status: 500, ...})`):
- Mismo resultado exacto: 1 único POST, mismo banner
  `resultadoIncierto`, mismo texto, mismos enlaces, resumen oculto,
  botón "Generar" deshabilitado.

**Revisión de código de seguridad (§4 del encargo), confirmada sin
cambios necesarios** — releída línea por línea en esta validación:
- No existe ningún `setTimeout` que reenvíe el POST (el único
  `setTimeout` del archivo es el de `manejarExito()`, que solo redirige
  tras el éxito real).
- `confirmarDistribucion()` es invocado **únicamente** desde el
  `@click` del botón "Generar" — ningún otro call site, ningún
  `catch` que lo vuelva a invocar.
- El botón nunca cambia su texto a "Reintentar" — permanece con su
  texto normal ("Generar N órdenes...") o "Generando órdenes..." durante
  el envío; en el estado `resultadoIncierto` queda simplemente
  deshabilitado.
- Ningún código compara/parsea el texto del mensaje de error para
  decidir el comportamiento — `manejarError()` decide únicamente por
  `!response` (sin respuesta) vs. `response.status === 422` vs.
  cualquier otro status, nunca por contenido de texto.

**Conclusión:** el diseño de `resultadoIncierto` (§15 del encargo de
implementación) funciona exactamente como se documentó, confirmado con
evidencia real por primera vez — sin necesidad de ningún cambio de
código.

### 25.3 Regresión rápida — CONFIRMADA, sin estado residual

Tras ambas simulaciones de error, en la misma sesión de navegador:
1. Se quitó la interceptación de red (`page.unroute()`).
2. "Volver y corregir" → EDITAR: la cantidad capturada antes de la
   simulación (15) se mantuvo intacta — cero corrupción de estado por
   las simulaciones.
3. Nueva captura limpia → REVISAR → "Generar" (esta vez sin
   interceptación, llegando al backend real): éxito real ("Se generaron
   1 orden de confección", `OF-000005`), redirect real confirmado a la
   Orden de Corte.
4. Verificado en BD: exactamente 3 `OrdenConfeccion` reales en total
   para la orden de prueba (`OF-000003`, `OF-000004` del Gap 1;
   `OF-000005` de esta regresión) — **ninguna orden extra** originada
   por las simulaciones de red/500, confirmando que abortar/falsear la
   respuesta en el navegador nunca llegó a tocar el backend real.

### 25.4 Tests y consola

```
vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"
Tests:    118 passed (371 assertions)

vendor/bin/pint --test packages/ByLopez/CorteConfeccion
{"tool":"pint","result":"passed"}
```

Sin cambios de código de producción en esta validación — los números
son idénticos a los de §24.9. Consola: 0 errores inesperados en todas
las corridas; las únicas entradas de red registradas fueron los fallos
deliberadamente simulados (`net::ERR_FAILED`, `HTTP 500`), esperados y
no indicativos de un bug.

### 25.5 Resultado

Ambos gaps cerrados sin encontrar ningún defecto nuevo. **03C3_REVISION_SUBMIT_RESPONSIVE
queda APROBADA** (2026-09-08). No se inició ninguna fase posterior.
