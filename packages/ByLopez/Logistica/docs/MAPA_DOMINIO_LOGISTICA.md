# Mapa del dominio Logística (ByLopez\Logistica)

**Fecha:** 2026-10-09 · **Estado:** LOG-01 y **DESPACHO V1 (§11)** **IMPLEMENTADOS EN DESARROLLO** (sin commit, sin producción) · **Responsable funcional:** Camilo Gaitán

> Documento permanente del paquete `packages/ByLopez/Logistica/`. Decisiones en `packages/ByLopez/VentasAsesor/docs/04_DECISIONES_PENDIENTES.md` §LOG-D. Si este documento contradice el código, manda el código.

## 1. Despacho ≠ entrega

| Hecho | Fuente | Quién lo escribe | Significa |
|---|---|---|---|
| Pedido `completed` | `orders.status` (Bagisto) | Bagisto | Facturado y despachado por completo. **No es entrega** |
| Factura / pago | Bagisto + Cartera (VentasAsesor) | Bagisto / Cartera | Cobro. **No es entrega** |
| **Despachado** | `shipments` + `shipment_items` (Bagisto) | Panel nativo de Bagisto | La mercancía salió (descuenta inventario). **No se duplica** en tablas propias |
| Guía / tracking | `shipments.track_number` | Panel nativo | Referencia. **No es entrega** |
| Cita de recogida | `pickup_orders` (bagisto-eu/pickup) | Checkout | Fecha y franja acordadas. **No es retiro ni entrega** |
| **Entregado** | `bylopez_lg_entregas` + `_items` | **Este paquete** | El cliente recibió esas unidades en esa fecha |
| **Retornado por transportadora** | `bylopez_lg_retornos` + `_items` | **Este paquete** | La transportadora no entregó y devolvió esas unidades |
| Devolución del cliente / reembolso | RMA / Refund (Fase 07) | — | **Fuera de LOG-01** |

Regla: **nunca** se infiere «entregado» de `orders.status`, de la existencia de un Shipment, de una Invoice ni del tracking.

## 2. Modelo de datos (migraciones `2026_10_08_1000xx`)

| Tabla | Clave | Columnas relevantes | Integridad |
|---|---|---|---|
| `bylopez_lg_transportadoras` | `codigo` UNIQUE | `nombre`, `tipo` (propia, transportadora, domiciliario, recogida, otra), `activo` | Se desactiva, no se borra; `codigo` no se edita |
| `bylopez_lg_entregas` | `idempotency_key` UNIQUE | `order_id`, `shipment_id`, `fecha_entrega` (real), `fuente`, `actor_admin_id` + `actor_name_snapshot`, `carrier_code_snapshot`, `carrier_title_snapshot`, `track_number_snapshot`, `evidencia_referencia`, `estado` (confirmado/anulado), `huella`, `motivo_anulacion`, `anulada_por_admin_id`, `anulada_por_name_snapshot`, `anulada_en`, timestamps (`created_at` = fecha de registro) | FK RESTRICT a `orders` y `shipments`; CHECK de estado; CHECK «anulado ⇔ campos de anulación completos» |
| `bylopez_lg_entrega_items` | (`entrega_id`, `shipment_item_id`) UNIQUE | `shipment_item_id`, `order_item_id`, `cantidad` | FK RESTRICT a `shipment_items` y `order_items`; CHECK `cantidad > 0` |
| `bylopez_lg_retornos` / `_items` | Igual que las entregas | `fecha_retorno`, `motivo` (obligatorio) | Igual que las entregas |

- Ids de Bagisto: `int(10) unsigned`.
- El actor va **sin FK**, con nombre snapshot: si se borra un admin, la auditoría sobrevive.
- `shipment_items.order_item_id` apunta a la línea **padre** (el configurable); es el mismo ancla que el snapshot de costo de COM-01.

## 3. Invariantes

1. Por `shipment_item`: **Σ entregado vigente + Σ retornado vigente ≤ `shipment_items.qty`**. Se valida bajo `FOR UPDATE` del Shipment, lo que serializa los registros concurrentes del mismo envío.
2. Solo se registran unidades **despachadas en ese envío**. Se rechazan: envío inexistente, envío de otro pedido, cantidad ≤ 0 o no entera, cantidad mayor que lo pendiente, fecha futura, fecha anterior al despacho y actor sin permiso.
3. **Inmutabilidad**: una entrega o un retorno no se edita ni se borra. La única escritura posterior es la **anulación auditada** `confirmado → anulado`, con motivo, quién y cuándo. Corregir = anular + registrar el evento correcto.
4. **Idempotencia**: la misma `idempotency_key` con la misma solicitud (`huella` sha256) devuelve el **mismo** registro. La misma clave con otra solicitud da `idempotencia_conflicto`. El UNIQUE es la última barrera.
5. El estado de entrega del pedido es **derivado**; no existe `orders.delivery_status`:
   - `despachadas` = Σ `shipment_items.qty`
   - `entregadas` = Σ unidades de entregas vigentes
   - `retornadas` = Σ unidades de retornos vigentes
   - `pendientes` = despachadas − entregadas − retornadas

## 4. Fuentes (`FuenteEventoLogistico`)

| Fuente | Cuándo (en el panel la decide el sistema) |
|---|---|
| `recogida_bodega` | Entrega con transportadora `RECOGIDA_BODEGA` o pedido con método `pickup`: el cliente **retiró** |
| `supervisor_manual` | Quien registra tiene `logistica.entregas.anular` (admin / supervisión) |
| `bodega_manual` | El resto (Bodega) |
| `integracion_transportadora` | **Reservada.** Se rechaza hasta que exista la integración (99envíos es radar) |

Un retorno nunca usa `recogida_bodega`.

## 5. Transportadoras (LOG-D-04)

- **Valores iniciales:** `BYLOPEZ`, `99ENVIOS`, `INTER_RAPIDISIMO`, `DOMICILIARIO`, `RECOGIDA_BODEGA`, `OTRA`. Son los conceptos aprobados por Camilo, cargados después de auditar los textos reales.
- **Históricos en desarrollo (sin convertir):** BYLOPEZ 13, BRAYAN 3, OPPS 1, INGRI 1, INTERRAPIDISMO 1, RECOLECCION INGRID 1, RECOGE EN BODEGA 1, PICAP 1 y vacío 1. Siguen con `carrier_code` NULL. La pantalla «Transportadoras» los lista solo para revisión.
- **Despacho nuevo (bloque 11, sin tocar Webkul):** el listener `AsignarCodigoTransportadoraAlDespacho` (`sales.shipment.save.after`) completa `shipments.carrier_code` en dos casos:
  - la petición trae `shipment[carrier_code]` de una transportadora activa;
  - el texto escrito es **exactamente** el código o el nombre de una transportadora activa (se normalizan mayúsculas, espacios y guion bajo; no se quitan tildes ni se aproxima).

  Si no hay coincidencia exacta, el código queda NULL y la entrega pide elegir una transportadora del catálogo. Esa elección se guarda en el snapshot de la entrega; el Shipment no se modifica.
