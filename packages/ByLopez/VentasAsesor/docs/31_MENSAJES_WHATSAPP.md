# 31 — Mensajes de WhatsApp al cliente (texto para copiar y pegar)

**Estado (2026-11-17):** V1 implementada en dev, sin commit, sin producción. **No envía nada**: previsualiza → copia → el usuario pega en WhatsApp.
Es una capa de **solo lectura**: generar un mensaje nunca cambia un estado, un stock, una cuenta, un crédito ni una comisión.

## 1. Arquitectura (una sola lógica, tres puertas)

| Pieza | Paquete | Qué hace |
|---|---|---|
| `Contracts/MensajesPedidoCliente` (+ `SinMensajesPedido` por defecto, `MensajePedidoNoDisponibleException`) | Logística | Puerta única: `opciones(order, actor)` y `generar(order, clave, actor)`. Mismo patrón que `SugiereRecaudo`. |
| `Services/Mensajes/MensajePedidoService` | VentasAsesor | **Único** lugar que decide qué mensajes corresponden y los genera. Implementa el contrato. |
| `Services/Mensajes/LectorPedidoParaMensaje` | VentasAsesor | Lee del estado REAL, con una lista cerrada de campos (no reutiliza DTO anchos). |
| `Services/Mensajes/PlantillasMensajePedido` | VentasAsesor | Solo formatea (puro, sin consultas). Plantillas por código. |
| `Support/Mensajes/AjustesMensajes` + `Config/system.php` | VentasAsesor | Configuración mínima: emojis y frase final (la marca es la de Cotizaciones). |
| `MensajesPedidoController` + `admin/mensajes/whatsapp.blade.php` + `boton.blade.php` | Logística | Un controlador delgado, un componente (modal) y un botón para las tres puertas. |

**Puertas (cada una con su ruta y su clave ACL; el servicio vuelve a autorizar por pedido):**

| Pantalla | Ruta | Clave ACL |
|---|---|---|
| Pedidos (lista y detalle), asesor/administrador | `admin.ventas_asesor.pedidos.mensajes.{opciones,mensaje}` | `ventas_asesor.pedidos.view_own` |
| Logística › Despachos (BODEGA) | `admin.logistica.despachos.mensajes.*` | `logistica.despachos` |
| Logística › Entregas (BODEGA) | `admin.logistica.entregas.mensajes.*` | `logistica.entregas` |

Un futuro n8n consume lo mismo: `GET …/pedidos/{id}/mensajes` y `…/mensajes/{clave}` devuelven `texto`, `tipo`, `telefono` y `pedido {id, numero}`. No se integró n8n ni ninguna API de WhatsApp.

## 2. UX

Botón con el icono de WhatsApp en la fila/tarjeta del pedido → **modal** (hoja inferior a pantalla completa en móvil, centrado en escritorio) con: chips para elegir el mensaje (solo si hay más de uno), texto seleccionable y botón **Copiar mensaje** (48 px, siempre visible) → «✓ Mensaje copiado».
- Se abre en el mensaje **más avanzado** del pedido (el último de la lista).
- **Portapapeles:** Clipboard API; si no está disponible (HTTP, navegador antiguo) selecciona el texto y usa `execCommand`; si tampoco, deja el texto seleccionado y lo dice.
- **Vigencia:** al copiar vuelve a pedir el texto; si cambió (guía corregida, despacho nuevo) lo actualiza y avisa «El pedido cambió… cópialo de nuevo».
- JavaScript puro y delegado, el modal se crea en `<body>` (fuera de la raíz Vue de Bagisto). No se tocó `guardia-scroll.blade.php`.

## 3. Tipos de mensaje y cuándo se ofrecen

