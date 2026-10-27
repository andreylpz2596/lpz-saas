# 03A_MOTOR_DISTRIBUCION.md — Alcance de implementación: motor backend de distribución

## 0. Estado de este documento

**APROBADA.** Fecha de implementación: 2026-09-04. Fecha de aprobación
final: 2026-09-04 (tras un ajuste puntual post-revisión, ver §7.11). Las
secciones 1-6 siguientes documentan el **alcance autorizado** tal como se
aprobó; la sección 7 ("Resultado de implementación") documenta lo que
realmente se construyó, los tests ejecutados y sus resultados exactos,
las diferencias puntuales frente al diseño original, y el ajuste final
que llevó a la aprobación.

**Esta fase requiere autorización explícita antes de ejecutarse.** Fue
autorizada, ejecutada y finalmente aprobada el 2026-09-04. No se avanzó a
ninguna fase posterior (03B, stock, estados avanzados, pagos, KPIs
quedan fuera).

---

## 1. Objetivo de la Fase 03A

Construir el **motor backend** que permite, en una sola operación
transaccional, distribuir la disponibilidad de una `OrdenCorte` entre uno
o varios Talleres, generando N `OrdenConfeccion` atómicamente, con
prioridad por variante y con las correcciones de integridad ya aprobadas
(anulación libera disponible; `product_id` debe ser un producto `simple`).

**No incluye ninguna interfaz nueva.** El resultado de esta fase es
consumible por tests y, más adelante, por la UI de la Fase 03B — pero esa
UI no se construye aquí.

---

## 2. Alcance incluido (13 puntos, según lo aprobado)

### 2.1 Migration de prioridad

Migración aditiva sobre `bylopez_cc_ordenes_confeccion_detalles`:

```
ALTER TABLE bylopez_cc_ordenes_confeccion_detalles
    ADD COLUMN prioridad VARCHAR(20) NOT NULL DEFAULT 'normal'
    AFTER cantidad_entregada;
```

- Sin `ENUM` nativo de MySQL (Decisión 3).
- `DEFAULT 'normal'` cubre automáticamente las filas existentes — sin
  backfill manual, sin script de datos.
- Sin índice (no hay necesidad real todavía, ver `02_MODELO_DISTRIBUCION.md` §10).
- Reversible (`down()` con `dropColumn`), como el resto de migraciones del
  módulo.

### 2.2 PHP Backed Enum de prioridad

Nuevo `Enums/PrioridadOrdenConfeccion.php`, mismo patrón que
`EstadoOrdenConfeccion`/`EstadoOrdenCorte` (enum `string`-backed con
`label()`/`badgeClass()`/`badgeHtml()` para reutilizar en Fase 03B sin
rediseñar el patrón):

```
enum PrioridadOrdenConfeccion: string
{
    case Normal = 'normal';
    case Alta = 'alta';
    case Urgente = 'urgente';
}
```

El dominio de valores válidos se controla en PHP, no en el esquema SQL
(Decisión 3).

### 2.3 Corrección de disponibilidad al anular

Modificar `DisponibilidadConfeccionService::porOrdenCorte()`:

- Excluir de la suma de "ya entregado" las `OrdenConfeccion` con
  `estado === 'anulada'`.
- Cambiar la agrupación de `product_id + talla` a **solo `product_id`**
  (Decisión 2 / `02_MODELO_DISTRIBUCION.md` §3.3, §4.4, §7).

Es un cambio de consulta/lógica, sin migración.

### 2.4 Validación de `product_id` simple

Nueva verificación de servidor (ubicación propuesta: método privado del
nuevo Service de distribución, o un pequeño helper reutilizable en
`Support/`) que, para cada `product_id` del payload, confirme
`products.type !== 'configurable'` y lance `ValidationException` para
**toda la operación** si alguno lo es (Decisión 2, obligatoria).

### 2.5 Nuevo payload multi-taller (backend)

Estructura conceptual ya fijada en `02_MODELO_DISTRIBUCION.md` §6:

```
orden_corte_id
asignaciones[]:
    taller_id
    fecha_entrega
    observacion
    detalles[]:
        product_id
        cantidad
        prioridad   (opcional, default 'normal')
```

