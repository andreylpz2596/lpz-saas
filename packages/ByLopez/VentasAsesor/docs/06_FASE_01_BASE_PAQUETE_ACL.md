# 06_FASE_01_BASE_PAQUETE_ACL.md

**Fase 01 — Base del paquete, ACL y fundamentos de autorización.**

**Fecha:** 2026-09-13

> **Estado: COMPLETADA tras el cierre 01A.** Una revisión externa encontró un hueco de
> autorización y exigió aislar las pruebas de la base de desarrollo. Las correcciones y
> los resultados vigentes están en
> `06A_CIERRE_FASE_01_Y_ENTORNO_PRUEBAS.md`. Las afirmaciones de este documento que
> resultaron inexactas llevan una nota «Corrección (Fase 01A)» o «Precisión (Fase 01A)»
> en su propio lugar.

Referencias `[A-§n]` → `01_AUDITORIA_CORE_VENTAS_BAGISTO.md`;
`[M-§n]` → `02_MODELO_FUNCIONAL_VENTAS_ASESOR.md`;
`[R-§n]` → `05A_AUDITORIA_RMA_Y_MAPA_CORE_RELACIONADO.md`;
`D-nn` → `04_DECISIONES_PENDIENTES.md`.

---

## 1. Objetivo

Dejar `ByLopez\VentasAsesor` como paquete mínimo, cargable y probado que:

- arranca con Laravel 12.62.0 y Bagisto 2.4.9;
- registra ACL propia y granular, y un menú de un solo nodo;
- expone una única pantalla administrativa protegida en servidor;
- identifica al asesor **por permiso ACL** (D-06), nunca por nombre de rol;
- deja centralizada y probada la regla `view_own` / `view_all`, que falla cerrada.

No consulta pedidos, no crea tablas y no adelanta lógica de ventas.

---

## 2. Estado inicial

Línea base tomada antes de modificar nada.

**Git** (`HEAD = 8739c18554`, rama `upgrade/bagisto-2.4.9`):

| Comprobación | Resultado |
|---|---|
| `git status --short` | 105 entradas |
| `git diff --stat` | 55 archivos, +2456 −1345 |
| `packages/Webkul/` | **0** cambios |
| `packages/ByLopez/CorteConfeccion/` | 97 entradas **preexistentes** |
| `packages/ByLopez/VentasAsesor/` | 7 documentos Markdown sin rastrear; **cero código** |
| Archivos raíz ya modificados | `CLAUDE.md`, `bootstrap/providers.php`, `composer.json`, `phpunit.xml`, `tests/Pest.php` |
| Sin rastrear fuera de paquetes | `docs/`, `public/themes/admin/default/build-bylopez/` |

Los cambios preexistentes de `composer.json` y `bootstrap/providers.php` corresponden
al registro de CorteConfeccion y **no se atribuyen a esta fase**.

**Base de datos** (MariaDB 10.4.28, `lpz_saas`, solo lectura):

| Dato | Valor |
|---|---|
| Tablas | 163 |
| Filas `admins` / `roles` | 6 / 4 |
| `AUTO_INCREMENT` `admins` / `roles` | 10796 / 10772 |
| Roles con algún permiso `ventas_asesor*` | 0 |

> La distancia entre filas y `AUTO_INCREMENT` indica que ejecuciones anteriores de
> pruebas con fábricas consumieron identificadores aunque sus transacciones se
> revirtieran. Motivó el diseño de pruebas sin escritura de §12.

---

## 3. Archivos creados

| Archivo | Responsabilidad |
|---|---|
| `composer.json` (del paquete) | Metadatos y PSR-4, igual que CorteConfeccion |
| `src/Providers/VentasAsesorServiceProvider.php` | Fusiona ACL y menú; carga rutas, vistas y traducciones |
| `src/Config/acl.php` | Árbol ACL de 23 claves (§6) |
| `src/Config/menu.php` | Un único nodo de menú |
| `src/Http/routes.php` | Una ruta: `GET admin/ventas-asesor` |
| `src/Http/Controllers/Admin/InicioController.php` | Muestra el perfil de acceso del administrador |
| `src/Support/AutorizacionVentasAsesor.php` | Punto único de decisión de autorización |
| `src/Enums/AlcanceVisibilidad.php` | `TODOS` · `PROPIOS` · `NINGUNO` |
| `src/Resources/views/admin/inicio/index.blade.php` | Pantalla inicial mínima |
| `src/Resources/lang/es/app.php`, `en/app.php` | Traducciones |
| `tests/helpers.php` | Administradores y roles **en memoria** |
| `tests/Unit/AutorizacionVentasAsesorTest.php` | 21 pruebas del servicio |
| `tests/Unit/AclYMenuConfiguracionTest.php` | 8 pruebas de ACL, menú y rutas |
| `tests/Feature/AccesoInicioTest.php` | 20 pruebas HTTP |
| `docs/06_FASE_01_BASE_PAQUETE_ACL.md` | Este documento |

