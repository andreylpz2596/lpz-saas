# 06A_CIERRE_FASE_01_Y_ENTORNO_PRUEBAS.md

**Fase 01A — Cierre técnico, autorización segura y aislamiento de pruebas.**

**Fecha:** 2026-09-13
**Estado:** **COMPLETADA**. Con ella, la **Fase 01 queda COMPLETADA**.

> La primera pasada terminó **PARCIAL / BLOQUEADA POR ENTORNO DE PRUEBAS**: la base aislada
> no tenía datos base de Bagisto. El usuario **autorizó expresamente la opción A** (§9.1):
> sembrar esos datos solo en `lpz_saas_testing` y sin RMA. Tras la siembra se cumplen todos
> los criterios de salida (§16).

Referencias: `[F01-§n]` → `06_FASE_01_BASE_PAQUETE_ACL.md`;
`[M-§n]` → `02_MODELO_FUNCIONAL_VENTAS_ASESOR.md`; `D-nn` → `04_DECISIONES_PENDIENTES.md`.

---

## 0. Línea base y cambios ajenos

Tomada antes de modificar nada.

| Comprobación | Cierre de la Fase 01 | Inicio de la Fase 01A |
|---|---|---|
| `git status --short` | 105 | **110** |
| `git diff --stat` | +2469 −1346 | **+2494 −1346** |
| `packages/Webkul/` | 0 | 0 |
| `packages/ByLopez/CorteConfeccion/` | 97 | **101** |
| `.env.testing` | no existía | no existía (ya ignorado por `.gitignore:6`) |
| Base de PHPUnit | `lpz_saas` (sin `DB_*` en `phpunit.xml`) | `lpz_saas` |

**Las 5 entradas nuevas no son de este módulo.** Son 4 archivos nuevos o modificados en
CorteConfeccion (entre ellos la migración
`2026_09_13_000001_add_retorno_fields_to_bylopez_cc_ordenes_confeccion` y
`OrdenConfeccionGate`) y el archivo `packages/ByLopez/VentasAsesor.rar`. Además, el
`AUTO_INCREMENT` de `admins` en desarrollo pasó de 11194 (cierre de la Fase 01) a
**12114**: hubo ejecuciones de pruebas sobre desarrollo **ajenas a estas fases**.

---

## 1. Hallazgo de autorización

La revisión externa tenía razón. En la Fase 01, `AutorizacionVentasAsesor::tienePermiso()`:

1. concedía con `permission_type = all`;
2. negaba si `permissions` no era arreglo;
3. en cualquier otro caso **delegaba en `Admin::hasPermission()`**.

`Admin::hasPermission()` solo niega cuando el tipo es `custom` y los permisos están vacíos;
en los demás casos devuelve `in_array($permiso, $permisos)`, **no estricto** y **sin mirar
el tipo**. Resultado verificado por prueba:

| Rol | Fase 01 | Correcto |
|---|---|---|
| `permission_type = otro`, `["ventas_asesor"]` | **concedía** | niega |
| `permission_type = custom`, `[true]` | **concedía** (`'ventas_asesor' == true`) | niega |

La prueba de la Fase 01 «tipo de permiso desconocido» usaba `permissions = null` y por eso
**ocultaba** el defecto. La afirmación «falla cerrado» de `[F01-§8]` era inexacta; quedó
corregida allí.

Además, **el middleware `Bouncer` de Bagisto también deja pasar** a un rol con tipo
desconocido que liste la clave, porque usa ese mismo `Admin::hasPermission()`. Se demuestra
en la prueba HTTP de §3.

**Impacto real en la Fase 01:** bajo, porque la pantalla solo muestra el perfil del propio
administrador. Pero la regla es la base de la visibilidad de pedidos de las Fases 02 y 05.

---

## 2. Corrección aplicada

`packages/ByLopez/VentasAsesor/src/Support/AutorizacionVentasAsesor.php`:

```php
if (! $rol)                               return false;
if ($rol->permission_type === 'all')      return true;
if ($rol->permission_type !== 'custom')   return false;

$permisos = $rol->permissions;
if (! is_array($permisos) || count(array_filter($permisos, 'is_string')) !== count($permisos)) {
    return false;
}

return in_array($permiso, $permisos, true);
```

