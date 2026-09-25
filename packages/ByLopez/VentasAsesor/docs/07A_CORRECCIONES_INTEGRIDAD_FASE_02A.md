# 07A_CORRECCIONES_INTEGRIDAD_FASE_02A.md

**Fase 02A1 — Corrección de integridad, idempotencia e historial de atribución.**
**Fase 02A2 — Cierre auditado antes de integrar pedidos** (sección 19, 2026-09-19).

**Fecha:** 2026-09-15 · **Actualizado:** 2026-09-19
**Estado:** **COMPLETADAS.** Fases exclusivamente correctivas sobre lo entregado en la 02A.
**No se inició la Fase 02B:** sigue sin haber listeners, integración con pedidos reales,
reconciliación, backfill, rutas, controladores ni vistas.

Referencias: `[A-§n]` → `01`; `[M-§n]` → `02`; `[T-§n]` → `05`; `[C-§n]` → `07`.
Este documento se cita como `[C1-§n]`.

---

## 1. Qué encontró la revisión y qué se hizo

Cada hallazgo se verificó leyendo el código real antes de tocarlo.

| # | Hallazgo confirmado en el código | Corrección |
|---|---|---|
| 1 | `orders → atribución` y `atribución → historial` con `cascadeOnDelete()` | Ambas pasan a **RESTRICT** (§2) |
| 2 | `advisor_id`, `created_by_admin_id` y `assigned_by_admin_id` con `nullOnDelete()`, lo que producía `advisor` + `advisor_id = null` | Se **retiran esas claves foráneas**; el id histórico queda sin FK junto al snapshot (§3) |
| 3 | `RegistrarAtribucionService` devolvía la fila existente **sin comparar** el contexto | Idempotencia real: idéntico devuelve, distinto **lanza conflicto** (§4) |
| 4 | La atribución inicial no generaba historial | Toda atribución nueva escribe su evento `initial_assignment` en la misma transacción (§5) |
| 5 | El historial se decía inmutable pero admitía `update()`, `delete()` y `save()` | Inmutabilidad real en el modelo y escritura reservada al servicio (§6) |
| 6 | `AsignacionAsesorService` calculaba el estado anterior sobre un modelo posiblemente obsoleto | `lockForUpdate()` dentro de la transacción (§7) |
| 7 | `is_excluded`, `exclusion_reason` y dos `esComisionable()` | Retirados: lógica de comisión prematura (§8) |
| 8 | `EstadoAtribucion::FALLO_TECNICO` no era alcanzable por ningún camino | Camino explícito, validado e idempotente (§9) |
| 9 | El historial solo guardaba cambio de asesor y de propiedad | Se amplía con tipo de operación y contexto comercial (§10) |
| 10 | `LogAsignacionAsesorRepository` exponía una API genérica con `update()` y `delete()` sobre auditoría | **Eliminado** (§6) |

---

## 2. Cascadas retiradas

```text
antes:  orders → atribución: CASCADE        atribución → historial: CASCADE
ahora:  orders → atribución: RESTRICT       atribución → historial: RESTRICT
```

Verificado en `lpz_saas_testing` sobre `information_schema.REFERENTIAL_CONSTRAINTS`:
las dos únicas claves foráneas del módulo son `bvaoa_order_fk` (a `orders`) y
`bvaal_attribution_fk` (a la atribución), ambas con `DELETE_RULE = RESTRICT`.

Consecuencias, todas cubiertas por prueba:

- borrar un pedido atribuido falla y **no** elimina atribución ni historial;
- borrar una atribución con historial falla en la base de datos;
- el modelo `AtribucionPedido` además rechaza `delete()` con una excepción propia, para
  que el error sea claro y no dependa de que existan filas de historial;
- el `down()` de las migraciones sigue retirando las tablas propias en orden inverso.

No se conserva una auditoría simulada: sobrevive la fila completa, no solo `order_id`.

---

## 3. Administradores históricos

**Decisión aplicada: conservar el identificador sin clave foránea, junto al snapshot.**

Se evaluaron las dos opciones del encargo:

| Opción | Efecto | Veredicto |
|---|---|---|
| `RESTRICT` sobre `admins` | Un módulo externo bloquearía el borrado de usuarios del core | **Descartada**: no corresponde a este módulo frenar operaciones administrativas de Bagisto |
| Id histórico sin FK + snapshot | El dato sobrevive, la relación resuelve `null`, el core sigue libre | **Aplicada**; es lo que ya hacía la tabla de historial |

