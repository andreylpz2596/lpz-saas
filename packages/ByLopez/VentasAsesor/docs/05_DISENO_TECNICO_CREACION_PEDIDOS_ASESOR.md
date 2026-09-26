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
