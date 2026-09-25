# 07_FASE_02A_MODELO_Y_MOTOR_ATRIBUCION.md

**Fase 02A — Modelo de datos y motor interno de atribución comercial.**

**Fecha:** 2026-09-15
**Estado:** **COMPLETADA Y CORREGIDA** por la Fase 02A1 (2026-09-15).

> ## Qué corrigió la Fase 02A1
>
> Este documento **conserva sus afirmaciones originales**, incluidas las que resultaron
> incorrectas, para que quede el registro de lo que se creyó entregado. Una revisión
> externa encontró incumplimientos reales y `07A_CORRECCIONES_INTEGRIDAD_FASE_02A.md`
> (`[C1-§n]`) documenta su corrección.
>
> | Afirmación de este documento | Estado tras la 02A1 |
> |---|---|
> | §2.1 `order_id` con FK `cascadeOnDelete` | **Corregido**: RESTRICT. Borrar un pedido atribuido ya no destruye la trazabilidad [C1-§2] |
> | §2.1 ids de administrador con FK `nullOnDelete` «para no destruir el histórico» | **Era falso**: dejaba `advisor` con `advisor_id = null`. Ahora el id se conserva **sin** clave foránea [C1-§3] |
> | §2.2 historial con FK `cascadeOnDelete` | **Corregido**: RESTRICT [C1-§2] |
> | §2.1 columnas `is_excluded` y `exclusion_reason` | **Eliminadas**: lógica de comisión prematura [C1-§8] |
> | §4 «idempotente: devuelve la fila existente sin modificarla» | **Insuficiente**: no comparaba el contexto. Ahora idéntico devuelve y distinto lanza conflicto [C1-§4] |
> | §4 la carrera «gana el primero» | **Corregido**: se compara con la fila ganadora [C1-§4] |
> | §2.2 «el historial nunca se borra ni se reescribe» | **Era solo un comentario**: el modelo permitía `update()` y `delete()`. Ahora es una barrera real [C1-§6] |
> | §1 tabla de repositorios, con `LogAsignacionAsesorRepository` | **Eliminado**: API genérica que permitía modificar auditoría [C1-§6] |
> | §3.1 «`fallo_tecnico` lo generará el listener de la 02B» | **Insuficiente**: era inalcanzable. Ahora hay camino autorizado, validado e idempotente [C1-§9] |
> | §4 la asignación no dejaba historial inicial | **Corregido**: toda atribución nueva escribe `initial_assignment` [C1-§5] |
> | §4 reasignación sobre el modelo recibido | **Corregido**: `lockForUpdate()` sobre el estado vigente [C1-§7] |
> | §5 «esComisionable» en las pruebas y §10 R-5 sobre `is_excluded` | Retirados [C1-§8] |
> | §12 «112 casos, 458 aserciones» | Vigente para el cierre de la 02A; hoy son **145 casos y 643 aserciones** [C1-§12] |

**Alcance original de la fase (sin cambios):** primera fase del módulo con código funcional.
**Alcance:** modelo de datos, dominio, servicios internos y pruebas. **El motor no
está conectado con la creación real de pedidos de Bagisto**: eso es la Fase 02B.

Referencias: `[A-§n]` → `01`; `[M-§n]` → `02`; `[T-§n]` → `05`; `[B-§n]` → `06B`;
`D-nn` / `RT-nn` → `04`. Este documento se cita como `[C-§n]`.

---

## 0. Línea base y cambios ajenos

| Comprobación | Cierre de la 01B | Inicio de la 02A |
|---|---|---|
| `git status --short` | 120 | 120 |
| `packages/Webkul/` | 0 | 0 |
| `packages/ByLopez/CorteConfeccion/` | 110 | 110 |
| Tablas en `lpz_saas_testing` | 162 | 162 |
| Tablas en `lpz_saas` | 165 | 165 |
| `HEAD` | `8739c18554` | `8739c18554` |

**Cambios ajenos a esta fase, registrados y no atribuidos a VentasAsesor:**

