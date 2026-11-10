# 30 — Cotizaciones de una venta en curso

**Estado (2026-11-04):** implementado en dev, sin commit, sin producción. Migración `2026_11_04_000001` aplicada en `lpz_saas`,
`lpz_saas_jornada` y `lpz_saas_testing` (con `--path`, solo ese archivo). **Producción: pendiente.**

## Qué es

Un PDF para enviarle al cliente lo que está armando el asesor en Nueva venta, antes de confirmar. **No es un pedido, no es una factura y no
reserva inventario.** Es una **foto congelada** (cliente, líneas, totales, entrega y datos de pago del momento): reabrirla siempre muestra lo
que se envió, aunque cambien los precios o los datos de pago.

## Reglas aprobadas por Camilo

- Diseño basado en el PDF de factura de Bagisto (A4, azul `#000DBB`, tabla de productos y totales).
- **El domicilio no entra en el total** y se aclara que se cobra por aparte. El total se llama «TOTAL PRODUCTOS» y, si Bagisto ya calculó el
  envío, se muestra solo como estimación («Domicilio no incluido… est. $ 12.000»). En recogida: «Sin costo de envío».
- El descuento sigue como línea aparte.
- Datos de pago: Nequi / Daviplata **3157941278** y Bancolombia. Todo editable por el administrador (ver abajo).

## Cómo funciona

| Pieza | Dónde |
|---|---|
| Tabla (solo adición) | `bylopez_va_cotizaciones`, sin FK al borrador (descartar la venta no borra lo enviado) |
| Servicio | `Services/Cotizaciones/CotizacionService` — lee `NuevaVentaService::estado()`, no toca carrito ni stock |
| Ajustes editables | `Support/Cotizaciones/AjustesCotizacion` + `Config/system.php` |
| PDF | `Resources/views/admin/cotizaciones/pdf.blade.php` (DomPDF vía `PDFHandler` de Bagisto) |
| Rutas | `POST nueva-venta/{borrador}/cotizaciones`, `GET nueva-venta/{borrador}/cotizaciones`, `GET nueva-venta/cotizaciones/{id}/pdf` |
| UI | Botón **Cotizar** bajo «Confirmar venta» + panel (número, estado, vigencia, «Abrir PDF», «Copiar texto» para WhatsApp) |

- **Numeración:** `COT-000123` (id de la venta en curso) y `COT-000123-2` desde la segunda versión.
- **Versiones:** una cotización no se edita ni se borra. Si la venta cambia (líneas, cliente, descuento, entrega) o el administrador cambia los
  datos de pago, **Cotizar** crea la versión siguiente y la anterior queda «Reemplazada». Pedirla dos veces sin cambios devuelve la misma
  mientras siga vigente (no se llena de duplicados).
- **Estados (derivados, no guardados):** Vigente · Vencida (pasó la fecha) · Reemplazada (hay versión más nueva) · Convertida en venta (la venta
  ya tiene pedido).
- **Reabrir:** al reanudar una venta desde «Ventas en curso» el panel muestra su última cotización; si la venta cambió después, avisa que hay
  que generar una nueva.
- **Vigencia:** 7 días calendario por defecto (editable en la configuración, 1–90).

## Dónde edita el administrador los datos de pago

Menú **Ventas Asesor › Datos de cotización** → abre **Configuración › Ventas Asesor › Cotizaciones** (pantalla estándar de Bagisto, la misma de
los métodos de envío). El enlace del menú usa la clave ACL `ventas_asesor.cotizaciones_datos` (sin asignar a ningún rol: solo la ven los roles
`all`); la pantalla en sí exige la clave `configuration` de Bagisto, así que un rol sin ella recibe 401 al abrirla.

> **Trampa (validación del formulario):** el formulario de Configuración valida en el navegador con vee-validate, que **no tiene la regla
> `nullable`** de Laravel. Con `nullable|max:191` el botón «Guardar» no hacía nada y sin error visible. Los campos opcionales llevan solo
> `max:N`. Las pruebas por el repositorio no lo detectan: se valida pulsando el botón en el navegador.

- *Encabezado del PDF:* nombre comercial, descripción/NIT, contacto del pie.
- *Formas de pago:* Nequi/Daviplata (número y titular), llaves (Bre-B u otras), banco (nombre, tipo, **número de cuenta**, titular) y otros
  medios (ej. contra entrega).
- *Vigencia y condiciones:* días de vigencia, lista de condiciones (una por línea) y aviso destacado.

Un campo vacío **no se imprime**. Un medio sin su dato principal (número) tampoco: por eso Bancolombia **no aparece hasta que se cargue el
número de cuenta** (Camilo no lo había entregado). Pendiente: cargarlo en esa pantalla.

## Seguridad

- Las tres rutas están en el ACL bajo la clave de **Nueva venta** (`ventas_asesor.pedidos.create`): quien puede crear una venta puede cotizarla.
  Sin esa clave, el ACL corta (401).
- Además el servicio exige ser **propietario de la venta** o tener «ver todas las ventas» (`pedidos.view_all`); si no, 403 `sin_permiso`.
- Solo se cotiza una venta **activa** y con cliente y al menos una línea (`409 borrador_no_activo`, `422 sin_cliente`, `422 sin_lineas`).

## Pruebas

`tests/Feature/CotizacionTest.php` (usa `lpz_saas_testing`): foto sin tocar pedido/stock/carrito, domicilio fuera del total, versiones y
reemplazo, inmutabilidad, vencida/convertida, rechazos, propietario vs «ver todo», edición de datos de pago y medio sin dato principal, foto
que congela los datos de pago, flujo HTTP completo con ACL y PDF real (`%PDF-`).

## Pendiente / decisiones abiertas

1. Cargar el **número de cuenta de Bancolombia**, titular y NIT/contacto en Configuración.
2. ~~¿Cotizar para todo asesor?~~ **Decidido por Camilo (2026-11-04): cualquier asesor puede enviar una cotización** (comparte la clave de Nueva
   venta). Sigue valiendo que solo ve las cotizaciones de SUS ventas (o todas si tiene «ver todas las ventas»).
3. Aviso de stock por producto en el PDF (hoy solo la nota general «Disponibilidad sujeta a inventario»).
4. Envío por correo desde el panel (hoy se descarga el PDF y se copia el texto para WhatsApp).
5. Prueba manual en pantalla (390 y 1366 px) y revisión visual del PDF con datos reales.