- **Domiciliarios:** se usa el código genérico `DOMICILIARIO`. No hay datos personales ni módulo de personas.

## 6. ACL (`logistica.*`) y roles

| Clave | Autoriza |
|---|---|
| `logistica` | Raíz; abre la bandeja |
| `logistica.entregas` | Ver la bandeja y el detalle de un envío (`?envio=ID`) |
| `logistica.entregas.registrar` | Registrar entregas y retornos (formularios y POST) |
| `logistica.entregas.anular` | Anular entregas, devoluciones y recepciones por corrección |
| `logistica.entregas.recibir_devolucion` | Recibir físicamente una devolución en Bodega (reingresa las aptas al inventario) |
| `logistica.entregas.validar` | Validar la entrega (hito de comisión y cierre) y registrar el cobro contra entrega por Cartera (ADMIN / SUPERVISION; no se asigna a Bodega) |
| `logistica.transportadoras` | Ver el catálogo y los textos históricos |
| `logistica.transportadoras.manage` | Agregar, activar y desactivar transportadoras |

- **BODEGA:** `logistica`, `.entregas`, `.entregas.registrar`.
- **ADMIN / SUPERVISIÓN:** además `.entregas.anular` y `.transportadoras(.manage)`. Un rol `all` lo tiene todo.
- **ASESOR VENTAS:** ninguna. No registra ni anula (probado: 401 por HTTP y `sin_permiso` en el servicio).
- Los servicios validan siempre; ocultar un botón no autoriza nada.
- Las claves reservadas `ventas_asesor.logistica(.view/.manage)` de VentasAsesor quedan **sin uso**: la entrega es de este paquete.
- En desarrollo **no se modificó ningún rol**. Los permisos se asignan en Configuración › Roles.

## 7. Servicios y UI

- **Servicios:**
  - `RegistrarEntregaService` y `RegistrarRetornoTransportadoraService` (base común `RegistrarEventoEnvio`).
  - `AnularEventoLogisticoService`.
  - `ConsultaEntregaService`: saldos, resumen por pedido, bandeja, indicadores, detalle y contrato con COM-04.
  - `TransportadorasService`.
- **Controladores delgados:** `EntregasController`, `TransportadorasController`.
- **Menú «Logística»** con dos pantallas:
  - **Entregas:** KPI, filtros, tabla en escritorio y tarjetas en móvil; detalle del envío con unidades, historial de entregas y retornos y anulación con motivo; formularios separados de entrega y de retorno, con cantidades por ítem, fecha real, transportadora si el envío no la tiene, evidencia opcional, clave de idempotencia y bloqueo de doble envío.
  - **Transportadoras.**
- **Pedido nativo:** el botón «Entregas (entregadas/despachadas)» se agrega con el hook `bagisto.admin.sales.order.page_action.before`.

## 8. Contrato con VentasAsesor (solo lectura)

- `ConsultaEntregaService::unidadesEntregadas(array $orderIds)` devuelve filas `{order_id, order_item_id, shipment_item_id, entrega_id, cantidad, fecha_entrega}`. Incluye solo entregas vigentes; un retorno nunca aparece.
- `resumenPedido(int)` y `resumenPedidos(array)` devuelven despachadas, entregadas, retornadas y pendientes.
- VentasAsesor (COM-04) **lee**; nunca escribe en `bylopez_lg_*`.

## 9. Fuera de alcance / radar

- **Tracking intermedio** (en tránsito, entrega fallida…): no se construye (LOG-D-03).
- **Integración 99envíos:** futura. Entrará como fuente `integracion_transportadora`, con su propia `idempotency_key` (`99envios:<guía>:<evento>`).
- **Reingreso físico a inventario de una devolución:** ver §12 (2026-10-13). LOG-01 (registrar la devolución) sigue sin tocar inventario; el reingreso ocurre solo al **recibir** la devolución en Bodega.
- **RMA / devolución del cliente:** Fase 07.
- **Corrección de los textos históricos:** solo manual y con decisión de Camilo; nunca automática.

## 10. Pruebas

`packages/ByLopez/Logistica/tests/Feature/Log01EntregaCanonicaTest.php`: **9/9** (99 aserciones), en `lpz_saas_testing`.

| Prueba | Cubre |
|---|---|
| LOG-1 | Entrega completa con snapshots. Código estable de un despacho nuevo; el texto no exacto queda NULL |
| LOG-2 | Entrega parcial (2 + 3) y rechazo del exceso |
| LOG-3 | Idempotencia: mismo registro; conflicto con otra solicitud; clave inválida |
| LOG-4 | Validaciones (exceso, ítem ajeno, otro pedido, 0 / negativo / vacío, fecha futura o anterior al despacho, envío inexistente, fuente no disponible, transportadora requerida o inválida). El despacho no se toca |
| LOG-5 | Anulación auditada; inmutabilidad de evento e ítems; nueva entrega correcta |
| LOG-6 | Retorno separado, motivo obligatorio; no es entrega; sin Refund; anulación del retorno |
| LOG-7 | El asesor no registra ni anula (servicio y HTTP 401) |
| LOG-8 | Bodega registra por el panel (`bodega_manual`) y no anula ni gestiona; el admin ve, anula y crea una transportadora |
| LOG-9 | La cita de recogida no es entrega; el retiro se registra como `recogida_bodega`. En testing no existe la tabla `pickup_orders`, así que esa rama solo se ejecuta si la tabla existe |


## 11. DESPACHO V1 — «Registrar guía y despachar» (2026-10-09)

**Estado:** IMPLEMENTADO EN DESARROLLO (sin commit, sin producción). Sin APIs de transportadoras, sin comparador de tarifas, sin WhatsApp automático.

### 11.1 Qué hace
- Una operaria despacha un pedido en pocos segundos **sin salir del listado**: **Despachar** → drawer con el resumen → transportadora y guía → flete y recaudo → PDF opcional → **Registrar guía y despachar**. Al guardar se cierra el drawer, se avisa y **solo se reemplaza la fila del pedido** (y los indicadores), sin recargar. El foco pasa al siguiente pedido pendiente.
- **Un solo componente** (`admin/despachos/componente.blade.php`, drawer Vue) y **un solo conjunto de servicios** para las dos puertas:
  - BODEGA: bandeja **Logística › Despachos**.
  - Administrador: botón en el listado de **Pedidos** (Ventas Asesor).
