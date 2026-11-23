# 33 — Cliente frecuente en Nueva venta y corrección de Ventas en curso (AUDITORÍA Y PROPUESTA)

**Estado:** AUDITORÍA Y DISEÑO FUNCIONAL. **Nada implementado.** Sin código, sin migraciones, sin cambios de roles ni de datos.
**Fecha:** 2026-11-22 · **Responsable funcional:** Camilo Gaitán.

**Código auditado:** `C:/xampp/htdocs/lpzsan`, rama `develop`, commit `8b4f448a0b` (la rama vigente). En las rutas, métodos y
tablas de este documento no hay diferencias con `upgrade/bagisto-2.4.9`.

**Bases consultadas (solo `SELECT`):** `lpz_saas` (desarrollo) y `lpz_saas_preproduccion_limpia` (la base que se usó para
producción, r3). **Producción no se consultó.**

> Regla de lectura: lo marcado **[VERIFICADO]** se comprobó en el código o en la base. Lo marcado **[PROPUESTA]** es diseño y
> necesita la aprobación de Camilo. Lo marcado **[POR VERIFICAR]** no se pudo comprobar en esta fase.

---

## 0. Resumen ejecutivo

1. **El rol CLIENTE FRECUENTE existe solo de nombre.** [VERIFICADO] Hay dos roles: `#12070 CLIENTE FRECUENTE` y
   `#12069 CLIENTE FRECUENTE 2`. Los creó una prueba de QA (2026-09-20, el mismo minuto que `#12071 BODEGA` con permisos `all`).
   Ninguno tiene permisos de Ventas Asesor. `#12070` puede **crear, editar y borrar productos, categorías, atributos y familias**.
   `#12069` tiene casi toda la rama `sales.*` de Bagisto (facturas, envíos, reembolsos, transacciones). Los dos están en la base
   limpia de preproducción, sin usuarios. **Así como están, no sirven y son peligrosos.**
2. **No existe ningún vínculo entre el usuario administrativo (`admins`) y el cliente comercial (`customers`).** [VERIFICADO]
   Hay que crearlo (tabla nueva mínima).
3. **Nueva venta se puede reutilizar casi entera.** [VERIFICADO] Los servicios (`NuevaVentaService`, `CatalogoVentaService`,
   `BorradorVentaService`, `ConfirmarVentaService`) no dependen de la pantalla y ya validan propiedad, stock, precio por grupo,
   entrega, total esperado e idempotencia. Lo que **no** se puede reutilizar tal cual es el **permiso**: una sola clave
   (`ventas_asesor.pedidos.create`) abre también buscar clientes, crear clientes, ver direcciones de cualquier cliente y cotizar.
4. **Causa real de los borradores vacíos:** [VERIFICADO] la pantalla llama a `POST nueva-venta/iniciar` al **elegir el cliente**
   (`seleccionarClienteEncontrado()` → `NuevaVentaService::iniciar()` → `BorradorVentaService::crear()`, que crea el Cart de
   Bagisto y la fila del borrador), y **el catálogo solo se muestra cuando ya hay borrador** (`<template v-if="borradorId">`).
   Para consultar inventario hay que crear una venta. Pero el backend del catálogo **no necesita borrador**: `colores`, `matriz`
   y `matrices` funcionan sin él (`borrador_id` es opcional y el precio sale de `ContextoPrecioComercial::duranteCreacionDeCarrito`).
5. **En desarrollo hay 26 borradores activos vacíos** (de 43 activos), todos con más de 30 días y **sin ninguna relación**
   (sin cotización, evento de grupo, clasificación, dirección, pago ni domiciliario). Hay además 9 borradores en
   `confirmando` desde octubre que la lista no muestra. **No hay vencimiento ni limpieza automática.**
6. **Recomendación:** **creación diferida (Opción A) + filtro defensivo y limpieza (parte de C)**: el borrador nace con la
   primera talla aplicada, en una sola operación idempotente. Para el cliente frecuente, **controlador y claves ACL propios** que
   llaman a los **mismos servicios**, con el cliente fijado en el servidor desde el vínculo. El pedido queda como **«Empresa»**
   (sin comisión) y sigue el flujo normal de Bodega. El cliente elige una **intención de pago** (el método del pedido) y **nunca
   registra dinero**.
7. **Problemas de seguridad que ya existen hoy (para asesores) y que deben corregirse antes de dar acceso a clientes:**
   [VERIFICADO] `GET nueva-venta/{borrador}` devuelve cualquier borrador sin comprobar el propietario, y
   `GET nueva-venta/clientes/{cliente}/direcciones` devuelve las direcciones de cualquier cliente (§11.3).

---

## 1. Estado real del rol CLIENTE FRECUENTE

### 1.1 Roles en desarrollo (`lpz_saas`) [VERIFICADO]

