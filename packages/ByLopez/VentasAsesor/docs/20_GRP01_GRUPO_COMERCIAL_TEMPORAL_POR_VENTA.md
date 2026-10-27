# 20 — GRP-01: grupo comercial TEMPORAL por venta

**Estado:** implementado y probado en `lpz_saas_testing`; las migraciones **no** están aplicadas en `lpz_saas`
(desarrollo) hasta que se ejecuten a mano (ver §9). Sin cambios en `packages/Webkul/`.

## 1. Regla

Una venta puede usar un grupo comercial distinto del habitual del cliente **solo para esa venta**.
`customers.customer_group_id` **no se escribe nunca**. Dos ventas simultáneas del mismo cliente pueden tener grupos
efectivos distintos.

| Concepto | Dónde vive |
|---|---|
| Grupo habitual / original | `customers.customer_group_id` (lectura) y `borradores.grupo_original_id` (el que había al iniciar o al aplicar el override) |
| Grupo efectivo | `borradores.grupo_efectivo_id` + `grupo_override` + `grupo_override_customer_id` |
| Historial | `bylopez_va_borrador_grupo_eventos` (una fila por evento, inmutable) |
| Snapshot del pedido | `bylopez_va_pedido_grupo_snapshots` (1:1 con `orders`, inmutable) |

## 2. Resolución del grupo de precios (RT-03 extendido)

`ContextoPrecioComercial::resolverGrupo()` (único punto de decisión, el mismo que ya alimenta a
`CustomerRepositoryConContextoComercial::getCurrentGroup()`):

1. Contexto con carrito (`durante(cart_id, customer_id)`): lee el borrador de ese `cart_id`. Con override vigente
   devuelve el grupo efectivo.
2. Sin override: el grupo ACTUAL del cliente, como siempre (sin grupo → invitado, como el core).
3. **Falla cerrado:** override aplicado para otro cliente (`override_de_otro_cliente`) o grupo borrado
   (`grupo_override_inexistente`): nunca se degrada al grupo del cliente ni al invitado.

El catálogo (`CatalogoVentaService::matriz/variante/varianteEnLote`) usa `durante(cart_id, …)` cuando conoce la venta
(parámetro `cartId`), así catálogo, líneas, carrito, entrega, pago y confirmación comparten el mismo grupo efectivo.
Sin venta, el contexto de creación con el grupo del cliente, igual que antes.

## 3. Cambiar / restaurar (`GrupoVentaService`)

Una transacción: bloquear borrador y carrito (`BorradorVentaService::bloquearParaEditar`: permiso de vender,
propietario, estado editable) → persistir override → `Cart::collectTotals()` en el contexto del carrito → fila de
historial. Si algo falla, rollback completo (grupo, precios y evento anteriores). El motivo (≤ 500) es obligatorio
también al restaurar. Elegir el grupo habitual equivale a restaurar. Grupos permitidos: los que acepta
`ValidadorGrupoComercial` (todos menos invitado).

## 4. Cambio de cliente

`BorradorVentaService::cambiarCliente` llama a `RegistroGrupoVenta::alCambiarCliente`: si había override, escribe
`override_cancelado_cambio_cliente` (cliente ANTERIOR, origen `cambio_cliente`) y la venta arranca con el grupo habitual
del cliente nuevo (`override = false`). Nunca se hereda.

## 5. Snapshot del pedido

`RegistrarSnapshotGrupoPedido` (`checkout.order.save.after`, misma transacción que el pedido, tras el enlace): guarda id,
nombre y código de grupo original y efectivo, `grupo_override` y el cliente, y completa `order_id` en los eventos de
historial de la venta. Si falla, lanza y el pedido se revierte. Sin backfill: los pedidos anteriores quedan sin fila.

## 6. ACL

`ventas_asesor.pedidos.cambiar_grupo_temporal` (rutas `grupo.cambiar` y `grupo.restaurar`, solo bajo esa clave). El
historial (`grupo.historial`, solo lectura) va con `ventas_asesor.pedidos.create`; el servicio exige propietario o
`view_all`. El servicio vuelve a validar el permiso (no depende de ocultar el botón).

## 7. UI (`nueva-venta/index.blade.php`)

Resumen de la venta: Grupo habitual, Grupo aplicado a esta venta (+ badge «Temporal»), «Cambiar para esta venta»
(solo con permiso), «Restaurar grupo habitual» y «Ver historial de grupo» (solo si hubo cambios). Modal con cliente,
grupo habitual, grupo aplicado, nuevo grupo, motivo obligatorio y avisos; no se cierra hasta tener respuesta correcta y
bloquea el doble clic. El estado recalculado viene del backend; el frontend no calcula precios.

## 8. Pruebas

`tests/Feature/Grp01GrupoTemporalVentaTest.php` (11 casos del requerimiento, permisos por servicio y HTTP, motivo, mismo
grupo, otro asesor, venta confirmada, atomicidad, fail closed). Regresión RT-03: `Rt03PrecioPorGrupoDelCarritoTest`,
`ContextoPrecioComercialTest`, `Rt03ReorderTest`.

## 9. Despliegue

```
php artisan migrate --path=packages/ByLopez/VentasAsesor/src/Database/Migrations/2026_10_13_000001_add_grupo_efectivo_to_bylopez_va_borradores_venta_table.php
php artisan migrate --path=packages/ByLopez/VentasAsesor/src/Database/Migrations/2026_10_13_000002_create_bylopez_va_borrador_grupo_eventos_table.php
php artisan migrate --path=packages/ByLopez/VentasAsesor/src/Database/Migrations/2026_10_13_000003_create_bylopez_va_pedido_grupo_snapshots_table.php
php artisan optimize:clear
```

Ventas anteriores: columnas `NULL`/`false` = comportamiento de siempre. Asignar el permiso al rol que corresponda.
