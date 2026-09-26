# 13 — RT-04B0: diseño técnico del cliente comercial

**Estado:** RT-04B0 **CERRADO** (diseño, 2026-09-25). **RT-04B1a CERRADO** (aprobado 2026-09-26, §36).
**RT-04B1b CERRADO** (aprobado 2026-09-26, §37). Fase 03 no iniciada.

> **Cómo leer este documento.** Las secciones 1–35 son el diseño RT-04B0 tal como se aprobó y se
> conservan como historia. Lo que la implementación de RT-04B1a confirmó, corrigió o aplazó está en
> la **§36** (y lo de RT-04B1b en la **§37**), con etiquetas HALLAZGO / DECISIÓN / IMPLEMENTADO / PENDIENTE / SUPERADO / DEUDA. Donde
> una sección antigua quedó superada lleva una nota «SUPERADO — §36.x». En caso de contradicción,
> manda la §36.

**Fecha:** 2026-09-25. **Base:** commits `f884d1f` (02B1B) y `2917a2c` (RT-03), más la auditoría
RT-04A sin commit (Doc. 12 y `tests/Feature/Rt04aCaracterizacionClienteSinEmailTest.php`).

Convención de evidencia: `Paquete/archivo:línea` relativo a `packages/Webkul/…/src/` salvo que se
indique otra ruta. Los datos de desarrollo se citan solo como agregados; los teléfonos de ejemplo
son ficticios.

---

## 1. Objetivo

Convertir los hallazgos de RT-04A y las decisiones D-RT04-01…10 en una arquitectura concreta
(archivo, clase, responsabilidad, interacción y prueba) para que el cliente de WhatsApp —nombre,
teléfono, dirección, **sin email**— pueda existir como `Customer` de Bagisto, encontrarse por
teléfono, tener siempre un grupo comercial válido y llegar más adelante a un pedido **sin**
`TypeError` en notificaciones y **sin** modificar Webkul.

## 2. Alcance

- Identidad del cliente comercial: alta, búsqueda, resolución y deduplicación asistida.
- Teléfono original y normalizado, y su convivencia con `customers.phone UNIQUE`.
- Email NULL en el flujo comercial y su activación futura.
- Grupo comercial obligatorio.
- Dirección comercial sin email (objeto y reglas; su uso en carrito es de la Fase 03).
- Notificaciones sin destinatario: extension point concreto.
- Eventos de Customer: cuáles conservar y cuáles no.
- Contratos para la Fase 03, POS, API, WhatsApp y n8n/IA.

## 3. Fuera de alcance

`CrearPedidoComercialService`, la pantalla «Nueva venta», rutas y vistas propias, voz, WhatsApp
API, n8n, IA, fusión de clientes, activación de cuenta ecommerce, RMA y cambios en
`CartAddressRequest` del core. Nada de esto se implementa en RT-04B1.

## 4. Dependencias de RT-03

Regla que RT-04 **no** rompe:

```text
CLIENTE COMERCIAL → GRUPO ACTUAL DEL CLIENTE → MOTOR DE PRECIOS DE BAGISTO
```

- RT-03 resuelve el grupo en `ContextoPrecioComercial` (`ByLopez/…/Support/ContextoPrecioComercial.php:221-226`):
  `customer->group ?? invitado`, **igual que el core**. Si un cliente llega sin grupo, RT-03 lo
  precia como invitado. **No es un defecto de RT-03 y no se modifica**: RT-04 garantiza el grupo
  **antes** de que RT-03 intervenga (§13).
- `CustomerRepository` se resuelve como `CustomerRepositoryConContextoComercial` (binding de
  RT-03). RT-04 usa `create()` heredado sin cambios: **no hay incompatibilidad**.
- RT-04 no calcula precios ni fija contexto. La Fase 03 abrirá `ContextoPrecioComercial::durante()`
  con el `customer_id` que RT-04 entregue ya resuelto.

## 5. Hallazgos heredados de RT-04A (y nuevos de RT-04B0)

Los 30 hallazgos de RT-04A (Doc. 12) se toman como evidencia. RT-04B0 añade, verificado por
lectura de código y consultas `SELECT`:

| # | Hallazgo nuevo | Evidencia | Impacto |
|---|---|---|---|
| N1 | Los listeners de eventos del core se instancian con `container->make($clase)` en cada disparo | `vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php:522-541` (l. 536) | **Existe extension point**: un binding del contenedor sustituye la clase del listener sin tocar Webkul |
| N2 | El `envelope()` del mailable se construye **antes** de enviar; `MessageSending` llega tarde | `Illuminate/Mail/Mailable.php:198-201`, `:1691-1698` | Un listener de `MessageSending` **no** puede evitar el `TypeError` |
| N3 | Todos los mailables del Shop son `ShouldQueue` | `Shop/Mail/Mailable.php:11` | Con cola asíncrona el `TypeError` saltaría en el worker (job fallido), no en la petición. Desarrollo y pruebas usan `QUEUE_CONNECTION=sync` (`.env:42`, `phpunit.xml:105`) |
| N4 | El alta del admin dispara también `customer.registration.after`, que envía verificación o bienvenida **al cliente** | `Admin/Http/Controllers/Customers/CustomerController.php:127`; `Shop/Providers/EventServiceProvider.php:76-78`; `Shop/Listeners/Customer.php:21-47` | Con email NULL daría `TypeError`. Un alta comercial **no** es un registro ecommerce: no debe disparar ese evento |
| N5 | `InvoiceRepository`, `ShipmentRepository` y `RefundRepository` abren transacción y solo capturan `\Exception` | `Sales/Repositories/InvoiceRepository.php:46,184-191`; `ShipmentRepository.php:44,139-146`; `RefundRepository.php:42,162-166` | Un `TypeError` del correo aborta la operación **y deja la transacción sin rollback explícito** |
| N6 | `Payment\Listeners\GenerateInvoice` escucha `checkout.order.save.after` y puede crear la factura **dentro** de la creación del pedido | `Payment/Providers/EventServiceProvider.php:17`; `Payment/Listeners/GenerateInvoice.php:29-46` | Un pedido sin email con facturación automática dispararía `InvoicedNotification` dentro de la transacción del pedido |
| N7 | RMA y desistimiento UE envían correo **desde controladores**, no desde listeners | `Admin/Http/Controllers/Sales/RMA/RequestController.php:114,173,302,620`; `Shop/…/Account/RMAController.php:256`; `Admin/…/Sales/EUWithdrawalController.php:129` | No se cubren con el binding de listeners. Riesgo residual para la Fase 07 (§30) |
| N8 | `customer_groups` no tiene columna de activo/inactivo: solo `id, code, name, is_user_defined` | `SELECT` sobre `lpz_saas` | «Grupo activo» no existe; la aplicabilidad se define por configuración propia (§13) |
| N9 | FK `customers.customer_group_id → customer_groups.id ON DELETE SET NULL` | `Customer/Database/Migrations/2018_07_24_082930_create_customers_table.php:37` | Borrar un grupo por SQL deja clientes sin grupo, y se preciarían como invitado. El panel lo impide si el grupo tiene clientes (`CustomerGroupController.php:97,103`) |
| N10 | `syncNewRegisteredCustomerInformation()` hace `where('customer_email', $email)`; con `null`, Laravel lo traduce a `whereNull` | `Customer/Repositories/CustomerRepository.php:93` | Ejecutado para un cliente sin email **absorbería todos los pedidos comerciales sin email** (ya demostrado en 06B §2). Regla: nunca invocarlo con email NULL |
| N11 | La edición de cliente del admin exige email (`required`) y valida su unicidad **global**, no por canal | `CustomerController.php:146` | Un cliente sin email no puede editarse en el panel de Bagisto sin capturarle un email |
| N12 | `OrderController::validateOrder()` no comprueba el email; `Cart::saveAddresses()` no valida nada | `Admin/…/Sales/OrderController.php:252-282`; `Checkout/Cart.php:428-437` | La exigencia de email vive solo en los Form Requests: un servicio propio puede crear direcciones y pedidos con email NULL usando las mismas APIs del core |
| N13 | Índices reales de `customers`: `customers_phone_unique(phone)`, `customers_email_channel_unique(email, channel_id)`, `customers_api_token_unique`; `password` es NULL-able | `SHOW INDEX` e `information_schema` sobre `lpz_saas` | El teléfono es único global y el email único por canal. Se puede crear un cliente sin contraseña |

Agregados de desarrollo (`lpz_saas`, solo lectura): 15 clientes, 5 sin teléfono, 0 sin
contraseña, 15 verificados, 1 canal, 0 pedidos con `customer_email` NULL, 7 grupos (`guest`,
`general` y 5 comerciales definidos por el usuario).

## 6. Decisiones aprobadas

D-RT04-01…10, registradas con problema, motivo, impacto, riesgos y fase en el Doc. 12 §14:

| Id | Decisión |
|---|---|
| D-RT04-01 | El `Customer` de Bagisto es el cliente comercial; sin `ContactoComercial` paralelo |
| D-RT04-02 | Email opcional en el flujo comercial, representado como `NULL`; nunca `""` ni correos sintéticos |
| D-RT04-03 | La tienda sigue exigiendo email para registro, login y reset |
| D-RT04-04 | Grupo explícito y válido en el alta comercial; si falta, se rechaza; invitado no es fallback |
| D-RT04-05 | La dirección comercial puede tener email NULL sin tocar `CartAddressRequest` |
| D-RT04-06 | Sin email no se intenta el correo al cliente; sin `TypeError` y sin abortar el pedido |
| D-RT04-07 | El teléfono es la llave principal de **búsqueda**; `Customer.id` sigue siendo la identidad |
| D-RT04-08 | Teléfono original + normalizado |
| D-RT04-09 | El teléfono normalizado no es UNIQUE: índice + detección asistida |
| D-RT04-10 | Cero cambios en Webkul |

## 7. Modelo de identidad

```text
                 ┌──────────────────────────────┐
                 │ customers (Bagisto)          │  identidad técnica = customers.id
                 │ first_name, last_name        │
                 │ email      NULL | real       │  único por canal; NULL no choca
                 │ phone      NULL | E.164      │  UNIQUE global del core (§9.4)
                 │ customer_group_id  NOT NULL* │  *garantizado por RT-04, no por la BD
                 │ password   NULL (sin cuenta) │
                 └──────────────┬───────────────┘
                                │ 1..N  (CASCADE)
                 ┌──────────────▼───────────────┐
                 │ bylopez_va_customer_phones   │  índice de búsqueda comercial
                 │ telefono_original            │  NO es identidad ni contacto paralelo:
                 │ telefono_normalizado (E.164) │  sin nombre, sin email, sin grupo
                 │ INDEX (no UNIQUE global)     │
                 └──────────────────────────────┘
```

- **Identidad:** `customers.id`. Ni el teléfono ni el email identifican; solo **ayudan a
  encontrar**.
- **Sin entidad paralela:** la tabla de teléfonos no guarda nombre, email, grupo ni dirección.
  No hay nada que sincronizar en ambos sentidos; solo se indexa (§9.5).
- **Cliente comercial sin cuenta:** `email` NULL o real, `password` NULL. Mientras no tenga
  contraseña **no puede iniciar sesión** (`Hash::check` devuelve `false` con hash vacío) y
  **no tiene cuenta ecommerce**, aunque tenga email.

## 8. Diseño del email nullable

| Regla | Detalle |
|---|---|
| Representación | `customers.email = NULL`. **Prohibidos** `""`, `telefono@…`, `uuid@…` y cualquier correo sintético o compartido de la empresa |
| Normalización de entrada | `trim`; una cadena vacía o de espacios se convierte en `NULL` **antes** de validar (así nunca se persiste `""`) |
| Validación si existe | Formato (`email:rfc`) y unicidad `(email, channel_id)`, la misma semántica del índice `customers_email_channel_unique` |
| Colisión de email | No se crea. Se devuelve el cliente que ya lo tiene como **candidato** (§12); nunca se fusiona |
| Carrito y pedido (Fase 03) | `Cart::createCart` copia `customer->email` (NULL); `setCustomerPersonnelDetails` cae al email de facturación, que en el flujo comercial es el del cliente o NULL (§14). Resultado: `cart.customer_email` y `orders.customer_email` son NULL, nunca `""` |
| Notificaciones | Omitidas por el guard de destinatario (§15) |
| Sincronización por email | `syncNewRegisteredCustomerInformation()` **nunca** se invoca para un cliente con email NULL (N10) |
| Panel de Bagisto | Editar al cliente exige capturarle un email (N11). Es una limitación aceptada; la edición comercial futura tendrá su propio servicio |

## 9. Diseño del teléfono

### 9.1 Alternativas comparadas

| Criterio | **A.** `customers.telefono_normalizado` | **B.** Tabla propia `bylopez_va_customer_phones` | **C.** Normalizar `customers.phone` en sitio | **D.** Normalizar al consultar (sin guardar) |
|---|---|---|---|---|
| Toca el esquema del core | **Sí** (DDL sobre `customers`) | No | No (DDL), pero reescribe datos del core | No |
| Duplicación | Un dato derivado en la tabla del core | Copia indexada del teléfono (origen marcado) | Ninguna | Ninguna |
| UNIQUE actual | No lo resuelve: `phone` sigue único | Independiente: la tabla no es única global | **Choca**: dos clientes no pueden compartir número | No aplica |
| Búsqueda y rendimiento | Índice simple | Índice simple sobre E.164 | Índice único existente | **Barrido completo** con `REGEXP_REPLACE`; sin reglas de país |
| Varios teléfonos por cliente | **No** (una columna) | **Sí** | No | Solo si también se barren direcciones |
| WhatsApp distinto del de contacto | No | Sí (`es_whatsapp`) | No | No |
| Números compartidos | Sí en la columna nueva | **Sí** | **No** | Sí |
| Internacionales | Sí (E.164) | Sí (E.164 + `pais_iso`) | Sí, pero sin metadatos | Frágil |
| Mantenimiento | `Customer::$fillable` no la incluye: escritura por query o `forceFill` | Modelo y repositorio propios | Reescribir históricos; cambia lo que ve la tienda | Consultas complejas |
| Upgrades de Bagisto | Riesgo de choque con migraciones futuras | **Ninguno** | Formularios del core ven formatos nuevos | Ninguno |
| Sincronización | Hay que mantenerla con `phone` | Indexación en un solo sentido desde el core (§9.5) | Ninguna | Ninguna |
| API, POS, n8n e IA | Un teléfono | Consultas por número con varios resultados | Un teléfono | Lento |
| Coherencia con D-RT04 | Parcial (sin multi-teléfono) | **Total** | Viola D-RT04-09 | Viola D-RT04-08 |

### 9.2 Recomendación (pregunta 1)

> **SUPERADO PARCIALMENTE — §36.4–§36.6.** Se mantiene la tabla propia, pero con la regla funcional
> nueva D-RT04-PHONE-01: el teléfono normalizado es **UNIQUE** (un número pertenece a un solo
> Customer). Ya no se diseña para números compartidos entre Customers. El principal vive en
> `customers.phone` y la tabla es su reflejo normalizado más los secundarios.

**B. `telefono_normalizado` vive en una tabla propia, `bylopez_va_customer_phones`, con índice
no único sobre el E.164.** Es la única opción que:

- admite varios teléfonos por cliente y números compartidos;
- no toca el esquema del core;
- sobrevive a upgrades de Bagisto;
- da una búsqueda indexada igual para todos los canales.

No es un `ContactoComercial`: solo indexa teléfonos de un `Customer`.

### 9.3 Estructura

> **SUPERADO — §36.6.** De las columnas propuestas solo se crearon `telefono_normalizado`,
> `telefono_original` y `principal` (más `customer_id` y timestamps). País, tipo y estado se
> derivan normalizando; `es_whatsapp`, `origen` y `created_by_admin_id` no tenían consumidor ni
> semántica en B1a.

Definida en §19; se resume así:

- una fila por par (cliente, teléfono normalizado);
- `telefono_original` tal como se escribió;
- `telefono_normalizado` en E.164, o NULL si es inválido o ambiguo;
- `pais_iso`, `tipo` (móvil, fijo, desconocido), `estado_normalizacion`;
- `es_whatsapp` (NULL = desconocido), `es_principal` y `origen`.

### 9.4 Convivencia con `customers.phone UNIQUE` (pregunta 2)

> **SUPERADO — §36.5 y §36.8.** U1/U2 ya no aplican: la auditoría confirmó que `customers.phone`
> debe conservar el **principal** (compatibilidad con la ficha, el perfil de la tienda, las
> exportaciones y el importador), escrito en E.164 por el futuro servicio comercial. El UNIQUE del
> core es coherente con D-RT04-PHONE-01, y el UNIQUE del normalizado lo extiende a formatos
> equivalentes y a los secundarios.

El índice `customers_phone_unique` **se mantiene intacto**: es esquema del core, y el
controlador del admin valida `unique:customers,phone` de todos modos (`CustomerController.php:88,148`).
Quitarlo solo trasladaría el error al panel.

| Opción | Qué hace el alta comercial con `customers.phone` | Pros | Contras |
|---|---|---|---|
| **U1** | Nunca lo escribe (queda NULL) | Cero colisiones y cero carreras | El panel de Bagisto y la ficha del cliente no muestran teléfono |
| **U2 (recomendada)** | Lo escribe en **E.164** solo si **ningún otro cliente** tiene ese valor exacto; si lo tiene, queda NULL y el resultado lleva la advertencia `telefono_no_reflejado_en_bagisto` | El panel muestra el teléfono en la mayoría de casos; E.164 pasa la regla `PhoneNumber` (`^\+?\d+$`) | Formatos mixtos con los históricos (10 dígitos); carrera mínima entre comprobar e insertar |
| U3 | Elimina el UNIQUE del core | Permite compartir en `phone` | DDL sobre el core, riesgo de upgrade, y el admin sigue validando único. **Descartada** |

Reglas comunes a U1 y U2:

- La **fuente de búsqueda comercial es siempre `bylopez_va_customer_phones`**, nunca
  `customers.phone`.
- `customers.phone` nunca se sobrescribe desde el índice. La relación es de un solo sentido,
  core → índice (§9.5).
