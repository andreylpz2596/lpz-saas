# Auditoría funcional y técnica — Módulo Corte y Confección

**Alcance**: `packages/ByLopez/CorteConfeccion/src`
**Fecha**: 2026-08-27
**Método**: lectura completa de migraciones, modelos, contratos, repositorios, servicios, enums, form requests, controladores, vistas Blade, datagrids y configuración ACL/menú. Sin reestructuración; solo bugs evidentes de bajo riesgo corregidos (ver sección "Cambios aplicados en esta auditoría").

---

## 0. Resumen ejecutivo

El módulo cubre sólidamente **Proveedor de tela → Compra de tela → Rollos → Orden de corte → Recepción de corte**. A partir de ahí (**Entrega a taller/satélite → Confección → Entrega del taller → Validación de bodega → Ingreso a inventario**) **no existe absolutamente nada**: ni modelos, ni tablas, ni servicios, ni integración con `Webkul\Inventory`. Es la mitad exacta del flujo objetivo.

Dentro de lo que sí existe, la ingeniería es en general cuidadosa (fórmulas de cierre correctas, snapshots históricos de precio, guardas contra división por cero, doble validación backend de permisos sensibles), pero había **un bug funcional grave que dejaba el módulo prácticamente inutilizable para cualquier rol que no fuera super-admin** (ACL con rutas huérfanas) y varios huecos de autorización puntuales — corregidos en esta misma auditoría por ser de bajo riesgo. El resto de hallazgos (huecos de trazabilidad, ausencia de costo por orden, ausencia de evidencia fotográfica, duplicación de campos legado) se documentan para decisión posterior, sin tocar código.

---

## 1. Estado actual

| Etapa del flujo objetivo | Estado |
|---|---|
| Proveedor de tela | ✅ Completo |
| Compra de tela | ✅ Completo |
| Rollos | ✅ Completo (con deuda técnica de campos duplicados legado/normalizado) |
| Orden de corte | ✅ Completo, con huecos de trazabilidad de costo y de secuencia estricta de estados |
| Resultado del corte | ✅ Completo (cierre matemáticamente correcto) |
| Recepción de corte (bodega recibe el corte) | ⚠️ Parcial — compara cortador vs. bodega pero sobreescribe el valor original; sin evidencia fotográfica |
| Entrega a taller/satélite | ❌ No existe |
| Confección | ❌ No existe |
| Entrega del taller | ❌ No existe |
| Validación de calidad de bodega (sobre prenda confeccionada) | ❌ No existe |
| Ingreso a inventario | ❌ No existe — sin integración con `Webkul\Inventory` |

---

## 2. Funcionalidades completas

- **Proveedor de tela**: alta/edición, relación muchos-a-muchos real con tipos de tela (`bylopez_cc_proveedor_tela_tipos` → `attribute_options` del atributo Bagisto `tipo_tela`, resuelto siempre por `code`, nunca por ID fijo), referencias por tipo (color, referencia, gramaje, valor_kilo_referencia, estado) con repeater dinámico que hidrata correctamente al editar, y **restricción real de borrado de referencias en uso** (frontend + backend, `StoreProveedorTelaRequest.php:124-126`).
- **Compra de tela**: una compra = un proveedor (confirmado, un solo `proveedor_id`), snapshot histórico real por rollo (`tipo_tela`, `referencia`, `color`, `gramaje`, `valor_kilo`, `costo_total` se copian al `Rollo` en el momento de la compra, no se re-consultan del catálogo vigente), cálculo de subtotal/descuento/impuesto/total en backend (`CompraTelaService::totales()`), edición con bloqueo de peso para rollos que ya tienen órdenes de corte asociadas (`normalizarRollosBloqueados`).
- **Rollos**: todos los campos de trazabilidad solicitados existen (compra origen, proveedor, tipo de tela, color, referencia, gramaje, peso inicial, peso disponible, valor kilo, costo, estado).
- **Orden de corte**: código automático estable (`OC-{prefijo cortador}-{consecutivo}`, ej. `OC-WIL-000001`), cortador resuelto por rol real `cortador` en BD (no lista fija), snapshot de nombre (`cortador_nombre_snapshot`) que hace el código inmune a renombres posteriores del usuario, soporte multi-rollo con validación de compatibilidad (mismo tipo de tela/color/gramaje).
- **Cierre de orden**: fórmulas verificadas matemáticamente correctas y protegidas contra división por cero (ver sección 11).
- **Autorización de `cantidad_cortada`**: correctamente restringida a roles Admin/Cortador/Bodeguero, en frontend (input vs. texto de solo lectura según permiso) y backend (triple verificación: FormRequest, Controller, Service), incluyendo regla especial de que una orden **cerrada** solo la puede tocar Admin.
- **IDs quemados**: no se encontró ninguno en todo el módulo; toda resolución de atributos/roles es dinámica por `code`/nombre.

