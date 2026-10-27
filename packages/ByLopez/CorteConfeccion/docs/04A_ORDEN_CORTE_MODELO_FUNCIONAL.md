# 04A_ORDEN_CORTE_MODELO_FUNCIONAL.md — Modelo funcional de Orden de Corte

## 0. Estado de este documento

**EN DISEÑO / DOCUMENTADA.** Fecha: 2026-09-07. Fase exclusivamente de
auditoría y diseño de dominio. **Ningún archivo de código, migración ni
test fue modificado para producir este documento.** `packages/Webkul/`
sin cambios. No se implementó ningún cambio funcional.

Esta fase es una pista **separada** de la numeración de fases de
`docs/confeccion/STATUS.md` (Distribución y Confección, donde ya existe
un "04A_STOCK_VARIANTE_DISENO" aprobado y sin relación con este
documento). Para evitar confusión, esta fase se identifica siempre como
**04A_ORDEN_CORTE_MODELO_FUNCIONAL**, nunca solo "04A".

---

## 1. Estado actual (resumen ejecutivo)

Hoy, una Orden de Corte:

- Se crea seleccionando **N rollos** y **N productos a cortar**
  (`cantidad_proyectada` por producto/talla).
- **Exige homogeneidad física real**: el frontend y el backend rechazan
  agregar un rollo si no coincide en `tipo_tela_id` + `color` + `gramaje`
  con el primer rollo ya seleccionado (`referencia` distinta solo genera
  una advertencia, no bloquea).
- Guarda un único `color` y un único `genero_linea` (resumen) a nivel de
  **toda la orden**, no por grupo.
- Registra el peso, sobrante, merma, piezas cortadas y rendimiento
  (`peso_promedio_pieza`) como **agregados únicos de toda la orden**, sin
  desglose por tipo de tela/color/gramaje.
- Cada rollo individual sí mantiene su propio peso inicial/sobrante/
  consumido (`OrdenCorteRollo`), y cada producto a cortar es un registro
  independiente (`OrdenCorteDetalle`) — la trazabilidad individual de
  rollo y de producto **ya existe y no se toca**.
- `cantidad_proyectada` sigue activa en todo el flujo de creación,
  validación, visualización y en la mayoría de los tests (ver §4).

## 2. Arquitectura encontrada

### 2.1 Tablas y modelos involucrados

| Modelo | Tabla | Relaciones | Campos de agregado a nivel de ORDEN (no por grupo) |
|---|---|---|---|
| `OrdenCorte` | `bylopez_cc_ordenes_corte` | `rollo()` BT (rollo "principal", legado), `rollosCorte()` HM → `OrdenCorteRollo`, `detalles()` HM → `OrdenCorteDetalle`, `recepcion()` HasOne, `ordenesConfeccion()` HM | `color`, `genero_linea`, `peso_total_rollos`, `peso_sobrante_total`, `merma_total`, `peso_consumido_total`, `total_piezas_cortadas`, `peso_promedio_pieza` |
| `OrdenCorteRollo` | `bylopez_cc_orden_corte_rollos` | `ordenCorte()` BT, `rollo()` BT | — (ya es por-rollo: `peso_inicial_usado`, `peso_sobrante`, `peso_consumido`) |
| `OrdenCorteDetalle` | `bylopez_cc_ordenes_corte_detalles` | `ordenCorte()` BT, `product()` BT | — (ya es por-producto/talla: `cantidad_proyectada`, `cantidad_real_cortada`, `es_adicional`) |
| `Rollo` | `bylopez_cc_rollos` | `compraTela()` BT, `referenciaTela()` BT, `tipoTela()` BT | `tipo_tela_id`, `color`, `referencia`, `gramaje`, `valor_kilo` (por rollo, ya estructurado) |
| `RecepcionCorte` | `bylopez_cc_recepciones_corte` | `ordenCorte()` BT (única, hasOne), `detalles()` HM | pesos de sobrante/retasos/desperdicio — también agregados **únicos por orden**, no por grupo |
| `RecepcionCorteDetalle` | `bylopez_cc_recepciones_corte_detalles` | `recepcionCorte()` BT, `product()` BT | — (por producto/talla) |

