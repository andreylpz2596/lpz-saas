# 05_DISENO_TECNICO_CREACION_PEDIDOS_ASESOR.md

Diseño técnico del motor y la interfaz de creación de pedidos del asesor.

**Fecha:** 2026-09-09 (Fase 00B) · **Actualizado:** 2026-09-20 (Fase 02B0)
**Estado:** DISEÑO. **No implementado.** Ninguna clase descrita aquí existe
todavía en el repositorio. **Fase 03A (2026-09-26):** auditoría del flujo real y diseño de
«Nueva venta» en **§22**.

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
   seleccionados, no la variante suelta. *(SUPERADO — §22.3: basta el padre con
   `selected_configurable_option`; `super_attribute` no es necesario, y el módulo debe
   verificar que la variante sea hija del padre.)*
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

> **Actualización 2026-09-25 — SUPERADO POR D-RT04-01…09 / DOC. 13.** La «ficha de contacto
> propia» se sustituye por el índice `bylopez_va_customer_phones`, sin nombre ni email (Doc. 13 §9).
> La dirección comercial usa el email del cliente o `NULL`, sin exigir un email de facturación
> (Doc. 13 §14). Las notificaciones se protegen con un binding de subclases de listeners (Doc. 13 §15.3).
> El grupo es obligatorio en el alta comercial (Doc. 13 §13). La búsqueda común es
> `BuscarClienteComercialService` (Doc. 13 §11).

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

---

## 22. Fase 03A — auditoría del flujo real (Bagisto 2.4.9) y diseño de «Nueva venta» (2026-09-26)

**Estado:** 03A EN AUDITORÍA; diseño entregado para revisión de Camilo Gaitán. **Sin código
productivo.** Base: `de7c81d`. §1–§21 se conservan; lo que aquí se corrige lleva la nota
«SUPERADO — §22.x». RT-03 (§21, Doc. 11) y RT-04 (Doc. 13 §37) no se rediseñan: se consumen.

### 22.1 Flujo real de creación de pedido desde el panel (verificado)

Los números de línea de §4.1 siguen siendo válidos en 2.4.9.

```text
Admin (buscar cliente / «Crear cliente»)
 → POST admin.sales.cart.store          CartController::store (l.54)
      Cart::createCart(['customer' => $customer, 'is_active' => false])   (Cart.php:130)
      snapshot: customer_id, first/last_name, email, is_guest=0, channel = canal actual
 → GET  admin.sales.orders.create/{cart} OrderController::create: vista + direcciones guardadas del cliente
 → POST admin.sales.cart.items.store     storeItem (l.78): Cart::setCart → Cart::addProduct(product, request)
      TypeInstance::prepareForCart → precio getFinalPrice() con el grupo de RT-03 → collectTotals
 → PUT/DELETE admin.sales.cart.items.*   updateItems / removeItem → collectTotals
 → POST admin.sales.cart.addresses.store storeAddress: CartAddressRequest (email obligatorio) →
      Cart::saveAddresses → collectTotals → Shipping::collectRates() (guarda cart_shipping_rates)
 → POST ...shipping-methods              Cart::saveShippingMethod(code) (exige que la tarifa exista) → collectTotals
 → POST ...payment-methods               Cart::savePaymentMethod(['method']) (NO valida disponibilidad) → collectTotals
 → POST admin.sales.orders.store/{cart}  OrderController::store (l.77):
      Cart::hasError()  (sin carrito, INSUFFICIENT_QUANTITY o mínimo de pedido)
      Cart::collectTotals()
      validateOrder()   (5 comprobaciones, §4.3)
      método ∈ {cashondelivery, moneytransfer}  (restricción del controlador, §4.4)
      OrderResource($cart) → OrderRepository::create() → Cart::removeCart() (BORRA el carrito)
```

**`OrderRepository::createOrderIfNotThenRetry`** (`Sales/Repositories/OrderRepository.php`) hace,
en una transacción propia:

1. `checkout.order.save.before`;
2. crea el pedido con `status = pending`, el pago, las direcciones y los ítems (hijos del
   configurable incluidos);
3. `manageInventory()` reserva en `product_ordered_inventories`;
4. `checkout.order.save.after`;
5. `commit` en el `finally`.

Ante `\Exception` hace rollback y reintenta hasta 3 veces (`max_retry_attempts`), salvo
`CouponUsageLimitExceededException`.

Listeners de `checkout.order.save.after` (§36.12 del Doc. 13 y Doc. 08):

- atribución (VentasAsesor);
- correo al administrador y guard del correo al cliente;
- `CartRule` (uso de cupones);
- notificación del panel;
- `Payment\GenerateInvoice`;
- `Product` (job de reindexado de inventario);
- FPC / CatalogCache;
- BookingProduct.

**Qué se puede reutilizar y qué está acoplado:**

| Pieza | Reutilizable desde un servicio | Acoplamiento |
|---|---|---|
| `Cart` (facade) | **Sí**, núcleo del flujo | Estado interno compartido (`setCart`/`getCart`); `removeCart` y `validateItems` tocan la sesión (`session()->forget('cart')`, flash `info`) |
| `Shipping::collectRates()` / `Payment::getSupportedPaymentMethods()` | Sí | Leen `Cart::getCart()`: hay que fijar el carrito antes |
| `OrderResource` + `OrderRepository::create()` | **Sí** | Ninguno |
| `CartController` / `OrderController` | **No** | Request, `CartAddressRequest` (email obligatorio), respuestas JSON, `validateOrder()` dentro del controlador, restricción de pago |
| Endpoints `admin.sales.cart.*` | No como base | Cargan cualquier `cart_id` sin comprobar dueño ni `is_active` |

### 22.2 Precio, recálculo, descuentos e impuestos

- **Precio de línea:** `prepareForCart()` → `getFinalPrice()` del tipo de producto. En el
  configurable, el de la **variante hija**. El grupo sale de
  `CustomerRepository::getCurrentGroup()`, que RT-03 ata al cliente del carrito.
- **Recálculo:** cada `collectTotals()` ejecuta:
  1. `validateItems()`: quita ítems inactivos y reprecia con `validateCartItem()` →
     `getFinalPrice($qty)` (precio por cantidad incluido);
  2. `checkout.cart.collect.totals.before`, que dispara `CartRule\Listeners\Cart@applyCartRules`
     (descuentos);
  3. `calculateItemsTax()` y `calculateShippingTax()`;
  4. suma de totales.

  Todo es del core.
- **HALLAZGO:** `cart_items.custom_price`, si no es NULL, **sustituye** el precio en
  `validateItems()` (`Cart.php:975`). Es un mecanismo del core para fijar precio manual. **El
  módulo nunca lo escribe** (invariante I-7); fijar precios manuales sería una decisión D-18
  explícita.

### 22.3 Productos, configurables y variantes (corrige §14)

**SUPERADO — §14.1:** `Cart::addProduct(padre, $data)` necesita solo
`selected_configurable_option` (id del producto **hijo**) y `quantity`. **`super_attribute` no
es necesario**: `getAdditionalOptions()` (`Configurable.php:470`) toma las etiquetas de talla y
color de los atributos del hijo. El carrito guarda dos ítems:

- padre: `product_id` del configurable, precio y cantidad reales;
- hijo: `product_id` de la variante, SKU y nombre de la variante.

**HALLAZGO de riesgo:** `Configurable::prepareForCart` (l.384) **no comprueba que la variante
pertenezca al padre**: hace `find($selected_configurable_option)` sin validar `parent_id`. Un id
equivocado o inventado entraría al carrito. **La resolución de variantes del módulo debe exigir
`hijo.parent_id = padre.id`** (invariante I-5).

**Catálogo real** (`lpz_saas`): 77 configurables, 300 variantes (`products.parent_id`), 303
simples (300 son variantes) y 1 agrupado.

**Estructura real del catálogo** (SELECT en `lpz_saas`, coherente con la auditoría de catálogo de
CorteConfeccion):

- Los 77 configurables tienen `color` como super-atributo, pero **un solo color entre sus
  variantes**: cada configurable es *línea × un color*.
- El eje real de variante es `size` (73 configurables) o `grupo_de_edad_ninos` (4), con 2 a 4
  variantes cada uno, en 4 familias de atributos.

Por tanto, «camiseta hombre azul rey M» se resuelve así:

1. `(línea o producto, color)` → **un** configurable;
2. talla → **una** variante hija.

CorteConfeccion ya tiene resolutores de solo lectura para **su** dominio (tela, línea, color, eje):
`ResolvedorVarianteCorteService` y `VarianteConsultaService`. Son trabajo ajeno y sin commit, así
que VentasAsesor no depende de ellos. `CatalogoVentaService` seguirá la misma estructura del
catálogo, y una extracción común puede evaluarse más adelante.

**Resolución diseñada** (Laravel, nunca la IA):

1. intención («camiseta hombre, azul rey, M, 6»);
2. producto padre configurable (búsqueda por nombre, SKU o categoría → candidatos);
3. el eje de variante (talla o grupo de edad) → la variante hija **real** con esa
   opción;
4. `addProduct(padre, ['selected_configurable_option' => hijo, 'quantity' => n])`.

Ambigüedad o ausencia → candidatos o error; nunca un id adivinado.

### 22.4 Stock (fuente real y momentos)

- **Fuente vendible:** `product_inventory_indices.qty` por canal (`AbstractType::getInventoryIndex`).
  Se calcula como la suma de `product_inventories.qty` en los orígenes **activos del canal**
  menos `product_ordered_inventories.qty` del canal (`Indexers/Inventory::getQuantity`).
  **No** se lee `product_inventories` directamente.
- **Reglas del core:**
  - `Simple::haveSufficientQuantity` → verdadero si el producto no gestiona stock (sin
    `manage_stock`); si no, `qty ≤ índice` o, en su defecto, backorders
    (`catalog.inventory.stock_options.back_orders`, hoy **0**).
  - `Configurable::isItemHaveQuantity` → el hijo.
- **Cuándo se comprueba:**
  - al agregar un configurable (`prepareForCart` lanza `InsufficientProductInventoryException`);
  - al confirmar: `Cart::hasError()` → `isItemsHaveSufficientQuantity()`, **fuera** de la
    transacción del pedido.

  `OrderRepository` **no** vuelve a comprobar: queda una ventana de carrera (D-19).
- **Cuándo se descuenta:**
  - al crear el pedido, **reserva** (`ordered_inventories`) y el job reindexa;
  - al crear el **envío** (`ShipmentItemRepository::updateProductInventory`), resta la reserva y
    `product_inventories` del origen.
- **Datos reales:** un solo origen (`BODEGA1`, activo, ligado al canal 1), 377 filas de índice,
  93 filas de reserva.
- **Deuda del core:** `Configurable::totalQuantity()` (l.600) hace `->qty` sobre un entero. No
  se usará; el stock se consulta por variante.

### 22.5 Carrito como borrador (D-16)

**Conclusión: sí, el `Cart` real sirve como borrador comercial, con una tabla propia mínima al
lado.**

| Aspecto | Evidencia | Consecuencia |
|---|---|---|
| Persistencia | Tabla `cart`; el admin lo crea con `is_active = 0` | Sobrevive entre peticiones y días |
| Aislamiento de la tienda | `initCart` y `mergeCart` solo toman `is_active = 1` (`Cart.php:67-104, 215`) | El borrador no aparece en la sesión del cliente ni se fusiona con su carrito |
| Varios borradores | Nada lo impide (hoy: 77 carritos inactivos con cliente) | Posible; hay que listarlos y **limpiarlos** |
| Dueño o asesor | `cart` no tiene asesor ni estado de negocio | **Falta información:** hace falta una tabla propia |
| Recalculado | `collectTotals()` reprecia con RT-03 | Correcto si siempre se opera dentro de `durante()` |
| Snapshots | `createCart` copia nombre, apellido y email; `setCustomerPersonnelDetails()` los reescribe al guardar direcciones | Cambiar de cliente exige reescribirlos |
| Expiración | **El core no limpia carritos** (sin comando ni tarea programada; solo `invoice:cron`) | Hará falta una política de limpieza propia. *(Precisado en §24.6: de los 77 inactivos, 8 son carritos de la tienda ya convertidos y el origen no puede determinarse.)* |
| Conversión | `OrderController::store` → `removeCart()` **borra** la fila | La tabla propia debe conservar `order_id` y no depender de la vida del carrito |
| Concurrencia | Sin bloqueo en el core | El servicio bloquea la fila del borrador (`lockForUpdate`) al confirmar, más idempotencia |

**Tabla propia propuesta (sin crear; requiere aprobación):** `bylopez_va_borradores_venta`

- `cart_id` UNIQUE (nullable tras la conversión);
- `customer_id`, `advisor_id`, `created_by_admin_id` (sin FK a admins, patrón 02A1);
- `estado` (`abierto` | `confirmado` | `descartado` | `expirado`);
- `canal_captura`;
- `external_reference` UNIQUE nullable (idempotencia de API/n8n);
- `order_id` nullable;
- timestamps.

No guarda precios ni líneas: eso vive en el carrito de Bagisto.

### 22.6 Cambio de cliente del borrador

Bagisto no tiene esa operación (`mergeCart` es para el login). Dentro de una transacción con el
borrador bloqueado:

1. resolver al cliente B (RT-04) y validar su grupo y su estado (§22.10);
2. reescribir `cart.customer_id`, `customer_first/last_name` y `customer_email` (NULL si no
   tiene);
3. retirar las direcciones `cart_*` de A (y el pago), y ejecutar `resetShippingMethod()`;
4. `ContextoPrecioComercial::durante(cart, B, fn () => Cart::collectTotals())`: reprecia ítems
   (`validateCartItem`), reaplica reglas de carrito y recalcula impuestos;
5. devolver las diferencias de precio para mostrarlas.

RT-03 impide el estado mixto porque su contexto exige `cart.customer_id = cliente`
(`fijarParaPeticion`, fail closed). Nunca se precia sin contexto.

### 22.7 Direcciones y envío

- **Direcciones:** `Cart::saveAddresses()` no valida nada (el email obligatorio vive en
  `CartAddressRequest`). La dirección guardada con `GuardarDireccionClienteComercialService`
  (RT-04) se pasa con `address_type = customer` e `id`, y entra como `parent_address_id`. Ya está
  probado (Doc. 13 §37.5), incluido `customer_email` NULL.
- **Envío:**
  - `Shipping::collectRates()` recorre `config('carriers')` y guarda las tarifas en
    `cart_shipping_rates`;
  - `saveShippingMethod()` exige que el código exista entre esas tarifas;
  - `validateOrder()` exige dirección de envío y tarifa si hay ítems almacenables.
  - Activos en `lpz_saas`: `flatrate` y `free`.
- **Separación** (sin implementar logística): «domicilio» o «recogida en bodega» serán **métodos
  de entrega** del dominio que se traducen a un transportista o tarifa de Bagisto.

  ```text
  Customer ≠ Address ≠ método de entrega ≠ Shipment ≠ guía
  ```

  Bagisto exige dirección de envío también para una recogida, porque hay ítems almacenables.
  Cómo representarlo (por ejemplo, la dirección de la bodega) es una decisión de la fase de
  logística.

### 22.8 Pago y contraentrega

- **Disponibles:** `Payment::getSupportedPaymentMethods()` recorre `config('payment_methods')` y
  cada método comprueba su `isAvailable()`.
- **Activos en `lpz_saas`:** `cashondelivery` y `moneytransfer`. PayPal está inactivo.
- **`savePaymentMethod()` no valida:** el servicio debe comprobar que el método esté disponible y
  permitido para la venta comercial (hoy el admin limita a COD y transferencia, §4.4).
- **Estado inicial:** `OrderRepository` crea el pedido `pending`. Luego
  `Payment\GenerateInvoice` (listener, dentro de la transacción del pedido) aplica la
  configuración real:

| Método | `generate_invoice` | Factura | Pedido |
|---|---|---|---|
| `cashondelivery` | 1 | automática, `pending` | `pending_payment` |
| `moneytransfer` | 1 | automática, `paid` | `processing` |

Datos: 11 pedidos en `pending_payment`, 12 `completed` y 1 `canceled`; 14 por contraentrega y 10
por transferencia.

La arquitectura no se ata a la contraentrega: el método es un dato de la venta validado contra
los disponibles.

### 22.9 Atribución (sin rediseño)

`ClasificadorOrigenPedido::clasificar` da **prioridad** al contexto explícito por `cart_id`
(l.55) sobre la ruta. La confirmación envolverá `OrderRepository::create()` en
`ContextoComercialDePeticion::durante($cartId, ContextoComercial, …)` (Doc. 09), con:

- `mecanismo = ventas_asesor`;
- canal (`whatsapp`, `telefono`…);
- `advisor_id`, `created_by_admin_id` y propiedad comercial según D-27.

Los reintentos del repositorio reutilizan el mismo contexto. No hay inferencia por sesión ni por
ruta.

### 22.10 P-09: cliente inactivo o suspendido (opciones; no se implementa sin decisión)

**Evidencia** (Doc. 13 §37.10): `status = 0` bloquea el login y el checkout de la tienda y de la
API; `is_suspended = 1` bloquea el checkout de la tienda. El flujo del panel **no** los consulta.
Hoy en `lpz_saas` hay **0** inactivos y **0** suspendidos.

| Opción | Efecto | Implicación |
|---|---|---|
| A. Bloquear ambos en venta comercial | Coherente con la tienda | Una suspensión por impago impide también la venta presencial o telefónica |
| B. Bloquear inactivos; suspendidos solo con permiso y motivo (auditado) | Flexible y trazable | Requiere un permiso nuevo y registrar el motivo |
| C. Solo avisar | Igual que el panel actual | Riesgo de vender a quien la empresa suspendió |
| D. Bloquear ambos al confirmar, pero permitir el borrador | El asesor puede preparar la venta | Evita pedidos, no borradores |

### 22.11 Grupos reales y UX (sin default)

Grupos en `lpz_saas`:

| id | code | Nombre | Clientes |
|---|---|---|---|
| 1 | `guest` | Invitado | 1 (excluido en venta comercial) |
| 2 | `general` | General | 3 |
| 3 | `Mayorista_VIP_1` | Mayorista_VIP_1 | 5 |
| 4 | `Mayorista_VIP_2` | Mayorista_VIP_2 | 1 |
| 5 | `Mayorista_VIP_3` | Mayorista_VIP_3 | 0 |
| 6 | `Mayorista_VIP_4` | Mayorista_VIP_4 | 1 |
| 7 | `Recurrente` | Recurrente | 4 |

El único cliente del grupo invitado **no puede recibir ventas comerciales** hasta que se le
asigne un grupo (RT-03 lo preciaría como invitado).

**UX propuesta:**

- el grupo del cliente existente se muestra y se usa;
- en el alta, el selector es obligatorio y no tiene preselección;
- cambiar el grupo de un cliente existente desde «Nueva venta» sería una acción aparte y
  auditada (decisión).

### 22.12 Arquitectura propuesta de la Fase 03

Servicios pequeños sobre el `Cart` de Bagisto. Todas las operaciones de precio corren dentro de
`ContextoPrecioComercial::durante($cartId, $customerId, …)`.

| Servicio | Responsabilidad | Usa |
|---|---|---|
| `CatalogoVentaService` (solo lectura) | Buscar productos padre; listar variantes con opciones, stock del índice y precio de vista previa (del core, en contexto del cliente); **resolver una variante por opciones**, con `parent_id` verificado | `ProductRepository`, TypeInstance, índice de inventario |
| `BorradorVentaService` | Crear el borrador (`Cart::createCart` con cliente explícito, `is_active = 0`, más la fila propia), listar los del asesor, descartar y cambiar de cliente (§22.6) | RT-04 `ResolverClienteComercialService` / `ValidadorGrupoComercial`, `Cart`, RT-03 |
| `LineasBorradorService` | Agregar, cambiar cantidad y quitar líneas (`addProduct`, `updateItems`, `removeItem`) | `CatalogoVentaService`, `Cart`, RT-03 |
| `EntregaPagoBorradorService` | Dirección (RT-04) → `saveAddresses`, `collectRates`, `saveShippingMethod`, pago validado contra los métodos disponibles y permitidos | `Cart`, `Shipping`, `Payment` |
| `ConfirmarVentaService` | Validaciones finales (I-1…I-10), idempotencia, `durante()` de RT-03 + contexto de atribución → `OrderResource` → `OrderRepository::create` → borrador `confirmado` con `order_id` | Todo lo anterior |
| `CrearPedidoComercialService` | **Fachada** para canales de un solo paso (API, n8n, IA, voz): resolver cliente → borrador → líneas → entrega y pago → confirmar. No contiene reglas propias | Los servicios anteriores |

La UI de «Nueva venta» consume los mismos servicios paso a paso, con autoguardado del borrador.
Cada paso devuelve el estado del carrito calculado por Bagisto. Ningún servicio calcula
precios, descuentos, impuestos ni stock.

### 22.13 Invariantes

| Id | Regla |
|---|---|
| I-1 | `cart.customer_id` = cliente del contexto RT-03 en **toda** operación que reprecie (el propio RT-03 falla cerrado si no) |
| I-2 | Nunca se precia fuera de `durante()`; nunca se usa el grupo de la sesión del asesor |
| I-3 | El cliente debe tener un grupo apto **al confirmar** (revalidado: pudo cambiar desde el alta) |
| I-4 | La identidad del cliente viene de RT-04 (teléfono → email → candidatos). Nunca se crea un duplicado ni se elige por nombre |
| I-5 | Toda variante añadida es hija real del padre (`parent_id`) y pasa por el catálogo del módulo; nunca un id sin verificar |
| I-6 | Stock: se muestra el del índice, y al confirmar se exige `Cart::hasError() = false`. La venta de más depende de D-19 |
| I-7 | El módulo **nunca** escribe `custom_price` ni precios en `cart_items` |
| I-8 | Confirmar es idempotente (bloqueo del borrador + `external_reference` UNIQUE): un doble clic o un reintento no crea dos pedidos |
| I-9 | El pedido se crea solo con `OrderRepository::create()` y con el contexto de atribución explícito; nada de INSERT directo |
| I-10 | El método de pago debe estar disponible (`isAvailable`) y permitido para la venta comercial |
| I-11 | Un borrador confirmado o descartado no se vuelve a abrir; su carrito se borra (core) o se descarta |

### 22.14 Riesgos y deuda

- **Carrera de stock** entre `hasError()` y la reserva (D-19).
- **Carritos inactivos** acumulados sin limpieza en el core.
- **Endpoints del panel** que operan sobre cualquier `cart_id`: los borradores del módulo
  también serían editables desde «Crear pedido» del core. El adaptador de RT-03 los precia bien,
  pero no hay control de dueño.
- **Efectos de sesión:** `validateItems()` hace `session()->flash('info')` y `removeCart()`
  hace `session()->forget('cart')`. Son inocuos en el panel y a vigilar en colas o API.
- **`validateOrder()` duplicado** (§4.3) y **`Configurable::totalQuantity`** roto.
- **`GenerateInvoice`** corre dentro de la transacción del pedido: un fallo de factura revierte y
  reintenta el pedido. Para clientes sin email el guard de RT-04 lo cubre.

### 22.15 Decisiones de negocio pendientes (Camilo Gaitán)

1. **P-09:** opción A, B, C o D (§22.10).
2. **D-19, venta de más:** si una venta sin stock suficiente se bloquea siempre o puede
   autorizarse.
3. **Métodos de pago** permitidos en venta comercial: solo contraentrega y transferencia (como
   el panel) u otros.
4. **Borradores:** caducidad (por ejemplo, N días) y quién puede ver o retomar los de otro
   asesor.
5. **Cambio de grupo** de un cliente existente desde «Nueva venta»: permitido (con permiso y
   motivo) o solo desde la ficha del cliente.
6. **D-18, precio manual:** confirmar que no hay precios manuales (el módulo nunca usa
   `custom_price`) o definir excepciones con permiso.
7. **Tabla `bylopez_va_borradores_venta`** (§22.5): aprobar su creación en 03B.

---

## 23. Fase 03B — catálogo comercial y borrador de venta (2026-09-26)

**Estado:** IMPLEMENTADO — pendiente de revisión de Camilo Gaitán. Sin commit. **No crea
pedidos, no reserva stock, no genera facturas, no tiene UI ni rutas.** Webkul tiene 0 cambios. La
migración 000006 está aplicada **solo en `lpz_saas_testing`**.

### 23.1 Decisiones de Camilo Gaitán registradas

| Id | Decisión |
|---|---|
| **D-18** | No hay precios manuales: prohibido escribir `cart_items.custom_price`. El precio lo calcula Bagisto. Un precio negociado sería una fase explícita, con autorización y auditoría |
| **D-19** | Una venta sin stock suficiente es un **compromiso**, nunca stock falso. Se distinguen tres situaciones: **disponible** (stock vendible), **en producción** (evidencia real en Corte y Confección) y **pedido especial** (fabricar por decisión expresa). Prohibido alterar el stock de Bagisto, crear reservas falsas o forzar stock negativo |
| Pago | Métodos iniciales: contraentrega y transferencia. La arquitectura no se ata a ellos: se valida la disponibilidad real del método |
| Borradores | El creador es el propietario. Pasado **1 día hábil**, otro asesor autorizado puede reclamarlo. El día hábil se calcula con un calendario configurable, nunca como «+24 h» |
| Grupo frente a descuento | El grupo es la clasificación persistente del cliente; un descuento es un beneficio de una venta concreta. No se cambia el grupo para dar un descuento |
| **P-09** | El borrador existe y se trabaja; la **confirmación** se bloqueará con `status` inactivo o `is_suspended` (en 03B solo se avisa) |
| Tabla de borradores | Aprobada: `bylopez_va_borradores_venta`, mínima |

### 23.2 `CatalogoVentaService` (solo lectura)

Vive en `Services/Venta/CatalogoVentaService.php`. Métodos:

- `buscarProductos(texto, límite)` → `ProductoVenta[]`: configurables y simples **sin padre**,
  activos, desde `product_flat` del canal y el idioma por defecto;
- `variantes(producto, cliente, cantidad)` → `VarianteVenta[]`;
- `variante(producto, varianteId, cliente, cantidad)` → `VarianteVenta` **verificada**;
- `resolverVariante(producto, [atributo => option_id], cliente, cantidad)` → exige todas las
  opciones y exactamente una coincidencia;
- `desglose(variante, cantidad)` → `DesgloseAbastecimiento`.

Qué incluye cada `VarianteVenta`:

- ids reales, SKU, nombre y activa;
- opciones de los super-atributos (atributo, `option_id`, etiqueta);
- `gestionaStock` y `stockVendible`;
- `precioUnitario` como vista previa.

Errores con código estable (`VarianteInvalidaException`): producto inexistente, producto no
vendible (también un hijo pasado como producto de primer nivel), variante que no pertenece al
padre, opciones incompletas, variante no encontrada, variante ambigua y cantidad inválida.

**Invariante I-5 implementada:** `hijoVerificado()` exige `hijo.parent_id = padre.id`. Probado:
la variante de otro configurable se rechaza aunque ambos ids existan.

- **Stock:** `TypeInstance::totalQuantity()`, la misma abstracción que usa Bagisto (índice del
  canal = inventario − reservas, §22.4). Probado: 7 en inventario y 2 reservados dan 5.
  `product_salable_inventories` **no** se usa porque ningún código del core lo consume.
- **Precio:** `TypeInstance::getFinalPrice($cantidad)` de la variante, lo mismo que usa el
  carrito al repreciar (`Configurable::validateCartItem`). Se calcula dentro de
  `ContextoPrecioComercial::duranteCreacionDeCarrito($cliente)` (RT-03, contexto de cliente sin
  carrito) y el servicio no contiene ninguna regla de grupo.
  - Probado: grupo A (−10 %) y grupo B (−30 %) dan cada uno el precio de Bagisto, aunque haya
    otro cliente con sesión en la tienda.
  - Probado: un cliente del grupo invitado o sin grupo no obtiene precio (fail closed).
  - Probado: la consulta no ejecuta ninguna escritura.

### 23.3 Corte y Confección: ¿unidades en producción por variante? (auditoría de solo lectura)

| Elemento | Evidencia |
|---|---|
| Entregado a talleres | `bylopez_cc_ordenes_confeccion_detalles.cantidad_entregada`, con `product_id` = **variante simple** (30 detalles, todos con variante; 2.149 unidades) |
| Devuelto por el taller | `bylopez_cc_ordenes_confeccion_retorno_detalles`: `cantidad_reportada`, `cantidad_recibida`, `cantidad_defectuosa` y `cantidad_faltante` (nullable), enlazado a su detalle. Totales: 1.044 reportadas, 855 recibidas, 4 defectuosas |
| Estados | Orden: `activa`, `en_confeccion`, `terminada_taller`, `recibida_bodega`, `cerrada`, `anulada`. Retorno: `pendiente_bodega` o `recibida_bodega` |
| Entrada a stock | `InventarioMovimientoService::registrarEntradaConfeccion` escribe `product_inventories` y el índice directamente: **lo recibido ya es stock vendible** |

Una fórmula candidata para una variante sería:

```text
enProduccion = Σ cantidad_entregada   (órdenes no cerradas ni anuladas)
             − Σ (recibida + defectuosa + faltante)   (retornos ya recibidos de esas órdenes)
```

Así no se cuenta dos veces: lo recibido sale de producción y ya está en el índice de Bagisto. Un
retorno `pendiente_bodega` sigue en producción.

**Conclusión: existe la materia prima, pero hoy NO es una fuente fiable para VentasAsesor.**

- Depende de la semántica de CorteConfeccion, que es trabajo ajeno y sin commit:
  - si `cerrada` implica que no llegará nada más;
  - qué significa un `faltante` NULL;
  - las piezas cortadas pero aún no entregadas a taller.
- Además, VentasAsesor no debe leer tablas de otro módulo.

**Diseño:** un contrato de solo lectura **propiedad de CorteConfeccion** (por ejemplo, «unidades
pendientes de recibir por variante»), aprobado por quien mantiene ese módulo. VentasAsesor lo
consumiría por interfaz. **No se devuelve «en producción» sin esa fuente.**

### 23.4 Disponible, en producción y pedido especial (contrato preparado)

*(SUPERADO por §24.2: campos definitivos `disponibleInmediato`, `faltanteInmediato`, `enProduccion` y `porFabricar`, con la invariante I-12.)* Versión inicial: `DesgloseAbastecimiento{solicitado, disponible, enProduccion, sinCubrir}`:

- `disponible` = mín(solicitado, stock vendible), o todo si la variante no gestiona stock;
- **`enProduccion = null`** («desconocido», nunca 0) hasta integrar la fuente de §23.3;
- `sinCubrir` = solicitado − disponible.

Ejemplo del negocio: pide 100 y hay 30 vendibles → 30 disponibles, producción desconocida, 70 sin
cubrir (probado). Con la fuente integrada: 30 disponibles, 50 en producción y 20 por fabricar,
estos últimos **solo** si se marca expresamente como pedido especial.

El futuro **compromiso comercial** (por línea) guardaría:

- variante y cantidad comprometida por origen (stock, producción, fabricar);
- quién autorizó el pedido especial y cuándo;
- fecha estimada, si existe;
- referencia informativa a la orden de producción, sin crearla.

No se implementa en 03B: no reserva ni crea producción.

### 23.5 `bylopez_va_borradores_venta` (diseño final, migración 000006)

| Campo | Por qué existe | Por qué no se tomó de otro sitio |
|---|---|---|
| `cart_id` (UNIQUE, FK `cart` **SET NULL**) | Enlace con el carrito real | Bagisto borra el carrito al crear el pedido, y el módulo lo borra al descartar: el borrador debe sobrevivirlo |
| `propietario_admin_id` | Quién trabaja el borrador (cambia al reclamarlo) | El carrito no lo sabe. **No** es el `advisor_id` comercial (D-27 se decide al confirmar) |
| `creado_por_admin_id` | Quién lo abrió (fijo) | Trazabilidad y futura atribución |
| `estado` | Ciclo de negocio (§23.6) | `cart.is_active` no expresa confirmando, confirmado o descartado |
| `canal_venta`, `mecanismo_creacion` | Contexto explícito para la atribución al confirmar | No se pueden deducir del carrito ni de la ruta |
| `external_reference` (UNIQUE con `mecanismo_creacion`) | Idempotencia de API, n8n, WhatsApp o voz | Por mecanismo: dos integraciones pueden usar el mismo identificador |
| `asignado_hasta` | Fin de la ventana del propietario | Calculado con el calendario laboral |
| `order_id` (UNIQUE, FK `orders` **RESTRICT**) | Pedido resultante (etapa posterior) | Como la atribución: un pedido con borrador no desaparece en silencio |

**No se duplica:** cliente, snapshots de nombre y email, líneas, precios ni totales (viven en
`cart` y se leen de allí). Sin FK a `admins` (patrón 02A1). Índice `(propietario_admin_id,
estado)` para listar.

### 23.6 Estados y concurrencia

```text
activo ──► confirmando ──► confirmado
  │              └──► activo   (si falla la confirmación; etapa posterior)
  └──► descartado
```

- Solo `activo` admite cambios. `confirmando` es el candado de la confirmación futura: nadie más
  edita ni confirma mientras exista.
- Cada mutación corre en una **transacción** con `lockForUpdate` sobre la fila del borrador (y
  sobre el carrito cuando lo cambia). Dos asesores o dos peticiones se serializan.
- La idempotencia la garantiza el **UNIQUE** `(mecanismo_creacion, external_reference)`: si dos
  peticiones empatan, la segunda ve la violación, su transacción (incluido su carrito) se revierte
  y devuelve el borrador ganador.
- La doble confirmación quedará impedida por la transición atómica `activo` → `confirmando` bajo
  bloqueo, más el UNIQUE de `order_id` (etapa posterior).

### 23.7 Propiedad y reclamación

- **Ventana:** al crear o reclamar, `asignado_hasta = calendario->sumarDiasHabiles(ahora,
  ventasasesor.borradores.dias_habiles_asignacion = 1)`.
- **Solo el propietario** cambia de cliente, recalcula o descarta.
- **Reclamar** exige el permiso `ventas_asesor.pedidos.create` y que la ventana haya vencido; el
  propietario anterior deja de poder editar.
- **Calendario:** no existía ninguno en el proyecto (ni Carbon BusinessDays ni helpers). Se creó
  el contrato `CalendarioLaboral` con una implementación por configuración:
  - días laborales ISO; *SUPERADO por D-35 (§24.1): lunes a sábado*;
  - festivos en configuración, **vacíos**: no hay festivos colombianos en el código.
- **Regla probada:** desde un día laborable conserva la hora (viernes 10:00 → lunes 10:00); desde
  un día no laborable cuenta desde el inicio del siguiente laborable; salta festivos configurados.

### 23.8 `BorradorVentaService`

Vive en `Services/Venta/BorradorVentaService.php`.

| Operación | Comportamiento |
|---|---|
| `crear(cliente, actor, canal, mecanismo, ?referencia)` | Exige permiso. Cliente existente con grupo apto (invitado o sin grupo → rechazo). Crea `Cart::createCart(cliente explícito, is_active = 0)` más la fila, en una transacción. Misma referencia → el **mismo** borrador; de otro cliente → `referencia_externa_en_uso` |
| `obtener` / `listarDelPropietario` | `VistaBorradorVenta`: estado, cliente (grupo, activo, suspendido), totales leídos del carrito, propiedad, `reclamable`, avisos y `clienteConfirmable`. Lista con relaciones precargadas |
| `reclamar` | Ver §23.7 |
| `descartar` | Estado final. Borra el carrito (sus líneas, direcciones, pago y tarifas caen en cascada) y `cart_id` pasa a NULL |
| `cambiarCliente` | Ver §23.9 |
| `recalcular` | Reprecia con el grupo **actual** del cliente del carrito (probado tras cambiarle el grupo). Se incluye porque es la forma de aplicar un cambio de grupo al borrador sin congelar el histórico |

**Operación de líneas:** agregar y quitar pertenecen a 03C y no se incluyeron. Las pruebas las
simulan con `Cart::addProduct` dentro de RT-03, como lo hará 03C.

**HALLAZGO:** `Cart::collectTotals()` **borra un carrito sin líneas** (`validateItems`). El
servicio solo recalcula si hay líneas; así un borrador recién creado no pierde su carrito.

### 23.9 Cambio de cliente

En **una** transacción, con el borrador y el carrito bloqueados:

1. validar al cliente B (existente y con grupo apto);
2. reescribir `customer_id`, nombre, apellido y email (NULL si no tiene) e `is_guest = 0`;
3. borrar las direcciones del carrito y el pago;
4. `Cart::resetShippingMethod()`, que retira tarifas y método de envío;
5. si hay líneas, `ContextoPrecioComercial::durante(cart, B, collectTotals)`, que reprecia ítems
   con el grupo actual de B, reglas de carrito e impuestos.

RT-03 exige `cart.customer_id = B`, así que no puede quedar un carrito de A preciado como B.

**Probado:** el precio pasa del de A (−10 %) al de B (−30 %); direcciones, pago y envío quedan
retirados; `custom_price` sigue NULL. Un cambio a un cliente invitado se rechaza y deja el
carrito intacto.

### 23.10 Descuento comercial autorizado (auditoría; sin implementar)

«Camilo autoriza un 5 % en esta venta», sin tocar el grupo ni `custom_price` y sin duplicar
totales. `CartRule` en 2.4.9 ofrece: `action_type` (`by_percent`, `by_fixed`, `cart_fixed`,
`buy_x_get_y`), cupones (`coupon_type`, `use_auto_generation`, `uses_per_coupon`), filtro por
grupos y canales, `end_other_rules`, `sort_order`. Se aplica en `collectTotals`
(`applyCartRules`) y el pedido conserva `coupon_code` y `applied_cart_rule_ids`.

| Opción | Cómo | Ventajas | Riesgos |
|---|---|---|---|
| **A. Regla con cupones autogenerados de un solo uso** (una por porcentaje autorizado: 5 %, 10 %…) | El módulo emite un cupón de la regla «Descuento comercial N %» y lo aplica con `Cart::setCouponCode` | 100 % nativo: descuento, impuestos y totales del core; auditable por cupón y pedido | Porcentajes fijos por regla; convive con otras reglas (prioridad, `end_other_rules`); la regla debe incluir todos los grupos comerciales |
| B. Una regla por venta | Se crea una `CartRule` con cupón para esa venta | Porcentaje libre | Llena la tabla de reglas y hay que limpiarlas |
| C. Descuento propio sobre totales | Listener que modifica `discount_amount` | — | **Descartado:** duplica la lógica de totales e impuestos |
| D. `custom_price` | — | — | **Prohibido** (D-18) |

**Recomendación técnica:** A, con autorización y motivo registrados en el módulo. Requiere
decisión de Camilo (porcentajes permitidos y quién autoriza). *(SUPERADO por D-38 y §24.3: el límite lo fija el margen mínimo, no un porcentaje; CartRule queda como mecanismo de aplicación, pendiente de la fuente de costo.)*

### 23.11 Pruebas (03B)

| Archivo | Casos |
|---|---|
| `tests/Feature/Fase03bCatalogoVentaTest.php` | 10: variantes reales, variante de otro padre rechazada, hijo como producto rechazado, resolución por opciones, stock (inventario − reservas), precio por grupo con RT-03, invitado o sin grupo rechazado, solo lectura, búsqueda sin variantes, desglose 100/30 |
| `tests/Feature/Fase03bBorradorVentaTest.php` | 15: crear (carrito real inactivo, aislado de la tienda), permisos y grupo, idempotencia (misma referencia, por mecanismo, otro cliente, UNIQUE ante carrera), listar, cambio de cliente con repreciado, invitado rechazado, grupo actual, descartar, «confirmando» no editable, propiedad y reclamo con día hábil, calendario con festivo, P-09, sin pedidos ni reservas |
| `tests/helpersFase03b.php` | Helpers |

Pruebas existentes ajustadas de forma explícita (6 migraciones; FK `bvabv_cart_fk` SET NULL y
`bvabv_order_fk` RESTRICT): `IntegridadAtribucionTest` (22) y `CierreAuditado02A2Test` (12).

### 23.12 Deuda y pendiente

- **Producción** (§23.3): contrato de CorteConfeccion pendiente. Hasta entonces, `enProduccion`
  es null.
- **Calendario:** confirmar días laborales y cargar los festivos.
- **03C:** líneas del borrador (agregar, cambiar y quitar con el catálogo verificado),
  dirección, envío y pago validado.
- **Etapa posterior:** confirmación (candado `confirmando`, P-09, grupo apto, `hasError`,
  idempotencia, atribución explícita, `OrderRepository`).
- **Carritos abandonados:** política de limpieza.
- **Borradores:** los endpoints del panel pueden editar cualquier carrito, también los del
  módulo (§22.14).
- **Descuento comercial** (§23.10): decisión.

---

## 24. Cierre de 03B (2026-09-26)

**Estado:** 03A **CERRADA** y 03B **CERRADA** (revisadas y aprobadas por Camilo Gaitán el
2026-09-26; un solo commit). 03C **PENDIENTE / NO INICIADA**. Decisiones nuevas: **D-35 a D-38**
en el Doc. 04, confirmadas como identificadores definitivos (Camilo las había numerado D-20 a
D-23, ya usados; la equivalencia está en el Doc. 04). La migración 000006 sigue aplicada solo en
`lpz_saas_testing`; pendiente para producción.

### 24.1 D-35: calendario de lunes a sábado (SUPERA el valor por defecto de §23.7)

`dias_laborales = [1, 2, 3, 4, 5, 6]`; festivos configurables y vacíos. Probado:

- viernes 10:00 → sábado 10:00;
- sábado 10:00 → lunes 10:00 (el domingo no es laboral);
- desde un domingo cuenta desde el inicio del lunes;
- un festivo configurado se salta.

La prueba de propiedad y reclamo se actualizó a este calendario (creado el viernes, reclamable el
sábado; al reclamar el sábado, nueva ventana hasta el lunes).

### 24.2 D-36 y D-37: desglose de abastecimiento definitivo (SUPERA los campos de §23.4)

`DesgloseAbastecimiento{solicitado, disponibleInmediato, faltanteInmediato, enProduccion, porFabricar}`:

- **Invariante I-12** (D-36): `disponibleInmediato = min(solicitado, stock vendible)` y
  `faltanteInmediato = solicitado − disponibleInmediato`. **Nada** de producción entra jamás en
  esas cifras. Si la variante no gestiona stock (sin `manage_stock`), Bagisto la trata como
  siempre vendible y cubre lo solicitado.
- **Informativo** (D-37): `conProduccionInformativa(?int)` añade `enProduccion` y
  `porFabricar = max(0, faltanteInmediato − enProduccion)` **sin tocar** las cifras inmediatas.
  Con producción desconocida, ambos son `null`; nunca 0 sin evidencia.
- **Probado:** 100 pedidos con 30 vendibles y 50 en confección dan 30 de entrega inmediata, 70 de
  faltante inmediato, 50 en producción y 20 por fabricar, y `cubreEntregaInmediata() = false`.
  Hoy el catálogo devuelve `enProduccion = null` porque la fuente no está integrada (§24.4).
- Texto que podrá mostrar la UI: «30 disponibles para entrega inmediata; 50 adicionales en
  confección; 20 tendrían que fabricarse». Sin fechas mientras no haya fuente fiable.

### 24.3 Auditoría de CartRule y cupones (D-38; sin implementar)

| Aspecto | Evidencia (2.4.9) |
|---|---|
| Porcentaje o monto | Vive en la **regla** (`cart_rules.discount_amount`, con `action_type` = `by_percent`, `by_fixed`, `cart_fixed` o `buy_x_get_y`). **El cupón no lleva importe** |
| Cupón | `cart_rule_coupons`: `code`, `usage_limit`, `usage_per_customer`, `times_used`, `expired_at`, `is_primary`. `CartRuleCouponRepository::generateCoupons()` genera N códigos para **una** regla, con los límites de la regla |
| Uso y concurrencia | `CartRule\Listeners\Order::manageCartRule` (en `checkout.order.save.after`) bloquea la regla, el cliente y el cupón (`lockForUpdate`) e incrementa los usos. Si se supera un límite lanza `CouponUsageLimitExceededException`, que `OrderRepository` **no reintenta** |
| Aplicación | `applyCartRules` en `collectTotals` filtra por canal y grupo (`cart_rule_customer_groups`); `end_other_rules` y `sort_order` controlan cómo se combinan |
| Trazabilidad | El pedido guarda `coupon_code`, `applied_cart_rule_ids` y el descuento por ítem (`discount_amount`, `discount_percent`) |

**Respuestas:**

- **¿Una regla reutilizable con cupones por venta? Sí, pero con porcentaje FIJO por regla.** Se
  pueden generar cupones de un solo uso para cada venta de una regla ya existente.
- **¿Porcentajes dinámicos? Obligan a crear reglas:** una por tramo (1 %, 2 %…, con el máximo
  fijado por el margen) o una por venta (crecimiento de `cart_rules`, `cart_rule_channels`,
  `cart_rule_customer_groups` y `cart_rule_coupons`, con limpieza).
- **Riesgos:**
  - un código válido también podría canjearse en la **tienda**: mitigarlo con códigos largos
    aleatorios, un solo uso y caducidad corta;
  - se combina con otras promociones activas: el control de margen debe evaluarse sobre el
    resultado final que calcula Bagisto (con todas las reglas), no sobre el porcentaje pedido;
  - la regla debe incluir todos los grupos comerciales.
- **Conclusión:** CartRule sirve como **mecanismo de aplicación** (Bagisto calcula descuento,
  impuestos y totales, y deja rastro en el pedido). El **límite** lo decide el módulo con la
  política de margen (D-38): tramos de porcentaje con reglas fijas, más una autorización propia
  del módulo por venta (quién, cuánto, margen resultante). No se aprueba como definitivo hasta
  tener la fuente de costo.

### 24.4 Qué falta para calcular un descuento máximo seguro

1. **Fuente de costo autoritativa** por variante (`costo_base_para_precio_minimo`). Candidatos a
   evaluar, sin usarlos todavía: el atributo `cost` de Bagisto (hay que demostrar que se mantiene
   y qué incluye) o un costo de producción de CorteConfeccion (tela, corte y confección).
2. **Política de margen mínimo**: valor, alcance (global, categoría o producto) y responsable.
3. **Qué precio se compara**: el unitario final de Bagisto tras todas las reglas, con o sin
   impuestos.

El diseño deja preparado un cálculo puro: precio mínimo = f(costo, margen) y descuento máximo =
f(precio de Bagisto, precio mínimo). Las futuras finanzas (utilidad final) serán una capa aparte,
sin mezclarse con este umbral.

### 24.5 Contrato futuro con CorteConfeccion (sin cambios en ese módulo)

Se mantiene la conclusión de §23.3: VentasAsesor consumirá un contrato de **solo lectura** que
defina el dueño de producción (unidades en confección y pendientes de recibir por variante, sin
contar dos veces lo que ya llegó a bodega). Nombre y forma los define ese módulo. Hasta entonces:
`enProduccion = null`.

### 24.6 Aclaración de los conteos de carritos (SELECT en `lpz_saas`; precisa §22.5)

| Universo | Cantidad |
|---|---|
| `cart` total | **89** (la cifra de 03B) |
| Activos | 12: 1 con cliente y 11 de invitado |
| Inactivos | **77**, todos con cliente (la cifra de 03A) |
| Inactivos con líneas reales (`cart_items`) | 27 |
| Inactivos sin líneas y `items_count` NULL (nunca se agregó nada) | 31 |
| Inactivos enlazados a un pedido (`orders.cart_id`) | 8 |

Rango de fechas de los inactivos: 2025-10-04 a 2026-09-20.

**Corrección de §22.5:** no todos los 77 son borradores abandonados del panel.

- **Origen de los 8:** son carritos ya convertidos. El checkout de la tienda **desactiva** el
  carrito (`Shop/…/API/OnepageController.php:192`, `Cart::deActivateCart()`), mientras que el
  panel lo **borra** (`OrderController::store`, `removeCart`).
- **Origen indeterminable:** la tabla `cart` no guarda de dónde viene un carrito, así que no se
  puede distinguir uno del panel de uno de la tienda. La política de limpieza futura debe
  excluir los enlazados a pedidos.

---

## 25. Fase 03C: líneas del borrador (2026-09-26)

**Estado:** 03C **IMPLEMENTADA con stock suficiente**, pendiente de revisión de Camilo Gaitán y sin
commit. El caso en que lo solicitado supera el stock queda **parcialmente bloqueado** por la
decisión **D-39** (§25.8). No hay migración nueva, ni UI, ni pedido, dirección, envío o pago.

### 25.1 Auditoría dirigida del Cart de Bagisto 2.4.9

| Clase::método | Efecto real (verificado en código y con pruebas) |
|---|---|
| `Checkout\Cart::addProduct($producto, $datos)` | Llama a `TypeInstance::prepareForCart()`; con `getItemByProduct()` localiza una línea existente y la **actualiza**, o crea una nueva. Después ejecuta `collectTotals()`. **Si `prepareForCart` devuelve un texto de error y el carrito está vacío, llama a `removeCart()` (BORRA el carrito) antes de lanzar `Exception`** |
| `Product\Type\Configurable::prepareForCart` | Exige `selected_configurable_option`. Suma la cantidad ya presente en el carrito (`getQtyRequest`) y, si la variante no tiene stock para ese **total**, lanza `InsufficientProductInventoryException` antes de escribir. Devuelve dos filas: la del configurable (precio, totales, `additional` con los atributos) y la fila interna de la variante (`parent_id`). **No comprueba que la variante sea hija del configurable** |
| `Product\Type\Simple::prepareForCart` | Igual con una fila. Si hay opciones personalizables obligatorias y no se envían, devuelve texto (el caso que borra el carrito vacío) |
| `…::compareOptions($opciones1, $opciones2)` | Exige `product_id` en los datos enviados. Sin él falla en cuanto el carrito tiene líneas. El configurable compara `selected_configurable_option`; el simple, las opciones personalizables |
| `Simple::haveSufficientQuantity` | `manage_stock = 0` equivale a siempre vendible. Si no, `qty <= índice de inventario del canal`; si no alcanza, depende de `catalog.inventory.stock_options.back_orders` (0 en `lpz_saas`, sin valor en testing, desactivado en ambos) |
| `Checkout\Cart::updateItems(['qty' => [id => n]])` | Si `n <= 0`, quita la línea y lanza excepción. Con stock insuficiente lanza `Exception` genérica **sin escribir**. Si no, escribe cantidad y totales y llama a `collectTotals()`. **Omite en silencio** un item cuyo `cart_id` no es el del carrito actual |
| `Checkout\Cart::removeItem($id)` | Solo si el id está entre las líneas de primer nivel del carrito. Borra las tarifas de envío y la línea; la fila de la variante cae en cascada (FK `cart_items.parent_id` con CASCADE). **No** recalcula |
| `Checkout\Cart::collectTotals()` | `validateItems()`: **borra un carrito sin líneas**, quita las inactivas y reprecia cada línea con `getFinalPrice($qty)`, respetando `custom_price`. Luego aplica las reglas de carrito (listener de CartRule), impuestos y totales, y guarda |
| `Checkout\Cart::isItemHaveQuantity($item)` | El predicado de stock que usa `updateItems` (en el configurable, el stock de la variante) |

**Controladores del core (referencia, no reutilizados):** `Admin\Sales\CartController` hace
`removeItem` seguido de `collectTotals`, lo que borraría el carrito del borrador al quitar la
última línea. No hay una abstracción intermedia reutilizable: se usan directamente los métodos
del facade `Cart`, envueltos en las garantías del módulo.

### 25.2 `LineasBorradorService`

Métodos:

- `listar(borradorId)`
- `agregar(borradorId, productoId, varianteId, cantidad, actor)`
- `cambiarCantidad(borradorId, cartItemId, cantidad, actor)`
- `eliminar(borradorId, cartItemId, actor)`

Todos devuelven `VistaLineasBorrador`. Solo trabaja con líneas: nada de cliente, envío, pago,
confirmación, producción ni costos.

**Reutilización:**

- `BorradorVentaService::bloquearParaEditar()` es el único método nuevo en 03B. Exige permiso,
  estado editable y propietario, y bloquea borrador y carrito.
- `CatalogoVentaService::variante()` aporta la verificación I-5, el grupo apto y el stock.
- `ContextoPrecioComercial::durante()` aporta RT-03.
- `DesgloseAbastecimiento` aporta I-12 y D-37.

### 25.3 Configurables, simples e identidad de la línea

- **Configurable:** la variante se verifica contra su padre **antes** de llamar a Bagisto.
  Después se llama `Cart::addProduct(padre, {product_id, selected_configurable_option,
  quantity})`. No hace falta `super_attribute`.
- **Simple sin padre:** `varianteId = productoId`. Un hijo de configurable enviado como simple se
  rechaza con `producto_no_vendible`; una variante inactiva, también.
- **Identidad:** es la de Bagisto, sin clave paralela. La misma variante se consolida en **una**
  línea con la cantidad sumada (3 + 2 = 5), y otra variante crea otra línea. La «línea» es la
  fila de primer nivel; la fila interna de la variante nunca se opera directamente.

### 25.4 Cantidad, precio y `custom_price`

- **Cantidad:** solo enteros ≥ 1 o su texto decimal exacto (`"4"`). Se rechazan 0, negativos,
  decimales (incluido `2.0`), espacios, ceros a la izquierda, booleanos y null. El tope técnico
  `1_000_000` responde a `INT UNSIGNED`, no a una regla comercial. Bagisto sigue validando stock
  y producto.
- **Precio:** toda operación que reprecia corre en `durante(cart, cliente del carrito)`, después
  de validar el grupo **actual** del cliente (`durante` comprueba la pertenencia del carrito, pero
  sin grupo caería a invitado). No se acepta ningún precio de entrada.
- **Probado:**
  - dos grupos dan dos precios;
  - si el cliente cambia de A a B, recalcular y cambiar cantidad dan el precio de B;
  - la sesión de la tienda de otro cliente no contamina el precio;
  - un cliente que pasa a invitado no puede operar.
- **`custom_price`:** sigue NULL tras agregar, cambiar cantidad, recalcular y cambiar de cliente
  (D-18). Ningún flujo de Bagisto lo escribe en estas operaciones.

### 25.5 Stock (D-36, D-37)

- **Cifras de cada línea:** `stockVendible` es el índice del canal, igual que en el catálogo, y
  `abastecimiento` es `DesgloseAbastecimiento::desdeStock(cantidad, stock actual)`. Si el stock
  baja después de agregar (por otra venta), la línea muestra `faltanteInmediato > 0` y
  `cubreEntregaInmediata() = false`.
- **Producción:** `enProduccion` y `porFabricar` son siempre `null` en 03C; no hay contrato con
  CorteConfeccion ni se leen sus tablas.

### 25.6 Carrito vacío (caso obligatorio)

- **Al quitar la última línea:** se llama `Cart::removeItem` y **no** `collectTotals`. Los totales
  agregados del carrito vacío se ponen a cero (no hay nada que sumar). El borrador sigue activo con
  el mismo `cart_id` y se puede volver a agregar.
- **No se tocan:** `coupon_code` ni `applied_cart_rule_ids`. El siguiente `collectTotals` los
  recalcula (D-38 sigue pendiente).
- **Rechazo del primer producto:** si Bagisto rechaza el primer producto y borra el carrito vacío,
  la transacción del servicio **revierte** el borrado. Está probado con una opción personalizable
  obligatoria.

### 25.7 Errores de dominio

- **Reutilizados:**
  - `VarianteInvalidaException`: producto, variante, pertenencia al padre, cantidad;
  - `BorradorVentaInvalidoException`: inexistente, no editable, no propietario, sin permiso,
    cliente;
  - `GrupoComercialInvalidoException`;
  - `ContextoPrecioComercialInvalidoException`.
- **Nuevo:** `LineaBorradorInvalidaException`, con tres códigos:
  - `linea_no_pertenece_al_borrador`: item de otro carrito o fila interna de una variante;
  - `stock_insuficiente`: trae `abastecimiento` con el desglose de lo pedido;
  - `rechazada_por_carrito`: la `Exception` genérica de Bagisto, como causa previa.
- **Datos personales:** ningún mensaje contiene PII.

### 25.8 Lo solicitado supera el stock: comportamiento real y decisión D-39 (BLOQUEO PARCIAL)

*(RESUELTO por §26: Camilo Gaitán aprobó la alternativa 4, la solicitud comercial fuera del Cart y sin aceptación automática de parciales. El comportamiento real de Bagisto descrito aquí sigue vigente.)*

- **Comportamiento real de Bagisto:** opción **A, rechaza la línea completa**. No recorta la
  cantidad ni la guarda marcando un error. Sin backorders, **un Cart no puede contener más
  unidades que el stock vendible**. Aplica tanto al agregar (sobre el total consolidado) como al
  cambiar la cantidad.
- **Qué hace hoy 03C:** lanza `stock_insuficiente` con el desglose, por ejemplo
  `{solicitado 100, disponibleInmediato 30, faltanteInmediato 70, enProduccion null,
  porFabricar null}`, y no escribe nada. La UI o la IA podrán ofrecer las 30 disponibles.
- **Qué se pierde:** el compromiso comercial de que el cliente pidió 100. Hoy no queda registrado
  en ninguna parte.
- **Alternativas:**
  1. **No persistir**, como ahora: el faltante vive solo en la conversación. Es simple, pero pierde
     el dato que Camilo considera importante (§18 del prompt de 03C).
  2. **Activar backorders:** prohibido, porque es una configuración global y mentiría a Bagisto
     sobre el stock.
  3. **Guardar la cantidad solicitada en `cart_items.additional`:** `addProduct` **reemplaza**
     `additional` en cada consolidación y la copia al pedido. Es frágil, depende de detalles
     internos del core y no se puede consultar. No se recomienda.
  4. **Tabla propia mínima (recomendada):** `bylopez_va_borrador_solicitudes` con
     `borrador_id` (FK CASCADE), `producto_id`, `variante_id`, `cantidad_solicitada`,
     timestamps y UNIQUE(`borrador_id`, `variante_id`).
     - El Cart guarda solo lo materializable: `min(solicitado, vendible)`.
     - El desglose de la línea usa `solicitado` de esta tabla, `disponibleInmediato` del stock y
       `faltanteInmediato = solicitado − disponible`.
     - `enProduccion` y `porFabricar` se completarán con el contrato de CorteConfeccion.
     - La confirmación futura (03D o posterior) decide qué hacer con el faltante: pedido parcial
       más pedido especial, o esperar.
- **Decisiones que necesita Camilo (D-39):**
  1. ¿Se persiste la cantidad solicitada aparte del Cart (alternativa 4)?
  2. Al agregar más que el stock, ¿el sistema pone automáticamente lo disponible en el carrito y
     registra el faltante, o rechaza y deja decidir al asesor?

  Hasta entonces, 03C solo materializa cantidades con stock suficiente.

### 25.9 Concurrencia

- **Bloqueos:** cada mutación ocurre en una transacción que bloquea (`FOR UPDATE`) primero el
  borrador y después el carrito, en el mismo orden que 03B, así que no hay interbloqueos entre
  ellas. Dos peticiones sobre el mismo borrador se serializan y un fallo revierte todo. Está
  probado el orden de bloqueos antes del primer `INSERT` en `cart_items`.
- **Qué no se bloquea:** no se bloquea el `cart_item` (el bloqueo del carrito ya serializa) ni el
  stock. Bagisto solo reserva stock al crear el pedido (`product_ordered_inventories`), así que
  entre agregar y confirmar otra venta puede consumirlo. La línea lo mostrará como faltante y la
  confirmación deberá revalidar.
- **Riesgo residual:** las rutas del panel de Bagisto (`admin.sales.cart.*`) pueden modificar un
  carrito por id sin pasar por este bloqueo. La pantalla Nueva Venta no las usará.

### 25.10 Pruebas

`Fase03cLineasBorradorTest`: 48 casos (196 aserciones), agrupados así:

- **A:** configurable, variante ajena, hijo como simple, simple, 15 cantidades inválidas, texto
  exacto, consolidación;
- **B:** dos grupos, cambio de grupo, sesión de la tienda, `custom_price` en 4 operaciones,
  invitado;
- **C:** stock suficiente, rechazo real de más que el stock con el carrito vivo, total
  consolidado, stock que baja, borrado del carrito revertido;
- **D:** cambiar cantidad, item ajeno o fila interna, cantidad inválida, revalidación de stock;
- **E:** eliminar, item ajeno, **última línea**;
- **F:** otro asesor, reclamo, sin permiso, 3 estados no editables, P-09;
- **G:** orden de bloqueos, agregados repetidos y descarte, contrato plano con totales de
  Bagisto.

**Regresión (2026-09-26):** VentasAsesor 503 pasadas (2061 aserciones = 455 + 48 casos y 1865 + 196 aserciones), CorteConfeccion 384 (2100) y Pint sin errores. El core de Bagisto no se volvió a ejecutar: 03C no registra bindings nuevos, y el único cambio en 03B es un método público nuevo en `BorradorVentaService`.

---

## 26. D-39: solicitud comercial frente a Cart (2026-09-26)

**Estado:** D-39 **IMPLEMENTADA**, pendiente de revisión de Camilo Gaitán y sin commit. Con esto,
03C queda completa. Supera el bloqueo parcial de §25.8.

### 26.1 Invariantes

- **I-13, solicitud frente a carrito:**
  - **Solicitud comercial** = lo que el cliente **quiere** (`bylopez_va_borrador_solicitudes`).
  - **Cart de Bagisto** = lo que hoy se puede **procesar** bajo sus reglas.
  - **Ninguna de las dos reserva stock:** Bagisto solo reserva al crear el pedido.
  - **El carrito solo materializa lo solicitado:** cada unidad del carrito tiene la solicitud de
    su variante, y el total del carrito por variante ≤ cantidad solicitada.
