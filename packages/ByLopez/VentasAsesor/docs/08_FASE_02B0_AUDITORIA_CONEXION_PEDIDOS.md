# 08_FASE_02B0_AUDITORIA_CONEXION_PEDIDOS.md

**Fase 02B0 — Auditoría del enganche real con pedidos.**

**Fecha:** 2026-09-20 · **Corregida:** 2026-09-20 (revisión externa, §16)
**Estado:** **COMPLETADA Y CORREGIDA.** Auditoría y documentación. **Sin listener, sin
integración, sin migraciones, sin comandos y sin backfill.**

> **§16 manda sobre las secciones anteriores** en cuatro puntos: política de rutas,
> ciclo de vida del contexto, incidencias durables y alcance de `catch (Throwable)`.

Referencias: `[A-§n]` → `01`; `[M-§n]` → `02`; `[T-§n]` → `05`; `[B-§n]` → `06B`;
`[C-§n]` → `07`; `[C1-§n]` → `07A`. Este documento se cita como `[D-§n]`.

> **Método.** Todo se verificó sobre el código actual del repositorio y con dos
> mediciones ejecutadas en `lpz_saas_testing`. No se reutilizaron números de línea de
> documentos anteriores: los de aquí se releyeron hoy. Las dos mediciones borraron sus
> datos al terminar.

---

## 1. Evento: candidatos y elección

| Candidato | Dónde se dispara | Payload | Veredicto |
|---|---|---|---|
| `checkout.order.save.before` | `Sales/src/Repositories/OrderRepository.php:52` | `[$data]`: el arreglo que va a crear el pedido. **Todavía no hay `order_id`** | **Descartado** para atribuir: no existe el pedido al que atribuir |
| **`checkout.order.save.after`** | **`Sales/src/Repositories/OrderRepository.php:86`** | **el modelo `Order` recién creado** | **Elegido** |
| `checkout.order.orderitem.save.after` | `OrderRepository.php:83` | `OrderItem` | No aplica: es por línea |
| `sales.order.update-status.after` | `OrderRepository.php:339` | `Order` | No aplica: cambio de estado posterior |
| `sales.order.cancel.after` | `OrderRepository.php:191` | `Order` | No aplica |
| `checkout.cart.*` | `Checkout/src/Cart.php` (varios) | carrito o ítem | No aplica: no hay pedido |

**Ningún flujo crea pedidos fuera del repositorio.** Se buscó creación directa
(`Order::create(`, inserciones en `orders`) en `packages/` y `app/`: sin resultados
fuera de factories y del propio repositorio.

---

## 2. Frontera transaccional, medida en ejecución

El método es `OrderRepository::createOrderIfNotThenRetry()` (línea 45):

```text
49   DB::beginTransaction();
52       Event::dispatch('checkout.order.save.before', [$data]);
         ... crea pedido, pago, direcciones, ítems, inventario ...
86       Event::dispatch('checkout.order.save.after', $order);   ← nuestro enganche
87   } catch (\Exception $e) {
89       DB::rollBack();
107          return $this->createOrderIfNotThenRetry($data, $attempt + 1, $maxAttempts);
112  } finally {
114      DB::commit();        ← se ejecuta SIEMPRE, incluso tras el rollBack
```

**Medición 1** (script `va02b0-frontera.php`, `lpz_saas_testing`): se aislaron los
listeners reales del evento, se creó un pedido mínimo con el repositorio oficial y se
observó desde dentro del listener.

| Observación | Resultado |
|---|---|
| `DB::transactionLevel()` **dentro** del listener | **1** → el evento corre **dentro de la transacción** |
| ¿El pedido es visible desde otra conexión en ese momento? | **No** → aún sin confirmar |
| Nivel al volver de `create()` | 0 |
| ¿Visible desde otra conexión al volver? | Sí → el `commit` del `finally` ya ocurrió |
| `cart_id` del pedido dentro del listener | el mismo que se envió |
| ¿Había ruta HTTP? | No, por ejecutarse en consola |

**Quién abre y cierra.** `beginTransaction()` lo ejecuta el propio repositorio (49);
`rollBack()` solo dentro del `catch` (89); `commit()` **únicamente** en el `finally`
(114). No hay transacción externa en los flujos actuales: la medición empezó en nivel 0.

**Consecuencia para el módulo:** la atribución escrita por el listener se confirma
**en el mismo `commit`** que el pedido. No hace falta reintento posterior ni cola. Y
también implica que, si el pedido se revierte, la atribución se revierte con él.

---

## 3. Qué pasa si el listener falla (medición 2)

**Medición 2** (`va02b0-excepcion.php`), con `max_retry_attempts = 3`:

| Escenario | Resultado medido |
|---|---|
| Un listener que **lanza siempre** | El evento se disparó **3 veces**, con **tres pedidos distintos** (ids 306, 307 y 308), **los tres revertidos**; **0 pedidos sobrevivieron** y la excepción llegó al llamador. **La venta se pierde.** |
| Un listener que **falla solo la primera vez** | El evento se disparó **2 veces con ids distintos** (309 revertido, 310 persistido). El pedido final se creó bien |

**Dos reglas que salen de aquí, no de la teoría:**

1. **El listener del módulo nunca puede lanzar.** Debe capturar `Throwable` —no solo
   `Exception`— porque un `TypeError` también revierte y reintenta la venta
   (mismo patrón que rompió las notificaciones en `[B-§2.3]`).
2. **El listener puede recibir pedidos que jamás existirán.** Cualquier efecto fuera de
   la base de datos (logs, métricas) se repetirá hasta tres veces con identificadores
   distintos. Lo que se escriba en base sí se revierte con el pedido.

---

## 4. Flujos reales que terminan en el evento

Nueve puntos del código llaman a `orderRepository->create()`:

| Flujo | Archivo | ¿Existe hoy? | ¿Usa `OrderRepository`? | ¿Dispara el evento? | ¿Admin autenticado? | ¿Hay `cart_id`? | ¿Origen determinable? |
|---|---|---|---|---|---|---|---|
| Panel administrativo | `Admin/…/Sales/OrderController.php:109` | Sí | Sí | Sí | **Sí** | Sí | Sí: ruta `admin.sales.orders.store` |
| Storefront (checkout) | `Shop/…/API/OnepageController.php:180` | Sí | Sí | Sí | No (ver §5.1) | Sí | Sí: ruta `shop.checkout.onepage.orders.store` |
| Stripe | `Stripe/…/StripeController.php:114` | Sí | Sí | Sí | No | Sí | Sí: ruta `stripe.payment.success` |
| PayU | `PayU/…/PayUController.php:110` | Sí | Sí | Sí | No | Sí | Sí: ruta `payu.success` |
| Razorpay | `Razorpay/…/RazorpayController.php:150` | Sí | Sí | Sí | No | Sí | Sí: ruta `razorpay.payment.success` |
| PayPal estándar | `Paypal/…/StandardController.php:57` | Sí | Sí | Sí | No | Sí | Sí |
| PayPal smart button | `Paypal/…/SmartButtonController.php:231` | Sí | Sí | Sí | No | Sí | Sí |
| PhonePe (callback del navegador) | `PhonePe/…/PhonePeController.php:146` | Sí | Sí | Sí | No | Sí | Sí: ruta `phonepe.callback` |
| **PhonePe (webhook servidor a servidor)** | `PhonePe/…/PhonePeController.php:272` | Sí | Sí | Sí | **No, y sin sesión del comprador** | Sí: lo recupera del identificador enviado a la pasarela y hace `Cart::setCart($cart)` | Sí, pero **solo** como venta de tienda |
| Reintento interno del repositorio | `OrderRepository.php:107` | Sí | — | **Sí, otra vez** | igual que el flujo original | igual | §3 |
| API propia / n8n / WhatsApp | — | **No existe hoy** | — | — | — | — | — |
| `CrearPedidoComercialService` | — | **No existe**: es de la Fase 03 | — | — | — | — | — |

Todos los flujos de tienda obtienen el carrito de la sesión con `Cart::getCart()`, salvo
el webhook de PhonePe, que lo recupera por identificador. **Todos convergen en el mismo
repositorio y en el mismo evento.**

**Orden respecto al carrito:** el pedido se crea primero y el carrito se toca después.
El panel llama `Cart::removeCart($cart)` (`OrderController.php:111`), que **borra la
fila** de `cart`; la tienda y las pasarelas llaman `Cart::deActivateCart()`, que solo
marca `is_active = 0`. Durante el evento, en ambos casos, el carrito todavía existe.

---

## 5. Qué información tiene el evento y cuál se pierde

### 5.1 Lo que llega y lo que no

| Dato | ¿Disponible en el listener? | Evidencia |
|---|---|---|
| `order_id` | **Sí**, en el payload | §1 |
| `cart_id` | **Sí**, `orders.cart_id`; `OrderResource` lo copia del carrito (`Transformers/OrderResource.php:43`) y el modelo no lo protege (`$guarded` no lo incluye). En desarrollo, **24 de 24** pedidos reales lo tienen | consulta de solo lectura |
| Cliente, canal de Bagisto, totales, direcciones, ítems | Sí, en el pedido | — |
| Storefront frente a panel | **No viene en el pedido.** Se deduce del punto de entrada (§6) | — |
| Administrador creador | **Solo** en la petición: `auth('admin')->id()` | — |
| Asesor, canal comercial, mecanismo, referencia externa | **No existen en el core**: no hay columna ni dato equivalente | `[A-§5.1, A-§5.2]` |

