# 11 — RT-03: precio por grupo del cliente del carrito

**Estado:** RT-03 **CERRADO** (RT-03A reproducción + RT-03B corrección, aprobados). Sin cambios
en `packages/Webkul/`.

Regla de dominio: **cliente comercial del carrito → grupo ACTUAL de ese cliente → motor de
precios de Bagisto.** VentasAsesor no calcula precios; solo garantiza que Bagisto use el grupo
correcto. Bagisto sigue resolviendo precios por grupo, tiers, configurables, reglas de catálogo y
de carrito, descuentos, impuestos y totales.

## 1. Defecto y causa raíz

`CustomerRepository::getCurrentGroup()` (`Customer/.../CustomerRepository.php:39-44`) devuelve
el grupo de `auth()->guard()->user()`. El guard por defecto es `customer` y el middleware del
panel no lo cambia, así que en el panel el precio salía del cliente con sesión en el navegador
(p. ej. tras «Iniciar sesión como cliente») o del **invitado**, nunca de `cart.customer_id`.
Todos los consumidores (precio, variante, tiers, reglas de carrito) pasan por esa función, y
`collectTotals()` → `validateItems()` reprecia en cada operación, incluida la confirmación
(`OrderController::store`, l. 89).

Hallazgo de pruebas: el helper del core `loginAsAdmin()` usa `actingAs(…, 'admin')`, que llama a
`shouldUse('admin')` y oculta el defecto. Nuestras pruebas inician la sesión sin `actingAs()`.

## 2. Evidencia (RT-03A, antes de corregir)

| Caso | Antes | Después (RT-03B) |
|---|---|---|
| T1 panel puro, carrito de A | invitado, base 8500 | A, 7400 |
| T2 sesión A, carrito B | A, 7400 | B, 6900 |
| T3 sesión cambia a B, carrito A | 6900 | 7400 |
| T5 configurable (variante −10 % A / −30 % B) | A | B |
| T6 tier qty 3 | tier de A | tier de B |
| T7 regla solo grupo B, carrito B, sesión A | descuento 0 | 100 (y 0 en el carrito de A) |
| T8 `orders.store` | reintroducía A | mantiene B, sin crear pedido |
| T10 cliente A pasa a grupo B | seguía la sesión / invitado | B con o sin sesión |
| T9 storefront | correcto | igual (no regresión) |

Valores de fixture, no reglas del negocio.

## 3. Arquitectura (estrategia C)

- `Support/ContextoPrecioComercial` (`scoped`): contexto EXPLÍCITO `(cart_id, customer_id)`.
  No conoce rutas. Dos formas, mismas reglas:
  - **`durante(cartId, customerId, fn)`** para servicios (futuro `CrearPedidoComercialService`,
    API, POS, n8n, WhatsApp, jobs): pila, restauración en `finally`, anidamiento.
  - **`fijarParaPeticion()` / `olvidarPeticion()`** para el adaptador HTTP del panel, que no
    puede envolver al controlador del core en un callback.
- `Support/CustomerRepositoryConContextoComercial` extiende el repositorio del core y solo
  sobrescribe `getCurrentGroup()`: con contexto devuelve el grupo del cliente del carrito; sin
  contexto, `parent::getCurrentGroup()` sin cambios.
- Binding en `VentasAsesorServiceProvider::register()`: `CustomerRepository` → la subclase.
  Verificado que llega a `Simple`, `Configurable`, `CartRule` y `ProductRepository`.
- **Estrategia B descartada:** usar `Cart::getCart()` como respaldo global cambiaría a todos los
  consumidores (listados de tienda, búsqueda del admin, jobs con carrito residual del facade).

## 4. Adaptador temporal del panel

`Listeners/FijarContextoPrecioEnRutasDelPanel` escucha `RouteMatched` (antes del controlador) y
declara el contexto con el carrito que identifica la URL. Se limpia en cada `RouteMatched` y en
`RequestHandled`, así nunca sobrevive a su petición. Lista exacta en `Support/RutasDePrecioDelPanel`:

| Ruta | Por qué |
|---|---|
| `admin.sales.cart.items.store` / `.items.update` / `.items.destroy` | `addProduct` / `updateItems` / `removeItem` + `collectTotals` |
| `admin.sales.cart.addresses.store` / `.shipping_methods.store` / `.payment_methods.store` | `collectTotals` |
| `admin.sales.cart.store_coupon` / `.remove_coupon` | `collectTotals` |
| `admin.sales.orders.store` (param `cartId`) | `collectTotals` antes de crear el pedido |
| `admin.customers.customers.cart.items.delete` (param `id` = cliente, carrito = el del `item_id`) | `collectTotals`; además rechaza un item de otro cliente |
| `admin.sales.orders.reorder` (param `id` = pedido) | contexto de **creación** con el cliente del pedido (§12) |