| id | Nombre | Tipo | Usuarios | Observación |
|---|---|---|---|---|
| 1 | Administrador | `all` | 2 (#1, #2) | Supervisor |
| 2 | ASESOR VENTAS | `custom` | 2 (#12177, #12178) | 24 permisos, entre ellos `operar_como_asesor`, `pedidos.create`, `cartera.registrar`, `logistica.despachos.despachar`, `logistica.guias.corregir` |
| 4 | BODEGA | `custom` | 1 (#53) | Corte/confección, catálogo, `logistica.*` (despachar, entregas, guías, stock) |
| 12069 | **CLIENTE FRECUENTE 2** | `custom` | 1 (#12137, nombre «CORTADOR», correo `@example.test`) | `dashboard` + casi todo `sales.*` (crear/cancelar pedidos, facturas, envíos, reembolsos, transacciones, RMA) |
| 12070 | **CLIENTE FRECUENTE** | `custom` | 1 (#12138, nombre «CORTADOR», correo `@example.test`) | `catalog.*` completo (crear, copiar, **editar y borrar** productos, categorías, atributos, familias) |
| 12071 | BODEGA | **`all`** | 1 (#12139, `@example.test`) | Mismo nombre que #4 y acceso total |

- Los roles 12069, 12070 y 12071 y sus usuarios se crearon el **2026-09-20 18:14:36–37**, junto a «Creador QA Fase1», «CORTADOR QA
  Fase1», etc. Son **restos de una prueba**. Esto coincide con la memoria de la base limpia («ambiguos conservados: roles
  12069/12070/12071»).
- **Base limpia de preproducción:** existen los mismos roles 12069, 12070 y 12071, **sin usuarios**.
- **Producción:** [POR VERIFICAR] no se consultó. Si se restauró desde r3, tiene los mismos roles. Pueden existir usuarios que
  Camilo creó después.
- Ningún código del repositorio menciona «CLIENTE FRECUENTE» (solo una línea del Doc. 28, que lo lista como rol denegado
  para el crédito).

### 1.2 Confusiones posibles [VERIFICADO]

- «CLIENTE FRECUENTE» y «CLIENTE FRECUENTE 2»: nombres casi iguales y permisos que no se parecen en nada.
- Dos roles «BODEGA» (#4 `custom` y #12071 `all`). Un usuario asignado al #12071 por error tendría acceso total.
- Los usuarios #12137 y #12138 se llaman «CORTADOR», pero tienen roles de cliente frecuente.
- Hay **clientes de Bagisto** (`customers`) y **grupos** (`customer_groups`: General, Mayorista_VIP_1…4, Recurrente). «Cliente
  frecuente» no es un grupo. No hay que confundir el **rol** (qué puede hacer el usuario) con el **grupo** (qué precio paga el
  cliente).

### 1.3 Autenticación, menú, rutas y middleware [VERIFICADO]

- El usuario entra por el login de **administración** de Bagisto (`admins`, guard `admin`). Así quiere Camilo que funcione:
  usuario administrativo con un rol.
- Todas las rutas de Ventas Asesor están en el grupo `['admin', NoCacheMiddleware]`. El middleware `admin` incluye el **Bouncer**
  de `Webkul\User\Http\Middleware\Bouncer`, que en 2.4.9 **falla cerrado**: una ruta que no está en ningún `acl.php` ni en
  `UNRESTRICTED_ROUTES` se rechaza. También cierra la sesión si `admins.status = 0` o si el rol no tiene permisos.
- El menú (`Config/menu.php`) muestra cada entrada según su clave ACL. «Nueva venta» depende de `ventas_asesor.pedidos.create`.
- El módulo decide por **clave ACL**, nunca por nombre ni id de rol (`AutorizacionVentasAsesor::tienePermiso`, D-06). La única
  excepción, a propósito, es `esAdministradorAutorizado()` (rol administrador configurado, para dinero).

### 1.4 Conclusión

No hay nada que conservar de los roles actuales. [PROPUESTA] Dejar **un solo** rol «CLIENTE FRECUENTE», con permisos nuevos y
mínimos (§11), y **retirar** el «2» (ver D-CF-02). La autorización se basa en una **clave marcadora nueva más el vínculo con el
cliente**, nunca en el nombre del rol.

---

## 2. Relación entre usuario administrativo y cliente Bagisto

### 2.1 Lo que existe [VERIFICADO]

- `admins`: id, name, email, password, status, role_id, 2FA. **Sin `customer_id`.**
- `customers`: grupo (`customer_group_id`), teléfono único, estado, suspendido. **Sin `admin_id`.**
- `bylopez_va_asesores`: vínculo 1:1 **usuario → asesor** (`admin_id` único e inmutable). Es el precedente que conviene copiar
  (ASESOR-01, `AsesorActualService`).
- Direcciones: `addresses` (`address_type = customer`) del cliente. Precio: grupo del cliente (RT-03, `ContextoPrecioComercial`).
  Pedidos: `orders.customer_id`.
- **Ninguna tabla relaciona un usuario administrativo con un cliente.**

### 2.2 Modelo mínimo propuesto [PROPUESTA]

Tabla `bylopez_va_clientes_acceso` (nombre por aprobar):

| Columna | Tipo | Regla |
|---|---|---|
| `id` | bigint | PK |
| `admin_id` | int unsigned | **UNIQUE**, FK `admins` RESTRICT. Un usuario representa a un solo cliente. |
| `customer_id` | int unsigned | FK `customers` RESTRICT. ¿Único? Ver D-CF-01. |
| `activo` | bool | Si es falso, el acceso se niega aunque el rol tenga los permisos. |
| `creado_por_admin_id`, `actualizado_por_admin_id` | int unsigned | Auditoría |
| `timestamps` | | |

Más una bitácora corta (`…_eventos`: vincular, desvincular, activar, desactivar, con usuario, motivo y fecha). Esto sigue el
patrón de las tablas `*_eventos` del módulo.

**Servicio fuente única** `ClienteActualService` (copia de `AsesorActualService`):

- `clienteDe(Admin): ?Customer`: vínculo **activo** + cliente activo, no suspendido y con un grupo apto (`ValidadorGrupoComercial`).
- `esAutoservicio(Admin)`: el rol es `custom` y tiene la clave marcadora `ventas_asesor.autoservicio`.
- `operable(Admin)`: si el rol tiene la clave marcadora y no hay vínculo activo, **falla cerrado** (igual que
  `asesor_sin_asociacion`).
- **Incompatibilidades** que se validan al guardar el vínculo y otra vez en cada operación: un usuario con la clave marcadora no
  puede tener también `operar_como_asesor` ni un rol `all`. Un usuario de autoservicio con perfil de asesor se rechaza.

### 2.3 Dónde se configura [PROPUESTA]

El formulario de usuarios de Bagisto (`Admin/settings/users/index.blade.php`) **no tiene puntos de extensión** (`view_render_event`)
cerca del rol, y Webkul no se modifica. Por eso se propone una pantalla propia, igual que **Ventas Asesor › Asesores**:

- **Ventas Asesor › Accesos de clientes** (clave `ventas_asesor.clientes_acceso.manage`, solo Administración).
- Flujo: el Administrador crea el usuario en Configuración › Usuarios con el rol CLIENTE FRECUENTE. Después, en «Accesos de
  clientes», elige el usuario y busca el cliente por teléfono (reutiliza `BuscarClienteComercialService`). Guarda con motivo.
- Se puede añadir un acceso directo desde la ficha nativa del cliente, como ya se hace con Cartera (sin tocar Webkul).
- Al desactivar un usuario (`status = 0`), el Bouncer ya lo expulsa. Sus borradores quedan como están (§12, caso 9).

### 2.4 Cómo queda fijado el cliente en cada petición

Las rutas de autoservicio **no reciben `customer_id`**. El controlador lo obtiene de `ClienteActualService::clienteDe(auth)`. En
las operaciones sobre un borrador, el servicio comprueba además que `cart.customer_id` sea el cliente vinculado **y** que
`propietario_admin_id` sea el usuario. Un `customer_id` que llegue en la petición se ignora. No hay buscador de clientes.

---

## 3. Permisos necesarios

Ver la matriz completa en §16. Claves nuevas propuestas (hojas explícitas, sin `all`):

| Clave | Abre (rutas) | Para quién |
|---|---|---|
| `ventas_asesor.autoservicio` | Marcador + inicio del módulo | CLIENTE FRECUENTE |
| `ventas_asesor.autoservicio.comprar` | Pantalla, catálogo, matriz, tallas, entrega, intención de pago, confirmar, ventas en curso propias, descartar la propia | CLIENTE FRECUENTE |
| `ventas_asesor.autoservicio.pedidos` | «Mis pedidos» (listado y detalle restringidos) y guía de sus pedidos | CLIENTE FRECUENTE |
| `ventas_asesor.clientes_acceso.manage` | Pantalla «Accesos de clientes» | Administrador |

El rol CLIENTE FRECUENTE **no** recibe `pedidos.create`, `pedidos.view_own`, `cartera.*`, `comisiones.*`, `logistica.*`,
`catalog.*`, `sales.*` ni `dashboard`.

---

## 4. Componentes reutilizables [VERIFICADO]

| Componente | Archivo | Reutilización |
|---|---|---|
| Orquestador | `Services/Venta/NuevaVentaService.php` | Tal cual (`aplicarTallas`, `entregaRapida`, `seleccionarPago`, `confirmar`, `estado`) |
| Catálogo | `Services/Venta/CatalogoVentaService.php` | Tal cual (`accesosRapidos`, `coloresDeAcceso`, `matriz`, `matrices`), pasando el cliente vinculado |
| Borrador | `Services/Venta/BorradorVentaService.php` | `crear`, `descartar`, `listarDelPropietario`. Cambia `exigirPermiso()` (§5) |
| Líneas y solicitudes | `LineasBorradorService`, `SolicitudBorradorService` | Tal cual (D-39, I-13) |
| Entrega | `EntregaBorradorService`, `PuntosRecogida`, `DomiciliarioDeVenta` | Tal cual, con limitaciones (§7.3) |
| Pago (intención) | `PagoBorradorService` | Tal cual, con allowlist propia (§7.4) |
| Confirmación | `ConfirmarVentaService` | Tal cual: token D-55, cerco D-54, `total_esperado`, revalidación de stock/entrega/pago/total. Cambia `exigirPermiso()` |
| Atribución | `ResolverAtribucionService::paraCreacionAdministrativa` | Con `SeleccionPropiedadComercial(EMPRESA)` fija (§14) |
| Precio por grupo | `ContextoPrecioComercial`, `CarritoComercial` (RT-03) | Tal cual: falla cerrado si cart y cliente no coinciden |
| Rentabilidad | `RentabilidadVentaService::exigirEnConfirmacion` / `paraAsesor` | Tal cual (no expone costos). Mensaje para el cliente (§7.5) |
| Pantalla | `Resources/views/admin/nueva-venta/index.blade.php` (Vue, 4.645 líneas) | Misma vista con **modo** `autoservicio` y un mapa de rutas inyectado (§5) |
| Error JSON | `Support/Http/RespuestaErrorNuevaVenta` | Tal cual |
| Consulta de pedidos | `Services/Pedidos/ConsultaPedidosAsesorService` | Se le añade un alcance «del cliente» (§8) |
| Guía | `GuiaPedidoController` + Logística | Se le añade un chequeo por cliente (§8) |

---

## 5. Cambios en Nueva venta

### 5.1 Decisión de arquitectura: controlador propio, servicios comunes [PROPUESTA]

Hay dos caminos:

- **(a) Darle al cliente `pedidos.create` y poner condiciones por rol en cada método.** Se descarta. Esa clave abre
  `clientes.buscar`, `clientes.crear`, `iniciar_cliente_nuevo`, `clientes.direcciones` (de cualquier cliente), `cotizaciones.*` y
  `ubicaciones.buscar`. Basta con olvidar una condición para filtrar datos de otros clientes.
- **(b) `AutoservicioController` delgado con rutas propias** (`ventas-asesor/autoservicio/...`) bajo las claves
  `autoservicio.*`. Llama a **los mismos servicios** con el cliente fijado. El Bouncer niega al cliente todas las rutas de
  asesor, porque no tiene sus claves. **Recomendado.**

Cambios mínimos en los servicios:

- `BorradorVentaService::exigirPermiso()` y `ConfirmarVentaService::exigirPermiso()` aceptan `pedidos.create` **o**
  (`autoservicio.comprar` + `ClienteActualService::operable`). Además, en autoservicio exigen que el cliente del carrito sea el
  vinculado.
- `NuevaVentaService::iniciar()` recibe un `MecanismoCreacion` nuevo, `autoservicio` (D-CF-04). La columna es `varchar(30)`, así
  que no hace falta migración.
- `CatalogoVentaService::matriz()`: en autoservicio **no** calcula ni devuelve `VentasSimultaneasService`. Hoy esa señal devuelve el
  **nombre de los asesores** y la demanda de otras ventas sin confirmar («coordinación interna», Doc. 05 §40). No debe llegar al
  cliente.

### 5.2 Qué se oculta o cambia en la pantalla (modo autoservicio) [PROPUESTA]

| Elemento actual | Asesor | Cliente frecuente |
|---|---|---|
| Buscador de cliente, «+ Crear cliente», «Cambiar cliente» | Sí | **No**. Cabecera fija «Comprando como: {nombre} · {grupo}» |
| Selector de canal | Sí | **No** (canal fijo, D-CF-04) |
| Categorías, colores, modal de tallas, matriz, edición de prendas, resumen | Sí | **Sí, iguales** |
| Señal «otras ventas sin confirmar» y nombre del asesor | Sí | **No** (el servidor no la envía) |
| Stock de la matriz | Número | Número o tope visible (D-CF-08) |
| «Cambiar grupo para esta venta» | Con permiso | **No** (el servidor también lo niega: no tiene la clave) |
| «Vender para», tipo de operación, detalle de rentabilidad | Con permiso | **No** |
| Aviso de rentabilidad | Nombra la referencia | Mensaje neutro (§7.5) |
| Cotizar | Sí | **No** en V1 (D-CF-17) |
| Saldo a favor / aplicar crédito | Con permiso | **No** |
| Entrega: recogida y domicilio con sus direcciones | Sí | Sí, con límites (§7.3) |
| Domiciliario local | Elige de la lista | No elige (D-CF-06) |
| Pago | Método del pedido | «Cómo vas a pagar» + datos de pago (§7.4) |
| Modal de venta creada | «Ver pedido» del módulo | «Pedido registrado correctamente» (§6) |
| Ventas en curso | Las del propietario | Solo las suyas con productos |

---

## 6. Flujo de confirmación [VERIFICADO + PROPUESTA]

**Lo que exige hoy el backend al confirmar** (`ConfirmarVentaService::crearPedido`): cliente activo y no suspendido, mismas
líneas, stock vendible (cerco D-54 con `FOR UPDATE` de `products`), dirección de facturación, y con productos físicos envío y
método válido (recogida solo en bodega real; domicilio local con domiciliario y tarifa). También exige **método de pago vigente**
(allowlist `cashondelivery`, `moneytransfer` ∩ Bagisto), el **total esperado igual al recalculado** y que la rentabilidad no
bloquee. Un borrador produce un solo pedido (token + lease). **No registra dinero, no crea Transaction ni Shipment.**

Modal propuesta (se reutiliza el componente `modalVentaCreada`, con otro texto y otras acciones):

```
┌──────────────────────────────────────────┐
│ Pedido registrado correctamente          │
│                                          │
│ Tu pedido #BYL-00000123-2026 fue         │
│ recibido. Nuestro equipo se encargará    │
│ de prepararlo.                           │
│                                          │
│ Total: $ 184.000 · Pago: Transferencia   │
│                                          │
│ [ Ver mi pedido ]   [ Crear otro pedido ]│
└──────────────────────────────────────────┘
```

Sin enlaces a Cartera, despacho, pagos ni comisiones. «Ver mi pedido» abre el detalle restringido (§8).

---

## 7. Flujo del cliente frecuente

### 7.1 Ingreso [PROPUESTA]

El menú muestra solo **Nueva venta** (o «Hacer pedido», D-CF-18) y **Mis pedidos**. «Ventas en curso» sigue siendo el panel
lateral que ya existe dentro de Nueva venta. La primera clave con punto del rol debe apuntar a una ruta GET sin parámetros. Es la
regla 3 de `acl.php`, porque `SessionController` redirige ahí después del login.

### 7.2 Productos

Catálogo, colores, tallas y cantidades **iguales** que el asesor. Precio = grupo del cliente vinculado (RT-03). No puede cambiar
de grupo: no tiene la ruta y el servicio vuelve a exigir el permiso.

### 7.3 Entrega [VERIFICADO + PROPUESTA]

- **Recogida en tienda:** sin cambios (carrier `pickup` sobre bodega real, D-41/D-57).
- **Domicilio con dirección guardada:** la lista sale **solo** del cliente vinculado.
- **Dirección nueva:** D-CF-07. `agregarDireccion` guarda la dirección **en la ficha del cliente**.
- **Domicilio local (zona local de Logística):** hoy la confirmación exige un domiciliario con tarifa
  (`domicilioLocalSinDomiciliario` → `domiciliario_requerido`). El asesor lo elige de una lista de transportadoras internas con
  sus tarifas. El cliente no debería ver esa lista. Ver D-CF-06.
- El flete lo calcula Bagisto o la tarifa de Logística. El cliente **no** lo puede escribir: `entregaRapida` solo acepta códigos de
  método, y la confirmación vuelve a calcular el flete.

### 7.4 Pago [VERIFICADO + PROPUESTA]

El paso «Pago» actual **no registra dinero**. Guarda el **método de pago del pedido** (`cart_payment` → `order_payment.method`). Los
pagos reales viven en `bylopez_va_pagos`, que **no tiene relación con el borrador** (sin `borrador_id`). Solo se registran desde el
detalle del pedido o Cartera, con `cartera.registrar` / `abono_general`, claves que el cliente no tendrá.

| Opción | Efecto con el backend actual | Riesgo |
|---|---|---|
| A. Ocultar el paso | `revalidarPago()` rechaza (`PAGO_INVALIDO`). Habría que elegir un método por el cliente | Decide en silencio cómo paga el cliente. `moneytransfer` genera factura `pending` y el pedido queda `pending_payment` (PAG-F), contra entrega no |
| B. Solo información | Igual que A | Igual que A |
| **C. Intención de pago** | Reutiliza `seleccionarPago` con una allowlist propia de autoservicio, más los datos de pago de Configuración (los de Cotizaciones) | Ninguno nuevo: no crea pagos ni marca pagado. Cartera sin cambios |

**Recomendado: C.** Texto: «¿Cómo vas a pagar? ○ Transferencia ○ Contra entrega», con los datos de la cuenta debajo. Qué métodos
se ofrecen: D-CF-05.

### 7.5 Confirmación

Igual que el asesor, con `total_esperado`. Si la rentabilidad bloquea (precio bajo el mínimo con su grupo), el mensaje no
menciona costos ni mínimos: «No podemos confirmar este pedido en línea. Escríbenos y te ayudamos.» (D-CF-13).

---

## 8. Consulta de pedidos propios [VERIFICADO + PROPUESTA]

**Lo que hay:** `PedidosAsesorController@index` + `ConsultaPedidosAsesorService::visibles()`. El alcance `PROPIOS` filtra por
`atr.advisor_id = usuario`. Un pedido de autoservicio queda con `advisor_id = NULL` (empresa), así que **el cliente no vería sus
propios pedidos**. Además, el detalle actual decide los bloques de Cartera, crédito, despacho y avance de comisión con permisos.
Hoy los oculta, pero es una vista pensada para personal interno.

**Propuesta:**

- Un alcance nuevo, `AlcanceVisibilidad::CLIENTE`, en el mismo punto único (`visibles()`): `o.customer_id = cliente vinculado`.
  Ningún filtro de la petición puede ampliarlo.
- Una **vista restringida nueva** (`autoservicio/pedidos`), que no es el detalle del asesor. Muestra número, fecha, productos,
  color, talla, cantidad, valor comercial (precio de venta y total), estado del pedido (Bagisto), estado de despacho derivado,
  entrega (método, dirección, transportadora) y número de guía.
- No muestra costos, utilidad, comisiones, atribución, aplicaciones de pago, custodia, crédito ni botones de despacho.
- Estado de pago: [D-CF-19] mostrar solo «Pendiente / Pagado» o no mostrarlo.
- **Guía (PDF):** hoy `GuiaPedidoController` delega la propiedad en Logística por asesor. Se añade una ruta de autoservicio con
  su chequeo `orders.customer_id = cliente vinculado`, en el servidor y en cada descarga.
- Los mensajes de WhatsApp (`pedidos/{pedido}/mensajes`) **no** se abren al cliente.

---

## 9. Modificaciones antes y después de confirmar

**Antes de confirmar** [VERIFICADO]: todo existe y se reutiliza. `aplicarTallas` aplica el lote completo o nada; cantidad 0 quita la
talla. Cada «Aplicar» **se guarda en el servidor** (Cart + solicitudes).

**Después de confirmar** [VERIFICADO]:

- No hay edición de pedidos confirmados en Ventas Asesor ni en el núcleo. El núcleo tiene `sales.orders.reorder` (crea otro
  carrito) y cancelar.
- Lo que hay es la **cancelación del pendiente** desde Logística (`despachos.cancelar_pendiente`, Doc. 26), con reglas de stock y
  contabilidad.
- Inventario: el pedido **reserva** (`product_ordered_inventories`; para configurables lo corrige `CantidadVarianteConfigurable`)
  y el stock físico baja con el Shipment.
- Cartera: el saldo se calcula a partir del pedido. Cambiar líneas después movería obligaciones, comisiones y rentabilidad.

| Opción | Evaluación |
|---|---|
| **1. Bloquear la edición posterior** | Seguro y sin código nuevo |
| 2. Solicitud de modificación | Útil, pero es otra fase (estado, bandeja, auditoría) |
| 3. Edición controlada antes de preparar | Toca inventario, Cartera, comisiones y despacho. Alto riesgo |

**Recomendado: 1 en V1.** El detalle muestra «¿Necesitas cambiar algo? Escríbenos» con el contacto configurado. La opción 2 queda
como fase posterior (D-CF-11). Bodega y Administración cancelan y rehacen con las herramientas que ya existen.

---

## 10. Funcionamiento actual de Ventas en curso [VERIFICADO]

- **Modelo/tabla:** `BorradorVenta` → `bylopez_va_borradores_venta`. Las líneas **no** están aquí: están en el `cart` de Bagisto
  (`is_active = 0`, invisible para la tienda) y en `bylopez_va_borrador_solicitudes` (lo que el cliente pidió, D-39, FK CASCADE).
- **Estados:** `activo`, `confirmando`, `confirmado`, `descartado`.
- **Propietario:** `propietario_admin_id` (lo puede cambiar `reclamar()`) y `creado_por_admin_id`. `asignado_hasta` = ahora +
  **1 día hábil** (`ventasasesor.borradores.dias_habiles_asignacion`). Pasado ese día, otro asesor podría reclamarlo con
  `reclamar()`, pero **no hay ninguna ruta ni botón** que lo use.
- **Creación:** `BorradorVentaService::crear()` crea el Cart y la fila en una transacción. Idempotencia con UNIQUE
  `(mecanismo_creacion, external_reference)`. **La pantalla no envía referencia**, así que cada clic en un cliente crea un borrador
  nuevo.
- **Primera persistencia:** al **elegir el cliente** o pulsar «Iniciar venta» (`POST nueva-venta/iniciar`), o al crear el cliente
  nuevo (`iniciar-cliente-nuevo`). **No** se crea nada al abrir la pantalla (`GET pantalla` y `GET nueva-venta` no escriben) ni al
  consultar colores o matrices (solo lectura).
- **Líneas:** `POST {borrador}/tallas` (lote).
- **Última actividad:** `updated_at` del borrador solo cambia al cambiar sus columnas (estado, entrega, grupo). Editar líneas
  cambia `cart.updated_at`, no el borrador. En dev, 17 de 43 activos tienen el carrito más reciente que el borrador. **La
  actividad real es `GREATEST(borrador.updated_at, cart.updated_at)`.**
- **Listado:** `GET en-curso` → `listarDelPropietario(adminId)`. Muestra **solo `activo`**, sin filtrar por líneas: los vacíos
  aparecen y los `confirmando` colgados no.
- **Descartar:** `descartar()` (propietario) → estado `descartado` y **borra el Cart**. Las líneas y direcciones caen en cascada y
  `cart_id` queda en NULL por la FK `SET NULL`. **La fila del borrador se conserva.**
- **Relaciones:** `bylopez_va_borrador_solicitudes` (FK CASCADE), `bylopez_va_cotizaciones.borrador_id`,
  `bylopez_va_borrador_grupo_eventos.borrador_id`, `bylopez_va_clasificaciones_pedido.borrador_id`,
  `bylopez_va_pedido_grupo_snapshots.borrador_id`. **Solo las solicitudes tienen FK.** Las otras cuatro tablas usan `borrador_id`
  sin restricción: borrar físicamente un borrador dejaría huérfanos sin error. `order_id` FK RESTRICT. Pagos: **ninguna relación**.
- **Reservas:** el borrador **no reserva stock**. La señal de ventas simultáneas solo lee las solicitudes de otros borradores
  activos, sin excluir por antigüedad (Doc. 05 §40).
- **Al cerrar el navegador:** lo aplicado ya está en el servidor. El `borradorId` vive **solo en memoria del Vue**: no hay
  `localStorage`, `sessionStorage`, `beforeunload` ni `pagehide`. Al volver, la pantalla arranca vacía y hay que abrir «Ventas en
  curso → Reanudar».
- **Dos pestañas:** cada una crea su propio borrador. Sobre el mismo borrador, el servidor serializa con `lockForUpdate` y la
  pantalla descarta respuestas viejas (`nuevaOperacion` / `operacionVigente`).
- **Errores de red:** el lote de tallas aplica todo o nada. Si la respuesta se pierde, la pantalla avisa «Verifica el estado de la
  venta antes de volver a intentarlo». Un reintento de `iniciar` sin referencia **crea otro borrador vacío**.

### 10.1 Datos (solo lectura, 2026-11-22)

| Medida | `lpz_saas` | Preproducción limpia |
|---|---|---|
| Borradores `activo` | 43 (propietarios: admin #1 = 32, asesora #12178 = 11) | 0 |
| — con carrito vacío y sin solicitudes | **26** (21 del admin, 5 de la asesora). Los 26 con más de 30 días | — |
| — vacíos con alguna relación (cotización, evento de grupo, clasificación, dirección, pago del carrito, domiciliario) | **0** | — |
| — con líneas | 17 (15 con más de 30 días, 2 entre 7 y 30) | — |
| `confirmando` sin pedido (lease vencido, invisibles en la lista) | **9** (ids 5, 6, 10, 11, 13, 24, 35, 37, 40; del 2026-09-30 al 2026-10-05) | 0 |
| `confirmado` / `descartado` | 54 / 9 | 0 |
| Carritos inactivos sin borrador | 89 (2025-10 a 2026-10; herencia anterior, fuera de alcance) | — |

---

## 11. Causa real de los borradores vacíos y seguridad de las rutas actuales

### 11.1 Causa [VERIFICADO]

`index.blade.php`:

- `seleccionarClienteEncontrado(cliente)` → `POST nueva-venta/iniciar` con `customer_id` y canal. Lo llaman el clic en un resultado
  y «Iniciar venta».
- `<template v-if="borradorId">` envuelve «Accesos rápidos, línea/color y matriz». **Sin borrador no hay catálogo.**
- `reiniciar()` (por ejemplo, «Crear otra venta») limpia `borradorId` en el navegador, pero **no descarta** el borrador del servidor.

Resultado: cada consulta de inventario («¿cuántas blancas hay?») crea un borrador más un Cart. Si no se agregan prendas, queda
visible para siempre en «Ventas en curso».

### 11.2 Lo que ya permite corregirlo [VERIFICADO]

`colores`, `matriz` y `matrices` aceptan `borrador_id` nulo. Sin borrador, el precio se calcula con
`ContextoPrecioComercial::duranteCreacionDeCarrito(customerId)`. **Consultar sin borrador ya funciona en el backend.** Solo lo
impide la pantalla.

### 11.3 Problemas de seguridad que ya existen [VERIFICADO]

| Ruta | Problema | Severidad con cliente frecuente |
|---|---|---|
| `GET nueva-venta/{borrador}` → `NuevaVentaService::estado()` → `LineasBorradorService::listar()` → `BorradorVentaService::obtener()` | **No comprueba el propietario.** Cualquiera con `pedidos.create` lee cualquier borrador: cliente, teléfono, correo, líneas, direcciones, totales | Crítica |
| `GET nueva-venta/clientes/{cliente}/direcciones` | Devuelve las direcciones de **cualquier** cliente sin relación con un borrador propio | Crítica |
| `GET productos/{p}/matriz?customer_id=X` | Calcula precios con el grupo de **cualquier** cliente | Alta (vería precios de otros grupos) |
| `?borrador_id=` en matriz/matrices | Lee el `cart_id` de cualquier borrador. RT-03 falla cerrado si el cliente no coincide, así que el impacto es bajo | Baja |
| Señal de ventas simultáneas | Devuelve nombres de asesores y la demanda de otras ventas | Media |

Con el diseño de §5.1, el cliente frecuente **no puede llegar** a estas rutas (el Bouncer se las niega). Aun así, las dos primeras
afectan hoy a los asesores y conviene corregirlas primero (P0, D-CF-14).

---

## 12. Duración y expiración actual [VERIFICADO]

- **No hay vencimiento.** `asignado_hasta` solo sirve para reclamar (sin uso en la pantalla). **No hay job ni scheduler** de
  borradores. El único programado del módulo es `ventas-asesor:comisiones:evaluar --bandeja`, que además depende de que el servidor
  ejecute `schedule:run`, y en el VPS el cron sigue pendiente.
- Un borrador puede quedar abierto **indefinidamente**. Se recupera desde «Ventas en curso» → «Reanudar» (solo el propietario).
- Puede tener cotizaciones (documento enviado al cliente) y eventos de grupo (auditoría). **No puede tener pagos.**
- Los `confirmando` colgados se recuperan con `ConfirmarVentaService::reconciliar()` cuando alguien vuelve a operar sobre ellos.
  Sin un acceso, no se recuperan nunca.

### 12.1 Políticas evaluadas y recomendación [PROPUESTA]

| Tipo | 24 h | 7 días | 15 días | 30 días | **Recomendado** |
|---|---|---|---|---|---|
| **Vacío** (sin líneas, sin solicitudes, sin relaciones) | ✔ | — | — | — | **Oculto de inmediato. Se descarta automáticamente a las 24 h** de la última actividad |
| **Con productos** (sin cotización) | Pierde ventas reales | Corto para mayoristas | ✔ aviso | ✔ | **Visible 15 días. Del día 15 al 30 «Por vencer». A los 30, descarte automático** (estado `descartado` + motivo, Cart borrado, fila conservada) |
| **Con relaciones** (cotización, eventos de grupo) | — | — | — | ✔ | **Igual que con productos, pero cuenta desde la última cotización. La fila y las relaciones no se borran nunca** |
| `confirmando` colgado | | | | | **Reconciliar** (no descartar) con el mismo job, antes de cualquier otra cosa |

Reglas: **nunca borrar filas** de `bylopez_va_borradores_venta` (las relaciones sin FK quedarían huérfanas). «Limpiar» significa
lo mismo que hace hoy `descartar()` más un motivo (`vencido` / `vacio`) y un evento. No se toca nada con `order_id`. La limpieza
**no** se implementa en esta fase (P8).

---

## 13. Comparación de alternativas A, B y C

| Criterio | **A — Creación diferida** | **B — Borrador técnico oculto** | **C — Híbrida (técnico + filtro + limpieza)** |
|---|---|---|---|
| Idea | No hay borrador hasta la primera talla con cantidad > 0 | Se crea al elegir el cliente (como hoy). La lista no lo muestra sin productos | B + limpieza automática de los abandonados |
| Ventajas | Corrige el origen: consultar no escribe nada. Sin Carts basura. El backend ya consulta sin borrador | Cambio mínimo en la pantalla | Sin basura visible y con limpieza |
| Desventajas | La primera talla necesita una operación «crear + aplicar» atómica. Cambiar el grupo temporal antes de la primera talla necesita una regla (§13.1) | Sigue creando un Cart y una fila por cada consulta. La tabla y `cart` crecen sin límite. La señal y los conteos siguen viendo filas | Lo mismo que B, más un job que mantener. Se limpia lo que no debió crearse |
| Riesgos | Doble clic o reintento en la primera talla → dos borradores (se evita con una referencia idempotente) | Crecimiento y ruido de datos. Un filtro olvidado vuelve a mostrar vacíos | Un job mal escrito podría descartar trabajo real |
| Complejidad | Media (una operación de servicio + ajuste del Vue) | Baja | Media-alta |
| Impacto en el módulo | `NuevaVentaService` (operación nueva), Vue (catálogo visible sin borrador) | `listarDelPropietario` | B + comando + scheduler |
| Impacto en otros módulos | Ninguno | Ninguno | Necesita cron en el VPS (hoy pendiente) |
| Migraciones | **Ninguna** (usa `external_reference` + UNIQUE existente) | Ninguna | Ninguna (motivo en un evento o columna, D-CF-10) |
| Varias pestañas | Cada pestaña tiene su referencia. Solo crean borrador las que agregan prendas | Una por pestaña, todas vacías | Igual que B, limpiadas después |
| Fallos de red | Reintento con la misma referencia → mismo borrador (idempotente) | Reintento de `iniciar` → otro borrador vacío | Igual que B |
| Pérdida de información | Ninguna: se guarda en cuanto hay una prenda | Ninguna | Baja (solo si la regla de limpieza es incorrecta) |

### 13.1 Recomendación: A + filtro defensivo + limpieza de vacíos (C reducida) [PROPUESTA]

1. **Elegir cliente no crea nada.** La pantalla guarda el cliente en memoria y muestra el catálogo. `matriz` y `matrices` se llaman
   sin `borrador_id` (ya funciona).
2. **Primera talla:** una operación nueva, `NuevaVentaService::iniciarConTallas(cliente, actor, canal, referencia, producto,
   cantidades)`. En **una sola transacción** hace `crear()` (que pasa a ser savepoint) + `aplicarTallas`, todo o nada. Si el lote
   falla (por ejemplo, por stock), no queda borrador. La `referencia` es un UUID que genera la pantalla por «venta en pantalla»
   (`nv-<uuid>`). El UNIQUE `(mecanismo, external_reference)` ya existe y devuelve el mismo borrador ante un doble clic, un
   reintento o una respuesta perdida (`mismaSolicitud`). **Sin migración.** Hay que verificar en P7 que dentro del lote no haya
   ningún commit implícito. El problema conocido de `OrderRepository` solo afecta a la confirmación.
3. **Lista:** `listarDelPropietario` muestra solo `activo` **con al menos una solicitud > 0 o una línea en el Cart** (defensa en
   profundidad para los vacíos que ya existen y para los que se vacían).
4. **Quitar todas las prendas:** no se descarta en la misma petición, porque otra pestaña podría estar agregando. El borrador queda
   oculto y la pantalla ofrece «Descartar venta vacía». Si nadie hace nada, la limpieza lo descarta a las 24 h (P8).
5. **Cambiar el grupo temporal (GRP-01) antes de la primera talla:** es un acto comercial con motivo y queda auditado. Se
   recomienda que cree el borrador (oculto mientras no tenga productos) y registre su evento. Ver D-CF-15.
6. **Recuperación:** la pantalla recuerda `borradorId` en `sessionStorage` por pestaña. Es solo una comodidad, protegida con
   try/catch. La fuente de verdad sigue siendo «Ventas en curso». Nada depende de `beforeunload`.
7. **Se aplica a todos los roles** (asesor, administrador y cliente frecuente). No es una regla especial del cliente.

---

## 14. Integración con Bodega [VERIFICADO + PROPUESTA]

- **Creador:** `bylopez_va_order_attributions.created_by_admin_id` = usuario del cliente (lo escribe el listener 02B1A).
- **Propietario comercial:** sin selección, `resolverSinSeleccion()` deja `SIN_ASIGNAR` para quien no tiene `operar_como_asesor`.
  **Recomendado:** enviar `SeleccionPropiedadComercial(EMPRESA)` fija desde el autoservicio → `commercial_owner_type = company`,
  `advisor_id = NULL`, origen `seleccion_explicita`. Empresa = **sin comisión** (Doc. 06B), con origen conocido. «Sin asignar»
  significa «no sabemos de quién es» y ensucia los reportes. Ver D-CF-03.
- **¿Exige un asesor?** No. La confirmación funciona sin asesor. La rentabilidad se evalúa con propiedad `company` y asesor nulo.
- **Comisiones:** ninguna (sin `advisor_id`). El cliente frecuente **nunca** es asesor (incompatibilidad del §2.2).
- **Bandeja de pedidos y Logística:** Logística **no filtra por atribución** (no hay referencias a `order_attributions` ni
  `advisor_id` en sus servicios). El pedido aparece en Despachos como cualquier otro: stock insuficiente → incidencia de stock;
  despachos parciales → «Ajustar cantidades». Todo eso queda en manos de Bodega, sin cambios.
- **Stock insuficiente al confirmar:** el cerco D-54 rechaza con `STOCK_INSUFICIENTE` y la lista de variantes. La pantalla ya muestra
  ese error; el texto se adapta al cliente.
- **Información de entrega para el cliente:** «Mis pedidos» muestra el estado de despacho, la transportadora y la guía. Los avisos
  activos por WhatsApp los manda el equipo (Doc. 31), no el sistema. [POR VERIFICAR] qué correos automáticos de Bagisto recibe el
  cliente al crear el pedido (`core_config` de notificaciones).
- El cliente frecuente **no** recibe ninguna clave `logistica.*`.

---

## 15. Riesgos financieros y de inventario

| Riesgo | Protección |
|---|---|
| Pedido con precio de otro grupo | Cliente fijado en el servidor + RT-03 + revalidación al confirmar. Sin rutas de grupo |
| Pago ficticio o pedido marcado como pagado | El cliente no tiene ninguna ruta de pagos, crédito ni Cartera (Bouncer + servicios con `cartera.*`). El método del pedido no es un pago |
| Comisión indebida | Propiedad `company` fija. Incompatibilidad con `operar_como_asesor` |
| Sobreventa | Cerco D-54 + reserva al confirmar. El borrador no reserva (igual que hoy). Venta de la última unidad: §12, caso 13 |
| Pedido sin control de precio mínimo | `exigirEnConfirmacion` se aplica igual |
| Rol mal configurado (por ejemplo `all`) | Incompatibilidad del §2.2: un rol `all` no puede usar autoservicio y no se vincula |
| Borradores viejos que inflan la señal de ventas simultáneas | Política del §12.1 |
| Fuga de datos de otros clientes | Rutas propias sin `customer_id` + corrección P0 |

---

## 16. Matriz de ACL

Leyenda: ✔ permitido · ✖ negado · «propio» = solo lo suyo.

| Acción | Administrador (`all`) | Asesor Ventas (#2) | **Cliente frecuente (propuesto)** | Bodega (#4) | Servicio responsable | Validación de propietario / cliente | Riesgo de bypass hoy → propuesto |
|---|---|---|---|---|---|---|---|
| Acceso a Nueva venta | ✔ | ✔ `pedidos.create` | ✔ `autoservicio.comprar` (pantalla propia) | ✖ | Bouncer + `BorradorVentaService::exigirPermiso` | — | Bajo |
| Crear borrador | ✔ | ✔ | ✔ solo para su cliente | ✖ | `BorradorVentaService::crear` | Cliente = vínculo (servidor) | — → bajo |
| Consultar productos y matriz | ✔ | ✔ | ✔ sin señal de ventas simultáneas | ✖ (Nueva venta) | `CatalogoVentaService` | `customer_id` fijado | **Alto hoy** (customer_id libre) → bajo |
| Modificar carrito | ✔ propio | ✔ propio | ✔ propio | ✖ | `NuevaVentaService::aplicarTallas` | Propietario (`lockForUpdate`) + cliente | Bajo |
| Leer estado de un borrador | ✔ | ✔ **cualquiera hoy** | ✔ propio | ✖ | `NuevaVentaService::estado` | **Hoy ninguna** → propietario | **Crítico hoy** |
| Ver direcciones | ✔ | ✔ **cualquier cliente hoy** | ✔ solo su cliente | ✖ | `NuevaVentaController::direccionesCliente` | **Hoy ninguna** → cliente del borrador propio | **Crítico hoy** |
| Buscar o crear clientes | ✔ | ✔ | ✖ | ✖ | `BuscarClienteComercialService` / `CrearClienteComercialService` | — | — |
| Confirmar | ✔ | ✔ | ✔ | ✖ | `ConfirmarVentaService` | Propietario + token | Bajo |
| Consultar pedidos | ✔ todos | propios (`advisor_id`) | propios (`customer_id`) | vía Logística | `ConsultaPedidosAsesorService::visibles` | Alcance en SQL | — → bajo |
| Registrar pagos | ✔ | ✔ `cartera.registrar` | ✖ | ✖ | `RegistrarPagoService` | Alcance de cartera | Bajo (sin ruta) |
| Aplicar crédito | ✔ (admin autorizado) | ✖ | ✖ | ✖ | `CreditoClienteService` | `esAdministradorAutorizado` | Bajo |
| Cambiar grupo de la venta | ✔ | según rol | ✖ | ✖ | `GrupoVentaService` | Permiso propio | Bajo |
| Editar precios | ✔ `precios_grupo.editar` | ✖ | ✖ | ✖ | `EdicionPreciosGrupoService` | — | Bajo |
| Despachar | ✔ | ✔ (hoy tiene `despachos.despachar`) | ✖ | ✔ | Logística | — | Bajo |
| Validar entrega | ✔ | ✔ `entregas.registrar` | ✖ | ✔ | Logística | — | Bajo |
| Descargar guía | ✔ | propios | propios (por `customer_id`) | ✔ | `GuiaPedidoController` + Logística | Pedido del cliente | — → bajo |
| Consultar costos o rentabilidad | ✔ | ✖ (solo aviso sin cifras) | ✖ (mensaje neutro) | ✖ | `RentabilidadVentaService::paraAsesor` | — | Bajo |
| Comisiones | ✔ | propias | ✖ | ✖ | Comisiones | — | Bajo |

### 16.1 Ataques conceptuales

| Ataque | Resultado con la propuesta |
|---|---|
| Cambiar el `customer_id` en la petición | Las rutas de autoservicio no lo leen. El cliente sale del vínculo |
| Consultar un pedido ajeno (`?pedido=ID`) | El alcance `CLIENTE` en SQL + chequeo del registro → 404/403 |
| Modificar el borrador de otro usuario | `exigirPropietario` + cliente del carrito = vínculo → 409 |
| Cambiar el precio desde la petición | No hay campo de precio. RT-03 recalcula. `total_esperado` distinto → `TOTALES_CAMBIARON` |
| Confirmar un pago desde una ruta oculta | Bouncer: rutas `cartera.*` y `pedidos.pagos` no mapeadas para el rol → negado. Los servicios exigen sus claves |
| Leer comisiones | Sin claves `comisiones.*` → negado |
| Crear un pedido para otro cliente | Imposible elegir cliente. `iniciar` de asesor negado por el Bouncer |
| Usar las rutas de asesor (`nueva-venta/{borrador}`, direcciones) | Negadas por el Bouncer (sin `pedidos.create`) |

---

## 17. Wireframes textuales

### 17.1 Escritorio (≥ 1024 px), cliente frecuente

```
┌───────────────────────────────────────────────────────────────────────┐
│ Nueva venta     Comprando como: Tienda La 14 · Mayorista_VIP_1        │
│                                                [ Ventas en curso (1) ] │
├───────────────────────────────────────────┬───────────────────────────┤
│ [Dama] [Caballero] [Niño] ...  (accesos)  │ Resumen del pedido        │
│ Colores: (buscar)                         │  Camiseta Dama Blanca     │
│  ○ Blanco  ○ Negro  ○ Azul rey ...        │   S 2 · M 4 · L 2   $ ... │
│                                           │  Subtotal       $ 184.000 │
│ ┌ Matriz Dama · Blanco ─────────────────┐ │                           │
│ │ Talla  S    M    L    XL               │ │ Entrega                   │
│ │ Disp.  12   30   8    0                │ │  ○ Recoger en tienda      │
│ │ Cant. [2]  [4]  [2]  [—]   [Aplicar]   │ │  ○ Domicilio: Cra 7 #..   │
│ └────────────────────────────────────────┘ │                           │
│                                           │ ¿Cómo vas a pagar?        │
│                                           │  ○ Transferencia          │
│                                           │  ○ Contra entrega         │
│                                           │  Datos: Nequi 315...      │
│                                           │ Total          $ 184.000  │
│                                           │ [  Confirmar pedido  ]    │
└───────────────────────────────────────────┴───────────────────────────┘
```

Sin buscador de cliente, canal, «Vender para», tipo de operación, cambio de grupo, cotizar, crédito ni señal de otras ventas.

### 17.2 Móvil (390 px)

```
┌──────────────────────────────┐
│ Nueva venta       [En curso] │
│ Tienda La 14 · VIP_1         │
├──────────────────────────────┤
│ [Dama][Caballero][Niño] →    │
│ Colores ▾ Blanco             │
│ ┌ Dama · Blanco ───────────┐ │
│ │ S  disp 12   [ 2 ]       │ │
│ │ M  disp 30   [ 4 ]       │ │
│ │ L  disp 8    [ 2 ]       │ │
│ │ XL agotado   [ — ]       │ │
│ │          [ Aplicar ]     │ │
│ └──────────────────────────┘ │
├──────────────────────────────┤
│ 8 prendas · $ 184.000  [Ver] │  ← barra fija
└──────────────────────────────┘
(«Ver» abre el resumen, la entrega, el pago y «Confirmar pedido»)
```

### 17.3 Mis pedidos (escritorio / móvil)

```
Mis pedidos
┌─────────────┬────────────┬────────────┬───────────────┬──────────┐
│ Pedido      │ Fecha      │ Total      │ Estado        │ Guía     │
│ #BYL-…123   │ 22/11/2026 │ $ 184.000  │ En preparación│ —        │
│ #BYL-…101   │ 10/11/2026 │ $ 92.000   │ Despachado    │ 7001234  │
└─────────────┴────────────┴────────────┴───────────────┴──────────┘
Móvil: una tarjeta por pedido (número, fecha, total, estado, guía) → toca para ver el detalle.
Detalle: líneas (producto, color, talla, cantidad, valor), entrega, guía/PDF, «¿Necesitas cambiar algo? Escríbenos».
```

### 17.4 Ventas en curso (todos los roles)

Solo los borradores con productos: «#123 · 8 prendas · $ 184.000 · actualizado hace 2 h · [Reanudar] [🗑]». Una venta con más de 15
días muestra «Por vencer el dd/mm». Las vacías no aparecen.

---

## 18. Plan técnico por etapas [PROPUESTA]

**Orden recomendado** (commits independientes): **P0 → P7 → P1 → P2 → P3 → P4 → P5 → P6 → P8**, con **P9** como pruebas dentro de
cada fase y un cierre global. P7 va antes porque beneficia a todos los roles, reduce el riesgo y prepara la pantalla para el modo
autoservicio.

| Fase | Objetivo | Archivos implicados | Reutiliza | Migración | Riesgos | Depende de | Pruebas / aceptación |
|---|---|---|---|---|---|---|---|
| **P0** Seguridad actual | Propietario en `GET nueva-venta/{borrador}`. Direcciones solo del cliente de un borrador propio | `NuevaVentaController` (`estado`, `direccionesCliente`), `NuevaVentaService::estado` (guarda opcional) | `exigirPropietario` | No | La pantalla del asesor pide direcciones por `customer_id` antes de tener borrador → cambiar a «por borrador» | — | Asesor B lee el borrador de A → 409. Direcciones de un cliente ajeno → 403. Recorrido del asesor intacto |
| **P7** Borradores vacíos | Creación diferida (A) + filtro + «descartar vacía» | `NuevaVentaService` (+`iniciarConTallas`), `NuevaVentaController`, `routes.php` + `acl.php` (ruta nueva bajo `pedidos.create`), `BorradorVentaService::listarDelPropietario`, `index.blade.php` (catálogo sin borrador, UUID, sessionStorage) | `crear`, `aplicarTallas`, UNIQUE de referencia | No | Commit implícito dentro del lote. Regresión de la pantalla (4.645 líneas) | P0 | Elegir cliente + consultar 4 matrices + cerrar → 0 filas nuevas en `borradores_venta` y `cart`. Primera talla con doble clic → 1 borrador. Lote rechazado por stock → 0 borradores. Quitar todo → oculto. Navegador real a 390 y 1366 px |
| **P1** Vínculo usuario-cliente | Tabla + servicio + pantalla «Accesos de clientes» | Migración nueva, modelo, `ClienteActualService`, controlador y vista, `acl.php`, `menu.php` | Patrón ASESOR-01 (`bylopez_va_asesores`), `BuscarClienteComercialService` | **Sí** (1 tabla + eventos) | Vincular a un rol `all` o asesor | — | Vínculo único por usuario. Un rol `all` o con `operar_como_asesor` se rechaza. Bitácora completa |
| **P2** Permisos | Claves `autoservicio.*` y `clientes_acceso.manage`. Rol CLIENTE FRECUENTE limpio | `acl.php`, `AutorizacionVentasAsesor`, `BorradorVentaService::exigirPermiso`, `ConfirmarVentaService::exigirPermiso` | Bouncer, que falla cerrado | No (los permisos del rol se cambian por la interfaz, con aprobación) | Regla 3 de redirección del login | P1 | Prueba de árbol ACL. Cliente frecuente contra cada ruta de asesor, cartera, comisiones y logística → negado |
| **P3** Nueva venta en modo autoservicio | `AutoservicioController` + modo en la vista | Controlador nuevo, rutas, `CatalogoVentaService::matriz` (sin señal), vista con `modo` y mapa de rutas | Todos los servicios de Venta | No | Ramas `v-if` en la vista grande | P2, P7 | Ningún `customer_id` aceptado. Precios = grupo del vínculo. Sin señal de ventas simultáneas en la respuesta |
| **P4** Confirmación sin pago | Intención de pago con allowlist propia. Propiedad EMPRESA fija. Mecanismo y canal | `config/ventasasesor.php` (`autoservicio.metodos_pago`), `MecanismoCreacion`, `CanalVenta`, `AutoservicioController::confirmar` | `PagoBorradorService`, `ResolverAtribucionService` | No (columnas varchar) | Que `moneytransfer` cambie el estado del pedido (PAG-F) | P3 | Pedido con `company`, `advisor_id` NULL, 0 pagos, 0 Transactions, `total_esperado` respetado, doble confirmación = mismo pedido |
| **P5** Mis pedidos y modal | Alcance `CLIENTE` + vista restringida + guía + modal | `AlcanceVisibilidad`, `ConsultaPedidosAsesorService::visibles`, vista nueva, ruta de guía de autoservicio | Consulta y Logística | No | Fuga de campos internos en la vista | P4 | Pedido ajeno → 404/403. HTML sin costos, comisiones ni atribución. Guía ajena → 403 |
| **P6** Bodega | Verificar el recorrido completo. Textos en Despachos («Origen: autoservicio») | Solo lectura en Logística o una etiqueta | Logística | No | Ninguno si solo se etiqueta | P4 | Pedido de autoservicio → despacho parcial → entrega → visible en Mis pedidos |
| **P8** Expiración | Comando `ventas-asesor:borradores:mantener`: reconcilia `confirmando`, descarta vacíos a las 24 h y con productos a los 30 días, nunca borra filas | Comando, scheduler, `EstadoBorradorVenta` o evento con motivo | `descartar`, `reconciliar` | Posible (motivo, D-CF-10) | Descartar trabajo real. **Cron pendiente en el VPS** | P7 | Simulación con fechas. Un borrador con cotización respeta su plazo. Nada con `order_id` |
| **P9** Pruebas | Funcionales, concurrencia (`tests/Concurrencia`: dos clientes por la última unidad, doble confirmación, descartar contra guardar), seguridad (§16.1), responsive | Pest dirigido y secuencial, navegador real | Suites existentes | — | Pruebas lentas (40–90 s por borrador) | Todas | Base exclusiva o ids por corrida |

---

## 19. Concurrencia y casos límite

| # | Caso | Comportamiento esperado | Riesgo | Protección |
|---|---|---|---|---|
| 1 | Dos pestañas | Cada una con su UUID. Solo las que agregan prendas crean borrador. Para el cliente, opcionalmente una sola venta (D-CF-09) | Ventas duplicadas | Referencia idempotente. Lista de en curso |
| 2 | Consulta sin comprar | 0 borradores, 0 carts | — | Creación diferida (P7) |
| 3 | Agrega y pierde conexión | Lo aplicado ya está en el servidor. Reintento con el mismo lote → mismo resultado | Lote aplicado sin respuesta | Lote todo o nada + aviso existente + reanudar |
| 4 | Elimina todas las prendas | Borrador oculto. «Descartar venta vacía». Limpieza a las 24 h | Descartar mientras otra pestaña agrega | No se descarta en la misma petición |
| 5 | Confirma dos veces | Un solo pedido (`ya_existia`) | — | Token D-55 + UNIQUE `order_id` |
| 6 | Sin stock entre consulta y confirmación | Rechazo `STOCK_INSUFICIENTE` con las variantes | — | Cerco D-54 |
| 7 | El precio cambia con el borrador abierto | El estado muestra el total nuevo. Confirmar con el total viejo → `TOTALES_CAMBIARON` y aviso «Total actualizado» | — | `total_esperado` |
| 8 | Borrador de varias semanas | «Por vencer» desde el día 15, descarte a los 30 | Señal de ventas simultáneas inflada | P8 |
| 9 | Usuario desactivado con borradores | El Bouncer lo expulsa. Sus borradores se descartan por la política. Administración puede descartarlos | Huérfanos visibles en la señal | P8 + vínculo inactivo |
| 10 | Bodega empieza a preparar | El cliente no puede editar (V1) | — | Sin rutas de edición |
| 11 | Pide cambiar un pedido confirmado | Mensaje «Escríbenos». Bodega o Administración cancelan y rehacen con las herramientas actuales | Cambios sin auditoría | D-CF-11 |
| 12 | Asesor y cliente operan a la vez | Borradores distintos. El cerco serializa la confirmación | Competencia por la misma unidad | D-54 |
| 13 | Dos compran la última unidad | El primero confirma. El segundo recibe `STOCK_INSUFICIENTE` | — | D-54 + reserva |
| 14 | Borrador «vacío» con datos asociados | No se descarta si tiene cotización, eventos de grupo o clasificación. Usa la regla «con relaciones» | Pérdida de evidencia | Consulta de relaciones en P8. Filas nunca borradas |
| 15 | Se descarta mientras otra pestaña guarda | La otra recibe el error de borrador no editable (409) | — | `lockForUpdate` + estado `descartado` |

---

## 20. Decisiones que necesitan la aprobación de Camilo

| Id | Decisión | Recomendación |
|---|---|---|
| **D-CF-01** | ¿Un cliente puede tener varios usuarios (por ejemplo, dueño y empleado)? | V1: **1 usuario ↔ 1 cliente** (UNIQUE en las dos columnas). Ampliar después |
| **D-CF-02** | ¿Qué hacer con los roles 12069/12070 (y 12071 BODEGA `all`) y los usuarios QA 12137/12138/12139? | Reutilizar **#12070** con permisos nuevos. Eliminar #12069 y #12071 si no tienen usuarios en producción. En dev, retirar los usuarios QA. **Revisar producción antes** |
| **D-CF-03** | Propiedad comercial del pedido de autoservicio | **Empresa (`company`)**, sin comisión |
| **D-CF-04** | Canal y mecanismo | Valores nuevos `autoservicio` (canal y mecanismo) |
| **D-CF-05** | Métodos de pago que puede elegir el cliente | Transferencia y contra entrega (los mismos del asesor). ¿Contra entrega para todos los clientes frecuentes? |
| **D-CF-06** | Domicilio local (necesita domiciliario) | Aplicar automáticamente el **sugerido** por Logística (último de la zona con tarifa). Si no hay, no ofrecer domicilio local en autoservicio |
| **D-CF-07** | ¿El cliente puede guardar direcciones nuevas en su ficha? | Sí, con el mismo servicio RT-04 |
| **D-CF-08** | ¿El stock se muestra exacto? | Exacto hasta 20 y «+20» por encima. Alternativa: exacto siempre |
| **D-CF-09** | ¿Una sola venta en curso por cliente? | Sí, en autoservicio: al entrar se reanuda la última |
| **D-CF-10** | Vencimiento: vacío 24 h, con productos 15 días de aviso y 30 de descarte, con cotización contando desde la última cotización. ¿Estado nuevo `vencido` o `descartado` con motivo? | `descartado` + evento con motivo (sin migración si cabe en una tabla de eventos existente; si no, una columna) |
| **D-CF-11** | Cambios después de confirmar | V1 bloqueado + «Escríbenos». Solicitud formal en una fase posterior |
| **D-CF-12** | ¿El cliente puede cancelar su pedido antes de que se prepare? | No en V1 |
| **D-CF-13** | Mensaje cuando la rentabilidad bloquea un pedido de cliente | Neutro, sin cifras («Escríbenos y te ayudamos») |
| **D-CF-14** | Corregir ya los dos problemas de seguridad que existen hoy (P0) | **Sí, primero** |
| **D-CF-15** | Cambiar el grupo temporal antes de la primera talla | Crea el borrador (oculto sin productos) y registra el evento |
| **D-CF-16** | Limpiar en dev los 26 vacíos y reconciliar los 9 `confirmando` | Después de P8, con el comando (no a mano) |
| **D-CF-17** | ¿El cliente puede cotizar? | No en V1 |
| **D-CF-18** | Nombre del menú para el cliente | «Hacer pedido» y «Mis pedidos» |
| **D-CF-19** | ¿El cliente ve el estado de pago? | Solo «Pendiente / Pagado», sin aplicaciones ni custodia |

---

## 21. Lo que NO se hizo en esta fase

Sin código, migraciones, cambios de roles o permisos, borrados (los 26 vacíos y los 9 `confirmando` siguen intactos), cambios de
precios, comisiones o Logística, Webkul, commits, push ni despliegue. Producción no se consultó. El worktree de QA no se tocó.

---

# 22. Decisiones definitivas v2 y estado de implementación (rama `feature/cliente-frecuente`)

> Esta sección **reemplaza** a §20 donde haya diferencias. Fuente: instrucción de Camilo del 2026-11-22. Todo se probó SOLO en la base
> exclusiva `lpz_cf_test` (copia de `lpz_saas_testing`); nada se ejecutó en desarrollo, preproducción ni producción; sin push ni despliegue.
> Estados: **Aprobado / Implementado / Probado / Pendiente / Requiere decisión**.

## 22.1 Decisiones

| Tema | Decisión v2 | Estado |
|---|---|---|
| D-CF-02 roles | Rol NUEVO e idempotente `CLIENTE FRECUENTE AUTOSERVICIO` (por nombre, nunca por id) con SOLO `autoservicio`, `autoservicio.comprar`, `autoservicio.pedidos`. Los roles de QA (12069/12070/12071) no se reutilizan, no se tocan y no se borran: el comando los lista como «no seguro». | Implementado, probado |
| Gestión de clientes | No hay segunda pantalla. Acceso desde la **ficha nativa del cliente** (hook de Bagisto) con tarjeta + modal. | Implementado, probado (ver 22.3) |
| Un usuario por cliente | `UNIQUE(admin_id)`, SIN `UNIQUE(customer_id)`; V1 limita a un acceso activo/suspendido por cliente en el servicio. | Implementado, probado |
| D-CF-08 inventario | Agotado / 1–10 exacto / «Más de 10 disponibles»; el stock real no cambia y el servidor valida cantidades exactas. | Implementado, probado |
| Grupo comercial | El cliente nunca ve su grupo: solo «Tu precio mayorista». Se quita de toda respuesta JSON (`grupo*`). | Implementado (JSON probado; texto en pantalla sin navegador) |
| D-CF-05/06 entrega y pago | Recogida en tienda + transferencia por defecto (`preparar`), sin elegir, sin dinero recibido. | Implementado, probado |
| Propiedad | EMPRESA (sin comisión), canal `ecommerce`, creador = el usuario del cliente. | Implementado, probado |
| D-CF-09 varios borradores | Varios borradores independientes por cliente (una referencia por intento). | Implementado, probado |
| D-CF-10 vencimiento | Vacíos 24 h; con productos 3 días de inactividad; `confirmando` nunca se descarta solo. | **Pendiente (P8)**, ver 22.5 |
| D-CF-11 edición de pedidos | Solo diseño (22.6). No se implementa. | Pendiente (diseño) |
| D-CF-13 rentabilidad | Se conserva la validación; al cliente: «No podemos confirmar este pedido en línea. Comunícate con nuestro equipo para ayudarte.» | Implementado (mapeo de códigos probado; el código real de rentabilidad no se disparó en prueba) |

## 22.2 Fases

| Fase | Commit | Resultado |
|---|---|---|
| P0 Seguridad | `ef27a51df9` | Cerradas las lecturas ajenas: estado de borrador, direcciones de cliente, matrices/precios con borrador ajeno o de otro cliente. 5 pruebas de ataque. |
| P7 Ventas en curso | `228652c469` | Consultar inventario no crea borrador; `iniciar-con-tallas` atómico e idempotente; «en curso» solo con productos. 9 pruebas. |
| P2 Rol seguro | `f11037819b` | `RolClienteFrecuente` + comando `ventas-asesor:rol-cliente-frecuente` (vista previa; `--aplicar` crea). 6 pruebas. |
| P1 Acceso de clientes | `79a3507f11` | Tablas `bylopez_va_clientes_acceso(+_eventos)`, servicio, rutas, tarjeta en la ficha, «Habilitar acceso» al crear cliente. 9 pruebas. |
| P3+P4 backend | `70f4c5f390` | Autoservicio (cliente desde el vínculo), saneador de inventario, preparar/confirmar. 16 pruebas. |
| P5 Mis pedidos | `ea26579276` | Solo pedidos del cliente vinculado; ajeno = 404 igual que inexistente. 5 pruebas. |
| P3 vista + middleware | (siguiente commit) | Misma vista de Nueva venta en modo cliente; deny-by-default para el usuario de autoservicio. |

## 22.3 Hallazgos de la implementación (relevantes para Camilo)

1. **«La tabla de Clientes de Ventas Asesor» no existe en este módulo.** La tabla de clientes es la de Bagisto (Webkul) y no se puede modificar. Por eso NO hay columna «Acceso al aplicativo», filtro por estado ni contadores en la grilla; el estado, los contadores (activos / suspendidos / sin acceso) y las acciones están en la **tarjeta de la ficha del cliente**. Alternativas si se quiere la columna: (a) modificar el DataGrid de Webkul (prohibido hoy), (b) una vista propia de solo lectura «Accesos de clientes» (rechazada en la instrucción: no crear otra pantalla de clientes). **Requiere decisión.**
2. **Rutas nativas de Bagisto sin ACL** (cualquier administrador autenticado entra): `get-notifications` devolvía pedidos de **todos** los clientes; `account`, `help`, `notifications` también. Se cerraron con el middleware `RestringirUsuarioAutoservicio` (deny-by-default: el usuario de autoservicio solo llega a `admin.autoservicio.*` y a cerrar sesión). Esto ya ocurría para cualquier administrador autenticado (comprobado con el rol de acceso total; no se probó con un rol custom): conviene revisarlo aparte.
3. **Regla 3 del ACL**: la hoja que Bagisto usa para redirigir tras el login debe tener ruta en texto, no lista; por eso las rutas de la API están en la clave de grupo `autoservicio` y cada operación exige además su hoja (`autoservicio.comprar|pedidos`) en `ClienteActualService`.
4. **Último acceso**: Bagisto no registra el ingreso de usuarios administrativos; la ficha dice «Sin información» y no inventa el dato.
5. **Cliente sin dirección registrada** (típico de un registro en la tienda): la recogida exige una dirección de facturación; hoy el cliente recibe «Necesitamos una dirección registrada… Comunícate con nuestro equipo». Mejora posible: formulario de dirección en autoservicio. **Requiere decisión.**
6. **Pruebas preexistentes que fallan en la base `9e3d45e785` sin tocar nada** (no son de esta rama): `K/M/N Confirmar domicilio con transferencia…` y `L/M/N guardar en el cliente…` (Fase03gd). La prueba de rendimiento `03G-B2 sin N+1` depende de la velocidad de la máquina.
7. **Trampa de pruebas**: cualquier dato persistente que se siembre en la base de pruebas (p. ej. una categoría `camiseta-caballero`) rompe `colores de un acceso…` por slug ambiguo.

## 22.4 Contraseñas y autenticación (§15; sin implementar cambios)

- **Cliente de la tienda** (`customers`): guard `customer`, hash bcrypt propio, recuperación por correo de Bagisto (`Shop\ForgotPasswordController`).
- **Usuario administrativo** (`admins`): guard `admin`, recuperación con `Admin\ForgetPasswordController` (broker `admins`, tabla `admin_password_resets`); cambio voluntario en `admin/account` (hoy bloqueado al cliente por el middleware); sin «forzar cambio inicial».
- **Lo implementado**: el usuario nace con una contraseña aleatoria que nadie conoce (no se copia ningún hash) y se envía el enlace de recuperación. Si el correo falla, la habilitación NO se deshace y la ficha explica que puede usar «¿Olvidaste tu contraseña?».
- **Propuesta fase posterior**: (1) «Reenviar enlace» desde la ficha; (2) restablecimiento por administrador = generar un enlace de un solo uso (nunca ver ni fijar la contraseña) con evento de auditoría; (3) pantalla propia de «Cambiar mi contraseña» en autoservicio (el middleware hoy bloquea `admin/account`); (4) caducidad del enlace de activación y recordatorio.

## 22.5 P8 — expiración (política propuesta, NO implementada)

- Vacíos sin relaciones: descartar tras 24 h sin actividad (el borrador ya no nace vacío con P7; quedan los heredados).
- Con productos: vencidos tras **3 días completos** sin actividad. El «descarte» actual (`BorradorVentaService::descartar`) **borra el carrito** (pérdida irreversible del trabajo). **Riesgo documentado**: no se debe borrar a los 3 días sin una recuperación.
- Alternativa propuesta: estado `vencido` (descarte lógico con motivo y fecha) que conserva el carrito 30 días y permite «Reanudar» (vuelve a `activo` si el stock lo permite); solo después de 30 días se elimina el carrito, nunca la fila ni sus cotizaciones/solicitudes (varias tablas tienen `borrador_id` sin FK).
- `confirmando`: reconciliar con el lease (D-55) y el pedido existente antes de cualquier descarte; nunca descartar solo.
- Medir actividad con `cart.updated_at` (el `updated_at` del borrador no refleja la edición de líneas).
- Prueba con fechas simuladas únicamente en la base exclusiva. **Requiere decisión**: aprobar el estado `vencido` + 30 días.

## 22.6 P10 — diseño de edición de pedido confirmado (NO implementado)

- **Reordenar de Bagisto** (`Admin\OrderController::reorder`): crea un carrito NUEVO e inactivo del mismo cliente con las líneas del pedido y lo abre en el editor de carrito del panel. **No modifica** el pedido; además salta el borrador comercial (RT-03/D-54/D-55). No sirve como «editar pedido».
- Bagisto 2.4.9 no trae edición de pedidos confirmados.
- Propuesta A (segura, corto plazo) **«Repetir pedido»** en autoservicio: crear un borrador nuevo con `iniciar-con-tallas` por cada producto del pedido anterior (precios y stock actuales).
- Propuesta B (edición real, fase posterior): **cancelar-y-reemplazar** transaccional: (1) bloquear el pedido (`FOR UPDATE`); (2) rechazar si hay Shipment, despacho parcial, factura/pago registrado, devolución o guía; (3) crear un borrador nuevo ligado al pedido (`reemplaza_order_id`); (4) al confirmar, cancelar el original (libera reservas con el mecanismo de cancelación existente) y crear el nuevo en la misma operación, con el mismo token D-55; (5) si cambió el precio, conserva el del momento de la edición y lo muestra; (6) si falta stock, no se cancela nada; (7) auditoría y número visible «reemplaza #X». Requiere pruebas de concurrencia con Bodega.
- Bloqueos: despachado, existe Shipment, despacho parcial, pago registrado, etapa logística iniciada, imposible recalcular reservas.

## 22.7 Cambio posterior de envío (§11.1; diseño, NO implementado)

- Hoy Logística clasifica por `orders.shipping_method` (`pickup` / `domicilio_local` / `envio_nacional`) y el despacho de un pedido de recogida es la entrega al cliente: **asignar transportadora al despachar NO cambia la modalidad comercial**, así que no resuelve pasar de recogida a domicilio.
- Cambiar la modalidad exige tocar `shipping_method`, dirección, tarifa, quién paga el flete y, si había cobro, la obligación del cliente. Propuesta: servicio `CambioEntregaPedido` con (a) solo pedidos sin Shipment ni guía; (b) consentimiento explícito del cliente registrado (quién, cuándo, canal); (c) recálculo de flete con el mecanismo de tarifas vigente y registro como ajuste de obligación (nunca silencioso); (d) transportadora y valor de flete en el evento; (e) auditoría y aviso al cliente; (f) bloqueo si hay pagos aplicados que cambiarían el saldo sin aprobación de Cartera.
