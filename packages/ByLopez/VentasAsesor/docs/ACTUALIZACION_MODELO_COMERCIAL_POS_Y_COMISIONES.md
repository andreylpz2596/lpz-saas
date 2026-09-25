# Actualización del modelo comercial: POS, canales y comisiones

## Estado de esta actualización

Este documento registra decisiones y requisitos aclarados después del cierre de la Fase 01. No autoriza todavía la implementación de la Fase 02 ni del POS.

> **Auditado en la Fase 01B (2026-09-13).** Resultados y evidencia en
> `06B_AUDITORIA_CLIENTES_PRECIOS_Y_PROPIEDAD_COMERCIAL.md`. Resumen:
>
> - §3 y §6 quedaron aprobados como **D-16** y **D-27** (decididas).
> - §4: el defecto de precios **se reprodujo** y tiene causa identificada (RT-03).
> - §5: el esquema de Bagisto ya admite correo nulo; los obstáculos reales son
>   validaciones, notificaciones y una sincronización por correo (D-28, RT-04). El
>   teléfono ya tiene **índice único** en el core.
> - §7 a §9 y §11 quedaron registrados como decisiones pendientes **D-29 a D-34**.
>
> Las notas «Fase 01B» de cada sección precisan lo que cambió. El texto original se
> conserva.

## 1. Arquitectura comercial multicanal

Bagisto debe continuar como fuente oficial de clientes, grupos de clientes, productos, variantes, inventario, precios, carritos, pedidos, facturas, envíos y reembolsos.

`ByLopez/VentasAsesor` añadirá la trazabilidad comercial y logística sin crear un segundo motor de pedidos.

Todos los mecanismos de creación futuros deben reutilizar el mismo servicio de aplicación y el flujo oficial de Bagisto:

| Caso | `sales_channel` | `creation_source` |
|---|---|---|
| Asesor recibe el pedido por WhatsApp | `whatsapp` | `ventas_asesor` |
| Cliente compra en la tienda web | `ecommerce` | `storefront` |
| Venta realizada en el local físico | `tienda_fisica` | `pos` |
| Administrador registra una venta | Según origen real | `admin_bagisto` o `ventas_asesor` |
| Automatización futura desde WhatsApp | `whatsapp` | `n8n` o `api` |

`orders.channel_id` no debe utilizarse como canal comercial porque representa el canal o tienda de Bagisto.

## 2. Servicio común de creación

El nombre conceptual `CrearPedidoAsesorService` resulta demasiado limitado si después será utilizado por POS, API y automatizaciones.

Antes de implementar la Fase 03 debe evaluarse un nombre neutral, por ejemplo:

```text
CrearPedidoComercialService
```

La interfaz del asesor, el POS y la API futura deben actuar como adaptadores del mismo servicio. Cada entrada aportará explícitamente:

- Cliente.
- Carrito oficial.
- Canal comercial.
- Mecanismo de creación.
- Usuario creador.
- Asesor propietario, cuando corresponda.
- Productos y variantes.
- Dirección.
- Método de pago.
- Método de envío o entrega.
- Referencia externa e idempotencia, cuando corresponda.

No se deben insertar pedidos directamente en tablas del core ni crear motores separados para WhatsApp y POS.

## 3. Flujo de WhatsApp aprobado conceptualmente

1. El cliente escribe por WhatsApp.
2. El asesor busca primero al cliente para evitar duplicados.
3. Si no existe, lo crea y asigna el grupo comercial correspondiente.
4. Se crea o recupera un carrito oficial de Bagisto.
5. Se cargan variantes, tallas, colores, cantidades, dirección, pago y envío.
6. El sistema recalcula los precios según el cliente y su grupo actual.
7. Mientras el cliente negocia, existe carrito o borrador; todavía no existe pedido.
8. El pedido oficial se crea solamente cuando el cliente confirma.
9. Se registra creador, asesor propietario, canal `whatsapp` y origen `ventas_asesor`.
10. Posteriormente se registra la guía de 99 Envíos o el despacho por domiciliario en Bogotá.
11. La comisión se causa únicamente por unidades efectivamente entregadas y no devueltas.

## 4. Defecto crítico de precios por grupo de cliente

Actualmente, al cambiar de cliente durante la creación administrativa de pedidos, Bagisto puede conservar temporalmente el precio o grupo del cliente anterior hasta reiniciar sesión.

Ejemplos reportados:

- Carol: precio esperado de $7.400.
- Rosario, grupo Madrugón: precio esperado de $6.900.

Reiniciar sesión no es una solución aceptable. Antes de implementar el motor comercial debe auditarse el flujo real para determinar qué carrito, sesión o contexto conserva el grupo anterior.

Al seleccionar o cambiar de cliente, el sistema deberá:

1. Sustituir el cliente y el grupo dentro del carrito oficial.
2. Eliminar cualquier contexto comercial heredado del cliente anterior.
3. Recalcular precios, descuentos y totales de todos los ítems.
4. Mostrar el cliente, grupo y precio aplicado antes de confirmar.
5. Advertir si el cambio de cliente modifica precios ya visibles.
6. Impedir confirmar mientras exista inconsistencia entre cliente, grupo y precios.

Este defecto es una precondición de la Fase 03, aunque no necesariamente bloquea la atribución básica de la Fase 02.

> **Fase 01B — causa identificada.** Bagisto calcula el precio con el grupo del
> **cliente autenticado en la sesión** (`CustomerRepository::getCurrentGroup()` sobre el
> guard `customer`), no con el cliente del carrito. Reproducido en `lpz_saas_testing`:
>
> - Sin «Iniciar sesión como cliente» el carrito administrativo sale a precio de
>   **invitado**.
> - Tras iniciar sesión como Carol, el carrito de Rosario toma el precio de Carol.
> - Al cambiar a Rosario, **todos** los ítems, incluidos los ya agregados, se reprecian
>   con el grupo de Rosario en el siguiente recálculo.
> - Cerrar la sesión del **administrador no limpia** ese contexto. Solo lo limpian el
>   cierre de sesión del cliente o una sesión nueva, y entonces vuelve el precio de
>   invitado.
> - «Reiniciar sesión» no crea otro carrito.
>
> Queda registrado como **RT-03** (bloquea las Fases 03 y 04, no la 02).

## 5. Identidad del cliente de WhatsApp: teléfono obligatorio y correo opcional

En el flujo especializado de VentasAsesor, muchos clientes entregan únicamente:

- Nombre completo.
- Teléfono desde el cual escriben por WhatsApp.
- Dirección de entrega.
- Documento, solo cuando corresponda.
- Correo electrónico, únicamente cuando el cliente realmente lo proporciona.

El correo no debe inventarse ni solicitarse como requisito artificial para cerrar una venta. Tampoco deben generarse direcciones falsas con el número de teléfono, porque pueden terminar en notificaciones, exportaciones, facturación o procesos de recuperación de contraseña.

Regla funcional propuesta para la interfaz de VentasAsesor:

- `phone`: obligatorio.
- `email`: opcional y validado solamente si fue suministrado.
- `name`: obligatorio.
- Dirección: obligatoria cuando el pedido requiera despacho.
- Documento: opcional inicialmente, salvo exigencia fiscal u operativa.

El teléfono deberá guardarse en dos formas:

- Valor original, para presentación y auditoría.
- Valor normalizado, para búsqueda y prevención de duplicados; para Colombia, preferiblemente en formato internacional `+57...`.

Antes de crear un cliente, la interfaz deberá buscar coincidencias por teléfono normalizado y, cuando existan, complementar la verificación con correo o documento.

No se recomienda imponer inmediatamente una restricción única absoluta al teléfono. Una familia, empresa o punto de venta puede compartir número; además, un número puede cambiar de titular. Ante una coincidencia, la interfaz debe mostrar el cliente existente y exigir una decisión consciente: usarlo, corregirlo o crear un registro diferente con justificación.

### Auditoría técnica obligatoria

No se debe volver nullable `customers.email` ni alterar validaciones sin revisar el flujo real completo de Bagisto. Antes de implementar se debe inspeccionar:

- Migración y restricciones de la tabla `customers`.
- Índice único del correo.
- Validadores de creación y edición en Admin y Shop.
- `CustomerRepository` y contratos/proxies relacionados.
- Creación del cliente desde el carrito administrativo.
- Login, recuperación de contraseña y verificación de correo.
- Checkout, pedidos, facturas y notificaciones que asumen correo existente.
- Reglas de correo en `orders` y direcciones.
- Compatibilidad con clientes invitados.

La auditoría deberá comparar al menos estas alternativas:

1. Permitir correo nullable mediante una extensión compatible desde el paquete.
2. Mantener separado el contacto comercial de WhatsApp y vincularlo al cliente Bagisto cuando sea posible.
3. Aprovechar correctamente un flujo de cliente invitado, solo si conserva precios, atribución e historial requeridos.

No se aprueba como solución definitiva el uso de correos sintéticos. La alternativa elegida debe funcionar sin modificar archivos de `packages/Webkul/` y sin romper autenticación, checkout, notificaciones ni actualizaciones futuras.

> **Fase 01B — resultado de la auditoría.**
>
> - `customers.email` **ya es nullable** y su índice único `(email, channel_id)` admite
>   varios NULL. Lo exigen las validaciones del panel (alta, edición y dirección del
>   carrito).
> - `customers.phone` **ya tiene índice único** (`customers_phone_unique`) y la búsqueda
>   del panel no busca por teléfono.
> - Con `orders.customer_email` nulo, la notificación de pedido lanza `TypeError`, que
>   los listeners del core no capturan.
> - `syncNewRegisteredCustomerInformation()` reasigna los pedidos con correo nulo a un
>   cliente sin correo.
>
> **Recomendación (D-28):** cliente Bagisto con correo nulo más contacto comercial
> propio, condicionada a **RT-04**. El invitado se descarta porque pierde grupo de
> precios e historial.

## 6. Asignación cuando vende un administrador y ventas propias de la empresa

No se creará un segundo usuario únicamente para que un administrador pueda actuar como asesor.

Reglas propuestas:

- Asesor normal: se asigna automáticamente a sí mismo.
- Administrador total: debe elegir explícitamente `sin asesor`, `asignarme a mí` u otro asesor.
- Otros roles: no reciben automáticamente la venta.
- Automatizaciones: reciben un asesor explícito o dejan la venta sin asignar para reconciliación.

Tener acceso total no convierte automáticamente al administrador en asesor ni genera comisión. El mismo `admins.id` puede ser creador y asesor cuando se seleccione expresamente.

Esta definición reemplaza la exclusión absoluta del administrador planteada anteriormente para D-27.

> **Fase 01B.** Aprobada y registrada como **D-27 decidida**. El administrador total
> elige expresamente entre «venta de empresa», «asignarme como asesor» y «asignar otro
> asesor». Los campos siguen sin implementar.

Debe separarse claramente quién creó el pedido de quién es su propietario comercial:

```text
created_by_admin_id = usuario que registró el pedido
advisor_id = asesor propietario, nullable
commercial_owner_type = advisor | company | unassigned
```

Casos esperados:

| Caso | Creador | Propiedad comercial | Comisión |
|---|---|---|---|
| Asesor vende por WhatsApp | Asesor | `advisor` + su ID | Sí, según regla |
| Administrador realiza una venta personal comisionable | Administrador | `advisor` + su mismo ID | Sí, si se selecciona expresamente |
| Administrador registra venta de un cliente fijo de ByLopez | Administrador | `company` y sin asesor | No |
| Pedido sin información suficiente | Usuario o integración | `unassigned` | No hasta resolver |

Clientes fijos como Rosario pueden tener una preferencia comercial `company`, pero esta debe actuar como valor predeterminado visible, no como una regla irreversible. El pedido debe guardar su propia decisión histórica para que un cambio futuro en el cliente no reescriba ventas anteriores.

Las métricas deberán distinguir:

- Pedidos creados por una persona.
- Ventas propiedad de un asesor.
- Ventas directas de la empresa.
- Ventas sin asignar.
- Ventas comisionables y no comisionables.

No se debe atribuir comisión solo porque el administrador creó el pedido ni solo porque posee acceso total.

## 7. Comisión sobre utilidad

La dirección de negocio aceptada es una comisión inicial equivalente al 20 % de la utilidad bruta positiva de cada unidad entregada y no devuelta.

```text
utilidad_unitaria = precio_neto_unitario - costo_unitario_congelado
comision_unitaria = max(0, utilidad_unitaria) * porcentaje_comision
comision_total = comision_unitaria * cantidad_entregada_elegible
```

Ejemplo con costo unitario de $5.500:

| Precio neto | Utilidad | Comisión al 20 % |
|---:|---:|---:|
| $8.500 | $3.000 | $600 |
| $7.500 | $2.000 | $400 |
| $6.900 | $1.400 | $280 |
| $5.500 o menos | $0 o pérdida | $0 |