Después de eliminar a un administrador, verificado por prueba:

- `advisor_id`, `created_by_admin_id` y `assigned_by_admin_id` **conservan el id**;
- los snapshots de nombre **permanecen**;
- `AtribucionPedido::asesor` y `::creador` resuelven `null` sin error;
- `commercial_owner_type` sigue siendo `advisor`, sin la combinación inválida;
- la atribución sigue consultable y su historial intacto.

---

## 4. Idempotencia: idéntico frente a conflicto

`ContextoComercial::CAMPOS_COMPARABLES` fija los diez campos comerciales que definen la
identidad de una atribución: `created_by_admin_id`, `advisor_id`,
`commercial_owner_type`, `commercial_owner_source`, `sales_channel`, `creation_source`,
`external_reference`, `cart_id`, `notes` y `attribution_status` (más `order_id`, que es
la clave del registro).

| Caso | Comportamiento |
|---|---|
| Reintento con contexto equivalente | Devuelve la fila existente, sin modificarla y **sin** escribir otro historial |
| Reintento con cualquier campo distinto | Lanza `AtribucionEnConflictoException`; la atribución y el historial quedan intactos |
| Carrera perdida (violación de unicidad) | Recupera la fila ganadora y **la compara igual**: equivalente devuelve, incompatible lanza conflicto. No se asume que quien insertó primero tenía razón |

El ejemplo obligatorio del encargo está cubierto: pedido con `unassigned` y luego intento
con `advisor` produce conflicto. Una reasignación legítima se hace con
`AsignacionAsesorService`.

La excepción informa **solo los nombres** de los campos en conflicto, el `order_id` y el
id de la atribución. Nunca sus valores: `notes` y `external_reference` pueden contener
datos del cliente.

---

## 5. Historial desde la atribución inicial

`RegistrarAtribucionService` crea, en una sola transacción, la atribución **y** su evento
`initial_assignment`, con: pedido, atribución, propiedad inicial, asesor inicial y su
snapshot, actor (el creador, cuando existe) y su snapshot, origen de la propiedad, canal,
mecanismo, tipo de operación, código técnico cuando aplica, motivo legible y fecha.

Los campos «anteriores» admiten `null`, porque en la creación no hay estado previo.
Un reintento idempotente **no** genera un segundo evento inicial.

---

## 6. Historial inmutable de verdad

`LogAsignacionAsesor`:

- rechaza `update()` y `delete()` con `HistorialInmutableException`;
- rechaza cualquier inserción que no venga de `HistorialAtribucionService`, mediante una
  puerta explícita (`escribirDesdeServicio()`);
- no usa borrado lógico, de modo que no hay `forceDelete` que cubrir.

> **Corregido en la 02A2 (§19.6).** Esta lista omitía `save()`: sobre una fila existente y
> sin cambios, `Model::save()` corta por `isDirty()` y nunca dispara `updating`, así que la
> promesa no se cumplía. Ahora hay un guardia en `saving`. Y la garantía es de **modelo y
> servicio**, no de base de datos: una consulta masiva o SQL directo saltaría los eventos.

`LogAsignacionAsesorRepository` **se eliminó**: era una API genérica heredada de
`Prettus` con `update()` y `delete()` sobre una tabla de auditoría. No se sustituyó por
otra: el único punto de escritura es el servicio.

Verificado por prueba que, tras los tres intentos (actualizar, borrar e insertar sin
autorización), la fila permanece **idéntica** atributo por atributo y la atribución no
cambia.

---

## 7. Concurrencia en las reasignaciones

`AsignacionAsesorService::cambiarPropiedad()` ahora:

1. abre la transacción;
2. relee la atribución con `lockForUpdate()`;
3. calcula propiedad anterior, asesor anterior y snapshot **desde esa lectura bloqueada**;
4. comprueba que el cambio altera algo;
5. valida el permiso según el estado real (`assign` si estaba sin asignar, `reassign` si
   ya tenía dueño);
6. actualiza y escribe **un** evento de historial;
7. confirma.

La prueba 12 usa deliberadamente un modelo obsoleto: con el estado real ya asignado, un
actor con solo `assign` es rechazado, y cuando el cambio procede el historial registra
como anterior al asesor **vigente**, no al que decía la copia obsoleta.