- El UNIQUE solo impide la igualdad **exacta de cadena**. El histórico `3005550101` y el nuevo
  `+573005550101` no chocan en `customers`, pero en el índice ambos normalizan igual: la
  coincidencia se detecta en la búsqueda y la resuelve el asesor (§12).
- **Carrera en U2:** si entre comprobar e insertar otro cliente toma el mismo valor, la
  transacción falla con `UniqueConstraintViolationException`. El servicio reintenta **una vez**
  con `phone = NULL`, sin perder el alta ni ocultar el motivo (advertencia en el resultado).

**Decisión pendiente P-01:** U1 o U2.

### 9.5 Indexación desde el core (un solo sentido)

> **SUPERADO — §36.9.** No se implementaron listeners de `customer.create.after` /
> `customer.update.after`: el importador del core escribe `customers.phone` con insert masivo, sin
> eventos, y un conflicto dentro de un listener no tiene resolución segura. Lo que se implementó:
> indexador histórico con dry-run que también detecta y re-deriva divergencias. La escritura
> transaccional del principal queda para el servicio explícito de B1b.

| Fuente | Cuándo se indexa | Origen | Fase |
|---|---|---|---|
| Alta comercial | Dentro de la transacción del alta | `alta_comercial` | B1 |
| `customers.phone` de clientes existentes | Comando `ventas-asesor:indexar-telefonos` (dry-run por defecto, `--persistir`) | `ficha_bagisto` | B1 |
| `customers.phone` editado o creado desde el panel o la tienda | Listener de `customer.create.after` y `customer.update.after` (núcleo: `CustomerController.php:125,171,306`; `RegistrationController.php:96`; `Shop/…/CustomerController.php:92`) | `ficha_bagisto` | B1 |
| Direcciones guardadas (`address_type = customer`) | Posterior | `direccion_cliente` | B2 (deuda) |
| Teléfono de WhatsApp entrante | Solo por decisión explícita del asesor | `whatsapp` | Fase 12 |

El listener de indexación:

- captura `Throwable` y no relanza (regla 20 de `00_STATUS`);
- solo añade o retira filas de origen `ficha_bagisto`;
- **nunca** toca filas de `alta_comercial`, `manual` ni `whatsapp`.

Las direcciones de carrito y pedido (`cart_*`, `order_*`) **no** se indexan: son snapshots.

## 10. Normalización

### 10.1 Por qué no basta `preg_replace('/\D/', '', $tel)`

- Pierde el `+`, que distingue lo internacional de lo nacional.
- Convierte `00` + código de país en un número nacional largo.
- Mezcla extensiones (`ext 12`) con el número.
- No distingue `3005550101` (móvil nacional) de `573005550101` (el mismo, con país).
- Acepta un fijo antiguo de 7 dígitos, que es **ambiguo** sin indicativo.
- No produce tipo ni país.

### 10.2 Contrato

```text
CLASE        ByLopez\VentasAsesor\Contracts\NormalizadorTelefono (interfaz)
IMPL. B1     ByLopez\VentasAsesor\Support\Telefono\NormalizadorTelefonoBasico
ENTRADA      ?string $valor, ?string $paisPorDefecto = null   (null → config ventasasesor.telefono.pais_por_defecto)
SALIDA       ByLopez\VentasAsesor\DataObjects\TelefonoNormalizado (inmutable)
DEPENDENCIAS config('ventasasesor.telefono.*'); sin librerías externas
CONSUMEN     CrearClienteComercialService, BuscarClienteComercialService, ResolverClienteComercialService,
             ValidadorDireccionComercial, indexador, y en el futuro API/POS/WhatsApp
```

`TelefonoNormalizado` expone:

- `original` (con `trim`);
- `normalizado` (E.164 o `null`);
- `paisIso` (`CO` o `null`);
- `tipo` (`TipoTelefono`: `movil`, `fijo`, `desconocido`);
- `estado` (`EstadoNormalizacionTelefono`: `vacio`, `valido`, `internacional_no_verificado`, `ambiguo`, `invalido`);
- `motivo` (código estable, p. ej. `fijo_sin_indicativo`);
- `esBuscable()` (estados `valido` e `internacional_no_verificado`).

### 10.3 Reglas de B1 (Colombia primero, sin cerrar lo internacional)

1. `null`, `""` o solo espacios → `vacio`.
2. Quitar solo separadores de formato: espacio, punto, guion, paréntesis y barra. Si queda
   cualquier otro carácter aparte de un `+` inicial y dígitos (letras, `ext`, `#`) → `invalido`.
3. `00` inicial → `+` (prefijo internacional).
4. Con `+`: se exigen entre 8 y 15 dígitos (máximo de E.164).
   - `+57…` → se aplican las reglas nacionales de CO a lo que sigue.
   - Otro código → `internacional_no_verificado`, con E.164 conservado, `pais_iso = null` y tipo
     `desconocido`. **Se acepta y es buscable**, pero no se verifica.
5. Sin `+`, se interpreta con el país por defecto (config, `CO`):
   - 10 dígitos que empiezan por `3` → `movil`, `+57` + número.
   - 10 dígitos que empiezan por `60` (plan de numeración vigente desde 2021) → `fijo`, `+57` + número.
   - 12 dígitos que empiezan por `57` y cuyo resto cumple una de las dos reglas anteriores →
     el mismo número (el caso `57300…`).
   - 7 u 8 dígitos (fijo antiguo sin indicativo o con indicativo de una cifra) → `ambiguo`.
     **No se adivina la ciudad.**
   - Cualquier otra longitud → `invalido`.
6. Un número `ambiguo` o `invalido` se puede **guardar** como `telefono_original` (con
   `normalizado = NULL`), pero **no** sirve para el alta comercial, que exige un teléfono
   `esBuscable()`.

Las cuatro formas `300 555 0101`, `3005550101`, `+57 300 555 0101` y `573005550101` normalizan
todas a `+573005550101`.

### 10.4 Librería (solo documentada, no se agrega)

| | `giggsey/libphonenumber-for-php` (o `-lite`) |
|---|---|
| Qué es | Adaptación a PHP de libphonenumber de Google: parseo, validación por país, tipo (móvil/fijo) y formato E.164 |
| Por qué | Metadatos de todos los países, mantenidos por Google; resuelve lo internacional que B1 deja como «no verificado» |
| Impacto | Dependencia nueva en el `composer.json` **raíz**, compartido con cambios ajenos (CorteConfeccion). La versión `-lite` evita el geocodificador y reduce el tamaño |
| Mantenimiento | Varias publicaciones al año por cambios de numeración; hay que actualizarla periódicamente |
| Alternativa sin dependencia | `NormalizadorTelefonoBasico` (§10.3) detrás de la interfaz. Cambiar de implementación no cambia datos (ambos producen E.164) ni consumidores |

**Recomendación:** B1 sin dependencia; evaluar la librería cuando haya volumen internacional real
(**P-12**).

## 11. Búsqueda comercial

```text
CLASE        ByLopez\VentasAsesor\Services\Clientes\BuscarClienteComercialService
ENTRADA      ConsultaClienteComercial { ?texto, ?telefono, ?nombre, ?email, ?channelId, limite=10 (máx. 25) }
SALIDA       ResultadoBusquedaClientes { estado, criterio, candidatos: CandidatoClienteComercial[] }
DEPENDENCIAS NormalizadorTelefono, TelefonoClienteRepository, CustomerRepository (solo lectura), ValidadorGrupoComercial
CONSUMEN     Panel asesor, Nueva venta, POS, API, WhatsApp/n8n, agente IA — todos la misma
```

**Interpretación de la consulta.**

- Los campos explícitos (`telefono`, `nombre`, `email`) mandan; la API y la IA siempre los usan.
- Un `texto` libre se clasifica en este orden:
  1. contiene `@` → email;
  2. el normalizador lo marca `valido` o `internacional_no_verificado` → teléfono;
  3. es `ambiguo` → teléfono parcial;
  4. tiene 2 o más letras → nombre;
  5. en otro caso, no hay consulta.

**Consultas.**

| Criterio | Consulta | Índice |
|---|---|---|
| Teléfono | `telefono_normalizado = :e164` | `idx telefono_normalizado` |
| Teléfono parcial (ambiguo, p. ej. 7 dígitos) | `telefono_normalizado LIKE '%:digitos'`, marcado como coincidencia **parcial** | Barrido (aceptable a la escala actual; deuda §31) |
| Nombre | `CONCAT(first_name,' ',last_name) LIKE %:t%` por palabra (AND), igual que el core (`CustomerController.php:283-285`) | Barrido (igual que el core) |
| Email | `email = :e` y, si no hay resultado, `LIKE :e%` | `customers_email_channel_unique` (prefijo) |

**Candidato** (`CandidatoClienteComercial`):

- identificación: `customer_id` y `nombre`;
- contacto: `email` (o `null`), `tiene_email` y `telefonos_coincidentes[]` (original, E.164, `es_whatsapp`);
- grupo: `grupo {id, code, name}` y `grupo_apto` (§13);
- estado: `apto_para_venta` (`status = 1`, `is_suspended = 0` y grupo apto) y `tiene_cuenta_ecommerce` (email y contraseña presentes);
- coincidencia: `tipo_coincidencia` (`telefono_exacto`, `telefono_parcial`, `email_exacto`, `email_parcial`, `nombre`);
- historial: `pedidos_count` y `ultimo_pedido_at`.

**Respuesta por número de resultados.**

| Estado | Significado | Qué hace el consumidor |
|---|---|---|
| `sin_coincidencias` | Ningún candidato | Ofrecer el alta (con el teléfono ya normalizado) |
| `coincidencia_unica` | Un candidato | **Proponerlo**, no seleccionarlo: el humano o el agente confirma con `ResolverClienteComercialService` |
| `multiples_coincidencias` | Dos o más, p. ej. un teléfono compartido | Mostrar todos; **prohibido elegir uno arbitrariamente** |

**La búsqueda nunca resuelve.** Siempre devuelve candidatos. Elegir es un acto explícito con
`customer_id`, y lo valida `ResolverClienteComercialService` (§17).

**Privacidad.** El panel interno muestra los datos completos a quien tenga permiso. Los
adaptadores de API e IA reciben por defecto teléfono y email enmascarados (`+57300***0101`,
`a***@dominio`) y el `customer_id`. **Decisión pendiente P-10.**

## 12. Duplicados

> **SUPERADO — §36.4.** Con D-RT04-PHONE-01, un teléfono ya indexado **no** permite crear otro
> Customer ni con confirmación: se localiza al titular y el flujo comercial (B1b) decide. La
> «confirmación con motivo» y P-05 dejan de aplicar para el teléfono. Siguen vigentes la detección
> por nombre y el rechazo por email duplicado.

Política: **detectar, advertir y seleccionar con ayuda; nunca fusionar automáticamente.**

Ejemplo: el asesor escribe `3005550101` y existen «Aurora Ejemplo» (`customers.phone = 3005550101`,
indexado como `ficha_bagisto`) y «Aurora E.» (alta comercial con `+57 300 555 0101`). Ambos se
indexan como `+573005550101`, así que la búsqueda devuelve `multiples_coincidencias` con los dos:
nombre, grupo, últimos pedidos, `tiene_email` y si son aptos para la venta. El asesor puede:

1. **usar** uno → `ResolverClienteComercialService::resolver(customer_id)`;
2. **crear otro** cliente → solo con `ConfirmacionDuplicado { candidatos_vistos: [ids], motivo }`.
   El motivo sale de una lista (`numero_compartido`, `cambio_de_titular`, `otro`) y admite nota.

Si los candidatos cambiaron entre la búsqueda y el alta, el servicio vuelve a pedir confirmación.

| Coincidencia al crear | Efecto |
|---|---|
| Teléfono exacto (E.164) | `requiere_confirmacion`: no crea sin `ConfirmacionDuplicado` que cubra **esos** ids |
| Email exacto en el mismo canal | **Rechazo** (`email_en_uso`), porque la BD no lo admite; se devuelve el titular como candidato |
| Solo nombre parecido | Advertencia informativa, sin bloqueo (**P-05**) |

La confirmación queda trazada en `bylopez_va_customer_origins` (§19, **P-04**). Fusionar clientes
queda fuera de RT-04: exigiría reasignar pedidos, direcciones, atribuciones y RMA.

## 13. Customer group

```text
CLASE        ByLopez\VentasAsesor\Support\Clientes\ValidadorGrupoComercial
ENTRADA      ?int $customerGroupId
SALIDA       CustomerGroup (válido)  |  GrupoComercialInvalidoException{codigo}
DEPENDENCIAS CustomerGroupRepository (core), core()->getGuestCustomerGroup(), config('ventasasesor.clientes.grupos_habilitados')
CONSUMEN     CrearClienteComercialService, ResolverClienteComercialService, GruposComercialesDisponibles (listado para UI/API)
```

Reglas, en orden:

| Código | Condición |
|---|---|
| `grupo_requerido` | `null` o no enviado. **Nunca** se aplica un valor por defecto en silencio |
| `grupo_inexistente` | El id no existe en `customer_groups` |
| `grupo_invitado_no_permitido` | Es el grupo de `core()->getGuestCustomerGroup()` (se identifica por la regla del core, `code = guest`, `Core/Core.php:731-738`, no por un nombre del proyecto) |
| `grupo_no_habilitado` | Existe una lista `grupos_habilitados` (códigos) en la configuración del módulo y el grupo no está en ella. Con la lista vacía o `null` valen todos menos el invitado |

- **No hay concepto de «grupo activo»** en Bagisto (N8); la aplicabilidad la define la lista de
  configuración. No se hardcodean `Mayorista_*`, `VIP`, `General` ni `Recurrente`.
- **Sin grupo por defecto.** La UI y la API presentan `GruposComercialesDisponibles::listar()`
  sin preselección, porque el grupo por defecto para WhatsApp aún no está decidido (**P-03**).
- **Invitado:** este diseño **lo prohíbe** en el alta comercial, porque un cliente comercial
  registrado con precio de invitado es el defecto que RT-03 cerró. Hay que confirmarlo
  explícitamente (**P-02**): no se toma como decidido.

**Garantía antes de RT-03 (pregunta 5).**

1. **En el alta:** `ValidadorGrupoComercial` dentro de `CrearClienteComercialService`, antes de
   abrir la transacción. Sin grupo válido no hay `Customer`.
2. **En la resolución:** `ResolverClienteComercialService` **revalida el grupo actual**. El
   grupo pudo cambiar después, pasar a invitado o quedar NULL por `SET NULL` (N9). Si no es
   apto, el cliente se devuelve con `apto_para_venta = false` y el motivo, y la Fase 03 **no**
   abre el contexto de precio.
3. **RT-03 no cambia:** sigue preciando con el grupo actual. La Fase 03 solo abre `durante()`
   con un `ClienteComercialResuelto`.
4. **Clientes existentes creados por el panel** con grupo NULL o invitado (hay 1 en desarrollo)
   aparecen en la búsqueda como no aptos, con la acción «asignar grupo».

**Si el grupo se elimina o cambia.**

- Cambiarlo en el panel es legítimo: RT-03 lo aplica en el siguiente recálculo, y la Fase 03
  revalida el borrador antes de confirmar.
- Borrarlo lo impide el panel si hay clientes (N9). Por SQL quedaría NULL, y lo detecta la
  resolución (punto 2).

## 14. Direcciones

**Qué usa Bagisto.**

| Tipo | Modelo y repositorio | Validación del core | Eventos |
|---|---|---|---|
| Guardada del cliente (`address_type = customer`) | `CustomerAddress` / `CustomerAddressRepository::create` (sin lógica propia) | `Admin/Http/Requests/AddressRequest.php:38-40` (email obligatorio) — **solo en el controlador** | `customer.addresses.create.before/after` (`Admin/…/Customers/AddressController.php:74,86`); **ningún listener en el core** |
| Carrito (`cart_billing`, `cart_shipping`) | `Cart::saveAddresses()` → `updateOrCreateBillingAddress/ShippingAddress` → `CartAddressRepository` | `CartAddressRequest` (Admin l. 53, Shop l. 52) — **solo en el controlador** | Ninguno propio; `setCustomerPersonnelDetails()` y `resetShippingMethod()` |
| Pedido (`order_billing`, `order_shipping`) | `OrderRepository::create()` a partir de `OrderResource` / `OrderAddressResource` (copia `email`) | Ninguna | `checkout.order.save.before/after` |

**Diseño.**

```text
CLASE        ByLopez\VentasAsesor\DataObjects\DireccionComercial (inmutable)
CAMPOS       nombre, apellido, telefono (TelefonoNormalizado), lineas[], ciudad, departamento (state),
             pais (ISO2), codigo_postal (?), empresa (?), email (?string, NULL si no hay)
MÉTODOS      paraDireccionDeCliente(): array   → formato de CustomerAddressRepository::create
             paraCarrito(bool $usarParaEnvio): array → formato exacto de Cart::saveAddresses()['billing'|'shipping']

CLASE        ByLopez\VentasAsesor\Support\Clientes\ValidadorDireccionComercial
REGLAS       las mismas de CartAddressRequest, salvo: email => nullable|email:rfc;
             phone => NormalizadorTelefono::esBuscable() en lugar de PhoneNumber; postcode (P-06)

CLASE        ByLopez\VentasAsesor\Services\Clientes\GuardarDireccionClienteComercialService
ENTRADA      int $customerId, DireccionComercial, bool $predeterminada
SALIDA       CustomerAddress
HACE         valida → dispatch customer.addresses.create.before → (si predeterminada) desmarca la anterior,
             como AddressController:76-80 → CustomerAddressRepository::create → dispatch customer.addresses.create.after
```

- **Qué email lleva la dirección** (cierra Doc. 12 §13-5): el del cliente si lo tiene; si no,
  **NULL**. Un email capturado durante la venta **no** se escribe solo en la dirección: pasa por
  el servicio de agregar email (§24), para que `customer.email` y la dirección no diverjan.
- **Teléfono de la dirección:** se guarda en E.164. Pasa la regla `PhoneNumber` del core, así
  que editarla desde el panel no falla por formato.