- **Sin aceptación automática de parciales:** crear o cambiar una solicitud **nunca** toca el
  Cart. Llevar unidades al carrito es una acción explícita del asesor
  (`LineasBorradorService::agregar`).

### 26.2 Tabla `bylopez_va_borrador_solicitudes` (migración 000007, solo en testing)

| Columna o restricción | Justificación |
|---|---|
| `borrador_id` (FK a borradores, CASCADE) | La solicitud es parte del borrador. El borrador nunca se borra (se descarta), así que las solicitudes **sobreviven al carrito** como rastro de lo pedido (probado al descartar) |
| `variante_id` (FK a `products`, CASCADE) | La variante concreta: hijo del configurable o simple sin padre. CASCADE, como `cart_items`: sin la variante no hay nada que vender. RESTRICT impediría borrar productos desde el catálogo de Bagisto (probado) |
| `cantidad_solicitada` (INT UNSIGNED) | El único dato comercial nuevo |
| UNIQUE(`borrador_id`, `variante_id`) | Ver §26.3 |
| timestamps | Trazabilidad mínima |

**Qué NO lleva, y por qué:**

- **Producto padre:** se deriva de `products.parent_id`, y el simple es su propio padre.
  Guardarlo duplicaría el dato y podría contradecir al catálogo.
- **`cart_item_id`:** Bagisto consolida una línea por variante, borra y recrea ítems y borra el
  carrito al confirmar. La solicitud puede existir sin ítem. Lo «en carrito» se calcula en
  tiempo real por (carrito, variante) con `LineasBorradorService::cantidadEnCarrito()`.
- **Snapshots de SKU, nombre o precio:** el borrador no es histórico. SKU, nombre, estado, stock
  y precio se leen en tiempo real (probado con un SKU cambiado y una variante desactivada). El
  histórico lo guardará el pedido.
- **Precio, `custom_price` o autorizaciones:** la solicitud no es una fuente de precio (D-18).
- **`external_reference`:** ver §26.8.

### 26.3 Identidad: UNIQUE(borrador, variante)

Una intención agregada por variante, por tres motivos verificados:

1. **Consolidación de Bagisto:** el Cart consolida una línea por variante (§25.3); una segunda
   solicitud de la misma variante no tendría una línea propia a la que mapearse.
2. **Reparto por origen:** el futuro compromiso comercial documentado (§23.4) reparte **una**
   línea por origen (stock, producción, fabricar): colgará de esta fila, no la multiplicará.
3. **Documentación existente:** no hay ningún requisito escrito de dos intenciones para la misma
   variante (distintas entregas o fulfillment separado).

Si aparece ese requisito, se añadirá una tabla hija de asignaciones sin romper esta identidad.

### 26.4 Responsabilidades

- **`SolicitudBorradorService` (intención):**
  - `listar`;
  - `crear`: rechaza duplicados con `solicitud_ya_existe`;
  - `cambiarCantidad`: absoluta, y nunca por debajo de lo que ya está en el carrito
    (`solicitud_menor_que_carrito`);
  - `eliminar`: `solicitud_en_carrito` si quedan unidades en el carrito.
  
  En todas: borrador activo, propietario, variante verificada por el catálogo (I-5), cliente con
  grupo comercial apto y cantidad entera ≥ 1.
- **Cambiar de variante:** no es una operación propia. Otra variante es otra identidad, así que
  se hace eliminando y creando, sin estados intermedios ambiguos.
- **`LineasBorradorService` (materialización):**
  - `agregar`, `cambiarCantidad` y `eliminar` sobre el Cart;
  - `agregar` y `cambiarCantidad` exigen I-13 (códigos `linea_sin_solicitud` y
    `linea_excede_solicitud`);
  - retirar del carrito siempre está permitido.
- **Cantidad compartida:** la validación vive en `Support\Venta\CantidadVenta`, sin duplicarse.

### 26.5 Vista de la solicitud (`SolicitudBorradorVenta`)

| Campo | Semántica |
|---|---|
| `cantidadSolicitada` | Lo que quiere el cliente (persistido) |
| `cantidadEnCarrito` | Unidades hoy en el Cart de Bagisto. **No** es una reserva ni una garantía; la confirmación revalidará |
| `stockVendible` | Índice de inventario del canal (0 si la variante no es vendible hoy) |
| `abastecimiento` | Desglose de lo **solicitado** contra el stock vendible actual (I-12, D-36). **No** depende de lo que haya en el carrito. `enProduccion` y `porFabricar` son null (D-37) |
| `excesoCarritoSobreStock` | Unidades del carrito que el stock actual ya no cubre |
| `vendible` | La variante y su padre siguen activos y verificados |
| `precioUnitarioReferencia` | Precio de Bagisto para el grupo **actual** del cliente (RT-03) y la cantidad solicitada. Informativo, **no se guarda** y es null si la variante no es vendible o el grupo no es apto |

**Totales:** `VistaSolicitudesBorrador` solo lleva los totales **reales** del Cart. No existe
«total solicitado» ni ninguna cifra financiera paralela a Bagisto.

### 26.6 Casos canónicos (probados)

| Situación | Solicitado | En carrito | Vendible | Disponible inmediato | Faltante inmediato | Producción | Por fabricar | Exceso |
|---|---|---|---|---|---|---|---|---|
| Pide 100 con 30 vendibles | 100 | 0 | 30 | 30 | 70 | null | null | 0 |
| El asesor lleva 30 al carrito | 100 | 30 | 30 | 30 | 70 | null | null | 0 |
| El stock baja a 20 después | 100 | 30 | 20 | 20 | 80 | null | null | 10 |

**Qué no se hace:**

- en el tercer caso **no se toca** ni el carrito ni el inventario: la vista informa y la
  confirmación revalidará;
- llevar al carrito más de 30 lo rechaza Bagisto (`stock_insuficiente`), aunque quepa en la
  solicitud.

### 26.7 Concurrencia

- **Un solo punto de bloqueo:** toda mutación de solicitudes, líneas, reclamo o descarte bloquea
  primero la **fila del borrador** (`FOR UPDATE`, probado). Dos altas simultáneas, dos cambios de
  cantidad, una materialización simultánea, un reclamo o un cambio de estado se serializan.
- **Carrera de dos altas:** si llegaran a la vez, el UNIQUE la resuelve: la segunda recibe
  `solicitud_ya_existe`.
- **I-13 dentro del mismo bloqueo:** se comprueba con la solicitud leída bajo el bloqueo del
  borrador, así que no hay una ventana entre leer lo solicitado y escribir en el carrito.

### 26.8 Idempotencia

- **Sin `external_reference` propia en esta etapa:** la idempotencia del borrador (mecanismo +
  referencia) ubica el borrador, y las operaciones de solicitud son idempotentes por diseño:
  - un segundo `crear` de la misma variante no duplica ni suma (`solicitud_ya_existe`);
  - `cambiarCantidad` fija el valor, así que repetirlo no cambia nada.
- **Mensajes duplicados de WhatsApp:** un «quiero 6» reenviado no se convierte en 12.
- **Cuándo añadirla:** si los canales externos necesitan distinguir dos pedidos iguales
  legítimos, se añadirá con la API, no antes.

### 26.9 Pruebas

- **`Fase03cSolicitudBorradorTest`:** 28 casos, que cubren:
  - caso canónico, carrito vacío intacto, materialización explícita, más que el stock, I-13;
  - stock que baja;
  - variante ajena e hijo como simple, 6 cantidades inválidas;
  - cambio absoluto y mínimo del carrito, eliminar con y sin carrito, solicitud de otro borrador;
  - UNIQUE en servicio y en base de datos, borrador ajeno y reclamo, 3 estados no editables,
    descarte con rastro, invitado;
  - precio de referencia que sigue al grupo y no se guarda, RT-03 y D-18 con materialización;
  - catálogo en tiempo real, cascada al borrar el producto, orden de bloqueos.
- **`Fase03cLineasBorradorTest`:** sus 48 casos se adaptaron a I-13 (cada línea parte de una
  solicitud amplia, para que el stock lo decida Bagisto). Siguen en verde.
- **Inventarios de esquema:** se actualizaron (7 migraciones y 9 FK).

**Regresión D-39 (2026-09-26):** VentasAsesor 531 pasadas (2155 aserciones; 503 + 28 casos), CorteConfeccion 384 (2100) y Pint sin errores. El core de Bagisto no se volvió a ejecutar: no hay bindings nuevos ni cambios en su comportamiento.

---

## 27. Cierre formal de 03C (2026-09-26)

**Estado:** 03C **CERRADA** (líneas y D-39), revisada y aprobada por Camilo Gaitán. **03D:
pendiente, no iniciada.**

### 27.1 Reglas confirmadas

- **Stock al operar:** al incorporar o modificar unidades en el Cart, `cantidad en carrito ≤
  stock vendible actual de Bagisto` **y** `cantidad en carrito ≤ cantidad solicitada` (I-13).
  Si se supera el stock, se **rechaza** (`stock_insuficiente`) informando lo disponible
  (`abastecimiento.disponibleInmediato`).
- **Qué no hace el sistema:** no recorta en silencio, no agrega automáticamente el máximo, no
  activa backorders, no crea stock y no suma producción.
- **Solicitud frente a Cart:** son conceptos distintos y **ninguno es una reserva**. El stock
  puede cambiar después; la confirmación deberá revalidarlo.
- **CASCADE en `bvabs_variante_fk`:** aprobado **solo para solicitudes de borrador**, que no son
  el histórico de una venta. No se extrapola a pedidos: el histórico de un pedido confirmado lo
  preservarán el Order de Bagisto, sus snapshots y la futura confirmación.
- **Producción:** stock vendible ≠ producción ≠ por fabricar. `enProduccion` y `porFabricar`
  quedan en null hasta que exista el contrato con CorteConfeccion.
- **Precio:** grupo actual del cliente → RT-03 → Bagisto. D-38 sigue pendiente y no bloquea 03C.

### 27.2 Contexto para 03D (solo documentado, NO diseñado ni implementado)

- **Primero reutilizar Bagisto:** antes de crear nada propio, auditar y reutilizar lo que ya
  tiene (direcciones, `CartAddress`, métodos de envío, tarifas, carriers, checkout de envío) y
  sus módulos de transportadoras como referencia.
- **Necesidades de ByLopez:**
  - transportadora nacional (Inter Rapidísimo, Servientrega…), con la guía inmediata o
    pendiente;
  - agregador (p. ej. 99envíos);
  - domiciliario local de Bogotá, sin guía oficial pero con trazabilidad interna del despacho;
  - recogida en bodega, sin transportadora ni guía.
- **Distinción conceptual:** método de entrega ≠ transportadora ≠ despacho ≠ guía/tracking.

**Regresión del cierre:** pruebas de 03C 77 pasadas (293 aserciones), VentasAsesor 532 (2166), CorteConfeccion 384 (2100) y Pint sin errores. Frente a la referencia (531 / 2155), la diferencia es la prueba de cierre «carrito ≤ stock vendible al operar» (+1 caso, +11 aserciones). El core de Bagisto no se volvió a ejecutar: no hay bindings nuevos ni cambios en su comportamiento.

---

## 28. Auditoría 03D: dirección, entrega, envío y logística (2026-09-26)

**Solo auditoría:** Camilo Gaitán todavía no ha decidido y no se ha escrito código, migraciones ni
configuración. Evidencia del código instalado (Bagisto 2.4.9) y de `lpz_saas` (solo SELECT,
conteos agregados, sin datos personales).

### 28.1 Direcciones

| Tema | Evidencia |
|---|---|
| Tabla | Una sola tabla, `addresses`, con `address_type` = `customer`, `cart_billing`, `cart_shipping`, `order_billing` u `order_shipping` |
| Obligatorio en base de datos | `first_name`, `last_name`, `address`, `city`. **`email`, `phone`, `postcode`, `state` y `country` admiten NULL** |
| Obligatorio en HTTP | El `CartAddressRequest` del panel y de la tienda exige `email`, `postcode` (PostCode), `phone` (PhoneNumber), `state` y `country`. Es validación de formulario, no del dominio |
| Del cliente al carrito | `Cart::saveAddresses()` **copia** los campos al carrito. Si viene `address_type = customer` e `id`, guarda `parent_address_id` (vínculo informativo). Una dirección puntual **no** se guarda en el cliente |
| Facturación y envío | La facturación es obligatoria. La de envío solo existe si el carrito tiene productos con stock (`haveStockableItems`); `use_for_shipping` la copia de la facturación. Guardar direcciones **resetea el método de envío** |
| Del carrito al pedido | `OrderResource` y `OrderAddressResource` copian un **snapshot** completo a `order_billing` y `order_shipping`: el histórico del pedido no depende del cliente |
| Email del pedido | `setCustomerPersonnelDetails()`: `cart.customer_email = customer.email ?? billing.email`. `orders.customer_email` admite NULL, así que un cliente RT-04 sin email ni email en la dirección da un pedido con email NULL. RT-04 ya protege los correos (listeners con destinatario verificado, incluido el de shipment) |
| RT-04 | `GuardarDireccionClienteComercialService` ya guarda direcciones **del cliente** con email opcional, teléfono E.164 y la regla PostCode del core. Falta solo el paso al **carrito**: `Cart::saveAddresses()` directamente (sin el FormRequest que exige email), dentro del bloqueo del borrador |

### 28.2 Envío nativo

- **Motor:** `Shipping::collectRates()` recorre `config('carriers')`. Cada clase hereda de
  `AbstractShipping`, `calculate()` devuelve una o **varias** `CartShippingRate` (carrier, título,
  método, descripción, precio) y se guardan en `cart_shipping_rates` **solo si existe dirección
  de envío**. `Cart::saveShippingMethod(code)` exige que el código esté entre las tarifas y lo
  guarda en `cart.shipping_method`.
- **Disponibilidad:** `isAvailable()` = `sales.carriers.<code>.active`.
- **Tarifa:** la calcula cada clase. Las reglas de carrito pueden descontar el envío
  (`apply_to_shipping`, `free_shipping`).
- **Qué pasa al pedido:** `shipping_method`, `shipping_title` (carrier − método),
  `shipping_description`, `shipping_amount` (con y sin impuesto), `shipping_discount_amount` y la
  dirección de envío.
- **Extensión prevista:** un paquete registra su carrier con `mergeConfigFrom(…, 'carriers')` y
  sus campos del panel con `mergeConfigFrom(…, 'core')` (`sales.carriers.<code>.*`), sin tocar
  el core.
- **Instalado:** solo `flatrate` y `free`. Ningún paquete de envío, recogida o tracking en
  `packages/` ni en `composer.lock`.
- **Activos en `lpz_saas`:**
  - `flatrate`, reutilizado como «Envío por pagar (se cobra al recibir)», con tarifa 0 por
    unidad: el cliente paga el flete a la transportadora;
  - `free`, «Envío gratuito».
  
  Pedidos: 17 con `free` y 7 con `flatrate`; todos tienen `shipping_method`.
- **Pago:** contra entrega (`isAvailable` exige solo `hasOnlyStockableItems`, sin relación con
  el envío) y transferencia.
- **Orden de pasos:** el pedido exige dirección de facturación, dirección de envío y tarifa
  elegida (si hay productos con stock) y pago. El pago no depende técnicamente del envío; el
  panel solo lo pide después.

### 28.3 Recogida en bodega

- **En el código:** no existe; no hay carrier de recogida.
- **Residuo del Store Pickup retirado:** en `lpz_saas` siguen `pickup_orders` (0 filas) y
  `pickup_timeslots` (40 filas), con sus migraciones registradas y **sin código** que las use.
  No se tocan.
- **Restricción de Bagisto:** con productos con stock **siempre** exige dirección de envío y una
  tarifa elegida. La recogida debe ser un **método de entrega**, es decir, una tarifa de un
  carrier. Queda por decidir qué dirección de envío lleva (§28.9).

### 28.4 Shipment (despacho) y tracking

- **Qué hace Shipment:** es el **despacho físico**. `ShipmentRepository::create` crea el shipment
  y sus ítems con las cantidades por bodega, **descuenta inventario** (`updateProductInventory`),
  suma `qty_shipped` y cambia el estado del pedido: `processing`; `completed` si está facturado y
  enviado del todo; `pending_payment` si hay una factura abierta. Admite envíos parciales.
- **Campos:**
  - `carrier_title` es **texto libre**;
  - `track_number` es TEXT **nullable** y el formulario no lo exige;
  - `carrier_code` existe pero **Bagisto nunca lo escribe**;
  - `status` existe pero **Bagisto no lo usa** (sin estados de envío).
- **Guía pendiente:** sí; se puede crear sin guía.
- **Guía posterior:** **no hay ruta, método de repositorio ni pantalla** para editar un
  shipment. Añadir la guía después exige una extensión.
- **Auditoría:** el shipment no guarda quién lo creó. Solo hay eventos
  `sales.shipment.save.before/after` y `order_comments` manuales.
- **Datos reales (22 shipments):**
  - `carrier_title` libre, con nombres de personas (domiciliarios), «BYLOPEZ», «PICAP»,
    «RECOGE EN BODEGA» y un «INTERRAPIDISMO» mal escrito;
  - `carrier_code` y `status` NULL en todos;
  - **21 de 22 con una «guía» de 1 a 3 dígitos**, que no es una guía real. Solo uno tiene 12
    dígitos (Inter Rapidísimo). Es un hábito operativo: el formulario no lo exige.
- **Defecto del core:** `Shop\Listeners\Shipment::afterCreated` hace
  `$shipment->query()->update(['email_sent' => 1])` sin `where`, así que marca **todos** los
  shipments. Explica los 22 con `email_sent = 1`. Solo se reporta.

### 28.5 Modelo conceptual contra Bagisto

| Concepto | Bagisto | Brecha ByLopez |
|---|---|---|
| **Método de entrega** (recogida, domicilio local, envío nacional) | Carrier y método (`shipping_method`, `shipping_title`); estructurado y con tarifa | Definir los métodos propios (hoy `flatrate` y `free` mezclan método con política de cobro del flete) |
| **Transportadora** (Inter, Servientrega, 99envíos, domiciliario, BYLOPEZ) | `shipments.carrier_title` libre y `carrier_code` sin usar | Un **catálogo** con códigos estables que alimente `carrier_code` y `carrier_title` |
| **Despacho** | `Shipment`, con inventario, cantidades y estado del pedido | Ninguna para crearlo. Registrar quién lo hizo |
| **Guía externa** | `track_number` | Poder añadirla o corregirla después, con auditoría |
| **Referencia interna** | `shipments.id`: ya es un identificador único del despacho | Ninguna: **no** hace falta un campo nuevo, y **no** deben ponerse números falsos en `track_number` |
| **Estado de la entrega** (en tránsito, entregado, incidencia) | No existe (`status` sin uso) | Extensión futura |
| **Costo pagado a la transportadora** | No existe | Extensión futura de logística o finanzas; distinto de `shipping_amount` (lo cobrado al cliente) |

- **Estados que se derivan de Bagisto:** «pendiente de despacho» = pedido con `qty_to_ship > 0`;
  «pendiente de guía» = shipment sin `track_number`; «despachado» = shipment existente.
- **No se debe duplicar:** direcciones, tarifas, `shipping_method`, Shipment, inventario, estado
  del pedido ni snapshots de dirección.

### 28.6 Integraciones futuras (sin decidir)

- **Al vender:** una API de cotización (Inter, Servientrega, 99envíos) encaja como carrier de
  Bagisto cuyo `calculate()` consulta un servicio ByLopez (patrón A + B).
- **Al despachar:** pedir la guía encaja como servicio ByLopez que crea o actualiza el Shipment
  (`track_number`, `carrier_code`) al recibir la respuesta.
- **Módulos externos:** no hay ninguno instalado. Si se evalúan, será aparte y como referencia.

### 28.7 Flujo recomendado (mínimo, sobre Bagisto)

| Caso | Al vender (03D) | Al despachar (logística) |
|---|---|---|
| **A. Recogida en bodega** | Método `recogida`, tarifa 0 | Shipment al entregar al cliente: transportadora «recogida», sin guía |
| **B. Domiciliario de Bogotá** | Método `domicilio local`, tarifa según política | Shipment con la transportadora del catálogo (empresa o persona), **sin guía**; referencia = número de despacho |
| **C. Nacional con guía inmediata** | Método `envío nacional` | Shipment con transportadora y `track_number` |
| **D. Nacional con guía posterior** | Igual | Shipment sin guía; la guía se añade después (extensión con auditoría) |
| **E. 99envíos** | Igual; más adelante, cotización por API | Servicio de integración que crea o actualiza el Shipment con transportadora y guía |

### 28.8 Riesgos para 03D

- **Tarifas desactualizadas:** `addProduct` y `updateItems` **no** recalculan las tarifas;
  `removeItem` las borra y deja `shipping_method` sin tarifa. Todo cambio de líneas en el borrador
  deberá **resetear el método de envío**, como ya hace `cambiarCliente`.
- **Guardar direcciones:** en el panel lo bloquea `Cart::hasError()` (stock insuficiente); el
  servicio del borrador debe tratarlo explícitamente. Guardar direcciones resetea el envío.
- **Momento del Shipment:** descuenta inventario, así que solo se crea cuando la mercancía sale
  o se entrega.
- **Calidad del histórico:** la transportadora en texto libre y las guías falsas no se migran
  ahora.

### 28.9 Decisiones pendientes para Camilo (D-40 a D-45, Doc. 04)

1. Catálogo de **métodos de entrega** y qué hacer con `flatrate` y `free`.
2. **Dirección de envío** en la recogida (bodega o dirección del cliente) y exigencia de
   dirección de facturación.
3. **Catálogo de transportadoras** (incluidas personas) y uso de `carrier_code`.
4. **Referencia interna** = número de Shipment, sin campo nuevo.
5. Guía **posterior** y estados de entrega (extensión futura).
6. **Ubicación** de los carriers (VentasAsesor o un paquete ByLopez de logística, porque también
   afectan a la tienda) y **división** de subfases.

---

## 29. Diseño 03D: dirección y método de entrega (2026-09-26)

**Estado:** **DISEÑO**, pendiente de aprobación de Camilo Gaitán. No hay código, carriers,
migraciones ni cambios de configuración. Se apoya en la auditoría de §28; aquí solo se añade la
evidencia nueva.

### 29.1 División de fases (confirmada, sin dependencia técnica que la impida)

| Fase | Contenido | Por qué puede ir sola |
|---|---|---|
| **03D** | Direcciones del borrador y método de entrega (tarifa) | `saveAddresses`, `collectRates` y `saveShippingMethod` operan sobre el Cart, sin pedido |
| **03E** | Pago (contra entrega y transferencia) | `savePaymentMethod` no depende del envío; el contra entrega solo exige `hasOnlyStockableItems` |
| **03F** | Confirmación: revalidar y crear el Order | Solo `validateOrder` y `OrderResource` exigen todo junto |
| **Logística** (fase aparte) | Shipment, transportadora (D-42), guía posterior y estados (D-44), integraciones | `ShipmentRepository` necesita un Order con dirección de envío: siempre ocurre después de 03F |

### 29.2 D-40: métodos de entrega (recomendación)

**Tres carriers de Bagisto, uno por método de entrega.** Cada uno hereda de `AbstractShipping`
(el mismo patrón que `flatrate`):

| Método | Código del carrier | `method` | Tarifa | Configuración del panel |
|---|---|---|---|---|
| A. Recogida en bodega | `recogida` | `recogida_bodega` | 0, fija | `active`, `title`, `description` |
| B. Domicilio local | `domicilio_local` | `domicilio_local` | Pendiente (§29.6) | `active`, `title`, `description`, `default_rate` |
| C. Envío nacional | `envio_nacional` | `envio_nacional` | Configurable; hoy 0 («flete por pagar al recibir») | `active`, `title`, `description`, `default_rate` |

**Por qué tres carriers y no uno con tres tarifas:** la configuración nativa de Bagisto es por
carrier (`sales.carriers.<code>.*`). Así cada método se activa, titula y tarifica desde el panel
sin interfaz propia, y `orders.shipping_method` guarda un código estable por método.

**El envío gratis no es un método, es una política de precio:** se hace con `CartRule`
(`free_shipping` y `apply_to_shipping`, nativos), aplicable a cualquier método. Así no se repite
la mezcla actual de `free`.

**Principio registrado:** método de entrega ≠ transportadora.

- `envio_nacional` → Inter Rapidísimo, Servientrega o lo que elija 99envíos, **al despachar**.
- `domicilio_local` → empresa o persona domiciliaria, al despachar.
- `recogida` → sin transportadora.

### 29.3 `flatrate` y `free` actuales: transición sin romper nada

- **Histórico:** `orders.shipping_method`, `shipping_title` y `shipping_description` son
  **textos snapshot** sin FK, y la dirección está copiada. Los 24 pedidos y 22 shipments quedan
  intactos aunque los carriers se desactiven.
- **Plan de transición:**
  1. activar los carriers nuevos;
  2. comprobar la tienda y el borrador;
  3. **desactivar** (`sales.carriers.flatrate.active = 0` y `free.active = 0`), sin borrar
     configuración ni clases.
  
  Es un cambio de configuración en producción que necesitará autorización expresa.
- **Equivalencias:**
  - `flatrate` («Envío por pagar», 0) ≈ `envio_nacional` con tarifa 0 y descripción de flete
    por pagar;
  - `free` ≈ cualquier método con una `CartRule` de envío gratis.
- **No** se reescriben pedidos antiguos.

### 29.4 D-41: recogida en bodega

- **Limitación técnica de Bagisto (verificada):** con productos con stock, **la dirección de
  envío es obligatoria** en cuatro puntos:
  1. `validateOrder` del panel y de la tienda;
  2. `Shipping::saveAllShippingRates()` no guarda tarifas sin dirección, así que
     `selected_shipping_rate` queda en null;
  3. `OrderResource` copia la dirección de envío al pedido;
  4. `ShipmentRepository::create` usa `$order->shipping_address->id`.
  
  Un pedido de recogida **sin** dirección de envío no es posible sin tocar el core.

| Alternativa | Cart y tarifas | OrderResource y OrderAddress | Shipment e histórico | Semántica | Impacto |
|---|---|---|---|---|---|
| **A. Envío = copia de la facturación del cliente** (`use_for_shipping`) | Compatible | Compatible | Compatible | El pedido muestra como «envío» un lugar al que no va nada; solo el método dice «recogida». Exige que el cliente tenga dirección | Es la **práctica actual**: los 24 pedidos, incluida la recogida registrada, tienen envío = facturación |
| **B. Envío = punto de recogida: la bodega (`inventory_sources`)** con nombre y teléfono de quien recoge | Compatible | Compatible | Compatible, y es la misma bodega de la que saldrá el Shipment | La dirección de envío de Bagisto es el **lugar de entrega**; en la recogida ese lugar es la bodega. Los datos son reales (fuente `BODEGA1`, con campos obligatorios) y **no** se presentan como domicilio del cliente: el método `recogida_bodega` y la empresa «Recogida en bodega» lo marcan | Si mañana hay varias bodegas, se elige la fuente de inventario |
| **C. Sin dirección de envío** | Sin tarifa guardada | `OrderResource` falla | El Shipment falla | — | **Inviable** sin modificar Webkul (prohibido) |
| **D. Entidad propia de «punto de recogida»** | Compatible, con datos duplicados | Compatible | Compatible | Igual que B | **Duplica** `inventory_sources`: no se recomienda |

**Recomendación: B.** Al elegir `recogida_bodega`, el servicio escribe como dirección de envío la
de la fuente de inventario activa, con el nombre y teléfono del cliente o de quien recoge. **No**
toca la dirección de facturación ni las direcciones guardadas del cliente. Si Camilo prefiere
mantener la práctica actual, A también es compatible.

### 29.5 Facturación mínima y dirección puntual

- **Datos que exige la base de datos (NOT NULL):** `first_name`, `last_name`, `address` (≥ 1
  línea), `city`.
- **Datos que exige nuestra operación** (se piden al asesor): nombre, apellido, teléfono de
  contacto, dirección (≥ 1 línea), ciudad, departamento y país.
- **Opcionales:** email (RT-04), empresa y NIT (`vat_id`).
- **Código postal:** la base de datos lo admite NULL y hoy no hay tasas de impuesto en
  `lpz_saas`, así que no afecta al cálculo. **RT-04 lo exige** en las direcciones guardadas.
  Decisión D-46: mantenerlo obligatorio u opcional (1 de los 24 pedidos históricos no lo tiene).
- **Validaciones artificialmente estrictas que no se aplican:** el email obligatorio del
  `CartAddressRequest` (contradice RT-04) y su regla `PhoneNumber` (RT-04 usa E.164).
- **Cliente sin ninguna dirección:** la facturación **siempre** es obligatoria en Bagisto, así
  que hay que pedir al cliente una dirección real. **No se inventa.** Si el cliente no la da, no
  hay pedido (D-46).

**Flujo de dirección (servicio `EntregaBorradorService`, VentasAsesor):**

```text
facturación: guardada del cliente (addressId verificado contra el cliente del carrito)
           | puntual (DireccionComercial de RT-04, validada con las MISMAS reglas)
           [+ opción explícita "guardar en el cliente" → GuardarDireccionClienteComercialService]
envío:       igual que la facturación | otra guardada | otra puntual | punto de recogida (§29.4)
→ Cart::saveAddresses() (copia al carrito; parent_address_id si es guardada)
→ el método de envío se resetea (lo hace Bagisto) → collectRates → métodos disponibles
→ en 03F, OrderResource copia el snapshot a OrderAddress
```

- **Dirección puntual:** **no** se guarda en el cliente salvo que se pida expresamente.
- **Sin duplicar reglas:** las validaciones de `GuardarDireccionClienteComercialService` se
  extraerán a un validador compartido, en lugar de copiarse.
- **Sin el FormRequest del panel:** se llama a `Cart::saveAddresses()` directamente.
- **Bloqueo y precios:** todo corre bajo el bloqueo del borrador y dentro de RT-03 (el
  `collectTotals` posterior reprecia).

### 29.6 Domicilio local y envío nacional

- **Qué pide 03D:** en los dos casos, solo método + dirección de destino + tarifa. Ni
  domiciliario, ni transportadora, ni Shipment, ni guía.
- **Tarifa del domicilio local:** **no hay una regla aprobada.** Alternativas compatibles con
  Bagisto:
  1. fija configurable (`default_rate` en el panel);
  2. 0 «por pagar al domiciliario» con descripción;
  3. gratis con `CartRule`;
  4. por zonas (una tarifa futura en `calculate()`).
  
  Un **valor manual por venta** no es nativo y sería una segunda fuente de precio: no se
  recomienda. Está pendiente, igual que si el método se limita a Bogotá: `calculate()` puede
  ofrecerlo solo si la ciudad o el departamento de envío coinciden, pero la ciudad es texto
  libre y habría que fijar el criterio (D-47).
- **Envío nacional:** tarifa configurable, hoy 0 con «flete por pagar al recibir», equivalente al
  `flatrate` actual. Queda pendiente si ByLopez cobrará alguna vez el flete dentro del pedido
  (D-47).