No se añadió control de versión optimista: con el bloqueo pesimista dentro de la
transacción, dos solicitudes simultáneas se serializan y la segunda ve el estado ya
escrito. Añadir una columna de versión sería redundante mientras toda escritura pase por
este servicio.

---

## 8. Lógica de comisiones retirada

Se eliminaron `is_excluded`, `exclusion_reason`, `TipoPropiedadComercial::esComisionable()`
y `AtribucionPedido::esComisionable()`.

En su lugar queda `AtribucionPedido::esPropiedadDeAsesor()`, que solo dice de quién es la
venta. Una venta de asesor **no** genera comisión por sí sola: dependerá de utilidad
positiva, precio correcto, costo congelado, entrega, devoluciones, reglas vigentes y
producto comisionable. **D-03, D-31 y D-32 siguen pendientes**, y los documentos `02`,
`04` y `05` quedaron corregidos donde afirmaban lo contrario.

---

## 9. Diseño final de `fallo_tecnico`

Se conserva el estado y se le da un camino real:

```text
ResolverAtribucionService::paraFalloTecnico($codigo, $notas)
RegistrarAtribucionService::registrarFalloTecnico($orderId, $resolver, $codigo, $notas)
```

Reglas aplicadas:

- es una operación **del servicio**, no una inserción directa;
- produce `commercial_owner_type = unassigned` y `attribution_status = fallo_tecnico`;
- **nunca** lleva asesor, garantizado por invariante del contexto;
- escribe su historial inicial, con el código técnico en columna propia;
- es **idempotente**: repetir la llamada devuelve la misma fila y no duplica historial;
- el código se normaliza y se valida contra `^[a-z0-9._:-]{1,60}$`, de modo que no admite
  trazas, credenciales ni texto libre con datos del cliente;
- `AtribucionPedido::scopeConIncidencia()` lo deja listo para la bandeja de
  reconciliación de una fase posterior.

---

## 10. Contenido del historial

Cada evento guarda ahora: tipo de operación (enum estable `TipoOperacionAtribucion`),
propiedad anterior y nueva, asesor anterior y nuevo con snapshots, actor con snapshot,
origen de la propiedad, canal comercial, mecanismo de creación, código técnico, motivo y
fecha.

Valores del enum: `initial_assignment`, `assign_advisor`, `reassign_advisor`,
`mark_company`, `mark_unassigned`, `reconciliation`. No dependen de textos traducidos.

Una reasignación no cambia canal ni mecanismo: se copian del estado vigente para poder
reconstruir el contexto del evento.

**La tabla conserva su nombre** `bylopez_va_advisor_assignment_logs`. Renombrarla
obligaría a reescribir una migración ya aplicada sin ganancia funcional. Queda
documentado que registra **toda** decisión de propiedad comercial, no solo cambios entre
asesores.

---

## 11. Migración correctiva

Se creó una **tercera migración aditiva**,
`2026_09_15_000003_ajustar_integridad_de_la_atribucion_comercial`, en lugar de reescribir
las dos originales: avanzar es más seguro que reescribir historia, aunque estas nunca
salieran de testing.

Hace, y solo sobre tablas propias: retirar las cuatro claves foráneas anteriores y volver
a crear la de `orders` con `RESTRICT`; rehacer la del historial con `RESTRICT`; eliminar
`is_excluded` con su índice y `exclusion_reason`; volver nullable
`tipo_propiedad_anterior`; y añadir `tipo_operacion`, `origen_propiedad`, `sales_channel`,
`creation_source` y `codigo_tecnico`. Su `down()` revierte exactamente eso.

**Ejecución:** verificado antes de migrar que `APP_ENV=testing`,
`DB_DATABASE=lpz_saas_testing`, `SELECT DATABASE()` = `lpz_saas_testing`, host
`127.0.0.1` y guard activo. Se aplicó con `php artisan migrate --env=testing --force`.

> **Efecto colateral que debe registrarse.** En esa misma ejecución había **otra migración
> pendiente ajena**, `2026_09_15_000001_create_bylopez_cc_inventario_movimientos_table`, de
> CorteConfeccion, y `migrate` la aplicó también en `lpz_saas_testing`. No se modificó ese
> paquete ni se tocó desarrollo, pero la base de pruebas quedó con esa tabla suya.