Fuera: GET (`cart.index`, `orders.create`, `customers.cart.items`) no reprecian; `cart.store` y
`customers.cart.store` solo crean el carrito. Rutas nuevas del core quedan fuera por defecto.

Este adaptador **no es** la arquitectura de ventas; se retira cuando las ventas comerciales usen
`CrearPedidoComercialService` con `durante()`.

## 5. Resolución del grupo y ciclo de vida

- El grupo se resuelve **una vez por operación** (primera consulta) desde el cliente ACTUAL en la
  base: `customer->group`, o el invitado si no tiene grupo (misma semántica que el core). Dentro
  de la operación se reutiliza (sin consultas extra, collectTotals coherente); una operación nueva
  vuelve a leerlo, así que un cambio de grupo A→B se aplica en el siguiente recálculo. No se
  guarda `customer_group_id` como fuente de verdad.
- Sin estado estático; instancia `scoped`. Octane ya limpia las instancias `scoped` y las
  facades por petición (`FlushTemporaryContainerInstances`, `Facade::clearResolvedInstances`); no
  se añadió código especial. La cola también olvida las instancias `scoped` entre jobs.

## 6. Fail closed

Contexto inexistente → core normal. Contexto válido → grupo comercial. **Contexto explícito
inconsistente → `ContextoPrecioComercialInvalidoException`** (HTTP 409 con `codigo`), nunca sesión
ni invitado:

| Situación | Código |
|---|---|
| carrito inexistente | `carrito_inexistente` |
| carrito sin cliente | `carrito_sin_cliente` |
| `cart.customer_id` ≠ `customer_id` del contexto (o id inválido) | `cliente_no_coincide` |
| cliente borrado al resolver el grupo | `cliente_inexistente` |
| `cart_id` no positivo | `identificador_invalido` |
| carrito de otro cliente creado durante un contexto de creación | `carrito_de_otro_cliente` |
| grupo borrado / nulo | invitado (compatible con el core) |

En el adaptador, un carrito inexistente no fija contexto: el controlador responde 404/422 sin
preciar nada.

## 7. CartRule y caches

`Webkul\CartRule\Listeners\Cart` se registra como `Clase@método`, así que Laravel crea el listener
(y su helper) **en cada disparo**: `$cartRules` vive un solo `collectTotals`. Confirmado por
prueba (2 `collectTotals` → 2 helpers). No hace falta invalidar nada. `typeInstance` consulta el
grupo en cada llamada; el índice de precios es por grupo (reindexar al crear grupos o precios).

## 8. T4 — requisito futuro, no defecto

Hoy, elegir otro cliente en el admin **crea otro carrito**; Bagisto no cambia el cliente de un
carrito existente. Si VentasAsesor permite cambiar el cliente de un borrador (Fase 03), deberá
actualizar la identidad comercial, recalcular todos los ítems, reglas, descuentos y totales, y
validar que no sobreviva ningún precio del cliente anterior. La prueba T4 comprueba que el
mecanismo de RT-03B lo permite.

## 9. Compatibilidad

- **Storefront:** sin contexto nunca; comportamiento idéntico (T9 y suites de precios de Shop).
- **Futuro:** `CrearPedidoComercialService`, API, POS, n8n, WhatsApp y jobs usan `durante()`, sin
  sesión, ruta ni carrito residual (probado).

## 10. Deuda para Fase 03

- Detectar cambios de precio entre lo mostrado y la confirmación (snapshot previo, comparación,
  revisión, bloqueo): no forma parte de RT-03B.
- Cambio de cliente de un borrador (T4).
- Retirar el adaptador cuando el panel de Bagisto deje de usarse para ventas comerciales.

**Deuda técnica — carrito huérfano (caso defensivo).** Durante un `MarcoCreacionDeCarrito`, si se
crea un carrito de OTRO cliente, el evento `eloquent.created` detecta la inconsistencia y lanza
`carrito_de_otro_cliente`, pero la fila del carrito ya fue insertada: puede quedar un carrito vacío
huérfano. No se borra automáticamente: es una inconsistencia defensiva fuera del flujo normal,
borrar desde el listener podría tener efectos secundarios y la garantía de precios no se ve
afectada (ningún precio incorrecto llega a calcularse).

