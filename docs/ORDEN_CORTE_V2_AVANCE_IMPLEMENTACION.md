## Estado actual

**F5 (Adicionales del cortador por resolvedor) COMPLETADA TECNICAMENTE** (linea+color+talla, sin product_id; suite 382 passed). Seccion F5 al final. Los parrafos siguientes son historicos.

**PRE-F5 COMPLETADO (PRE-F5 + PRE-F5.1, solo datos): diagnostico 8 errores → 0.
CATALOGO ESTRUCTURAL LISTO PARA CORTE V2. 110DURZ-H pendiente solo de decision comercial
(EAV 0 vs flat 1; no bloquea). F5 NO INICIADA.** Secciones PRE-F5 / PRE-F5.1 al final.

**F4 (Creacion V2 de Orden de Corte) COMPLETADA TECNICAMENTE. CATALOGO PRODUCTIVO
PENDIENTE DE CORREGIR AMBIGUEDADES (reducido a 1 tras PRE-F5)** (los 5 configurables problematicos siguen sin
corregir; F4 los bloquea con `color_ambiguo`). **F5 NO INICIADA** (requiere
aprobacion explicita). Detalle en la seccion "F4 — Creación V2 de Orden de Corte" al
final de este archivo.

Evidencia final de F4:

- `vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/`
- Resultado: 370 passed / 1976 assertions (351 previos + 19 nuevos).
- `vendor/bin/pint --dirty` -> passed. `packages/Webkul/`: 0 modificaciones.

Evidencia de PRE-F4.1 (cierre anterior): 351 passed / 1821 assertions.

Evidencia de PRE-F4 (cierre anterior):

- Resultado: 345 passed / 1748 assertions (316 de F3 + 8 de F1-D + 21 nuevos).
- `vendor/bin/pint --dirty` -> passed.
- `packages/Webkul/`: 0 modificaciones.

Orden cronologico y guia de lectura (este archivo conserva la historia; hay dos
secciones tituladas "F1-D completada" por antecedentes, NO son la misma):

- `## F1-D completada` (arriba, tras F1-C): cierre ORIGINAL de F1-D (rollos
  manuales/color estructurado).
- `## F1-D completada` (al final, tras F3): REVALIDACION posterior de las reglas
  de color del rollo; hizo inmutable el snapshot de rollos bloqueados y dejo
  documentado el hueco de Compra de Tela que PRE-F4 cerro.
- `## PRE-F4 — Blindaje de rollos en Compra de Tela`: la fuente vigente sobre
  el bloqueo de rollos y el retiro de rollos de una orden.

F3 - Merma total de tela por Orden de Corte quedo COMPLETADA (cierre anterior).
Detalle en la seccion "F3 — Merma total de tela por Orden de Corte".

Evidencia final de F3:

- Resultado: 316 passed / 1497 assertions (296 previos + 20 nuevos).

F2 - Resolvedor de variantes + diagnostico de catalogo quedo completada
(cierre anterior; seccion "F2 — Resolvedor de variantes").

Evidencia final de F2:

- Resultado: 296 passed / 1289 assertions (254 previos + 42 nuevos).

F1 - Color estructurado quedo completada formalmente (cierre anterior).

Alcance cerrado: `color_option_id` estructurado en referencias de proveedor y
rollos, manteniendo la columna nullable solo por compatibilidad historica.
Los datos nuevos ya requieren una opcion real del atributo Bagisto
`code = color`.

Evidencia final de F1:

- `vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/`
- Resultado: 254 passed / 1079 assertions.
- `packages/Webkul/`: 0 modificaciones.

## Ya implementado

F0 si esta implementada.

- Migracion nueva: `orden_corte_detalle_id` en
  `bylopez_cc_recepciones_corte_detalles`, nullable para historicos.
- `StoreRecepcionCorteRequest` exige `detalles.*.orden_corte_detalle_id`,
  valida pertenencia a la orden y `distinct`.
- `RecepcionCorteService::create()` repite la validacion en servicio,
  deriva `product_id`/SKU/nombre/talla desde `OrdenCorteDetalle` y ya no
  empareja por posicion de array.
- La recepcion nueva no sobreescribe `cantidad_real_cortada`; compara contra
  lo reportado por identidad real.

F0.1 tambien aparece implementada; no queda pendiente segun codigo actual.

- `DisponibilidadConfeccionService::porOrdenCorte()` agrupa por `product_id`
  y suma `cantidad_recibida`/`cantidad_defectuosa` antes de calcular
  disponible.
- `disponiblePara()` consume ese resultado agregado.
- `STATUS.md` del paquete la marca como COMPLETADA el 2026-09-22.

F1/color estructurado esta completada.

- F1-A completada: referencias nuevas/editadas exigen `color_option_id` real
  del atributo Bagisto `color`; compras bloquean referencias historicas sin
  color vinculado; rollos creados por compra copian el ID desde la referencia.
- F1-B completada: UI de referencias usa selector de colores Bagisto, sin
  texto libre ni sentinel "sin vincular"; historicas NULL siguen visibles y
  deben vincularse antes de guardar.
- F1-C completada: migracion
  `2026_09_23_000001_add_color_option_id_to_referencias_y_rollos` agrega FK a
  referencias/rollos y backfill historico estricto `LOWER(TRIM(...))`, dejando
  NULL cuando hay 0 o mas de 1 coincidencia.
- F1-D completada: rollos manuales nuevos exigen color Bagisto real; rollos con
  referencia, reservados o ya usados en Orden de Corte no permiten cambiar color.
- F1-E1 completada: fixtures/helpers de tests adaptados a color estructurado
  sin debilitar la regla productiva.
- Historicos con `color_option_id = NULL` siguen existiendo y visualizandose;
  no hubo backfill forzado ni conversion automatica por texto.

F4 (creacion V2) esta implementada: rollos primero (mismo `tipo_tela_id`, multi-color y
multi-gramaje), lineas/tallas reales por `ResolvedorVarianteCorteService`, seleccion
resuelta en servidor con `resolverLote()` a simples exactos, `cantidad_proyectada = 0`,
migracion `color` en detalles, y show/cortador/Bodega adaptados. Ver seccion F4.

## Pendiente

F1 y F2 no tienen pendientes funcionales conocidos.

- **Catalogo productivo:** PRE-F5/PRE-F5.1 dejaron 0 errores estructurales. Solo queda como
  decision comercial el estado de 110DURZ-H en tienda (EAV 0 vs flat 1; no bloquea corte).
- **F5:** completada (ver seccion F5); pendientes propios al final de esa seccion.

- `STATUS.md` quedo alineado con F1 y F2 al cerrar F2.
- Antes de habilitar la creacion V2 en produccion (F4) el negocio debe corregir
  el catalogo: el diagnostico de F2 lista 5 grupos color/familia ambiguos
  (13 combinaciones familia+color+talla con mas de un simple). Es trabajo de
  catalogo en el admin de Bagisto, no de codigo.

## Proximo paso recomendado