**Hallazgo clave**: `OrdenCorteRollo` (por-rollo) y `OrdenCorteDetalle`
(por-producto) **ya son granulares** — la trazabilidad individual que
pide la Fase 04A como principio (§4 del pedido) ya existe a ese nivel.
Lo que **no existe** es una capa intermedia que agrupe varios
`OrdenCorteRollo` en un "grupo de tela física" dentro de la misma orden;
hoy la orden entera se trata como si fuera un solo grupo.

### 2.2 Flujo actual completo

1. **Crear** (`OrdenCorteController::create/store` → `OrdenCorteService::create()`):
   selecciona rollos (validación de compatibilidad física, ver §2.3),
   selecciona productos a cortar con `cantidad_proyectada`, elige
   cortador. Estado inicial: `pendiente_entrega`.
2. **Entregar** (`entregar()`): el peso a entregar se calcula
   automáticamente (`pesoReservadoOrden()` = suma de
   `OrdenCorteRollo.peso_inicial_usado`). Estado → `entregado_a_cortador`.
3. **Marcar en corte** (`cambiarEstado()`, transición simple): →
   `en_corte`.
4. **Reportar resultado** (`reportarResultado()`): el cortador escribe
   `cantidad_real_cortada` por cada `OrdenCorteDetalle` ya existente
   (nunca cambia producto/talla/`cantidad_proyectada` de esas filas) y
   puede agregar detalles **adicionales** no solicitados
   (`es_adicional=true`, `cantidad_proyectada=0`). Estado →
   `reportado_por_cortador`.
5. **Confirmar recepción** (`RecepcionCorteService::create()`): Bodega/
   Admin confirma `cantidad_recibida`/`cantidad_defectuosa` por
   producto/talla (comparado contra `cantidad_real_cortada`, nunca contra
   `cantidad_proyectada`). Actualiza los agregados **de toda la orden**
   (`peso_sobrante_total`, `merma_total`, `peso_consumido_total`,
   `total_piezas_cortadas`, `peso_promedio_pieza`). Estado →
   `recibido_bodega`.
6. **Cerrar** (`cerrar()`): recalcula los mismos agregados de toda la
   orden a partir de `cantidad_real_cortada` (o de la recepción si el
   cortador no reportó nada), descuenta `peso_sobrante_total` +
   `merma_total` del peso base y distribuye el sobrante entre los
   `OrdenCorteRollo` en **orden secuencial de creación**
   (`actualizarRollosAlCerrar()` — primer rollo agotado hasta cubrir el
   sobrante, luego el siguiente). Estado → `cerrado`.
7. **Anular** (`cancel()`): libera los rollos reservados. Disponible
   desde cualquier estado no terminal.

### 2.3 Dónde vive hoy la restricción de homogeneidad (a eliminar)

- **Backend, bloqueante**: `OrdenCorteService::validarCompatibilidadRollos()`
  (`src/Services/OrdenCorteService.php:351-364`) — compara TODOS los
  rollos seleccionados contra el primero por `tipo_tela_id` + `color` +
  `gramaje`; si alguno difiere, lanza
  `ValidationException` con la clave `rollos-incompatibles`.
