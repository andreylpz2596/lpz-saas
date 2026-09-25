# 05_DISENO_TECNICO_CREACION_PEDIDOS_ASESOR.md

Diseño técnico del motor y la interfaz de creación de pedidos del asesor.

**Fecha:** 2026-09-09 (Fase 00B) · **Actualizado:** 2026-09-20 (Fase 02B0)
**Estado:** DISEÑO. **No implementado.** Ninguna clase descrita aquí existe
todavía en el repositorio.

> **Fase 01B.** Se actualizan §15 (clientes), §17 (servicio común y POS), §20
> (decisiones) y se añade §21 (precio por grupo, RT-03). Se recomienda que
> `CrearPedidoAsesorService` se llame `CrearPedidoComercialService` al implementarse;
> en este documento se conserva el nombre original salvo en §17 y §21. Evidencia en
> `06B_AUDITORIA_CLIENTES_PRECIOS_Y_PROPIEDAD_COMERCIAL.md` (`[B-§n]`).

> **Fase 02B0.** §9 se actualiza con la frontera transaccional ya medida y con la regla
> de que el servicio de esta fase **rellena el contexto** y no escribe la atribución: el
> listener es el único escritor. Evidencia en
> `08_FASE_02B0_AUDITORIA_CONEXION_PEDIDOS.md` (`[D-§n]`).

Referencias `[A-§n]` → `01_AUDITORIA_CORE_VENTAS_BAGISTO.md`;
`[M-§n]` → `02_MODELO_FUNCIONAL_VENTAS_ASESOR.md`;
`D-nn` → `04_DECISIONES_PENDIENTES.md`.

> **Nota de método.** Este documento no contiene pseudocódigo ejecutable. Las
> firmas y nombres que aparecen son **contratos propuestos**, no código
> aprobado. Todas las clases del core citadas fueron verificadas leyendo el
> repositorio; se indica archivo y línea.

---

## 1. Objetivo

Permitir que un asesor registre una venta llegada por WhatsApp desde una
interfaz propia, adaptada a ese flujo, **sin** construir un segundo motor de
pedidos y **sin** modificar `packages/Webkul/`.

El resultado de la operación debe ser un pedido de Bagisto **indistinguible** de
uno creado por el panel oficial, más una fila de atribución del módulo.

---

## 2. Límites con Bagisto

| Responsabilidad | Dueño |
|---|---|
| Carrito, ítems, variantes | **Bagisto** (`Webkul\Checkout\Cart`) |
| Precios, descuentos, cupones, impuestos | **Bagisto** (`Cart::collectTotals()`) |
| Inventario y reserva | **Bagisto** (`OrderRepository::create()` → `product_ordered_inventories`) |
| Cliente y direcciones | **Bagisto** (`CustomerRepository`, `Cart::saveAddresses()`) |
| Métodos de envío y pago | **Bagisto** (`Cart::saveShippingMethod()`, `Cart::savePaymentMethod()`) |
| Transformación a pedido | **Bagisto** (`OrderResource`) |
| Persistencia del pedido | **Bagisto** (`OrderRepository::create()`) |
| Estados oficiales | **Bagisto** |
| **Orquestación del flujo** | **ByLopez** (`CrearPedidoAsesorService`) |
| **Interfaz del asesor** | **ByLopez** |
| **Atribución comercial** | **ByLopez** |
| **Idempotencia por referencia externa** | **ByLopez** |

Prohibiciones (repetidas aquí porque son el punto crítico del diseño):
no INSERT directo en `orders`, `order_items`, `addresses`, `order_payment`,
`order_shipping_rates`; no copiar controladores ni repositorios del core;
no llamadas HTTP internas a rutas de Bagisto [M-§1.1].

---

## 3. Flujo técnico completo