- **Snapshots que necesita el carrito:** `customer_id`, `customer_first_name/last_name` y
  `customer_email` (NULL) del cliente, más las direcciones `cart_*`. Todo lo escribe
  `Cart::saveAddresses()`, dentro de `ContextoPrecioComercial::durante()` (Fase 03).
- **Snapshots que necesita el pedido:** los copia `OrderResource` del carrito. RT-04 no escribe
  nada en `orders`.
- **Sin escrituras descontroladas:** ninguna tabla del core se escribe con query builder; se
  usan `CustomerRepository`, `CustomerAddressRepository` y el facade `Cart`. Solo se reemplazan
  las reglas del Form Request, que no son lógica de dominio.

## 15. Notificaciones

### 15.1 Mapa exacto

| Evento | Listener core → método | Provider | Notificación | Destinatario | Sin email hoy |
|---|---|---|---|---|---|
| `customer.registration.after` | `Shop\Listeners\Customer::afterCreated` | `Shop/Providers/EventServiceProvider.php:76` | `EmailVerificationNotification` o `RegistrationNotification` (según config) | `customer.email` | **TypeError** (el listener atrapa solo `\Exception`) |
| `customer.create.after` | `Admin\Listeners\Customer::afterCreated` | `Admin/Providers/EventServiceProvider.php:22` | `Admin\Mail\Customer\RegistrationNotification` | email del **administrador** (config) | Sin problema de destinatario del cliente |
| (sin evento) `CustomerController::store` | — | — | `NewCustomerNotification` (credenciales) | `customer.email` | **TypeError** → 500 **después** de guardar |
| `customer.password.update.after` | `Shop\Listeners\Customer::afterPasswordUpdated` | Shop:80 | `UpdatePasswordNotification` | `customer.email` | TypeError |
| `customer.note.create.after` | `Shop\Listeners\Customer::afterNoteCreated` (solo si `customer_notified`) | Shop:88 | `NoteNotification` | `note.customer.email` | TypeError |
| `customer.subscription.after` | `Shop\Listeners\Customer::afterSubscribed` | Shop:84 | `SubscriptionNotification` | `subscriber.email` (siempre existe) | No aplica |
| `checkout.order.save.after` | `Shop\Listeners\Order::afterCreated` → `Base::prepareMail` | Shop:106 | `Shop\Mail\Order\CreatedNotification` | `order.customer_email` | **TypeError dentro de la transacción del pedido** → rollback y reintentos (02B0) |
| `checkout.order.save.after` | `Admin\Listeners\Order::afterCreated` | Admin:38 | `Admin\Mail\Order\CreatedNotification` | administrador | Sin problema |
| `checkout.order.save.after` | `Payment\Listeners\GenerateInvoice::handle` → `InvoiceRepository::create` → `sales.invoice.save.after` | `Payment/Providers/EventServiceProvider.php:17` | `InvoicedNotification` (encadenado) | `order.customer_email` | TypeError (N6) |
| `sales.order.cancel.after` | `Shop\Listeners\Order::afterCanceled` | Shop:111 | `CanceledNotification` | `order.customer_email` | TypeError |
| `sales.order.comment.create.after` | `Shop\Listeners\Order::afterCommented` (solo si `customer_notified`) | Shop:116 | `CommentedNotification` | `comment.order.customer_email` | TypeError |
| `sales.invoice.save.after` | `Shop\Listeners\Invoice::afterCreated` | Shop:120 | `InvoicedNotification` | `order.customer_email` | TypeError; la transacción de la factura queda sin rollback (N5) |
| `sales.invoice.send_duplicate_email` | `Shop\Listeners\Invoice::afterCreated($invoice, $email)` | Shop:124 | `InvoicedNotification` | **email escrito por el admin** ?? `order.customer_email` | Funciona si el admin escribe uno |
| `sales.shipment.save.after` | `Shop\Listeners\Shipment::afterCreated` | Shop:128 | `ShippedNotification` | `order.customer_email` | TypeError (N5) |
| `sales.shipment.save.after` | `Admin\Listeners\Shipment::afterCreated` | Admin:50 | `InventorySourceNotification` y aviso al admin | email del inventario o del admin | Sin problema |
| `sales.refund.save.after` | `Shop\Listeners\Refund::afterCreated` | Shop:132 | `RefundedNotification` | `order.customer_email` | TypeError (N5) |
| (controladores RMA) | — | — | `CustomerRMA*Notification`, `AdminToCustomerConversationNotification` | `rma.order.customer.email` | TypeError → 500 (**no cubierto**, N7) |
| (controlador EUWithdrawal) | — | — | `WithdrawalConfirmation` | `withdrawal.customer_email` | No aplica en CO (**no cubierto**, N7) |
| Reset de contraseña | `Customer::sendPasswordResetNotification` | — | `ResetPasswordNotification` | email del broker | Imposible sin email (lo pide la tienda) |

### 15.2 Alternativas

| | Archivos afectados | Dependencia con Webkul | Upgrades | Duplicación | Doble email | Perder notificaciones | Tienda | Admin | VentasAsesor |
|---|---|---|---|---|---|---|---|---|---|
| **A.** Desregistrar listeners concretos y registrar propios | Provider propio que manipule `Event` | Alta: Laravel no permite quitar **un** listener; `Event::forget` borra **todos** los del evento (inventario, facturación, atribución…) | Alto | Reescribir la lista completa de listeners del evento | Alto si se duplica el registro | Alto si se olvida uno | Riesgo | Riesgo | Funciona |
| **B. Sustituir la clase del listener por binding (subclase con guard)** | 5 subclases + bindings en `VentasAsesorServiceProvider::register()` | Media: hereda de clases públicas del core; **cero ediciones** | Medio, mitigado con pruebas de contrato (§35) | Ninguna: el cuerpo es `if (sin destinatario) return; parent::…` | **Ninguno**: se sustituye, no se añade | Ninguno para destinatarios válidos | **Idéntica** con email válido | Idéntico | Omite sin error |
| **C.** Capa propia de notificación comercial | Nuevo sistema de correo | Baja | Bajo | **Alta**: plantillas y lógica de correos del core | Alto (core + propio) | — | No resuelve el `TypeError` del core por sí sola | — | — |
| **D.** Evitar que los listeners reciban un destinatario sin email | Crear pedido con email `""` o sintético, o no disparar eventos | — | — | — | — | `""` pierde el correo en silencio | — | — | **Prohibida** (D-RT04-02) o rompe inventario, facturación y atribución |
| **E.** Decorar el mailer (`Mail::queue`) y atrapar `TypeError` | Binding de `mail.manager` | Baja | Bajo | Ninguna | Ninguno | **Oculta errores reales de todo el sistema**; con cola asíncrona no actúa | Riesgo | Riesgo | Parcial |
| E′. Listener de `MessageSending` | Listener | Ninguna | — | — | — | — | Llega tarde (N2) | — | **No funciona** |

### 15.3 Extension point concreto (pregunta 3): **B**

> **IMPLEMENTADO con dos ajustes — §36.12–§36.14.**
> - El guard se engancha en `prepareMail()`, no en los métodos públicos: ese es el punto en el que
>   el core **ya decidió** enviar (configuración y `customer_notified`), así que no se duplica
>   ninguna regla.
> - En `Shop\Listeners\Customer` solo se protege `afterNoteCreated`, el único camino real sin email.
>
> El binding quedó demostrado con pruebas antes de implementarse.

```php
// VentasAsesorServiceProvider::register()  (diseño, no implementado)
$this->app->bind(\Webkul\Shop\Listeners\Order::class,    OrderConDestinatarioVerificado::class);
$this->app->bind(\Webkul\Shop\Listeners\Invoice::class,  InvoiceConDestinatarioVerificado::class);
$this->app->bind(\Webkul\Shop\Listeners\Shipment::class, ShipmentConDestinatarioVerificado::class);
$this->app->bind(\Webkul\Shop\Listeners\Refund::class,   RefundConDestinatarioVerificado::class);
$this->app->bind(\Webkul\Shop\Listeners\Customer::class, CustomerConDestinatarioVerificado::class);
```

**Por qué funciona.** `Shop/Providers/EventServiceProvider::$listen` registra `[Clase, 'método']`.
En cada disparo `Dispatcher::createClassCallable` hace `container->make(Clase)` (N1), así que
el binding entrega la subclase. El `handlerShouldBeQueued` usa reflexión sobre la clase
original, que no es `ShouldQueue`, y la ejecución sigue síncrona igual que hoy.

**Cada subclase** (`ByLopez\VentasAsesor\Listeners\Correo\…`) sobrescribe solo los métodos
públicos mapeados en §15.1:

```php
public function afterCreated(OrderContract $order)
{
    if (! DestinatarioCorreo::esValido($order->customer_email)) {
        $this->omitir('pedido_creado', $order);   // log + evento, nunca lanza
        return;
    }
    parent::afterCreated($order);
}
```

| Subclase | Métodos | Destinatario comprobado |
|---|---|---|
| `OrderConDestinatarioVerificado` | `afterCreated`, `afterCanceled`, `afterCommented` | `order.customer_email` / `comment.order.customer_email` |
| `InvoiceConDestinatarioVerificado` | `afterCreated($invoice, $duplicado = null)` | `$duplicado ?? invoice.order.customer_email` (respeta el reenvío manual) |
| `ShipmentConDestinatarioVerificado` | `afterCreated` | `shipment.order.customer_email` |
| `RefundConDestinatarioVerificado` | `afterCreated` | `refund.order.customer_email` |
| `CustomerConDestinatarioVerificado` | `afterCreated`, `afterPasswordUpdated`, `afterNoteCreated` (no `afterSubscribed`) | `customer.email` / `note.customer.email` |

- **`DestinatarioCorreo::esValido(?string)`:** cadena, `trim` no vacío y
  `filter_var(FILTER_VALIDATE_EMAIL)`. Cubre `NULL` (hoy `TypeError`), `""` (hoy se pierde en
  silencio) y el formato inválido (hoy excepción de Symfony registrada). Los tres pasan a una
  **omisión explícita**.
- **Omisión:** `Log::info` con el tipo y el id de la entidad, sin PII, más el evento propio
  `ventas_asesor.notificacion_cliente.omitida` (tipo, `order_id`/`customer_id`, motivo). Ese
  evento es el **punto de enganche futuro para notificar por WhatsApp** (Fase 12) sin tocar
  nada más.
- **Los listeners de Admin no se sustituyen:** su destinatario es el administrador o el
  inventario.
- **Tienda:** con email válido el código ejecutado es exactamente el del core (`parent::`); no se
  desactiva ningún correo válido.
- **Riesgo de upgrade:** si Bagisto cambia la firma de un método, PHP falla al cargar la
  subclase. Si renombra o mueve el listener, el binding queda muerto y vuelve el `TypeError`.
  Ambos casos los detectan las pruebas de contrato de §35 (el mapa de `$listen` apunta a los
  métodos sobrescritos, y cada evento con email válido encola el mismo mailable que el core).
- **Residual no cubierto (N7):** los correos de RMA y desistimiento se envían desde
  controladores. Queda documentado para la Fase 07 (RMA), que ya está bloqueada por RT-01 y
  RT-02. **No bloquea RT-04B1.**

## 16. Eventos

### 16.1 Alta en el admin, hoy (pregunta del bloque 9)

```text
CustomerController::store  (Admin/…/Customers/CustomerController.php)
 80-88  validate (email required, phone unique + PhoneNumber, grupo NO validado)
 93     Event customer.registration.before
 113    Event customer.create.before
 115    customerRepository->create()      ← SIN transacción: el Customer queda persistido aquí
 117-122 Mail::queue(NewCustomerNotification)  ← correo de credenciales (catch \Exception: el TypeError escapa)
 125    Event customer.create.after       → Admin\Listeners\Customer (correo al admin)
 127    Event customer.registration.after → Shop\Listeners\Customer (verificación/bienvenida AL CLIENTE)
```

- **Qué dispara el correo:** el de credenciales lo envía el **controlador** directamente, sin
  evento. La verificación o bienvenida la dispara `customer.registration.after`.
- **Dentro o fuera de transacción:** fuera, porque no hay transacción.
- **¿Persiste el Customer si falla?** Sí. Probado en RT-04A: 500 con el cliente ya guardado.
- **Otros listeners:** en todo `packages/` solo escuchan esos dos. `Prettus RepositoryEntityCreated`
  → `Core\Listeners\CleanCacheRepository` se dispara dentro de `create()`.

### 16.2 Alta comercial (pregunta 4)

| Paso | Qué | Por qué |
|---|---|---|
| 1 | Validar datos, grupo, teléfono y email, y detectar duplicados | Fuera de la transacción |
| 2 | `DB::transaction`: `Event customer.create.before` → `CustomerRepository::create()` → filas en `bylopez_va_customer_phones` → (opcional) dirección → fila de procedencia | Todo o nada; `create()` conserva los eventos de Eloquent y Prettus (limpieza de caché) |
| 3 | `DB::afterCommit`: `Event customer.create.after` dentro de `try/catch (Throwable)` + `report()` | Conserva la **extensibilidad** del core (aviso al admin y listeners de terceros). Un fallo de correo **no** deshace el alta: el resultado lleva la advertencia `notificacion_admin_fallida` |
| 4 | `DB::afterCommit`: evento propio `ventas_asesor.cliente_comercial.creado` | Enganche para integraciones futuras (n8n, CRM) |

**No se disparan:**

- `customer.registration.before/after`: significan registro de **cuenta ecommerce**, y además
  envían verificación o bienvenida al cliente (N4). **P-08** decide si un cliente comercial con
  email recibe bienvenida.
- `NewCustomerNotification`: no hay credenciales que enviar.
- `customer.subscription.*`: no se suscribe a nadie al boletín.

**Datos del `Customer` comercial:**

- identidad: `first_name`, `last_name`, `email` (NULL o validado), `gender` (NULL si no se da);
- grupo y canal: `customer_group_id` validado y `channel_id` (explícito o canal por defecto);
- `phone` según P-01;
- cuenta: `password = NULL` (sin cuenta), `is_verified = 0` (no hay email verificado), `status = 1`,
  `is_suspended = 0` y `subscribed_to_news_letter = 0`;
- tokens: `token` y `api_token` en NULL (el cliente sin email nunca recibe token de verificación,
  requisito C8 de 06B).

Aunque la Fase 03 llame al alta dentro de su propia transacción, `DB::afterCommit` garantiza que
los eventos solo salen si **toda** la operación confirma.

## 17. Servicios propuestos

| Servicio | Responsabilidad | Entrada | Salida | Dependencias | Consumidores |
|---|---|---|---|---|---|
| `BuscarClienteComercialService` | Búsqueda única por teléfono, nombre y email | `ConsultaClienteComercial` | `ResultadoBusquedaClientes` | Normalizador, `TelefonoClienteRepository`, `CustomerRepository`, `ValidadorGrupoComercial` | Todos los canales |
| `CrearClienteComercialService` | Alta con email NULL o real, grupo obligatorio, teléfono indexado y duplicados confirmados | `DatosClienteComercial`, `ActorComercial` | `ResultadoCreacionCliente` (`creado`, `requiere_confirmacion`, `rechazado`, con errores y advertencias) | Normalizador, validadores, `BuscarClienteComercialService`, `CustomerRepository`, `TelefonoClienteRepository`, `GuardarDireccionClienteComercialService` | Modal de Nueva venta, POS, API |
| `ResolverClienteComercialService` | Convertir un `customer_id` elegido en un cliente **apto y verificado** para vender | `int customerId`, `?TelefonoNormalizado declarado` | `ClienteComercialResuelto` o `ClienteComercialNoAptoException{motivo}` | `CustomerRepository`, `ValidadorGrupoComercial`, `TelefonoClienteRepository` | Fase 03, API, WhatsApp |
| `GuardarDireccionClienteComercialService` | Dirección guardada del cliente sin email obligatorio | `customerId`, `DireccionComercial`, `predeterminada` | `CustomerAddress` | `ValidadorDireccionComercial`, `CustomerAddressRepository` | Alta, Fase 03 |
| `IndexadorTelefonosClientes` | Indexar `customers.phone` en un solo sentido | `Customer` | void | Normalizador, `TelefonoClienteRepository` | Listener y comando |
| *(posterior)* `AgregarEmailClienteComercialService` | Añadir email al mismo Customer | `customerId`, `email` | resultado | — | RT-04B2 o posterior |
| *(Fase 03)* `CambiarClienteDelBorradorService` | Cambiar el cliente de un carrito borrador | — | — | Resolver (RT-04), `ContextoPrecioComercial` (RT-03), `Cart` | Fase 03 |

`ClienteComercialResuelto` (inmutable, serializable): `customer_id`, `nombre`, `apellido`,
`email` (NULL o real), `tiene_email`, `grupo {id, code}`, `channel_id`, `telefono_principal`
(E.164), `direccion_predeterminada_id` (o NULL) y `advertencias[]`. Es **lo único** que la
Fase 03 necesita para abrir `ContextoPrecioComercial::durante()`.

## 18. Clases propuestas

Todas dentro de `packages/ByLopez/VentasAsesor/src/`.

