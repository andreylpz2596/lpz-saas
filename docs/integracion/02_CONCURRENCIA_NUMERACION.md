# 02 · Numeración nativa de pedidos y facturas: concurrencia

Revisión del 2026-11-16. Decisión vigente de Camilo: **numeración nativa de Bagisto, sufijo de factura `-2026`, sin generador personalizado**. Este documento no cambia esa decisión. Registra lo comprobado y deja las opciones para que él decida.

## 1. Cómo numera Bagisto
`Webkul\Sales\Generators\Sequencer::generate()` arma el número así: prefijo + (`id` de la última fila + 1) con relleno + sufijo.
- Lee la última fila con una consulta normal (`orderBy('id','desc')->first()`), **sin bloqueo**.
- Esa lectura ocurre dentro de la transacción que crea el documento, antes del INSERT.
- Mientras esa transacción no hace COMMIT, otra transacción no ve la fila nueva y calcula el mismo número.

| Documento | Índice único sobre `increment_id` | Qué hace Bagisto si choca |
|---|---|---|
| Pedido (`orders`) | **Sí** (`orders_increment_id_unique`) | `OrderRepository::createOrderIfNotThenRetry` revierte y **reintenta** (hasta `max_retry_attempts`, por defecto 3) con un número nuevo |
| Factura (`invoices`) | **No** | Nada: la segunda factura se guarda con el **mismo número** |

## 2. Quién crea facturas en ByLopez
- Automáticamente, al confirmar un pago: `VentasAsesor\Services\Cartera\ConfirmarPagoService`.
- Al aplicar crédito: `VentasAsesor\Services\Credito\SatisfaccionFacturaService`.
- A mano, desde Ventas › Facturar (Bagisto).

Dos confirmaciones de pago casi simultáneas, de dos personas de cartera o de un asesor y un administrador, son el caso realista.

## 3. Lo comprobado
**Pruebas de flujo completo** (copia desechable `lpz_saas_preproduccion_pruebas_20261116c`, dos servidores, peticiones a la vez): tres rondas de dos pedidos y dos facturas simultáneos. **Sin duplicados.**
- Los pedidos del mismo producto se serializan por el cerco de Ventas Asesor (`CercoCreacionPedido`: `FOR UPDATE` del borrador y de los productos).
- En facturas, las peticiones no llegaron a solaparse en el instante crítico.

Que pasaran **no demuestra** que el riesgo no exista.

**Sonda dirigida** (misma copia desechable). Dos procesos arrancan a la misma hora; cada uno abre una transacción, pide el número al secuenciador **nativo**, inserta y mantiene la transacción abierta *N* ms antes del COMMIT, que es lo que hace Bagisto mientras guarda líneas, totales y eventos.

| Caso | Rondas | Resultado |
|---|---|---|
| Facturas, 0 ms de transacción abierta | 8 | **2 rondas con número duplicado** (`FAC-BYL-00000121-2026` ×2, `…125-2026` ×2), ambas con COMMIT |
| Facturas, 300 ms | 8 | **8 de 8 rondas con número duplicado** |
| Pedidos, 300 ms | 5 | 5 de 5: el segundo INSERT falla por el índice único (`1062 Duplicate entry`). En el flujo real Bagisto reintenta con otro número |

Las filas de la sonda se borraron de la copia desechable. La evidencia está fuera del repositorio, en `…\ByLopez_respaldos\20261115\evidencia_ronda3\sonda\`.

## 4. Conclusión
- **Pedidos:** sin riesgo de número repetido. En un choque, Bagisto reintenta; en el peor caso, tras 3 choques seguidos, la confirmación falla y se puede repetir.
- **Facturas:** **riesgo real de número duplicado**, en silencio, cuando dos facturas se crean con transacciones que se solapan. Con el volumen actual es poco frecuente, pero puede pasar. **No está resuelto.**

## 5. Opciones (decisión de Camilo; ninguna aplicada)
1. **Índice único en `invoices.increment_id`** (migración de ByLopez, sin tocar Webkul). Un choque deja de ser silencioso: la segunda confirmación falla, se revierte entera y se puede repetir. Cambia la estructura, así que exige actualizar la base limpia y generar una exportación nueva.
2. **Serializar la creación de facturas** en las transacciones propias de ByLopez (`ConfirmarPagoService`, `SatisfaccionFacturaService`): tomar un bloqueo antes de cualquier lectura. No cubre la facturación manual de Bagisto.
3. **Aceptar el riesgo** y vigilarlo con una consulta periódica (`SELECT increment_id, COUNT(*) FROM invoices GROUP BY increment_id HAVING COUNT(*) > 1`).

Recomendación técnica: la 1, sola o junto con la 2. Mantiene la numeración nativa y convierte un error silencioso en uno visible.
