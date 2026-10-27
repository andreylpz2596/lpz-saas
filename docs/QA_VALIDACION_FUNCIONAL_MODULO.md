# QA_VALIDACION_FUNCIONAL_MODULO.md — Validación funcional integral del módulo Corte y Confección

## 0. Estado

**COMPLETADA / PENDIENTE DE REVISIÓN.** Fecha: 2026-09-08. Fase exclusivamente
de QA: reproducir, medir, documentar, clasificar. **No se corrigió ningún
hallazgo en esta fase** — ver §7/§20 para la lista completa con causa
probable, severidad y propuesta. `packages/Webkul/` sin cambios
(confirmado con `git status`). No se modificó ningún archivo de
producción del paquete `packages/ByLopez/CorteConfeccion/` durante esta
validación. El estado **APROBADA** de 03A-03C3 (distribución) **no se
modifica** — esta es una validación horizontal del resto del módulo, no
una reapertura de esas fases.

**Seguimiento (2026-09-08):** los hallazgos H-CRÍTICO-1, H-ALTO-2 y
H-MEDIO-3 (parcial) fueron corregidos en la fase **SEC-01_PERMISOS_OPERATIVOS**
— ver `docs/confeccion/STATUS.md` para el reporte completo de esa
corrección (snapshot antes/después de permisos, defensa en profundidad
`DistribucionConfeccionGate`, 10 tests de regresión nuevos). §20 de este
documento se actualizó para reflejar el estado RESUELTO/MITIGADO de cada
hallazgo.

Metodología: lectura estática del código (agente de exploración
dedicado, resultados incorporados abajo), ejecución real de flujos de
negocio vía `tinker` contra la base de datos real de desarrollo,
peticiones HTTP reales (login real + cookies reales) contra
`php artisan serve`, y navegador real (patchright) para la pantalla de
distribución. Todos los fixtures de QA (`QAINT*`, admins `qa-int.test`,
roles temporales `CORTADOR`) se crearon y **se eliminaron al terminar**
— confirmado en 0 (ver §8.4). Ningún dato real preexistente fue
modificado o eliminado, salvo 2 operaciones de solo-permiso sobre un rol
`CORTADOR` temporal creado por esta misma sesión de QA (nunca sobre
`BODEGA`/`TALLER CONFECCION`/`Administrador`, que son roles reales
preexistentes y se dejaron exactamente como estaban).

---

## 1. Estado general

### 1.1 Suite de tests

```
vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"
Tests:    118 passed (371 assertions)
Duration: ~43s

vendor/bin/pint --test packages/ByLopez/CorteConfeccion
{"tool":"pint","result":"passed"}
```

### 1.2 Estructura (migrations, rutas, ACL, Models, Services, Requests, Views)

Auditoría estática completa (agente dedicado, solo lectura). Resumen:

- **19 migrations** — esquema consistente; ver hallazgos H1-H4 en §7
  (tipos de columna inconsistentes, columnas legacy convivientes con las
  nuevas, sin impacto funcional detectado).
- **30 rutas** en `Http/routes.php`, las 30 cubiertas por una entrada en
  `Config/acl.php` — **ninguna ruta queda sin mapeo ACL** (fail-closed
  correcto para roles `permission_type=custom`).
- **Menú vs ACL**: las 7 claves de `Config/menu.php` tienen su
  contraparte exacta en `acl.php` — sin claves huérfanas.
- **12 Models**, todas las relaciones apuntan a un modelo existente;
  2 inconsistencias estructurales menores (`OrdenCorteRollo` y
  `ProveedorTelaReferencia` no implementan un `Contracts\*` como sus
  hermanos) — ver H5.
- **11 Services**, cada uno con responsabilidad única y clara (sin
  solapamiento funcional detectado).
- **15 FormRequests** — `authorize()` consistente con `acl.php`; 3 de
  ellas (`StoreOrdenCorteRequest`, `StoreRecepcionCorteRequest`) además
  aplican una segunda capa de autorización de negocio vía
  `CantidadCortadaGate` (ver §2).
