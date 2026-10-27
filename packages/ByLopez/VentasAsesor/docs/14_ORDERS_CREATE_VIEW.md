# 14 — Vista "Crear pedido" del admin de Bagisto (`/admin/sales/orders/create/{cartId}`)

> Referencia técnica permanente de la pantalla core de creación de pedidos desde el
> panel. Consolidada el 2026-10-02 a partir de documentación previa, validada contra el
> código actual de Bagisto 2.4.9 en este repositorio.
>
> **Fuentes previas reutilizadas (siguen vigentes):**
> - `01_AUDITORIA_CORE_VENTAS_BAGISTO.md` §2.4 (controladores), §2.5 (árbol de vistas),
>   §3.2 (flujo B — pedido desde el panel) y la tabla de rutas `admin.sales.cart.*`.
> - `packages/ByLopez/CorteConfeccion/docs/autocomplete-select-context.md` (buscador de
>   cliente y buscador de productos).
>
> Este documento **no repite** el detalle de backend de creación del pedido
> (`OrderRepository::create`, eventos `checkout.order.*`, atribución): ver Doc 01 §3 y
> Doc 05. Aquí se documenta la **pantalla**: componentes, endpoints que consume, flujo
> de datos y piezas reutilizables para VentasAsesor.

---

## 1. Corrección importante sobre la ruta

El parámetro de la URL es el **id del carrito**, no el id del cliente:

```
GET admin/sales/orders/create/{cartId}   →  admin.sales.orders.create
```

El cliente se elige **antes**, en `sales/orders/index` (drawer `v-customer-search`), y
ese paso crea un carrito admin inactivo cuyo id es el que viaja en esta URL.
`/admin/sales/orders/create/155` = carrito 155.

---

## 2. Rutas

Archivo: `packages/Webkul/Admin/src/Routes/sales-routes.php`

| Método | URI (prefijo `admin/sales/`) | Nombre | Acción |
|---|---|---|---|
| GET | `orders/create/{cartId}` | `admin.sales.orders.create` | `OrderController@create` (pantalla) |
| POST | `orders/create/{cartId}` | `admin.sales.orders.store` | `OrderController@store` (crear pedido) |
| GET | `cart/{id}` | `admin.sales.cart.index` | `CartController@index` (recargar carrito) |
| POST | `cart/create` | `admin.sales.cart.store` | `CartController@store` (crear carrito; paso previo) |
| POST | `cart/{id}/items` | `admin.sales.cart.items.store` | `CartController@storeItem` |
| PUT | `cart/{id}/items` | `admin.sales.cart.items.update` | `CartController@updateItem` |
| DELETE | `cart/{id}/items` | `admin.sales.cart.items.destroy` | `CartController@destroyItem` |
| POST | `cart/{id}/addresses` | `admin.sales.cart.addresses.store` | `CartController@storeAddress` |
| POST | `cart/{id}/shipping-methods` | `admin.sales.cart.shipping_methods.store` | `CartController@storeShippingMethod` |
| POST | `cart/{id}/payment-methods` | `admin.sales.cart.payment_methods.store` | `CartController@storePaymentMethod` |
| POST | `cart/{id}/coupon` | `admin.sales.cart.store_coupon` | `CartController@storeCoupon` |
| DELETE | `cart/{id}/coupon` | `admin.sales.cart.remove_coupon` | `CartController@destroyCoupon` |

Rutas de otros grupos que la pantalla también consume:

| Ruta | Uso en la pantalla |
|---|---|
| `admin.catalog.products.search` | Drawer "Buscar productos" |
| `admin.catalog.products.configurable.options` (y `bundle`/`grouped`/`downloadable`) | Drawer de configuración del producto |
| `admin.customers.customers.addresses.store` / `.update` | Crear/editar dirección del cliente desde el paso Dirección |
| `admin.customers.customers.cart.items` / `.delete` | Columna derecha: carrito previo del cliente |
| `admin.customers.customers.wishlist.items` / `.delete` | Columna derecha: lista de deseos |
| `admin.customers.customers.compare.items` / `.delete` | Columna derecha: comparar |
| `admin.customers.customers.orders.recent_items` | Columna derecha: pedidos recientes |