- CorteConfeccion sigue en desarrollo activo. Hoy 2026-09-15 a las **21:28** se modificó
  `src/Services/TallerService.php` (archivo sin rastrear), casi una hora antes de que
  existiera el código de esta fase (22:22). Consecuencias en §7.2.
- En la base de **desarrollo**, entre el cierre de la 01B y el inicio de esta fase, se
  creó un administrador (id 12117, 21:37:35) y un taller (id 1504, 21:37:54), y
  avanzaron los `AUTO_INCREMENT` de `admins`, `roles` y `bylopez_cc_talleres`. Son
  anteriores al código de esta fase y coinciden con el trabajo de CorteConfeccion. Esta
  fase **no escribió** en desarrollo (§8).

---

## 1. Qué se implementó

| Capa | Archivos | Notas |
|---|---|---|
| Migraciones | `2026_09_15_000001_create_bylopez_va_order_attributions_table.php`, `2026_09_15_000002_create_bylopez_va_advisor_assignment_logs_table.php` | Solo tablas propias con prefijo `bylopez_va_` |
| Enums | `TipoPropiedadComercial`, `EstadoAtribucion`, `CanalVenta`, `MecanismoCreacion`, `OrigenPropiedadComercial` | Valores exactos verificados por prueba |
| Objetos de valor | `ContextoComercial`, `SeleccionPropiedadComercial` | Inmutables; validan invariantes al construirse |
| Modelos | `AtribucionPedido`, `LogAsignacionAsesor` | `belongsTo` hacia `OrderProxy` y `AdminProxy` [M-§11.1] |
| Contratos | `Contracts\AtribucionPedido`, `Contracts\LogAsignacionAsesor` | Convención del proyecto |
| Repositorios | `AtribucionPedidoRepository`, `LogAsignacionAsesorRepository` | Sobre `Webkul\Core\Eloquent\Repository` |
| Servicios | `ResolverAtribucionService`, `RegistrarAtribucionService`, `AsignacionAsesorService` | Dominio puro, sin HTTP |
| Excepción | `AtribucionInvalidaException` | Falla cerrado |
| Provider | `VentasAsesorServiceProvider` | Se añadió `loadMigrationsFrom` |
| Autorización | `AutorizacionVentasAsesor` | Se **añadieron** 2 constantes y `esAsesorParaAtribucionAutomatica()`; no se modificó nada existente |

**No se creó** ningún listener, suscriptor, ruta, controlador, vista, DataGrid, menú
nuevo, bandeja, integración con `OrderRepository` ni con `OrderResource`, ni backfill.

---

## 2. Modelo de datos

### 2.1 `bylopez_va_order_attributions`

| Campo | Tipo | Notas |
|---|---|---|
| `order_id` | int unsigned, **UNIQUE**, FK → `orders` `cascadeOnDelete` | La relación 1:1 se materializa aquí, nunca con una columna en `orders` [M-§5.1] |
| `created_by_admin_id` | int unsigned, nullable, FK → `admins` `nullOnDelete` | Quién ejecutó la creación |
| `created_by_name_snapshot` | string, nullable | Sobrevive a la baja del administrador [M-§11.2] |
| `advisor_id` | int unsigned, nullable, FK → `admins` `nullOnDelete` | Dueño comercial (D-05: uno solo) |
| `advisor_name_snapshot` | string, nullable | Ídem |
| `commercial_owner_type` | string, indexado | `advisor` · `company` · `unassigned` (D-27) |
| `commercial_owner_source` | string, nullable | Por qué quedó así |
| `sales_channel` | string, indexado | [M-§6.1] |
| `creation_source` | string, indexado | [M-§6.2] |
| `attribution_status` | string, indexado | `completa` · `sin_asesor` · `fallo_tecnico` [M-§10.3] |
| `external_reference` | string, nullable, indexado | Base de idempotencia futura |
| `cart_id` | int unsigned, nullable, indexado | **Sin FK**: `Cart::removeCart()` borra la fila de `cart` al crear el pedido |
| `assigned_at`, `assigned_by_admin_id` | timestamp / FK `nullOnDelete` | Cuándo y quién asignó |
| `is_excluded`, `exclusion_reason` | bool indexado / string | Pedido de prueba o duplicado |
| `notes` | text, nullable | — |