### 5.2 El guard `admin` **no** es un discriminador fiable

El panel y la tienda **comparten la misma sesión** de Laravel: en `[B-§4.2]` se observó
que `login_admin_<hash>` y `login_customer_<hash>` conviven en la misma sesión. Por lo
tanto, un administrador con sesión abierta que compre en la tienda produciría un pedido
de storefront con `auth('admin')->check() === true`. **Atribuir por el guard etiquetaría
esa venta como creada en el panel.**

Lo que sí distingue el punto de entrada es el **nombre de la ruta**, porque cada flujo
tiene la suya (§4). En consola o en colas no hay ruta (medido: `hay_ruta_http = false`),
y eso también es información: no es una creación del panel.

### 5.3 Lo que se pierde y lo que no

- **No se pierde** `cart_id`: queda en el pedido aunque el carrito se borre después.
- **Se pierde al terminar la petición** la identidad del administrador y el punto de
  entrada. Si no se capturan durante la petición, después son irrecuperables: el core no
  guarda ningún usuario administrativo `[A-§5.2]`, y `cart` tampoco tiene columna de
  usuario (sus columnas de persona son `customer_id`, `customer_email`,
  `customer_first_name`, `customer_last_name`).
- **Nunca ha existido** el contexto comercial propio (asesor, canal, mecanismo): no se
  «pierde», hay que aportarlo.

El dominio **no debe leer** sesión ni petición. Quien las lee es un adaptador de
infraestructura que entrega un `ContextoComercial` ya construido.

---

## 6. Transporte del contexto: comparación

### Opción A — contexto acotado a la petición

Registro `scoped` que un adaptador rellena **antes** de crear el pedido y el listener
consume.

| Aspecto | Evaluación |
|---|---|
| Storefront y pasarelas | El módulo **no controla** esos controladores del core, así que hoy nadie rellenaría el contexto. Funciona como «si hay contexto, úsalo», no como única fuente |
| Panel administrativo | Igual: el controlador es del core |
| Fase 03 (servicio propio) | **Aquí sí encaja**: el servicio rellena el contexto y llama al repositorio |
| Dos pestañas / varios pedidos por petición | Resuelto si el contexto se guarda **indexado por `cart_id`** y el listener lo busca por el `cart_id` del pedido |
| Octane y procesos persistentes | **Relevante: `laravel/octane ^2.3` está instalado.** Obliga a `scoped()` (se vacía por petición) y **prohíbe `singleton`** para esto |
| Excepciones | El adaptador debe limpiar en `finally` |
| Reejecución del evento (§3) | El contexto sigue disponible en la misma petición: los reintentos lo reutilizan |
| Comandos y colas | No hay contexto: la reconciliación debe derivarlo o dejar `unassigned` |

### Opción B — tabla durable ligada a `cart_id`

**No se justifica hoy.** La prueba que pedía el encargo —demostrar que existe información
necesaria que se pierde antes del evento— **falla**: el único dato que se pierde al
terminar la petición es el punto de entrada y el administrador, y ambos están disponibles
**durante** la misma petición en la que corre el listener (§2: el evento es síncrono y
dentro de la transacción). No hay ninguna ventana en la que el contexto exista y el
listener no pueda verlo.

Se reconsiderará solo si aparece un flujo con contexto **diferido**: por ejemplo, un
carrito preparado por un asesor que el cliente confirma horas después desde la tienda
(que es exactamente el escenario de D-16 ya decidido, pero cuya creación pasará por el
servicio de la Fase 03, no por un contexto durable).

### Opción C — registro directo por el servicio creador (Fase 03)

El servicio conocerá contexto y pedido. **Riesgo:** si el servicio registra la atribución
por su cuenta *y* el listener también, habría doble escritura. Con la idempotencia actual
no se duplicaría (la segunda llamada compararía y devolvería la misma fila), pero si los
contextos difirieran produciría un conflicto artificial.

**Regla para la Fase 03:** el servicio **no** registra la atribución; **rellena el
contexto** (Opción A) antes de llamar al repositorio y deja que el listener sea el
**único escritor**. Así hay un solo camino de escritura y la idempotencia no se usa para
tapar un diseño ambiguo.

### Recomendación