Sin filas de cantidad 0 — solo se envían/persisten asignaciones > 0
(mismo principio que hoy, ver `02_MODELO_DISTRIBUCION.md` §5).

### 2.6 Request correspondiente

Nuevo `Http/Requests/DistribuirConfeccionRequest.php` (o el nombre que se
confirme al implementar), validando:
- `orden_corte_id`: existe.
- `asignaciones`: array, mínimo 1.
- `asignaciones.*.taller_id`: existe.
- `asignaciones.*.detalles`: array, mínimo 1.
- `asignaciones.*.detalles.*.product_id`: existe en `products`.
- `asignaciones.*.detalles.*.cantidad`: entero > 0.
- `asignaciones.*.detalles.*.prioridad`: nullable, `in:normal,alta,urgente`.

El filtrado de filas en 0 se hace en `prepareForValidation()`, igual que
hoy hace `StoreOrdenConfeccionRequest` (documentado en
`02_MODELO_DISTRIBUCION.md` §5), pero de forma explícita
(`> 0` en vez de depender de `empty()`).

La autorización (`authorize()`) reutiliza el mismo permiso ya existente
`corte_confeccion.ordenes_confeccion.create`.

### 2.7 Service de distribución multi-taller

Evolucionar `OrdenConfeccionService` agregando `distribuir(array $data):
Collection` (Decisión 1 / `02_MODELO_DISTRIBUCION.md` §8) — no se crea
una clase paralela `DistribucionConfeccionService`. El método `create()`
actual (single-taller) se conserva sin romper para no afectar nada que ya
dependa de él; se decide en el momento de implementar si `create()` pasa
a ser un caso particular de `distribuir()` internamente.

### 2.8 Agrupación por Taller

Dentro de `distribuir()`, las `asignaciones[]` del payload ya vienen
agrupadas por `taller_id` desde el request (un bloque = un taller); el
Service crea exactamente una `OrdenConfeccion` por bloque, con sus
`OrdenConfeccionDetalle` correspondientes.

### 2.9 Validación de suma por `product_id`

Antes de crear ninguna orden: sumar, para cada `product_id`, la cantidad
solicitada a través de **todas** las asignaciones del payload, y
comparar contra el disponible recalculado en servidor. Si algún
`product_id` excede su disponible, `ValidationException` para toda la
operación (`02_MODELO_DISTRIBUCION.md` §8, paso 4.f).

### 2.10 `DB::transaction()`

Una única transacción envolviendo todo `distribuir()`, igual que ya hace
`create()` hoy, pero para el conjunto completo de asignaciones.

### 2.11 `lockForUpdate()`

Adquirido sobre la fila de `RecepcionCorte` de la `OrdenCorte`, **al
inicio** de la transacción, **antes** de recalcular disponibilidad
(regla crítica de la Decisión 4 — orden no negociable).

### 2.12 Rollback total

Si cualquier validación o creación falla en cualquier punto dentro de la
transacción (taller inactivo, cantidad excedida, producto configurable,
error inesperado), Laravel revierte automáticamente todo lo hecho dentro
de `DB::transaction()` al lanzar la excepción — cero órdenes parciales.

### 2.13 Compatibilidad con órdenes existentes

- Las 3 `OrdenConfeccion` reales existentes no se tocan ni se
  recalculan retroactivamente.
- `prioridad` nueva llega con `DEFAULT 'normal'` (§2.1).
- El cambio de agrupación de disponibilidad (`product_id` solo, sin
  `talla`) no altera ningún resultado observable hoy, porque `talla` ya
  está vacía en el 100% de los datos reales (ver
  `02_MODELO_DISTRIBUCION.md` §3.1) — es decir, hoy `product_id +
  talla('')` y `product_id` solo agrupan exactamente igual.
- `create()` (flujo single-taller actual) sigue funcionando sin cambios
  de comportamiento para quien ya lo use.

---

## 3. Explícitamente FUERA de alcance de la Fase 03A

No se toca ni se diseña en detalle en esta fase (quedan para fases
futuras separadas, ninguna autorizada todavía):

- Rediseño de `create.blade.php` o cualquier Blade/Vue nuevo.
- Taller predeterminado como ayuda visual (Decisión 6: no se persiste,
  pero tampoco se construye su UI aquí).