No se ejecutó rollback general, `migrate:fresh`, `migrate:refresh` ni `db:wipe`.

---

## 12. Pruebas

| | Cierre de la 02A | Cierre de la 02A1 |
|---|---|---|
| Casos propios | 112 | **145** |
| Aserciones | 458 | **643** |
| Fallos | 0 | **0** |
| Suite «VentasAsesor Unit Test» | 78 | **93** |
| Suite «VentasAsesor Feature Test» | 34 | **52** |

Los 33 casos nuevos cubren los 22 puntos obligatorios del encargo: historial inicial
único, reintento idéntico, reintento incompatible en cuatro variantes, comparación con la
fila ganadora de una carrera, borrado de pedido restringido, supervivencia de atribución e
historial, conservación de ids y snapshots al eliminar administradores, resolución nula de
la relación, prohibición de actualizar y borrar historial, prohibición de borrar
atribución, reasignación sobre estado bloqueado, cambio sin efecto sin historial, un log
exacto por cada operación, camino autorizado e idempotente de `fallo_tecnico` sin asesor,
ausencia de campos y métodos de comisión, y comprobación de que ninguna migración del
módulo altera tablas del core.

---

## 13. Mutaciones

Cada una se aplicó temporalmente, se ejecutó la suite afectada y se restauró. Los cuatro
archivos quedaron **idénticos byte a byte** (`cmp`), y el esquema volvió a `RESTRICT` con
su índice único.

| # | Mutación | Dónde | Pruebas que fallaron |
|---|---|---|---|
| 1 | `permission_type = all` vuelve a ser atribución automática | `AutorizacionVentasAsesor::esAsesorParaAtribucionAutomatica()` | **3** |
| 2 | `company` admite `advisor_id` | invariante de `ContextoComercial` | **2** |
| 3 | Se neutraliza la unicidad por pedido | índice `bvaoa_order_unique` en testing | **2** |
| 4 | El contexto incompatible se acepta en silencio | `RegistrarAtribucionService::exigirEquivalencia()` | **6** |
| 5 | Vuelve el borrado en cascada | ambas claves foráneas en testing | **2** |
| 6 | El historial vuelve a ser modificable | guardia `updating` de `LogAsignacionAsesor` | **1** |

Tras restaurar, la suite completa vuelve a **145 aprobadas, 643 aserciones**. No quedó
ninguna mutación en el árbol.

---

## 14. Regresión

```
vendor/bin/pest packages/ByLopez/CorteConfeccion/tests
                packages/Webkul/Admin/tests/Feature/Settings/RoleTest.php
                packages/Webkul/Admin/tests/Feature/Settings/UsersTest.php
                packages/Webkul/Admin/tests/Feature/Admin/TwoFactorAuthenticationTest.php
```

**186 casos: 179 aprobados y 7 fallidos**, 639 aserciones, 0 bloqueos del guard y **0**
menciones al módulo, a la atribución o a sus tablas.

| Origen | Cantidad | Causa |
|---|---|---|
| `Admin/Settings/UsersTest` | 4 | Los de siempre: 3 por la tilde escapada en JSON con locale `es` y 1 por el nombre del rol sembrado en español [06A-§11.1] |
| CorteConfeccion `MenuAclKeysConsistencyTest` | 1 | Su clave de menú `corte_confeccion.inventario_movimientos` no existe en su propio `acl.php` |
| CorteConfeccion `MenuVisibilidadPorRolTest` | 1 | Espera 6 hijos del nodo `corte_confeccion` y ahora hay 7, por ese mismo nodo nuevo |
| CorteConfeccion `DistribucionConfeccionTest` RET-01 | 1 | Su estado de retorno: espera `recibida_bodega` y obtiene `cerrada` |

Los tres de CorteConfeccion son de su funcionalidad de inventario y retornos, en curso
hoy mismo; las dos pruebas de menú filtran claves `corte_confeccion.*` y cuentan los hijos
de **su** nodo, no nodos globales, así que el menú de VentasAsesor no interviene. No se
corrigió nada de ese paquete: está prohibido tocarlo.

Comparación con la 02A: entonces eran 37 fallos, 33 de ellos por `TallerService`. Esos 33
desaparecieron por avances de su responsable, ajenos a esta fase.

---

## 15. Bases de datos

