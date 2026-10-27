# Estado desarrollo — Distribución y Confección

## 05 — Tarifas y cuentas por pagar de prestadores (talleres y cortadores) — FASE 2 AJUSTADA A REFERENCIAS, PENDIENTE DE PRUEBA MANUAL (2026-10-14)

Reglas, modelo, permisos y plan en `docs/confeccion/05_TARIFAS_CUENTAS_PRESTADOR.md` (decisiones de Camilo: R13–R16 y, el 2026-10-14, tarifas por referencia).

**Fase 2 (hecha, ajustada):** la «tarifa general con excepciones» se reemplazó por una tabla explícita **«Referencias y precios de
confección / de corte»** en la ficha del taller y del cortador: cada fila es tipo de tela (atributo Bagisto `tipo_tela`) + referencia
del producto (familia Bagisto) con su valor por unidad y vigencia; cambiar la tarifa crea una vigencia nueva (sin superposición) y
nunca reescribe el historial ni las órdenes. Cada orden conserva una tarifa **por combinación** (órdenes mixtas) en
`bylopez_cc_orden_tarifas`; sin tarifa vigente queda «Sin tarifa» (no se asume 0 ni se aplica una general); se puede pactar otra
solo para esa combinación en esa orden, con motivo, auditado. Las 2 tarifas generales de prueba (taller 19, cortador 1) se
conservan sin efecto: **pendiente configurar todas las referencias**. Permisos `corte_confeccion.talleres.tarifas` /
`corte_confeccion.cortadores.tarifas` (asignarlos en Roles). Migraciones aplicadas solo en dev. No se crearon ni ejecutaron tests
(los hace Camilo). `packages/Webkul/` sin cambios. Cantidades pagables, recepción, defectuosas y cierre NO se tocaron.

**Pendiente:** N1 (el taller declara prendas defectuosas no confeccionables) sigue en pausa; Fase 3 (cuenta/pagos) aún no iniciada.

## ORDEN_CORTE_V2 — F5: Adicionales del cortador por resolvedor — COMPLETADA (2026-09-25)

Los adicionales se piden por linea+color+talla (sin product_id), se resuelven con `SeleccionOrdenCorteService`/`resolverLote` dentro de una sola transaccion (rollback total ante error), quedan como detalles separados (`es_adicional`, proyectada 0) y Bodega/Confeccion siguen igual (por detalle / por product_id). Tests: 14 nuevos; suite 382 passed / 2057 aserciones. Detalle en `docs/ORDEN_CORTE_V2_AVANCE_IMPLEMENTACION.md`, seccion F5.

## ORDEN_CORTE_V2 — PRE-F5 / PRE-F5.1: Saneamiento de catálogo — COMPLETADO (2026-09-24)

**PRE-F5 COMPLETADO. CATÁLOGO ESTRUCTURAL LISTO PARA CORTE V2 (0 errores). 110DURZ-H pendiente solo de decisión comercial. F5 NO INICIADA.** PRE-F5.1: creada la opción de color «Terracota» (id 4441) y vinculados los 4 hijos de `124DURZ-D` (mismo product_id, stock/pedidos intactos); Mujer/Terracota resuelve a 124DURZ-D y Mujer/Rojo a 110DURZ-DAMA. Historial del primer paso:

Solo datos de `lpz_saas`, sin cambios de código. Corregidos 10 simples (103DURZ-H → Cafe, 107DURZ-H-XL →
Azul Oscuro, 121DURZ-H → Verde Manzana, 125DURZ-D-S → Verde Menta) con el repositorio oficial de Bagisto,
sin mover stock ni historial. Diagnóstico de catálogo: **8 errores → 1** (queda Mujer/Rojo por
`124DURZ-D`: el color Terracota no existe; requiere decisión humana). `110DURZ-H` (EAV 0 vs flat 1) sin
tocar. **Catálogo listo para producción: NO.** F5 NO iniciada. Detalle en el MD, sección PRE-F5.

## ORDEN_CORTE_V2 — F4: Creación V2 de Orden de Corte — COMPLETADA TÉCNICAMENTE (2026-09-25)

**F4 COMPLETADA TÉCNICAMENTE. CATÁLOGO PRODUCTIVO PENDIENTE DE CORREGIR AMBIGÜEDADES. F5 NO INICIADA.**
Detalle en `docs/ORDEN_CORTE_V2_AVANCE_IMPLEMENTACION.md`, sección «F4 — Creación V2 de Orden de Corte».

- Flujo: rollos (mismo `tipo_tela_id`; multi-color y multi-gramaje) → colores reales de los rollos →
  líneas (`lineasPorTipoTela`) → tallas reales (`opcionesEjeLote`) → revisión → crear. Payload
  `rollo_ids` + `seleccion[{attribute_family_id, color_option_id, eje_option_id, filtros?}]`, sin
  `product_id`; el servidor resuelve con `resolverLote()` (`OrdenCorteService::crearDesdeSeleccion`,
  transacción con bloqueo de rollos y rollback total).
- Detalles: simple exacto, `cantidad_proyectada = 0`, snapshots (SKU, nombre, línea, color — columna
  nueva `color` nullable —, talla). `OrdenCorte.color`/`genero_linea` = resúmenes de lectura.
- Show/cortador/Bodega adaptados (línea·color·talla·SKU; sin proyección en V2); históricas intactas.
- F3, PRE-F4 y PRE-F4.1 intactos. 19 tests nuevos; suite **370 passed / 1976 assertions**; Pint verde;
  `packages/Webkul/`: 0 modificaciones.
- **Catálogo NO corregido:** los 5 configurables problemáticos siguen bloqueando con `color_ambiguo`;
  no declarar el catálogo listo para producción.

## ORDEN_CORTE_V2 — PRE-F4.1: Coherencia del punto de no retorno — COMPLETADO (2026-09-24)

**PRE-F4.1 COMPLETADO. F4 NO INICIADA.** `en_corte` es el punto definitivo (derivado de
`OrdenCorteEstadoMachine`): desde allí no se agregan ni retiran rollos y la orden no
puede anularse («No se puede anular una Orden de Corte después de iniciar el corte»);
antes de en_corte todo sigue igual (cancelar libera los rollos). La ficha oculta
«Anular», «Agregar rollo» y «Retirar» desde en_corte; el backend es la autoridad. 6
tests nuevos; suite completa **351 passed / 1821 assertions**; Pint verde;
`packages/Webkul/`: 0 modificaciones. Detalle en
`docs/ORDEN_CORTE_V2_AVANCE_IMPLEMENTACION.md`, sección «PRE-F4.1».

## ORDEN_CORTE_V2 — PRE-F4: Blindaje de rollos en Compra de Tela + retiro formal — COMPLETADO (2026-09-24)

**PRE-F4 COMPLETADO. F4 NO INICIADA** (requiere aprobación explícita). Detalle en
`docs/ORDEN_CORTE_V2_AVANCE_IMPLEMENTACION.md`, sección única "PRE-F4 — Blindaje de
rollos en Compra de Tela".

- **Bug:** `CompraTelaService::sincronizarRollos()` solo protegía rollos con la FK
  histórica `ordenes_corte.rollo_id` (primer rollo de la orden); los rollos 2..N
  (solo en el pivote `orden_corte_rollos`) podían cambiar de color, volver a
  `disponible` o eliminarse (con su pivote por cascada) al editar la compra.
- **Regla:** `Rollo::estaVinculadoAOrdenCorte()` (pivote U FK, definición única).
  Compra NUNCA cambia, reactiva, elimina ni desvincula un rollo vinculado (falla
  explícita, transacción atómica, solo `observacion` editable). Rollo libre: normal.
- **Punto de no retorno = `en_corte`** (derivado de `OrdenCorteEstadoMachine`):
  antes (pendiente_entrega, entregado_a_cortador) el rollo se libera con el nuevo
  **retiro formal** `OrdenCorteService::retirarRollo()` (botón «Retirar» en la
  ficha de la orden; reasigna la FK legacy; no permite retirar el último rollo);
  desde en_corte queda bloqueado. Órdenes anuladas: rollo liberado de estado pero
  vinculado históricamente (bloqueado) — decisión del usuario.
- **Referencia cambia de color:** no propaga a rollos bloqueados; rollos nuevos
  heredan el color actual. F1-D (snapshot inmutable) conservado.
- **Tests:** 21 nuevos. Suite completa: **345 passed / 1748 assertions**. Pint verde.
- **`packages/Webkul/`: 0 modificaciones.**

## ORDEN_CORTE_V2 — F3: Merma total de tela por Orden de Corte — COMPLETADA (2026-09-24)

**F3 COMPLETADA. F4 NO INICIADA** (requiere aprobación explícita). Detalle
completo (auditoría de pesos, modelo, contrato de recepción, tests) en
`docs/ORDEN_CORTE_V2_AVANCE_IMPLEMENTACION.md`, sección "F3 — Merma total de tela
por Orden de Corte".

- **Problema:** `actualizarRollosAlCerrar()` repartía en cascada el
  `peso_sobrante_total` (sobrante usable capturado por Bodega) entre los rollos
  de la orden; correcto solo mientras todos los rollos eran intercambiables, y
  atribuiría tela de un color a rollos de otro con rollos mezclados (F4).
- **Modelo:** se reutiliza `OrdenCorte.merma_total` (kg de tela no reutilizable de
  toda la orden) y se agrega el marcador `bylopez_cc_ordenes_corte.merma_unica`
  (boolean, default `false`; migración `2026_09_24_000001`). `false` = orden
  histórica (contrato antiguo, cascada intacta); `true` = recepción creada con el
  contrato F3. Ninguna orden existente se convierte ni recalcula.
- **Recepción de Bodega:** un único campo «Merma / sobrante no reutilizable» (kg)
  para toda la orden; sin inputs por rollo/color/gramaje; sobrante usable,
  retazos y desperdicio ya no se aceptan. Validación en Request y Service:
  numérico, `>= 0`, hasta 3 decimales y `<= peso de tela asignado`
  (`OrdenCorte::pesoBase()`).
- **Cierre F3:** pivote `peso_sobrante = 0`, `peso_consumido = peso_inicial_usado`;
  rollo con `peso_disponible = 0` (agotado). La merma nunca se atribuye a un rollo
  y nunca incrementa `Rollo.peso_disponible`. La cascada solo corre para
  históricas.