| Caso | Resultado |
|---|---|
| `all` | concede (semántica oficial de Bagisto) |
| `custom` con la clave exacta | concede |
| `custom` sin la clave | niega |
| `custom` con permisos nulos, no arreglo o con elementos no cadena | niega |
| Tipo vacío, nulo o desconocido | niega |
| Rol nulo o administrador nulo | niega |

Ya **no** se delega en `Admin::hasPermission()`. El nombre del rol sigue sin consultarse.

**Defensa en el controlador.** `InicioController::index()` añade
`abort_unless($autorizacion->tienePermiso($admin, PERMISO_MODULO), 401)`, porque el Bouncer
de Bagisto no rechaza el tipo desconocido. Nueva constante
`AutorizacionVentasAsesor::PERMISO_MODULO = 'ventas_asesor'`.

**D-27 no se resolvió en código:** `all` sigue reconociéndose como asesor.

---

## 3. Pruebas nuevas

| Prueba | Tipo | Qué demuestra |
|---|---|---|
| «solo permission_type all o custom pueden conceder…» | Unit, **dataset de 10 casos** | `custom` con clave: sí · `custom` sin clave: no · `all`: sí · `otro`, `''` y `null` con la clave: **no** · `custom` con permisos nulos, no arreglo, `[true]` o `['ventas_asesor', 1]`: **no** |
| «Admin::hasPermission de Bagisto concede en casos que el servicio rechaza» | Unit | Documenta el defecto del core con `otro` y con `[true]` |
| «falla cerrado sin administrador o con rol nulo» | Unit | Sustituye a la prueba que ocultaba el defecto |
| «un rol con permission_type desconocido pasa el Bouncer de Bagisto pero el módulo lo rechaza» | Feature | Con un espía, el controlador **sí** recibe la petición; con el servicio real, **401** |
| `GuardBaseDePruebasTest` | Unit, 5 funciones y 11 casos | §8 |

**Pruebas de mutación** (copia de seguridad, mutación, ejecución, restauración, `cmp`):

| Mutante | Casos que fallan | Esperado |
|---|---|---|
| Sin el filtro «solo `custom`» | 3: tipo desconocido, vacío y nulo | ✅ |
| Comparación no estricta y sin validar elementos | 2: `[true]` y `['ventas_asesor', 1]` | ✅ |
| Original restaurado (idéntico byte a byte) | 0 de 10 | ✅ |

**Error propio corregido durante la fase:** la primera versión del dataset comprobaba también
`esAsesor()` añadiendo el permiso marcador, lo que en el caso «custom sin el permiso»
convertía al administrador legítimamente en asesor. Fallaba la prueba, no el servicio; se
retiró esa aserción.

---

## 4. Auditoría del registro del paquete