Todas bajo middleware `admin` y protegidas por el Bouncer (ACL `sales.orders.create`).

---

## 3. Controladores, servicios y repositorios

| Pieza | Archivo | Rol en la pantalla |
|---|---|---|
| `OrderController::create(int $cartId)` | `packages/Webkul/Admin/src/Http/Controllers/Sales/OrderController.php:59` | `CartRepository::find()`; si no existe → redirect a índice. Pasa `CartResource` y `AddressResource::collection($cart->customer->addresses)` a la vista. |
| `OrderController::store(int $cartId)` | mismo archivo `:77` | `Cart::setCart` → `hasError` → `collectTotals` → `validateOrder()` → guard de pago `cashondelivery`/`moneytransfer` → `OrderResource` → `OrderRepository::create()` → `Cart::removeCart()` → `redirect_url` a `admin.sales.orders.view`. |
| `CartController` | `packages/Webkul/Admin/src/Http/Controllers/Sales/CartController.php` | Envoltura HTTP del facade `Cart`. Cada acción: `findOrFail($id)` → `Cart::setCart($cart)` → operación → `new CartResource(Cart::getCart())`. |
| `CartController::storeItem` | `:78` | Valida `product_id` (`required|integer|exists:products,id`) → `Cart::addProduct($product, request()->all())`. **Un producto por llamada.** |
| `CartController::updateItem` | `:132` | `Cart::updateItems(['qty' => [cart_item_id => qty]])`. |
| `CartController::destroyItem` | `:109` | Valida `cart_item_id` → `Cart::removeItem()` → `collectTotals()`. |
| `ProductController::search` | `packages/Webkul/Admin/src/Http/Controllers/Catalog/ProductController.php:327` | Busca por `name` (database o elastic según config), filtra por `channel_id` del cliente. Devuelve `ProductResource` paginado. |
| Facade `Cart` | `Webkul\Checkout\Facades\Cart` → `Webkul\Checkout\Cart` | Motor real: `addProduct`, `updateItems`, `removeItem`, `collectTotals`, `saveShippingMethod`, etc. |
| `CartRepository` | `Webkul\Checkout\Repositories\CartRepository` | `find`/`findOrFail` del carrito. |
| `ProductRepository` | `Webkul\Product\Repositories\ProductRepository` | `findOrFail` del producto y búsqueda. |
| `OrderRepository` | `Webkul\Sales\Repositories\OrderRepository` | Solo en `store` (ver Doc 01 §3). |
| `CartResource` | `packages/Webkul/Admin/src/Http/Resources/CartResource.php` | Forma JSON del carrito que consume todo el Vue (`items`, `items_count`, `have_stockable_items`, `formatted_*`, `customer_id`...). |

**Contrato de respuesta de `CartController`:** en éxito `{ data: CartResource, message }`;
en error **solo** `{ message }` con HTTP 200. El frontend distingue por la presencia de
`data.items` (ver §6). Esto es una particularidad de Bagisto: los errores de negocio del
carrito no vienen con status 4xx.

---

## 4. Componentes Blade / Vue

Árbol (todos en `packages/Webkul/Admin/src/Resources/views/sales/orders/`):

```
create.blade.php                     <v-create-order>  (raíz, estado global)
├── create/cart/items.blade.php      <v-cart-items>    (elementos del carrito + drawer "Buscar productos")
├── create/cart/address.blade.php    <v-cart-addresses>
│   └── create/cart/address/form.blade.php  <v-checkout-address-form>
├── create/cart/shipping.blade.php   <v-cart-shipping-methods>
├── create/cart/payment.blade.php    <v-cart-payment-methods>
├── create/cart/summary.blade.php    <v-cart-summary>  (cupón + "Realizar pedido")
├── create/types/*.blade.php         opciones por tipo (dentro del drawer de configuración)
│   └── configurable.blade.php       <v-product-configurable-options>
└── columna derecha (360px)
    ├── create/cart-items.blade.php          <v-previous-cart-items>
    ├── create/wishlist-items.blade.php      <v-wishlist-items>
    ├── create/compare-items.blade.php       <v-compare-items>
    └── create/recent-order-items.blade.php  <v-recent-order-items>
```