| Comprobación | Resultado |
|---|---|
| Migración y pruebas | Solo `lpz_saas_testing`, verificado antes de migrar y por el guard en cada prueba |
| Bloqueos del guard | 0 |
| `lpz_saas`: tablas | **165**, sin ninguna tabla del módulo |
| `lpz_saas`: `AUTO_INCREMENT`, conteos y checksums | **SIN CAMBIOS** entre el cierre de la 02A y el cierre de esta fase |
| Escrituras de esta fase en desarrollo | Ninguna |

> **Aclarado 2026-09-25:** las cuatro migraciones del módulo están aplicadas también en `lpz_saas` (batch 21, 2026-09-22, compartido con migraciones de CorteConfeccion: un `migrate:rollback` de ese batch quitaría ambas). El registro de esa aplicación no está en ningún documento de fase. Ver `00_STATUS.md` y Doc. 10.

---

## 16. Verificación Git

| Comprobación | Cierre de la 02A | Cierre de la 02A1 |
|---|---|---|
| `git status --short` | 120 | **126** (las 6 entradas nuevas son ajenas, fuera del módulo) |
| `git diff --stat` | 56 archivos, +2552 −1346 | **56 archivos, +2633 −1345** (cambios ajenos en archivos rastreados de CorteConfeccion) |
| `git diff --check` | limpio | **limpio** |
| `packages/Webkul/` | 0 | **0** |
| `packages/Webkul/RMA/` | 0 | **0** |
| `packages/ByLopez/CorteConfeccion/` | 110 | **115**, por trabajo ajeno en curso |
| `git status --short -uall -- packages/ByLopez/VentasAsesor/` | 50 | **57** |
| Archivos raíz rastreados | +270 −8 de fases anteriores | **sin ningún cambio de esta fase** |
| `.env.testing` | no aparece | **no aparece** |
| Restos de mutaciones o respaldos en el árbol | — | **ninguno**; los cuatro `.orig` viven en el scratchpad, fuera del repositorio |
| `HEAD` | `8739c18554` | `8739c18554` |

**Archivos de esta fase.** Creados 8: `TipoOperacionAtribucion`,
`AtribucionEnConflictoException`, `HistorialInmutableException`,
`HistorialAtribucionService`, la migración correctiva, dos archivos de pruebas y este
documento. Eliminado 1: `LogAsignacionAsesorRepository`. Modificados 6 de `src/`
(`TipoPropiedadComercial`, `ContextoComercial`, `AtribucionPedido`, `LogAsignacionAsesor`,
`RegistrarAtribucionService`, `AsignacionAsesorService`, más `ResolverAtribucionService`),
2 de pruebas y 6 documentos (`00`, `02`, `03`, `04`, `05`, `07`).

`CLAUDE.md` figura modificado desde antes de esta sesión y no se tocó. No se ejecutó
`commit`, `push`, `merge`, `rebase`, `cherry-pick`, `reset`, `checkout` ni `stash`.

---

## 17. Riesgos restantes para la Fase 02B

| # | Riesgo | Nota |
|---|---|---|
| R-1 | Un conflicto no capturado en el listener podría abortar una venta real | **Corregido en la 02A2 (§19.7):** el conflicto se captura, la atribución existente se conserva intacta y la incidencia va a reconciliación; **nunca** se convierte en `fallo_tecnico` ni se sobrescribe. **Auditado en la Fase 02B0** [D-§2]: el listener **sí** corre dentro de la transacción (nivel 1 medido) y un `Throwable` suyo revierte y reintenta la venta [D-§3] |
| R-2 | `RESTRICT` sobre `orders` impide borrar pedidos atribuidos | Es deliberado. Si el core llegara a borrar pedidos en algún flujo, habrá que decidir una excepción explícita y documentada |
| R-3 | Los ids de administrador ya no tienen clave foránea | El módulo asume que el id puede apuntar a un usuario inexistente; toda lectura debe tolerar `null` en la relación |
| R-4 | `reconciliation` existe en el enum pero ningún flujo lo produce | Lo usará el comando de reconciliación de la 02B |
| R-5 | La idempotencia compara `notes`: dos intentos con notas distintas son conflicto | Correcto y deliberado, pero la 02B no debe generar notas variables (marcas de tiempo, contadores) |
| R-6 | La base de pruebas contiene ahora una tabla de CorteConfeccion aplicada por esta fase | §11; no afecta a desarrollo |
| R-7 | 7 fallos de regresión ajenos siguen presentes | §14; pertenecen a CorteConfeccion y al locale del core |

