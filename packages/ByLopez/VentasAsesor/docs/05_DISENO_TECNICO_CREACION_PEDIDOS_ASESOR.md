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

> **Aclarado 2026-10-05 (D-56, Doc. 04).** Esta regla se implementa hoy como **lectura
> derivada** en `Support/Pedidos/EstadoPagoPedido` (Mis ventas: listado, detalle y KPI
> «Pendientes de pago») y, duplicada con la misma semántica, en
> `ConfirmarVentaService::estadoPago()` (03F; deuda: unificar en `EstadoPagoPedido`). Mostrar
> el estado de pago **no es Cartera**: no registra, valida, aplica ni concilia pagos, y no
> autoriza ni bloquea el despacho.

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

> **Revisado por PAG-A (2026-10-05, Doc. 04 D-59, §42 de este documento).** Este cierre se conserva como
> historial. Cambia: (a) registrar un pago **ya no crea de inmediato** la `order_transaction` (P-01): la
> Transaction nativa nace al **confirmar**; (b) la tabla `bylopez_va_registros_pago` (§35.4, nunca creada)
> queda sustituida por `bylopez_va_pagos` + `bylopez_va_aplicaciones_pago` + `bylopez_va_pagos_eventos`;
> (c) lo «pagado» deja de ser `SUM(transacciones)` y pasa a la fórmula de §42.3; (d) el orden de bloqueos pasa
> a `pago → pedidos → facturas` (§42.5). **Siguen vigentes:** las primitivas de §35.1, la regla de estado del
> pedido de §35.2, la idempotencia por `(mecanismo, clave)` de §35.3 (con huella ampliada) y los medios de
> cobro de §35.6.

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

> **Actualizado por PAG-A (2026-10-05, §42).** El abono general **sigue siendo una sola operación
> atómica**, pero ya no produce Transactions al registrarse: crea **un pago `sin_revisar` con varias
> aplicaciones**, y las Transactions nativas (una por aplicación) se crean al **confirmar**. FIFO queda
> como **propuesta editable** (P-07) y la regla dura «nunca más que el dinero recibido» se conserva
> (P-06). El texto original se conserva abajo.

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

---

## 41. 03G-B: dominio de aplicación de «Nueva venta rápida» (2026-09-28)

**Estado: IMPLEMENTADA, sin commit, sin interfaz.** Solo capa de aplicación en
`packages/ByLopez/VentasAsesor/`. Sin migraciones, sin tablas, sin rutas, sin Blade/Vue.

### 41.1 `NuevaVentaService` (orquestador)

No duplica reglas: delega en `BorradorVentaService`, `CrearClienteComercialService`,
`CatalogoVentaService`, `SolicitudBorradorService`, `LineasBorradorService`,
`EntregaBorradorService`, `PagoBorradorService` y `ConfirmarVentaService`. No depende de
Request/Route/Session/Blade/Vue. Operaciones: `iniciar`, `iniciarConClienteNuevo`,
`aplicarTallas`, `entregaRapida`, `seleccionarPago`, `confirmar`, `estado`,
`direccionPredeterminadaId`.

- **Canales:** `tienda_fisica` y `whatsapp` (`ventasasesor.nueva_venta.canales`); explícito por
  venta, sin «último usado». Otro canal → `canal_no_permitido` sin crear nada.
- **Cliente:** se valida primero; sin cliente válido no se crea Cart. Cliente nuevo mínimo
  (RT-04: grupo explícito, teléfono como identidad, email opcional) y borrador en una sola
  transacción. Misma referencia externa → misma venta. Inactivo/suspendido puede tener borrador;
  el estado lo explica (`cliente_no_confirmable`) y 03F bloquea la confirmación (P-09 intacta).
- **Lote de tallas (`aplicarTallas`):** todo o nada, sin parciales automáticos. Transacción
  exterior (las internas son savepoints); una talla fallida revierte todo y lanza
  `lote_rechazado` con `varianteId` y causa. Orden por variante (solicitud ≠ carrito, D-39/I-13):
  T=0 quitar línea y luego solicitud; sin solicitud, crearla y luego agregar; T>R subir solicitud
  y luego carrito; T≤R fijar carrito y luego bajar solicitud. Las tallas omitidas no se tocan.
- **Entrega rápida:** facturación + envío + método en un viaje. Facturación = dirección REAL
  indicada o predeterminada del cliente (D-30); sin ninguna → `direccion_facturacion_requerida`
  (`requiereDireccionFacturacion` en el estado). Nunca se usa la bodega como facturación.
- **Reaplicación de entrega tras editar líneas:** se lee el método previo antes del lote; se
  reaplica solo el mismo y solo si sigue disponible; si la tarifa cambió se acepta y el total la
  refleja; si ya no es válido NO se elige otro y se marca `requiereRevisionEntrega`.
- **Pago:** los métodos ofrecidos (`Payment::getPaymentMethods()`, ~650 ms) se resuelven una vez
  por operación y solo si la operación toca el pago; sin caché entre solicitudes ni clientes.
- **`EstadoNuevaVenta`:** se compone UNA vez al final de cada operación, sin matriz de catálogo.
  Trae `motivosNoConfirmable` (informativo; la confirmación revalida todo).

### 41.2 Catálogo

`CatalogoVentaService` añade `accesosRapidos()` (CAMISETA CABALLERO, CAMISETAS DAMA, TOP Dama,
CAMISETA NIÑO; resueltos por slug y luego nombre de categoría desde la config, nunca por id;
ambiguo o inexistente falla cerrado), `coloresDeAcceso`, `buscarVariantePorSku` y `matriz`
(`MatrizVariantes`: tallas por `sort_order`, SKU, stock del índice de Bagisto, precio RT-03 y señal
de ventas simultáneas por lote; sin «disponible» calculado). Relaciones precargadas, sin N+1.

### 41.3 Cambios en servicios existentes (aditivos)

Métodos `*SinVista` en Solicitud/Líneas/Entrega/Pago (evitan armar una vista por primitiva);
`PagoBorradorService::vista` acepta ofrecidos ya resueltos; `ClienteEncontrado` añade `activo`,
`suspendido`, `grupoApto`; `BorradorVentaService::existentePorReferencia`. Comportamiento previo
intacto.

### 41.4 Medición (`lpz_saas_testing`, 4 variantes)

| Operación | Consultas | Tiempo |
|---|---|---|
| Matriz (4 variantes) | 29 (antes `variantes()`: 34) | ~0,45 s |
| Lote M6+L6, primitivas con vista (ingenuo, sin estado final) | 336 | ~1,8 s |
| `aplicarTallas` M6+L6 (incluye el estado) | 337 | ~1,6 s |
| Entrega rápida (recogida) | 284 | ~1,3 s |
| Selección de pago (con métodos) | 188 | ~1,1 s |
| Estado sin métodos de pago | 116 | ~0,4 s |

El orquestador equivale al ingenuo más el estado, que se compone una vez. **Corregido en 03G-B1
(§41.7):** `collectTotals()` NO es el costo dominante (14 % de las consultas en M6/L6).

### 41.5 Pruebas

`Fase03gbNuevaVentaTest`: 33 casos (lote, mutaciones 0→6/6→10/10→6/6→0/M6-L6→M3-L10/quitar/agregar,
rollback por stock/variante ajena/cantidad, rollback completo con entrega elegida (03G-B1), RT-03,
cliente, entrega, reaplicación, pago una vez, accesos, matriz, SKU, estado una vez).

### 41.6 Límites y deudas

- Sin controladores, rutas ni interfaz (03G-C). Sin polling.
- Limitación nativa de configurables (Doc. 05 §40) sin cambios.
- `Fase03fVentasSimultaneasTest` caso 1-2-11: corregido en 03G-B1 (§41.7.1).

### 41.7 03G-B1: cierre técnico (2026-09-28)

#### 41.7.1 Prueba 1-2-11 dependiente de la fecha

- **Qué pretende:** la señal no expone un «disponible» derivado. Con stock 38 y otra venta de 10,
  ninguna cifra debe ser 38 − 10 = 28.
- **Causa del fallo:** comprobaba `json_encode(...)` sin «28», pero el JSON incluye
  `consultado_en` y `ultima_actividad` en ISO-8601. Fallaba cualquier día 28 y cualquier hora o
  minuto 28, y fallaría también si un `*_id` contuviera «28».
- **Corrección:**
  - Recorre los valores enteros que no son identificadores y exige exactamente
    `{stock 38, en otras 10, cantidad 10, en esta 0, confirmados 0, no descontados 0}`, sin ningún 28.
  - Reloj fijado en `2028-02-28 08:28:28` con `Carbon::setTestNow`, restaurado en `finally`.
  - Comprueba además que las fechas del JSON son las del reloj fijado.
- **Evidencia:** pasa con un reloj lleno de «28». Una mutación temporal del DTO
  (`'saldo' => stock − otras`) hace fallar la prueba.

#### 41.7.2 Traza de `aplicarTallas` (M6/L6, alta)

**Orden de ejecución:**

1. `objetivosValidados`: I-5 y cantidades, antes de tocar nada.
2. `DB::transaction` externa.
3. Por cada variante:
   1. solicitud (`crearSinVista`);
   2. `LineasBorradorService::agregarSinVista`: `DB::transaction` (savepoint), `bloquearParaEditar`
      (FOR UPDATE del borrador y del carrito), `CatalogoVentaService::variante` (I-5, grupo, stock),
      I-13, RT-03 `durante`, `Cart::resetShippingMethod`, `Cart::addProduct`
      (`prepareForCart` → **stock** → escritura de `cart_items` → **`collectTotals`**).
4. `reaplicarEntrega`.
5. Estado.

**Llamadas a `collectTotals()`:**

- **Cantidad:** una por primitiva de carrito, es decir, 2 en M6/L6 y 3 en M8/L4 con entrega (2
  `updateItems` + 1 al reaplicar el método).
- **Origen:** las lanza **Bagisto dentro de** `addProduct` (Cart.php:314) y `updateItems`
  (Cart.php:396). Ninguna es nuestra salvo `eliminarSinVista`, cuando quedan líneas, y la
  reaplicación del método.
- **Qué hace:**
  - `validateItems` quita inactivos y vuelve a calcular el precio por grupo y cantidad
    (`getFinalPrice($qty)`);
  - aplica las reglas de CartRule (listener de `collect.totals.before`);
  - calcula el impuesto de ítems y de envío;
  - calcula los totales y guarda el `cart`.
- **Stock:** no lo valida. El stock se valida antes de escribir, en `prepareForCart`
  (AbstractType:806 y Configurable:396) y en `isItemHaveQuantity` (`updateItems`:373 y nuestra
  comprobación previa).
- **Dependencias:** la primitiva siguiente del mismo lote lee `cart_items.quantity`, solicitudes
  e inventario, que no dependen de `collectTotals`.
- **Transacciones y eventos:** Bagisto (Checkout, CartRule, Tax, Shipping, tipos de Product) no
  abre transacciones: todo escribe dentro de nuestros savepoints en la misma conexión. El único
  listener es CartRule, que solo escribe en la BD.

#### 41.7.3 ¿Se pueden diferir los `collectTotals` intermedios? No, sin sustituir el core (conclusión B)

- **Semántica:** diferirlos no reduciría la seguridad de stock y el último `collectTotals`
  recalcula todo desde las líneas.
- **Por qué no es posible:** no hay forma de omitirlos con la API de Bagisto, porque van
  incrustados en `addProduct` y `updateItems`, sin parámetro ni evento para saltarlos. Solo
  habría dos caminos:
  - sustituir el singleton `cart` por una subclase, lo que cambia el Cart de toda la aplicación,
    incluida la tienda;
  - escribir `cart_items` sin `addProduct` ni `updateItems`: sería un segundo motor de carrito,
    prohibido por §25, y perdería `prepareForCart` (precio, stock y opciones).

  Los dos cambian la arquitectura aprobada.
- **Beneficio máximo, medido:** el costo por llamada crece con las líneas del carrito (21, 25, 35
  y 43 consultas). Diferirlas ahorraría 21 consultas con 2 tallas (6 %) y 81 con 4 (12 %).
- **Decisión:** no se implementa. Queda como deuda aceptada.

#### 41.7.4 Dónde está el costo real (consultas, M6/L6 = 333)

| Capa | Consultas | % |
|---|---|---|
| `EstadoNuevaVenta` final | 116 | 35 |
| `CatalogoVentaService::variante` dentro de cada `agregarSinVista` (21 por llamada) | 84 | 25 |
| `addProduct` de Bagisto sin `collectTotals` | 48 | 14 |
| `collectTotals` de Bagisto | 46 | 14 |
| Bloqueos, RT-03, solicitudes y lecturas | 39 | 12 |

**Escalado de `aplicarTallas` en alta** (producción tiene como máximo 4 variantes por configurable:
73 con 4 y 4 con 2; no hay caso natural de 8):

| Tallas | Consultas | `collectTotals` | Consultas dentro de `collectTotals` | Tiempo aproximado |
|---|---|---|---|---|
| 1 | 192 | 1 | 21 | ~0,8 s |
| 2 | 333 | 2 | 46 | ~1,6 s |
| 4 | 655 | 4 | 124 | ~3,4 s |

- **Crecimiento:** unas 150 consultas por talla. El N+1 real es **nuestro**:
  - el estado suma unas 35 por línea: `SolicitudBorradorService::listar` llama a
    `CatalogoVentaService::variante` por solicitud y `aLinea` consulta el stock por línea;
  - la revalidación de catálogo suma unas 42 por talla.
- **Posible optimización (sin Bagisto):** un catálogo por lote en el listado de solicitudes y en
  el lote. Toca primitivas de 03C/03D usadas por otros consumidores, así que **requiere su propia
  fase** y no se hizo aquí.

**Matriz:** 23, 25 y 28 consultas con 1, 2 y 4 variantes. Sin N+1 relevante. El tiempo crece unos
110 ms por variante, por cálculo en PHP.

### 41.8 03G-B2: optimización por lote del catálogo y del estado (2026-09-29, sin commit)

**03G-B (con 03G-B1) quedó en el commit `a20638b4b4`.** 03G-B2 no cambia el comportamiento
comercial ni toca `collectTotals`, `Cart`, `cart_items` ni Webkul.

#### 41.8.1 Causas del N+1 propio

- **Catálogo por talla (~42 consultas):** cada talla llama dos veces a
  `CatalogoVentaService::variante()` (21 consultas y ~140 ms cada vez): una en
  `SolicitudBorradorService::crearSinVista` o `cambiarCantidadSinVista`, y otra en
  `LineasBorradorService::agregarSinVista`. Cada llamada repite:
  - producto padre (3), hijo (1), cliente y grupo (2) y súper atributos con sus opciones (2);
  - valores de atributo y familia del hijo (2) y su índice de inventario (1);
  - las traducciones de atributos y opciones (4), que son lo más caro.

  Ninguna de las dos usa el precio: el precio real lo pone Bagisto en `prepareForCart`.
- **Estado (~35 consultas por línea):**
  - `SolicitudBorradorService::aSolicitud` repite, por cada solicitud, `productoVendible` y
    `hijoVerificado` (dos veces) y una `variante()` completa;
  - `aLinea` carga de forma diferida la familia, los valores y el inventario de cada línea;
  - las líneas se construyen **dos veces**, porque `EntregaBorradorService::vista` vuelve a
    listarlas en `lineasSinStock`;
  - `obtener()` del borrador se ejecuta 5 veces.
- **Tiempo:** el SQL es poco (≈240 ms de 3,5 s con 4 tallas). El costo real está en PHP:
  - cada acceso a una traducción de Bagisto (`$atributo->name`, `$opcion->label`, con
    Astrotomic Translatable) cuesta ~35 ms y no se reutiliza entre accesos;
  - `Configurable::prepareForCart` de Bagisto tarda ~240 ms por talla por el mismo motivo. Es
    core y no se toca.

**Clasificación de las consultas:**

| Tipo | Consultas |
|---|---|
| Repetidas | Producto, hijo, cliente y grupo, súper atributos, traducciones, `obtener()` y la segunda lectura de líneas |
| Derivables de lo ya cargado | Atributos e inventario del mismo hijo |
| Agrupables en bloque | Relaciones de las líneas y de los hijos |
| Dependientes de RT-03 (se conservan) | Contexto de precio (cliente y grupo) y precio de grupo y de reglas en cada `getFinalPrice` |
| De stock (se conserva la fuente) | `inventory_indices`, que ahora se precarga pero se sigue leyendo con `totalQuantity()` |

#### 41.8.2 Diseño

- **`Support\Venta\LoteCatalogo`:** memoria de UNA operación.
  - Guarda solo resultados exitosos: un fallo se vuelve a evaluar y lanza igual.
  - No se persiste ni sobrevive a la operación, así que no es una caché comercial.
- **Dónde se crea:**
  - `aplicarTallas`: **dentro** de su transacción. Con REPEATABLE READ, releer en la misma
    transacción devuelve el mismo snapshot, por lo que la revalidación por talla era redundante
    por definición.
  - `estado()`: al empezar cada llamada.
- **`CatalogoVentaService::varianteEnLote`:** mismas comprobaciones, en el mismo orden y con las
  mismas excepciones que `variante()`:
  - cantidad → producto vendible → hijo verificado → cliente apto → súper atributos → variante;
  - el hijo sale de los hijos precargados; si no está entre ellos, `hijoVerificado` lanza igual;
  - las etiquetas traducidas se leen una vez por atributo y opción;
  - precio y stock se siguen calculando con Bagisto en cada llamada, dentro de RT-03.
- **Parámetros opcionales al final (por defecto `null`, así que los demás consumidores no
  cambian):**
  - `SolicitudBorradorService::crearSinVista`, `cambiarCantidadSinVista` y `listar` (con `$vista`);
  - `LineasBorradorService::agregarSinVista` y `listar` (precarga familia, valores e inventario);
  - `EntregaBorradorService::vista($lineas)`;
  - `PagoBorradorService::vista(..., $vista)`.
- **No se tocó:** los bloqueos, el orden de mutación (D-39/I-13), la validación de stock de
  Bagisto ni ninguna excepción.

#### 41.8.3 Medición (`lpz_saas_testing`, alta con 6 unidades por talla)

Consultas y tiempos de `aplicarTallas`, que incluye el estado; tiempo = mediana de 3 ejecuciones.
El «antes» reproduce el commit `a20638b4b4`.

| Tallas | Consultas (antes → después) | Estado | `collectTotals` | Tiempo |
|---|---|---|---|---|
| 1 | 187 → 141 | 81 → 51 | 1 → 1 | ~1,15 s → ~0,99 s |
| 2 | 333 → 231 | 116 → 60 | 2 → 2 | ~1,77 s → ~1,49 s |
| 4 | 655 → 439 | 186 → 77 | 4 → 4 | ~3,87 s → ~2,52 s |

- **Catálogo por talla:** unas 42 → 11 consultas (lo que queda es RT-03 y precio).
- **Estado por línea:** unas 35 → 9.

#### 41.8.4 Pruebas nuevas (`Fase03gbNuevaVentaTest`, 37 casos)

