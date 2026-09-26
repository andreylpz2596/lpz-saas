# 06B_AUDITORIA_CLIENTES_PRECIOS_Y_PROPIEDAD_COMERCIAL.md

**Fase 01B — Auditoría de clientes, precios y propiedad comercial.**

**Fecha:** 2026-09-13
**Estado:** **COMPLETADA**. Fase de auditoría, reproducción controlada y documentación.
**Sin código funcional.** Las Fases 01 y 01A siguen completadas y no se reabrieron.

Documento de entrada: `ACTUALIZACION_MODELO_COMERCIAL_POS_Y_COMISIONES.md`.
Referencias: `[A-§n]` → `01`; `[M-§n]` → `02`; `[T-§n]` → `05`; `D-nn`/`RT-nn` → `04`.

---

## 0. Línea base y cambios ajenos

Tomada antes de empezar.

| Comprobación | Cierre de la 01A | Inicio de la 01B |
|---|---|---|
| `git status --short` | 112 | **120** |
| `git diff --stat` | 56 archivos, +2537 −1346 | **56 archivos, +2552 −1346** |
| `packages/Webkul/` | 0 | 0 |
| `packages/ByLopez/CorteConfeccion/` | 101 | **110** |
| VentasAsesor sin rastrear | 25 | **26** (+ el documento de entrada) |
| `HEAD` | `8739c18554` | `8739c18554` |

**Cambios ajenos a esta fase, no atribuidos a VentasAsesor:**

- `tests/Pest.php` (+2 líneas: enlaza `CorteConfeccionTestCase`).
- Nuevos archivos de CorteConfeccion: contratos, modelos, repositorios, enum y
  requests de retornos de confección, `OrdenConfeccionGate` y las migraciones
  `2026_09_13_000002` y `2026_09_13_000003`.
- **En la base de desarrollo** (instantánea de solo lectura frente al cierre de la
  01A): tablas 163 → 165 (`bylopez_cc_ordenes_confeccion_retornos` y
  `…_retorno_detalles`); `migrations` 210 → 212 filas; checksum distinto en
  `bylopez_cc_ordenes_confeccion` y `bylopez_cc_ordenes_confeccion_detalles`.
  Alguien aplicó migraciones de CorteConfeccion en desarrollo **antes** de esta fase.
  Esa instantánea es la referencia de la 01B (§12).

---

## 1. Método

| Regla | Cómo se cumplió |
|---|---|
| Nada de escritura ni pruebas sobre `lpz_saas` | En desarrollo solo se ejecutaron consultas `SELECT` e `information_schema`, sin imprimir datos personales (solo conteos y patrones) |
| Reproducciones solo en `lpz_saas_testing` | Pruebas Pest que heredan de `Tests\TestCase`: el guard bloquea cualquier otra base y `DatabaseTransactions` revierte todo |
| Sin tocar pruebas existentes | Los archivos de reproducción viven en el scratchpad. Para ejecutarlos se copiaron **temporalmente** a `packages/ByLopez/VentasAsesor/tests/Repro01BTemporal/`, carpeta que ninguna testsuite de `phpunit.xml` incluye, y **se borraron en el mismo comando** (verificado: la carpeta no existe) |
| Core de referencia | `git diff v2.4.9 HEAD` sobre Checkout, Customer, Product, Admin/Http, vistas de ventas y clientes, CartRule, CatalogRule, `Core.php`, `config/auth.php` y `config/session.php` → **vacío**. El comportamiento auditado es el de Bagisto 2.4.9 original |
| Sin extensiones propias que interfieran | Ningún archivo de `app/`, `bootstrap/`, `config/` ni `packages/ByLopez/*/src` referencia `CustomerRepository`, `getCurrentGroup` ni el guard `customer` |

---

## 2. Correo obligatorio del cliente

### 2.1 Esquema real (idéntico en desarrollo y testing)

| Tabla.columna | Tipo | Nullable | Índice |
|---|---|---|---|
| `customers.email` | varchar(255) | **SÍ** | `customers_email_channel_unique` **ÚNICO** `(email, channel_id)` |
| `customers.phone` | varchar(255) | SÍ | `customers_phone_unique` **ÚNICO** `(phone)` |
| `customers.password` | varchar(255) | SÍ | — |
| `addresses.email` / `addresses.phone` | varchar(255) | SÍ / SÍ | — |
| `orders.customer_email` | varchar(255) | SÍ | — |
| `cart.customer_email` | varchar(255) | SÍ | — |

Origen: `Customer/…/2018_07_24_082930_create_customers_table.php:22-23`
(`email` y `phone` `unique()->nullable()`) y `2024_06_04_130600_make_email_unique_per_channel.php`.

**Conclusión: la base de datos NO exige correo.** MariaDB admite varios `NULL` en un
índice único (demostrado en C1, §2.3).

### 2.2 Dónde se vuelve obligatorio

