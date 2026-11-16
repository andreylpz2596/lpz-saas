# 18 · Tipo de cargue «Inventario — Actualizar cantidades (conteo físico)»

**Estado (2026-11-14):** implementado y activo en desarrollo (tipo 5, versión 2). Probado con el archivo real `docs/pruebas_cargues/INVENTARIOCOMPLETO31MAYO2026.xlsx` solo hasta **VALIDAR** (no se ejecutó). Sin Pest: el entorno aislado (puerto 3401) no estaba disponible en esta sesión.

## Qué hace
Fija el stock de variantes **existentes** en la cantidad del archivo (conteo absoluto). **No crea productos** (eso sigue en «Productos — Camisetas piel de durazno»).

## Archivo
El mismo que exporta Bagisto (Nombre, SKU, Familia, Precio, **Cantidad**, **ID**, Estado, Categoría, Tipo). Se relacionan ID, SKU, Cantidad (Nombre y Tipo son referencia). El resto se ignora.

## Reglas
- Cruce por **ID y/o SKU**. Si vienen los dos y no apuntan al mismo producto → rechazada (`ID_SKU_NO_COINCIDEN`, `ID_SKU_DISTINTOS`): no se adivina.
- No existe → `PRODUCTO_NO_ENCONTRADO`. Sin ID ni SKU → `NO_IDENTIFICA` (p. ej. la fila de totales). Sin cantidad → `SIN_CANTIDAD`; negativa → `CANTIDAD_NEGATIVA`; producto repetido → `PRODUCTO_REPETIDO`.
- **Configurables**: se ignoran (`CONFIGURABLE_IGNORADO`, sin cambio). Solo se actualizan productos `simple`.
- Misma cantidad → `sin_cambio`. Sin tocar los productos que no vienen en el archivo.
- Bodega: la única fuente activa del canal por defecto (la del ajuste manual).

## Escritura
`InventarioMovimientoService::registrarConteoFisico` (CorteConfeccion): una transacción por producto; stock físico + índice vendible + movimiento `conteo_fisico` (anterior, nueva, usuario = quien creó la carga, origen `cargue_inventario`/carga). Clave idempotente `cargue-inventario:<carga>:<producto>`: repetir la ejecución no duplica.

## Archivos
`Database/Procedures/sp_cg_inventario_conteo_v1.{sql,map.json}`, `Extensiones/InventarioConteo/InventarioConteoExtension.php`, `Services/Cargas/Complementos/InventarioConteoResultado.php`, `Config/cargues.php`; en CorteConfeccion: enum `ConteoFisico`, badge y `registrarConteoFisico`. Migraciones: ninguna de negocio (la intermedia la crea el motor al activar).

## Notas
- VALIDAR solo puede marcar «rechazada»: lo «sin cambio» y las configurables se marcan al EJECUTAR (por eso la v2 del tipo).
- Requiere el worker: `php artisan queue:work cargues --queue=cargues`.
- Ejecutar con un conteo viejo pisa el stock actual: usar un conteo físico del mismo día.

## Pruebas de ejecución (2026-11-15/16, copias desechables de la base limpia)
Por HTTP, con el worker y datos sintéticos (ver `docs/limpieza-preproduccion/00_ENTREGA.md` §4):
- Carga inicial sobre stock 0 fija las cantidades del archivo en stock físico **e** índice vendible, con un movimiento `conteo_fisico` por variante cambiada.
- Reejecutar la misma carga → 409 `ESTADO_NO_EJECUTABLE`; subir otra vez el mismo archivo → «sin cambio», sin movimientos nuevos.
- Un conteo posterior fija el valor absoluto (7, no 5 + 7).
- Un conteo que dejaría el stock por debajo de lo comprometido se rechaza (`CONTEO_RECHAZADO`).
Sigue sin suite Pest propia.