- **Views**: 8 subcarpetas, estructura completa; `ordenes-confeccion/`
  no tiene vista `edit` (correcto — el controller no expone esa acción).

---

## 2. Matriz de roles

Metodología: la ruta HTTP protegida por `Webkul\User\Http\Middleware\Bouncer`
solo permite acceso si `permission_type='all'` (bypass total) o si la
clave ACL de la ruta está en `role.permissions`. Esto se validó de dos
formas independientes y coincidentes: (a) análisis determinístico usando
exactamente `bouncer()->hasPermission()` (la función real de producción)
contra los **permisos reales ya configurados** de `BODEGA` (id 4) y
`TALLER CONFECCION` (id 52) — roles reales preexistentes, no tocados; y
(b) peticiones HTTP reales aisladas (login real + cookies) confirmando
2-3 casos clave, incluido el hallazgo crítico de TALLER CONFECCION (ver
§7, H-CRÍTICO-1). Para CORTADOR, dado que **no existe ningún rol
persistente con ese nombre** en esta base de datos, se creó uno
temporal con un permiso mínimo razonable para la prueba (eliminado al
terminar).

Además de la ACL de rutas, ciertas acciones tienen una **segunda capa de
autorización de negocio** vía `CantidadCortadaGate` (independiente de
la ACL, basada en el nombre exacto del rol) — marcada explícitamente
donde aplica.

| Acción | Admin | Cortador | Bodega | Taller Confección |
|---|---|---|---|---|
| Ver/gestionar Proveedores de Tela | PASS | FAIL (401) | PASS | FAIL (401) |
| Ver/gestionar Compras de Tela | PASS | FAIL (401) | PASS | FAIL (401) |
| Ver/gestionar Rollos | PASS | FAIL (401) | PASS | FAIL (401) |
| Ver Órdenes de Corte (index/show) | PASS | PASS | PASS | FAIL (401) |
| Crear solicitud de Orden de Corte | PASS | FAIL (401 ACL **+** bloqueado por `CantidadCortadaGate::userCanGestionarSolicitud`) | PASS | FAIL (401) |
| Operar Orden de Corte (entregar/en_corte/reportar/cerrar) | PASS | PASS (reportar) | PASS | FAIL (401) |
| Confirmar Recepción Bodega | PASS | FAIL (bloqueado por `CantidadCortadaGate::userCanConfirmarRecepcion`, aunque careciera de ACL igual) | PASS | ACL PASS pero **bloqueado por el gate de negocio** (ver H-ALTO-2) |
| Editar cantidad_cortada tras reportar | PASS | PASS (`userCanEdit`) | PASS | NO APLICA |
| Ver Talleres / Crear-Editar Talleres | PASS | FAIL (401) | FAIL (401) | PASS |
| Ver Órdenes de Confección (index/show) | PASS | FAIL (401) | FAIL (401) | PASS |
| Crear/Distribuir Confección (`create`/`contexto`/`distribuir`) | PASS | FAIL (401) | FAIL (401) | **PASS — ver H-CRÍTICO-1** |
| Anular Orden de Confección | PASS | FAIL (401) | FAIL (401) | PASS |

Ningún rol pudo acceder a una acción que la ACL le niega explícitamente
— la enforcement en sí **funciona correctamente**. El hallazgo real no
es un bug de código: es que la **configuración actual** del rol real
`TALLER CONFECCION` le concede el permiso `corte_confeccion.ordenes_confeccion.create`
(el mismo que protege `create`/`store`/`contexto`/`distribuir`), lo cual
no coincide con la expectativa de negocio de que un Taller no debería
poder distribuir mercancía a otros Talleres (ver H-CRÍTICO-1 en §7).

> **Actualización (SEC-01, 2026-09-08):** esta matriz refleja el estado
> encontrado en la validación original. Los permisos de `BODEGA` y
> `TALLER CONFECCION` fueron corregidos en la fase SEC-01 — ver
> `STATUS.md` → entrada `SEC-01_PERMISOS_OPERATIVOS` para la matriz
> objetivo, el snapshot antes/después completo, y la matriz de roles
> **resultante** tras la corrección.

---