| Punto | Archivo | Regla |
|---|---|---|
| Alta de cliente en el panel | `Admin/…/Customers/CustomerController.php:86` | `email` **required** + único por canal |
| Edición en el panel | `CustomerController.php:146` | `email` **required** |
| Formulario del panel | `customers/index/create.blade.php:72` | `required\|email` |
| Dirección del carrito administrativo | `Admin/…/Requests/CartAddressRequest.php:53` | `{billing\|shipping}.email` **required** |
| Registro en tienda | `Shop/…/Customer/RegistrationRequest.php:30` | **required** + único por canal |
| Perfil en tienda | `Shop/…/Customer/ProfileRequest.php:34` | `email` (único) |
| Login de cliente | `Shop/…/Customer/LoginRequest.php:16` | **required** (el guard autentica por correo) |
| Recuperar contraseña | `Shop/…/Customer/ForgotPasswordRequest.php:16`; tabla `customer_password_resets.email` | **required** |
| Dirección de la libreta en tienda | `Shop/…/Customer/AddressRequest.php:40` | **required** |
| Dirección del checkout en tienda | `Shop/…/CartAddressRequest.php:52` | **required** |
| Importación de clientes | `DataTransfer/…/Importers/Customer/Importer.php:83, 243` | `email` **required**; es la **clave maestra** de la importación |
| Retiro UE / GDPR | `LookupGuestOrderRequest.php:26`; `GDPRController.php:63` | Buscan por correo |

**Otros usos que asumen correo existente:**

| Uso | Archivo | Efecto con correo nulo |
|---|---|---|
| Creación del carrito | `Checkout/Cart.php:149` | Copia `customer_email` del cliente (nulo) |
| Datos personales del carrito | `Cart.php:556` | `customer_email = cliente->email ?? dirección_facturación->email` |
| `OrderResource` | `Sales/Transformers/OrderResource.php:47` | Copia `customer_email` del carrito |
| Correos de pedido al cliente | `Shop/Mail/Order/{Created,Canceled,Commented,Invoiced,Shipped,Refunded}Notification.php` | `new Address($order->customer_email)` |
| Comentario de pedido (panel) | `Admin/Mail/Order/CommentedNotification.php:28` | Ídem |
| Correos de cliente | `Shop/Mail/Customer/{Registration,EmailVerification,UpdatePassword,Note}Notification.php`; `Admin/Mail/Customer/NewCustomerNotification.php` | `new Address($customer->email)` |
| Correos RMA | `Shop/Mail/Customer/RMA/*`; `Admin/Mail/Admin/RMA/*` | `$rma->order->customer->email` |
| Sincronización de pedidos invitados | `CustomerRepository.php:93` (desde `RegistrationController::verifyAccount`, :129) | `Order::where('customer_email', $customer->email)` |
| Búsqueda de clientes del panel | `CustomerController.php:283-286` | Filtra por **correo o nombre**; **no por teléfono** |
| Listeners de correo | `Shop/Listeners/Base.php:40-44`; `Shop/Listeners/Order.php`; `Admin/Listeners/Order.php` | `catch (\Exception)`: **no** capturan `TypeError` |

**Invitados.** `sales.checkout.shopping_cart.allow_guest_checkout` = `1` en desarrollo
(`OnepageController.php:32`). El invitado usa el grupo `guest`
(`CustomerRepository::getCurrentGroup()` → `core()->getGuestCustomerGroup()`), queda con
`orders.customer_id = NULL` y la dirección igualmente exige correo.

**Configuración de desarrollo leída (sin secretos):** `queue.default = sync`,
`mail.default = smtp`; notificaciones al cliente de pedido, factura, envío, reembolso y
cancelación **activadas**; `customer.settings.email.verification` sin fila (usa el
valor por defecto).

**Datos de desarrollo:** 14 clientes; **0** con correo nulo o vacío; **0** con patrones
de correo sintético; 24 pedidos, **0** invitados y **0** con `customer_email` nulo.

### 2.3 Reproducción en `lpz_saas_testing`

`ReproClienteSinCorreoTest` (y `DiagTelefono57Test` para C3c), con `mail = array` y
`queue = sync`.

| # | Caso | Resultado |
|---|---|---|
| C1 | Dos clientes con `email = NULL` en el mismo canal vía `CustomerRepository::create()` | **Ambos creados** |
| C1b | Segundo cliente con el mismo `phone` | `UniqueConstraintViolationException` en `customers_phone_unique` |
| C2 | Alta en el panel sin correo | **422** `email` |
| C3a | Alta en el panel con teléfono ya usado | **422** `phone` |
| C3b | Alta con teléfono `300 555 6677` | **422** `phone` (`PhoneNumber`: `^\+?\d+$`, sin espacios) |
| C3c | Alta con `+573001112233` (mismo número que C1, otro formato) | **Pasa la validación y la unicidad.** Después termina en 500 por un `TypeError` de `Symfony\Mime\Address` al enviar el correo del alta. El mismo 500 aparece con un teléfono distinto y sin teléfono, así que **no depende del teléfono**; es compatible con que testing no tenga remitente configurado. No se investigó más |
| C4 | Edición en el panel de un cliente sin correo, sin agregarlo | **422** `email` |
| C5 | Búsqueda del panel por `3001112233` / por nombre | **0** / **1** resultados |
| C6a | Dirección del carrito administrativo sin correo | **422** `billing.email`; `cart.customer_email` = `NULL` |
| C6b | Misma dirección con correo | 200; `cart.customer_email` toma **el correo de la dirección** |
| C7 | Pedido con `customer_email = NULL` y notificación `new_order` activa | `CreatedNotification::envelope()` → **`TypeError`**; `Shop\Listeners\Order::afterCreated()` → **`TypeError` propagado** (no es `\Exception`) |
| C8 | `syncNewRegisteredCustomerInformation()` para un cliente sin correo | **Reasignó a ese cliente 2 pedidos ajenos** con correo nulo (`is_guest` 1 → 0) |