| Clase | Tipo | Responsabilidad | Entrada | Salida | Dependencias | Quién la consume |
|---|---|---|---|---|---|---|
| `Contracts/NormalizadorTelefono` | Interfaz | Contrato de normalización | `?string, ?string país` | `TelefonoNormalizado` | — | Servicios y validadores |
| `Support/Telefono/NormalizadorTelefonoBasico` | Implementación | Reglas §10.3 | ídem | ídem | config | Binding de la interfaz |
| `DataObjects/TelefonoNormalizado` | VO | Resultado de normalizar | — | — | — | Todos |
| `Enums/EstadoNormalizacionTelefono` | Enum | `vacio`, `valido`, `internacional_no_verificado`, `ambiguo`, `invalido` | — | — | — | Normalizador |
| `Enums/TipoTelefono` | Enum | `movil`, `fijo`, `desconocido` | — | — | — | Normalizador |
| `Enums/OrigenTelefono` | Enum | `alta_comercial`, `ficha_bagisto`, `manual`, `direccion_cliente`, `whatsapp` | — | — | — | Índice |
| `Models/TelefonoCliente` | Modelo | Fila de `bylopez_va_customer_phones`; `belongsTo(CustomerProxy)` | — | — | — | Repositorio |
| `Contracts/TelefonoCliente` + `Repositories/TelefonoClienteRepository` | Repositorio | Consultas por E.164 y por cliente; alta idempotente | — | — | Modelo | Servicios e indexador |
| `DataObjects/ConsultaClienteComercial` | DTO | Consulta | — | — | — | Búsqueda |
| `DataObjects/ResultadoBusquedaClientes`, `CandidatoClienteComercial` | DTO | Resultado serializable (`toArray`) | — | — | — | Todos los canales |
| `Enums/EstadoBusquedaCliente`, `Enums/TipoCoincidenciaCliente` | Enum | Estados y tipos §11 | — | — | — | Búsqueda |
| `DataObjects/DatosClienteComercial` | DTO | Nombre, apellido, teléfono, email?, grupo, canal?, género?, dirección?, confirmación?, canal de captura | — | — | — | Alta |
| `DataObjects/ConfirmacionDuplicado` | DTO | `candidatos_vistos[]`, motivo, nota | — | — | — | Alta |
| `DataObjects/ResultadoCreacionCliente` | DTO | Estado, `customer_id`, candidatos, errores y advertencias | — | — | — | Llamadores del alta |
| `DataObjects/ClienteComercialResuelto` | DTO | §17 | — | — | — | Fase 03 |
| `DataObjects/DireccionComercial` | VO | §14 | — | — | — | Dirección, Fase 03 |
| `Support/Clientes/ValidadorGrupoComercial` | Validador | §13 | `?int` | `CustomerGroup` | `CustomerGroupRepository` | Alta, resolución |
| `Support/Clientes/GruposComercialesDisponibles` | Consulta | Grupos seleccionables (sin preselección) | — | colección | Validador | UI y API |
| `Support/Clientes/ValidadorDireccionComercial` | Validador | §14 | array | `DireccionComercial` | Normalizador | Dirección |
| `Support/Correo/DestinatarioCorreo` | Helper | `esValido(?string)` | — | bool | — | Subclases de listener |
| `Listeners/Correo/{Order,Invoice,Shipment,Refund,Customer}ConDestinatarioVerificado` | Subclases de listener | Guard §15.3 | evento | — | Listener del core | Contenedor (binding) |
| `Listeners/IndexarTelefonoDeCliente` | Listener | `customer.create.after` / `customer.update.after` → indexador | Customer | — | Indexador | Eventos |
| `Services/Clientes/*` | Servicios | §17 | — | — | — | — |
| `Exceptions/GrupoComercialInvalidoException`, `DatosClienteComercialInvalidosException`, `ClienteComercialNoAptoException` | Excepciones | Errores de dominio con código estable | — | — | — | Llamadores |
| `Console/Commands/IndexarTelefonosClientesCommand` | Comando | Backfill con dry-run por defecto, `--persistir`, `--customer`, `--limite` | — | informe | Indexador | Operación |
| `Config/ventasasesor.php` | Config | `telefono.pais_por_defecto` (`CO`) y `clientes.grupos_habilitados` (`null`) | — | — | — | Normalizador y validador |

## 19. Migraciones propuestas (no ejecutadas)

> **SUPERADO — §36.6 y §36.16.** M1 se creó con esquema reducido: UNIQUE global del normalizado y
> principal 1/NULL con UNIQUE (customer_id, principal). M2 (`bylopez_va_customer_origins`)
> **no se creó** y queda aplazada.

**M1 — `bylopez_va_customer_phones`**

| Columna | Tipo | NULL | INDEX | UNIQUE | FK | Motivo |
|---|---|---|---|---|---|---|
| `id` | bigint unsigned PK | no | PK | — | — | — |
| `customer_id` | int unsigned | no | (por el UNIQUE compuesto) | compuesto con `telefono_normalizado` | `customers.id` **ON DELETE CASCADE** | Es un índice derivado: sin cliente no tiene sentido. **No** bloquea el borrado de clientes del core, a diferencia de las atribuciones, que usan RESTRICT a propósito |
| `telefono_original` | varchar(50) | no | — | — | — | Presentación y auditoría |
| `telefono_normalizado` | varchar(16) | **sí** | **sí** (`idx_va_phones_normalizado`) | — | — | E.164 (máx. `+` y 15 dígitos). NULL si es ambiguo o inválido |
| `pais_iso` | char(2) | sí | — | — | — | País detectado |
| `tipo` | varchar(20) | no | — | — | — | `TipoTelefono` |
| `estado_normalizacion` | varchar(30) | no | — | — | — | `EstadoNormalizacionTelefono` |
| `es_whatsapp` | boolean | **sí** | — | — | — | NULL = desconocido |
| `es_principal` | boolean, default 0 | no | — | — | — | Uno por cliente, garantizado por el servicio con `lockForUpdate` (MySQL no tiene índices parciales) |
| `origen` | varchar(30) | no | — | — | — | `OrigenTelefono`; el indexador solo toca `ficha_bagisto` |
| `created_by_admin_id` | int unsigned | sí | — | — | **sin FK** | Mismo patrón que la 02A1 (el administrador puede borrarse) |
| `created_at`, `updated_at` | timestamp | sí | — | — | — | — |

**M2 — `bylopez_va_customer_origins` (procedencia del alta comercial, P-04)**

| Columna | Tipo | NULL | INDEX | UNIQUE | FK | Motivo |
|---|---|---|---|---|---|---|
| `id` | bigint unsigned PK | no | PK | — | — | — |
| `customer_id` | int unsigned | no | — | **sí** | `customers.id` ON DELETE CASCADE | Una procedencia por cliente |
| `canal_captura` | varchar(30) | no | — | — | — | `CanalVenta` existente (panel, POS, API, WhatsApp…) |
| `created_by_admin_id` | int unsigned | sí | sí | — | sin FK | Quién lo creó |
| `created_by_admin_name` | varchar(255) | sí | — | — | — | Snapshot, patrón 02A1 |
| `duplicado_confirmado` | boolean, default 0 | no | — | — | — | Se creó pese a una coincidencia |
| `motivo_duplicado` | varchar(30) | sí | — | — | — | `numero_compartido`, `cambio_de_titular`, `otro` |
| `nota_duplicado` | varchar(500) | sí | — | — | — | — |
| `candidatos_duplicado` | json | sí | — | — | — | Ids vistos al confirmar |
| `created_at` | timestamp | sí | — | — | — | Inmutable |

M2 no guarda nombre, email, teléfono ni grupo: **no** es una entidad de identidad paralela.

**Ninguna migración altera tablas del core.** Primero se aplican en `lpz_saas_testing`. En
`lpz_saas`, solo con autorización expresa y con `--path` limitado a estos archivos, para no
arrastrar migraciones pendientes de CorteConfeccion (ya ocurrió en la 02A1: 00_STATUS, precondición 16).

## 20. Índices propuestos

> **SUPERADO — §36.6.** Índices reales: `bvacp_normalizado_unique (telefono_normalizado)` UNIQUE
> **global** y `bvacp_customer_principal_unique (customer_id, principal)`. La afirmación
> «sin índice UNIQUE global sobre el teléfono normalizado» quedó revocada por D-RT04-PHONE-01.

| Tabla | Índice | Tipo | Consulta que sirve |
|---|---|---|---|
| `bylopez_va_customer_phones` | `idx_va_phones_normalizado (telefono_normalizado)` | INDEX | Búsqueda por teléfono (principal) |
| `bylopez_va_customer_phones` | `uq_va_phones_cliente_tel (customer_id, telefono_normalizado)` | UNIQUE **por cliente** | Evita repetir el mismo número en el mismo cliente; admite el mismo número en **clientes distintos** (D-RT04-09) y varios NULL |
| `bylopez_va_customer_origins` | `uq_va_origins_customer (customer_id)` | UNIQUE | 1:1 con el cliente |
| `bylopez_va_customer_origins` | `idx_va_origins_admin (created_by_admin_id)` | INDEX | Altas por asesor (KPIs futuros) |

**Sin índice UNIQUE global sobre el teléfono normalizado.** Sin índices nuevos en tablas del core.

## 21. Flujo alta cliente

```text
Adaptador (modal / POS / API)  ── autoriza (ACL ventas_asesor.clientes.create, P-11)
   │
   ▼
CrearClienteComercialService::crear(DatosClienteComercial, ActorComercial)
   1. email: trim → ""→NULL → formato → unicidad (email, canal)      ── colisión ⇒ rechazado + candidato
   2. teléfono: NormalizadorTelefono → debe ser esBuscable()           ── si no ⇒ rechazado (campo telefono)
   3. grupo: ValidadorGrupoComercial                                   ── si no ⇒ rechazado (código de grupo)
   4. dirección (si viene): ValidadorDireccionComercial
   5. duplicados: BuscarClienteComercialService(teléfono E.164)
        ├─ hay candidatos y la confirmación no cubre esos ids ⇒ requiere_confirmacion(candidatos)  [no crea]
        └─ ninguno, o confirmación válida ⇒ sigue
   6. DB::transaction {
        customer.create.before
        CustomerRepository::create([... email NULL|real, password NULL, is_verified 0, grupo, canal, phone P-01 ...])
        TelefonoClienteRepository::agregar(principal, alta_comercial, es_whatsapp)
        (dirección) GuardarDireccionClienteComercialService
        (P-04) procedencia
      }
   7. afterCommit: customer.create.after (try/catch Throwable) + ventas_asesor.cliente_comercial.creado
   8. ResultadoCreacionCliente::creado(customer_id, advertencias)
```

## 22. Flujo búsqueda cliente

```text
BuscarClienteComercialService::buscar(ConsultaClienteComercial)
   texto → clasificar (email | teléfono | teléfono parcial | nombre)
   teléfono → E.164 → bylopez_va_customer_phones.telefono_normalizado = ?  → customer_ids
   nombre   → customers CONCAT LIKE
   email    → customers.email = ? | LIKE ?%
   unir, deduplicar por customer_id, ordenar (teléfono exacto > email > nombre; luego último pedido)
   candidatos + grupo_apto + apto_para_venta
   ⇒ sin_coincidencias | coincidencia_unica | multiples_coincidencias   (nunca selecciona)
```

## 23. Flujo cliente sin email

```text
alta con email NULL  →  customers.email NULL, password NULL, is_verified 0
Fase 03: Resolver → durante(cart, customer) → Cart::createCart(customer)  (cart.customer_email NULL)
         → Cart::saveAddresses(DireccionComercial::paraCarrito(), email NULL) → setCustomerPersonnelDetails (NULL ?? NULL)
         → OrderRepository::create(OrderResource)  → orders.customer_email NULL
checkout.order.save.after:
   Shop\Listeners\Order (binding → OrderConDestinatarioVerificado) → omite + evento omitida   ✔ sin TypeError
   Admin\Listeners\Order → correo al admin                                                     ✔
   Payment GenerateInvoice → sales.invoice.save.after → InvoiceConDestinatarioVerificado omite ✔
   AtribuirPedidoCreado (VentasAsesor)                                                          ✔
```

## 24. Flujo cliente que después obtiene email

RT-04 **no** implementa este flujo; solo verifica que no quede bloqueado.

```text
Cliente comercial (email NULL, password NULL)
   │ da su email
   ▼
AgregarEmailClienteComercialService (futuro)
   - trim, formato, unicidad (email, channel_id)          → si existe: se devuelve el titular como posible duplicado, sin fusionar
   - UPDATE del MISMO customers.id (CustomerRepository::update) + customer.update.before/after
   - is_verified sigue en 0 hasta verificar
   - pedidos históricos: NO se tocan (snapshots con customer_email NULL; decisión de conservar la historia tal como fue)
   - pedidos futuros: llevarán el email (Cart::createCart copia customer->email)
   - NO se ejecuta syncNewRegisteredCustomerInformation automáticamente (P-13)
   │ opcional
   ▼
Activar cuenta ecommerce (futuro)
   - exige email verificado (enlace o código)
   - contraseña: el cliente la define con el broker de reset `customers` (Password::broker) → sin credenciales en texto plano
   - is_verified = 1; a partir de aquí login, reset y notificaciones de la tienda funcionan con el core
   - si se decide (P-13): syncNewRegisteredCustomerInformation, ahora con email NO nulo, enlaza pedidos invitados con ese email
```

**Qué garantiza que esto sea posible:**

- es el mismo `Customer`: pedidos, direcciones y atribuciones cuelgan de `customers.id`;
- `email` pasa de NULL a un valor sin conflicto con otros NULL;
- `password` pasa de NULL a un hash;
- ninguna tabla de RT-04 depende del email.

## 25. Contrato para Fase 03 (pregunta 6)

La Fase 03 recibe de RT-04 exactamente esto (interfaces estables, DTOs serializables):

| Operación | Firma | Garantía |
|---|---|---|
| `normalizarTelefono` | `NormalizadorTelefono::normalizar(?string $valor, ?string $pais = null): TelefonoNormalizado` | Determinista, sin efectos |
| `buscarCliente` | `BuscarClienteComercialService::buscar(ConsultaClienteComercial): ResultadoBusquedaClientes` | Solo lectura; nunca selecciona |
| `crearCliente` | `CrearClienteComercialService::crear(DatosClienteComercial, ActorComercial): ResultadoCreacionCliente` | Grupo válido, email NULL o real, teléfono indexado; no crea con duplicados sin confirmar |
| `resolverCliente` | `ResolverClienteComercialService::resolver(int $customerId, ?TelefonoNormalizado $declarado = null): ClienteComercialResuelto` | Cliente existente, activo, no suspendido y con **grupo apto actual** |
| `gruposDisponibles` | `GruposComercialesDisponibles::listar(): Collection<CustomerGroup>` | Sin preselección |
| `direccion` | `ValidadorDireccionComercial::validar(array): DireccionComercial` → `->paraCarrito()` | Formato exacto de `Cart::saveAddresses()`, email NULL admitido |
| `guardarDireccion` | `GuardarDireccionClienteComercialService::guardar(int, DireccionComercial, bool)` | Dirección de cliente sin email obligatorio |
| Notificaciones | Transparente (binding) | Un pedido con email NULL no lanza `TypeError` |

**Responsabilidades.**

| Tema | RT-04 | Fase 03 |
|---|---|---|
| Identidad del cliente | Buscar, crear y resolver | Pedir un `ClienteComercialResuelto` antes de vender |
| Grupo | Validar en el alta y en la resolución | Abrir `durante()` solo con un cliente resuelto |
| Precio | — | RT-03 (`durante`, `collectTotals`) |
| Dirección | VO, validador y dirección guardada | `Cart::saveAddresses()` con `paraCarrito()` |
| Email en el pedido | Garantizar NULL (nunca `""`) | Pasarlo tal cual |
| Notificaciones | Guard | — |
| Cambiar el cliente del borrador | Resolver al nuevo cliente | `CambiarClienteDelBorradorService`: bloquear el carrito (`lockForUpdate`) → reescribir `customer_id`, `customer_first/last_name` y `customer_email` (NULL) → retirar direcciones `cart_*` y exigir las del nuevo cliente → `resetShippingMethod` y retirar el pago → `durante(cart, nuevoCliente)` + `collectTotals()` (RT-03 reprecia ítems, descuentos y totales) → validar consistencia antes de confirmar (RT-03 T4) |
| Crear el pedido | — | `CrearPedidoComercialService` |

## 26. Contrato futuro para POS

El POS usa **los mismos servicios**:

- búsqueda por teléfono o nombre en el mostrador;
- alta rápida con grupo explícito;
- resolución antes de cobrar;
- canal de captura `pos` en la procedencia.

La venta sin cliente identificado (consumidor final) es una **decisión de negocio POS pendiente**
(D-34): RT-04 no la resuelve y no usa invitado como fallback comercial.

## 27. Contrato futuro para API

Endpoints finos, en la Fase 12, que solo traducen HTTP ↔ DTO:

- `POST /api/ventas-asesor/clientes/buscar` → `ResultadoBusquedaClientes::toArray()`;
- `POST /api/ventas-asesor/clientes` → `ResultadoCreacionCliente`, con `409 requiere_confirmacion`;
- `GET /api/ventas-asesor/clientes/{id}/resolver`.

Requisitos:

- idempotencia por clave de solicitud (D-08);
- autenticación del integrador;
- ACL;
- enmascarado por defecto (P-10).

La API no contiene reglas propias.

## 28. Contrato futuro para WhatsApp

- El número de WhatsApp entrante se normaliza y se usa como **criterio de búsqueda**, **nunca**
  como identidad. Varias coincidencias → se devuelve `multiples_coincidencias` al asesor o al
  orquestador.
- Guardar ese número como teléfono del cliente (`origen = whatsapp`, `es_whatsapp = 1`) solo
  ocurre tras una elección explícita.
- Las notificaciones omitidas por falta de email emiten
  `ventas_asesor.notificacion_cliente.omitida`, que un futuro notificador de WhatsApp podrá
  escuchar sin tocar RT-04.

## 29. Contrato futuro para n8n/IA

```text
Voz/Texto → IA → IntencionDeVenta { cliente:{nombre?, telefono?, email?}, lineas:[{producto, color, talla, cantidad}] }
         → n8n → API comercial Laravel
                  → NormalizadorTelefono → BuscarClienteComercialService → (humano/regla) → Resolver | Crear
                  → Fase 03: variantes, stock, precio (RT-03), pedido
```

La IA y n8n **no**:

- resuelven Customer;
- deciden IDs, grupo ni precio;
- consultan tablas;
- crean Order.

Si la búsqueda es ambigua, el flujo se detiene y pide selección humana.

## 30. Riesgos