- **Equivalencia exacta** (`toEqual`) de líneas, solicitudes, entrega y pago en lote frente a la
  lectura normal. Escenario: precio de grupo, solicitud por encima del carrito, producto simple,
  variante desactivada y recogida elegida.
- **Equivalencia con cliente de grupo no apto** (sin precio).
- **`varianteEnLote` frente a `variante()`:** misma variante con cantidades 1 y 7 en todas las
  variantes, y el mismo error en 6 casos inválidos (variante ajena, inexistente, hijo como
  padre, producto inexistente, cantidad 0 e invitado).
- **Cota de N+1:** el estado crece como máximo 12 consultas por línea y `collectTotals` sigue
  siendo uno por primitiva.
- **Prueba de mutación:** forzar `vendible` en el camino de lote hace fallar la equivalencia.

#### 41.8.5 Matriz: mismo `LoteCatalogo`, sin nuevo mecanismo (2026-09-29)

`CatalogoVentaService::matriz()` crea un `LoteCatalogo` local y lo pasa a `aVariante()`: mismo
patrón exacto de §41.8.2, ninguna clase ni parámetro nuevo. Las consultas no cambian (ya estaban
acotadas por `hijosPrecargados`); lo que se ahorra es el costo en PHP de repetir la traducción de
cada atributo y opción por variante.

**Medición (`lpz_saas_testing`, mediana de 3 ejecuciones, comparado contra el comportamiento del
commit `a20638b4b4`):**

| Variantes | Consultas (antes → después) | Tiempo (antes → después) |
|---|---|---|
| 1 | 23 → 23 | ~136 ms → ~144 ms |
| 2 | 25 → 25 | ~247 ms → ~207 ms |
| 4 | 28 → 28 | ~978 ms → ~437 ms (−55 %) |

Con 1 variante no hay traducciones repetidas que memorizar, así que el tiempo es equivalente; la
mejora aparece a partir de 2 variantes y crece con ellas.

**Equivalencia:** test que reconstruye la misma matriz variante por variante con `variante()` (sin
lote) y compara con `toArray()` contra la construida con `matriz()` (con lote), en el mismo orden
y sin contar `consultado_en` (cambia entre las dos llamadas). Se confirma que sigue habiendo
variante real, padre configurable, talla, color, SKU, precio RT-03, stock Bagisto, orden de tallas
y señal de ventas simultáneas — nada de "disponible" calculado, reservas, stock propio ni precio
cacheado entre operaciones (la memoria vive solo dentro de esta llamada). Una mutación temporal
(pasar `cantidad + 1` a `aVariante` dentro de `matriz()`) hizo fallar la prueba, confirmando que
detecta un cambio real y no es un test vacío.

#### 41.8.6 Deuda que queda (expresamente fuera de alcance)

- **`prepareForCart` de Bagisto** (~240 ms por talla, por las traducciones), **`collectTotals`**
  (§41.7) y las consultas de RT-03 por precio: son core o fuente de verdad, así que no se tocan.
- **`cantidadEnCarrito`:** sigue haciendo una consulta por solicitud en el estado.
- **`Payment` core, SMTP, shipping core e índice de `order_items`:** sin auditar en B2.

### 41.9 03G-C: API JSON de "Nueva venta rápida" (2026-09-29, sin commit)

**03G-B + 03G-B1 en el commit `a20638b4b4`; 03G-B2 en `6234bf40e2`.** 03G-C conecta la futura
interfaz rápida con `NuevaVentaService` mediante un controlador delgado: Request → autorización
estructural → el servicio de dominio ya existente → JSON. Ninguna regla de precio, stock, grupo,
variantes, solicitudes, Cart, envío, pago, confirmación o atribución vive en el controlador.

#### 41.9.1 Rutas y controlador

`Http/Controllers/Admin/NuevaVentaController`, bajo `admin/ventas-asesor/nueva-venta`, con el
mismo middleware (`admin`, `NoCacheMiddleware`) que ya usa `InicioController`:

| Operación | Ruta | Método de dominio |
|---|---|---|
| A. Bootstrap | `GET /nueva-venta` | `CatalogoVentaService::accesosRapidos` + config |
| B. Buscar cliente | `POST /nueva-venta/clientes/buscar` | `BuscarClienteComercialService::buscar` |
| C. Crear cliente | `POST /nueva-venta/clientes` | `CrearClienteComercialService::crear` |
| D. Iniciar | `POST /nueva-venta/iniciar` | `NuevaVentaService::iniciar` |
| D. Iniciar + cliente nuevo | `POST /nueva-venta/iniciar-cliente-nuevo` | `NuevaVentaService::iniciarConClienteNuevo` |
| E. Colores de un acceso | `GET /nueva-venta/lineas/{clave}/colores` | `CatalogoVentaService::coloresDeAcceso` |
| G. Matriz | `GET /nueva-venta/productos/{producto}/matriz` | `CatalogoVentaService::matriz` |
| F/K. Estado | `GET /nueva-venta/{borrador}` | `NuevaVentaService::estado` |
| H. Lote de tallas | `POST /nueva-venta/{borrador}/tallas` | `NuevaVentaService::aplicarTallas` |
| I. Entrega | `POST /nueva-venta/{borrador}/entrega` | `NuevaVentaService::entregaRapida` |
| J. Pago | `POST /nueva-venta/{borrador}/pago` | `NuevaVentaService::seleccionarPago` |
| L/M. Confirmar | `POST /nueva-venta/{borrador}/confirmar` | `NuevaVentaService::confirmar` |

Cada acción tiene su `FormRequest` propio (`Http/Requests/NuevaVenta/`) que valida SOLO forma
(tipos, longitudes, "al menos un criterio", estructura de la dirección): nunca reglas de negocio,
que siguen en los servicios. Las direcciones puntuales del cuerpo JSON se traducen a
`OrigenDireccion`/`DireccionComercial` dentro del propio Request (mapeo de datos, no lógica
comercial). La selección de propiedad comercial (`SeleccionPropiedadComercial`) sigue sin
exponerse por HTTP, tal como ya lo documenta su propio DTO.

#### 41.9.2 ACL: mismo permiso, sin clave nueva

Las 12 rutas se autorizan con el permiso que `BorradorVentaService::PERMISO` ya exige en el
dominio (`ventas_asesor.pedidos.create`), añadidas al arreglo `route` de esa MISMA entrada de
`Config/acl.php` (`Arr::wrap`, que `Acl::getRoles()` ya soporta). No se creó ninguna clave nueva
ni se duplicó ninguna: las pruebas `AclYMenuConfiguracionTest` (conteo exacto de 23 claves, árbol
exacto) siguen en verde sin modificarlas, más una prueba nueva que fija que las 12 rutas quedan
mapeadas a ese permiso.

**Riesgo aceptado y documentado:** `SessionController::redirectToFirstAccessibleRoute()` (Bagisto
core) hace `redirect()->route($permissionDetails['route'])` sin indexar `[0]`; si esta clave fuera
algún día la primera con punto en la lista de permisos guardada de un rol `custom`, ese redirect
fallaría con un arreglo. El propio `Admin/Config/acl.php` de Bagisto ya usa arreglos en `route` de
forma extendida (mismo riesgo, preexistente y no introducido por 03G-C); corregirlo exigiría
modificar `Webkul`, fuera de alcance. Se mantiene `$inicio` como primer elemento del arreglo
(mismo target seguro que todas las demás claves del módulo).

#### 41.9.3 Contrato de error único

`Support\Http\RespuestaErrorNuevaVenta::envolver()` traduce cualquier excepción de dominio ya
existente (03B–03G-B2) a JSON estable, sin crear códigos nuevos (docblock de
`NuevaVentaInvalidaException`: "03G-C los mapea todos"). El estado HTTP se deriva del propio
`codigo` (permiso→403, inexistente→404, conflicto de estado→409, el resto→422); para
`LOTE_RECHAZADO` (que envuelve la falla de UNA talla del lote), el estado se calcula sobre `causa`
(el código real de la falla envuelta, p. ej. `no_es_propietario` o `stock_insuficiente`), no sobre
el código genérico del envoltorio. `ContextoPrecioComercialInvalidoException` ya tenía su propio
`render()` (patrón preexistente) y se deja pasar sin envolver.

#### 41.9.4 Pruebas (`Fase03gcApiNuevaVentaTest`, 7 casos, HTTP real)

Pasan por el stack completo: middleware `admin` (Bouncer real), ACL real y los servicios de
dominio reales, no solo llamadas directas al servicio.

- **E2E cliente existente:** bootstrap → buscar por teléfono → iniciar → colores del acceso
  rápido → matriz → lote M6/L6 → recogida (facturación = dirección real, D-30) → pago → estado →
  confirmar → `Order` real verificada en `lpz_saas_testing`.
- **E2E cliente nuevo:** teléfono no encontrado → alta mínima RT-04 (grupo explícito, email NULL)
  + venta nueva en una transacción → continúa aplicando una talla.
- **ACL real:** sin autenticar → 302 al login; sin el permiso del módulo → 401 del Bouncer real
  (no simulado).
- **Borrador ajeno:** un segundo asesor con el mismo permiso no puede operar el borrador de otro
  (409, `no_es_propietario`); el dueño sí puede.
- **Todo o nada por HTTP:** una talla sin stock revierte el lote completo y devuelve
  `LOTE_RECHAZADO` con la variante y la causa; el carrito queda sin líneas.
- **Validación estructural:** sin criterio de búsqueda, sin `customer_id`, lote vacío → 422 antes
  de tocar el dominio.
- **PII:** el JSON del estado y de la matriz nunca contienen `password`, `remember_token` ni
  `api_token`.

#### 41.9.5 Fuera de alcance (expresamente, para 03G-D)

Sin diseño visual, sin Vue, sin polling, sin WebSockets, sin n8n/WhatsApp/IA, sin cartera, sin
logística adicional, sin comisiones ni descuentos D-38, sin tocar Webkul. `cantidadEnCarrito`
N+1, `prepareForCart`, `collectTotals`, Payment core, SMTP y el índice de `order_items` quedan
como deuda ya documentada (§41.7–§41.8), sin auditar de nuevo aquí.

### 41.10 03G-D: Entrega → Dirección → Pago en la pantalla (2026-10-05, sin commit)

Referencia funcional y visual: el checkout nativo de Bagisto (opciones de radio grandes, título,
descripción debajo, precio alineado, el siguiente paso aparece cuando el anterior está decidido).
Sigue siendo **una sola pantalla**, sin wizard; cualquier decisión anterior se puede cambiar.

#### 41.10.1 Auditoría: «Recogida en bodega» vs «Recogida en tienda»

| Concepto | Qué era | Dónde vive |
|---|---|---|
| «Recogida en bodega» | Opción **de UI** (`entregaForm.tipo = punto_recogida`): pone como CartAddress de envío la bodega real (`PuntosRecogida`, D-41) y manda `metodo = pickup` en la misma petición | Solo pantalla |
| «Recogida en tienda» | **ShippingRate real** del carrier `pickup` (extensión `bagisto-eu/pickup`; título y descripción en `core_config sales.carriers.pickup.*`) | `cart_shipping_rates` → `cart.shipping_method = 'pickup'` → `orders.shipping_method` |
| «Envío por pagar» / «Envío gratuito» | Rates de los carriers del core `flatrate` / `free` (activos en `lpz_saas`) | `cart_shipping_rates` |
| «Domicilio local» | Rate de `ByLopez\Logistica\Carriers\DomicilioLocal` (tarifa en `core_config`) | `cart_shipping_rates` |

Era **la misma decisión preguntada dos veces**: la opción de UI ya aplicaba `pickup`, y después
la lista de rates volvía a mostrar «Recogida en tienda» **junto a** «Envío por pagar» y «Envío
gratuito», que Bagisto también calcula para la dirección de la bodega (estado híbrido posible:
envío por pagar a la propia bodega). `orders.shipping_method` es lo único que llega a Bagisto.

**Corrección.**
- UI: una sola opción «Recogida en tienda» (título/descripción reales de `pickup`); al elegirla se
  aplica el rate `pickup` y se muestra como información (bodega + precio real del rate). No se
  vuelve a preguntar. La opción «Recogida en bodega» desaparece de la UI.
- Dominio: `PuntosRecogida::metodoPermitido()` pasa a ser bicondicional: `pickup` solo con
  destino bodega **y** con destino bodega solo `pickup`. Lo usan `EntregaBorradorService::vista()`
  (rates ofrecidos), `seleccionarMetodo()` (422 `metodo_entrega_no_disponible`) y
  `ConfirmarVentaService::revalidarEntrega()` (03F: un carrito híbrido ya no se confirma).

#### 41.10.2 Progresión visual

```
ENTREGA  ○ Recogida en tienda   ○ Envío a domicilio
  ├─ Recogida → bodega real + pickup aplicados → (facturación si hace falta) → PAGO
  └─ Domicilio → DIRECCIÓN (tarjetas de CustomerAddress / + Agregar dirección)
                   → MÉTODO DE ENVÍO (rates reales para ESA dirección) → PAGO
PAGO → RESUMEN / CONFIRMAR
```

- Para domicilio el método va **después** de la dirección porque Bagisto calcula los rates para un
  destino (`Shipping::collectRates()` exige `shipping_address`; `DomicilioLocal` no aplica a una
  bodega). No se inventa una lista de métodos sin destino.
- **Pago** aparece solo con `entregaLista` = `entrega_completa` del backend **y** el tipo elegido en
  pantalla igual al aplicado al carrito (`tipoAplicado`, derivado del CartAddress de envío). El
  botón Confirmar exige lo mismo: no se puede confirmar una recogida previa mientras se está
  eligiendo domicilio.
- Estilo de opciones: radio + título + descripción + precio a la derecha, borde/anillo azul al
  seleccionar, área táctil completa; direcciones en 1 columna en móvil y 2 en escritorio.

#### 41.10.3 Recogida

- Envío = bodega real (inventory source activo) + datos de quien recibe tomados de la facturación
  (03D). Sin formulario de dirección del cliente; sin dirección ficticia.
- Facturación (Bagisto la exige para crear el Order): la que ya tenga el carrito, o la
  **predeterminada** del cliente (`NuevaVentaService::entregaRapida`, D-30). Si no hay ninguna
  resoluble (`requiere_direccion_facturacion`), la pantalla pide elegir una dirección real del
  cliente o agregarla; nunca usa la bodega. Sin hueco de dominio nuevo.

#### 41.10.4 Domicilio / envío y direcciones

- Direcciones: `GET nueva-venta/clientes/{cliente}/direcciones` (CustomerAddress con scope
  `address_type = customer`).
- **Bug corregido:** la ruta usaba `{customer}`, que Bagisto vincula implícitamente al modelo
  `Customer`; el controlador recibía un modelo y respondía 500 (`TypeError` en `laravel.log`
  de desarrollo el 2026-10-03 y el 2026-10-05). Por eso las direcciones no cargaban. El
  parámetro pasa a `{cliente}`.
- **Predeterminada:** la de Bagisto (`addresses.default_address = 1`), igual que
  `NuevaVentaService::direccionPredeterminadaId()`. Preselección **solo visual** (no escribe
  nada): predeterminada; si no hay y el cliente tiene **una sola**, esa; con varias sin
  predeterminada, el asesor elige (aviso «Selecciona la dirección de entrega»).
- Al elegir una dirección se envía `envio = {tipo: guardada, address_id}`: `Cart::saveAddresses`
  copia los campos al CartAddress con `parent_address_id` (vínculo informativo). La facturación
  solo se manda si el backend no puede resolverla. Cambiar de dirección limpia el método (Bagisto
  lo invalida al guardar direcciones).
- Sin direcciones: «Este cliente no tiene una dirección de entrega.» + «+ Agregar dirección».

#### 41.10.5 Agregar dirección sin salir de Nueva venta

- Formulario inline (sirve a envío y a facturación): nombre, apellido, teléfono, dirección,
  ciudad/municipio (texto libre: Bagisto no tiene catálogo de municipios), departamento
  (`country_states` CO), código postal. Email **no** se pide (RT-04).
- «Guardar en las direcciones del cliente» (marcado por defecto) →
  `POST nueva-venta/{borrador}/direcciones` → `NuevaVentaService::agregarDireccionCliente()` →
  `GuardarDireccionClienteComercialService` (RT-04: teléfono E.164, email NULL, eventos
  `customer.addresses.create.*`). El cliente es **el del borrador** (nunca uno de la petición) y
  se exige ser propietario del borrador editable (`bloquearParaEditar`; ajeno → 409). Si el
  cliente no tenía predeterminada, la nueva lo es. Guardada **exige código postal** (regla vigente
  de RT-04, ver D-57). ACL: misma clave `ventas_asesor.pedidos.create`.
- Sin marcar → dirección **puntual** de esta venta: solo viaja al CartAddress
  (`OrigenDireccion::puntual`, código postal opcional, D-46); no crea CustomerAddress.

#### 41.10.6 CustomerAddress → CartAddress → OrderAddress

- **CustomerAddress** (`address_type = customer`): reutilizable; solo se crea/modifica por RT-04
  y de forma explícita. Usarla en una venta **no la modifica** (probado: fila idéntica antes y
  después).
- **CartAddress** (`cart_billing` / `cart_shipping`): snapshot de ESTA venta
  (`Cart::saveAddresses`).
- **OrderAddress** (`order_billing` / `order_shipping`): Bagisto lo copia del carrito al crear el
  Order (`OrderResource`, 03F). Probado en confirmación real de testing.

#### 41.10.7 Cambio Domicilio ↔ Recogida

- Domicilio → Recogida: envío = bodega, método `pickup`, la dirección del cliente deja de ser
  destino (`cart_shipping.parent_address_id` NULL). La facturación se conserva.
- Recogida → Domicilio: el `pickup` no se arrastra (la pantalla limpia el método y el dominio lo
  rechazaría con destino domicilio); se aplica la dirección elegida y se vuelve a pedir el método.

#### 41.10.8 Pago

Sin cambios de arquitectura (03E): métodos = allowlist del canal asesor ∩ disponibles en
Bagisto. Solo cambia la presentación (radio + título + descripción). Transferencia: «El pago
sigue pendiente hasta que exista evidencia real»; no crea transacción ni Cartera.
**Hecho de configuración en `lpz_saas`:** `moneytransfer.generate_invoice = 1` e
`invoice_status = paid` (D-48, despliegue pendiente), así que Bagisto crea la factura `paid` sin
transacción y «Mis ventas» la muestra como **Revisar** (D-56), no como Pagado.

#### 41.10.9 PDF de factura: separado del dominio comercial

La confirmación vía `php artisan serve` sigue bloqueándose por el problema ya auditado (el PDF de la
notificación de factura pide el logo por HTTP a la misma app de un solo worker): en `laravel.log`
de desarrollo, los intentos del 2026-10-05 11:39 (pedido 37) y 11:53 (pedido 38) terminaron en
«Maximum execution time» y se revirtieron (borradores quedan `confirmando` sin `order_id`; D-55
permite reintentar). No es un problema de Entrega/Pago ni de RT-04. Configuración nativa propuesta
para desarrollo (no aplicada, requiere aprobación): D-57.

