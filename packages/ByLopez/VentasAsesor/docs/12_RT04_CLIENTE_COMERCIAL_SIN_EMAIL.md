# 12 — RT-04A: cliente comercial sin email (AUDITORÍA)

**Estado:** RT-04A **CERRADO** (auditado 2026-09-25; cerrado 2026-09-26). Sin cambios de comportamiento, sin migraciones, sin
cambios en Webkul. Solo esta documentación y pruebas de caracterización
(`tests/Feature/Rt04aCaracterizacionClienteSinEmailTest.php`, 14 casos, `lpz_saas_testing`).

> **Posterior a la auditoría:** las decisiones de negocio tomadas a partir de estos hallazgos están
> en **§14** (D-RT04-01…10), y el diseño técnico en **`13_RT04B_DISENO_CLIENTE_COMERCIAL.md`**
> (RT-04B0). Las secciones 1–13 se conservan como evidencia original de la auditoría.

## 1. Problema

Las ventas por WhatsApp Business las atiende un asesor. El cliente da nombre, teléfono,
dirección y ciudad; muchas veces **no** da correo. No se aceptan correos inventados.

## 2. Premisas corregidas

| Premisa | Realidad | Evidencia |
|---|---|---|
| Existen `customer_addresses`, `cart_addresses`, `order_addresses` | Una sola tabla **`addresses`** con `address_type` (`customer`, `cart_billing`, `cart_shipping`, `order_billing`, `order_shipping`) | `Core/.../2020_04_16_185147_add_table_addresses.php` |
| El email de customers es obligatorio en BD | `customers.email` es **NULL-able** desde la creación de la tabla | `create_customers_table.php:22`; `information_schema` |
| El teléfono no tiene restricciones | `customers.phone` es **UNIQUE global** (no por canal) | `create_customers_table.php:23`; índice `customers_phone_unique` |

## 3. Email

**Base de datos**

| Tabla.columna | Tipo | NULL | Índice |
|---|---|---|---|
| `customers.email` | varchar(255) | sí | UNIQUE (`email`, `channel_id`) — `2024_06_04_130600_make_email_unique_per_channel` |
| `addresses.email` | varchar(255) | sí | — |
| `cart.customer_email` | varchar(255) | sí | — |
| `orders.customer_email` | varchar(255) | sí | — |
| `customer_password_resets.email` | varchar | no | index |

MySQL admite varios `NULL` en un índice único, **pero no varios `""`** (probado).

**Modelo y repositorio.** `Customer` (Authenticatable, Notifiable) tiene `email` en `$fillable`, sin
casts ni mutators de email. `CustomerRepository::create()` acepta `email = null` (probado).
`syncNewRegisteredCustomerInformation()` enlaza pedidos por `customer_email` al registrarse.

**Dónde se exige email** (capas responsables):

| Flujo | Regla | Archivo |
|---|---|---|
| Crear cliente en admin | `'email' => 'required|unique…'` | `Admin/.../Customers/CustomerController::store` (l. 86) y `update` (l. 146) |
| Registro storefront | `email|required|unique` | `Shop/.../Requests/Customer/RegistrationRequest.php:30` |
| Login storefront | credenciales `email` + `password` | `Shop/.../Customer/SessionController.php:36` |
| Reset de contraseña | broker por `email` | `Shop/.../Customer/ForgotPasswordController.php:36` |
| **Dirección de carrito (admin y tienda, incluido invitado)** | `"{tipo}.email" => required` | `Admin/.../Requests/CartAddressRequest.php:53`, `Shop/.../Requests/CartAddressRequest.php:52` |
| Dirección guardada de cliente | `email required` | `Admin/.../Requests/AddressRequest.php:40`, `Shop/.../Customer/AddressRequest.php:40` |

**Copia al carrito y al pedido.** `Cart::createCart()` copia `customer->email` (l. 149).
`Cart::saveAddresses()` → `setCustomerPersonnelDetails()` (l. 556):
`customer_email = customer?->email ?? billing_address->email`. Con email **NULL** cae al email de la
dirección de facturación (obligatorio); con **`""`** NO cae (el `??` solo cubre null) y el carrito
queda con `""` (probado). `OrderResource` copia `customer_email` del carrito al pedido.

## 4. Notificaciones