```text
02B: listener único escritor.
     1. ¿Hay contexto explícito para el cart_id del pedido?  → usarlo.
     2. Ruta administrativa exacta y verificada                → creación administrativa.
     3. Ruta exacta de la lista verificada de tienda/pasarela  → storefront (empresa).
     4. Ruta desconocida, ausente o no clasificada             → NO determinable:
                                                                 fallo_tecnico.
03:  el servicio comercial rellena el contexto; no escribe atribución.
```

> **Corregido en §16.1.** La versión inicial clasificaba por descarte («cualquier otra
> ruta o sin ruta → tienda»), lo que habría convertido en venta de empresa a cualquier
> origen nuevo. La política definitiva falla cerrado y usa la lista exacta de §16.2.

---

## 7. Reglas comerciales vigentes que aplicará el listener

| Origen | `created_by_admin_id` | propiedad | asesor | canal | mecanismo | estado |
|---|---|---|---|---|---|---|
| Storefront y pasarelas | `null` | `company` | `null` | `ecommerce` | `storefront` | `completa` |
| Panel, creador **asesor** (`custom` + `ventas_asesor.operar_como_asesor`) | el administrador | `advisor` | él mismo | pendiente de contexto; por defecto `otro` | `admin_bagisto` | `completa` |
| Panel, cualquier otro administrador, incluido el de acceso total | el administrador | `unassigned` | `null` | por defecto `otro` | `admin_bagisto` | `sin_asesor` |
| Sin contexto determinable | `null` | `unassigned` | `null` | `desconocido` | `desconocido` | `fallo_tecnico` |

Storefront es **`company` / `completa`**, no `sin_asesor`. La selección explícita de
empresa, asesor propio u otro asesor llega en la Fase 03 y no se diseña aquí.

---

## 8. Fallos y conflictos: comportamiento exigido al listener

```text
No existe atribución y el contexto se resolvió
  → registrar normalmente (RegistrarAtribucionService).

No existe atribución y el contexto no puede determinarse
  → registrar fallo_tecnico, si la base y el motor siguen disponibles.

Existe atribución equivalente
  → reintento idempotente: devolver, sin tocar nada.

Existe atribución incompatible
  → conservarla intacta y registrar la incidencia para reconciliación.
    NUNCA convertirla en fallo_tecnico. NUNCA sobrescribirla.

La base no permite ni siquiera registrar fallo_tecnico
  → conservar el pedido, escribir log técnico seguro y dejar que la
    detección de huérfanos lo encuentre.
```

**Cómo se impide que un fallo del módulo cancele la venta.** Por la medición 2, el
listener **envuelve todo su cuerpo en `try { … } catch (\Throwable)`** y nunca relanza.

> **Corregido en §16.5.** Eso es necesario pero **no suficiente**: capturar no neutraliza
> una desconexión, un interbloqueo que invalide la transacción ni un fallo de otro
> listener posterior del core. La promesa correcta es que el módulo no propaga **sus**
> errores recuperables ni sus conflictos, y que el pedido puede confirmarse **mientras la
> transacción y la conexión sigan utilizables**. Si el fallo es de base de datos, tampoco
> podrá escribirse `fallo_tecnico` ni la incidencia por esa misma conexión.

Prohibido en todos los casos: recrear el pedido, escribir trazas o payloads en la
atribución o en el historial, y suponer que `fallo_tecnico` siempre podrá guardarse.

---

## 9. Reconciliación (diseñada, no implementada)

**Detección de huérfanos** (consulta, no tabla nueva):

```sql
SELECT o.id FROM orders o
LEFT JOIN bylopez_va_order_attributions a ON a.order_id = o.id
WHERE a.id IS NULL
```

**Incidencias técnicas:** `bylopez_va_order_attributions.attribution_status = 'fallo_tecnico'`
(ya existe el scope `conIncidencia()`).

**Comando propuesto para la 02B1** (sin implementar):

| Aspecto | Decisión |
|---|---|
| Nombre | `ventas-asesor:reconciliar-atribuciones` |
| Modo por defecto | `--dry-run`: informa y **no escribe** |
| Selección | `--order=<id>` para un pedido; sin él, huérfanos y `fallo_tecnico` |
| Lote | `--limite=<n>` con tope por defecto conservador |
| Idempotencia | Se apoya en `RegistrarAtribucionService`: equivalente devuelve, incompatible conflicta |
| Conflictos | Se informan y se cuentan; **no** se resuelven solos |
| Registro | Log técnico con `order_id`, resultado y código; nunca payloads |
| Pedidos históricos sin evidencia | `unassigned` con canal y mecanismo `desconocido`. **Nunca** se infiere asesor |
| Backfill masivo | **Fuera de la 02B**: es decisión de la Fase 05 y de D-12 |

Esta fase **no ejecutó** ningún backfill ni reconciliación.

---

## 10. ¿Hace falta una tabla propia de incidencias?