- Acciones masivas de interfaz (seleccionar filas, asignar taller/
  prioridad a seleccionados, "asignar todo disponible", limpiar).
- Modal/flujo visual de "dividir entre talleres".
- Resumen visual antes de confirmar (CONFECCION_MASTER.md §20) — el
  backend soporta los datos que ese resumen necesitaría, pero la pantalla
  no se construye aquí.
- Lectura de stock Bagisto (`StockConsultaService`) y stock proyectado —
  aprobados conceptualmente, implementación diferida.
- Estados de producción avanzados ("en confección", "terminada",
  "recibida en bodega") y flujo de retorno físico del taller.
- Pagos a talleres.
- KPIs.
- Advertencia de UI al anular (Decisión 5) — el **cambio de cálculo**
  backend sí es parte de esta fase; el mensaje de advertencia en pantalla
  no.

---

## 4. Casos de test obligatorios

Los 8 casos siguientes son la definición de "hecho" de la Fase 03A. Se
implementan como tests Pest sobre el nuevo `OrdenConfeccionService::distribuir()`
(y, donde aplique, sobre `DisponibilidadConfeccionService`), usando el
mismo patrón de fixtures ya presente en `RolesYTalleresTest.php` /
`TallerVistaDetalleTest.php` (auditado en `01_AUDITORIA.md` §11).

**Caso 1 — Multi-taller.**
Disponible L = 100. Distribuir 60 → Taller 1 y 20 → Taller 4 en una sola
llamada. Resultado esperado: 2 `OrdenConfeccion` creadas, disponible
restante L = 20.

**Caso 2 — Cantidad pendiente.**
Disponible M = 80, no se distribuye nada de M en la operación. Resultado
esperado: disponible M sigue en 80; no se crea ningún
`OrdenConfeccionDetalle` de cantidad 0 para M.

**Caso 3 — Exceso entre talleres.**
Disponible L = 100. Solicitar 60 → Taller 1 y 50 → Taller 4 (suma 110).
Resultado esperado: `ValidationException`, no se crea ninguna orden.

**Caso 4 — Rollback.**
Primera asignación válida, segunda falla (p. ej. taller inactivo).
Resultado esperado: 0 `OrdenConfeccion` creadas (ni siquiera la primera,
válida en aislamiento).

**Caso 5 — Anulación.**
Disponible = 100. Crear `OrdenConfeccion` con 60 (disponible pasa a 40).
Anular esa orden. Resultado esperado: disponible vuelve a 100.

**Caso 6 — Concurrencia.**
Disponible = 100. Proceso A intenta distribuir 70, Proceso B intenta
distribuir 60, de forma solapada. Resultado esperado: nunca deben quedar
130 distribuidas — uno de los dos procesos debe fallar con
`ValidationException` tras esperar el lock. (Nota de diseño: simular
concurrencia real en Pest/SQLite o MySQL de test tiene límites técnicos;
el test debe al menos demostrar que el segundo proceso, ejecutado después
del commit del primero, ve el disponible actualizado y falla
correctamente — y documentar si el motor de BD de test soporta probar el
bloqueo real.)

**Caso 7 — Producto configurable.**
El payload contiene un `product_id` que corresponde a un producto
`configurable` (padre). Resultado esperado: rechazo de toda la operación,
sin crear ninguna orden.

**Caso 8 — Prioridad.**
Una asignación con L=60 prioridad `urgente` y M=40 prioridad `normal`.
Resultado esperado: cada `OrdenConfeccionDetalle` creado conserva su
propia `prioridad` correctamente (no se mezclan ni se sobreescriben entre
filas del mismo taller).

---

## 5. Archivos que se crearían/modificarían al ejecutar (referencia, no hecho todavía)

| Archivo | Acción |
|---|---|
| Nueva migración `..._add_prioridad_to_bylopez_cc_ordenes_confeccion_detalles.php` | Crear |
| `src/Enums/PrioridadOrdenConfeccion.php` | Crear |
| `src/Models/OrdenConfeccionDetalle.php` | Modificar (`fillable`/`casts` para `prioridad`) |
| `src/Services/DisponibilidadConfeccionService.php` | Modificar (§2.3) |
| `src/Services/OrdenConfeccionService.php` | Modificar (agregar `distribuir()`) |
| `src/Http/Requests/DistribuirConfeccionRequest.php` (nombre tentativo) | Crear |
| `src/Http/Controllers/Admin/OrdenConfeccionController.php` | Modificar (nuevo método o adaptar `store()`) — **sin** tocar Blade en esta fase |
| `src/Http/routes.php` | Modificar (nueva ruta para el endpoint de distribución, si se decide separarlo de `store()`) |
| `tests/Feature/DistribucionConfeccionTest.php` (nombre tentativo) | Crear — los 8 casos de §4 |