**Deducido del código, no reproducido de extremo a extremo:**
`OrderRepository::createOrderIfNotThenRetry()` dispara `checkout.order.save.after`
dentro de su transacción y solo captura `\Exception` (líneas 49-114). Un `TypeError`
del listener de correo saldría sin pasar por su `rollBack()` y haría fallar la creación
del pedido.

### 2.4 Por qué el correo es obligatorio

1. **Validaciones de entrada** del panel, la tienda y el importador (§2.2), no el
   esquema.
2. **Identidad de autenticación:** login y recuperación de contraseña del cliente usan
   el correo.
3. **Destinatario de notificaciones:** los mailables construyen `Address` con un
   `string` obligatorio.
4. **Clave de conciliación:** la importación y la sincronización de pedidos invitados
   emparejan por correo.

### 2.5 Alternativas comparadas

Criterios del encargo: no modificar Webkul, compatibilidad con actualizaciones, precios
por grupo, historial de compras, atribución, y no romper login, checkout, pedidos ni
notificaciones.

| Criterio | (1) Cliente Bagisto con correo nulo, creado por el servicio | (2) Contacto comercial propio vinculado a cliente Bagisto | (3) Cliente invitado | (4) Correo solo en la dirección (lo que hace el panel) |
|---|---|---|---|---|
| Sin tocar Webkul | ✅ El esquema ya lo admite | ✅ Tabla propia | ✅ | ✅ |
| Precios por grupo | ✅ El cliente tiene grupo | ✅ si hay cliente vinculado; ❌ si no | ❌ grupo `guest` | ✅ |
| Historial de compras | ✅ `customer_id` | ✅ | ❌ `customer_id` nulo | ✅ |
| Atribución al asesor | ✅ | ✅ | ✅ (por pedido) | ✅ |
| Login y recuperación | ⚠️ No aplica (no tiene cuenta web) | ⚠️ Igual que el cliente vinculado | ⚠️ No aplica | ✅ |
| Checkout y creación del pedido | ⚠️ Requiere RT-04: sin correo en dirección, el pedido queda con `customer_email` nulo → C7 | Depende del cliente vinculado | ❌ La dirección exige correo | ❌ **Exige un correo** para cada pedido: sin correo real obliga a inventarlo |
| Notificaciones | ❌ sin mitigación (C7); ✅ con RT-04 | Depende | — | ✅ si el correo es real |
| Edición en el panel de Bagisto | ⚠️ Exige añadir correo (C4) | — | — | ✅ |
| Riesgo de datos | ⚠️ C8 si se dispara la sincronización | Ninguno propio | — | ❌ Un correo compartido o sintético mezclaría pedidos en C8 |
| Por sí sola resuelve el caso | No: necesita RT-04 | **No**: sigue necesitando un cliente Bagisto con o sin correo | **No** | **No** sin correo real |

**No se encontró en el core ninguna otra vía** que permita crear pedidos de un cliente
registrado sin correo: el panel y la tienda exigen correo en la dirección.

### 2.6 Recomendación (registrada como D-28, pendiente)

**Combinar (1) y (2), condicionado a RT-04:**

1. El servicio común crea o reutiliza un **cliente Bagisto real** (grupo, precios,
   historial) con `email = NULL` cuando el cliente no lo entrega, usando
   `CustomerRepository` y **sin** pasar por los Form Requests del panel.
2. Una **ficha de contacto comercial propia** (`bylopez_va_…`, sin implementar) guarda
   el teléfono original y el normalizado, su historial de titularidad y el vínculo con
   `customers.id`.
3. **RT-04 antes de habilitarlo:**
   - (a) ningún pedido con `customer_email` nulo puede llegar a un mailable que
     construya `Address(null)`. Opción compatible a evaluar: enlazar en el contenedor
     subclases de los listeners de correo del core (`Shop\Listeners\Order`, `Invoice`,
     `Shipment`, `Refund`) que omitan al destinatario nulo. Esos listeners se resuelven
     por el contenedor desde los `EventServiceProvider`; no se modifica Webkul, pero hay
     que revisarlos en cada actualización;
   - (b) un cliente sin correo **nunca** debe recibir `token` de verificación (C8);
   - (c) la búsqueda por teléfono vive en el módulo, porque el panel no la ofrece.
4. Limitación aceptada: editar ese cliente desde el panel de Bagisto exige capturar
   correo (C4). Login y recuperación no aplican mientras no tenga cuenta web.

**Descartados:** invitado (pierde grupo e historial) y correos sintéticos, incluido un
correo compartido de la empresa (C8 mezclaría pedidos de clientes distintos).