| # | Riesgo | Mitigación |
|---|---|---|
| R1 | Un upgrade de Bagisto cambia firma, nombre o ubicación de un listener sustituido → vuelve el `TypeError` o hay un error fatal | Pruebas de contrato (§35); revisión obligatoria en cada upgrade |
| R2 | Correos de RMA y desistimiento desde controladores con cliente sin email → 500 (N7) | Documentado para la Fase 07; RMA no habilitado para estos clientes hasta entonces |
| R3 | Con cola asíncrona (futuro), un `TypeError` no guardado iría al worker como job fallido | El guard actúa **antes** de encolar: cubre la cola síncrona y la asíncrona |
| R4 | `syncNewRegisteredCustomerInformation` con email NULL absorbe pedidos ajenos (N10) | Regla explícita + prueba; solo se invoca con email no nulo |
| R5 | Grupo NULL posterior (SQL, `SET NULL`) → precio de invitado en el panel de Bagisto | La resolución revalida; el panel de Bagisto sigue como adaptador temporal (RT-03) |
| R6 | Carrera en U2 sobre `customers.phone` | Un reintento con `phone = NULL` + advertencia |
| R7 | Formatos mixtos en `customers.phone` (históricos nacionales frente a E.164 nuevos) | La búsqueda solo usa el índice normalizado |
| R8 | El panel de Bagisto exige email para editar un cliente comercial (N11) | Limitación aceptada; edición comercial propia en una fase posterior |
| R9 | Remitente de correo no configurado en testing → `TypeError` en el `from` | Las pruebas fijan `config('mail.from.*')` (como en RT-04A); la configuración del entorno queda para el usuario |
| R10 | Migraciones del módulo aplicadas junto con migraciones ajenas | `--path` limitado a los archivos del módulo |
| R11 | Comentario de pedido con «notificar al cliente» marcado en un pedido sin email → omisión silenciosa para el admin | Log y evento; aviso en la UI futura (deuda) |

## 31. Deuda técnica

- Búsqueda parcial por sufijo y por nombre sin índice (barrido): aceptable hoy; FULLTEXT o
  columna de dígitos invertidos si crece.
- Indexar teléfonos de direcciones guardadas (`direccion_cliente`) → B2.
- Normalizador sin metadatos internacionales → librería (P-12).
- Adaptador del panel de Bagisto (RT-03) y edición del cliente en el panel: siguen exigiendo
  email mientras no exista la pantalla propia.
- RMA y desistimiento sin guard (N7).
- Aviso en la UI cuando se omite una notificación.

## 32. Alternativas descartadas

| Alternativa | Motivo |
|---|---|
| `ContactoComercial` paralelo | D-RT04-01: identidad duplicada y sincronización permanente |
| Email `""`, sintético o compartido | D-RT04-02: rompe la unicidad, pierde correos en silencio y mezcla pedidos |
| Cliente invitado | Pierde grupo de precios e historial (06B) |
| `customers.telefono_normalizado` | DDL sobre el core, un solo teléfono (§9.1) |
| Normalizar `customers.phone` en sitio / quitar su UNIQUE | Viola D-RT04-09 o toca el esquema del core |
| UNIQUE global sobre el teléfono normalizado | D-RT04-09 |
| Desregistrar listeners con `Event::forget` | Borra todos los listeners del evento |
| Listener de `MessageSending` | Llega después del `TypeError` (N2) |
| Decorar el mailer y atrapar `TypeError` | Oculta errores reales en todo el sistema |
| Modificar `CartAddressRequest` o el controlador del admin | D-RT04-10 |
| Disparar `customer.registration.after` en el alta comercial | No es un registro ecommerce; envía correo al cliente (N4) |

## 33. Decisiones pendientes

> **Actualizado — §36.20.** Resueltas o superadas: P-01 (U1/U2 superado: principal en
> `customers.phone`), P-05 (teléfono), P-07 (direcciones siguen en B2), P-12 (sin librería en B1a),
> P-14 (B1a + B1b adoptado). Aplazadas: P-04 (procedencia) y P-11 (ACL, sin consumidor). El resto
> sigue pendiente.
>
> **Actualizado — §37.10 (RT-04B1b):** P-03, P-06, P-08 y P-10 quedan **cerradas para RT-04**.
> P-09 se aplaza con evidencia del core. P-02 quedó aplicada (invitado prohibido, §36.15).

| Id | Pregunta | Recomendación |
|---|---|---|
| P-01 | `customers.phone` en el alta comercial: U1 (siempre NULL) o U2 (E.164 si está libre) | U2 |
| P-02 | ¿Prohibir el grupo invitado en el alta comercial? ¿Hay casos legítimos? | Prohibir |
| P-03 | Grupos habilitados (todos menos invitado o lista explícita) y grupo por defecto para WhatsApp | Sin valor por defecto; lista vacía = todos menos invitado |
| P-04 | ¿Incluir `bylopez_va_customer_origins` (procedencia y confirmación de duplicado) en B1? | Sí |
| P-05 | ¿Qué coincidencias exigen confirmación con motivo? | Teléfono exacto: confirmar; email: rechazar; nombre: solo avisar |
| P-06 | ¿Código postal obligatorio en la dirección comercial? (el core lo exige) | Mantener la paridad con el core hasta decidir |
| P-07 | ¿Indexar direcciones guardadas en B1? | No (B2) |
| P-08 | Bienvenida por correo cuando el cliente comercial tiene email | No |
| P-09 | Clientes suspendidos o inactivos en la búsqueda | Mostrarlos marcados y bloquear su resolución |
| P-10 | Visibilidad y enmascarado: ¿todos los asesores ven todos los clientes?, ¿qué ve la API o la IA? | Panel completo con permiso; API e IA enmascaradas |
| P-11 | Nuevas claves ACL `ventas_asesor.clientes.search` y `ventas_asesor.clientes.create` (cambian el conteo de 23) | Sí |
| P-12 | Librería libphonenumber | Más adelante, detrás de la interfaz |
| P-13 | ¿Enlazar pedidos invitados al activar la cuenta ecommerce? | Decidir en esa fase; nunca con email NULL |
| P-14 | ¿B1 en un solo paso o en B1a (normalizador, tabla, guard, grupo) + B1b (búsqueda, alta, resolución, dirección)? | B1a + B1b |
| P-15 | Aplicar las migraciones en `lpz_saas` | Solo con autorización expresa y `--path` |

## 34. Plan de implementación RT-04B1 (tras aprobación)

**B1a — cimientos.**

1. Config `ventasasesor.php` y enums de teléfono.
2. `NormalizadorTelefono` (interfaz + básica) y `TelefonoNormalizado`, con binding.
3. M1, más M2 si se aprueba P-04, solo en `lpz_saas_testing`. Modelo, contrato y repositorio.
4. Guard de notificaciones: `DestinatarioCorreo`, 5 subclases y bindings.
5. `ValidadorGrupoComercial` y `GruposComercialesDisponibles`.
6. Indexador, listener (`customer.create.after` y `customer.update.after`) y comando de backfill
   con dry-run.

**B1b — servicios de identidad.**

7. `BuscarClienteComercialService` y sus DTOs.
8. `DireccionComercial`, `ValidadorDireccionComercial` y `GuardarDireccionClienteComercialService`.
9. `CrearClienteComercialService`: transacción, `afterCommit` y duplicados.
10. `ResolverClienteComercialService`.
11. Claves ACL si se aprueba P-11. **Sin rutas, vistas, pedidos ni API.**

Cada paso lleva su prueba en verde antes del siguiente, más Pint y la suite de VentasAsesor
completa. Webkul sigue con 0 cambios.

## 35. Plan de pruebas

Todas en `lpz_saas_testing`, con guard, `Mail::fake()` o el mailer `array`, y remitente fijado
en memoria.

| Grupo | Pruebas |
|---|---|
| Normalizador | Las 4 formas equivalentes → mismo E.164; `00` → `+`; internacional no verificado; fijo `60x`; 7 dígitos → ambiguo; letras o `ext` → inválido; vacío; el original se conserva |
| Índice | Mismo número en 2 clientes permitido; mismo número dos veces en 1 cliente rechazado; CASCADE al borrar el cliente; un solo principal |
| Contrato de guard | `$listen` del core sigue apuntando a los métodos sobrescritos; el contenedor resuelve cada subclase; las firmas son compatibles (reflexión) |
| Paridad tienda | Para **cada** evento de §15.1 con email válido → el mismo mailable del core encolado al mismo destinatario (`Mail::assertQueued`) |
| Sin email | Cada evento con `customer_email` NULL, `""` o inválido → nada encolado al cliente, sin excepción, evento `omitida` emitido; los correos de admin se siguen encolando |
| Pedido de punta a punta | Cliente comercial sin email → carrito con `durante()` → `saveAddresses` con email NULL → `OrderRepository::create` → pedido con `customer_email` NULL, sin reintentos, atribución creada, factura automática sin `TypeError` |
| Factura, envío y reembolso | Sobre un pedido sin email: se crean sin excepción y la transacción cierra |
| Grupo | Requerido, inexistente, invitado, no habilitado (con config), válido; resolución con grupo cambiado a NULL → no apto |
| Alta | Email NULL (dos clientes sin email conviven); `""` → NULL; email duplicado → rechazado con candidato; teléfono inválido o ambiguo → rechazado; duplicado sin confirmación → `requiere_confirmacion` sin escritura; con confirmación → creado y trazado; `customer.create.after` sí, `customer.registration.after` **no**; fallo de listener tras commit → cliente persistido + advertencia; password NULL (no puede iniciar sesión); U2: `phone` escrito o NULL según colisión |
| Búsqueda | Por teléfono en cualquier formato; varios clientes con el mismo teléfono → `multiples_coincidencias`; nombre; email; nunca selecciona; límite |
| Resolución | Suspendido, inactivo, sin grupo o invitado → no apto; apto → DTO completo |
| Dirección | Email NULL aceptado; `paraCarrito()` aceptado por `Cart::saveAddresses()` y deja `cart.customer_email` NULL (no `""`) |
| Sync | `syncNewRegisteredCustomerInformation` nunca se invoca con email NULL |
| Indexador | Dry-run sin escrituras; `--persistir` idempotente; no toca las filas `alta_comercial` |
| Mutaciones | Quitar el guard → vuelve el `TypeError`; quitar la validación de grupo → alta sin grupo detectada; usar `""` → la prueba de unicidad falla |
| Regresión | Suite completa de VentasAsesor, incluidas las 14 caracterizaciones de RT-04A, que **siguen siendo válidas**: documentan el core (p. ej. `prepareMail` directo sobre `new ShopOrderListener` sigue lanzando `TypeError`, porque no pasa por el contenedor) |

---

## 36. RT-04B1a — implementación y evidencia real (2026-09-26)

**Estado: CERRADO** (aprobado 2026-09-26; incluido en el commit de cierre, sin push). RT-04B1b y la Fase 03 no
están iniciados. Webkul tiene 0 cambios. `lpz_saas` solo se leyó (SELECT).

Etiquetas: **HALLAZGO** (evidencia del código o de una prueba), **DECISIÓN**, **IMPLEMENTADO**,
**PENDIENTE**, **SUPERADO**, **DEUDA**.

### 36.1 Decisión funcional D-RT04-PHONE-01

> «Un teléfono normalizado pertenece a un único Customer. Un Customer puede tener un teléfono
> principal y teléfonos secundarios. La integración debe preservar la compatibilidad con el uso real
> de `customers.phone` de Bagisto, determinado mediante auditoría del código.»

**Intención inicial, registrada como HIPÓTESIS:** «`customers.phone` contendrá siempre el
principal». **Resultado de la auditoría (§36.2–§36.5): se confirma → DECISIÓN.** La evidencia
muestra que `customers.phone` es el único teléfono del Customer que conoce Bagisto. No lo usa
ningún proceso de venta, pero sí la ficha, el perfil de la tienda, el DataGrid y su exportación,
el importador y el PDF de GDPR. Mantener allí el principal evita una segunda fuente de verdad
visible para el core.

Esta decisión **supera** D-RT04-09 («normalizado no UNIQUE»): ahora es UNIQUE.

### 36.2 HALLAZGO — esquema real de `customers.phone`

| Aspecto | Realidad | Evidencia |
|---|---|---|
| Tabla y columna | `customers.phone` | `Customer/Database/Migrations/2018_07_24_082930_create_customers_table.php` |
| Tipo | `varchar(255)` | `information_schema` en `lpz_saas` |
| NULL | Sí | ídem |
| Índice | `customers_phone_unique (phone)`, **UNIQUE global** (no por canal) | `SHOW INDEX FROM customers` |
| Modelo | `Customer::$fillable` incluye `phone`; sin casts, accessors ni mutators de teléfono | `Customer/Models/Customer.php:50-66` |
| Repositorio | `CustomerRepository` no tiene lógica de teléfono | `Customer/Repositories/CustomerRepository.php` |
| Normalizador o librería existentes | **Ninguno.** Solo la regla `PhoneNumber` (`^\+?\d+$`); ninguna librería en `vendor` | `Core/Rules/PhoneNumber.php`; `ls vendor` |
| Datos de desarrollo | 10 de 15 clientes con teléfono: todos de 10 dígitos que empiezan por 3, sin colisiones al normalizar. 1 caso en que el teléfono de la ficha aparece en una dirección guardada de **otro** cliente (dirección, no ficha) | SELECT agregado |

### 36.3 HALLAZGO — matriz de consumidores del teléfono

| Componente | Campo que lee | Momento | Obligatorio | Impacto si `customers.phone` es NULL | Evidencia |
|---|---|---|---|---|---|
| Alta de cliente en el admin | `customers.phone` | store | Opcional; `unique` + `PhoneNumber`; `''` → NULL | Ninguno | `Admin/…/Customers/CustomerController.php:88,108-110` |
| Edición de cliente en el admin | `customers.phone` | update | Opcional; `unique` + `PhoneNumber` | Ninguno | `CustomerController.php:148` |
| Registro en la tienda | — | — | No captura teléfono | Ninguno | `Shop/Http/Requests/Customer/RegistrationRequest.php:28-33` |
| Perfil de la tienda (cuenta) | `customers.phone` | Editar perfil | **Obligatorio** + `unique` + `PhoneNumber` | Para guardar el perfil hay que capturarlo (solo clientes con login) | `Shop/Http/Requests/Customer/ProfileRequest.php:40`; `profile/edit.blade.php:127-129` |
| DataGrid de clientes | `customers.phone` | Listado | — | Muestra «N/A». Filtrable, **no** buscable | `Admin/DataGrids/Customers/CustomerDataGrid.php:47,64,122-127`; `customers/index.blade.php:179` |
| Exportación del DataGrid | `customers.phone` | Exportar | — | Columna vacía | `DataGrid/Column.php:58` (`exportable` por defecto) |
| Ficha del cliente (admin) | `customers.phone` y `address.phone` | Ver | — | «N/A» | `customers/view.blade.php:209,282`; `view/edit.blade.php:114-117` |
| Importador de clientes | `customers.phone` | Importar | Opcional + `PhoneNumber`; duplicado **dentro del archivo** | Escribe con `update` por id o **insert masivo** (sin eventos) | `DataTransfer/Helpers/Importers/Customer/Importer.php:250,278-291,421-438` |
| PDF de GDPR | `customers.phone` y teléfono de dirección | Solicitud GDPR | — | «NA» | `Shop/…/gdpr/pdf.blade.php:202,282` |
| Búsqueda de clientes (admin) | email y nombre | Buscar | — | El teléfono **no** participa | `CustomerController.php:283-285` |
| Dirección guardada del cliente | `addresses.phone` (`address_type = customer`) | Crear/editar | **Obligatorio** + `PhoneNumber` | Independiente de la ficha | `Admin/Http/Requests/AddressRequest.php:38`; `Shop/…/Customer/AddressRequest.php:38` |
| Dirección del carrito (checkout) | `addresses.phone` (`cart_*`) | `saveAddresses` | **Obligatorio** en los Request | Nunca se copia de la ficha | `Checkout/Cart.php:444-470,485-541`; `CartAddressRequest` (Admin l. 59, Shop l. 58) |
| Carrito | — | — | La tabla `cart` **no tiene** teléfono | — | Prueba `Rt04b1aCaracterizacionTelefonoCoreTest` |
| Pedido | — | — | La tabla `orders` **no tiene** teléfono | — | ídem |
| Dirección del pedido | `addresses.phone` (`order_*`) | Crear pedido | Copia de la dirección del carrito | — | `Sales/Transformers/OrderAddressResource.php:30`; `OrderRepository.php:60-64` |
| Factura | `order.billing_address.phone` (y la de envío en el PDF) | Vista y PDF | — | Nunca lee la ficha | `shop::customers.account.orders.pdf:382,398` (admin y tienda usan la misma vista, `Admin/…/InvoiceController.php:150-151`) |
| Envío | `shipment.address` = dirección de envío del pedido (`order_address_id`) | Crear | — | Nunca lee la ficha | `Sales/Repositories/ShipmentRepository.php:59`; `Shipment::address()` |
| Correos de pedido, factura, envío y reembolso (admin y tienda) | `order.billing_address.phone` / `shipment.order.billing_address.phone` | Plantilla | — | Nunca leen la ficha | `Shop/…/emails/orders/*.blade.php`; `Admin/…/emails/orders/*.blade.php` |
| Pasarelas de pago | `cart.billing_address.phone` | Pago | — | Nunca leen la ficha | `Paypal/…/SmartButtonController.php:131-136`; `Razorpay/…/RazorpayPayment.php:235`; `PayU/…/PayU.php:137` |
| Reservas | `addresses.phone` | Listado | — | — | `BookingProduct/Repositories/BookingRepository.php:98` |
| APIs de dirección de la tienda | `address.phone` | Cuenta | — | — | `Shop/Http/Resources/AddressResource.php:35`; `Shop/…/API/AddressController.php:49,98` |
| Eventos create/update | `customer.create.*` / `customer.update.*` | Admin, tienda, importador | — | El importador **no** los dispara | `CustomerController.php:113,125,167,171,300,306`; `Shop/…/CustomerController.php:85,92`; `RegistrationController.php:96-98` |

**Conclusión de la matriz:**

- **`customers.phone`:** ningún proceso operativo (carrito, pedido, factura, envío, pagos,
  correos) lo lee. Con NULL todo sigue funcionando (probado: factura y envío con ficha NULL).
  Su valor es de **identidad visible**: ficha, perfil, listado, exportación, importación y GDPR.
- **Teléfono de las direcciones:** es el teléfono **operativo** (entrega, factura, pasarelas,
  correos). Viaja como snapshot: dirección del carrito → dirección del pedido.

### 36.4 HALLAZGO — Customer → Cart → Order → Invoice/Shipment