Decidir el siguiente hito tras F5 (aprobacion explicita del usuario) y correccion del catalogo por el negocio. (Historico: F2 ya
expone lo que F4 necesita (`lineasPorTipoTela`, `opcionesEjeLote`,
`resolverLote`), F3 dejo el cierre seguro para rollos de colores mezclados y
PRE-F4 blindo los rollos vinculados a ordenes (Compra de Tela no puede
alterarlos; el retiro formal libera antes de en_corte).

## F1-A completada

Cambio aplicado: `color_option_id` sigue nullable en BD para historicos, pero
los datos nuevos ya no pueden crear referencias/rollos desde proveedor sin una
opcion real del atributo Bagisto `color`.

Reglas cerradas:

- Referencia nueva: exige `color_option_id` valido del atributo `code = color`.
- Referencia existente: al guardarse debe quedar vinculada; el snapshot `color`
  se deriva de la opcion Bagisto.
- Referencias historicas con `color_option_id = NULL` siguen visibles si no se
  tocan.
- Compra de tela: bloquea crear rollos desde una referencia historica/sin
  vincular.
- Rollo creado por compra: copia `color_option_id` desde la referencia e ignora
  cualquier alternativa enviada en el payload.

Archivos:

- `src/Http/Requests/StoreProveedorTelaRequest.php`
- `src/Services/ProveedorTelaService.php`
- `src/Http/Requests/StoreCompraTelaRequest.php`
- `src/Http/Requests/UpdateCompraTelaRequest.php`
- `src/Services/CompraTelaService.php`
- `src/Resources/lang/es/app.php`
- `tests/Feature/ColorEstructuradoF1ATest.php`
- Ajustes de fixtures en `ProveedorTelaVistaDetalleTest.php` y
  `CompraTelaCargaMasivaTest.php`.

Tests ejecutados:

- `vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/ColorEstructuradoF1ATest.php`
  -> 7 passed.
- `vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/ProveedorTelaVistaDetalleTest.php packages/ByLopez/CorteConfeccion/tests/Feature/CompraTelaCargaMasivaTest.php`
  -> 26 passed.
- `vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/ColorEstructuradoF1ATest.php packages/ByLopez/CorteConfeccion/tests/Feature/ProveedorTelaVistaDetalleTest.php packages/ByLopez/CorteConfeccion/tests/Feature/CompraTelaCargaMasivaTest.php`
  -> 33 passed.
- `vendor/bin/pint --dirty` -> passed.

Resultado historico: F1-A completada y no se inicio F2.

## F1-B completada

- Cambio: UI de referencias alineada con F1-A; crear/editar usa solo select de
  colores Bagisto, placeholder "Seleccionar color del catálogo", sin "sin
  vincular" ni texto libre.
- Historicas NULL: siguen visibles; edicion muestra "Color sin vincular al
  catálogo" y "Color histórico: ..."; guardar exige seleccionar color real.
- Archivos: `referencia-form.blade.php`, `show.blade.php`,
  `ProveedorTelaVistaDetalleTest.php`.
- Tests: `vendor/bin/pest ...ProveedorTelaVistaDetalleTest.php ...ColorEstructuradoF1ATest.php`
  -> 30 passed.
- Formato: `vendor/bin/pint --dirty` -> passed.
- Resultado historico: F1-B completada y no se inicio F2.
- Hallazgo: el formulario de rollo manual aun ofrece `__sin_vincular__`; no se
  modifico en esta tarea.

## F1-C completada

- Migracion: requirio cambio minimo; el backfill ahora usa explicitamente
  `LOWER(TRIM(...))` en referencias y rollos manuales, y expone el bloque como
  metodo reutilizado por `up()` para testearlo sin DDL.
- Reglas verificadas: referencia con match unico, normalizacion historica,
  sin match queda NULL, duplicados quedan NULL, rollo con referencia copia de
  su referencia, rollo manual usa match estricto, rollo manual sin match queda
  NULL.
- Tests: `vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/ColorBackfillMigrationF1CTest.php`
  -> 1 passed / 7 assertions.
- Control runtime: `vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/ColorEstructuradoF1ATest.php`
  -> 7 passed.
- Formato: `vendor/bin/pint --dirty` -> passed.
- Resultado historico: F1-C completada y no se inicio F2.

## F1-D completada

- Confirmado: alta manual de rollos por ruta sigue desactivada
  (`rollos.create/store` no existen), pero `RolloService` y requests quedaron
  blindados si se invocan.
- Cambios: rollo manual nuevo exige `color_option_id` real del atributo
  Bagisto `color`; snapshot `color` se deriva en backend; sin texto libre ni
  sentinel en UI de rollos.
- Rollo con referencia: no permite cambiar `color_option_id` ni snapshot desde
  rollo; manipular request se rechaza.
- Rollo reservado o usado en Orden de Corte: color bloqueado por backend,
  incluyendo pivote multi-rollo y FK historica `ordenes_corte.rollo_id`.
- Archivos: `StoreRolloRequest.php`, `UpdateRolloRequest.php`,
  `RolloService.php`, `RolloDataGrid.php`, vistas `rollos/form` e `index`,
  `Resources/lang/es/app.php`, `RolloColorF1DTest.php`.
- Tests: `vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/Feature/RolloColorF1DTest.php`
  -> 9 passed / 14 assertions; `RollosPaginaRenderTest.php` -> 3 passed.
- Formato: `vendor/bin/pint --dirty` -> passed.
- Cambio de color en referencia: hoy no propaga a rollos existentes; conservan
  snapshot. Rollos futuros heredan el nuevo color de la referencia.
- Resultado historico: F1-D completada y no se inicio F2.

## F1-E1 — Adaptación de fixtures

- Causa: fixtures historicos creaban referencias nuevas de proveedor sin
  `color_option_id`, ahora invalido para datos nuevos por F1-A.
- Helper corregido: `cc_test_color_option()` en `tests/Pest.php`, crea opcion
  real del atributo Bagisto `code = color` sin IDs hardcodeados.
- Fixtures ajustados: helpers base de permisos, Orden de Corte, distribucion,
  inventario, rollos, talleres y SEC-01 que crean proveedor->referencia.
- Archivos: `tests/Pest.php` y tests Feature
  `AccionesCriticasPermisos`, `OrdenCorteResultadoYRecepcion`,
  `DistribucionConfeccion`, `DistribuirBaseFrontend`,
  `InventarioMovimientoVista`, `OrdenCorteVistaEstados`,
  `RolesYTalleres`, `RollosResumenYFiltro`, `SEC01PermisosDistribucion`,
  `TallerVistaDetalle`.
- Validacion acotada: 5 archivos (`AccionesCriticasPermisos`,
  `RollosResumenYFiltro`, `OrdenCorteResultadoYRecepcion`,
  `DistribuirBaseFrontend`, `InventarioMovimientoVista`) -> 61 passed /
  232 assertions.
- Resultado: ya no aparece "La referencia de tela debe tener un color
  vinculado al catalogo" en la muestra ejecutada.
- Cierre: suite completa `packages/ByLopez/CorteConfeccion/tests/` verde
  (254 passed / 1079 assertions) y Pint acotado verde.
- Resultado historico: F1-E1 completada y no se inicio F2.

## F1-E — Cierre formal de F1

- F1 completada formalmente tras F1-A, F1-B, F1-C, F1-D y F1-E1.
- Validacion final registrada:
  `vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/`
  -> 254 passed / 1079 assertions.
- Se actualizo `STATUS.md` para marcar F1 como COMPLETADA.
  (Correccion posterior: al iniciar F2 se comprobo que `STATUS.md` aun no lo
  reflejaba; quedo alineado al cerrar F2.)
- `packages/Webkul/`: 0 modificaciones.
- F2 no iniciada (a fecha del cierre de F1).

## F2 — Resolvedor de variantes

Estado: **COMPLETADA** (2026-09-24). Solo lectura sobre el catalogo Bagisto; sin
migraciones, sin config con IDs, sin tabla de lineas/perfiles, sin UI. No se
inicio F3 ni F4.

### Diseño real implementado

Un unico Service, `src/Services/ResolvedorVarianteCorteService.php`, es el unico
punto del modulo nuevo que conoce como esta modelado el catalogo. Resuelve en
DOS niveles, ambos con unicidad obligatoria:

```
(tipo_tela, linea, color)   -> exactamente 1 configurable
(configurable, color, eje2) -> exactamente 1 simple
```

- **Linea** = `attribute_family_id`. Los `filtros` opcionales
  (`['codigo_atributo' => option_id]`, p. ej. `['cuello' => 12]`) ya estan
  soportados y se evaluan en memoria, asi una futura `LineaCorte` sera
  familia + filtros sin cambiar el Service.
- **Atributos por `attributes.code`** (`color`, `tipo_tela`, `status` + los de los
  filtros). Si falta alguno: `ConfiguracionCatalogoCorteException` (falla cerrado,
  el mensaje lista los codes faltantes). Nunca IDs fijos.
- **Color y eje 2 por `attribute_options.id`**, nunca por texto.
- **Eje 2 dinamico**: un configurable es "soportado" si tiene exactamente 2 super
  atributos y uno es `color`; el otro es el eje 2 (`size`, `grupo_de_edad_ninos`...).
  Cualquier otra forma se marca `ejes_no_soportados` con su motivo y no se adivina.
- **Nivel 1**: candidatos = configurables de la familia (+ filtros) con
  `tipo_tela = T`. Se buscan los que tienen algun hijo con el color pedido,
  considerando TODOS los hijos (activos o no; la ambiguedad es estructural) y
  tambien los configurables no soportados. 0 -> `sin_producto_para_color`;
  >1 -> `color_ambiguo` (con id/SKU/nombre de cada padre); 1 no soportado ->
  `ejes_no_soportados`. Nunca se elige "el primero".
- **Nivel 2**: dentro del configurable unico, simples con `color` y `eje2`
  pedidos. 0 -> `talla_no_disponible`; >1 -> `variante_duplicada`; 1 -> se valida la
  postcondicion (mismo `attribute_family_id` que la linea; `type = simple` y
  `parent_id` quedan garantizados por la consulta) y se devuelve.
- **Estado**: `status` se lee del EAV (`product_attribute_values`, fila del canal
  por defecto `core()->getDefaultChannel()`), nunca de `product_flat`. Inactivo en
  tienda NO bloquea: genera advertencia. Fila de status ausente = "no activo en
  tienda" (igual que Bagisto) y tambien solo advertencia. Stock y vendibilidad no
  son criterio.
- **Performance**: cada llamada publica lee el catalogo en **8 consultas fijas**
  (atributos, configurables, super atributos, hijos, valores EAV, opciones,
  familias, nombres) y resuelve en memoria. Verificado: 1080 combinaciones contra
  `lpz_saas` = 8 consultas; el test de N+1 exige el mismo conteo para 4 y 60
  celdas. El Service no guarda cache entre llamadas (un hijo nuevo aparece en la
  siguiente llamada).

Archivos nuevos (todos bajo `packages/ByLopez/CorteConfeccion/`):

- `src/Services/ResolvedorVarianteCorteService.php`
- `src/Support/ResolucionVarianteCorte.php` (resultado inmutable)
- `src/Enums/ErrorResolucionVarianteCorte.php`, `src/Enums/AdvertenciaResolucionVarianteCorte.php`
- `src/Exceptions/ConfiguracionCatalogoCorteException.php`
- `src/Console/Commands/DiagnosticarCatalogoCorte.php` (+ registro en
  `src/Providers/CorteConfeccionServiceProvider.php`, solo en consola)
- `tests/Support/CatalogoCorteFixtures.php`,
  `tests/Feature/ResolvedorVarianteCorteServiceTest.php`,
  `tests/Feature/DiagnosticoCatalogoCorteTest.php`

### API publica

| Metodo | Uso |
|---|---|
| `lineasPorTipoTela(int $tipoTelaOptionId): array` | Lineas (familias) con al menos un configurable soportado para esa tela: `attribute_family_id`, `codigo`, `nombre`, `configurables`, `configurables_no_soportados`, `ejes`. Ordenadas por nombre. Derivado del catalogo, sin tabla. |
| `opcionesEje($tela, $familia, $color, $filtros = [])` | Opciones REALES del eje 2 (solo las que tienen hijo con ese color), ordenadas por `attribute_options.sort_order`: `error`, `detalle`, `configurable`, `linea`, `eje`, `opciones[{option_id,label,sort_order,variantes}]`, `advertencias`. |
| `opcionesEjeLote($tela, iterable $selecciones)` | Igual, por lote (familia + color [+ filtros] por seleccion; conserva claves). Pensado para la matriz de F4. |
| `resolver($tela, $familia, $color, $eje, $filtros = []): ResolucionVarianteCorte` | Resuelve al simple exacto. |
| `resolverLote($tela, iterable $combinaciones): array` | Igual, por lote (conserva claves). Una sola lectura del catalogo para toda la matriz, incluso con varias lineas. |
| `diagnosticarCatalogo(?int $tela = null): array` | Diagnostico de solo lectura (ver abajo). Sin tela revisa todo el catalogo. |

`ResolucionVarianteCorte`: `resuelta()`, `productId`, `sku`, `error`, `advertencias`,
`configurable {id,sku,nombre,activo}`, `snapshot` (linea, color, eje_code,
eje_etiqueta, eje_valor + IDs, listo para `genero_linea`/`talla` de F4),
`detalle` (contexto del error), `toArray()`. Nunca devuelve un configurable.

Comando: `php artisan corteconfeccion:diagnosticar-catalogo [--tipo-tela=ID] [--sku=SKU ...] [--json]`.

### Errores bloqueantes (`ErrorResolucionVarianteCorte`)

`sin_producto_para_color`, `color_ambiguo`, `ejes_no_soportados`,
`talla_no_disponible`, `variante_duplicada`, `postcondicion_incumplida`
(simple de una familia distinta a la de la linea). Aparte, la excepcion
`ConfiguracionCatalogoCorteException` para atributo estructural o canal por
defecto inexistente (o filtro sobre un atributo que no existe).

### Advertencias (`AdvertenciaResolucionVarianteCorte`, no bloquean)

`configurable_inactivo_en_tienda`, `variante_inactiva_en_tienda`.

### Diagnostico de catalogo

Codigos y severidad (`error` = inconsistencia de datos; `advertencia` = informativo):

| Codigo | Severidad |
|---|---|
| `color_ambiguo` (misma familia + tela + color en >1 configurable) | error |
| `configurable_multicolor` (hijos de varios colores) | error |
| `ejes_no_soportados` (no es color + 1 eje) | error |
| `simple_sin_color`, `simple_sin_eje`, `simple_valor_multiple` | error |
| `variante_duplicada` (configurable + color + eje2 con >1 simple) | error |
| `simple_familia_distinta_de_padre` | error |
| `configurable_sin_tipo_tela` (no es candidato; puede ser intencional) | advertencia |
| `configurable_inactivo_en_tienda`, `variante_inactiva_en_tienda` | advertencia |
| `status_desalineado_eav_flat` (EAV vs `product_flat`, solo informativo) | advertencia |

Cada hallazgo trae SKU/nombre/familia del configurable, sus hijos (color, eje,
activo) y detalle; `configurables_afectados` agrupa los codigos por configurable.
No corrige nada (el test DIAG 13 verifica que las tablas del catalogo no cambian).

### Resultado real contra `lpz_saas` (2026-09-24, solo lectura)

77 configurables, 300 simples: **8 errores, 5 advertencias**.

- `color_ambiguo` x5 (= las 13 colisiones de AUD-02 §2.4): Hombre / Beige Oscuro
  (`102DURZ-H`, `103DURZ-H`), Hombre / Verde Oliva (`120DURZN-H`, `121DURZ-H`),
  Hombre / Lila (`201DURZ-H`, `103DURZ-H`, `107DURZ-H`), Mujer / Rojo
  (`110DURZ-DAMA`, `124DURZ-D`), Mujer / Marfil (`117DURZ-DAMA`, `125DURZN-D`).
- `configurable_multicolor` x3: `103DURZ-H` (Beige Oscuro + Lila), `107DURZ-H`
  (Azul Oscuro + Lila), `125DURZN-D` (Marfil + Verde Menta).

Los 5 configurables problematicos de AUD-02 (sin decidir si el error esta en el
padre o en los hijos):

| SKU | Nombre | Hijos (color/eje) | Hallazgos |
|---|---|---|---|
| `103DURZ-H` | Café Hombre | Beige Oscuro/S, Lila/M, Lila/L, Lila/XL | `color_ambiguo` (Beige Oscuro con `102DURZ-H`; Lila con `201DURZ-H`, `107DURZ-H`), `configurable_multicolor` |
| `107DURZ-H` | Azul Oscuro Hombre | Azul Oscuro/S,M,L + Lila/XL | `color_ambiguo` (Lila), `configurable_multicolor` |
| `121DURZ-H` | Verde Manzana Hombre | Verde Oliva x4 | `color_ambiguo` (con `120DURZN-H`) |
| `124DURZ-D` | Terracota Mujer | Rojo x4 | `color_ambiguo` (con `110DURZ-DAMA`) |
| `125DURZN-D` | Verde Menta Mujer | Marfil/S + Verde Menta/M,L,XL | `color_ambiguo` (Marfil, con `117DURZ-DAMA`), `configurable_multicolor` |

Tambien aparecen como participantes de la ambiguedad, aunque su propio dato se
ve coherente: `102DURZ-H`, `110DURZ-DAMA`, `117DURZ-DAMA`, `120DURZN-H`, `201DURZ-H`.

`110DURZ-H` (Rojo Hombre): sigue desalineado — status EAV = 0 (inactivo) vs
`product_flat.status = 1` — y sus 4 hijos estan activos. Diagnostico:
`configurable_inactivo_en_tienda` + `status_desalineado_eav_flat` (advertencias).
El resolvedor SI resuelve `Hombre + Rojo + M` -> `110DURZ-H-M` con la advertencia
`configurable_inactivo_en_tienda`.

Otras advertencias: `109DURZ-H-L/-M/-XL` (Vino Tinto Hombre) inactivos en tienda.
Cambio respecto a AUD-02 (que contaba 297/297 hijos activos): el catalogo ha
cambiado desde entonces; no es un error de produccion.

Casos de AUD-02 §10 reproducidos contra el catalogo real: `Hombre+Negro+M` ->
`106DURZ-H-M`; `Hombre+Lila` -> `color_ambiguo` (201/103/107); `Hombre+Azul
Oscuro+XL` -> `talla_no_disponible`; `Mujer+Rojo` -> `color_ambiguo`
(110DURZ-DAMA/124DURZ-D); `Hombre+Cafe` -> `sin_producto_para_color`;
`Nino+Negro+6-8` -> `106DURZ-NINO-6-8`; Crop Top Negro -> opciones M y T-Unica.
`lineasPorTipoTela(Piel Durazno)`: Hombre (34), Mujer (35), Niño (4, eje
`grupo_de_edad_ninos`), Crop Top (4).

### Decisiones de implementacion (no reabren decisiones aprobadas)

1. Fila de `status` ausente en el EAV = inactivo en tienda (advertencia), no error.
2. `configurable_sin_tipo_tela` es advertencia, no error: puede ser un producto
   no textil; simplemente no es candidato de ninguna tela.
3. `configurable_multicolor` es error de datos (causa raiz de varios
   `color_ambiguo`), aunque el resolvedor sigue resolviendo los hijos correctos
   de un configurable multicolor cuando el color no es ambiguo.
4. Se exige canal por defecto para leer el status (`ConfiguracionCatalogoCorteException`
   si no existe); nunca `getCurrentChannel()`.
5. Se agrego `postcondicion_incumplida` como sexto error bloqueante (la
   postcondicion pedida de familia del simple).

### Tests

42 nuevos, en 2 archivos Feature (fixtures compartidos en `tests/Support/`):

- `ResolvedorVarianteCorteServiceTest.php` (28): los 16 casos obligatorios del
  encargo (1-16) mas 2b (ambiguedad con hijos inactivos), 5b/5c (status por EAV,
  fila ausente), 7b, 9b (configurable con 2 colores, caso real 107DURZ-H), 11b
  (sin eje color), 17 (falla cerrado si falta `color`/`tipo_tela`/`status`), 18
  (filtros opcionales por cuello), 19 (postcondicion), 20-21 (lotes/claves) y 22
  (numero de consultas fijo, sin N+1).
- `DiagnosticoCatalogoCorteTest.php` (14): los 10 diagnosticos requeridos,
  simple de familia distinta, agrupacion por configurable, solo lectura y
  comando artisan (JSON, `--sku`, validacion).

Se verifico con mutaciones temporales (ya revertidas) que los tests detectan:
elegir "el primero" ante ambiguedad, leer status de un valor fijo, listar
tallas de otro color, ignorar filtros y ignorar ejes no soportados.

### Validacion

- F2 especifico: `ResolvedorVarianteCorteServiceTest` + `DiagnosticoCatalogoCorteTest`
  -> 42 passed.
- `VarianteConsultaServiceTest` -> 15 passed / 50 assertions (sin cambios).
- Suite completa `vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/` ->
  296 passed / 1289 assertions.
- `vendor/bin/pint --dirty` -> passed (y `pint --test` explicito sobre los
  archivos nuevos -> passed).
- `packages/Webkul/`: 0 modificaciones.

### Hallazgos fuera de alcance (no corregidos)

- `VarianteConsultaService` (04B) sigue leyendo `product_flat.status` para
  `activo` (linea 51): para `110DURZ-H` devolvera `activo = true` mientras el EAV
  dice 0. No afecta al resolvedor; conviene alinearlo cuando F4/F5 reutilicen
  `activo`.
- `VarianteConsultaService` toma como eje 2 "el primer super atributo que no es
  color" y no falla cerrado con >2 ejes, a diferencia del resolvedor.
- La correccion de los 5 configurables (y de `110DURZ-H`) es trabajo de
  catalogo (D-6 de AUD-02), prerequisito para habilitar F4 en produccion.

## F3 — Merma total de tela por Orden de Corte

Estado: **COMPLETADA** (2026-09-24). **F4 NO INICIADA.** No se habilito mezcla
de colores en `create` (la compatibilidad de rollos sigue igual hasta F4).

### Auditoria previa (codigo y datos reales, antes de modificar)

El prompt de F3 partia de un modelo con un unico `peso_sobrante_total` que se
repartia en cascada. La auditoria encontro diferencias importantes (se detuvo el
trabajo, se explicaron y el usuario resolvio dos decisiones — ver abajo):

| Campo | Significado real (pre-F3) |
|---|---|
| `Rollo.peso_inicial` | kg comprados. |
| `Rollo.peso_disponible` | kg utilizables para nuevas ordenes. **No se descuenta al reservar ni al entregar** (solo cambia el estado a `reservado_en_corte`). El unico escritor en el flujo de cortes era `actualizarRollosAlCerrar()`, que lo **sobrescribia** (no restaba) con el sobrante asignado. |
| `OrdenCorteRollo.peso_inicial_usado` | Copia de **TODO** el `peso_disponible` del rollo al elegirlo (`create()` y `agregarRollo()`); no es una porcion asignada. |
| `OrdenCorteRollo.peso_sobrante` / `peso_consumido` | 0 hasta `cerrar()`; luego los llenaba la cascada. |
| `OrdenCorte.peso_sobrante_total` | **No era merma**: era el «Peso sobrante usable» que Bodega escribia en la recepcion (tela REUTILIZABLE que volvia al rollo). |
| `OrdenCorte.merma_total` (ya existia) | Retazos + desperdicio de la recepcion, a nivel de orden; nunca se repartia a rollos. |
| `OrdenCorte.peso_consumido_total` | `peso base − sobrante usable − merma`. |

- **Reserva/entrega:** al elegir un rollo (`create`/`agregarRollo`) se crea el
  pivote con `peso_inicial_usado = peso_disponible` y el rollo pasa a
  `reservado_en_corte` sin tocar su peso. `entregar()` marca `entregado_en` y
  `peso_entregado` = suma de `peso_inicial_usado` de los rollos entregados;
  `peso_total_rollos` = suma al crear/agregar.
- **Cierre:** `cerrar()` (`recibido_bodega` → `cerrado`, permiso `operar`)
  llamaba a `actualizarRollosAlCerrar()`: repartia `peso_sobrante_total` en
  cascada; cada rollo quedaba con `peso_disponible` = su parte y estado por
  `estadoPorPeso()` (0 → `agotado`).
- **Lectores de pesos:** `card-recepcion.blade.php`, `RecepcionCorteDataGrid` y
  las vistas de `recepciones-corte`. No existen KPIs ni dashboards.
- **Tests previos:** ninguno afirmaba el resultado de la cascada; 9 archivos
  usaban `peso_sobrante_usable/peso_retasos/peso_desperdicio` en el payload de
  fixtures de recepcion.
- **Datos de `lpz_saas`:** ninguna orden cerrada (la cascada nunca corrio alli);
  3 recepciones (una con 2 kg usables, 2 de retazos y 2 de desperdicio).
- **Riesgo real de la cascada:** `actualizarRollosAlCerrar()` asigna el sobrante
  al primer rollo hasta agotar su peso, luego al segundo, etc. Era inocuo solo
  porque todos los rollos de una orden eran fisicamente intercambiables
  (mismo tipo, color y gramaje). Con rollos de colores/gramajes distintos (F4)
  atribuiria tela reutilizable de un color a rollos de otro.

Decisiones del usuario tras la auditoria (2026-09-24):

1. **Retirar el sobrante usable en F3.** El peso no consumido deja de volver al
   inventario: todo es merma de la orden y los rollos F3 terminan agotados.
   Consecuencia aceptada: se pierde el retorno de remanentes reutilizables hasta
   que exista un flujo con identidad fisica de remanente.
2. **Reusar `merma_total`** (sin `peso_merma_total`) mas un marcador booleano
   legacy/F3 en `OrdenCorte`.

### Modelo adoptado

- Migracion `2026_09_24_000001_add_merma_unica_to_bylopez_cc_ordenes_corte`:
  `bylopez_cc_ordenes_corte.merma_unica` boolean, `NOT NULL DEFAULT false`.
  Aditiva; ninguna fila existente se convierte ni se recalcula (todas quedan
  `false` = historicas). Aplicada en `lpz_saas` y `lpz_saas_testing`.
- `merma_unica = true` ⇔ la recepcion se creo con el contrato F3: UNA merma total
  no reutilizable de toda la orden en `OrdenCorte.merma_total`
  (`RecepcionCorte.merma_total` igual); `peso_sobrante_total = 0`;
  `peso_consumido_total = pesoBase − merma`.
- **Por que un marcador** y no `merma_total = 0` / `peso_sobrante_total = 0`: 0 es
  un valor real en ambos contratos. Se escribe al **crear la recepcion** (el
  momento en que se captura el peso): una orden creada antes de F3 pero recibida
  despues ya usa el contrato nuevo; una ya recibida antes de F3 sigue historica
  hasta su cierre.
- `merma_total` conserva su significado («kg de tela no reutilizable de la
  orden»); en historicas es retazos + desperdicio, en F3 es el total capturado.
  `peso_retasos`, `peso_desperdicio` y `peso_sobrante_usable` de
  `bylopez_cc_recepciones_corte` quedan como columnas historicas (0 en F3).
- `OrdenCorte::pesoBase()`: unica definicion del peso fisico asignado
  (`peso_entregado` si > 0; si no, `peso_total_rollos`). La usan el Request, el
  Service y el cierre (`pesoBaseCierre` mantiene su tercer respaldo por pivotes).

### Contrato de recepcion (Bodega)

- `card-recepcion.blade.php`: un unico campo «Merma / sobrante no reutilizable
  (kg)» con el texto «Peso total de tela que no puede volver al inventario de
  rollos. Registre el total de toda la orden, sin separarlo por color.» Sin
  inputs por rollo, color, gramaje ni grupo. Reemplaza a los tres pesos
  anteriores. Tras la recepcion la ficha muestra Tela asignada, Merma no
  reutilizable y Peso aprovechado (`pesoBase − merma` = `peso_consumido_total`,
  correcto porque en F3 no existe otro componente de peso); las historicas
  conservan sus tarjetas originales.
- `StoreRecepcionCorteRequest`: `merma_total` requerido, numerico, `>= 0`,
  `decimal:0,3` (misma precision que los pesos, `decimal(12,3)`); los tres pesos
  historicos son `prohibited` (descartarlos en silencio ocultaria tela que el
  usuario cree que vuelve al inventario). Limite fisico en `withValidator()`:
  `merma_total <= OrdenCorte::pesoBase()`; si no, «La merma total no puede superar
  el peso de tela asignado a la orden.»
- **Limite fisico derivado del modelo real:** como `peso_inicial_usado` es el
  peso completo del rollo, el maximo posible es el peso realmente entregado
  (`peso_entregado`), o `peso_total_rollos` si aun no hay entrega; el limite es
  inclusivo. Si solo se entregaron parte de los rollos manda lo entregado.
- `RecepcionCorteService::create()`: repite las validaciones (rechaza pesos
  legacy, no numerico, negativo, mas de 3 decimales, mayor al limite), guarda la
  merma **una sola vez** a nivel de orden con payload explicito (nunca `$data`
  completo), fija `merma_unica = true` y `peso_sobrante_total = 0`. No cambia el
  manejo de `orden_corte_detalle_id`, cantidades, defectuosas, observaciones,
  autorizaciones ni el estado. La merma de tela es independiente de prendas
  defectuosas y faltantes.
- Vistas historicas: `recepciones-corte/show` y `RecepcionCorteDataGrid` ocultan
  sobrante/retazos/desperdicio en recepciones F3 (un 0 seria enganoso); las
  historicas se muestran igual que antes.

### `actualizarRollosAlCerrar()` (logica final)

Bifurca por el marcador, nunca por valores:

- `merma_unica = true` → `cerrarRollosSinRetorno()`: por cada pivote
  `peso_sobrante = 0` y `peso_consumido = peso_inicial_usado` (todo lo que salio
  fisicamente del rollo); el rollo queda con `peso_disponible = 0` y estado por
  `estadoPorPeso()` = `agotado`. **La merma nunca se atribuye a un rollo, color
  ni gramaje**, y no se toca color, `color_option_id`, `tipo_tela_id`, gramaje,
  referencia ni `peso_inicial`. El `peso_consumido` del pivote incluye la parte
  indeterminada de la merma de ese rollo; la merma total vive solo en la orden
  (`OrdenCorte.peso_consumido_total` si la excluye).
- `merma_unica = false` → `repartirSobranteHistorico()`: la cascada original,
  intacta, solo para ordenes historicas.
- `cerrar()` no cambio: con `peso_sobrante_total = 0` su formula
  `base − sobrante − merma` ya es correcta para F3. La proteccion de estado
  existente (recepcion unica, orden terminal) evita que un segundo intento
  duplique o reaplique la merma.

Estados de rollo: reglas generales sin cambios (`estadoPorPeso`). Un rollo F3
queda `agotado` porque no existe peso reutilizable identificado.

### KPI futuro

Queda almacenado el dato fuente para `% merma = merma_total / peso_entregado`
(por orden, mes, tela, cortador, linea). No se construyo ningun KPI/dashboard.

### Tests

20 nuevos en `tests/Feature/MermaTotalOrdenCorteF3Test.php`. Las ordenes se
construyen directamente (pivotes con rollos de colores y gramajes distintos,
sin relajar `validarCompatibilidadRollos()`); recepcion y cierre usan los
Services reales.

- CASO 1 merma simple (50 kg asignados, 4 kg de merma) · 2 mismo color sin
  reparto · 3 Negro/Rojo/Vino Tinto con 4.8 kg sin contaminacion de peso · 4
  merma 0 valida y marca F3 · 5 negativa/no numerica/4 decimales rechazadas · 6
  mayor al maximo rechazada, igual valida · 7 idempotencia (segunda recepcion y
  segundo cierre) · 8 orden historica legible y no recalculada · 9 recepcion de
  prendas (cantidades, defectuosas, identidad, total de piezas) · 10
  `DisponibilidadConfeccionService` no depende de la merma · 11 la merma nunca
  incrementa `Rollo.peso_disponible` · 12 identidad de rollos intacta (dentro
  del CASO 3) · 13 limite fisico segun peso entregado · 14/14b cascada solo
  historica, ninguna orden F3 entra por ella (incluye una orden F3 con sobrante
  anomalo), contrato legacy rechazado en recepciones nuevas.
- Extra: HTTP (rechazos del Request y exito), Request aislado (el limite y los
  pesos historicos se rechazan antes del Service), vista del formulario (un solo
  input de peso), ficha tras la recepcion, vista de recepcion y DataGrid.
- Fixtures: los 12 payloads de recepcion de 9 archivos existentes cambiaron
  `peso_sobrante_usable/peso_retasos/peso_desperdicio` (todos 0) por
  `merma_total => 0`. Cambio justificado: el contrato de recepcion cambia a
  proposito (el Service ahora rechaza los pesos legacy). No se relajo ninguna
  asercion ni se cambio el comportamiento esperado de esos tests.
- Mutaciones verificadas (revertidas): F3 entrando a la cascada historica, no
  marcar `merma_unica`, sin limite en Service, sin limite en Request, rollo que
  recupera la merma, y aceptar pesos legacy — todas detectadas.

### Validacion

- F3 especifico: `MermaTotalOrdenCorteF3Test` → 20 passed / 208 assertions.
- Recepcion/rollos (6 archivos) → 69 passed. Distribucion/confeccion (6
  archivos) → 110 passed.
- Suite completa `vendor/bin/pest packages/ByLopez/CorteConfeccion/tests/` →
  316 passed / 1497 assertions.
- `vendor/bin/pint --dirty` → passed (y `pint --test` explicito → passed).
- `packages/Webkul/`: 0 modificaciones.

### Hallazgos fuera de alcance (no corregidos)

- Se pierde el retorno de remanentes reutilizables al inventario de rollos hasta
  que exista un flujo con identidad fisica (decision explicita del usuario).
- `recepciones-corte/create.blade.php` (pantalla retirada desde F0, sin uso)
  conserva los tres campos de peso historicos; no se toco por estar sin ruta.
- `peso_consumido` del pivote (F3) incluye la merma indeterminada del rollo,
  mientras `OrdenCorte.peso_consumido_total` la excluye: nombres iguales, base
  distinta. Conviene aclararlo si F4/KPIs consumen el pivote.
- El marcador `merma_unica` vive solo en `OrdenCorte`, no en `RecepcionCorte`
  (la recepcion siempre pertenece a una orden y `RecepcionCorteDataGrid` ya
  une ambas tablas).

## F1-D completada

Revalidacion/cierre de las reglas de color del rollo (2026-09-24; F1 NO se cierra aqui).
- **Confirmado A–E:** rollo con referencia hereda `color_option_id` y no puede cambiarlo ni con request manipulado (422); reservado o ya usado (FK historica o pivote, orden abierta o cerrada) tampoco; manual sin uso solo cambia a opciones del atributo `color`. El modal envia solo un `<select>` de colores Bagisto (deshabilitado si esta bloqueado): sin texto libre ni «Sin vincular».
- **CASO B:** el alta manual NO es una ruta activa (no existen `rollos.create/store`); `StoreRolloRequest` y `RolloService::create` exigen un color del atributo `color`. No se elimino nada.
- **Cambio (hueco real):** un rollo bloqueado reescribia su snapshot `color` desde Bagisto si llegaba el mismo `color_option_id` (opcion renombrada). Ahora el color de un rollo bloqueado es inmutable. Nuevo `Rollo::colorBloqueado()` (unica definicion) usado por `UpdateRolloRequest` y `RolloService::update`.
- **Archivos:** `Models/Rollo.php`, `Services/RolloService.php`, `Http/Requests/UpdateRolloRequest.php`, `tests/Feature/RolloColorF1DTest.php`.
- **Tests:** `RolloColorF1DTest` 17 (9 previos + 8: tipo_tela, orden cerrada por pivote, snapshot inmutable, HTTP manipulado/permitido/manual, Request de alta, UI). Rollos/color (6 archivos): 39 passed / 159 assertions. Pint OK. `packages/Webkul/`: 0. Suite completa no ejecutada.
- **Cuando cambia el color de una referencia (auditado con prueba temporal, ya eliminada):** la referencia se edita en sitio y NO propaga: los rollos existentes conservan `color_option_id` y snapshot; los futuros heredan el nuevo color.
- **Hallazgo (RESUELTO despues en PRE-F4, ver esa seccion; se conserva aqui como historia):** al guardar/editar la COMPRA, `CompraTelaService::sincronizarRollos` re-copia el color actual de la referencia a todo rollo sin FK `ordenes_corte.rollo_id`. Solo protege por esa FK, no por el pivote `orden_corte_rollos`: un 2.o..N.o rollo reservado de una orden multi-rollo cambia de color y vuelve a `disponible`; si se omite de la compra, se elimina (y su pivote por cascada). Requiere decision/correccion aparte. (Resuelto en PRE-F4.)

## PRE-F4 — Blindaje de rollos en Compra de Tela

Estado: **COMPLETADO** (2026-09-24). **F4 NO INICIADA.** Sin cambios en F2, F3,
catalogo Bagisto, movimientos de inventario ni confeccion.

### Hallazgo original y riesgo del pivote multi-rollo

`CompraTelaService::sincronizarRollos()` y `normalizarRollosBloqueados()` solo
protegian los rollos con FK historica `ordenes_corte.rollo_id`, que apunta
UNICAMENTE al primer rollo de cada orden. Los rollos 2..N solo existen en el
pivote `orden_corte_rollos`. Verificado con prueba temporal (eliminada): al
guardar una compra, un 2.o rollo reservado cambiaba de color y volvia a
`disponible`, y si se omitia del payload se eliminaba (y su pivote por cascada).
Ademas la ficha de edicion de compra solo marcaba «bloqueado» por esa FK.

### Auditoria previa (codigo real)

- Bloqueo previo: `Rollo::colorBloqueado()` (referencia, estado reservado, pivote
  o FK). Reserva: `agregarRollo()`/`create()` (pivote + estado `reservado_en_corte`,
  `peso_disponible` intacto). **No existia ningun flujo para retirar un rollo de
  una orden** (solo `cancel()` de la orden completa); se detuvo el trabajo, se
  explico y el usuario aprobo crearlo.
- `ordenes_corte.rollo_id` es NOT NULL con `cascadeOnDelete` (borrar ese rollo
  borraria la orden); pivote `unique(orden, rollo)` con cascade.
- `cancel()` (desde cualquier estado no terminal) libera bien el estado del rollo
  (`disponible`/`parcialmente_usado` por peso) pero CONSERVA pivote y FK.

### Regla de negocio y punto de no retorno

- Rollo disponible sin orden: editable con las reglas normales.
- Rollo reservado en orden que aun no inicio corte: NO se modifica desde Compra
  (mensaje: «... Retirelo primero de la orden antes de modificarlo en la Compra de
  Tela»); se libera con el retiro formal desde la Orden.
- **Punto de no retorno = `en_corte`.** Derivado de la maquina de estados
  (`OrdenCorteEstadoMachine::antesDeEnCorte()` recorre las transiciones; no es una
  lista aparte): pendiente_entrega y entregado_a_cortador pueden retirar;
  en_corte, reportado_por_cortador, recibido_bodega y cerrado quedan bloqueados
  (`haIniciadoCorte()`). Mensaje: «... ya participo en una Orden de Corte iniciada
  y no puede modificarse ni eliminarse».
- **Orden anulada (decision del usuario: mantener bloqueado).** El estado del
  rollo se libera correctamente y puede reservarse de nuevo, pero pivote y FK
  quedan: el rollo sigue vinculado historicamente (`VINCULO_SOLO_ANULADAS`) y
  Compra no puede modificarlo ni eliminarlo (mensaje propio). Motivo: la maquina de
  estados no guarda el estado previo a la anulacion ni marca de tiempo de `en_corte`,
  y borrar/anular el vinculo arriesga la FK con cascade.

### Cambios

- `Rollo`: `vinculosConOrdenCorte()` (2 consultas, sin N+1; pivote U FK; prioridad
  iniciada > reservada > solo anuladas; `reservado_en_corte` sin orden cuenta como
  reservada), `vinculoConOrdenCorte()`, **`estaVinculadoAOrdenCorte()`** (definicion
  unica) y `colorBloqueado()` = referencia OR vinculado. `RolloService` la usa (se
  elimino su copia).
- `CompraTelaService::update()`: valida ANTES de escribir y dentro de una sola
  transaccion (filas de los rollos bloqueadas): un rollo vinculado omitido del
  payload, o con cambios en codigo, referencia, color, referencia (texto), gramaje,
  peso inicial, valor por kilo (o tipo de tela si no tiene referencia), falla con
  `ValidationException` (`rollos`). Solo `observacion` sigue editable. Sin cambios ->
  se conserva todo (snapshot, `color_option_id`, estado, pivote). `sincronizarRollos`
  mantiene una ultima red de seguridad antes de `delete()`. La referencia que cambia
  de color NO propaga a rollos bloqueados; los rollos nuevos heredan el color actual.
  Rollos libres se siguen modificando/eliminando como antes.
- `UpdateCompraTelaRequest`: no exige consistencia con la referencia ACTUAL a un rollo
  bloqueado cuya identidad no cambia (evitaba guardar la compra tras cambiar la
  referencia de color); si intenta cambiarla, sigue el Service. La ficha de edicion
  (`compras-tela/form`) marca «bloqueado» con la misma regla (antes solo la FK); se
  quito el eager-load `rollos.ordenesCorte` del controlador.
- **Retiro formal** `OrdenCorteService::retirarRollo()` + `RetirarRolloOrdenCorteRequest`
  (mismo permiso que agregar rollo) + ruta POST
  `ordenes-corte/view/{id}/rollos/{rolloId}/retirar` + boton «Retirar» en
  «Rollos seleccionados» (solo antes de en_corte y con mas de un rollo): bloquea orden
  y rollo; exige `antesDeEnCorte`; elimina el pivote (accion explicita, nunca
  automatica); el rollo vuelve a `estadoPorPeso` (disponible/parcialmente_usado) solo
  si no sigue reservado en otra orden abierta, su `peso_disponible` no cambia;
  recalcula `peso_total_rollos` y `peso_entregado`; si era el de `ordenes_corte.rollo_id`
  reasigna la FK al primer rollo restante y actualiza el snapshot `color`; no permite
  retirar el ultimo rollo (se anula la orden).
- Maquina de estados: `antesDeEnCorte()` y `haIniciadoCorte()`.
- Mensajes en `lang/es/app.php`. F1-D conservado: un rollo bloqueado no reescribe su
  snapshot aunque llegue el mismo `color_option_id`.

### Tests (21 nuevos: `tests/Feature/BlindajeRollosCompraPreF4Test.php`)

Maquina de estados; CASO 1 rollo libre editable; 2 primer rollo (FK+pivote); 3 rollos
2..N solo por pivote; 4-6 omitido: no se elimina, conserva pivote y sigue reservado;
7 mensaje «retirar primero» (pendiente y entregado); 8 retiro formal y luego edicion;
9 en_corte bloquea retiro; 10/13 en_corte/reportada/recibida/cerrada bloquean
modificar, eliminar y retirar (tambien solo por pivote); 11/12 referencia cambia de
color (bloqueado conserva, futuro hereda); orden legacy solo con FK; 14 retiro del
primer rollo reasigna FK; retiro: ultimo rollo, ajeno, recalculo de pesos; 15 HTTP
manipulado 422 con datos intactos; 15b HTTP guardar con rollos bloqueados y referencia
cambiada; 16 rollback total; 17 rollo libre omitido se sigue eliminando; 18 orden
anulada; F1-D conservado; HTTP del retiro (401 sin permiso); UI. Mutaciones
verificadas (revertidas): ignorar el pivote, sin validacion previa, retiro en
cualquier estado, sin reasignar FK, Request sin excepcion, rollo que queda reservado,
en_corte como previo, anulada sin vinculo.

### Resultado

- PRE-F4 especifico: 21 passed / 183 assertions. Compra/rollo/orden (8 archivos): 88
  passed. `RolloColorF1DTest`: 17 passed. `MermaTotalOrdenCorteF3Test`: 20 passed.
- Suite completa: **345 passed / 1748 assertions**. `pint --dirty`: passed.
- `packages/Webkul/`: 0 modificaciones.

### Pendientes / hallazgos fuera de alcance (no corregidos)

- `agregarRollo()` permitia agregar rollos en `en_corte` y `cancel()` desde en_corte o
  posterior devolvia los rollos a disponible con su peso completo: RESUELTOS en
  PRE-F4.1 (ver esa seccion).
- La maquina de estados no registra el estado previo a `anulado` ni cuando empezo
  `en_corte`; por eso las anuladas siguen bloqueadas por seguridad.
- `recepciones-corte/create.blade.php` y `rollos/form.blade.php`/`create`/`edit`
  (sin ruta) conservan logica antigua; no se tocaron.
- F4 NO iniciada.

## PRE-F4.1 — Coherencia del punto de no retorno

Estado: **COMPLETADO** (2026-09-24). **F4 NO INICIADA.** Cierra los dos huecos que PRE-F4
dejo documentados; no toca F1/F2/F3, resolvedor, inventario, confeccion ni catalogo.

- **`en_corte` es el punto definitivo**, siempre derivado de
  `OrdenCorteEstadoMachine::antesDeEnCorte()`/`haIniciadoCorte()` (sin otra lista).
- **Agregar/retirar rollos:** permitido en pendiente_entrega y entregado_a_cortador;
  bloqueado desde en_corte (en_corte, reportado, recibido, cerrado).
  `OrdenCorteService::agregarRollo()` ahora valida `antesDeEnCorte` (antes solo
  `puedeEditarSolicitud`, que incluye en_corte). `puedeEditarSolicitud`/`entregar`/
  agregar detalles no cambian. Mensaje: «No se pueden agregar rollos a una Orden de
  Corte despues de iniciar el corte.»
- **Cancelacion:** `OrdenCorteService::cancel()` permite anular solo antes de en_corte
  (libera rollos como siempre). Desde en_corte lanza «No se puede anular una Orden de
  Corte despues de iniciar el corte.» sin tocar orden, rollos, pesos ni pivotes: ya
  puede haber consumo fisico y no hay flujo de reversion (fuera de alcance). Cerrada y
  anulada conservan su comportamiento terminal («Una orden cerrada no puede anularse»).
- **UI:** la ficha de la orden oculta «Anular» y «Agregar rollo» (y «Retirar») desde
  en_corte (`show.blade.php`, `card-rollos.blade.php`); el backend sigue siendo la
  autoridad. `canEditOrder` no se cambio porque tambien gobierna productos/detalles.
- **Archivos:** `Services/OrdenCorteService.php`, `Resources/lang/es/app.php`,
  `ordenes-corte/show.blade.php`, `ordenes-corte/partials/card-rollos.blade.php`,
  `tests/Feature/BlindajeRollosCompraPreF4Test.php`.
- **Tests (6 nuevos, prefijo «PRE-F4.1»):** agregar por estado (pendiente/entregado si;
  en_corte/reportado/recibido/cerrado no), retirar con la misma frontera, cancelar antes
  de en_corte libera, cancelar desde en_corte/reportado/recibido bloquea sin cambios
  (rollos, `peso_disponible`, pivotes, estado de la orden), cerrada terminal, HTTP+UI.
  Mutaciones verificadas (revertidas): sin bloqueo de cancelar y sin bloqueo de agregar.
- **Resultado:** PRE-F4.1 6 passed; PRE-F4 + F1-D + F3 + OrdenCorte (6 archivos) 108
  passed; suite completa **351 passed / 1821 assertions**; `pint --dirty` passed;
  `packages/Webkul/`: 0 modificaciones.
- **F4 sigue NO INICIADA.**

## F4 — Creación V2 de Orden de Corte

Estado: **F4 COMPLETADA TECNICAMENTE** (2026-09-25). **CATALOGO PRODUCTIVO PENDIENTE
DE CORREGIR AMBIGUEDADES** (no declarar «catalogo listo para produccion»). **F5 NO
INICIADA.** Sin cambios en `packages/Webkul/`, catalogo Bagisto, F2, F3, PRE-F4/PRE-F4.1,
inventario ni confeccion.

### 1. Flujo final

`ordenes-corte/create`: (1) rollos → (2) resumen de tela (tipo, peso, colores REALES de
los rollos) → (3) lineas (una o varias) → (4) variantes: por linea y color solo las
tallas/ejes que existen → (5) «Revisar seleccion» (resuelve en servidor, muestra SKU
exactos, advertencias y errores, total de variantes) → «Crear orden» (solo habilitado
tras una revision vigente y sin errores; cualquier cambio invalida la revision).
El usuario ve tela, rollo, color, linea y talla; los IDs solo viajan como campos
ocultos. Componente Vue `v-orden-corte-form` en `create.blade.php` (cards/acordeones,
responsive). El autocomplete de productos y `cantidad_proyectada` ya no existen en el
create.

### 2. Contrato del payload (POST `ordenes-corte/create`)

```
rollo_ids: [10, 11, 12]
cortador_id, observacion
seleccion: [ {attribute_family_id, color_option_id, eje_option_id, filtros?}, ... ]
```
`filtros` (`{codigo_atributo: option_id}`) esta aceptado y propagado al resolvedor pero
NO tiene UI. NO existe `product_id` ni `cantidad_proyectada`: si el navegador los
envia (`detalles`, `seleccion.*.product_id`) simplemente no se leen (no estan en las
reglas → `validated()` no los devuelve). `StoreOrdenCorteRequest` valida estructura;
`SeleccionOrdenCorteRequest::reglasSeleccion()` la comparte con los endpoints de lectura.

### 3-8. Rollos, compatibilidad, multi-color, multi-gramaje, colores, lineas, eje

- **Validacion de rollos (backend, `OrdenCorteService::rollosValidadosParaCorte()`):**
  existen, disponibles, no reservados en otra orden abierta, con `tipo_tela_id` y
  `color_option_id`, y del mismo `tipo_tela_id`. El frontend solo ayuda.
- **Nueva compatibilidad — `Support/CompatibilidadRollosCorte` (regla unica):** mismo
  `tipo_tela_id`; color, gramaje, referencia, compra y proveedor pueden variar. La usan
  crear, `agregarRollo()` y la matriz. Mensaje `rollos-incompatibles` actualizado.
- **Multi-color / multi-gramaje:** oficial. Los colores de la orden salen SOLO de
  `Rollo.color_option_id` (colores del catalogo no presentes en los rollos se rechazan).
- **Lineas:** `ResolvedorVarianteCorteService::lineasPorTipoTela()` (linea =
  AttributeFamily; sin tabla, perfiles ni config). **Eje/tallas:** `opcionesEjeLote()`
  (dinamico: size, grupo_de_edad_ninos...; solo opciones con hijos; XXL sin hijo no
  aparece); una celda linea×color sin producto se informa, no se inventa.

### 9. Endpoints (ACL = crear orden) y servicios

- `GET ordenes-corte/opciones` → matriz JSON (`SeleccionOrdenCorteService::matriz()`).
- `POST ordenes-corte/previsualizar` → filas resueltas + errores
  (`SeleccionOrdenCorteService::resolver()`).
- `Services/SeleccionOrdenCorteService`: contexto (tela/colores), matriz y resolucion;
  unica semantica de la seleccion, compartida por preview y create.
- Rutas registradas en `acl.php` bajo `corte_confeccion.ordenes_corte.create`.

### 10-12. resolverLote, transaccion y rollback

`OrdenCorteService::crearDesdeSeleccion()` (unico camino del store), en UNA
transaccion: bloquea (`lockForUpdate`) y valida rollos → colores permitidos →
duplicados → **`resolverLote()` de TODA la seleccion (una carga de catalogo, no
`resolver()` por celda)** → cualquier error lanza `ValidationException` (`seleccion`) y
revierte TODO → orden + pivotes + reserva + un detalle por simple. Como la resolucion
ocurre antes de escribir, ademas del rollback transaccional no se crea ni un registro
parcial. Rendimiento verificado: mismo numero de consultas para 1 o 15 filas.

### 13. Snapshots (por detalle; `OrdenCorteDetalle`)

`product_id` = SIMPLE exacto (nunca el configurable), `producto_sku` = sku del simple,
`producto_nombre` = nombre del configurable (linea×color), `genero_linea` = nombre de la
familia (espacios normalizados), **`color` = etiqueta del color (columna nueva,
nullable)**, `talla` = etiqueta del eje dinamico, `variant_id` = NULL (sin uso nuevo),
`cantidad_proyectada` = 0, `cantidad_real_cortada` = 0, `es_adicional` = false.
Migracion `2026_09_25_000001_add_color_to_bylopez_cc_ordenes_corte_detalles`
(nullable; historicos en NULL; sin backfill). Aplicada en `lpz_saas` y `lpz_saas_testing`.

### 14. cantidad_proyectada

No se pide, no se elimina la columna, no es nullable: las ordenes V2 guardan 0.
`OrdenCorte::tieneProyeccion()` (suma de proyectado de detalles no adicionales > 0)
distingue historicas: la UI oculta «Solicitado», la columna Cantidad y «solicitado N»
cuando no hay proyeccion.

### 15-16. Warnings y errores

- Warnings (no bloquean): `configurable_inactivo_en_tienda`, `variante_inactiva_en_tienda`
  → la UI muestra «Inactivo en tienda — permitido para producción».
- Errores bloqueantes (mensaje comprensible con linea·color·talla y SKU en conflicto,
  nunca IDs): `sin_producto_para_color`, `color_ambiguo`, `ejes_no_soportados`,
  `talla_no_disponible`, `variante_duplicada`, `postcondicion_incumplida`; ademas
  color ajeno a los rollos, seleccion duplicada/vacia/incompleta y
  `ConfiguracionCatalogoCorteException` (atributo estructural ausente).

### 17-18. OrdenCorte.color y OrdenCorte.genero_linea (auditados)

Usos reales: `card-productos` (color por fila, ahora `detalle.color` con respaldo al de
la orden), `show` (tile Tela/Color), pivote/DataGrid no lo usan como fuente. Decision:
son **RESUMENES de lectura**, no fuente normativa. Orden V2 (y `create()` legacy):
`color` = colores distintos de los rollos («Negro, Rojo, Verde Cali», limitado a 250) —
antes era el color del primer rollo y mentia con multi-color; `genero_linea` = lineas de
los detalles unidas con «, » (limite 250). Fuente real: rollos (pivote) y
`OrdenCorteDetalle`. Historicas intactas.

### 19-22. UI, show, cortador y Bodega

- **Show:** tela y colores, rollos (color, gramaje, peso), productos por linea/color/talla
  con SKU; sin proyeccion para V2; historicas siguen igual. En V2 se oculta «Agregar
  producto» manual (los adicionales son F5).
- **Cortador (`card-resultado`):** una tarjeta por linea/color con filas «Color · Talla ·
  SKU» + cantidad real; no vuelve a escoger producto; adicionales sin cambios (F5).
- **Bodega (`card-recepcion`):** sigue por `orden_corte_detalle_id` (F0); ve linea, color,
  talla, SKU y cantidad reportada; no agrega ni cambia productos.
- `AgrupadorDetallesCorte` agrupa por linea + nombre (antes por product_id: en V2 daba
  una tarjeta por talla); conserva las claves originales para los formularios.
- CSS del paquete recompilado (`npm run build`) para las clases nuevas.

### 23-25. Interaccion con F3, PRE-F4 y PRE-F4.1

- **F3 intacto:** recepcion con `merma_unica`, sin reparto entre rollos/colores;
  verificado con 3 colores y 3 gramajes (merma 4.8 kg, peso_consumido_total 62.2, rollos
  agotados). `OrdenCorteRollo.peso_consumido` ≠ `OrdenCorte.peso_consumido_total`: no se
  asumio equivalencia.
- **PRE-F4:** crear reserva por los caminos existentes; los rollos quedan vinculados
  (Compra de Tela no puede modificarlos). Conservados `lockForUpdate` y validaciones.
- **PRE-F4.1:** antes de en_corte se agrega/retira (compatibilidad = mismo tipo de tela);
  desde en_corte no se agrega, retira ni anula (`antesDeEnCorte`/`haIniciadoCorte`).
- **Concurrencia:** los rollos se leen `FOR UPDATE` dentro de la transaccion, la segunda
  solicitud ve el rollo reservado y falla (verificado con consultas y llamada
  secuencial; no hay prueba con dos procesos reales).

### Compatibilidad historica

`OrdenCorteService::create()` conserva el contrato interno legacy (`detalles[]` con
`product_id`/`cantidad_proyectada`) para fixtures y llamadas internas, pero comparte la
validacion de rollos y la persistencia; el store de la pantalla usa
`crearDesdeSeleccion()`. Ordenes historicas: `color` del detalle NULL, con proyeccion,
visibles y funcionales.

### 26-27. Tests y resultado

- `tests/Feature/CreacionV2OrdenCorteF4Test.php`: **19 tests / 155 aserciones** que
  cubren los 42 casos pedidos (agrupados): un rollo, mismo color/colores distintos/gramaje/
  referencia, otra tela / sin tipo_tela_id / sin color_option_id, Hombre 4 detalles,
  Dama+Niño = 7, tres lineas = 11, simple exacto, proyectada/real/adicional, product_id
  manipulado (HTTP), color ajeno, duplicados, color ambiguo/talla inexistente/variante
  duplicada con rollback total, inactivos con warning, XXL, eje de Niño, una carga de
  catalogo, concurrencia, show V2, cortador, recepcion por detalle, pantalla+endpoints+ACL,
  historica visible, F3 multi-color/gramaje, PRE-F4/PRE-F4.1 y compatibilidad al agregar
  rollos. Mutaciones verificadas (revertidas): sin exigir mismo tipo de tela, sin validar
  colores, ignorar errores de resolucion, sin bloqueo de filas, proyectada ≠ 0.
- Validacion: F4 19 passed; F2+F3+PRE-F4+OrdenCorte/recepcion/confeccion+F1-D (9
  archivos) 229 passed; **suite completa 370 passed / 1976 assertions** (351 previos +
  19); `pint --dirty` passed; `packages/Webkul/`: 0 modificaciones.

### 28. Catalogo pendiente (NO corregido)

Los 5 configurables (`103DURZ-H`, `107DURZ-H`, `121DURZ-H`, `124DURZ-D`, `125DURZN-D`)
siguen sin tocarse: F4 los maneja bloqueando con `color_ambiguo` (con los SKU en
conflicto; p.ej. Hombre/Lila, Mujer/Rojo, Mujer/Marfil, Hombre/Beige Oscuro y
Verde Oliva). Hasta corregirlos esos colores no se pueden cortar en esas lineas.

### 29. Pendientes y hallazgos

- F5 (adicionales del cortador vía resolvedor) NO iniciada; `card-resultado` y
  `ordenes_corte.productos` conservan el buscador libre para adicionales.
- `agregarDetalle()` sigue existiendo en backend (solo oculto en UI para V2).
- Prueba de concurrencia real con dos procesos: no incluida.
- `VarianteConsultaService` sigue leyendo `product_flat.status` (hallazgo de F2).
- Vistas huerfanas con logica antigua siguen sin tocarse.
- **F5 NO iniciada.**


## PRE-F5 — Saneamiento de catálogo productivo

Estado: **EJECUTADO EN DOS PASOS** (PRE-F5 y PRE-F5.1, 2026-09-24). Solo DATOS de `lpz_saas`;
**sin cambios de codigo**, sin `packages/Webkul/`, sin migraciones. Tras el primer paso quedaba 1
error (Mujer/Rojo por `124DURZ-D`); **PRE-F5.1 lo cerro (ver al final): 0 errores estructurales.**
**F5 NO iniciada.**

### Diagnostico inicial (`corteconfeccion:diagnosticar-catalogo --json`)

77 configurables / 300 simples: **8 errores** (5 `color_ambiguo` + 3 `configurable_multicolor`)
y 5 advertencias (110DURZ-H inactivo + desalineado EAV/flat; 3 simples de 109DURZ-H
inactivos). Coincide con F2.

### Evidencia usada por SKU (BD real, no se asumio nada)

Para cada configurable: hijos, color por hijo, status EAV/flat, stock (`product_inventories` /
`_indices`), `order_items`, `cart_items`, `OrdenCorteDetalle`, movimientos, confeccion,
otros configurables de la familia con el mismo color y existencia de la opcion de color
(`attribute_options` del atributo `color`, siempre por ID).

| SKU | Hallazgo | Evidencia de intencion | Decision |
|---|---|---|---|
| `103DURZ-H` | padre «Café Hombre»; S=Beige Oscuro, M/L/XL=Lila | nombre/url_key Café; pedido real «Camiseta Piel Durazno **Cafe** Hombre S» (item 95); opcion Cafe (27) existe y solo la usa `103DURZ-DAMA` (Mujer); Beige Oscuro y Lila ya tienen su propio configurable (`102DURZ-H`, `201DURZ-H`) | **A**: los 4 hijos → Cafe (27) |
| `107DURZ-H` | padre Azul Oscuro; S/M/L=Azul Oscuro, XL=Lila | pedidos reales «… Azul Oscuro Hombre XL» (items 19, 77); Lila ya existe completo en `201DURZ-H` | **A**: hijo XL → Azul Oscuro (23) |
| `121DURZ-H` | padre «Verde Manzana»; 4 hijos Verde Oliva | opcion Verde Manzana (55) existe y la usa `121DURZ-D` (Mujer); Verde Oliva Hombre ya es `120DURZN-H`; sin pedidos | **A**: los 4 hijos → Verde Manzana (55) |
| `125DURZN-D` | padre «Verde Menta Mujer»; S=Marfil, M/L/XL=Verde Menta | pedido real «… Verde Menta Mujer M»; Marfil Mujer ya es `117DURZ-DAMA`; S sin stock ni pedidos | **A**: hijo S → Verde Menta (52) |
| `124DURZ-D` | padre «Terracota Mujer»; 4 hijos Rojo | pedidos reales «… Terracota Mujer M/L» (2 items); **no existe la opcion Terracota** en el atributo color; `110DURZ-DAMA` ya es «Rojo Mujer» | **E: NO TOCAR**. Requiere decision humana (crear el color Terracota o elegir uno existente, o reorganizar). No se creo ningun color ni se cambio nada |
| `110DURZ-H` | padre EAV `status = 0`, `product_flat.status = 1`; los 4 hijos activos | producto muy usado (8 pedidos, 8 carritos, 3 detalles de orden de corte, stock 116-140); `products.updated_at` == `flat.updated_at` (2025-10-13) → el flat no esta «viejo» respecto de la ultima edicion; no hay evidencia de si el 0 fue deliberado | **E: NO TOCAR** (decision de negocio: activar o desactivar en tienda). Nota: `indexer:index` solo reindexa precio e inventario, NO el flat; el flat se regenera al guardar el producto por el flujo estandar de Bagisto |

### Cambios de datos aplicados (10 simples; mismos `product_id`)

Por `ProductRepository::update(['color' => id], $id, ['color'])` + eventos
`catalog.product.update.before/after` (repositorio oficial; regenera `product_flat` e
indices; cola `sync`; sin SQL de escritura). Respaldo previo del EAV/flat/stock (scratch).

- `111,112,113,114` (`103DURZ-H-S/M/L/XL`): 25/45/45/45 → **27 Cafe**
- `134` (`107DURZ-H-XL`): 45 Lila → **23 Azul Oscuro**
- `201,202,203,204` (`121DURZ-H-S/M/L/XL`): 21 Verde Oliva → **55 Verde Manzana**
- `381` (`125DURZ-D-S`): 56 Marfil → **52 Verde Menta**

Verificado por producto: color nuevo, sin filas duplicadas, `sku`/`parent_id`/status
iguales, stock e indice de inventario identicos (27, 14, 0, 21, 10, 8, 0, 1, 4, 0).

### NO cambiado

Padres (nombre, SKU, url_key), SKUs de hijos (p.ej. `125DURZ-D-*` vs padre `125DURZN-D`,
inconsistencia de nomenclatura no corregida), stock, pedidos, carritos, historial de
ordenes de corte, `124DURZ-D`, `110DURZ-H`, colores del catalogo (ninguno creado), codigo.

### Diagnostico final

**1 error, 5 advertencias** (antes 8/5): queda `color_ambiguo` Mujer/Rojo
(`110DURZ-DAMA` + `124DURZ-D`). Desaparecieron los 4 `color_ambiguo` corregidos (Hombre
Beige Oscuro, Verde Oliva, Lila; Mujer Marfil) y los 3 `configurable_multicolor`. Sin
errores nuevos (ni `variante_duplicada` ni `talla_no_disponible`). Advertencias sin cambio:
110DURZ-H (inactivo en tienda + status EAV≠flat) y los 3 simples de 109DURZ-H inactivos.

### Pruebas

- Resolvedor (real): Hombre Cafe/Azul Oscuro/Verde Manzana/Verde Oliva/Beige Oscuro/Lila
  y Mujer Verde Menta/Marfil resuelven S/M/L/XL a su configurable correcto; Nino solo Rojo
  (`sin_producto_para_color` para los demas: correcto); **Mujer/Rojo → `color_ambiguo`
  (110DURZ-DAMA, 124DURZ-D)**.
- F4 (simulacion en transaccion con rollback sobre `lpz_saas`, sin dejar datos): con rollos
  Cafe/Verde Manzana/Azul Oscuro/Verde Menta/Rojo se creo una orden de 7 detalles
  (103DURZ-H-S, 103DURZ-H-XL, 121DURZ-H-M, 107DURZ-H-XL, 125DURZ-D-S, 125DURZN-D-M,
  110DURZ-H-M; proyectada 0; color de la orden «Cafe, Verde Manzana, Azul Oscuro, Verde
  Menta, Rojo»). Mujer/Rojo se bloqueo con mensaje y SKU en conflicto, sin reservas.
- Suite del modulo: 370 passed / 1976 assertions (sin cambios de codigo). Pint: no aplica.
  `packages/Webkul/`: 0 modificaciones.

### Riesgos / pendientes

- El negocio debe decidir `124DURZ-D` (color Terracota inexistente) para eliminar el ultimo
  error; hasta entonces Mujer/Rojo no se puede cortar.
- Decidir el estado real de `110DURZ-H` en tienda (advertencia, no bloquea produccion).
- Los cambios de color son de datos reales: los pedidos historicos conservan sus nombres
  (ya decian el color correcto). Revisar visualmente fotos/fichas de los 3 productos
  corregidos en la tienda es recomendable.
- (Estado tras el primer paso; superado por PRE-F5.1.) F5 NO iniciada.

### PRE-F5.1 — Ultimo error estructural cerrado (2026-09-24)

Decision de negocio aprobada: `124DURZ-D` es CAMISETA MUJER TERRACOTA. Solo datos, sin cambios de
codigo, sin `packages/Webkul/`, sin migraciones.

- **Opcion creada** (repositorio oficial `AttributeOptionRepository`): atributo `color`
  (`code = color`), `admin_name`/label es «Terracota», **`attribute_options.id = 4441`**,
  `sort_order = 36`, swatch `#e2725b` (hex estandar de terracota; el atributo usa swatch de color,
  valor cosmetico). Aviso: el primer intento creo la opcion y fallo al insertar la traduccion (asignacion
  masiva); se reutilizo esa unica opcion y se completo la traduccion (no hay duplicados: 37 opciones de color).
- **4 hijos vinculados** (mismo `product_id`, repositorio oficial + eventos `catalog.product.update.*`):
  411 `124DURZ-D-S`, 412 `-M`, 413 `-L`, 414 `-XL`: color 1 (Rojo) → 4441 (Terracota).
- **Preservado** (verificado antes/despues): SKU, `parent_id` (410), status, stock (0/15/22/7), indice de
  inventario, pedidos (S 0, M 1, L 1, XL 0), nombre del padre. Respaldo previo del EAV/flat/stock/indices en scratch.
- **Diagnostico**: antes 1 error / 5 advertencias → **despues 0 errores / 5 advertencias** (desaparece
  `color_ambiguo` Mujer/Rojo). Advertencias sin cambio: 110DURZ-H (inactivo en tienda + EAV≠flat) y 3
  simples inactivos de 109DURZ-H. Ningun error nuevo.
- **Resolvedor**: Mujer + Terracota + S/M/L/XL → solo `124DURZ-D` y sus 4 simples; Mujer + Rojo +
  S/M/L/XL → solo `110DURZ-DAMA` (sin `color_ambiguo`).
- **F4 (simulacion en transaccion con rollback)**: rollos Terracota y Rojo → orden con 5 detalles
  (124DURZ-D-S/M/L/XL + 110DURZ-DAMA-M), `cantidad_proyectada = 0`, `cantidad_real_cortada = 0`,
  2 rollos reservados; tras el rollback ordenes 8→8, rollos 28→28, detalles 19→19.
- **110DURZ-H NO se toco**: EAV status 0 vs flat 1 queda como decision comercial independiente (no bloquea).
- Suite del modulo: 370 passed / 1976 assertions. `packages/Webkul/`: 0 modificaciones.

**PRE-F5 COMPLETADO. CATALOGO ESTRUCTURAL LISTO PARA CORTE V2** (0 errores estructurales).
110DURZ-H pendiente solo de decision comercial. **F5 NO INICIADA.**


## F5 — Adicionales del cortador por resolvedor

Estado: **F5 COMPLETADA TECNICAMENTE** (2026-09-25). Sin cambios en `packages/Webkul/`,
catalogo Bagisto, F4 (crear), F3, PRE-F4/PRE-F4.1, inventario ni reglas de rollos. No se
creo LineaCorte. Se detiene aqui.

### 1. Flujo

En `en_corte` el cortador registra `cantidad_real_cortada` de los detalles solicitados y,
si aprovecho tela, pulsa «+ Agregar adicional»: linea → color → talla (eje dinamico) →
cantidad. Se muestra una vista previa (linea, color, talla, SKU, cantidad y
advertencias). Al «Reportar corte terminado», el servidor resuelve el simple exacto y crea
un `OrdenCorteDetalle` nuevo por adicional. Se quito el buscador libre de productos.

### 2. Payload

`adicionales: [{attribute_family_id, color_option_id, eje_option_id, cantidad_real_cortada, filtros:{}}]`.
`product_id`, SKU, talla y linea en texto libre NO estan en las reglas: si el navegador los
envia, `validated()` no los devuelve (verificado por HTTP).

### 3. Colores permitidos

Solo `Rollo.color_option_id` de los rollos de la orden (`OrdenCorteService::rollosDeOrden()`);
la tela sale de esos rollos, no del navegador. Un color del catalogo ajeno a los rollos se
rechaza («El color no pertenece a los rollos seleccionados»).

### 4. Linea y eje

Linea = `AttributeFamily` (`lineasPorTipoTela()`); eje 2 dinamico (size, grupo de edad
ninos...) por `opcionesEjeLote()`. Nada hardcodeado.

### 5. resolverLote

Se reutiliza `SeleccionOrdenCorteService::resolver()` (misma semantica de F4: colores
ajenos, duplicados, `resolverLote`). No se duplicaron consultas EAV. Una sola carga de
catalogo para N adicionales (test: mismas consultas con 1 o 6). Se agrego la clave
`indice` a las filas resueltas para casar cada simple con su cantidad.

### 6. Cantidad

Requerida, entera, > 0 (FormRequest y de nuevo en el servicio). Ya no se descartan filas
con cantidad 0 en silencio: se rechazan con mensaje.

### 7. Snapshots

Como F4, desde `ResolucionVarianteCorte`: `product_id` = simple exacto (nunca el
configurable), `producto_sku`, `producto_nombre` (configurable), `genero_linea` (familia
normalizada), `color`, `talla`; `cantidad_proyectada = 0`, `es_adicional = true`,
`cantidad_real_cortada` = cantidad reportada, `variant_id` NULL.

### 8. Relacion con el solicitado

Si el adicional cae en el mismo `product_id` que un solicitado, quedan DOS detalles
separados; nunca se fusionan ni se suma a la cantidad solicitada.

### 9. Identidad separada

Duplicado en el mismo envio (misma linea+color+talla): **se rechaza** («La combinacion esta
repetida»), es la decision mas simple y evita ambiguedad. Si el cortador cortó mas, escribe
una sola cantidad.

### 10. Recepcion

Sigue por `orden_corte_detalle_id` (F0): Bodega ve la fila solicitada y la adicional por
separado; no agrega productos.

### 11. Confeccion

`DisponibilidadConfeccionService` (F0.1) agrega por `product_id`: 20 solicitadas + 6
adicionales = 26 (test). Sin cambios.

### 12. Atomicidad

`reportarResultado()` valida estado y permiso, resuelve TODOS los adicionales
(`resolverAdicionales()`) ANTES de escribir y solo entonces actualiza cantidades, crea
detalles + auditoria y cambia el estado, todo en una transaccion. Ante cualquier error:
cantidades existentes, adicionales, auditoria y estado quedan intactos.

### 13. Warnings

`configurable_inactivo_en_tienda` / `variante_inactiva_en_tienda` no bloquean: la vista
previa muestra «Inactivo en tienda — permitido para produccion».

### 14. Errores bloqueantes

`sin_producto_para_color`, `color_ambiguo`, `ejes_no_soportados`, `talla_no_disponible`,
`variante_duplicada`, `postcondicion_incumplida`, `ConfiguracionCatalogoCorteException`,
color ajeno, duplicado, cantidad invalida. Mensajes con linea·color·talla y SKU en
conflicto, sin IDs.

### 15. UI y endpoints

`card-resultado`: componente `v-resultado-corte-form` con selects encadenados, vista previa,
varios adicionales y badge «ADICIONAL» (tambien en la vista de solo lectura, con color y
SKU). IDs solo como campos ocultos. Endpoints (ACL `operar`, solo lectura):
`GET ordenes-corte/view/{id}/adicionales/opciones` y
`POST ordenes-corte/view/{id}/adicionales/previsualizar`; solo responden si la orden aun
puede reportarse. CSS del paquete recompilado.

### 16. Tests

`tests/Feature/AdicionalesCortadorF5Test.php`: 14 tests / 95 aserciones que cubren los 35
casos agrupados (resolucion, eje de Nino, colores permitidos/ajenos, product_id manipulado,
cantidad 0/negativa, inactivos con warning, rollback total por color ambiguo / talla
inexistente / variante duplicada / sin producto, varios adicionales, multi-color/linea,
mismo product_id separado, duplicados, una carga de catalogo, estados, recepcion,
confeccion 20+6, historicas, UI sin buscador, endpoints/ACL). Mutaciones verificadas y
revertidas: ignorar errores del resolvedor (3 fallos) y aceptar cantidad <= 0 (1 fallo).
Tests legacy retirados de `OrdenCorteResultadoYRecepcionTest.php` (usaban
`adicionales[].product_id`, contrato eliminado): «agrega un producto adicional no
solicitado» y «CASO 2 y 9 mismo product_id»; su cobertura equivalente esta en F5.

### 17. Suite final

Suite completa del modulo: **382 passed / 2057 assertions** (370 - 2 retirados + 14).
`pint` passed. `packages/Webkul/`: 0 modificaciones.

### 18. Pendientes

- Ordenes historicas cuyos rollos carecen de `tipo_tela_id`/`color_option_id` no pueden
  agregar adicionales estructurados (mensaje claro, sin cambios); reportar sin adicionales
  funciona.
- `agregarDetalle()` y `ordenes_corte.productos` siguen en el backend (no se tocaron).
- No hay observacion por adicional (el campo libre se retiro con el contrato legacy).
- Sin prueba E2E de navegador de los selects encadenados (solo render + endpoints).