- Acciones **contextuales** por estado de la fila:

| Estado del pedido | Acción principal | Otras |
|---|---|---|
| Sin despachar | **Despachar** | — |
| Sin despachar (recogida) | **Entregar en bodega** | — |
| Despacho parcial | **Completar despacho** (recogida: Entregar en bodega) | Ver envío |
| Despachado | **Ver envío** | Ver guía / Descargar guía, solo si hay PDF |

### 11.2 Modelo: el dato y el documento, separados
| Tabla | Es | Claves |
|---|---|---|
| `bylopez_lg_guias` | El **dato del envío**: transportadora (código del catálogo), `numero_guia`, `tracking`, `origen_guia` (manual · directa_transportadora · 99envios), `generada_por` (operaria · api), `proveedor_referencia`, `valor_flete`, `flete_pagador`, `recaudo_esperado`, `recaudo_estado`, observación, actor snapshot, `idempotency_key` | UNIQUE `shipment_id` (una guía por Shipment), UNIQUE (`transportadora_codigo`, `numero_guia`), UNIQUE `idempotency_key`; FK RESTRICT a `orders` y `shipments` |
| `bylopez_lg_guia_archivos` | El **documento PDF**, por versiones: `version`, `vigente` (1 o NULL), `origen_archivo` (manual · api), disco/ruta, `mime`, `tamano_bytes`, `sha256`, quién lo subió, `motivo_reemplazo`, `reemplazado_en` | UNIQUE (`guia_id`, `vigente`): una sola versión vigente; FK RESTRICT a la guía |

- **No se toca ninguna tabla de Bagisto.** El Shipment que se crea es el nativo: `track_number` y `carrier_title` salen del formulario; `carrier_code` se escribe en la columna que ya existía y nadie llenaba.
- **Guía inmutable** salvo `tracking`, `proveedor_referencia` y `recaudo_estado` (lo único que una API o la conciliación podrán completar). **Archivo inmutable** salvo pasar de vigente a reemplazado.
- **API futura:** un adaptador escribe en **la misma guía** (`generada_por = api`, `proveedor_referencia`) y agrega su PDF como una versión más (`origen_archivo = api`). No hay otro flujo ni otras tablas.

### 11.3 Servicios
- `DespachoService`:
  - `despachar()`: valida permiso, guía, catálogo, flete y recaudo. Bloquea el pedido (`FOR UPDATE`) y valida unidades e inventario. Crea el Shipment nativo (que descuenta inventario y dispara los eventos de Bagisto), la guía y el PDF, todo en **una transacción**; si algo falla, se revierte y el archivo escrito se borra.
  - `entregarEnBodega()`: pedidos de recogida; ver 11.5.
- `UnidadesDespachables`: reproduce las reglas del despacho **nativo** de Bagisto (que son del controlador, no del repositorio): `qty_to_ship`, hijos de productos compuestos y stock por bodega. Elige la primera bodega activa del canal con stock para todo.
- `GuiaArchivoService`: valida el PDF, lo guarda en el disco privado, versiona los reemplazos y responde ver/descargar.
- `ContextoDespachoService`: arma lo que necesita el drawer. `ConsultaDespachoService`: la bandeja. `ConsultaGuiaService`: guías por pedido, en lote.
- **Contratos** (Logística no depende de VentasAsesor, VentasAsesor los reemplaza):
  - `SugiereRecaudo`: Logística sugiere con datos de Bagisto; VentasAsesor usa el saldo de Cartera.
  - `AccesoGuiaPedido`: Logística exige el permiso; VentasAsesor deja ver al asesor las guías de **sus** pedidos.

### 11.4 Flete y recaudo (decisiones de Camilo)
- **Flete estructurado, no texto libre:** `valor_flete` + `flete_pagador` ∈ {`cliente_al_recibir`, `bylopez`, `incluido_en_venta`, `otro`}. Si paga ByLopez, el valor es obligatorio. Finanzas podrá determinar después si es un gasto real.
- **Recaudo contra entrega = lo que la transportadora DEBE recaudar.** Se guarda `recaudo_esperado` con `recaudo_estado = pendiente_liquidar` (reservado `liquidado` para la futura conciliación). **No** se registra en Cartera ni como Transaction: no se infla caja ni se marca el pedido como pagado antes de que la transportadora liquide. Tope: no puede superar el pedido más el flete.

### 11.5 Recogida en bodega
- Flujo propio **«Entregar en bodega»**: sin transportadora, guía ni PDF. En una sola operación crea el Shipment **real** (la salida de inventario no se puede omitir) y la entrega con fuente `recogida_bodega`, con las reglas de LOG-01. No hay guía ni envío ficticio. Exige poder despachar **y** registrar entregas.

### 11.6 PDF
- Solo PDF validado por **contenido** (firma `%PDF-` y tipo detectado, no extensión ni tipo declarado). **Máximo 5 MB.** Disco **privado** (`storage/app/private`), nombre generado (uuid), nunca accesible por URL pública.
- Ver y descargar **solo** por una ruta autenticada que autoriza el servicio (`Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`).
- Reemplazar crea una versión nueva: **motivo obligatorio**, quién y cuándo; la anterior se conserva. Subir el mismo archivo otra vez no crea versión.

### 11.7 Permisos
| Clave | Autoriza |
|---|---|
| `logistica.despachos` | Ver la bandeja y el contexto de un pedido |
| `logistica.despachos.despachar` | Registrar guía y despachar / completar / entregar en bodega |
| `logistica.guias` | Agrupadora |
| `logistica.guias.ver` | Ver y descargar cualquier guía |
| `logistica.guias.archivo` | Adjuntar o reemplazar el PDF |
| `logistica.guias.corregir` | Corregir número o transportadora de una guía (auditado, con motivo) |