```text
customers.phone ──(no se copia a ningún sitio)──  ficha / perfil / listado / exportación / GDPR

addresses(customer).phone ──(el asesor la elige o la escribe)──► addresses(cart_billing|cart_shipping).phone
     ──OrderAddressResource──► addresses(order_billing|order_shipping).phone
          ├─ Invoice: PDF y vista → order.billing_address / order.shipping_address
          ├─ Shipment: order_address_id → order_shipping
          └─ Correos y pasarelas → billing_address
```

HALLAZGOS nuevos (probados):

- **Factura:** `InvoiceRepository::create` envía `order_address_id` (l. 69), pero **`invoices` no
  tiene esa columna** en ninguna de las dos bases. Eloquent la descarta en silencio y
  `Invoice::address()` siempre es NULL. La factura toma la dirección de `order.billing_address`.
- **Carrito:** `Cart::updateOrCreateShippingAddress` no guarda dirección de envío si el carrito no
  tiene ítems almacenables (l. 490).
- **Core:** `Shop\Listeners\Invoice`, `Shipment` y `Refund` hacen `$entidad->query()->update(['email_sent' => 1])`,
  que marca **todas** las filas de la tabla. Lo hacen además aunque el correo no salga.

Para la logística futura: **el teléfono de entrega es el de la dirección de envío del pedido**; el
de facturación, el de la dirección de facturación. `customers.phone` no participa.

### 36.5 DECISIÓN — ¿`customers.phone` conserva el principal? **Sí**

| Criterio | Evidencia | Peso |
|---|---|---|
| Es el único teléfono del Customer en la UI y en los datos del core | Matriz §36.3 | Alto |
| El perfil de la tienda lo exige y lo valida como único | `ProfileRequest.php:40` | Medio: para cuando el cliente active su cuenta ecommerce |
| El importador y la exportación trabajan con él | `Importer.php`; `CustomerDataGrid` | Medio |
| Su UNIQUE global ya expresa «un número, un cliente» (en cadena exacta) | `customers_phone_unique` | Coherente con D-RT04-PHONE-01 |
| Ningún proceso operativo lo lee | §36.3–§36.4 | No obliga, pero tampoco impide |
| E.164 pasa la regla `PhoneNumber` del core | Prueba de compatibilidad | Sin conflicto con las validaciones |

No encontré una estrategia superior que evite la segunda fuente de verdad: guardar el principal
**solo** en la tabla propia dejaría la ficha de Bagisto vacía o desfasada. Guardarlo en ambos
sitios exige una regla explícita, que es esta:

- **Fuente de verdad del principal: `customers.phone`.**
- La fila `principal = 1` de la tabla es su **reflejo normalizado**, derivable en todo momento.
- **Los secundarios existen solo en la tabla propia.**

### 36.6 IMPLEMENTADO — `bylopez_va_customer_phones`

Migración `2026_09_26_000005_create_bylopez_va_customer_phones_table.php`, aditiva; no toca tablas
del core.

| Columna | Tipo | NULL | Problema que resuelve | Quién escribe | Quién lee |
|---|---|---|---|---|---|
| `id` | bigint PK | no | — | — | — |
| `customer_id` | int unsigned, FK `customers.id` **CASCADE** | no | Todo teléfono resuelve a un Customer | Indexador (B1a); servicio de teléfonos (B1b) | Búsqueda (B1b) |
| `telefono_normalizado` | varchar(16), **UNIQUE** | no | Búsqueda por formatos equivalentes; un número → un Customer | ídem | ídem |
| `telefono_original` | varchar(255) | no | Volver a normalizar si cambian las reglas o se adopta una librería; detectar divergencia exacta con la ficha; conservar lo escrito en los secundarios | ídem | Indexador |
| `principal` | boolean, **1 o NULL** | sí | Un solo principal por Customer, garantizado por la base | ídem | Indexador y búsqueda |
| `created_at`, `updated_at` | timestamp | sí | Auditoría mínima | Eloquent | — |

| Columna propuesta en B0 | Decisión | Motivo |
|---|---|---|
| `pais_iso`, `tipo`, `estado_normalizacion` | **No creadas** | Se derivan de `telefono_normalizado` con el normalizador. Solo se indexan números buscables, así que el estado siempre sería válido |
| `es_whatsapp` | **No creada** | Hoy no tiene semántica ni consumidor (WhatsApp no está integrado) |
| `origen` | **No creada** | Derivable: el principal viene de `customers.phone` y los secundarios del servicio comercial |
| `created_by_admin_id` | **No creada** | Sin consumidor en B1a; se reconsidera en B1b si la edición de teléfonos lo necesita |

**Índices e invariantes, protegidos por la base:**

- `bvacp_normalizado_unique (telefono_normalizado)`: un número normalizado → **un** Customer.
  Cubre principal y secundarios de todos los clientes.
- `bvacp_customer_principal_unique (customer_id, principal)`, con `principal` en 1 o NULL (nunca
  0): **un solo principal por Customer**. MySQL 8 y MariaDB 10.4 admiten varios NULL en un índice
  único, así que los secundarios no chocan. El mutador del modelo convierte cualquier falso en
  NULL. Se descartó una columna generada: el patrón 1/NULL es portable y no depende de funciones
  del motor.
- FK **CASCADE**: es un índice derivado. RESTRICT impediría borrar clientes desde el panel de
  Bagisto. Es una excepción deliberada frente a las atribuciones, que usan RESTRICT, y queda
  cubierta en `CierreAuditado02A2Test` (12).

**Ejecución:** se aplicó solo en `lpz_saas_testing` (batch 12, con `--path` del archivo concreto y
`--pretend` previo). En `lpz_saas` **no existe**.

### 36.7 IMPLEMENTADO — principal y secundarios

| Operación | Garantía | Estado |
|---|---|---|
| Un solo principal | UNIQUE (customer_id, principal) | IMPLEMENTADO (base) y probado |
| N secundarios | `principal` NULL | IMPLEMENTADO y probado |
| Número de otro Customer | UNIQUE del normalizado → `TelefonoPerteneceAOtroClienteException` con el titular; nunca se reasigna | IMPLEMENTADO y probado |
| Promover un secundario a principal | En una transacción: `lockForUpdate` sobre las filas del cliente → principal actual a NULL → secundario a 1 → escribir su E.164 en `customers.phone` | **PENDIENTE B1b** (la estructura lo permite; el orden evita violar el índice) |
| Cambiar el principal por un número nuevo | Igual, con alta de la fila nueva | PENDIENTE B1b |
| Eliminar el principal | Promover otro o dejar `customers.phone` NULL, en la misma transacción | PENDIENTE B1b |

### 36.8 DECISIÓN — sincronización con `customers.phone`

- **Escrituras del módulo (B1b):** un único servicio explícito escribirá **en la misma transacción**
  `customers.phone` (E.164) y la fila principal. Si choca con el UNIQUE, revierte las dos.
- **Escrituras fuera del módulo** (edición del admin, perfil de la tienda, importador) pueden hacer
  divergir el reflejo. No se sincronizan en caliente (§36.9); el indexador las **detecta**
  (DIVERGENTE) y, con `--persistir`, re-deriva la fila desde la ficha, que es la fuente.
- **Históricos:** `customers.phone` **nunca** se modifica. Se indexan tal cual (el original se
  guarda) y se normalizan en la tabla.

### 36.9 DECISIÓN — sin listeners automáticos de Customer

| Pregunta | Evidencia | Consecuencia |
|---|---|---|
| ¿Quién crea Customers fuera del módulo? | Alta del admin, registro de la tienda, importador | Tres caminos |
| ¿Quién modifica `phone`? | Edición del admin, perfil de la tienda, importador | ídem |
| ¿Qué eventos se disparan? | Admin y tienda: `customer.create.after` / `customer.update.after` **después** de guardar y fuera de transacción. Importador: `customerRepository->insert()` masivo, **sin eventos** (y sin eventos de Eloquent) | Un listener **no** vería las importaciones: sincronización parcial |
| Si el número ya es de otro cliente en el índice | El listener tendría que fallar después de que el core ya guardó (500 con el dato persistido) o callar | Divergencia inevitable, por una vía o por la otra |

**Decisión:** no implementar listeners. Se usan un **servicio explícito** (B1b) y el **indexador**
con detección de divergencias (B1a). Es más predecible y no introduce fallos en los flujos del
core.

### 36.10 IMPLEMENTADO — normalizador

- `Contracts\NormalizadorTelefono` → `Support\Telefono\NormalizadorTelefonoBasico`, enlazado en
  `VentasAsesorServiceProvider`.
- Devuelve `DataObjects\TelefonoNormalizado`. Enums: `EstadoNormalizacionTelefono` y `TipoTelefono`.
- Configuración: `ventasasesor.telefono.pais_por_defecto = CO`.

**Reglas:**

- Solo quita separadores de formato (espacio, NBSP, `.`, `-`, `()`, `/`). Cualquier otro carácter
  invalida el valor.
- `00` → `+`.
- Con `+`: de 8 a 15 dígitos. `+57` aplica las reglas de CO; otro código de país se conserva como
  internacional no verificado.
- Sin `+` y con país CO:
  - móvil: `3` + 9 dígitos;
  - fijo: `60[1-8]` + 7 dígitos;
  - `57` + número nacional válido;
  - 7 u 8 dígitos (fijo antiguo) → ambiguo;
  - cualquier otro → inválido.
- Sin `+` y con un país sin reglas → ambiguo.

**Nunca inventa** indicativo, ciudad, extensión ni país.

| Entrada | Salida | Estado |
|---|---|---|
| `3001230045` · `300 123 0045` · `+57 300 123 0045` · `573001230045` | `+573001230045` | estructura_reconocida (móvil, CO) |
| `0057 300 555 0101` · `+57 (300) 555-0101` · `300.555.0101` | `+573005550101` | estructura_reconocida |
| `601 234 5678` | `+576012345678` | estructura_reconocida (fijo) |
| `2345678` · `1 234 5678` · `+57 1 234 5678` | — | ambiguo (`fijo_sin_indicativo_vigente`) |
| `+1 415 555 2671` · `0052 55 1234 5678` | `+14155552671` · `+525512345678` | internacional_no_verificado |
| `+1234` · `+1234567890123456` · `00` | — | invalido (`longitud_internacional`) |
| `300 555 0101 ext 12` · `300-555-0101#` · `+57+300…` | — | invalido (`caracteres_no_permitidos`) |
| `12345` · `4005550101` | — | invalido (`numeracion_colombiana_no_reconocida`) |
| `null` · `''` · `'   '` | — | vacio |

**Límites (DEUDA):**

- Los internacionales solo se validan en longitud: no se verifica el plan de numeración de cada
  país ni el tipo de línea.
- Validarlos de verdad exige `giggsey/libphonenumber-for-php` (o su versión `-lite`). No se
  agregó. Cambiar de implementación solo requiere otro binding de la interfaz; los datos no
  cambian, porque ambas producen E.164.

### 36.11 IMPLEMENTADO — indexador histórico

- **Comando:** `ventas-asesor:indexar-telefonos`, con dry-run por defecto y las opciones
  `--persistir`, `--customer=` y `--limite=`. `--dry-run` y `--persistir` juntos → código 2.
- **Implementación:** servicio `Services\Clientes\IndexadorTelefonosClientesService`.
- **Seguridad:** nunca modifica `customers.phone`, escribe una transacción por cliente y muestra
  los teléfonos enmascarados (`***0101`).

| Resultado | Cuándo | Persistente |
|---|---|---|
| `indexable` / `indexado` | Normalizable y sin titular | Crea el principal |
| `ya_indexado` | La fila principal coincide con la ficha (normalizado y original) | Nada |
| `invalido` / `ambiguo` | La ficha no se normaliza | Nada |
| `duplicado` | El mismo número normalizado en **varias fichas** (p. ej. `300…` y `+57300…`) | Nada: no se elige titular |
| `conflicto` | El número ya está indexado para **otro** Customer, o es un secundario del mismo | Nada |
| `divergente` / `reindexado` | La ficha cambió fuera del módulo (número u original distinto, ficha sin teléfono o no normalizable) | Re-deriva o retira la fila principal |
| `error` | Fallo inesperado en ese cliente | Sigue con los demás; log sin PII |

**No ejecutado en `lpz_saas`:** la tabla no existe allí y no se autorizó escribir. Según los
SELECT agregados, un dry-run daría 10 `indexable` y 0 conflictos. Esto es una **estimación, no
una ejecución**.

### 36.12 HALLAZGO — auditoría final de notificaciones (código de esta versión)

| Evento | Listener registrado | Provider | Método que se sustituye | Notificación | Destinatario | Con NULL, sin guard | Transacción |
|---|---|---|---|---|---|---|---|
| `checkout.order.save.after` | `Shop\Listeners\Order@afterCreated` | `Shop/Providers/EventServiceProvider.php:106` | `prepareMail` | `CreatedNotification` | `order.customer_email` | TypeError → rollback y reintentos del pedido (02B0) | La del pedido |
| `sales.order.cancel.after` | `Shop\Listeners\Order@afterCanceled` | Shop:111 | `prepareMail` | `CanceledNotification` | `order.customer_email` | TypeError | — |
| `sales.order.comment.create.after` | `Shop\Listeners\Order@afterCommented` (si `customer_notified`) | Shop:116 | `prepareMail` | `CommentedNotification` | `comment.order.customer_email` | TypeError | — |
| `sales.invoice.save.after` | `Shop\Listeners\Invoice@afterCreated` | Shop:120 | `prepareMail` | `InvoicedNotification` | `order.customer_email` | TypeError **y transacción abierta** (probado) | `InvoiceRepository` (catch `\Exception`) |
| `sales.invoice.send_duplicate_email` | `Shop\Listeners\Invoice@afterCreated($invoice, $email)` | Shop:124 | `prepareMail` | `InvoicedNotification` | email del admin ?? `order.customer_email` | Funciona si el admin escribe uno | — |
| `sales.shipment.save.after` | `Shop\Listeners\Shipment@afterCreated` | Shop:128 | `prepareMail` | `ShippedNotification` | `shipment.order.customer_email` | TypeError | `ShipmentRepository` (catch `\Exception`) |
| `sales.refund.save.after` | `Shop\Listeners\Refund@afterCreated` | Shop:132 | `prepareMail` | `RefundedNotification` | `refund.order.customer_email` | TypeError | `RefundRepository` (catch `\Exception`) |
| `customer.note.create.after` | `Shop\Listeners\Customer@afterNoteCreated` (si `customer_notified`) | Shop:88 | `afterNoteCreated` | `NoteNotification` | `note.customer.email` | TypeError | — |
| `customer.registration.after` | `Shop\Listeners\Customer@afterCreated` | Shop:76 | **no se sustituye** | Verificación o bienvenida | `customer.email` | Inalcanzable sin email: la disparan el alta del admin y el registro de la tienda, que exigen email | — |
| `customer.password.update.after` | `Shop\Listeners\Customer@afterPasswordUpdated` | Shop:80 | **no se sustituye** | `UpdatePasswordNotification` | `customer.email` | Inalcanzable sin email (requiere login o reset por email) | — |
| `customer.subscription.after` | `Shop\Listeners\Customer@afterSubscribed` | Shop:84 | **no se sustituye** | `SubscriptionNotification` | email del suscriptor (siempre existe) | — | — |

Los listeners de **Admin** escriben al administrador (`core()->getAdminEmailDetails()`,
`Core.php:884-892`, con fallback a `mail.from.address`) y **no** se sustituyen.

**HALLAZGO de entorno:** en `lpz_saas_testing` no hay `MAIL_FROM_ADDRESS`, así que el correo al
admin es NULL y los listeners de Admin lanzan TypeError. Es la causa principal de los fallos
de base del core (§36.19). En `lpz_saas`, `.env` sí tiene `MAIL_FROM_ADDRESS`, que sirve de
fallback. **Riesgo de despliegue:** un entorno sin remitente ni correo de admin haría fallar la
creación de pedidos por el listener de Admin, con o sin RT-04.

### 36.13 IMPLEMENTADO — extension point demostrado antes de usarlo

Prueba `Rt04b1aPuntoDeExtensionListenersTest` (11 casos), con un **espía**, no con el guard real:

1. El core registra cada listener de correo del Shop como `[Clase, 'método']`, **una sola vez**
   por evento (10 eventos, `Event::getRawListeners()`).
2. Un binding del contenedor hace que el disparo **real** del evento ejecute la subclase.
3. El número de listeners del evento no cambia: no se duplica ninguno.
4. `parent::` ejecuta el cuerpo del core.

La causa es que `Illuminate\Events\Dispatcher::createClassCallable` instancia la clase con
`container->make($clase)` (l. 536).

### 36.14 IMPLEMENTADO — guard de correos al cliente

**Bindings, en `VentasAsesorServiceProvider::registrarGuardDeCorreosAlCliente()`:**

- `Shop\Listeners\Order` → `Listeners\Correo\OrderConDestinatarioVerificado`
- `Shop\Listeners\Invoice` → `InvoiceConDestinatarioVerificado`
- `Shop\Listeners\Shipment` → `ShipmentConDestinatarioVerificado`
- `Shop\Listeners\Refund` → `RefundConDestinatarioVerificado`
- `Shop\Listeners\Customer` → `CustomerConDestinatarioVerificado` (solo `afterNoteCreated`)

**Cómo funciona:**

- **Dónde se engancha:** el trait `OmiteCorreoSinDestinatario` sobrescribe `prepareMail()`, el punto
  en el que el core ya decidió enviar. Resuelve el **mismo** destinatario que el `envelope()` del
  mailable, incluido el email del reenvío manual de factura.
- **Validez:** `Support\Correo\DestinatarioCorreo::motivoDeOmision()` usa el mismo validador que el
  envío (el constructor de `Symfony\Component\Mime\Address`). Nunca omite un correo que el mailer
  habría aceptado. Motivos: `sin_email`, `email_vacio`, `email_invalido`.
- **Omisión:** `Log::info('ventas_asesor.notificacion_cliente.omitida', …)` sin PII (ni email ni
  teléfono) más el evento propio `Events\NotificacionClienteOmitida`, con notificación, entidad, id,
  `order_id`, `customer_id` y motivo. Es el punto de enganche futuro para WhatsApp.