## 11. Pruebas

`tests/Feature/Rt03PrecioPorGrupoDelCarritoTest.php` (T1–T10, MEZCLA, W, GET, servicio con
`durante()`, CartRule) y `tests/Feature/ContextoPrecioComercialTest.php` (binding, A–Q, jobs,
adaptador, rutas existentes) y `tests/Feature/Rt03ReorderTest.php` (R1–R9). Mutaciones: sin binding o sin adaptador fallan 14 pruebas; sin contexto de creación fallan R1–R5.

## 12. Volver a pedir (`admin.sales.orders.reorder`)

**Traza** (`Admin/.../Sales/OrderController::reorder`): `findOrFail(pedido)` → `$order->customer`
(morphTo; si no hay cliente vigente, redirige con aviso y no crea nada) → `Cart::createCart([customer
=> cliente])` (sin evento de Bagisto, sin precios) → `setCart` → por cada ítem (salvo booking)
`Cart::addProduct($item->product, $item->additional)` → `prepareForCart` (variante vía
`selected_configurable_option`, cantidad de `additional`) → `getFinalPrice()` → `collectTotals()` →
redirección a `orders.create/{cart}`.

**Precio actual, no histórico:** `additional` solo aporta producto, cantidad y opciones; Bagisto
vuelve a calcular con `getFinalPrice`. No copia `order_items.price` (prueba R3 con precio histórico
1234 que no aparece).

**Por qué el carrito no puede nacer mal:** sin contexto, el carrito del cliente B se creaba con
precios del cliente de la sesión o del invitado y solo se corregía en el siguiente recálculo. RT-03
exige la invariante desde el nacimiento del carrito.

**Contexto de creación** (tipo distinto, no un `cart_id` nulo):

| | Contexto normal | Contexto de creación |
|---|---|---|
| Tipo | `MarcoPrecioComercial` (`cart_id` + `customer_id`) | `MarcoCreacionDeCarrito` (`customer_id`) |
| API | `durante()` / `fijarParaPeticion()` | `duranteCreacionDeCarrito()` / `fijarCreacionParaPeticion()` |
| Validación | carrito existe, tiene cliente y `cart.customer_id == customer_id` | el cliente existe |
| Duración | la operación / petición | hasta que nace el carrito |

Al crearse el carrito (evento Eloquent `eloquent.created` del modelo `Cart`; Bagisto no dispara
ningún evento propio en `createCart`), `carritoCreado()` convierte el contexto de creación en el
normal con el mismo grupo ya resuelto. Si el carrito que nace es de **otro** cliente (o sin
cliente): `carrito_de_otro_cliente`, fail closed. El contexto normal nunca admite un carrito nulo:
`durante()` sigue exigiendo `cart_id`.

**Adaptador:** en `RouteMatched`, `reorder` toma el cliente del **pedido** (`customer_id` +
`customer_type` de cliente registrado, cliente existente) y declara el contexto de creación. Pedido
inexistente, de invitado o de cliente borrado: no se declara nada, porque Bagisto no crea carrito.

**Fuera de RT-03 (sin ocultar):** un producto o variante del pedido ya borrados producen errores
propios de Bagisto (`addProduct` con producto nulo; `find` de variante nula); no se añade ningún
respaldo de precio.

**Pruebas** (`tests/Feature/Rt03ReorderTest.php`): R1 sesión A + pedido B nace en B (se registran
todos los precios guardados: nunca A ni invitado); R2 sin sesión → B; R3 grupo cambiado desde el
pedido → grupo actual, sin precio histórico; R4 configurable; R5 tier qty 3 del grupo actual; R6/R7
el contexto desaparece y lo siguiente es core; R8 carrito de otro cliente, cliente inexistente,
pedido de invitado o de cliente borrado, transición a contexto normal y ausencia de atajos; R9
storefront. Mutación: sin el contexto de creación fallan R1–R5.

## 13. Línea base del entorno (no son fallos de RT-03)

En las suites del core ejecutadas como regresión (Shop Simple/Configurable Prices, Admin Orders,
CartRule, CustomerGroups: 125 pasan) fallan 3 pruebas **con y sin RT-03** (verificado desactivando
binding y listeners en la misma ejecución):

- `it should store the payment method after storing the shipping method`
- `it should place order via admin` (500)
- `it should store the newly created customers group`

Son del entorno de pruebas (auditoría 01A: locale `es` y datos sembrados). No se corrigen en RT-03.