| Mecanismo | Cubre | Limitación |
|---|---|---|
| Log de Laravel | Todo, incluido el fallo de base de datos | No es consultable como estado; se rota y se pierde |
| Ausencia de atribución (huérfano) | Pedidos sin atribución | No distingue «nunca se intentó» de «falló» |
| `attribution_status = fallo_tecnico` | Atribuciones **nuevas** que no pudieron resolverse | No sirve para un conflicto: implicaría **modificar** una atribución existente, que es justo lo prohibido |
| Tabla consultable de conflictos | Conflictos sobre atribuciones existentes | Tabla nueva, migración, mantenimiento |

**Decisión inicial (incorrecta, conservada como registro): no crear tabla en la 02B.** El
argumento era que el único productor real de conflictos sería el comando de
reconciliación.

> **Corregido en §16.4.** El listener también puede encontrar una atribución existente
> incompatible y, como no puede propagar, ese conflicto quedaría invisible: no es
> huérfano, no es `fallo_tecnico` y un log no es una bandeja consultable. **Se aprueba
> `bylopez_va_attribution_incidents` para la 02B1.**

**Cuándo habría que crearla:** si la reconciliación pasa a ejecutarse desatendida
(programada), porque entonces nadie leería su salida. Queda registrado como condición
explícita, no como tarea pendiente.

---

## 11. Límite de la bandeja: contradicción resuelta

El roadmap incluía «bandeja» dentro de la 02B y `00_STATUS.md` asigna listado, detalle,
asignación y backfill a la Fase 05.

**Se confirma la separación:**

```text
02B  = listener, contexto, detección de huérfanos y reconciliación por comando (backend).
Fase 05 = DataGrid, detalle, bandeja visual, asignación desde interfaz y backfill.
```

Motivo: la 02B no necesita interfaz para cumplir su objetivo, y la Fase 05 ya tiene el
aislamiento por asesor y las pruebas IDOR en su alcance. Los documentos quedaron
corregidos en esta fase.

---

## 12. Prueba concurrente pendiente de implementar

Queda registrada como obligatoria para la 02B1, porque la de la 02A2 demuestra el `catch`
pero su ganador se insertó a mano y **no tiene historial inicial**, así que no sirve como
ejemplo de atribución válida:

```text
Dos ejecuciones reales del servicio compiten por el mismo pedido.
  El ganador crea atribución e historial inicial.
  El perdedor recupera la atribución ganadora y la compara.
Resultado final: una atribución y exactamente un historial inicial.
```

---

## 13. Riesgos y límites que hereda la 02B1

| # | Riesgo | Nota |
|---|---|---|
| R-1 | Un `Throwable` del módulo **revierte y reintenta la venta** (medición 2) | El listener captura `Throwable` y nunca relanza |
| R-2 | El evento puede dispararse hasta 3 veces con ids distintos | Lo escrito en base se revierte con el pedido; los efectos fuera de base se repiten |
| R-3 | El guard `admin` no distingue panel de tienda | Discriminar por nombre de ruta (§5.2) |
| R-4 | En consola o en cola no hay ruta | **Corregido (§16.1):** origen **no determinable** → `fallo_tecnico`. El webhook de PhonePe sí tiene ruta y se clasifica por la lista de §16.2 |
| R-5 | Octane instalado | El contexto debe ser `scoped`, nunca `singleton` |
| R-6 | Si la base falla, `fallo_tecnico` tampoco podrá guardarse | Queda log y huérfano detectable (§8) |
| R-7 | El canal comercial real (WhatsApp, teléfono) no es deducible del core | Hasta la Fase 03, los pedidos del panel quedan con canal por defecto, no inventado |
| R-8 | `RESTRICT` sobre `orders` impide borrar pedidos atribuidos | Ya documentado en `[C1-§17]` |

---

## 14. Plan para la 02B1

1. Listener propio registrado desde el `ServiceProvider` del paquete, suscrito a
   `checkout.order.save.after`, **síncrono** y envuelto en `try/catch (\Throwable)`.
2. Adaptador de infraestructura que construye el `ContextoComercial` con la precedencia
   cerrada de §16.1 y la lista exacta de §16.2.
3. Registro `scoped` del contexto indexado por `cart_id`; la limpieza es de quien lo
   establece, con `try/finally` alrededor de toda la llamada a `create()` (§16.3).
4. Comando de reconciliación con `--dry-run`, `--order` y `--limite` (§9).
5. Pruebas: pedido real del panel atribuido; pedido real de tienda como `company`;
   listener que falla **no** rompe la venta; evento repetido no duplica; carrera real
   entre dos ejecuciones del servicio (§12); huérfanos detectados; reconciliación
   idempotente.