```
[1]  Validar contexto y permisos del asesor
[2]  Resolver o crear el cliente                      -> CustomerRepository
[3]  Comprobar idempotencia (external_reference)      -> tabla propia
[4]  Crear el carrito                                 -> Cart::createCart()
[5]  Añadir cada producto/variante                    -> Cart::addProduct()
[6]  Guardar direcciones                              -> Cart::saveAddresses()
[7]  Seleccionar método de envío                      -> Cart::saveShippingMethod()
[8]  Seleccionar método de pago                       -> Cart::savePaymentMethod()
[9]  Aplicar cupón (opcional)                         -> Cart::setCouponCode()
[10] Calcular totales oficiales                       -> Cart::collectTotals()
[11] Revalidar ítems y stock                          -> Cart::validateItems(),
                                                         Cart::isItemsHaveSufficientQuantity()
[12] Validación final del pedido                      -> comprobaciones de validateOrder()
[13] Transformar                                      -> new OrderResource($cart)
[14] Persistir                                        -> OrderRepository::create()
     (aquí Bagisto reserva inventario y dispara
      checkout.order.save.after)
[15] Registrar atribución                             -> módulo
[16] Cerrar el carrito                                -> Cart::removeCart()
[17] Devolver el pedido y la URL del detalle propio
```

---

## 4. Clases oficiales reutilizables (verificadas)

### 4.1 Carrito — `Webkul\Checkout\Cart`

Facade: `Webkul\Checkout\Facades\Cart`. Archivo:
`packages/Webkul/Checkout/src/Cart.php`.

| Paso | Método | Línea |
|---|---|---|
| 4 | `createCart(array $data)` — admite `customer` e `is_active` | 130 |
| — | `setCart(Contracts\Cart $cart)` / `getCart()` | 105 / 122 |
| 5 | `addProduct(ProductContract $product, array $data)` | 263 |
| 5 | `updateItems(array $data)` / `removeItem(int $itemId)` | 348 / 324 |
| 6 | `saveAddresses(array $params)` | 429 |
| 7 | `saveShippingMethod(string $code)` | 566 |
| 8 | `savePaymentMethod(array $params)` | 602 |
| 9 | `setCouponCode(?string $code)` | 625 |
| 10 | `collectTotals()` | 856 |
| 11 | `validateItems()` | 955 |
| 11 | `isItemsHaveSufficientQuantity()` | 830 |
| 12 | `haveMinimumOrderAmount()` | 818 |
| — | `hasError()` / `getErrors()` | 756 / 764 |
| 16 | `removeCart(Contracts\Cart $cart)` | 163 |

### 4.2 Cliente, producto, pedido

| Clase | Método | Uso |
|---|---|---|
| `Webkul\Customer\Repositories\CustomerRepository` | `findOneWhere()`, `create()` | Paso 2 |
| `Webkul\Product\Repositories\ProductRepository` | `findOrFail()` | Paso 5 |
| `Webkul\Sales\Transformers\OrderResource` | `jsonSerialize()` | Paso 13 |
| `Webkul\Sales\Repositories\OrderRepository` | `create(array $data)` | Paso 14 |
| `Webkul\Sales\Repositories\OrderRepository` | `updateOrderStatus($order)` | Recalculo oficial |

### 4.3 Validación previa — vacío real del core

Existen **dos implementaciones equivalentes y duplicadas** de `validateOrder()`,
ambas públicas y ambas **dentro de controladores**, no en un servicio [A-§19.2]:

- `Webkul\Admin\Http\Controllers\Sales\OrderController::validateOrder()` (:252)
- `Webkul\Shop\Http\Controllers\API\OnepageController::validateOrder()` (:207)

Comprobaciones de la versión administrativa (la aplicable aquí):

1. `Cart::haveMinimumOrderAmount()`
2. si `haveStockableItems()` → existe `shipping_address`
3. existe `billing_address`
4. si `haveStockableItems()` → existe `selected_shipping_rate`
5. existe `payment`

**No existe una clase reutilizable para esto.** El módulo debe reimplementar
exactamente estas cinco comprobaciones dentro de su servicio. Es la única
duplicación aceptada, y se documenta como deuda conocida: si Bagisto añade una
validación en una versión futura, el módulo debe replicarla.