Todas las de cliente usan `new Address($…->email)` en `envelope()`. `Address(string)` con `null`
lanza **`TypeError`**, que **no** atrapan los `catch (\Exception)` de Bagisto
(`Shop\Listeners\Base::prepareMail`, `CustomerController::store`). Con `""`, Symfony lanza una
excepción (subclase de `Exception`) que sí se atrapa y solo se registra.

| Correo | Destinatario | NULL | `""` |
|---|---|---|---|
| Pedido creado / cancelado / comentado / facturado / enviado / reembolsado (`Shop\Mail\Order\*`) | `order.customer_email` | **TypeError sin capturar** (probado en creado) | atrapado, se pierde en silencio (probado) |
| Credenciales de cliente nuevo (`Admin\Mail\Customer\NewCustomerNotification`) | `customer.email` | **TypeError** (probado) | atrapado |
| Registro, verificación, cambio de contraseña, nota, recordatorio de factura (`Shop\Mail\Customer\*`) | `customer.email` | TypeError (misma construcción, no probado uno a uno) | atrapado |
| Reset de contraseña (`ResetPasswordNotification`) | `routeNotificationForMail` = email | sin email no hay a quién enviar; el flujo exige email | — |

Con `QUEUE_CONNECTION=sync` (desarrollo) el `TypeError` salta **dentro del listener de
`checkout.order.save.after`**; según la 02B0 eso revierte y reintenta el pedido. Hoy no ocurre en el
flujo normal porque el email de la dirección es obligatorio y el pedido nunca queda con email NULL.

**Hallazgo de entorno:** en `lpz_saas_testing` no hay remitente configurado; entonces **todo**
correo falla con `TypeError` en el `from`, y el alta de cliente del admin responde 500 **después**
de guardar al cliente (probado). Conviene configurar un remitente de pruebas.

## 5. Teléfono

- `customers.phone` (nullable, **UNIQUE global**) y `addresses.phone` (nullable en BD, pero
  **obligatorio** en todas las validaciones de dirección).
- Un cliente puede existir sin dirección (3 de 15 en desarrollo) y tener varios teléfonos a
  través de varias direcciones (1 caso en desarrollo con teléfono de dirección distinto del del
  cliente).
- Validación `PhoneNumber`: `^\+?\d+$` — rechaza espacios y guiones (probado). No hay
  normalización, ni país, ni formato E.164.
- Datos de desarrollo (agregados): 15 clientes, 5 sin teléfono, todos los teléfonos solo dígitos,
  ninguno con prefijo 57 ni `+`.

## 6. Búsqueda de clientes

- `GET admin/customers/search` (`CustomerController::search`, l. 281): **email** y **nombre
  completo**. **No busca por teléfono.** La usan el buscador del encabezado y el cajón «Buscar
  cliente» de «Crear pedido».
- `CustomerDataGrid`: nombre y email buscables; `phone` solo **filtrable**, no buscable.

## 7. Grupo al crear cliente

- Admin: el select de grupo **no es obligatorio** y el controlador no lo valida; si no se envía,
  `customer_group_id = NULL` (probado) y el cliente se precia como **invitado**
  (`$customer->group ?? invitado`, también bajo RT-03).
- Storefront: grupo por configuración `customer.settings.create_new_account_options.default_group`
  (en desarrollo: `general`).
- Desarrollo: 1 cliente real está en el grupo 1 (invitado).
- Cambio posterior de grupo: RT-03 lo aplica en el siguiente recálculo (probado en RT-03, T10).

## 8. Customer → Cart → Order

| Dato | Cart | Order | Sincronización posterior |
|---|---|---|---|
| `customer_id` | referencia (al crear) | copia del carrito | no |
| `customer_email/first_name/last_name` | snapshot al crear y al guardar direcciones | snapshot del carrito | nunca, salvo enlace de invitado por email al registrarse |
| dirección y teléfono | `addresses` tipo `cart_*` (copia; `parent_address_id` si viene de una guardada) | `addresses` tipo `order_*` (copia) | nunca |
| grupo | no se guarda; se resuelve al preciar (RT-03) | precios ya calculados | — |

Si la Fase 03 permite cambiar el cliente de un borrador habrá que re-escribir `customer_id`,
`customer_email/first/last`, las direcciones del carrito (o exigir nuevas) y recalcular precios
(RT-03 lo hace con el contexto).