Utilidades reutilizadas: `x-admin::layouts`, `x-admin::drawer`, `x-admin::form` /
`x-admin::form.control-group.*` (vee-validate), `x-admin::quantity-changer`,
`x-admin::shimmer.*`, `this.$axios`, `this.$emitter` (`add-flash`, `open-confirm-modal`),
directiva `v-debounce`.

Puntos de extensión: `view_render_event('bagisto.admin.sales.order.create.left_component.before|after')`,
`...right_component.before|after`, `...cart.items.before|after`, `...product_options.before|after`.

### 4.1 Layout

`<div class="mt-3.5 flex gap-2.5 max-xl:flex-wrap">`: columna izquierda `flex-1`
(pasos) + columna derecha fija `w-[360px]` (fuentes de productos). En `< xl` se apilan.

### 4.2 `v-create-order` — estado global

```js
data: {
  cart: @json($cart),          // CartResource inicial (server-side)
  selectedProductOptions: null,// producto pendiente de configurar
  currentStep: 'address',      // address → shipping → payment → review
  isAddingToCart: false,
  shippingMethods: null,
  paymentMethods: null,
  canPlaceOrder: false,
}
```

Métodos: `setCart(cart)` (solo acepta payloads con `items`), `getCart()` (GET
`admin.sales.cart.index`; si falla redirige al índice), `configureAddToCart(params)`,
`addToCart(params)`, `stepReset()`, `stepForward(step)`, `stepProcessed(data)`,
`scrollToCurrentStep()`.

### 4.3 Pasos (visibilidad condicional, no wizard con páginas)

| Paso | Condición `v-if` |
|---|---|
| Elementos del carrito | siempre |
| Dirección | `cart.items_count && currentStep ∈ [address, shipping, payment, review]` |
| Envío | `cart.have_stockable_items && currentStep ∈ [shipping, payment, review]` |
| Pago | `currentStep ∈ [payment, review]` |
| Resumen | `currentStep === 'review'` |

Cualquier cambio en el carrito (`add-to-cart`, `remove-from-cart`, `cart-item-updated`)
llama `stepReset()` → vuelve a `address`: Bagisto obliga a re-confirmar dirección,
envío y pago después de tocar los ítems.

---

## 5. Modal lateral "Buscar productos" (`create/cart/items.blade.php`)

- Componente: `x-admin::drawer ref="searchProductDrawer"` (posición por defecto
  **derecha**, ancho por defecto `500px`).
- Se abre con el botón "Agregar producto" (`$refs.searchProductDrawer.open()`).
- `@close` limpia `searchTerm` y `searchedProducts`.
- **Header:** título + input `v-model.lazy="searchTerm"` + `v-debounce="500"` + spinner
  (`isSearching`) o icono lupa.
- **Búsqueda:** `watch: searchTerm → search()`. Con ≤ 1 carácter vacía resultados. Si no,
  GET `admin.catalog.products.search` con `{ query, customer_id: cart.customer_id }` y
  pinta `response.data.data`.
- **Cada resultado:** imagen (o placeholder), nombre, SKU, cantidad disponible
  (`qty_available` o suma de `inventories[].qty`), precio formateado y un **formulario
  propio por producto** (`x-admin::form`) con `product_id` hidden + `qty`
  (`required|numeric|min_value:1`) + botón "Agregar al carrito" (deshabilitado si
  `! product.is_saleable`).
- **Agregar:** `addToCart(params)` emite `add-to-cart` con `{ product, qty }` y **cierra el
  drawer**. Es decir: un producto por clic; el drawer se cierra tras cada agregado.
- **Estado vacío:** imagen + textos `search.empty-title` / `search.empty-info`.

### 5.1 Componente `x-admin::drawer` (reutilizable)

Archivo: `packages/Webkul/Admin/src/Resources/views/components/drawer/index.blade.php`

| Prop | Default | Valores |
|---|---|---|
| `position` | `right` | `left`, `right`, `top`, `bottom` |
| `width` | `500px` | cualquier CSS (en `max-sm` fuerza `!w-full`) |
| `is-active` | `false` | abre inicialmente |