- **Compatible con el futuro:** la tarifa guarda `carrier = envio_nacional`, no la
  transportadora. Una cotización por API podrá devolver una o varias tarifas desde
  `calculate()`. `shipments.carrier_code` (la transportadora real) es independiente.

### 29.7 D-42, D-43 y D-44

- **D-42 (catálogo de transportadoras):** **diferida** a logística. Se aprueba la necesidad de
  evitar el texto libre en el flujo nuevo, sin estructura definida ni personas registradas.
- **D-43 (referencia interna):** **aprobada**. `Shipment.id` es la referencia interna del
  despacho, sin `dispatch_reference`. `track_number` solo guarda guías externas **reales**; sin
  guía, NULL. Nunca «1», «123», «NA» ni «sin guía».
- **D-44 (guía posterior y estados de entrega):** **diferida** a logística. No bloquea 03D, 03E
  ni 03F.

### 29.8 D-45: dónde viven los carriers (recomendación)

- **Estructura actual:** `packages/ByLopez/` contiene `CorteConfeccion` (producción) y
  `VentasAsesor` (venta asesorada con bindings RT-03).
- **Dependencias reales:** los carriers solo dependen de Webkul `Shipping`, `Checkout` y `Core`.
  Los usarán **la tienda y VentasAsesor**.

| Opción | Evaluación |
|---|---|
| A. VentasAsesor | Encierra el envío global en el módulo de venta asesorada: la tienda dependería de él y de sus bindings. **No** |
| **B. Paquete nuevo `ByLopez/Logistica`** (nombre por confirmar) | Dependencia limpia: Logística depende de Webkul, y VentasAsesor usa los códigos de método. Será también la casa de D-42, D-44 e integraciones. **Recomendado** |
| C. CorteConfeccion | Otro dominio (producción). **No** |

- **Coste de B:** registrar el paquete toca `bootstrap/providers.php` y `composer.json`
  (PSR-4), dos archivos raíz con **cambios ajenos sin commit**. Habrá que preparar solo los
  bloques propios al implementar. El paquete no se crea todavía.

### 29.9 Invalidación de tarifas al cambiar líneas

**Hecho:** `addProduct` y `updateItems` no recalculan tarifas; `removeItem` las borra y deja
`shipping_method` sin tarifa.

**Regla:**

| Operación | Efecto en el envío |
|---|---|
| `LineasBorradorService::agregar`, `cambiarCantidad`, `eliminar` (éxito) | `Cart::resetShippingMethod()` en la misma transacción. Borra tarifas y método y **conserva direcciones**. El asesor vuelve a elegir método |
| `SolicitudBorradorService::*` | Ninguno (no tocan el Cart) |
| `BorradorVentaService::cambiarCliente` | Ya lo hace: resetea el envío y borra direcciones y pago |
| `BorradorVentaService::recalcular` | Mantiene el método: las tarifas de estos carriers no dependen del precio, y las reglas de carrito sobre el envío se reaplican en cada `collectTotals` |
| **Guardia de 03F** | Repite `collectRates` y exige que el método elegido siga ofrecido con el **mismo precio**; si no, `tarifa_envio_obsoleta`. **Nunca** se confirma con una tarifa vieja |

Es un cambio pequeño en 03C (se aplicará en 03D con pruebas), sin cambiar su contrato.

### 29.10 Stock

- **Sin efecto sobre el stock:** direcciones y envío no cambian cantidades, no crean stock, no
  habilitan backorders y no suman producción (siguen I-12 e I-13).
- **Igual que el panel de Bagisto:** antes de `saveAddresses` o `saveShippingMethod`, si
  `Cart::hasError()` informa `INSUFFICIENT_QUANTITY` (una línea supera el stock actual), se
  rechaza con el código estable **`carrito_con_stock_insuficiente`** y las líneas afectadas con
  su desglose. Primero se ajustan las líneas. La validación no se salta.

### 29.11 Contratos (DTO) propuestos

- **Entrada:**
  - `DireccionComercial` (RT-04) para las direcciones puntuales;
  - `addressId` para las guardadas;
  - un modo de envío: `igual_facturacion`, `guardada`, `puntual` o `punto_recogida`.
- **`DireccionBorrador` (salida):**
  - `rol` (facturación o envío) y `origen` (guardada, puntual o punto_recogida);
  - `customerAddressId`, o null si es puntual;
  - nombre, apellido, empresa, líneas, ciudad, departamento, país, código postal (nullable),
    teléfono y email (nullable).
- **`MetodoEntregaDisponible`:** `codigo` (method), `carrier`, `titulo`, `descripcion` y
  `precio`, este último de la tarifa de Bagisto.
- **`VistaEntregaBorrador`:**
  - `facturacion` y `envio` (DireccionBorrador, nullable);
  - `metodosDisponibles`;
  - `metodoSeleccionado` (código, título, precio y descuento de envío de Bagisto), nullable;
  - `entregaCompleta` (bool: facturación y, si hay productos con stock, envío y tarifa elegida);
  - `avisos`, como `stock_insuficiente`.
- **Sin modelos Eloquent.** Sirve para la UI futura, WhatsApp o IA: la IA propone datos, pero
  Laravel valida y Bagisto calcula la tarifa.

### 29.12 Decisiones pendientes (Doc. 04)

- **D-40:** aprobar los tres carriers, el envío gratis como `CartRule` y el plan de transición
  de `flatrate` y `free`.
- **D-41:** recogida: B (punto de recogida = bodega de `inventory_sources`) o A (práctica
  actual).
- **D-45:** aprobar el paquete `ByLopez/Logistica` (nombre) y el registro en los archivos raíz.
- **D-46:** facturación mínima: código postal obligatorio u opcional; y qué hacer si el cliente
  no da dirección.
- **D-47:** tarifas: domicilio local (valor y política), si el domicilio local se limita a
  Bogotá (y con qué criterio), y si el envío nacional será siempre «por pagar».
- **Ya resueltas:** D-43 aprobada; D-42 y D-44 diferidas a logística.

---

## 30. 03D implementada: dirección y método de entrega (2026-09-26)

**Estado:** **IMPLEMENTADA**, pendiente de revisión de Camilo Gaitán y sin commit. Sin
migraciones, sin cambios en Webkul ni en CorteConfeccion, y sin escrituras en `lpz_saas`.

### 30.1 Paquete `ByLopez/Logistica` (D-45)

- **Qué hace:** registra los carriers con los puntos de extensión nativos de Bagisto:
  - `mergeConfigFrom(carriers.php, 'carriers')`, que recorre `Shipping::collectRates`;
  - `mergeConfigFrom(system.php, 'core')`, para los campos del panel
    `sales.carriers.<code>.title/description/default_rate/active`;
  - traducciones es/en.
- **Qué no tiene:** tablas, Shipment, transportadoras ni guías.
- **Registro:** una línea en `bootstrap/providers.php` (más su `use`) y una en `composer.json`
  (PSR-4).
- **Nacen INACTIVOS:** sin fila en `core_config`, Bagisto usa el valor de
  `config('carriers.<code>.*')` (`SystemConfig::getDefaultConfig`) y ahí `active = false`.
  Desplegar el código **no** cambia la tienda ni el panel hasta activarlos en la configuración.

| Carrier y método (código estable) | Clase | Tarifa |
|---|---|---|
| `recogida_bodega` | `RecogidaBodega` | 0 por naturaleza del método. **Solo** se ofrece si la dirección de envío es una bodega activa |
| `domicilio_local` | `DomicilioLocal` (hereda de `EntregaADestino`) | `default_rate` del panel, **por pedido**. Por defecto `null`, y **sin tarifa no se ofrece** |
| `envio_nacional` | `EnvioNacional` (hereda de `EntregaADestino`) | `default_rate` del panel, por defecto `0` con la descripción «Flete por pagar a la transportadora al recibir» |

- **Destinos:** `EntregaADestino` no se ofrece si el destino es una bodega (a una bodega solo se
  «recoge»).
- **Envío gratis:** es una promoción con `CartRule` (`free_shipping`). Bagisto pone a 0 el
  precio de la tarifa **elegida** y registra la regla en `applied_cart_rule_ids`; el método no
  cambia (probado con domicilio y con nacional).

### 30.2 Recogida (D-41, opción B)

- **Lugar de entrega:** `PuntosRecogida` (Logística) toma el lugar de las **fuentes de
  inventario activas del canal** (`inventory_sources`, sin id ni nombre fijos): calle, ciudad,
  departamento, país y código postal reales, más el nombre de la bodega como `company_name`.
  - Si hay una sola bodega activa, se usa esa.
  - Si hay varias, hay que indicarla: `punto_recogida_ambiguo`.
- **Persona (auditoría del punto 11):** en Bagisto una dirección de envío es **lugar +
  destinatario** (por ejemplo, el cliente en la dirección de su oficina). En la recogida, el
  lugar es la bodega y el destinatario es quien recibe: nombre, apellido, teléfono y email de
  la facturación. No hay campos nuevos ni datos inventados.
- **Límite documentado:** si recoge **otra persona**, hoy no se representa. Mejora futura.
- **Cómo se identifica:** la dirección de envío coincide con una bodega activa (calle, ciudad,
  departamento y país normalizados) y el método es `recogida_bodega`.

### 30.3 Direcciones y D-46

- **Facturación:** guardada del cliente (se verifica que sea suya) o puntual.
- **Envío:** igual que la facturación (`use_for_shipping`), guardada, puntual o punto de
  recogida. Todo pasa por `Cart::saveAddresses()`; la guardada deja `parent_address_id`.
- **Dirección puntual:** **no** se guarda en el cliente.
- **Reglas:** las de RT-04, extraídas a `Support\Clientes\ValidadorDireccionComercial` y
  compartidas con `GuardarDireccionClienteComercialService`, que conserva su comportamiento.
  - **Obligatorios:** nombre, apellido, líneas, ciudad, departamento, país y teléfono
    (normalizado a E.164).
  - **Opcionales:** email (RT-04), empresa y NIT.
- **Código postal:** **opcional en el borrador.** La base de datos lo admite NULL, en
  `lpz_saas` no hay tasas de impuesto y ningún carrier lo usa. Si viene, se valida con
  PostCode. Las direcciones **guardadas** lo siguen exigiendo (decisión de RT-04, sin tocar).
- **Cliente sin dirección:** Bagisto exige siempre la dirección de facturación, así que el
  asesor debe pedir una real. **No se inventa**, y sin facturación no hay entrega
  (`facturacion_requerida`).

### 30.4 `EntregaBorradorService` (VentasAsesor)

- **Operaciones:**
  - `vista`;
  - `establecerFacturacion`;
  - `establecerEnvio`;
  - `calcularMetodos`: vuelve a llamar a `Shipping::collectRates`;
  - `seleccionarMetodo`: `Cart::saveShippingMethod`, que exige que el método esté entre las
    tarifas, seguido de `collectTotals`.
- **Métodos disponibles:** son los que devuelve Bagisto, sin lista propia. Un carrier nuevo
  aparece solo (probado con un carrier de prueba).
- **Patrón:** transacción → `bloquearParaEditar` (borrador → carrito, el mismo orden de
  siempre) → grupo apto → RT-03 (`Support\Venta\CarritoComercial`, compartido con
  `LineasBorradorService`) → stock → operación.
- **Nunca:** calcula tarifas, crea Order o Shipment, elige transportadora ni toca el pago.
- **DTO:** `DireccionBorrador`, `MetodoEntregaDisponible`, `VistaEntregaBorrador` y la entrada
  `OrigenDireccion`. Sin modelos Eloquent.

### 30.5 Invalidación de tarifas

| Operación | Efecto |
|---|---|
| Guardar facturación o envío | Bagisto resetea el método (`saveAddresses`), y se recalculan totales y tarifas para el destino nuevo |
| `LineasBorradorService::agregar`, `cambiarCantidad`, `eliminar` (también la última línea) | `Cart::resetShippingMethod()` **antes** del cambio (así `collectTotals` no suma el envío anterior); conserva direcciones. Si la operación falla, la transacción lo revierte. La vista avisa `metodos_por_calcular` |
| `SolicitudBorradorService::*` | Ninguno: no tocan el Cart (probado) |
| `BorradorVentaService::cambiarCliente` | Ya limpiaba direcciones, pago y envío (03B, confirmado) |
| `recalcular` | Mantiene el método. **03F** deberá volver a calcular las tarifas y comparar tras `collectTotals` (el envío gratis pone el precio a 0) |

### 30.6 Stock

- **Rechazo:** si una línea supera el stock actual (`Cart::isItemsHaveSufficientQuantity`, la
  misma comprobación que el panel), cualquier operación de entrega se rechaza con
  `carrito_con_stock_insuficiente` y la lista de líneas afectadas.
- **Qué no hace:** no recorta, no reserva, no usa producción ni backorders.

### 30.7 Cobertura de los 15 casos

| # | Caso | Bagisto | ByLopez | Qué se guarda | Qué NO se inventa |
|---|---|---|---|---|---|
| 1 | Recogida en bodega | Exige dirección de envío y tarifa; copia el snapshot al pedido | La bodega real como lugar y quien recibe como persona; carrier `recogida_bodega` | Dirección de envío = bodega + persona; `shipping_method = recogida_bodega`; 0 | Domicilio del cliente, transportadora, guía |
| 2 | Domicilio local | Tarifa del carrier en totales | Carrier con tarifa del panel | Dirección de destino real; `domicilio_local`; tarifa | Domiciliario, tarifa manual |
| 3 | Nacional, flete por pagar | Tarifa 0 | `envio_nacional`, 0 + descripción | `shipping_amount = 0` sin regla aplicada | Envío gratis, transportadora |
| 4 | Nacional con promoción | CartRule `free_shipping` pone a 0 la tarifa elegida | Nada propio | `envio_nacional` + regla en `applied_cart_rule_ids` | Un método «gratis» |
| 5 | Domicilio con promoción | Igual | Nada propio | `domicilio_local` + regla | Un método «gratis» |
| 6 | Dirección guardada | Copia al carrito con `parent_address_id` | Verifica que sea del cliente | Dirección en el carrito vinculada | — |
| 7 | Dirección puntual | Copia al carrito | Reglas de RT-04; no la guarda en el cliente | Solo en el carrito (y en el pedido en 03F) | Dirección del cliente nueva |
| 8 | Sin dirección y necesita envío | Exige facturación | `facturacion_requerida`; el asesor pide una real | — | Direcciones de relleno |
| 9 | Recoge sin domicilio de entrega | Exige envío y facturación | Envío = bodega. La facturación sigue siendo real y obligatoria (límite de Bagisto, D-46) | Bodega como lugar | Domicilio falso |
| 10 | Cambia la dirección tras elegir método | `saveAddresses` resetea el método | Recalcula tarifas del destino nuevo | Método en null | Tarifa de otro destino |
| 11 | Cambia cantidades tras elegir método | No recalcula tarifas | `resetShippingMethod` en agregar, cambiar cantidad y eliminar | Método en null, direcciones intactas | Tarifa obsoleta |
| 12 | El stock baja tras elegir entrega | `isItemsHaveSufficientQuantity` | `carrito_con_stock_insuficiente` y aviso; 03F revalidará | Nada cambia | Stock ni recorte |
| 13 | Aparece un carrier nuevo | `collectRates` lo incluye | Nada (VentasAsesor no tiene lista) | Su código en `shipping_method` | — |
| 14 | Envío nacional con tarifa por API | — | Se reemplaza `EnvioNacional::calculate()` en Logística | La tarifa que devuelva | VentasAsesor no cambia |
| 15 | Pedidos históricos con `free` o `flatrate` | Snapshots de texto en `orders` | No se tocan; `free` y `flatrate` siguen activos | — | Migraciones de pedidos o shipments |

### 30.8 Pruebas

`Fase03dEntregaBorradorTest`: 27 casos, que cubren:

- facturación guardada y puntual, email NULL, reglas de RT-04 y dirección ajena;
- envío guardado, puntual e igual a facturación;
- recogida, con bodega inexistente, ambigua o domicilio;
- domicilio, tarifa del panel y sin tarifa;
- nacional por pagar, envío gratis (domicilio y nacional), método inexistente y sin productos
  físicos;
- carriers inactivos por defecto y extensibilidad;
- invalidación por dirección, por agregar, cambiar o eliminar línea y por la última línea;
- una solicitud no invalida; `cambiarCliente`;
- stock, propiedad y límites (sin `custom_price`, Order ni Shipment).

### 30.9 Pendientes y riesgos

- **D-47:** valor de la tarifa del domicilio (hoy sin tarifa no se ofrece); limitar el
  domicilio a Bogotá (no implementado); envío nacional siempre por pagar (implementado como
  tarifa configurable en 0).
- **Activación en producción y transición:** activar los carriers y desactivar `free` y
  `flatrate` es una escritura de configuración que requiere autorización.
- **Guardia de 03F:** revalidar stock, tarifa (tras `collectTotals`) y direcciones antes del
  Order.
- **Tienda:** la recogida solo se ofrece si la dirección de envío es una bodega, y la tienda
  todavía no tiene cómo elegir punto de recogida.
- **Persona que recoge:** si es distinta del cliente, hoy no se representa.
- **Código postal:** obligatorio en las direcciones guardadas (RT-04) y opcional en el
  borrador. Hay que decidir si también se relaja en RT-04.
- **Caché de `core_config`:** el repositorio de configuración tiene caché activada
  (`config/repository.php`). Los cambios desde el panel la limpian; un SQL directo no.

**Regresión 03D (2026-09-26):**

- VentasAsesor: 559 pasadas (2284 aserciones = 532 + 27 casos).
- CorteConfeccion: 384 (2100).
- Pint: sin errores (VentasAsesor y Logistica).
- Core de Bagisto (Admin Sales, Admin Configuration y Shop Checkout): 142 pasadas y **18 fallos idénticos con y sin** `LogisticaServiceProvider`, es decir, fallos previos no causados por 03D.

---

## 31. Cierre formal de 03D (2026-09-27)

**03D CERRADA**, revisada y aprobada por Camilo Gaitán. **03E (pago): pendiente, no iniciada.**

**Decisiones:**

- D-40 y D-41: aprobadas y cerradas.
- D-43 y D-45: aprobadas.
- D-42 y D-44: diferidas a la fase de logística.
- D-46: implementada, con un punto abierto: si se relaja el código postal en RT-04.
- D-47: parcial. Falta el valor de la tarifa del domicilio y la posible limitación por zona o
  Bogotá.

**Producción:** los carriers nuevos siguen **inactivos** y `free` y `flatrate` siguen activos.
Cambiarlo requiere autorización expresa. No hay migraciones.

**Deudas que no bloquean 03D y quedan donde corresponden:**

- persona distinta que recoge;
- catálogo de transportadoras, guía posterior, tracking y estados de entrega;
- integraciones (99envíos, Inter Rapidísimo, Servientrega) — fase de logística;
- la guardia de 03F, que revalidará stock, tarifa y direcciones.

---

## 32. Auditoría 03E: pago (2026-09-27)

**Solo auditoría:** no hay código, migraciones ni cambios de configuración. Evidencia del código
instalado (Bagisto 2.4.9) y de `lpz_saas` (solo SELECT, conteos agregados).

### 32.1 Motor de pagos de Bagisto

| Pieza | Evidencia |
|---|---|
| Registro | Cada paquete fusiona su `Config/payment-methods.php` en `config('payment_methods')` (Payment, Paypal, Stripe, PayU, Razorpay, PhonePe) |
| Clase | Hereda de `Webkul\Payment\Payment\Payment`. `getConfigData()` lee `sales.payment_methods.<code>.*`; sin fila en `core_config`, toma el valor por defecto del archivo, igual que los carriers |
| Disponibilidad | `Payment::getPaymentMethods()` recorre la configuración, instancia la clase, filtra por `isAvailable()` y ordena por `sort`. La base es `active`; cada método puede añadir condiciones |
| Selección | `Cart::savePaymentMethod(['method' => code])` borra el `CartPayment` anterior y crea uno con `method` y `method_title` (título de la configuración). **No valida** que el método exista ni que esté disponible (`isAvailable`): acepta cualquier texto |
| Persistencia en el carrito | `cart_payment(method, method_title, cart_id)`. Sin importes ni datos adicionales |
| Paso al pedido | `OrderPaymentResource`: `method`, `method_title` y `additional = request()->input('orderData')` (**acoplado al request HTTP**: en un servicio queda null). Queda en `order_payment` |
| Recargos o descuentos por método | **Ninguno.** `collectTotals` no toca el pago; el método no cambia precios ni totales. RT-03 sigue siendo la autoridad del precio |
| Inventario | Seleccionar el pago no toca el inventario. La reserva ocurre al crear el pedido (`manageInventory`) |
| Eventos | Seleccionar no dispara ninguno. Al crear el pedido, `checkout.order.save.after` dispara, **dentro de la transacción del pedido**: `GenerateInvoice`, la atribución de VentasAsesor, CartRule, notificaciones y otros |

### 32.2 Métodos: en código, activos y usados

| Método | Clase | ¿Disponible en `lpz_saas`? | Pedidos históricos |
|---|---|---|---|
| `cashondelivery` | `CashOnDelivery` | Sí (`active = 1`) | 14 |
| `moneytransfer` | `MoneyTransfer` | Sí (`active = 1`) | 10 |
| `paypal_standard`, `paypal_smart_button` | Paypal | No (`active = 0` en `core_config`) | 0 |
| `stripe`, `razorpay`, `phonepe`, `payu` | Sus paquetes | `active = true` **por defecto** (sin fila en `core_config`), pero `isAvailable()` exige `hasValidCredentials()` y no hay credenciales: **no disponibles** | 0 |

**Riesgo latente:** si se configuran credenciales de esos gateways, aparecerían solos, porque su
`active` por defecto es verdadero.

### 32.3 Contra entrega (`cashondelivery`)

- **Disponibilidad:** `active` y `cart->hasOnlyStockableItems()`, es decir, todos los productos
  son físicos. **No** depende del método de envío, la dirección, el país, el total ni el canal.
  **No** revisa stock.
- **Al crear el pedido:** estado `pending`. `GenerateInvoice`, si `generate_invoice = 1` (así en
  `lpz_saas`), crea la factura en estado `invoice_status = pending` y fija
  `order_status = pending_payment`.
- **Qué implica esa factura temprana:** una factura `pending` suma igualmente las cantidades y los
  totales «invoiced» del pedido (`InvoiceRepository::create` → `collectTotals`), y dispara el
  correo de factura al cliente (`new_invoice = 1`, protegido por RT-04).
- **Al despachar:** `ShipmentRepository` deja `pending_payment` si hay factura abierta.
- **Cómo se registra el cobro:**
  - Vía nativa: `TransactionController::store` registra transacciones hasta el total; entonces la
    factura pasa a `paid` y el pedido a `completed` (con Shipment) o `processing`.
  - Alternativa: `invoices.mass_update.state` cambia **solo** la factura, **sin** el estado del
    pedido. Datos reales: **3 pedidos contra entrega en `pending_payment` con factura `paid`**, la
    inconsistencia que produce esa vía.
- **Datos de `lpz_saas`:** 8 pedidos `pending_payment` con factura `pending`, 2 `completed` con
  factura `paid`, 1 `canceled` sin factura. `order_transactions` está vacía: nunca se usó la vía
  de transacciones.

### 32.4 Transferencia (`moneytransfer`) — HALLAZGO CRÍTICO

- **Disponibilidad:** solo `active`. Sin condiciones.
- **Instrucciones:** `mailing_address` en `getAdditionalDetails()`; hoy está vacía. No guarda
  datos del pago.
- **Configuración actual de `lpz_saas`:** `generate_invoice = 1`, **`invoice_status = paid`** y
  **`order_status = processing`**. Al crear el pedido, `GenerateInvoice` crea la factura
  **PAGADA** y deja el pedido en `processing`.

  **Bagisto trata la transferencia como dinero recibido al crear el pedido, sin ninguna
  verificación.**
- **Datos reales:** las 10 transferencias históricas están `completed` con factura `paid`.
  Coincide.
- **No existe en Bagisto:**
  - un estado «pendiente de verificación» propio del pago;
  - un comprobante o adjunto.
- **Qué sí existe en Bagisto:**
  - facturas `pending`, `paid`, `overdue` y `refunded`;
  - pedidos `pending_payment`;
  - `order_transactions` (con `data` JSON);
  - `order_comments` (texto).
- **Solución nativa posible, sin código:** configurar `moneytransfer` con
  `invoice_status = pending` y `order_status = pending_payment`, y confirmar el dinero con una
  transacción (la factura pasa a `paid` sola al completar el total). Es un cambio de
  configuración **global** (también afecta a la tienda) y en producción: requiere decisión y
  autorización (D-48).

### 32.5 Tabla factual por método (configuración actual de `lpz_saas`)

| Método | CartPayment | OrderPayment | Factura al crear | Estado inicial del pedido | Qué lo cambia después |
|---|---|---|---|---|---|
| Contra entrega | `cashondelivery` | `cashondelivery` (`additional` null) | Sí, `pending` | `pending_payment` | Transacciones hasta el total → factura `paid` y pedido `processing` o `completed`. Shipment con factura abierta → sigue `pending_payment` |
| Transferencia | `moneytransfer` | `moneytransfer` | Sí, **`paid`** | **`processing`** | Shipment → `completed`. **Nunca pasa por «pendiente»** |
| Sin factura automática (si `generate_invoice = 0`) | — | — | No | `pending` | Factura manual del panel, que puede crear una transacción |

Estados reales del pedido: `pending`, `pending_payment`, `processing`, `completed`, `canceled`,
`closed` y `fraud`. De la factura: `pending`, `pending_payment`, `paid`, `overdue` y
`refunded`. No hace falta inventar estados.

### 32.6 Método de pago ≠ estado del dinero

- **El método** vive en `order_payment.method`.
- **El estado del dinero** se lee de la factura (`state`), la suma de `order_transactions` y el
  estado del pedido.
- **Abonos:** son nativos. `TransactionController` admite **varias transacciones por factura**
  hasta su total, así que se puede calcular el saldo pendiente (total de la factura menos la suma
  de transacciones).
- **Límite:** hay un solo `order_payment` por pedido (un método) y, en la práctica, una sola
  factura (0 pedidos con más de una).
- **`order_transactions.payment_method`** es texto libre del formulario del panel: puede
  registrar con qué se cobró (efectivo, datáfono, transferencia) sin crear métodos de Bagisto
  nuevos. Queda como opción para D-50, sin decidir.

### 32.7 Comprobante de transferencia

- **No hay soporte nativo:** ni adjuntos en pedido, pago, factura ni transacción, ni ningún
  módulo instalado que lo cubra.
- **Lo que existe:** `order_transactions.data` (JSON, hoy solo `paidAmount`) y `order_comments`
  (texto).
- **Si se necesita** evidencia (imagen del comprobante), será una extensión futura: no se diseña
  ahora (D-51).

### 32.8 Métodos permitidos en el canal asesor

- **Disponible globalmente** = `Payment::getPaymentMethods()`.
- **Permitido para VentasAsesor** = una política del canal. El panel de Bagisto ya la tiene fija
  en código (`OrderController::store` solo acepta `cashondelivery` y `moneytransfer`).
- **Recomendación (sin duplicar Payment):**
  - **métodos ofrecidos = disponibles según Bagisto ∩ lista permitida configurable** (códigos en
    la configuración de VentasAsesor, inicialmente contra entrega y transferencia);
  - un método nuevo de Bagisto aparece solo cuando se añade a la lista, sin tocar código;
  - si la lista incluye un método no disponible, no se ofrece.
  
  Pendiente de decisión (D-49).
- **IA futura:** interpretará la intención («pago cuando me llegue») y Laravel devolverá los
  códigos ofrecidos; la IA nunca inventa códigos.

### 32.9 Dependencias e invalidación

| Cambio posterior a elegir el pago | Bagisto | Diseño propuesto para 03E |
|---|---|---|
| Método de envío o dirección | No toca `cart_payment` (`saveAddresses` y `resetShippingMethod` no lo borran) | Conservar el pago. Ningún método actual depende del envío; 03F revalida `isAvailable()` |
| Líneas, cantidades o total | No lo toca | Conservar; contra entrega solo cambia si entran productos no físicos. 03F revalida |
| Cambio de cliente | 03B ya borra `cart_payment` (confirmado) | Nada nuevo |
| Stock | El pago no lo considera | La operación de pago aplica la misma comprobación de stock que 03D; 03F revalida |

### 32.10 Frontera 03E / 03F

- **03E termina** cuando el Cart tiene un pago que está **disponible según Bagisto y permitido
  para el canal**. Como `savePaymentMethod()` no valida, esa comprobación la hace VentasAsesor
  antes de guardar.
- **03F repite todo antes del Order:** stock, direcciones, tarifa (tras `collectTotals`),
  disponibilidad y permiso del pago.
- **03F debe tener en cuenta:**
  - `OrderPaymentResource` lee el request HTTP;
  - `GenerateInvoice` fija el estado dentro de la transacción del pedido;
  - si falla, `OrderRepository` escribe todo `$data` en el log (datos personales) y reintenta
    hasta 3 veces.

### 32.11 Atribución y notificaciones

- **Atribución:** el listener de atribución de VentasAsesor comparte evento con `GenerateInvoice`,
  pero **no depende del pago ni del estado**. Si cualquier listener falla, todo el pedido se
  revierte (y se reintenta). No se modifica.
- **Correos al crear un pedido con pago contra entrega o transferencia**:
  - pedido nuevo: al cliente (`new_order = 1`, RT-04) y al admin (`new_order_mail_to_admin = 1`);
  - **factura** al cliente (`new_invoice = 1`, RT-04), ya en el momento del pedido;
  - notificación interna del panel al crear el pedido y en cada cambio de estado;
  - envío (`new_shipment = 1`), más adelante.
- **Defecto del core:** el listener de factura del Shop también hace `$invoice->query()->update(
  ['email_sent' => 1])` sin `where`. Solo se reporta.

### 32.12 Propuesta para 03E (sin implementar)

- **`PagoBorradorService`** (VentasAsesor):
  - `metodosDisponibles`: `Payment::getSupportedPaymentMethods()` con el Cart del borrador
    ∩ lista permitida;
  - `seleccionar`: valida que el código esté entre los ofrecidos y llama a
    `Cart::savePaymentMethod()`;
  - `vista`: expone la advertencia de estado inicial por método («la transferencia se registrará
    como pagada» mientras D-48 no cambie).
  
  Mismo bloqueo del borrador, grupo apto, RT-03 y comprobación de stock que 03D.
- **Sin** tablas, estados ni recargos propios, y sin tocar facturas o transacciones (eso es cobro
  y posventa).

### 32.13 Decisiones pendientes para Camilo (Doc. 04)

- **D-48 — estado inicial de la transferencia:** mantener pagada al crear (actual) o pendiente
  hasta confirmar (configuración nativa + transacción). Es global y en producción.