---

## 18. Criterios de salida

*Los criterios de la 02A2 están en §19.10.*

| Criterio | Estado |
|---|---|
| Sin cascadas que destruyan atribución o historial | ✅ §2 |
| Ids históricos sobreviven a la eliminación de administradores | ✅ §3 |
| No se rompe la invariante `advisor → advisor_id` | ✅ §3 |
| Reintento idéntico idempotente | ✅ §4 |
| Reintento incompatible con conflicto | ✅ §4 |
| Toda atribución inicial crea historial | ✅ §5 |
| Historial inmutable | ✅ §6 |
| Reasignaciones con control de concurrencia | ✅ §7 |
| Sin lógica anticipada de comisiones | ✅ §8 |
| `fallo_tecnico` con camino válido | ✅ §9 |
| Todas las pruebas propias pasan | ✅ 145 casos |
| Mutaciones demuestran sensibilidad | ✅ §13 |
| Sin listeners ni integración real con pedidos | ✅ |
| Sin backfill | ✅ |
| Desarrollo intacto | ✅ §15 |
| Webkul, RMA y CorteConfeccion sin cambios de esta fase | ✅ §16 |

---

## 19. Revisión externa y cierre 02A2 — 2026-09-19

Una segunda revisión externa confirmó que las correcciones de la 02A1 existen en el
código, y encontró **seis huecos** que esta microfase cerró. Todo lo anterior de este
documento se conserva tal cual; aquí queda lo que cambió.

### 19.1 Qué se corrigió

| # | Hueco confirmado leyendo el código | Corrección |
|---|---|---|
| 1 | La prueba «4. ante una carrera…» insertaba la fila **antes** de llamar al servicio, así que la consulta inicial la encontraba y el `catch` nunca se ejecutaba | Prueba real de carrera con segunda conexión (§19.2). La antigua se renombró a «4. con una atribución preexistente se compara en vez de sobrescribir», que es lo que de verdad prueba |
| 2 | `paraFalloTecnico()` y `registrarFalloTecnico()` aceptaban `$notas` libres | Se eliminó el parámetro; el detalle va al log técnico (§19.3) |
| 3 | El código técnico no participaba en la idempotencia: dos códigos distintos parecían equivalentes | Se compara contra el evento inicial (§19.4) |
| 4 | El `down()` forzaba `NOT NULL` sobre filas con `null` y recreaba las claves foráneas a `admins` sin resolver huérfanos | Trata los datos antes de tocar el esquema (§19.5) |
| 5 | El modelo bloqueaba `updating` y `deleting`, pero `save()` sin cambios no dispara `updating`: `Model::save()` corta antes por `isDirty()` | Guardia en `saving` (§19.6) |
| 6 | Documentos y comentario de `EstadoAtribucion` con afirmaciones desactualizadas | Corregidos (§19.7) |

### 19.2 Evidencia de que la rama de carrera se ejecuta

La prueba usa una **segunda conexión** que confirma sus escrituras fuera de la
transacción de la prueba:

1. el pedido y la fila ganadora los crea esa conexión y quedan confirmados;
2. antes de llamar al servicio se ejecuta **la misma consulta que hace el servicio**
   (`where order_id`) y devuelve **0**, dos veces: la transacción de la prueba mantiene
   su instantánea bajo `REPEATABLE-READ`, nivel que la prueba comprueba explícitamente;
3. por eso el servicio no encuentra nada y llega al `INSERT`, que viola la unicidad;
4. la recuperación posterior al `catch` relee con `lockForUpdate()`, que sí ve la última
   versión confirmada;
5. el servicio devuelve una fila **cuyo id generó la otra conexión**. Como las dos
   consultas previas devolvieron cero, no hay otro camino por el que esa fila pueda
   volver: la rama del `catch` se ejecutó;
6. con contexto incompatible, la misma rama termina en `AtribucionEnConflictoException`;
7. no quedan atribuciones ni historiales duplicados.

**Endurecimiento que salió de escribir esta prueba:** la recuperación usaba
`firstOrFail()` con lectura consistente. Bajo `REPEATABLE READ` esa lectura puede seguir
sin ver la fila del ganador y habría lanzado `ModelNotFoundException` en lugar de
comparar. Ahora usa `lockForUpdate()` y, si de verdad no hay fila, **relanza la
excepción original** en vez de disimularla.