- **Decisión del usuario:** el sobrante usable se retira en F3 (se pierde el
  retorno de remanentes reutilizables hasta que exista un flujo con identidad
  física).
- **Sin cambios:** cantidades recibidas/defectuosas, `orden_corte_detalle_id`,
  `DisponibilidadConfeccionService`, distribución/confección, `create()` de
  Orden de Corte (la compatibilidad de rollos sigue hasta F4).
- **Tests:** 20 nuevos. Suite completa de CorteConfeccion: **316 passed / 1497
  assertions**. `vendor/bin/pint --dirty` verde.
- **`packages/Webkul/`: 0 modificaciones.**

## ORDEN_CORTE_V2 — F2: Resolvedor de variantes + diagnóstico de catálogo — COMPLETADA (2026-09-24)

Solo lectura sobre el catálogo Bagisto; sin migraciones, sin config con IDs,
sin tabla de líneas/perfiles y sin UI. Detalle completo (diseño, API, errores,
advertencias, diagnóstico real contra `lpz_saas`, tests) en
`docs/ORDEN_CORTE_V2_AVANCE_IMPLEMENTACION.md`, sección "F2 — Resolvedor de
variantes".

- **Servicio nuevo:** `src/Services/ResolvedorVarianteCorteService.php`, único
  punto del módulo nuevo que interpreta la estructura Bagisto para Orden de
  Corte. Resuelve en dos niveles con unicidad obligatoria:
  `(tipo_tela, línea = familia, color) → 1 configurable` y
  `(configurable, color, eje 2) → 1 simple`. Atributos por `attributes.code`,
  color/eje por `attribute_options.id`, estado (`status`) desde el EAV (nunca
  `product_flat`), productos inactivos en tienda **no bloquean** (advertencia).
- **API:** `lineasPorTipoTela`, `opcionesEje`/`opcionesEjeLote`,
  `resolver`/`resolverLote`, `diagnosticarCatalogo`. Consultas por lote (8
  fijas por llamada, sin N+1). Filtros estructurados opcionales ya soportados
  para una futura línea = familia + filtros.
- **Diagnóstico de solo lectura:**
  `php artisan corteconfeccion:diagnosticar-catalogo [--tipo-tela=ID] [--sku=SKU] [--json]`.
- **Contra `lpz_saas`:** 8 errores (5 grupos `color_ambiguo` = las 13
  colisiones de AUD-02; 3 `configurable_multicolor`) y 5 advertencias
  (incluye `110DURZ-H` desalineado EAV/product_flat). Los 5 configurables
  problemáticos (`103DURZ-H`, `107DURZ-H`, `121DURZ-H`, `124DURZ-D`,
  `125DURZN-D`) quedan diagnosticados; **no se corrigió ningún dato del
  catálogo**: es trabajo de catálogo (D-6 de AUD-02) previo a habilitar F4.
- **Tests:** 42 nuevos. Suite completa de CorteConfeccion: **296 passed /
  1289 assertions**. `vendor/bin/pint --dirty` verde.
- **`packages/Webkul/`: 0 modificaciones.**
- **A fecha del cierre de F2:** F3 y F4 no iniciadas (F3 quedó completada
  después, ver entrada superior; F4 sigue sin iniciar).

## ORDEN_CORTE_V2 — F1: Color estructurado — COMPLETADA

`color_option_id` estructurado en referencias de proveedor y rollos (nullable
solo por compatibilidad histórica; los datos nuevos exigen una opción real del
atributo Bagisto `code = color`). Sub-fases F1-A a F1-E documentadas en
`docs/ORDEN_CORTE_V2_AVANCE_IMPLEMENTACION.md`. Cierre: 254 passed / 1079
assertions. *(Esta entrada faltaba en STATUS.md; se alineó al cerrar F2.)*

## ORDEN_CORTE_V2 — F0.1: Disponibilidad agregada por product_id — COMPLETADA (2026-09-22)

**Corrige el hallazgo que F0 dejó documentado como fuera de alcance:**
`DisponibilidadConfeccionService::porOrdenCorte()` devolvía **una fila por
cada `RecepcionCorteDetalle`**, no una fila por `product_id`. Cuando el
mismo producto aparecía en más de una fila de una misma recepción (ej. una
fila "solicitud" y una fila "adicional" del mismo producto — el caso que ya
cubre el CASO 2/9 de F0), dos consumidores distintos subcontaban lo
realmente disponible, cada uno de forma diferente:

- `disponiblePara()` (usado por `OrdenConfeccionService::create()` para
  validar una distribución a un solo taller) hacía `->first()` → solo veía
  la **primera** fila.
- `OrdenConfeccionService::distribuir()` (distribución a varios talleres en
  una sola operación) hacía `->keyBy('product_id')` sobre el resultado →
  Laravel conserva la **última** entrada al colisionar claves, así que solo
  veía la fila reportada más recientemente.

Ninguno de los dos sumaba ambas filas. Consecuencia real: una distribución
por una cantidad que **sí** estaba disponible en conjunto (pero superaba
cualquiera de las dos filas individuales) se rechazaba con
`cantidad-excede-disponible`, bloqueando una operación legítima de negocio.

**Corrección:** `porOrdenCorte()` ahora agrupa las filas de
`RecepcionCorteDetalle` por `product_id` **antes** de calcular disponible,
sumando `cantidad_recibida`/`cantidad_defectuosa` de todas las filas de ese
producto. Nunca devuelve más de una fila por `product_id`. `disponiblePara()`
y `distribuir()` no necesitaron cambios propios: al recibir ya un resultado
agregado, su lógica existente queda correcta. `resumenPorOrdenCorte()`
(totales agregados de la orden) ya sumaba sobre todas las filas sin
distinción, así que no tenía este bug y no cambió de comportamiento.

**Tests nuevos:** 2, en `tests/Feature/DistribucionConfeccionTest.php`
(agregación en `porOrdenCorte()`/`disponiblePara()`; y `distribuir()`
aceptando una cantidad mayor a cualquier fila individual cuando el total
agregado la cubre). Suite completa de CorteConfeccion Feature:
**223 tests, 0 fallos** (221 de F0 + 2 nuevos).

**`packages/Webkul/`: 0 modificaciones.**

---

## ORDEN_CORTE_V2 — F0: Recepción por identidad de OrdenCorteDetalle — COMPLETADA (2026-09-22)

**Alcance de esta fase, tal como se aprobó:** corregir el bug documentado en
`docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md §12.2` — la recepción de
Bodega emparejaba "reportado por el cortador" vs "recibido" por **posición
de array** (índice del POST, orden de una consulta SQL ordenada por `id`,
orden de la colección Eloquent), en vez de por identidad real de la fila.
Bajo ciertas condiciones (agrupación visual que reordena filas, o el mismo
`product_id` repetido entre una fila solicitada y una adicional) esto podía
comparar la cantidad recibida de una fila contra el reportado de **otra**.

**Cambio de contrato:** cada fila de recepción ahora identifica
explícitamente su `OrdenCorteDetalle` vía `orden_corte_detalle_id`
(`detalles[$i][orden_corte_detalle_id]`). Producto, SKU, nombre y talla se
derivan **siempre** del lado servidor a partir de ese detalle — nunca se
leen ni se confían del payload del cliente. `StoreRecepcionCorteRequest`
valida que cada `orden_corte_detalle_id` exista, pertenezca exactamente a
la orden que se está recibiendo y no se repita dentro del mismo envío;
`RecepcionCorteService::create()` repite esa misma validación de forma
independiente (defensa en profundidad, porque el Service también se llama
directamente desde tests sin pasar por el FormRequest).

**Migración:** `bylopez_cc_recepciones_corte_detalles.orden_corte_detalle_id`
(nullable, FK a `bylopez_cc_ordenes_corte_detalles`, `nullOnDelete()`).
Nullable a propósito: las recepciones creadas antes de esta fase no tienen
forma confiable de reconstruir esa relación — no se reescribe histórico. La
vista de la orden (`card-recepcion.blade.php`) conserva el emparejamiento
anterior por `product_id`+`talla` **solo** como fallback para esas filas
históricas; toda recepción nueva desde F0 llena la columna siempre. Ver el
razonamiento completo de la política de borrado (`nullOnDelete` y no
`cascadeOnDelete`/`restrictOnDelete`) en el comentario de la propia
migración.

**Pantalla independiente retirada (no eliminada):** `RecepcionCorteController::create()`
(GET `recepciones-corte/create/{ordenCorteId}`) ahora redirige a la vista
de la orden en vez de renderizar `recepciones-corte/create.blade.php` — esa
vista dejaba elegir **cualquier** producto del catálogo por fila, sin
validar que perteneciera a la orden, exactamente el hueco que esta fase
cierra. Ya estaba retirada del menú desde antes (Fase 11, ver comentario en
`src/Config/menu.php`); no había ningún link ni test que dependiera de
renderizarla. El archivo Blade se conserva sin uso (comentado como
retirado) en vez de borrarse, siguiendo la instrucción de esta fase de no
eliminar a ciegas.

**Tests:** 10 casos nuevos (CASO 1 a CASO 10 del encargo, más CASO 3b/4b de
defensa del Service y un test del redirect) en
`tests/Feature/OrdenCorteResultadoYRecepcionTest.php`. Se actualizaron los
fixtures de recepción de `DistribucionConfeccionTest.php`,
`DistribuirBaseFrontendTest.php`, `InventarioMovimientoVistaTest.php`,
`OrdenCorteVistaEstadosTest.php`, `RolesYTalleresTest.php`,
`SEC01PermisosDistribucionTest.php`, `TallerVistaDetalleTest.php` y
`AccionesCriticasPermisosTest.php` al nuevo contrato (llamaban a
`RecepcionCorteService::create()` directamente con el payload viejo).
Suite completa de CorteConfeccion (Unit + Feature): **230 tests, 0
fallos**.