> **Alternativa descartada:** instanciar el `OrderController` de Admin para
> llamar a su `validateOrder()` público. Funcionaría, pero ataría el módulo a la
> firma de un controlador y arrastraría sus dependencias de constructor.

### 4.4 Restricción real del flujo administrativo

`Webkul\Admin\Http\Controllers\Sales\OrderController::store()` bloquea todo
método de pago que no sea `cashondelivery` o `moneytransfer` [A-§3.2]. **Esa
restricción vive en el controlador, no en el motor**, de modo que el servicio
propio deberá decidir si la replica. Para venta por WhatsApp contraentrega es
justamente el caso de uso principal, pero la decisión es de negocio (D-21).

---

## 5. Clases que debe aportar ByLopez

| Clase propuesta | Responsabilidad |
|---|---|
| `CrearPedidoAsesorService` | Orquestador del flujo de §3 |
| `ContextoCreacionPedido` (DTO) | Asesor, creador, canal, mecanismo, referencia externa |
| `SolicitudPedidoAsesor` (DTO) | Cliente, líneas, direcciones, envío, pago, cupón |
| `ResultadoPedidoAsesor` (DTO) | Pedido creado, atribución, advertencias |
| `ResolverClienteService` | Buscar o crear cliente sin duplicar (D-17) |
| `ValidacionPedidoAsesor` | Las cinco comprobaciones de §4.3 más las propias |
| `IdempotenciaPedidoService` | Bloqueo por `external_reference` |
| `AtribucionPedidoService` | Registro de la atribución [M-§5] |
| `PedidoAsesorController` | Punto de entrada HTTP de la interfaz propia |
| Form Requests | Validación de entrada por paso |

Ninguna de ellas duplica lógica del core: todas delegan.

---

## 6. Datos de entrada

| Grupo | Campos |
|---|---|
| **Contexto** | `advisor_id`, `created_by_admin_id`, `sales_channel`, `creation_source`, `external_reference` |
| **Cliente** | `customer_id` **o** datos para crear (nombre, apellido, teléfono, correo si existe) |
| **Líneas** | por línea: `product_id` (variante o configurable), `quantity`, `super_attribute` cuando aplique |
| **Direcciones** | facturación y envío (o marca "misma dirección") |
| **Envío** | `shipping_method` (código de una tarifa ofrecida por Bagisto) |
| **Pago** | `payment.method` |
| **Opcional** | `coupon_code`, nota interna |

**El precio no es dato de entrada.** Lo calcula Bagisto (D-18 decidirá si se
permiten excepciones y con qué permiso).

---

## 7. Datos de salida

| Campo | Notas |
|---|---|
| `order_id`, `increment_id` | Del pedido de Bagisto |
| `attribution_id` | Fila de atribución del módulo |
| `redirect_url` | Detalle **propio** del módulo, no el de Bagisto |
| `warnings[]` | Ej. cliente creado nuevo, cupón no aplicable |
| `totals` | Solo lectura, tal como los calculó Bagisto |

---

## 8. Manejo de errores

| Categoría | Ejemplo | Respuesta | Efecto |
|---|---|---|---|
| Autorización | El usuario no tiene `ventas_asesor.pedidos.create` | 401/403 | Nada |
| Validación de entrada | Falta dirección | 422 con errores por campo | Nada |
| Estado del carrito | `Cart::hasError()` | 422 con `Cart::getErrors()` | Carrito conservado |
| Stock insuficiente | `isItemsHaveSufficientQuantity()` falso | 422 identificando la línea | Nada |
| Validación de pedido | Falta método de pago | 422 | Nada |
| Idempotencia | `external_reference` repetido | 409 con el pedido existente | **No crea nada** |
| Fallo al persistir | Excepción en `OrderRepository::create()` | 500 | Rollback completo |
| Fallo de atribución | Excepción tras crear el pedido | 201 con advertencia | **El pedido se conserva** (§9) |

