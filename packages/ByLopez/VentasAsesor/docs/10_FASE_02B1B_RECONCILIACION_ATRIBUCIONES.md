# 10 — Fase 02B1B: reconciliación segura de atribuciones

**Estado:** COMPLETADA (2026-09-25). Sin cambios en `packages/Webkul/` ni en
`packages/ByLopez/CorteConfeccion/`. **No se ejecutó la reconciliación persistente sobre
`lpz_saas`.**

Regla que gobierna toda la fase: **no inventar historia.**

---

## 1. Qué se implementó

Comando `php artisan ventas-asesor:reconciliar-atribuciones` (spec: Doc. 08 §9).

| Opción | Efecto |
|---|---|
| *(ninguna)* / `--dry-run` | Analiza y reporta. **No escribe.** Es el modo por defecto. |
| `--persistir` | Escribe lo que el dry-run anuncia. Excluyente con `--dry-run`. |
| `--order=<id>` | Analiza un solo pedido. |
| `--limite=<n>` | Analiza como máximo *n* pedidos. |

No existe `--force`: el comando nunca sobrescribe una atribución existente.

Piezas: `Console/Commands/ReconciliarAtribucionesCommand`,
`Services/ReconciliadorAtribucionesService`, `DataObjects/AnalisisPedidoHistorico`,
`Enums/ResultadoReconciliacion`, `Support/FuenteEvidenciaHistorica` (contrato). Se reutilizan
`RegistrarAtribucionService`, `ResolverAtribucionService::paraPedidoSinEvidencia()` e
`IncidenciasAtribucionService`; no se duplicó lógica.

## 2. Por qué NO se reutiliza `ClasificadorOrigenPedido`

Ese clasificador lee la **petición actual** (ruta, request, guard, contexto por `cart_id`). Un
comando de consola no tiene la petición que creó el pedido hace días, y su propia ruta o su
usuario no dicen nada del pasado. El reconciliador **no depende** de esas clases (verificado
por reflexión en una prueba). La ausencia de request en Artisan no se interpreta como origen.

## 3. Evidencia histórica que conserva un pedido de Bagisto

Auditado sobre `orders` y `cart` (24 pedidos de desarrollo):

| Dato persistido | ¿Determina el origen? |
|---|---|
| `orders.cart_id` | **No.** Lo tienen pedidos de la tienda y del panel. (Los 24 lo tienen; en 16 el carrito ya no existe y en 8 sigue existiendo.) |
| `customer_id`, `customer_type`, `is_guest` | **No.** Idénticos en tienda y panel. |
| `channel_id`, `channel_name`, `channel_type` | **No.** Es el canal/tienda de Bagisto, no el canal comercial. |
| método de pago/envío, direcciones, fechas, estado | **No.** |
| `cart.checkout_method`, `cart.is_active` | **No** (nulos o no discriminantes). |
| Fila propia en `bylopez_va_order_attributions` | **Sí**, pero es la atribución misma (se ignora). |

**Conclusión:** el esquema de Bagisto **no guarda quién ni por dónde** se creó un pedido. Hoy no
existe ninguna evidencia determinista para pedidos anteriores al listener. Estos hechos se
muestran en el reporte como descripción, **nunca** como fundamento de una atribución.

## 4. Algoritmo

Por cada pedido (recorrido `lazyById`, memoria constante):

1. Tiene atribución **no** `fallo_tecnico` → `ya_atribuido`. Se ignora.
2. Tiene atribución `fallo_tecnico` → `fallo_tecnico` (informado, no tocado; corregirlo es una
   operación de dominio que esta fase no define). Si hubiera evidencia distinta → `conflicto`.
3. No tiene atribución:
   - consulta las **fuentes de evidencia** registradas (`FuenteEvidenciaHistorica`, tag
     `ventasasesor.fuentes_evidencia`); **hoy no hay ninguna**;
   - una fuente → `reconciliable` (registra ESE contexto);
   - fuentes contradictorias → `conflicto`;
   - ninguna → `sin_evidencia`.
4. Modo persistente: `reconciliable` → `RegistrarAtribucionService::registrar()`;
   `sin_evidencia` → `registrarSinEvidencia()` (**`sin_asesor`, canal `desconocido`, mecanismo
   `desconocido`, origen `sin_contexto`, sin asesor, sin creador**, con nota fija); `conflicto` →
   incidencia `atribucion_conflicto` solo si no hay una abierta.

`sin_evidencia` **no crea incidencia** (es el estado esperado de un pedido histórico; se reporta
en el comando). El motivo del historial usa el tipo de operación existente
`initial_assignment`; el tipo `reconciliation` del enum sigue sin usarse (no hacía falta).

## 5. Garantías

- `advisor_id` y `created_by_admin_id` **solo** salen del contexto que devuelve una fuente
  determinista; sin fuente quedan nulos.
- Nunca se usa cliente, nombre, fecha, grupo, canal, usuario autenticado ni la ruta actual.
- Idempotencia: la segunda ejecución ve `ya_atribuido`; sin historial, filas ni contadores
  duplicados. La incidencia de conflicto no se reabre ni incrementa `attempt_count` si ya está
  abierta.