**Confirmación controlada (2026-10-05 12:20, borrador 36, por CLI con los mismos servicios):** sin
`serve` de por medio el pedido llega a crearse dentro de la transacción (`Order event triggered`,
id 39) y la ejecución muere en el **mismo PDF** de la factura, ahora por memoria:
`Allowed memory size of 134217728 bytes exhausted` en `khaled.alshamaa/ar-php` (llamado desde
`Webkul\Core\Traits\PDFHandler`). Todo se revirtió (0 pedidos nuevos, 0 facturas, 0 atribuciones;
el borrador queda `confirmando` sin `order_id`). Conclusión: el bloqueo es el PDF de la
notificación de factura, por dos causas independientes (logo por HTTP en `serve` y memoria de
`ar-php` con `memory_limit = 128M`); Entrega, Pago, atribución y OrderAddress no intervienen.

#### 41.10.10 Pruebas (`Fase03gdEntregaDireccionPagoTest`, 6 casos, HTTP real)

A/B/J recogida sin dirección del cliente, `pickup` real y solo `pickup` ofrecido; C/D
predeterminada y elección entre varias, CustomerAddress intacta; E/F/L alta desde Nueva venta
sin email (RT-04), predeterminada, código postal exigido, ajeno 409; G/H cambios
domicilio↔recogida sin híbridos; K/M/N confirmación con transferencia: OrderAddress = snapshot,
sin transacción, atribución al asesor y visible en «Mis ventas»; pantalla con secciones
condicionadas.

### 41.11 03G-D: modal «Agregar dirección», postcode dinámico, desbloqueo del PDF y primer Order real (2026-10-05, sin commit)

Camilo aprobó la progresión Entrega → Dirección → Método de envío → Pago (§41.10); no se rediseña.

#### 41.11.1 Modal «Agregar dirección» (IMPLEMENTADO)

- El formulario inline desaparece. «+ Agregar dirección» (domicilio y facturación de recogida)
  abre `x-admin::modal` (`ref="modalDireccion"`), el mismo componente de las otras modales de la
  pantalla. Cuerpo con scroll interno (`max-h-[60vh]`) y pie visible con «Cancelar» / «Guardar
  dirección»: usable en celular con el teclado abierto. 1 columna en móvil, 2 en escritorio.
- Campos: nombre, apellido, teléfono (normalización de RT-04, sin cambios), dirección física,
  ciudad/municipio, departamento, código postal, «Guardar en las direcciones del cliente». **Email
  no se pide.**
- Guardada → RT-04 (`POST nueva-venta/{borrador}/direcciones`, §41.10.5) y se usa en la venta.
  Sin guardar → dirección puntual: solo CartAddress (la modal no se cierra si faltan obligatorios;
  el backend valida igual).
- **Provisional hasta aprobar D-58 (reemplazado en §41.12 por el autocomplete único):** ciudad en texto y departamento en select. El select ahora
  envía el **código** de `country_states` (lo que Bagisto guarda en `addresses.state`), no el
  nombre; las tarjetas muestran el nombre. El autocomplete único «Departamento — Municipio» queda
  PROPUESTO en D-58 (sin tablas ni endpoint).

#### 41.11.2 Código postal (IMPLEMENTADO)

`GuardarDireccionClienteComercialService` exige el código postal solo si
`core()->isPostCodeRequired()` (`customer.address.requirements.postcode`) lo exige; ya no es fijo.
En `lpz_saas` vale 0 → opcional. La modal lo marca obligatorio solo si «Guardar» está marcado y
Bagisto lo exige. La dirección puntual del borrador sigue opcional (D-46). Auditoría completa del
código postal colombiano y de por qué no se resuelve por municipio: D-58.

#### 41.11.3 Desbloqueo del PDF en desarrollo (APLICADO por configuración)

`core_config` id 8 `emails.general.notifications.emails.general.notifications.new_invoice`
(global, `channel_code` NULL): **1 → 0**. Solo deja de enviarse el correo de factura al cliente
(y su PDF). Creación de Invoice, `generate_invoice`, `invoice_status` y semántica de pago sin
cambios. Restaurar: Configuración → Correos → Notificaciones → «Nueva factura» = Sí (o valor 1 +
`php artisan optimize:clear`). Detalle: D-57.

#### 41.11.4 Confirmación real (2026-10-05 ~14:40, una sola)

Borrador 36 (venta de Camilo: cliente 1, 2 unidades, recogida + transferencia, 14.800; propietario
admin 1). Antes: `confirmando`, sin `order_id`, 24 pedidos, lease vencido. Confirmado por CLI con
los mismos servicios (`NuevaVentaService::confirmar`), 18,5 s, sin bloqueo.

| Verificación | Resultado |
|---|---|
| Order | **id 41, `BYL-00000025-CO`**, `processing`, 14.800, 2 unidades |
| `shipping_method` | `pickup` («Recogida en tienda») |
| Invoice | `FAC-BYL-00000025-2025`, `paid` (config de `moneytransfer`), `email_sent = 0` |
| Transacciones | **0** (ninguna ficticia) |
| OrderAddress envío | BODEGA PRINCIPAL, «Carrea 74 b numero 58-19 sur», BOGOTA, **state MI, postcode 48127** (datos de la bodega, ver D-57) |
| OrderAddress facturación | dirección real del cliente («dirección del cliente», SOACHA) |
| Atribución | `unassigned` / `sin_asesor`, creador admin 1, canal `tienda_fisica`, mecanismo `ventas_asesor` — **correcto por D-27** (admin `all`, sin selección); no se tocaron roles |
| Pago derivado (D-56) | **Revisar** (factura `paid` sin transacción) |
| Despacho (D-56) | **Sin despachar** |
| Mis ventas | visible con «ver todos», propietario «Sin asignar»; no visible para asesores con «ver propios» |
| Borrador 36 | `confirmado`, `order_id = 41` |

Nota: `orders.shipping_title` quedó «Recogida en tienda - Recogida en tienda» (Bagisto concatena
título de carrier y de método); solo cosmético.

### 41.12 03G-D: catálogo DIVIPOLA y autocomplete único «Departamento — Municipio» (D-58, 2026-10-05, sin commit)

Camilo aprobó D-58 (Doc. 04). La modal «Agregar dirección» (§41.11) se conserva y la progresión
Entrega → Dirección → Método de envío → Pago **no** se rediseña. Detalle de fuente, esquema,
tablas, equivalencia, Bogotá, importador, endpoint y código postal: **Doc. 04, D-58**. Aquí, lo que
cambió en la pantalla y en el flujo de la dirección.

#### 41.12.1 Qué cambió en la modal

- «Ciudad / municipio» y «Departamento» (dos controles provisionales de §41.11.1) → **un solo
  input autocompletable**. Resultados en vivo (debounce 300 ms) con el formato
  **«Departamento — Municipio»** («Boyacá — Pesca», «Santander — Bucaramanga»,
  «Bogotá, D.C. — Bogotá, D.C.»); busca por municipio **o** departamento, sin tildes ni mayúsculas.
- La lista va **en flujo** debajo del input (no flotante), con `max-height` y scroll propio, para que
  el teclado del celular no la oculte; flechas ↑↓ + Enter y toque/clic eligen.
- Al elegir se muestra «Departamento: Boyacá» solo como información; no hay segundo selector. Volver
  a escribir invalida la selección. Sin selección, «Guardar dirección» no envía nada y muestra
  «Selecciona una ciudad o municipio de la lista.».
- El código postal, el teléfono (RT-04), el email opcional y la casilla «Guardar en las direcciones
  del cliente» no cambian.

#### 41.12.2 Qué viaja y qué decide el backend

- La UI manda **`municipio_codigo`** (5 dígitos); ya no manda ciudad, departamento ni país.
- `AgregarDireccionNuevaVentaRequest` / `EntregaNuevaVentaRequest`: `municipio_codigo` obligatorio
  para CO (422 con «Selecciona una ciudad o municipio de la lista.»).
- `ValidadorDireccionComercial` resuelve el código en el catálogo y **ignora** lo que el navegador
  mande en `ciudad` / `departamento` / `pais`: `country = CO`, `state = country_states.code`
  (`BOY`, `BOG`, …), `city = nombre_oficial` (`Pesca`). Código inexistente / inactivo →
  `datos_cliente_invalidos` (`errores.municipio_codigo`).
- Ambos destinos usan el mismo resultado: guardada → `GuardarDireccionClienteComercialService`
  (CustomerAddress, RT-04) y luego CartAddress; puntual → solo CartAddress (`OrigenDireccion::puntual`).
  El OrderAddress lo copia Bagisto al confirmar (probado).
- Corrección encontrada por las pruebas: `NuevaVentaService::agregarDireccionCliente` reconstruía el
  DTO al marcar «predeterminada» y perdía `municipioCodigo`; ahora usa
  `DireccionComercial::comoPredeterminada()`.

#### 41.12.3 Pruebas

- `Fase03gdGeoDivipolaTest`: nombres (presentación / búsqueda); importador (dry-run sin escrituras,
  persistir, cero inicial, trazabilidad, **idempotencia sin un solo INSERT/UPDATE**, cambios,
  inactivación sin borrar y reactivación, **falla cerrado**: departamento sin equivalencia, estado
  inexistente, esquema distinto, datos mal formados) y comando (códigos de salida); endpoint:
  «Pes», «Tun», «Buc», «Boy», «Chí», «Bog», varias palabras, orden municipio→departamento, tope de 15,
  mínimo 2 caracteres, Bouncer; `resolver()`.
- `Fase03gdEntregaDireccionPagoTest` (ampliada): selección de Pesca → CO/BOY/Pesca; manipulación de
  ciudad/estado/país ignorada (CustomerAddress y CartAddress); texto parcial → 422 sin escrituras;
  inexistente / inactivo → rechazo; guardar = CustomerAddress, sin guardar = solo CartAddress;
  CustomerAddress existente intacta; postcode `true` / `false`; email NULL; Bogotá → BOG;
  confirmación con OrderAddress del catálogo.
- Datos de prueba: fixture mínimo cargado con el **mismo importador** dentro de cada transacción
  (`tests/helpersGeo.php`); nada depende del catálogo completo ni de contaminación previa.

---

## 42. PAG-A: arquitectura de Pagos / Cartera (2026-10-05, solo documentación)

**Estado:** diseño **aprobado por Camilo Gaitán** (decisiones P-01…P-12 y C-1…C-4 en Doc. 04, **D-59**).
**Sin migraciones, modelos, servicios, controladores, rutas, vistas ni API; sin tests; sin tocar
Webkul ni configuración.** Revisa y sustituye en parte el diseño de **D-53** (§35 y §36.4), cuyo
historial se conserva. La auditoría de Pagos/Cartera ya se había realizado: aquí solo se cierra la
arquitectura; los datos de Bagisto citados salen de §32–§36 y de lecturas puntuales de `core_config`.

### 42.1 Base aceptada

| Hecho (auditoría ya aceptada) | Consecuencia de diseño |
|---|---|
| `orders` no representa el dinero cobrado | El saldo no se lee del pedido |
| `invoices.state = paid` representa facturación, **no** demuestra ingreso (D-48: hoy `moneytransfer` crea la factura `paid` sin dinero) | `Invoice paid` nunca es evidencia de pago |
| `order_transactions` es lo más cercano al dinero **verificado** nativo | Se crea solo al **confirmar** (P-01) |
| Bagisto no ofrece pago reportado sin revisar, aplicación multipedido, rechazo, reversión auditable, actor, evidencia, saldo comercial, cartera de cliente ni idempotencia suficiente | Dominio propio ByLopez, complementario (no sustituye a Bagisto) |
| Webkul no implementa reversión de Transactions | La reversión confirmada queda como capacidad futura sin mecanismo cerrado (§42.4) |

**Tres conceptos que no se confunden (D-56 se mantiene):**
**Invoice** = facturación · **Transaction** = dinero verificado en el modelo nativo ·
**Pago ByLopez** = ingreso comercial reportado, aplicado a pedidos y su ciclo de revisión.
Además, **Pedido ≠ Pago ≠ Despacho**, y **método de pago del pedido** (`order_payment.method`,
03E) **≠ medio de cobro** por el que realmente entró un abono (§42.7).

### 42.2 Modelo conceptual (sin migración; el DDL final se define en PAG-B)

**Relación con D-53.** La tabla `bylopez_va_registros_pago` de §35.4 **nunca se creó** y queda
**sustituida**: su clave de idempotencia pasa a `bylopez_va_pagos`, y su `order_transaction_id`
pasa a `bylopez_va_aplicaciones_pago` (nace NULL y se llena al confirmar).

**`bylopez_va_pagos`** — el ingreso comercial registrado.

| Campo conceptual | Notas |
|---|---|
| `id`, `customer_id` | Cliente dueño del ingreso. Todas sus aplicaciones recaen en pedidos de ese cliente (I-11) |
| `monto` | Positivo, moneda base (COP), sin multimoneda |
| `medio_cobro` | Código de la fuente configurable (§42.7); no es texto libre |
| `referencia` | Opcional / requerida según la política del medio |
| `fecha_pago` | Cuándo ingresó realmente el dinero (distinta de la fecha de registro); no futura |
| `estado` | `sin_revisar`, `confirmado`, `rechazado`, `reversado` (§42.4) |
| `mecanismo`, `idempotency_key`, `huella_solicitud` | UNIQUE(`mecanismo`, `idempotency_key`); misma lógica de D-53 (§35.3) |
| `registrado_por_admin_id`, `revisado_por_admin_id`, `revisado_en` | Sin FK a administradores (como la atribución); snapshot de nombre opcional |
| `notas`, timestamps | |

**`bylopez_va_aplicaciones_pago`** — a qué pedido se aplicó cada peso.

| Campo conceptual | Notas |
|---|---|
| `id`, `pago_id`, `order_id`, `monto` | `monto > 0` |
| `anulada_en`, `anulada_evento_id` *(implementado sin `anulada_evento_id`, ver §42.16)* | NULL = activa. Se llenan **exactamente** para las aplicaciones de un pago rechazado (o reversado) |
| `order_transaction_id` | NULL hasta confirmar; UNIQUE; FK a `order_transactions`, RESTRICT. Es el **vínculo estructural** que evita el doble conteo (§42.3) |

**`bylopez_va_pagos_eventos`** — historial **append-only** (sin UPDATE ni DELETE): `id`, `pago_id`,
`tipo` (`registrado`, `confirmado`, `rechazado`, `reversado`), `actor_admin_id`, `motivo`
(obligatorio al rechazar y al reversar), `datos` (JSON: distribución y montos al momento), fecha.

**Posterior (PAG-G):** `bylopez_va_pago_evidencias`. Comprobante ≠ dinero confirmado: nunca confirma
solo.

**No se guarda:** saldo, deuda ni columnas equivalentes (`customers.deuda` queda descartado, P-03),
ni totales derivados. **No se crean:** wallet, saldo a favor ni anticipos.

**Invariantes**

| # | Invariante |
|---|---|
| I-1 | Ninguna deuda ni saldo persistido: saldo del pedido y cartera del cliente son **derivados** |
| I-2 | Un pago `sin_revisar` o `confirmado` tiene aplicaciones activas cuya suma = `monto` (P-06: sin sobreaplicación ni remanente) |
| I-3 | Un pago `rechazado` o `reversado` no tiene aplicaciones activas; sus aplicaciones quedan anuladas (no borradas) con el evento que las anuló |
| I-4 | Para todo pedido, `aplicado_total ≤ total` al escribir, comprobado **bajo bloqueo** |
| I-5 | Toda Transaction nativa creada por ByLopez se vincula a su aplicación **en la misma transacción de base de datos** que la crea. Una Transaction sin vínculo es, por definición, «ajena» |
| I-6 | Confirmar **no** cambia el saldo. Rechazar hace reaparecer exactamente lo aplicado por ese pago |
| I-7 | `Invoice paid` jamás cuenta como pago por sí sola |
| I-8 | Registrar nunca confirma. Todo pago nace `sin_revisar` |
| I-9 | Eventos append-only; cada transición de estado escribe su evento en la misma transacción |
| I-10 | Permisos y visibilidad se validan **en el servicio**, no solo en vistas ni botones |
| I-11 | Todas las aplicaciones de un pago pertenecen a pedidos del `customer_id` del pago |
| I-12 | No se modifica `orders.status` directamente: solo a través de repositorios y eventos públicos de Bagisto, y solo al confirmar |

### 42.3 Fórmulas

Todo en **moneda base**. Para un pedido `O` en el alcance de Cartera (§42.8):

```text
T(O)     = orders.base_grand_total
Ap(O)    = Σ monto de aplicaciones de O con anulada_en IS NULL          (pagos sin_revisar o confirmado)
Nv(O)    = Σ order_transactions.amount de las facturas de O
           cuya id NO aparece en aplicaciones.order_transaction_id        («ajenas» a ByLopez)

aplicado_total(O) = Ap(O) + Nv(O)
verificado(O)     = Ap_confirmado(O) + Nv(O)         ← Ap_confirmado: aplicaciones activas de pagos confirmados
sin_revisar(O)    = Ap_sin_revisar(O)
                    ⇒ aplicado_total = verificado + sin_revisar
saldo(O)          = max(0, T(O) − aplicado_total(O))     (redondeo a 2 decimales)
```

**Sin doble conteo.** Al confirmar, la aplicación pasa de `sin_revisar` a `confirmado` y recibe su
`order_transaction_id`. Esa Transaction **queda excluida de `Nv`** por el vínculo, así que el pago
se cuenta **una sola vez**: `sin_revisar` baja, `verificado` sube y `aplicado_total` y `saldo` no se
mueven.

**Estado de pago derivado del pedido** (evolución de `EstadoPagoPedido`, PAG-C; conserva las
constantes de `ConfirmacionVenta`):

| Estado | Condición |
|---|---|
| `no_aplica` | Pedido `canceled`, `closed` o `fraud` |
| `revisar` | Pedido con Refund (P-10) · `Invoice paid` sin Transaction ni aplicación (D-48/histórico) · `aplicado_total > T` (anomalía) |
| `pendiente` | `aplicado_total = 0` |
| `parcial` | `0 < aplicado_total < T` |
| `pagado` | `saldo = 0` y ninguna condición de `revisar`; la interfaz indica «por revisar» si `sin_revisar > 0` (no es un estado nuevo) |

Para pedidos **fuera de Cartera** (anteriores a la fecha de corte) se conserva la derivación actual
de D-56 (Invoice + Transaction).

**Cartera del cliente `C`:**

```text
pedidos_en_cartera(C) = pedidos de C, en el alcance de Cartera (§42.8),
                        con estado ∉ {canceled, closed, fraud}
cartera(C)            = Σ saldo(O) para O ∈ pedidos_en_cartera(C) sin Refund
por_revisar(C)        = Σ saldo(O) para O con Refund (se muestra aparte, sin ocultar la deuda;
                        no entra en la propuesta FIFO)
```

Detalle por pedido: **Total · Aplicado · Verificado · Sin revisar · Saldo**. Las consultas deben
agregar por lote (sumas agrupadas por pedido y por cliente), sin consulta por pedido: la misma
estrategia que «Mis ventas».