| Punto | Resultado | Evidencia |
|---|---|---|
| PSR-4 `ByLopez\VentasAsesor\` en `composer.json` | **Una sola vez** en `autoload`; ninguna en `autoload-dev` | `json_decode` de `composer.json`; `vendor/composer/autoload_psr4.php:235` |
| Provider en `bootstrap/providers.php` | **Una sola entrada**: el `use` y un elemento de la lista | `grep -c` = 2 |
| ¿Instalado por Composer? | **No** | Ausente de `composer.lock` y de `installed.json`; `composer show bylopez/ventas-asesor` → «Package not found» |
| `extra.laravel.providers` del `composer.json` interno | **Inerte hoy** | El descubrimiento lee `installed.json`; no figura en `bootstrap/cache/packages.php` |
| Aparición en `bootstrap/cache/services.php` | Esperada | Manifiesto compilado desde `bootstrap/providers.php` |
| `repositories` del `composer.json` raíz | Tipo `path` sobre `packages/*/*` con symlink | Ver §5 |
| `composer dump-autoload` | 14 950 clases, ningún cambio rastreado | §15 |

### 4.1 Cambios de los archivos raíz

| Archivo | Cambio de la Fase 01A | Cambios previos |
|---|---|---|
| `composer.json` | ninguno (Fase 01: +1 línea PSR-4 y una coma) | `autoload-dev` de CorteConfeccion |
| `bootstrap/providers.php` | ninguno (Fase 01: +2 líneas) | registro de CorteConfeccion |
| `phpunit.xml` | ninguno (Fase 01: +2 testsuites) | 2 testsuites de CorteConfeccion |
| `tests/TestCase.php` | **guard global** | — |
| `.gitignore` | ninguno: ya ignoraba `.env.testing` | — |
| `.env.testing.example` | **nuevo**, sin secretos | — |
| `.env.testing` | **nuevo**, local e ignorado | — |

---

## 5. Registro efectivo del provider

| Elemento | Instancias en ejecución |
|---|---|
| `VentasAsesorServiceProvider` cargado | **1** |
| Entradas ACL con clave `ventas_asesor` | **1** |
| Claves ACL `ventas_asesor*` | **23** |
| Nodos de menú `ventas_asesor*` | **1** |
| Rutas `admin.ventas_asesor.*` | **1** |

**No hay duplicación efectiva.**

**Riesgo futuro, inerte hoy.** Por el repositorio `path` de la raíz, un
`composer require bylopez/ventas-asesor` activaría también el descubrimiento automático. Aun
así no habría doble registro efectivo: `Illuminate\Foundation\Application::register()`
(línea 885) devuelve el provider ya registrado si existe.

---

## 6. Revisión de suites duplicadas

| Comprobación | Resultado |
|---|---|
| `vendor/bin/pest --list-tests` (todas las suites) | 1288 casos, **0 líneas repetidas** |
| Casos de VentasAsesor en el listado completo | 72 |
| Suite «VentasAsesor Unit Test» | 51 |
| Suite «VentasAsesor Feature Test» | 21 |
| Suma | **72**: sin solapamiento |

### 6.1 Conteo correcto de pruebas

| | Fase 01 | Fase 01A (final) |
|---|---|---|
| Funciones `test()` físicas | 34 | **42** (Unit 24: ACL 8, autorización 11, guard 5 · Feature 18) |
| Casos expandidos (con datasets de Pest) | 49 | **72** |
| Aserciones | 229 (sobre desarrollo) | **251** (sobre `lpz_saas_testing`) |

Las cifras de la Fase 01 no eran 49 flujos independientes: incluían 3 datasets.

---

## 7. Configuración segura de testing

**`.env.testing`** se generó con un script que lee `.env` **sin imprimir valores** y verifica la
ida y vuelta con el parser de Dotenv.

| Grupo | Claves |
|---|---|
| Copiadas de `.env` | `APP_NAME`, `APP_URL`, `APP_ADMIN_URL`, `APP_TIMEZONE`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE`, `APP_CURRENCY`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, `DB_PREFIX` |
| Propias del entorno de pruebas | `APP_ENV=testing`, `APP_DEBUG=true`, `APP_KEY` **nueva**, `APP_MAINTENANCE_DRIVER=file`, `BCRYPT_ROUNDS=4`, **`DB_DATABASE=lpz_saas_testing`**, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `FILESYSTEM_DISK=local`, `RESPONSE_CACHE_ENABLED=false`, `DEBUGBAR_ENABLED=false` |
| **No copiadas** | `MAIL_*` (host y credenciales), `AWS_*`, `REDIS_*`, la `APP_KEY` de desarrollo |
| No añadidas porque el paquete no está instalado | `TELESCOPE_ENABLED`, `PULSE_ENABLED` |

**Corrección durante la fase.** La primera versión fijaba `BROADCAST_DRIVER=null` y
`LOG_CHANNEL=null`. Laravel convierte el texto `null` en el valor PHP `null`, así que esos
servicios quedaban **sin driver**. Se **quitaron**. Verificado con `--env=testing`:
`broadcasting.default = 'null'`, `logging.default = 'stack'`,
`logging.deprecations.channel = 'null'`.

**Verificación con `--env=testing`:** `app.env = testing`; conexión `mysql` en
`127.0.0.1:3306`; base configurada **y** base realmente conectada `lpz_saas_testing`.

**Git:** `git check-ignore -v .env.testing` → `.gitignore:6`; no aparece en `git status`.
`.env.testing.example` es la plantilla sin secretos.

**`php artisan test`:** `Collision\…\TestCommand::clearEnv()` borra del proceso las variables
de `.env` antes de lanzar el subproceso; verificado en la práctica (§8).

---

## 8. Protección contra base incorrecta

`tests/TestCase.php` sobrescribe `setUpTraits()`, donde Laravel abre la transacción de
`DatabaseTransactions`. Antes de llamar a `parent::setUpTraits()`:

1. comprueba la **configuración** (base y host), **sin consultar la base**;
2. comprueba el **nombre real** de la base conectada con `SELECT DATABASE()`.

```php
public const BASE_DE_PRUEBAS = 'lpz_saas_testing';
public const HOSTS_DE_PRUEBAS = ['127.0.0.1', 'localhost', '::1'];
```

Si algo no coincide lanza `RuntimeException`:
**«Ejecución bloqueada: las pruebas no están conectadas a lpz_saas_testing.»**, o
«…no está en un host local autorizado.»

**Cobertura:** central. Todas las clases base de pruebas del repositorio heredan de
`Tests\TestCase` (`AdminTestCase`, `CoreTestCase`, `CustomerTestCase`, `DataGridTestCase`,
`EUWithdrawalTestCase`, `InstallerTestCase`, `PaymentTestCase`, `PayUTestCase`,
`RazorpayTestCase`, `ShopTestCase`, `StripeTestCase`, `CorteConfeccionTestCase`). No afecta a
comandos Artisan ni a producción.

| Evidencia | Resultado |
|---|---|
| Unitaria: `lpz_saas` en `127.0.0.1` | bloqueada, **sin conectarse** |
| Unitaria: base vacía, nombre parecido, mayúsculas, host remoto por IP o por nombre | bloqueadas |
| Unitaria: `lpz_saas_testing` en `127.0.0.1`, `localhost`, `::1` | aceptadas |
| Reflexión: `setUpTraits` declarado en `Tests\TestCase` | ✅ |
| Real, vía `php artisan test`: conexión efectiva | `lpz_saas_testing` |
| **Extremo a extremo:** `DB_DATABASE=lpz_saas_guard_inexistente vendor/bin/pest …` | **«Ejecución bloqueada…»**, 0 aserciones, **sin** «Unknown database» |
| Bloqueos en las ejecuciones reales (propias y regresión) | **0** |

**Límite:** los providers arrancan antes que `setUpTraits()`. Si alguno consultara la base al
arrancar, esa lectura ocurriría antes del guard; hoy no ocurre (no apareció «Unknown database»).

---

## 9. Preparación del esquema

| Paso | Resultado |
|---|---|
| Entorno | `APP_ENV=local`, MariaDB 10.4.28 en `127.0.0.1:3306`; no es producción ni staging |
| ¿Existía `lpz_saas_testing`? | **No** |
| Creación | `CREATE DATABASE lpz_saas_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`, sin `IF NOT EXISTS`. Se usó la collation de la conexión del proyecto; el esquema `lpz_saas` tiene por defecto `utf8mb4_general_ci` |
| Verificación A | tinker con `--env=testing`: base configurada y real `lpz_saas_testing` |
| Verificación B | `migrate:status --env=testing` → «Migration table not found» |
| Migración | `php artisan migrate --env=testing --force` → **exit 0** |
| Resultado | **206 migraciones, 160 tablas** |

**Tablas de desarrollo que ninguna migración crea:** `pickup_orders`, `pickup_timeslots`,
`visits` (163 frente a 160).

**Tras migrar, sin datos base:** `channels`, `locales`, `currencies`, `roles`, `admins`,
`core_config`, `attribute_families`, `inventory_sources`, `categories` y `customer_groups` en 0;
`attributes` en 1. Bagisto crea esos datos con los seeders de instalación, cuyo seeder
principal **también invoca los de RMA** (`Installer/…/Seeders/DatabaseSeeder.php:45`).

### 9.1 Siembra de datos base autorizada (opción A)

El usuario **autorizó expresamente la opción A**: sembrar los datos base de Bagisto **solo en
`lpz_saas_testing` y sin RMA**.

**Cómo se hizo.** Sin `bagisto:install` ni `db:seed`. Un script con
`php artisan tinker --env=testing`:

1. **abortaba** si la base real no era `lpz_saas_testing`, si el entorno no era `testing`, si no
   había exactamente 160 tablas o si ya existían locales;
2. simulaba los discos por defecto y `public` (`Storage::fake`), para no escribir imágenes en
   `storage/app` de la copia de trabajo;
3. construía los mismos parámetros que `Installer::getSeederConfiguration()` a partir de
   `APP_LOCALE` y `APP_CURRENCY`;
4. ejecutaba, **dentro de una transacción**, los sub-seeders del instalador en su orden,
   **excepto `RMASeeder`**.

**Equivalencia con el instalador.** `DatabaseManager::seed()` hace
`app(DatabaseSeeder::class)->run($parametros)`, que invoca esos mismos sub-seeders con esos
mismos parámetros. Diferencias: exclusión de RMA y transacción.

| Seeder | Ejecutado |
|---|---|
| Attribute, Category, Core, Customer, CMS, Inventory, SocialLogin, Shop (tema), User | ✅ |
| **RMA** (estados, motivos, reglas) | **❌ excluido** |

**Resultado:** `locales` 1 · `currencies` 1 · `countries` 254 · `country_states` 586 ·
`channels` 1 · `core_config` 21 · `attribute_families` 1 · `attribute_groups` 8 · `attributes` 30
· `attribute_options` 9 · `categories` 1 · `customer_groups` 3 · `inventory_sources` 1 ·
`cms_pages` 10 · `theme_customizations` 8 · `roles` 1 · `admins` 1 · `products` 0 · `orders` 0.

**RMA sigue sin habilitar:** `rma_statuses`, `rma_reasons` y `rma_rules` = 0, y ninguna clave
`sales.rma.*`. El seeder de atributos crea el grupo `rma` y los atributos de producto
`allow_rma` y `rma_rule_id`, que forman parte del catálogo estándar de atributos. No crean
catálogos ni configuración de RMA.

**Archivos:** 17 imágenes en los discos simulados (`storage/framework/testing/disks/`); **0**
archivos nuevos en `storage/app`.

**Credencial de ejemplo:** `AdminsTableSeeder` crea el administrador por defecto documentado
por Bagisto, igual que el instalador. Existe **solo** en la base local de pruebas.

---

## 10. Resultado de pruebas propias

**Ejecución vigente:** `php artisan test --filter=VentasAsesor`, sobre `lpz_saas_testing` con
datos base.

| Funciones | Casos | Aprobados | Fallidos | Aserciones | Duración |
|---|---|---|---|---|---|
| 42 | 72 | **72** | **0** | **251** | 26,85 s |

**Historia.** Antes de sembrar, la misma ejecución dio 61 aprobados y 11 fallidos, todos por
`Attempt to read property "direction" on null` en el layout de Admin (`locales` vacía). Tras la
siembra, los 11 pasan sin ningún cambio en el código.

### 10.1 ACL provisional

La seguridad de la pantalla inicial depende de que `ventas_asesor` sea la **última** entrada
que lista su ruta compartida, porque Bagisto autoriza con la última. Es **provisional**:

- solo protege la pantalla inicial;
- no puede convertirse en el mecanismo de las rutas futuras;
- cada operación futura necesita su propia ruta y su propia clave ACL;
- la reutilización de la ruta inicial debe desaparecer a medida que existan rutas reales;
- la prueba de manipular parámetros **no** demuestra protección IDOR sobre pedidos; eso se
  comprobará cuando existan atribuciones y rutas con `order_id` (Fases 02 y 05).

---

## 11. Resultado de la regresión

**Comando** (solo sobre `lpz_saas_testing`):

```
vendor/bin/pest packages/ByLopez/CorteConfeccion/tests
                packages/Webkul/Admin/tests/Feature/Settings/RoleTest.php
                packages/Webkul/Admin/tests/Feature/Settings/UsersTest.php
                packages/Webkul/Admin/tests/Feature/Admin/TwoFactorAuthenticationTest.php
```

| Momento | Casos | Aprobados | Fallidos | Aserciones | Bloqueos del guard |
|---|---|---|---|---|---|
| Antes de sembrar | 174 | 44 | 130 | 155 | 0 |
| **Después de sembrar (vigente)** | **174** | **169** | **5** | **546** | **0** |

**Antes de sembrar**, los 130 fallos se debían a datos base ausentes: 96 por la clave foránea
`attribute_family_id → attribute_families` (verificado); unos 26 por respuestas 401, porque
`AdminFactory` fija `role_id = 1` y `admins` no tiene claves foráneas, con `roles` vacía
(mecanismo verificado, atribución caso a caso inferida); y 4 por el layout sin locale.

### 11.1 Los 5 fallos vigentes

| Archivo | Prueba | Mensaje | Causa | Nivel |
|---|---|---|---|---|
| `UsersTest` | should store the newly created admin | `"Usuario creado con éxito."` no contiene «éxito» | Locale `es`: la tilde viaja escapada en JSON | **Demostrado**: con `APP_LOCALE=en` pasa |
| `UsersTest` | should update the existing admin | Ídem con «actualizado» | Locale `es` | **Demostrado** |
| `UsersTest` | should delete the existing admin | Ídem con «eliminado» | Locale `es` | **Demostrado** |
| `UsersTest` | should returns the user and its roles | Espera `Administrator`, obtiene `Administrador` | El rol 1 se siembra con el locale `es` | **Verificado en datos** (`roles.id = 1 → Administrador`) |
| `VarianteConsultaServiceTest` (CorteConfeccion) | CASO 1 - variante adulta normal | Espera `Talla`, obtiene `Tamaño` | La prueba crea el atributo `size` como «Talla», pero la base sembrada ya lo trae como «Tamaño» | **Verificado en datos** (`attributes.code = size → Tamaño`) |

**Prueba comparativa de locale.** `APP_LOCALE=en vendor/bin/pest …/UsersTest.php` sobre
`lpz_saas_testing`: pasan store, update y delete; sigue fallando «returns the user and its
roles» (nombre del rol sembrado en español). Aparece además un fallo en «returns the user index
page», que se interpreta como **artefacto** de mezclar locale `en` con datos sembrados en `es` y
no se analiza más.

**Conclusión:** ninguno de los 5 fallos involucra a VentasAsesor, y sus causas están demostradas
o verificadas en datos. Se reproducen sobre una base **recién sembrada sin datos de desarrollo**.

### 11.2 Los cuatro fallos anteriores de `UsersTest`

Los 4 fallos que se vieron en la Fase 01 sobre desarrollo son **los mismos 4** que fallan aquí.
Su causa ya **no es una suposición**: tres se deben al locale `es` (demostrado con la ejecución
comparativa) y uno al nombre del rol sembrado en español. No se hizo una comparación con y sin
el paquete; la clasificación se apoya en esas causas demostradas. `[F01-§12.4]` quedó
corregido.

---

## 12. Verificación de la base de desarrollo

Instantánea de **solo lectura** de `lpz_saas`, tomada antes de crear la base de pruebas y
repetida **después de todo**: migración, siembra, pruebas propias, regresiones, mutaciones,
demostración del guard y ejecución comparativa de locale.

| Comprobación | Antes | Después |
|---|---|---|
| Base | `lpz_saas` | `lpz_saas` |
| Tablas base | 163 | 163 |
| Filas totales | 21 416 | 21 416 |
| `AUTO_INCREMENT` de las 163 tablas | — | **SIN CAMBIOS** |
| `COUNT(*)` de las 163 tablas | — | **SIN CAMBIOS** |
| `CHECKSUM TABLE` de las 163 tablas | — | **SIN CAMBIOS** |

**`lpz_saas` no recibió tablas, filas nuevas, modificaciones, borrados ni avances de
`AUTO_INCREMENT`.** No se intentó revertir los incrementos anteriores (§0).

---

## 13. Estado de D-16 y D-27

**Ambas siguen PENDIENTES.**

- **D-27** mantiene la recomendación: un administrador con `permission_type = all` puede
  administrar el módulo, pero no debe ser asesor para atribución automática; sus pedidos quedan
  sin asesor hasta seleccionar uno explícitamente. No implementada.
- **D-16** fue reevaluada. La atribución de pedidos **ya creados** no depende del momento
  comercial de confirmación; sí dependen de D-16 el contexto previo ligado a carrito o borrador,
  la confirmación del cliente y la creación desde el servicio propio. Se **propone** una Fase 02A
  (atribución de pedidos ya creados, bloqueada solo por D-27) y dejar el contexto previo en la
  Fase 03. **El roadmap no se reorganizó.**

---

## 14. Riesgos pendientes

| # | Riesgo | Tratamiento |
|---|---|---|
| R-1 | ACL provisional dependiente del orden de `acl.php` | §10.1; prueba guardiana y mutación |
| R-2 | Un rol con tipo desconocido ve el nodo de menú pero recibe 401 al entrar | Cosmético; corregir el dato del rol |
| R-3 | `FILESYSTEM_DISK=local` y `LOG_CHANNEL` por defecto siguen escribiendo en `storage/` de la copia de trabajo durante las pruebas | Local y sin efectos externos |
| R-4 | El guard solo verifica la conexión **por defecto** | Otra conexión con nombre no quedaría cubierta |
| R-5 | Providers que consulten la base al arrancar actuarían antes del guard | Hoy no ocurre; vigilar |
| R-6 | Doble registro inerte si se hace `composer require` del paquete | §5 |
| R-7 | `all` sigue reconociéndose como asesor | D-27 |
| R-8 | Archivos temporales `pac217A.tmp` y `serD40.tmp` en `bootstrap/cache/` | Preexistentes; no se tocaron |
| R-9 | `AUTO_INCREMENT` de desarrollo consumido por ejecuciones ajenas anteriores | No reversible; el guard impide que se repita |
| R-10 | La base de pruebas contiene ahora datos base persistentes, incluido el administrador de ejemplo de Bagisto | Solo local; las pruebas revierten sus propias escrituras. Si cambia el esquema habrá que migrar y, si hace falta, volver a sembrar con la misma exclusión de RMA |
| R-11 | 5 fallos de regresión dependientes del locale `es` y de datos sembrados | §11.1; no afectan a VentasAsesor |

---

## 15. Resultado de Git

| Comprobación | Inicio de 01A | Final de 01A |
|---|---|---|
| `git status --short` | 110 | **112**: +`M tests/TestCase.php` y +`?? .env.testing.example` |
| `git diff --stat` | 55 archivos, +2494 −1346 | **56 archivos, +2537 −1346**: las 43 líneas del guard |
| `git diff --check` | — | **Sin problemas de espacios** |
| `git diff --stat -- composer.json bootstrap/providers.php phpunit.xml tests/TestCase.php .gitignore` | 3 archivos | 4 archivos: se añade `tests/TestCase.php` (+43); `.gitignore` sin cambios |
| `git diff -- packages/ByLopez/VentasAsesor/` | 0 líneas | 0 líneas: el paquete no está rastreado |
| Archivos sin rastrear de VentasAsesor | 23 | **25**: +`tests/Unit/GuardBaseDePruebasTest.php` y +`docs/06A_…md` |
| `packages/Webkul/` | 0 | **0** |
| `packages/Webkul/RMA/` | 0 | **0** |
| `packages/ByLopez/CorteConfeccion/` (sin desglosar sin rastrear) | 101 | **101**: sin cambios de esta fase |
| `.env.testing` en `git status` | — | **No aparece** |
| `composer dump-autoload` | — | 14 950 clases; ningún cambio rastreado |
| `php -l` y `pint --test` sobre los archivos tocados | — | Sin errores / aprobado |
| `HEAD` | `8739c18554` | `8739c18554` |

No se ejecutó `commit`, `push`, `pull`, `merge`, `rebase`, `cherry-pick`, `reset`, `checkout`
ni `stash`. No se ejecutó `migrate:fresh`, `db:wipe`, `db:seed` ni `bagisto:install`.

---

## 16. Criterios de salida

| Criterio | Estado |
|---|---|
| Registro raíz auditado | ✅ §4 |
| Sin provider activo duplicado | ✅ §5 |
| Pruebas no descubiertas dos veces | ✅ §6 |
| Tipos de permiso desconocidos fallan cerrados | ✅ §2, §3 |
| Nuevas pruebas de autorización pasan | ✅ §3, §10 |
| Base MySQL exclusiva para pruebas | ✅ `lpz_saas_testing` |
| Ninguna prueba ejecutada sobre `lpz_saas` | ✅ §8, §12 |
| Guard global contra la base incorrecta | ✅ §8 |
| Migraciones solo en `lpz_saas_testing` | ✅ §9 |
| Base de desarrollo idéntica | ✅ §12 |
| Webkul, CorteConfeccion y RMA sin modificar | ✅ §15 |
| No se ejecutaron seeders | **Sustituido por autorización expresa (opción A):** seeders base solo en `lpz_saas_testing`; **ningún seeder de RMA** (§9.1) |
| No se inició la Fase 02 | ✅ |
| D-16 y D-27 pendientes | ✅ §13 |