- **D-49 — métodos permitidos en el canal asesor:** lista configurable ∩ disponibles
  (recomendado), inicialmente contra entrega y transferencia.
- **D-50 — cobro de contra entrega y medios de cobro:** registrar el cobro con transacciones
  nativas (no con el cambio masivo de estado); efectivo o datáfono como medio de la transacción,
  o como métodos de Bagisto.
- **D-51 — comprobante de transferencia:** diferido; no hay soporte nativo.

---

## 33. D-52: principio de venta rápida de bodega (2026-09-27)

**Estado:** requisito transversal **decidido** por Camilo Gaitán (Doc. 04 D-52). Solo
documentación: **no** se implementa interfaz, cartera, 03E ni 03F en este paso.

### 33.1 Motivación y objetivo

- **Problema:** en bodega se atiende físicamente primero y se registra después, así que se
  acumulan pedidos sin registrar. Con ello se pierden trazabilidad, inventario actualizado,
  cartera y la cuenta de cuánto debe cada cliente.
- **Objetivo:** el caso habitual (cliente existente, productos disponibles, recogida y método de
  pago conocido) debe convertirse en pedido **sin salir de una pantalla, sin navegar por
  módulos y sin volver a escribir datos conocidos**. No se fija un número de clics, pero la
  cantidad de interacciones será criterio de diseño y de prueba.

### 33.2 Capas (sin reemplazar Bagisto)

```text
UI Nueva Venta (Blade/Vue: solo presentación e interacción)
        ↓
Servicios comerciales ByLopez: búsqueda y alta de cliente (RT-04), CatalogoVentaService,
BorradorVentaService, SolicitudBorradorService, LineasBorradorService,
EntregaBorradorService, pago (03E), confirmación (03F), registrar pago (cobros)
        ↓
Bagisto: Customer, grupo, Cart, precios, inventario, envío, pago, Order, factura y transacciones
```

Vue y Blade no contienen reglas de negocio. El mismo contrato sirve a asesor, voz, WhatsApp, n8n
e IA: la IA interpreta la intención y Laravel resuelve cliente, producto, variante, stock y
precio.

### 33.3 Qué se reutiliza

| Pieza existente | Aporte a la venta rápida |
|---|---|
| RT-03 | Precio por grupo **actual** del cliente, calculado por Bagisto; la UI solo lo presenta |
| RT-04 | Búsqueda por teléfono, nombre o email; alta comercial con email NULL y sin cuenta de tienda; resolución de identidad; direcciones guardadas |
| 03B | `CatalogoVentaService` (variantes verificadas, stock, precio) y `BorradorVentaService` (borrador reanudable, propiedad, reclamo, bloqueos, idempotencia) |
| 03C | Solicitud frente a Cart (I-13), materialización explícita, carrito ≤ stock vendible, desglose disponible/faltante y producción en null |
| 03D | Métodos de entrega de Bagisto (`recogida_bodega` sin pedir domicilio: la bodega real), direcciones guardadas o puntuales, invalidación de tarifas |

### 33.4 Requisitos para la futura interfaz Nueva Venta (no implementar)

- **Pantalla principal:** cliente, productos, resumen (prendas, subtotal, descuentos, entrega,
  total, calculados por Bagisto), entrega, pago y «Confirmar venta».
- **Cliente:**
  - búsqueda rápida;
  - al seleccionarlo se resuelven `customer_id`, grupo y contexto de precio;
  - alta inline (modal o drawer) solo con los datos comerciales necesarios, volviendo al mismo
    borrador.
- **Productos:**
  - búsqueda por nombre o SKU;
  - matriz compacta línea/color × talla con cantidades;
  - stock vendible visible, y disponible/faltante sin inventar existencias.
- **Borradores:** guardado continuo, reanudar y listar recientes (atender a otro cliente y
  volver), con `BorradorVentaService`, sin otro sistema.
- **Entrega y pago:** los métodos disponibles y permitidos que devuelven los servicios; nada
  fijo en el frontend.
- **Confirmación continua:** mensaje breve con el número de pedido, el total y el estado del
  pago, y acciones «Nueva venta», «Registrar pago» y «Ver pedido», sin pantallas intermedias.
- **Teclado:** autofocus, Enter, Tab y atajos seguros (requisito registrado; atajos no
  implementados).
- **Diseño adaptable:** probar en PC, tablet y celular; en celular, sin tablas horizontales
  grandes ni scroll excesivo.
- **Rendimiento:** revisar consultas repetidas, N+1, índices de búsqueda, número de requests,
  recálculos, carga del catálogo y respuesta de variantes (sin optimizar antes de tiempo).
- **Concurrencia y trazabilidad intactas:** propietario del borrador, reclamo, bloqueos, cliente,
  asesor, productos, precios de Bagisto, entrega, pago, Order y atribución.

### 33.5 Relación con pago (03E) y confirmación (03F)

- **03E:** elegir el pago debe ser inmediato entre los métodos permitidos y disponibles (D-49).
  El estado inicial de cada método (D-48) debe quedar claro sin que el asesor lo interprete.
- **03F:** confirmar crea el Order con los mecanismos de Bagisto, revalidando stock, tarifa,
  direcciones y pago. Debe devolver lo necesario para la confirmación breve y para encadenar
  «Registrar pago».
- **Dos hechos distintos:** (1) crear el pedido y (2) registrar el dinero recibido. «Confirmar
  y registrar pago» puede sentirse como un solo paso, pero **no** marca «pagado» con una casilla:
  registra el dinero con el mecanismo nativo (factura + transacción, §32.3 y §32.6), sin buscar
  la factura a mano.
- **Pago pendiente:** el pedido queda pendiente y aparece solo en la cartera, sin acciones
  administrativas extra durante la venta.

### 33.6 Relación con la futura cartera (no implementar)

- **Nombre:** «Cartera» o «Pagos pendientes», nunca «morosos». La mora requerirá vencimiento y
  política explícita.
- **En Nueva Venta:** saldo pendiente del cliente **solo informativo**. No bloquea: un bloqueo
  sería una política comercial futura explícita.
- **Corte por periodo:** compras, pagos y saldo del periodo (por ejemplo, del 21 al 26 de
  septiembre), más el saldo histórico total.
  - Se derivará de Customer, Order, factura y `order_transactions` sin duplicar saldos si
    Bagisto tiene la evidencia (§32.6: abonos nativos; saldo = total facturado − transacciones).
  - Hay que diseñar cómo tratar los pedidos sin factura, los cancelados y los reembolsos.
- **«Registrar pago»:** **un solo servicio de dominio**, usado desde el pedido, la cartera y la
  ficha del cliente.

### 33.7 Qué NO se implementa todavía

Interfaz Nueva Venta, cartera, corte semanal, «Registrar pago», atajos, alta inline, 03E, 03F
y cualquier cambio en producción. Orden acordado: cerrar **03E** (pago) y **03F** (confirmación
y Order) y, después, diseñar la interfaz sobre servicios estables.

### 33.8 Precisión de D-52: tiempo comercial frente a tiempo operativo (2026-09-27)

- **A. Tiempo comercial:** lo que tarda el cliente en decidir línea, producto, color, talla y
  cantidad. Es tiempo humano: **no** se le fija SLA ni se considera lentitud del sistema.
- **B. Tiempo operativo del sistema:** tiempo e interacciones que **añade la aplicación** para
  registrar lo ya decidido. **Este es el que se optimiza y se mide.**

**Principio:** no se busca que toda venta dure X segundos, sino que, **una vez conocida la
decisión comercial, registrarla sea extremadamente rápido**. Si el cliente tarda 4 minutos
eligiendo colores, no es un fallo de UX. Si después el asesor tarda 3 minutos navegando,
repitiendo datos y guardando, sí lo es.

**Velocidad no significa omitir** stock, precio, cliente, atribución, entrega, pago ni
trazabilidad. Significa **hacer automáticamente lo que el sistema ya conoce**.

**Selección de productos (sin implementar):**

- búsqueda rápida por nombre y SKU;
- productos recientes y frecuentes;
- conservar el contexto del producto;
- selección por línea y color;
- **matriz compacta de tallas** con cantidades editables en la propia celda;
- stock y precio visibles (los resuelve Laravel);
- agregar varias tallas sin repetir la búsqueda;
- navegación con teclado;
- sin abrir y cerrar un modal por cada variante.

**Caso crítico de matriz:** elegido «Caballero / Negro» (por ejemplo, stock S 20, M 35, L 28,
XL 12), el asesor registra S 0, M 6, L 6, XL 0 **en la misma matriz**, sin buscar «Caballero Negro
M» y luego «Caballero Negro L». Laravel resuelve y valida cada variante real (I-5); cada cantidad
pasa por la solicitud (D-39) y la materialización (I-13), con el carrito ≤ stock vendible.

**Métricas por escenario, cuando exista la interfaz:**

- tiempo total, tiempo comercial estimado o separable y **tiempo operativo**;
- clics o taps, pulsaciones, búsquedas repetidas y cambios de página;
- modales o drawers abiertos, requests HTTP y latencia perceptible;
- errores y reintentos.

El éxito **no** se declara porque la pantalla sea bonita.

**Escenarios cronometrables:**

| Id | Escenario | Qué se mide | Objetivo de diseño |
|---|---|---|---|
| E-01 | Venta rápida conocida: cliente existente, 6 Caballero Negro M + 6 L, recogida en bodega, transferencia pendiente | Sobre todo el tiempo operativo | Ningún cambio de página innecesario |
| E-02 | Varias variantes: mismo producto y color, S/M/L/XL con cantidades distintas | Búsquedas y aperturas por variante | Una sola selección de producto y color + matriz de tallas; sin repetir búsqueda |
| E-03 | Varios productos: Caballero Negro, Dama Rojo, Niño Blanco | Trabajo adicional por cambio de producto | Cambiar de producto sin perder la venta ni repetir datos |
| E-04 | Cliente nuevo | Tiempo de alta mínima y regreso | Alta comercial mínima (RT-04, email opcional) **sin abandonar el borrador** |
| E-05 | Pago inmediato: venta + transferencia ya recibida | Pasos entre confirmar y registrar el pago | Pedido → «Registrar pago», sin buscar la factura |
| E-06 | Pago pendiente | Acciones extra al confirmar | Ninguna: el pedido queda en la cartera solo |
| E-07 | Reanudar borrador tras atender otra operación | Tiempo para recuperar el contexto | Reanudación directa desde borradores recientes (03B) |
| E-08 | Siguiente cliente tras confirmar | Tiempo hasta quedar en «Nueva venta» | Transición prácticamente inmediata |
| E-09 | Consultar cartera de un cliente | Tiempo hasta ver saldo actual, saldo del periodo y pedidos pendientes | Un solo punto de consulta |
| E-10 | Registrar pago desde la cartera | Navegación hasta registrar el pago | Cliente → pedido o saldo → «Registrar pago», sin pasar por Facturación; mismo servicio único |

**Metas:**

- **No** se fijan todavía segundos ni SLA: primero se construye y se mide, y después Camilo
  aprobará objetivos reales basados en el uso.
- Criterios vigentes desde ya:
  - minimizar la navegación y la repetición;
  - **cero datos conocidos pedidos de nuevo**;
  - no repetir la búsqueda por cada variante;
  - no redirigir a pantallas administrativas;
  - ninguna regla de negocio en el frontend;
  - trazabilidad completa.

### 33.9 Navegación POS rápida de productos: auditoría del catálogo y diseño (2026-09-27)

**Solo análisis:** no hay interfaz, endpoints, tablas ni cambios en `CatalogoVentaService`.
Evidencia: código y `lpz_saas` (solo SELECT, conteos agregados; nombres de producto, no datos
de clientes). **El POS facilita la navegación; no inventa la estructura del catálogo.**

#### 33.9.1 Catálogo real (Bagisto)

| Elemento | Evidencia |
|---|---|
| Productos | 77 configurables raíz, 300 simples hijos, 3 simples sin padre y 1 agrupado |
| Configurables | 73 con 4 hijos y 4 con 2 hijos (tops). **Todos con 2 ejes**: `color` + `size` (73) o `color` + `grupo_de_edad_ninos` (4) |
| Color por configurable | El padre no tiene color. **Cada configurable tiene exactamente 1 color** entre sus hijos, es decir, configurable = línea × color. No hay dos configurables con la misma línea y color |
| Familias de atributos | `Camiseta_Hombre_DURZ` (34), `Camiseta_Mujer_DURZ` (35), `Camisetas_nino_DURZ` (4), `Crop_Top` (4); `default` sin configurables |
| Categorías | Raíz → **HOMBRE / MUJER / NIÑO** (también CAMISETAS ESTAMPADAS, sin productos) → **CAMISETA CABALLERO** (34), **CAMISETAS DAMA** (35), **TOP Dama** (4, bajo MUJER), **CAMISETA NIÑO** (4) → una subcategoría de material («Piel Durazno»). Cada configurable está en 2 categorías: línea y material |
| `color` | Atributo 23, select con **swatch de color**: 37 opciones, todas con swatch y 35 en uso. Por línea: Dama 35, Caballero 34, Niño 4 y Top 4 colores |
| Tallas y edades | `size` (24): S, M, L, XL y T-Unica en uso, ordenadas por `sort_order`. `grupo_de_edad_ninos` (31): 2-4, 6-8, 10-12 y 14-16 |
| Género | **No existe** ningún atributo de género |
| Nombres y SKU | Configurable «Camiseta Piel Durazno Lila Hombre» / `201DURZ-H`; variante con sufijo de talla (`201DURZ-H-S`). Hay patrones inconsistentes (`203DURZN-H`). Los nombres dicen «Hombre» o «Mujer», **no** «Caballero» ni «Dama» |
| Otros atributos | `familia_colores` definido **sin uso** (2 opciones, en ninguna familia); `product_number` en los 77 configurables (texto como `202DURZHOMBRELILA`); `tipo_tela` con opciones de prueba («Tela QA…») en producción (ruido ajeno a este alcance); **ningún atributo de código de barras** |
| Estado y canal | `product_flat` de un solo canal y locale (`default`/`es`); los 77 configurables activos y visibles; 297 de 300 variantes activas; 259 variantes con stock > 0 en el índice |
| Precio | Los hijos de un mismo configurable tienen el **mismo** precio base, pero hay **precios de grupo por variante** (1480 filas en 296 productos): el precio del cliente puede variar por variante |

#### 33.9.2 Fuente de «Caballero / Dama / Niño»

| Alternativa | Qué representa | Estabilidad y riesgos |
|---|---|---|
| **Categorías** (recomendada) | Organización comercial nativa, jerárquica y editable desde el panel. La tienda ya navega por ellas. Los nombres son los comerciales («CAMISETA CABALLERO», «CAMISETAS DAMA») | Cubre los 77 configurables. Riesgos: un producto está en varias categorías (línea y material), así que hay que fijar qué categorías son accesos rápidos. MUJER mezcla camisetas y tops |
| Familia de atributos | Esquema técnico (qué atributos tiene un producto). Hoy coincide 1:1 con la línea | Nombres técnicos («Camiseta Hombre DURZ»). Una línea nueva podría reutilizar una familia. CorteConfeccion la usa como línea operativa, pero eso no la hace semántica del POS. **No recomendada** |
| Nombre del configurable | Texto libre | Tendría que interpretar «Hombre» o «Mujer» dentro del nombre: frágil. **No** |
| Atributo de género | — | No existe |

**Recomendación:** los accesos rápidos salen de **categorías de Bagisto**, identificadas por
**configuración** (una lista de ids de categoría en la configuración de VentasAsesor, sin nombres
en el código), con el nombre de la categoría como etiqueta. Por ejemplo, CAMISETA CABALLERO,
CAMISETAS DAMA, TOP Dama y CAMISETA NIÑO, o el nivel HOMBRE/MUJER/NIÑO con subnivel.
**Pendiente:** qué categorías son accesos rápidos.

#### 33.9.3 Navegación línea → color → variantes

1. **Línea:** una categoría configurada. Se muestran sus configurables raíz activos.
2. **Color:** se agrupan esos configurables por la opción `color` de sus hijos (con etiqueta y
   swatch reales, en una sola consulta). Hoy cada color corresponde a un configurable. **No se
   asume 1:1**: si mañana hay varios productos para una línea y color, se listan por nombre.
3. **Variantes:** `CatalogoVentaService::variantes(configurable, cliente)`, con hijos reales
   verificados, sus opciones de eje (sea `size`, `grupo_de_edad_ninos` u otro), stock vendible y
   precio RT-03. Se ordenan por `sort_order` de la opción, sin S/M/L/XL fijo en la interfaz.

**Casos:**

- **Caballero Negro M 6 / L 6 / XL 3:** una sola matriz del configurable (Caballero, Negro) con
  las cantidades escritas en sus celdas y una sola acción. Laravel valida cada variante.
- **Cambiar de color** (Negro → Azul Rey, L 6): se mantiene la línea seleccionada y su lista de
  colores ya cargada; solo se piden las variantes del nuevo configurable.
- **Cambiar de línea** (Dama → Palo Rosa, S y M 3): otro acceso rápido → color → matriz. La venta
  acumula varias líneas, colores y tallas en el mismo borrador.

#### 33.9.4 Qué hace hoy `CatalogoVentaService`

- **`buscarProductos`:**
  - busca en `product_flat` (canal y locale por defecto), **solo productos padre** (sin padre,
    configurable o simple) y `status = 1`;
  - cada palabra de 2 o más caracteres debe aparecer en `name` o `sku` (LIKE `%palabra%`, todas
    las palabras a la vez);
  - ordena por nombre, con límite de 50 y **sin paginación**;
  - no filtra por visibilidad ni por stock;
  - no distingue mayúsculas ni tildes (intercalación `utf8mb4_unicode_ci`).
- **Limitaciones reales de la búsqueda:**
  - «caballero» o «dama» **no encuentran nada**, porque los nombres dicen «Hombre» o «Mujer»;
  - el **SKU de una variante** (`201DURZ-H-M`) no encuentra nada;
  - no busca por categoría, etiqueta de color ni talla;
  - no devuelve stock en los resultados;
  - `product_flat` no tiene índice en `name` ni `sku` (hoy son 378 filas, así que no importa).
- **`variantes()`:** carga los atributos de eje con sus opciones una vez, pero **por cada
  variante** consulta el stock (índice), el precio (con el precio de grupo en RT-03) y las
  opciones (EAV). Es un N+1 pequeño con 4 variantes, que se medirá antes de optimizar.

#### 33.9.5 Buscador POS futuro (sin implementar)

- **Qué debe entender:**
  - nombre;
  - SKU del padre y **de la variante** (lleva directamente a la variante);
  - **etiqueta de color**;
  - **nombre de categoría** (así «caballero» encuentra la línea);
  - talla en combinación («negro m»).
- **Cómo muestra los resultados:** tipados (producto o variante), con stock y precio del cliente
  al abrirlos.
- **Código de barras:** no hay atributo. Hará falta uno real antes del escáner; `product_number`
  existe solo en los configurables y no es un código de barras. Todo sale de Bagisto.

#### 33.9.6 Cuatro caminos, un solo destino

Los accesos rápidos (categoría → color → variantes), el buscador (texto o SKU → producto o
variante), los recientes o frecuentes y el escáner futuro **convergen** en
`CatalogoVentaService` (producto real → variante verificada → stock de Bagisto → precio RT-03),
y de ahí en la solicitud (D-39) y el carrito (I-13). No hay caminos de negocio separados.

#### 33.9.7 Recientes y frecuentes (recomendación, sin persistencia nueva)

- **Recientes, MVP:** los productos que **ese asesor** agregó en sus borradores recientes. Se
  derivan de las solicitudes y líneas de sus borradores, sin tabla nueva. Además, en la venta en
  curso, conservar la línea y el color seleccionados.
- **Frecuentes, MVP:** los **frecuentes del cliente seleccionado** (derivados de sus pedidos:
  útil para mayoristas que repiten). Si no tiene historial, los más vendidos del canal en los
  últimos N días.
- **Evolución:** frecuentes del asesor y favoritos configurables. «Más vendido» no es siempre lo
  más rápido; se medirá con P-06.
- **Orden de colores:** los más vendidos de esa línea primero (derivado, por ejemplo los 8
  principales) y «Ver todos» por el `sort_order` de Bagisto. Mientras no haya datos de uso
  suficientes, el `sort_order` configurado.

#### 33.9.8 Stock, precio y productos sin stock

- **Stock:** el mismo de 03C (índice de Bagisto vía `CatalogoVentaService`), por variante. El
  frontend no calcula stock y la producción no cuenta.
- **Precio:** el precio de Bagisto para el cliente actual (RT-03), **por variante**, porque los
  precios de grupo pueden diferir entre variantes. Si todos coinciden, se muestra una sola vez.
- **Sin stock:** la variante **se muestra** (stock 0) porque es útil comercialmente. Puede
  registrar demanda en la solicitud (D-39), pero **no** se puede llevar al carrito más allá del
  stock. Se ve «disponible» y «faltante»; producción sigue en null (D-37).

#### 33.9.9 Varias variantes en una operación

**Recomendación:** una **operación comercial por lote** (producto + {variante → cantidad}) en un
servicio de aplicación que **usa las primitivas existentes** (`SolicitudBorradorService` y
`LineasBorradorService`), sin duplicarlas.

- **Atomicidad:** un solo bloqueo del borrador y una transacción. **Todo o nada**: si una
  variante no es válida o supera el stock al llevarla al carrito, se rechaza el lote con el
  detalle por variante para que la interfaz lo muestre. Nada queda a medias.
- **Solicitud frente a carrito:** el lote puede registrar la solicitud (demanda) y, de forma
  explícita, cuánto va al carrito, siempre por debajo del stock.
- **Rendimiento:** con las primitivas actuales hay un `collectTotals` por variante; con 3 o 4
  variantes es aceptable. Se optimizará (recalcular una sola vez) solo si la medición lo exige.

#### 33.9.10 Rendimiento y caché

| Dato | Consulta propuesta | ¿Se puede cachear? |
|---|---|---|
| Accesos rápidos | Categorías configuradas y conteo de configurables activos | Sí (estructura), invalidando al guardar categorías o productos |
| Colores de una línea | **Una** consulta: categoría → configurables → hijos → valor de `color` → opción (etiqueta y swatch) | Sí (estructura), con la misma invalidación |
| Variantes | `variantes()` actual; más adelante, precargar stock y precio de las variantes del configurable si la medición lo pide | Solo la estructura (hijos y opciones) |
| Búsqueda | LIKE sobre `product_flat` (hoy 378 filas); índice o full-text si el catálogo crece | No |
| Stock y precio del cliente | Siempre en vivo (índice de Bagisto y RT-03) | **No** |

#### 33.9.11 Dispositivos (requisitos, sin CSS)

- **PC:** clic en la línea y el color, y Tab entre cantidades (6, Tab, 6, Tab, 3, Enter), sin
  atajos definidos todavía.
- **Tablet:** botones de línea y color grandes, y cantidades con ± táctiles, sin teclado físico.
- **Celular:** la matriz se adapta en vertical (una fila por talla), sin tablas horizontales.

#### 33.9.12 Escenarios cronometrables de producto

| Id | Escenario | Qué se mide |
|---|---|---|
| P-01 | Caballero → Negro → M 6 / L 6 / XL 3 → agregar | Interacciones, requests y latencia operativa |
| P-02 | Cambiar de color: Caballero Negro → Azul Rey, L 6 | Trabajo extra al cambiar de color |
| P-03 | Cambiar de línea: Dama → Palo Rosa, S y M 3 | Trabajo extra al cambiar de línea |
| P-04 | Buscar por SKU (incluido el de la variante) | Pulsaciones hasta la matriz o la variante |
| P-05 | Buscar por texto («caballero negro») | Resultados correctos a la primera |
| P-06 | Producto frecuente | Toques hasta la matriz |
| P-07 | Producto reciente | Toques hasta la matriz |

Sin SLA en segundos hasta medir (§33.8).

#### 33.9.13 Contratos de dominio sugeridos (reutilizables por voz, WhatsApp, n8n e IA)

- `accesosRapidos()` → `[{categoriaId, nombre, productos}]`, según la configuración.
- `coloresDeLinea(categoriaId)` → `[{optionId, etiqueta, swatch, productos: [{productoId, nombre}]}]`.
- `variantes(productoId, customerId)` → lo actual (id, SKU, opciones por eje, stock, precio
  RT-03, activa), ordenado por `sort_order`.
- `buscar(texto, customerId?)` → resultados tipados (producto o variante).
- `registrarSeleccion(borradorId, productoId, {varianteId: cantidad}, materializar)` → lote
  sobre las primitivas de 03C.

Frases como «Caballero negro, seis M, seis L y tres XL» encajan en estos contratos: la IA
extrae línea, color y cantidades por etiqueta, y Laravel resuelve la categoría, la opción de
color, el configurable, las variantes, el stock y el precio. La IA no inventa ids ni valores.

#### 33.9.14 No duplicar y no implementar todavía

- **No duplicar:** productos, colores, tallas, variantes, stock ni precios. Nada de tablas del
  POS: el POS consulta Bagisto.
- **Cambios futuros en `CatalogoVentaService`**, cuando se implemente la interfaz:
  - búsqueda por SKU de variante, etiqueta de color y nombre de categoría;
  - `coloresDeLinea` en una sola consulta;
  - variantes ordenadas por `sort_order`;
  - precarga de stock y precio si la medición lo exige.
- **No implementar todavía:** interfaz, endpoints, recientes, frecuentes, escáner, caché ni el
  lote. Primero **03E → 03F**, y después la interfaz Nueva Venta.
- **Pendientes para Camilo:**
  - qué categorías son accesos rápidos;
  - si se crea un atributo de código de barras para el escáner.

---

## 34. Auditoría definitiva de pagos: Transactions y Cartera (2026-09-27)

**Solo auditoría y diseño:** no hay código, configuración ni cambios en producción. Amplía §32
con verificación en código de Bagisto 2.4.9 y SELECT agregados sobre `lpz_saas`.

### 34.1 Flujo nativo de la transacción (única vía de «dinero recibido» en Bagisto)

**Ruta:** `POST admin/sales/transactions/create` → `TransactionController::store`, con ACL
`sales.transactions`. El formulario del panel es un modal en `sales/transactions/index` con un
desplegable de `Payment::getSupportedPaymentMethods()`.

**Secuencia real:**

1. Valida `invoice_id` (requerido), `payment_method` (**solo requerido: texto libre**) y
   `amount` (numérico).
2. La factura debe existir y **no** estar `paid`.
3. `transactionAmtBefore = SUM(order_transactions.amount WHERE invoice_id)`.
4. Rechaza si `amount + transactionAmtBefore > invoice.base_grand_total` o si `amount <= 0`.
5. Crea `order_transactions` con estos campos:
   - `transaction_id`: aleatorio;
   - `type` y `payment_method`: el valor enviado;
   - `status`: `paid`;
   - `amount`;
   - `data`: `{"paidAmount": amount}`;
   - `invoice_id` y `order_id`.
6. Si `SUM(amount) >= invoice.base_grand_total`:
   - el pedido pasa a `completed` si existe **algún** Shipment, o a `processing` si no;
   - `invoiceRepository->updateState(invoice, 'paid')`.

**Qué no hace:**

- **Ninguna** transacción de base de datos, ningún `lockForUpdate` ni eventos propios. Solo
  `updateOrderStatus` dispara `sales.order.update-status.before/after`.
- **No** recalcula los totales del pedido.
- **No** toca `order_payment`.

**Otra vía:** una factura creada a mano en el panel con `can_create_transaction = 1` crea una
transacción por el total, con `status` igual al estado de la factura (`Admin\Listeners\Invoice`).

**Decisión de diseño:**

- **Transacción → factura → pedido** es la semántica nativa.
- La factura es **obligatoria**: no hay transacción sin factura.
- El pedido no guarda ningún «total pagado»: solo `grand_total`, `*_invoiced` y `*_refunded`.

### 34.2 Pago total, pago parcial y segunda transacción (siguiendo el código)

| Caso (factura de 400.000) | Efecto |
|---|---|
| Pago total de 400.000 | 1 fila en `order_transactions`; factura `pending` → `paid`; pedido → `processing` o `completed`. Eventos de cambio de estado (notificación interna del panel). Sin correo al cliente ni cambios en `order_payment` o en los totales (lo facturado ya contaba al crear la factura) |
| Pago parcial de 150.000 | 1 fila; la factura **sigue `pending`**; el pedido **no cambia** (por ejemplo `pending_payment`). **Abono nativo** |
| Luego 250.000 | Suma 400.000 ≥ total → factura `paid` y pedido `processing` o `completed` |
| 100.000 y luego 350.000 | La segunda se **rechaza**: 450.000 > 400.000 («transaction-amount-exceeds») |
| Con la factura ya `paid` | Se rechaza («already-paid») |

**Riesgo de estado:** «completed si existe algún Shipment» no comprueba que el pedido esté
enviado del todo. `OrderRepository::updateOrderStatus($order)` sin estado explícito sí lo calcula
(`isInCompletedState`).

### 34.3 Sobrepago, concurrencia e idempotencia

- **Sobrepago:** solo se comprueba **dentro de la misma petición, sin bloqueo**.
- **Concurrencia NO protegida:** los asesores A y B leen la suma a la vez (0), ambos pasan la
  validación e insertan, y quedan **800.000 registrados** sobre 400.000. Nada lo impide.
- **Idempotencia inexistente:** `transaction_id` es aleatorio. Un doble clic o un reintento HTTP
  crea dos transacciones (la segunda solo se detiene si la primera ya dejó la factura `paid`).
- **Un servicio propio** deberá bloquear (`lockForUpdate` de la factura o el pedido) y aceptar una
  **clave de idempotencia**. Dónde persistir esa clave es la «decisión nueva propuesta 1» de
  §34.13.

### 34.4 Comparación de caminos

| Camino | Evidencia de dinero | Factura | Pedido | ¿Coherente? |
|---|---|---|---|---|
| A. Crear con `invoice_status = paid` (transferencia hoy) | **Ninguna** (0 transacciones) | `paid` al crear | Estado de la configuración (`processing`) | **No**: marca pagado sin registrar dinero |
| B. Factura `pending` + transacción nativa (panel) | Sí (`order_transactions`) | `paid` al completar el total | `processing` o `completed` | **Sí**, con los riesgos de §34.3 y la regla simplista de estado |
| C. Cambiar la factura a `paid` directamente | No | `paid` | **Sin cambios** | **No**: produce el caso inconsistente |
| D. Cambio masivo de estado de facturas | No | `paid` | Sin cambios | **No** (igual que C, en lote) |
| E. Crear el pedido y registrar el pago con el mismo servicio que Cartera | Sí | `paid` al completar | Estado nativo calculado | **Sí (recomendado)**: la semántica de B con bloqueo, idempotencia y cálculo nativo del estado |

**Equivalencias:** C = D. B ≈ E (E añade garantías). A **no** equivale a ninguno: coincide en
estados, pero sin evidencia del dinero.

### 34.5 Los 3 pedidos `pending_payment` con factura `paid`