6. Migración de la tabla de incidencias (§16.4) y las quince pruebas de §16.6.
7. Sin interfaz y sin backfill: la bandeja es de la Fase 05.

---

## 15. Estado de las bases y de Git

Las dos mediciones corrieron en `lpz_saas_testing` y borraron sus datos: quedaron 0
pedidos de medición y 0 atribuciones. Sobre `lpz_saas` solo se ejecutaron consultas de
lectura.

Git al cierre: `diff --check` limpio; `packages/Webkul/` y `packages/Webkul/RMA/` en 0; el
módulo sin cambios de código, solo documentación. Detalle en la respuesta de la fase.

---

## 16. Corrección posterior a revisión externa — 2026-09-20

Una revisión externa aceptó la auditoría del evento y de la transacción, y rechazó
**cuatro decisiones del plan**. Las secciones anteriores se conservan; aquí queda lo que
cambia y manda sobre ellas.

### 16.1 Política cerrada de clasificación del origen

La regla anterior («cualquier otra ruta o sin ruta → tienda») clasificaba por descarte, y
eso convertiría en venta `company` / `completa` a cualquier origen nuevo: API, n8n,
importación, comando, job, webhook futuro, prueba u otro módulo. **Se sustituye por una
precedencia cerrada, que falla cerrado:**

```text
1. Contexto explícito indexado por cart_id
   → usarlo.

2. Ruta administrativa exacta y verificada
   → creación administrativa.

3. Ruta exacta incluida en la lista verificada de tienda o pasarela
   → storefront: company / completa.

4. Ruta desconocida, ausente o no clasificada
   → origen NO determinable: fallo_tecnico si puede persistirse.

Nunca:
  ruta desconocida  = storefront
  guard admin activo = panel administrativo
```

La comparación es por **nombre exacto de ruta**, nunca por prefijo: `admin.*` incluiría
cualquier pantalla futura del panel, y `shop.*` incluiría rutas que no crean pedidos. El
nombre además es estable aunque cambie el prefijo de la URL (`APP_ADMIN_URL`).

Cuando aparezca un origen nuevo, el adaptador **no lo adivina**: lo marca como no
determinable y la fase que lo introduzca deberá añadirlo a la lista de forma explícita.

### 16.2 Lista exacta de rutas verificadas

Los nueve puntos que crean pedidos, con el método que los contiene y su ruta, leídos hoy:

| # | Punto de creación | Método | Ruta exacta | Clasificación |
|---|---|---|---|---|
| 1 | `Admin/…/Sales/OrderController.php:109` | `store()` | `admin.sales.orders.store` | **Administrativa** |
| 2 | `Shop/…/API/OnepageController.php:180` | `storeOrder()` | `shop.checkout.onepage.orders.store` | Tienda |
| 3 | `Stripe/…/StripeController.php:114` | `success()` | `stripe.payment.success` | Tienda (pasarela) |
| 4 | `PayU/…/PayUController.php:110` | `success()` | `payu.success` | Tienda (pasarela) |
| 5 | `Razorpay/…/RazorpayController.php:150` | `handlePaymentSuccess()`, desde `paymentSuccess()` | `razorpay.payment.success` | Tienda (pasarela) |
| 6 | `Paypal/…/StandardController.php:57` | `success()` | `paypal.standard.success` | Tienda (pasarela) |
| 7 | `Paypal/…/SmartButtonController.php:231` | `captureOrder()` | `paypal.smart-button.capture-order` | Tienda (pasarela) |
| 8 | `PhonePe/…/PhonePeController.php:146` | `callback()` | `phonepe.callback` | Tienda (pasarela) |
| 9 | `PhonePe/…/PhonePeController.php:272` | `webhook()` | `phonepe.webhook` | Tienda (pasarela, servidor a servidor) |

`paypal.standard.ipn` existe pero **no crea pedidos**, así que no entra en la lista.

**Nota sobre R-4 (corregida):** el webhook de PhonePe **sí tiene ruta**, y por eso se
clasifica por la lista. Lo que no tiene ruta es la ejecución en consola o en cola, y eso
cae en el caso 4: no determinable.

### 16.3 Ciclo de vida del contexto durante los reintentos

El repositorio puede reintentar y entregar al listener **varios pedidos temporales
distintos para el mismo carrito** (medido en §3: tres ids en tres intentos). De ahí:

- el listener **consulta** el contexto por `cart_id` y **no lo consume ni lo borra**;
- el contexto **no se vincula** al primer `order_id` recibido: la clave es `cart_id`;
- un intento posterior vuelve a leer el mismo contexto;
- la escritura de la atribución sí se revierte con el pedido, por estar en la misma
  transacción.