`packages/Webkul/` no se toca en ningún punto de esta fase.

---

## 6. Riesgos conocidos que quedan abiertos después de la Fase 03A

- El Caso 6 (concurrencia) puede ser difícil de probar de forma
  determinística en el entorno de test actual; se documentará el
  resultado real alcanzado, no se asumirá cobertura completa sin
  verificarlo.
- La advertencia procedimental de anulación (Decisión 5) no tiene UI
  todavía — el cambio de backend por sí solo no comunica al usuario la
  limitación del Caso B (mercancía ya entregada físicamente). Queda
  como riesgo operativo hasta la Fase 03B.
- Ningún flujo de "retorno de taller" existe todavía, así que el Caso B
  de la Decisión 5 sigue sin poder resolverse técnicamente después de
  esta fase.

---

## 7. Resultado de implementación (2026-09-04)

### 7.1 Archivos creados

- `src/Database/Migrations/2026_09_04_000001_add_prioridad_to_bylopez_cc_ordenes_confeccion_detalles.php`
- `src/Enums/PrioridadOrdenConfeccion.php`
- `src/Http/Requests/DistribuirConfeccionRequest.php`
- `tests/Feature/DistribucionConfeccionTest.php` (11 tests)

### 7.2 Archivos modificados

- `src/Models/OrdenConfeccionDetalle.php` — `prioridad` agregado a `fillable` (sin cast nativo de Eloquent, mismo patrón que `estado` en `OrdenConfeccion`: valor string plano, enum PHP usado externamente para el dominio).
- `src/Services/DisponibilidadConfeccionService.php` — reescrito: agrupación únicamente por `product_id` (se eliminó `talla` de la clave y el método `clave()`); se excluyen del cálculo de "ya entregado" las `OrdenConfeccion` con `estado = 'anulada'`; `disponiblePara()` perdió el parámetro `$talla` (ya no participa).
- `src/Services/OrdenConfeccionService.php` — `create()` actualizado a la nueva firma de `disponiblePara()` y ahora también llama a `validarProductosSimples()`; se agregó `validarProductosSimples()` (protegido, reutilizado por `create()` y `distribuir()`) y el nuevo método público `distribuir()`.
- `src/Http/Controllers/Admin/OrdenConfeccionController.php` — nuevo método `distribuir(DistribuirConfeccionRequest $request)`.
- `src/Http/routes.php` — nueva ruta `POST distribuir` → `admin.corte_confeccion.ordenes_confeccion.distribuir`.
- `src/Config/acl.php` — la nueva ruta se agregó al mismo permiso ya existente `corte_confeccion.ordenes_confeccion.create` (no se creó un permiso nuevo).
- `src/Resources/lang/es/app.php` — nueva clave `errors.producto-configurable-no-valido` (el `en/app.php` de este paquete simplemente re-exporta el archivo `es`, así que quedó cubierto automáticamente).

### 7.3 Migration creada y ejecutada

`2026_09_04_000001_add_prioridad_to_bylopez_cc_ordenes_confeccion_detalles.php`:
aditiva, `ADD COLUMN prioridad VARCHAR(20) NOT NULL DEFAULT 'normal'`. Se
ejecutó contra la base de datos real del entorno (`php artisan migrate`)
porque los tests de este paquete usan `DatabaseTransactions` (no
`RefreshDatabase`): corren sobre el esquema ya migrado, no uno recreado
por test. Verificado con una consulta directa que las filas
`OrdenConfeccionDetalle` ya existentes (creadas en fases anteriores)
quedaron en `prioridad = 'normal'` sin ningún script de backfill manual.

### 7.4 Arquitectura final del endpoint/service

Se conservaron **dos métodos separados** en `OrdenConfeccionService`, tal
como pedía la sección "Endpoint" de la autorización:

- `create(array $data): OrdenConfeccion` — sin cambios de contrato (mismo
  input/output), solo internamente ahora también valida `product_id`
  simple y usa la nueva firma de `disponiblePara()`. Sigue siendo
  "un taller, una orden".
- `distribuir(array $data): Collection<OrdenConfeccion>` — nuevo. "N
  asignaciones (una por taller) → N órdenes", en una sola transacción.

Ambos comparten la lógica común real (`validarProductosSimples()`) sin
duplicarla, y cada uno tiene su propio Request de validación
(`StoreOrdenConfeccionRequest` sin cambios de contrato,
`DistribuirConfeccionRequest` nuevo). Endpoint HTTP nuevo y separado:
`POST /corte-confeccion/ordenes-confeccion/distribuir` →
`OrdenConfeccionController::distribuir()` → redirige a la vista de la
Orden de Corte de origen (no hay pantalla propia de resultado todavía,
por diseño: eso es Fase 03B).

### 7.5 Cambios de `DisponibilidadConfeccionService`

- Clave de agrupación: **solo `product_id`** (antes `product_id + talla`).
- Filtro nuevo: `->where('estado', '!=', EstadoOrdenConfeccion::Anulada->value)` sobre `ordenesConfeccion` antes de sumar `cantidad_entregada`.
- `disponiblePara(OrdenCorte $ordenCorte, ?int $productId): int` — se retiró el tercer parámetro `$talla` (antes `disponiblePara($ordenCorte, $productId, $talla)`); el único call site interno (`OrdenConfeccionService::create()`) se actualizó en el mismo commit lógico.
- Se eliminó el método privado `clave()` (ya sin uso).

### 7.6 Estrategia real utilizada para lock/concurrencia

Implementada tal como exigía la regla crítica, con una precisión técnica
adicional respecto al texto del diseño: dentro de `distribuir()`, la
**primera sentencia SQL de toda la transacción** es
`RecepcionCorte::where('orden_corte_id', $id)->lockForUpdate()->first()`
— antes incluso de `OrdenCorte::findOrFail()`. Esto importa porque bajo
`REPEATABLE READ` (nivel de aislamiento por defecto de InnoDB/MySQL), el
snapshot de lecturas *no bloqueantes* de una transacción se fija en la
**primera lectura no bloqueante**, no en el `BEGIN`; una lectura
bloqueante (`FOR UPDATE`) no cuenta como esa primera lectura y siempre
trae el dato más reciente confirmado. Por eso, si `OrdenCorte::findOrFail()`
(una lectura no bloqueante) se hubiera ejecutado *antes* del
`lockForUpdate()`, el snapshot de la transacción habría quedado fijado
demasiado pronto, y las lecturas posteriores (incluida
`DisponibilidadConfeccionService::porOrdenCorte()`) podrían no ver los
datos recién confirmados por la transacción que acaba de liberar el lock.
Poniendo el `lockForUpdate()` primero, se garantiza que **todo** lo que se
lee después —incluida la disponibilidad— ya refleja el estado posterior
al commit de cualquier operación anterior sobre la misma orden de corte.

Después del lock: recálculo de disponibilidad vía
`DisponibilidadConfeccionService::porOrdenCorte()` (ya corregido, §7.5),
validación de talleres (existencia + activo), validación de productos
simples (`validarProductosSimples()`), suma de cantidades solicitadas por
`product_id` a través de **todas** las asignaciones del payload contra el
disponible recalculado, y solo entonces creación de las N
`OrdenConfeccion` + sus detalles. Cualquier `ValidationException` en
cualquier punto revierte automáticamente todo el bloque por estar dentro
de un único `DB::transaction()`.

### 7.7 Tests ejecutados y resultado exacto

```
vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/DistribucionConfeccionTest.php
Tests:    11 passed (28 assertions)
Duration: 5.91s

vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"
Tests:    78 passed (205 assertions)
Duration: 34.13s

vendor/bin/pint --test packages/ByLopez/CorteConfeccion
result: passed
```

Los 11 tests del archivo nuevo, uno por escenario:

| Test | Caso obligatorio |
|---|---|
| prioridad normal por defecto | (soporte de Decisión 3) |
| anular devuelve cantidad al disponible | **Caso 5** |
| anulada no bloquea redistribuir la misma cantidad | (refuerzo de Caso 5) |
| product_id configurable rechazado, 0 órdenes | **Caso 7** |
| multi-taller 60/20 + variante M pendiente | **Casos 1 y 2** (en un solo test, mismo escenario) |
| exceso entre talleres (60+50 sobre 100) rechazado | **Caso 3** |
| rollback total si un taller es inactivo | **Caso 4** |
| prioridad por detalle conservada (urgente/normal) | **Caso 8** |
| endpoint HTTP crea N órdenes y redirige | soporte de arquitectura §7.4 |
| endpoint HTTP filtra filas de cantidad 0 | refuerzo de §5 del diseño |
| segunda distribución ve disponibilidad actualizada | **Caso 6 — SOLO parte funcional A** |

La suite completa del paquete (78 tests, incluyendo todos los archivos
preexistentes) pasó sin regresiones tras los cambios de
`DisponibilidadConfeccionService`/`OrdenConfeccionService`.

### 7.8 Caso 6 (concurrencia) — qué se probó y qué NO

**Se probó (Parte A — funcional):** que una segunda llamada a
`distribuir()`, ejecutada *después* de que la primera ya persistió sus
cambios, recalcula la disponibilidad en servidor y rechaza correctamente
una cantidad que ya no cabe (70 + 60 sobre 100 → la segunda falla,
quedan exactamente 70 distribuidas, nunca 130).

**NO se probó (Parte B — concurrencia real con bloqueo efectivo):**
`CorteConfeccionTestCase` usa `Illuminate\Foundation\Testing\DatabaseTransactions`,
es decir, cada test entero corre dentro de **una sola transacción externa
sin commit real**, sobre **una sola conexión** a la base de datos. Esto
hace imposible, dentro de este arnés de test, abrir una segunda conexión/
transacción genuinamente independiente que demuestre que `lockForUpdate()`
efectivamente bloquea a un segundo proceso mientras el primero no ha
confirmado — cualquier segunda "conexión" dentro del mismo test comparte
la transacción no confirmada del framework de test, o simplemente no
vería los datos de fixture (que tampoco están confirmados) si se abriera
una conexión realmente distinta. **No se afirma que el lock fue probado
bajo concurrencia real** — solo que la lógica de revalidación posterior
al lock es correcta cuando se ejecuta en el orden esperado. Verificar la
Parte B requeriría un test de integración fuera de Pest (por ejemplo, dos
procesos PHP reales contra la misma base de datos con datos ya
confirmados), que no se construyó en esta fase por quedar fuera de lo que
el arnés de test actual del proyecto permite sin cambiar su
infraestructura de testing — cambio que tampoco fue autorizado en esta
fase.

### 7.9 Diferencias entre diseño 03A y código implementado

1. **Orden exacto del lock más estricto que el texto del diseño:** el
   diseño decía "lockForUpdate() antes de recalcular disponibilidad"; la
   implementación además garantiza que sea la **primera sentencia SQL de
   la transacción**, sin ninguna lectura no bloqueante previa (ni siquiera
   `OrdenCorte::findOrFail()`) — ver §7.6. Es una precisión más estricta
   del mismo requisito, no una desviación.
2. **`validarProductosSimples()` también se aplicó a `create()`**, no
   solo a `distribuir()`. El diseño (`02_MODELO_DISTRIBUCION.md` §3.3
   punto 4) excluía explícitamente de esta fase extender la validación a
   `StoreOrdenCorteRequest`/`StoreRecepcionCorteRequest`, pero no decía
   nada sobre `create()` de `OrdenConfeccionService` — que escribe en la
   misma tabla `OrdenConfeccionDetalle` que `distribuir()`. Se aplicó ahí
   también por consistencia de integridad sobre la misma entidad, sin
   tocar los dos flujos que sí estaban explícitamente excluidos (corte y
   recepción). **[APROBADO en la revisión post-implementación]:** queda
   confirmado como decisión aceptada, no como riesgo abierto.
