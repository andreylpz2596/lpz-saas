# Contexto tecnico: autocomplete/select en admin

Este documento describe el patron actual de busqueda seleccionable usado en el admin, tomando como referencia principal la busqueda de cliente al crear un pedido. Tambien se documenta el patron hermano de agregar producto a un pedido y la implementacion existente de referencia/color en Compra de Tela.

## Archivos relevantes

### Buscar cliente para crear pedido

- Blade con Vue inline: `packages/Webkul/Admin/src/Resources/views/sales/orders/index.blade.php`
  - Componente: `<v-customer-search ref="selectCustomerComponent"></v-customer-search>`
  - Template: `#v-customer-search-template`
  - Busqueda: `this.$axios.get("{{ route('admin.customers.customers.search') }}", { params: { query: this.searchTerm } })`
  - Seleccion: `this.$axios.post("{{ route('admin.sales.cart.store') }}", {customer_id: customer.id})`
- Controller de busqueda de clientes: `packages/Webkul/Admin/src/Http/Controllers/Customers/CustomerController.php`
  - Metodo: `search()`
  - Repositorio usado: `Webkul\Customer\Repositories\CustomerRepository`
- Controller que recibe el cliente seleccionado y crea el carrito/pedido admin: `packages/Webkul/Admin/src/Http/Controllers/Sales/CartController.php`
  - Metodo: `store()`
  - Valida existencia con `findOrFail(request()->input('customer_id'))`
  - Retorna `redirect_url` hacia `admin.sales.orders.create`
- Controller de pantalla de creacion de pedido: `packages/Webkul/Admin/src/Http/Controllers/Sales/OrderController.php`
  - Metodo: `create(int $cartId)`
  - Carga el carrito y expone `CartResource`, que contiene `customer_id`
- Rutas:
  - `packages/Webkul/Admin/src/Routes/web.php`
  - `packages/Webkul/Admin/src/Routes/customers-routes.php`
  - `packages/Webkul/Admin/src/Routes/sales-routes.php`
- Recurso de carrito: `packages/Webkul/Admin/src/Http/Resources/CartResource.php`
- Modelo serializado por la busqueda: `packages/Webkul/Customer/src/Models/Customer.php`
- Componentes/utilidades reutilizadas:
  - `x-admin::drawer`
  - `v-debounce="500"`
  - `this.$axios`
  - `this.$emitter`
  - `x-admin::form` y `x-admin::form.control-group` en formularios relacionados

### Agregar producto al crear pedido

- Blade con Vue inline: `packages/Webkul/Admin/src/Resources/views/sales/orders/create/cart/items.blade.php`
  - Componente: `v-cart-items`
  - Busqueda: `admin.catalog.products.search`
  - Parametros: `query` y `customer_id`
  - Persistencia: emite `product.id` y luego se envia como `product_id`
- Controller de busqueda de productos: `packages/Webkul/Admin/src/Http/Controllers/Catalog/ProductController.php`
  - Metodo: `search()`
- Ruta: `packages/Webkul/Admin/src/Routes/catalog-routes.php`
  - `admin.catalog.products.search`
- Resource JSON: `packages/Webkul/Admin/src/Http/Resources/ProductResource.php`
- Componente reutilizable parecido: `packages/Webkul/Admin/src/Resources/views/components/products/search.blade.php`

### Compra de Tela y Corte/Confeccion

- Rutas del modulo: `packages/ByLopez/CorteConfeccion/src/Http/routes.php`
  - `admin.corte_confeccion.compras_tela.referencias`
- Controller: `packages/ByLopez/CorteConfeccion/src/Http/Controllers/Admin/CompraTelaController.php`
  - Metodo: `referencias(Request $request)`
- Blade/JS inline: `packages/ByLopez/CorteConfeccion/src/Resources/views/admin/compras-tela/form.blade.php`
  - Usa `fetch(referenciasUrl + '?' + params.toString())`
  - Renderiza un `input list` + `datalist`
  - Guarda `referencia_tela_id` en hidden
- Requests:
  - `packages/ByLopez/CorteConfeccion/src/Http/Requests/StoreCompraTelaRequest.php`
  - `packages/ByLopez/CorteConfeccion/src/Http/Requests/UpdateCompraTelaRequest.php`
- Servicios:
  - `packages/ByLopez/CorteConfeccion/src/Services/CompraTelaService.php`
  - `packages/ByLopez/CorteConfeccion/src/Services/OrdenCorteService.php`
  - `packages/ByLopez/CorteConfeccion/src/Services/ProductLookupService.php`