## 3. Funcionalidades parciales

- **Recepción de corte**: sí distingue "reportado por cortador" (`cantidad_real_cortada` en `OrdenCorteDetalle`) vs. "recibido por bodega" (`cantidad_recibida` en `RecepcionCorteDetalle`) y las compara, pero **cuando hay diferencia y el usuario tiene permiso, sobreescribe** `cantidad_real_cortada` con el nuevo valor en vez de conservar ambos como columnas separadas — el valor "reportado" original solo sobrevive en la tabla de auditoría, no como dato de negocio consultable directamente.
- **Estados de la orden**: la máquina de estados real (7 valores) es funcional pero no exige secuencia estricta — se puede registrar una recepción de bodega sin haber pasado por `en_corte`/`reportado_por_cortador` (solo excluye `anulado`/`cerrado`).
- **Auditoría**: existe pero cubre **únicamente** `cantidad_real_cortada`; no audita pesos, merma, recepciones, rechazos ni cambios de estado.
- **ACL/permisos**: granularidad de grano grueso (solo `index`/`create`/`edit`/`operar` genéricos); no hay clave dedicada para "cerrar orden" o "recibir en bodega" como acciones independientes. Las rutas ya quedaron mapeadas en esta auditoría (ver sección de cambios), pero la granularidad fina sigue pendiente de decisión.

## 4. Bugs encontrados (y su estado tras esta auditoría)