## 3. Proveedor de Tela

Flujo real ejecutado: crear (nombre, NIT, contacto, 2 tipos de tela, 2
referencias con gramaje obligatorio/valor_kilo opcional) → editar
(cambiar contacto/dirección, actualizar `valor_kilo_referencia` de una
referencia existente, agregar una tercera referencia nueva).

- **Resultado: PASS.** Persistencia confirmada tras editar: la
  referencia editada mantuvo su cambio (`valor_kilo_referencia`
  20000→21000), la referencia **no incluida** en el payload de edición
  se conservó intacta (comportamiento aditivo, nunca se borra lo
  omitido — documentado como diseño, no defecto), y la nueva referencia
  se agregó correctamente (2 → 3 referencias).
- `gramaje` obligatorio y `valor_kilo_referencia` opcional (`null`)
  ambos se comportaron como se esperaba.

---

## 4. Compra de Tela

Flujo real ejecutado: Proveedor → Tipo de tela → Referencia → 2 Rollos
→ editar la compra agregando un tercer rollo.

- **Resultado: PASS.** Los 2 rollos originales se conservaron tras
  editar, se agregó un tercer rollo (60kg + 40kg + 30kg = 3 rollos
  totales), y `proveedor_id` se mantuvo sin cambios tras la edición.
- **Hallazgo de código encontrado durante esta prueba** (H-BAJO-1, ver
  §7): un PHP warning real (`Undefined array key "tipo_tela"`) en
  `CompraTelaService::datosRollo()` línea 178. Investigado a fondo: la
  causa fue un **fixture de QA propio mal construido** (al reconstruir
  el payload de edición leí `$r->referencia_tela_id`, un nombre de
  atributo que no existe en el modelo `Rollo` — la columna real es
  `proveedor_tela_referencia_id` — por lo que el warning es 100%
  atribuible al fixture, no al código de producción). Aun así, el
  patrón de código (`$referenciaTela?->tipoTela?->admin_name ??
  $rolloData['tipo_tela']`) queda documentado como mejora de bajo
  riesgo: el fallback no debería depender de una clave que el flujo
  real de la UI nunca envía.

---

## 5. Orden de Corte — flujo completo de estados

Ejecutado end-to-end contra la BD real, con `Auth::guard('admin')`
cambiando de usuario en cada paso para respetar la separación de roles
real (Creador → Cortador real → Bodega real):

| Transición | Estado resultante | Resultado |
|---|---|---|
| `create()` | `pendiente_entrega` | PASS |
| `entregar()` | `entregado_a_cortador` | PASS |
| `cambiarEstado(...)` | `en_corte` | PASS |
| `reportarResultado()` (como Cortador real) | `reportado_por_cortador` | PASS |
| `RecepcionCorteService::create()` (como Bodega real) | `recibido_bodega` | PASS |
| `cerrar()` | `cerrado` | PASS |

Los 6 estados se alcanzaron sin errores, con datos reales (cantidades,
rollos, producto/variantes) persistidos correctamente en cada paso.
Vista `show` (HTTP real) verificada en el estado final `cerrado`: 200
OK, datos históricos visibles.

`OrdenCorteVistaEstadosTest.php` (4 tests ya existentes) **se mantuvo
sin cambios** y sigue pasando dentro de la suite completa (118/118). El
antiguo ID 1209 permanece clasificado **NO REPRODUCIBLE / fixture
temporal eliminado** — sin cambios en esta fase.

---

## 6. Cortador (rol)

Validado con un rol `CORTADOR` real (nombre exacto, `permission_type=custom`,
permisos mínimos: `corte_confeccion`, `corte_confeccion.ordenes_corte`,
`corte_confeccion.ordenes_corte.operar`):

- Puede consultar/operar Órdenes de Corte (`index`, `reportar`,
  `entregar`, `cerrar` según el flujo) — **PASS**.
- **No puede** crear la solicitud de corte — bloqueado en dos capas
  independientes: ACL (401, carece de `...ordenes_corte.create`) **y**
  `CantidadCortadaGate::userCanGestionarSolicitud()` (`false`) — **PASS
  (restricción confirmada)**.