**Prohibido:** reintento automático, `catch` que vuelva a ejecutar la creación,
`setTimeout` de reenvío, botón principal presentado directamente como
"Reintentar", y determinar la causa de un 422 por comparación de textos. Los
errores se identifican por **código**, nunca por mensaje humano.

---

## 9. Transacción y consistencia

- Los pasos 4 a 13 ocurren sobre el carrito; un fallo ahí deja el carrito
  inactivo y **ningún pedido**.
- El paso 14 (`OrderRepository::create()`) abre su propia transacción y dispara
  `checkout.order.save.after` **dentro** de ella. **Verificado por medición en la Fase
  02B0** [D-§2]: dentro del listener `DB::transactionLevel()` vale 1 y el pedido todavía
  no es visible desde otra conexión; el `commit` ocurre después, en el `finally` del
  repositorio.
- El paso 15 (atribución) lo ejecuta el **listener**, que es el **único escritor**. El
  servicio de esta fase **no** registra la atribución por su cuenta: **rellena el
  contexto comercial** acotado a la petición, indexado por `cart_id`, y deja que el
  listener escriba [D-§6]. Así no hay dos caminos de escritura ni doble atribución.
- Garantías: idempotencia por `RegistrarAtribucionService`, restricción única por
  `order_id` y `try/catch` de `Throwable` que nunca relanza [D-§3].
- **Quien establece el contexto es quien lo limpia** [D-§16.3]: `try/finally` alrededor de
  **toda** la llamada a `OrderRepository::create()`, porque el repositorio reintenta y el
  listener puede recibir varios pedidos temporales para el mismo `cart_id`. El listener
  **lee** el contexto y no lo consume.
- **Límite honesto de `catch (Throwable)`** [D-§16.5]: el módulo no propaga sus errores
  recuperables ni sus conflictos, pero una desconexión o una transacción ya invalidada no
  se neutraliza capturando. Cuando el fallo es de base, no se intenta escribir
  `fallo_tecnico` ni la incidencia por esa misma conexión.

**Regla de consistencia (decisión de diseño explícita):** si la atribución falla, **la
venta se conserva**. Un pedido real no se pierde por un fallo del módulo. El fallo se
registra en log técnico seguro y el pedido queda con `attribution_status = fallo_tecnico`
**cuando la base lo permita**; si el fallo es de base de datos, quedará como huérfano
detectable [D-§8]. Esto no es "silenciar el error": es priorizar la venta y **declarar**
el fallo.

La **bandeja visual** de incidencias no pertenece a esta cadena: es de la **Fase 05**
[D-§11]. La 02B entrega detección y reconciliación por comando, sin interfaz.

> **Implementado en la Fase 02B1A (2026-09-20).** La detección ya existe y es durable:
> `bylopez_va_attribution_incidents`, con `UNIQUE (order_id, codigo)`, contador de
> repeticiones incrementado atómicamente y reapertura automática si una incidencia resuelta
> vuelve a ocurrir. Guarda **solo nombres de campo**, nunca valores, payloads ni mensajes de
> excepción. La **reconciliación por comando** sigue pendiente: es la Fase 02B1B.
>
> Se comprobó con pruebas lo que esta sección afirmaba: el listener corre síncrono dentro de la
> transacción del pedido, un rollback se lleva también la atribución y la incidencia, y el
> siguiente intento reutiliza el contexto del mismo `cart_id`. `OrderRepository` no se tocó.

---

## 10. Idempotencia

| Escenario | Mecanismo |
|---|---|
| Doble clic del asesor | Token de formulario de un solo uso + bloqueo del botón |
| Reintento de n8n | `external_reference` con índice **UNIQUE**; el segundo intento devuelve 409 y el pedido ya creado |
| Reejecución del listener | `updateOrCreate` sobre `order_id` (UNIQUE) |
| Reproceso de una importación | `external_reference` = id de fila del archivo |