### 42.4 Estados, transiciones y semántica

```text
sin_revisar ──► confirmado ──► reversado   (reversado: capacidad futura, PAG-I)
     └────────► rechazado
```

- Registrar + aplicar es **una operación atómica**; no existe un estado «registrado».
- Terminales: `rechazado` y `reversado`. No hay transición de salida.
- **RECHAZAR ≠ REVERSAR.**
  - **Rechazar:** el ingreso nunca fue válido. Solo desde `sin_revisar`. Anula **exactamente** sus
    aplicaciones. **No** crea Transaction (nunca hubo dinero confirmado en Bagisto). Motivo obligatorio.
  - **Reversar:** el pago **ya estaba confirmado** (ya existe Transaction). Operación excepcional y de
    mayor privilegio. Efecto comercial esperado: sus aplicaciones dejan de contar y la deuda reaparece.
- **Mecánica de la reversión: ABIERTA, no aprobada.** La auditoría propuso una Transaction negativa
  compensatoria. **No se implementa ni se aprueba.** PAG-I empieza con una microauditoría de sus efectos
  sobre `SUM(amount)`, el panel de Bagisto, `Invoice`, `Order`, `Refund`, futuras pasarelas y reportes.
- Pedidos con Refund: no reciben pagos nuevos sin revisión; resultado `revisar` (P-10). **Refund de
  mercancía ≠ reversión de un pago.**

### 42.5 Flujos, idempotencia, bloqueos

**Orden canónico de bloqueos (todas las operaciones):**
`pago → pedidos (id ascendente) → facturas (id ascendente) → creación de Transactions`.
Una sola transacción de base de datos por operación; `DB::transaction` con reintentos ante deadlock
(como §35.5). Sin bloqueo del cliente: los pedidos en común serializan a los abonos que se solapan.

**Registrar (atómico e idempotente)** — desde el pedido (un pedido) o abono general (varios pedidos):

1. `INSERT` del pago `sin_revisar` (reserva `UNIQUE(mecanismo, idempotency_key)`).
2. Bloquear los pedidos afectados, en orden ascendente.
3. Validar bajo bloqueo: pedido en alcance de Cartera, no `canceled`/`closed`/`fraud`, sin Refund, visible
   para el actor, **saldo recalculado ahora**, cada aplicación `≤ saldo`, suma de aplicaciones **= monto**
   (si no hay deuda aplicable suficiente se **rechaza** con motivo claro: P-06), medio válido y referencia
   según su política.
4. `INSERT` de las aplicaciones y del evento `registrado`. `COMMIT`.

- **Idempotencia** (D-53 §35.3, se conserva): misma clave + misma huella → devuelve el pago existente
  (`duplicado = true`); misma clave + huella distinta → conflicto; clave distinta → otro pago legítimo.
  La huella cubre cliente, monto, medio, referencia, fecha y la distribución normalizada (pares
  `order_id:monto` ordenados). Un doble clic, un reintento HTTP o dos usuarios **no duplican** ni
  **sobreaplican**: el segundo espera el bloqueo del pedido, recalcula y se rechaza.
- **La propuesta FIFO** se calcula antes (en la interfaz o el servicio) pero **el backend revalida todo**;
  si el saldo cambió, rechaza y devuelve una propuesta nueva.

**Confirmar** (`sin_revisar → confirmado`):

1. Bloquear el pago; exigir `estado = sin_revisar` (si no, `409`, sin escribir).
2. Bloquear pedidos y luego facturas (ascendente).
3. Por **cada aplicación activa**: crear la Transaction nativa con `OrderTransactionRepository::create`
   (monto de la aplicación, medio, factura del pedido) y **vincularla** (`order_transaction_id`) en
   la misma transacción de base de datos.
4. Si la factura queda cubierta: `InvoiceRepository::updateState('paid')` y después
   `OrderRepository::updateOrderStatus($order)` **sin estado** (cálculo por cantidades, §35.2). Nunca
   `UPDATE` directo sobre `orders.status` (I-12).
5. Estado `confirmado`, revisor y fecha, evento `confirmado`. `COMMIT`.

- **Confirmar dos veces:** la guarda de estado bajo bloqueo y el `UNIQUE` de `order_transaction_id` impiden
  una segunda Transaction. **El saldo no cambia** (I-6).
- **Puntos a resolver con una microauditoría puntual en PAG-E (no se deciden aquí):** pedido sin factura
  (la Transaction exige `invoice_id`; ver la configuración objetivo, §42.9), pedido con varias facturas y
  reparto de una aplicación entre facturas; cómo se compone el `transaction_id` de la Transaction.

**Rechazar** (`sin_revisar → rechazado`): bloquear pago y pedidos (ascendente); exigir `sin_revisar`;
anular exactamente sus aplicaciones (`anulada_en`); estado `rechazado`; motivo obligatorio; evento.
**No** toca Transactions, `Invoice` ni `Order`. La deuda **reaparece automáticamente**. Rechazar dos
veces no duplica nada (guarda de estado).

**Registrar y confirmar son capacidades distintas.** Un usuario con ambos permisos puede hacer las dos
acciones, pero **registrar jamás confirma** (I-8); el evento guarda siempre al actor.

### 42.6 ACL (la validación vive en el servicio)

Claves propuestas (siguen la convención de `Config/acl.php`: planas, `ventas_asesor.<grupo>.<hoja>`, sin
guiones):

| Clave | Autoriza |
|---|---|
| `ventas_asesor.cartera` | Agrupadora: ordena el árbol de Roles y abre el listado. **No autoriza operaciones** |
| `ventas_asesor.cartera.view_own` | Ver cartera, saldos y pagos de **sus** pedidos y clientes (mismo criterio que `pedidos.view_own`: atribución `advisor_id`; sin atribución válida y sin `view_all`, se niega: RA-6) |
| `ventas_asesor.cartera.view_all` | Ver toda la cartera |
| `ventas_asesor.cartera.registrar` | Registrar + aplicar un pago a **un** pedido que el actor puede ver. Nace `sin_revisar` |
| `ventas_asesor.cartera.abono_general` | Registrar un abono multipedido de un cliente (FIFO editable), solo sobre pedidos visibles |
| `ventas_asesor.cartera.revisar` | Confirmar y rechazar |
| `ventas_asesor.cartera.reversar` | Reversar un pago confirmado (diseñado, **no implementado**) |

- **Reglas heredadas de `acl.php`:** la agrupadora lleva una ruta **string** (no arreglo), porque
  `SessionController::redirectToFirstAccessibleRoute()` no indexa arreglos; cada hoja necesita una ruta
  GET sin parámetros en la posición 0 de su `route`; las rutas POST (registrar, abono, confirmar, rechazar,
  reversar) se añaden como elementos de su hoja (Bouncer mapea **una** clave por ruta; la última gana).
  `AclYMenuConfiguracionTest` exige el árbol exacto: PAG-B debe actualizarlo.
- `AutorizacionVentasAsesor` se ampliará con el alcance de cartera y las comprobaciones por acción.
- **Quién revisa:** supervisora, administrador u otro rol al que se otorgue `revisar`. **Nada se
  hardcodea** por nombre ni identidad (ni «mamá», ni Camilo, ni «supervisora»).
- **P-12 (política; no se cambian roles en PAG-A):** los asesores no usan el flujo nativo
  `sales.transactions` (claves nativas `sales.transactions`, con `admin.sales.transactions.store`, y
  `sales.transactions.view`). Revisar en el despliegue (§42.12).

### 42.7 Medios de cobro y referencia

- **Medio de cobro ≠ método de pago del pedido.** Un pedido contraentrega puede cobrarse luego por
  transferencia. Dato real (dev, 2026-10-05): `moneytransfer` se titula «Transferencia de dinero(NEQUI,
  DAVIPLATA, EFECTIVO, BANCO)»: **un mismo método de Bagisto cubre varios medios reales**, así que el
  medio de cobro se pide al registrar y nunca se infiere del método del pedido.
- **Fuente única configurable** (propuesta: `ventasasesor.pagos.medios_cobro`, junto a
  `metodos_permitidos`), con, por medio: código estable, clave de traducción, `activo` y política de
  referencia (`requerida` / `opcional` / `no_aplica`). La política **no se dispersa** en controladores.
  Es la lista que ya preveía §35.6; no admite texto libre.
- **Valores iniciales conceptuales:** `transferencia`, `efectivo`, `contraentrega_recaudo`.
  **Transferencia: referencia `requerida` (preferida).** Si en operación aparecen casos legítimos sin
  referencia, se reporta antes de relajarla; no se encontró evidencia en el código a favor ni en contra.
  Sin integraciones con Nequi, Daviplata, bancos ni pasarelas (posteriores).
- El código del medio de cobro es el que recibirá `order_transactions.payment_method` al confirmar (D-50).

### 42.8 Histórico y fecha de corte (propuesta; sin backfill)

- **Hecho:** hay pedidos con `Invoice paid` y **0 Transactions** (las transferencias históricas, D-48; el
  pedido 41 es ejemplo vivo). Eso **no demuestra pago**.
- **Reglas aprobadas:** no crear pagos heredados, no reconciliar el histórico automáticamente y no
  convertir `Invoice paid` en `Pago confirmado`.
- **Política propuesta (pendiente de aprobación de Camilo):**
  1. **`cartera.fecha_corte`:** almacenamiento propuesto en `core_config` (editable desde el panel, sin
     despliegue). **Falla cerrado:** sin fecha, Cartera no calcula saldos ni permite registrar (solo
     lectura heredada de D-56).
  2. Los pedidos con `created_at ≥ fecha_corte` entran en Cartera; los anteriores quedan **«Histórico»**
     con la derivación actual (D-56), sin saldo de Cartera y sin aplicar pagos.
  3. **Precondición:** la fecha de corte debe ser **posterior al despliegue de la configuración objetivo de
     `moneytransfer`** (§42.9); si no, los pedidos nuevos por transferencia nacerían con `Invoice paid`
     sin dinero y saldrían como `revisar`.
  4. **Incorporación puntual** de un pedido histórico: acción explícita, auditada, con motivo y permiso
     elevado; nunca masiva ni automática (es el «casos puntuales» ya recomendado en D-12). Su mecanismo se
     define en PAG-F.
- **Pedido 41** (`BYL-00000025-CO`): `Invoice paid` + 0 Transactions. **No se modifica, no recibe Pago ni
  Transaction y no se usa para pruebas con escritura**; solo lectura de referencia. Queda anterior a
  cualquier fecha de corte válida.

### 42.9 Configuración objetivo de `moneytransfer` (diseñada, NO aplicada)

| Clave `core_config` | Hoy (dev, 2026-10-05) | Objetivo |
|---|---|---|
| `sales.payment_methods.moneytransfer.generate_invoice` | `1` | `1` (se mantiene: la Transaction nativa exige una factura abierta) |
| `sales.payment_methods.moneytransfer.invoice_status` | `paid` | **`pending`** |
| `sales.payment_methods.moneytransfer.order_status` | `processing` | **`pending_payment`** (como `cashondelivery`, que ya está en `pending` / `pending_payment`) |

- Es la «opción nativa» de D-48: factura pendiente → Transaction al confirmar → factura `paid`. Con ello
  una transferencia deja de producir `Invoice paid` sin dinero.
- **Alcance:** global (también afecta a la tienda) y en producción requiere autorización explícita (D-48).
- **PAG-A no cambia nada.** El cambio real va en PAG-F, antes de fijar la fecha de corte; hasta entonces
  Cartera **no** puede tratar `Invoice paid` como pago.
- **Sin relación con el correo de factura:** `new_invoice = 0` (temporal en desarrollo) sigue como está y
  el problema de PDF/correo es deuda aparte.

### 42.10 Entradas de usuario (arquitectura, no implementadas)

La capa de servicios es **una sola**; las tres entradas la reutilizan.

| Entrada | Contenido | Notas |
|---|---|---|
| **A. Desde el pedido** | «Mis ventas → Pedido → Registrar pago»: Total · Pagado/aplicado · Saldo · `[+ Registrar pago]` (modal sencilla) | La más rápida; un pedido |
| **B. Desde el cliente** | Cartera total, pedidos con saldo, pagos recientes y `[+ Registrar abono]` | Multipedido con **propuesta FIFO editable** |
| **C. Bandeja Pagos / Cartera** | `SIN REVISAR`, `CONFIRMADOS`, `RECHAZADOS`, `CARTERA` y filtros | Para supervisión: confirmar rápido; rechazar exige motivo |

- **FIFO es propuesta, no regla.** Ej.: A 200.000 · B 300.000 · C 400.000 con un pago de 500.000 → propone
  A 200.000 + B 300.000, pero el usuario autorizado puede cambiarlo a A 100.000 · B 0 · C 400.000. El
  backend revalida.
- **Sin ficha paralela de Customer (P-09):** el Customer de Bagisto sigue siendo la autoridad. La «Cartera del
  cliente» es de ByLopez con ACL propia, y se enlaza con una tarjeta/resumen mediante un **hook limpio en la
  ficha nativa** (el mecanismo exacto se audita puntualmente en PAG-D). Prohibido sobrescribir vistas de
  Webkul.
- **Pago registrado = saldo comercial menor de inmediato**, aunque esté `sin_revisar`: la revisión no
  bloquea la operación.
- **P-11:** no existe la regla «saldo pendiente ⇒ prohibido despachar». Contraentrega: pedido → pendiente de
  pago → preparación → despacho → cobro → registro → revisión.

### 42.11 Ejemplos canónicos (casos de prueba futuros)

**1. Pago simple.** Pedido 500.000, saldo 500.000. El asesor registra 300.000 por transferencia →
aplicado 300.000, sin revisar 300.000, **saldo 200.000**. La supervisora **confirma** → verificado
300.000, sin revisar 0, **saldo sigue 200.000** (no baja a −100.000). Escenario alterno: la supervisora
**rechaza** → la aplicación se anula y el **saldo vuelve a 500.000**; la auditoría se conserva.

**2. Multipedido.** Cliente Rosario con A 200.000, B 300.000 y C 400.000 (cartera 900.000). Abono de
500.000; FIFO propone A 200.000 + B 300.000 → A 0, B 0, C 400.000, **cartera 400.000**. Si el pago se
rechaza: A 200.000, B 300.000, C 400.000, **cartera 900.000**.

**3. Sobreaplicación.** Saldo aplicable total 200.000 y pago de 300.000 → **se rechaza** con motivo
claro (sin wallet ni saldo a favor).

**4. Anti doble conteo.** Confirmar un pago crea la Transaction vinculada: el saldo no cambia. Una
Transaction creada a mano en el panel nativo y **sin vínculo** sí cuenta en `Nv` (dinero verificado
ajeno).

**5. Idempotencia y concurrencia.** Doble clic o reintento HTTP con la misma clave → un solo pago. Dos
usuarios aplicando al mismo saldo → solo uno aplica; el otro recalcula y se rechaza. Confirmar dos veces
→ una sola Transaction. Rechazar dos veces → sin efecto doble.

### 42.12 Checklist de despliegue (no se ejecuta en PAG-A)

1. Aplicar la configuración objetivo de `moneytransfer` (§42.9), con autorización explícita.
2. Fijar `cartera.fecha_corte` posterior a (1).
3. Revisar roles: ningún rol de asesor con `sales.transactions` ni `sales.transactions.view`; otorgar
   `cartera.*` según el rol (P-12).
4. Confirmar que no quedan pedidos nuevos con `Invoice paid` sin Transaction ni aplicación.
5. Mantener (o decidir aparte) la configuración temporal de `new_invoice`.

### 42.13 Riesgos

| # | Riesgo | Mitigación |
|---|---|---|
| R-1 | Las pantallas nativas de Bagisto (facturas, transacciones, panel) mostrarán el pedido sin pagar hasta confirmar | Es intencional (P-01); Mis ventas y Cartera leen el dominio ByLopez |
| R-2 | Doble conteo si falta el vínculo `order_transaction_id` | I-5 + prueba de invariante |
| R-3 | Pedidos nuevos con `Invoice paid` sin dinero si la configuración no está alineada | Precondición de la fecha de corte |
| R-4 | Uso de `sales.transactions` nativo por un asesor (sin revisión ni bloqueo) | P-12; esas Transactions cuentan como `Nv` pero se saltan la revisión |
| R-5 | La pantalla nativa de transacciones no bloquea: carrera residual contra la guarda de sobreaplicación | Se recalcula `Nv` bajo bloqueo del pedido; riesgo ya documentado en §35.5 |
| R-6 | Pedido sin factura o con varias facturas al confirmar | Microauditoría puntual en PAG-E |
| R-7 | Refund posterior a un pago | `revisar` (P-10); Refund ≠ reversión |
| R-8 | Mecánica de reversión sin cerrar | Microauditoría al inicio de PAG-I |

### 42.14 Hoja de ruta (el orden definitivo lo fija Camilo en cada prompt)

| Fase | Contenido | Puerta |
|---|---|---|
| **03G** | Validación manual de Camilo y cierre documental posterior | Independiente de PAG |
| **PAG-A** | Arquitectura (este documento y D-59) | ✅ cerrada en documentación |
| **PAG-B** | Modelo persistente (3 migraciones, modelos, enums), fuente `medios_cobro`, claves ACL (con su prueba) y **consulta de cartera** (servicios de lectura: saldo del pedido y cartera del cliente) | Sin registrar pagos todavía. **IMPLEMENTADA 2026-10-06 (sin commit): ver §42.16** |
| **PAG-C** | Registrar pago desde el pedido (servicio atómico e idempotente + modal) y estado de pago de Mis ventas leyendo del dominio nuevo (evoluciona `EstadoPagoPedido`; salda la duplicación con `ConfirmarVentaService::estadoPago()`) | **IMPLEMENTADA 2026-10-06 (sin commit): ver §42.18.** Pagos reales bloqueados por diseño hasta fijar `cartera.fecha_corte` y migrar desarrollo |
| **PAG-D** | Cartera del cliente + abono general multipedido (FIFO editable) + hook en la ficha del cliente | **IMPLEMENTADA 2026-10-06 (sin commit): ver §42.20** |
| **PAG-E** | Bandeja central Pagos/Cartera + **Confirmar / Rechazar** (crea la Transaction nativa al confirmar; microauditoría de factura/pedido) | **IMPLEMENTADA 2026-10-06 (sin commit): ver §42.21** |
| **PAG-F** | Configuración objetivo de `moneytransfer`, fecha de corte, incorporación puntual de históricos, roles (P-12) y activación | **No activar en producción antes de PAG-E y PAG-F**. **CERRADA EN DESARROLLO 2026-10-07 / PENDIENTE ROLLOUT PRODUCCIÓN: `moneytransfer` objetivo, corte `2026-10-06` (opción A) y rol `#2 ASESOR VENTAS` sin módulo Sales nativo (§42.22-§42.24)** |
| **PAG-G** | Evidencias / comprobantes | |
| **PAG-H** | Conciliación y saldo a favor / sobreaplicación (estudio; hoy fuera de alcance) | |
| **PAG-I** | Reversión de pagos confirmados | Empieza con microauditoría del mecanismo |
| Posterior | Canales automatizados (n8n / WhatsApp / IA) sobre la **misma** capa de servicios | IA no confirma dinero; n8n no escribe tablas directamente |