- Repositories:
  - `packages/ByLopez/CorteConfeccion/src/Repositories/CompraTelaRepository.php`
  - `packages/ByLopez/CorteConfeccion/src/Repositories/ProveedorTelaRepository.php`
  - `packages/ByLopez/CorteConfeccion/src/Repositories/RolloRepository.php`
  - `packages/ByLopez/CorteConfeccion/src/Repositories/OrdenCorteRepository.php`
- Modelos:
  - `packages/ByLopez/CorteConfeccion/src/Models/ProveedorTelaReferencia.php`
  - `packages/ByLopez/CorteConfeccion/src/Models/Rollo.php`
  - `packages/ByLopez/CorteConfeccion/src/Models/CompraTela.php`
  - `packages/ByLopez/CorteConfeccion/src/Models/OrdenCorte.php`

## Flujo real: buscar cliente al crear pedido

1. El usuario entra a Ventas > Pedidos y hace clic en Crear pedido.
2. El boton abre el drawer del componente `v-customer-search`.
3. El usuario escribe en el input de busqueda.
4. El input usa `v-model.lazy="searchTerm"` y `v-debounce="500"`.
5. El watcher de `searchTerm` ejecuta `search()`.
6. Si el termino tiene 1 caracter o menos, limpia `searchedCustomers` y no consulta backend.
7. Si tiene mas de 1 caracter, el frontend hace GET a `admin.customers.customers.search` con `query`.
8. `CustomerController::search()` busca por `email` o por `CONCAT(first_name, " ", last_name)`, ordena por `created_at desc` y pagina 10 registros.
9. El backend retorna JSON paginado de Laravel.
10. El frontend toma `response.data.data` y pinta cada cliente con nombre completo y email.
11. El usuario hace clic en un cliente.
12. El frontend ejecuta POST a `admin.sales.cart.store` con `{ customer_id: customer.id }`.
13. `Sales\CartController::store()` resuelve el cliente con `CustomerRepository::findOrFail()`. Si el ID no existe, falla.
14. Si existe, crea un carrito admin inactivo con `Cart::createCart(['customer' => $customer, 'is_active' => false])`.
15. El backend retorna `redirect_url` a `admin.sales.orders.create`.
16. El navegador redirige a `sales/orders/create/{cartId}`.
17. `OrderController::create()` carga el carrito y la vista de creacion usa `CartResource`, donde queda disponible `cart.customer_id`.

Nota importante: en este flujo no hay un `input hidden` en el drawer de cliente, porque la seleccion no se guarda como formulario pendiente. La seleccion dispara inmediatamente un POST con el ID real (`customer_id`) y crea el carrito. Para selects que si permanecen dentro de un formulario, el patron correcto es guardar el ID real en hidden/input y usar el texto solo como label.

## Flujo hermano: agregar producto al pedido

1. En `sales/orders/create/cart/items.blade.php`, el usuario abre el drawer "Agregar producto".
2. Escribe en el input con `v-debounce="500"`.
3. El frontend consulta `admin.catalog.products.search` con `query` y `customer_id`.
4. `ProductController::search()` busca productos por nombre, toma el `channel_id` del cliente y retorna `ProductResource::collection($products)`.
5. La vista muestra nombre, SKU, precio y cantidad disponible.
6. Al agregar, el formulario incluye un hidden real `product_id`.
7. El componente encuentra el producto seleccionado en `searchedProducts` por `params.product_id`.
8. El componente padre envia POST a `admin.sales.cart.items.store` con `product_id` y `quantity`.
9. `Sales\CartController::storeItem()` valida `product_id` con `required|integer|exists:products,id` y luego resuelve el producto con `ProductRepository::findOrFail()`.

Este flujo es el ejemplo mas cercano a un autocomplete que muestra texto pero persiste un ID dentro de un formulario.

## Estructura JSON encontrada

### Clientes

Endpoint:

```text
GET admin/customers/search?query=andres
route name: admin.customers.customers.search
```