`external_reference` es el mismo campo para las cuatro cosas [M-§6.3]: id de
conversación, ejecución, fila o token. Debe registrarse **antes** de crear el
pedido, no después, para que el segundo intento encuentre la marca.

---

## 11. Contexto de atribución

El servicio recibe el contexto de forma **explícita** y lo conoce antes de
llamar a `OrderRepository::create()` (mecanismo M-3 de [M-§10.2]). No depende de
`auth()->guard('admin')` dentro del listener, que es precisamente lo que falla
en el storefront y en colas.

El contexto se transporta al listener por una de estas vías, a decidir en la
Fase 02:

1. Variable de contexto de la petición (más simple; suficiente para HTTP).
2. Registro previo asociado a `cart_id` (necesario solo si se adoptan
   carritos-borrador de larga duración, D-16).

**No se aprueba todavía ninguna tabla de contexto durable** [M-§10.5].

> **Fase 02A.** El contexto ya existe como objeto de valor inmutable,
> `ContextoComercial`, con sus invariantes validadas, y `RegistrarAtribucionService` lo
> persiste de forma idempotente. Falta decidir en la Fase 02B **cómo viaja** ese contexto
> hasta el listener; la tabla de contexto durable sigue sin aprobarse [C-§4].

> **Resuelto en la Fase 02B1A (2026-09-20).** Viaja por la **vía 1**:
> `ContextoComercialDePeticion`, registrado **`scoped`** en el contenedor (nunca `singleton`:
> con Octane un singleton filtraría el contexto de una petición a la siguiente), indexado por
> **`cart_id`** y no por `order_id`, porque cada reintento de `OrderRepository` crea un pedido
> con id distinto. La vía 2 —tabla durable ligada a `cart_id`— **sigue sin aprobarse**.
>
> El contrato de uso es `durante(int $cartId, ContextoComercial $contexto, Closure $operacion)`:
> registra, ejecuta con todos los reintentos internos, y retira en `finally`, también si la
> operación lanza. **El listener solo consulta; nunca consume ni limpia.** Quien todavía no
> existe es el productor del contexto: `CrearPedidoComercialService` es de la Fase 03, así que
> hoy todos los pedidos reales se clasifican por ruta exacta.

---

## 12. Seguridad

- Permiso requerido: `ventas_asesor.pedidos.create`.
- El asesor solo puede crear pedidos **a su propio nombre**, salvo que tenga
  `ventas_asesor.pedidos.assign`.
- El `advisor_id` del contexto **nunca** se acepta desde el cliente sin
  verificar: si el usuario no puede asignar a terceros, se fuerza al usuario
  autenticado.
- La redirección posterior lleva al detalle **propio**, sujeto al aislamiento
  horizontal [M-§12.4].
- Toda validación se repite en el servidor aunque exista en la interfaz.
- Ningún endpoint del módulo devuelve datos de pedidos de otro asesor, ni
  siquiera para "validar" un `order_id`.

---

## 13. Inventario

- El módulo **nunca** toca inventario.
- `OrderRepository::create()` reserva en `product_ordered_inventories`; el
  descuento físico de `product_inventories` ocurre **al crear el envío**
  [A-§6].
- La validación de stock previa se hace con
  `Cart::isItemsHaveSufficientQuantity()` (paso 11), **inmediatamente antes** de
  transformar, no al principio del flujo: entre que el asesor arma el pedido y
  lo confirma pueden pasar minutos.
- Aun así existe una ventana de carrera. Si D-19 decide impedir la sobreventa,
  el fallo del paso 11 debe ser un error duro que no crea el pedido.

---

## 14. Productos configurables

Es el punto donde una implementación ingenua se rompe. Hechos verificados
[A-§16]:

- Al añadir un configurable, Bagisto crea **dos filas** en `order_items`: padre
  (`parent_id NULL`, cantidad y dinero reales) e hija (`parent_id` = padre,
  `qty_ordered = 0`, `price = 1`).