## 9. Alternativas

- **A. `customers.email` NULL-able.** Ya lo es en BD; lo que impide un cliente sin email es la
  validación del admin y, sobre todo, el `email` obligatorio en las direcciones del carrito.
  Relajar eso exige cambiar reglas del core (Webkul) o reemplazar rutas/Requests, y deja los
  correos con `TypeError` para `NULL`. Riesgo alto de actualización.
- **B. `ContactoComercial` propio** (teléfono original y normalizado, email opcional,
  `customer_id` nullable). Evita tocar Bagisto pero duplica identidad y exige sincronizar; y un
  pedido de Bagisto sigue necesitando email en la dirección.
- **C (propuesta).** Mantener `Customer` de Bagisto como **cliente comercial** (sin login real) y
  crear la venta desde la **capa comercial de VentasAsesor** (Fase 03), no desde los formularios del
  core:
  - alta del cliente por un servicio propio que usa `CustomerRepository::create()` con
    `email = NULL` (la BD lo admite; varios NULL no chocan), grupo **explícito obligatorio** y
    teléfono validado;
  - direcciones y pedido creados por el servicio comercial, que decide qué email usar para la
    dirección (el del cliente si existe; si no, NULL), sin pasar por `CartAddressRequest`;
  - un listener propio que **omita** las notificaciones de cliente cuando el email sea NULL/vacío
    (hoy no existe extension point limpio: los listeners del core se registran por eventos y hay
    que desactivarlos/reemplazarlos o garantizar que el pedido nunca llegue con NULL);
  - identidad comercial de búsqueda: teléfono normalizado propio (tabla o columna propia de
    VentasAsesor), sin tocar `customers.phone`.

  > **Superado por Doc. 13 §15 (RT-04B0).** Sí existe un extension point limpio: los listeners
  > del core se instancian con `container->make($clase)` (`Illuminate/Events/Dispatcher.php:536`),
  > así que un binding de subclases con guard de destinatario los sustituye sin modificar Webkul
  > ni desactivar correos válidos. El teléfono se diseña en una tabla propia (Doc. 13 §9).

  **Punto a decidir antes de RT-04B:** cómo evitar el `TypeError` de las notificaciones de
  pedido sin modificar Webkul (opciones: quitar los listeners de correo del core y registrar
  versiones que comprueben el email; o que la capa comercial nunca deje `customer_email` NULL
  — pero sin inventar correos, eso implica `""`, que se atrapa pero es una cadena falsa y rompe
  la unicidad si llega a `customers`).

## 10. Teléfono como identidad comercial

> **SUPERADO POR D-RT04-PHONE-01 (Doc. 13 §36.1).** El negocio decidió después que un teléfono
> normalizado pertenece a **un solo** Customer; el índice propio es UNIQUE. Se conserva el texto
> original como evidencia del razonamiento de RT-04A.

`telefono_normalizado` debería ser **INDEXADO, no UNIQUE**: hay clientes que comparten número
(familia, empresa), números reciclados, fijo + celular, WhatsApp distinto del de contacto y
cambios de número. Un UNIQUE bloquearía ventas legítimas; la deduplicación debe ser asistida
(sugerir coincidencias) y no una restricción. Además `customers.phone` ya es UNIQUE global en
Bagisto: **no conviene usarlo como teléfono comercial principal** (un segundo cliente con el mismo
número no se puede crear).

## 11. Auditoría UX (línea base para la Fase 03)

**Cliente nuevo** («Soy Aurora Ejemplo, quiero 6 camisetas de hombre»):
1. Ventas → Pedidos → «Crear pedido» → cajón «Buscar cliente» (solo email/nombre).
2. Sin resultado → «Crear cliente» (modal): nombre, apellido, **email obligatorio**, género
   obligatorio, canal, teléfono opcional sin espacios, grupo opcional (si se omite: invitado).
3. `POST customers/create` → `POST sales/cart/create` → redirección a `orders/create/{cart}`.
4. Buscar producto → por cada configurable, modal de opciones (color/talla) → cantidad → agregar
   (repetir por variante).
5. Dirección: nombre, apellido, **email obligatorio**, dirección, país, departamento, ciudad,
   código postal, teléfono (nombre y teléfono se repiten).