### 42.15 Fuera de alcance de PAG-A (confirmado)

Migraciones, modelos, servicios, controladores, vistas, API, cambios de configuración (`core_config`),
cambios de roles, backfill, Transactions, Invoices, restauración de `new_invoice`, y cualquier toque a
Webkul, a 03G, a Nueva venta, al PDF/correo, a Logística o a n8n/WhatsApp/IA.

### 42.16 PAG-B: modelo persistente y consulta de cartera — IMPLEMENTADO (2026-10-06, sin commit)

**Alcance (solo infraestructura).** Tres tablas, modelos, estados, fuente de medios de cobro, claves ACL,
lectura de la fecha de corte y el servicio de CONSULTA. **No** registra, confirma, rechaza ni reversa pagos;
**no** crea Transactions ni Invoices; **no** hay interfaz, rutas, API ni evidencias (PAG-G); **no** cambia
configuración de Bagisto ni roles (P-12 sigue como política de despliegue); Webkul intacto; pedido 41
intacto; sin backfill. Los servicios de escritura de los eventos (`registrado`, `confirmado`…) tampoco
existen todavía: una prueba verifica que no hay clases de registrar/confirmar/rechazar/reversar.

**Tipos reales verificados (lpz_saas, MariaDB 10.4.28):** `orders.id`, `customers.id`, `invoices.id`,
`order_transactions.id` y `admins.id` son `int(10) unsigned`; el dinero de Bagisto es `decimal(12,4)`
(`orders.base_grand_total`, `order_transactions.amount`). Por eso las FK de ByLopez hacia Bagisto son
`unsignedInteger` (no bigint) y el dinero propio es `decimal(12,4)`. Las claves propias de las tres
tablas son `bigint unsigned` (`$table->id()`).

#### Migraciones (`src/Database/Migrations/`, aditivas, solo tablas propias)

`2026_10_06_000011_create_bylopez_va_pagos_table`,
`2026_10_06_000012_create_bylopez_va_aplicaciones_pago_table`,
`2026_10_06_000013_create_bylopez_va_pagos_eventos_table`. **Aplicadas SOLO a `lpz_saas_testing`**
(convención de las fases de pago); `lpz_saas` (desarrollo) no se migró: un `php artisan migrate` allí las
creará (son aditivas y quedan vacías). `down()` borra solo su tabla; los FK RESTRICT impiden deshacer una
tabla con historia dependiente.

**`bylopez_va_pagos`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint unsigned PK | |
| `customer_id` | int unsigned | FK `customers.id` **RESTRICT** (`bvapag_customer_fk`) |
| `monto` | decimal(12,4) | CHECK `monto > 0` (`bvapag_monto_positivo`) |
| `medio_cobro` | varchar(40) | Código de `MediosCobro` (no es el método de pago del pedido) |
| `fecha_pago` | date | Cuándo ingresó el dinero (distinta de `created_at`) |
| `referencia`, `referencia_normalizada` | varchar(100) null | La normalizada es solo un índice para detectar repetidas (aviso, no bloqueo) |
| `observacion` | text null | |
| `estado` | varchar(20), default `sin_revisar` | Valor de `Enums\EstadoPago` |
| `mecanismo`, `idempotency_key`, `huella_solicitud` | varchar(30), varchar(100), char(64) | Idempotencia de D-53 |
| `registrado_por_admin_id` | int unsigned | **Sin FK** (la auditoría sobrevive al administrador) |
| `revisado_por_admin_id`, `revisado_en`, `motivo_revision` | int unsigned null, timestamp null, varchar(500) null | Sin FK |
| `created_at`, `updated_at` | timestamps | |

Índices: **UNIQUE `bvapag_idem_unique` (`mecanismo`, `idempotency_key`)**; `bvapag_cliente_estado_idx`
(`customer_id`, `estado`); `bvapag_estado_fecha_idx` (`estado`, `created_at`, para la bandeja);
`bvapag_referencia_idx` (`medio_cobro`, `referencia_normalizada`); `bvapag_registrador_idx`.

**`bylopez_va_aplicaciones_pago`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint unsigned PK | |
| `pago_id` | bigint unsigned | FK `bylopez_va_pagos` **RESTRICT** |
| `order_id` | int unsigned | FK `orders.id` **RESTRICT** |
| `invoice_id` | int unsigned null | FK `invoices.id` **RESTRICT**; se fija al confirmar |
| `monto` | decimal(12,4) | CHECK `monto > 0` |
| `posicion` | smallint unsigned, default 1 | Orden de la distribución (propuesta FIFO); informativo |
| `anulada_en`, `anulada_por_admin_id` | timestamp null, int unsigned null | NULL = activa; sin FK al administrador |
| `order_transaction_id` | int unsigned null | FK `order_transactions.id` **RESTRICT**; **UNIQUE** (varios NULL permitidos); es el vínculo anti doble conteo |
| `created_at` | timestamp default now | Sin `updated_at` |

Índices: **UNIQUE `bvaap_pago_order_unique` (`pago_id`, `order_id`)**; **UNIQUE `bvaap_transaction_unique`
(`order_transaction_id`)**; `bvaap_order_activa_idx` (`order_id`, `anulada_en`).

**`bylopez_va_pagos_eventos`** (append-only): `id`, `pago_id` (FK RESTRICT), `tipo` varchar(30)
(`Enums\TipoEventoPago`), `actor_admin_id` int unsigned **null y sin FK**, `mecanismo` varchar(30), `motivo`
varchar(500) null, `datos` json null, `created_at`. Índices `(pago_id, created_at)`, `tipo`,
`actor_admin_id`. La inmutabilidad la impone el modelo (`PagoEvento` lanza
`PagoEventoInmutableException` en `update`, `delete` y `save` sobre filas existentes); **alcance honesto:**
son eventos de Eloquent, una consulta masiva o SQL directo los salta (misma regla que el historial de
atribución: los eventos solo se escriben por el servicio).

**Diferencias con el diseño conceptual de §42.2 (decididas al implementar):** (1) se omitió
`anulada_evento_id` (circularidad entre tablas y poco valor; se conserva `anulada_en` + `anulada_por_admin_id`,
y el evento de rechazo/reverso vive en `bylopez_va_pagos_eventos`); (2) se agregaron `invoice_id` y
`posicion` a las aplicaciones, y `observacion`, `referencia_normalizada` y `motivo_revision` a los pagos;
(3) `mecanismo` en los eventos; (4) CHECK `monto > 0` (MariaDB ≥ 10.2.1 / MySQL ≥ 8.0.16); (5) sin snapshot
de nombre del administrador (se puede añadir después).

#### Código

| Pieza | Responsabilidad |
|---|---|
| `Enums\EstadoPago` | Fuente única de `sin_revisar`, `confirmado`, `rechazado`, `reversado`; `transicionesPermitidas()` / `puedeTransicionarA()` (solo las 3 aprobadas), `esFinal()`, `cuentaParaSaldo()` (sin_revisar y confirmado), `esVerificado()`, `valores()`. **No ejecuta transiciones** |
| `Enums\TipoEventoPago` | `registrado`, `confirmado`, `rechazado`, `reversado`, `evidencia_agregada`, `posible_duplicado`; `estadoResultante()`, `exigeMotivo()` |
| `Models\Pago`, `AplicacionPago`, `PagoEvento` | Eloquent planos (como el resto del paquete); `Pago` castea `estado` al enum; `AplicacionPago::scopeActivas()`; relaciones |
| `Support\Cartera\MediosCobro` | **Fuente única** de medios de cobro sobre `ventasasesor.pagos.medios_cobro`: `todos()`, `activos()`, `existe()`, `estaActivo()`, `etiqueta()`, `politicaReferencia()`, `exigeReferencia()`, `admiteReferencia()`, `normalizarReferencia()`. Configuración inválida → `LogicException` |
| `Support\Cartera\FechaCorteCartera` | Lee `ventasasesor.cartera.fecha_corte`: `valor()`, `activa()`, `incluye($creadoEn)`. **Sin fecha = inactiva (falla cerrado)**; no se inventó ninguna fecha |
| `Support\AutorizacionVentasAsesor` | + constantes `PERMISO_CARTERA_*` y `alcanceDeCartera()` / `puedeVerCarteraDe()` (espejo de `pedidos`; la agrupadora sola no concede nada; `pedidos.view_all` no da cartera) |
| `DataObjects\SaldoPedido`, `CarteraCliente` | DTO de lectura (con `toArray()`) |
| `Services\Cartera\CarteraConsultaService` | `saldosDePedidos(array)` por lote y SIN actor; `saldoDePedido($id, $actor)`; `carteraDelCliente($customerId, $actor)` |

**Medios de cobro (configuración inicial, a confirmar por Camilo).** `transferencia` (referencia
**requerida**), `efectivo` (opcional), `contraentrega_recaudo` (opcional); etiquetas en
`ventasasesor::app.cartera.medios.*` (es/en). Una prueba garantiza que esos códigos no se repiten en ningún
otro archivo del paquete.

**ACL (`Config/acl.php`, 7 claves nuevas; el árbol pasa de 23 a 30):** `ventas_asesor.cartera` (sort 7,
agrupadora, ruta string) y las hojas `view_own` (1), `view_all` (2), `registrar` (3), `abono_general` (4),
`revisar` (5), `reversar` (6). Todas apuntan a la ruta de inicio como marcador (regla 3 del encabezado) hasta
que PAG-C cree la suya. **No se asignó a ningún rol existente** y no se quitó `sales.transactions` (P-12 sigue
siendo checklist de despliegue). `AclYMenuConfiguracionTest` se actualizó (listas y conteo 30).

#### Consulta: la fórmula implementada (Doc. 05 §42.3)

Por pedido en Cartera, en moneda base y con redondeo a 2 decimales:
`Ap` = aplicaciones activas (`anulada_en IS NULL`) de pagos `sin_revisar` o `confirmado`;
`Nv` = suma de `order_transactions.amount` del pedido **sin vínculo** en
`aplicaciones.order_transaction_id` (anti-join); `aplicado_total = Ap + Nv`;
`verificado = Ap_confirmado + Nv`; `sin_revisar = Ap_sin_revisar`;
`saldo = max(0, orders.base_grand_total − aplicado_total)`. **Ejemplo canónico probado:** pedido 500.000,
pago sin revisar 300.000 → saldo 200.000; al «confirmar» (Transaction vinculada) el saldo **sigue** en
200.000; sin el vínculo la misma Transaction contaría doble (aplicado 600.000, saldo 0, marcado
`sobreaplicado`). Rechazar (aplicaciones anuladas) → saldo 500.000.

- **Fuera de Cartera** (`en_cartera = false`, sin cifras): `cartera_inactiva` (sin fecha de corte),
  `estado_no_aplica` (`canceled`, `closed`, `fraud`) y `anterior_a_fecha_corte` (creado antes de la fecha; el
  límite exacto entra).
- **`requiere_revision` y motivos** (nunca se resuelven solos): `pedido_con_reembolso` (P-10:
  `orders.base_grand_total_refunded > 0` o fila en `refunds`), `factura_pagada_sin_evidencia` (`Invoice paid`
  sin Transaction ni aplicación: **no es pago**), `sobreaplicado` y `aplicacion_activa_en_pago_cerrado`
  (aplicación activa de un pago rechazado/reversado: invariante I-3 violada; no se cuenta y se señala).
- **Cartera del cliente:** suma del saldo de sus pedidos en Cartera **sin** Refund; los pedidos con Refund se
  suman aparte en `por_revisar_total`; `pedidos` sale del más antiguo al más nuevo (orden FIFO) con su
  desglose Total · Aplicado · Verificado · Sin revisar · Saldo; `fuera_de_cartera` cuenta el resto.
- **Alcance por actor (I-10):** `view_all` = todo; `view_own` = solo pedidos atribuidos al actor
  (`bylopez_va_order_attributions.advisor_id`; sin atribución se niega, RA-6); sin permiso (o solo la
  agrupadora, o solo `pedidos.view_*`) → `AuthorizationException`; un pedido ajeno o inexistente devuelve
  `null` (no se revela). `saldosDePedidos()` no valida alcance y es solo para llamadores que ya lo acotaron.
- **Rendimiento:** **5 consultas agrupadas por lote** (pedidos, reembolsos, aplicaciones, Transactions ajenas,
  facturas pagadas), constantes con 1 o 15 pedidos (probado). Solo lectura (probado: ni un INSERT/UPDATE/DELETE).
- **`EstadoPagoPedido` y «Mis ventas» no cambian** en PAG-B: su evolución hacia este dominio es de PAG-C.

#### Pruebas (dirigidas, secuenciales, sobre `lpz_saas_testing`)

| Archivo | Resultado |
|---|---|
| `PagBModeloPersistenteTest` | 16 passed (140 aserciones), ≈ 29 s: esquema y tipos de Bagisto, UNIQUE de idempotencia / pago-pedido / transacción, CHECK `monto > 0`, FK RESTRICT, eventos append-only, enums y transiciones, medios de cobro (fuente única y política), fecha de corte, ACL |
| `PagBCarteraConsultaTest` | 14 passed (123 aserciones), ≈ 8 s: fórmula y ejemplo canónico, anti doble conteo, rechazo, Transactions ajenas, estados fuera de Cartera, Refund, fecha de corte, Invoice paid sin evidencia, cartera Rosario (FIFO y rechazo), alcance, lote, sin N+1, solo lectura |
| `AclYMenuConfiguracionTest` | 10 passed (201 aserciones): árbol de 30 claves |
| `AutorizacionVentasAsesorTest` | 32 passed (en la corrida combinada) |

Pint: sin hallazgos en los archivos nuevos o tocados. No se ejecutaron regresiones masivas ni suites de otras
fases.

#### Pendiente y siguiente

- Servicios de **registrar** (PAG-C), **confirmar / rechazar** (PAG-E) y reversar (PAG-I); eventos de auditoría;
  idempotencia en uso real; estado de pago de Mis ventas sobre este dominio (PAG-C).
- **Fecha de corte:** valor, almacenamiento definitivo (propuesta `core_config`) e incorporación puntual de
  históricos siguen **pendientes de aprobación** (PAG-F); mientras tanto Cartera está inactiva.
- Aplicar las migraciones en desarrollo (`php artisan migrate`) cuando se quiera; no se hizo para no alterar la
  base de Camilo sin su visto bueno.
- Registrar en el próximo prompt: ¿se confirma la lista inicial de medios de cobro y la política de referencia
  de `efectivo` y `contraentrega_recaudo` (hoy `opcional`)?

### 42.17 Corrección de acceso/menú tras la validación manual de 03G (2026-10-06)

**Validación manual de Camilo (celular):** cambió las opciones de la venta, confirmó y se creó el pedido
`#BYL-00000045-CO` por **$184.000 COP**; la pantalla reconoció «Esta venta ya fue confirmada» y mostró la modal
«VENTA CREADA». El pedido no se modifica ni se usa para pruebas con escritura.

**Causa exacta del error de acceso.** El botón «Ver pedido» de la modal armaba `/{admin_url}/sales/orders/{id}`.
Esa ruta **no existe** en Bagisto 2.4.9 (la vista nativa es `sales/orders/view/{id}`, con la clave nativa
`sales.orders.view`): resultaba en **404**, no en un problema de ACL. Además apuntaba a la pantalla nativa y no al detalle
propio del módulo, que se rige por `pedidos.view_own|view_all` y la atribución (D-27/RA-6). Se verificó con el
administrador de Camilo (rol «all»): `ventas-asesor/pedidos?pedido=45` y `sales/orders/view/45` dan 200 y
`sales/orders/45` da 404. **No había contradicción entre crear y ver**: la atribución del pedido 45 es
`unassigned / sin_asesor / sin_contexto` (lo creó el admin 1, que no opera como asesor); un rol `view_own` no lo
vería (RA-6, correcto), uno `view_all` o «all» sí.

**Corrección.** «Ver pedido» abre `admin.ventas_asesor.pedidos.index?pedido={id}` (el detalle del módulo, autorizado
registro a registro). No se concedió ningún permiso ni se tocó Webkul.

**Menú «Ventas Asesor»** (Config/menu.php; cada clave reutiliza una clave ACL existente, así que no hay permisos nuevos):

| Entrada | Clave (ACL y menú) | Ruta |
|---|---|---|
| Inicio | `ventas_asesor.operar_como_asesor` | `admin.ventas_asesor.inicio.index` |
| Mis ventas / Pedidos | `ventas_asesor.pedidos` | `admin.ventas_asesor.pedidos.index` |
| Nueva venta (tercer nivel, bajo Mis ventas) | `ventas_asesor.pedidos.create` | `admin.ventas_asesor.nueva_venta.pantalla` |

- Bagisto anida el menú por la clave con puntos y filtra cada entrada con esa misma clave: por eso «Nueva venta» queda
  anidada bajo «Mis ventas / Pedidos» (no hay clave ACL hermana). **Limitación conocida:** el tercer nivel solo se
  despliega cuando la sección está activa; desde otra sección el flyout no lo muestra (la pantalla de Inicio también
  enlaza a Nueva venta). Hacerla de segundo nivel exige una clave ACL nueva (decisión de Camilo; no se tomó).
- «Inicio» usa `operar_como_asesor`: un rol sin esa clave no ve «Inicio» (el módulo se abre en «Mis ventas»).
- **No hay entrada de Cartera**: todavía no existe su pantalla (se agrega con PAG-D/PAG-E). Una prueba lo exige.

### 42.18 PAG-C: registrar pago desde el pedido — IMPLEMENTADO (2026-10-06, sin commit)

**Alcance.** Primera escritura del dominio de pagos: registrar **un** pago aplicado a **un** pedido. **No** confirma,
rechaza ni reversa; **no** crea `order_transactions`; **no** toca `Invoice` ni `orders.status`. El dinero queda
**REPORTADO y aplicado (`sin_revisar`)**, no verificado.

**Servicio `Services\Cartera\RegistrarPagoService::registrar(actor, orderId, datos, idempotencyKey, mecanismo)`.**
Controlador delgado (`Http\Controllers\Admin\PagosController@store`), request de forma
(`Http\Requests\Cartera\RegistrarPagoRequest`), rechazos tipados (`Exceptions\RegistroPagoInvalidoException`, con código
estable y estado HTTP) y resultado (`DataObjects\ResultadoRegistroPago`).

Flujo (una sola transacción de base de datos, hasta 3 intentos ante deadlock):

1. **Autorización en el servicio:** `ventas_asesor.cartera.registrar` **más** alcance de lectura de cartera sobre el
   pedido (`view_all` = todo; `view_own` = pedido atribuido al actor; sin atribución se niega, RA-6). Sin permiso → 403;
   pedido inexistente **o fuera de alcance** → misma respuesta 404 (no revela existencia). Nada hardcodeado por rol o persona.
2. **Falla cerrado:** sin `cartera.fecha_corte` → `cartera_inactiva`, **antes** de tocar ninguna tabla de pagos.
3. Validación de forma y normalización: monto > 0 con máximo 2 decimales; medio **activo** de `MediosCobro`; referencia
   según su política (`transferencia` la exige; `no_aplica` la rechaza); fecha válida y no futura; clave de idempotencia
   de 16 a 100 caracteres seguros. El backend es la autoridad; la interfaz solo refleja las reglas.