| Clave | Tipo | Condición exacta (estado real) | BODEGA |
|---|---|---|---|
| `resumen` | `resumen` | Pedido no cancelado/cerrado/fraude y con unidades | No (lleva el valor del pedido) |
| `listo-domicilio` | `listo_domicilio` | `shipping_method = domicilio_local` y 0 unidades despachadas | Sí |
| `listo-recoger` | `listo_recoger` | `shipping_method = pickup` y 0 unidades despachadas | Sí |
| `despacho-{shipment_id}` | `despacho_completo` | Es el 1.º envío y no quedan unidades pendientes | Sí |
| | `despacho_parcial` | Tras ese envío aún quedan unidades pendientes | Sí |
| | `despacho_final` | Es el 2.º o posterior envío y con él no queda nada pendiente | Sí |
| `entregado` | `entregado` | **Todos** los envíos con validación de entrega vigente y nada pendiente de despachar | Sí |

- **Un mensaje por despacho:** solo las prendas de ESE envío y SU guía (nunca se mezclan guías). Las pendientes se calculan «tras ese envío» a partir de los `shipment_items` reales.
- **Recogida en bodega:** no hay «despacho con guía» que avisar (se entrega en mano); tras entregar solo queda `resumen` y, validada la entrega, `entregado`.
- Un tipo que no corresponde **no se ofrece ni se puede generar** (409 `mensaje_no_disponible`).

## 4. Fuente exacta de cada dato

| Dato | Fuente |
|---|---|
| Nombre del cliente | `orders.customer_first_name` (primer nombre, capitalizado) |
| Teléfono (solo en la respuesta de la API, no en el texto) | `addresses.phone` del envío (`order_shipping`) |
| Número de pedido | `orders.increment_id` (igual que en las pantallas) |
| Productos | `order_items` padres (`name`, `qty_ordered − qty_canceled`) + `additional.attributes` (color / talla) |
| Lo despachado | `shipments` + `shipment_items` (nunca las cantidades originales) |
| Pendientes | objetivo de cada línea − lo despachado hasta ese envío |
| Guía / transportadora | `bylopez_lg_guias` **vigente** del envío (`numero_guia`, `origen_guia`, `tracking`) + `bylopez_lg_transportadoras` (`nombre`, `tipo`). Sin fila de guía: `shipments.carrier_title/track_number` |
| Guía interna | `origen_guia = interna` → «Guía interna: GI-…» |
| Domicilio local | transportadora de tipo `domiciliario` o `propia` |
| Destino / dirección | `addresses` (`order_shipping`) **del propio pedido** + `country_states` |
| Lugar de recogida | dirección de envío del pedido (la bodega elegida en la venta); fecha/horario solo si hay fila en `pickup_orders` |
| Entrega validada | `bylopez_lg_validaciones_entrega.estado = confirmado` |
| Total del pedido | `SaldoPedido->totalOperativo` (Cartera, si el pedido es limpio) o `orders.base_grand_total` |
| Abonos / saldo | `SaldoPedido->aplicadoTotal` / `->saldo` de `CarteraConsultaService::saldosDePedidos` |
| Nombre comercial | `ventas_asesor.cotizacion.encabezado.empresa_nombre` (por defecto **LÓPEZ LÓPEZ**) |

## 5. Dinero (regla de V1, deliberadamente conservadora)

- Siempre «💰 Total del pedido».
- «Abonos aplicados» y «Saldo pendiente» (o «Pedido pagado») **solo** si el pedido está dentro de Cartera, sin motivos de revisión, con algún abono aplicado, y el actor tiene alcance de Cartera sobre ese pedido. Con crédito D10 aplicado, el abono ya lo incluye (no se muestra aparte).
- Sin abonos no se escribe ninguna frase de «debes pagar».
- **BODEGA no ve ningún valor** (ni total, ni recaudo, ni flete).
- **No se muestra** el recaudo de la guía ni el flete: con datos reales ambos mienten (ej. el pedido 86 tiene DOS guías con `recaudo_esperado` = 61.000 sobre un pedido de 61.000). Ver §8, decisión 1.

