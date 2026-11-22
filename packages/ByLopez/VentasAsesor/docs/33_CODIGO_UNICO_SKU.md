# 33 — Código único (SKU) por patrón

Aprobado por Camilo el 2026-11-22: formato, Terracota = 127, Niño con la primera edad, renombrar también los pedidos viejos, aplicar en pruebas y producción, generación automática desde la ficha y desde Cargues, y búsqueda rápida.

## Formato

| Producto | Código | Lectura |
|---|---|---|
| Padre | `115D-DZ` | 115 Verde Cali · Dama · Durazno |
| Variante | `115D-DZ-M` | … talla M |
| Niño | `115N-DZ-6` | … 6-8 (2 / 6 / 10 / 14) |
| Crop top | `106D-TP-U` | Negro · Dama · Top · Única |

- **Número de color:** un número por color, igual en Dama, Hombre y Niño. Está en la tabla `bylopez_va_sku_colores`. Un color nuevo recibe el siguiente número libre la primera vez que se guarda un producto con él.
- **Público:** sale de la familia de atributos (`ventasasesor.sku.familias`): H = Hombre, D = Dama, N = Niño. Crop Top cuenta como D.
- **Línea:** la tela (`tipo_tela` «Piel Durazno» = DZ) o la línea fija de la familia (Crop Top = TP).
- **Talla:** S, M, L, XL, XXL; T-Unica = U; para niño, la primera edad del rango.
- **Repetición:** si un segundo producto tiene el mismo color, público y línea, recibe `115D-DZ2`.
- **Fuera del patrón:** los productos de otras familias conservan su SKU.

## Piezas

- **`Services/Catalogo/CodigoSkuService`:** calcula los códigos. No escribe nada, salvo el número de un color nuevo.
- **`Services/Catalogo/RenombradorSkuService`:** escribe el SKU, el nombre y el url_key (deja un redireccionamiento 301 del url_key anterior). También pone el SKU nuevo en las copias guardadas en otras tablas: toda columna con «sku» en el nombre, cambiando solo el valor exacto anterior. Así cambian pedidos, facturas, envíos, reembolsos, carritos, costos, comisiones, rentabilidad y cortes. No toca las columnas del archivo de los cargues (`bylopez_cgi_*`, salvo `producto_sku`).
- **`Listeners/SincronizarSkuProducto`:** se ejecuta con `catalog.product.create.after` y `catalog.product.update.after`. Si al guardar falta un dato, el producto conserva su SKU.
  - Un producto copiado («Copia de …») toma el nombre de la plantilla con su color real.
  - Nunca hace fallar el guardado.
- **`Console/Commands/NormalizarSkuCommand`:** `ventas-asesor:sku-normalizar` normaliza el catálogo completo en una sola transacción.
  - Sin opciones solo muestra la vista previa; `--aplicar` escribe los cambios.
  - También corrige nombres y textos de color, y deja un CSV con el antes y el después.
- **Cargues (`Extensiones/ProductosCamisetas/SkuCamisetas`):**
  - Las columnas `codigo_base` y `sku_variante` del archivo solo sirven para agrupar las filas; el código lo pone el patrón.
  - Un grupo se reescribe solo si todas sus filas tienen público, color y talla reconocidos y un único color.
- **Búsqueda (`Support/Catalogo/BusquedaSku`):**
  - Nueva venta: `115d` y `115 d` traen el producto 115D.
  - Precios por grupo: `115dm` trae las variantes 115D-…-M.
  - `dama`, `mujer`, `hombre`, `caballero` y `niño` se buscan como sinónimos.

## Operación

```bash
php artisan migrate --path=packages/ByLopez/VentasAsesor/src/Database/Migrations/2026_11_22_000001_create_bylopez_va_sku_colores_table.php --force
php artisan ventas-asesor:sku-normalizar --color="Terracota=127"            # vista previa
php artisan ventas-asesor:sku-normalizar --color="Terracota=127" --aplicar  # aplica
```

Los archivos de conteo de inventario y de cargue deben usar los SKU nuevos desde ese momento. El cargue de camisetas ya genera el código solo.