Los tipos son `unsignedInteger` porque `orders.id` y `admins.id` son `increments()`.

### 2.2 `bylopez_va_advisor_assignment_logs`

`order_attribution_id` (FK `cascadeOnDelete`), `order_id` (redundante a propósito),
`advisor_id_anterior` / `advisor_id_nuevo` con sus snapshots, `tipo_propiedad_anterior`
/ `tipo_propiedad_nuevo`, `changed_by_admin_id` con snapshot, `motivo` y marcas de
tiempo.

Los identificadores de administrador van **sin clave foránea**: si alguien se elimina,
el historial debe seguir diciendo quién era en vez de quedar en nulo. Es la única
auditoría de propiedad comercial, porque el core no tiene ninguna [A-§5.1, H5].

### 2.3 Diferencias respecto a lo propuesto en `02`

1. Se añadió `created_by_name_snapshot`, simétrico al del asesor.
2. Se añadió `commercial_owner_source`.
3. El historial guarda también el tipo de propiedad anterior y nuevo: un cambio puede
   ser asesor ↔ empresa, no solo de asesor a asesor.

---

## 3. Reglas implementadas

`ResolverAtribucionService` es el único lugar donde viven estas reglas.

| Regla | Entrada | Resultado |
|---|---|---|
| **A. Storefront** | `paraStorefront()` | `company`, `advisor_id = null`, `created_by_admin_id = null`, mecanismo `storefront`, origen `regla_storefront`, estado `completa` |
| **B. Asesor identificado** | `permission_type = custom` **y** permiso `ventas_asesor.operar_como_asesor` | `advisor`, asesor = creador, origen `auto_asesor` |
| **C. Administrador total sin contexto** | `permission_type = all` | `unassigned`, `advisor_id = null`, `created_by_admin_id` = quien creó, origen `sin_contexto` |
| **C. Administrador total con selección** | venta de empresa · asignarme · asignar otro asesor | `company` / `advisor` (él mismo) / `advisor` (el elegido), origen `seleccion_explicita` |
| **D. Sin evidencia** | `paraPedidoSinEvidencia()` | `unassigned`, canal y mecanismo `desconocido`, origen `sin_contexto` |
| Automatización | `paraAutomatizacion()` | Asesor explícito verificado o `unassigned` |

**Qué nunca hace:** inferir asesor por nombre de rol, correo, cliente, fecha o usuario
de la sesión; asignar automáticamente a un administrador con acceso total; aceptar un
`advisor_id` sin verificarlo [T-§12].

**Autorización.** Se reutiliza `AutorizacionVentasAsesor`, que falla cerrado. **No** se
usa `Admin::hasPermission()` del core, que concede con tipos de permiso desconocidos
[06A-§1]. Se añadió `esAsesorParaAtribucionAutomatica()`, que exige `custom` más el
permiso explícito; `esAsesor()` no cambió y sigue devolviendo `true` con `all`, que es
lo que permite elegirlo expresamente como asesor (D-27).

**Separación (regla E).** `created_by_admin_id`, `advisor_id`,
`commercial_owner_type`, `sales_channel` y `creation_source` son campos distintos y se
guardan por separado; el creador puede ser distinto del asesor, y se verifica por prueba.

### 3.1 Divergencia registrada frente a `02`

[M-§10.4] preveía que un pedido de storefront quedara como `sin_asesor`. La decisión
aprobada para esta fase es que pertenece a la **empresa** (`company`), un estado
completo. Por eso `attribution_status` se deriva así: `advisor` y `company` →
`completa`; `unassigned` → `sin_asesor`. `fallo_tecnico` queda reservado para el
listener de la Fase 02B y esta fase no lo produce.