> **Actualización 2026-09-25 — SUPERADO PARCIALMENTE POR D-RT04-01/02 (Doc. 12 §14) Y EL DOC. 13.**
> El punto 1 queda decidido. El punto 2 **no** se implementa como ficha de contacto: se sustituye
> por un índice de teléfonos sin identidad paralela (Doc. 13 §9). La opción del punto 3(a)
> (subclases de listeners resueltas por el contenedor) se **confirmó** con evidencia
> (`Illuminate/Events/Dispatcher.php:536`) y es el extension point elegido (Doc. 13 §15.3), ampliado
> a `Shop\Listeners\Customer`.

---

## 3. Teléfono y duplicados

### 3.1 Dónde vive

| Lugar | Reglas | Evidencia |
|---|---|---|
| `customers.phone` | nullable, **ÚNICO**; panel: `unique:customers,phone` + `PhoneNumber`; perfil de tienda: **required** + único | §2.1, `CustomerController.php:88,148`, `ProfileRequest.php:40` |
| `addresses.phone` (tipos `customer`, `cart_billing/shipping`, `order_billing/shipping`) | nullable en base; **required** en direcciones del panel y de la tienda | `CartAddressRequest.php:59` (Admin), `:58` (Shop), `AddressRequest.php:38` |
| Formato aceptado | `PhoneNumber`: `^\+?\d+$` (sin espacios ni guiones) | `Core/Rules/PhoneNumber.php:23` |

El teléfono vive **en ambos**: en el cliente (único) y en cada dirección (libre).

### 3.2 Datos de desarrollo (solo lectura, sin imprimir números)

| Medida | Resultado |
|---|---|
| Formato de `customers.phone` (9 con valor) | 9 × móvil colombiano de 10 dígitos (`3xx`), sin prefijo ni separadores |
| Clientes sin `customers.phone` | 5; **3** de ellos tienen teléfono en alguna dirección |
| Formato en direcciones de cliente (12) | 12 × móvil de 10 dígitos |
| Formato en direcciones de pedido (48) | 48 × móvil de 10 dígitos |
| Mismo teléfono normalizado en direcciones de **clientes distintos** | **1** caso |
| Cliente cuyo `phone` difiere del de alguna de sus direcciones | 1 |
| Clientes con más de una dirección | 0 |

### 3.3 Hallazgos

- **La unicidad del teléfono ya existe en el core**, pero compara **texto**:
  `3001112233` y `+573001112233` se aceptan como clientes distintos (C3c). Un índice
  único no evita el duplicado real si no hay normalización.
- Un teléfono compartido (familia, empresa, punto de venta) **no puede** guardarse en
  `customers.phone` de dos clientes; ya existe un caso en direcciones de desarrollo.
- La búsqueda del panel no encuentra clientes por teléfono (C5).
- **Normalización propuesta (Colombia):** quitar todo lo que no sea dígito; 10 dígitos
  que empiezan por `3` (móvil) o `60` (fijo) → `+57` + 10 dígitos; 12 dígitos que
  empiezan por `57` → `+` + dígitos; con `+` inicial y otro prefijo → se conserva como
  internacional; lo demás → sin normalizar y marcado para revisión. Todos los valores
  actuales de desarrollo normalizan sin ambigüedad a `+57…`.
- **Impacto de imponer más unicidad:** un índice único sobre el teléfono normalizado
  bloquearía números compartidos y cambios de titular. **No se recomienda.** En la
  ficha propia el índice debe ser **no único**. `customers.phone` (único del core) solo
  debería llenarse cuando no choque con otro cliente.

### 3.4 Proceso documental (sin implementar)

```text
normalizar teléfono
→ buscar coincidencias (teléfono normalizado en ficha propia, customers.phone y direcciones)
→ mostrar cliente(s) existente(s) con grupo, últimos pedidos y direcciones
→ el asesor elige:
     usar el cliente existente
   | corregir sus datos (con historial)
   | crear uno nuevo con confirmación explícita y motivo
       (número compartido, cambio de titular, otro)
```

- Si el correo o el documento están presentes, refuerzan la coincidencia pero no la
  sustituyen.
- **Cambio de titular:** se registra en el historial de la ficha propia; los pedidos
  anteriores conservan su cliente.
- **Varias direcciones:** se muestran todas; la del pedido se copia al carrito como hoy.
- Política exacta de coincidencias: **D-29** (pendiente).

---

## 4. Precio heredado por grupo

### 4.1 Flujo real del panel

| Paso | Ruta y controlador | Qué ocurre |
|---|---|---|
| Buscar cliente | `GET admin/customers/search` → `CustomerController::search` | Correo o nombre |
| Elegir cliente | `POST admin/sales/cart/create` → `Admin\…\Sales\CartController::store` | `Cart::createCart(['customer' => $cliente, 'is_active' => false])`: **nuevo carrito inactivo** con `customer_id` del cliente |
| Pantalla | `GET admin/sales/orders/create/{cartId}` → `OrderController::create` | — |
| Agregar ítem | `POST admin/sales/cart/{id}/items` → `CartController::storeItem` | `Cart::addProduct()` → `prepareForCart()` → `getFinalPrice()` |
| Cambiar cantidades | `PUT admin/sales/cart/{id}/items` → `updateItem` | `updateItems()` → `collectTotals()` |
| Direcciones, envío, pago, cupón | `CartController::storeAddress / storeShippingMethod / storePaymentMethod / storeCoupon` | Todos llaman `collectTotals()` |
| Crear pedido | `POST admin/sales/orders/create/{cartId}` → `OrderController::store` | `collectTotals()` → `OrderResource` → `OrderRepository::create()` |
| Iniciar sesión como cliente | `GET admin/customers/login-as-customer/{id}` → `CustomerController::loginAsCustomer` (ACL `customers.customers.login_as_customer`) | `auth()->guard('customer')->login($cliente)` **en la misma sesión** |