- `Cart::addProduct()` recibe el **producto configurable** más
  `super_attribute` (mapa `attribute_id => option_id`) y
  `selected_configurable_option` (id de la variante).
- El `additional` resultante conserva `selected_configurable_option` y
  `attributes` con etiquetas legibles (`{Talla: M, Color: Blanco}`).

**Implicaciones para el diseño:**

1. La interfaz debe enviar el producto **padre** más los atributos
   seleccionados, no la variante suelta.
2. La identidad de la variante para mostrar y para comisionar sale de
   `additional.selected_configurable_option` y de `sku`, **nunca** de
   `shipment_items.product_id` (que es el configurable).
3. Cualquier conteo de prendas aplica `parent_id IS NULL` [A-§16.5].
4. `bundle`, `grouped`, `virtual` y `downloadable` **no están verificados** con
   datos reales y quedan fuera del alcance inicial.

---

## 15. Clientes y prevención de duplicados

El core busca clientes por correo. En venta por WhatsApp muchos clientes **no
tienen correo**, y ese es el origen clásico de duplicados.

Diseño propuesto, sujeto a D-17:

1. Buscar por **teléfono normalizado** (E.164) como criterio primario.
2. Buscar por correo si existe.
3. Buscar por documento si el negocio lo captura.
4. Mostrar coincidencias al asesor **antes** de crear, con los últimos pedidos
   de cada candidato.
5. Crear solo si el asesor confirma que ninguno coincide.

**Prohibido inventar correos falsos automáticamente** (`whatsapp+573001234567@…`):
contamina la base, rompe las notificaciones y hace imposible la deduplicación
posterior. Si Bagisto exige correo, la decisión D-17 debe elegir explícitamente
entre capturarlo siempre, permitir cliente invitado, o usar un patrón
documentado y reversible.

> **Corrección Fase 01B (verificada) [B-§2, B-§3].**
>
> - **Bagisto no exige correo en la base:** `customers.email` es nullable y el índice
>   único es `(email, channel_id)`, que admite varios NULL. Lo exigen las validaciones
>   del panel: alta, edición y dirección del carrito. La frase «el core busca clientes
>   por correo» es exacta para el panel: su búsqueda filtra por correo y nombre, **no
>   por teléfono**.
> - La opción «patrón documentado y reversible» queda **descartada**: sería un correo
>   sintético.
> - Estrategia recomendada (D-28): cliente Bagisto con correo nulo creado por el
>   servicio, más ficha de contacto propia con teléfono original y normalizado. Está
>   sujeta a **RT-04**: el pedido no puede llegar con `customer_email` nulo a las
>   notificaciones del core (se demostró `TypeError`) y el cliente sin correo no puede
>   disparar `syncNewRegisteredCustomerInformation()` (se demostró reasignación de
>   pedidos ajenos).
> - `customers.phone` tiene **índice único** en el core, así que el teléfono normalizado
>   y los teléfonos compartidos deben vivir en la tabla propia, no en `customers.phone`.
> - En el flujo del panel, el pedido toma `customer_email` del correo de la dirección
>   de facturación cuando el cliente no tiene; la dirección exige correo
>   (`Admin\Http\Requests\CartAddressRequest`).

---

## 16. Métodos de pago y envío

- Los métodos disponibles los ofrece Bagisto; el módulo **no** define métodos
  propios.
- El flujo administrativo del core restringe a `cashondelivery` y
  `moneytransfer` [A-§3.2]; el servicio propio decide si replica esa restricción
  (§4.4).
- Las tarifas de envío se obtienen del carrito tras `collectTotals()`; el asesor
  elige entre las ofrecidas, no escribe un valor libre, salvo que D-21 autorice
  tarifa manual.
- El cobro contraentrega **no** implica dinero recibido: es una advertencia que
  el dashboard debe reflejar [M-§14.3].

---