**Deliberadamente no creados:** migraciones (ni el directorio), modelos,
repositorios, `ModuleServiceProvider` de Concord, `EventServiceProvider`,
listeners, seeders, una clase `TestCase` propia (se reutiliza `Tests\TestCase`, así
se evita tocar `autoload-dev`) y cualquier archivo «para fases futuras».

---

## 4. Archivos modificados

### 4.1 Fuera del paquete

| Archivo | Cambio de esta fase | ¿Por qué era indispensable? |
|---|---|---|
| `composer.json` | +1 línea PSR-4 `ByLopez\VentasAsesor\` y **una coma** añadida al final de la línea preexistente de CorteConfeccion | Sin autoload no carga ninguna clase |
| `bootstrap/providers.php` | +1 `use` y +1 `VentasAsesorServiceProvider::class` | Registro del provider, igual que CorteConfeccion |
| `phpunit.xml` | +2 testsuites (`VentasAsesor Unit Test`, `VentasAsesor Feature Test`) | **Demostrado:** sin ellos `php artisan test --filter=VentasAsesor` responde «No tests found» (§12.2) |

**No modificados:** `tests/Pest.php` (las pruebas declaran `uses()` por archivo),
`config/concord.php`, `CLAUDE.md`, `packages/Webkul/`, `packages/ByLopez/CorteConfeccion/`.

Los tres cambios se aplicaron con un script que exige **exactamente una**
coincidencia del texto de anclaje antes de escribir, para no alterar contenido
preexistente por error.

### 4.2 Documentación

| Documento | Cambio |
|---|---|
| `00_STATUS.md` | Estado de la Fase 01 |
| `01_AUDITORIA_CORE_VENTAS_BAGISTO.md` | Corrección en §18.3 sobre el rol huérfano (§13) |
| `02_MODELO_FUNCIONAL_VENTAS_ASESOR.md` | Tres precisiones: §5.1, §6.3, §8.6 (§14) |
| `03_ROADMAP_IMPLEMENTACION.md` | Precisiones en Fases 02, 05, 09 y 12; estado de la Fase 01 |
| `04_DECISIONES_PENDIENTES.md` | Nueva **D-27**; precisiones en D-05 y D-06 |

---

## 5. Forma de registro del paquete

Sigue el patrón real de CorteConfeccion, verificado en sus archivos:

1. **Autoload:** PSR-4 en el `composer.json` raíz, porque el paquete vive dentro del
   repositorio. El `composer.json` del paquete declara auto-descubrimiento, pero es
   inoperante en un paquete vendorizado; se conserva como metadato.
2. **Provider:** `bootstrap/providers.php`, justo después de
   `CorteConfeccionServiceProvider`.
3. **`register()`:** `mergeConfigFrom(…/menu.php, 'menu.admin')` y
   `mergeConfigFrom(…/acl.php, 'acl')`.
4. **`boot()`:** rutas con `['web', PreventRequestsDuringMaintenance::class]`;
   traducciones y vistas con el espacio de nombres `ventasasesor`.
5. **Sin Concord:** esta fase no crea ni sustituye modelos.

**Ruta registrada** (`php artisan route:list --path=ventas-asesor -v`):

```
GET|HEAD  admin/ventas-asesor  admin.ventas_asesor.inicio.index
          › ByLopez\VentasAsesor\Http\Controllers\Admin\InicioController@index
          ⇂ web
          ⇂ Webkul\Core\Http\Middleware\PreventRequestsDuringMaintenance
          ⇂ Webkul\User\Http\Middleware\Bouncer
          ⇂ Webkul\Core\Http\Middleware\NoCacheMiddleware