## 6. Permisos (siempre en servidor)

- **Ventas:** `view_own` solo pedidos atribuidos al asesor; `view_all` todos. El valor del pedido lo ve quien entra por Ventas; abonos/saldo, además con `cartera.view_*` sobre ese pedido.
- **Logística:** `logistica.despachos` o `logistica.entregas` (BODEGA): mensajes operativos **sin dinero y sin resumen comercial**.
- Sin ningún camino de acceso: `sin_permiso` aunque el pedido no exista (no se revela la existencia). Pedido cancelado/cerrado/fraude: sin mensajes.
- No se creó ninguna clave ACL nueva para esto. Los roles `all` (p. ej. un segundo BODEGA con `permission_type=all`) ven todo, como en el resto del módulo.

## 7. Datos que NUNCA salen

La lista de campos es cerrada (§4). No se lee ni se imprime: costos, utilidad, margen, comisión, notas internas, deuda/cuenta de transportadora, conciliación, C6, `proveedor_referencia` ni estados de 99envíos, IDs técnicos, observaciones, ni nada de otros clientes. La prueba `ningun tipo de mensaje menciona nada interno` revisa todos los textos.

## 8. Decisiones pendientes de Camilo (impacto en lo que lee el cliente)

1. **«Pagas al recibir $X» (contra entrega):** no se ofrece. El recaudo de la guía y el flete por pagar no son una fuente inequívoca (pedido 86: dos guías con el total completo). Para ofrecerlo hay que definir cuál es la cifra oficial por guía (C1) y si incluye flete.
2. **Número de pedido visible:** hoy `#BYL-00000114-CO` (el `increment_id`, igual que en todas las pantallas). ¿Prefieres un número corto para el cliente?
3. **Nombre del domiciliario:** no se muestra (tampoco su teléfono). ¿Quieres «Va contigo Jeison»?
4. **Plataforma y 99envíos:** se muestra el **nombre del catálogo** de transportadoras (`PICAP`, `99envíos`…). Si no quieres que el cliente vea «99envíos», cambia ese nombre en Logística › Transportadoras.
5. **«Entregado» con envíos mezclados:** solo se ofrece cuando TODOS los envíos están validados; un envío validado de un pedido con varios envíos aún no genera mensaje.
6. **Horario de recogida:** solo sale si existe en `pickup_orders` (hoy esa tabla está vacía). Sin fuente oficial no se inventa.

## 9. Configuración

Configuración › Ventas Asesor › **Mensajes de WhatsApp**: «Usar emojis» y «Frase final» (admite `{marca}`). El **nombre comercial** es el de Cotizaciones › Encabezado del PDF y su valor por defecto pasó de «ByLopez» a **LÓPEZ LÓPEZ** (solo aplica donde no se haya guardado un valor). Evolución posible, no hecha: saludo propio, plantillas editables.

> Trampa recordada: el formulario de Configuración valida con vee-validate; los opcionales llevan solo `max:N` (la regla `nullable` no existe allí).

## 10. Pruebas

- `tests/Unit/MensajePedidoPlantillasTest.php` (13): cada tipo, emojis, dinero, consolidación (solo líneas idénticas), color solo si el nombre no lo trae, y ningún término interno.
- `tests/Feature/MensajePedidoWhatsAppTest.php` (9, pedidos reales despachados con `DespachoService`): sin despachar y sin efectos, completo, guía corregida, guía interna, parcial + segundo despacho (una guía por despacho), domicilio local, recogida, entregado (y su anulación), permisos (asesor propio/ajeno, administrador, BODEGA sin dinero, sin permisos, inexistente, cancelado), HTTP por las tres puertas con su ACL, y dinero (sin abonos, parcial, pagado, revisión, fuera de Cartera, sin alcance de Cartera).
- Navegador real (jornada, 390/430/1366 px): ver el informe de la entrega.