## 17. Compatibilidad futura con n8n y WhatsApp

**Requisito de diseño:** la API futura debe invocar **exactamente el mismo**
`CrearPedidoAsesorService` que usa la interfaz del asesor. Ninguna lógica de
creación puede vivir en el controlador.

```
Interfaz del asesor  ─┐
POS (futuro)         ─┤
API n8n / WhatsApp   ─┼──> CrearPedidoComercialService ──> Bagisto
Importación Excel    ─┘
```

> **Fase 01B — nombre neutral.** Como el servicio también atenderá el POS, el nombre
> `CrearPedidoAsesorService` describe mal su alcance. **Recomendación:** llamarlo
> `CrearPedidoComercialService` desde su creación en la Fase 03. Hoy no hay código que
> renombrar.
>
> Cada adaptador aporta explícitamente: cliente, carrito oficial, `sales_channel`,
> `creation_source`, usuario creador, propiedad comercial y asesor cuando corresponda,
> productos y variantes, dirección, pago, envío o entrega, referencia externa e
> idempotencia. POS usa `sales_channel = tienda_fisica` y `creation_source = pos`.

Diferencias por canal, y solo estas: autenticación (sesión frente a token),
`creation_source`, `external_reference` y cómo se resuelve el `advisor_id`.

La API debe exigir `external_reference` **obligatorio** para que el reintento de
n8n no genere un pedido duplicado (§10, CL-21).

---

## 18. Casos límite

| # | Caso | Tratamiento |
|---|---|---|
| 1 | Doble envío del formulario | Token de un solo uso; el segundo devuelve el pedido ya creado |
| 2 | Stock agotado entre armado y confirmación | Fallo duro en el paso 11 (sujeto a D-19) |
| 3 | Cliente sin correo | §15 / D-17 |
| 4 | Cliente duplicado detectado | Se muestran candidatos antes de crear |
| 5 | Variante inexistente para la combinación color/talla | Error de validación, no pedido |
| 6 | Cupón caducado | Advertencia, el pedido continúa sin cupón |
| 7 | Monto mínimo no alcanzado | 422, el core lo rechaza |
| 8 | Método de pago no permitido | 422 |
| 9 | Fallo tras crear el pedido | El pedido se conserva; `attribution_status = fallo_tecnico` (§9) |
| 10 | Asesor sin permiso intenta asignar a otro | Se fuerza a sí mismo o se rechaza (§12) |
| 11 | El asesor cambia de idea tras confirmar | **No se edita `order_items`**: se cancela y se recrea (D-20) |
| 12 | Producto gratuito o de garantía | Se crea con precio cero; se marca no comisionable (D-03) |
| 13 | Pedido de prueba | Marca de exclusión [M-§5.1]. **No existe todavía**: la Fase 02A1 retiró `is_excluded` de la atribución por ser lógica anticipada de comisiones |
| 14 | Carrito abandonado a medias | Queda inactivo; no genera pedido ni reserva inventario |
| 15 | Dos asesores atienden al mismo cliente a la vez | Cada uno con su carrito; el pedido pertenece a quien lo confirma |

---

## 19. Estrategia de pruebas

**Las pruebas se construyen en la fase que introduce cada capa**, no al final.

| Tipo | Qué verifica |
|---|---|
| Unitarias | DTOs, resolución de cliente, idempotencia, validación |
| Feature | El servicio crea un pedido de Bagisto válido con totales correctos |
| Feature | Un configurable produce padre + hija, y el conteo con `parent_id IS NULL` da la cantidad real |
| Feature | El pedido queda atribuido con creador, asesor, canal y mecanismo correctos |
| Feature | `external_reference` repetido no crea un segundo pedido |
| Feature | Stock insuficiente no crea pedido ni reserva inventario |
| Seguridad | Un asesor no ve ni crea sobre pedidos de otro (HTTP real, no UI) |
| Seguridad | Sin `ventas_asesor.pedidos.create` → 401 |
| Regresión | El checkout del storefront sigue funcionando con el módulo activo |
| Regresión | Los totales de un pedido creado por el servicio son idénticos a los del flujo oficial con los mismos datos |
| Regresión | La suite de Corte y Confección sigue en verde |