- **Con email válido:** se ejecuta exactamente el `prepareMail` del core, incluido su try/catch.
  - Un fallo de transporte se registra como antes («Error in Sending Email»).
  - Un `Error` no recuperable se propaga como antes.
  - El guard no captura nada.

| Caso (probado) | Email válido | NULL / vacío / espacios / inválido |
|---|---|---|
| Pedido creado | 1 correo al destinatario correcto; sin omisión | 0 correos; **1** omisión con el motivo; sin TypeError con el mailer real |
| `checkout.order.save.after` real (todos los listeners) | — | Todos corren; 1 omisión |
| Cancelado (evento real) | 1 | 1 omisión |
| Comentario con aviso (evento real) | 1 | 1 omisión; sin aviso: nada |
| Factura (`InvoiceRepository` real) | 1 al cliente + 1 al admin | Se crea, **cierra su transacción**, 0 al cliente, 1 al admin, 1 omisión |
| Factura automática (`GenerateInvoice`, contraentrega) | — | Se genera sin error; 1 omisión |
| Reenvío manual de factura | Va al email escrito por el admin aunque el pedido no tenga email | — |
| Envío (`ShipmentRepository` real) | 1 | Se crea; 1 omisión |
| Reembolso (`RefundRepository` real, tras facturar; revisión final) | 1 correo al destinatario correcto; sin omisión | Se crea, `qty_refunded` = 1, **cierra su transacción**, 1 omisión (entidad `Refund`); sin guard: TypeError y transacción abierta |
| Nota del cliente (evento real) | 1 | 1 omisión; sin aviso: nada |
| Fallo de transporte del mailer | El core lo registra; sin omisión | — |
| `Error` del mailer | Se propaga | — |
| Sin guard (binding retirado) | — | La factura lanza TypeError y deja la transacción abierta (caracterización) |

**Mutación:** con los bindings desactivados fallan 21 de las 27 pruebas del guard. Las 6 que
siguen pasando son las de email válido, fallo del mailer y la caracterización sin guard, como
corresponde.

**DEUDA del core, no corregida:** tras una omisión, `Shop\Listeners\Invoice`, `Shipment` y `Refund`
siguen marcando `email_sent = 1`, y en **todas** las filas (§36.4). Corregirlo exigiría reescribir
esos métodos; queda documentado.

**Residual (sin cambios desde B0):** los correos de RMA y de desistimiento se envían desde
controladores. Corresponden a la Fase 07.

### 36.15 IMPLEMENTADO — `ValidadorGrupoComercial`

- **Auditoría:** `CustomerGroup` (`id`, `code`, `name`, `is_user_defined`) y `CustomerGroupRepository`
  sin lógica. **No existe** un concepto de grupo activo.
- **Cómo identifica Bagisto al invitado:** por `code = 'guest'`, con
  `core()->getGuestCustomerGroup()` (`Core.php:731-738`). Los formularios del admin lo excluyen con
  `code <> 'guest'` (`CustomerController.php:71,258`; `OrderController.php:49`). El `code` es
  editable en el admin (`CustomerGroupController::update`, sin protección para grupos del
  sistema).
- **Validador:** `Support\Clientes\ValidadorGrupoComercial::validar(?int)` devuelve el
  `CustomerGroup` o lanza `GrupoComercialInvalidoException` con uno de estos códigos:
  - `grupo_requerido` (NULL, 0 o negativo; sin valor por defecto);
  - `grupo_inexistente`;
  - `grupo_invitado_no_permitido` (compara con el invitado **del core**, no por nombre: un grupo
    llamado «Guest» con otro code es válido);
  - `invitado_no_identificable` (si el core no encuentra su invitado, se falla cerrado).
- **Pendiente:** RT-03 no se modificó. P-02 (prohibir el invitado) se aplicó tal como lo pide
  D-RT04-04 («NO Guest»). P-03 (grupos habilitados y grupo por defecto) sigue abierto: no se
  implementó ninguna lista.

### 36.16 DECISIÓN — procedencia del cliente: **aplazada** (M2 no creada)

| Candidato existente | Qué representa | ¿Sirve como procedencia del Customer? |
|---|---|---|
| `bylopez_va_order_attributions` (`created_by_admin_id`, `advisor_id`, `commercial_owner_type`, `creation_source`) | Propiedad y origen de **cada pedido** | No: un Customer puede no tener pedidos o tener varios de orígenes distintos |
| `bylopez_va_advisor_assignment_logs` | Historial de propiedad de pedidos | No |
| `bylopez_va_attribution_incidents` | Cola operativa de incidencias | No |

El motivo principal de M2 en B0 era trazar el alta **pese a un duplicado confirmado**. Con
D-RT04-PHONE-01 ese caso **ya no existe**. Lo que queda (quién creó al cliente y por qué canal)
no tiene consumidor hoy. **No se crea** para no convertir la tabla en un cajón de sastre; se
reevaluará en B1b junto con `CrearClienteComercialService`.

### 36.17 DECISIÓN — ACL: aplazada

Sin endpoint, UI ni servicio que las consuma, no se crean. Se prevén
`ventas_asesor.clientes.search` y `ventas_asesor.clientes.create`, que se implementarán en B1b o en
la Fase 03 junto con su primer consumidor.

### 36.18 Entrega y transportadoras (solo separación conceptual)

`CLIENTE ≠ DIRECCIÓN ≠ MÉTODO DE ENTREGA ≠ ENVÍO ≠ GUÍA`:

- B1a no guarda nada de entrega.
- El teléfono operativo de la entrega es el de la dirección de envío del pedido (§36.4), no el de
  la ficha.
- «Entrega a domicilio» y «recogida en bodega» podrán diferir. La cadena
  «pedido → método → dirección si aplica → transportadora → tarifa → guía → tracking» se diseñará
  con documentación real de la transportadora.

### 36.19 Pruebas y regresión

| Archivo | Casos | Qué cubre |
|---|---|---|
| `tests/Feature/Rt04b1aPuntoDeExtensionListenersTest.php` | 11 | Extension point con espía |
| `tests/Unit/NormalizadorTelefonoTest.php` | 34 | Normalizador |
| `tests/Feature/Rt04b1aTelefonosClienteTest.php` | 18 | Invariantes de la tabla, compatibilidad con `customers.phone`, indexador y comando |
| `tests/Feature/Rt04b1aValidadorGrupoComercialTest.php` | 8 | Grupo |
| `tests/Feature/Rt04b1aCaracterizacionTelefonoCoreTest.php` | 7 | Teléfono en Cart, Order, Invoice, Shipment y PDF; hallazgos de la factura y de `email_sent` |
| `tests/Feature/Rt04b1aGuardNotificacionesTest.php` | 27 | Guard de notificaciones |
| `tests/helpersRt04b1a.php` | — | Pedido facturable y enviable (mismo armado que los tests del core) |

**Pruebas existentes ajustadas de forma explícita**, no solo en el número:

- `IntegridadAtribucionTest` (22): 5 migraciones y ninguna altera `customers`.
- `CierreAuditado02A2Test` (12): 5 FKs, incluida la excepción CASCADE hacia `customers`.

**Resultados:**

- **VentasAsesor:** 376 passed, 1576 aserciones (antes 271 / 1293). Las 14 caracterizaciones de
  RT-04A siguen en verde. Pint en verde.
- **CorteConfeccion:** 384 passed, 2100 aserciones (igual que la última referencia).
- **Core seleccionado** (Admin Customers y Sales; Shop Checkout, Customers y Registration; Customer
  Unit): 225 passed y 26 failed.
  - **La lista de fallos es idéntica con y sin los bindings del guard** (comparación en la misma
    sesión), así que ninguno viene de RT-04B1a.
  - Incluye los 3 de base conocidos de RT-03.
  - Causas observadas en el log de testing: el correo del admin es NULL en los listeners de Admin
    (sin `MAIL_FROM_ADDRESS` en testing), `transaction_isolation` (variable de MySQL 8 que no
    existe en MariaDB 10.4), moneda USD de las fábricas, reCAPTCHA sin configurar y el locale.
  - **No determinado:** si alguno de los 26 depende también de RT-03. Solo se desactivó RT-04B1a.

### 36.20 Desviaciones respecto del diseño B0

| Premisa anterior | Evidencia real | Conclusión | Impacto | Decisión técnica |
|---|---|---|---|---|
| Normalizado INDEX no único; números compartidos entre Customers (D-RT04-09, §9, §12, §20) | Cambio funcional D-RT04-PHONE-01 | Un número → un Customer | Duplicados: ya no hay «crear con motivo» | UNIQUE global del normalizado |
| U1/U2: `customers.phone` opcional o NULL en el alta comercial (§9.4) | Matriz §36.3: es el teléfono visible del core; el perfil de la tienda lo exige | Debe contener el principal | Principal = `customers.phone` (E.164) | Fuente de verdad: la ficha; la tabla es su reflejo |
| Listeners de `customer.create/update` para indexar (§9.5) | El importador inserta sin eventos; los eventos del admin ocurren después de guardar | Sincronización parcial e inevitablemente divergente | Sin listeners | Servicio explícito (B1b) + indexador con detección de divergencias |
| Columnas país, tipo, estado, WhatsApp, origen y admin (§19) | Derivables o sin consumidor | Sobremodelado | Esquema mínimo | Solo normalizado, original y principal |
| Guard en los métodos públicos de los listeners (§15.3) | `prepareMail` es el punto posterior a las reglas del core | Engancharlo arriba duplicaría reglas de configuración | Guard más exacto | Sobrescribir `prepareMail` (y `afterNoteCreated`) |
| Proteger `afterCreated` y `afterPasswordUpdated` de `Shop\Listeners\Customer` | Inalcanzables sin email | Sin riesgo real | — | Se heredan sin cambios |
| La factura tiene su dirección (`order_address_id`) | La columna no existe; `Invoice::address()` es NULL | La factura usa `order.billing_address` | Afecta a la futura facturación y logística | Documentado; no se toca |
| M2 procedencia (§19) | Su motivo principal desapareció con PHONE-01 | Sin consumidor | — | Aplazada |
| ACL en B1 (P-11) | Sin consumidor en B1a | — | — | Aplazada |

### 36.21 Deuda técnica (B1a)

- **Normalizador:** los internacionales no se verifican (librería opcional).
- **Divergencias:** las causadas por el admin, la tienda o el importador solo se corrigen al
  ejecutar el indexador; no hay sincronización en caliente, por decisión (§36.9).
- **`email_sent`:** el core lo marca en todas las filas y también en las omisiones.
- **Factura:** `invoices.order_address_id` no existe y `Invoice::address()` está roto (core).
- **Correos de admin:** en entornos sin remitente ni correo de admin, los listeners de Admin rompen
  la creación de pedidos (entorno).
- **Reembolso:** *(resuelto en la revisión final: probado con `RefundRepository` real, §36.23).*
- **Direcciones:** el teléfono de las direcciones guardadas no se indexa (B2).

### 36.22 Lo que queda para RT-04B1b

> **Implementado en RT-04B1b (§37)**, salvo lo que §37.9 deja pendiente.

- **Servicio de teléfonos del cliente:** alta del principal con escritura transaccional de
  `customers.phone` y de la fila; secundarios; promoción; cambio y eliminación del principal.
- **Búsqueda y alta:** `BuscarClienteComercialService` (teléfono, nombre y email; 0, 1 o N
  coincidencias; nunca selecciona) y `CrearClienteComercialService` (email NULL o real; grupo
  validado; teléfono → un Customer; eventos §16.2).
- **Resolución y dirección:** `ResolverClienteComercialService` (revalida el grupo actual),
  `DireccionComercial` y `GuardarDireccionClienteComercialService`.
- **Permisos y procedencia:** las claves ACL, con su primer consumidor, y la decisión sobre la
  procedencia (M2).
- **Datos:** aplicar la migración y el indexador en `lpz_saas`, solo con autorización expresa.

### 36.23 Revisión final antes del cierre (2026-09-26)

**1. Principal 1/NULL: VERIFICADO contra la base real.** Ejecuté SQL directo sobre
`lpz_saas_testing`, dentro de `START TRANSACTION … ROLLBACK`: tras el ROLLBACK quedaron 0 filas.

| Operación | Resultado real |
|---|---|
| Customer A: principal 1 + secundario NULL + secundario NULL | Aceptado (3 filas) |
| Customer B: su propio principal 1 | Aceptado |
| Customer A: segundo principal 1 | `ERROR 1062 Duplicate entry '<A>-1' for key 'bvacp_customer_principal_unique'` |
| El número de A como secundario de B | `ERROR 1062 Duplicate entry '+57…' for key 'bvacp_normalizado_unique'` |

DDL real (`SHOW CREATE TABLE`): `principal tinyint(1) DEFAULT NULL`,
`UNIQUE KEY bvacp_normalizado_unique (telefono_normalizado)`,
`UNIQUE KEY bvacp_customer_principal_unique (customer_id, principal)`,
`CONSTRAINT bvacp_customer_fk FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE`.
Se mantiene la estrategia 1/NULL. Un 0 escrito por fuera del modelo no corrompe los datos en
silencio: el segundo 0 del mismo cliente choca contra la unica.

**2. Normalizado frente a validado: CORREGIDO.** El estado `VALIDO` («E.164 garantizado»)
afirmaba más de lo que se comprueba. Cambios:

- Pasa a llamarse `ESTRUCTURA_RECONOCIDA` (`estructura_reconocida`): prefijo y longitud coinciden
  con el plan de numeración de CO. No prueba que el número exista ni que esté asignado.
- `INTERNACIONAL_NO_VERIFICADO` se conserva: solo se comprueba la forma E.164.
- Los docblocks del enum, del DataObject y del normalizador dicen explícitamente «normalizar no es
  validar».
- La etiqueta del comando pasa de «Validos» a «Normalizables».
- Prueba nueva: `300 000 0000` y `+999…` (código de país no asignado) se aceptan por estructura, y
  ningún estado se llama `valido`.
- No se agregó libphonenumber.

**3. Refund real: VERIFICADO.** `RefundRepository::create` se ejecutó después de facturar
(`rt04b1a_reembolsar`):

- **Con email válido:** exactamente 1 `RefundedNotification` al destinatario correcto y ninguna
  omisión.
- **Sin email, con el mailer real:** el reembolso se crea, `qty_refunded` queda en 1, la transacción
  vuelve a su nivel y hay 1 omisión con entidad `Refund`.
- **Sin guard:** TypeError y transacción abierta.

Sustituye a la prueba en memoria.

**4. Clasificación demostrada de los 26 fallos de base del core.** Se comprobó lo siguiente:

- **Subset:** Admin Customers y Sales, Shop Checkout, Customers y Registration, y Customer Unit.
- **B1a habilitado** → lista X (26). **B1a deshabilitado** (bindings del guard retirados en la
  misma sesión) → **la misma lista X**, idéntica también a la de la revisión anterior.
- **NO se comprobó:** si alguno depende de RT-03, porque RT-03 no se desactivó.

| Causa | Tests | Evidencia |
|---|---|---|
| **MAIL_FROM / correo del admin** (14) | 11 por correo del admin NULL (`Admin\Mail\Order\CreatedNotification.php:27`): place order via admin; Checkout place order ×10 (simple, configurable, virtual y downloadable, invitado y cliente; los tres de «two products»). 3 por remitente NULL (Symfony `Address` desde `Message::createAddress`): create a new customer, store the shipment, successfully registers a customer | **Experimental:** al volver a ejecutar el subset con `MAIL_FROM_ADDRESS` definido solo en el proceso, desaparecen exactamente estos 14 y no aparece ninguno nuevo (26 → 12) |
| **Moneda / fábricas** (3) | print/download the invoice (Admin), view the order y print the order invoice (Shop Customers) | `formatCurrency(): Argument #2 null` en la excepción o en el log de la ejecución aislada. Testing solo tiene COP y `OrderFactory` usa USD (`OrderFactory.php:49-51`) |
| **Locale `es` y JSON escapado** (4) | store the newly created customers group; Review: delete, mass delete y mass update | El mensaje de fallo muestra el texto en español con `é`/`ñ` frente al esperado en UTF-8. `customers group` pasa con `APP_LOCALE=en` (experimental). Las tres de reseñas tienen el mismo patrón, demostrado por el propio mensaje |
| **`PHP_EOL` de Windows** (3) | store the payment method after storing the shipping method (Admin); store the payment method for guest user y for customer (Checkout) | Diff: la dirección vuelve en 1 elemento con salto de línea en vez de 2. `AddressResource.php:29` separa con `PHP_EOL` (`"\r\n"` en este Windows); la fábrica usa `faker->address()` (con `\n`) y el test separa con `"\n"` |
| **NO DETERMINADO** (2) | including / excluding tax rate based on shipping origin | Esperado `14,85 $`, real `0,00 $`. Evidencia de código, no demostrada: los campos `sales.shipping.origin.country/state` son `locale_based` y el test los escribe con `locale_code = 'en'` mientras la app está en `es`. Ejecutar todo con `APP_LOCALE=en` rompe 157 pruebas (la base de testing está sembrada en `es`), así que no sirve como demostración |
| `transaction_isolation` / MariaDB | **0** de los 26 | Aparece en el log de testing, pero no es la causa de ningún fallo de esta lista |
| reCAPTCHA | **0** de los 26 | Ídem |

**5. `Invoice::address()`: deuda del core confirmada; el módulo no depende de ella.**

- La prueba `el modulo no depende de Invoice::address()` recorre `src/` y no encuentra ningún uso.
- La fuente correcta es `order.billing_address` / `order.shipping_address`, que ya está probada.
- No se modificó ni el core ni Webkul.

**6. `email_sent` no prueba entrega.** La prueba
`email_sent NO prueba entrega` crea una factura sin email:

- no se encola ningún correo al cliente;
- se emite `NotificacionClienteOmitida`;
- aun así el core deja `email_sent = 1`.

Regla del módulo: `email_sent` **nunca** se interpreta como envío ni como entrega. La evidencia
propia de que no hubo envío es `NotificacionClienteOmitida` y su log.

**7. Regresión final:**