**Hallazgo adicional detectado en F0 (fuera de su alcance en ese momento):**
`DisponibilidadConfeccionService::disponiblePara()` tomaba la **primera**
fila de `RecepcionCorteDetalle` que coincidiera por `product_id` — si el
mismo producto aparecía en dos filas de una misma recepción (solicitud +
adicional del mismo producto, el mismo caso que motiva el CASO 2/9 de esta
fase), `OrdenConfeccionService::distribuir()` podía subcontar la cantidad
disponible para distribuir a talleres. **Corregido en F0.1 (ver arriba,
entrada previa a esta).**

**Pendiente explícito (histórico, a fecha de F0):** F1 — Color estructurado
(`color_option_id` en `bylopez_cc_proveedor_tela_referencias`/`bylopez_cc_rollos`)
— **NO INICIADA**. Orden de Corte V2 sigue sin cerrarse; solo F0 quedó
completada en esta fase. *(Actualización: F1 y F2 ya están COMPLETADAS, ver
entradas superiores.)*

---

## FASE_1_Y_2_FLUJO_ENTREGAS_CONFECCION — COMPLETADAS (2026-09-20)

**FASE 1 — Exponer correctamente el flujo de entregas de confección.**
El backend (`OrdenConfeccionService::reportarTaller()`/`recibirBodega()`,
`OrdenConfeccionGate`, el DataGrid compartido, `show.blade.php`) ya estaba
completo desde `RET-02`; el problema real era de exposición/navegación,
no de lógica: `src/Config/menu.php` no tenía ninguna entrada para "Mis
órdenes" del rol TALLER CONFECCION. Cambios de esta fase:

- **ACL nueva: `corte_confeccion.mis_ordenes`** (`src/Config/acl.php` +
  `src/Config/menu.php`). Es una clave de **visibilidad de menú**,
  independiente de `corte_confeccion.ordenes_confeccion.reportar_taller`
  (que sigue gatillando la acción real). **Importante para despliegue:**
  ningún rol existente la tiene marcada automáticamente — es un nodo ACL
  nuevo, igual que cualquier otro que agregue un paquete. Un administrador
  debe entrar a Configuración > Roles y marcar **ambas** casillas
  (`Mis órdenes` + `Reportar taller`) para cada rol TALLER CONFECCION que
  deba ver y usar la bandeja. No se hizo ninguna asignación automática o
  silenciosa a roles de producción — es una decisión explícita del
  administrador, a propósito.
- `OrdenConfeccionDataGrid`: columna adicional `retornos_pendientes`
  (subquery aditiva, sin tocar las columnas existentes) para el badge
  "N entregas pendientes" en el listado de Bodega/Admin.
- `show.blade.php`: terminología "Recibida" → "Buenas / aprobadas" donde
  representa lo que entra a inventario (solo texto, el campo interno
  sigue siendo `cantidad_recibida`); autocálculo JS de "Faltantes"
  (`reportada - buenas - defectuosas`, de solo lectura).
- `OrdenConfeccionGate::puedeReportarTaller()`: se agregó un bloqueo duro
  para el rol `bodega` (antes solo Taller/Cortador estaban bloqueados de
  forma dura); antes de este cambio, Bodega podía reportar una entrega en
  nombre de un taller si su ACL tenía marcada por error la clave
  `reportar_taller` — ahora nunca puede, sin importar el ACL.

**FASE 2 — Endurecimiento, casos límite y cierre del flujo.** Auditoría
confirmó que el Service/Gate ya cubrían correctamente: entrega parcial y
total, distinción entre "taller terminó" (`TerminadaTaller`) y "bodega
recibió todo" (`Cerrada`/`RecibidaBodega`), que defectuosas/faltantes
nunca dejan pendiente de confección residual (el pendiente del taller se
calcula solo con `cantidad_reportada`), idempotencia de recepción
(guardada en dos capas: estado del retorno + movimiento de inventario),
y el lock de fila (`lockForUpdate()`) que serializa reportes/recepciones
concurrentes sobre la misma orden. No se encontró ningún defecto que
justificara tocar esa lógica. Cambio de comportamiento (no de lógica de
negocio): el formulario de recepción de Bodega ahora bloquea el submit en
el navegador si buenas+defectuosas supera lo reportado (el backend seguía
— y sigue — siendo la autoridad real de esa validación). Ver
`tests/Feature/DistribucionConfeccionTest.php` (RET-10 a RET-24,
incluido un test end-to-end "TEST FINAL INTEGRAL" y un test de
concurrencia real con dos conexiones MySQL) para el detalle completo.

`packages/Webkul/` sin cambios en ninguna de las dos fases.

---

## Pista paralela — 04A_ORDEN_CORTE_MODELO_FUNCIONAL (NO confundir con la 04A de esta pista)

**04A_ORDEN_CORTE_MODELO_FUNCIONAL — EN DISEÑO / DOCUMENTADA** (2026-09-07).
Pista de trabajo **separada** de la numeración de fases de Distribución
de este archivo — coincide en el número "04A" con
`04A_STOCK_VARIANTE_DISENO` (ya aprobada, más abajo) por pura
coincidencia de nombres entre dos pistas distintas; no tienen relación
entre sí. Documento completo:
`packages/ByLopez/CorteConfeccion/docs/04A_ORDEN_CORTE_MODELO_FUNCIONAL.md`.

Fase exclusivamente de auditoría y diseño de dominio sobre el flujo de
Orden de Corte (crear/editar/entregar/reportar/recepción/cierre). **No
se implementó ningún cambio funcional, migración ni test.**
`packages/Webkul/` sin cambios.

Resumen de lo documentado: eliminar conceptualmente (para una futura
04B, no implementado aún) la restricción actual que impide mezclar en
una misma orden rollos de distinto tipo de tela/color/gramaje
(`OrdenCorteService::validarCompatibilidadRollos()` +
`compatibleWithSelection()` en el frontend); agrupar los rollos por
compatibilidad física (`tipo_tela_id`+`referencia_tela_id`+`gramaje`, o
su respaldo para históricos sin referencia) **sin incluir `valor_kilo`**
en la clave (a diferencia de la agrupación financiera de Compras de
Tela); se auditaron todas las apariciones de `cantidad_proyectada`
(activa hoy en creación/validación/vistas y en ~10 archivos de test); se
detectó un riesgo real no trivial para 04B: los agregados de
peso sobrante/merma/piezas/rendimiento son hoy un solo valor por
**toda la orden**, no por grupo, y el reparto de sobrante al cerrar es
secuencial entre rollos sin criterio físico — quedaría mal si la orden
se vuelve mixta sin resolver esto primero. Quedan 4 decisiones abiertas
sin resolver, listadas al final del documento, antes de poder iniciar
04B.

**No iniciar 04B** hasta que el usuario confirme las decisiones abiertas
del documento.

---

## RET-02_RETORNOS_HISTORICOS_TALLER_BODEGA

**COMPLETADA / PENDIENTE DE VALIDACION FUNCIONAL EN NAVEGADOR** (2026-09-13).
Esta fase corrige y reemplaza el enfoque de `RET-01`: el retorno de
confeccion ya no se modela como una sola cantidad editable en la orden o
en el detalle, sino como entregas historicas parciales. El ejemplo base
queda soportado: Bodega entrega 40 unidades al Taller, el Taller puede
reportar 10 hoy, 20 despues y 10 al final, y Bodega recibe fisicamente
cada entrega con cantidades recibidas, defectuosas y faltantes.

Alcance implementado:

- Nuevas tablas historicas:
  `bylopez_cc_ordenes_confeccion_retornos` y
  `bylopez_cc_ordenes_confeccion_retorno_detalles`.
- Nuevos modelos, contracts y repositories:
  `OrdenConfeccionRetorno` y `OrdenConfeccionRetornoDetalle`.
- Cada retorno conserva codigo, numero, orden, taller, fecha/usuario de
  reporte del Taller, fecha/usuario de recepcion de Bodega, estado y
  observaciones generales.
- Cada detalle de retorno conserva snapshot de producto/SKU/nombre/talla,
  cantidad reportada por el Taller y control fisico de Bodega:
  recibidas, defectuosas, faltantes y comentario.
- Estados de OrdenConfeccion derivados por cantidades:
  `activa`, `en_confeccion`, `terminada_taller`, `recibida_bodega`,
  `cerrada`, `anulada`.
- `OrdenConfeccionService::reportarTaller()` crea un retorno historico,
  valida que el detalle pertenezca a la orden, impide superar el pendiente
  historico por variante y usa bloqueo transaccional.
- `OrdenConfeccionService::recibirBodega()` recibe un retorno pendiente,
  exige que recibidas + defectuosas + faltantes cuadren con lo reportado y
  obliga comentario cuando hay defectuosas o faltantes.
- Nueva bandeja scoped `mis-ordenes` para rol `TALLER CONFECCION`, usando
  el permiso `corte_confeccion.ordenes_confeccion.reportar_taller`.
- DataGrid de Ordenes de Confeccion suma reportado/recibido desde retornos,
  no desde columnas simples.
- Vista de Orden de Confeccion reconstruida:
  resumen de avance, barra de progreso, cards por variante ordenadas por
  prioridad, accion "Entregar todo", seleccion masiva, revision previa al
  guardado, historial de entregas y formularios de recepcion por retorno.
- Migracion correctiva elimina las columnas legadas del enfoque simple:
  cantidades/observaciones directas en `ordenes_confeccion_detalles` y
  fechas/usuarios directos en `ordenes_confeccion`.

Fuera de alcance, sin cambios:

- No hay entrada automatica a inventario Bagisto.
- No se toca `packages/Webkul/`.
- No se toca `OrdenCorteEstadoMachine`.
- No se altera el motor de distribucion multi-taller.

Validacion ejecutada en esta fase:

```
php artisan migrate
php artisan migrate --env=testing
vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/DistribucionConfeccionTest.php --filter=RET
Tests: 5 passed (29 assertions)

vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/DistribucionConfeccionTest.php
Tests: 25 passed (108 assertions)

vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/SEC01PermisosDistribucionTest.php
Tests: 10 passed (33 assertions)

vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"
Tests: 133 passed (433 assertions)

vendor/bin/pint --test packages/ByLopez/CorteConfeccion/src/Http/Controllers/Admin/OrdenConfeccionController.php packages/ByLopez/CorteConfeccion/src/Services/OrdenConfeccionService.php packages/ByLopez/CorteConfeccion/src/DataGrids/OrdenConfeccionDataGrid.php packages/ByLopez/CorteConfeccion/tests/Feature/DistribucionConfeccionTest.php packages/ByLopez/CorteConfeccion/tests/Feature/SEC01PermisosDistribucionTest.php
Passed

npm run build
Done
```

