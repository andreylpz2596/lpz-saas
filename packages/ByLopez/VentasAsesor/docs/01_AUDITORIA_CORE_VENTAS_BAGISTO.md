# 01_AUDITORIA_CORE_VENTAS_BAGISTO.md

Auditoría técnica del dominio de ventas de Bagisto tal como está instalado en
este proyecto. Documento de evidencia: cada afirmación importante lleva ruta de
archivo, nombre de clase, tabla o ruta HTTP real.

**Fecha:** 2026-09-08
**Alcance:** exclusivamente inspección y documentación. No se creó ni modificó
código funcional, migraciones, modelos, controladores, rutas, vistas, ACL ni
base de datos.

---

## 1. Versión y arquitectura real detectada

| Elemento | Valor detectado | Evidencia |
|---|---|---|
| Bagisto | **2.4.9** | `packages/Webkul/Core/src/Core.php:31` → `const BAGISTO_VERSION = '2.4.9'` |
| Laravel | **v12.62.0** | `composer.lock` (`laravel/framework`) |
| PHP requerido | `>=8.3 <8.5` | `composer.json` → `require.php` |
| PHP en ejecución | 8.3.33 | `php -r 'echo PHP_VERSION;'` |
| Konekt Concord | 1.18.1 | `composer.lock` |
| Prettus L5 Repository | 2.10.1 | `composer.lock` |
| Rama git | `upgrade/bagisto-2.4.9` | `git rev-parse --abbrev-ref HEAD` |
| Nombre composer del proyecto | `bagisto/bagisto` | `composer.json` |

### 1.1 El proyecto es un FORK del monorepo de Bagisto (hallazgo relevante)

```
origin    https://github.com/andreylpz2596/lpz-saas.git
upstream  https://github.com/bagisto/bagisto.git
```

Los paquetes de Webkul **no están en `vendor/`**: están versionados dentro del
repositorio en `packages/Webkul/*` y cargados por PSR-4 desde `composer.json`.
Las actualizaciones se hacen con `git merge upstream/2.4` (hay commits
`Merge remote-tracking branch 'upstream/2.4' into 2.4` en el historial).

**Consecuencia directa para el diseño:** cualquier modificación dentro de
`packages/Webkul/` genera conflicto de merge en cada actualización. Esto refuerza
la restricción ya vigente en `CLAUDE.md` y es el argumento técnico principal para
la arquitectura recomendada en §11.

### 1.2 Modificaciones locales ya existentes dentro de `packages/Webkul/`

Detectadas durante la auditoría (no introducidas por esta fase). Se documentan
porque afectan el riesgo de actualización y porque algunas tocan el flujo de
pedidos:

| Modificación local | Evidencia |
|---|---|
| Paquete `Webkul\RMA` (devoluciones) — añadido local | `git log` → commits `feat/fix: RMA issues and flow`; PSR-4 `Webkul\RMA\` en `composer.json` |
| Paquete `Webkul\EUWithdrawal` — añadido local | `git log` → `feat: add EU Withdrawal package (Directive (EU) 2023/2673)` |
| `OrderRepository::cancel($orderOrId, bool $force = false)` — parámetro `$force` no estándar | `packages/Webkul/Sales/src/Repositories/OrderRepository.php` |
| `OrderItem::canCancel(bool $force)` + `isCancelableByCustomer()` | `packages/Webkul/Sales/src/Models/OrderItem.php` |
| `Order::canReorder()` con salto de ítems `booking` | `packages/Webkul/Sales/src/Models/Order.php` |
| `order_items.rma_return_period` | migración `2026_02_11_095547` |
| `order_items.tax_category_id` FK → `nullOnDelete()` | migración `2026_04_09_120100` |
| Paquete de terceros `Brainstream\ProductPdfGenerate` | PSR-4 en `composer.json` |

### 1.3 Paquetes propios de ByLopez existentes

| Paquete | Relación con ventas/pedidos/inventario |
|---|---|
| `ByLopez\CorteConfeccion` | **Ninguna con `Webkul\Sales` ni `Webkul\Checkout` ni `Webkul\Customer`.** Solo **lee** stock: `DB::table('product_inventory_indices')` en `VarianteConsultaService.php:119`. Nunca escribe inventario ni crea pedidos. |

Imports de Webkul en todo `CorteConfeccion` (verificado con grep):
`Attribute\Models\Attribute`, `Attribute\Models\AttributeOption`,
`Core\Eloquent\Repository`, `Core\Http\Middleware\NoCacheMiddleware`,
`Core\Http\Middleware\PreventRequestsDuringMaintenance`, `DataGrid\DataGrid`,
`Product\Models\ProductProxy`, `User\Models\Admin`.

**No existe hoy ningún módulo propio que cree carritos, pedidos, direcciones,
pagos ni movimientos de inventario.**

### 1.4 ¿Existe hoy una pantalla personalizada de "Pedidos"?

**No.** Verificado:

- `app/` contiene solo 3 archivos stock (`Controller.php`, `EncryptCookies.php`,
  `AppServiceProvider.php`).
- `resources/` no contiene ningún `.php` ni `.blade.php` propio (sin overrides de
  tema).
- `routes/web.php` está prácticamente vacío (6 bytes).
- `grep -ri "pedido"` sobre `app/ resources/ routes/ config/` → 0 resultados.

La pantalla de pedidos que se usa hoy es **la del core de Bagisto**
(`admin.sales.orders.*`), incluida su pantalla de creación manual (§3.2).

---

## 2. Inventario de archivos y clases del dominio de ventas

### 2.1 `packages/Webkul/Sales/src/` — estructura

| Carpeta | Contenido |
|---|---|
| `Contracts/` | 14 interfaces (Order, OrderItem, OrderAddress, OrderPayment, OrderComment, OrderTransaction, Invoice, InvoiceItem, Shipment, ShipmentItem, Refund, RefundItem, DownloadableLinkPurchased, Sequencer) |
| `Database/Factories/` | 9 factories |
| `Database/Migrations/` | 23 migraciones |
| `Exceptions/` | `InvalidRefundQuantityException.php` |
| `Generators/` | `OrderSequencer.php`, `InvoiceSequencer.php`, `Sequencer.php` |
| `Models/` | 13 modelos + 13 `*Proxy` |
| `Providers/` | `SalesServiceProvider.php`, `ModuleServiceProvider.php` |
| `Repositories/` | 12 repositorios |
| `Traits/` | `InvoiceReminder.php`, `PaymentTerm.php` |
| `Transformers/` | `OrderResource`, `OrderAddressResource`, `OrderItemResource`, `OrderPaymentResource` |

**No tiene** `Http/`, `Routes/`, `Listeners/`, `Observers/` ni `Config/`.
`SalesServiceProvider::boot()` solo hace `loadMigrationsFrom()`.
`ModuleServiceProvider` solo declara los 13 bindings de modelos para Concord.

**No hay observers ni listeners registrados dentro de `Webkul/Sales`.** Todos los
consumidores de sus eventos viven en otros paquetes (Admin, Shop, Product,
Payment, Notification, CartRule, FPC, BookingProduct).

### 2.2 Modelos y relaciones (`Webkul\Sales\Models`)

| Modelo | Tabla | Relaciones |
|---|---|---|
| `Order` | `orders` | `cart` BelongsTo CartProxy · `items` HasMany OrderItemProxy (`whereNull(parent_id)`) · `all_items` HasMany · `comments` HasMany OrderCommentProxy · `shipments`/`invoices`/`refunds`/`transactions` **HasMany** · `customer` **MorphTo** · `channel` **MorphTo** · `addresses` HasMany OrderAddressProxy · `payment` HasOne OrderPaymentProxy · `downloadable_link_purchased` HasMany |
| `OrderItem` | `order_items` | `order` BelongsTo · `product` MorphTo · `parent`/`children`/`child` self · `invoice_items`/`shipment_items`/`refund_items` HasMany · `booking` HasOne (añadido local) |
| `OrderAddress` | **`addresses`** (hereda de `Webkul\Core\Models\Address`) | `order` BelongsTo · `customer` BelongsTo |
| `OrderPayment` | `order_payment` (explícito) | sin relaciones |
| `OrderComment` | `order_comments` | `order` BelongsTo · **fillable:** `comment`, `customer_notified`, `order_id` |
| `OrderTransaction` | `order_transactions` (explícito) | sin relaciones |
| `Invoice` | `invoices` | `order` BelongsTo · `items` HasMany · `customer`/`channel` MorphTo · `address` BelongsTo |
| `InvoiceItem` | `invoice_items` | `invoice`/`order_item` BelongsTo · `product` MorphTo · `child`/`children` |
| `Shipment` | `shipments` | `order` BelongsTo · `items` HasMany · `inventory_source` BelongsTo · `customer` MorphTo · `address` BelongsTo |
| `ShipmentItem` | `shipment_items` | `shipment`/`order_item` BelongsTo · `product` MorphTo |
| `Refund` | `refunds` | `order` BelongsTo · `items` HasMany · `customer`/`channel` MorphTo · `address` BelongsTo |
| `RefundItem` | `refund_items` | `refund`/`order_item` BelongsTo · `product` MorphTo · `child` |
| `DownloadableLinkPurchased` | `downloadable_link_purchased` | `customer`/`order`/`order_item` BelongsTo |

Todos usan `$guarded` salvo `OrderComment` y `DownloadableLinkPurchased`
(`$fillable`). Los 13 `*Proxy` son clases vacías que extienden
`Konekt\Concord\Proxies\ModelProxy` — **este es el mecanismo oficial de
sustitución de modelos** (relevante para §11).

⚠️ **Inconsistencia detectada en el core:** `Invoice::address()` y
`Refund::address()` referencian `order_address_id`, columna que **no existe** en
`invoices` ni en `refunds` (solo existe en `shipments`).

### 2.3 Repositorios y métodos que crean o mutan datos

| Repositorio | Métodos de escritura |
|---|---|
| `OrderRepository` | `create(array $data)` · `createOrderIfNotThenRetry(...)` · `cancel($orderOrId, bool $force = false)` · `updateOrderStatus($order, $orderState = null)` · `collectTotals($order)` · `generateIncrementId()` |
| `OrderItemRepository` | `collectTotals()` · `manageInventory()` · `returnQtyToProductInventory()` · `updateProductOrderedInventories()` · `manageCustomizableOptions()` |
| `InvoiceRepository` | `create(array $data, $invoiceState = null, $orderState = null)` · `collectTotals()` · `updateState()` |
| `InvoiceItemRepository` | `updateProductInventory($data)` |
| `ShipmentRepository` | `create(array $data, $orderState = null)` |
| `ShipmentItemRepository` | `updateProductInventory($data)` |
| `RefundRepository` | `create(array $data)` · `collectTotals()` |
| `RefundItemRepository` | `returnQtyToProductInventory($orderItem, $quantity)` |
| `OrderAddressRepository`, `OrderCommentRepository`, `OrderTransactionRepository` | solo CRUD heredado |
| `DownloadableLinkPurchasedRepository` | `saveLinks()` · `updateStatus()` |

### 2.4 Admin — controladores de ventas

`packages/Webkul/Admin/src/Http/Controllers/Sales/`

| Controlador | Métodos públicos |
|---|---|
| `OrderController` | `index`, `create`, `store`, `view`, `reorder`, `cancel`, `comment`, `search`, `validateOrder` |
| `CartController` | `index`, `store`, `storeItem`, `updateItem`, `destroyItem`, `storeAddress`, `storeShippingMethod`, `storePaymentMethod`, `storeCoupon`, `destroyCoupon` |
| `InvoiceController` | `index`, `create`, `store`, `view`, `sendDuplicateEmail`, `printInvoice`, `massUpdateState` |
| `ShipmentController` | `index`, `create`, `store`, `isInventoryValidate`, `view` |
| `RefundController` | `index`, `create`, `store`, `updateTotals`, `view` |
| `TransactionController` | `index`, `store`, `view` |
| `BookingController`, `BookingProductController`, `EUWithdrawalController`, `RMA/*` | añadidos locales |

Nota: `InvoiceController::create()`, `ShipmentController::create()` y
`RefundController::create()` **no tienen ruta**; sus blades se incluyen dentro de
`sales/orders/view.blade.php`.

### 2.5 Vistas Blade de ventas

`packages/Webkul/Admin/src/Resources/views/sales/`

```
address.blade.php
orders/        index, view, items, create
orders/create/ cart-items, compare-items, recent-order-items, wishlist-items
orders/create/cart/  address, address/form, items, payment, shipping, summary
orders/create/types/ simple, configurable, bundle, grouped, virtual, downloadable, booking
invoices/      index, view, create
shipments/     index, view, create
refunds/       index, view, create
transactions/  index
bookings/      index, calendar
eu-withdrawals/ index, view
rma/           returns, reasons, rules, statuses, custom-fields
```

### 2.6 DataGrids

`packages/Webkul/Admin/src/DataGrids/Sales/`: `OrderDataGrid`,
`OrderInvoiceDataGrid`, `OrderShipmentDataGrid`, `OrderRefundDataGrid`,
`OrderTransactionDataGrid`, `BookingDataGrid`, `EUWithdrawalDataGrid`, `RMA/`.

**`OrderDataGrid`** — query base: `DB::table('orders')` con `leftJoin addresses`
(shipping y billing por `address_type`), `leftJoin order_payment`,
`GROUP_CONCAT(order_payment.method)`, `groupBy('orders.id')`.

Columnas: `increment_id`, `status`, `base_grand_total`, `method`, `channel_id`,
`full_name` (alias `CONCAT(customer_first_name,' ',customer_last_name)`),
`customer_email`, `location`, `items`, `created_at`.
Acciones: solo `view` (gated `sales.orders.view`). **Sin mass actions.**

**No existe ninguna columna de asesor ni de creador para filtrar o exportar.**

---

## 3. Flujos reales de creación de pedidos

### 3.1 Flujo A — Pedido desde la tienda virtual (storefront)

```
POST shop.checkout.onepage.orders.store      (api/checkout/onepage/orders)
 → Webkul\Shop\Http\Controllers\API\OnepageController::storeOrder()   (:149)
   → Cart::hasError()                        [Webkul\Checkout\Facades\Cart]
   → Cart::collectTotals()
   → $this->validateOrder()                  (:207)  min. amount, direcciones, tarifa, pago
   → $cart = Cart::getCart()
   → Payment::getRedirectUrl($cart)          (pasarelas externas cortan aquí)
   → $data = (new Webkul\Sales\Transformers\OrderResource($cart))->jsonSerialize()   (:177)
   → Webkul\Sales\Repositories\OrderRepository::create($data)                        (:180)
       → createOrderIfNotThenRetry()         (OrderRepository.php:45)
           → Event 'checkout.order.save.before'
           → $data['status'] = Order::STATUS_PENDING
           → orders.insert (increment_id vía OrderSequencer)
           → order_payment.insert
           → addresses.insert (billing + shipping)
           → por ítem: Event 'checkout.order.orderitem.save.before'
                       → OrderItemRepository::create() → order_items
                       → manageInventory()  → product_ordered_inventories
                       → Event 'checkout.order.orderitem.save.after'
           → Event 'checkout.order.save.after'   ← PUNTO DE ENGANCHE CLAVE
   → Cart::deActivateCart()
   → redirect shop.checkout.onepage.success
```

Rutas: `packages/Webkul/Shop/src/Routes/api.php:82-92` (acciones) y
`checkout-routes.php:14-18` (páginas).

### 3.2 Flujo B — Pedido desde el panel administrativo

**Confirmado: es funcionalidad del CORE de Bagisto 2.4.9, no una personalización
de este proyecto.** Es un flujo basado en carrito administrativo.

```
[Pedidos] index → botón "Crear" (gated bouncer 'sales.orders.create')
   sales/orders/index.blade.php:15-30 → drawer <v-customer-search>
 → POST admin.sales.cart.store            (sales/cart/create)
   → Admin\...\Sales\CartController::store()
       → CustomerRepository::findOrFail(request('customer_id'))
       → Cart::createCart(['customer' => $customer, 'is_active' => false])
       → redirect_url = route('admin.sales.orders.create', $cart->id)
 → GET  admin.sales.orders.create/{cartId} → OrderController::create()  (pantalla)
 → POST admin.sales.cart.{id}/items        → CartController::storeItem()
 → POST admin.sales.cart.{id}/addresses    → CartController::storeAddress()
 → POST admin.sales.cart.{id}/shipping-methods → storeShippingMethod()
 → POST admin.sales.cart.{id}/payment-methods  → storePaymentMethod()
 → POST admin.sales.orders.store/{cartId}
   → Admin\...\Sales\OrderController::store()
       → Cart::setCart($cart) → Cart::collectTotals() → validateOrder()
       → guard: payment.method ∈ ['cashondelivery','moneytransfer']   ← RESTRICCIÓN REAL
       → $data = (new OrderResource($cart))->jsonSerialize()          ← AQUÍ SE PIERDE EL CREADOR (§5)
       → OrderRepository::create($data)
       → Cart::removeCart($cart)
       → redirect admin.sales.orders.view
```

Segundo punto de entrada: ficha de cliente →
`admin.customers.customers.cart.store` (`customers-routes.php:54-55`).

**Ambos flujos (A y B) convergen en el mismo motor**: `OrderResource` →
`OrderRepository::create()` → mismos eventos. Esto es una ventaja arquitectónica:
un solo punto de enganche cubre los dos orígenes.

### 3.3 Flujo C — Pedido creado por un módulo propio

**No existe.** `ByLopez\CorteConfeccion` no importa `Webkul\Sales`,
`Webkul\Checkout` ni `Webkul\Customer` (verificado por grep, §1.3).

### 3.4 Flujo D — Pedido por API / automatización (n8n, WhatsApp)

**No existe hoy un API REST de pedidos.** Verificado:

- Directorios `Routes/` presentes: Admin, Installer, PayU, PhonePe, Razorpay,
  Shop, Stripe. **No hay `Webkul/RestApi` ni `Webkul/GraphQLAPI`.**
- `packages/Webkul/Admin/src/Routes/rest-routes.php` **no** expone pedidos: solo
  dashboard, datagrid, saved-filters, TinyMCE upload, Magic AI, cuenta y 2FA.
- `packages/Webkul/Shop/src/Routes/api.php` es el API **AJAX del storefront**
  (sesión de cliente): `shop.api.*` + `shop.checkout.onepage.*`. Técnicamente
  podría automatizarse manteniendo sesión de cliente, pero no es un API de
  integración.
- `packages/Webkul/Shop/src/Routes/webmcp-routes.php` solo expone
  `shop.webmcp.product` y `shop.webmcp.wishlist.add`.

Conclusión: hoy, una automatización (n8n/WhatsApp) solo puede crear pedidos
(a) simulando el checkout del storefront con sesión, o (b) invocando
`OrderRepository::create()` desde código PHP propio. **Ambas rutas requieren
diseño explícito en una fase futura.**

---

## 4. Estados: inventario real y separación de responsabilidades

### 4.1 Estado comercial del pedido — `orders.status`

Constantes en `packages/Webkul/Sales/src/Models/Order.php`:

| Constante | Valor | Etiqueta |
|---|---|---|
| `STATUS_PENDING` | `pending` | Pending |
| `STATUS_PENDING_PAYMENT` | `pending_payment` | Pending Payment |
| `STATUS_PROCESSING` | `processing` | Processing |
| `STATUS_COMPLETED` | `completed` | Completed |
| `STATUS_CANCELED` | `canceled` | Canceled |
| `STATUS_CLOSED` | `closed` | Closed |
| `STATUS_FRAUD` | `fraud` | Fraud |

⚠️ `Order::getStatusLabelAttribute()` hace `$this->statusLabel[$this->status]`
**sin null-coalesce**: un estado desconocido provoca error. Esto prohíbe
inyectar estados propios en `orders.status`.

**Nadie asigna el estado directamente.** El único escritor es
`OrderRepository::updateOrderStatus($order, $orderState = null)`
(`OrderRepository.php:315-340`):

```
Event 'sales.order.update-status.before'
si se pasa $orderState  → status = $orderState
si no:
    status = PROCESSING
    if isInCompletedState()  status = COMPLETED
    if isInCanceledState()   status = CANCELED
    elseif isInClosedState() status = CLOSED
guardar; Event 'sales.order.update-status.after'
```

- `isInCanceledState()` → `Σqty_ordered === Σqty_canceled`
- `isInClosedState()` → `Σqty_ordered === Σqty_refunded + Σqty_canceled`
- `isInCompletedState()` → lógica de cantidades (facturado/enviado/cancelado)

Llamadores: `InvoiceRepository::create()`, `ShipmentRepository::create()`
(fuerza `pending_payment` si hay factura abierta), `RefundRepository::create()`,
`OrderRepository::cancel()`.

**No existe ruta ni pantalla para "cambiar el estado del pedido" a mano.**

### 4.2 Estado del pago

No hay una columna `payment_status` en `orders`. El pago se representa por:

- `order_payment.method` / `method_title` (cómo se pagará)
- `order_transactions` (transacciones registradas; `admin.sales.transactions.store`)
- Derivados en `orders`: `grand_total_invoiced`, `base_grand_total_invoiced`,
  y los accesores `getTotalDueAttribute()` / `getBaseTotalDueAttribute()`
  (`grand_total - grand_total_invoiced`).

### 4.3 Estado de la factura — `invoices.state`

`packages/Webkul/Sales/src/Models/Invoice.php`:

| Constante | Valor |
|---|---|
| `STATUS_PENDING` | `pending` |
| `STATUS_PENDING_PAYMENT` | `pending_payment` |
| `STATUS_PAID` | `paid` |
| `STATUS_OVERDUE` | `overdue` |
| `STATUS_REFUNDED` | `refunded` |

Columna: **`state`** (no `status`). `InvoiceRepository::create()` la fija en
`paid` salvo que se pase `$invoiceState`. Existe `massUpdateState`.

### 4.4 Estado de despacho / envío — hallazgo crítico

`packages/Webkul/Sales/src/Models/Shipment.php` **no define ninguna constante de
estado y no tiene métodos de ayuda**. La columna `shipments.status` existe en la
migración pero **`ShipmentRepository::create()` nunca la escribe**.

**Conclusión: en Bagisto 2.4.9 el envío no tiene máquina de estados.** No existe
"en tránsito", "entregado", "entrega fallida" ni "devuelto" a nivel de envío.

### 4.5 Estado de la transportadora

No existe. `shipments.carrier_title` y `shipments.track_number` son **texto libre
capturado a mano** por el administrador en
`views/sales/shipments/create.blade.php` (líneas ~70 y ~87).
`shipments.carrier_code` existe en el esquema pero **ninguna línea de código de
la aplicación la escribe** (columna muerta).

Transportadoras disponibles de fábrica (`packages/Webkul/Shipping/src/Config/carriers.php`):

| code | método | clase | tarifa |
|---|---|---|---|
| `flatrate` | `flatrate_flatrate` | `Webkul\Shipping\Carriers\FlatRate` | `default_rate` = 10 |
| `free` | `free_free` | `Webkul\Shipping\Carriers\Free` | 0 |

**No hay ninguna integración con transportadoras** (sin SDK, sin API de tarifas,
sin generación de guías, sin webhooks de rastreo).

### 4.6 Estado de la comisión

**No existe ningún concepto de comisión en el core.** Ninguna tabla, columna,
clase ni configuración.

### 4.7 ¿Un pedido puede tener varios envíos y varias guías?

**Sí, confirmado por tres vías independientes:**

1. `Order::shipments()` es **`HasMany`** (`Order.php:185-188`), no `HasOne`.
2. `shipments.order_id` es FK sin índice único
   (migración `2018_09_27_115022_create_shipments_table.php`).
3. Lógica de envío parcial en todo el flujo:
   `OrderItem::getQtyToShipAttribute() = qty_ordered - qty_shipped - qty_refunded - qty_canceled`;
   `ShipmentRepository` incrementa `qty_shipped` de forma acumulativa;
   `Order::canShip()` sigue en `true` mientras quede `qty_to_ship > 0`.

**Por tanto el número de guía NO puede modelarse como una columna del pedido.**
Vive (y debe seguir viviendo) a nivel de envío: `shipments.track_number`.

Dato real actual: 22 envíos, todos con `track_number`, **máximo 1 envío por
pedido hoy** — pero los valores son de relleno (`"1"`, `"1"`, `"2"`), lo que
indica que la guía real no se está capturando con disciplina.

### 4.8 Mapeo de las situaciones de negocio pedidas

| Situación de negocio | Dónde vive hoy | ¿Existe? |
|---|---|---|
| Pendiente de pago | `orders.status = pending / pending_payment` | Sí |
| Pagado | `invoices.state = paid` + `grand_total_invoiced` | Sí (derivado) |
| Facturado | `invoices` + `order_items.qty_invoiced` | Sí |
| Listo para despacho | — | **No existe** |
| Con uno o varios envíos | `shipments` (HasMany) | Sí |
| En tránsito | — | **No existe** |
| Entregado | — | **No existe** |
| Entrega fallida | — | **No existe** |
| Devuelto (logístico) | — | **No existe** (RMA local cubre otra cosa) |
| Cancelado parcial | `order_items.qty_canceled` | Sí |
| Cancelado total | `orders.status = canceled` | Sí |
| Reembolsado parcial | `refunds` + `qty_refunded` | Sí |
| Reembolsado total | `orders.status = closed` | Sí |

**Los cuatro estados logísticos que el negocio necesita (listo, en tránsito,
entregado, fallido/devuelto) no existen en Bagisto y deben modelarse en el módulo
propio, sobre `shipments`, sin tocar `orders.status`.**

---

## 5. Identidad del creador — hallazgo central

### 5.1 Qué guarda Bagisto hoy

| Dato | ¿Se guarda? | Evidencia |
|---|---|---|
| Cliente que compra | **Sí** | `orders.customer_id` + `customer_type` (MorphTo) + `customer_email/first_name/last_name` |
| Usuario admin que creó el pedido | **NO** | ver §5.2 |
| Usuario que modificó el pedido | **NO** | no hay columnas de auditoría en `orders` |
| Usuario que creó la factura | **NO** | `invoices` no tiene columna de usuario |
| Usuario que creó el envío | **NO** | `shipments` no tiene columna de usuario |
| Autor de un comentario | **NO** | `order_comments` = `comment`, `customer_notified`, `order_id` |
| Historial de acciones administrativas | **NO** | no existe tabla de log/auditoría en el core |

Verificación del último punto: la única tabla de auditoría en las 163 tablas de
la base de datos es `bylopez_cc_orden_corte_cantidad_audits`, del módulo propio
de Corte y Confección.

### 5.2 Evidencia de que `orders` no tiene columna de usuario administrativo

Columnas reales de `orders` (consultadas en la base de datos en vivo con
`Schema::getColumnListing('orders')`): las únicas que enlazan personas son
`customer_id`, `customer_type`, `customer_email`, `customer_first_name`,
`customer_last_name`. **No hay `created_by`, `admin_id`, `user_id`, `agent_id`
ni `sales_person`.**

Solo **dos** migraciones tocan la tabla en todo el proyecto:

- `packages/Webkul/Sales/src/Database/Migrations/2018_09_27_113154_create_orders_table.php`
- `packages/Webkul/Sales/src/Database/Migrations/2024_04_19_102939_add_incl_tax_columns_in_orders_table.php` (8 columnas de impuestos)

Verificación exhaustiva sobre **todo el repositorio** (no solo `packages/`):
`table('orders'` produce 10 coincidencias; la única mutación de esquema es la
migración de 2024 (líneas 14 y 41) más un `DB::table('orders')->update()` de
relleno (línea 28). Las 7 restantes son lecturas de DataGrids
(`Admin\DataGrids\Sales\OrderDataGrid`, `Admin\DataGrids\Customers\View\OrderDataGrid`,
`Admin\DataGrids\Sales\RMA\*`, `Shop\DataGrids\*`) y `OrderFactory`.
Las 8 migraciones de nivel aplicación (`database/migrations/`) no tocan `orders`.

⚠️ **Aviso de nomenclatura para evitar confusión futura:** existe
`database/migrations/2026_03_11_113926_create_agent_conversations_table.php`
(tablas `agent_conversations`, `agent_conversation_messages`). Extiende
`Laravel\Ai\Migrations\AiMigration` — pertenece al paquete **`laravel/ai`** y su
`agent` es un **agente de IA**, no un asesor comercial. No tiene ninguna relación
con ventas ni con `orders`, y no debe reutilizarse para atribución comercial.

### 5.3 Punto exacto donde se pierde la atribución

En el flujo administrativo (§3.2), el administrador **está autenticado** durante
todo el proceso (`auth()->guard('admin')->user()` disponible). La información se
pierde en un punto concreto y verificable:

`packages/Webkul/Admin/src/Http/Controllers/Sales/OrderController::store()`:

```php
$data = (new OrderResource($cart))->jsonSerialize();   // ← AQUÍ
$order = $this->orderRepository->create($data);
```

`Webkul\Sales\Transformers\OrderResource::toArray()` serializa **únicamente
campos del carrito**: `cart_id`, `is_guest`, `customer_id`, `customer_type`,
`customer_email/first_name/last_name`, `channel_id/name/type`, contadores,
monedas, todos los totales, `coupon_code`, `applied_cart_rule_ids`,
`billing_address`, `shipping_address`, `shipping_method/title/description`,
`payment`, `items`. **Ningún campo del usuario administrativo.**

Son dos huecos independientes y acumulativos:

1. **Transformador:** `OrderResource` no conoce al admin y no lo emite.
2. **Esquema:** aunque lo emitiera, `orders` no tiene columna donde recibirlo.
3. **Carrito:** tampoco sirve de puente — la tabla `cart` (singular) no tiene
   ninguna columna de usuario administrativo
   (verificado con `Schema::getColumnListing('cart')`).

### 5.4 Consecuencia de diseño

Ni `created_by_admin_id` ni `advisor_id` ni `origin_channel` pueden derivarse de
datos existentes. **Requieren almacenamiento propio.** Y como el core no ofrece
la información, el módulo debe capturarla **en el momento en que ocurre el
evento**, no después.

El enganche natural existe y es estable: el evento `checkout.order.save.after`
recibe el `$order` recién creado y se dispara en **ambos** flujos (storefront y
admin). En ese instante:

- `auth()->guard('admin')->id()` → devuelve el admin si el pedido nació en el
  panel; `null` si nació en el storefront.
- Eso mismo sirve como **discriminador natural de canal de origen** por defecto.

### 5.5 Diferenciación obligatoria de conceptos

| Concepto | Significado | ¿Puede coincidir? |
|---|---|---|
| `created_by_admin_id` | Usuario administrativo que **ejecutó** la creación | Sí, normalmente |
| `advisor_id` | Asesor comercial **dueño** de la venta | No siempre |
| `origin_channel` | Origen de la venta (ecommerce, WhatsApp, teléfono, presencial, carga manual, API, n8n) | — |
| Reasignador | Quién cambió el `advisor_id` | — |
| Fecha y motivo de reasignación | Trazabilidad del cambio | — |

Casos reales que prueban que **no** deben unificarse: un supervisor registra el
pedido para otro asesor; una automatización (n8n) crea el pedido y luego se
asigna el asesor; un pedido de tienda virtual que después atiende un asesor.

---

## 6. Inventario: cómo se mueve realmente

Bagisto usa un **modelo de reserva**, no de descuento inmediato.

| Tabla | Rol |
|---|---|
| `product_inventories` | Stock físico por fuente (`inventory_source_id`) |
| `product_ordered_inventories` | Stock **reservado** por canal |
| `product_inventory_indices` | Índice derivado (**no** es fuente de verdad) |

| # | Disparador | Clase::método | Efecto |
|---|---|---|---|
| 1 | Pedido creado | `OrderItemRepository::manageInventory()` | `product_ordered_inventories.qty += qty_ordered` (**reserva**; no toca stock físico) |
| 2 | **Envío creado** | `ShipmentItemRepository::updateProductInventory()` | `product_ordered_inventories.qty -= qty` **y** `product_inventories.qty -= qty` (**descuento físico real**) |
| 3 | Factura de ítems no inventariables con caja de cantidad | `InvoiceItemRepository::updateProductInventory()` | mismas tablas |
| 4 | Cancelación | `OrderItemRepository::returnQtyToProductInventory()` (desde `OrderRepository::cancel()`) | libera reserva y devuelve físico de lo ya enviado |
| 5 | Reembolso | `RefundItemRepository::returnQtyToProductInventory()` | devuelve físico de lo enviado y reduce reserva |

Índice (`Webkul\Product\Helpers\Indexers\Inventory::getQuantity()`):
`qty = Σ product_inventories.qty (fuentes activas del canal) − product_ordered_inventories.qty`.

Reindexación por eventos (`Webkul\Product\Providers\EventServiceProvider`):
`checkout.order.save.after`, `sales.order.cancel.after`, `sales.refund.save.after`
→ `UpdateCreateInventoryIndexJob`.

**Implicación para comisiones:** "unidad vendida" (reservada) y "unidad
despachada" (descontada de stock) son eventos distintos y ocurren en momentos
distintos. Las columnas confiables son las de `order_items`:
`qty_ordered`, `qty_invoiced`, `qty_shipped`, `qty_canceled`, `qty_refunded`
(recalculadas por `OrderItemRepository::collectTotals()` sumando
`invoice_items` / `shipment_items` / `refund_items`).

---

## 7. Facturas, envíos, cancelaciones y reembolsos

### 7.1 Factura
```
POST admin.sales.invoices.store (sales/invoices/create/{order_id})
 → InvoiceController::store() → $order->canInvoice()
   → InvoiceRepository::create($data)
       Event 'sales.invoice.save.before'
       invoices.insert (increment_id InvoiceSequencer, state = paid)
       invoice_items.insert
       OrderItemRepository::collectTotals()   → qty_invoiced, total_invoiced
       InvoiceRepository::collectTotals() → OrderRepository::collectTotals()
       OrderRepository::updateOrderStatus()
       Event 'sales.invoice.save.after'
```
Auto-factura: `Webkul\Payment\Listeners\GenerateInvoice@handle` escucha
`checkout.order.save.after` y factura automáticamente `cashondelivery` /
`moneytransfer` si está configurado.

### 7.2 Envío
```
POST admin.sales.shipments.store (sales/shipments/create/{order_id})
 → ShipmentController::store() → $order->canShip()
   → isInventoryValidate()   (qty ≤ qty_to_ship y stock suficiente en la fuente)
   → ShipmentRepository::create($data)
       Event 'sales.shipment.save.before'
       shipments.insert (carrier_title, track_number, inventory_source_id, order_address_id)
       shipment_items.insert
       ShipmentItemRepository::updateProductInventory()   ← descuento físico
       order_items.qty_shipped += qty
       updateOrderStatus(...)   (pending_payment si hay factura abierta)
       Event 'sales.shipment.save.after'
```

### 7.3 Cancelación
```
POST admin.sales.orders.cancel → OrderController::cancel($id)
 → OrderRepository::cancel($id, force: true)      [force: modificación local]
     Event 'sales.order.cancel.before'
     por ítem: returnQtyToProductInventory() ; qty_canceled += qty_to_cancel
     updateOrderStatus($order)
     Event 'sales.order.cancel.after'
```
`qty_to_cancel = qty_ordered - qty_canceled - qty_invoiced` → **lo ya facturado
no se cancela, se reembolsa**.

### 7.4 Reembolso
```
POST admin.sales.refunds.store → RefundController::store() → $order->canRefund()
 → RefundRepository::create($data)
     Event 'sales.refund.save.before'
     refunds.insert (state = refunded) ; refund_items.insert
     RefundItemRepository::returnQtyToProductInventory()
     OrderItemRepository::collectTotals() ; collectTotals del pedido
     updateOrderStatus($order)
     Event 'sales.refund.save.after'
```
`qty_to_refund = qty_invoiced - qty_refunded` → **solo se reembolsa lo facturado**.

---

## 8. Eventos disponibles (puntos de extensión)

Todos vía `Illuminate\Support\Facades\Event::dispatch`.

| Evento | Origen | Payload |
|---|---|---|
| `checkout.order.save.before` | `OrderRepository.php:52` | `[$data]` |
| `checkout.order.orderitem.save.before` | `OrderRepository.php:67` | `$item` (array) |
| `checkout.order.orderitem.save.after` | `OrderRepository.php:83` | `$orderItem` |
| **`checkout.order.save.after`** | `OrderRepository.php:86` | **`$order`** |
| `sales.order.cancel.before` / `.after` | `OrderRepository.php:149` / `:191` | `$order` |
| `sales.order.update-status.before` / `.after` | `OrderRepository.php:317` / `:339` | `$order` |
| `sales.invoice.save.before` / `.after` | `InvoiceRepository.php:49` / `:184` | `$data` / `$invoice` |
| **`sales.shipment.save.before` / `.after`** | `ShipmentRepository.php:47` / `:139` | `$data` / **`$shipment`** |
| `sales.refund.save.before` / `.after` | `RefundRepository.php:45` / `:162` | `$data` / `$refund` |
| `sales.order.comment.create.before` / `.after` | `Admin\...\Sales\OrderController.php:210` / `:214` | — / `$comment` |
| `catalog.product.update.after` | Invoice/ShipmentItemRepository | `$product` |

⚠️ No existe `sales.order.save.after`: la creación se anuncia como
`checkout.order.save.after`.

Ya escuchan `checkout.order.save.after`: Admin, BookingProduct, CartRule, FPC,
Notification, Payment, Product, Shop. **Añadir un listener más es el patrón
normal y no invasivo.**

---

## 9. ACL y menú de ventas

`packages/Webkul/Admin/src/Config/acl.php`:

| Clave | Rutas cubiertas |
|---|---|
| `sales` | `admin.sales.orders.index` |
| `sales.orders` | `orders.index`, `orders.search` |
| `sales.orders.create` | `orders.create`, `orders.store`, `orders.reorder`, **los 10 `cart.*`**, `admin.customers.customers.cart.store` |
| `sales.orders.view` | `orders.view`, `orders.comment` |
| `sales.orders.cancel` | `orders.cancel` |
| `sales.invoices` / `.view` / `.create` / `.update` | índice / ver+imprimir+email / crear / mass-update estado |
| `sales.shipments` / `.view` / `.create` | índice / ver / crear |
| `sales.refunds` / `.view` / `.create` | índice / ver / crear+update_totals |
| `sales.transactions` / `.view` | índice+crear / ver |
| `sales.rma.*`, `sales.eu_withdrawals.*`, `sales.bookings` | añadidos locales |

Menú (`menu.php`): `sales` → orders, shipments, invoices, refunds,
transactions, bookings, rma, eu_withdrawals.

**Observación de granularidad:** `sales.orders.create` es una clave **plana** que
habilita simultáneamente el carrito administrativo y la creación del pedido. No
permite separar "crear pedido" de "manipular carrito". (Mismo patrón que ya se
documentó como riesgo en el módulo de Corte y Confección.)

### 9.1 Roles reales existentes en esta instalación

| id | Rol | permission_type | Observación |
|---|---|---|---|
| 1 | Administrador | `all` | Bypass total de ACL |
| 2 | **ASESOR VENTAS** | `custom` | **Ya existe** y ya tiene `sales.orders.create`, `sales.orders.view`, `sales.orders.cancel`, `sales.invoices.*`, `sales.shipments.*`, `sales.refunds.*`, `sales.transactions.*`, `catalog.products` |
| 4 | BODEGA | `custom` | — |
| 52 | TALLER CONFECCION | `custom` | — |

Dos hallazgos operativos:

1. **Ningún usuario tiene hoy el rol `ASESOR VENTAS`** (los admins usan roles
   1, 3, 4, 52).
2. **Integridad referencial rota:** el admin `ASESOR_PRUEBA` (id 52) tiene
   `role_id = 3`, y **el rol 3 no existe** en la tabla `roles`. Con
   `permission_type` inaccesible, `Webkul\User\Http\Middleware\Bouncer`
   ejecutaría `abort(401)` para ese usuario. **No se corrigió** (fase de solo
   auditoría).

---

## 10. Datos reales en la base de datos (línea base)

| Métrica | Valor |
|---|---|
| Pedidos | 24 (`completed` 12, `pending_payment` 11, `canceled` 1) |
| Pedidos invitado (`is_guest=1`) | 0 — los 24 tienen `customer_id` |
| Facturas | 23 |
| Envíos | 22 — **máximo 1 por pedido hoy** |
| Envíos con `track_number` | 22/22, pero con valores de relleno (`"1"`, `"2"`) |
| Reembolsos | 0 |
| Canales | 1 (`ByLopez | Camisetas Piel de Durazno (Colombia)`) |
| Administradores | 6 |
| Clientes | 14 |
| Tablas totales | 163 |

**Nota sobre `channel_id`:** es el canal de venta de Bagisto (tienda), no el
origen comercial. **No puede reutilizarse como `origin_channel`.**

---

## 11. Puntos de extensión disponibles y arquitectura

### 11.1 Mecanismos que Bagisto ofrece a un paquete externo

| Mecanismo | Cómo | Precedente en este proyecto |
|---|---|---|
| Eventos | `Event::listen('checkout.order.save.after', ...)` | 8 paquetes del core ya lo hacen |
| Sustitución de modelos | Proxies de Concord (`OrderProxy`, …) + `config/concord.php` | `CorteConfeccion\Providers\ModuleServiceProvider` |
| ACL propio | `mergeConfigFrom(Config/acl.php, 'acl')` | `CorteConfeccionServiceProvider.php:15` |
| Menú propio | `mergeConfigFrom(Config/menu.php, 'menu.admin')` | `CorteConfeccionServiceProvider.php:14` |
| Vistas / traducciones / migraciones propias | `loadViewsFrom`, `loadTranslationsFrom`, `loadMigrationsFrom` | `CorteConfeccionServiceProvider.php:22-24` |
| DataGrid propio | extender `Webkul\DataGrid\DataGrid` | 5 DataGrids en `CorteConfeccion` |
| Repositorios | extender `Webkul\Core\Eloquent\Repository` | idem |
| Lectura de datos del core | consultas dirigidas encapsuladas en un Service | `VarianteConsultaService` |

**El paquete `ByLopez\CorteConfeccion` es la prueba de que este stack admite un
módulo complementario completo sin tocar una sola línea de `Webkul/`.**

### 11.2 Comparación de alternativas

| # | Alternativa | Ventajas | Riesgos | Impacto en actualizaciones | Duplicación | Inconsistencias | Complejidad de pruebas |
|---|---|---|---|---|---|---|---|
| 1 | Copiar `Webkul/Sales` completo | Control total | Reimplementar totales, impuestos, inventario, secuenciadores, facturas, envíos, reembolsos | Muy alto: divergencia permanente | Máxima | Casi garantizadas (dos motores de stock) | Muy alta |
| 2 | Heredar/sobrescribir clases del core | Reutiliza el motor | Acopla el módulo a firmas internas (`OrderRepository::cancel($force)` ya divergió localmente) | Alto: cada merge de upstream puede romperlo | Media | Medias | Alta |
| 3 | **Paquete complementario** que usa modelos, contratos, repositorios y **eventos** existentes | Bagisto sigue siendo fuente de verdad; cero conflicto de merge; patrón ya probado en el proyecto | Depende de que los eventos sigan existiendo (son API pública estable desde 1.x) | **Mínimo** | Mínima | Bajas | Media |
| 4 | Módulo independiente con tablas de pedidos duplicadas | Aislamiento | Dos verdades de stock, totales y estados; conciliación permanente | Alto | Máxima | **Garantizadas** | Muy alta |

### 11.3 Recomendación

**Alternativa 3 — paquete complementario `ByLopez\VentasAsesor`.**

No se encontró ninguna restricción técnica que lo impida. Al contrario, la
auditoría encontró tres condiciones que lo favorecen:

1. Los flujos A (storefront) y B (admin) **convergen** en
   `OrderRepository::create()` y disparan el mismo evento
   `checkout.order.save.after` → un solo enganche cubre ambos orígenes.
2. `Order::shipments()` es `HasMany` → la trazabilidad logística puede colgarse
   de `shipments` sin inventar una tabla de envíos propia.
3. El proyecto ya opera un módulo complementario equivalente
   (`CorteConfeccion`) con ACL, menú, DataGrids y migraciones propias, y con
   cero acoplamiento a `Webkul/Sales`.

### 11.4 Límites propuestos entre paquetes

```
Webkul/Sales        → Fuente de verdad: pedidos, ítems, direcciones, pagos,
                      facturas, envíos, reembolsos, cancelaciones, totales,
                      inventario, estados oficiales, secuenciadores.
                      NUNCA se modifica.

Webkul/Admin        → Pantallas oficiales de venta (crear/ver/facturar/enviar/
                      reembolsar). NUNCA se modifica. Se puede *complementar*
                      con menús, ACL y vistas propias desde fuera.

ByLopez/VentasAsesor→ Capa especializada. Tablas PROPIAS solo para lo que el
                      core no modela: atribución comercial, estado logístico,
                      evidencias, reglas de comisión, liquidaciones, metas.
                      Lee pedidos/ítems/envíos del core; nunca los reescribe.
```

**El módulo NO debe replicar:** tabla de pedidos, cálculo de totales, movimiento
de inventario, facturación, envíos, reembolsos, ni la máquina de estados
existente.

---

## 12. Hallazgos

| # | Hallazgo | Severidad | Evidencia |
|---|---|---|---|
| H1 | `orders` no guarda ningún usuario administrativo; la atribución se pierde en `OrderResource` | **Crítico para el objetivo** | §5.2, §5.3 |
| H2 | Los envíos no tienen máquina de estados (`shipments.status` nunca se escribe, sin constantes) | **Alto** | §4.4 |
| H3 | `carrier_title` / `track_number` son texto libre sin validación; `carrier_code` es columna muerta | **Alto** | §4.5 |
| H4 | No existe integración con transportadoras (solo `flatrate` y `free`) | Medio | §4.5 |
| H5 | No hay tabla de auditoría de acciones administrativas en el core | **Alto** | §5.1 |
| H6 | `order_comments` no guarda autor | Medio | §5.1 |
| H7 | `Order::getStatusLabelAttribute()` sin null-coalesce → prohíbe estados propios en `orders.status` | Medio | §4.1 |
| H8 | `OrderRepository::createOrderIfNotThenRetry()` hace `DB::commit()` en `finally` incluso tras `rollBack()` | Medio (core) | `OrderRepository.php:112-115` |
| H9 | `Invoice::address()` / `Refund::address()` usan `order_address_id`, columna inexistente en esas tablas | Bajo (core) | §2.2 |
| H10 | Admin `ASESOR_PRUEBA` (id 52) tiene `role_id = 3` inexistente → `abort(401)` | **Alto (datos)** | §9.1 |
| H11 | Rol `ASESOR VENTAS` existe con permisos de venta pero **ningún usuario lo tiene asignado** | Medio (datos) | §9.1 |
| H12 | Creación de pedido en admin restringida a `cashondelivery` / `moneytransfer` | Medio (funcional) | §3.2 |
| H13 | `packages/Webkul/` ya contiene modificaciones locales (RMA, EUWithdrawal, `$force` en cancel) → cada merge de upstream es un punto de riesgo | **Alto** | §1.2 |
| H14 | `OrderDataGrid` no tiene mass actions ni columnas de asesor/creador | Bajo | §2.6 |
| H15 | Guías actuales cargadas con valores de relleno (`"1"`, `"2"`) | Medio (datos) | §10 |

---

## 13. Riesgos para el módulo futuro

1. **Dependencia de eventos.** Si un merge de upstream renombra
   `checkout.order.save.after`, la atribución dejaría de registrarse. Mitigación:
   prueba de regresión que cree un pedido real y verifique que se generó la fila
   de atribución.
2. **Pedidos creados fuera del panel.** Un pedido de storefront no tendrá
   `created_by_admin_id`. Debe ser un caso válido (`origin_channel = ecommerce`,
   `advisor_id = null`), no un error.
3. **Comisión sobre datos móviles.** `qty_shipped`, `qty_refunded` y
   `qty_canceled` se recalculan (`collectTotals`) después de la venta: una
   liquidación calculada "en vivo" cambia sola. Exige congelar (snapshot) las
   cantidades y la regla al momento de liquidar.
4. **Multi-envío.** Cualquier diseño que asuma una guía por pedido será
   incorrecto (§4.7).
5. **Estados logísticos propios.** Deben vivir en tablas del módulo y **nunca**
   escribirse en `orders.status` (H7).
6. **Evidencias.** Sin almacenamiento privado y autorización explícita se expone
   información de clientes; el core no ofrece nada para esto.
7. **Integridad de roles.** H10/H11 deben resolverse antes de apoyar permisos de
   asesor sobre esos roles.

---

## 14. Qué puede reutilizarse y qué requiere tablas propias

### 14.1 Reutilizable directamente (sin duplicar)

| Necesidad | Tabla/clase del core |
|---|---|
| Pedido y su identidad | `orders` (`increment_id`, `status`, totales) |
| Unidades vendidas / facturadas / despachadas / canceladas / devueltas | `order_items.qty_ordered / qty_invoiced / qty_shipped / qty_canceled / qty_refunded` |
| Valor de la venta | `orders.grand_total`, `base_grand_total`, `*_invoiced`, `*_refunded` |
| Cliente y direcciones | `customers`, `addresses` (`address_type`) |
| Facturación | `invoices`, `invoice_items` |
| Envíos y guías | `shipments` (`carrier_title`, `track_number`), `shipment_items` |
| Reembolsos | `refunds`, `refund_items` |
| Pagos | `order_payment`, `order_transactions` |
| Comentarios | `order_comments` |
| Producto y variantes | `products`, `product_flat`, `product_inventory_indices` |
| Usuarios y roles | `admins`, `roles` |

### 14.2 Requiere tablas propias del módulo

| Necesidad | Motivo |
|---|---|
| Atribución comercial (`advisor_id`, `created_by_admin_id`, `origin_channel`) | No existe ninguna columna (H1) |
| Historial de reasignación de asesor | No existe auditoría (H5) |
| Estado logístico (listo, en tránsito, entregado, fallido, devuelto) | Los envíos no tienen máquina de estados (H2) |
| Historial de cambios de guía | No existe (H3, H5) |
| Evidencias (archivos, categoría, autor, nota) | No existe nada equivalente |
| Reglas de comisión + snapshot de la regla aplicada | No existe el concepto (§4.6) |
| Liquidaciones, ajustes y estado de pago de comisión | idem |
| Metas mensuales por asesor | idem |
| KPIs materializados (si se decide precalcular) | idem |

---

## 15. Pruebas existentes

- `packages/Webkul/Sales/`: **sin carpeta de pruebas**.
- `packages/Webkul/Admin/tests/Feature/Sales/`: existen pruebas del core
  (referencian, por ejemplo, `carrier_code` en `ShipmentTest.php`).
- `packages/ByLopez/CorteConfeccion/tests/`: 128 pruebas propias en verde
  (referencia de estilo y de suites por paquete en `phpunit.xml`).

---
## 16. Auditoría dirigida: ítems configurables y doble conteo de prendas

> **Fase 00B.** Toda esta sección se sustenta en consultas ejecutadas sobre la
> base de datos real (24 pedidos, 174 filas en `order_items`), no en supuestos.

### 16.1 Cómo materializa Bagisto un producto configurable en `order_items`

Al vender un producto configurable, Bagisto escribe **dos filas** en
`order_items`:

| Fila | `parent_id` | `type` | `sku` | `product_id` | `qty_ordered` | `price` | `total` |
|---|---|---|---|---|---|---|---|
| Padre | `NULL` | `configurable` | SKU del padre (`124DURZ-H`) | id del **configurable** (215) | **N real** | precio real | total real |
| Hija | id de la fila padre | `simple` | SKU de la **variante** (`124DURZ-H-M`) | id de la **variante** (217) | **0** | `1.0000` | `0.0000` |

Evidencia directa (extracto real, pedido 5):

```
order_id | oi_id | parent_id | type         | sku            | qty_ord | qty_inv | qty_shp | price
5        | 56    | NULL      | configurable | 124DURZ-H      | 9       | 9       | 9       | 6900
5        | 57    | 56        | simple       | 124DURZ-H-M    | 0       | 9       | 9       | 1
```

### 16.2 Agregados reales sobre las 174 filas

```
FILAS: total=174   padre/simple=152   hijas=22

columna         SUM padres   SUM hijas   SUM TOTAL (ingenuo)
qty_ordered     608          0           608     <- NO duplica
qty_invoiced    605          55          660     <- DUPLICA (+55)
qty_shipped     591          52          643     <- DUPLICA (+52)
qty_canceled    3            0           3       <- NO duplica
qty_refunded    0            0           0       <- sin datos

Filas hijas con valor > 0:
qty_ordered  = 0 filas
qty_invoiced = 20 filas
qty_shipped  = 18 filas
qty_canceled = 0 filas

Filas hijas con price distinto de 1: 0   |   Filas hijas con total distinto de 0: 0
```

### 16.3 Conclusiones con evidencia

1. **¿Padre e hija repiten la cantidad?**
   Depende de la columna. `qty_ordered` y `qty_canceled` **solo** se escriben en
   el padre (la hija queda en 0). `qty_invoiced` y `qty_shipped` se escriben en
   **ambas** filas. Por tanto:
   - `SUM(order_items.qty_ordered)` es **correcto** tal cual (608 prendas).
   - `SUM(order_items.qty_shipped)` **sobrecuenta** un 8,8 % (643 frente a 591 reales).
   - `SUM(order_items.qty_invoiced)` **sobrecuenta** (660 frente a 605 reales).

2. **¿Cuál fila representa la variante física?**
   La **hija** aporta la identidad (SKU de la variante, `product_id` de la
   variante), pero **no aporta cantidad ni dinero** (`qty_ordered = 0`,
   `price = 1`, `total = 0`). La fila **padre** aporta cantidad y dinero, pero su
   `product_id` y su SKU son los del producto configurable, no los de la
   variante.
   **Ninguna de las dos filas, por sí sola, describe la prenda vendida.**

3. **Cómo evitar doble conteo en unidades vendidas:**

   ```sql
   SUM(qty_ordered) WHERE parent_id IS NULL
   ```

   Hoy equivale a `SUM(qty_ordered)`, pero es explícito y queda a prueba de que
   Bagisto cambie de criterio en una actualización.

4. **Cómo evitar doble conteo en unidades despachadas y facturadas:**
   El filtro `parent_id IS NULL` es **obligatorio**, no opcional:

   ```sql
   SUM(qty_shipped)  WHERE parent_id IS NULL   -- 591, correcto
   SUM(qty_invoiced) WHERE parent_id IS NULL   -- 605, correcto
   ```

   Es exactamente el criterio que aplica el propio core en
   `Webkul\RMA\Helpers\Helper::getOrderItems()`, con `whereNull('order_items.parent_id')`.

5. **Cómo enlazar correctamente `shipment_items`:**
   `shipment_items` **no tiene columna `parent_id`** (verificado con
   `Schema::getColumnListing`). Genera **una sola fila por prenda enviada** y su
   `order_item_id` apunta **siempre a la fila padre**:

   ```
   shipment_items: 144 filas, SUM(qty) = 591
   -> coincide exactamente con SUM(qty_shipped) WHERE parent_id IS NULL (591)
   -> las 144 filas apuntan a order_items con parent_id IS NULL
   ```

   Fila real (`shipment_items.id = 48`):

   ```
   order_item_id = 56           -> fila PADRE del pedido 5
   sku           = 124DURZ-H-M  -> SKU de la VARIANTE            (correcto)
   product_id    = 215          -> id del CONFIGURABLE           (NO es la variante)
   qty           = 9
   additional.selected_configurable_option = 217  -> id real de la VARIANTE
   additional.attributes = {Talla: M, Color: Blanco}
   ```

   > **`shipment_items` es la unidad canónica de prenda entregada.** No duplica,
   > lleva el SKU y los atributos reales de la variante, y su `qty` es la
   > cantidad física. Es la tabla correcta para causar comisión por entrega.

   > **Advertencia:** `shipment_items.product_id` es el producto **configurable**,
   > no la variante. Usarlo como identidad de la prenda produciría un error
   > silencioso: todas las tallas y colores colapsarían en un mismo producto.
   > La identidad de la variante está en
   > `additional.selected_configurable_option` y en `sku`.

6. **`invoice_items` sí tiene `parent_id`** y sí duplica:

   ```
   169 filas: 149 con parent_id NULL (SUM qty 605) + 20 hijas (SUM qty 55)
   ```

   Cualquier métrica sobre `invoice_items` requiere el mismo filtro.

### 16.4 Tratamiento por tipo de producto

| Tipo | Presente hoy | Filas en `order_items` | Criterio de conteo |
|---|---|---|---|
| `simple` | Sí (152 filas, 551 uds) | 1 fila, `parent_id NULL` | Directo |
| `configurable` | Sí (22 filas, 57 uds) | 2 filas (padre + hija) | Solo el padre para cantidad; la variante desde la hija o desde `additional` |
| `bundle` | No | padre + N hijas | **Sin verificar**: exigiría decidir si se comisiona el bundle o sus componentes |
| `grouped` | No | N filas independientes | **Sin verificar** |
| `virtual` / `downloadable` | No | 1 fila, no genera envío | **Nunca causaría comisión por entrega** (no hay `shipment_item`) |
| Gratuito / garantía / muestra | No modelado hoy | igual que su tipo base | Requiere marca explícita de "no comisionable" (D-03) |

**Para el negocio actual (camisetas con color y talla) la unidad física
inventariable es la variante**, y la fuente correcta para contarla es
`shipment_items` (entregado) o la fila padre de `order_items` junto con el
`additional` de la variante (vendido). Esto queda **verificado con datos
reales**, no asumido.

### 16.5 Fórmulas corregidas

| Métrica | Fórmula **incorrecta** anterior | Fórmula corregida |
|---|---|---|
| Unidades vendidas | `SUM(qty_ordered)` | `SUM(qty_ordered) WHERE parent_id IS NULL` |
| Unidades facturadas | `SUM(qty_invoiced)` | `SUM(qty_invoiced) WHERE parent_id IS NULL` |
| Unidades despachadas | `SUM(qty_shipped)` | `SUM(shipment_items.qty)` (canónico) |
| Unidades entregadas | — | `SUM(shipment_items.qty)` de envíos con estado logístico `entregado` |
| Unidades canceladas | `SUM(qty_canceled)` | `SUM(qty_canceled) WHERE parent_id IS NULL` |
| Unidades reembolsadas | `SUM(qty_refunded)` | `SUM(qty_refunded) WHERE parent_id IS NULL` |

### 16.6 Dato anómalo detectado

`order_items.id = 43` (pedido 3) tiene `sku = temporary-sku-29ae88`: un SKU
temporal de Bagisto que quedó persistido en un pedido real. Confirma el criterio de
integridad referencial (documento 02, §12): **`product_id` y `sku` no son identidades históricas fiables**, y por eso
el módulo debe congelar snapshots de SKU y nombre en cada línea de comisión.

---

## 17. Auditoría del paquete RMA

> **Fase 00B, corregido en Fase 00C.** `packages/Webkul/RMA/`: 64 archivos,
> 11 migraciones de creación más 2 de renombrado de títulos.
>
> **CORRECCIÓN C-1 (Fase 00C).** La versión original de esta sección afirmaba
> que RMA era «una modificación local de este fork» y que las dos migraciones de
> renombrado eran «locales». **Ambas afirmaciones son falsas.** RMA y sus 13
> migraciones son código **upstream** de Bagisto:
> `git cat-file -e upstream/2.4:packages/Webkul/RMA/src/Database/Migrations/2026_06_24_000000_rename_received_package_rma_status.php`
> confirma que existen en `upstream/2.4` (`fa0ff2b3`, 2026-08-24).
>
> De hecho la relación es la inversa: este árbol está **por detrás** de upstream
> en RMA y le faltan endurecimientos suyos. Detalle en
> `05A_AUDITORIA_RMA_Y_MAPA_CORE_RELACIONADO.md` §22.

> **Auditoría profunda:** esta sección es el resumen de la Fase 00B. El análisis
> completo —flujos de cliente y administrador, máquina de estados, cantidades
> parciales, inventario, seguridad y estado real de los datos— está en
> `05A_AUDITORIA_RMA_Y_MAPA_CORE_RELACIONADO.md` (Fase 00C).

### 17.1 Tablas

| Tabla | Columnas relevantes | FK |
|---|---|---|
| `rma` | `order_id`, `rma_status_id`, `package_condition`, `information` | `order_id` → `orders` **onDelete CASCADE**; `rma_status_id` → `rma_statuses` `set null` |
| `rma_items` | `rma_id`, `rma_reason_id`, **`order_item_id`**, **`variant_id`**, `quantity`, `resolution` | `rma_id` → `rma` cascade; `order_item_id` → `order_items` **cascade**; `variant_id` → `products` `restrict` |
| `rma_statuses` | `title`, `status`, `color`, `default` | — |
| `rma_reasons` | `title`, `status`, `position`, `is_admin` | — |
| `rma_reason_resolutions` | `rma_reason_id`, `resolution_type` | cascade |
| `rma_rules` | `name`, `status`, `return_period`, `default` | — |
| `rma_images` | evidencias adjuntas a la solicitud | — |
| `rma_messages` | conversación cliente ↔ admin | — |
| `rma_custom_fields`, `rma_custom_field_options`, `rma_additional_fields` | campos configurables | — |

> **CORRECCIÓN C-3 (Fase 00C).** La versión original decía: «`rma_items` guarda
> `variant_id`. El core de RMA **sí resuelve** la identidad de la variante
> física». Es **impreciso**. La columna existe y se **escribe**
> (`RequestController.php:262` y `Shop/…/RMAController.php:216`), pero
> **nunca se lee**: `RMAItem` no define ninguna relación `variant()`, y no hay
> ni una lectura en las vistas de Admin ni de Shop.
>
> Peor aún, la relación que sí existe —`RMAItem::product()`— es un
> `hasOneThrough` que resuelve a `order_items.product_id`, que para un
> configurable es el **producto padre**, no la variante.
>
> **RMA almacena la identidad de la variante; no la resuelve.** Detalle en
> `05A_…md` §3.2 (hallazgo H-RMA-2).

### 17.2 Estados

Los estados **no son un enum cerrado**: viven en la tabla `rma_statuses` y son
editables desde el panel (`admin.sales.rma.statuses.*`). El enum
`DefaultRMAStatusEnum` solo fija los **ids por defecto**:

| id | Título original | Título tras el renombrado local | Significado |
|---|---|---|---|
| 1 | Pending | **Pending Review** | Solicitud recibida |
| 2 | Accept | **Approved** | Aprobada |
| 3 | Awaiting | **Awaiting Return** | Esperando que el cliente devuelva |
| 4 | Dispatched Package | **Return In Transit** | El cliente ya despachó la devolución |
| 5 | Received Package | **Refunded** | **Dispara el reembolso real del pedido** |
| 6 | Solved | Solved | Resuelta |
| 7 | Declined | **Request Declined** | Rechazada |
| 8 | Item Canceled | Item Canceled | **Dispara la cancelación de ítems** |
| 9 | Canceled | **Request Canceled** | Solicitud anulada |

`DefaultRMAResolution` ofrece dos resoluciones: `return` y `cancel_items`.

### 17.3 Cantidades

- `rma_items.quantity` es la cantidad solicitada por línea.
- `Helper::getRemainingQtyAfterRMA()` calcula
  `qty_ordered − SUM(rma_items.quantity)` excluyendo RMA en estado `DECLINED` o
  `CANCELED`.
- `Helper::getOrderItems()` filtra por **`whereNull('order_items.parent_id')`**
  (confirma §16.3) y por `qty_ordered > qty_refunded + qty_canceled`, más la
  ventana de devolución `order_items.rma_return_period` (columna local del fork).
- Calcula `forReturnQuantity = qty_invoiced − qty_refunded` y
  `forCancelQuantity = qty_ordered − qty_invoiced − qty_canceled`.

### 17.4 Relación con reembolsos e inventario

El flujo real está en
`packages/Webkul/Admin/src/Http/Controllers/Sales/RMA/RequestController.php`:

- `updateStatus()` resuelve con `match($statusId)`:
  - **`RECEIVED_PACKAGE` (5)** → `prepareRefundDataFromRmaItem()` →
    `processOrderRefund()` → `RefundRepository::create()`.
  - **`ITEM_CANCELED` (8)** → cancelación → `OrderRepository::updateOrderStatus()`
    y `Event::dispatch('sales.order.cancel.after', $order)`.
- `processOrderRefund()` valida `$order->canRefund()`, calcula totales con
  `RefundRepository::getOrderItemsRefundSummary()` y valida el monto contra
  `grand_total − SUM(base_adjustment_refund)`.

**¿Devuelve inventario?** Sí, pero **indirectamente**: es
`Webkul\Sales\Repositories\RefundRepository::create()` quien invoca
`RefundItemRepository::returnQtyToProductInventory()`, y lo hace de forma
diferenciada para los `childOrderItem` (líneas 134 y 145). RMA **no toca
inventario por su cuenta**.

### 17.5 Eventos

```
grep -rn "Event::dispatch" packages/Webkul/RMA/src/   ->   (ningún resultado)
```

El paquete `RMA` **no dispara ningún evento propio**. Los únicos eventos del
flujo están en el controlador de Admin:

| Evento | Origen |
|---|---|
| `sales.rma.request.create.before` | `RequestController::store()` :243 |
| `sales.rma.request.create.after` | `RequestController::store()` :295 |
| `sales.order.cancel.after` | `RequestController` :478 (vía cancelación) |
| `sales.refund.save.before` / `.after` | `RefundRepository` (no RMA) |

> **CORRECCIÓN C-2 (Fase 00C).** La tabla anterior está **incompleta**. El
> *paquete* `packages/Webkul/RMA/src/` efectivamente no contiene ningún
> `Event::dispatch`, pero el *flujo* de RMA emite **diez** eventos en total. La
> Fase 00B omitió los cuatro del lado cliente —
> `customer.rma.request.create.before` / `.after` y
> `customer.rma.request.update.before` / `.after`
> (`Shop/…/RMAController.php` :197, :249, :311, :349, :388) — y los de catálogo
> (`sales.rma.reason.*`, `sales.rma.rules.*`). Catálogo completo en
> `05A_…md` §12.1.

**El hueco real, formulado con precisión:** no existe evento en la **transición
de estado administrativa**. `RequestController::updateStatus()` y
`finalizeRmaUpdate()` no emiten nada, y son justamente los que producen el
reembolso y la cancelación. El único enganche fiable a ese hecho es
`sales.refund.save.after`, emitido por `RefundRepository` dentro de la
transacción. Ver `05A_…md` §12.3 y §12.4.

### 17.6 Controladores y permisos

Controladores en `packages/Webkul/Admin/src/Http/Controllers/Sales/RMA/`:
`RequestController`, `ReasonController`, `RulesController`, `StatusController`,
`CustomFieldController`. DataGrids en `Admin/src/DataGrids/Sales/RMA/`.

Claves ACL (`packages/Webkul/Admin/src/Config/acl.php`, líneas 148 y siguientes):
`sales.rma`, `sales.rma.requests`, `sales.rma.requests.create`,
`sales.rma.reasons[.create|.edit|.delete]`, `sales.rma.rules[...]`,
`sales.rma.statuses[...]`, `sales.rma.custom-fields[...]`.

### 17.7 Flujo de aprobación y casos cubiertos

| Concepto | ¿Lo resuelve RMA? |
|---|---|
| Solicitud de devolución del cliente | Sí (razones, resoluciones, imágenes y mensajería) |
| Aprobación / rechazo | Sí (estados 2 y 7) |
| Devolución **parcial** | Sí — `rma_items.quantity` por línea |
| Ventana de devolución | Sí — `rma_rules.return_period` y `order_items.rma_return_period` |
| Reembolso financiero | Sí, delegado en `RefundRepository` |
| Retorno de inventario | Sí, indirectamente vía `RefundRepository` |
| Cancelación de ítems no despachados | Sí (estado 8) |
| **Reposición / cambio por otra prenda** | **No** — solo `return` y `cancel_items` |
| **Garantía** | **No modelado** como concepto propio |
| **Retorno de transportadora** (nunca entregado) | **No** — ver §17.9 |

### 17.8 Estado de uso real

```
rma          = 0 filas       rma_statuses           = 0 filas
rma_items    = 0 filas       rma_reasons            = 0 filas
refunds      = 0 filas       rma_reason_resolutions = 0 filas
refund_items = 0 filas       rma_rules              = 0 filas

order_items con rma_return_period NOT NULL =   0
order_items con rma_return_period NULL     = 174
```

> **AMPLIACIÓN (Fase 00C).** RMA no está solo sin usar: está **inoperante**.
> Tres condiciones independientes lo bloquean hoy:
>
> 1. `rma_statuses` vacía → no hay estado que asignar.
> 2. `rma_reasons` vacía → `rma_reason_id` es obligatorio; sin catálogo no hay
>    RMA posible.
> 3. `order_items.rma_return_period` es `NULL` en las **174** filas, y
>    `Helper::getOrderItems()` descarta explícitamente todo ítem con ese campo
>    nulo → **devuelve colección vacía para todos los pedidos**.
>
> Ningún cliente y ningún administrador puede crear un RMA en esta instalación
> en su estado actual. Ver `05A_…md` §15 (hallazgo H-RMA-14) y la decisión
> pendiente **D-25**.

Ninguna decisión de diseño puede apoyarse en el comportamiento observado de RMA
en producción; solo en su código.

### 17.9 RMA no es lo mismo que retorno de transportadora

| | RMA | Retorno de transportadora |
|---|---|---|
| ¿La prenda llegó al cliente? | **Sí** | **No** |
| Quién lo inicia | Cliente (o admin en su nombre) | La transportadora |
| Qué modela Bagisto | Solicitud, razón, resolución, reembolso | **Nada** |
| Efecto en comisión | Ajuste negativo (ya se había causado) | **Nunca causó comisión** |
| Tabla | `rma` / `rma_items` | **no existe** |

Esta distinción es la razón por la que el módulo **sí** necesita estado
logístico propio por envío (§4.4) aunque RMA exista.

### 17.10 ¿Debe VentasAsesor crear tablas de devoluciones propias?

**No.** RMA ya resuelve solicitud, razón, resolución, cantidad por línea,
evidencias, conversación, aprobación, reembolso e inventario. Duplicarlo sería
exactamente el error que esta fase busca evitar.

> **Lista de vacíos sustituida (Fase 00C).** La auditoría profunda amplió esta
> lista de 6 a 12 entradas y corrigió V-3. La versión vigente es la de
> `05A_AUDITORIA_RMA_Y_MAPA_CORE_RELACIONADO.md` §19. Se conserva aquí solo el
> resumen:
>
> | # | Vacío |
> |---|---|
> | V-1 | Estado logístico del envío |
> | V-2 | **Entrega confirmada** — Bagisto solo registra despacho |
> | V-3 | **Evento en la transición de estado administrativa** (no «RMA no dispara eventos»: sí lo hace, pero no ahí) |
> | V-4 | Retorno de transportadora |
> | V-5 | Vínculo `rma` ↔ `refunds` (no existe FK) |
> | V-6 | Autor de cada acción de RMA |
> | V-7 | Identidad de la variante en la devolución (se guarda, no se usa) |
> | V-8 | Idempotencia del reembolso |
> | V-9 | Relación con `shipment_items` |
> | V-10 | Reposición y garantía |
> | V-11 | Recepción física separada del reembolso |
> | V-12 | Ajuste de comisión por devolución |

---

## 18. Riesgo de autorización del rol `ASESOR VENTAS` (datos reales)

### 18.1 Permisos actuales del rol

`roles.id = 2`, `name = ASESOR VENTAS`, `permission_type = custom`, **18 permisos**:

```
sales, sales.orders, sales.orders.create, sales.orders.view, sales.orders.cancel,
sales.invoices, sales.invoices.view, sales.invoices.create,
sales.shipments, sales.shipments.view, sales.shipments.create,
sales.refunds, sales.refunds.view, sales.refunds.create,
sales.transactions, sales.transactions.view,
catalog, catalog.products
```

### 18.2 Clasificación del riesgo

| Permiso | Riesgo | Severidad |
|---|---|---|
| `sales.orders.view` | Ve **todos** los pedidos de **todos** los asesores; el core no tiene filtro por propietario | **CRÍTICO** — autorización horizontal |
| `sales.orders.cancel` | Puede cancelar ventas ajenas | **CRÍTICO** |
| `sales.refunds.create` | Puede **generar reembolsos**: mueve dinero e inventario | **CRÍTICO** |
| `sales.invoices.create` | Puede facturar | ALTO |
| `sales.shipments.create` | Puede despachar y descontar inventario físico | ALTO |
| `sales.transactions.view` | Ve movimientos de pago | MEDIO |
| `catalog.products` | Ve catálogo y costos | MEDIO |

Ninguna ruta de `Webkul\Sales` filtra por propietario, porque el concepto de
propietario **no existe** en el core (§5). Conocer un `order_id` basta para
consultar cualquier venta.

### 18.3 Usuario con rol huérfano

```
admins.id = 52  |  ASESOR_PRUEBA  |  asesor.prueba@example.test  |  role_id = 3  |  status = 1
roles existentes: 1 (Administrador), 2 (ASESOR VENTAS), 4 (BODEGA), 52 (TALLER CONFECCION)
```

El `role_id = 3` **no existe**. Consecuencia técnica verificada en el código:

- `Webkul\User\Bouncer::hasPermission()` evalúa
  `auth()->guard('admin')->user()->role->permission_type`
- `Webkul\User\Models\Admin::hasPermission()` evalúa `$this->role->permission_type`

Con `role` a `null`, ambas producen un **error fatal de PHP** ("Attempt to read
property on null") y por tanto **HTTP 500**, no un 401. El usuario está
efectivamente inoperante y cualquier pantalla que evalúe permisos para él falla.

> **CORRECCIÓN (Fase 01).** El párrafo anterior es cierto para los dos *métodos*,
> pero **no para cualquier pantalla**. Hay que separar dos momentos:
>
> - **Rutas administrativas con sesión:** el middleware
>   `Webkul\User\Http\Middleware\Bouncer::isPermissionsEmpty()` comprueba el rol
>   **antes** de llegar a cualquier controlador y hace `abort(401)` si es nulo.
>   Resultado: **401**, no 500. Verificado por prueba HTTP en la Fase 01.
> - **Inicio de sesión:** `SessionController::store()` llama a
>   `bouncer()->hasPermission('dashboard')` justo después de autenticar, fuera de
>   ese middleware; con rol nulo lanza error. En el entorno Laravel el aviso se
>   convierte en `ErrorException` (verificado por prueba unitaria). Conclusión
>   **inferida de alta confianza**, no probada por HTTP porque exigiría una fila
>   real en `admins`: el login de un usuario con rol huérfano termina en **500**.
>
> En ambos casos el fallo ocurre **dentro de Bagisto, antes** de cualquier módulo
> propio. Detalle en `06_FASE_01_BASE_PAQUETE_ACL.md` §13.

**Precondición operativa (no se corrige en esta fase documental):**

1. Asignar a ASESOR_PRUEBA un rol existente.
2. Revisar y reducir los permisos de `ASESOR VENTAS` antes de dar acceso real.
3. Ningún asesor debe conservar `sales.orders.cancel`, `sales.refunds.create`,
   `sales.invoices.create` ni `sales.shipments.create` para operar la interfaz
   propia del módulo.

---

## 19. Clases oficiales reutilizables para crear un pedido

> **Fase 00B.** Confirmadas leyendo el código, no supuestas. Alimentan
> `05_DISENO_TECNICO_CREACION_PEDIDOS_ASESOR.md`.

### 19.1 `Webkul\Checkout\Cart` (facade `Webkul\Checkout\Facades\Cart`)

Métodos públicos reales relevantes, con su línea en `packages/Webkul/Checkout/src/Cart.php`:

| Método | Línea | Uso |
|---|---|---|
| `createCart(array $data)` | 130 | Crear el carrito (admite `customer` e `is_active`) |
| `setCart(Contracts\Cart $cart)` | 105 | Fijar el carrito de trabajo |
| `getCart()` | 122 | Recuperar el carrito actual |
| `addProduct(ProductContract $product, array $data)` | 263 | Añadir producto o variante configurable |
| `updateItems(array $data)` | 348 | Cambiar cantidades |
| `removeItem(int $itemId)` | 324 | Quitar línea |
| `saveAddresses(array $params)` | 429 | Direcciones de facturación y envío |
| `saveShippingMethod(string $code)` | 566 | Método de envío |
| `savePaymentMethod(array $params)` | 602 | Método de pago |
| `setCouponCode(?string $code)` | 625 | Cupón |
| `collectTotals()` | 856 | **Cálculo oficial de totales** |
| `validateItems()` | 955 | Revalida precios y disponibilidad |
| `hasError()` / `getErrors()` | 756 / 764 | Estado de error del carrito |
| `isItemsHaveSufficientQuantity()` | 830 | **Validación de stock** |
| `haveMinimumOrderAmount()` | 818 | Monto mínimo |
| `removeCart(Contracts\Cart $cart)` | 163 | Cerrar el carrito tras crear el pedido |

### 19.2 Validación oficial previa al pedido

Existen **dos implementaciones equivalentes** de `validateOrder()`, ambas
públicas:

- `Webkul\Admin\Http\Controllers\Sales\OrderController::validateOrder()` (:252)
- `Webkul\Shop\Http\Controllers\API\OnepageController::validateOrder()` (:207)

Comprobaciones comunes: monto mínimo, dirección de envío si hay ítems
enviables, dirección de facturación, tarifa de envío seleccionada y método de
pago. La del storefront añade validaciones de cuenta del cliente (suspendida o
inactiva) y de PayPal.

**No existe una clase de servicio reutilizable para esta validación**: la lógica
está duplicada dentro de dos controladores. El módulo deberá reimplementar el
mismo conjunto de comprobaciones en su servicio de aplicación, o invocar el
método público del controlador de Admin. Es un vacío real del core, no una
omisión de esta auditoría.

### 19.3 Transformación y persistencia

| Clase | Método | Papel |
|---|---|---|
| `Webkul\Sales\Transformers\OrderResource` | `jsonSerialize()` | Convierte el carrito en el arreglo del pedido |
| `Webkul\Sales\Repositories\OrderRepository` | `create(array $data)` | **Única vía autorizada** para persistir el pedido; dispara `checkout.order.save.after` |
| `Webkul\Sales\Repositories\OrderRepository` | `updateOrderStatus($order)` | Recalcula el estado oficial |
| `Webkul\Customer\Repositories\CustomerRepository` | `create` / `findOneWhere` | Cliente |
| `Webkul\Product\Repositories\ProductRepository` | `findOrFail` | Producto a añadir al carrito |

### 19.4 Rutas oficiales equivalentes (referencia de flujo, no de llamada)

El flujo administrativo del core encadena estas rutas, todas cubiertas por la
clave ACL `sales.orders.create`:

```
admin.sales.cart.store                    POST  cart/create
admin.sales.cart.items.store              POST  cart/{id}/items
admin.sales.cart.items.update             PUT   cart/{id}/items
admin.sales.cart.items.destroy            DELETE cart/{id}/items
admin.sales.cart.addresses.store          POST  cart/{id}/addresses
admin.sales.cart.shipping_methods.store   POST  cart/{id}/shipping-methods
admin.sales.cart.payment_methods.store    POST  cart/{id}/payment-methods
admin.sales.cart.store_coupon             POST  cart/{id}/coupon
admin.sales.orders.store                  (crea el pedido)
```

`Webkul\Admin\Http\Controllers\Sales\CartController` es una envoltura HTTP casi
transparente sobre la facade `Cart`: cada método hace `setCart()`, llama al
método homónimo de `Cart` y devuelve `CartResource`. **Esto confirma que el
módulo puede orquestar la facade directamente, sin llamadas HTTP internas.**

---

## 20. Verificación de no intrusión

- No se creó ni modificó código funcional en ninguna de las dos fases (00 y 00B).
- `packages/Webkul/` → **0 archivos modificados**. Todo el trabajo sobre RMA e
  ítems configurables fue inspección de solo lectura (`cat`, `grep`,
  `Schema::getColumnListing`, `SELECT`).
- `packages/ByLopez/CorteConfeccion/` → **0 archivos modificados en estas fases**;
  los cambios que muestra `git status` son previos y provienen de la fase SEC-01.
- Ninguna consulta ejecutada modificó datos: solo lectura de esquema y `SELECT`.
- Único cambio: documentos Markdown en `packages/ByLopez/VentasAsesor/docs/`.