| # | Bug | Severidad | Estado |
|---|---|---|---|
| 1 | **ACL con 18 rutas POST/PUT/show sin mapear** (`acl.php`) — cualquier rol que no fuera super-admin (`permission_type = 'all'`) recibía 401 en casi toda acción de escritura del módulo (crear compra, cerrar orden, cancelar rollo, recibir en bodega, etc.) | 🔴 Crítica | **Corregido** en esta auditoría |
| 2 | `RolloController::cancel`, `OrdenCorteController::marcarEnCorte`, `reportar`, `cancel` sin ningún chequeo de permiso explícito en el código (dependían 100% de que la ruta estuviera en el mapa ACL, que no lo estaba — bug #1 los enmascaraba) | 🟠 Alta | **Corregido** en esta auditoría |
| 3 | `RolloService::update()` permite sobrescribir `peso_disponible`/`peso_inicial` de un rollo en estado `reservado_en_corte` (reservado por una orden abierta), pudiendo desincronizar el peso reservado en el pivote de lo que realmente tiene el rollo | 🟠 Alta | **Corregido** en esta auditoría (ahora lanza `ValidationException` si se intenta) |
| 4 | `referencias.*.gramaje` aceptaba `0` como válido (`gte:0`), físicamente imposible para una tela | 🟡 Media | **Corregido** en esta auditoría (`gt:0`) |
| 5 | Ninguno de los 5 DataGrids condiciona sus botones "Ver"/"Editar" a `bouncer()->hasPermission()` (a diferencia del patrón usado en `Webkul\Admin\DataGrids\Catalog\ProductDataGrid`) | 🟡 Media | No corregido — documentado, requiere decisión de diseño sobre qué permiso usar por botón |
| 6 | `ordenes-corte/show.blade.php`, `rollos/show.blade.php`, `compras-tela/show.blade.php` (estado de cabecera) muestran el estado como texto plano sin badge; solo `OrdenCorteDataGrid` (listado) tiene badges reales, y los badges de rollos dentro de `compras-tela/show.blade.php` usan una convención de color distinta a la del datagrid | 🟡 Media | No corregido — es la petición explícita del usuario en la sección 7, requiere trabajo de vista, se deja para implementación aprobada |
| 7 | `en/app.php` solo hace `return require '../es/app.php'` — no existe traducción real al inglés en todo el módulo | 🟢 Baja | No corregido — fuera de alcance de "bug evidente de bajo riesgo" (requiere traducir ~50 claves) |
| 8 | Cálculo de cierre (`peso_consumido = peso_base - sobrante - merma`) está implementado **tres veces de forma independiente** (`OrdenCorteService::cerrar()`, `RecepcionCorteService::create()`, `OrdenCorteService::calcularMetricasOrden()`) sin método compartido — hoy consistentes entre sí, pero riesgo de divergencia futura | 🟢 Baja | No corregido — refactor menor, se sugiere en roadmap |
| 9 | Ningún modelo del módulo tiene Contract/Repository real resuelto vía el contenedor (`ModuleServiceProvider::$models = []`, sin `bind()` en el ServiceProvider) — los `Contracts/*.php` son interfaces vacías puramente decorativas | 🟢 Baja | No corregido — arquitectónico, no afecta funcionalidad actual |

## 5. Riesgos de integridad de datos

1. **Doble fuente de verdad en `Rollo`**: coexisten `proveedor`/`tipo_tela`/`color` (texto libre, legado) con `proveedor_tela_id`/`tipo_tela_id`/`proveedor_tela_referencia_id` (FK normalizadas). Ninguna migración sincroniza ambos; un rollo creado manualmente vía `RolloController` (no vía compra) puede tener los campos de texto sin sus contrapartes FK.
2. **`bylopez_cc_ordenes_corte.rollo_id`** sigue siendo `NOT NULL` con cascada, pero la orden es multi-rollo desde hace varias migraciones — se rellena con el primer rollo de la selección. Cualquier consulta que lea `orden->rollo` en vez de `orden->rollosCorte` obtiene una imagen parcial/engañosa de una orden con más de un rollo.
3. **`RecepcionCorte.recibido_por`** es texto libre, no FK a `admins` — no hay trazabilidad fuerte de qué usuario del sistema recibió, solo lo que se escribió a mano.
4. **Alta manual de rollo** (`RolloController`/`StoreRolloRequest`, sin pasar por `CompraTelaService`) no captura `valor_kilo` — rompe la trazabilidad de costo histórico para rollos que no vienen de una compra registrada en el módulo.
5. **Sobrescritura de `cantidad_real_cortada`** en `RecepcionCorteService::create()` (ver sección 3) — el valor "reportado por cortador" original deja de ser consultable como dato de negocio una vez que bodega registra una diferencia; solo queda en el log de auditoría.
6. **Secuencia de estados no forzada**: se puede recibir en bodega sin pasar por `en_corte`/`reportado_por_cortador`.

## 6. Relaciones actuales de base de datos

```
ProveedorTela 1───N CompraTela
ProveedorTela N───N AttributeOption(tipo_tela)   [pivote bylopez_cc_proveedor_tela_tipos]
ProveedorTela 1───N ProveedorTelaReferencia
ProveedorTelaReferencia N───1 AttributeOption(tipo_tela)
ProveedorTelaReferencia 1───N Rollo

CompraTela 1───N Rollo

Rollo N───1 CompraTela (nullable)
Rollo N───1 ProveedorTela (nullable, FK directa además de la vía referencia)
Rollo N───1 AttributeOption(tipo_tela) (nullable)
Rollo N───1 ProveedorTelaReferencia (nullable)
Rollo 1───N OrdenCorte (vía rollo_id de cabecera, legado/parcial)
Rollo 1───N OrdenCorteRollo (pivote real multi-rollo)

OrdenCorte N───1 Rollo (cabecera, ver riesgo 2)
OrdenCorte 1───N OrdenCorteDetalle
OrdenCorte 1───N OrdenCorteRollo (pivote: peso_inicial_usado, peso_sobrante, peso_consumido)
OrdenCorte 1───1 RecepcionCorte

OrdenCorteDetalle N───1 Product (ProductProxy)
OrdenCorteDetalle 1───N (registros en bylopez_cc_orden_corte_cantidad_audits, sin modelo Eloquent)

RecepcionCorte 1───N RecepcionCorteDetalle
RecepcionCorteDetalle N───1 Product (ProductProxy)
```

## 7. Relaciones faltantes para el flujo completo

- `Taller`/`Satélite` (maquilador externo) — no existe ninguna entidad.
- `OrdenConfeccion` y su detalle por producto/talla — no existe.
- Relación `OrdenConfeccion N─N Taller` con entregas parciales (entregado/primer retorno/segundo retorno/pendiente) — no existe.
- `ControlCalidadBodega` (cantidad recibida / aprobada / rechazada / pendiente / reproceso) sobre la prenda confeccionada — no existe (lo que hoy existe, `cantidad_defectuosa` en `RecepcionCorteDetalle`, es sobre el **corte**, no sobre la prenda terminada).
- Cualquier FK o evento hacia `Webkul\Inventory` — no existe.
- Tabla/columna para **costo de tela consumida por orden** (`peso_consumido * valor_kilo` del rollo) — los datos base existen (`OrdenCorteRollo.peso_consumido`, `Rollo.valor_kilo` histórico) pero no hay ningún cálculo ni columna que los combine.
- Campo de **evidencia fotográfica** (adjunto de imagen) en `RecepcionCorteDetalle` o una futura recepción de taller — no existe en ningún punto del módulo.

## 8. Problemas de UX

- Ninguna acción destructiva/irreversible (anular rollo, anular orden, cerrar orden, marcar en corte, reportar) tiene confirmación (`confirm()`/modal) — es un submit directo.
- Badges de estado inconsistentes entre pantallas (sección 4, bug #6).
- Falta de loading state en el buscador de referencias de `compras-tela/form.blade.php` (sí lo tiene el buscador de productos de `ordenes-corte/create.blade.php` — inconsistencia entre pantallas del mismo módulo).
- Cadenas de UI hardcodeadas en español directamente en Blade/DataGrids en vez de usar `trans()`, inconsistente con el propio estándar de traducciones del proyecto.
- Campo `gramaje` en `compras-tela/form.blade.php` es `readonly` sin ayuda contextual de por qué (se autocompleta al elegir referencia).

## 9. Problemas de permisos

- Bug #1 y #2 (sección 4) — **corregidos**.
- DataGrids sin condicionar acciones a permisos (bug #5) — pendiente.
- ACL de grano grueso: no hay clave independiente para "cerrar orden", "cancelar orden", "cancelar rollo" o "recibir en bodega" — todas comparten la clave `operar` o la clave `edit` genérica. Aceptable como fix mínimo, pero si se necesita que, por ejemplo, un Bodeguero pueda recibir pero no cerrar/cancelar, hace falta dividir esa clave (cambio estructural, no aplicado — requiere aprobación).
- `RecepcionCorteController::store` usa la clave genérica `corte_confeccion.recepciones_corte` (la misma del índice) en vez de una `.create` dedicada — funcional, pero no distingue "ver recepciones" de "crear recepciones".

## 10. Problemas de estados

- Nombres reales de `EstadoOrdenCorte` difieren de los propuestos en el enunciado: `entregado_a_cortador` (no `entregado`), `reportado_por_cortador` (no `cortado`); no existe `borrador` (la orden nace en `pendiente_entrega`). Ver tabla comparativa completa en sección 11.
- No hay bloqueo explícito de edición de rollos/cantidades/pesos/merma cuando la orden está `cerrado` más allá de lo que cada método individual ya valida puntualmente (p. ej. `cerrar()` exige `recibido_bodega`, `cancel()` bloquea si ya está `cerrado`) — no hay un guard centralizado tipo "si cerrado, ninguna escritura excepto por administrador".
- `EstadoRollo` real: `disponible`, `parcialmente_usado`, `reservado_en_corte`, `agotado`, `anulado` (5 valores) — cubre los 6 propuestos por el usuario salvo que `en_corte` no es un estado independiente (está fusionado con `reservado_en_corte`).

## 11. Problemas de cálculos

**Cierre de orden — verificación matemática** (`OrdenCorteService::cerrar()`):
- `peso_consumido = peso_base − peso_sobrante − merma` ✅ correcto, con guarda `(sobrante + merma) > pesoBase → excepción`.
- `peso_promedio_pieza = peso_consumido / total_piezas_cortadas` ✅ correcto, con guarda explícita contra `total_piezas_cortadas == 0` (excepción antes de llegar a la división).
- **Matiz conceptual**: `peso_base` usado en el cierre es `peso_entregado` (lo entregado al cortador) por defecto, no el "peso total inicial de los rollos" (`peso_total_rollos`) — ambos existen como columnas pero el cierre prioriza el primero. Si el objetivo de auditoría es rastrear mermas de logística/reserva (no solo de corte), esta elección debe validarse con negocio.
- **Redundancia**: la misma fórmula está implementada 3 veces en archivos distintos (bug #8, sección 4) — hoy consistente, riesgo de divergencia futura.
- **`peso_disponible` de rollo**: se descuenta **una sola vez**, en el cierre (`actualizarRollosAlCerrar()`) — no hay doble descuento. Mientras la orden está abierta, el rollo solo cambia de `estado` (a `reservado_en_corte`), su peso permanece intacto. Esto es correcto salvo por el riesgo #3 de bug (ya corregido).
- **Costo de tela consumida**: no calculado en ningún punto (sección 7) — dato faltante para reportes de costo por prenda.

## 12. Recomendaciones

Ordenadas por la prioridad de la sección 13:

1. **(Crítica, ya corregida)** Completar mapeo ACL de rutas — hecho en esta auditoría.
2. **(Alta)** Diseñar y aprobar el modelo de datos para Taller/Satélite → OrdenConfeccion → Recepción de taller → Control de calidad de bodega → Movimiento de inventario. Es el 50% del flujo objetivo que falta por completo.
3. **(Alta)** Decidir si `cantidad_real_cortada` debe conservarse inmutable (con `cantidad_recibida_bodega` como columna separada) en vez de sobreescribirse — afecta directamente la trazabilidad "cortador reportó 885, bodega recibió 882" que pide el usuario.
4. **(Alta)** Agregar cálculo de costo de tela consumida por orden (`SUM(peso_consumido * valor_kilo)` sobre `OrdenCorteRollo` ⋈ `Rollo`) — los datos base ya existen, solo falta el cálculo y su persistencia/exposición.
5. **(Media)** Agregar campo de adjunto/imagen a la recepción de corte (y a la futura recepción de taller) para evidenciar daños/faltantes.
6. **(Media)** Unificar badges de estado en una sola convención (reutilizar el mapeo de `OrdenCorteDataGrid::estadoBadge()` también en las vistas `show` y en `RolloDataGrid`/`CompraTelaDataGrid`).
7. **(Media)** Condicionar acciones de los 5 DataGrids a `bouncer()->hasPermission()`, siguiendo el patrón de `ProductDataGrid`.
8. **(Media)** Granularizar ACL si el negocio necesita separar quién cierra/cancela/recibe de quién simplemente edita — actualmente todo comparte `operar`/`edit`.
9. **(Baja)** Extraer la fórmula de cierre a un único método compartido, invocado desde los tres puntos que hoy la reimplementan.
10. **(Baja)** Resolver la duplicación de campos legado/normalizados en `Rollo` (decidir si retirar `proveedor`/`tipo_tela`/`color` de texto libre ahora que existen las FK).
11. **(Baja)** Registrar el módulo en `config/concord.php` con bindings reales Contract→Model si en algún momento se va a aprovechar el patrón Proxy de Bagisto para este módulo.
12. **(Baja)** Traducir `en/app.php` de verdad en vez de reexportar `es/app.php`.

## 13. Prioridad de los hallazgos abiertos (no corregidos en esta auditoría)

- 🔴 **Crítica**: ninguna pendiente (la única crítica encontrada —ACL— ya fue corregida).
- 🟠 **Alta**: flujo Taller/Confección/Inventario inexistente; sobrescritura de `cantidad_real_cortada` en vez de trazabilidad reportado-vs-recibido; ausencia de costo de tela consumida por orden.
- 🟡 **Media**: DataGrids sin permisos por acción; badges de estado inconsistentes; ACL de grano grueso; falta de evidencia fotográfica; secuencia de estados no forzada.
- 🟢 **Baja**: triplicación de fórmula de cierre; Contracts decorativos sin binding; traducción al inglés inexistente; duplicación legado/normalizado en `Rollo`; UX menor (confirmaciones, loading states).

## 14. Roadmap sugerido (no implementado, pendiente de aprobación)

**Fase 1 — Cerrar huecos del flujo actual (antes de construir taller/confección)**
- Separar `cantidad_real_cortada` (cortador) de `cantidad_recibida_bodega` (bodega) como columnas independientes en vez de sobreescritura.
- Calcular y persistir costo de tela consumida por orden.
- Agregar campo de adjunto/imagen a `RecepcionCorteDetalle`.
- Unificar badges de estado y condicionar DataGrids a permisos.

**Fase 2 — Taller/Satélite**
- Modelo `Taller` (catálogo simple: nombre, contacto, estado).
- Modelo `OrdenConfeccion` (cabecera: taller, orden de corte origen, fecha entrega, estado) + `OrdenConfeccionDetalle` (producto/talla, cantidad entregada).
- Soporte de entregas parciales: modelar como `OrdenConfeccionRetorno` (uno o más registros de retorno por orden, cada uno con cantidad y fecha), en vez de una sola columna "cantidad recibida".

**Fase 3 — Control de calidad de bodega**
- `RecepcionTallerDetalle` con cantidad recibida / aprobada / rechazada / pendiente / reproceso, y adjunto fotográfico.

**Fase 4 — Inventario**
- Evento/servicio `IngresoPorConfeccion` que solo se dispare tras aprobación de bodega, con trazabilidad completa (`orden_confeccion_id`, `recepcion_id`, producto/variante, cantidad, usuario, fecha) hacia `Webkul\Inventory`.

**Fase 5 — Reportes**
- Con Fase 1–4 completas, los datos ya permiten calcular: producción por mes/cortador/producto, rendimiento por rollo/proveedor/tipo de tela/color, camisetas por kilogramo, merma por orden/rollo, costo de tela por prenda, producción/rechazos por taller. **Tiempo promedio de confección** requerirá además registrar `fecha_entrega_taller` y `fecha_retorno_taller` por orden de confección (parte del modelo de Fase 2).

---

## Cambios aplicados en esta auditoría (bajo riesgo, dentro del alcance autorizado)

Archivos modificados — todos dentro de `packages/ByLopez/CorteConfeccion/`:

1. **`src/Config/acl.php`** — se completó el mapeo `route` de 18 rutas POST/PUT/show que no estaban en ningún mapa ACL (usando `'route' => [...]` como arreglo, mismo patrón que usa `Webkul\Admin\Config\acl.php` para `sales.orders.create`). Esto no crea permisos nuevos ni cambia quién puede hacer qué — solo hace que las claves ACL **ya existentes** (`compras_tela.create`, `.edit`, `rollos.create`, `.edit`, `ordenes_corte.create`, `.operar`, `recepciones_corte`) cubran también las rutas POST/PUT/show correspondientes, que antes quedaban huérfanas y devolvían `401` a cualquier rol restringido.
2. **`src/Http/Controllers/Admin/RolloController.php`** — se agregó `abort_if(! bouncer()->hasPermission('corte_confeccion.rollos.edit'), 403)` al inicio de `cancel()`, que no tenía ningún chequeo de permiso propio.
3. **`src/Http/Controllers/Admin/OrdenCorteController.php`** — se agregó el mismo tipo de chequeo (`corte_confeccion.ordenes_corte.operar`) a `marcarEnCorte()`, `reportar()` y `cancel()`, que tampoco lo tenían.
4. **`src/Services/RolloService.php`** — `update()` ahora rechaza (con `ValidationException`) cualquier intento de modificar `peso_disponible` o `peso_inicial` mientras el rollo está en estado `reservado_en_corte`, evitando que se desincronice del peso ya comprometido en una orden abierta.
5. **`src/Http/Requests/StoreProveedorTelaRequest.php`** — `referencias.*.gramaje` pasó de `gte:0` a `gt:0` (un gramaje de 0 no es válido).
6. **`src/Resources/lang/es/app.php`** — se agregó la clave `errors.rollo-reservado-no-editable` usada por el punto 4 (no se tocó `en/app.php` porque reexporta el archivo `es`, ver bug #7).

**Verificación realizada**: `php -l` sin errores en los 6 archivos, `vendor/bin/pint` aplicado (solo estilo), `php artisan optimize:clear` sin errores, y confirmación vía Tinker de que las 4 rutas antes huérfanas (`compras_tela.store`, `ordenes_corte.cerrar`, `rollos.cancel`, `recepciones_corte.store`) ahora resuelven a su clave ACL correcta. No se ejecutó `migrate:fresh`, no se borraron datos, no se tocó `packages/Webkul/`.

**No se aplicaron** cambios estructurales (nuevas tablas, nuevos estados, separación de `cantidad_real_cortada`/`cantidad_recibida_bodega`, cálculo de costo, evidencia fotográfica, modelos de Taller/Confección) — todos quedan documentados en las secciones 7, 12 y 14 para aprobación previa antes de implementar.