Slots: `toggle`, `header` (incluye la X que llama `close`), `content`
(`flex-1 overflow-auto`), `footer`. Como `content` es `flex-1` con scroll propio, el
`footer` queda **fijo abajo** aunque el contenido sea largo.
Métodos vía `ref`: `open()`, `close()`, `toggle()`. Eventos: `open`, `close`, `toggle`
(el `close` se emite tanto al pulsar la X como al llamar `close()` por código).
El overlay **no** cierra al hacer clic.

---

## 6. Flujo de datos frontend → backend

### 6.1 Agregar producto

```
[Buscar productos] submit form(product_id, qty)
  → v-cart-items.addToCart() → $emit('add-to-cart', {product, qty}) → drawer.close()
  → v-create-order.configureAddToCart(params) + stepReset()
      ├─ product.is_options_required && sin attributes
      │     → abre drawer "productConfigurationDrawer" (types/*.blade.php)
      │     → submit → addToCart() con FormData del form + product_id + quantity
      └─ si no → addToCart(params) con { product_id, quantity, ...additional }
  → POST admin.sales.cart.items.store
      → CartController::storeItem → Cart::addProduct($product, $params)
  ← { data: CartResource, message }  → this.cart = data; flash success
  ← { message }                       → flash error (no toca this.cart)
```

Productos configurables: el drawer de configuración (`types/configurable.blade.php`)
pide `admin.catalog.products.configurable.options` y obliga a elegir atributos
(super attributes) **de a una variante por vez**. No existe matriz de tallas: para
vender S, M y L del mismo color hay que repetir el flujo 3 veces.

### 6.2 Actualizar / quitar ítem

- `x-admin::quantity-changer @change` → PUT `admin.sales.cart.items.update`
  `{ qty: { [item.id]: qty } }` → emite `cart-item-updated`.
- "Eliminar" → `open-confirm-modal` → DELETE `admin.sales.cart.items.destroy`
  `{ cart_item_id }` → emite `remove-from-cart` (si la respuesta no trae `data`, recarga
  la página).

### 6.3 Pasos y pedido

- Dirección: `v-cart-addresses` → POST `admin.sales.cart.addresses.store` → emite
  `processing` / `processed` → `stepForward` / `stepProcessed` (recarga carrito).
- Envío: POST `admin.sales.cart.shipping_methods.store`.
- Pago: POST `admin.sales.cart.payment_methods.store`.
- Resumen: cupón (`store_coupon` / `remove_coupon`) y "Realizar pedido" → POST
  `admin.sales.orders.store` → `redirect_url` a la vista del pedido.

---

## 7. Eventos importantes

| Evento | Emisor → receptor | Efecto |
|---|---|---|
| `add-to-cart` | `v-cart-items`, `v-previous-cart-items`, `v-wishlist-items`, `v-compare-items`, `v-recent-order-items` → `v-create-order` | `configureAddToCart()` + `stepReset()` |
| `remove-from-cart` | `v-cart-items` → raíz | `setCart()` + `stepReset()` |
| `cart-item-updated` | `v-cart-items` → raíz | `setCart()` + `stepReset()` |
| `processing` / `processed` | address, shipping, payment → raíz | avanza paso / guarda métodos y recarga carrito |
| `coupon-applied` / `coupon-removed` | summary → raíz | recarga |
| `add-flash` | global (`$emitter`) | toasts |
| `open-confirm-modal` | global (`$emitter`) | confirmación antes de eliminar |

Eventos de backend (no de pantalla): `checkout.order.save.before|after` y
`checkout.order.orderitem.save.*` — ver Doc 01 §3.1. **Ojo:** `checkout.order.save.after`
se dispara **antes** del `DB::commit()` de `OrderRepository::createOrderIfNotThenRetry()`;
un listener lento o colgado (p. ej. correo síncrono con SMTP roto y
`QUEUE_CONNECTION=sync`) revierte el pedido entero. Diagnosticado en VentasAsesor el
2026-10-02.

---

## 8. Validaciones y estados