4. **Idempotencia** (§42.5): misma clave + misma huella → devuelve el pago existente (`duplicado = true`, HTTP 200, sin
   escribir); misma clave + huella distinta → `idempotencia_en_conflicto` (409). La huella (SHA-256) cubre cliente,
   medio, fecha, referencia normalizada y la distribución `order_id:monto`. El reintento se resuelve **antes** de
   revalidar saldo (reenviar una solicitud ya registrada no falla porque el saldo ya bajó). Una carrera con la misma clave
   la resuelve el `UNIQUE(mecanismo, idempotency_key)` (error 1062 → se devuelve el existente).
5. **Orden de bloqueos** (§42.5): `INSERT` del pago (reserva la clave) → `SELECT … FOR UPDATE` del pedido.
6. **Bajo el bloqueo se recalcula el saldo REAL** con `CarteraConsultaService` (nunca el que vio el navegador) y se valida:
   pedido en Cartera (no cancelado/cerrado/fraude, creado desde la fecha de corte), con cliente, **sin
   `requiere_revision`** (Refund, sobreaplicación, factura pagada sin evidencia…) y `monto ≤ saldo`
   (`monto_excede_saldo`; no existe saldo a favor).
7. `INSERT` de `AplicacionPago` (posición 1, sin `invoice_id` ni `order_transaction_id`) y del evento `registrado`
   (actor, mecanismo y datos: pedido, monto, medio, fecha, `saldo_antes`, `saldo_despues`, huella). Cualquier fallo →
   **rollback completo**: nunca queda un Pago sin aplicación (probado).

El pago **siempre nace `sin_revisar`**, aunque el actor tenga `revisar` (I-8). El saldo comercial baja de inmediato
(ejemplo canónico probado: 500.000 → registra 300.000 → aplicado 300.000, sin revisar 300.000, verificado 0, **saldo 200.000**).
Dos registros simultáneos sobre el mismo pedido se serializan en el bloqueo: el segundo recalcula y se rechaza si ya no
alcanza (la serialización real entre conexiones no se probó con procesos concurrentes; se apoya en `FOR UPDATE` + `UNIQUE`).

**Ruta y ACL.** `POST {admin}/ventas-asesor/pedidos/{pedido}/pagos`, nombre
`admin.ventas_asesor.pedidos.pagos.store`, mapeada a la hoja `ventas_asesor.cartera.registrar` (`$inicio` sigue en la
posición 0). El árbol ACL **no cambia** (30 claves). **Cómo habilitarlo:** ningún rol tiene `cartera.*` (P-12); roles
con permiso «all» ya pueden. Para un rol personalizado hay que marcar, en Roles, `cartera.registrar` y
`cartera.view_own` o `cartera.view_all`. No se asignó nada.

**Interfaz (detalle del pedido, `pedidos?pedido=ID`).** Bloque «Pago / Cartera» con Total · Aplicado · Sin revisar ·
Verificado · Saldo (todo de `CarteraConsultaService`, sin cálculo en Blade ni controlador) y, si procede, el botón
**«+ Registrar pago»** (permiso `registrar`, pedido en Cartera, sin revisión pendiente y con saldo). La modal precarga
el saldo actual como monto (editable para pago parcial), muestra el saldo, ofrece solo los medios activos de
`MediosCobro` (código, etiqueta y política de referencia vienen del servidor), marca la referencia como requerida u
opcional, genera una clave de idempotencia por apertura y bloquea el doble clic. Al terminar recarga el detalle.
Sin fecha de corte el bloque informa «Cartera todavía no está activa» y **no** ofrece el botón.

**Estado de pago de «Mis ventas».** `Support\Pedidos\EstadoPagoPedido::desdeCartera()`: **para pedidos dentro de
Cartera** la fuente única es el dominio (`requiere_revision` → revisar; aplicado = 0 → pendiente; saldo > 0 → parcial;
saldo = 0 → pagado = «aplicado por completo»). La diferencia entre dinero **sin revisar** y **verificado** se muestra
aparte (chips «Sin revisar» / «Verificado» en el listado y cifras en el detalle); nada sin revisar se llama
«confirmado». **Para pedidos fuera de Cartera** (histórico, Cartera inactiva, cancelados…) rige la derivación D-56 por
facturas, como dispone §42.8. `ConsultaPedidosAsesorService` obtiene ambos por lote (sin N+1, probado).
Deuda que sigue: `ConfirmarVentaService::estadoPago()` (03F) conserva su copia de la regla D-56; para un pedido
recién confirmado ambos dan «pendiente». Se unifica cuando se reabra 03F.

**Cartera inactiva ya no toca tablas.** `CarteraConsultaService::saldosDePedidos()` devuelve los pedidos como «fuera
de Cartera» sin consultar las tablas de pagos cuando no hay fecha de corte; así Mis ventas funciona en un entorno sin
las migraciones de PAG-B (desarrollo, hoy).

**Bloqueo real para pagos reales (por diseño, no se saltó):** hoy `cartera.fecha_corte` está **inactiva** y las tablas
de PAG-B **no están migradas en desarrollo**. Para que Camilo registre un pago real sobre `#BYL-00000045-CO` hacen
falta, con su autorización explícita: (1) `php artisan migrate` en desarrollo (crea las 3 tablas vacías), y (2) fijar
`VENTASASESOR_CARTERA_FECHA_CORTE` (mecanismo actual: variable de entorno → config) a una fecha ≤ la creación del
pedido. §42.8 pide que la fecha de corte sea posterior a la configuración objetivo de `moneytransfer` (§42.9, PAG-F); en
desarrollo, con ese pedido, es una decisión de Camilo. **No se inventó ninguna fecha.**

**Pruebas (dirigidas, secuenciales, sobre `lpz_saas_testing`).**

| Archivo | Resultado |
|---|---|
| `PagCRegistrarPagoTest` (nuevo) | 4 passed (51 aserciones): parcial 500k→300k→saldo 200k sin Transactions ni cambios en Invoice/Order; idempotencia (misma solicitud no duplica; otra solicitud con la misma clave → 409); sobreaplicación, monto inválido, referencia requerida, medio no válido y Cartera inactiva rechazados sin escribir nada; ruta y ACL (sin `registrar` → 401; con `registrar` sin alcance o `view_own` sin atribución → 404; con permisos → 201, reintento → 200 duplicado; el detalle muestra el bloque) |
| `PagBCarteraConsultaTest` | 14 passed (tras el atajo con Cartera inactiva) |
| `PagBModeloPersistenteTest` (solo la prueba de «sin servicios de escritura», ahora «solo existe registrar») | 1 passed |
| `Fase05aMisVentasTest` | 10 passed (alcance, estados derivados, KPI, sin N+1) |
| `AclYMenuConfiguracionTest` | 11 passed (menú de 4 entradas con clave ACL y ruta GET reales, sin Cartera; «Ver pedido» al detalle del módulo) |

Pint: sin hallazgos en los archivos PHP modificados. No se ejecutó Playwright, Chromium, JS, 03G, Corte ni suites completas.

**Pendiente (PAG-D / PAG-E y después).**
- **PAG-D:** cartera del cliente, abono general multipedido (FIFO editable) y enlace en la ficha del cliente; **entrada
  de menú de Cartera** cuando exista su pantalla.
- **PAG-E:** bandeja central y **Confirmar / Rechazar** (la Transaction nativa nace solo al confirmar).
- **PAG-F:** configuración objetivo de `moneytransfer`, fecha de corte (valor y almacenamiento), roles (P-12).
- La interfaz (modal y bloque) **no se probó en un navegador** (sin Playwright/JS por instrucción): la valida Camilo a
  mano. Lo verificado: la ruta, el servicio y que el HTML del detalle incluye el bloque y el componente.
- Decisión abierta: hacer «Nueva venta» entrada de segundo nivel del menú requiere una clave ACL nueva.

### 42.19 «Mis ventas»: KPI clicables y listado compacto (2026-10-06, solo UX; sin cambio de reglas de negocio)

- **Contrato de filtro `?resumen=`** (`hoy` · `abiertos` · `por_despachar` · `pendientes_pago`; cualquier otro valor se rechaza por
  validación). Cada tarjeta KPI es un enlace a esa URL y **su cifra y su filtro usan el mismo método**
  (`ConsultaPedidosAsesorService::resumen()`), así que no pueden divergir: hoy = creados desde las 00:00 sin cancelados, fraude ni
  cerrados; abiertos = pendiente / pago pendiente / procesando; por despachar = unidades físicas pendientes (`agregadoDespacho`);
  pendientes de pago = estado de pago exactamente «pendiente» (`idsPendientesDePago`, misma regla que la columna Pago).
- **Convivencia con los demás filtros:** el resumen se combina en AND con búsqueda, desde/hasta, estado, entrega y asesor, y respeta el
  alcance del actor (nunca lo amplía). Al **pulsar una tarjeta** se conservan `q`, `entrega` y `asesor` y se **reemplazan** `estado`
  y fechas (contradirían al indicador); pulsar de nuevo la tarjeta activa, «Quitar filtro» o «Limpiar» vuelven atrás. Las cifras de las
  tarjetas siguen siendo del alcance, no de los filtros.
- Tarjeta activa: borde y fondo más marcados, check y `aria-current`. Listado de 8 columnas (Pedido/Fecha · Cliente · Asesor/Despacho ·
  Unid./Total · Estado · Pago · Entrega · Acción) con pills de color semántico (misma paleta que los `label-*` de Bagisto:
  amarillo pendiente, cian procesando/parcial, verde completado/pagado/despachado, índigo revisar, rojo cancelado, gris no aplica);
  Estado (pedido), Pago (financiero) y Despacho (logístico) nunca comparten etiqueta. Bajo 1180 px se muestran tarjetas (2 columnas en
  tablet, 1 en móvil). El pago muestra, solo dentro de Cartera, «Sin revisar» / «Verificado» y el saldo pendiente.
- Prueba dirigida: `Fase05aMisVentasTest` (KPI clicable → conjunto correcto; convivencia con filtros y alcance).

### 42.20 PAG-C validada por Camilo y PAG-D: Cartera del cliente + abono general multipedido — IMPLEMENTADO (2026-10-06, sin commit)

**PAG-C VALIDADA MANUALMENTE por Camilo (desarrollo).** Sobre `#BYL-00000045-CO` registró dos abonos reales, uno por
transferencia y uno en efectivo. Resultado visible: Total $184.000 · Aplicado $134.000 · Sin revisar $134.000 · Verificado $0 ·
Saldo $50.000 (pago parcial / sin revisar). La lógica de PAG-C no se modificó.

**Alcance PAG-D.** Pantalla «Cartera del cliente» y un abono multipedido de UN cliente. **No** hay bandeja global, confirmar,
rechazar ni reversar (PAG-E); **no** hay menú global de Cartera; **no** se crean `order_transactions`, no se toca `Invoice` ni
`orders.status`; `cartera.fecha_corte` y `moneytransfer` no cambian (PAG-F).

**Servicio `Services\Cartera\RegistrarAbonoClienteService::registrarAbono(actor, customerId, datos, distribucion, idempotencyKey, mecanismo)`.**
Extiende `RegistrarPagoService` **sin modificarlo**: hereda la normalización (monto, medio de `MediosCobro`, referencia según su
política, fecha), la resolución de repeticiones de idempotencia y los rechazos tipados (`RegistroPagoInvalidoException`, con tres
códigos nuevos: `cliente_inexistente`, `distribucion_invalida`, `pedido_de_otro_cliente`). Controlador delgado
(`CarteraClienteController`), forma en `RegistrarAbonoClienteRequest`.

Flujo (una sola transacción, hasta 3 intentos ante deadlock):

1. **Autorización:** `ventas_asesor.cartera.abono_general` **más** alcance de cartera sobre **cada pedido** de la distribución
   (`view_all` = todos; `view_own` = solo pedidos atribuidos al actor; sin atribución se niega, RA-6). Poder ver al cliente no basta:
   un pedido fuera de alcance rechaza todo el abono con la misma respuesta 404 que un pedido inexistente.
2. Falla cerrado sin `cartera.fecha_corte` (antes de tocar las tablas de pagos); cliente existente; clave de idempotencia válida.
3. **Distribución explícita** (lista de `{pedido_id, monto}`): pedidos únicos, cada línea > 0 con máximo 2 decimales y **suma exacta
   = monto** del pago (aritmética en centavos). No se acepta una distribución implícita: el FIFO depende del saldo del momento y haría
   que un reintento con la misma clave pareciera otra solicitud.
4. **Huella** SHA-256 de cliente, medio, fecha, referencia normalizada, monto y distribución (`order_id:monto` ordenada por pedido):
   misma clave + misma huella → devuelve el pago existente (`duplicado`, HTTP 200; el orden de las líneas no cambia la huella);
   misma clave + otra distribución o datos → 409. Una carrera con la misma clave la resuelve el `UNIQUE` (error 1062).
5. **Bloqueos:** `INSERT` del pago (reserva la clave) → `SELECT … FOR UPDATE` de **todos** los pedidos en orden de id ascendente.
6. **Bajo el bloqueo se recalcula el saldo real** de cada pedido (`CarteraConsultaService`) y se valida: pedido del mismo cliente, en
   Cartera, sin `requiere_revision`, con saldo, y **cada línea ≤ su saldo** (`monto_excede_saldo`). Como la suma es igual al monto,
   el abono nunca supera la deuda elegible total: no existe saldo a favor.
7. Un `Pago` `sin_revisar` + **N `AplicacionPago`** (posición por id ascendente) + un evento `registrado` con la distribución y el
   saldo antes/después de cada pedido. Cualquier fallo → **rollback total** (nunca un pago parcialmente aplicado; probado).

**Propuesta FIFO editable.** `Support\Cartera\DistribucionFifo` (pedido elegible más antiguo primero; elegible = en Cartera, con saldo
y sin revisión) calcula la propuesta; la interfaz la replica en el navegador para responder al teclear y el usuario puede editar cada
línea. Es solo una ayuda: el backend revalida todo. Ejemplo probado: A $50.000 · B $100.000, abono $120.000 → A $50.000 + B $70.000;
manual A $20.000 + B $100.000 también válido.

**Consulta (`CarteraConsultaService`).** `carteraDelCliente()` ya existía (PAG-B, FIFO, acotada por alcance, sin N+1); el DTO
`CarteraCliente` suma `totalPedidos()`, `aplicadoTotal()`, `sinRevisarTotal()`, `verificadoTotal()`, `pedidosConSaldo()` y
`pedidosPorRevisar()`. Nuevo `fichaDelCliente()` (solo lectura, acotado al actor): datos del cliente, cartera, pedidos elegibles,
deuda elegible, fechas y el resumen del abono recién registrado. Con `view_own` un cliente sin pedidos propios responde 404 (no se
revela su ficha). Con Cartera inactiva no toca las tablas de pagos.

**Rutas y ACL.** `GET ventas-asesor/cartera?cliente=ID` (`admin.ventas_asesor.cartera.index`, mismo patrón que `pedidos?pedido=ID`;
el controlador exige `view_own` o `view_all`; sin cliente redirige al inicio) y `POST ventas-asesor/cartera/clientes/{cliente}/abonos`
(`admin.ventas_asesor.cartera.abonos.store`, hoja `abono_general`). **Cambio de ACL:** la agrupadora `ventas_asesor.cartera` pasa de
apuntar a `$inicio` a la ruta `cartera.index` (string, regla 3); el árbol sigue en 30 claves y ningún rol recibió permisos. Para
habilitar a un rol personalizado: marcar `abono_general` y `view_own` o `view_all`.

**Accesos contextuales (sin menú global ni tocar Webkul).** (1) Detalle del pedido: botón «Ver cartera del cliente» (con alcance de
cartera sobre ese pedido). (2) Ficha **nativa** del cliente: tarjeta «Pago / Cartera» (deuda, sin revisar, «Ver cartera») inyectada con
el hook propio de Bagisto `bagisto.admin.customers.customers.view.card.orders.before` mediante el listener
`MostrarCarteraEnFichaCliente`; solo se muestra con módulo y alcance de cartera.

**Interfaz.** Cabecera (cliente, teléfono, grupo), 4 KPI (Deuda total · Sin revisar · Verificado · Pedidos con saldo), banner de «Abono
registrado» tras guardar (servidor, vía `?abono=ID`), tabla de pedidos con saldo o en revisión (Pedido/Fecha · Total · Aplicado · Sin
revisar · Verificado · Saldo · Pago · Acción) con los mismos pills de «Mis ventas» (cada pedido con la regla
`EstadoPagoPedido::desdeCartera`), tarjetas apiladas bajo 1024 px (2 columnas en tablet, 1 en móvil), y la modal «Registrar abono»:
monto (por defecto la deuda elegible), medio, fecha, referencia (requerida u opcional según `MediosCobro`, reflejada en la UI y
validada en el servidor), observación y la distribución FIFO editable con contador «Asignado / Falta / Sobra»; el botón se habilita
solo si la distribución cuadra. Sin fecha de corte la pantalla informa que Cartera no está activa y no ofrece el botón.
Duda abierta de estilo: el CSS de los pills está duplicado en `admin/cartera/estilos.blade.php` y en «Mis ventas»; unificar después.

**Pruebas (dirigidas, secuenciales, `lpz_saas_testing`).** `PagDAbonoClienteTest` (nuevo): 4 passed (52 aserciones) — FIFO 50k+70k
con un pago, 2 aplicaciones, 1 evento, saldos y sin Transactions; distribución manual válida y rechazos (suma distinta, línea sobre
saldo, pedido ajeno o repetido, deuda superada) con rollback total; idempotencia (misma solicitud no duplica, otra distribución →
409); ACL y alcance (`registrar` sin `abono_general` → 401; `view_own` con pedido ajeno → 404 sin escribir; pantalla 200/401/403/404
según alcance). Regresión dirigida: `AclYMenuConfiguracionTest` 11, `PagBCarteraConsultaTest` 14, `PagCRegistrarPagoTest` 4, todas
verdes. Pint: sin hallazgos. No se ejecutó Playwright, Chromium, JS, 03G ni Corte; la modal y el responsive los valida Camilo a mano.

**Pendiente.** PAG-E: bandeja central y Confirmar / Rechazar (la Transaction nativa nace solo al confirmar). PAG-F: configuración de
`moneytransfer`, fecha de corte definitiva y roles. Entrada de menú de Cartera cuando exista la bandeja.

### 42.21 PAG-E: bandeja central + Confirmar / Rechazar — IMPLEMENTADO (2026-10-06, sin commit)

**Microauditoría de Transactions en Bagisto 2.4.9 (código real, antes de implementar).**