- **Hechos:**
  - pago contra entrega;
  - factura creada con el pedido;
  - 0 transacciones;
  - con Shipment.
- **Explicación compatible:** factura `pending` → Shipment (`hasOpenInvoice` → `pending_payment`)
  → **cambio directo de la factura a `paid`** (C o D). Ese cambio no toca el pedido.
- **Descartado:**
  - la vía de transacciones (0 filas);
  - «factura creada ya `paid`»: el Shipment habría recalculado el pedido a `processing` o
    `completed`.
- **No concluyente:** los `updated_at` de facturas no sirven como prueba. El listener del Shop
  hace `$invoice->query()->update(['email_sent' => 1])`, que actualiza **todas** las facturas y sus
  fechas. No se corrigen.

### 34.6 Fuente del total pagado y del saldo

- **Pagado por factura** (compatible con el histórico):
  - `SUM(order_transactions.amount)` de esa factura (moneda base COP; no existe `base_amount`);
  - **si la factura está `paid` sin transacciones** (transferencias históricas autopagadas o
    cambio manual), se toma `invoice.base_grand_total` como pagado heredado, marcado como «sin
    evidencia de transacción».
- **Pagado por pedido** = Σ del pagado de sus facturas.
- **Saldo del pedido (MVP)** = `order.base_grand_total − pagado`, solo para pedidos que **no**
  están `canceled`, `closed` ni `fraud`.
- **Por qué no `order.status`:** no sirve para calcular el saldo (§34.5).

### 34.7 Varias facturas, cancelaciones y reembolsos

- **Varias facturas:** el panel admite facturas parciales por cantidad. Las transacciones se
  asocian a una factura, y la parte **no facturada** no admite transacción nativa. Con
  `GenerateInvoice` (todo facturado al crear) hay 1 factura por pedido (0 casos con más). La
  fórmula suma por pedido.
- **Cancelación:** `qty_to_cancel = pedidas − canceladas − facturadas`. Un pedido **ya facturado
  no se puede cancelar**; solo reembolsar.
- **Reembolso:** `RefundRepository` actualiza `grand_total_refunded` y el estado del pedido
  (`closed` si se reembolsa todo). **No** cambia la factura (sigue `paid`) ni crea una transacción
  negativa.
- **Riesgo:** `total − transacciones` sería incorrecto con reembolsos. Un pedido pagado y
  reembolsado del todo daría 400.000 − 400.000 pagado, pero el dinero volvió al cliente.
- **MVP:** excluir `closed`, `canceled` y `fraud`, y marcar para revisión los pedidos con
  reembolso parcial. La política completa es la «decisión nueva propuesta 2».

### 34.8 Transferencia, contra entrega y alcance de la configuración

- **Transferencia pendiente:** con la configuración `moneytransfer` en `invoice_status =
  pending` y `order_status = pending_payment`, el flujo **pedido → factura pendiente →
  `pending_payment` → transacción → `paid` y `processing`** es coherente y nativo (confirmado en
  código).
- **Transferencia confirmada:** la misma transacción que en contra entrega.
- **Alcance:** `invoice_status` y `order_status` son campos **por canal** (`channel_based`, no por
  locale). `lpz_saas` tiene un solo canal (`default`), así que en la práctica son **globales**:
  tienda, panel y VentasAsesor. D-48 separa la **decisión de arquitectura** (pendiente hasta el
  dinero real) del **despliegue** (cambio de configuración autorizado).
- **Contra entrega:** ya nace `pending` y `pending_payment`, y se paga después con **el mismo**
  registro de pago. No necesita lógica especial.

### 34.9 Método del pedido frente a medio real del dinero

- **Campos:** `order_payment.method` = método del pedido. `order_transactions`: `payment_method`
  y `type` (texto libre, el mismo valor), `data` (JSON) y `amount`. **No** existe
  `payment_method_title`.
- **Semántica propuesta:** `order_transactions.payment_method` = **medio real** con que se
  recibió el dinero (efectivo, transferencia, datáfono…), independiente de `order_payment.method`.
- **Riesgo del texto libre:** «Transferencia», «transfer», «Nequi», «NEQUI»… El backend solo exige
  que venga algo.
- **Mitigación sin otro motor de pagos:** el servicio acepta **solo códigos de una lista
  configurable de medios de cobro** (configuración de VentasAsesor, con código y etiqueta) y
  guarda el código. El panel nativo seguiría admitiendo texto libre (límite de Bagisto).
- **Dato curioso:** fuera de un carrito, el desplegable del panel **no ofrece contra entrega**
  (su `isAvailable()` exige carrito).

### 34.10 Comprobante

- **No hay soporte nativo:** ni adjuntos en Order, Invoice, Transaction ni OrderComment
  (comentario de texto).
- **Principio:** **comprobante ≠ pago confirmado**. Un comprobante no crea una transacción: una
  persona valida y registra el pago. La evidencia de archivo será una extensión futura.

### 34.11 Diseño (sin implementar)

- **Pago inmediato tras la venta (D-52):**
  - Confirmar (03F) crea el pedido y hace **commit**.
  - Después, «Registrar pago» usa **el mismo servicio que Cartera**, en otra transacción y con
    clave de idempotencia.
  - La interfaz puede encadenarlos en un solo gesto, pero **no** comparten transacción de base de
    datos: si el pago falla, el pedido sigue siendo válido y el pago se reintenta sin duplicarse.
- **Venta pendiente:** se confirma y termina ahí. El pedido aparece en Cartera con su saldo. Sin
  bloqueos por deuda.
- **Cliente con varios pedidos:** saldo total = Σ de saldos de sus pedidos abiertos (§34.6).
- **Pago de un pedido (MVP):** la transacción va a **su factura**.
- **Pago global del cliente (futuro):** la transacción nativa exige una factura, así que un abono
  global necesitará una **política de distribución** explícita que genere una transacción por
  factura. No se aprueba «el más antiguo primero»: es la «decisión nueva propuesta 3».
- **Cartera (derivada, sin tabla de saldos):**
  - por cliente: saldo total, pedidos abiertos con total, pagado y saldo, fecha, método y pagos
    registrados (`order_transactions`);
  - «esta semana» = saldo de los pedidos con `orders.created_at` en ese periodo;
  - **saldo total** = todos los pedidos abiertos (por ejemplo, deuda anterior 150.000 + semana
    400.000 = 550.000);
  - sin vencimiento, plazo ni mora (no hay política).

**`RegistrarPagoPedidoService`:**

- **Entrada:** pedido (o factura), monto, medio (código de la lista), clave de idempotencia y
  actor.
- **Pasos:**
  1. En una transacción, bloquear la factura (y el pedido).
  2. Recalcular el pagado **dentro del bloqueo**.
  3. Validar que el monto sea mayor que 0, que no supere el saldo y que la factura no esté
     `paid`.
  4. Crear `order_transactions` con los campos nativos.
  5. Si se completa el total: `updateState('paid')` y `updateOrderStatus($order)` con el cálculo
     nativo.
- **Resultado:**
  - `order_id`, `invoice_id` y `transaction_id`;
  - monto registrado, total pagado y saldo pendiente;
  - estado de pago derivado, `order_status` e `invoice_state`;
  - `duplicado`: si la clave ya existía, devuelve el resultado original.
- **Reutilizable** desde Nueva Venta, Cartera, API, n8n y conciliación. Bagisto **no** tiene un
  servicio equivalente reutilizable (la lógica vive en el controlador), así que el servicio
  replica sus efectos nativos, sin motor contable propio.

**Estado de pago derivado (no persistido):**

- `pendiente` si pagado = 0;
- `parcial` si 0 < pagado < total neto;
- `pagado` si pagado ≥ total neto;
- `revisar` si hay reembolso parcial o una factura `paid` sin transacción que contradice el
  pedido.

Para pedidos `canceled`, `closed` o `fraud`, no aplica.

### 34.12 03E, 03F y aspectos transversales

- **`PagoBorradorService` (03E, antes del pedido):**
  - métodos = disponibles según Bagisto ∩ lista permitida (D-49);
  - selección validada y `Cart::savePaymentMethod`;
  - informa el estado inicial esperado según la configuración vigente.
  
  **No registra dinero.**
- **D-49:** la lista se guarda en la configuración de VentasAsesor (`ventasasesor.pagos.metodos_
  permitidos`, inicialmente `cashondelivery` y `moneytransfer`), sin estar fija en el servicio.
- **Revalidaciones en 03F:**
  - método permitido y disponible;
  - cliente existente, activo y no suspendido (P-09 bloquea aquí), con grupo apto;
  - precios (`collectTotals` en RT-03);
  - líneas ≤ solicitud y stock (I-12, I-13);
  - direcciones;
  - método de envío con tarifa vigente tras `collectTotals`;
  - totales;
  - contexto de atribución.
  
  **Nunca** inventa un pago.
- **Resultado mínimo de 03F para la venta rápida:** `order_id`, `increment_id`, `grand_total`,
  método de pago (código y título), `invoice_id` si se generó, estado de pago derivado y saldo,
  `customer_id` y nombre, y las acciones disponibles (nueva venta, registrar pago, ver pedido).
- **Eventos y notificaciones:**
  - la transacción nativa solo dispara los eventos de cambio de estado del pedido (notificación
    interna del panel);
  - `updateState` de la factura no dispara eventos;
  - no hay correo al cliente al registrar el pago.
  
  El servicio debe producir **los mismos** efectos (usar los mismos repositorios), sin correos
  extra.
- **Privacidad:** `OrderRepository::createOrderIfNotThenRetry` escribe
  `Log::error(..., ['data' => $data])` con el pedido completo (direcciones, email y teléfono) en
  cada fallo, y reintenta hasta 3 veces. Es deuda técnica del core (no se modifica Webkul); 03F
  deberá considerarla.
- **Defecto del core:** `Order::hasOpenInvoice()` usa `where(pending)->orWhere(pending_payment)`
  sin agrupar, así que la condición se sale del pedido. Hoy no hay facturas `pending_payment`, por
  lo que no se manifiesta. Solo se reporta.

### 34.13 Matriz de pruebas futura y decisiones

- **Pruebas previstas (PAGO-01 a PAGO-15):**

  | Id | Caso |
  |---|---|
  | PAGO-01 | Pedido de 400.000 → pago total |
  | PAGO-02 | Pago parcial de 150.000 → factura `pending` y pedido sin cambios |
  | PAGO-03 | Pago del resto (250.000) → factura `paid` y estado nativo |
  | PAGO-04 | Intento de sobrepago → rechazado |
  | PAGO-05 | Misma clave de idempotencia → una sola transacción |
  | PAGO-06 | Dos asesores a la vez → una transacción y la otra rechazada |
  | PAGO-07 | Transferencia pendiente |
  | PAGO-08 | Transferencia confirmada |
  | PAGO-09 | Contra entrega pendiente → pago posterior |
  | PAGO-10 | Pago inmediato después de la venta |
  | PAGO-11 | Cliente con varios pedidos |
  | PAGO-12 | Cartera: saldo de la semana + saldo histórico |
  | PAGO-13 | Factura `paid` con pedido `pending_payment` → estado derivado `revisar`/`pagado` sin corregir datos |
  | PAGO-14 | Cancelación |
  | PAGO-15 | Reembolso → excluido o `revisar` |

- **Estado de D-48 a D-51:** ver el Doc. 04.
- **Decisiones nuevas propuestas (sin numerar):**
  1. **Idempotencia de pagos:** dónde persistir la clave (una tabla propia mínima de registro de
     pagos frente a `order_transactions.data`, que no admite UNIQUE).
  2. **Política de saldo** ante reembolsos, cancelaciones y facturas `paid` sin transacción.
  3. **Distribución de un pago global** del cliente entre facturas.

---

## 35. Cierre arquitectónico 03E: registro seguro de pagos (2026-09-27)

**Diseño cerrado, sin implementar:** no hay migración, modelo, servicio ni configuración.
Decisiones: D-48 a D-51 aprobadas por Camilo Gaitán y **D-53** (idempotencia y concurrencia)
cerrada. El despliegue de D-48 en producción sigue necesitando una autorización aparte.

### 35.1 Primitivas reales de Bagisto (verificadas)

| Primitiva | Efecto exacto |
|---|---|
| `OrderTransactionRepository::create(array)` | Inserta en `order_transactions`. Sin eventos ni validación |
| `InvoiceRepository::updateState($invoice, $status)` | `state = $status` y `save()`. **Sin eventos**; no toca el pedido ni los totales |
| `OrderRepository::updateOrderStatus($order, $state = null)` | Dispara `sales.order.update-status.before` → calcula el estado si no se indica → guarda → `sales.order.update-status.after`, que dispara el listener de notificaciones (evento `UpdateOrderNotification`, `ShouldBroadcast`; en `lpz_saas` el broadcast va al driver `log`) |
| Cálculo sin `$state` | `processing` por defecto; `completed` si `isInCompletedState`; `canceled` si `isInCanceledState`; si no, `closed` si `isInClosedState`. **Solo cuenta cantidades** (pedidas, facturadas, enviadas, reembolsadas y canceladas) y **no** mira el pago. `pending_payment` solo aparece si se indica explícitamente |
| `isInCompletedState` | Todo facturado (+ cancelado) y todo enviado (los productos no físicos cuentan como enviados al facturarse), o ya estaba `completed` sin estar reembolsado del todo |

**No existe un servicio nativo reutilizable** que combine transacción, factura y pedido: esa
lógica está dentro de `TransactionController::store` (HTTP, sin bloqueos). El servicio de
ByLopez **compone las tres primitivas** anteriores, sin reimplementar el cálculo de estado.

### 35.2 Estado del pedido al completar el pago

- **Regla nativa del controlador:** «existe algún Shipment → `completed`; si no, `processing`».
- **Regla de ByLopez:** llamar a `updateState(invoice, 'paid')` y **después**
  `updateOrderStatus($order)` **sin estado**, para que Bagisto calcule el estado por cantidades.
  El pago parcial **no** cambia el estado del pedido (sigue, por ejemplo, `pending_payment`, igual
  que el nativo).

| Escenario (pago completo) | Regla del controlador | `updateOrderStatus($order)` | Correcto |
|---|---|---|---|
| A. Sin Shipment | `processing` | `processing` (facturado, nada enviado) | Iguales |
| B. Shipment completo | `completed` | `completed` | Iguales |
| C. Shipment parcial, una línea | **`completed`** | `processing` (enviado < facturado) | El cálculo nativo |
| D. Varias líneas, Shipment parcial | **`completed`** | `processing` | El cálculo nativo |

Cuando el envío se completa después, `ShipmentRepository` recalcula a `completed` (sin factura
abierta). **Copiar la regla del controlador sería incorrecto** en C y D.

### 35.3 Idempotencia (D-53)

- **Quién genera la clave:** el cliente de la operación.
  - La interfaz: un UUID por intención de «Registrar pago», que se reutiliza en cada reintento de
    esa misma intención.
  - API o n8n: el identificador del mensaje o evento.
  - Conciliación futura: la referencia bancaria.
- **Formato:** hasta 100 caracteres (`[A-Za-z0-9._:-]`). Obligatoria.
- **Alcance:** `(mecanismo, clave)`, igual que los borradores: dos canales no chocan entre sí.
- **Huella de la solicitud:** `sha256` de `order_id | invoice_id | monto normalizado (base, 2
  decimales) | código del medio de cobro`.

| Caso | Resultado |
|---|---|
| Misma clave + misma huella (doble clic, reintento HTTP, API o n8n, respuesta perdida tras el commit) | **No** crea otra transacción. Devuelve la operación ya registrada, con `duplicado = true` |
| Misma clave + huella distinta (por ejemplo, 150.000 en efectivo y después 250.000 por transferencia con la misma clave) | **Conflicto** (`idempotencia_en_conflicto`). No escribe nada |
| Clave distinta sobre la misma factura (pago 1 de 150.000 y pago 2 de 250.000) | Se registra normalmente, sujeto al saldo real |

**Reconstrucción del resultado en un reintento:** registro → `order_transactions` (monto, medio,
factura, pedido) → pagado y saldo **actuales**, recalculados al leer. El resultado lleva el monto
registrado originalmente y el estado vigente.

### 35.4 Tabla mínima propuesta: `bylopez_va_registros_pago` (sin migración todavía)

| Campo | Por qué existe | Por qué no está en otro sitio |
|---|---|---|
| `id` | Clave técnica | — |
| `mecanismo` (string 30) | Alcance de la clave (`ventas_asesor`, `api`, `n8n`, `conciliacion`…) | Bagisto no guarda el origen |
| `idempotency_key` (string 100) | La clave de la operación lógica | `order_transactions.data` es JSON y no admite UNIQUE |
| `huella_solicitud` (char 64) | Detectar misma clave con otro contenido | — |
| `order_transaction_id` (UNIQUE, FK a `order_transactions`, RESTRICT) | Resultado de la operación; de ahí salen factura, pedido, monto y medio **sin duplicarlos** | — |
| `actor_admin_id` (sin FK, como la atribución) | **Quién registró el cobro** (auditoría de caja) | Verificado: `order_transactions` no guarda actor y Bagisto no tiene tabla de auditoría de administradores |
| timestamps | Trazabilidad | — |

- **Restricciones:** UNIQUE(`mecanismo`, `idempotency_key`) y UNIQUE(`order_transaction_id`).
  Nada de UNIQUE(`invoice_id`), que impediría los abonos.
- **Qué no guarda:** saldo, monto, medio, pedido, factura, snapshots, estado ni ledger. No es un
  motor contable: es solo el control de la operación ByLopez sobre la transacción nativa.

### 35.5 Bloqueos, recálculo y atomicidad (una sola transacción de base de datos)

```text
DB::transaction(intentos = 3):
  1. INSERT registro (mecanismo, clave, huella, actor, order_transaction_id = NULL)
       ← UNIQUE: si otra petición con la misma clave está en curso, esta ESPERA; cuando la otra
         hace commit, recibe clave duplicada → sale de la transacción y va al paso R.
  2. SELECT orders ... FOR UPDATE              (orden estable: pedido → factura)
  3. SELECT invoices ... FOR UPDATE            (la factura del pedido; debe pertenecer a él)
  4. Validar bajo bloqueo:
       - pedido no canceled, closed ni fraud;
       - sin reembolsos (grand_total_refunded = 0; si no → pedido_con_reembolso_revisar);
       - factura no paid;
       - pagado = SUM(order_transactions.amount de la factura), recalculado AHORA;
       - 0 < monto <= base_grand_total − pagado;
       - medio ∈ lista configurada.
  5. OrderTransactionRepository::create(transaction_id aleatorio, status paid,
       type = payment_method = código del medio, amount, data {paidAmount}, invoice_id, order_id)
  6. UPDATE registro.order_transaction_id
  7. Si pagado + monto >= total: InvoiceRepository::updateState(invoice, 'paid')
                                 → OrderRepository::updateOrderStatus($order)   (sin estado)
COMMIT
R. Clave duplicada: leer el registro existente; misma huella → resultado original
   (duplicado); huella distinta → idempotencia_en_conflicto.
```

- **Carrera entre dos asesores con 400.000 cada uno y claves distintas:** ambos insertan su
  registro y compiten por el bloqueo del pedido. El segundo espera y, al obtenerlo, recalcula:
  la factura ya está `paid` (o el saldo es 0) y se rechaza. **Solo uno registra el dinero.** El
  saldo que muestra la interfaz nunca manda.
- **Fallos intermedios:** todo va en una transacción, así que no puede quedar «una transacción
  sin factura actualizada» ni «una factura sin pedido actualizado». Una excepción o un deadlock
  revierten todo, también el registro de idempotencia, y el reintento (automático o del cliente)
  es seguro.
- **Deadlocks:** `DB::transaction` con 3 intentos; orden fijo registro → pedido → factura.
- **Timeout después del commit:** el reintento con la misma clave devuelve el resultado
  (§35.3).
- **Eventos:** `updateOrderStatus` dispara sus eventos **dentro** de la transacción, igual que el
  flujo nativo. El broadcast (`log`) es el único efecto externo y va en el último paso, así que un
  rollback posterior es improbable. No hay correos. No se añaden eventos propios en esta etapa.
- **Riesgo residual:** la pantalla nativa de transacciones del panel sigue sin bloqueos. Se
  recomienda que los asesores registren pagos solo por el servicio ByLopez (política de ACL
  `sales.transactions`, a decidir en la implementación).

### 35.6 Medios de cobro (contrato, sin lista definitiva)

- **Configuración de VentasAsesor:** lista de `{codigo estable, clave de traducción de la
  etiqueta}`. Ejemplos posibles: efectivo, transferencia, datáfono. La lista definitiva la
  aprueba Camilo.
- **Uso:** `order_transactions.payment_method` (y `type`) recibe **el código**. La interfaz y la
  API de ByLopez **no** aceptan texto libre.
- **Independencia:** el medio de cobro es independiente del método del pedido
  (`order_payment.method`).

### 35.7 Lo que queda fuera de este cierre

- **Reembolsos y datos históricos inconsistentes (propuesta 2, diferida):** el servicio **rechaza**
  pagar un pedido con reembolsos o una factura ya `paid`. Esos casos se ven como `revisar` en la
  vista derivada y no se crea saldo persistido.
- **Pago global del cliente (propuesta 3, diferida):** el MVP registra el pago contra **un pedido
  o factura**. Sin reparto automático ni «el más antiguo primero». La tabla admite más adelante
  varias transacciones por operación lógica si se decide un reparto.
- **Relación con 03E y 03F:**
  - `PagoBorradorService` (03E) **no** registra transacciones: solo métodos permitidos
    ∩ disponibles, validación, `savePaymentMethod` y estado inicial esperado.
  - 03F confirma y hace **commit**; «Registrar pago» es **otra transacción** con este servicio.
  - `RegistrarPagoPedidoService` se implementará con Cartera o cuando la venta rápida lo necesite.

---

## 36. 03E implementada: `PagoBorradorService` (2026-09-27)

**Estado:** **IMPLEMENTADA**, pendiente de revisión de Camilo Gaitán y sin commit. Cierra la
arquitectura de §35 en su parte previa al pedido. `RegistrarPagoPedidoService` (D-53) y la
Cartera siguen sin implementar.

### 36.1 `PagoBorradorService`

- **`vista(borradorId)`:** métodos ofrecidos (allowlist de VentasAsesor ∩ disponibles según
  Bagisto ahora mismo) y el método seleccionado, leído de `cart_payment`. Si el seleccionado ya
  no está entre los ofrecidos (por ejemplo, se desactivó), la vista lo sigue mostrando pero fuera
  de la lista de disponibles: la interfaz puede detectarlo (probado).
- **`seleccionar(borradorId, metodo, actor)`:** valida el código contra los métodos ofrecidos
  **antes** de llamar a `Cart::savePaymentMethod()`, porque Bagisto no comprueba disponibilidad
  al guardar. Devuelve dos códigos distintos: `metodo_pago_inexistente` (no lo conoce Bagisto) y
  `metodo_pago_no_disponible` (existe pero no está permitido o no está disponible ahora).
- **Fuente de métodos:** `Payment::getPaymentMethods()`, sin catálogo propio. La allowlist vive
  en `ventasasesor.pagos.metodos_permitidos` (inicialmente `cashondelivery` y `moneytransfer`).
  Un gateway nuevo de Bagisto (por ejemplo, con credenciales futuras) no aparece si no está en la
  allowlist (probado).
- **No registra dinero:** no crea `order_transactions`, no toca `invoices` ni `orders` (probado).
- **No cambia precio ni stock:** no llama a `collectTotals` ni a las reglas de inventario
  (probado).
- **Concurrencia y propiedad:** mismo patrón que 03D (`bloquearParaEditar`, grupo comercial apto,
  RT-03).
- **Cambio de cliente:** ya limpiaba `cart_payment` desde 03B; se confirmó, sin duplicar lógica.

### 36.2 Hallazgo de implementación: anidar `CarritoComercial::operar` pierde el Cart del facade

`CarritoComercial::operar()` hace `Cart::setCart()` y, en su `finally`, `Cart::resetCart()`.
Llamar a `operar()` **dentro** de otro `operar()` en curso (por ejemplo, para reutilizar el
cálculo de métodos ofrecidos al validar antes de `savePaymentMethod`) deja el Cart del facade en
`null` **antes** de que el código externo pueda usarlo, sin lanzar ninguna excepción
(`savePaymentMethod` solo devuelve `false` si no hay carrito). El servicio separa un método que
asume que el Cart ya está fijado (`ofrecidosConCartYaFijado`) de otro que lo fija por sí mismo
(`metodosOfrecidos`, para usarse en solitario). **Regla para el resto del módulo:** nunca llamar
a `CarritoComercial::operar()` dentro de un `operar()` ya en curso.

### 36.3 `estadoInicialEsperado` (informativo)

Se deriva de la configuración **vigente** de Bagisto para ese método
(`generate_invoice`, `invoice_status`), sin cambiarla y sin suponer que D-48 ya está desplegada:

| Configuración | Estado informado |
|---|---|
| `generate_invoice = 0` | `sin_factura_automatica` |
| `generate_invoice = 1`, `invoice_status = paid` | `pago_confirmado` |
| `generate_invoice = 1`, `invoice_status != paid` | `pago_pendiente` |

Con la configuración auditada de producción (§32.5) —cashondelivery pendiente,
moneytransfer aún confirmado—, el DTO informa `pago_confirmado` para moneytransfer: **no miente**,
refleja lo que Bagisto haría hoy. D-48 sigue pendiente de desplegarse; cuando se autorice el
cambio de configuración, el mismo servicio informará `pago_pendiente` sin tocar código.

### 36.4 Requisito futuro aprobado por Camilo para Cartera (documentado, sin implementar)

- **Dos formas de registrar dinero, ambas futuras:**
  - **A. Pago de un pedido específico:** total o parcial, sobre su factura (ya diseñado en §35).
  - **B. Abono general del cliente:** un monto que se reparte entre varios pedidos abiertos.
- **Distribución del abono general:**
  - **Por defecto: FIFO** (pedidos más antiguos primero). Ejemplo: pedidos A 300.000, B 250.000 y
    C 650.000 con un abono de 750.000 → A y B quedan pagados y C recibe 200.000, con saldo
    450.000.
  - **Alternativa: distribución manual**, donde el asesor decide cuánto aplicar a cada pedido o
    factura.
  - **Regla dura:** lo distribuido debe ser exactamente el dinero recibido; nunca más. Un monto
    por encima del saldo total aplicable **se rechaza**: no se crea saldo a favor, wallet, crédito
    ni anticipo (conceptos no aprobados).
- **Relación con D-53 — el abono general es UNA operación comercial, no varias sueltas:** por
  dentro producirá varias transacciones nativas contra varias facturas (una por factura afectada,
  reutilizando las primitivas de §35, sin un motor de reparto paralelo), pero de cara al negocio
  es una sola operación con un único resultado. Su implementación futura deberá garantizar:
  - **atomicidad del reparto completo:** todas las aplicaciones se confirman o ninguna; un abono
    de 750.000 repartido en A, B y C no puede dejar A y B aplicados si C falla;
  - **idempotencia de la operación global** (una clave para todo el abono, no solo por factura);
  - **bloqueos en un orden estable** sobre todas las facturas afectadas, para evitar deadlocks
    entre abonos simultáneos que toquen pedidos en común;
  - **reversión completa** del reparto si alguna aplicación individual falla.
  
  Esto se analiza y garantiza en la implementación futura del abono general; no se resuelve
  reutilizando `RegistrarPagoPedidoService` en un bucle sin esas garantías.
- **No implementado ahora:** Cartera, pago de pedido, abono general, FIFO, distribución manual,
  ninguna Transaction ni `RegistrarPagoPedidoService`.

### 36.5 Pruebas y regresión

`Fase03ePagoBorradorTest`: 18 casos (03E-01 a 03E-18b), que cubren la allowlist, la selección
válida e inválida (inexistente, no permitido, desactivado), el cambio de método, la no creación
de Transaction/Invoice/Order, la no alteración de precio y stock, el cambio de cliente, un método
que deja de estar disponible, un gateway fuera de la allowlist, el estado no editable, la
propiedad y el DTO informativo.

**Nota de infraestructura de pruebas:** `CoreConfigRepository` cachea `getConfigData()`
(`config/repository.php`) y solo se invalida mediante los eventos del repositorio Prettus
(`RepositoryEntityCreated`/`Updated`), que un `UPDATE` SQL directo no dispara. El helper de
prueba `f03e_configurarPago` escribe y después vacía la caché (`Cache::flush()`), replicando lo
que hace el panel al guardar configuración. Es infraestructura de la prueba, no del servicio.

---

## 37. Auditoría 03F: confirmación del pedido (2026-09-27)

**Solo auditoría y diseño:** no hay código, migraciones, staging ni commit. HEAD `83df25bbbf`.
Evidencia: Bagisto 2.4.9 instalado y `lpz_saas` (solo SELECT, sin datos personales). Hay
decisiones nuevas **propuestas, sin numerar** (§37.12): las decide Camilo Gaitán.

### 37.1 Flujo nativo real de creación del pedido

`Admin\OrderController::store`:

1. `Cart::setCart`;
2. `Cart::hasError()` (stock insuficiente o pedido mínimo);
3. `Cart::collectTotals()`;
4. `validateOrder()` (dirección de envío y facturación, tarifa elegida y pago; **no** comprueba
   si el cliente está suspendido; la tienda sí);
5. solo acepta `cashondelivery` o `moneytransfer`;
6. `OrderResource($cart)` (incluye **`cart_id`**);
7. `OrderRepository::create($data)`;
8. **`Cart::removeCart($cart)` en el controlador**, no en el repositorio.

La tienda, en cambio, desactiva el carrito (`deActivateCart`).

`OrderRepository::create` = `createOrderIfNotThenRetry($data)`:

```text
DB::beginTransaction()
try:
  checkout.order.save.before
  Order::create(status = pending, increment_id = OrderSequencer)
  payment, direcciones de envío y facturación
  por cada ítem: OrderItem (+ hijos) → manageInventory (reserva) → enlaces descargables
  checkout.order.save.after            ← todos los listeners, DENTRO de la transacción
catch (Exception):
  DB::rollBack()
  CouponUsageLimitExceededException → relanza sin reintentar
  Log::error(..., ['data' => $data])   ← pedido completo con datos personales
  intento < max (sales.order_settings.order_creation.max_retry_attempts, por defecto 3)
    → se llama a sí mismo (pedido nuevo, increment_id nuevo)
  si no → relanza
finally:
  DB::commit()                         ← se ejecuta SIEMPRE, también tras un rollback
```

- **Idempotencia nativa:** no existe. Un mismo carrito puede producir varios pedidos:
  `orders.cart_id` no tiene índice, ni UNIQUE, ni FK (hoy hay 0 duplicados en `lpz_saas`).
- **Pedido a medias:** no queda ninguno mientras el repositorio **no** esté dentro de otra
  transacción, porque cada intento se revierte entero.