| Dónde | Validación |
|---|---|
| Form de búsqueda (frontend) | `qty` `required|numeric|min_value:1` (vee-validate) |
| `storeItem` | `product_id` `required|integer|exists:products,id`; stock/opciones dentro de `Cart::addProduct` (lanza excepción → `{ message }`) |
| `destroyItem` | `cart_item_id` `required|exists:cart_items,id` |
| `storeAddress` | `CartAddressRequest` |
| `store` (pedido) | `Cart::hasError()`, `validateOrder()` (dirección, envío, pago, mínimo), guard de pago (`cashondelivery`, `moneytransfer`) |

Estados de UI: `isAddingToCart`, `isUpdating`, `isSearching` (spinners), `currentStep`,
`canPlaceOrder`, shimmer inicial (`x-admin::shimmer.sales.orders.create`).

---

## 9. Archivos exactos

```
packages/Webkul/Admin/src/Routes/sales-routes.php
packages/Webkul/Admin/src/Http/Controllers/Sales/OrderController.php
packages/Webkul/Admin/src/Http/Controllers/Sales/CartController.php
packages/Webkul/Admin/src/Http/Controllers/Catalog/ProductController.php        (search)
packages/Webkul/Admin/src/Http/Resources/CartResource.php
packages/Webkul/Admin/src/Http/Resources/AddressResource.php
packages/Webkul/Admin/src/Http/Resources/ProductResource.php
packages/Webkul/Admin/src/Resources/views/sales/orders/create.blade.php
packages/Webkul/Admin/src/Resources/views/sales/orders/create/cart/items.blade.php
packages/Webkul/Admin/src/Resources/views/sales/orders/create/cart/address.blade.php
packages/Webkul/Admin/src/Resources/views/sales/orders/create/cart/address/form.blade.php
packages/Webkul/Admin/src/Resources/views/sales/orders/create/cart/shipping.blade.php
packages/Webkul/Admin/src/Resources/views/sales/orders/create/cart/payment.blade.php
packages/Webkul/Admin/src/Resources/views/sales/orders/create/cart/summary.blade.php
packages/Webkul/Admin/src/Resources/views/sales/orders/create/cart-items.blade.php
packages/Webkul/Admin/src/Resources/views/sales/orders/create/wishlist-items.blade.php
packages/Webkul/Admin/src/Resources/views/sales/orders/create/compare-items.blade.php
packages/Webkul/Admin/src/Resources/views/sales/orders/create/recent-order-items.blade.php
packages/Webkul/Admin/src/Resources/views/sales/orders/create/types/*.blade.php
packages/Webkul/Admin/src/Resources/views/components/drawer/index.blade.php
packages/Webkul/Admin/src/Resources/views/sales/orders/index.blade.php           (paso previo: v-customer-search)
```

---

## 10. Qué reutiliza VentasAsesor y qué no

| De esta pantalla | VentasAsesor (`nueva-venta/pantalla`) |
|---|---|
| `x-admin::drawer` | **Reutilizado** (modal lateral de matrices, `position="left"`, footer fijo) y en "Ventas en curso". |
| `x-admin::modal`, `$axios`, `$emitter`, `v-debounce` | Reutilizados. |
| Patrón buscador `v-model.lazy` + `v-debounce` + `watch` | Reutilizado en el buscador de cliente. |
| Layout dos columnas | Inspiración: trabajo a la izquierda + resumen sticky a la derecha. |
| `CartController` / `Cart::addProduct` por HTTP | **No se reutiliza.** VentasAsesor usa su propia API (03G-C) sobre `NuevaVentaService`, que también termina en el `Cart` real de Bagisto pero con RT-03 (precio por grupo del cliente), solicitudes, lote todo-o-nada por línea×color y control de propiedad del borrador. |
| Drawer de configuración por variante | **Reemplazado** por la matriz de tallas (varias variantes de una vez). |
| `OrderController::store` | **No se reutiliza.** VentasAsesor confirma con `ConfirmarVentaService` (03F): idempotencia por token, cerco de inventario, atribución comercial. |
| Contrato de error `{ message }` con HTTP 200 | **No se replica.** VentasAsesor usa `RespuestaErrorNuevaVenta` (`{ codigo, mensaje }` con 4xx). |
| `stepReset()` al tocar ítems | Equivalente en VentasAsesor: el backend recalcula entrega/pago al aplicar tallas (`reaplicarEntrega`) y `estado.motivos_no_confirmable` dice qué falta. |