---

## 4. Servicios

| Servicio | Responsabilidad | Garantías |
|---|---|---|
| `ResolverAtribucionService` | Decide el contexto comercial antes de persistir (mecanismo M-3 de [M-§10.2]) | Falla cerrado ante datos incoherentes o falta de permiso |
| `RegistrarAtribucionService` | Persiste la atribución de un pedido existente | **Idempotente**: si el pedido ya tiene atribución, la devuelve **sin modificarla**, de modo que no pisa una asignación hecha después por una persona. Ante carrera, gana el primero |
| `AsignacionAsesorService` | Cambia la propiedad comercial | Exige motivo; exige `assign` si estaba sin asignar y `reassign` si ya tenía dueño; el asesor destino debe tener el permiso; cada cambio deja exactamente una fila de historial; todo dentro de una transacción |

Ninguno se registra en eventos ni se expone por HTTP en esta fase.

---

## 5. Pruebas propias

Ejecución: `php artisan test --filter=VentasAsesor`, sobre `lpz_saas_testing`.

| | Antes de la 02A | Después |
|---|---|---|
| Casos | 72 | **112** |
| Aserciones | 251 | **458** |
| Duración | 26,85 s | 54,02 s |
| Fallos | 0 | **0** |

Casos nuevos: **40** (27 unitarios + 13 de integración), en **30** funciones `test()`.
Suites: «VentasAsesor Unit Test» 78 y «VentasAsesor Feature Test» 34; suman 112 y el
listado completo de la suite del proyecto no tiene ninguna línea repetida.

**Qué cubren los casos nuevos:**

- valores exactos de los cinco enums y derivación del estado;
- invariantes del contexto comercial (6 combinaciones inválidas, cada una rechazada);
- reglas A, B, C y D, incluidas las tres opciones del administrador total;
- un rol con `permission_type` desconocido que lista el permiso **no** es asesor;
- asignar a otro asesor exige permiso y destino válido;
- existencia de tablas, columnas, unicidad e índices;
- que `orders` y `cart` **no** reciben columnas del módulo;
- snapshots, estado, fecha de asignación y relaciones con el pedido y el asesor;
- idempotencia del registro y unicidad en base de datos;
- baja de administrador (conserva snapshot) y borrado de pedido (arrastra la atribución);
- historial de asignación completo y sus negativas: sin permiso, sin motivo, sin cambio real, destino que no es asesor;
- filtrado por asesor en el servidor.

---

## 6. Migraciones

| Paso | Resultado |
|---|---|
| Verificación previa | `APP_ENV=testing`; conexión `mysql` en `127.0.0.1`; base configurada y **base real** `lpz_saas_testing` (`SELECT DATABASE()`); guard de `tests/TestCase.php` activo |
| Pendientes antes | exactamente las 2 de este paquete |
| Ejecución | `php artisan migrate --env=testing --force` → lote 3, ambas `DONE` |
| Resultado | `lpz_saas_testing` pasó de 162 a **164** tablas; las 2 nuevas son `bylopez_va_order_attributions` y `bylopez_va_advisor_assignment_logs` |
| Desarrollo | `lpz_saas` sigue con **165** tablas y **0** tablas `bylopez_va_%` |

No se ejecutó `migrate:fresh`, `migrate:refresh`, `db:wipe`, `db:seed` ni
`bagisto:install`.

---

## 7. Regresión

```
vendor/bin/pest packages/ByLopez/CorteConfeccion/tests
                packages/Webkul/Admin/tests/Feature/Settings/RoleTest.php
                packages/Webkul/Admin/tests/Feature/Settings/UsersTest.php
                packages/Webkul/Admin/tests/Feature/Admin/TwoFactorAuthenticationTest.php
```

| Resultado | Valor |
|---|---|
| Casos | 181 |
| Aprobados | 144 |
| Fallidos | **37** |
| Aserciones | 453 |
| Duración | 136,84 s |
| Bloqueos del guard | 0 |