- Aislamiento: cada pedido es independiente; un error en uno se informa como `error` y no revierte
  los demás. `RegistrarAtribucionService` ya abre su propia transacción: no se anida otra.
- Carrera con el listener: una atribución creada entre el análisis y la escritura se respeta y se
  reporta como conflicto.
- Salida sin datos del cliente (ni correo ni nombre).

## 6. Dry-run

`analizar()` es de solo lectura y en dry-run **no se invoca `aplicar()`**: la ruta de código ya
decide no persistir (no hay transacción con rollback). Prueba: con `DB::listen` se comprueba que
durante tres ejecuciones del comando no se emite ningún `INSERT/UPDATE/DELETE/DDL`, y que las
tablas propias, `orders` y `customers` conservan su conteo. Una mutación que hace que el dry-run
aplique rompe 3 pruebas.

## 7. Resultado sobre `lpz_saas` (solo `--dry-run`)

24 pedidos analizados: **24 `sin_evidencia`**, 0 ya atribuidos, 0 reconciliables, 0 fallos
técnicos, 0 conflictos, 0 errores. Tablas propias: 0 filas antes y después. El modo persistente
registraría, para cada uno, `sin_asesor` / canal `desconocido` / mecanismo `desconocido`.

## 8. Pruebas

`tests/Feature/ReconciliacionAtribucionesTest.php`: 15 casos. Suite del módulo: **213 casos, 1052
aserciones** (los 198/973 previos siguen verdes). Mutaciones demostradas: dry-run que aplica (3
fallos), sobrescribir `fallo_tecnico` (2), incidencia sin comprobar la abierta (1).

## 9. Semántica de propiedad y estado (cierre 2026-09-25)

Decisión vigente, sin estados nuevos. Los tres casos se expresan con los campos existentes:

| Caso | `commercial_owner_type` | `attribution_status` | Significado |
|---|---|---|---|
| A. Asesor conocido | `advisor` | `completa` | Existe asesor determinado (`advisor_id`). |
| B. Venta conocida de empresa | `company` | `completa` | Se sabe que comercialmente es de la empresa (p. ej. storefront). **Nunca** `sin_asesor`. |
| C. Venta no atribuida / no resuelta | `unassigned` | `sin_asesor` | Pendiente de asignación, histórico sin evidencia u origen no resuelto. **No** es venta de empresa. |

**Histórico sin evidencia** = caso C más `creation_source = desconocido`,
`commercial_owner_source = sin_contexto`, `sales_channel = desconocido`, `advisor_id = NULL`,
`created_by_admin_id = NULL` y la nota fija `NOTA_SIN_EVIDENCIA`
(`ResolverAtribucionService::paraPedidoSinEvidencia()`).

`unassigned + sin_asesor + desconocido` **no debe interpretarse ni documentarse como venta de
empresa**: es una atribución no resuelta.

**Por qué no `fallo_tecnico`.** `fallo_tecnico` queda reservado a errores del listener al crear el
pedido (Doc. 09) y alimenta `AtribucionPedido::scopeConIncidencia()`. Un pedido anterior al listener
no sufrió un fallo técnico: simplemente no dejó evidencia. Doc. 08 §7 asocia «contexto no
determinable» a `fallo_tecnico`, pero se refiere a la creación en vivo, no a la reconciliación
histórica.

## 10. Regla para la interfaz futura (bandeja, Fase 05)

Una interfaz **no** debe mostrar solo «Sin asesor» para `unassigned + desconocido + sin_contexto`,
porque sugiere que se confirmó una venta de empresa o que no hubo asesor. Debe distinguir:

| Categoría | Criterio con campos existentes |
|---|---|
| Venta de empresa conocida | `commercial_owner_type = company` |
| Venta asignada a asesor | `commercial_owner_type = advisor` |
| Venta sin asignar | `unassigned` + `sin_asesor` con `creation_source` conocido |
| Venta histórica / origen desconocido | `unassigned` + `sin_asesor` + `creation_source = desconocido` + `commercial_owner_source = sin_contexto` |
| Fallo técnico | `attribution_status = fallo_tecnico` |

Nota: `sin_contexto` también lo usa un administrador `all` que crea sin elegir propiedad; ahí el
`creation_source` es `admin_bagisto` y `created_by_admin_id` no es nulo, por eso la categoría
histórica exige además `creation_source = desconocido`.

Esta regla no se implementa ahora.

## 11. Estado operativo de los 24 pedidos de `lpz_saas`

Siguen **intactos**: sin atribución, sin historial, sin incidencias. El comando queda preparado y
probado; ejecutar `--persistir` sobre `lpz_saas` es una decisión operativa separada que requiere
autorización expresa.

## 12. Riesgos y deuda

- Registrar `sin_asesor/desconocido` es reversible solo por una operación de reasignación futura;
  por eso el modo persistente **no** se ejecutó en desarrollo.
- Los `fallo_tecnico` no tienen operación de corrección (fuera de alcance).
- No hay fuente de evidencia real hasta que una fase futura persista el origen (referencia
  externa, contexto durable del carrito).
- Sin bandeja para incidencias (Fase 05).