```

El alias `admin` corresponde a `Webkul\User\Http\Middleware\Bouncer`
(`UserServiceProvider.php:28`).

Se ejecutaron `composer dump-autoload` (14 947 clases) y `php artisan optimize:clear`.
`CACHE_STORE=file` y `SESSION_DRIVER=file`: limpiar cachés **no** escribe en la base
de datos.

---

## 6. Árbol ACL definitivo

| Clave | Tipo | Concede en Fase 01 | Fase que la usará |
|---|---|---|---|
| `ventas_asesor` | **Raíz** | **La pantalla de inicio** (única) | 01 |
| `ventas_asesor.operar_como_asesor` | **Marcador de identidad** | Nada: identifica al asesor | 02 |
| `ventas_asesor.pedidos` | Agrupador | Nada | — |
| `ventas_asesor.pedidos.create` | Hoja | Nada | 03, 04 |
| `ventas_asesor.pedidos.view_own` | Hoja | Nada (alcance `PROPIOS`) | 05 |
| `ventas_asesor.pedidos.view_all` | Hoja | Nada (alcance `TODOS`) | 05 |
| `ventas_asesor.pedidos.assign` | Hoja | Nada | 05 |
| `ventas_asesor.pedidos.reassign` | Hoja | Nada | 05 |
| `ventas_asesor.logistica` | Agrupador | Nada | — |
| `ventas_asesor.logistica.view` | Hoja | Nada | 06 |
| `ventas_asesor.logistica.manage` | Hoja | Nada | 06 |
| `ventas_asesor.evidencias` | Agrupador | Nada | — |
| `ventas_asesor.evidencias.create` | Hoja | Nada | 11 |
| `ventas_asesor.evidencias.delete` | Hoja | Nada | 11 |
| `ventas_asesor.comisiones` | Agrupador | Nada | — |
| `ventas_asesor.comisiones.view_own` | Hoja | Nada | 09 |
| `ventas_asesor.comisiones.view_all` | Hoja | Nada | 09 |
| `ventas_asesor.comisiones.manage` | Hoja | Nada | 08 |
| `ventas_asesor.comisiones.approve` | Hoja | Nada | 09 |
| `ventas_asesor.comisiones.pay` | Hoja | Nada | 09 |
| `ventas_asesor.kpis` | Agrupador | Nada | — |
| `ventas_asesor.kpis.view_own` | Hoja | Nada | 10 |
| `ventas_asesor.kpis.view_all` | Hoja | Nada | 10 |

**Depuración frente a `[M-§12.2]`.** Se conservan las 19 claves de ese documento. Se
añaden cuatro agrupadores (`logistica`, `evidencias`, `comisiones`, `kpis`) porque
`Webkul\Core\Acl::processSubAclItems()` descarta los hijos cuyo padre no es una
entrada ACL: sin ellos, esas claves serían invisibles en Configuración > Roles y
nadie podría concederlas. «Administrar evidencias» queda como dos hojas
explícitas (`create`, `delete`) porque cada mutación futura necesita su propia
clave.

**Justificación de la clave raíz como acceso a la pantalla.** Corresponde al
concepto «acceder al módulo» y solo concede una pantalla de lectura sin datos de
negocio. No se convertirá en permiso implícito de operaciones: la prueba
«la ruta de inicio la autoriza únicamente la clave raíz» comprueba que la raíz
aparece en **una sola** entrada y con **una sola** ruta.

**Por qué todas las claves apuntan a la ruta de inicio.** Ver §9.3.

---

## 7. Permiso que identifica al asesor

```
ventas_asesor.operar_como_asesor
```

Expuesto como `AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR`.

- Es una **capacidad**, no un acceso: no autoriza rutas ni concede visibilidad.
- El nombre del rol **no se consulta en ningún punto**. Una prueba recorre todo
  `src/` y verifica que la cadena `ASESOR VENTAS` no aparece.
- Ningún rol lo tiene hoy (0 roles). **No se concedió automáticamente a nadie** y no
  se modificó ningún rol: se asigna desde Configuración > Roles.

---

## 8. Reglas de autorización

| # | Regla | Dónde se aplica |
|---|---|---|
| RA-1 | La pantalla exige sesión administrativa y la clave `ventas_asesor` | Middleware `Bouncer` de Bagisto, en servidor |
| RA-2 | Es asesor quien tiene el permiso marcador, sea cual sea el nombre de su rol | `AutorizacionVentasAsesor::esAsesor()` |
| RA-3 | Visibilidad: `view_all` → `TODOS`; si no, `view_own` → `PROPIOS`; si no, `NINGUNO` | `alcanceDeVisibilidad()` |
| RA-4 | Sin administrador, sin rol o con permisos malformados: **se niega**. **Corregido en la Fase 01A:** la versión de la Fase 01 concedía con cualquier `permission_type` distinto de `all` si la clave estaba en el arreglo (por ejemplo `otro` con `["ventas_asesor"]`), porque delegaba en `Admin::hasPermission()`. Ahora solo `all` y `custom` pueden conceder | `tienePermiso()` |
| RA-5 | `permission_type = all` concede todo, igual que Bagisto | `tienePermiso()`; ver D-27 |
| RA-6 | Sin atribución válida y sin `view_all`: **se niega** | `puedeVerRegistroDe()` |
| RA-7 | Los permisos de ventas del core no conceden nada del módulo | Probado con los 18 permisos reales de `ASESOR VENTAS` |

**Respuestas HTTP verificadas por prueba:**

| Situación | Respuesta |
|---|---|
| Sin sesión | 302 al login administrativo |
| Sesión sin `ventas_asesor` | **401** |
| Solo `ventas_asesor` | 200 |
| Las 22 claves hijas **sin** la raíz | **401** |
| Los 18 permisos actuales de `ASESOR VENTAS` | **401** |
| `permission_type = all` | 200, asesor, alcance `todos` |
| Rol huérfano | **401**, sin llegar al módulo |
| Administrador inactivo | 302 al login y sesión cerrada |
| Rol personalizado sin permisos | 302 al login y sesión cerrada |
| Parámetros manipulados en la URL | Sin efecto sobre la autorización |
| Subruta inexistente | 404 |

**Por qué el servicio no delega a ciegas en Bagisto.** Verificado en tinker, sin
escribir: `bouncer()->hasPermission()` lanza error con rol nulo, y
`Admin::hasPermission()` lanza `TypeError` con `permission_type = all` (sus
`permissions` son nulos). El servicio ataja ambos casos y, en los demás, delega en
`Admin::hasPermission()`. Una prueba compara sus respuestas con las de
`bouncer()->hasPermission()` para roles personalizados y para `all`: si Bagisto
cambia su semántica, esa prueba lo detecta.

---

## 9. Comportamiento de los permisos planos

### 9.1 Evidencia en código

| Mecanismo | Archivo | Comportamiento |
|---|---|---|
| Ruta → clave | `Webkul\Core\Acl::getRoles()` | `flatMap` + `mapWithKeys`: **si varias entradas listan la misma ruta, gana la última** |
| Ruta no mapeada | `Bouncer::checkIfAuthorized()` | `abort(401)`: falla cerrado |
| Comprobación | `Admin::hasPermission()` | `in_array($permiso, $role->permissions)`: **coincidencia exacta** |
| Acceso total | `Bouncer::isPermissionsEmpty()` | `permission_type === 'all'` retorna antes de comprobar la ruta |
| Menú | `Webkul\Core\Menu::getItems()` | `bouncer()->hasPermission($item['key'])` clave por clave |

**Conclusión:** una clave padre **no** concede sus hijas y una hija **no** concede a
su padre. No hay herencia efectiva.

### 9.2 La regla «gana la última» y la clave raíz

Verificado dos veces:

1. **Tinker:** dos entradas con la misma ruta → la autoriza la segunda.
2. **Prueba de mutación:** se añadió temporalmente al final de `acl.php` una entrada
   `ventas_asesor.kpis.view_all` con la ruta de inicio. Las dos pruebas guardianas
   **fallaron**; se restauró el archivo (idéntico byte a byte, `cmp`) y **pasaron**.

Por eso la entrada `ventas_asesor` va **al final** del archivo, con un comentario que
lo exige.

### 9.3 Por qué toda clave necesita una ruta real

`SessionController::redirectToFirstAccessibleRoute()` hace
`redirect()->route($permiso['route'])` con el primer permiso con punto de un rol sin
`dashboard`. Una clave con ruta vacía o inexistente **rompería el inicio de sesión**
de ese rol. El rol real `ASESOR VENTAS` no tiene `dashboard`.

Solución: toda clave apunta a `admin.ventas_asesor.inicio.index` (GET, sin
parámetros). Gracias a §9.2, solo la raíz autoriza esa ruta. Una prueba recorre las
23 claves y comprueba que su ruta existe, acepta GET y no tiene parámetros.

**Obligación para fases futuras:** cuando una fase cree la ruta de una hoja, debe
apuntar esa hoja a su **propia** ruta GET y mantener verdes estas pruebas.

### 9.4 Por qué el menú tiene un solo nodo

`Menu::getItems()` filtra clave por clave y luego reconstruye el árbol con
`Arr::undot()`. Un hijo concedido sin su padre produce un nodo sin `name` y el menú
lateral falla. Con un único nodo ese caso no puede ocurrir.

---

## 10. Alcance real de `view_own` y `view_all`

### 10.1 Implementado y probado

- Distinción entre ambos permisos y el alcance resultante (`TODOS`, `PROPIOS`,
  `NINGUNO`), incluidos los casos «ambos», «solo marcador» y «`view_own` de
  comisiones no es `view_own` de pedidos».
- La regla de registro individual `puedeVerRegistroDe(?Admin, ?int $advisorId)`,
  función pura sin consultas, probada con 8 combinaciones.
- La pantalla muestra el alcance calculado **en servidor**; parámetros de URL como
  `?alcance=todos&view_all=1` no lo alteran.

### 10.2 Lo que NO está implementado y no se afirma

> **No existe hoy ninguna ruta que reciba un `order_id`.** No hay tabla de
> atribución, modelo, listado ni detalle de pedidos. Por tanto, **esta fase no
> protege ningún pedido contra acceso horizontal (IDOR)**, porque no hay nada que
> proteger todavía.

`puedeVerRegistroDe()` no tiene consumidor HTTP. Es la regla que las Fases 02 y 05
deben usar; no es una garantía vigente.

---

## 11. Aspectos diferidos a las Fases 02 y 05

| Aspecto | Fase |
|---|---|
| Tabla `bylopez_va_order_attributions` y su `advisor_id` | 02 |
| Consumir `puedeVerRegistroDe($admin, $atribucion?->advisor_id)` en toda ruta con `order_id` | 02, 05 |
| **Pruebas IDOR obligatorias**: `order_id` ajeno con `view_own` → denegado | 02, 05 |
| Filtrado de **consultas** de listados por `advisor_id` cuando el alcance es `PROPIOS` (no basta comprobar registro a registro) | 05 |
| Validar al asignar que el asesor destino tiene el permiso marcador: `tienePermiso($adminDestino, …)`, que admite un administrador distinto del autenticado, cosa que `bouncer()` no permite | 05 |
| Decisión **D-27**: si un administrador `all` es asesor por defecto | 02 |

Estas obligaciones quedaron añadidas al roadmap.

---

## 12. Resultado de pruebas

### 12.1 Pruebas del paquete

**34 funciones de prueba, 49 casos expandidos, 229 aserciones, todos en verde**
sobre la base de desarrollo.

> **Precisión (Fase 01A).** La cifra «49 pruebas» contaba casos expandidos de
> datasets de Pest, no 49 flujos independientes: eran 34 funciones `test()`, de las
> cuales tres tenían dataset (6, 8 y 4 casos). Tras la Fase 01A el paquete tiene
> **42 funciones y 72 casos expandidos** (51 del suite Unit y 21 del Feature), y ya
> no se ejecutan sobre la base de desarrollo. Resultados vigentes en
> `06A_CIERRE_FASE_01_Y_ENTORNO_PRUEBAS.md`.

| Requisito del encargo (§13) | Prueba que lo cubre |
|---|---|
| 1. El provider carga | «el provider del paquete está cargado y registra la ruta de inicio» |
| 2. La ACL se fusiona | «la ACL del paquete se fusiona sin reemplazar la de Bagisto…» · «el árbol ACL del módulo es exactamente el definido» |
| 3. El menú se fusiona | «el menú del paquete tiene un único nodo…» · «el menú muestra el módulo solo a quien tiene la clave raíz» |
| 4. La ruta existe | pruebas 1 y «toda ruta del paquete exige el middleware admin…» |
| 5. No autenticado no entra | «un usuario no autenticado es redirigido al login» |
| 6. Autenticado sin permiso | «un administrador sin la clave del módulo recibe 401» |
| 7. Con permiso accede | «la clave raíz basta para entrar…» |
| 8. `ASESOR VENTAS` sin marcador no es asesor | unitaria + HTTP |
| 9. Otro nombre con marcador sí es asesor | unitaria + HTTP |
| 10. `permission_type = all` como Bagisto | «decide igual que el bouncer…» · unitaria + HTTP |
| 11. Sin permisos amplios de ventas | «la clave raíz basta…» · «los 18 permisos actuales… no abren el módulo» |
| 12. `view_own` ≠ `view_all` | 6 casos unitarios + 4 HTTP |
| 13. La vista no consulta tablas inexistentes | «la pantalla inicial no escribe ni consulta tablas propias, pedidos ni RMA» |
| 14. Protección en servidor | «todas las claves hijas sin la raíz…» · rol huérfano · mutación de §9.2 |
| 15. Manipular la URL | «manipular la URL no altera la autorización del servidor» |
| 16. Sin dependencia del nombre de rol | pruebas 8, 9 y búsqueda estática en `src/` |
| 17. Sin dependencia de RMA | «el paquete no depende de RMA…» |

La prueba 13 registra todo el SQL de la petición con `DB::listen` y comprueba que no
hay consultas a `bylopez_va_*`, a `orders` ni a tablas `rma*`, **ni ninguna
sentencia `INSERT`, `UPDATE`, `DELETE` o `REPLACE`**.

### 12.2 Ejecuciones

| Ejecución | Resultado |
|---|---|
| `vendor/bin/pest packages/ByLopez/VentasAsesor/tests` (antes de tocar `phpunit.xml`) | **49 passed** (229 aserciones), 19,21 s |
| `php artisan test --filter=VentasAsesor` **sin** testsuites registrados | **«No tests found»** → modificar `phpunit.xml` era indispensable |
| `php artisan test --filter=VentasAsesor` **con** testsuites | **49 passed** (229 aserciones), 14,56 s |
| Mutación de §9.2 | 2 fallos esperados en el mutante; 2 aprobadas tras restaurar |

### 12.3 Cero escrituras en la base de desarrollo

No existe base de pruebas aislada: `Tests\TestCase` usa `DatabaseTransactions`
sobre `lpz_saas`. Para no consumir ni siquiera `AUTO_INCREMENT`, las pruebas de este
paquete construyen administradores y roles **en memoria** (`forceFill` +
`setRelation`) y nunca los guardan.

Instantánea de solo lectura antes y después de **todas** las ejecuciones propias
(las 49 pruebas y la mutación):

| Comprobación | Resultado |
|---|---|
| `AUTO_INCREMENT` de las 163 tablas | **Ninguno cambió** |
| `CHECKSUM TABLE` de `admins`, `roles`, `core_config`, `orders`, `rma*` | **Idénticos** |
| Conteos de filas | **Idénticos** |

### 12.4 Regresión

**Comando:**

```
vendor/bin/pest packages/ByLopez/CorteConfeccion/tests
                packages/Webkul/Admin/tests/Feature/Settings/RoleTest.php
                packages/Webkul/Admin/tests/Feature/Settings/UsersTest.php
                packages/Webkul/Admin/tests/Feature/Admin/TwoFactorAuthenticationTest.php