- **Hallazgo crítico: no envolverlo en una `DB::transaction` propia.** Dentro de una
  transacción externa, el camino de reintento hace un `DB::commit()` extra en el `finally` del
  primer intento. Eso lleva el nivel de transacción de 1 a 0 y **confirma la transacción externa
  antes de tiempo**, soltando sus bloqueos y rompiendo su atomicidad sin avisar. En el camino
  exitoso sin reintento no ocurre. Conclusión: **03F no debe envolver `OrderRepository::create`
  en una transacción propia**.

### 37.2 Reserva de inventario, stock y factura

- **Reserva:** `OrderItemRepository::manageInventory` suma la cantidad a
  `product_ordered_inventories` con un **leer-sumar-escribir sin bloqueo**. Dos pedidos
  simultáneos pueden **perder parte de la reserva** (ambos leen 0 y ambos escriben 10).
- **Stock:** **el repositorio no valida stock.** Solo lo hacen el controlador o la tienda antes
  de crear el pedido (`Cart::hasError()` / `isItemsHaveSufficientQuantity`), sin bloqueo.
- **Carrera real de sobreventa:** con stock 10 y los borradores A y B pidiendo 10 cada uno, los
  dos validan 10 ≤ 10, los dos crean pedido y ambos quedan reservados sobre 10 unidades. Bagisto
  no lo impide.
- **Índice de inventario:** el listener `Product\Order::afterCancelOrCreate` despacha
  `UpdateCreateInventoryIndexJob`. Con `QUEUE_CONNECTION=sync` (el valor de `lpz_saas`) se
  ejecuta **dentro** de la transacción del pedido y el índice queda al día al hacer commit. Con
  una cola asíncrona quedaría desfasado.
- **Reserva frente a stock físico:** el pedido **reserva**; el stock físico solo baja con el
  Shipment (§28.4). 03F no descuenta stock físico.
- **Factura automática:** `GenerateInvoice` corre dentro de la misma transacción del pedido
  (listener de `checkout.order.save.after`). `InvoiceRepository::create` abre un savepoint
  anidado. Si falla, se revierte todo el pedido y se reintenta. El estado de la factura sale de
  la configuración del método (§32.5).

### 37.3 Listeners reales de `checkout.order.save.after` (`artisan event:list`), en orden

1. **`AtribuirPedidoCreado`** (VentasAsesor).
2. `Admin\Order@afterCreated`: correo al admin (`new_order_mail_to_admin = 1`).
3. `BookingProduct`.
4. `CartRule\Order@manageCartRule`: usos de reglas y cupones con `lockForUpdate`.
5. `FPC`.
6. `Notification@createOrder`: notificación del panel.
7. **`GenerateInvoice`**: factura, que a su vez dispara `sales.invoice.save.after` y el correo de
   factura al cliente.
8. `Product@afterCancelOrCreate`: reindexado de inventario.
9. `Shop\Order@afterCreated`: correo al cliente (RT-04 lo protege).
10. `CatalogCache@flush`.

**Efectos externos dentro de la transacción:** con `QUEUE_CONNECTION=sync` y `MAIL_MAILER=smtp`,
`Mail::queue` **envía en el acto**. Si un listener posterior (por ejemplo `GenerateInvoice`)
falla, el correo al admin ya salió para un pedido revertido, y el reintento lo **vuelve a
enviar**. Además, la conversación SMTP alarga la transacción y sus bloqueos (D-52). Es deuda de
operación (una cola real), no se corrige en 03F.

### 37.4 Contradicción con el prompt (§32): el listener de atribución NO revierte el pedido

- **Lo que dice el código:** `AtribuirPedidoCreado` **nunca relanza** sus errores (decisión de
  02B1A, documentada en su clase). Si falla, deja el pedido con `fallo_tecnico` y una incidencia;
  si la base de datos falla, solo queda el log.
- **Consecuencia:** una atribución fallida **no** revierte el pedido. Es lo contrario de lo que
  daba por hecho el prompt.
- **Qué hace 03F:** respeta esa decisión vigente y garantiza que el contexto llegue siempre
  correcto (§37.8). Cambiarla sería una decisión nueva.

### 37.5 Idempotencia y enlace borrador ↔ pedido (diseño)

- **Identidad de la confirmación: el propio borrador.** Un borrador produce como máximo un pedido
  (`borradores.order_id` UNIQUE, FK RESTRICT, ya existente).
  - Reintentar la confirmación de un borrador `confirmado` **devuelve el mismo pedido**
    (`ya_existia = true`), aunque el payload haya cambiado: los snapshots del pedido son finales.
  - `(mecanismo, external_reference)` identifica la **creación** del borrador. Un canal externo lo
    usa para encontrar el borrador y confirmar por `borrador_id`. No hace falta una segunda clave.
- **Cerrar la ventana «pedido creado sin enlazar» dentro de la transacción de Bagisto:** un
  listener nuevo de VentasAsesor en `checkout.order.save.after`, registrado **antes** de los
  correos (el proveedor de VentasAsesor se carga antes y su listener ya es el primero), que:
  - lee un contexto explícito «confirmación en curso» (`cart_id` → `borrador_id`), con scoping
    igual al de `ContextoComercialDePeticion`;
  - ejecuta `UPDATE borradores SET order_id = :pedido, estado = 'confirmado' WHERE id = :borrador
    AND estado = 'confirmando' AND order_id IS NULL`;
  - si actualiza **0 filas, lanza** una excepción, y el pedido se revierte.
  
  Si el pedido hace commit, el enlace hace commit con él; si se revierte, el enlace también. **La
  ventana desaparece por construcción.**

| Ruta | Garantía |
|---|---|
| Doble clic o dos peticiones sobre el mismo borrador | El paso A bloquea el borrador (`FOR UPDATE`) y pasa de `activo` a `confirmando`. La segunda petición lo encuentra `confirmando` y devuelve `confirmacion_en_proceso`, o el pedido si ya está `confirmado` |
| Toma de un `confirmando` viejo (el proceso murió) | Permitido tras un umbral (por ejemplo, `updated_at` más antiguo que el tiempo máximo de petición). Si el proceso original seguía vivo, su UPDATE condicional y el del nuevo compiten por la misma fila: el segundo en hacer commit ve `confirmado` → 0 filas → excepción → **rollback**. **Nunca dos pedidos** |
| El proceso muere antes del commit del pedido | MySQL revierte el pedido. Queda `confirmando` sin `order_id`, recuperable por la toma anterior |
| El proceso muere después del commit | El borrador ya está `confirmado` con `order_id` (mismo commit). El reintento devuelve el pedido |
| Respuesta HTTP perdida | Igual que el caso anterior: **el mismo pedido** |

- **Conciliación defensiva** (por ejemplo, pedidos de otras vías sobre el mismo carrito): un
  borrador `confirmando` sin `order_id` se contrasta con `orders.cart_id = borradores.cart_id`
  (identidad nativa) **antes** de volver a `activo` o de tomarlo.
- **Estados:** `activo` → `confirmando` (paso A) → `confirmado` (dentro de la transacción del
  pedido). Si una validación falla **antes** de crear el pedido, un UPDATE condicional lo devuelve
  a `activo` (`WHERE estado = 'confirmando' AND order_id IS NULL`). Con `confirmando`, los
  servicios del módulo **no** permiten editar (`esEditable()` solo acepta `activo`).
- **No hace falta migración:** `order_id` UNIQUE, `estado` y `updated_at` ya existen. **Umbral de
  toma:** decisión propuesta 4.

### 37.6 Transacción propia, bloqueos y orden de revalidación

**Sin transacción externa alrededor del repositorio (§37.1).**

**Paso A** (transacción corta; bloqueo borrador → carrito, `bloquearParaEditar`):

1. borrador existente y `activo`, o `confirmado` → devolver el pedido, o `confirmando` → en
   proceso o toma;
2. propietario y permiso;
3. cliente existente, **activo y no suspendido** (P-09 bloquea aquí) y grupo comercial apto (no
   invitado);
4. carrito existente, de ese cliente, inactivo y **con líneas** (si no, `carrito_vacio`);
5. invariantes del módulo: cada línea con su solicitud y cantidad ≤ solicitado (I-13), variante
   hija real y activa (I-5, catálogo), sin `custom_price` (D-18). Si falla, **carrito
   inconsistente**, por ejemplo alterado por las rutas del panel de Bagisto;
6. pago seleccionado ∈ allowlist ∩ disponibles (03E);
7. pasar a `confirmando`.

COMMIT.

**Paso B** (sin transacción externa; contexto RT-03 y `Cart::setCart`):

8. **antes** de `collectTotals`, comprobar que todas las variantes y productos estén activos,
   porque `validateItems()` **quita en silencio** las líneas inactivas (y borra el carrito si
   queda vacío). Una línea inactiva da `variante_no_vendible`, sin tocar nada;
9. `Cart::collectTotals()`: reprecia con el grupo **actual** (RT-03), aplica reglas de carrito,
   descuentos e impuestos. Después se verifica que el conjunto de líneas no cambió;
10. stock: `Cart::isItemsHaveSufficientQuantity()`; si no alcanza, `stock_insuficiente` con las
    variantes afectadas. **Nunca** se reduce la cantidad (D-39);
11. entrega: facturación y envío presentes, `Shipping::collectRates()` y el método elegido **sigue
    entre las tarifas** (si no, `entrega_invalida`); luego `collectTotals` de nuevo, que aplica el
    envío gratis de CartRule;
12. pago de nuevo tras los totales (`isAvailable` puede depender del carrito);
13. total esperado (decisión propuesta 2): si `grand_total` ≠ el total que vio el asesor, el
    resultado es `totales_cambiaron` con los totales nuevos;
14. `Cart::hasError()` final (la misma comprobación que el panel);
15. `OrderResource`, luego `ContextoComercialDePeticion::durante(cart_id, contexto)` con el
    contexto «confirmación en curso» y después `OrderRepository::create`.

**Paso C** (después del commit):

16. `Cart::removeCart`, como el panel. La FK `bvabv_cart_fk` (SET NULL) pone `borradores.cart_id`
    en NULL; `orders.cart_id` conserva el id histórico. Si falla, el pedido ya está enlazado y el
    carrito queda huérfano (inofensivo);
17. armar el DTO (§37.10).

**Si falla B antes del pedido:** el borrador vuelve a `activo` con UPDATE condicional y sigue
editable.

**Si un listener lanza durante el pedido:** rollback de pedido, reserva, factura y enlace, y
reintento nativo; si se agotan los intentos, se devuelve a `activo` (condicional). Los correos ya
enviados no se pueden revertir (§37.3).

### 37.7 Stock concurrente: brecha real de Bagisto

- **Riesgo real (§37.2):** la validación del paso 10 no bloquea nada. Dos confirmaciones que
  compiten por las últimas unidades pueden sobrevender, y `manageInventory` puede perder parte de
  la reserva.
- **Opción propuesta sin tocar Webkul** (decisión propuesta 1):
  - bloqueos con nombre de MySQL (`GET_LOCK`) por variante, en orden ascendente, tomados antes del
    paso 10 y liberados después del commit del pedido;
  - al ser de sesión y no transaccionales, funcionan **sin** transacción externa;
  - serializan las confirmaciones de VentasAsesor que compiten por la misma variante, que así ven
    el índice ya actualizado (reindexado sync dentro del pedido anterior).
- **Límites:**
  - no protege frente a pedidos de la tienda o del panel nativo (no toman el bloqueo);
  - depende de `QUEUE_CONNECTION=sync` para el índice (con una cola asíncrona habría que leer
    `product_inventories − product_ordered_inventories` en lugar del índice).
- **Sin garantía adicional:** hay que aceptar el riesgo nativo de Bagisto.

### 37.8 Atribución en 03F

- **Por qué hace falta un contexto explícito:** fuera de una ruta HTTP, el clasificador daría
  `fallo_tecnico` (`listener.origen_no_determinado`).
- **Qué hace 03F:** registra el **contexto explícito** con `ContextoComercialDePeticion::
  durante(cart_id, contexto)`, precedencia 1 del clasificador. El contexto lo construye
  `ResolverAtribucionService::paraCreacionAdministrativa` con:
  - creador: el administrador que confirma;
  - mecanismo y canal del borrador;
  - referencia externa del borrador;
  - la `SeleccionPropiedadComercial` recibida (asignarme, asignar otro asesor, empresa o sin
    asignar), cuyos permisos resuelve el propio resolver (decisiones de 02A a 02B1B).
- **Sin duplicados:** el listener existente escribe la atribución una sola vez; 03F no escribe
  atribución.

### 37.9 Precio, entrega, pago y D-48

- **Precio:** siempre se recalcula en el paso 9 con el grupo actual (RT-03), sin `custom_price`.
  Un cambio de grupo entre el borrador y la confirmación cambia el precio: se detecta con el
  total esperado (decisión propuesta 2).
- **Tarifa de envío:** se revalida con `Shipping::collectRates()` y la tarifa vigente queda
  guardada. Una tarifa distinta cambia el total y se detecta igual que el precio. El envío gratis
  sigue siendo CartRule: el método real se conserva y `shipping_amount` es el final de Bagisto.
- **Pago:** allowlist ∩ disponibles revalidado. 03F **no registra dinero**.
- **D-48:** mientras la configuración de producción no cambie, un pedido por transferencia nace
  con **factura `paid` y pedido `processing`** (semántica vieja): confirmarlo registraría como
  cobrado un dinero no verificado. Opciones (decisión propuesta 3): desplegar D-48 antes de usar
  03F en producción con transferencia, o dejar temporalmente la allowlist solo con
  `cashondelivery`.
- **D-38:** **no bloquea 03F.** No hay descuento manual; se respetan las reglas de Bagisto
  vigentes.

### 37.10 Solicitudes, borrador confirmado y DTO

- **Solicitud 100, carrito 30 → pedido de 30.** Nunca se convierten los faltantes en pedido ni se
  inventa stock.
- **Solicitudes:** siguen ligadas al borrador confirmado (nunca se borra). El faltante comercial
  (solicitado − pedido por variante, a partir de `order_items`) queda derivable para el futuro.
  No se crea un segundo pedido.
- **Borrador confirmado:** conserva `estado = confirmado`, `order_id`, propietario, creador,
  mecanismo, canal, `external_reference` y timestamps. `cart_id` pasa a NULL al borrar el carrito
  (FK SET NULL); la correlación queda en `orders.cart_id`.
- **`ConfirmarVentaService` es suficiente:** es la operación sobre el borrador. Un
  `CrearPedidoComercialService` que orqueste todo el flujo (cliente → borrador → … → confirmar)
  sería una capa de **aplicación** para la API y n8n más adelante. Hoy duplicaría: POS, API, n8n,
  WhatsApp y voz invocan la misma secuencia de servicios sin Request, sesión ni rutas.
- **DTO de confirmación:**
  - `borrador_id`, `order_id` y `increment_id`;
  - cliente (id y nombre);
  - `grand_total` y moneda;
  - método de pago (código y título) y método de entrega (código y título);
  - factura (id y estado) si existe;
  - **estado de pago derivado y saldo** (§34.11, sin persistir; con la configuración actual, la
    transferencia sale como «pagado sin evidencia de transacción» → `revisar`);
  - `ya_existia`;
  - acciones: `registrar_pago` (si hay saldo), `ver_pedido` y `nueva_venta`.
- **Errores de dominio** (nueva `ConfirmacionVentaInvalidaException`, reutilizando las de 03C,
  03D y 03E cuando aplique): `borrador_no_confirmable`, `confirmacion_en_proceso`,
  `cliente_no_confirmable`, `carrito_vacio`, `carrito_inconsistente`, `variante_no_vendible`,
  `stock_insuficiente` (con las variantes), `entrega_invalida`, `pago_invalido`,
  `totales_cambiaron` (con los totales nuevos). «Ya confirmado» es un **éxito idempotente**, no un
  error.

### 37.11 Rendimiento, datos personales y deudas

- **Coste:** `collectTotals` (dos veces), `collectRates`, precio por ítem (índice de precios) y,
  dentro de la transacción del pedido, SMTP sync y reindexado sync. La mayor latencia y duración
  de bloqueos viene del SMTP síncrono.
- **Instrumentación:** hoy no existe; se propone medir revalidación, totales, repositorio y
  respuesta total al implementar (D-52).
- **Datos personales:** cada intento fallido de `OrderRepository` escribe el pedido completo en el
  log (hasta 3 veces). Es deuda del core que no se modifica; 03F no replica ese log.
- **Cart alterado fuera del módulo:** se detecta con los invariantes del paso 5 y la comparación
  de líneas del paso 9. Las rutas del panel siguen sin bloqueo (deuda conocida).

### 37.12 Decisiones nuevas propuestas (sin numerar; las decide Camilo)

1. **Garantía anti-sobreventa en la confirmación:** bloqueos con nombre por variante (§37.7), o
   aceptar el riesgo nativo de Bagisto.
2. **Cambio de precio o tarifa entre la vista y la confirmación:** confirmación con `total_esperado`
   y conflicto `totales_cambiaron` (recomendado; imprescindible para IA y WhatsApp), frente a
   confirmar siempre con el total recalculado.
3. **Transferencia antes de desplegar D-48:** desplegar D-48 primero, o allowlist solo con
   `cashondelivery` mientras tanto.
4. **Umbral para tomar un `confirmando` viejo** y si la toma es automática en el siguiente intento
   o requiere una acción explícita.
5. **Carrito tras confirmar:** borrarlo como el panel (recomendado) o desactivarlo como la tienda.
6. **Operación:** pasar correos y reindexado a una cola asíncrona real, lo que exige que el
   reindexado se ejecute después del commit.

### 37.13 Matriz de pruebas futura (03F-01 a 03F-36)

| Id | Caso |
|---|---|
| 03F-01 | Confirmación normal |
| 03F-02 | Doble clic |
| 03F-03 | Reintento de una confirmación ya hecha → el mismo pedido |
| 03F-04 | Timeout después del commit → el mismo pedido |
| 03F-05 | Dos peticiones simultáneas → un pedido |
| 03F-06 | El stock cambia antes de confirmar → `stock_insuficiente`, sin reducir |
| 03F-07 | Dos borradores compiten por el último stock → uno solo, si se aprueba la decisión 1 |
| 03F-08 | Cliente suspendido |
| 03F-09 | El grupo del cliente cambia |
| 03F-10 | El precio cambia → `totales_cambiaron` |
| 03F-11 | La tarifa de envío cambia |
| 03F-12 | El método de pago deja de estar disponible |
| 03F-13 | Carrito vacío |
| 03F-14 | Carrito alterado fuera del módulo |
| 03F-15 | Variante desactivada → no se quita en silencio |
| 03F-16 | Atribución: asesor que se autoasigna |
| 03F-17 | Atribución: empresa, otro asesor o sin asignar según permisos |
| 03F-18 | El listener de atribución falla → el pedido **se conserva** con `fallo_tecnico` (§37.4) |
| 03F-19 | Factura automática |
| 03F-20 | Transferencia con la configuración actual → factura `paid` (documentado) |
| 03F-21 | Contra entrega |
| 03F-22 | Recogida en bodega |
| 03F-23 | Domicilio local |
| 03F-24 | Envío nacional |
| 03F-25 | Solicitud 100, carrito 30 → pedido de 30 |
| 03F-26 | `order_id` enlazado dentro de la misma transacción |
| 03F-27 | Carrito después del pedido (borrado; `borradores.cart_id` en NULL y `orders.cart_id` conservado) |
| 03F-28 | Fallo antes del pedido → vuelve a `activo` |
| 03F-29 | Fallo durante el pedido → rollback completo y vuelve a `activo` |
| 03F-30 | `confirmando` recuperable |
| 03F-31 | Sin transacción de pago |
| 03F-32 | Sin `custom_price` |
| 03F-33 | Sin descuento de stock físico antes del Shipment |
| 03F-34 | 03F nunca envuelve el repositorio en una transacción externa (regresión del commit prematuro) |
| 03F-35 | Toma de un `confirmando` viejo mientras el original sigue vivo → un solo pedido (UPDATE condicional) |
| 03F-36 | Enlace condicional con 0 filas → el pedido se revierte |

---

## 38. Cierre técnico 03F: concurrencia de stock y recuperación de `confirmando` (2026-09-27)

**Solo diseño:** no hay código, migraciones, staging ni commit. HEAD `83df25bbbf`.

**Decisiones que Camilo Gaitán tomó en este paso** (registradas en el Doc. 04, sin numerar):

- se mantiene 02B1A: una atribución fallida no revierte el pedido;
- **`total_esperado` aprobado**: si no coincide, `totales_cambiaron` con el total nuevo;
- 03F se desarrolla, pero la transferencia **no** se usa en producción hasta desplegar D-48;
- el carrito se **borra** tras confirmar;
- la cola asíncrona queda fuera de 03F.

**Quedan dos propuestas técnicas pendientes de aprobación:** A (stock) y B (recuperación).

### 38.1 Recurso real de stock (verificado)

| Pieza | Tabla o código | Filas estables |
|---|---|---|
| Stock físico | `product_inventories` (UNIQUE `product_id`, `inventory_source_id`, `vendor_id`) | Una por variante, fuente y vendedor |
| Reservas | `product_ordered_inventories` (UNIQUE `product_id`, `channel_id`) | Una por variante y canal, **solo si ya hubo alguna reserva** (hoy 93 de 300 variantes) |
| Vendible (índice) | `product_inventory_indices` (UNIQUE `product_id`, `channel_id`), calculado por `Indexers\Inventory::getQuantity` | Σ `inventories.qty` de las fuentes **activas del canal** − `ordered.qty` del canal |
| Reserva del pedido | `OrderItemRepository::manageInventory` (único llamador: `OrderRepository`, línea 77) | Leer-sumar-escribir sin bloqueo sobre `ordered_inventories` |
| Salida física | `ShipmentItemRepository::updateProductInventory` | Leer-restar-escribir sin bloqueo sobre `ordered_inventories` e `inventories` |
| Fila siempre presente | `products` (variante) | Sí, una por variante |

`lpz_saas`: MariaDB 10.4.28, `REPEATABLE-READ`, `innodb_lock_wait_timeout = 50`, sin conexiones
persistentes.

**Carrera demostrada:** con stock 10, A y B validan 10 ≤ 10 fuera de toda transacción, los dos
crean pedido, y `manageInventory` de cada uno lee `ordered = 0` y escribe 10. Resultado: dos
pedidos de 10 sobre 10 unidades y una reserva final de **10 en lugar de 20** (actualización
perdida). Bagisto no valida stock dentro de la transacción del pedido.

### 38.2 Alternativas, con la arquitectura real

| Opción | Cobertura | Interacción con `OrderRepository` y reintentos | Varias variantes | Riesgo o coste |
|---|---|---|---|---|
| **A. `GET_LOCK` por variante (VentasAsesor)** | **Solo ByLopez contra ByLopez**. La tienda y el panel no lo toman | El bloqueo es de sesión: `COMMIT` y `ROLLBACK` no lo liberan, así que sobrevive a los reintentos nativos. No necesita transacción externa | Varios bloqueos por sesión (MariaDB ≥ 10.0.2), en orden ascendente | Se pierde en silencio si Laravel reconecta; se libera solo si el proceso muere. **No protege el inventario global** |
| B. Bloqueo de fila `FOR UPDATE` en una transacción externa de VentasAsesor | Solo ByLopez | **No viable de forma segura**: `createOrderIfNotThenRetry` hace `DB::commit()` en su `finally`, incluso tras un rollback. En el camino de error o reintento confirma la transacción externa y suelta los bloqueos a mitad de la operación. Llamarlo con `maxAttempts = 1` evita el reintento pero **no** ese commit en el error | Orden fijo posible | Frágil; depende de un detalle interno |
| C. Bloqueo de fila sobre `ordered_inventories` | Solo ByLopez | Igual que B. Además, la fila **no existe** para 207 de 300 variantes | — | Condición de carrera en la inserción (el UNIQUE la corta con un error y reintento) |
| D. Serialización propia (tabla de semáforos) | Solo ByLopez | Necesita transacción o `GET_LOCK` igualmente | — | Migración y complejidad sin ventaja sobre A |
| E. Aceptar el riesgo nativo | Ninguna | — | — | Sobreventa y reservas perdidas |
| **F. «Cerco de inventario» dentro de la transacción de Bagisto:** un listener de VentasAsesor en **`checkout.order.save.before`** (hoy sin listeners; se dispara dentro de la transacción del pedido y **antes** de cualquier escritura) | **Global**: todo pedido pasa por `OrderRepository` (tienda, panel y ByLopez) | El bloqueo vive en la **propia** transacción de Bagisto, que se libera al hacer commit o rollback. Un reintento vuelve a ejecutar el cerco. No hace falta transacción externa ni tocar Webkul (mismo patrón de extensión que RT-03 y RT-04) | Bloquea **todas** las variantes del pedido de una vez, ordenadas por id ascendente, así que no hay deadlocks entre pedidos | **Cambia el comportamiento global:** un checkout de la tienda que antes sobrevendía ahora falla al crear el pedido. Cada fallo pasa por los reintentos nativos y su log con datos personales (deuda existente) |

**Detalle de F** (propuesta; no implementada):

1. **Qué bloquea:** de `$data['items']` salen las variantes con `manage_stock` (hijos en los
   configurables). Se ejecuta `SELECT id FROM products WHERE id IN (…) ORDER BY id FOR UPDATE`,
   que usa una fila que existe siempre.
2. **Qué valida:** con **lecturas bloqueantes**, vendible = Σ `product_inventories.qty` de las
   fuentes activas del canal − `product_ordered_inventories.qty` (la misma fórmula que
   `Indexers\Inventory::getQuantity`), respetando `manage_stock` y `back_orders`. Si no alcanza,
   lanza `stock_insuficiente` con las variantes.
3. **Por qué basta la reserva nativa:** con las filas `products` bloqueadas por **todo** pedido
   concurrente, la reserva nativa (`manageInventory`) queda serializada. Su lectura consistente se
   establece **después** de obtener el bloqueo (es la primera del intento, ya dentro de la
   transacción) y ve los commits anteriores. No hace falta sobrescribir `manageInventory`.
4. **Espera:** `innodb_lock_wait_timeout` (50 s por defecto). El bloqueo dura lo que dura la
   transacción del pedido, incluidos el SMTP y el reindexado síncronos (§37.3), así que dos ventas
   de la misma variante esperan una por la otra. Al agotarse la espera, error 1205 → reintento
   nativo → `stock_ocupado` o reintento para el usuario.

**Riesgo residual incluso con F:**

- `ShipmentItemRepository` (salida física) sigue con su leer-restar-escribir sin tomar el cerco.
  Un Shipment simultáneo a un pedido de la misma variante puede perder la actualización de
  `ordered_inventories`. Es raro (acción del panel), pero existe.
- Las escrituras directas a `product_inventories` (recepciones de CorteConfeccion, ajustes
  manuales) no pasan por el cerco.
- **Garantía global completa:** además del cerco, exigiría que Shipment y los ajustes de
  inventario tomen el mismo bloqueo. Eso toca módulos ajenos o Webkul y queda como deuda.

### 38.3 Recomendación de stock (PROPUESTA TÉCNICA PARA CAMILO)

> **Aprobada el 2026-09-27 como D-54** e implementada (§39.3). Límite verificado: Bagisto 2.4.9
> no reserva las variantes de configurables (§39.4). `RELEASE_ALL_LOCKS()` no existe en
> MariaDB 10.4 (afecta solo a la alternativa `GET_LOCK`, descartada).

- **Recomendada: F, «cerco de inventario»** en `checkout.order.save.before`.
  - **Garantía:** ningún pedido creado por `OrderRepository` (**tienda, panel y ByLopez**) se
    confirma con stock insuficiente frente a otro pedido concurrente, y las reservas dejan de
    perderse entre pedidos.
  - **No garantiza:** la carrera con Shipment y con las escrituras directas de inventario.
  - **Coste:** cambia el comportamiento global de la tienda, así que requiere aprobación.
- **Alternativa si Camilo no quiere tocar el comportamiento global: A, `GET_LOCK`**:
  - **identidad:** `bylopez:stock:<product_id>`, con el id entero de la variante, por debajo de 64
    caracteres;
  - **orden:** por `product_id` ascendente;
  - **espera:** configurable, por ejemplo 10 s; si no se obtiene, `stock_ocupado`;
  - **liberación:** `RELEASE_ALL_LOCKS()` en un `finally` tras el commit del pedido; si el
    proceso muere, MariaDB lo libera;
  - **verificación:** antes de crear el pedido, `IS_USED_LOCK` = `CONNECTION_ID()`, para detectar
    una reconexión.
  - **Garantía:** **solo entre ventas de ByLopez**; la tienda y el panel siguen pudiendo
    sobrevender. **No debe presentarse como «stock protegido».**
- **No recomendadas:** B, C, D y E.

### 38.4 Prueba de concurrencia real (diseño)

- **Por qué no sirven las pruebas actuales:** `DatabaseTransactions` envuelve cada prueba en una
  transacción invisible para otras conexiones, y un solo proceso PHP no es concurrente.
- **Diseño:**
  - un grupo de pruebas aparte (`@group concurrencia`) contra `lpz_saas_testing`, con datos
    **confirmados** (sin la transacción envolvente) y limpieza al final;
  - **dos procesos PHP reales** (Symfony Process: dos `php artisan` de prueba o dos scripts), cada
    uno con su conexión, sincronizados con una barrera (un `GET_LOCK` del proceso padre que se
    libera para arrancar a la vez);
  - para forzar el entrelazado: un gancho **solo de prueba** hace esperar a A dentro de su
    transacción después del cerco, mientras B arranca. Se afirma que B queda bloqueado y termina
    con `stock_insuficiente`.
- **Casos:**
  - stock 10, A = 10 y B = 10 → exactamente un pedido; el otro, `stock_insuficiente`; reservas =
    10;
  - A = [v1, v2] y B = [v2, v1] con stock suficiente → dos pedidos, **cero deadlocks** (el orden
    ascendente lo evita);
  - espera agotada (A retiene más que el tiempo de espera de B) → error controlado, sin pedido de
    B.

### 38.5 Recuperación de `confirmando`: evidencia disponible (verificada)

| Dato | Utilidad |
|---|---|
| `borradores.order_id` (UNIQUE, FK RESTRICT) | Enlace definitivo |
| `borradores.estado` | `activo`, `confirmando`, `confirmado`, `descartado`; `esEditable()` solo con `activo` |
| `borradores.cart_id` (FK SET NULL) | Se pierde al borrar el carrito |
| `orders.cart_id` (sin índice, UNIQUE ni FK) | Correlación con el carrito; **no garantiza unicidad** |
| `updated_at` | Genérico: **cualquier** guardado Eloquent lo cambia. No sirve como lease |
| `external_reference` | Identidad de creación, no de un intento de confirmación |
| Señal de proceso vivo | **No existe ninguna fiable** (no hay heartbeat; un `GET_LOCK` indicaría que la conexión vive, pero no impide que un proceso reconectado siga adelante) |

**Conclusión:** los campos actuales **no bastan** para que un intento viejo que despierta no
pueda enlazar un pedido construido con datos obsoletos. Ejemplo:

1. A queda colgado;
2. B toma la confirmación y termina en `totales_cambiaron`, así que el borrador vuelve a `activo`;
3. el asesor edita y confirma de nuevo (C), y el borrador vuelve a `confirmando`;
4. A despierta: `estado = confirmando AND order_id IS NULL` **se cumple**, y A enlazaría su pedido
   con el carrito y el total viejos.

**Hace falta identidad del intento (fencing).**

### 38.6 Diseño de recuperación (PROPUESTA TÉCNICA PARA CAMILO)

> **Aprobada el 2026-09-27 como D-55** e implementada (§39.2). La columna se llama
> `confirmation_token`, como se aprobó en D-55 (no `confirmacion_token`).

**Esquema** (una migración pequeña, 000008, en `bylopez_va_borradores_venta`; **no** creada):

- `confirmacion_token` (char 36, nullable): identidad del intento (UUID);
- `confirmacion_iniciada_en` (timestamp, nullable): inicio del lease, separado de `updated_at`.

La propiedad comercial (`propietario_admin_id`, `asignado_hasta`) no se toca: el lease es del
**intento técnico**, no del asesor.

**Flujo:**

1. **Paso A** (transacción corta; `FOR UPDATE` del borrador):
   - `confirmado` → devolver el pedido (`ya_confirmado`);
   - `confirmando` con lease vigente → `confirmacion_en_proceso`;
   - `confirmando` con lease vencido → `reconciliarConfirmacion` (§38.7) y, solo si es
     `recuperable`, **nuevo token** y nueva hora de inicio;
   - `activo` → validaciones baratas, luego `confirmando` + token T + hora de inicio.
   
   Commit. El intento lleva T.
2. **Cerco del intento, `checkout.order.save.before`**, dentro de la transacción de Bagisto y
   antes de cualquier escritura. Con el contexto «confirmación en curso» (`cart_id` →
   `borrador_id` + T):
   - `SELECT … FROM borradores WHERE id = :b AND estado = 'confirmando' AND confirmacion_token =
     :T AND order_id IS NULL FOR UPDATE`;
   - si da 0 filas, lanza `confirmacion_obsoleta`. **No se ha escrito nada del pedido**, y los
     reintentos nativos fallan igual;
   - la fila del borrador queda **bloqueada hasta el commit o rollback del pedido**, así que una
     toma concurrente espera.
   
   Además, un pedido sin contexto de ByLopez cuyo `cart_id` pertenezca a un borrador `activo` o
   `confirmando` **se rechaza**. Eso cierra la confirmación del carrito de un borrador desde las
   rutas del panel.
3. **Enlace, `checkout.order.save.after`** (primer listener):
   - `UPDATE borradores SET order_id = :o, estado = 'confirmado', confirmacion_token = NULL WHERE
     id = :b AND confirmacion_token = :T AND estado = 'confirmando' AND order_id IS NULL`;
   - debe afectar 1 fila; si no, lanza, y el pedido se revierte (con el cerco activo no debería
     ocurrir).
4. **Fallo antes del pedido** (validación, `totales_cambiaron`, stock) o **tras agotar los
   reintentos**: `UPDATE … SET estado = 'activo', confirmacion_token = NULL,
   confirmacion_iniciada_en = NULL WHERE id = :b AND confirmacion_token = :T AND estado =
   'confirmando' AND order_id IS NULL`. Si da 0 filas, otro intento tomó la confirmación y no se
   hace nada. **Nunca queda atascado por `totales_cambiaron`.**
5. **Paso C:** borrar el carrito (decidido); `cart_id` pasa a NULL.

**Lease:**

- `confirmacion_iniciada_en + lease` (configurable, mayor que el tiempo máximo de petición más
  SMTP) solo decide **cuándo se puede intentar** una toma.
- **La seguridad no depende del reloj:** el token, el `FOR UPDATE` del cerco y los UPDATE
  condicionados por token garantizan que **un intento sin el token vigente no puede crear ni
  enlazar un pedido**.
- Si A sigue vivo **dentro** de su transacción, retiene la fila y la toma de B espera hasta que A
  termine. Si A hizo commit, B ve `confirmado`. Si A revirtió, B toma.

**Escenario A → cuelgue → B toma → A despierta:**

1. A tenía el token T1;
2. vence el lease y B toma: la fila pasa a T2 (si A estaba dentro de su transacción, B espera);
3. A despierta y llama a `OrderRepository`;
4. el cerco lee T2 ≠ T1 → `confirmacion_obsoleta` **antes** de insertar pedido, ítems, reservas o
   factura, y antes de cualquier correo;
5. rollback total (y los reintentos nativos repiten el mismo rechazo);
6. el UPDATE de reversión de A con T1 afecta 0 filas;
7. **B confirma. Un solo pedido.**

**Orden de listeners:** Laravel 12 no tiene prioridades; el orden es el de registro.
VentasAsesor registra su `EventServiceProvider` en `register()`, y por eso su listener ya es el
primero de `checkout.order.save.after` (`artisan event:list`, §37.3). `save.before` hoy no tiene
listeners. El cerco en `save.before` no depende del orden de `after`, y el enlace se registra
primero en `after`. **Una prueba debe afirmar ese orden** (si alguien reordena los proveedores, se
detecta).

### 38.7 `reconciliarConfirmacion(borradorId)`: clasificación conceptual

| Caso | Evidencia | Clasificación |
|---|---|---|
| `confirmado` + `order_id` | Enlace definitivo | `ya_confirmado`: se devuelve el pedido (también tras una respuesta HTTP perdida) |
| `confirmando` + lease vigente | Token presente | `confirmacion_en_proceso` |
| `confirmando` + lease vencido + `order_id` NULL + `cart_id` presente + **0** pedidos con `orders.cart_id = cart_id` | El proceso murió antes o durante el pedido (con rollback) | `recuperable`: se toma con nuevo token, o se devuelve a `activo` |
| `confirmando` + `order_id` presente | Imposible por construcción (el enlace fija los dos a la vez) | `inconsistente` |
| `order_id` NULL + **≥ 1** pedido con `orders.cart_id = cart_id` | Pedido creado fuera del flujo (el cerco lo impediría) o de antes | `inconsistente`: **nunca** se enlaza sola |
| **2 o más** pedidos con el mismo `cart_id` | `orders.cart_id` no es UNIQUE | `inconsistente` **grave**: no se elige el primero ni el último |
| `confirmando` o `activo` con `cart_id` NULL | Carrito borrado fuera del flujo | `inconsistente` |

- No se crea `confirmado_recuperado`: con el enlace dentro de la transacción del pedido, un pedido
  del flujo nunca queda sin enlazar. `inconsistente` exige una acción humana, que se diseñará
  aparte.
- **Casos por momento de fallo:**
  - muerte antes de llamar a `OrderRepository` → `recuperable` al vencer el lease;
  - muerte durante `OrderRepository` → MariaDB revierte y se liberan los bloqueos → `recuperable`;
  - muerte después del commit → `ya_confirmado`.
- **Tras el éxito**, el carrito se borra y `cart_id` pasa a NULL, pero `estado = confirmado` y
  `order_id` bastan: no hacen falta snapshots.

### 38.8 Pruebas añadidas a la matriz 03F

| Id | Caso |
|---|---|
| 03F-37 | Concurrencia real con dos procesos: stock 10, A = 10 y B = 10 → un pedido, el otro `stock_insuficiente`, reservas = 10 |
| 03F-38 | A = [v1, v2] y B = [v2, v1] → sin deadlocks |
| 03F-39 | Espera de bloqueo agotada → error controlado, sin pedido |
| 03F-40 | A pierde el lease, B toma, A despierta y llama a `OrderRepository` → `confirmacion_obsoleta` en `save.before`, rollback total, sin factura ni correos, y B confirma |
| 03F-41 | La toma de B espera mientras A está dentro de su transacción → A confirma y B recibe `ya_confirmado` |
| 03F-42 | Pedido del panel sobre el carrito de un borrador → rechazado por el cerco |
| 03F-43 | Dos pedidos con el mismo `cart_id` → `inconsistente`, sin elegir ninguno |
| 03F-44 | `totales_cambiaron` → el borrador vuelve a `activo` con token nulo |
| 03F-45 | Orden de los listeners afirmado (cerco en `before`, enlace primero en `after`) |
| 03F-46 | Reintento tras una respuesta perdida → el mismo pedido |

---

## 39. 03F implementada: confirmación idempotente y concurrente (2026-09-27)

**Implementación, sin commit.** HEAD `83df25bbbf`. D-54 y D-55 aprobadas por Camilo Gaitán
(Doc. 04). Migración 000008 aplicada **solo** en `lpz_saas_testing`. Sin cambios en Webkul,
Logistica ni CorteConfeccion.

### 39.1 Piezas

| Pieza | Responsabilidad |
|---|---|
| `Services/Venta/ConfirmarVentaService` | `confirmar(borradorId, actor, totalEsperado, selección?)` y `reconciliar(borradorId)`. Sin Request, Route, Session, Blade ni Vue |
| `Listeners/CercoCreacionPedido` | Primer y único listener de `checkout.order.save.before`: token vigente (D-55), inventario (D-54) y carrito de borrador fuera de confirmación |
| `Support/Venta/CercoInventario` | `FOR UPDATE` de `products` en orden ascendente y revalidación de stock con la fórmula de Bagisto |
| `Listeners/EnlazarPedidoConBorrador` | Primer listener de `checkout.order.save.after`: enlace condicionado por token |
| `Support/Venta/ConfirmacionEnCurso` | Registro `scoped` `cart_id` → borrador + token (mismo patrón que `ContextoComercialDePeticion`) |
| `DataObjects/ConfirmacionVenta`, `ReconciliacionConfirmacion`; `Enums/EstadoReconciliacionConfirmacion` | Resultado de dominio y clasificación |
| `Exceptions/ConfirmacionVentaInvalidaException`, `PedidoRechazadoPorCercoException` | Códigos estables (§39.5) |
| Migración `2026_09_27_000008_agregar_intento_de_confirmacion_al_borrador_venta` | `confirmation_token` CHAR(36) NULL y `confirmacion_iniciada_en` TIMESTAMP NULL, sin índices (toda consulta filtra por `id`) |
| `PagoBorradorService::metodoVigenteConCartYaFijado()` | Reutiliza la semántica 03E (allowlist ∩ `Payment::getPaymentMethods()`) |
| Config `ventasasesor.confirmacion.lease_segundos` | Lease técnico, por entorno (`VENTASASESOR_CONFIRMACION_LEASE_SEGUNDOS`, 300 por defecto); no es regla de negocio |

### 39.2 Flujo de `confirmar` (D-55)

1. **Paso A** (transacción corta, `FOR UPDATE` del borrador): permiso y propietario; después,
   `reconciliar` y, según el resultado:
   - `ya_confirmado` → el mismo pedido con `ya_existia = true`;
   - `confirmacion_en_proceso` → error;
   - `inconsistente` → error con la evidencia, sin repararla;
   - `descartado` → `borrador_no_confirmable`;
   - `confirmable` o `recuperable` → `confirmando`, un `confirmation_token` nuevo (UUID) y
     `confirmacion_iniciada_en = now()`.
   
   Commit.
2. **Paso B** (**sin transacción exterior**), en este orden:
   1. token vigente;
   2. carrito existente;
   3. cliente: existe, activo, no suspendido, no invitado y con grupo comercial apto;
   4. carrito inactivo y con líneas, sin `custom_price`, variante real e hija de su padre,
      producto y variante activos (**antes** de `collectTotals`, que quitaría la línea en
      silencio) y cantidad ≤ solicitud (I-13);
   5. `collectTotals` con RT-03 (grupo actual), y comprobación de que las líneas no cambiaron;
   6. stock temprano;
   7. entrega: `saveShippingMethod` recalcula las tarifas y exige el método (la recogida solo
      existe con la bodega real), y después `refreshCart` + `collectTotals`;
   8. pago vigente;
   9. `total_esperado`;
   10. `Cart::getErrors()` (la misma comprobación final del panel);
   11. `OrderResource`, contexto de atribución explícito
       (`ResolverAtribucionService::paraCreacionAdministrativa` con el mecanismo, el canal y la
       referencia del borrador), `ConfirmacionEnCurso` y, por último, `OrderRepository::create`.
3. **Paso C:** `CartRepository::delete` (como el panel, sin tocar la sesión). La FK pone
   `cart_id` en NULL. Si falla, se registra en el log y el pedido ya está enlazado.

**Liberación:** cualquier fallo del paso B, o de `OrderRepository` tras agotar sus reintentos,
ejecuta `UPDATE … SET estado = 'activo', token = NULL, iniciada = NULL WHERE id AND token = T AND
estado = 'confirmando' AND order_id IS NULL`. Un intento viejo afecta 0 filas: nunca reactiva ni
pisa otro intento.

**Reconciliación (§38.7), reglas implementadas:**

- A: confirmado + `order_id` → `ya_confirmado`.
- B: confirmando + `order_id` → `inconsistente`.
- C: 1 pedido con el `cart_id` → `inconsistente`, sin enlazarlo.
- D: 2 o más → `inconsistente` grave, sin elegir ninguno.
- E: lease vigente → en proceso.
- F: lease vencido → `recuperable`.
- G: `cart_id` NULL → `inconsistente`.
- Además: un `activo` con un pedido de su carrito también es `inconsistente`. Un `confirmando`
  sin hora de inicio (dato anterior a D-55) cuenta como lease vencido.

### 39.3 Cerco de creación de pedidos (D-54) y enlace

**`save.before` (dentro de la transacción de Bagisto, antes de insertar nada), en orden:**

1. **Solo confirmaciones ByLopez:** `SELECT … FROM borradores WHERE id AND cart_id AND estado =
   'confirmando' AND confirmation_token = T AND order_id IS NULL FOR UPDATE`. Con 0 filas lanza
   `confirmacion_obsoleta`, sin crear pedido, ítems, reservas, factura ni correos; los
   reintentos nativos repiten el rechazo. La fila queda bloqueada hasta el commit, así que una
   recuperación concurrente espera.
2. **Todo pedido:** `SELECT id FROM products WHERE id IN (…) ORDER BY id FOR UPDATE` (padres e
   hijos del pedido). Después, el vendible = Σ `product_inventories` de las fuentes activas del
   canal − `product_ordered_inventories` del canal, **solo donde Bagisto 2.4.9 exige stock**:
   tipos `simple` y `virtual` con `manage_stock` (`back_orders` solo en simple). Los descargables
   y los de reserva no se endurecen. La primera versión los validaba y rompía el checkout de
   descargables de Bagisto, lo que la regresión detectó y se corrigió.
   - **Cantidad de la variante de un configurable = cantidad del padre** (la que valida
     `Configurable::isItemHaveQuantity`), no el `qty_ordered` del hijo, que vale 0 (§39.4).
3. **Sin confirmación ByLopez:** si el carrito pertenece a un borrador `activo` o
   `confirmando`, se rechaza con `carrito_de_borrador_fuera_de_confirmacion` (ruta nativa del
   panel). Los carritos normales no tienen borrador y pasan.
   - **Entorno sin la tabla de borradores** (000006+ sin aplicar, como `lpz_saas` hoy): no puede
     existir un carrito de borrador, así que el paso 3 se omite (existencia de la tabla en caché
     por proceso). Sin eso, este código rompería **todos** los pedidos de la tienda y el panel en
     ese entorno. El paso 2 (inventario) no usa tablas propias y sigue activo.

**Por qué la reserva nativa ve los datos correctos:** en REPEATABLE READ, la vista consistente de
la transacción nace con su primera lectura **no bloqueante**. El cerco solo hace lecturas
bloqueantes hasta el `FOR UPDATE` de `products`, así que la vista (la del cerco y la de
`manageInventory`) nace después de obtener el bloqueo y ve el commit del pedido anterior. Por eso
el cerco **debe ser el primer listener de `save.before`**, y una prueba lo afirma.

**`save.after` (primer listener, antes de la atribución, los correos, la factura y el
reindexado):** `UPDATE … SET order_id, estado = 'confirmado', token = NULL, iniciada = NULL WHERE
id AND token = T AND estado = 'confirmando' AND order_id IS NULL`. Debe afectar exactamente 1
fila; si no, lanza y el pedido se revierte.

**Orden real verificado** (`artisan event:list`):

- `save.before` = [`CercoCreacionPedido`];
- `save.after` = [`EnlazarPedidoConBorrador`, `AtribuirPedidoCreado`, Admin Order mail, …].

**Garantía, dicha exactamente (redacción corregida por Camilo, 2026-09-27):** serialización y
revalidación de inventario durante la creación de pedidos, **con la semántica nativa de
Bagisto**. Es efectiva donde Bagisto genera reservas (simples y variantes vendidas como línea
simple). **No** introduce reservas para configurables ni promete «sin sobreventa» en sus
variantes (limitación conocida, §39.4). No es consistencia global del inventario.

**Riesgos residuales:** Shipment concurrente, ajustes directos de inventario y operaciones que no
pasan por `OrderRepository`.

### 39.4 Hallazgo crítico: Bagisto 2.4.9 no reserva las variantes de configurables

**Verificado en el código y en `lpz_saas` (solo SELECT):**

- `Configurable::prepareForCart` crea la fila hija **sin** `quantity`, y la columna vale 0 por
  defecto;
- `OrderItemResource` calcula el `qty_ordered` del hijo como `quantity × padre` = 0;
- `manageInventory` reserva 0.

**En producción:**

- hijos en `cart_items`: todos con cantidad 0 salvo uno;
- hijos en `order_items`: todos con `qty_ordered` 0;
- `product_ordered_inventories`: 88 de 93 filas en 0.

**Consecuencia:** para las variantes de configurables (todo el catálogo línea × color), un pedido
no descuenta nada del vendible hasta el Shipment. Dos pedidos de 30 sobre 50 unidades se
confirman los dos, **incluso en secuencia**, por la tienda, el panel o VentasAsesor.

- El cerco D-54 serializa, pero no puede ver una reserva que no existe. La prueba «LIMITE» lo
  caracteriza.
- Con productos **simples**, la reserva funciona y la garantía concurrente queda demostrada con
  procesos reales (§39.6).
- **Corregirlo exige tocar Webkul o sustituir la reserva nativa**, lo que contradice el «no
  reserva por sí mismo» de D-54.
- **Decisión de Camilo (2026-09-27): LIMITACIÓN CONOCIDA, no se corrige.** Bagisto sigue siendo la
  autoridad. El hueco entre pedido y Shipment se hace visible solo con la señal informativa de
  §40. Traza definitiva en §40.1.

### 39.5 Errores, DTO y estado de pago

**`ConfirmacionVentaInvalidaException`:**

- `borrador_no_confirmable` (`detalles.motivo`);
- `confirmacion_en_proceso`, `confirmacion_obsoleta`;
- `confirmacion_inconsistente` (con la reconciliación);
- `cliente_no_confirmable`;
- `carrito_vacio`, `carrito_inconsistente`;
- `variante_no_vendible`;
- `stock_insuficiente` (`detalles.fase` = `revalidacion` o `cerco`, más las variantes);
- `entrega_invalida`, `pago_invalido`;
- `totales_cambiaron` (`total_esperado`, `total_nuevo` y totales).

Permiso, propietario e inexistente siguen usando `BorradorVentaInvalidoException`. El cerco lanza
`PedidoRechazadoPorCercoException` (`stock_insuficiente`, `confirmacion_obsoleta`,
`carrito_de_borrador_fuera_de_confirmacion`), que el servicio traduce.

**`ConfirmacionVenta`:**

- borrador, `order_id` e `increment_id`;
- cliente;
- total y moneda;
- método de pago y entrega;
- factura (id y estado);
- `estadoPago`, derivado y sin persistir (§34.6 y §34.11): `pendiente`, `parcial`, `pagado` o
  `revisar`, este último si hay una factura `paid` sin transacción, como la transferencia con la
  configuración actual;
- `saldo`;
- `yaExistia`.

Sin acciones de interfaz ni Eloquent.

### 39.6 Pruebas

- **Funcionales** (`tests/Feature/Fase03fConfirmacionVentaTest.php`): la matriz 03F-01 a 03F-36
  y 03F-42 a 03F-46, más regresión de panel y tienda y la prueba LIMITE.
- **Concurrencia real** (`tests/Concurrencia`, grupo `concurrencia`, fuera de las testsuites de
  `phpunit.xml`): dos procesos PHP con su propia conexión y fixtures confirmados y borrados. Se
  ejecuta con `vendor/bin/pest packages/ByLopez/VentasAsesor/tests/Concurrencia`. Casos:
  - 03F-37: carrera, entrelazado forzado y global ByLopez contra nativo;
  - 03F-38: orden inverso de variantes, sin deadlock;
  - 03F-39: espera agotada, 1205 controlado;
  - 03F-40: fencing;
  - 03F-41: la recuperación espera y recibe `ya_existia`;
  - 03F-34: el commit prematuro de una transacción exterior, demostrado.

**Resultados (2026-09-27, código final, `lpz_saas_testing`):**

| Suite | Resultado |
|---|---|
| 03F funcionales | 57/57 |
| Concurrencia real | 8/8 en tres pasadas consecutivas |
| VentasAsesor (Unit + Feature) | 634/634 |
| CorteConfeccion | 384/384 |
| Bagisto: `Admin/Sales`, `Shop/Checkout` y `Shop/Customers/OrdersTest` | 20 fallos y 136 aprobadas, **misma lista que la línea base previa a 03F** (correo al administrador sin dirección en testing, formato de moneda). Ninguno causado por el cerco |
| Pint (archivos propios) | Pasa |

**Defensas de la prueba** (no del producto):

- `f03f_sinCommitPrematuro` abre tantos savepoints como reintentos tiene el repositorio. Sin él,
  un pedido que falla dentro de `DatabaseTransactions` confirmaría la transacción de la prueba.
- La suite de concurrencia se sincroniza con archivos de señal y no con `GET_LOCK`: en MariaDB
  10.4 para Windows (`thread_handling = pool-of-threads`), un hilo del servidor bloqueado en
  `GET_LOCK` retrasaba las conexiones nuevas y volvía intermitente la prueba.

### 39.7 Otros hallazgos de la implementación

- **Envío gratis y revalidación:**
  - `saveShippingMethod` recrea las tarifas, pero el Cart del facade conserva en caché la tarifa
    elegida anterior (cargada por un `collectTotals` previo);
  - CartRule aplicaba el envío gratis sobre esa fila ya borrada y el total subía. Se corrige con
    `Cart::refreshCart()` antes del `collectTotals` final;
  - el flujo del panel no lo sufre porque carga la relación una sola vez.
- **MariaDB 10.4 no tiene `RELEASE_ALL_LOCKS()`:** corrige lo dicho en §38.3 sobre la
  alternativa `GET_LOCK` (descartada).
- **Reintentos con datos personales:** cada rechazo del cerco pasa por los 3 reintentos nativos
  y su `Log::error` con el pedido completo. Es deuda del core, conocida (§37.11).
- **`orders.cart_id` sin índice:** la reconciliación cuenta pedidos por `cart_id` con un recorrido
  completo. Es aceptable con el volumen actual, y no se toca una tabla del core.

### 39.8 Pendiente antes de producción

- aplicar las migraciones 000006, 000007 y 000008 en producción (autorización aparte). Sin
  ellas, el cerco de inventario **sí** actúa en la tienda y el panel desde el despliegue del
  código (cambio global aprobado en D-54), pero la confirmación ByLopez no funciona;
- desplegar D-48 antes de usar la transferencia en Nueva Venta;
- ~~decidir la reserva de configurables~~: decidido, limitación conocida (§39.4 y §40);
- validar el valor del lease;
- la cola asíncrona (fuera de 03F);
- la interfaz Nueva Venta;
- `CrearPedidoComercialService` (no implementado, por instrucción).

---

## 40. Inventario configurable (limitación conocida) y señal de ventas simultáneas (2026-09-27)

**Implementación sin commit** (03F sigue pendiente de revisión final). Sin cambios en Webkul,
Logistica, CorteConfeccion, tablas ni migraciones.

### 40.1 Traza definitiva del configurable

**Traza en `lpz_saas_testing`**, dentro de una transacción revertida, con stock 50 y 30 unidades
por el camino nativo del panel (`OrderResource` + `OrderRepository`):

| Paso | Configurable | Simple |
|---|---|---|
| `cart_items` | padre: el configurable, `quantity` 30; hijo: la variante, `quantity` **0** | la línea, `quantity` 30 |
| `OrderResource` | padre: `qty_ordered` 30; hijo: `qty_ordered` **0** | `qty_ordered` 30 |
| `order_items` | padre 30; hijo **0** | 30 |
| `manageInventory` recibe | la variante con **0** | el producto con 30 |
| Reserva | 0 | 30 |
| Vendible (índice) | **50** | 20 |
| Segundo pedido de 30 | **se crea** (60 vendidas sobre 50) | lo rechaza Bagisto (vendible 20) |

- **Divergencia:** tres pasos del core que se encadenan:
  - `Configurable::prepareForCart` no da cantidad al hijo, y la columna vale 0 por defecto;
  - en `OrderItemResource`, el `?? 1` no cubre el 0;
  - en `manageInventory`, `isset` acepta el 0.
- **Es nativo:** esos archivos no tienen cambios locales y ByLopez no enlaza nada en ese camino.
  El catálogo está bien configurado: `manage_stock` = 1, `back_orders` = 0 y la fuente del canal
  activa.
- **Variante real** (`lpz_saas`, solo SELECT): «Camiseta Piel Durazno Azul Rey Hombre» · M,
  variante 137, stock 38, reserva 0.
- **Decisión:** limitación conocida (Doc. 04). No se corrige.

### 40.2 `VentasSimultaneasService` (solo lectura)

- **Métodos:** `paraVariante(varianteId, actor, borradorId?)` y
  `paraVariantes(varianteIds[], actor, borradorId?)`. El lote es para la matriz S/M/L/XL.
- **Permiso:** exige `ventas_asesor.pedidos.create`. Ver nombres no cambia la propiedad ni la
  autorización de los borradores ajenos.

**Métricas** (`VentasSimultaneasVariante`):

| Campo | Fuente | Regla |
|---|---|---|
| `stockBagisto` | `product_inventory_indices` del canal (el mismo que muestra el catálogo) | Intacto; nada se le resta |
| `enEstaVenta` | Cart del borrador indicado | Nunca se cuenta en «otras» |
| `enOtrasVentasSinConfirmar` | `bylopez_va_borradores_venta` (`activo`, `confirmando`) + `cart` + `cart_items` | Configurable: variante del hijo y cantidad del **padre**. Simple: la propia línea. Sin exclusión por antigüedad |
| `confirmadosSinDespachar` | `order_items` + `orders` | `qty_ordered − qty_shipped − qty_refunded − qty_canceled` (el `qty_to_ship` nativo), en pedidos no `closed` ni `fraud` (como `Order::canShip`). Configurable: la cantidad y los despachos del **padre** (el Shipment y la cancelación actualizan el padre) |
| `confirmadosSinDespacharNoDescontados` | Lo mismo, solo lo vendido **a través de un configurable** | Cantidad informativa de pedidos confirmados pendientes de despacho que la semántica nativa del configurable todavía no refleja en el stock vendible de Bagisto. **No** es stock, disponibilidad, reserva ni inventario comprometido. Lo vendido como simple ya está reservado en `stockBagisto` y **no** se vuelve a considerar |
| `otrasVentas[]` (`OtraVentaSinConfirmar`) | Una entrada por borrador | Solo `asesorId`, `asesorNombre`, `cantidad`, `estado` y `ultimaActividad` |
| `advertencia` | Comparación | `enEstaVenta + enOtras + noDescontados > stockBagisto` (y hay otras ventas o pendientes). Solo contexto |
| `consultadoEn` | — | Hora de la consulta |

**No existe** ningún campo `disponible`, `reservado` ni `comprometido`, y ningún número resultado
de restar.

**Advertencia conceptual para la interfaz:** «Hay otras ventas sobre esta variante. Si todas se
concretan, el stock actual puede no alcanzar». No bloquea, no reduce ni reserva.

**Última actividad:** el cambio más reciente entre `cart.updated_at` (lo guarda `collectTotals` en
cada cambio) y `MAX(cart_items.updated_at)`. No `borradores.updated_at`, que no cambia al editar
líneas. No es presencia en línea.

**Solicitud comercial:** no se mezcla. La métrica simultánea es solo la cantidad materializada en
el Cart; lo solicitado (D-39) podrá mostrarse aparte en el futuro, nunca sumado.

### 40.3 Consultas y rendimiento

- **Consultas:** un número fijo por lote, independiente del número de variantes (medido: el
  mismo para 1 que para 4):
  - stock;
  - líneas de carritos (`UNION ALL` configurable/simple);
  - actividad por carrito (`GROUP BY`);
  - nombres de asesores;
  - confirmados sin despachar (`UNION ALL` + `GROUP BY`).
- **Índices usados:** `cart_items.product_id`, `parent_id` y `cart_id`; `borradores.cart_id`
  (UNIQUE); `product_inventory_indices(product_id, channel_id)`.
- **Deuda:** `order_items` **no tiene índice por `product_id`**, así que la consulta de pendientes
  recorre `order_items` una vez por lote. Es despreciable con el volumen actual (unos 150 ítems en
  producción). Un índice en esa tabla del core exigiría una decisión aparte.
- **Tiempo real:** sin WebSockets (producción usa `BROADCAST_CONNECTION=log`). La interfaz futura
  cargará al seleccionar, refrescará tras editar y hará polling por lotes cada 20–30 s mientras
  la pantalla esté visible. El servicio ya está preparado; el polling no se implementa sin
  interfaz.

### 40.4 Alcance y límites

- Uso interno de Nueva Venta y asesores. No se expone a la tienda, a clientes, a WhatsApp, a n8n
  ni a la IA como stock oficial.
- No escribe ninguna tabla (probado), no participa en `ConfirmarVentaService` ni en el cerco y no
  bloquea.
- En la interfaz, «confirmados sin despachar» debe presentarse distinguiendo:
  - en configurables, cubre la limitación nativa (el stock Bagisto aún lo cuenta);
  - en simples, el stock Bagisto ya lo descuenta. No se resta dos veces.
- **Verificación multivariante (cierre):** un pedido con M = 30 y L = 20 del mismo configurable
  genera un padre configurable por variante, con su hijo enlazado. Los pendientes pasan de 30/20
  a 20/20 tras despachar 10 de M, y a 0/20 tras completar M, sin contaminación entre tallas.
  Cancelar (en Bagisto la cancelación es por pedido) un pedido de M no afecta a uno de L. Dos
  pedidos de M (10 + 15) suman 25, sin duplicar filas.
- **Pruebas** (`Fase03fVentasSimultaneasTest`, 16 casos): stock intacto (38, sin 28), dos
  asesores, esta venta frente a otras, borrador propio como otra venta, tallas separadas, lote sin
  N+1, descartado/confirmado/confirmando/vacío, simple, pendiente con Shipment parcial y completo,
  cancelación, advertencia sin −7, última actividad, sin exclusión por antigüedad, permiso y solo
  lectura.