**Propietario de la limpieza: quien establece el contexto**, no el listener. Debe
envolver **toda** la llamada a `OrderRepository::create()`:

```php
$contextos->poner($cartId, $contexto);

try {
    $pedido = $orderRepository->create($datos);   // incluye todos sus reintentos
} finally {
    $contextos->olvidar($cartId);                 // exito o excepcion, siempre
}
```

| Situación | Comportamiento exigido |
|---|---|
| Fin correcto | El `finally` limpia la entrada de ese `cart_id` |
| `create()` lanza | El `finally` limpia igual; el contexto no sobrevive a la petición |
| Varios carritos en una petición | Cada uno con su clave; limpiar solo el propio |
| Octane y procesos persistentes | Registro `scoped`, que se vacía al terminar la petición; **nunca `singleton`** |
| Flujos actuales del core | **No hay contexto explícito que limpiar**: todos resuelven por la lista de rutas (§16.2) |

### 16.4 Incidencias durables: se aprueba la tabla

La conclusión anterior («no hace falta tabla porque el único productor de conflictos es
el comando») **era falsa**. El listener puede encontrar una atribución existente
incompatible; como no puede propagar la excepción, el conflicto quedaría invisible:

| Mecanismo | ¿Detecta ese conflicto? |
|---|---|
| Huérfanos por `LEFT JOIN` | **No**: el pedido sí tiene atribución |
| `attribution_status = fallo_tecnico` | **No**: marcarlo exigiría modificar la atribución original, que está prohibido |
| Log de Laravel | **No** como bandeja: no es consultable, no se marca resuelto y se rota |
| Historial de asignación | **No debe usarse**: es auditoría inmutable de decisiones, no una cola de trabajo mutable. Mezclarlo rompería su garantía |

**Decisión: se aprueba `bylopez_va_attribution_incidents` para la Fase 02B1** (aquí solo
se diseña; no se crea migración).

| Campo | Notas |
|---|---|
| `order_id` | Siempre |
| `order_attribution_id` | Nullable: puede no haber atribución |
| `codigo` | Código estable de incidencia, p. ej. `conflicto_contexto`, `historial_incoherente`, `origen_no_determinable` |
| `campos_en_conflicto` | **Solo nombres** de campos, nunca valores |
| `estado` | `open` \| `resolved` |
| `intentos` | Contador |
| `detectada_en`, `ultima_deteccion` | Fechas |
| `resuelta_en`, `resuelta_por_admin_id`, `resolucion_codigo` | Cuando exista |
| marcas de tiempo | — |

**Idempotencia:** clave única por `(order_id, codigo)`; repetir el mismo conflicto
**actualiza** `ultima_deteccion` e `intentos`, no crea otra fila.

**Nunca se guardan:** contextos completos, payloads, notas libres, datos del cliente,
tokens, trazas ni los valores comerciales en conflicto.

**Separación de responsabilidades:** el historial sigue siendo inmutable y describe
**decisiones**; la tabla de incidencias es **operacional y mutable** y describe **trabajo
pendiente**. Resolver una incidencia **no** cambia la atribución por sí misma: si hay que
cambiarla, se hace con `AsignacionAsesorService`, que exige permiso, motivo e historial.

### 16.5 Alcance real de capturar `Throwable`

La afirmación «la venta siempre se conserva» era excesiva. La correcta:

```text
El módulo no debe propagar sus propios errores recuperables ni sus conflictos.

Mientras la transacción y la conexión sigan utilizables, el pedido debe poder
confirmarse.

Una falla fatal de la conexión o de la transacción compartida NO se neutraliza
con catch(Throwable).
```

Casos en los que capturar no basta: desconexión de la base, interbloqueo que invalide la
transacción, pérdida de conexión durante el listener, transacción exterior ya inutilizable
y fallos posteriores de otros listeners del core, que corren después del nuestro.

**Taxonomía mínima que debe distinguir la 02B1:**

| Tipo | Tratamiento |
|---|---|
| `AtribucionEnConflictoException` | Registrar incidencia (§16.4); no tocar la atribución |
| `HistorialIncoherenteException` | Registrar incidencia; no tocar el historial |
| Errores de dominio o validación | Registrar incidencia con código; no propagar |
| Errores inesperados | Log técnico seguro e incidencia; no propagar |
| **Errores de base o de conexión** | **No intentar escribir `fallo_tecnico` ni la incidencia por esa misma conexión.** Log técnico y, si la conexión está caída, el pedido quedará detectable como huérfano |

### 16.6 Pruebas añadidas al plan de la 02B1