La limpieza de lo que escribió la conexión paralela se registra con
`beforeApplicationDestroyed()` y corre **después** del rollback de la prueba: hacerlo
antes chocaba con el bloqueo del propio servicio y agotaba el tiempo de espera. Se
verificó que, tras una ejecución correcta, no queda ninguna fila en `lpz_saas_testing`.

### 19.3 Política de datos seguros en `fallo_tecnico`

- `paraFalloTecnico(string $codigo)` y
  `registrarFalloTecnico(int $orderId, ResolverAtribucionService $resolver, string $codigo)`:
  **sin parámetro de notas**.
- Lo único que se persiste es el código ya validado contra `^[a-z0-9._:-]{1,60}$`.
- El motivo del historial es **texto fijo más el código**:
  `Atribucion inicial con fallo tecnico: <codigo>`.
- `notes` queda en `null`.
- El detalle operativo va al log técnico de Laravel, con tres campos acotados:
  `order_id`, `codigo_tecnico` y `attribution_id`. Nunca excepciones, trazas, payloads,
  tokens ni datos personales.

### 19.4 El código técnico entra en la idempotencia

El código no vive en la atribución sino en su evento inicial, así que la comparación lo
lee de ahí cuando cualquiera de los dos lados es un fallo técnico:

| Caso | Resultado |
|---|---|
| Mismo código (normalizado) | Reintento idempotente: devuelve la fila, sin historial nuevo |
| Código distinto | `AtribucionEnConflictoException` con `codigo_tecnico` entre los campos en conflicto |
| Sin evento inicial, con más de uno, o sin código en un fallo técnico | `HistorialIncoherenteException`: es un problema de integridad, no un reintento válido |

No se añadió ninguna columna a la atribución: el dato ya existía en el historial.

### 19.5 Resultado del `down()` dirigido

Verificación reproducible sobre `lpz_saas_testing`, invocando **solo** el `down()` y el
`up()` de esta migración. No se usó `migrate:rollback`, que habría arrastrado una
migración ajena de CorteConfeccion en el mismo lote, ni `migrate:fresh`, `refresh` o
`db:wipe`.

Escenario sembrado: una atribución `advisor` con los tres ids apuntando a un
administrador **inexistente**, y su evento inicial con `tipo_propiedad_anterior = null`.

| Momento | Resultado |
|---|---|
| Antes | `RESTRICT` ×2, `tipo_propiedad_anterior` nullable, sin `is_excluded` |
| `down()` | **Sin excepción.** La fila y su historial **sobreviven**; `tipo_propiedad_anterior` se resuelve a `advisor` y pasa a `NOT NULL`; los tres ids huérfanos quedan en `null`; vuelven `is_excluded`, las cascadas y las claves foráneas a `admins` |
| `up()` | **Sin excepción.** `RESTRICT` ×2, sin claves foráneas a `admins`, sin `is_excluded`, columnas del historial restauradas |
| Limpieza | 0 filas del escenario |

**Pérdidas asumidas y documentadas del rollback:** copiar la propiedad nueva sobre la
anterior borra la distinción «asignación inicial»; anular los ids huérfanos reintroduce
la combinación `advisor` sin `advisor_id` que la 02A1 corrigió. Son el precio de volver
a un esquema que no puede representar esos casos, no efectos inadvertidos.

### 19.6 Alcance real de la inmutabilidad

El historial rechaza `save()` (con y sin cambios), `update()` y `delete()`, y solo acepta
inserciones desde `HistorialAtribucionService`. `save()` sin cambios necesitaba guardia
propia porque `Model::save()` corta por `isDirty()` antes de disparar `updating`.

**La garantía es de modelo y servicio, no de base de datos.** Una consulta masiva por
Query Builder o SQL directo salta los eventos de Eloquent. Esta fase **no** añade
disparadores de base de datos; la regla del módulo es que el historial solo se escribe
por el servicio. No se afirma inmutabilidad absoluta.

### 19.7 Reglas corregidas para la Fase 02B

La regla anterior («derivar el conflicto a `fallo_tecnico`») era incorrecta y se
sustituye por:

```text
No existe atribución y no puede determinarse el contexto
  → puede registrarse fallo_tecnico.

Ya existe una atribución equivalente
  → reintento idempotente.

Ya existe una atribución incompatible
  → conservarla intacta y enviar la incidencia a reconciliación.

Nunca convertir una atribución existente incompatible en fallo_tecnico.
Nunca sobrescribirla desde el listener.
```

Además, **no se afirma** que el listener vaya a correr dentro de la transacción del
checkout: `checkout.order.save.after` es un **candidato pendiente de auditoría** sobre el
código real de Bagisto antes de diseñar la 02B. El comentario de `EstadoAtribucion` que
decía que la fase no podía producir `fallo_tecnico` también quedó corregido.

### 19.8 Pruebas, regresión y bases

| | Cierre 02A1 | Cierre 02A2 |
|---|---|---|
| Casos propios | 145 | **153** |
| Aserciones | 643 | **678** |
| Fallos | 0 | **0** |

Casos nuevos: los doce puntos obligatorios del encargo, entre ellos la carrera real, el
ganador equivalente e incompatible, la ausencia de notas libres, los dos casos de código
técnico, el historial inicial ausente y duplicado, `save()` con y sin cambios, y el
esquema final con `RESTRICT`. Los dos puntos del `down()` se verificaron con el script
dirigido (§19.5), porque ejecutar DDL dentro de la suite rompería las transacciones.

**Regresión dirigida:** 187 casos, 183 aprobados y **4 fallidos**, todos los conocidos de
locale en `Admin/Settings/UsersTest`. Los tres fallos de CorteConfeccion que había en la
02A1 ya no aparecen: su responsable avanzó ese trabajo. Cero bloqueos del guard y cero
menciones al módulo.

**Bases de datos.** Migraciones y pruebas solo en `lpz_saas_testing`. Esta fase **no
ejecutó ninguna migración nueva**: no hubo cambios de esquema.

Sobre `lpz_saas`: esta fase no ejecutó ninguna escritura contra desarrollo, solo lecturas
de la instantánea. Comparada con el cierre de la 02A1 (hace cuatro días), la base **sí
cambió**: 165 → 166 tablas por la nueva tabla de inventario de CorteConfeccion, más datos
suyos y un administrador y un rol nuevos. Son cambios **ajenos**, hechos entre fases. No
se tomó una instantánea al inicio exacto de esta microfase, así que lo que se afirma es
lo verificable: el módulo no tiene ninguna tabla en desarrollo (0) y esta fase no emitió
ninguna escritura contra esa base.

### 19.9 Git al cierre de la 02A2

| Comprobación | Cierre 02A1 | Cierre 02A2 |
|---|---|---|
| `git status --short` | 126 | **125** (variación ajena, en CorteConfeccion) |
| `git diff --stat` | 56 archivos, +2633 −1345 | **56 archivos, +2647 −1345** (cambios ajenos) |
| `git diff --check` | limpio | **limpio** |
| `packages/Webkul/` · `RMA/` | 0 · 0 | **0 · 0** |
| `packages/ByLopez/CorteConfeccion/` | 115 | **115** |
| VentasAsesor `-uall` | 57 | **59** (+`HistorialIncoherenteException`, +`CierreAuditado02A2Test`) |
| Archivos raíz rastreados | sin cambios de la fase | **sin cambios de la fase** |
| `.env.testing` | no aparece | **no aparece** |
| Listeners o proveedores de eventos en el módulo | 0 | **0** |
| `HEAD` | `8739c18554` | `8739c18554` |

### 19.10 Criterios de salida de la 02A2

| Criterio | Estado |
|---|---|
| La rama de carrera se ejecuta y está demostrada | ✅ §19.2 |
| `fallo_tecnico` sin notas libres | ✅ §19.3 |
| Código técnico dentro de la idempotencia | ✅ §19.4 |
| `down()` trata datos reales | ✅ §19.5 |
| `save()` sobre historial persistido rechazado | ✅ §19.6 |
| Alcance de la inmutabilidad documentado sin exagerar | ✅ §19.6 |
| Regla de conflictos corregida para la 02B | ✅ §19.7 |
| Evento del listener presentado como candidato | ✅ §19.7 |
| Todas las pruebas propias pasan | ✅ 153 casos |
| Sin listeners ni integración con pedidos | ✅ §19.9 |
| Sin backfill | ✅ |
| Fase 02 **no** marcada como completa | ✅ |