### 7.1 Clasificación de los 37 fallos

| Origen | Cantidad | Causa |
|---|---|---|
| CorteConfeccion (`DistribucionConfeccionTest` 25, `SEC01PermisosDistribucionTest` 7, `DistribuirBaseFrontendTest` 1) | **33** | Todos con el mismo mensaje, «El campo usuario asociado es obligatorio», lanzado desde `CorteConfeccion/src/Services/TallerService.php:37` |
| `Admin/Settings/UsersTest` | **4** | Los mismos ya documentados: 3 por la tilde escapada en JSON con locale `es` y 1 por el nombre del rol sembrado en español [06A-§11.1] |

### 7.2 Por qué los 33 no son de esta fase

1. El mensaje sale de `TallerService::validarUsuario()`, que exige `usuario_id` y que
   ese usuario tenga el rol `TALLER CONFECCION`.
2. El helper de esas pruebas, `dist_crearTaller()`, sigue enviando `'usuario_id' => null`:
   el servicio cambió y su prueba quedó desactualizada.
3. `TallerService.php` y `TallerConfeccionResolver.php` son archivos **sin rastrear** de
   CorteConfeccion. El primero se modificó hoy a las **21:28**; el código de esta fase
   se creó a las **22:22**.
4. La regresión de la Fase 01A, del 2026-09-13 a las 19:26, tenía CorteConfeccion en
   verde; los archivos implicados cambiaron después (13-sep 21:57 y 23:06, y 15-sep 21:28).
5. No hay acoplamiento: ningún archivo de CorteConfeccion menciona `VentasAsesor`,
   `ventas_asesor` ni `bylopez_va`, y ningún archivo de `VentasAsesor/src` menciona
   CorteConfeccion.
6. Ninguno de los 37 fallos menciona el módulo, la atribución ni sus tablas.
7. Ejecutado en aislamiento, `DistribucionConfeccionTest` reproduce el mismo resultado
   (25 fallidos, 1 aprobado).

**No se corrigió nada de CorteConfeccion:** el encargo prohíbe modificar ese paquete.
Queda registrado como precondición operativa para su responsable.

**Observación menor:** el fallo de `VarianteConsultaServiceTest` que se vio en la 01A
(«Talla» frente a «Tamaño») ya no aparece. Depende de datos de la base de pruebas y no
se investigó más.

---

## 8. Bases de datos

| Comprobación | Resultado |
|---|---|
| Pruebas y migraciones | Solo `lpz_saas_testing`, verificado antes de migrar y por el guard en cada prueba |
| Bloqueos del guard | 0 en todas las ejecuciones |
| `lpz_saas`: tablas | 165, sin ninguna tabla del módulo |
| `lpz_saas`: comparación de cierre | §9 |
| Escrituras de esta fase en desarrollo | Ninguna: sobre desarrollo solo se ejecutaron consultas de lectura |

> **Aclarado 2026-09-25:** las cuatro migraciones del módulo están aplicadas también en `lpz_saas` (batch 21, 2026-09-22, compartido con migraciones de CorteConfeccion: un `migrate:rollback` de ese batch quitaría ambas). El registro de esa aplicación no está en ningún documento de fase. Ver `00_STATUS.md` y Doc. 10.

Los cambios detectados en desarrollo (un administrador y un taller creados a las 21:37,
más avances de `AUTO_INCREMENT`) son **anteriores** al código de esta fase y coinciden
con el trabajo en curso de CorteConfeccion (§0).

---

## 9. Verificación final

### 9.1 Base de desarrollo

Instantánea de **solo lectura** de `lpz_saas` (`AUTO_INCREMENT`, `COUNT(*)` y
`CHECKSUM TABLE` de todas las tablas), tomada al inicio de la fase y repetida al cierre,
después de las migraciones, las pruebas propias y la regresión.