La reducción del precio por volumen ya reduce la utilidad y, por tanto, la comisión. No se aplicará además una segunda penalización por cantidad sin una regla comercial expresa, porque podría reducir dos veces la comisión por la misma causa.

La comisión no se calcula por unidades meramente despachadas. Se calcula por unidades entregadas, conciliadas y no devueltas.

Cada línea de comisión deberá congelar como mínimo:

- Precio neto vendido.
- Descuento aplicado.
- Costo unitario utilizado.
- Utilidad unitaria.
- Porcentaje aplicado.
- Comisión unitaria.
- Cantidad entregada elegible.
- Regla aplicada.
- Pedido, ítem, envío y evento de entrega.
- Asesor y fecha causante.

Sigue pendiente definir exactamente qué integra el costo unitario: tela, corte, confección, transporte interno, empaque, estampado, costos de recaudo y otros costos aplicables. El 20 % no debe marcarse como política definitiva hasta validar márgenes reales.

## 8. Devoluciones posteriores

Una liquidación pagada es inmutable. Si una unidad comisionada se devuelve después:

- No se reescribe la liquidación original.
- Se genera un ajuste negativo por la comisión originalmente pagada por esa unidad.
- El ajuste se descuenta de una liquidación futura.
- Se conserva trazabilidad con pedido, ítem, entrega, devolución y liquidación original.

## 9. POS futuro

El POS se contempla como una interfaz futura para ventas en el establecimiento físico. Debe reutilizar clientes, grupos, precios, inventario, carritos, pedidos y el servicio común de creación.

Una venta POS utilizaría inicialmente:

```text
sales_channel = tienda_fisica
creation_source = pos
```

El POS no debe añadirse simplemente como una pantalla adicional. Antes de implementarlo habrá que diseñar y decidir:

- Sucursal y fuente de inventario.
- Caja, apertura, cierre y arqueo.
- Cajero y asesor comercial, que pueden ser personas diferentes.
- Efectivo, transferencia, datáfono y pagos combinados.
- Comprobante de venta y obligaciones fiscales aplicables.
- Entrega inmediata, envío posterior o recogida.
- Cancelaciones, cambios y devoluciones.
- Operación con o sin conexión.
- Numeración e idempotencia.
- Comisión para ventas presenciales.

Una venta POS entregada inmediatamente podrá generar el evento de entrega en el mismo flujo, pero solo después de registrar correctamente el pago y la salida de inventario. No debe declararse entregada únicamente por haber creado el pedido.

## 10. Secuencia recomendada

El POS no debe adelantarse a la estabilización de ventas por asesor. La secuencia recomendada es:

1. Cerrar atribución y contexto de creación.
2. Auditar y corregir precios por grupo al cambiar de cliente.
3. Implementar el servicio comercial común.
4. Implementar la interfaz de ventas por asesor.
5. Estabilizar logística, entregas, devoluciones y comisiones.
6. Diseñar el POS como nueva interfaz sobre el mismo motor.
7. Incorporar API, n8n o WhatsApp automatizado reutilizando el mismo servicio.

## 11. Decisiones pendientes relacionadas

- Definición completa del costo unitario comisionable.
- Confirmación definitiva del porcentaje inicial del 20 %.
- Responsable autorizado para confirmar entregas.
- Tratamiento de comisión en ventas POS.
- Alcance operativo y fiscal del POS.
- Precios negociados por debajo del margen mínimo.
- Política para descuentos excepcionales y autorización requerida.
- Estrategia técnica compatible para clientes sin correo.
- Política de coincidencias cuando varios clientes comparten teléfono.
- Datos mínimos requeridos para clientes con entrega, recogida o venta POS.

> **Fase 01B — identificadores asignados** (`04_DECISIONES_PENDIENTES.md`):
>
> | Asunto | Registro |
> |---|---|
> | Costo unitario comisionable | D-31 |
> | Porcentaje inicial del 20 % | D-32 |
> | Responsable de confirmar entregas | D-14 (existente) |
> | Comisión en ventas POS | D-33 |
> | Alcance operativo y fiscal del POS | D-34 |
> | Precios bajo margen mínimo y descuentos excepcionales | D-18 (ampliada) |
> | Estrategia técnica para clientes sin correo | D-28 (+ RT-04) |
> | Coincidencias por teléfono compartido | D-29 |
> | Datos mínimos por modalidad de entrega | D-30 |
> | Precio correcto al cambiar de cliente | RT-03 (requisito técnico) |