## QA FUNCIONAL INTEGRAL (validación horizontal del módulo completo)

**COMPLETADA / PENDIENTE DE REVISIÓN** (2026-09-08). Validación funcional
de todo el paquete `packages/ByLopez/CorteConfeccion/` (Proveedores,
Compras de Tela, Rollos, Orden de Corte completa, Recepción Bodega,
Disponibilidad, Distribución UI end-to-end, Talleres, OrdenConfección,
ACL/roles, integridad de BD, vistas, logs). **No modifica el estado
APROBADA de 03A-03C3** — es una fase de QA horizontal separada, no una
reapertura de las fases de distribución. Reporte completo, matriz de
roles y matriz de hallazgos con severidad en
`docs/QA_VALIDACION_FUNCIONAL_MODULO.md`. `packages/Webkul/` sin
cambios; ningún archivo de producción del paquete fue modificado —
solo se leyó código, se ejecutaron flujos reales contra fixtures
temporales (eliminados al terminar) y se documentaron hallazgos, sin
corregir ninguno. Hallazgo más relevante: el rol real `TALLER
CONFECCION` (preexistente, no creado por esta QA) tiene actualmente el
permiso que le permite distribuir mercancía a otros Talleres — ver
`QA_VALIDACION_FUNCIONAL_MODULO.md` §20 (H-CRÍTICO-1) para el detalle,
causa probable y propuesta de corrección, **pendiente de autorización
antes de corregir**.

---

## SEC-01_PERMISOS_OPERATIVOS (corrección de los hallazgos de permisos de la QA integral)

**COMPLETADA / PENDIENTE DE VALIDACIÓN** (2026-09-08). Corrige
H-CRÍTICO-1, H-ALTO-2 y (parcialmente) H-MEDIO-3 de
`QA_VALIDACION_FUNCIONAL_MODULO.md`. **No inicia ninguna funcionalidad
nueva** (no Portal Taller, no estados nuevos, no retorno a Bodega, no
pagos, no KPIs). No modifica el estado APROBADA de 03A-03C3.
`packages/Webkul/` sin cambios.

### Cómo se administran los permisos en este proyecto

Los roles (`Webkul\User\Models\Role`, tabla `roles`, columna JSON
`permissions`) son **datos de runtime**, administrados normalmente desde
el propio panel de Bagisto Admin (Configuración → Usuarios → Roles). No
existe ningún `RoleSeeder` ni mecanismo de seed/migración para esto en
el proyecto — se confirmó explícitamente antes de tocar nada. Por eso
esta corrección se aplicó como un **cambio de datos controlado** (vía
`Role::save()`, equivalente a editar el rol desde el Admin), nunca como
una migration nueva. El snapshot completo (antes/después) queda
documentado abajo para poder revertir o para replicar exactamente el
mismo cambio en staging/producción desde el propio panel de roles.

### Matriz objetivo aprobada

| Rol | Acceso |
|---|---|
| **ADMIN** (`permission_type=all`) | Administrativo completo (sin cambios) |
| **BODEGA** | Todo lo que ya tenía + `corte_confeccion.ordenes_confeccion` (index/show) + `corte_confeccion.ordenes_confeccion.create` (create/contexto/distribuir). **No** se agregó `...ordenes_confeccion.operar` (anular) — no estaba explícitamente aprobado para Bodega en este encargo |
| **CORTADOR** | Sin cambios (no existe un rol persistente con este nombre en la base de datos real; solo se usa como fixture temporal en tests) — mantiene únicamente sus operaciones de Corte, sin distribución, sin Recepción Bodega, sin administración de Talleres |
| **TALLER CONFECCION** | Se le retiraron `corte_confeccion.recepciones_corte`, `corte_confeccion.talleres`, `corte_confeccion.talleres.create`, `corte_confeccion.ordenes_confeccion`, `corte_confeccion.ordenes_confeccion.create`, `corte_confeccion.ordenes_confeccion.operar`. Queda únicamente `corte_confeccion` (permiso de menú superior) hasta que exista un "Mis órdenes" con scoping real por Taller |

### Snapshot textual (antes → después) — para revertir o replicar en staging/producción

```json
// BODEGA (id 4, permission_type=custom) -- ANTES
["corte_confeccion","corte_confeccion.compras_tela","corte_confeccion.compras_tela.create",
 "corte_confeccion.compras_tela.edit","corte_confeccion.proveedores_tela",
 "corte_confeccion.proveedores_tela.create","corte_confeccion.proveedores_tela.edit",
 "corte_confeccion.rollos","corte_confeccion.rollos.create","corte_confeccion.rollos.edit",
 "corte_confeccion.ordenes_corte","corte_confeccion.ordenes_corte.create",
 "corte_confeccion.ordenes_corte.operar","corte_confeccion.recepciones_corte",
 "catalog","catalog.products","catalog.products.create","catalog.products.copy",
 "catalog.products.edit","catalog.products.delete","catalog.categories",
 "catalog.categories.create","catalog.categories.edit","catalog.categories.delete",
 "catalog.attributes","catalog.attributes.create","catalog.attributes.edit",
 "catalog.attributes.delete","catalog.families","catalog.families.create",
 "catalog.families.edit","catalog.families.delete"]

// BODEGA -- DESPUES (se agregaron exactamente estas 2 al final, nada mas se toco)
[... todo lo anterior ...,
 "corte_confeccion.ordenes_confeccion",
 "corte_confeccion.ordenes_confeccion.create"]

// TALLER CONFECCION (id 52, permission_type=custom) -- ANTES
["corte_confeccion","corte_confeccion.recepciones_corte","corte_confeccion.talleres",
 "corte_confeccion.talleres.create","corte_confeccion.ordenes_confeccion",
 "corte_confeccion.ordenes_confeccion.create","corte_confeccion.ordenes_confeccion.operar"]

// TALLER CONFECCION -- DESPUES
["corte_confeccion"]
```

**Para replicar en staging/producción**: desde Bagisto Admin →
Configuración → Roles, editar el rol `BODEGA` y marcar los permisos de
"Órdenes de Confección" (ver/consultar y crear/distribuir, NO anular);
editar el rol `TALLER CONFECCION` y desmarcar completamente "Recepción
de Corte", "Talleres" y "Órdenes de Confección" (dejar solo el permiso
de módulo "Corte y Confección" visible). `Administrador` y `CORTADOR`
no se tocaron.

### Defensa en profundidad (nueva)

`src/Support/DistribucionConfeccionGate.php` (clase nueva, mismo patrón
que `CantidadCortadaGate` ya existente en el paquete): lista de bloqueo
explícita por **nombre de rol** (`taller confeccion`, `cortador`) que
nunca puede distribuir, sin importar `permission_type` ni la ACL
configurada. Fuera de esa lista, respeta el mecanismo estándar de
Bagisto (`bouncer()->hasPermission()`, que ya contempla
`permission_type='all'`) — así no se rompe ningún test/fixture existente
que use `permission_type='all'` con nombres de rol arbitrarios (patrón
ya establecido en toda la suite). Aplicada en 3 puntos:
`OrdenConfeccionController::create()`, `OrdenConfeccionController::contexto()`
(ambos con `abort_if(..., 403)`), y `DistribuirConfeccionRequest::authorize()`.

`CantidadCortadaGate` (recepción/solicitud) **no se modificó** — fuera
del alcance de este encargo; su comportamiento con `permission_type='all'`
queda como riesgo documentado (H-MEDIO-3, ahora "MITIGADO parcial" ya
que la distribución específicamente sí quedó blindada).

### Validación real ejecutada

- **BODEGA (rol real, admin temporal adjunto)**: login real, `GET create`/`GET contexto`/`POST distribuir` los 3 permitidos; escenario completo en navegador real (disponible 100, 60→Taller 1, 20→Taller 2, 20 pendiente) generó exactamente **2 `OrdenConfeccion`** reales, confirmadas en BD.
- **TALLER CONFECCION (rol real, admin temporal adjunto)**: `GET create`, `GET contexto`, `GET index` de OrdenConfección, `GET recepciones_corte`, `GET talleres` → los 5 devuelven **401** real; `POST distribuir` bloqueado (419 CSRF en el navegador real sin token; 401 confirmado con el cliente de test que sí controla el token).
- **`permission_type='all'` + rol "TALLER CONFECCION"/"CORTADOR"** (fixture controlado): `DistribucionConfeccionGate::puedeDistribuir()` devuelve `false`; `create`/`contexto`/`distribuir` los 3 devuelven **403** — la ACL habría dejado pasar (bypass total), pero el Gate lo bloqueó igual. Fixture eliminado al terminar.
- **Recepción Corte con ACL mal configurada (simulada)**: rol `TALLER CONFECCION` con `corte_confeccion.recepciones_corte` concedido a propósito (simulando el estado anterior) → `POST recepciones-corte/create/{id}` devuelve **403** (`CantidadCortadaGate::userCanConfirmarRecepcion()` sigue bloqueando la mutación real).

### Tests (10 nuevos, `tests/Feature/SEC01PermisosDistribucionTest.php`)

```
vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"
Tests:    128 passed (404 assertions)   [118 anteriores + 10 nuevos, ninguno eliminado]

vendor/bin/pint --test packages/ByLopez/CorteConfeccion
{"tool":"pint","result":"passed"}
```

1. Admin puede distribuir.
2. Bodega (permisos objetivo) puede `create`/`contexto`/`distribuir`, genera 2 órdenes reales (también sirve de regresión de que 03C3 sigue intacto).
3-5. Taller Confección (permisos objetivo) no puede `create`/`contexto`/`distribuir` (401, capa ACL).
6. Taller Confección con ACL de recepción mal configurada (simulada) sigue sin poder confirmar recepción (403, gate de negocio).
7. Taller Confección con `permission_type=all` sigue sin poder distribuir (403, `DistribucionConfeccionGate`).
8. Cortador sigue sin poder distribuir.
9. Bodega puede consultar el índice y el show de órdenes de confección ya generadas.
10. El contrato JSON de 03C3 (éxito 200 y error 422) sigue intacto para Bodega.