- **BODEGA (#4):** `logistica`, `.despachos`, `.despachos.despachar`, `.entregas`, `.entregas.registrar`, `.guias`, `.guias.ver`, `.guias.archivo`, `.guias.corregir`. Ningún permiso comercial. Aplicado en desarrollo (30 → 39 permisos).
- **Administrador:** todo. **Asesor:** ninguna clave `logistica.*`. Ve transportadora, número, tracking y PDF **solo de sus pedidos**, por la ruta de Ventas Asesor `admin.ventas_asesor.pedidos.guia` (clave `ventas_asesor.pedidos.view_own`). Solo lee: no puede adjuntar, reemplazar ni borrar.
- La autorización se valida **siempre en el servicio**; ocultar un botón no autoriza nada.

### 11.8 Lo que BODEGA NO ve
Margen, costos, comisión, utilidad ni el valor comercial del pedido. El drawer muestra solo lo operativo (pedido, cliente, teléfono, destino, dirección, tipo de entrega y de pago) y el **valor a recaudar** cuando aplica. El flete solo lo ve quien puede despachar.

### 11.9 Límites conocidos de V1
- **PHP:** en desarrollo está en `upload_max_filesize = 2M` / `post_max_size = 8M`; el máximo lógico aprobado es 5 MB. Si PHP rechaza el archivo por su límite, el sistema NO dice «supera 5 MB»: responde que **el servidor no permite subir un archivo de ese tamaño y muestra el límite vigente**. Se cubren los dos casos (archivo mayor que `upload_max_filesize`, y cuerpo mayor que `post_max_size`, que Laravel corta con 413 antes de los controladores; el drawer lo traduce). **No se modificó ningún `php.ini`**: para subir los 5 MB hay que ampliarlo en cada entorno.
- El drawer es JavaScript de Vue y **no se ejecutó en un navegador** (no hay Playwright por instrucción): se verificó la sintaxis, el balance de la plantilla, todo el HTML del servidor y los endpoints. Necesita la prueba manual de Camilo en escritorio y móvil.

### 11.10 Decisión PENDIENTE para una fase posterior: anulación de un despacho (Shipment)
**No está implementada.** Anular un Shipment tiene implicaciones de **inventario** (reingreso de stock), de **estado del pedido** y de **operación** (qué pasa con la guía, el PDF y un recaudo ya esperado) distintas de las de corregir una guía. Por eso quedó fuera de V1: una guía mal digitada se resuelve con **Corregir guía** (§11.11), que no toca el despacho. Pendiente decidir cuándo, quién y con qué consecuencias se anula un envío (y si el retorno de transportadora de LOG-01 debe reingresar inventario).

### 11.11 Corregir guía (2026-10-09)
Acción **explícita y auditada**, separada del despacho: el Shipment (salida de inventario) y el número de guía son conceptos distintos.

- **Qué corrige:** el número de guía y/o la transportadora. Disponible en el drawer («Ver envío» y «Completar despacho») para quien tenga `logistica.guias.corregir` (Administrador; BODEGA #4 en desarrollo). No hay edición silenciosa: **motivo obligatorio**.
- **Historial** `bylopez_lg_guia_correcciones` (solo de adición; migración `2026_10_09_100005`): número anterior y nuevo, transportadora anterior y nueva, motivo, usuario (id + nombre) y fecha/hora. La cadena queda completa (el «anterior» de una corrección es el «nuevo» de la previa). Se muestra en el drawer. No se borra.
- **Guía vigente:** `bylopez_lg_guias` conserva el valor actual. `Guia` sigue siendo inmutable; `numero_guia` y `transportadora_codigo` solo pueden cambiar dentro de `Guia::corrigiendo()`, ventana que solo abre `CorregirGuiaService` y que se cierra siempre, incluso si la operación falla.
- **Sincronía con Bagisto:** `shipments.track_number`, `carrier_code` y `carrier_title` se actualizan con la guía vigente (columnas de texto que ya existían).
- **Validaciones:** motivo obligatorio; debe haber algo que cambiar; mismo formato de número que al despachar; la transportadora nueva debe estar **activa** en el catálogo y no ser recogida; no puede repetirse (transportadora, número) en otra guía.
- **NO hace:** no crea otro Shipment, no descuenta inventario, no cambia cantidades, no registra una venta, no toca Cartera ni facturas, no modifica el flete, el recaudo ni el PDF (cuyo reemplazo tiene su propio motivo).
- **Asesor:** ve siempre la guía vigente; no puede corregirla.
- **PICAP u otra transportadora nueva:** no se programa en código. Se agrega desde **Logística › Transportadoras**, como cualquier otra, y queda disponible en el despacho y en la corrección.

### 11.12 Pruebas
`Log02DespachoTest` (11) y `Log03CorregirGuiaTest` (8: COR-1 a COR-8) en `lpz_saas_testing`.

### 11.13 Cantidades a enviar y quién paga el flete (ajustes de UX, 2026-10-09)

**Una sola regla de cantidades: la de Bagisto 2.4.9.** No se inventó una segunda.
- **Pendiente** de una línea = `qty_to_ship` = pedido − enviado − reembolsado − cancelado.
- **Máximo despachable** = `min(pendiente, stock permitido en la bodega)`. Lo calcula `UnidadesDespachables::maximoEnBodega()` y lo usan **a la vez** el contexto del drawer (para precargar) y la validación del servidor (para rechazar). No hay lógica de cantidades en JavaScript más allá de acotar el input.
- **Producto compuesto (configurable):** el stock se mide sobre lo que el Shipment realmente descuenta del hijo. Las **43 filas hijas de configurables reales tienen `qty_ordered = 0`** y `ShipmentRepository` les descuenta la cantidad completa (razón 1); el despacho nativo no valida el stock de esas hijas, pero el Shipment sí lo descuenta, así que aquí sí se valida.
- **Bodega:** un Shipment sale de una sola bodega; se usa la que permita despachar más unidades (empate: mayor prioridad).

**Interfaz**
- Cada línea llega con `pendientes`, `disponibles` y `maximo`; el drawer **precarga `maximo`** (nunca 0 si hay unidades y stock).
  - Pedido 2, stock 20 → **2**. Pedido 2 con 1 despachado → **1**. Pedido 10 con 4 despachados → **6**.
  - Aunque haya 20 en bodega, **no se puede pasar de 2**: el input se acota a `[0, máximo]` (al cambiar y al salir del campo).
- **Stock insuficiente:** se precarga lo que hay y aparece «Despacho parcial: quedarán N unidades pendientes por falta de stock». El pedido queda en parcial y luego «Completar despacho».
- **Stock 0:** la línea muestra «Sin stock disponible» con el input bloqueado en 0. Si ninguna línea tiene stock, el botón queda bloqueado.
- «Ajustar cantidades» se conserva para excepciones; el caso normal no obliga a tocar cantidades.
- La interfaz **siempre envía cantidades explícitas** (ya acotadas). Sin cantidades, el servicio sigue entendiendo «todo lo pendiente» y falla con `stock_insuficiente` si no alcanza (comportamiento deliberado para otros llamadores).

**Backend (no confía en el input HTML)** — dentro de la transacción, con el pedido bloqueado (`FOR UPDATE`) y las filas de inventario bloqueadas:
- Cantidad > pendiente → `cantidad_excede_pendiente` (sobre-despacho).
- Cantidad > stock permitido → `stock_insuficiente`.
- Cantidad no entera, negativa o no numérica → `cantidad_invalida`; todo en 0 → `sin_unidades`.
- Probado: formulario «viejo» tras otro despacho, stock consumido mientras tanto, y manipulación del request por HTTP.

**Quién paga el flete**
- El valor inicial de un despacho nuevo es **siempre «Cliente, al recibir»** (`PagadorFlete::porDefecto()`, enviado por el servidor), sin depender del método de envío ni de despachos anteriores. Se quitó el placeholder «Elige quién paga».
- Se puede cambiar a ByLopez, Incluido en la venta u Otro **para ese despacho**.
- **Ya no se recuerda el pagador.** Se siguen recordando la transportadora y el origen de la guía. Al abrir el drawer se borra la preferencia `logistica.pagador` que guardaban versiones previas. Así una excepción (por ejemplo ByLopez) no puede contaminar el siguiente pedido.

**Pruebas:** `Log04CantidadesYFleteTest` (QTY-1 a QTY-8), incluida la ejecución real del JavaScript del drawer con Node (`tests/js/componente_despacho.mjs`), validada con mutaciones (precargar 0, no acotar al máximo, volver a recordar el pagador).

### 11.14 Excepción de despacho: «el sistema dice 0, pero la prenda está en bodega» (2026-10-09)

**Regla de fondo:** nunca se despacha sin stock (ni el Administrador). El despacho sigue siendo el normal; lo que se corrige, con auditoría, es el **inventario**.

**Flujo (dentro de Logística › Despachos, sin módulo nuevo):**
1. Stock insuficiente → el drawer muestra el panel **«Stock insuficiente»** (SKU, necesita, sistema). El input sigue en 0 y bloqueado.
2. **BODEGA solicita** autorización con el **conteo físico** por línea y una nota. Solo solicita: nunca toca inventario (`logistica.stock.solicitar`).
3. El **Administrador** revisa (stock en vivo, reservas, otros pedidos que lo necesitan, últimos movimientos del libro, despachos recientes) y **regulariza** (`logistica.stock.regularizar`) o **rechaza** con motivo. Puede también **solicitar y resolver en una sola operación** (queda como incidencia con solicitante y resolutor).
4. El drawer **recalcula** (`recargarContexto`, sin perder lo escrito) y el despacho es el normal (`stock_insuficiente` sigue aplicando si el conteo fue menor a lo pedido → despacho parcial).

**Sin segunda lógica de stock.** `Contracts/AjustaInventario` es la única puerta; `Support/Inventario/AjusteViaCorteConfeccion` (el **único** archivo de Logística que conoce CorteConfeccion) delega en `InventarioMovimientoService::registrarAjusteManual` (libro `bylopez_cc_inventario_movimientos`: cantidad anterior, movimiento, nueva, usuario, fecha, motivo, `idempotency_key`; bloqueo de fila; sin negativos; índice vendible). CorteConfeccion no se modificó.

**Reglas:** solo **incrementos** hacia el conteo físico (menor → `conteo_menor_al_sistema`; igual → `sin_diferencia`); si el stock cambió entre la solicitud y la resolución, el resultado es **exactamente** el conteo confirmado (el delta se recalcula bajo bloqueo); toda regularización exige **conteo, motivo y confirmación** expresa; todo-o-nada; sin umbral numérico; una incidencia abierta por pedido (`UNIQUE(order_id, abierta)`); resolución única e inmutable (`solicitada → regularizada | rechazada`); rechazar no cambia nada y permite una nueva solicitud. SKU físico = la línea simple o la **variante** del configurable (la hija que el Shipment descuenta); una línea compuesta con varios hijos → `linea_no_soportada`.

**Tablas:** `bylopez_lg_incidencias_stock` (+ snapshots de solicitante/resolutor) y `bylopez_lg_incidencia_stock_lineas` (necesaria, stock al solicitar, conteo declarado, stock al resolver, conteo confirmado, diferencia, `movimiento_id`). Migración `2026_10_09_100006`.

**ACL:** `logistica.stock` (ver pestaña/detalle), `.stock.solicitar` (BODEGA), `.stock.regularizar` (Administrador). BODEGA #4 en desarrollo: 41 permisos (+2); **no** recibe `.regularizar`. La compuerta de CorteConfeccion (`InventarioMovimientoGate`) además limita el ajuste a roles Administrador / `all`.

**UI:** pestaña **«Incidencias de stock»** (contador de abiertas) en Despachos; «Revisar» abre el mismo drawer del pedido. **Pruebas:** `Log05IncidenciasStockTest` (13) + sección 6 de `componente_despacho.mjs`.

**Límites conocidos:** una sola bodega (con varias, el adaptador falla de forma explícita); los movimientos aparecen como «ajuste manual» en el libro de CorteConfeccion; la edición nativa de stock en la ficha del producto sigue abierta; un rol Supervisor no-Administrador no podría regularizar (lo impide la compuerta de CorteConfeccion); drawer sin probar en navegador real.


## 12. ENTREGAS — drawer único, recepción de devoluciones y reingreso a inventario (2026-10-13)

**Estado:** IMPLEMENTADO EN DESARROLLO (sin commit, sin producción). **Pendiente la prueba manual de Camilo en navegador** (el JavaScript se probó con Node y simulación, no en un navegador real).

### 12.1 Tres hechos distintos (no se confunden)

| Hecho | Dónde vive | Efecto en inventario |
|---|---|---|
| **Devolución REGISTRADA** (la mercancía regresa) | `bylopez_lg_retornos` (+ `_items`), sin cambios | **Ninguno** |
| **Devolución RECIBIDA en Bodega** (llegó físicamente; se valida por producto/talla) | `bylopez_lg_recepciones_devolucion` (+ `_items`) | Solo las unidades **APTAS** |
| **Reingreso** | movimiento `reingreso_devolucion` en el libro de inventario | Entrada en la **misma bodega del Shipment** |

Una recepción confirmada produce el reingreso automáticamente en la **misma transacción**; si cualquier línea falla, se revierte la recepción completa. No participan Cartera, pagos, comisiones, Refund ni el estado del pedido. Unidades con novedad (dañadas/no aptas) y faltantes **nunca** vuelven al stock vendible.

### 12.2 Tablas (migración `2026_10_13_100012`)

- `bylopez_lg_recepciones_devolucion`: `retorno_id`, `shipment_id`, `order_id`, `inventory_source_id` (copiado de `shipments.inventory_source_id`), `fecha_recepcion`, `observacion`, actor + snapshot, `estado` (`confirmado`→`anulado`, anulación auditada como entregas/retornos), `idempotency_key` UNIQUE, `huella`. **`vigente_unica`** (columna generada = `retorno_id` mientras esté confirmada) **UNIQUE**: nunca dos recepciones vigentes de la misma devolución.
- `bylopez_lg_recepcion_devolucion_items`: `retorno_item_id`, `shipment_item_id`, `order_item_id`, **`product_id` = variante física** que descontó el Shipment (no el configurable padre), `esperadas`, `recibidas`, `aptas`, `novedad`, `faltantes`, `motivo_novedad`, `reingreso_estado` (`reingresado` / `sin_aptas` / `no_aplica`), `movimiento_inventario_id` UNIQUE (sin FK: frontera con el libro), `reingresado_en`. CHECK en base: `recibidas ≤ esperadas`, `aptas + novedad = recibidas`, `faltantes = esperadas − recibidas`, `reingresado ⇔ movimiento`.
- CorteConfeccion (autorizado solo para esto): migración `2026_10_13_000001` agrega `origen_tipo` / `origen_id` (nullable, sin FK) al libro `bylopez_cc_inventario_movimientos`; enum `TipoInventarioMovimiento::ReingresoDevolucion`; método genérico `InventarioMovimientoService::registrarEntradaExterna()` (bodega explícita, cantidad > 0, idempotente por `idempotency_key`, bloqueo de fila, stock físico + índice vendible, libro con anterior/nueva/usuario/origen). No cambia ninguna otra regla de Corte y Confección.

### 12.3 Servicios y fronteras

- `RecibirDevolucionService`: autoridad de la recepción (permiso `logistica.entregas.recibir_devolucion`, idempotencia `idempotency_key` + `huella`, bloqueo `FOR UPDATE` del Shipment y de la devolución, validaciones de backend, bodega del Shipment). Sin bodega determinable → `bodega_origen_indeterminada` («Requiere revisión»): no se recibe ni se toca el stock.
- `Contracts/ReingresaInventario` + `Support/Inventario/ReingresoViaCorteConfeccion`: la lógica de devolución **no** llama a clases de CorteConfeccion; el adaptador es (junto a `AjusteViaCorteConfeccion`) el único archivo que las conoce. `Support/ProductoFisicoDeLinea` resuelve la variante física y si gestiona stock (`manage_stock` es un atributo EAV de Bagisto; si no lo gestiona, el Shipment no descontó y no se repone: `no_aplica`).
- **Idempotencia del reingreso:** clave determinista por línea `reingreso:recepcion:{id}:retorno_item:{id}` (UNIQUE en el libro) + UNIQUE de recepción vigente + UNIQUE de `movimiento_inventario_id` + transacción + bloqueos. Doble clic, refresh, timeout/reintento y concurrencia no duplican stock.
- **No se usa** `OrderItemRepository::returnQtyToProductInventory` (cancelación de pedido entero; hallazgo H-RMA-12) **ni Refund**.
- Anulaciones: una devolución con recepción vigente **no se anula** (primero la recepción); una recepción con unidades ya reintegradas **no se anula** («No se puede anular esta recepción porque sus unidades aptas ya fueron reintegradas al inventario»); no existe resta silenciosa de stock. Una recepción sin reingreso (todo con novedad) sí se puede anular y la devolución vuelve a «pendiente de recibir».

### 12.4 Estados (lenguaje humano)

Entrega: Pendiente de entrega · Entrega parcial · Entregado · Devuelto · Entregado con devoluciones. Devolución: **Devolución pendiente de recibir** · Requiere revisión · Devolución recibida · Recibida con novedades · **Reintegrada al inventario** · Reintegrada parcialmente. Se derivan de lo registrado (`ConsultaEntregaService::estadoEntrega/estadoDevolucion`); los códigos internos no se muestran.

### 12.5 UX: drawer único en `/admin/logistica/entregas`

`entregas/componente.blade.php` (`v-logistica-entregas`) con cuatro modos —**Ver**, **Registrar entrega**, **Registrar devolución**, **Recibir devolución**— sin salir de la bandeja. Listado: `[Ver] [Registrar entrega] [Devolución]` (según permisos y pendientes), sin tallas; KPI nuevo «Devoluciones por recibir». «Recibir devolución» aparece dentro de «Ver» como banner con acción. Recepción: el sistema propone (recibidas = esperadas, aptas = recibidas), Bodega trabaja las excepciones; novedad y faltantes se derivan; motivo por línea si hay diferencias; resumen con diferencias resaltadas y la ayuda «Las unidades aptas volverán al inventario de Bodega al confirmar»; confirmación explícita. Tras guardar se actualizan drawer, fila/tarjeta y KPIs sin recargar. Móvil: filas/cards, sin tabla horizontal. Las páginas clásicas (`?envio=ID`, `/envios/ID/entregar`, `/envios/ID/retorno`) siguen como respaldo. En la interfaz «retorno» se lee **Devolución**; tablas, clases y rutas internas conservan `retorno`.

JSON: `GET entregas/envios/{id}/contexto`, `POST …/drawer/entregar|devolver`, `POST entregas/devoluciones/{retorno}/recibir`, `POST entregas/drawer/{entregas|devoluciones|recepciones}/…/anular` (`EntregasDrawerController`, capa delgada sobre los servicios; ACL por ruta + el servicio vuelve a validar).

### 12.6 Pruebas

`Log07RecepcionDevolucionTest` (14: RD-1…RD-14, incl. JS de `componente_entregas.mjs`): devolución no sube stock; +10 / +8 con novedad / +8 con faltantes; validaciones; idempotencia y UNIQUE; todo-o-nada; bodega real del Shipment y caso sin bodega; variante hija y `manage_stock`; permisos; anulaciones; drawer JSON y páginas antiguas; bandeja. Regresión: Log01–06 y `DetallePedidoDespachoRefrescoTest` verdes. **Límites:** no hay prueba de concurrencia con procesos paralelos reales (se cubre con el UNIQUE, el bloqueo y el reintento secuencial); el JavaScript no se probó en un navegador real.

### 12.7 Pendiente / decisiones futuras

Flujo administrativo para revertir un reingreso ya hecho (salida de stock auditada); efectos comerciales de una devolución (Cartera/crédito/reenvío); validación administrativa «ByLopez» separada de la evidencia (ver informe de Entregas); varias bodegas en el ajuste de stock de despacho (`AjusteViaCorteConfeccion` sigue con una sola).

## 13. GUÍA INTERNA ByLopez — GI-000001 (2026-10-14)

**Estado:** IMPLEMENTADA EN DESARROLLO (sin commit, sin producción) · pendiente de la prueba manual de Camilo. **GI reemplaza la propuesta «BL»** para las guías internas nuevas: no existe ni existirá ninguna guía `BL-…`.

### 13.1 Qué guía usa cada transportadora (eleccion excluyente)

En Logística › Transportadoras (alta y edición): **Guía externa** (el número lo da la transportadora o plataforma) o **Guía interna ByLopez** (la genera el sistema). Se guarda en la capacidad ya existente `genera_guia_interna` (`true` = interna): **no hay otro campo** que la duplique. Es independiente del tipo (una empresa local también puede tener guía externa) y nunca se decide por el nombre. El listado muestra el modo en la columna «Guía». Permiso: `logistica.transportadoras.manage` (validado en `TransportadorasService`). No se activó ninguna guía interna masivamente: las configuraciones que ya existían se conservan tal cual.

### 13.2 Numeración

`GI-000001`, `GI-000002`… (mínimo seis dígitos, crece sin tope). **Consecutivo global** compartido solo entre transportadoras con guía interna; sin reinicio anual ni por transportadora; número inmutable, único y nunca reutilizable; **no se exige ausencia de huecos**. No se genera GI para envíos con guía externa ni se crea otro identificador para todos los envíos. El número externo sigue disponible para cruces futuros.

- Contador: tabla `bylopez_lg_consecutivos` (clave `guia_interna`), bloqueada con `FOR UPDATE` dentro de la transacción del despacho (`Support/ConsecutivoGuiaInterna`). Siguiente = `max(contador, mayor consecutivo guardado) + 1`. Nunca `MAX+1` sin protección.
- Unicidad en base de datos: `bylopez_lg_guias.consecutivo_interno` UNIQUE (los NULL de las externas no chocan). El UNIQUE `(transportadora_codigo, numero_guia)` sigue rigiendo a las externas.
- Migración `2026_10_14_100013`: columna, tabla de consecutivos y CHECK ampliados (`origen_guia = 'interna'`, `generada_por = 'sistema'`, y una guía es interna **si y solo si** tiene consecutivo y la genera el sistema). Sin backfill: las guías manuales anteriores no se renumeran ni se convierten.
- Los números `GI-…` están **reservados**: una guía externa no puede escribirlos (despacho ni corrección).

### 13.3 Despacho (`DespachoService::despachar`, mismo drawer)

- **Externa:** flujo de siempre (número, origen, tracking, PDF externo; unicidad por transportadora).
- **Interna:** el drawer muestra «Se asignará automáticamente al despachar» (sin campo de número, origen, tracking ni PDF; se retiró el aviso de «siguiente fase»). El servidor ignora lo que el cliente mande de esos campos, asigna el GI **antes de crear el Shipment nativo** (así viaja como `track_number` desde su creación, y la sincronización con el Shipment es la misma de siempre) y lo guarda como `numero_guia` de la guía única del envío. `origen_guia = interna`, `generada_por = sistema`; el actor sigue guardado (`actor_admin_id` + nombre) para auditoría.
- **Idempotencia:** la misma `idempotency_key` devuelve el mismo envío y la misma guía (la huella incluye el modo, no un número que aún no existe). Si la transacción se revierte, el contador se revierte con ella.
- El número aparece en la confirmación («Despachado. Guía GI-000001 registrada.») y en «Ver envío» (origen «Guía interna ByLopez»).
- La recogida en bodega no cambia (sin guía).

### 13.4 Histórico e integridad

- Cambiar el modo de una transportadora afecta **solo los despachos nuevos**. La consulta y la corrección de guías anteriores usan el **origen guardado en cada guía**, nunca la configuración actual.
- El número GI es inmutable también desde backend (`Guia::updating` lo bloquea incluso dentro de una corrección auditada).
- «Corregir guía» conserva motivo e historial, pero: no cambia el número de una GI (`guia_interna_inmutable`) y no convierte una guía interna en externa ni al revés (`guia_cambio_de_tipo`: la transportadora nueva debe usar el mismo tipo de guía que el origen guardado).

### 13.5 Implementado vs. pendiente

| Implementado | Pendiente (sin definir) |
|---|---|
| Elección externa/interna por transportadora; consecutivo GI; despacho; drawer; Ver envío; restricciones; corrección acotada | **Diseño y generación del PDF / etiqueta interna** (formato por definir; no hay botón de PDF para GI) |
| Reglas de histórico | APIs de transportadoras, cruces de archivos, liquidaciones, cuentas de domiciliarios, WhatsApp |
| | Anulación de despachos y reingreso de inventario (sigue §11.10) |

**Nota para las pruebas automáticas existentes (no se ejecutaron ni se modificaron en esta entrega):** los helpers/pruebas de Domicilios locales (`helpersDomicilios.php`, `Log06…`) crean transportadoras con `genera_guia_interna = true` y despachan con un número escrito; con GI ese número se ignora y se asigna uno automático. Actualizarlas queda para cuando se vuelvan a correr.


## 13. VALIDACION DE ENTREGA y cierre del pedido (2026-10-14)

**Estado:** IMPLEMENTADO EN DESARROLLO (sin commit, sin producción). **Pendiente la prueba manual de Camilo en navegador.**

### 13.1 Tres hechos distintos

| Hecho | Dónde vive | Quién lo hace | Efecto |
|---|---|---|---|
| Entrega **REGISTRADA** | `bylopez_lg_entregas` | Bodega / domiciliario / supervisión | Operativo. No comisiona. |
| Entrega **VALIDADA** | `bylopez_lg_validaciones_entrega` | Administrador (`logistica.entregas.validar`) | **Hito que habilita la comisión del asesor** (COM-04 lo consume; la comisión no se calcula aquí). |
| **PAGO** | Cartera (`bylopez_va_pagos` → `order_transactions`) | Cartera (registrar + confirmar) | Única vía por la que la factura pasa a `paid` y el pedido a `completed`. |

**Decisión (Camilo, 2026-10-14):** validar no fuerza la factura ni el pedido. Si la factura se marcara pagada sin dinero registrado, Cartera quedaría inconsistente (saldo vivo con factura pagada → «requiere revisión»). El cierre ocurre por la vía oficial: `RegistrarPagoService` → `ConfirmarPagoService` → `InvoiceRepository::updateState(paid)` + `OrderRepository::updateOrderStatus` (Bagisto calcula `completed`). Desde el drawer, el administrador puede registrar y confirmar el cobro contra entrega en el mismo lugar (medio `contraentrega_recaudo` / `efectivo`); no se crean pagos desde Logística directamente.

### 13.2 Tabla y reglas (migración `2026_10_14_100013_create_bylopez_lg_validaciones_entrega_table`)

`bylopez_lg_validaciones_entrega`: `shipment_id`, `order_id`, `observacion`, actor + snapshot, `entregadas`/`devueltas` (foto de lo ratificado), `estado` (`confirmado` → `anulado`, anulación auditada con motivo, quién y cuándo), `idempotency_key` UNIQUE + `huella`, **`vigente_unica`** (columna generada = `shipment_id` mientras esté confirmada) **UNIQUE**: nunca dos validaciones vigentes del mismo envío. Mismo patrón inmutable que entregas/retornos/recepciones. Sin backfill: lo histórico no se valida solo.

`ValidarEntregaService` (bloquea el Shipment; revalida en servidor): al menos una unidad entregada · cero pendientes · ninguna devolución vigente sin recibir en Bodega. Con la validación vigente **no se anula ninguna entrega / devolución / recepción** del envío (`envio_validado`): primero se anula la validación (permiso `validar`, motivo obligatorio).

### 13.3 Frontera con Cartera

`Contracts/GestionaCobroPedido` + `Support/Cobro/CobroViaCartera` (único archivo de Logística que conoce los servicios de Cartera): `saldo()`, `medios()` (activos sin plataforma) y `cobrar()` (registra y, si el actor puede revisar pagos, confirma; si no puede, el pago queda `sin_revisar` en Cartera y se avisa). `CobrarEntregaService` exige permiso `validar` y al menos una unidad entregada; Cartera vuelve a validar saldo, medio, referencia, fecha de corte, permisos e idempotencia. Pedidos fuera de Cartera (anteriores al corte, estados que no aplican) o con «requiere revisión»: no se cobra desde aquí.

### 13.4 Estados y UX

Columna «ByLopez» de la bandeja: **Por validar** (entrega completa, sin validar) → **Validada**. Filtro «Por validar» y KPI «Por validar» (enlace al filtro). En el drawer, «Ver» muestra la sección **Validación y cierre** (validación, estado del pedido, factura, saldo por cobrar) y el botón «Validar entrega»; los modos **Validar entrega** (confirmación con observación) y **Registrar cobro contra entrega** (monto por defecto = saldo, medio, fecha, referencia) funcionan sin salir de la bandeja. Rutas JSON: `POST entregas/envios/{id}/validar`, `.../cobrar`, `entregas/drawer/validaciones/{id}/anular`.

### 13.5 Pendiente

La comisión del asesor **no se calcula ni se paga todavía**: COM-04 debe leer `bylopez_lg_validaciones_entrega` (estado `confirmado`) como condición. «Se le cuenta al asesor» queda como esa condición; no hay vista nueva para el asesor. Producción: asignar `logistica.entregas.validar` solo a Administración/Supervisión y aplicar la migración.

## 14. CUENTAS CORRIENTES Y LIQUIDACIONES DE TRANSPORTADORAS (2026-10-14)

**Estado:** IMPLEMENTADO EN DESARROLLO (sin commit, sin producción) · pendiente de la prueba manual de Camilo. **Detalle completo:** `CUENTAS_TRANSPORTADORAS.md`.

- **Tres cosas separadas:** Cartera (cliente ↔ ByLopez), cuenta corriente (custodia/obligación transportador ↔ ByLopez) y liquidación (cierre). La cuenta corriente no es ingreso ni venta.
- **Por capacidad:** `liquida_cuenta_corriente`, guardada en la guía al despachar. PICAP no participa.
- **Libro de movimientos** `bylopez_lg_cuenta_movimientos`, con importe firmado: **+ la transportadora debe a ByLopez / − ByLopez debe**. El saldo es Σ abiertos. Solo de adición: se corrige con reversos o ajustes.
- **El dinero nace de la ENTREGA, no del despacho** (`ReglaCuentaEnvio` + `GeneradorMovimientosCuenta`, en la misma transacción de entregar, devolver, anular y corregir guía; idempotente con claves UNIQUE):
  - **recaudo:** `recaudo_esperado`, con el envío completamente entregado;
  - **servicio:** `valor_flete`, si lo paga ByLopez, con la primera unidad entregada;
  - **por pagar:** sin servicio;
  - **parcial con devolución, pagador «otro» o sin costo:** «Requiere revisión».
- **Liquidación inmutable** `bylopez_lg_liquidaciones_transportadora` (LIQ-000001), solo ADMIN, bajo bloqueo. Sus movimientos suman 0; la diferencia se cierra y se reabre en la cuenta siguiente, con observación obligatoria. Las correcciones posteriores son ajustes.
- **ACL:** `logistica.cuentas` (ver), `.liquidar` y `.ajustar` (ADMIN).
- **Interfaz:** menú Logística › Cuentas de transportadoras (listado + drawer).
- **Migración:** `2026_10_14_100014`.
- **Pruebas:** `Log09CuentasTransportadoraTest` 15/15.

## 15. ESTADO REPORTADO POR EL PROVEEDOR (99 Envíos) — informativo (2026-10-15)

Migración aditiva `2026_10_15_100015_create_bylopez_lg_estados_proveedor_tables`. Detalle en `packages/ByLopez/Cargues/docs/13_ENVIOS_COMPLETOS_Y_E2.md`.

- **Proveedor ≠ transportadora efectiva:** 99 Envíos es intermediario; `bylopez_lg_proveedor_transportadoras` traduce su código («1», «4») al catálogo (`INTER_RAPIDISIMO`…), por configuración. Sin fila activa, el código es desconocido.
- `bylopez_lg_guia_estados_proveedor` (último estado por guía y proveedor) y `bylopez_lg_guia_estado_proveedor_eventos` (historial append-only con procedencia: carga y fila del cargue). Las escribe **solo** el procedimiento del cargue `sp_cg_envios_completos_v1`.
- **ESTADO REPORTADO ≠ ENTREGA REGISTRADA ≠ ENTREGA VALIDADA ≠ PAGO.** No genera entregas, retornos, recepciones, validaciones, recaudos ni comisiones; la fuente `integracion_transportadora` sigue reservada. La guía no se modifica.
- Conciliación del recaudo (reportado vs. `recaudo_esperado` de la guía), exacta, sin tolerancia: `coincide` | `difiere` | `sin_dato`.
- **Visible en Entregas (2026-10-18, solo lectura):** `ReporteProveedorService` alimenta el listado (indicador «Cruzada con 99 Envíos», estado reportado, aviso de discrepancia; «Sin reporte de 99 Envíos» solo para guías de origen 99envios) y el drawer «Ver» (proveedor ≠ transportadora efectiva, estado y fecha del estado, último cruce aplicado + cargue/fila, recaudos, valor del servicio y seguro sin llamarlos flete, historial). «Cruzada» exige un reporte aplicado y persistido. Permiso: `logistica.entregas`; no concede acceso a Cargues. La página clásica `?envio=ID` no lo muestra. Pruebas: `Log10ReporteProveedorTest`.
- Pendiente: `coordinadora` no está en el catálogo; 3 guías de dev tienen `99ENVIOS` como transportadora.