```

**Criterio de selección.** CorteConfeccion fusiona su ACL y su menú en las mismas
claves de configuración que este paquete. `RoleTest` usa `acl()->getRoles()->random()`,
que ahora incluye las claves nuevas. `UsersTest` y `TwoFactorAuthenticationTest`
recorren los flujos de usuario, sesión y `Bouncer`.

**Resultado:** 170 pruebas — **166 aprobadas, 4 fallidas** (535 aserciones), 88,31 s.
Todas las de CorteConfeccion, `RoleTest` y `TwoFactorAuthenticationTest` pasaron.
Los 4 fallos están en `UsersTest`.

**Clasificación de los fallos: COMPATIBLES con datos y locale preexistentes de la
base de desarrollo. NO son preexistentes confirmados.**

> **Corrección (Fase 01A).** La versión original de esta sección decía
> «PREEXISTENTES… No los causa la Fase 01». Esa afirmación no estaba demostrada:
> no existía una ejecución de referencia comparable (la misma suite, sobre los mismos
> datos, sin el paquete). La repetición en `lpz_saas_testing` tampoco sirve de
> referencia, porque allí `UsersTest` falla por completo al no haber datos base.
> Lo que sí está verificado es el **mecanismo** de cada fallo, descrito en la tabla.

| Prueba | Aserción que falla | Causa | Evidencia (solo lectura) |
|---|---|---|---|
| `should store the newly created admin` | `assertSeeText(trans('…create-success'))` | Con locale `es` el mensaje es «Usuario creado con **é**xito.»; la respuesta JSON escapa la tilde como `é` y el texto nunca coincide | `app()->getLocale() = es`; `json_encode` produce `"Usuario creado con éxito."`; en inglés el texto no tiene tilde |
| `should update the existing admin` | `assertSeeText(trans('…update-success'))` | Mismo mecanismo: «Usuario actualizado con éxito.» | Ídem |
| `should delete the existing admin` | `assertSeeText(trans('…delete-success'))` | Mismo mecanismo: «Usuario eliminado con éxito.» | Ídem |
| `should returns the user and its roles` | `assertJsonPath('roles.0.name', 'Administrator')` | En la base de desarrollo el rol id 1 se llama `Administrador` | `roles.id = 1 → Administrador` |

Ninguna de las cuatro aserciones involucra ACL, menú, rutas, providers ni el espacio
de traducciones `ventasasesor`, y el módulo no cambia el locale ni ningún dato. Son
consecuencia de ejecutar la suite oficial contra una base real en español.

> **Límite de la evidencia.** No se reejecutó `UsersTest` sin el provider del
> paquete: habría consumido todavía más `AUTO_INCREMENT` de la base de desarrollo.
> La clasificación se apoya en el **mecanismo verificado** de cada fallo, no en una
> comparación con/sin paquete.

**Efecto de la regresión en la base de desarrollo.** A diferencia de las pruebas de
este paquete, las suites existentes crean filas con fábricas dentro de
`DatabaseTransactions`:

| Comprobación | Resultado |
|---|---|
| Checksums de `admins`, `roles`, `core_config`, `orders`, `rma*` | **Idénticos** |
| Conteos de filas | **Idénticos**: ninguna fila persistió |
| `AUTO_INCREMENT` | **Avanzó en 23 tablas**, entre ellas `admins` 10796→11194, `roles` 10772→11127, `products` 5568→5740, `channels` 28→29 y 15 tablas `bylopez_cc_*` |

Es el comportamiento que esas suites ya tenían antes de esta fase (§2). No se
modificó ningún dato, pero se registra con exactitud porque consume identificadores
de la base de desarrollo. Ver RF-6.

---

## 13. Riesgo del usuario con rol huérfano

**Consulta de solo lectura (2026-09-13):** el administrador **id 52** sigue con
`role_id = 3`. Roles válidos: 1 (Administrador, `all`), 2 (ASESOR VENTAS), 4
(BODEGA), 52 (TALLER CONFECCION). **La inconsistencia continúa.**

| Momento | Qué ocurre | Dónde falla | Evidencia |
|---|---|---|---|
| Petición a una ruta administrativa con sesión | **401** | `Bouncer::isPermissionsEmpty()`, **antes** del controlador del módulo | Prueba HTTP: un espía sobre `AutorizacionVentasAsesor` confirma que nunca se invoca |
| Inicio de sesión | **500** | `SessionController::store()` llama a `bouncer()->hasPermission('dashboard')` | **Inferido de alta confianza**: la prueba unitaria confirma que ese método lanza `ErrorException` con rol nulo; no se probó por HTTP porque exigiría una fila real en `admins` |
| Servicio del módulo | Niega sin fallar | — | Prueba unitaria |

**Conclusión:** el fallo ocurre **dentro de Bagisto, antes** de llegar al módulo.
`VentasAsesor` **no puede** resolverlo y no lo intenta.

**Acción manual recomendada:** que un administrador asigne un rol válido a ese
usuario desde Configuración > Usuarios, o lo desactive si ya no debe entrar. **No se
modificó la base de datos, no se reasignó el usuario, no se creó ningún rol y no se
tocó Webkul.**

Este análisis corrige una afirmación demasiado amplia de la auditoría
(`[A-§18.3]`: «HTTP 500 para cualquier pantalla»); la corrección quedó anotada allí.

---

## 14. Precisiones sobre comisión, atribución e idempotencia

Añadidas a la documentación **sin cambiar decisiones aprobadas** y sin implementar
esquemas.

| Precisión | Contenido | Dónde quedó |
|---|---|---|
| **Identidad de la línea de comisión** | `UNIQUE(delivery_event_id)` es demasiado amplia si una entrega agrupa varios `shipment_items`. Candidata: `delivery_event_id + shipment_item_id`, con porción o secuencia para entregas parciales del mismo ítem. **Índice final no aprobado.** | `[M-§8.6]`, roadmap Fase 09 |
| **Atribución 1:1 conceptual** | No se agrega `advisor_id` a `orders`; la atribución vive en una tabla propia 1:1 con `orders` | `[M-§5.1]`, roadmap Fase 02, D-05 |
| **Idempotencia externa** | `external_reference` no es única por sí sola; evaluar `creation_source + external_reference` o `integration_id + external_reference` | `[M-§6.3]`, roadmap Fase 12 |

---

## 15. Relación con la auditoría RMA

`05A_AUDITORIA_RMA_Y_MAPA_CORE_RELACIONADO.md` sigue siendo la fuente vigente. Esta
fase **no** la reabre.

| Comprobación | Resultado |
|---|---|
| Referencias a `Webkul\RMA` en `src/` | **Ninguna** (prueba) |
| Consultas a tablas `rma*` al abrir la pantalla | **Ninguna** (prueba) |
| La pantalla funciona con RMA inoperante (catálogos vacíos) | **Sí** (prueba) |
| Seeders de RMA ejecutados | **Ninguno** |
| Archivos de RMA modificados | **Ninguno** |
| Riesgos RMA corregidos | **Ninguno**, por alcance: siguen asignados a la Fase 07 |
| RT-01, RT-02, D-24, D-25, D-26 | Sin cambios |
| Commit `00a71449c0` de upstream | Sigue pendiente de evaluar antes de la Fase 07 |

---

## 16. Evidencia de no modificación de Webkul

| Comprobación | Línea base (§2) | Final | Lectura |
|---|---|---|---|
| `git status --short` | 105 entradas | 105 entradas | Sin entradas nuevas: `VentasAsesor/` ya figuraba como directorio sin rastrear |
| `git status --short -- packages/Webkul/` | 0 | **0** | **Webkul intacto**, RMA incluido |
| `git status --short -- packages/ByLopez/CorteConfeccion/` | 97 | **97** | Todas preexistentes |
| Archivos de Webkul o CorteConfeccion con fecha de hoy (`find -newermt 2026-09-13`) | — | **Ninguno** | Confirma que las 97 no son de esta fase |
| `git diff --stat` | 55 archivos, +2456 −1345 | 55 archivos, **+2469 −1346** | Diferencia **+13 −1**, exactamente los cambios de §4.1 |

**Desglose de la diferencia de `git diff --stat`:**

| Archivo | Inserciones | Eliminaciones |
|---|---|---|
| `composer.json` | 2 | 1 (la línea de CorteConfeccion, reescrita con coma) |
| `bootstrap/providers.php` | 2 | 0 |
| `phpunit.xml` | 9 | 0 |
| **Total** | **13** | **1** |

**Inventario sin rastrear de `packages/ByLopez/VentasAsesor/`** (23 archivos):
`composer.json`; 8 documentos en `docs/` (7 previos y este); 10 en `src/`
(provider, `acl.php`, `menu.php`, `routes.php`, controlador, enum, servicio, vista,
traducciones `es` y `en`); 4 en `tests/` (`helpers.php` y 3 archivos de pruebas).

**Nunca ejecutados en esta fase:** `php artisan migrate`, `php artisan db:seed`,
`php artisan bagisto:install`, seeders de RMA, `git commit`, `push`, `pull`,
`merge`, `rebase`, `cherry-pick`, `reset`, `checkout` ni `stash`.

**Base de datos:** cero escrituras de las pruebas del paquete (§12.3). La regresión
de suites existentes no dejó filas, pero avanzó `AUTO_INCREMENT` (§12.4).

---

## 17. Riesgos y deuda pendiente

| # | Riesgo | Nivel | Tratamiento |
|---|---|---|---|
| RF-1 | Un administrador `permission_type = all` se reconoce como asesor con alcance total (comportamiento oficial de Bagisto). Sin regla adicional, la Fase 02 le atribuiría automáticamente sus ventas | **Alto para Fase 02** | Nueva decisión **D-27**, bloquea la Fase 02 |
| RF-2 | Un rol con **solo** `ventas_asesor`, sin otra clave con punto y sin `dashboard`, aterrizaría tras el login en el dashboard y recibiría 401 (`redirectToFirstAccessibleRoute` no encuentra hijo accesible). Es comportamiento del core; le ocurre igual al rol TALLER CONFECCION de CorteConfeccion | Medio, **inferido del código** | Conceder además `ventas_asesor.operar_como_asesor` u otra clave con punto, o `dashboard` |
| RF-3 | Un rol con claves hijas pero **sin** la raíz es redirigido a la pantalla de inicio y recibe 401 | Bajo: falla cerrado | Conceder siempre la raíz a quien use el módulo |
| RF-4 | La seguridad de la ruta depende del **orden** de `acl.php` | Medio | Comentario en el archivo, prueba guardiana y mutación verificada |
| RF-5 | Las 22 claves no raíz apuntan provisionalmente a la pantalla de inicio | Bajo | Cada fase debe darle a su hoja su propia ruta (§9.3) |
| RF-6 | Sin base de pruebas aislada: las suites con fábricas (CorteConfeccion, Admin) consumen `AUTO_INCREMENT` de la base de desarrollo | Medio, **preexistente** | Las pruebas de este paquete no lo hacen (§12.3); medido en la regresión (§12.4) |
| RF-7 | El rol `ASESOR VENTAS` conserva 18 permisos amplios del core | **Alto operativo, preexistente** | El módulo no los necesita (probado); reducirlos es decisión operativa |
| RF-8 | Usuario con rol huérfano | Medio operativo, preexistente | §13 |
| RF-9 | `bagisto:translations:check` solo escanea `packages/Webkul`: no valida las traducciones del paquete | Bajo | `es` y `en` completas y en uso |
| RF-10 | `puedeVerRegistroDe()` no tiene consumidor HTTP | Informativo | Por diseño; su uso es obligatorio en Fases 02 y 05 |

---

## 18. Criterios de salida

| Criterio | Estado |
|---|---|
| El paquete está registrado | ✅ |
| El paquete carga sin errores | ✅ |
| La ruta administrativa inicial funciona | ✅ |
| ACL y menú se cargan correctamente | ✅ |
| La identificación del asesor depende del permiso ACL | ✅ |
| No depende del nombre del rol | ✅ |
| Las comprobaciones se hacen en servidor | ✅ |
| Las pruebas específicas pasan | ✅ 49/49 |
| `view_own` y `view_all` quedan diferenciados | ✅ |
| Las limitaciones del aislamiento horizontal se declaran | ✅ §10.2 |
| No se modificó Webkul | ✅ §16 |
| No se modificó CorteConfeccion | ✅ §16 |
| No se modificó RMA | ✅ |
| No se crearon tablas | ✅ |
| No se ejecutaron seeders | ✅ |
| No se adelantaron fases posteriores | ✅ |