Ningún test existente fue eliminado ni modificado.

### Archivos modificados/creados

- `src/Support/DistribucionConfeccionGate.php` — nuevo.
- `src/Http/Controllers/Admin/OrdenConfeccionController.php` — `abort_if` en `create()`/`contexto()`.
- `src/Http/Requests/DistribuirConfeccionRequest.php` — `authorize()` delega en el Gate.
- `tests/Feature/SEC01PermisosDistribucionTest.php` — nuevo, 10 tests.
- Datos: permisos de los roles reales `BODEGA` (id 4) y `TALLER CONFECCION` (id 52) — ver snapshot arriba.

### Fixtures restaurados

Todos los admins/talleres/productos/proveedores/compras/roles
temporales creados para esta validación (`SEC01*`, `sec01.test`) se
eliminaron al terminar. Los admins temporales que se adjuntaron a los
roles **reales** `BODEGA`/`TALLER CONFECCION` (solo para poder hacer
login real) también se eliminaron — los roles en sí permanecen con su
nueva configuración de permisos, que es el cambio permanente e
intencional de esta fase. `.env` restaurado (mismo ajuste temporal de
Debugbar que en fases anteriores, revertido al finalizar). Servidor de
prueba detenido.

### Riesgos pendientes

- H-MEDIO-3 sigue parcialmente abierto para `CantidadCortadaGate`
  (recepción/solicitud) — un rol Cortador/Bodega real configurado con
  `permission_type='all'` seguiría saltándose esas dos restricciones de
  negocio específicas. No corregido en esta fase (fuera del alcance
  explícito del encargo, que se limitó a permisos operativos de
  distribución).
- La opción estructural mencionada en H-CRÍTICO-1/H-ALTO-2 (dividir las
  claves ACL planas de `ordenes_confeccion`/`recepciones_corte` en
  permisos más finos) no se implementó — la corrección aplicada
  (permisos + Gate de código) ya resuelve el riesgo real sin necesidad
  de tocar `acl.php`/rutas.
- No se construyó "Mis órdenes" ni scoping por Taller — deliberadamente
  fuera de alcance, según lo indicado.

**No se inició ninguna fase posterior (Portal Taller u otra).**

---

## Fase actual (pista Distribución y Confección)
03C3_REVISION_SUBMIT_RESPONSIVE — **APROBADA** (diseñada, implementada y
validada con navegador real 2026-09-08; validación final acotada que
cerró los 2 gaps pendientes, 2026-09-08). Ver reporte completo en
`03C3_REVISION_SUBMIT_RESPONSIVE.md` §24-§25 y el resumen en "PUNTO DE
REANUDACIÓN" al final de este documento. **No se inició ninguna fase
posterior.**

## Estado (03C2, fase previa ya cerrada)
**APROBADA** (implementada 2026-09-05, ajuste de densidad 2026-09-05,
breakpoint bajado a `md`/768px 2026-09-06, confirmación final de
navegador real en 900/768px 2026-09-08). El hallazgo de densidad
original quedó **resuelto** con el layout compacto desktop/laptop
(opción B). Una validación acotada (2026-09-06) encontró que el
breakpoint `lg` (1024px) dejaba un hueco UX real en 768-991px (tarjetas
muy altas, ~6.700px) y lo bajó a `md` (768px); en su momento no pudo
confirmarse con navegador real por un bloqueo de memoria del sistema, y
esa confirmación puntual (900px/768px, cero overflow, interacción
completa probada sobre órdenes reales) ya se ejecutó con éxito — ver
`03C2_INTERACCION_DISTRIBUCION.md` §26. Ningún Controller/Service/
Request/ruta/migración fue tocado. `packages/Webkul/` sin cambios.

### Ajustes finales de aprobación, implementados tal cual
1. **`tallerTocadoManualmente`** (en vez del genérico `tocadaManualmente`
   del diseño): inicia en `false`; pasa a `true` **únicamente** cuando el
   usuario cambia el `<select>` de Taller de una fila simple. Cambiar
   cantidad o prioridad NO la activa. "Taller predeterminado" solo
   rellena filas `modo==='simple' && tallerTocadoManualmente===false`.
   "Limpiar asignación" y "Quitar división" resetean siempre a
   `entregar=0, prioridad=normal, taller_id=tallerPredeterminado actual
   (o null), tallerTocadoManualmente=false`.
2. **Checkbox de filas divididas deshabilitado desde el inicio** (no solo
   excluido de acciones masivas después del hecho), con badge
   "Dividida" visible. "Seleccionar todas" solo marca
   `modo==='simple' && disponible_confeccion>0`. Consecuencia esperada:
   nunca aparece un aviso de "N divididas ignoradas" como flujo normal.
3. **Confirmación condicional**: "Asignar Taller", "Cambiar Prioridad" y
   "Asignar disponible" se aplican de inmediato sin diálogo (reversible,
   estado local). "Limpiar asignación" solo pide confirmación si alguna
   fila afectada tiene `entregar > 0`; si todas ya están en 0, limpia sin
   preguntar. "Quitar división" en una fila individual **siempre**
   confirma si `repartos.length > 0` (irrelevante si hay cantidades
   distintas de 0, porque quitar una división con repartos vacíos ya se
   resuelve sin diálogo en el propio método).

También implementado y validado: `totalDistribuirAhora` en el header
(derivado exclusivamente de `filaEntregarActual()`, nunca almacenado
aparte); fechas por Taller (`fechaPredeterminada` + mapa disperso
`configuracionTalleres`); `observacionGeneral` único; `talleresUtilizados`
(solo talleres con cantidad > 0 realmente asignada); constructor del
payload canónico basado en un `Map` indexado por `taller_id` que fusiona
por construcción los detalles de filas simples y de `repartos[]` de
filas divididas en un único bloque por taller — **verificado con
evidencia real** que un taller usado simultáneamente por una fila simple
y por un reparto de otra fila dividida produce un solo bloque con ambos
`product_id`, nunca dos bloques duplicados.

### Riesgo UX de densidad — **RESUELTO (opción B, 2026-09-05), breakpoint ajustado (2026-09-06, ver más abajo)**
Se ejecutó la prueba de densidad obligatoria con un escenario real de
**30 variantes** (fixture temporal, ya eliminado tras la prueba), en
navegador headless real, login real, en 1280px / 1024px / 500px / 390px.

El usuario eligió la **opción B** (layout compacto CSS Grid en
desktop/laptop, tarjetas sin cambios en móvil) más un ajuste dirigido a
la barra de acciones masivas. Detalle completo, con evidencia real
antes/después, en `03C2_INTERACCION_DISTRIBUCION.md` §24. Resumen:

- **Breakpoint:** `lg` de Tailwind (1024px) — elegido tras medir el
  ancho de contenido real disponible bajo el sidebar de Bagisto (978px a
  1280px, solo 722px a 1024px).
- **Altura de página con 30 variantes:** 1280px pasó de 6.711px a
  1.861px (**~72,3% de reducción**); 1024px pasó de 6.760px a 1.910px
  (**~71,7%**). Móvil (500/390px) se mantuvo exactamente igual
  (~12.500px), por decisión explícita de no tocar las tarjetas ahí.
  (Aclaración: el "~12.700px" citado originalmente como el problema era
  la medición a 390px, no la de escritorio — la altura real en desktop
  antes del ajuste era ~6.700px, no ~12.700px.)