El backend retorna la paginacion estandar de Laravel. La vista usa solamente `response.data.data`.

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "first_name": "Andres",
      "last_name": "Lopez",
      "gender": "Male",
      "date_of_birth": null,
      "email": "andres@example.com",
      "phone": "3000000000",
      "customer_group_id": 2,
      "channel_id": 1,
      "status": 1,
      "is_verified": 1,
      "is_suspended": 0,
      "created_at": "2026-06-14T00:00:00.000000Z",
      "updated_at": "2026-06-14T00:00:00.000000Z",
      "image_url": null
    }
  ],
  "per_page": 10,
  "total": 1
}
```

El label no viene calculado como `label`; se arma en frontend:

```js
customer.first_name + ' ' + customer.last_name
customer.email
```

### Productos

Endpoint:

```text
GET admin/catalog/products/search?query=camisa&customer_id=1
route name: admin.catalog.products.search
```

Formato de cada item segun `ProductResource`:

```json
{
  "id": 10,
  "type": "simple",
  "sku": "CAMISA-001",
  "name": "Camisa basica",
  "price": "25000.0000",
  "formatted_price": "$25.000,00",
  "images": [],
  "inventories": [],
  "is_options_required": false,
  "is_saleable": true
}
```

### Referencia / Color de proveedor en Compra de Tela

Endpoint existente:

```text
GET admin/corte-confeccion/compras-tela/referencias?proveedor_tela_id=1&tipo_tela_id=2&search=blanco
route name: admin.corte_confeccion.compras_tela.referencias
```

Formato real retornado por `CompraTelaController::referencias()`:

```json
[
  {
    "id": 1,
    "label": "blanco - 888 - 175gr - $15.000/kg",
    "color": "blanco",
    "referencia": "888",
    "gramaje": "175",
    "valor_kilo_referencia": "15000.00"
  }
]
```

## Patron de rutas

Los endpoints de autocomplete/busqueda usan `GET search` o un sustantivo especifico bajo el recurso:

```text
admin.customers.customers.search
admin.catalog.products.search
admin.sales.orders.search
admin.corte_confeccion.compras_tela.referencias
```

Para nuevas busquedas del modulo, seguir este estilo:

```text
admin.corte_confeccion.compras_tela.referencias
admin.corte_confeccion.ordenes_corte.rollos.search
admin.corte_confeccion.proveedores_tela.search
```

Usar `query` cuando se replica el patron Bagisto (`customers`, `products`) y `search` cuando el endpoint del modulo ya lo usa (`compras_tela.referencias`). No mezclar nombres dentro del mismo endpoint.

## Patron de validacion

Regla base: el label nunca es la relacion principal. El backend debe validar y persistir el ID.

En cliente:

```php
$customer = $this->customerRepository->findOrFail(request()->input('customer_id'));
```

En producto agregado a pedido:

```php
$this->validate(request(), [
    'product_id' => 'required|integer|exists:products,id',
]);
```

En Compra de Tela:

```php
'proveedor_id' => ['required', 'exists:bylopez_cc_proveedores_tela,id'],
'rollos.*.referencia_tela_id' => ['required', 'integer', 'exists:bylopez_cc_proveedor_tela_referencias,id'],
```

Ademas, `StoreCompraTelaRequest` y `UpdateCompraTelaRequest` hacen validacion cruzada:

- El tipo de tela debe pertenecer al proveedor seleccionado.
- La referencia debe pertenecer al proveedor seleccionado.
- La referencia debe pertenecer al tipo de tela seleccionado.
- El color escrito debe coincidir con el color de la referencia.

Esto evita que el usuario escriba un texto visual valido pero envie un ID inexistente, de otro proveedor o de otro tipo de tela.

## Aplicacion al modulo Compra de Tela

### Referencia / Color de proveedor

Este autocomplete ya existe en `compras-tela/form.blade.php` y debe ser el punto de partida.

Entrada requerida:

```text
proveedor_tela_id
tipo_tela_id
search
```

Salida esperada:

```json
[
  {
    "id": 1,
    "label": "blanco - 888 - 175gr - $15.000/kg",
    "color": "blanco",
    "referencia": "888",
    "gramaje": 175,
    "valor_kilo_referencia": 15000
  }
]
```

Comportamiento recomendado:

- Mostrar `label` en el input visible.
- Guardar `id` en `rollos[index][referencia_tela_id]`.
- Copiar datos snapshot al formulario: `color`, `referencia`, `gramaje`, `valor_kilo`.
- Al editar, hidratar `referencia_label` desde la relacion `referenciaTela`; si no hay relacion, mostrar dato historico.
- Si cambia proveedor o tipo de tela, limpiar `referencia_tela_id`, `color`, `referencia`, `gramaje` y `valor_kilo`.
- Mantener fallback local solo como ayuda visual; la autoridad final es el backend.

### Rollos disponibles para corte

Hoy `ordenes-corte/create.blade.php` carga todos los rollos disponibles desde el controller y usa un `<select>` local. Para replicar el patron de autocomplete, crear un endpoint que filtre en backend y retorne solo rollos disponibles.

Parametros recomendados:

```text
search
tipo_tela_id opcional
proveedor_tela_id opcional
color opcional
referencia opcional
exclude_ids[] opcional
```

JSON recomendado:

```json
[
  {
    "id": 25,
    "label": "CT-20260614-R001 - Lycra - blanco - 888 - 12.500 kg",
    "codigo": "CT-20260614-R001",
    "proveedor_tela_id": 1,
    "tipo_tela_id": 2,
    "tipo_tela": "Lycra",
    "color": "blanco",
    "referencia": "888",
    "gramaje": "175",
    "peso_disponible": "12.500"
  }
]
```

Validacion obligatoria al guardar orden:

- `rollo_ids.*` con `exists:bylopez_cc_rollos,id`.
- Verificar estado disponible/parcialmente usado.
- Verificar que no este reservado en una orden abierta.
- Verificar compatibilidad entre rollos seleccionados: mismo `tipo_tela_id`, mismo `color`, mismo `gramaje`.

`OrdenCorteService` ya hace estas validaciones con `validarRollosDisponibles()` y `validarCompatibilidadRollos()`. Si se agrega autocomplete, no reemplazar esas validaciones.

### Proveedor de tela

Proveedor en Compra de Tela es actualmente un `<select>` server-rendered. Si crece el catalogo, se puede replicar el patron de cliente:

- Endpoint `admin.corte_confeccion.proveedores_tela.search`.
- Buscar por `nombre`, `nit` y campos comerciales que existan.
- Retornar `{ id, label, nombre, nit }`.
- Guardar `proveedor_id`, no el nombre.
- Al seleccionar proveedor, limpiar referencias/rollos dependientes.

### Orden de corte y productos

`ordenes-corte/create.blade.php` carga productos con `ProductLookupService::options()` y usa un `<select>`. Si se vuelve autocomplete:

- Reutilizar el patron de `admin.catalog.products.search` cuando aplique.
- Guardar `detalles[index][product_id]`.
- Mantener snapshot historico con `ProductLookupService::snapshot()` al crear detalle.
- No guardar SKU/nombre como relacion principal; SKU/nombre son snapshot historico.

## Buenas practicas obligatorias

- No guardar labels como relacion principal.
- Guardar siempre IDs reales: `customer_id`, `product_id`, `proveedor_id`, `referencia_tela_id`, `rollo_ids`.
- Usar input hidden si el componente visible solo muestra texto.
- Validar existencia del ID en backend con `exists` o `findOrFail`.
- Validar pertenencia contextual: proveedor, tipo de tela, estado y disponibilidad.
- No traer datos de otros proveedores.
- No quemar IDs en JS ni en Blade.
- Usar `DB::transaction()` al guardar documentos con detalle.
- Mantener snapshot historico cuando aplique: proveedor_nombre, color, referencia, tipo_tela, gramaje, producto_sku, producto_nombre.
- Hidratar opciones preseleccionadas al editar.
- Limpiar campos dependientes cuando cambia el padre: proveedor, tipo de tela, rollo o producto.
- Limitar resultados (`paginate(10)`, `limit(20)`) para no cargar catalogos completos.
- No confiar en validaciones del frontend; el frontend solo mejora la experiencia.

## Errores que ya ocurrieron o se deben evitar

- El select muestra texto pero no guarda ID.
- Al editar no se hidrata la opcion seleccionada.
- El autocomplete carga datos visuales pero no persiste.
- Se guardan referencias duplicadas por escribir colores manualmente.
- Se rompe edicion porque no se inicializan opciones preseleccionadas.
- Se permite seleccionar una referencia de otro proveedor.
- Se permite conservar una referencia despues de cambiar el tipo de tela.
- Se mezclan rollos incompatibles en una orden de corte.
- Se manda `label` al backend y se intenta resolver despues por texto.
- Se pierde el snapshot historico cuando cambia el catalogo maestro.

## Resultado esperado para futuras implementaciones

Un autocomplete/select nuevo debe tener estas piezas:

1. Ruta `GET` nombrada de forma consistente.
2. Controller que valida parametros de busqueda y contexto.
3. Query filtrada por contexto real, no solo por texto.
4. JSON con `id`, `label` y campos auxiliares necesarios.
5. Frontend con input visual y estado de busqueda.
6. Hidden/input real con el ID seleccionado.
7. Hidratacion de la opcion seleccionada al editar.
8. Request backend con `exists` y validaciones cruzadas.
9. Service con `DB::transaction()` para encabezado + detalle.
10. Snapshot historico cuando el documento no debe cambiar si cambia el catalogo.