| Punto | Hallazgo |
|---|---|
| Tabla `order_transactions` | `id` (int), `transaction_id` (string, **no único**), `status`/`type` (string null), `amount` decimal(12,4) default 0, `payment_method` (string null), `data` (json null), `invoice_id` (int unsigned, **NOT NULL**, sin FK a `invoices`), `order_id` (int unsigned, FK a `orders`, cascade), timestamps |
| Modelo / repositorio | `Webkul\Sales\Models\OrderTransaction` (`$guarded` = id y fechas) y `OrderTransactionRepository` (sin lógica ni eventos propios: `create()` plano). Relación `Order::transactions()` |
| Flujo nativo (`TransactionController::store`) | Exige factura existente; rechaza si la factura ya está `paid`, si `amount <= 0` o si Σ Transactions + monto supera `invoice.base_grand_total`; crea con `transaction_id = bin2hex(random_bytes(20))`, `type = payment_method`, `status = 'paid'`, `data = json_encode(['paidAmount'])`; si Σ ≥ total de la factura → `InvoiceRepository::updateState('paid')` y `OrderRepository::updateOrderStatus($order, processing|completed)` |
| Sin / una / varias facturas | **Sin factura: imposible** (`invoice_id` NOT NULL y el flujo la exige). Una factura: caso normal. Varias: la Transaction se asigna a UNA factura con capacidad; cada Transaction pertenece a un solo pedido |
| Monto | Un único `amount` (sin `base_amount`); la capacidad se mide contra `invoice.base_grand_total` (moneda base) |
| `transaction_id` vs PK | `transaction_id` es una referencia externa libre (no única); la PK es `id`. ByLopez usa `BYL-PAGO-{pago}-AP-{aplicación}`; el vínculo real es `aplicaciones.order_transaction_id` (UNIQUE) |
| Eventos / efectos | `create()` no dispara eventos de dominio; `updateOrderStatus` dispara `sales.order.update-status.before/after`. El listener `Admin\Listeners\Invoice` solo crea Transactions al crear una factura con `can_create_transaction` (casilla de la UI de facturas), no aplica aquí |
| Consumidores | `OrderTransactionDataGrid` (pantalla nativa de Transactions), `TransactionResource` (detalle nativo) y pasarelas de pago. **Hallazgo:** el accesor `payment_title` hace `config('payment_methods')[$payment_method]['title']` y **revienta** con un código que no sea un método de Bagisto (`transferencia` → `Undefined array key`) |

**Integración decidida (sin contradicción con la arquitectura; un único refinamiento de D-50).**
- Una **Transaction nativa por aplicación**, cada una en el pedido de su aplicación (una Transaction no puede abarcar varios pedidos), enlazada en `aplicaciones.order_transaction_id` e `invoice_id`. No hay segunda tabla de Transactions.
- **Elección de factura:** la de menor id, abierta (no `paid`) y con capacidad para **toda** la aplicación (Σ de sus Transactions + monto ≤ `base_grand_total`, como el flujo nativo). Nunca se parte una aplicación entre facturas: no podría enlazarse 1:1 y la segunda Transaction contaría doble como «ajena». Sin factura utilizable (sin factura, todas `paid`, o sin capacidad) **no se confirma** (`sin_factura_disponible`, con motivo) y no se crean facturas. En desarrollo 28 de 29 pedidos tienen factura y ninguno tiene más de una.
- **Refinamiento de D-50 / §42.7:** `payment_method` de la Transaction es el **método de pago del pedido** (código que Bagisto conoce; si el pedido no tiene uno reconocido se rechaza con `metodo_pago_pedido_invalido`); el **medio de cobro real** (`transferencia`, `efectivo`…) va en `type` y en `data` (origen, pago, aplicación, referencia, quién registró y confirmó). Es reversible en un solo método (`ConfirmarPagoService::confirmar`).
- Igual que el flujo nativo: si la factura queda cubierta → `InvoiceRepository::updateState('paid')` y `OrderRepository::updateOrderStatus($order)` **sin estado explícito** (por cantidades; jamás un UPDATE directo, I-12).