6. Método de envío → método de pago → «Crear pedido».
Fricción: email obligatorio dos veces, datos repetidos, búsqueda sin teléfono, grupo opcional con
riesgo de precio invitado, una variante por vez, ~6 cambios de pantalla/modal y 20+ campos.

**Cliente existente:** mismo flujo sin el alta; buscar exige email o nombre exactos (no teléfono);
si tiene dirección guardada se selecciona, si no se vuelve a escribir todo.

## 12. Objetivos futuros (no implementados)

- **Nueva venta** en una pantalla: buscar por teléfono/nombre → cargar o crear cliente en modal →
  productos/variantes/cantidades con disponibilidad y precio → envío → total → borrador → confirmar.
- **Captura por voz/IA**: la IA solo produce intención estructurada (cliente, teléfono, producto,
  color, talla, cantidad); Laravel resuelve cliente, variante, SKU, stock, grupo, precio (RT-03),
  descuentos y pedido. n8n/IA nunca consultan tablas ni crean pedidos directamente.

## 13. Decisiones pendientes

1. ¿Cliente comercial = `Customer` de Bagisto con email NULL (C) o entidad propia (B)?
2. Estrategia para las notificaciones sin email (reemplazar listeners vs. garantizar email).
3. ¿El grupo será obligatorio al crear cliente comercial? ¿Cuál por defecto para WhatsApp?
4. Teléfono comercial: tabla/columna propia, indexada, no única; normalización para Colombia
   (+57) y tratamiento de internacionales y fijos.
5. ¿Qué email lleva la dirección del pedido cuando el cliente no tiene email?
6. Configurar remitente de correo en el entorno de pruebas.

> **Estado de estas preguntas tras RT-04B0:** 1 → D-RT04-01 (C). 2 → D-RT04-06 + Doc. 13 §15.3
> (binding de listeners). 3 → D-RT04-04 (obligatorio; sin valor por defecto, pendiente P-02/P-03).
> 4 → D-RT04-07/08/09 + Doc. 13 §9–§10. 5 → Doc. 13 §14 (el del cliente o NULL). 6 → sigue
> pendiente (Doc. 13 R9).

---

## 14. DECISIONES POSTERIORES A RT-04A

Tomadas por el negocio el 2026-09-25, **después** de la auditoría y a partir de sus hallazgos. Son
decisiones del proyecto; el diseño técnico que las implementa está en el Doc. 13. Ninguna está
implementada todavía.

### D-RT04-01 · El `Customer` de Bagisto es el cliente comercial

- **Problema:** hacía falta decidir quién representa al cliente de WhatsApp: una entidad propia o
  el `Customer` del core.
- **Decisión:** el cliente comercial es el `Customer` de Bagisto (alternativa C, §9). No se crea
  `ContactoComercial`. `customers.id` es la identidad técnica.
- **Motivo:** conserva grupo, precios (RT-03), historial, direcciones y atribuciones en una sola
  identidad. Evita `Customer` + `ContactoComercial` + una sincronización permanente entre ambos.
- **Impacto:** los datos auxiliares del módulo (índice de teléfonos, procedencia) cuelgan de
  `customers.id` y no repiten nombre, email ni grupo.
- **Riesgos:** depender de las reglas del core para `customers` (UNIQUE de `phone`, email
  exigido por el panel). Mitigados en el Doc. 13 §9.4 y §8.
- **Fase:** RT-04B1.

### D-RT04-02 · Email NULL permitido en el flujo comercial

- **Problema:** muchos clientes de WhatsApp no tienen email, y el panel lo exige.
- **Decisión:** un cliente creado por VentasAsesor puede no tener email, representado **solo**
  como `NULL`. Prohibidos `""`, `telefono@bylopez.com`, `uuid@…` y cualquier email sintético.
- **Motivo:** la BD ya lo admite y varios NULL no chocan (§3). `""` sí choca y pierde correos en
  silencio (§3, §4); los sintéticos contaminan la base y mezclan clientes.
- **Impacto:** el alta comercial no pasa por los Form Requests del panel. Los carritos y
  pedidos de estos clientes llevan `customer_email` NULL.
- **Riesgos:** notificaciones (→ D-RT04-06); `syncNewRegisteredCustomerInformation` con NULL
  (Doc. 13 N10); el panel de Bagisto exige email para editarlos.
- **Fase:** RT-04B1 (alta) y Fase 03 (pedido).

