# 25 — Mejoras visuales y de uso tras la auditoría E2E (2026-10-24)

Estado: **implementado en desarrollo, sin commit.** Solo presentación, textos, estados de carga y manejo visual de errores. No cambia reglas
de negocio, tasas, umbrales ni permisos; no hay migraciones; `packages/Webkul` y `vendor` quedan intactos.

La auditoría del 2026-10-21 es histórica: antes de editar se comprobó qué ya existía. Entre tanto otras jornadas implementaron el recaudo por
guía (C1, `Logistica/Services/Despachos/RecaudoPorGuia`) y el efecto de la devolución recibida en Cartera con el inventario «Defectuosas» (C5,
`Logistica/docs/DEVOLUCIONES_C5_DEFECTUOSAS.md`). Esta jornada **no** los toca: solo los presenta.

## 1. Qué cambió

| Pantalla | Cambio | Archivos principales |
|---|---|---|
| Nueva Venta · colores | Búsqueda por nombre (desde 10 colores; sin tildes ni mayúsculas) que **no toca la selección**; aviso de elegidos ocultos por el filtro; «Ningún color coincide…» + «Limpiar búsqueda». Título «Colores · <líneas>». Resumen minimizado que distingue **«Por agregar»** de **«Ya en la venta»**; se descarta al cambiar de línea. «Cargando colores…» con acciones quietas. Marca «En la venta» por color. Error por línea con «Reintentar». | `VentasAsesor/…/nueva-venta/index.blade.php` |
| Nueva Venta · «otras ventas» | «en otras ventas sin confirmar» = unidades en **borradores** de otras ventas; «pedidos sin despachar» = pedidos ya confirmados. Ayuda accesible: solo informativo, **no reserva ni descuenta** (Doc. 05 §40; no hay reserva de inventario). | ídem |
| Nueva Venta · guardado | «Guardando productos…» (pie de la modal), «Guardando entrega…», «Guardando método de pago…» (en su sección). Lo que viaja no se lista como faltante (nunca marcado + «Selecciona un método de pago»). | ídem |
| Nueva Venta · fallos al agregar | El error vive **junto al botón**, no arriba de una lista larga. Separa «Ya está en la venta» de «Pendiente», conserva cantidades, no cierra la modal y rotula «Reintentar lo pendiente». Tras un fallo se **relee el estado real**: si el servidor sí guardó (corte después de guardar) se reconcilia; si no se puede leer, dice que hay que verificar. Sin rutas ni trazas (5xx → texto neutro). Sin reintentos automáticos. | ídem |
| Importes | Un solo formato **«$ 8.500»**: `ByLopezMoneda.precio()` (navegador) y `Logistica\Support\Moneda` (servidor). Corrige «8500 COP» (Intl no agrupa 4 cifras en `es`), «46.000,00 $» y el prefijo «COP» del parcial de máscara (ahora «$», como el servidor). No redondea nada guardado. | `CorteConfeccion/…/partials/moneda.blade.php`, vistas de VentasAsesor y Logistica |
| Campos de monto | «Monto cobrado» (Entregas), «Costo del servicio» y «Valor a cobrar» (Despacho) usan `<v-moneda>`: escribir/pegar/borrar conserva el importe («69.500» = 69500, nunca 69,5). | `Logistica/…/entregas/componente`, `despachos/componente` |
| Despacho | Método de pago corto + «Medios admitidos» aparte (el título configurado no se renombra). Dinero **reportado pendiente de revisión** y **saldo según Cartera** por separado, con la aclaración de que lo reportado ya está descontado del saldo. «El flete está incluido en el total del pedido» (en vez de «el cliente ya lo pagó») salvo pedido pagado. Destino: **local / fuera de cobertura / sin identificar**; fuera de cobertura no sugiere elegir Bogotá o Soacha y explica que se registra el costo de la transportadora. Despacho parcial: saldo del pedido / comprometido en otras guías / cobra esta guía (valores del servidor). | `Logistica/…/despachos/componente`, `Services/Despachos/CondicionDespacho` |
| Recepción de devolución | El `confirm()` nativo pasa al **modal de confirmación del proyecto** con el resumen real (recibidas, aptas que vuelven al inventario vendible, con novedad que NO vuelven, faltantes); cancelar no escribe. Se sube su z-index sobre el drawer (se cortaba el botón). | `Logistica/…/entregas/componente`, `admin/estilos` |
| Cobro / validación | Ya no promete «la factura pasa a pagada» ni «habilita la comisión». | `Logistica/Resources/lang` |
| Cartera | Modal de confirmar/rechazar con cliente, monto, medio, referencia y pedidos relacionados; texto sin «transacción en Bagisto». Móvil: «Más filtros» pliega medio, fechas y «registrado por» con insignia de activos (no borra nada). | `VentasAsesor/…/pagos/*` |
| Cuentas de transportadoras | Encabezados y ayuda distinguen «recaudo registrado», «servicios», «saldo pendiente» y lo entregado al liquidar. No se cambió ninguna cifra. | `Logistica/Resources/lang` |
| Comisiones | Aviso «Las comisiones todavía no están activas…» (sin nombre de variable). Estimada y habilitada se muestran como **etapas del mismo importe** (no se suman). «Reevaluar pendientes» pasa bajo el título en móvil con área táctil de 44 px; pestañas con desplazamiento propio. | `VentasAsesor/…/comisiones/*` |
| Entregas · móvil | Indicadores compactables; compactos siguen visibles «por recibir» y «por validar». | `Logistica/…/entregas/index` |
| Accesibilidad | Foco visible por teclado en las pantallas intervenidas, etiquetas en los campos nuevos, estados con texto además del color, `role="status"/"alert"` en carga y errores. Corregido el «⚠» roto en Nueva Venta. | varias |