**Sesión.** Panel y tienda comparten la misma sesión de Laravel (una sola cookie,
`path=/`; en desarrollo `session.driver = file`). En ella conviven las claves
`login_admin_<hash>` y `login_customer_<hash>`.

**Cálculo del precio.** Todo el cálculo pide el grupo a
`CustomerRepository::getCurrentGroup()` (`CustomerRepository.php:39-44`):

```php
$customer = auth()->guard()->user();        // guard por defecto = 'customer' (config/auth.php:20)
return $customer->group ?? core()->getGuestCustomerGroup();
```

Lo usan `AbstractType::getFinalPrice()` (:689), `getPriceIndex()` (:706),
`getCustomerGroupPrice()` (:1061), `Configurable::prepareForCart()` (:400, vía la
variante), `ProductRepository` (:274, :611) y `CartRule\Helpers\CartRule` (:484).

- `getMinimalPrice()` lee `product_price_indices` del grupo actual.
- `AbstractType::validateCartItem()` (:929) y `Configurable::validateCartItem()` (:526)
  **reprecian cada ítem** con ese grupo en cada `Cart::collectTotals()` → `validateItems()`.

`cart.customer_id` **no interviene** en el precio.

### 4.2 Reproducción (`ReproPrecioHeredadoTest`, `lpz_saas_testing`)

> **Aclarado 2026-09-25:** `ReproPrecioHeredadoTest` **ya no existe** en el repositorio (se retiró por diseño, §0). La tabla es evidencia histórica y no se puede re-ejecutar; RT-03 exigirá una prueba de reproducción nueva dentro del módulo.

**Datos:** grupo «Carol» con precio fijo 7400; grupo «Madrugón» con 6900; precio base
8500; un producto simple y un configurable (precio por grupo en sus variantes); índices
de precio reconstruidos. Índice del simple: `guest` 8500 · `general` 8500 ·
`wholesale` 8500 · Carol 7400 · Madrugón 6900.

**Sesión administrativa real:** `guard('admin')->login()`, **sin** `actingAs()`, y cada
petición reconstruye guards y carrito desde la sesión.

| Paso | Cliente en guard `customer` | Grupo usado | Carrito (cliente del carrito) | Simple | Configurable |
|---|---|---|---|---|---|
| E1 · Panel puro, sin «login as» | — | `guest` | 1 (Carol) | **8500** | **8500** |
| E2 · «Login as» Carol | Carol | Carol | — | — | — |
| E3 · Nuevo carrito de Rosario | Carol | Carol | 2 (Rosario) | **7400** ❌ | **7400** ❌ |
| E4 · Recalcular carrito 1 (agregado antes a 8500) | Carol | Carol | 1 (Carol) | 7400 | 7400 |
| E5 · «Login as» Rosario y recalcular | Rosario | Madrugón | 2 (Rosario) | 6900 ✅ | 6900 ✅ |
| E5 · … y recalcular el carrito de Carol | Rosario | Madrugón | 1 (Carol) | **6900** ❌ | **6900** ❌ |
| E6 · Solo abrir el carrito (GET) | Rosario | — | 1 | 6900 (no recalcula) | 6900 |
| E7a · Cerrar sesión del **administrador** | **Rosario (sigue)** | Madrugón | — | — | — |
| E7b · Volver a entrar y crear carrito de Carol | Rosario | Madrugón | 3 (Carol) | **6900** ❌ | **6900** ❌ |
| E8 · Cerrar sesión del **cliente** en tienda y recalcular | — | `guest` | 3 (Carol) | 8500 | 8500 |
| E9 · Sesión nueva (invalidada) y recalcular | — | `guest` | 2 (Rosario) | 8500 | 8500 |
| E10 · Carritos creados por cerrar sesión o volver a entrar | — | — | — | **0** | — |

### 4.3 Respuestas a las preguntas del encargo