| Comprobación | Inicio | Cierre |
|---|---|---|
| Tablas | 165 | 165 |
| `AUTO_INCREMENT` | — | **SIN CAMBIOS** |
| `COUNT(*)` | — | **SIN CAMBIOS** |
| `CHECKSUM TABLE` | — | **SIN CAMBIOS** |
| Tablas `bylopez_va_%` | 0 | **0** |

### 9.2 Git

| Comprobación | Inicio de la 02A | Cierre |
|---|---|---|
| `git status --short` | 120 | **120** |
| `git diff --stat` | 56 archivos, +2552 −1346 | **56 archivos, +2552 −1346** |
| `git diff --check` | — | **limpio** |
| Archivos raíz rastreados | +270 −8 de fases anteriores | **sin ningún cambio de esta fase** |
| `git diff -- packages/ByLopez/VentasAsesor/` | 0 líneas | 0 líneas (paquete sin rastrear) |
| Entradas sin rastrear del paquete | — | **50** |
| `packages/Webkul/` | 0 | **0** |
| `packages/Webkul/RMA/` | 0 | **0** |
| `packages/ByLopez/CorteConfeccion/` | 110 | **110** |
| `.env.testing` en `git status` | — | no aparece |
| `HEAD` | `8739c18554` | `8739c18554` |

**Archivos de esta fase.** 22 creados: 19 en `src/` (2 migraciones, 5 enums, 2 objetos de
valor, 1 excepción, 2 contratos, 2 modelos, 2 repositorios, 3 servicios), 2 pruebas y este
documento. 2 archivos existentes del paquete modificados de forma aditiva
(`VentasAsesorServiceProvider`, `AutorizacionVentasAsesor`) y 5 documentos actualizados
(`00`, `02`, `03`, `04`, `05`).

**`CLAUDE.md` aparece modificado desde antes de esta sesión y no se tocó.** No se ejecutó
`commit`, `push` ni ninguna operación que altere el historial.

---

## 10. Riesgos y pendientes

| # | Riesgo o pendiente | Tratamiento |
|---|---|---|
| R-1 | El motor no está conectado: hoy ningún pedido real se atribuye | Es el alcance de la Fase 02B |
| R-2 | `fallo_tecnico` está modelado pero no se produce | Lo generará el listener de la 02B |
| R-3 | El bloqueo de reasignación tras una liquidación pagada (D-04) no está implementado | No existen tablas de liquidación todavía |
| R-4 | La preferencia comercial del cliente (D-27) todavía no tiene dónde guardarse | Fase 02B o la de interfaz |
| R-5 | La exclusión (`is_excluded`) existe pero ningún flujo la activa | Fases posteriores |
| R-6 | 33 pruebas de CorteConfeccion en rojo por su propio trabajo en curso | Pertenece a su responsable; esta fase no puede tocar ese paquete |
| R-7 | Los 4 fallos de `UsersTest` por locale siguen presentes | Documentado desde la 01A |
| R-8 | `AtribucionPedido` usa los proxies del core; si Concord dejara de registrarlos, las relaciones fallarían | Cubierto por prueba que resuelve pedido y asesor |

---

## 11. Criterios de salida

| Criterio | Estado |
|---|---|
| Documentación obligatoria leída y contrastada con el código | ✅ |
| Modelo de datos propio, sin tocar tablas del core | ✅ §2, §5 |
| Reglas A, B, C, D y E implementadas y probadas | ✅ §3, §5 |
| Autorización estricta reutilizada, sin `Admin::hasPermission()` | ✅ §3 |
| Servicios internos sin interfaz, rutas ni listeners | ✅ §1, §4 |
| Migraciones y pruebas solo en `lpz_saas_testing` | ✅ §6, §8 |
| Pruebas propias en verde | ✅ 112 casos, 458 aserciones |
| Regresión ejecutada y clasificada | ✅ §7 |
| Sin backfill ni cambios en datos de desarrollo | ✅ §8 |
| Webkul, RMA y CorteConfeccion sin modificar | ✅ §9 |