- **Barra de acciones masivas:** el primer intento con `sticky` no
  funcionó — un `<div>` del layout core de Bagisto (no tocado) tiene
  `overflow: hidden auto` dos niveles arriba de `.bylopez-scope`, lo que
  rompe `position: sticky` quando el scroll real ocurre en la ventana.
  Se resolvió usando `position: fixed` sin offsets (conserva su posición
  de layout, pero no se mueve con el scroll) — verificado con
  `getBoundingClientRect()` que `top/left/width` quedan idénticos antes y
  después de 900px de scroll. Solo la barra es fija; el header de
  totales sigue en flujo normal (no se hizo sticky "múltiples
  cabeceras").
- **Cero overflow horizontal** en los 4 anchos, antes y después.
- **Cero cambios de lógica**: `modo`, `repartos[]`, `repartosDraft`,
  `tallerTocadoManualmente`, `configuracionTalleres`, fechas,
  observación, payload `Map` por taller, acciones masivas, stock y
  prioridades — todo intacto. Validado de nuevo con navegador real tras
  el ajuste: dividir una variante a mitad de lista (16 de 30), badge
  "Dividida", checkbox deshabilitado, "2 talleres · 15", "Mixta" — todo
  correcto en el layout compacto.

### Validación acotada 2026-09-06 — breakpoint bajado a `md` (768px), confirmado 2026-09-08

Se verificó con navegador real (matriz de 7 anchos: 1280/1024/992/900/
768/500/390, 30 variantes) que el breakpoint `lg` (1024px) dejaba un
**hueco UX real**: entre 768 y 991px la pantalla seguía mostrando
tarjetas y medía ~6.700-6.750px (casi el mismo problema que se resolvió
para 1280/1024px), con solo ~4 filas visibles por pantalla en vez de
~17. Capturas reales confirmaron además que el sidebar de Bagisto ya
colapsa a modo ícono en ese rango, dejando **más** ancho de contenido
disponible que a 1024px con sidebar expandido (donde el layout compacto
ya cabía sin problema).

Con esa evidencia se bajó el breakpoint a **`md` (768px)** (5 clases
Tailwind cambiadas, cero lógica tocada). **No fue posible confirmar con
navegador real que el layout compacto efectivamente renderiza bien en
768-1023px tras el cambio**: el sistema se quedó sin memoria suficiente
para ejecutar Chrome (segfault confirmado con el binario directo, sin
relación con la app ni con el script de prueba), y no se recuperó tras
~19 minutos de espera. El usuario decidió explícitamente conservar el
cambio con esta evidencia indirecta, **sin aprobar la fase todavía**.
Detalle completo en `03C2_INTERACCION_DISTRIBUCION.md` §25.

**Confirmación puntual (2026-09-08):** repetida la validación con
navegador real en 900px y 768px sobre dos órdenes reales existentes
(`OC-WIL-000004`, `OC-WIL-000005`) — layout compacto en ambos anchos,
cero overflow horizontal, cantidad/Taller/Prioridad/Dividir/barra masiva
funcionando correctamente, 0 errores de consola. **03C2 queda APROBADA**
— ver `03C2_INTERACCION_DISTRIBUCION.md` §26.

Quedan aprobados explícitamente (heredado de 03C1, sin cambios):
integración de `VarianteConsultaService` en `create()`; metadata/stock
preparados en el Controller; componente Vue base; Taller predeterminado
local (no persiste); fecha predeterminada local; "Entregar ahora" inicia
siempre en 0; prioridad normal/alta/urgente; `stock_actual = null`
distinto de `0`; stock proyectado únicamente informativo (no
persistido); el responsive actual (tarjetas, sin `<table>`); los estilos
propios del módulo (`partials/estilos` + build de CorteConfeccion); el
`<select>` nativo de Taller aceptado para esta etapa (en vez de
`record-search` asíncrono).

### Riesgo UX de densidad — ya ejecutado y resuelto en 03C2 (ver sección de arriba)

La prueba de densidad con 20-40 variantes, pendiente desde 03C1, **ya se
ejecutó y resolvió en 03C2** con 30 variantes reales (opción B, layout
compacto desktop/laptop — ver sección "Riesgo UX de densidad" arriba y
`03C2_INTERACCION_DISTRIBUCION.md` §24).

Resumen de lo construido: `OrdenConfeccionController::create()` ahora
arma `$filas` fusionando `DisponibilidadConfeccionService` +
`VarianteConsultaService` (04B) y `$talleres` (activos); nueva
`create.blade.php` con componente Vue `v-distribuir-confeccion` (estado
por fila, taller predeterminado, fecha predeterminada, stock
actual/proyectado, prioridad), sin `<table>` — tarjetas responsive que
colapsan de 4 a 2 columnas en `<768px`. Botón "Revisar distribución"
presente pero deshabilitado (03C2/03C3 lo activan). 5 tests backend
nuevos + **validación visual real en navegador headless** (login real,
0 errores de consola, sin overflow horizontal verificado en 1280px y
500px) contra la orden real `OC-WIL-000004`. Suite completa del paquete:
100 tests pasando, sin regresiones. Se crearon también los documentos de
alcance `03C2_INTERACCION_DISTRIBUCION.md` y
`03C3_REVISION_SUBMIT_RESPONSIVE.md` (sin diseño exhaustivo, solo
objetivo/alcance/fuera de alcance/dependencias).

## Fase anterior — 04B_STOCK_VARIANTE_IMPLEMENTACION
**APROBADA** (implementada 2026-09-04, aprobada 2026-09-04, sin cambios
de arquitectura respecto a lo implementado). `packages/Webkul/` sin
cambios. Detalle completo: `04B_STOCK_VARIANTE_IMPLEMENTACION.md`.

**Decisiones congeladas de 04B:**
- Firma: `VarianteConsultaService::porProductIds(iterable $productIds, ?int $channelId = null): Collection`.
- Contrato por `product_id`: `sku, nombre, color, talla, talla_etiqueta, stock_actual, tipo_producto, activo`.
- Consultas batch, número de queries aproximadamente constante (5, verificado).
- Cero consultas a tablas de Webkul fuera del Service.
- Stock vendible desde `product_inventory_indices` (nunca físico).
- `stock_actual = null` es distinto de `stock_actual = 0`.
- Color/talla estructurados, sin parsear nombres; soporte dinámico `size`/`grupo_de_edad_ninos`.
- `Default Channel` como fallback; `channelId` explícito soportado.
- **Límite conocido y aceptado:** máximo un eje configurador no-color por variante — no se amplía sin evidencia real de necesidad.

Resumen de lo construido: `src/Services/VarianteConsultaService.php`
(`porProductIds(iterable $productIds, ?int $channelId = null): Collection`),
que resuelve en **5 consultas SQL fijas** (verificado con datos reales
para N=3 y N=25 — idéntico) SKU, nombre (del configurable padre), color,
talla (resuelta dinámicamente por `attribute_code`, soporta tanto
`size`/Talla como `grupo_de_edad_ninos`), `talla_etiqueta`, stock vendible
(`product_inventory_indices.qty`, nunca físico), `tipo_producto` y
`activo`. Todas las consultas a tablas de Webkul quedan encapsuladas
dentro de este único Service. 15 tests nuevos (los 13 casos obligatorios
+ 2 de base), suite completa del paquete: 95 tests pasando, sin
regresiones.

## Fase anterior — 04A_STOCK_VARIANTE_DISENO
**APROBADA** (diseñada 2026-09-04, aprobada 2026-09-04 con 2 ajustes
finales: `channelId` opcional en el contrato, y aislamiento total de las
consultas a Webkul dentro del Service — ver
`04A_STOCK_VARIANTE_DISENO.md` §0).

Hallazgos clave de la investigación (evidencia real, sin cambios):
- Color y talla **sí están estructurados** (`product_attribute_values` →
  `attribute_options`), nunca hay que parsear `producto_nombre`.
- **Hallazgo real:** no todo el catálogo usa el mismo "segundo eje" de
  variación — 73 configurables usan `size`/Talla, pero 4 (línea infantil)
  usan `grupo_de_edad_ninos` en su lugar. El servicio implementado lo
  resuelve dinámicamente (`talla_etiqueta`), nunca con un `attribute_id` fijo.
- No existe un "nombre base" limpio sin color en ningún campo
  estructurado; la alternativa usada es el nombre del `configurable`
  padre (sin talla, pero con color incluido).
- Stock vendible correcto = `product_inventory_indices.qty` (ya descuenta
  reservas), no `product_inventories.qty` (físico).
- `core()->getCurrentChannel()` depende del Host HTTP de la request —
  riesgo real para un contexto Admin; el servicio usa
  `core()->getDefaultChannel()` cuando no se pasa `channelId` explícito.
- Servicio: `VarianteConsultaService` (no `StockVarianteReadService` — su
  alcance es más que solo stock), conviviendo con `ProductLookupService`
  (responsabilidades distintas, sin tocarlo).

## Fase anterior — 03B_FRONTEND_DISTRIBUCION
**APROBADA** (diseñada 2026-09-04, aprobada 2026-09-04). Fase
exclusivamente de diseño UX/documentación: no se creó ni modificó ningún
Blade/Vue/JS/CSS/Service/Controller/Request/ruta/migration. Documento
completo con las decisiones finales: `03B_FRONTEND_DISTRIBUCION.md` §0.

Decisiones aprobadas de la Fase 03B:
1. Un único componente Vue montado sobre `create.blade.php`.
2. Dos estados locales (EDITAR → REVISAR) dentro de la misma pantalla,
   sin navegación a página independiente.
3. `x-admin::drawer` para "Dividir entre talleres".
4. Barra contextual **local** para acciones masivas (no el mecanismo
   remoto de mass-action del DataGrid, porque no hay persistencia hasta
   confirmar).
5. Botón final: "Generar N órdenes de confección" (N dinámico).
6. Responsive de tres niveles (escritorio → laptop → tarjetas),
   reutilizando el patrón tabla→cards ya existente en
   `recepciones-corte/create.blade.php`.
7. Corregir en la futura 03C la ausencia de
   `@include('corteconfeccion::admin.partials.estilos')` — confirmado
   que es el patrón ya establecido del módulo.
8. Aclaración reforzada: "Stock bajo" y "Prioridad: Urgente" son
   conceptos distintos, con tratamientos visuales distintos. Reutilizar
   la clase CSS `label-canceled` para el badge de prioridad "Urgente" es
   solo reutilización de estilo/color, no un acoplamiento conceptual con
   "cancelado"/"anulado".

## Cambio de orden de fases (decisión del usuario, 2026-09-04)
Antes de construir **03C** (implementación del frontend ya diseñado en
03B), se resuelven primero:
1. **04A_STOCK_VARIANTE_DISENO** (documento de alcance creado, esta
   entrada de STATUS).
2. **04B_STOCK_VARIANTE_IMPLEMENTACION** (no iniciada).

Motivo: evitar que 03C implemente placeholders de stock/atributos de
variante que luego haya que rehacer — 03C debe consumir desde el
principio el contrato real que produzca 04B.

**03C** se dividió en tres subfases para no ejecutar una fase demasiado
grande: **03C1_BASE_FRONTEND** (completada, ver arriba),
**03C2_INTERACCION_DISTRIBUCION** (documento de alcance creado, no
iniciada) y **03C3_REVISION_SUBMIT_RESPONSIVE** (documento de alcance
creado, no iniciada).

## Color/Talla/Variante — resuelto e implementado
Confirmado con evidencia real y ya implementado en
`VarianteConsultaService`: color y talla sí están estructurados vía
atributos de Bagisto (`product_attribute_values` → `attribute_options`);
nunca se parsea `producto_nombre`. El "segundo eje" de variación no
siempre es Talla (una línea infantil usa `grupo_de_edad_ninos`) — el
servicio lo resuelve dinámicamente vía `talla_etiqueta`.

## Fases anteriores
- 01_AUDITORIA — Completada (con evidencia en `01_AUDITORIA.md`, fecha 2026-09-04).
- 02_MODELO_DISTRIBUCION — Aprobada (fecha 2026-09-04).
- 03A_MOTOR_DISTRIBUCION — Aprobada (implementada y aprobada 2026-09-04).
- 03B_FRONTEND_DISTRIBUCION — Aprobada (fecha 2026-09-04).
- 04A_STOCK_VARIANTE_DISENO — Aprobada (fecha 2026-09-04, con 2 ajustes finales).
- 04B_STOCK_VARIANTE_IMPLEMENTACION — Aprobada (implementada y aprobada 2026-09-04).
- 03C1_BASE_FRONTEND — Aprobada (implementada 2026-09-04, aprobada 2026-09-05).
- 03C2_INTERACCION_DISTRIBUCION — **Aprobada** (implementada 2026-09-05; ajuste de densidad 2026-09-05; breakpoint bajado a md/768px 2026-09-06; confirmación final de navegador 2026-09-08, ver arriba).
- 03C3_REVISION_SUBMIT_RESPONSIVE — **Aprobada** (diseñada e implementada 2026-09-08; QA real en navegador con evidencia en BD; validación final acotada 2026-09-08 que cerró los 2 gaps pendientes, ver `03C3_REVISION_SUBMIT_RESPONSIVE.md` §24-§25).

## Decisiones finales aprobadas de la Fase 02
1. **Arquitectura:** sin tabla `DistribucionConfeccion`. Se conserva
   `OrdenCorte hasMany OrdenConfeccion belongsTo Taller` +
   `OrdenConfeccion hasMany OrdenConfeccionDetalle`. Una operación de
   distribución genera N `OrdenConfeccion` (una por Taller) en una sola
   transacción.
2. **Identidad de variante:** clave operativa = `product_id` (verificado
   con datos reales que corresponde a la variante `simple` exacta de
   Bagisto). `talla`, `variant_id`, `producto_sku`, `producto_nombre` se
   conservan como campos legacy/informativos, sin gobernar el cálculo de
   cantidades.
3. **Prioridad:** vive en `OrdenConfeccionDetalle.prioridad`, valores
   `normal|alta|urgente`. Columna física `string`/`VARCHAR` con
   `DEFAULT 'normal'` — **sin** `ENUM` nativo de MySQL — más un PHP
   Backed Enum para el dominio/validación.
4. **Concurrencia:** una sola `DB::transaction()` para toda la
   distribución + `lockForUpdate()` sobre la `RecepcionCorte`
   correspondiente, adquirido antes de recalcular disponibilidad.
   Cualquier fallo implica `ROLLBACK` completo.
5. **Anulación (regla temporal):** anular una `OrdenConfeccion` devuelve
   sus cantidades al pool disponible, mientras no exista flujo de
   retorno físico del taller.
6. **Taller predeterminado:** comportamiento de interfaz únicamente, no
   se persiste en base de datos.
7. **Stock:** solo lectura desde Bagisto, sin copiar a tablas ByLopez.
   Aprobado conceptualmente; su diseño detallado es exactamente el
   objeto de 04A/04B.

Documento completo con evidencia y matriz de decisiones:
`02_MODELO_DISTRIBUCION.md`.

## Problemas resueltos en la Fase 03A
- **Bug de integridad corregido:** anular una Orden de Confección ahora
  libera la cantidad hacia "disponible". Cubierto por test (Caso 5).
- Motor de distribución multi-taller implementado:
  `OrdenConfeccionService::distribuir()`, transaccional, con `lockForUpdate()`
  y rollback total ante cualquier fallo. Cubierto por tests (Casos 1, 2,
  3, 4).
- Prioridad por variante implementada. Cubierto por test (Caso 8).
- Validación de `product_id` simple implementada (rechaza productos
  `configurable`). Cubierto por test (Caso 7).
- `taller_id` único por operación de distribución (sin fusión, se
  rechaza el duplicado). Ver `03A_MOTOR_DISTRIBUCION.md` §7.11.
- `validarProductosSimples()` aplicada tanto en `create()` como en
  `distribuir()` — decisión confirmada.

## Problemas/riesgos abiertos
- La pantalla de distribución todavía no permite completar ninguna
  distribución real de punta a punta — 03C1 construyó la base
  visual/de datos, 03C2 ya implementó (validado con navegador real)
  dividir entre talleres, acciones masivas, Taller predeterminado con
  `tallerTocadoManualmente`, fechas por Taller y el payload canónico en
  memoria; resumen, submit real al backend y manejo de errores quedan
  para 03C3.
- El hallazgo de densidad de 03C2 quedó **resuelto** (opción B
  implementada) — ya no es un riesgo abierto, ver sección "Riesgo UX de
  densidad" arriba. Quedan como decisiones de implementación sin
  revisión campo por campo: el breakpoint elegido (1024px, `lg` de
  Tailwind) y los textos abreviados del layout compacto ("Dividir"/
  "Editar"/"Quitar"/"Mixta") — ver `03C2_INTERACCION_DISTRIBUCION.md`
  §24.7.
- **HOTFIX Orden de Corte cerrada — NO REPRODUCIBLE / PENDIENTE DE
  EVIDENCIA** (2026-09-05): ver sección dedicada más abajo. Sin cambios
  de producción; solo tests de regresión agregados.
- No existen estados de producción posteriores a la entrega ("en
  confección", "terminada", "recibida en bodega") ni campos de retorno
  del taller. Fuera de alcance.
- No existe ningún campo, tabla o servicio de pagos a talleres. Fuera de
  alcance.
- El Caso 6 (concurrencia, Fase 03A) solo tiene cobertura de su parte
  funcional; la parte de bloqueo real bajo concurrencia sigue pendiente
  de un test de integración futuro fuera de Pest — ver
  `03A_MOTOR_DISTRIBUCION.md` §7.8.
- No existe un "nombre base" limpio sin color en ningún campo
  estructurado de Bagisto para este catálogo — la alternativa usada
  (nombre del `configurable` padre) sigue incluyendo el color.
  Documentado como limitación aceptada, no como pendiente.
- "Stock bajo" no se implementó en 03C1 por ausencia de una regla de
  negocio definida — decisión explícita, no un olvido (ver
  `03C1_BASE_FRONTEND.md` §7).
- Sin cobertura automatizada (Pest) de la interacción Vue en vivo
  (computeds, clamps) — mitigado con validación visual real en navegador,
  documentada en `03C1_BASE_FRONTEND.md` §9.2.
- `product_inventory_indices` es un valor indexado por Bagisto, no en
  vivo — ver `04B_STOCK_VARIANTE_IMPLEMENTACION.md` §11.

## Próxima fase
**03C2_INTERACCION_DISTRIBUCION** y **03C3_REVISION_SUBMIT_RESPONSIVE**
están ambas **APROBADAS** (03C3: implementación completa + QA real en
navegador + validación final acotada que cerró sus 2 gaps pendientes,
ver `03C3_REVISION_SUBMIT_RESPONSIVE.md` §24-§25). Ninguna fase
posterior iniciada.

## No implementar todavía
- pagos
- entrada automática inventario
- KPIs
- estados de producción posteriores a "activa" (en confección/terminada/recibida en bodega)

## Decisiones congeladas

Una decisión marcada como APROBADA no debe reinterpretarse ni cambiarse
en fases posteriores salvo que se detecte una incompatibilidad técnica real.

En ese caso:
1. explicar la incompatibilidad;
2. indicar qué parte del código la provoca;
3. proponer alternativas;
4. no modificar la decisión sin autorización.

Quedan congeladas bajo esta regla: las 7 decisiones de la Fase 02, las 2
decisiones adicionales de la Fase 03A (`taller_id` único por operación;
`validarProductosSimples()` también en `create()`), las 8 decisiones de
la Fase 03B, y las 6 decisiones + 2 ajustes finales de la Fase 04A
listados arriba (nombre `VarianteConsultaService`; fuente de stock
vendible; color/talla estructurados; resolución dinámica del segundo eje;
`admin_name` sin traducción; semántica `null` vs `0`; `channelId`
opcional; aislamiento total de Webkul dentro del Service) — todas a
partir de 2026-09-04. Las decisiones de 04B (firma, contrato, 5
consultas fijas, aislamiento de Webkul) quedan congeladas también, sin
cambios de arquitectura respecto a lo implementado.

Quedan congeladas, a partir de 2026-09-05, las decisiones de 03C1
listadas al inicio de este documento (integración de
`VarianteConsultaService` en `create()`, componente Vue base, Taller
predeterminado y fecha predeterminada locales, "Entregar ahora" en 0,
prioridad normal/alta/urgente, `null` vs `0` en stock, stock proyectado
solo informativo, responsive de tarjetas, estilos propios del módulo,
`<select>` nativo de Taller para esta etapa) — **excepto** el patrón de
tarjetas en escritorio/laptop, que la prueba de densidad con 20-40
variantes (ver riesgo UX arriba) sí justificó reconsiderar: a partir de
2026-09-05 queda congelado en su lugar el layout compacto CSS Grid
`>=1024px` de la §24 de `03C2_INTERACCION_DISTRIBUCION.md` (las tarjetas
siguen congeladas sin cambios para `<1024px`).

---

## HOTFIX — Error al consultar Orden de Corte cerrada (2026-09-05)

**Estado: NO REPRODUCIBLE / PENDIENTE DE EVIDENCIA.** Detenido a petición
del usuario. Sin relación con el flujo 03C2 ni con `OrdenConfeccion`.

- El ID citado en el reporte (1209) **no existe** en esta base de
  datos — corresponde a un fixture temporal de QA creado y eliminado en
  la sesión de validación de 03C2, no a una orden real.
- Se intentó reproducir el error de forma genérica (sin hardcodear el
  ID) con dos escenarios reales de una Orden de Corte cerrada: (1)
  construida recorriendo el flujo completo real (crear → entregar →
  marcar en corte → reportar → confirmar recepción → cerrar), y (2) con
  forma "dispersa/legacy" (sin `OrdenCorteDetalle` ni `rollosCorte`,
  solo con recepción) — la forma más parecida al fixture original 1209.
  **Ninguno de los dos reprodujo el error** (ambos abren con HTTP 200).
- No hay rastro en `storage/logs/laravel.log` de una excepción real
  sobre esta ruta.
- **Se agregaron y se conservan** 4 tests de regresión reales en
  `tests/Feature/OrdenCorteVistaEstadosTest.php`: orden cerrada abre con
  200, datos históricos visibles, acciones inválidas para "cerrado" no
  aparecen, y una orden en estado operativo (`recibido_bodega`) sigue
  abriendo correctamente. Los 4 pasan.
- **Sin cambios de código de producción** — no hay causa raíz
  confirmada que corregir. `OrdenCorteController`, `OrdenCorte`,
  `show.blade.php` y sus partials quedaron exactamente como estaban.
- **Pendiente solo si aparece evidencia reproducible**: el texto/captura
  exacta del error, el stack trace de Laravel, o un ID real y
  actualmente existente donde el error se repita en este entorno.

---

## PUNTO DE REANUDACIÓN

**Última fase ejecutada:** 03C3_REVISION_SUBMIT_RESPONSIVE (implementación
real + QA en navegador + validación final acotada) — **APROBADA
(2026-09-08)**.

**Últimas fases aprobadas:**
- 03A_MOTOR_DISTRIBUCION
- 03B_FRONTEND_DISTRIBUCION
- 04A_STOCK_VARIANTE_DISENO
- 04B_STOCK_VARIANTE_IMPLEMENTACION
- 03C1_BASE_FRONTEND
- 03C2_INTERACCION_DISTRIBUCION (aprobada 2026-09-08, ver `03C2_INTERACCION_DISTRIBUCION.md` §26)
- 03C3_REVISION_SUBMIT_RESPONSIVE (aprobada 2026-09-08, ver `03C3_REVISION_SUBMIT_RESPONSIVE.md` §24-§25)

**Ya implementado y validado con navegador real en 03C2 (sin cambios,
nada de esto se puso en duda):**
- selección de variantes (excluye divididas y disponible=0 desde el
  inicio, checkbox deshabilitado);
- las 4 acciones masivas con confirmación condicional (AJUSTE 3);
- `tallerTocadoManualmente` (AJUSTE 1);
- dividir una variante entre varios Talleres mediante drawer, con
  badge "Dividida" y prioridad mixta;
- quitar división, con confirmación cuando corresponde;
- fechas por Taller (predeterminada + overrides dispersos);
- payload canónico en memoria (Map por `taller_id`, fusión verificada
  entre fila simple y repartos de fila dividida sobre el mismo taller);
- layout compacto + barra de acciones masivas `fixed` en 1280/1024px
  (30 variantes, drawer a mitad de lista, 0 errores de consola, 0
  overflow horizontal, ~72% de reducción de altura);
- layout compacto en 900px/768px confirmado con navegador real sobre
  órdenes reales existentes (2026-09-08): cero overflow, cantidad/
  Taller/Prioridad/Dividir/barra masiva funcionando, 0 errores de
  consola. **03C2 no tiene ningún punto pendiente.**

**Fase actual: ninguna iniciada.** 03C3_REVISION_SUBMIT_RESPONSIVE —
**APROBADA** (2026-09-08). Implementada, validada con navegador real +
evidencia en BD, y con los 2 gaps de la validación final (fecha
override, network error/HTTP 500) cerrados sin encontrar defectos. Ver
`03C3_REVISION_SUBMIT_RESPONSIVE.md` §24-§25 para el reporte completo:
patrón de estado `vista: 'editar' | 'revisar' | 'exito'`, endpoint
`contexto()` nuevo, submit JSON vía `wantsJson()` en `distribuir()`
(único cambio de backend, aditivo), reconciliación real tras 422 sin
parseo de texto ni autoajuste, un bug real encontrado y corregido
(`talleresState` — no se puede reasignar un prop directamente en Vue 3
dentro de un `<script type="module">`, que corre en modo estricto), y
confirmación de que no existe retry automático ni parsing de texto para
determinar la causa de un error. 118 tests backend pasando (10 nuevos),
Pint limpio. **No se inició ninguna fase posterior.**

**No implementar todavía:**
- estados de producción;
- retorno de Taller;
- pagos;
- KPIs;
- entrada automática a inventario;
- HOTFIX de OrdenCorte cerrada — detenido como NO REPRODUCIBLE, solo si
  aparece evidencia reproducible (ver sección dedicada arriba);
- cualquier fase posterior a 03C3, hasta su aprobación explícita.

**Regla de reanudación:**
Leer `STATUS.md` primero.
No volver a auditar fases ya aprobadas.
No reabrir decisiones congeladas salvo incompatibilidad técnica real.
## RET-01_RETORNO_TALLER_RECEPCION_BODEGA

**SUPERADA POR RET-02_RETORNOS_HISTORICOS_TALLER_BODEGA** (2026-09-13).
Este enfoque queda conservado como antecedente, pero no es la fuente de
verdad vigente. El diseno actual usa retornos historicos parciales en
tablas propias y elimina las columnas simples descritas abajo mediante
una migracion correctiva.

**Antecedente completado / ya no vigente** (2026-09-13).
Implementa la primera fase operativa posterior a la distribucion: el
Taller puede reportar cuantas prendas salieron confeccionadas y Bodega
puede confirmar cuantas prendas regresaron fisicamente. Resuelve el caso
funcional pedido: si Bodega entrego 40 unidades al Taller y el Taller
reporta/entrega menos de 40, el sistema conserva la cantidad original,
la cantidad reportada, la cantidad recibida en Bodega y obliga a dejar
observacion en los detalles con faltante.

Alcance implementado:

- Nuevas columnas aditivas en `bylopez_cc_ordenes_confeccion`:
  `fecha_reporte_taller`, `fecha_recepcion_bodega`, `reportado_por`,
  `recibido_por`.
- Nuevas columnas aditivas en `bylopez_cc_ordenes_confeccion_detalles`:
  `cantidad_reportada_taller`, `cantidad_recibida_bodega`,
  `observacion_taller`, `observacion_bodega`.
- Estados nuevos de produccion en `EstadoOrdenConfeccion`:
  `reportada_taller` y `recibida_bodega`; se conserva `activa`/`anulada`.
- `OrdenConfeccionService::reportarTaller()`:
  `activa -> reportada_taller`, valida todos los detalles, impide reportar
  mas de lo entregado y exige observacion cuando el Taller reporta menos.
- `OrdenConfeccionService::recibirBodega()`:
  `reportada_taller -> recibida_bodega`, valida todos los detalles, impide
  recibir mas de lo reportado y exige observacion cuando Bodega recibe menos
  de lo entregado originalmente al Taller.
- Nuevos Requests: `ReportarOrdenConfeccionRequest` y
  `RecibirOrdenConfeccionRequest`.
- Nuevo `OrdenConfeccionGate`: separa ver/reporte de Taller/recepcion de
  Bodega y evita que un rol llamado `TALLER CONFECCION` con
  `permission_type=all` vea o reciba ordenes ajenas.
- Vista detalle de Orden de Confeccion ampliada con comparativo:
  entregadas / reportadas / recibidas / faltante final, mas formularios
  contextuales para reportar Taller y recibir Bodega.
- DataGrid de Ordenes de Confeccion ampliado con totales reportados y
  recibidos.
- Nuevas rutas POST:
  `admin.corte_confeccion.ordenes_confeccion.reportar_taller` y
  `admin.corte_confeccion.ordenes_confeccion.recibir_bodega`.
- Nuevas ACL configurables:
  `corte_confeccion.ordenes_confeccion.reportar_taller` y
  `corte_confeccion.ordenes_confeccion.recibir_bodega`.

Fuera de alcance, sin cambios:

- Entrada automatica a inventario Bagisto.
- Pagos a Taller.
- KPIs/reportes financieros.
- Portal independiente "Mis ordenes" para Taller; la operacion queda
  disponible desde la vista de Orden de Confeccion y depende de configurar
  los nuevos permisos en roles custom.

Validacion ejecutada:

```
php artisan migrate
vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/DistribucionConfeccionTest.php --filter=RET
Tests: 4 passed (11 assertions)

vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/DistribucionConfeccionTest.php
Tests: 24 passed (90 assertions)

vendor/bin/pest --testsuite="CorteConfeccion Unit Test","CorteConfeccion Feature Test"
Tests: 132 passed (415 assertions)
```

Pendiente recomendado:

- Validar en navegador real la pantalla de detalle de Orden de Confeccion
  con roles reales `BODEGA` y `TALLER CONFECCION` tras asignar los nuevos
  permisos en el panel de Roles.
- Definir una fase separada para ingreso a inventario, porque convertir
  `recibida_bodega` en stock vendible afecta `Webkul\Inventory` y no debe
  hacerse automaticamente sin regla contable/operativa aprobada.

---
## HOTFIX_DISPONIBILIDAD_DISTRIBUCION_CORTE

**COMPLETADA / PENDIENTE DE VALIDACION DE TESTS** (2026-09-15).
Regla nueva congelada: una OrdenCorte solo ofrece la accion
"Distribuir a talleres" mientras exista al menos una cantidad recibida
por Bodega aun no distribuida a OrdenesConfeccion activas. La
disponibilidad se determina por cantidades reales, no unicamente por el
estado textual de la OrdenCorte.

Fuente de verdad reutilizada: `DisponibilidadConfeccionService`.

Calculo operativo:

```text
total_apto_confeccion = SUM(cantidad_recibida - cantidad_defectuosa)
total_distribuido     = SUM(OrdenConfeccionDetalle.cantidad_entregada)
                        de ordenes no anuladas
total_pendiente       = SUM(max(total_apto_por_variante - distribuido_por_variante, 0))
puede_distribuir      = OrdenCorte no anulada && total_pendiente > 0
```

Impacto implementado:

- `ordenes-corte/show` ya no muestra el boton de distribucion cuando
  `total_pendiente = 0`; muestra "Distribucion completada" y el resumen
  apto/distribuido/pendiente.
- La pantalla directa `ordenes-confeccion/create/{ordenCorteId}` redirige
  al detalle de OrdenCorte si no hay pendiente real.
- El endpoint `contexto/{ordenCorteId}` responde 422 si no hay pendiente.
- `OrdenConfeccionService::distribuir()` y el flujo legado
  `OrdenConfeccionService::create()` rechazan cualquier generacion cuando
  no hay pendiente real.
- El flujo multi-taller conserva su proteccion de concurrencia con
  `lockForUpdate()` sobre `RecepcionCorte`; el flujo legado ahora toma el
  mismo lock antes de recalcular disponibilidad.
- No se modifica RET-02, `OrdenCorteEstadoMachine`, stock, costos, pagos
  ni `packages/Webkul/`.

Matriz vigente:

| Situacion | Pendiente | Mostrar Distribuir |
| --- | ---: | --- |
| Corte todavia no recibido por Bodega | N/A | NO |
| Recibido y nada distribuido | >0 | SI |
| Distribucion parcial | >0 | SI |
| Todo distribuido | 0 | NO |
| Orden anulada | N/A | NO |
| Orden sin cantidades validas | 0 | NO |

---