| Pregunta | Respuesta verificada |
|---|---|
| Ruta y controlador | §4.1 |
| Carrito utilizado | Un carrito **nuevo e inactivo** por cada cliente elegido (`is_active = 0`, `customer_id` correcto) |
| Sesión administrativa | La misma sesión de Laravel que usa la tienda; el guard `customer` puede estar autenticado dentro de ella |
| `customer_id` | Correcto en el carrito desde su creación; **no** se usa para el precio |
| `customer_group_id` | Se toma del cliente del **guard `customer`**; si no hay, `guest` |
| Momento de asignar cliente | `CartController::store` |
| Momento de calcular precio | `addProduct()` y **cada** `collectTotals()` (ítems, dirección, envío, pago, cupón y creación del pedido) |
| Price index / reglas | `product_price_indices` por grupo y `product_customer_group_prices`; reglas de carrito por el mismo grupo. Desarrollo: 1480 precios por grupo (5 grupos), 2639 índices, **0** reglas de catálogo, **0** reglas de carrito |
| Qué conserva el cliente anterior | La clave `login_customer_<hash>` de la sesión |
| ¿Ítems antes o después del cambio? | **Ambos**: los posteriores nacen con el grupo de sesión y los anteriores se reprecian en el siguiente recálculo (E4, E5) |
| ¿Simples y configurables? | **Ambos**, con el mismo resultado |
| ¿Reiniciar sesión crea otro carrito? | **No** (E10). Cerrar la sesión del administrador **ni siquiera limpia** el contexto (E7). Lo limpian el cierre de sesión del cliente o una sesión nueva, y entonces el precio pasa al de **invitado** |
| ¿Core, fork o datos? | **Core de Bagisto 2.4.9**: los archivos implicados son idénticos a `v2.4.9` y ningún código propio los altera. No es un defecto de datos ni de configuración |

**Por qué las pruebas del core no lo detectan.** `AdminTestBench::loginAsAdmin()` usa
`actingAs($admin, 'admin')`, y `actingAs()` llama a `shouldUse('admin')`: cambia el
guard por defecto durante la prueba. En HTTP real sigue siendo `customer`.

### 4.4 Evidencia en datos reales de desarrollo (solo lectura)

Comparación de `base_price` guardado con el índice **actual** del grupo **actual** del
cliente:

| Conjunto | Total | Igual al grupo del cliente | Igual a **invitado** y no al grupo del cliente | Otros |
|---|---|---|---|---|
| Ítems de carritos con cliente | 95 | 26 (1 de ellos con escala por cantidad; 1 coincide también con invitado) | **69** | 0 |
| Ítems de pedidos con cliente | 152 | 123 (26 de ellos coinciden también con invitado; 2 con escala por cantidad) | **26** | 3 sin índice |

En los pedidos la comparación es **indicativa**: precios o grupos pudieron cambiar
después. En carritos es consistente con E1: la mayoría de carritos del panel se
preciaron como invitado.

### 4.5 Qué no se pudo comprobar

- La secuencia exacta del usuario en desarrollo no se reprodujo allí, por la
  restricción de no escribir en `lpz_saas`. Por eso no se afirma qué pasos concretos
  siguió.
- Lo demostrado es compatible con el síntoma reportado: el precio correcto de Carol
  (7400) solo aparece cuando la sesión tiene autenticada a Carol, y persiste con otro
  cliente hasta cambiar o limpiar la sesión del cliente. Tras limpiarla, el panel vuelve
  a **invitado**, no al precio del grupo.

---

## 5. Comportamiento correcto esperado (RT-03)

```text
cambiar cliente
→ cambiar grupo (el del cliente del carrito, nunca el de la sesión)
→ eliminar contexto comercial anterior
→ recalcular todos los ítems (anteriores y nuevos, simples y configurables)
→ recalcular descuentos, reglas y totales
→ mostrar cliente, grupo y precio aplicados
→ advertir si cambió un precio ya visible
→ bloquear confirmación si existe inconsistencia entre cliente, grupo y precios
```

**Invariantes verificables en pruebas futuras:**

- (a) para cada ítem, `base_price` = precio del índice del grupo de `cart.customer`
  para su cantidad;
- (b) el resultado no cambia al autenticar o desautenticar otro cliente en la sesión;
- (c) crear el pedido vuelve a validar la invariante y aborta si no se cumple;
- (d) las pruebas usan sesión real y **no** `actingAs()`.

Opciones de implementación compatibles sin tocar Webkul: `[T-§21]` (recomendada P-1,
contexto comercial explícito en un `CustomerRepository` enlazado por el contenedor).
**«Reiniciar sesión» no es solución**; no usar «Iniciar sesión como cliente» mientras se
crean pedidos es solo una mitigación operativa temporal.

---

## 6. D-16 — cerrada

Registrada como **DECIDIDA** en `04`:

> Durante la conversación existe un carrito oficial o borrador de Bagisto. El pedido se
> crea únicamente cuando el cliente confirma productos, variantes, cantidades,
> dirección, envío y total. El pago contraentrega no significa que el pedido esté
> pagado.

Encaja con el core: el carrito administrativo nace inactivo y la reserva de inventario
ocurre solo en `OrderRepository::create()`.

---

## 7. D-27 — reformulada y cerrada; ventas de empresa

### 7.1 Regla

| Quién registra | Automático | Opciones explícitas |
|---|---|---|
| Asesor normal | Se asigna a sí mismo | Otro asesor solo con permiso |
| Administrador `all` | **Nunca** | Venta de empresa · Asignarme como asesor · Asignar otro asesor |
| Otros roles | Nunca | Según permiso |
| Automatización | Nunca por usuario técnico | Asesor explícito o sin asignar |

No se crea un segundo usuario. `all` no implica ser asesor ni comisión.

### 7.2 Conceptos separados (no implementados)