**Servicios (`Services\Cartera\`).** Base común `RevisionPagoService` (permiso `ventas_asesor.cartera.revisar` **más** alcance de cartera sobre el pago, bloqueo del pago y de sus aplicaciones activas, `Support\Cartera\AlcanceDePagos`: `view_all` = todos; `view_own` = todos los pedidos del pago atribuidos al actor; fuera de alcance = 404 sin revelar). Errores tipados en `RevisionPagoInvalidaException` (código estable y estado HTTP); resultado en `ResultadoRevisionPago`.

*`ConfirmarPagoService::confirmar`* (una transacción; orden de bloqueos **pago → aplicaciones → pedidos id↑ → facturas id↑**): guarda de estado (ya `confirmado` → resultado estable sin escribir; solo `sin_revisar` es confirmable); falla cerrado sin fecha de corte; revalida invariantes (≥1 aplicación activa, Σ = monto, ninguna con Transaction enlazada, pedidos del cliente del pago, en Cartera y sin `requiere_revision`); crea las Transactions y las enlaza; pago → `confirmado` con revisor y fecha; evento `confirmado` con las Transactions. Cualquier fallo → rollback total. **El saldo no se mueve**: la Transaction vinculada sale de las «ajenas» (anti doble conteo), así que solo cambia la composición (Sin revisar ↓ · Verificado ↑). Idempotente por estado bajo bloqueo + `UNIQUE(order_transaction_id)`.

*`RechazarPagoService::rechazar`* (solo `sin_revisar → rechazado`): categoría de `ventasasesor.pagos.motivos_rechazo` (`no_encontrado`, `monto_no_coincide`, `referencia_incorrecta`, `duplicado`, `efectivo_no_entregado`, `otro`) **más** detalle obligatorio (5 a 500 caracteres); anula **lógicamente** las aplicaciones (`anulada_en`, `anulada_por_admin_id`; nada se borra), pago → `rechazado` con `motivo_revision`, evento `rechazado` (actor, mecanismo, motivo y categoría). No crea ni toca Transactions, Invoice ni Order. La deuda vuelve por derivación (no existe saldo guardado). Idempotente; un pago `confirmado` **no** se rechaza (sería un reverso: PAG-I).

**Pantalla.** Entrada de menú **Cartera / Pagos** (clave `ventas_asesor.cartera`, ruta `admin.ventas_asesor.cartera.index`, sin parámetros): la misma ruta sirve la bandeja, el detalle (`?pago=ID`) y la cartera de un cliente (`?cliente=ID`, PAG-D). POST `cartera/pagos/{pago}/confirmar` y `/rechazar` van a la hoja `ventas_asesor.cartera.revisar` (el árbol sigue en 30 claves; ningún rol recibió permisos; el rol «all» funciona). Bandeja: KPI clicables (Sin revisar · Monto sin revisar · Confirmados hoy · Rechazados) con `?resumen=` (`sin_revisar`, `confirmados_hoy`, `rechazados`; cifra y filtro comparten el mismo criterio en `BandejaPagosService::resumen`), filtros GET (buscar cliente/teléfono/referencia, estado, medio, desde, hasta, registrado por —solo `view_all`—), tabla compacta (Pago/Fecha · Cliente · Registrado por · Medio · Monto · Aplicaciones «2 pedidos · #45 · #50» · Estado · Acción Ver/Confirmar/Rechazar) y tarjetas bajo 1024 px. Detalle: datos del pago, aplicaciones con total, monto, saldo antes/después (del evento `registrado`), estado actual del pedido y de su pago, Transaction enlazada, e historial append-only. **Confirmar** pide una modal de confirmación; **Rechazar** exige categoría y detalle. Sin fecha de corte la bandeja informa que Cartera no está activa y no consulta las tablas de pagos.

**Estado de pago de Mis ventas** sigue derivándose de Cartera sin otra fórmula: pago completo sin revisar → «Pagado» + señal «Sin revisar»; tras confirmar → «Pagado» + «Verificado»; tras rechazar el pago deja de contar.

**Pruebas (dirigidas, secuenciales, `lpz_saas_testing`).** `PagEConfirmarRechazarTest` (nuevo): 4 passed (98 aserciones): confirmar (Transaction con `type`/`payment_method`/`transaction_id`, aplicación enlazada, saldo 200.000 antes y después, sin revisar → verificado, factura aún pendiente, doble confirmación sin duplicar); multipedido (una Transaction por pedido, facturas `paid`, pedido sin factura rechazado sin escribir nada); rechazar (aplicación anulada, deuda 184.000 restaurada, 0 Transactions, motivo y categoría obligatorios, idempotente, confirmado no se rechaza y rechazado no se confirma); ACL y alcance (sin `revisar` o sin alcance → `sin_permiso`, `view_own` con pedido ajeno → 404, bandeja/detalle/rutas confirmar y rechazar). Regresión dirigida: `PagDAbonoClienteTest` 4, `PagCRegistrarPagoTest` 4, `PagBCarteraConsultaTest` 14, `AclYMenuConfiguracionTest` 11, todas verdes. Pint aplicado. No se ejecutó Playwright, Chromium, JS, 03G ni Corte; la modal y el responsive los valida Camilo a mano. Los pagos reales de Camilo (#BYL-00000045-CO) no se confirmaron ni rechazaron.

**Pendiente.** PAG-F: configuración objetivo de `moneytransfer` (con ella sus facturas nacerán `pending` y la confirmación podrá cubrirlas; hoy `moneytransfer` crea la factura `paid` y esos pedidos salen «a revisar»), fecha de corte definitiva y roles (P-12). PAG-G: evidencias. PAG-I: reversar. Aviso: una factura `paid`, sin capacidad o sin factura rechaza la confirmación por diseño.

### 42.22 PAG-F: configuración operativa de pagos, fecha de corte y roles — PARCIAL (2026-10-07, sin commit; cerrada en desarrollo en §42.23)

**Estado:** configuración de `moneytransfer` **aplicada y verificada en desarrollo**; **fecha de corte** y **asignación de roles**
**detenidas a la espera de decisión de Camilo** (no se cambió ni el corte ni ningún rol).

**1. Auditoría real (Bagisto 2.4.9 + desarrollo `lpz_saas`).**
- La factura automática la crea `Webkul\Payment\Listeners\GenerateInvoice` en `checkout.order.save.after` (el mismo evento al
  confirmar una venta de VentasAsesor): si el pedido es `cashondelivery` o `moneytransfer` y su `generate_invoice` está activo, llama
  `InvoiceRepository::create($datos, invoice_status, order_status)`. Ese `create` guarda la factura con ese estado (si no hay estado,
  `paid`) y aplica `OrderRepository::updateOrderStatus($order, order_status)`.
- Valores permitidos por la configuración de Bagisto (`Admin/Config/system.php`): `invoice_status` ∈ {`pending`, `paid`};
  `order_status` ∈ {`pending`, `pending_payment`, `processing`}. Los tres campos son **por canal** y viven en `core_config`
  (canal `default`); el archivo `payment-methods.php` solo da el valor por defecto (`generate_invoice = false`).
- **No nace ninguna Transaction automática:** el listener de facturas (`Admin\Listeners\Invoice`) solo crea una si la petición trae
  la casilla `can_create_transaction` de la pantalla nativa de facturas; al confirmar una venta no viene. El listener de la tienda
  solo envía el correo si `new_invoice` = 1 (hoy 0; además marca `email_sent` en todas las facturas con `query()->update`: deuda de
  Bagisto, irrelevante mientras siga en 0).
- Valores **antes** del cambio (efectivos y en `core_config`, sin caché de configuración): `moneytransfer` = `generate_invoice 1`,
  `invoice_status paid`, `order_status processing` (desde 2025-10-01); `cashondelivery` = `1`, `pending`, `pending_payment`
  (desde 2025-10-16). Efecto real: **los 14 pedidos por transferencia** del entorno tienen factura `paid` y 0 Transactions.
- **Sin contradicción con PAG-E:** la configuración objetivo de transferencia es idéntica a la que contra entrega ya usaba (pedido 45),
  y es justo la que PAG-E necesita (factura abierta con capacidad; al confirmar, Transaction → factura `paid` → `updateOrderStatus`).

**2. Configuración aplicada en DESARROLLO** (2026-10-07 08:20:34, filas existentes de `core_config`, canal `default`):
`sales.payment_methods.moneytransfer.invoice_status` = `pending` y `…order_status` = `pending_payment` (`generate_invoice` ya
era `1`). Se limpió solo la caché de configuración y de aplicación. Valores **efectivos** leídos con `core()->getConfigData()`:
`moneytransfer` 1 / pending / pending_payment; `cashondelivery` 1 / pending / pending_payment (sin cambios). `new_invoice` sigue en
`0` (deuda: PDF/correo de factura). Producción no se tocó.

**3. Contra entrega:** no se cambió. Ya estaba en `pending` / `pending_payment`; PAG-E no depende de alterarlo. Sigue sin existir la
regla «pedido sin pagar = no despachar» (P-11).

**4. Fecha de corte — DETENIDA (sin cambio; sigue `VENTASASESOR_CARTERA_FECHA_CORTE=2026-10-06`, temporal de desarrollo).**
`FechaCorteCartera` interpreta el valor en la zona horaria de la aplicación (America/Bogota) y acepta `Y-m-d` o `Y-m-d H:i:s`; un pedido
entra si `created_at ≥ corte`. Pedidos desde el 2026-10-05:

| Pedido | Creado | Método | Factura | Transactions | Pagos ByLopez | Con corte actual (2026-10-06) |
|---|---|---|---|---|---|---|
| 41 `BYL-00000025-CO` | 2026-10-05 14:40 | transferencia | paid | 0 | — | Fuera (histórico) |
| 42 `BYL-00000042-CO` | 2026-10-05 14:55 | transferencia | paid | 0 | — | Fuera |
| 44 `BYL-00000043-CO` | 2026-10-05 21:32 | transferencia | paid | 0 | — | Fuera |
| 45 `BYL-00000045-CO` | 2026-10-06 13:20 | **contra entrega** | pending | 0 | **#1 y #2 `sin_revisar` (84.000 + 50.000)** | Dentro |
| 46 `BYL-00000046-CO` | 2026-10-06 21:11 | transferencia | **paid** | 0 | — | Dentro, «revisar: factura pagada sin evidencia» |

- §42.8 pide que el corte sea **posterior** al momento en que el flujo nuevo está vigente (2026-10-07 08:20:34). Ese corte dejaría
  **fuera el pedido 45**, que tiene dos pagos reales: dejarían de contar en su saldo y no podrían confirmarse. Por la regla de este
  bloque **no se cambió sin decisión de Camilo**.
- No existe un corte que incluya el 45 y excluya el 46 (el 46 es posterior).
- **Opción A (recomendada):** mantener `2026-10-06` como corte definitivo. El 45 (contra entrega, que ya tenía la configuración correcta)
  sigue en Cartera con sus pagos; el 46 aparece como «a revisar» (factura pagada sin evidencia, creado con la configuración antigua) y
  no recibe pagos hasta resolverlo a mano. Todo lo anterior queda histórico.
- **Opción B:** `2026-10-07 08:20:34` (o `2026-10-08`). Cumple §42.8 al pie de la letra, pero saca de Cartera al 45 y a sus pagos.
- Ninguna opción crea pagos, Transactions ni modifica facturas históricas.

**5. Roles (P-12) — DETENIDA (ningún rol modificado).**

| Rol | Tipo | Usuarios | Permisos de Ventas Asesor |
|---|---|---|---|
| #1 Administrador | all | Admin (1), Administradora (cuenta 2) (2) | Todos (permiso «all») |
| #2 ASESOR VENTAS | custom (18) | **ninguno** | **ninguno** (`sales.*` nativos, incluidos `sales.transactions` y `sales.transactions.view`, `sales.refunds.create`, `sales.invoices.create`; `catalog.products`) |
| #4 BODEGA | custom | KAREN PRUEBA (53) | ninguno |
| #52 TALLER CONFECCION · #12050 CORTADOR | custom | talleres / cortador | ninguno |
| Roles de QA (#12059…#12071) | all/custom | usuarios de prueba | — |

Además el usuario ASESOR_PRUEBA (52) apunta a un rol inexistente (#3). No hay rol de supervisor: la supervisión la hace hoy el rol «all», válido
para el primer rollout. «ASESOR VENTAS» **no es un rol inequívoco ya usado por VentasAsesor** (sin usuarios ni claves del módulo; una prueba
fija que sus 18 permisos actuales no abren el módulo) y conserva `sales.transactions`, que P-12 pide retirar a los asesores. Propuesta
exacta para cuando Camilo lo apruebe: **agregar** `ventas_asesor`, `ventas_asesor.operar_como_asesor`, `ventas_asesor.pedidos`,
`ventas_asesor.pedidos.create`, `ventas_asesor.pedidos.view_own`, `ventas_asesor.cartera`, `ventas_asesor.cartera.view_own`,
`ventas_asesor.cartera.registrar`, `ventas_asesor.cartera.abono_general`; **quitar** `sales.transactions` y `sales.transactions.view`
(P-12); **no** dar `pedidos.view_all`, `cartera.view_all`, `cartera.revisar` ni `cartera.reversar`. La clave `cartera.reversar` no se
asigna a nadie y no tiene servicio ni acción en pantalla (PAG-I).

**6. Menú.** Inicio · Mis ventas / Pedidos (con Nueva venta) · Cartera / Pagos. Cada entrada se filtra con su clave ACL: quien no tenga
cartera no ve el enlace; un asesor con `cartera.view_own` vería la bandeja solo con sus pagos y sin botones de confirmar/rechazar; el
rol «all» ve todo.

**7. Pruebas (dirigidas, `lpz_saas_testing`).** `PagFFlujoTransferenciaTest` (nuevo): 2 passed (26 aserciones). (1) Transferencia con la
configuración objetivo, factura generada por el listener **real** de Bagisto: pedido `pending_payment`, factura `pending`, 0 Transactions,
sin «revisar»; registrar 120.000 → `sin_revisar`, saldo 0, sin revisar 120.000; confirmar → 1 Transaction `moneytransfer`, factura `paid`,
pedido `processing`, saldo 0 sin cambio, verificado 120.000. (2) Contra entrega sigue igual (`pending_payment`, `pending`, 0
Transactions, saldo completo). No recorre la pantalla de Nueva venta (03G fuera de alcance). Pint sin hallazgos.

**Pendiente para cerrar PAG-F:** decisión de Camilo sobre el corte (A o B) y sobre el rol de asesor (aprobar la propuesta o indicar otro
rol); qué hacer con el pedido 46; replicar la configuración en producción con autorización explícita (D-48); `new_invoice` y el PDF.

### 42.23 PAG-F: cierre en desarrollo — CERRADA EN DESARROLLO / PENDIENTE ROLLOUT PRODUCCIÓN (2026-10-07, sin commit)

**Decisiones de Camilo (2026-10-07):** opción A de corte; rol existente `#2 ASESOR VENTAS` como rol operativo inicial de los asesores;
supervisión financiera por el rol **Administrador (`all`)** sin crear un rol Supervisor; el pedido 46 no se toca; producción no se toca
hasta que Camilo valide PAG-E a mano.

**Fecha de corte — corte definitivo inicial del rollout en desarrollo:** `VENTASASESOR_CARTERA_FECHA_CORTE=2026-10-06` (sin cambio de
valor). Razones: mantiene dentro el pedido 45 y sus pagos reales, deja históricos los anteriores y muestra el 46 como excepción de
transición sin manipular datos. Sin excepciones por id en el código.

| Pedido | Situación con el corte | Verificado (lectura, 2026-10-07) |
|---|---|---|
| 41 · 42 · 44 | **Fuera** (históricos, `anterior_a_fecha_corte`) | 0 Transactions |
| 45 `BYL-00000045-CO` (contra entrega) | **Dentro** | Saldo 50.000 · sin revisar 134.000 (pagos #1 84.000 y #2 50.000, `sin_revisar`) · 0 Transactions |
| 46 `BYL-00000046-CO` (transferencia, configuración antigua) | **Dentro + `requiere_revision`** (`factura_pagada_sin_evidencia`) | Saldo 103.500 · factura `paid` · 0 Transactions · no recibe pagos |

**Pedido 46 — tratamiento aprobado:** incidencia de transición visible para revisión posterior. No se cambia la factura, no se crea
Transaction ni Pago, no se anula ni se saca del corte y no hay excepción en el código.

**Configuración de pagos (lectura de valores efectivos):** `moneytransfer` = `generate_invoice 1` / `invoice_status pending` /
`order_status pending_payment` (aplicado el 2026-10-07 08:20:34, §42.22); `cashondelivery` = `1` / `pending` / `pending_payment` (sin
cambios; contra entrega puede despacharse sin pago confirmado, P-11). `new_invoice` = `0` (deuda técnica: PDF/correo de factura).

**Rol `#2 ASESOR VENTAS` (desarrollo, 0 usuarios; ningún usuario reasignado).** Auditoría de rutas y controladores reales de los
permisos nativos que tenía:

| Permiso nativo | Qué autoriza en Bagisto 2.4.9 | Decisión |
|---|---|---|
| `sales.transactions` | `admin.sales.transactions.index` y **`.store`**: `TransactionController::store` crea una Transaction y, si cubre la factura, la pasa a `paid` y cambia el estado del pedido | **Retirado** (P-12): saltaba la revisión; las Transactions nacen solo en `ConfirmarPagoService` |
| `sales.transactions.view` | Detalle nativo de una Transaction | **Retirado** (P-12) |
| `sales.invoices.create` | `admin.sales.invoices.store`: `InvoiceRepository::create` **sin estado → factura `paid`**; con la casilla `can_create_transaction` además crea una Transaction | **Retirado**: convertiría una venta en «pagada» por fuera de Cartera |
| `sales.refunds.create` | `admin.sales.refunds.store` y `update_totals`: crea Refunds | **Retirado**: Refund ≠ reverso de pago (PAG-I) |
| `sales.orders.cancel` | `OrderController::cancel`, solo si hay unidades sin facturar (`qty_to_cancel`); con factura automática no hay | Se mantiene (no financiero) |
| `sales`, `sales.orders`, `sales.orders.view`, `sales.invoices`, `sales.invoices.view`, `sales.shipments`(`.view`), `sales.refunds`, `sales.refunds.view` | Listados y detalles nativos; `send_duplicate_email` no envía nada con `new_invoice = 0` | Se mantienen (solo lectura) — ver riesgo abajo |
| `sales.orders.create` | Creación nativa de pedidos desde el panel (con el adaptador RT-03 de VentasAsesor) | Se mantiene |
| `sales.shipments.create` | Registrar envíos (logística) | Se mantiene |
| `catalog`, `catalog.products` | Listado de productos (consulta) | Se mantienen (consulta de catálogo) |

**Claves agregadas** (primero en la lista: tras el login Bagisto redirige al primer permiso, así el asesor entra a Ventas Asesor ›
Inicio): `ventas_asesor`, `ventas_asesor.operar_como_asesor`, `ventas_asesor.pedidos`, `ventas_asesor.pedidos.create`,
`ventas_asesor.pedidos.view_own`, `ventas_asesor.cartera`, `ventas_asesor.cartera.view_own`, `ventas_asesor.cartera.registrar`,
`ventas_asesor.cartera.abono_general`. **No** se agregaron `pedidos.view_all`, `cartera.view_all`, `cartera.revisar` ni
`cartera.reversar`. Resultado: 23 permisos (antes 18), 0 usuarios.

**Comportamiento del asesor:** entra al módulo; opera como asesor; crea ventas; ve y abre solo sus pedidos; ve en «Cartera / Pagos» solo
pagos de sus pedidos y su cartera; registra pagos y abonos generales (todo nace `sin_revisar`); **no** ve Confirmar ni Rechazar (dependen
de la ACL `revisar`, no del nombre del rol) y el Bouncer bloquea esas rutas; no usa Transactions, facturas manuales ni Refunds nativos.
Menú: Inicio · Mis ventas / Pedidos · Nueva venta · Cartera / Pagos.

**Supervisión:** el rol Administrador (`all`: Admin y la cuenta 2) ve todo, abre la bandeja y confirma o rechaza. No se creó rol Supervisor.
**Reversar:** ningún rol tiene `cartera.reversar`; no hay servicio ni acción (PAG-I).

**Riesgo residual — CERRADO el 2026-10-07 en §42.24 (se retiró toda la rama `sales` del rol):** los permisos nativos de solo lectura que conserva el rol (`sales.orders.view`,
`sales.invoices.view`, etc.) dejan ver en las pantallas **nativas** de Bagisto pedidos y facturas de otros asesores (el alcance `view_own`
solo rige en las pantallas de VentasAsesor). Si se quiere cerrar, el siguiente paso es retirar también la rama `sales` del rol (el asesor
no la necesita para Nueva venta, Mis ventas ni Cartera). **Hallazgo separado:** el usuario ASESOR_PRUEBA (52) apunta a un rol inexistente (#3);
fuera de PAG-F.

**Pruebas (dirigidas).** `PagFRolAsesorVentasTest` (nuevo): 2 passed (29 aserciones) con la copia literal de los 23 permisos: alcance propio
de pedidos y cartera, registrar y abonar sí; `view_all`, `revisar`, `reversar` no; Inicio, Mis ventas, Nueva venta y bandeja responden
(sin botones de revisión); confirmar/rechazar, Transactions nativas, factura manual y Refund → 401; el menú muestra las 4 entradas. No se
corrieron las suites de PAG-B…PAG-F ni 03G (por instrucción). Pint sin hallazgos.

**Estado:** DESARROLLO = preparado. PRODUCCIÓN = pendiente de autorización (no se replicaron `moneytransfer`, fecha de corte, roles ni
migraciones). **Siguiente acción:** prueba manual de PAG-E por Camilo (Cartera / Pagos → abrir el pago del pedido 45 → Confirmar).

### 42.24 Riesgo residual de Sales nativo CERRADO — el asesor trabaja solo desde Ventas Asesor (2026-10-07, sin commit)

**Riesgo (detectado en §42.23):** el rol `#2 ASESOR VENTAS` conservaba permisos nativos de lectura y operación de Sales; las pantallas
nativas de Bagisto **no** respetan `ventas_asesor.pedidos.view_own`, así que un asesor podía ver pedidos y facturas de otros asesores,
crear pedidos y registrar envíos desde el módulo nativo.

**Verificación previa en código (sin suposiciones):** ninguna función propia depende de una ACL `sales.*`. En
`packages/ByLopez/VentasAsesor/src` no hay ninguna comprobación de permiso (`hasPermission`, `tienePermiso`, `bouncer()`) sobre claves
`sales.*` y las vistas y el JavaScript del módulo no llaman rutas nativas `admin.sales.*`. Las únicas lecturas `sales.*` son de
**configuración** (`sales.carriers.pickup.title/description`), no de permisos. Nueva venta, Mis ventas, Cartera, registrar pago y abono se
autorizan con claves `ventas_asesor.*`; la creación del pedido usa los repositorios de Bagisto por dentro, sin pasar por rutas nativas.

**Cambio en DESARROLLO (rol #2, 0 usuarios, con guardas de entorno, nombre y tipo de rol):** se retiró **toda la rama nativa `sales`**
(12 claves): `sales`, `sales.orders`, `sales.orders.create`, `sales.orders.view`, `sales.orders.cancel`, `sales.invoices`,
`sales.invoices.view`, `sales.shipments`, `sales.shipments.view`, `sales.shipments.create`, `sales.refunds`, `sales.refunds.view`. Se
**conservan** `catalog` y `catalog.products` (consulta de catálogo). Permisos finales del rol (11):
`ventas_asesor`, `ventas_asesor.operar_como_asesor`, `ventas_asesor.pedidos`, `ventas_asesor.pedidos.create`,
`ventas_asesor.pedidos.view_own`, `ventas_asesor.cartera`, `ventas_asesor.cartera.view_own`, `ventas_asesor.cartera.registrar`,
`ventas_asesor.cartera.abono_general`, `catalog`, `catalog.products`. Sin `pedidos.view_all`, `cartera.view_all`, `cartera.revisar` ni
`cartera.reversar`. **0 usuarios asignados** (los asigna Camilo).

**Comportamiento final del asesor.** SÍ: entrar a Ventas Asesor, crear ventas (con su consulta de productos y clientes propia del módulo),
ver solo sus pedidos, registrar pagos y abonos, ver solo su cartera. NO: módulo Sales nativo (pedidos globales, facturas, envíos,
refunds, Transactions), confirmar, rechazar, reversar ni `view_all`. La logística propia se cerrará en una fase posterior.

**Administrador (`all`)** no se modificó: sigue como supervisor inicial (toda la cartera, confirmar, rechazar y pantallas nativas).

**Pruebas (dirigidas).** `PagFRolAsesorVentasTest` actualizado a la copia literal de los 11 permisos: 2 passed (40 aserciones). El
asesor responde 200 en Inicio, Mis ventas, Nueva venta y Cartera / Pagos (sin botones de revisión) y 401 en pedidos, detalle, creación de
pedido, facturas, envíos (listado y creación), refunds y Transactions nativos, y en confirmar/rechazar; el menú muestra las 4 entradas del
módulo; el Administrador sigue entrando a Sales nativo y a la bandeja con revisión. No se corrieron otras suites. Pint sin hallazgos.

**Listo para la prueba manual de PAG-E (lectura, 2026-10-07):** pedido 45 dentro de Cartera, factura `pending`, saldo 50.000, sin revisar
134.000, pagos #1 y #2 `sin_revisar`, 0 Transactions; el Administrador abre «Cartera / Pagos» y el detalle de los pagos #1 y #2 con
**Confirmar** y **Rechazar** disponibles (no se pulsaron). Pedido 46 sigue `requiere_revision` (`factura_pagada_sin_evidencia`); pedido 41
intacto. Configuración sin cambios: `moneytransfer` y contra entrega `1 / pending / pending_payment`, corte `2026-10-06`, `new_invoice` 0.

**Producción:** sigue pendiente de autorización (nada de PAG-B…PAG-F se replicó).

## 43. ASESOR-01: identidad del asesor + pantalla «Asesores» (2026-10-07, sin commit)

### 43.1 Arquitectura encontrada (auditoría previa)

- **No existía una entidad de asesor.** La identidad comercial es el **usuario de Bagisto** (`admins.id`): un usuario «es asesor» si su rol
  tiene la clave ACL `ventas_asesor.operar_como_asesor` (`AutorizacionVentasAsesor::esAsesor()`); recibe la venta automáticamente solo
  con rol `custom` + esa clave (`esAsesorParaAtribucionAutomatica()`, D-27). El administrador `all` no recibe ventas automáticamente
  (queda «sin asignar» salvo selección explícita).
- La atribución (`bylopez_va_order_attributions.advisor_id`) guarda **ese `admins.id`** (sin FK) más `advisor_name_snapshot`;
  `ResolverAtribucionService` decide la propiedad (reglas A–D); la reasignación tiene su historial en `bylopez_va_advisor_assignment_logs`.
- `view_own` (pedidos, cartera, pagos) compara `advisor_id` con el id del usuario en sesión (`puedeVerRegistroDe`,
  `puedeVerCarteraDe`, `AlcanceDePagos`).
- **Faltaba:** nombre comercial, teléfono, estado activo/inactivo y una regla de «falla cerrado» para un usuario que vende como asesor
  sin perfil. En desarrollo no había ninguna atribución con asesor (todas `unassigned`/`company`), así que no hay histórico que migrar.

### 43.2 Decisión: perfil mínimo propio, sin segunda identidad

- **Tabla `bylopez_va_asesores`** (migración `2026_10_07_000014`): `id`, `admin_id` (int unsigned, **UNIQUE**, FK `admins` RESTRICT),
  `nombre` (120), `telefono` (30, null), `activo` (bool, default true), `creado_por_admin_id` (null, sin FK), timestamps; índice `activo`.
  Modelo `Models\Asesor` (scope `activos`). Aplicada en `lpz_saas_testing` y en desarrollo `lpz_saas` (aditiva, vacía).
- **La identidad sigue siendo el usuario**: `advisor_id` no cambia de significado ni se reescriben pedidos. El perfil se asocia 1:1 al
  usuario; `admin_id` es **único e inmutable** (un usuario tiene como mucho un perfil en toda su vida y un perfil nunca cambia de usuario).
  Por eso una venta atribuida sigue apuntando siempre al mismo asesor. Nombre, correo y rol se leen de Bagisto; el perfil solo agrega
  nombre visible, teléfono y estado.
- **Nunca se borra:** se inactiva.

### 43.3 Asesor en sesión y «falla cerrado»

- **`Services\Asesores\AsesorActualService`** es la fuente única: `asesorDe($admin)` (perfil **activo** o null; un inactivo no resuelve),
  `requierePerfil($admin)` (rol `custom` + `operar_como_asesor`, por ACL), `operable($admin)` (puede vender).
- **Falla cerrado:** si el usuario requiere perfil y no tiene uno activo, `BorradorVentaService` y `ConfirmarVentaService` rechazan con
  `asesor_sin_asociacion` («Tu usuario no tiene un asesor comercial asociado. Contacta al administrador.»). Nunca se degrada a
  «sin asignar» ni a «empresa». El aviso se ve en Inicio, Nueva venta y Asesores. Inactivar un asesor bloquea nuevas ventas también
  entre el borrador y la confirmación.
- **Administrador `all`:** no requiere perfil (D-27 sin cambios).
- **No se tocó `ResolverAtribucionService`** (la atribución sigue igual: el asesor con perfil activo recibe la venta con su `admins.id`).
- **`view_own`** sigue comparando `advisor_id` con el usuario en sesión: como `admin_id` es único e inmutable en el perfil, «mis pedidos
  como usuario» y «mis pedidos como asesor» son el mismo conjunto. No se rompió ninguna regla existente.
- **Históricos:** sin backfill ni reasignación; `unassigned`/`company` no se convierten en asesores.

### 43.4 Servicios, rutas y ACL

- `ConsultaAsesoresService` (lectura por lote: métricas agrupadas por `advisor_id` — ventas de hoy con
  `ConsultaPedidosAsesorService::soloVentasDeHoy()` (nuevo método público, mismo criterio que el KPI de Mis ventas), última venta, pedidos
  abiertos, clientes atendidos, cartera con `CarteraConsultaService` (sin Refund, como la cartera del cliente) y pagos sin revisar con las
  aplicaciones activas de pagos `sin_revisar`; sin N+1), `GuardarAsesorService` (alta, edición de nombre/teléfono, activar/inactivar;
  `AsesorInvalidoException` con códigos estables) y `AsesoresController` (delgado).
- **Elegibles para asociar:** usuarios activos cuyo rol tiene `operar_como_asesor` y sin perfil (sin nombres de rol). Activar exige que el
  usuario siga activo y elegible. Esta pantalla **asocia**; los usuarios y contraseñas siguen en Bagisto.
- **Rutas:** `GET ventas-asesor/asesores` (`admin.ventas_asesor.asesores.index`; sin parámetros = listado; `?asesor=ID` = ficha; con
  `view_own` siempre la ficha propia), `POST asesores` (alta), `POST asesores/{asesor}` (edición), `POST asesores/{asesor}/estado`.
- **ACL** (árbol 30 → 34 claves): agrupadora `ventas_asesor.asesores` (sort 8, ruta string de la pantalla) y hojas `view_own`, `view_all`
  y `manage` (alta, edición y estado). **Menú:** «Asesores» (sort 4) entre Cartera / Pagos y el resto.
- **Rol `#2 ASESOR VENTAS` (desarrollo, 0 usuarios):** se le agregaron solo `ventas_asesor.asesores` y `ventas_asesor.asesores.view_own`
  (ve su ficha); queda con 13 permisos, sin `view_all` ni `manage`. Administrador `all` gestiona todo.

### 43.5 Interfaz

- **Listado (view_all):** cabecera con icono, subtítulo y «+ Crear asesor» (modal: usuario elegible, nombre visible, teléfono, activo);
  KPI informativos sobre todos los asesores (Asesores activos · Ventas de hoy · Cartera pendiente con «sin revisar» · Pagos sin revisar);
  filtros GET (buscar nombre/usuario/correo/teléfono, estado); tabla compacta (Asesor/Usuario con iniciales · Actividad = última venta ·
  Ventas hoy · Cartera con señal «sin revisar» · Pagos por revisar · Estado · Ver) y tarjetas bajo 1024 px (2 columnas en tablet, 1 en
  móvil).
- **Ficha:** cabecera (nombre, estado, usuario asociado, rol, correo, teléfono; Editar y Activar/Inactivar solo con `manage`), 4 KPI del
  asesor (Ventas hoy · Pedidos abiertos · Cartera pendiente · Pagos sin revisar), y secciones con datos reales: Resumen, Pedidos (el mismo
  listado y semántica de Mis ventas, con enlace a Mis ventas filtrado), Clientes atendidos (clientes con pedidos atribuidos; no existe
  «dueño» permanente de un cliente) y Cartera / Pagos (saldo, sin revisar, verificado y pagos recientes enlazados a la bandeja; sin
  confirmar/rechazar aquí).
- Sin perfil y con `view_own`: pantalla con el aviso, sin listado ni datos ajenos.

### 43.6 Pruebas (dirigidas)

`Asesor01IdentidadTest` (nuevo): 3 passed (35 aserciones): alta y unicidad (un usuario, un asesor), usuario sin marcador no elegible,
sin `manage` no crea, usuario inmutable al editar; asesor en sesión (activo resuelve; inactivo y sin perfil fallan cerrado con
`asesor_sin_asociacion`; el `all` no requiere perfil; aviso en Inicio); `view_own` solo su ficha (aunque pida otra), sin perfil ve el
aviso, no crea; `view_all` lista con métricas por lote y abre cualquier ficha. `AclYMenuConfiguracionTest` 11 passed (34 claves, menú con
Asesores) y `PagFRolAsesorVentasTest` 2 passed (13 permisos). Pint aplicado. **No se corrieron** otras suites (PAG, 03G, Corte) por
instrucción; para que los escenarios de venta existentes con asesor sigan siendo válidos, el helper de pruebas `f03b_admin` crea el perfil
cuando el rol `custom` lleva el marcador. Riesgo a vigilar: pruebas antiguas que creen asesores sin ese helper y luego vendan.

**Producción:** pendiente (no se replicó la migración ni los permisos). No se asignaron usuarios.