- VentasAsesor: **381 passed**, 1595 aserciones.
- CorteConfeccion: **384 passed**, 2100 aserciones.
- Core seleccionado: 225 passed y 26 failed, iguales con y sin B1a.
- Pint en verde.
- `lpz_saas`: solo SELECT, sin migración ni indexador.
- Webkul: 0 cambios.

---

## 37. RT-04B1b — cliente comercial operativo (2026-09-26)

**Estado: CERRADO** (aprobado 2026-09-26; commit propio, sin push). No crea pedidos. Webkul tiene
0 cambios y `lpz_saas` solo se leyó. Base: commit `cf7a57c` (B1a). Cubre lo que quedaba abierto en
§36.22, salvo lo indicado en §37.9.

### 37.1 Servicios y contratos

Todos viven en `src/Services/Clientes`. Lanzan excepciones con código estable y sin datos
personales, y devuelven DTOs de solo lectura (`toArray()` donde lo consumirá una API).

| Servicio | Responsabilidad | Entrada → salida |
|---|---|---|
| `BuscarClienteComercialService` | Búsqueda única de solo lectura | `buscar(ConsultaClienteComercial{telefono?, email?, nombre?, channelId?})` → `ResultadoBusquedaCliente{tipo, cliente?, candidatos[], conflicto?, clientesEnConflicto[], avisos[]}`. Auxiliares: `idPorEmail()` y `candidatosPorNombre()` |
| `CrearClienteComercialService` | Alta de un Customer real, sin cuenta ecommerce | `crear(DatosClienteComercial{nombre, apellido, telefono, customerGroupId, email?, channelId?})` → `Customer` |
| `TelefonosClienteService` | Única puerta de escritura de teléfonos | `establecerPrincipal(id, tel, conservarAnterior = true)`, `promoverAPrincipal()`, `agregarSecundario()`, `eliminarSecundario()` |
| `ResolverClienteComercialService` | Decide sin escribir: existente, nuevo o conflicto | `resolver(telefono, email?, nombre?, channelId?)` → `ResolucionCliente{estado, cliente?, candidatos[], conflicto?, avisos[]}` |
| `GuardarDireccionClienteComercialService` | Dirección guardada del cliente, sin los Form Requests de la UI | `guardar(customerId, DireccionComercial, ?addressId)` → `CustomerAddress` |
| `Support\Telefono\LocalizadorTitularTelefono` | Dueño de un número normalizado (índice + fichas históricas) | `titular(TelefonoNormalizado)` → `?TitularTelefono{customerId, fuente}` |

Excepciones nuevas:

- `DatosClienteComercialInvalidosException` (campo => código);
- `ClienteComercialExistenteException` (id, `telefono` | `email`);
- `ConflictoIdentidadClienteException` (id por teléfono, id por email);
- `OperacionTelefonoInvalidaException` (`telefono_no_normalizable`, `cliente_inexistente`,
  `telefono_no_es_del_cliente`, `principal_no_eliminable`);
- `TelefonoEnVariasFichasException`.

Se reutiliza `TelefonoPerteneceAOtroClienteException` de B1a.

### 37.2 Identidad y búsqueda

| Criterio | Semántica | Resultado |
|---|---|---|
| Teléfono | Identidad fuerte: un número normalizado = un Customer (principal, secundario o ficha histórica) | `TELEFONO_EXACTO` y `via` (`indice_principal`, `indice_secundario` o `ficha_sin_indexar`) |
| Email | Identifica **dentro del canal**, que es la unicidad real de Bagisto `(email, channel_id)` (Doc. 12 §3). La collation de MariaDB (`utf8mb4_unicode_ci`) no distingue mayúsculas (probado) | `EMAIL_EXACTO`, solo si el teléfono no coincidió |
| Nombre | Solo candidatos: cada palabra en «nombre apellido», con el grupo precargado (una consulta, sin N+1), límite de 20 (máximo 50) | `CANDIDATOS_NOMBRE`; nunca identidad ni fusión |
| Teléfono de A y email de B | Contradicción | `CONFLICTO` (`telefono_y_email_de_clientes_distintos`), fail closed |
| Mismo número en varias fichas | Datos históricos inconsistentes | `CONFLICTO` (`telefono_en_varias_fichas`) |
| Teléfono no normalizable o email inválido | No se busca por ese criterio | Aviso en `avisos[]` |

**Transición de clientes históricos** (indexador no ejecutado en `lpz_saas`, §36.11): el
localizador busca también en `customers.phone` con las variantes que el core pudo guardar. El
core solo admite `^\+?\d+$` (§36.3), así que las variantes son finitas: `+57n`, `0057n`, `57n` y
`n` para Colombia, o `+d` y `00d` para internacionales. Solo se conservan las que el normalizador
devuelve al mismo E.164. La consulta usa el UNIQUE de `customers.phone`, es de solo lectura y
**no indexa ni modifica** nada. Un valor escrito fuera de ese patrón (SQL directo) no se detecta
hasta que corra el indexador.

### 37.3 Creación

Validación previa, sin escribir nada:

- nombre y apellido obligatorios (≤ 255);
- email opcional: vacío o espacios → **NULL**; si existe, formato válido con el mismo criterio que
  el mailer (§36.14);
- teléfono normalizable;
- grupo validado por `ValidadorGrupoComercial` (NULL, inexistente o invitado → rechazo, sin crear
  nada).

Identidad libre:

- teléfono ya usado (principal, secundario, otro formato o ficha histórica) →
  `ClienteComercialExistenteException(telefono)`;
- email ya usado en el canal → `ClienteComercialExistenteException(email)`;
- teléfono de A y email de B → `ConflictoIdentidadClienteException`.

Nunca se crea un duplicado ni se fusiona.

**Transacción:** `CustomerRepository::create` (sin cuenta: `password` NULL, `is_verified` 0, sin
token; canal por defecto) más `TelefonosClienteService::establecerPrincipal` en **una**
transacción. Si el teléfono falla (por ejemplo, en una carrera), se revierte también el Customer
(probado). Una carrera por el email se traduce a `ClienteComercialExistenteException`.

**Eventos** (§16.2, verificado):

- `customer.create.before` y, **después del commit**, `customer.create.after`, protegido con
  `try/catch (Throwable)`. Un listener roto no deshace el alta (probado).
- **No** se disparan `customer.registration.*`, porque en el core significan registro de cuenta y
  disparan verificación o bienvenida (§16.1, N4).
- No se envían `NewCustomerNotification` ni `RegistrationNotification` (probado con `Mail::fake`).

### 37.4 Teléfonos principal y secundarios

Todas las operaciones de `TelefonosClienteService` corren en una transacción con el Customer
bloqueado (`lockForUpdate`):

| Operación | Comportamiento |
|---|---|
| `establecerPrincipal` | Normaliza, comprueba que el número no sea de otro cliente y lo deja como principal. Si ya era secundario, lo promueve. El principal anterior queda como secundario, o se retira si `conservarAnterior = false`. Escribe `customers.phone` en E.164 |
| Cliente histórico sin fila en el índice | Al establecer un principal nuevo, su `customers.phone` anterior pasa a secundario si es normalizable y está libre |
| `promoverAPrincipal` | Exige que el número ya sea del cliente |
| `agregarSecundario` | Idempotente, en cualquier formato equivalente |
| `eliminarSecundario` | Sí para secundarios. **El principal no se elimina**: primero se establece otro. Así, un cliente comercial nunca se queda sin principal por estos servicios |
| Número de otro cliente | `TelefonoPerteneceAOtroClienteException`; nunca se reasigna |

- **Sincronización:** `customers.phone` coincide con el principal del índice en cada operación.
- **Carreras:** si el UNIQUE de `customers.phone` choca, el servicio revierte la transacción
  completa, incluida la fila del índice (probado).
- **Cambios externos:** los hechos desde el panel o el importador siguen siendo cosa del indexador
  (§36.8–§36.9).

### 37.5 Dirección

**Evidencia del core:**

- `CustomerAddress` fija `address_type = customer` con un scope global.
- `CustomerAddressRepository` no tiene lógica.
- La validación vive solo en `Admin/Http/Requests/AddressRequest.php:28-41`.
- `AddressController::store/update` une las líneas con `PHP_EOL` y dispara
  `customer.addresses.create|update.before/after`.
- En `addresses`, `postcode`, `state`, `country`, `email` y `phone` admiten NULL.

**El servicio replica ese contrato con dos diferencias del flujo comercial:**

- email NULL permitido;
- teléfono normalizado a E.164, que cumple `PhoneNumber`.

Reutiliza la regla `PostCode` del core, no una copia. Los campos obligatorios son los mismos que en
el core (el código postal es obligatorio y usa la regla `PostCode`, como en el core; decisión P-06 en §37.10). Si se actualiza,
la dirección debe ser del mismo cliente.

**Teléfono de la dirección:** lo indica el llamador. **No** se asume el principal (quien recibe
puede ser otra persona) y **no** entra en el índice comercial.

**HALLAZGO del core:** `AddressController` desmarca la dirección predeterminada anterior usando el
`customer_id` que llega en el **request**, no el de la ruta. El servicio lo hace por el cliente
correcto (probado: no toca las direcciones de otros clientes).

**Compatibilidad probada:** la dirección guardada sirve tal cual como origen de
`Cart::saveAddresses()`: `parent_address_id`, teléfono y `email` NULL, y `cart.customer_email`
queda NULL (nunca `""`).

**Fuera de alcance:** domicilio frente a recogida, transportadora, tarifa, guía y `vat_id`.

### 37.6 DECISIÓN — ACL: aplazada hasta el primer endpoint

Evidencia: el `Bouncer` del core autoriza por **nombre de ruta** (`Route::currentRouteName()` →
`acl()->getRoles()`, `User/src/Http/Middleware/Bouncer.php:136-142`). Estos servicios no tienen
ruta ni pantalla. Una clave ACL sin ruta no protege nada y sería configuración muerta.

Se crearán `ventas_asesor.clientes.search`, `.create` y `.edit` junto con el primer endpoint o
pantalla que consuma estos servicios (Fase 03 o 04), y la autorización se hará en esa capa.

### 37.7 DECISIÓN — procedencia: sigue aplazada

B1b no demostró una necesidad real: ningún consumidor pregunta quién creó al cliente ni por qué
canal. La atribución de pedidos (02B) no se mezcla con esto.

### 37.8 Pruebas

| Archivo | Casos | Cubre |
|---|---|---|
| `tests/Feature/Rt04b1bTelefonosClienteServiceTest.php` | 12 | Servicio de teléfonos |
| `tests/Feature/Rt04b1bClienteComercialTest.php` | 28 | Crear, buscar, resolver (casos A–F, §37.10) y eventos |
| `tests/Feature/Rt04b1bDireccionComercialTest.php` | 6 | Dirección |
| `tests/helpersRt04b1b.php` | — | Helpers compartidos |

**Resultados:**

- VentasAsesor: **424 passed**, 1736 aserciones (antes 381 / 1595). Las pruebas del guard de B1a
  siguen en verde.
- CorteConfeccion y core: ver `00_STATUS`.

### 37.9 Deuda y pendiente antes de la Fase 03

- **Datos en `lpz_saas`:** *hecho el 2026-09-26* (§37.11): migración `000005` aplicada e índice
  persistido.
- **Decisiones abiertas:**
  - P-06, P-03, P-08 y P-10: **cerradas para RT-04** en §37.10.
  - P-09 (clientes suspendidos o inactivos): aplazada con evidencia en §37.10.
  - Agregar email a un cliente existente y activar su cuenta ecommerce (§24): diseñado, no
    implementado.
- **ACL** con el primer endpoint (§37.6).
- **Deuda:** el teléfono de las direcciones no se indexa (B2) y los internacionales no se verifican
  (§36.10).
- **Para la Fase 03:** `CrearPedidoComercialService` consumirá lo siguiente:
  - `ResolverClienteComercialService`, y `CrearClienteComercialService` si el estado es NUEVO;
  - `TelefonosClienteService` para asociar un número nuevo a un cliente existente;
  - `GuardarDireccionClienteComercialService`;
  - `ContextoPrecioComercial::durante()` de RT-03.

  Antes de preciar, deberá comprobar que el grupo actual del cliente sigue siendo apto
  (`ValidadorGrupoComercial`), porque el grupo pudo cambiar después del alta (§13).

### 37.10 Revisión final de RT-04B1b: identidad y decisiones cerradas (2026-09-26)

**Resolución A–F.** Probado en `Rt04b1bClienteComercialTest`: cada caso registra con `DB::listen`
todas las sentencias no-SELECT y el resultado es **0**, así que `ResolverClienteComercialService`
es de **solo lectura**.

| Caso | Teléfono | Email | Resultado |
|---|---|---|---|
| A | → A | → A | `EXISTENTE` A, sin avisos |
| B | → A | inexistente | `EXISTENTE` A + aviso `email_distinto_al_registrado` (el email de A no cambia) |
| C | inexistente | → A | `EXISTENTE` A (`via = email`) + aviso `telefono_no_registrado`. **El teléfono NO se agrega a A**: si procede, lo hace el llamador con `TelefonosClienteService` |
| D | → A | → B | `CONFLICTO` (`telefono_y_email_de_clientes_distintos`) con ambos ids |
| E | inexistente | inexistente | `NUEVO`, sin crear nada |
| F | (nombre parecido) | — | `NUEVO` + candidatos + aviso `nombres_parecidos`; nunca identidad automática |

**SUPERADO:** la primera versión de B1b devolvía `CONFLICTO (email_de_cliente_existente)` en el
caso C. Se eliminó esa constante. `CrearClienteComercialService` sigue sin crear un duplicado en
ese caso (`ClienteComercialExistenteException(email)`).

| Decisión | Estado | Contenido |
|---|---|---|
| **P-06** Código postal | **CERRADA para RT-04** | No hay regla comercial nueva. La dirección guardada mantiene la misma obligatoriedad y validación que Bagisto (`AddressRequest`: obligatorio + `PostCode`). **Crear un Customer no depende del código postal**, porque el alta no pide dirección. Si una transportadora lo exige de otra forma, será responsabilidad del flujo logístico, no de la identidad |
| **P-03** Grupo | **CERRADA para RT-04** | El grupo es explícito, existente y distinto del invitado (identificado como en el core, §36.15). No hay grupo comercial por defecto ni grupos hardcodeados por nombre. Si «Nueva venta» necesita una política de grupo por defecto, la decidirá esa fase |
| **P-08** Bienvenida | **CERRADA para RT-04** | Crear un cliente comercial ≠ crear una cuenta ecommerce: no se envían bienvenida ni credenciales (probado, §37.3). Activar la cuenta ecommerce será un flujo aparte (§24) |
| **P-09** Suspendidos o inactivos | **APLAZADA** | Ver abajo |
| **P-10** API y privacidad | **PRINCIPIO** | Las futuras API, n8n, WhatsApp o IA recibirán solo los datos que necesite cada operación. No se expone el Customer completo (modelo, relaciones, campos de cuenta). `ClienteEncontrado` ya es una vista reducida; cada endpoint definirá su propio recorte y enmascarado. No hay API en RT-04 |

**P-09, evidencia del core (solo lectura).** Bagisto tiene dos campos reales en `customers`:

- **`status`** (activo o inactivo): impide el login de la tienda
  (`Shop/…/Customer/SessionController.php:46`, `Shop/Http/Middleware/AuthenticateCustomer.php:28`)
  y el checkout de la API de la tienda (`Shop/…/API/OnepageController.php:222`).
- **`is_suspended`**: impide el checkout de la tienda (`Shop/…/OnepageController.php:40`,
  `API/OnepageController.php:215`).
- Ambos se editan en el panel (`Admin/…/CustomerController.php:159-160`; `status` también en
  edición masiva, l. 303).
- **Solo se aplican al cliente con sesión en la tienda** (guard `customer`). El carrito y el pedido
  del panel (`Admin/…/Sales/CartController`, `OrderController`) **no** los consultan.

Hoy nada del dominio comercial los tiene en cuenta: el alta crea `status = 1` e `is_suspended = 0`,
y la búsqueda y el resolver no los filtran. No se inventa un estado nuevo ni se implementa nada.
La Fase 03 decidirá, ante una necesidad funcional concreta, si una venta comercial a un cliente
inactivo o suspendido se bloquea, se avisa o se permite, usando estos mismos campos del core.

### 37.11 Datos reales preparados en `lpz_saas` (2026-09-26)

Hecho con autorización expresa y por pasos, sin modificar código. Lo único que se escribió en
`lpz_saas` fue el DDL de la 000005 y las filas del índice creadas por el comando.

| Paso | Resultado |
|---|---|
| Respaldo previo | `mysqldump --single-transaction` completo de `lpz_saas`, guardado localmente **fuera del repositorio**. Contiene datos personales |
| Migración `2026_09_26_000005` | Aplicada **solo** con `--path` (era la única pendiente), batch 25. Esquema verificado igual al de §36.6: UNIQUE del normalizado, UNIQUE `(customer_id, principal)` y FK CASCADE hacia `customers`. La estructura de `customers` no cambió |
| Dry-run inicial | 10 `indexable`; 0 inválidos, ambiguos, duplicados, conflictos, divergentes o errores |
| `--persistir` (una sola vez) | 10 `indexado` |
| Integridad | 10 filas, 10 clientes, 10 números distintos, 10 principales, 0 secundarios. Ningún cliente con más de un principal ni ningún número en más de un cliente. Los 5 clientes sin `customers.phone` siguen sin fila |
| Sincronía | Para los 10, `normalizar(customers.phone)` = `telefono_normalizado` de su principal, y `telefono_original` = `customers.phone` |
| Sin efectos colaterales | `customers.phone` idéntico (comparación fila a fila). `CHECKSUM TABLE` de `customers`, `addresses`, `orders`, `cart` y `customer_groups` igual al previo. Conteos: 15 clientes, 88 direcciones, 24 pedidos |
| Dry-run posterior (idempotencia) | 10 `ya_indexado` y 0 escrituras propuestas; la tabla sigue igual |

Con esto, la búsqueda por teléfono en `lpz_saas` usa el índice. La transición por fichas
históricas (§37.2) queda solo como red de seguridad para cambios hechos fuera del módulo.
**RT-04 queda preparado para que la Fase 03 pueda empezar cuando se autorice. La Fase 03 no se ha
iniciado.**
