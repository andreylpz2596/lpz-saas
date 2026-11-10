# 06 — Reasignación de prendas pendientes entre talleres

Implementada el 2026-10-29 (plan de validación de Corte y Confección, sección 6). Solo `packages/ByLopez/CorteConfeccion/`.
Pruebas: `tests/Feature/ReasignacionPrendasTest.php` (R01–R23), `tests/Concurrencia/ReasignacionConcurrenteTest.php` (procesos reales),
`tests/Feature/PermisosMatrizEndpointsTest.php`, `tests/Feature/TarifasPorCombinacionTest.php`.

## Qué resuelve

Cambiar la **responsabilidad** sobre una cantidad concreta de una variante exacta (`product_id`) de un corte, de un taller a otro
(parcial, varias variantes o todo el saldo elegible). No cambia el `taller_id` de la orden, no borra detalles, no cancela y recrea,
no edita ENT anteriores, no toca inventario terminado ni tela.

## Modelo (migración aditiva `2026_10_29_000001`)

| Dónde | Campo | Significado |
|---|---|---|
| `bylopez_cc_reasignaciones` | operación auditada | código `RA-######`, corte, talleres y órdenes origen/destino, `estado`, `situacion_fisica`, `motivo`, `observacion`, `idempotency_key` (única) + `payload_hash`, quién/cuándo (reasigna, recibe, cierra) |
| `bylopez_cc_reasignacion_lineas` | una fila por variante | detalle origen y destino, cantidad, `cantidad_en_proceso_declarada` (evidencia) |
| `…_ordenes_confeccion_detalles` | `cantidad_reasignada_salida` | lo que el detalle **cedió** a otro taller |
| `…_ordenes_confeccion_detalles` | `cantidad_en_transito` | lo recibido por reasignación cuyo traslado físico aún no se confirma (no reportable) |
| `…_ordenes_confeccion` | `reasignacion_origen_id` | marca la orden destino creada por una reasignación |

Migración `2026_10_29_000002` (hallazgo H07): `attribute_family_id` conservado en los detalles de corte y de confección (relleno con la
familia actual del producto; después ya no cambia con el catálogo).

**Una sola definición de la responsabilidad** (`OrdenConfeccionDetalle`):

- `cantidad_vigente = cantidad_entregada − cantidad_reasignada_salida` (la asignación original nunca se edita; `SQL_VIGENTE` para agregados).
- `pendientePorReportar() = vigente − en_transito − Σ reportado` (incluye ENT `pendiente_bodega`). **Es también lo único reasignable.**

La usan: `DisponibilidadConfeccionService`, `OrdenConfeccionService` (reportar, estado, cancelar), `OrdenTarifaService`, `OrdenConfeccionDataGrid`,
`TallerDataGrid`, resumen de `TallerController`, `ReportarOrdenConfeccionRequest` y las vistas de orden de confección, orden de corte y taller.

## Diseño

- **Orden destino vinculada y nueva** (consecutivo `OF-######` propio): Andrea conserva su orden de 300 y suma otra de 100; nada se fusiona ni se reabre.
  Una sola representación del ingreso: el detalle destino (no hay «entrada de traslado» aparte).
- **Invariante por variante**: Σ responsabilidades vigentes + saldo sin asignar = aptas del corte, antes y después. Con el disponible global en cero también funciona
  (mueve responsabilidad, no libera al pool).
- **Origen en cero** → estado `reasignada` (explícito; no queda «activa» eterna ni «anulada», que liberaría las entregas históricas).
- **Estados de la operación**: `en_transito` → `aplicada`; compensaciones `cancelada` (antes de recibir) y `revertida` (después). Nunca se muestra una solicitud pendiente como recibida.
- **Situación física**: `sin_entrega_fisica` (solo responsabilidad) o `entregada_verificada` (exige confirmación explícita de que no tienen trabajo; traslado
  `completado` o `en_transito`). Las prendas «en proceso» sin reportar se **declaran** (`cantidad_en_proceso`) y se excluyen: máximo = pendiente − en proceso.
- **Reversión**: operación compensatoria; solo si el destino conserva el saldo sin reportar y el origen sigue abierto (no cerrada/recibida/anulada). Si el destino ya reportó
  o cedió a un tercero, se bloquea (cadena A→B→C se revierte de C hacia A).
- **Anular una orden con reasignación vigente o con ENT está bloqueado** (libera al pool lo que no debe).
- **Tarifas**: el destino toma SU tarifa vigente o queda «Sin tarifa» (nunca copia la del origen); el origen conserva la suya y muestra la cantidad vigente. Se puede pactar
  después con el permiso existente. El modal muestra el impacto referencial.
- **Corte padre cerrado** no bloquea (cierre de corte ≠ cierre de confección).

## Seguridad y concurrencia

- ACL propia `corte_confeccion.ordenes_confeccion.reasignar` + `ReasignacionGate` (denylist de roles `taller confeccion` y `cortador`, incluso con `permission_type = all`).
  `StoreOrdenConfeccionRequest` (endpoint antiguo, H02) usa ahora `DistribucionConfeccionGate`.
- Un usuario de taller no ve el nombre ni el enlace del otro taller en el historial.
- Orden de bloqueo único: **recepción del corte → órdenes (id ascendente)**; la primera lectura consistente va después de los bloqueos. `cancel` usa el mismo orden y estado fresco (H06).
- Idempotencia: llave única persistida; misma llave + mismo contenido devuelve la operación previa; misma llave + contenido distinto → `ReasignacionConflictoException` (HTTP 409).
- Validación estricta en servicio (enteros > 0, sin decimales, detalle propio, variante simple, destino activo y distinto, motivo) y todo-o-nada.
- Punto de extensión/fallo inducido: evento `ReasignacionService::EVENTO_DESCONTADO` entre el descuento del origen y la creación del destino.

## Rutas

`GET ordenes-confeccion/reasignar/{id}` (contexto), `POST ordenes-confeccion/reasignar/{id}`, `POST ordenes-confeccion/reasignaciones/{id}/recibir`,
`POST ordenes-confeccion/reasignaciones/{id}/revertir`.

## Pendiente / decisiones

- **Asignar la ACL `reasignar`** en Settings → Roles (ADMIN la tiene por `all`). Sin ella el botón no aparece.
- Migraciones: `2026_10_29_000001/2` aplicadas el 2026-10-29 en `lpz_saas` (dev, batch 72, verificado en la tabla `migrations` el 2026-10-31), `lpz_saas_testing` y la demo; `2026_10_31_000001` (constancia de devolucion) aplicada en dev y pruebas. La frase anterior «pendiente en dev» era un texto desactualizado.
- Familia historica (H07): para filas anteriores a la migracion, `attribute_family_id` se relleno con la familia **vigente del producto al migrar** (29-oct-2026); es la unica referencia disponible, no necesariamente la que tenia al crearse. Desde entonces se conserva al crear cada detalle.
- Custodia fisica: devolver al origen prendas que salieron (`entregada_verificada`), en transito o ya recibidas, exige constancia de devolucion y confirmacion explicita de que estan de regreso; sin salida fisica no se exige.
- El sistema no sabe qué prendas están físicamente «en proceso»: lo declara el operador. Si se quiere control real, haría falta registrar el despacho físico al taller (no existe hoy).
- Cuenta/pagos a prestadores (Fase 3) y N1 siguen pendientes de decisión; la reasignación no los implementa ni los bloquea.