- **Frontend, bloqueante**: `compatibleWithSelection()` en
  `create.blade.php:443-453` — misma comparación, en el navegador, antes
  de permitir agregar el rollo a la tabla (mensaje: *"No se pueden
  mezclar rollos con diferente tipo de tela, color o gramaje."*).
- **Frontend, solo advertencia (no bloquea)**: `create.blade.php:421-429`
  — si la `referencia` difiere del primer rollo, solo muestra un aviso
  amarillo, ya permite continuar. Es decir, **la referencia ya no separa
  grupos hoy en el frontend**, pero el backend no lo replica: el backend
  compara únicamente `tipo_tela_id`+`color`+`gramaje` (no `referencia`),
  así que un rollo con distinta referencia pero mismo tipo/color/gramaje
  **ya pasa hoy** la validación de backend. Esto es una inconsistencia
  menor preexistente entre frontend (advierte) y backend (ni siquiera
  mira `referencia`) — documentada aquí, no corregida en esta fase.
- **Origen del dato "un solo color" a nivel de orden**:
  `OrdenCorteService::create()` línea 69: `'color' => $primerRollo->color`
  — la orden completa hereda el color del PRIMER rollo seleccionado. Esto
  es una consecuencia directa de asumir homogeneidad, no una validación
  explícita, pero es igual de limitante: aunque se permitiera mezclar
  rollos, el campo `color` de la orden seguiría siendo engañoso (solo
  mostraría el color del primer rollo).

## 3. Problemas actuales (más allá de la restricción de mezcla)

1. **Agregados de peso/piezas/rendimiento a nivel de orden, no de grupo.**
   `peso_sobrante_total`, `merma_total`, `peso_consumido_total`,
   `total_piezas_cortadas`, `peso_promedio_pieza` son columnas únicas en
   `bylopez_cc_ordenes_corte`. Si la orden se vuelve mixta, estos números
   dejan de tener sentido como "rendimiento de la orden" (mezclarían
   telas con rendimientos físicamente distintos) — es exactamente el KPI
   que pide la §7 del pedido ("Piel Durazno/Rojo: 6.61 prendas/kg" vs
   "Piel Durazno/Blanco: 7.45 prendas/kg"), y **hoy no hay dónde
   guardarlo por grupo**.
2. **Reparto de sobrante entre rollos al cerrar es secuencial, no por
   grupo.** `actualizarRollosAlCerrar()` reparte `peso_sobrante_total`
   (un solo número) entre `OrdenCorteRollo` en el orden en que fueron
   creados, agotando el primero antes de tocar el segundo. En una orden
   mixta esto asignaría sobrante de un grupo a un rollo de OTRO grupo
   físico sin ningún criterio de negocio — un problema real que 04B debe
   resolver (probablemente: `peso_sobrante` y `merma` deberían
   capturarse por grupo, no como un solo total).
3. **"Productos a cortar" no tiene ninguna relación con los rollos ni con
   un futuro grupo.** `OrdenCorteDetalle` no tiene FK a `OrdenCorteRollo`
   ni a ningún concepto de grupo — hoy es implícito: el cortador sabe por
   contexto de qué tela sale cada producto. Si se introduce el concepto
   de "grupo de tela", queda abierta la pregunta de si cada producto a
   cortar debería poder asociarse a un grupo específico (ver §9).
4. **`OrdenCorteDetalle.talla` es texto libre**, sin relación con
   atributos estructurados de Bagisto (a diferencia de
   `VarianteConsultaService`, ya construido en la fase de Distribución,
   que sí resuelve color/talla estructurados desde
   `product_attribute_values`). "Productos a cortar" usa
   `ProductLookupService::snapshot()`, que solo trae `sku`/`nombre` desde
   `products`+`product_flat`, sin color/talla/stock. No es un bloqueante
   para 04A, pero es una oportunidad de reutilización real para 04B (ver
   §14).
5. **Colisión de nombres: "grupo" ya significa algo distinto hoy.**
   `AgrupadorDetallesCorte::agrupar()` (`src/Support/AgrupadorDetallesCorte.php`)
   agrupa `OrdenCorteDetalle` por `product_id + genero_linea` para las
   cards de Solicitud/Resultado — es un concepto de **agrupación de
   producto**, no de tela física. El nuevo "grupo de tela" que pide esta
   fase es una dimensión completamente distinta. Se debe usar un término
   diferenciado en el código/UI de 04B (propuesta: "grupo de tela" o
   "lote de corte" para el nuevo concepto, dejar "grupo" a secas para el
   existente) para no confundir ambos.
6. **Inconsistencia frontend/backend ya mencionada en §2.3** (backend no
   valida `referencia`, frontend sí advierte).

## 4. Todas las apariciones de `cantidad_proyectada`

### 4.1 Backend (producción)

| Archivo | Línea(s) | Rol |
|---|---|---|
| `src/Database/Migrations/2026_05_07_000003_create_bylopez_cc_ordenes_corte_detalles_table.php` | 18 | Columna `unsignedInteger('cantidad_proyectada')` — **NOT NULL, sin default**, en la tabla desde su creación. |
| `src/Database/Migrations/2026_06_14_000001_add_multi_rollo_fields_to_bylopez_cc_ordenes_corte.php` | 35 | Referencia posicional (`->after('cantidad_proyectada')`) al agregar `cantidad_real_cortada` — no la modifica. |
| `src/Models/OrdenCorteDetalle.php` | 22, 29 | `fillable` + cast `integer`. |
| `src/Http/Requests/StoreOrdenCorteRequest.php` | 24, 54 | `prepareForValidation()` la usa para decidir si una fila de `detalles` está "vacía" (se descarta si faltan todos los campos incluida esta); regla `required\|integer\|gt:0` — **hoy es obligatoria y debe ser mayor a 0 para poder guardar una orden**. |
| `src/Services/OrdenCorteService.php` | 30 (doc), 102, 121 (doc), 170 | `create()` la persiste tal cual llega del formulario; `reportarResultado()` la fija en `0` para las filas **adicionales** (nunca la reescribe en las filas ya existentes — comentario explícito: "nunca se permite cambiar... solo la cantidad [real]"). |
| `src/Support/AgrupadorDetallesCorte.php` | 29 | `total_proyectado` = suma de `cantidad_proyectada` del grupo (producto+género), usado en las 3 cards de Solicitud/Resultado/Recepción. |
| `src/Resources/views/admin/ordenes-corte/create.blade.php` | 18, 26, 295-296, 387 | Campo de captura obligatorio (`min="1" required`) en "Productos a cortar"; estado inicial Vue; input `name="detalles[i][cantidad_proyectada]"`. |
| `src/Resources/views/admin/ordenes-corte/show.blade.php` | 7 | `$totalSolicitado = sum('cantidad_proyectada')`, mostrado en el bloque resumen "Solicitado" de la vista de detalle. |
| `src/Resources/views/admin/ordenes-corte/partials/card-resultado.blade.php` | 10, 41, 156, 312, 338 | Se copia al "copiar solicitud" antes de reportar resultado; se muestra como referencia informativa ("solicitado: N") junto al campo de cantidad real. |

### 4.2 Tests que dependen de `cantidad_proyectada`

Aparece en **10 archivos de test** (todos construyen el payload de
`OrdenCorteService::create()`/`POST store`, donde hoy es obligatoria):
`AccionesCriticasPermisosTest.php`, `DistribuirBaseFrontendTest.php`,
`DistribucionConfeccionTest.php`, `OrdenCorteResultadoYRecepcionTest.php`
(el más extenso: ~15 apariciones, incluye aserciones directas sobre el
valor persistido), `RolesYTalleresTest.php`, `TallerVistaDetalleTest.php`,
`RollosResumenYFiltroTest.php`, `OrdenCorteVistaEstadosTest.php`. Ninguno
de estos tests verifica una regla de negocio específica *sobre*
`cantidad_proyectada` en sí (más allá de "no se modifica al reportar
resultado", ver `OrdenCorteResultadoYRecepcionTest.php:134`) — todos la
usan porque **hoy es un campo obligatorio para poder crear cualquier
orden de corte de prueba**, no porque el test exista para validarla.

### 4.3 Conclusión sobre `cantidad_proyectada`

- **Nadie fuera de la propia orden depende de ella**: no se usa en
  Compras de Tela, Rollos, Distribución/Confección
  (`OrdenConfeccion`/`OrdenConfeccionDetalle` usan su propia
  `cantidad_entregada`, sin relación).
- **Si se retira del flujo de captura**, el impacto real es: (a) la
  columna de BD debe volverse nullable o recibir un default antes de
  quitar la validación `required` (la migración actual no tiene
  `->nullable()` ni `->default()`); (b) `StoreOrdenCorteRequest` pierde
  la regla `gt:0`; (c) **~10 archivos de test** dejan de poder construir
  su fixture actual sin pasar algún valor (aunque sea 0) mientras la
  columna siga NOT NULL; (d) las vistas de Solicitud/Resultado dejan de
  tener un "total proyectado" que mostrar — hay que decidir qué mostrar
  en su lugar (ver §6).
- **No hay dato histórico que dependa de ella para cálculos posteriores**
  fuera de la propia orden: `cerrar()` prioriza `cantidad_real_cortada`
  y solo cae a `RecepcionCorteDetalle.cantidad_recibida` si no hay
  reporte de cortador — `cantidad_proyectada` nunca entra en el cálculo
  de cierre/rendimiento actual.

## 5. Modelo de dominio propuesto (para decidir en 04B, no implementado)

Introducir una entidad conceptual **"Grupo de corte"** (nombre de trabajo,
a confirmar) como capa intermedia entre `OrdenCorte` y `OrdenCorteRollo`:

```
OrdenCorte (1) ──< Grupo de corte (N) ──< OrdenCorteRollo (N) ──> Rollo (1)
                         │
                         └──< (posible) OrdenCorteDetalle (N) — ver §9, abierto
```

- Un grupo se identifica por la clave física de §6, **no por un ID
  autoincremental persistido obligatoriamente** — puede derivarse en
  tiempo de lectura igual que ya se hace en Compras de Tela
  (`03C2`/mejora de Compras: los "grupos" ahí tampoco tienen tabla
  propia, se derivan de los rollos en el frontend). Ver §7 para la
  decisión de persistencia.
- Cada grupo agrega, **solo para presentación**, cantidad de rollos, peso
  disponible/usado, y (cuando exista el dato) prendas producidas y
  rendimiento — todo derivado de los `OrdenCorteRollo` que caen en ese
  grupo, nunca almacenado como fuente de verdad aparte.

## 6. Regla exacta de agrupación (evaluada contra el modelo real)

Propuesta del pedido, **validada contra los datos reales de
`Rollo`/`ProveedorTelaReferencia`** (mismo patrón ya usado y probado en
la mejora de Compras de Tela):

- **Con referencia de catálogo vinculada** (`proveedor_tela_referencia_id`
  no nulo): `tipo_tela_id + proveedor_tela_referencia_id + gramaje`. En
  la práctica, como `proveedor_tela_referencia_id` ya determina de forma
  única `tipo_tela_id`+`color`+`referencia`+`gramaje` (es una fila de
  `ProveedorTelaReferencia`), la clave puede simplificarse a
  **`proveedor_tela_referencia_id`** por sí sola — `tipo_tela_id` y
  `gramaje` son redundantes cuando la referencia existe, pero se
  mantienen en la clave por claridad y como salvaguarda si algún día
  `ProveedorTelaReferencia` permitiera editar su gramaje sin crear una
  referencia nueva (hoy no se validó si eso es posible; se deja como
  riesgo documentado en §12).
- **Histórico sin referencia vinculada** (`proveedor_tela_referencia_id`
  nulo — mismo caso ya visto en Rollos antiguos, ver
  `form.blade.php` de Compras de Tela: campo `historico`):
  `tipo_tela_id + color + referencia + gramaje` (comparando texto
  normalizado: `trim` + `strtolower`, igual que ya hace
  `validarCompatibilidadRollos()` hoy).
- **`valor_kilo` queda fuera de la clave de agrupación de Corte** —
  confirmado como correcto: dos rollos físicamente idénticos comprados a
  distinto precio deben cortarse juntos sin problema; el costo
  individual de cada rollo (`Rollo.valor_kilo`, `Rollo.costo_total`) ya
  se conserva en el registro del propio rollo (`OrdenCorteRollo` no
  duplica el costo, lo consulta vía `rollo()` — correcto, sin cambios
  necesarios) y sigue disponible para costeo posterior por rollo
  individual dentro del grupo.
- **Diferencia deliberada con Compras de Tela**: la clave de agrupación
  de Compras SÍ incluye `valor_kilo` (dos rollos al mismo
  tipo/color/gramaje pero distinto precio son grupos financieros
  distintos). Aquí, para Corte, se excluye a propósito porque la
  agrupación es productiva/física, no financiera — exactamente la
  distinción que pide el punto 3 del encargo. **No reutilizar la función
  de agrupación de Compras tal cual**; es un cálculo aparte con una clave
  distinta, aunque con la misma forma general (determinista, sin
  depender de texto formateado).

**Validación contra el modelo real**: no se encontró ningún problema —
`Rollo.proveedor_tela_referencia_id`, `tipo_tela_id`, `color`,
`referencia`, `gramaje` y `valor_kilo` ya existen exactamente como se
necesitan (mismos campos ya usados por la mejora de Compras de Tela, sin
necesidad de nueva columna en `Rollo`).

## 7. Relación Orden → Grupos → Rollos

- Los grupos **se derivan de `OrdenCorteRollo` + `Rollo` en tiempo de
  lectura** (Controller o un Service nuevo, ej. `OrdenCorteGrupoResumenService`),
  igual que ya se hace en el frontend de Compras de Tela. **No se
  propone una tabla `bylopez_cc_orden_corte_grupos`** salvo que 04B
  encuentre una necesidad real de persistir metadatos propios del grupo
  (ej. una observación específica del grupo, o el reparto de sobrante
  por grupo — ver el problema #2 de §3, que si se resuelve
  correctamente probablemente SÍ exige guardar `peso_sobrante`/`merma`
  por grupo en algún lugar, ya sea una tabla nueva o columnas nuevas en
  `OrdenCorteRollo`/una tabla puente).
- Selección de rollos en "Crear orden": el picker actual (`create.blade.php`)
  deja de bloquear por incompatibilidad; en su lugar, el rollo elegido se
  añade al grupo cuya clave coincida, o crea un grupo nuevo
  automáticamente — comportamiento idéntico al ya aprobado y validado en
  la mejora de Compras de Tela (mismo patrón de acordeón, mismo criterio
  de "agregarse a un grupo compatible o generar uno nuevo").

## 8. Tratamiento de rollos de compras diferentes

Ya soportado sin cambios: `OrdenCorteRollo` no tiene ninguna restricción
que exija que todos los rollos de una orden vengan de la misma
`CompraTela` — cada `Rollo` ya apunta a su propia `compra_tela_id`, y
`entregar.blade.php`/`create.blade.php` ya muestran "compra origen" por
rollo individualmente. Dos rollos de compras distintas con las mismas
características físicas caerían en el mismo grupo de corte sin ningún
ajuste adicional.

## 9. Productos a cortar (pregunta abierta para 04B)

Auditado el propósito actual: "Productos a cortar" es la **solicitud**
(qué se quiere obtener del corte, con `cantidad_proyectada` como meta),
independiente de qué rollo específico provee la tela — hoy es una lista
plana a nivel de toda la orden, sin FK a rollos ni a un grupo.

**Distinción confirmada entre los tres conceptos** (ya limpia en el
modelo actual, no requiere cambios de nombre):
- **Producto solicitado** = `OrdenCorteDetalle` con `es_adicional=false`
  (fila creada en `create()`).
- **Cantidad proyectada/solicitada** = `cantidad_proyectada` (la meta,
  fijada al crear, inmutable después — confirmado por el comentario y el
  comportamiento real de `reportarResultado()`).
- **Cantidad realmente cortada** = `cantidad_real_cortada` (escrita
  únicamente por el cortador en `reportarResultado()`, es el dato que
  gobierna cierre y rendimiento).

**Pregunta abierta, no decidida en esta fase**: al introducir grupos de
tela, ¿cada "producto a cortar" debería poder asociarse opcionalmente a
un grupo (para saber "estas prendas salen de la tela Roja"), o el modelo
correcto es mantenerlos desacoplados (la solicitud es agnóstica de qué
tela se usa, el cortador decide en la práctica)? Ambas opciones son
válidas funcionalmente:
- **Opción A — desacoplado (mínimo cambio)**: no tocar
  `OrdenCorteDetalle`; los KPIs de rendimiento por grupo (§10) se
  calculan solo con el peso del grupo y el total de piezas cortadas de
  TODA la orden, sin poder decir "estas piezas salieron de este grupo".
  Coincide con el pedido de "no reintroducir cantidad proyectada bajo
  otro nombre" y es el cambio más simple.
- **Opción B — `OrdenCorteDetalle.grupo_key` opcional (nullable)**:
  permite reportar cuántas piezas salieron de cada grupo, habilitando el
  KPI de "prendas/kg" **por grupo** que pide literalmente la §7 del
  encargo. Requiere una migración nueva (columna nullable) y tocar
  `reportarResultado()`.

Se recomienda **Opción B** si el KPI "prendas/kg por grupo" del punto 7
es realmente un objetivo de negocio a corto plazo (no solo un ejemplo
ilustrativo); si no, Opción A evita tocar `OrdenCorteDetalle` en 04B.
**Esta decisión debe tomarla el usuario antes de iniciar 04B**, no está
resuelta aquí.

## 10. Cantidad realmente cortada — impacto del modelo mixto

`reportarResultado()` no necesita cambios estructurales para soportar
órdenes mixtas: ya opera por `OrdenCorteDetalle` individual, ajeno al
concepto de grupo. Si se adopta la Opción B de §9, el único cambio
necesario aquí es exigir (o sugerir) `grupo_key` al reportar una fila
**adicional** nueva (las filas ya existentes conservarían el `grupo_key`
fijado al crear la orden, si alguno).

## 11. Impacto en recepción/cierre

Este es el punto de mayor riesgo real detectado (ya adelantado en el
problema #2 de §3):

- `RecepcionCorteService::create()` y `OrdenCorteService::cerrar()`
  ambos escriben **un solo valor** de `peso_sobrante_total`/`merma_total`
  para toda la orden, y `actualizarRollosAlCerrar()` reparte ese sobrante
  entre `OrdenCorteRollo` en orden de creación, sin ningún criterio
  físico.
- En una orden mixta, esto es **incorrecto por diseño** si se implementa
  tal cual hoy: el sobrante de tela Roja podría terminar asignado
  contablemente a un rollo Blanco.
- **Para 04B**: la recepción/cierre probablemente necesitan capturar
  `peso_sobrante`/`merma` **por grupo**, no un solo total para toda la
  orden — esto es un cambio de UX y de modelo de datos más profundo que
  el propio "grupos de tela" en sí, y debe evaluarse con cuidado antes
  de implementar 04B (podría justificar su propia sub-fase).
- Mientras no se resuelva, una alternativa mínima y explícita para 04B
  sería: mantener el reparto secuencial actual como limitación conocida
  y documentada (no ideal, pero no rompe nada existente), y dejar el
  reparto correcto por grupo para una fase posterior una vez que el
  usuario confirme que es prioritario.

## 12. Riesgos

1. **Reparto de sobrante/merma al cierre (§11)** — el riesgo más serio;
   no tiene una solución de "cambio mínimo" limpia, requiere decisión de
   negocio.
2. **`OrdenCorteDetalle` sin relación a grupo (§9)** — decisión abierta
   entre Opción A/B, con impacto directo en si el KPI "prendas/kg por
   grupo" del punto 7 es alcanzable o no.
3. **No se validó si `ProveedorTelaReferencia.gramaje` puede editarse
   después de tener rollos asociados** — si pudiera, la clave de
   agrupación de corte (`referencia_tela_id` solo) podría desincronizarse
   silenciosamente de `gramaje`; se recomienda mantener `gramaje` en la
   clave compuesta como salvaguarda (ya reflejado en §6) en vez de confiar
   únicamente en el ID de referencia.
4. **Inconsistencia backend/frontend preexistente en la validación de
   `referencia`** (§2.3) — de bajo impacto práctico hoy (el backend es
   más permisivo), pero conviene que 04B no la copie sin revisar cuál de
   los dos comportamientos es el correcto para el nuevo modelo (lo más
   probable: ninguno, ya que la restricción completa desaparece).
5. **Colisión de nombres "grupo"** (§3.5) — riesgo de confusión en código
   y comunicación si no se nombra explícitamente distinto.
6. **Tests existentes con fixtures acopladas a `cantidad_proyectada`
   obligatoria** (§4.2) — cualquier cambio a la validación de esa
   columna en 04B debe revisar los 10 archivos listados.

## 13. Compatibilidad con históricos

- Órdenes de corte **ya cerradas** con un único `color`/tipo de tela no
  se ven afectadas: seguirían mostrando/derivando un solo grupo
  (trivialmente, todos sus rollos comparten la misma clave de
  agrupación).
- Rollos históricos sin `proveedor_tela_referencia_id` (ya contemplados
  hoy en el propio flujo de Compras de Tela) agrupan correctamente con
  la clave de respaldo de §6, sin necesidad de backfill de datos.
- No se identificó ninguna migración de datos necesaria para habilitar
  la sola presentación agrupada (§7) sobre órdenes existentes — es
  puramente derivada de datos que ya existen.

## 14. Cambios necesarios para la futura Fase 04B (sin implementar aquí)

1. Quitar `validarCompatibilidadRollos()` (backend) y
   `compatibleWithSelection()`/el aviso de referencia (frontend) de
   `create.blade.php` — reemplazar el picker de rollos por una vista
   agrupada tipo acordeón (mismo patrón ya construido y validado en la
   mejora de Compras de Tela: grupos cerrados por defecto, resumen
   ejecutivo, expandir para ver rollos individuales con "Quitar").
2. Decidir y (si aplica) migrar la columna de `OrdenCorteDetalle` de la
   Opción B de §9 antes de tocar `reportarResultado()`.
3. Decidir el tratamiento de `peso_sobrante`/`merma` por grupo (§11)
   antes de tocar `RecepcionCorteService`/`OrdenCorteService::cerrar()`/
   `actualizarRollosAlCerrar()`.
4. Decidir el destino de `cantidad_proyectada`: si se retira de la
   captura, la migración debe volver la columna nullable o con
   `default(0)` **antes** de relajar la regla `required|gt:0` en
   `StoreOrdenCorteRequest`, y decidir qué mostrar en `show.blade.php`/
   `card-resultado.blade.php` en su lugar (¿nada, o un total derivado de
   otra cosa?).
5. Actualizar los ~10 archivos de test listados en §4.2 si cambia la
   obligatoriedad de `cantidad_proyectada`.
6. Evaluar (oportunidad, no obligación) reemplazar
   `ProductLookupService::snapshot()` por `VarianteConsultaService`
   (ya construido en la fase de Distribución) para que "Productos a
   cortar" resuelva color/talla estructurados en vez de texto libre —
   fuera del alcance mínimo de 04B, pero coherente con lo ya construido.

## 15. Archivos que habría que modificar en 04B (lista de referencia, sin tocar ahora)

- `src/Services/OrdenCorteService.php` (quitar validación de
  compatibilidad; posible ajuste de `cerrar()`/`actualizarRollosAlCerrar()`).
- `src/Services/RecepcionCorteService.php` (si se adopta reparto por
  grupo).
- `src/Http/Requests/StoreOrdenCorteRequest.php` (regla de
  `cantidad_proyectada` si se retira; nuevo campo si se adopta Opción B
  de §9).
- `src/Resources/views/admin/ordenes-corte/create.blade.php` (picker de
  rollos → vista agrupada; posible cambio en "Productos a cortar").
- `src/Resources/views/admin/ordenes-corte/show.blade.php` y
  `partials/card-resultado.blade.php`/`card-recepcion.blade.php`
  (presentación agrupada; tratamiento del "total proyectado" si
  desaparece).
- `src/Models/OrdenCorteDetalle.php` (si Opción B de §9).
- Nueva migración (columna nullable en `OrdenCorteDetalle`, y/o cambios
  de nullable/default en `cantidad_proyectada`, y/o columnas de
  sobrante/merma por grupo si se resuelve §11).
- Tests listados en §4.2 (backend) + tests nuevos de agrupación (mismo
  patrón que los CASO A-E de la mejora de Compras de Tela).

---

## Resumen de decisiones que requieren confirmación explícita antes de 04B

1. Opción A vs B de §9 (¿`OrdenCorteDetalle` se asocia a un grupo o no?).
2. Tratamiento de `peso_sobrante`/`merma` por grupo al cierre (§11) —
   posible sub-fase propia.
3. Destino final de `cantidad_proyectada`: ¿se retira de la UI pero se
   conserva en BD (nullable), o se conserva también en la UI como dato
   opcional/informativo?
4. Nombre definitivo del nuevo concepto de grupo (evitar colisión con
   "grupo" de `AgrupadorDetallesCorte`).