```text
created_by_admin_id     quien registró (operativo, inmutable)
advisor_id              asesor propietario, nullable
commercial_owner_type   advisor | company | unassigned
```

- `commercial_owner_type` es propiedad **comercial**. `attribution_status` (`completa` |
  `sin_asesor` | `fallo_tecnico`) sigue siendo estado **técnico** [M-§10.3].
- Una venta `company` tiene la atribución completa y **no** está sin asignar.

**Compatibilidad con el código de la Fase 01.** `AutorizacionVentasAsesor::esAsesor()`
devuelve `true` para `all`. Es coherente: el administrador **puede** elegirse como
asesor. La regla «nunca automático» corresponde al resolvedor de la Fase 02. No hay
defecto objetivo que obligue a reabrir la Fase 01.

### 7.3 Ventas directas de la empresa (caso Rosario)

- El administrador crea el pedido y figura como `created_by_admin_id`.
- `commercial_owner_type = company`, `advisor_id = NULL`, **sin comisión**.
- Cuenta en las métricas de ventas de empresa.
- **Preferencia predeterminada del cliente** (propuesta): valor sugerido y visible al
  crear la venta. El pedido guarda su propia decisión (y cómo se tomó); cambiar después
  la preferencia no altera pedidos anteriores.

### 7.4 Métricas futuras separadas

Pedidos creados por usuario · ventas de asesor · ventas directas de empresa · ventas sin
asignar · ventas comisionables · ventas no comisionables (detalle en `[M-§14.5]`).

---

## 8. Comisión y POS

### 8.1 Comisión (registrada, sin implementar)

- **Propuesta:** 20 % de la utilidad bruta positiva por unidad (D-32, pendiente).
- Solo sobre unidades **entregadas y no devueltas** (D-01).
- **Costo unitario completo pendiente** (D-31).
- Una venta `company` no genera comisión (D-27).
- **Dependencia técnica:** la utilidad parte del precio neto vendido, así que un precio
  mal calculado por RT-03 contaminaría directamente la base comisionable.

### 8.2 POS (fase futura)

- Interfaz nueva sobre el **mismo servicio común**; `sales_channel = tienda_fisica`,
  `creation_source = pos`.
- No se adelanta a la venta por asesor.
- Decisiones: D-30, D-33, D-34. Registrado en `03` como fase futura sin número.

### 8.3 Nombre del servicio

| Aspecto | `CrearPedidoAsesorService` | `CrearPedidoComercialService` |
|---|---|---|
| Describe el alcance real (asesor, POS, API, n8n, importación) | No | **Sí** |
| Riesgo de que cada canal cree «su» servicio | Mayor | Menor |
| Coste de cambio hoy | Nulo: no hay código | Nulo |

**Recomendación:** adoptar `CrearPedidoComercialService` al crear el servicio en la
Fase 03. No se renombró nada.

---

## 9. Decisiones y requisitos registrados

| Registro | Estado |
|---|---|
| D-16 | **DECIDIDA** |
| D-27 | **REFORMULADA Y DECIDIDA** |
| D-18 | Ampliada: precios bajo margen mínimo y descuentos excepcionales |
| D-28 Clientes sin correo | Pendiente (recomendación §2.6) |
| D-29 Teléfono compartido y cambio de titular | Pendiente |
| D-30 Datos mínimos por modalidad de entrega | Aprobado para despacho; pendiente recogida y POS |
| D-31 Costo unitario comisionable | Pendiente |
| D-32 Porcentaje inicial de comisión | Pendiente |
| D-33 Comisión POS | Pendiente |
| D-34 Alcance operativo y fiscal del POS | Pendiente |
| **RT-03** Precio por grupo del cliente del carrito | Requisito técnico; bloquea las Fases 03 y 04 |
| **RT-04** Clientes sin correo compatibles | Requisito técnico; bloquea las Fases 03 y 04 si D-28 adopta correo nulo |

No se renumeró ninguna decisión existente.

---

## 10. Documentos

| Documento | Cambio |
|---|---|
| `06B_AUDITORIA_CLIENTES_PRECIOS_Y_PROPIEDAD_COMERCIAL.md` | **Creado** |
| `00_STATUS.md` | Fase 01B, decisiones, bloqueos, documentos y precondiciones |
| `02_MODELO_FUNCIONAL_VENTAS_ASESOR.md` | §5.1 propiedad comercial, §5.4 nueva, §6 `tienda_fisica`/`pos`, §10.4 panel oficial según D-27, §10.5 nota D-16, §14.5 métricas, CL-23 a CL-28 |
| `03_ROADMAP_IMPLEMENTACION.md` | Fase 01B, Fase 02 sin bloqueos, Fases 03 y 04 con D-28 a D-30 y RT-03/RT-04, fase POS futura, mapa de decisiones |
| `04_DECISIONES_PENDIENTES.md` | D-16 y D-27 decididas, D-17 y D-18 anotadas, D-28 a D-34, RT-03, RT-04, resumen |
| `05_DISENO_TECNICO_CREACION_PEDIDOS_ASESOR.md` | §15 corregida, §17 servicio común y POS, §20 y §21 nueva (RT-03) |
| `ACTUALIZACION_MODELO_COMERCIAL_POS_Y_COMISIONES.md` | Notas «Fase 01B» en §4, §5, §6, §11 y en la cabecera; el texto original se conserva |