### D-RT04-03 · La tienda permanece separada

- **Problema:** permitir clientes sin email podía interpretarse como relajar todo Bagisto.
- **Decisión:** la tienda sigue exigiendo email para registro, login y reset. Solo el flujo
  comercial de VentasAsesor lo hace opcional. El cliente comercial no necesita cuenta ecommerce.
- **Motivo:** el email es el identificador de login y reset (§3). Relajarlo en la tienda
  exigiría cambiar el core.
- **Impacto:** ningún Request ni ruta de la tienda cambia. La activación futura de la cuenta
  se diseña en el Doc. 13 §24.
- **Riesgos:** ninguno para la tienda. El cliente comercial sin email no puede usar la tienda
  hasta que tenga email y cuenta.
- **Fase:** permanente; la activación de la cuenta, en una fase futura.

### D-RT04-04 · Grupo obligatorio en el alta comercial

- **Problema:** el alta del panel deja `customer_group_id` NULL y el cliente se precia como
  invitado (§7).
- **Decisión:** un `Customer` creado por VentasAsesor nunca queda sin grupo. El grupo debe ser
  explícito y válido; si falta, el alta se rechaza. El invitado no es fallback. No hay grupo
  por defecto decidido, y no se hardcodean nombres de grupo.
- **Motivo:** proteger la regla de RT-03: cliente comercial → grupo actual → precio.
- **Impacto:** `ValidadorGrupoComercial` en el alta y en la resolución (Doc. 13 §13).
- **Riesgos:** un grupo puede quedar NULL después (FK `SET NULL`); lo detecta la resolución.
  Falta decidir si el invitado se prohíbe siempre (P-02) y el grupo por defecto (P-03).
- **Fase:** RT-04B1.

### D-RT04-05 · Dirección comercial con email NULL

- **Problema:** `CartAddressRequest` exige email en las direcciones (§3).
- **Decisión:** la dirección del flujo comercial puede llevar `email = NULL`. No se inventa email
  para satisfacer el Request del core y no se modifica `CartAddressRequest`.
- **Motivo:** la exigencia vive solo en el Form Request. `Cart::saveAddresses()` y
  `OrderRepository` no validan email (Doc. 13 N12).
- **Impacto:** `DireccionComercial` + `ValidadorDireccionComercial` propios. El carrito se escribe
  con `Cart::saveAddresses()` (Fase 03).
- **Riesgos:** mantener las reglas propias alineadas con las del core en cada upgrade.
- **Fase:** RT-04B1 (objeto y validador) y Fase 03 (uso en el carrito).

### D-RT04-06 · Sin notificación por email cuando no hay destinatario

- **Problema:** un pedido o cliente con email NULL provoca `TypeError` en las notificaciones y
  puede revertir el pedido (§4).
- **Decisión:** con email válido, las notificaciones siguen igual. Sin email: no se intenta el
  correo, no hay `TypeError`, no se aborta el pedido y no se usa `""`.
- **Motivo:** no perder ventas ni esconder el problema.
- **Impacto:** binding del contenedor con subclases de los listeners de correo del Shop (Doc. 13
  §15.3). No se reemplazan registros de eventos ni se desactivan correos válidos.
- **Riesgos:** upgrades del core (pruebas de contrato); los correos de RMA y desistimiento
  enviados desde controladores no quedan cubiertos (Doc. 13 N7, Fase 07).
- **Fase:** RT-04B1.

### D-RT04-07 · El teléfono es la llave principal de búsqueda comercial

- **Problema:** el panel busca por email y nombre, no por teléfono (§6).
- **Decisión:** se busca primero por teléfono, luego por nombre y luego por email si existe.
  `customers.phone` no se convierte en llave primaria; `Customer.id` sigue siendo la identidad.
- **Motivo:** el teléfono es el dato que siempre da el cliente de WhatsApp.
- **Impacto:** `BuscarClienteComercialService`, único para todos los canales (Doc. 13 §11).
- **Riesgos:** los teléfonos compartidos generan ambigüedad; se devuelve y no se resuelve
  automáticamente.
- **Fase:** RT-04B1.

### D-RT04-08 · Normalización propia del teléfono

- **Problema:** `300 555 0101`, `+57 300 555 0101` y `573005550101` (ficticios) son el mismo número para el negocio, pero
  distintos para la base (§5).