El build de Tailwind del admin solo escanea `packages/Webkul/Admin`: las utilidades nuevas que no existan allí **no se generan**. Por eso el estilo
nuevo vive en bloques `<style>` propios (`nv-*`, `va-*`, `lg-*`, `lgm-*`).

## 2. Dependencias y pendientes (no se tocaron a propósito)

Fallos de negocio o decisiones que **no** se resuelven aquí; el mensaje ahora se ve mejor, pero el problema no está resuelto:

- **Carrera al registrar pagos / cobros simultáneos (F3/F4)** y el 500 al confirmar: servidor.
- **Sin reserva de stock al confirmar (F5):** «otras ventas» solo informa.
- **Transferencia con saldo pendiente sale «no cobrar nada» (F6):** decisión de negocio.
- **Listado de Cuentas con recaudo/servicios en cero (F10):** se reprodujo en `lpz_saas_testing` (recaudado 0 y servicios 0 con saldo de millones); causa probable en `ConsultaCuentasService::listado` (`whereIn('concepto', …)` sobre un campo con cast a enum), sin corregir ni ocultar ni estimar.
- **Pago confirmado en el cajón de despacho:** el contrato `PagosPendientesDeConfirmar` solo entrega `sin_revisar` y `saldo`. Mostrarlo exige añadir `verificado` al contrato (tres pruebas comparan el arreglo exacto).
- **Costo adicional del despacho:** puede ser negativo; la máscara compartida no admite signo, así que sigue siendo `type="number"`.
- **Motivo de devolución:** no existe un catálogo reutilizable (el catálogo `bylopez_lg_catalogo_defectos` es de **defectos** y no debe mezclarse). Propuesta sin modelo paralelo: añadir un `tipo = 'motivo_devolucion'` a ese mismo catálogo cuando se apruebe. El motivo sigue siendo texto libre.
- **Gestión de defectuosas** (reparación, merma, reingreso, valorización): sigue en su alcance específico.
- **Líneas del catálogo con SKU como talla** («temporary-sku-…» en Camiseta niño) y **direcciones Bogotá/Soacha sin zona local** (F8): datos.
- **Rendimiento del POST `tallas`** y de Comisiones con miles de pedidos: no se tocó.
- **Vista de la asesora en Comisiones, 401 y políticas pendientes:** etapa aparte; no se amplió acceso a costos ni acuerdos.

## 3. Pruebas

- JS: `moneda.mjs` (+`precio()` y «69.500»), `moneda_dom.mjs`, `componente_entregas.mjs` (modal de confirmación: cancelar no envía, aceptar envía una vez), nuevo `presentacion_despacho.mjs`.
- PHP: `Log13PresentacionDespachoTest` (clasificación del destino y lógica de presentación), `MonedaMascaraTest` (prefijo `$`).
- Navegador (Chrome headless, Playwright): recorridos de Nueva Venta, Despacho, Cartera y móvil con capturas con nombre único; los fallos de guardado se inyectan en el navegador (sin dañar datos).
- Pruebas que **ya fallaban o fallan por el volumen** de `lpz_saas_testing` (≈5.000 pedidos clonados) y no por estos cambios: ver el reporte de la jornada.