`06_FASE_01_BASE_PAQUETE_ACL.md` y `06A_CIERRE_FASE_01_Y_ENTORNO_PRUEBAS.md` **no se
modificaron**: registran el estado de su fase, cuando D-16 y D-27 estaban pendientes.

---

## 11. Reproducciones ejecutadas (todas en `lpz_saas_testing`)

| Archivo (scratchpad) | Resultado Pest | Salida |
|---|---|---|
| `va01b/ReproPrecioHeredadoTest.php` | 1 aprobada, 19,79 s | `va01b-repro-precio.json` (§4.2) |
| `va01b/ReproClienteSinCorreoTest.php` | 1 aprobada, 4,76 s | `va01b-repro-correo.json` (§2.3) |
| `va01b/DiagTelefono57Test.php` | 1 aprobada, 2,69 s | `va01b-diag-57.json` (C3c) |

Son **diagnósticos**, no pruebas de regresión: registran observaciones y afirman solo
que la ejecución terminó. Ninguno quedó en el repositorio.

**Consultas de solo lectura en desarrollo:** `va01b-esquema-datos.php` (esquema, formatos
de teléfono, grupos, precios, carritos) y `va01b-precios-reales.php` (§4.4). También se
ejecutó `va01b-esquema-datos.php` en testing para comparar el esquema.

Pruebas propias de VentasAsesor y regresión de la 01A: **no se ejecutaron**, porque la
fase no cambió código.

---

## 12. Verificación de la base de desarrollo

Instantánea de **solo lectura** de `lpz_saas`: `AUTO_INCREMENT`, `COUNT(*)` y
`CHECKSUM TABLE` de **todas** las tablas. Se tomó al inicio de la fase y se repitió
después de todas las reproducciones y consultas.

| Comprobación | Inicio de la 01B | Final de la 01B |
|---|---|---|
| Base | `lpz_saas` | `lpz_saas` |
| Tablas | 165 | 165 |
| `AUTO_INCREMENT` de todas las tablas | — | **SIN CAMBIOS** |
| `COUNT(*)` de todas las tablas | — | **SIN CAMBIOS** |
| `CHECKSUM TABLE` de todas las tablas | — | **SIN CAMBIOS** |

**`lpz_saas` permanece idéntica** respecto a la línea base de esta fase. Las diferencias
frente al cierre de la 01A (dos tablas nuevas de CorteConfeccion) son **anteriores** a
la fase y ajenas a ella (§0).

---

## 13. Resultado de Git

| Comprobación | Inicio de la 01B | Final de la 01B |
|---|---|---|
| `git status --short` | 120 | **120** (sin diferencias línea a línea: VentasAsesor aparece como carpeta sin rastrear) |
| `git diff --stat` | 56 archivos, +2552 −1346 | **56 archivos, +2552 −1346** |
| `git diff --check` | — | **limpio** |
| Archivos raíz (`composer.json`, `bootstrap/providers.php`, `phpunit.xml`, `tests/TestCase.php`, `tests/Pest.php`, `.gitignore`, `config/concord.php`) | +71 −1 | **sin cambios de esta fase** |
| `git diff -- packages/ByLopez/VentasAsesor/` | 0 líneas | 0 líneas (paquete sin rastrear) |
| Archivos sin rastrear de VentasAsesor | 26 | **27**: solo se añadió este documento; los demás cambios son ediciones de documentos ya sin rastrear |
| Código de VentasAsesor (`src/`, `tests/`) | — | **sin cambios** |
| `packages/Webkul/` | 0 | **0** |
| `packages/Webkul/RMA/` | 0 | **0** |
| `packages/ByLopez/CorteConfeccion/` | 110 | **110** |
| `.env.testing` en `git status` | — | no aparece |
| Carpeta temporal `tests/Repro01BTemporal` | — | **no existe** |
| `HEAD` | `8739c18554` | `8739c18554` |

No se creó ni se ejecutó ninguna migración, modelo, repositorio, controlador, ruta, ACL,
interfaz, lógica de atribución, comisión, POS, API ni n8n. No se ejecutó `commit`, `push`
ni ninguna operación de Git que modifique el historial.

---

## 14. Criterios de salida

| Criterio | Estado |
|---|---|
| Se conoce por qué el correo es obligatorio | ✅ §2.2, §2.4 |
| Alternativas reales comparadas | ✅ §2.5 |
| Recomendación compatible con Bagisto | ✅ §2.6 (D-28, sujeta a RT-04) |
| Causa exacta del precio heredado | ✅ §4: grupo tomado del guard `customer` de la sesión; reproducido |
| D-16 cerrada | ✅ §6 |
| D-27 reformulada y cerrada | ✅ §7 |
| Creador, asesor y venta de empresa diferenciados | ✅ §7.2, §7.3 |
| Tratamiento futuro del POS documentado | ✅ §8.2 |
| Sin código funcional | ✅ §13 |
| Webkul, RMA y CorteConfeccion sin modificar | ✅ §13 |
| `lpz_saas` idéntica | ✅ §12 |
| Fase 02 no iniciada | ✅ |