- **Decisión:** se guardan el teléfono original y el normalizado. El lugar del normalizado lo
  decide el diseño.
- **Motivo:** reconocer representaciones equivalentes sin perder lo que se escribió.
- **Impacto:** tabla propia `bylopez_va_customer_phones` y normalizador a E.164 detrás de una
  interfaz (Doc. 13 §9–§10).
- **Riesgos:** reglas de numeración de otros países → librería opcional (P-12).
- **Fase:** RT-04B1.

### D-RT04-09 · El teléfono normalizado no es UNIQUE por defecto

> **SUPERADA el 2026-09-26 por D-RT04-PHONE-01** (Doc. 13 §36.1): un teléfono normalizado pertenece
> a un único Customer, que puede tener un principal y varios secundarios. Se conserva el registro
> original.

- **Problema:** un UNIQUE bloquearía familias, empresas, números reciclados y teléfonos de
  contacto distintos del WhatsApp (§10).
- **Decisión:** índice no único + detección asistida de duplicados; nunca un bloqueo automático.
- **Motivo:** son casos legítimos de venta.
- **Impacto:** UNIQUE solo por par (cliente, teléfono); confirmación con motivo al crear un
  cliente con un teléfono ya indexado. `customers.phone` conserva su UNIQUE del core, con la
  política U1/U2 (Doc. 13 §9.4, P-01).
- **Riesgos:** más clientes duplicados si el asesor confirma mal; queda trazado.
- **Fase:** RT-04B1.

### D-RT04-10 · Cero cambios en Webkul

- **Problema:** varias soluciones obvias pasaban por editar el core.
- **Decisión:** 0 modificaciones en `packages/Webkul/`. Solo paquete propio, providers, bindings,
  listeners, servicios, eventos, Requests y rutas propias. Si no hay extension point, se
  documenta el bloqueo.
- **Motivo:** mantener la capacidad de actualizar Bagisto.
- **Impacto:** todo el diseño del Doc. 13 cumple la regla. El único punto sin extension point
  limpio son los correos de RMA y desistimiento, que se envían desde controladores; queda
  documentado.
- **Riesgos:** las subclases de listeners del core deben revisarse en cada upgrade.
- **Fase:** permanente.

---

## 15. Complemento de evidencia (RT-04B1a, 2026-09-26)

Hallazgos nuevos que **complementan** la auditoría y que se probaron en `lpz_saas_testing`. El
detalle está en el Doc. 13 §36:

- **Teléfono:** `customers.phone` no lo lee ningún proceso operativo (carrito, pedido, factura,
  envío, pasarelas, correos), pero es el teléfono **visible** del Customer (ficha, perfil de la
  tienda, que lo exige, DataGrid, exportación, importador y GDPR). El teléfono operativo es el de
  las direcciones, copiado del carrito al pedido (Doc. 13 §36.3–§36.4).
- **Importador:** el importador de clientes escribe `customers.phone` con insert masivo, sin eventos.
- **Factura:** `invoices` **no** tiene `order_address_id`, aunque `InvoiceRepository` lo envía;
  `Invoice::address()` siempre es NULL y la factura usa `order.billing_address`.
- **`email_sent`:** `Shop\Listeners\Invoice`, `Shipment` y `Refund` lo marcan en **todas** las filas
  de su tabla.
- **Transacción abierta:** una factura de un pedido sin email lanza `TypeError` y deja la
  transacción de `InvoiceRepository` abierta. Queda confirmado lo que en RT-04B0 se había inferido
  (N5).
- **Entorno:** en testing no hay `MAIL_FROM_ADDRESS` y los listeners de **Admin** también fallan
  con `Address(null)` (correo del administrador). Es la causa principal de los fallos de base del
  core. Desarrollo sí tiene el remitente configurado.
- **Reembolso (revisión final de RT-04B1a):** con `RefundRepository` real, un reembolso de un
  pedido sin email también lanza `TypeError` y deja su transacción abierta si no hay guard. Queda
  probado igual que en la factura.
- **`email_sent` no prueba entrega:** el core marca `email_sent = 1` en facturas, envíos y
  reembolsos aunque el correo no se haya enviado (también cuando el guard lo omite por falta de
  destinatario). La evidencia de que no hubo envío es el evento propio `NotificacionClienteOmitida`.