- **No puede** confirmar la recepción de Bodega — mismo doble bloqueo
  (401 ACL + `userCanConfirmarRecepcion()` = `false`) — **PASS**.
- **No puede** distribuir a Taller — 401 ACL (`corte_confeccion.ordenes_confeccion.create`
  ausente) — **PASS**.
- **Puede** editar `cantidad_cortada` ya reportada (`userCanEdit()` =
  `true`, por diseño: el propio Cortador puede corregir su reporte) —
  **PASS**.

**Nota metodológica importante** (no es un hallazgo de producción):
durante esta prueba se detectó que mi primer rol de prueba `CORTADOR`
tenía `permission_type='all'` (heredado de un fixture de una fase
anterior, creado solo para satisfacer la validación de "el cortador_id
debe tener rol Cortador" en `StoreOrdenCorteRequest`). Con
`permission_type='all'`, **todas** las restricciones de negocio de
`CantidadCortadaGate` se saltan (por diseño: esa clase trata
`permission_type='all'` como equivalente a "admin"). Al corregir el rol
de prueba a `permission_type='custom'` con permisos realistas, las
restricciones se comportaron exactamente como está documentado. Esto
confirma que `CantidadCortadaGate` funciona correctamente **siempre
que el rol se configure de forma realista** — y sirve de advertencia
operativa: si alguna vez se crea un rol real "Cortador" con
`permission_type='all'` por error, perdería todas sus restricciones de
negocio silenciosamente (ver H-MEDIO-3 en §7).

---

## 7. Recepción Bodega

Validado con datos reales:

- **Caso A**: 100 recibidas, 0 defectuosas → **100 aptas**. PASS.
- **Caso B**: 80 recibidas, 5 defectuosas → **75 aptas**. PASS.
- Persistencia confirmada en `RecepcionCorteDetalle` y reflejada
  correctamente por `DisponibilidadConfeccionService`.
- Confirmado: `RecepcionCorte` sigue representando exclusivamente
  **Cortador → Bodega** — no existe ningún campo, relación ni lógica
  que la confunda con una recepción del lado del Taller (that flujo no
  existe en el módulo, correctamente fuera de alcance, ver §16).

---

## 8. Disponibilidad de Confección

Validado matemáticamente con datos reales, cubriendo los 6 escenarios
pedidos:

| Escenario | Resultado |
|---|---|
| `apta = recibida - defectuosa` | PASS (100-0=100; 80-5=75) |
| Sin distribución | `disponible = apta` — PASS |
| Distribución parcial | `disponible = apta - entregado` — PASS (100-80=20 tras distribuir 60+20) |
| Múltiples Talleres | Suma correcta entre 2 Talleres (60 a Taller 1, 20+40 a Taller 4) — PASS |
| Orden anulada | Anular devuelve la cantidad al disponible (20→80 tras anular 60) — PASS |
| Intento de exceder disponibilidad | Rechazado con `ValidationException` (`"La cantidad entregada supera la cantidad disponible..."`), disponible permanece intacto (80, no se corrompió) — PASS |

---

## 9. Distribución UI end-to-end (obligatoria, real, en navegador)

Ejecutada en navegador real (patchright), login real, contra
`php artisan serve` y la BD real, con el escenario **exacto** pedido:

- Producto L (disponible 100): 60 → Taller 1 (urgente), 20 → Taller 4
  (alta), via el drawer "Dividir entre talleres".
- Producto M (disponible 80): 40 → Taller 4 (normal), fila simple.
- Pendiente esperado: L=20, M=40.

Flujo real: EDITAR → configurar cantidades/Talleres/prioridades →
dividir L → REVISAR → verificar resumen → "Generar 2 órdenes de
confección" → éxito → redirect real a la Orden de Corte.

**Verificado físicamente en BD** (no solo por la UI):

```
OrdenConfeccion count = 2
OF-000003  taller=978 (Taller 1)  fecha=2026-09-10  obs="QA distribucion"
  product=L  cantidad=60  prioridad=urgente
OF-000004  taller=979 (Taller 4)  fecha=2026-09-12  obs="QA distribucion"
  product=L  cantidad=20  prioridad=alta
  product=M  cantidad=40  prioridad=normal

disponible restante: L=20, M=40
```

**Resultado: PASS exacto**, cero desviación respecto al escenario
pedido. 0 errores de consola durante todo el flujo.

---

## 10. Fechas y observación

Validado dentro del mismo escenario del §9:

- Fecha predeterminada `10/09/2026` aplicada correctamente al Taller 1
  (sin override).
- Override de Taller 4 a `12/09/2026` — visible en EDITAR
  (`[personalizada]` / "Usar predeterminada"), visible en REVISAR
  ("Entrega: 2026-09-12"), y **persistido exactamente así en BD**
  (`fecha_entrega = 2026-09-12` en `OF-000004`, `2026-09-10` en
  `OF-000003`).
- Observación general `"QA distribución"` replicada **idéntica** en
  ambas `OrdenConfeccion` generadas — confirmado en BD.

**Resultado: PASS exacto.**

---

## 11. Anulación

Ejecutada sobre una `OrdenConfeccion` real del escenario de distribución:

```
antes de distribuir:      disponible L = 100
después de distribuir:    disponible L = 20   (100 - 60 - 20)
después de anular OF-000003 (60 unidades, Taller 1): disponible L = 80  (20 + 60)
```

- Estado tras anular: `anulada` — PASS.
- La orden anulada **sigue existiendo y visible** en BD y en la vista
  `show` real (HTTP 200, "Estado: Anulada") — trazabilidad conservada,
  no se eliminó nada — PASS.
- No se implementó (ni se intentó implementar) ningún retorno físico
  del Taller — correctamente fuera de alcance de esta fase.

---

## 12. Variante / Stock Bagisto

Validado con **datos reales del catálogo** (no solo fixtures QA),
además de la suite automatizada ya existente (`VarianteConsultaServiceTest.php`,
15 tests, todos dentro de los 118 passing de §1):

- Adulto: SKU/color/talla resueltos correctamente (ej. `201DURZ-H-S`,
  color "Lila", talla "S", stock 54).
- Infantil: `talla_etiqueta` correctamente distinta ("grupo de edad
  (niños)"), rangos de edad reales (2-4, 6-8, 10-12) — nunca rotulado
  como "Talla".
- Stock > 0 confirmado con datos reales; stock = 0 y stock = null ya
  cubiertos por la suite automatizada existente (Casos 5/6).
- Múltiples `product_id` en una sola llamada: sin duplicados, número de
  queries SQL constante (Caso 4 de la suite, ya validado).
- Confirmado: la fuente de stock vendible sigue siendo
  `product_inventory_indices.qty`. No se consultó ninguna tabla Webkul
  fuera de `VarianteConsultaService`. No se modificó inventario.

**Resultado: PASS.**

---

## 13. Talleres

Validado modelo + vista actual (real, HTTP + BD):

- Crear/editar/activo/inactivo/contacto/usuario asociado: todos los
  campos de `$fillable` presentes y funcionales (`nombre, nit, contacto,
  telefono, direccion, estado, observacion, usuario_id`).
- Vista `show` real (HTTP 200) muestra correctamente nombre, estado,
  órdenes de confección relacionadas, y el enlace a "Crear Confección".
- **Lo que ve hoy un usuario TALLER CONFECCION**: acceso a `talleres.index/create/edit/show`,
  `ordenes_confeccion.index/show/create/contexto/distribuir/cancel`, y
  `recepciones_corte.index` (ver H-CRÍTICO-1 y H-ALTO-2 en §7) —
  **ningún acceso** a Compras/Proveedores/Rollos/Órdenes de Corte.
- **Lo que falta para una experiencia operativa real** (documentado,
  no implementado — fuera de alcance de esta fase):
  - Sin scoping por Taller: `OrdenConfeccionController::index()`/`show()`
    no filtran por el `Taller` vinculado al usuario autenticado
    (`usuario_id`) — un usuario Taller Confección, si tuviera el
    permiso correspondiente, vería **todas** las órdenes de **todos**
    los Talleres, no solo las propias.
  - Sin ciclo de vida del lado de Confección: el único cambio de estado
    disponible es `cancel()`. No existe "recibido por Taller",
    "en confección", "terminado" — ya documentado como NO IMPLEMENTADO
    en `STATUS.md`, confirmado aquí a nivel de código real.
  - Sin vista de autoservicio: `TallerController` no filtra por
    `auth()->user()->id` — cualquier Taller con acceso a `show`/`edit`
    puede ver/editar cualquier registro de Taller por id, no solo el
    propio.

---

## 14. OrdenConfeccion

Validado index/show/código/Corte origen/Taller/detalles/cantidades/
prioridades/fecha/observación/estado/anulación/históricos — todos
presentes y correctos en la vista real (HTTP 200, datos reales del
escenario de los §9-§11). No se encontraron datos legacy de
`OrdenConfeccion` en esta base de datos previos a Fase 03A (la tabla
existe desde 2026-08-29, después de la arquitectura actual) — por
tanto no aplica la comparación legacy-vs-actual para este modelo
específico.

---

## 15. ACL específico de Confección

Ver matriz completa en §2. Resultado consolidado:

- `index`, `create`, `contexto` (JSON), `distribuir` (POST), `show`,
  `cancel` de `OrdenConfeccion`: la enforcement ACL **funciona
  correctamente** en el código — cada ruta exige exactamente la clave
  documentada en `acl.php`, sin excepciones ni bypass de código.
- **Especial atención confirmada**: TALLER CONFECCION (rol real,
  preexistente, id 52) **sí puede** hoy distribuir mercancía a otros
  Talleres, porque su configuración de permisos actual incluye
  `corte_confeccion.ordenes_confeccion.create` — no existe ninguna
  regla explícita en el código que lo permita a propósito; es
  simplemente el resultado de cómo está configurado el rol
  actualmente. Confirmado con petición HTTP real (login real como ese
  rol → 200 OK sobre la pantalla de distribución). Ver H-CRÍTICO-1.

---

## 16. Integridad BD (solo consulta)

```
OrdenConfeccion sin Taller:                        0
OrdenConfeccionDetalle huérfanos:                   0
RecepcionCorteDetalle huérfanos:                    0
cantidad_entregada negativa:                        0
prioridad fuera de normal|alta|urgente:              0
cantidad_recibida / cantidad_defectuosa negativa:    0
defectuosa > recibida:                              0
product_id configurable en detalles de confección:  0
OrdenCorte sin ningún OrdenCorteRollo:               1  → DATOS LEGACY
```

El único registro con hallazgo (`OrdenCorte` id=1, código `0001`,
`created_at=2026-05-07`) es la primera orden de corte de todo el
sistema, creada **antes** de que existiera el sistema de múltiples
rollos por orden (migración de 2026-06-14) — usa la columna legacy
`rollo_id` directamente. Clasificado **DATOS LEGACY**, no bug actual.
No se corrigió ni se tocó la base de datos.

---

## 17. Vistas / Smoke

Abiertas con navegador real y/o HTTP real: Proveedores, Compras de
Tela, Orden de Corte (index/show), Recepción, Taller (index/show),
OrdenConfección (index/show), pantalla de distribución
(create/review). Todas devolvieron 200 con contenido correcto. **0
errores de consola inesperados** en ninguna de las páginas visitadas.
No se realizó ningún rediseño visual.

---

## 18. Logs

`storage/logs/laravel.log` revisado filtrando por la fecha de esta
ejecución (2026-09-08): **0 entradas** — ninguna excepción quedó
registrada durante toda la validación (las operaciones de negocio que
se esperaba que fallaran, como el intento de exceder disponibilidad,
lanzan `ValidationException` que se captura en el propio flujo de
prueba y nunca llega a escribirse como excepción no controlada en el
log).

---

## 19. Funcionalidades NO IMPLEMENTADA / PLANIFICADA (no son FAIL)

- En confección / Terminada / Recibida en Bodega (estados posteriores a
  "activa" en `OrdenConfeccion`).
- Retorno físico del Taller.
- Pagos a Talleres.
- KPIs.
- Portal operativo completo del Taller (autoservicio, scoping por
  Taller propio — ver §13).
- Entrada automática a inventario de producto terminado.

Ninguna de estas se evaluó como FAIL — todas están correctamente fuera
del alcance actual, consistente con `STATUS.md`.

---

## 20. Matriz final de hallazgos

| # | Área | Estado | Evidencia | Severidad |
|---|---|---|---|---|
| H-CRÍTICO-1 | ACL / Roles | **RESUELTO (SEC-01, 2026-09-08)** | §2, §15 — rol real `TALLER CONFECCION` (id 52) ya no tiene `corte_confeccion.ordenes_confeccion.create`; además se agregó defensa en profundidad (`DistribucionConfeccionGate`) independiente de la ACL. Ver `STATUS.md` → entrada `SEC-01_PERMISOS_OPERATIVOS` para el detalle completo | **CRÍTICO** |
| H-ALTO-2 | ACL / Roles | **RESUELTO (SEC-01, 2026-09-08)** | §2, §13 — rol real `TALLER CONFECCION` ya no tiene `corte_confeccion.recepciones_corte`. `CantidadCortadaGate::userCanConfirmarRecepcion()` seguía bloqueando la mutación real incluso antes de este cambio (defensa en profundidad ya existente, ahora reforzada con test de regresión explícito) | **ALTO** |
| H-MEDIO-3 | Código / Negocio | **MITIGADO (SEC-01, 2026-09-08)** — riesgo original de `CantidadCortadaGate` no modificado (fuera de alcance de SEC-01), pero la nueva `DistribucionConfeccionGate` (que protege específicamente la distribución) fue diseñada desde el inicio para NO depender de `permission_type='all'`, y se demostró con test que un rol `TALLER CONFECCION`/`CORTADOR` con `permission_type='all'` sigue bloqueado para distribuir | §6 — `CantidadCortadaGate::evaluar()` conserva su comportamiento original (fuera del alcance de SEC-01, que se limitó a permisos operativos de distribución); el riesgo documentado sigue siendo válido específicamente para `userCanConfirmarRecepcion()`/`userCanGestionarSolicitud()` si un rol Cortador/Bodega real se configurara alguna vez como `permission_type='all'` | **MEDIO (parcial)** |
| H-BAJO-1 | Código | Warning PHP reproducido (causa: fixture propio, no producción) | §4 — `CompraTelaService::datosRollo()` línea 178, fallback a `$rolloData['tipo_tela']` sin `?? null` | **BAJO** |
| H5 | Estructura | Inconsistencia estructural | Auditoría §1.2 — `OrdenCorteRollo` y `ProveedorTelaReferencia` no implementan `Contracts\*` como sus modelos hermanos | **BAJO** |
| H1-H4 | Migrations | Inconsistencias de tipo/estilo | Auditoría §1.2 — `cortador_id` sin FK real, `RecepcionCorteDetalle` sin `$casts`, `Taller` sin `$casts`, asimetría `restrictOnDelete`/`cascadeOnDelete` entre `ordenes_confeccion`/`ordenes_corte` | **BAJO** |
| — | Todo lo demás (§1, §3-§14, §16-§19) | **PASS** | Ver secciones correspondientes | — |

### Causa probable / archivo:línea / test propuesto / propuesta de corrección (solo para severidad MEDIO o superior)

**H-CRÍTICO-1**
- Causa probable: configuración de permisos del rol, no un defecto de
  código — probablemente se marcó la casilla "Crear" del módulo
  "Órdenes de Confección" al configurar el rol `TALLER CONFECCION`,
  sin darse cuenta de que esa misma clave protege también `distribuir`.
- Archivo/línea: no aplica (dato de configuración: tabla `roles`,
  columna `permissions`, fila id=52).
- Test que debería cubrirlo: un test de regresión que falle si
  `corte_confeccion.ordenes_confeccion.create` aparece en los permisos
  de cualquier rol cuyo nombre normalizado sea `taller confeccion` (o,
  más robusto, un test que documente explícitamente qué claves ACL se
  espera que un Taller nunca tenga).
- Propuesta de corrección (pendiente de autorización, **no
  implementada**): (a) opción mínima, ajustar la configuración del rol
  real quitándole `corte_confeccion.ordenes_confeccion.create` desde el
  panel de administración de roles (cambio de datos, no de código); (b)
  opción estructural, dividir la clave ACL actual en dos (`...create`
  para crear/editar una única `OrdenConfeccion` vs. una clave nueva
  específica para `distribuir`/`contexto`), permitiendo conceder una sin
  la otra — esto sí requeriría tocar `acl.php` y las rutas.

**H-ALTO-2**
- Causa probable: `recepciones_corte` nunca se dividió en
  `index`/`create` como los demás módulos (el propio audit lo señala
  como "clave plana, sin split") — al conceder el permiso base a
  `TALLER CONFECCION` (posiblemente para que pudiera *ver* algo
  relacionado con recepciones), automáticamente also permitió alcanzar
  la ruta `store`.
- Archivo/línea: `Config/acl.php`, entrada `corte_confeccion.recepciones_corte`.
- Test propuesto: test HTTP que confirme que un rol con
  `corte_confeccion.recepciones_corte` pero sin ser admin/bodega recibe
  `ValidationException` al intentar `POST recepciones-corte/create/{id}`
  (ya cubierto indirectamente por `userCanConfirmarRecepcion` = false,
  pero no existe hoy un test HTTP explícito para este camino).
- Propuesta de corrección: igual que H-CRÍTICO-1 opción (a) — revisar
  si `TALLER CONFECCION` realmente necesita ver `recepciones_corte`; si
  no, quitarle el permiso.

**H-MEDIO-3**
- Causa probable: diseño consciente de `CantidadCortadaGate::evaluar()`
  (tratar `permission_type='all'` como "es admin" para simplificar la
  lógica), pero sin una guarda adicional que además exija que el
  *nombre* del rol sea literalmente "admin"/"administrador" cuando se
  usa para roles operativos como Cortador/Bodega.
- Archivo/línea: `src/Support/CantidadCortadaGate.php:97`
  (`$isAdmin = $role === 'admin' || $user->role->permission_type === 'all';`).
- Test propuesto: test unitario que cree un Admin con rol nombrado
  "CORTADOR" y `permission_type='all'`, y confirme que
  `userCanConfirmarRecepcion()`/`userCanGestionarSolicitud()` devuelven
  `false` — hoy fallaría (devuelven `true`), documentando el riesgo.
- Propuesta de corrección (no implementada): decisión de producto
  pendiente — ¿debe existir alguna vez un rol operativo (Cortador/Taller)
  con `permission_type='all'`? Si la respuesta es "nunca, por
  convención", no se requiere cambio de código, solo disciplina
  operativa al crear roles. Si se quiere una garantía a nivel de
  código, habría que separar el concepto "es super-admin" del concepto
  "tiene todos los permisos ACL".

---

## 21. Fixtures eliminados

Confirmado en 0 tras la limpieza: Admins (`%qa-int.test%`), Talleres
(`%QAINT%`), Productos (`QAINT%`), Proveedores (`Proveedor QAINT%`),
Rollos (`R-QAINT%`, `R-REPRO%`), Compras (`CT-QAINT%`), Órdenes de Corte
(ids 2022, 2023) y sus Recepciones/OrdenConfección relacionadas, y los
roles temporales `CORTADOR`, `CORTADOR QA REALISTA`, `BODEGA QAINT`,
`BODEGA QAINT2`. Los roles reales `Administrador` (id 1), `BODEGA` (id
4) y `TALLER CONFECCION` (id 52) permanecen exactamente como estaban
antes de esta validación (mismos permisos, mismo `permission_type`).
Servidor temporal de QA detenido. `.env` restaurado (el ajuste temporal
`DEBUGBAR_ENABLED=false`, necesario para que el navegador pudiera
interactuar con el drawer de división sin que la barra de Debugbar
tapara el botón, se revirtió al finalizar).