3. **Taller duplicado entre asignaciones — resuelto en §7.11:** el diseño
   (`02_MODELO_DISTRIBUCION.md` §18 caso límite 2) dejaba abierta la
   pregunta de si dos asignaciones del mismo payload apuntando al mismo
   `taller_id` deberían fusionarse o rechazarse. La primera entrega de
   esta fase no lo resolvía (permitía dos `OrdenConfeccion` para el mismo
   taller); en la revisión post-implementación se decidió **rechazar
   explícitamente** el duplicado (no fusionar, por la ambigüedad de
   `fecha_entrega`/`observacion` entre bloques) — ver §7.11 para el
   detalle de la corrección.
4. **`talla` no viaja en el payload de `distribuir()`**, tal como definía
   el diseño (§6): se sigue derivando del snapshot de producto al
   persistir, igual que hace `create()` hoy.
5. **Endpoint HTTP sin vista propia:** `distribuir()` en el controller
   redirige a la vista de la Orden de Corte (que ya existe), porque no
   hay pantalla nueva en esta fase (confirmado fuera de alcance).

### 7.10 Riesgos y pendientes detectados durante la implementación

- El riesgo del §7.9 punto 2 (aplicar la validación de producto simple
  también a `create()`) fue revisado y **aprobado** — ya no es un riesgo
  abierto (ver §7.11).
- El caso límite de talleres duplicados en el mismo payload (§7.9 punto
  3) fue revisado y **corregido** — ya no es un riesgo abierto (ver §7.11).
- La Parte B del Caso 6 (concurrencia real) permanece sin verificación
  automatizada, según lo documentado en §7.8 — es un riesgo conocido y
  declarado, no una omisión silenciosa. Queda pendiente de un test de
  integración fuera de Pest en una fase futura.
- La advertencia de UI al anular (Decisión 5) sigue pendiente para
  Fase 03B; hasta entonces, nada en pantalla evita que alguien anule una
  orden cuya mercancía ya salió físicamente hacia el taller.

### 7.11 Ajuste post-revisión (2026-09-04): `taller_id` único por operación

Antes de la aprobación final se revisó el caso límite del §7.9 punto 3 y
se decidió: **una operación de distribución nunca puede generar más de
una `OrdenConfeccion` para el mismo Taller.** No se fusionan las
asignaciones duplicadas (cada bloque puede traer `fecha_entrega`/
`observacion` distintas, y fusionar introduciría ambigüedad sobre cuál
prevalece) — en su lugar, se **rechaza toda la operación**.

**Cambios:**

- `DistribuirConfeccionRequest::rules()` — se agregó la regla `distinct`
  a `asignaciones.*.taller_id`, que compara cada valor contra sus
  hermanos del mismo array. Un `taller_id` repetido produce un error de
  validación estándar de Laravel (la request nunca llega al Service).
- `OrdenConfeccionService::distribuir()` — se agregó la misma validación
  como defensa en profundidad: se compara el conteo de `taller_id` antes
  y después de `unique()`; si difieren, `ValidationException` con la
  nueva clave `errors.taller-duplicado-en-distribucion`, dentro de la
  transacción (por lo tanto con rollback total garantizado igual que
  cualquier otra validación de este método). Esto mantiene el Service
  seguro incluso si se invoca directamente, sin pasar por el Request
  (como hacen los tests, y como podría hacer un futuro caller).
- `src/Resources/lang/es/app.php` — nueva clave
  `errors.taller-duplicado-en-distribucion`.

**Tests agregados** (`tests/Feature/DistribucionConfeccionTest.php`):
- Escenario exacto pedido: disponible L=60, M=40; payload con dos
  asignaciones al mismo Taller (Taller→L=60, Taller→M=40) llamando
  directamente a `OrdenConfeccionService::distribuir()` →
  `ValidationException`, 0 `OrdenConfeccion` creadas, disponible de L y M
  sin alterar.
- Mismo escenario a través del endpoint HTTP
  (`admin.corte_confeccion.ordenes_confeccion.distribuir`) → la request
  falla la validación (`assertSessionHasErrors()`) antes de tocar el
  Service, 0 órdenes creadas.

**Resultado de la re-ejecución tras el ajuste:**

```
vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/DistribucionConfeccionTest.php
Tests:    13 passed (34 assertions)

vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"
Tests:    80 passed (211 assertions)

vendor/bin/pint --test packages/ByLopez/CorteConfeccion
result: passed
```

Sin regresiones. `packages/Webkul/` sin cambios en este ajuste (verificado).