Nota de entorno: en pruebas con `permission_type = 'all'` el ACL se puentea por
completo [A-§9]; las pruebas negativas deben usar roles `custom` explícitos.

---

## 20. Decisiones todavía pendientes que afectan a este diseño

| Decisión | Qué bloquea aquí |
|---|---|
| ~~D-16~~ | **Decidida (01B):** carrito o borrador durante la negociación; pedido solo al confirmar. Queda por evaluar en la Fase 02 si §11 necesita contexto durable |
| **D-17** | Identidad del cliente → determina §15 por completo |
| **D-28** | Clientes sin correo → §15 |
| **D-29** | Teléfono compartido y cambio de titular → §15 |
| **RT-03** | Precio por grupo del cliente del carrito → §21 (requisito técnico) |
| **RT-04** | Clientes sin correo compatibles con notificaciones y sincronización → §15 (requisito técnico) |
| **D-18** | Precios y descuentos manuales → determina §6 y si hace falta permiso y auditoría de excepción |
| **D-19** | Sobreventa → determina si el paso 11 es error duro o advertencia (§13) |
| **D-20** | Cambios tras confirmar → determina si existe un flujo de modificación (§18 caso 11) |
| **D-21** | Envío y transporte → determina §16 y si el transporte entra en la base comisionable |
| **D-03** | Productos no comisionables → determina §18 caso 12 |

Ninguna de estas decisiones impide **documentar** el diseño; todas impiden
**implementarlo** sin ambigüedad.

---

## 21. Precio por grupo del cliente del carrito — RT-03 (Fase 01B)

**Defecto reproducido [B-§4].** `CustomerRepository::getCurrentGroup()` devuelve el
grupo de `auth()->guard()->user()`. En una petición HTTP el guard por defecto es
`customer`, así que el precio de cada ítem sale del **cliente autenticado en la
sesión**, no de `cart.customer_id`. `AbstractType::validateCartItem()` reprecia todos
los ítems en cada `collectTotals()` con ese mismo grupo.

**Consecuencia para el servicio.** Los pasos 5 a 11 de §3 **no** pueden fiarse del
contexto de sesión. Antes de cada operación sobre el carrito debe fijarse el grupo del
cliente del carrito para esa petición.

**Opciones compatibles sin modificar `packages/Webkul/`** (a decidir y probar en la
Fase 03):

| # | Opción | Ventaja | Riesgo |
|---|---|---|---|
| P-1 | Enlazar en el contenedor una subclase de `CustomerRepository` cuyo `getCurrentGroup()` devuelva el grupo de un **contexto comercial explícito** cuando existe, y el comportamiento oficial en otro caso | Explícito; no suplanta autenticación; cubre `AbstractType`, `CartRule` y `ProductRepository`, que resuelven el repositorio por el contenedor | Hay que revisar la firma en cada actualización de Bagisto |
| P-2 | Fijar solo en memoria el usuario del guard `customer` (`setUser`, sin tocar la sesión) durante la operación del carrito y restaurarlo al terminar | No requiere enlazar clases | Suplanta la identidad del cliente en toda la petición; otros listeners podrían actuar como si el cliente estuviera autenticado |

**Recomendación técnica:** P-1, con pruebas HTTP que **no** usen `actingAs()`
(`actingAs()` cambia el guard por defecto y oculta el defecto [B-§4.6]).

**Comportamiento obligatorio al cambiar de cliente** [B-§5]: sustituir cliente y grupo
del carrito, eliminar el contexto comercial anterior, recalcular todos los ítems,
descuentos y totales, mostrar cliente, grupo y precio aplicados, advertir si cambió
un precio visible y bloquear la confirmación si hay inconsistencia.