1. Ruta administrativa exacta → creación administrativa.
2. Ruta de tienda exacta → `company` / `completa`.
3. Cada callback de pasarela de §16.2, verificado uno por uno.
4. Ruta desconocida **no** se clasifica como tienda.
5. Ausencia de ruta **no** se clasifica como tienda.
6. Guard administrativo activo dentro de una ruta de tienda sigue siendo tienda.
7. El contexto explícito tiene prioridad sobre la ruta.
8. El contexto sobrevive al primer intento fallido del repositorio.
9. Un reintento con otro `order_id` recupera el mismo contexto por `cart_id`.
10. La limpieza ocurre **después** de todos los reintentos, y también si `create()` lanza.
11. Un conflicto del listener crea o actualiza una incidencia durable.
12. Repetir el mismo conflicto **no** duplica incidencias.
13. El comando de reconciliación detecta el conflicto.
14. Resolver la incidencia **no** sobrescribe la atribución.
15. Dos ejecuciones reales del servicio terminan con **una** atribución y **un** historial inicial.

### 16.7 Plan de la 02B1, corregido

1. Listener propio, síncrono, que captura `Throwable` con la taxonomía de §16.5 y nunca
   relanza.
2. Adaptador de origen con la **precedencia cerrada** de §16.1 y la lista de §16.2.
3. Registro `scoped` del contexto indexado por `cart_id`, con limpieza en `finally` a
   cargo de quien lo establece (§16.3).
4. Migración de `bylopez_va_attribution_incidents` con clave única `(order_id, codigo)`
   (§16.4).
5. Comando de reconciliación con `--dry-run`, `--order` y `--limite`, que además lee y
   marca incidencias.
6. Las quince pruebas de §16.6.
7. Sigue sin interfaz y sin backfill: la bandeja es de la Fase 05.

---

## 17. Resultado de la implementación — Fase 02B1A (2026-09-20)

Esta sección se añade **sin borrar nada** de lo anterior: cierra el ciclo entre lo que
esta auditoría recomendó y lo que finalmente se construyó. El detalle completo está en
`09_FASE_02B1A_LISTENER_CONTEXTO_INCIDENCIAS.md`.

| Punto del plan §16.7 | Estado | Dónde quedó |
|---|---|---|
| 1. Listener síncrono que captura `Throwable` y nunca relanza | **Implementado** | `src/Listeners/AtribuirPedidoCreado.php` |
| 2. Adaptador de origen con precedencia cerrada y lista exacta | **Implementado** | `src/Support/ClasificadorOrigenPedido.php` + `src/Support/RutasDeCreacionDePedido.php` |
| 3. Registro `scoped` por `cart_id`, limpieza en `finally` a cargo de quien lo establece | **Implementado** | `src/Support/ContextoComercialDePeticion.php` (`durante()`) |
| 4. Migración `bylopez_va_attribution_incidents` con única `(order_id, codigo)` | **Implementado** | `2026_09_20_000004_…php` + `src/Services/IncidenciasAtribucionService.php` |
| 5. Comando de reconciliación con `--dry-run`, `--order` y `--limite` | **Pendiente** | Fase 02B1B, no iniciada |
| 6. Las quince pruebas de §16.6 | **Superado**: 35 pruebas | Doc. 09 §10 |
| 7. Sin interfaz ni backfill | **Cumplido** | — |

### 17.1 Dos precisiones que la implementación añadió

1. **Ruta del panel sin administrador autenticado.** §16.1 decía "origen inválido o fallo
   técnico; nunca storefront". Quedó con código propio, `listener.admin_sin_autenticar`,
   distinto de `listener.origen_no_determinado`: son dos anomalías diferentes y conviene
   poder contarlas por separado.
2. **Pedido sin `cart_id`.** §16.3 no cerraba el caso. La decisión, justificada en el Doc.
   09 §4: en ruta de tienda produce `listener.cart_id_ausente`; en ruta administrativa
   **sí** se atribuye, porque allí la evidencia es el administrador autenticado y no la
   correlación con el carrito. Se pierde la trazabilidad al carrito y queda registrado
   como limitación conocida (Doc. 09 §13, R-5).

### 17.2 Lo que esta auditoría acertó y quedó confirmado en ejecución

- El contexto debía indexarse por `cart_id` y **no** por `order_id` (§16.3): confirmado
  por la prueba 31, donde el primer intento se revierte entero y el segundo crea otro
  pedido con el mismo carrito.
- El listener **no** debía consumir el contexto (§16.3): la mutación 2 del Doc. 09 §11
  hace fallar seis pruebas en cuanto se consume en la primera lectura.
- `catch (Throwable)` **no** salva una transacción ya invalidada (§16.5): por eso la rama
  de error de base de datos se limita al log y no intenta escribir nada más por esa misma
  conexión (prueba 29).
