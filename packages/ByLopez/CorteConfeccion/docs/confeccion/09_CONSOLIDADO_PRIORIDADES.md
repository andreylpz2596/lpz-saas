# 09 — Consolidado general y prioridad por cantidad

Estado: **IMPLEMENTADO EN DESARROLLO Y PRUEBAS (2026-11-02; sugerencia por stock y regla P1 aprobada el 2026-11-03). Sin commit, push ni despliegue.** Pendiente: configurar mínimos reales, asignar permisos en Roles y prueba en iPhone físico (§16).

## 1. Qué se hizo

- **Dos pestañas** en «Órdenes de confección»: *Órdenes de confección* (listado actual, sin cambios) y *Consolidado general* (nueva).
- **Consolidado**: por taller + variante real (`product_id`) + tela del corte. Muestra referencia (familia), tela (si los rollos del corte tienen una sola), color, talla y SKU. Cada fila despliega sus órdenes de origen: orden y corte, por confeccionar, pendiente de recibir (con fecha del reporte), reparto por prioridad, en tránsito, fecha de entrega al taller y antigüedad, enlace a la orden e historial de prioridad.
- **Filtros**: texto libre (todas las palabras, sin tildes: «camiseta negra»), taller (solo administración), referencia, color, tallas (varias a la vez, p. ej. 14 y 16) y prioridad (urgente / alta / urgente o alta / normal). Los indicadores se calculan sobre **todo** lo filtrado, no sobre la página.
- **Prioridad por cantidad**: desde el detalle, «Cambiar prioridad» de una cantidad concreta (orden, variante, disponible por nivel, origen, nueva, nota opcional, resumen «Quedarán: …»). No edita la orden.
- **Alertas en la campana y el listado nativos de Bagisto**, solo al usuario vinculado al taller.
- La ficha de la orden muestra el reparto real por prioridad (antes mostraba solo la prioridad de asignación).

### Interfaz (rediseño 2026-11-03)

- **Escritorio (≥1024 px)**: variantes en filas alineadas con encabezado de columnas: producto/variante, taller (columna desde 1280 px; debajo del nombre entre 1024 y 1279 px), por confeccionar, por recibir, prioridad y órdenes. El nombre se recorta en una línea con el texto completo en `title`. Las órdenes de origen se despliegan en una sub-tabla alineada.
- **Celular**: tarjetas con las tres cifras destacadas y el botón «Cambiar prioridad» a todo el ancho. Los filtros se pliegan bajo un botón «Filtros» que muestra cuántos hay activos.
- **Indicadores**: 4 columnas desde 1280 px, 2 desde 420 px y 1 en pantallas menores. Cada uno con una ayuda breve; la distinción por confeccionar / tránsito / pendiente de recibir se conserva.
- **Modal «Cambiar prioridad»**: reutiliza `x-admin::modal` de Bagisto, la misma del detalle de movimientos de inventario (fondo, panel centrado, scroll interno y bloqueo del scroll del cuerpo). El componente `v-cc-prioridad` agrega Escape, foco atrapado, foco devuelto al botón que la abrió, restauración del scroll, errores junto al campo, vista previa («50 pasarán a Alta · 30 seguirán en Normal») y botón deshabilitado mientras guarda. Si falla, conserva lo escrito y actualiza los disponibles.
- **Al guardar**: la modal se cierra y se reemplazan **solo** los indicadores y las filas (`#cc-resultados`), sin recargar la página. Se conservan filtros, posición y variantes desplegadas. La confirmación queda dentro de la fila, porque el aviso flotante de Bagisto dura 2 s y «nadie recibió la alerta» no debe perderse.
- Los estilos son solo de esta pantalla (`#cc-consolidado`); no hay cambios globales.
- **Causa del `@endif` visible**: `terminadas@if (...)`. Blade no compila una directiva pegada a una palabra, así que quedaba un `@endif` huérfano que cerraba el bloque equivocado. Por eso aparecía el texto de la alerta sin haber abierto ninguna. Está corregido, con prueba de regresión (P14).

## 2. Reglas de cantidades (reutilizadas, una sola definición por detalle)

| Concepto | Regla | Dónde |
|---|---|---|
| Responsabilidad vigente | entregado − reasignado a otro taller | `OrdenConfeccionDetalle::SQL_VIGENTE` |
| Por confeccionar | vigente − en tránsito − reportado (aún no reportado como terminado) | `pendientePorReportar()` |
| Pendiente de recibir | lo reportado en entregas que bodega aún no recibe | retornos `pendiente_bodega` |
| En tránsito | llegó por reasignación sin confirmar; aparte, no es por confeccionar | `cantidad_en_transito` |

- Nunca «asignado − buenas recibidas»: haría reaparecer defectuosas y faltantes ya resueltos.
- Los reportes se suman en una subconsulta por detalle **antes** de unir: varias entregas no duplican cantidades.
- Una orden terminada por el taller sigue apareciendo mientras bodega no reciba.
- Productos inactivos siguen visibles (no se filtra por estado del producto).
- No se usa «Listo para recoger»: el sistema no registra dónde están las prendas.
- La pantalla aclara que «Por confeccionar» no prueba que las prendas estén físicamente sin coser.

## 3. Modelo de prioridad

- En el detalle: `cantidad_urgente` y `cantidad_alta` (migración aditiva `2026_11_02_000002`). Normal = el resto. `prioridad` (texto) se conserva como la prioridad de asignación.
- Invariante: `urgente + alta ≤ vigente − reportado`. Lo que se muestra se calcula con `PrioridadOrdenConfeccion::repartir()` sobre lo **por confeccionar**, así que nunca supera el pendiente real (las unidades en tránsito no cuentan como prioritarias hasta confirmarse).
- **Relleno compatible**: cada detalle Urgente/Alta existente queda con todo su saldo actual en ese nivel; nada se convierte a Normal ni se pierde una Urgente. Órdenes anuladas en 0.
- Historial: `bylopez_cc_prioridad_operaciones` (quién, cuándo, nota, taller, si hubo destinatario) + `bylopez_cc_prioridad_cambios` (orden, detalle, cantidad, anterior, nueva). Inmutable.
- No se tocan asignaciones originales, inventario, tarifas ni cartera (prueba P02).

### Cambio seguro

Todo o nada, con bloqueo recepción del corte → órdenes → detalles (mismo orden que reasignar/reportar) e idempotencia por llave: el formulario genera una llave por apertura. Un doble clic o reintento devuelve la misma operación sin otra alerta. La misma llave con otros datos responde 409. Valida enteros estrictos positivos, saldo del nivel de origen y que la prioridad realmente cambie. Probado con procesos simultáneos reales (RCC-P1..P3).

## 4. Regla de consumo P1 — APROBADA (2026-11-03)

Sustituye la propuesta anterior («Normal primero»). Código: `PrioridadConfeccionService` (`repartoSugerido`, `resolverReparto`, `descontar`).

1. **Al reportar prendas terminadas** se **sugiere** descontar **Urgente → Alta → Normal** y se muestra compacto: «Descuenta: 20 Urgentes + 10 Altas (sugerido)». Con «Ajustar» el taller indica otro reparto si confeccionó otro nivel. El total debe ser igual a lo reportado y ningún nivel puede superar su saldo. El reparto confirmado se guarda en el reporte (`retorno_detalles.cantidad_urgente/cantidad_alta`) y se ve en el historial de la ficha.
   *Ejemplo*: 100 = Urgente 20 · Alta 30 · Normal 50. Reporta 40 sin ajustar → descuenta 20 Urgentes + 20 Altas y quedan Alta 10 · Normal 50.
2. **Reasignación**: el modal muestra la prioridad de lo elegible y lo que se traslada («Se trasladan: 100 Urgentes + 150 Normales (sugerido)»); con «Ajustar» se eligen las cantidades por nivel (deben sumar lo que se mueve). El destino nace con exactamente esas cantidades y la línea de reasignación las guarda. Lo que sigue **en tránsito** no cuenta como por confeccionar ni como prioritario hasta confirmarse (se muestra aparte).
3. **Reverso de reasignación**: devuelve al origen el reparto **registrado en la línea**, no las prioridades que el destino tenga después (si el administrador cambió la prioridad del destino, el reverso no la reconstruye). Validaciones actuales: nunca más prioritarias que el saldo del origen.
4. **Cambios simultáneos**: reporte y reasignación envían `base` (el saldo por nivel que vio la pantalla, «u:a:n»). Bajo el bloqueo de la orden, si cambió, se rechaza con «Las prioridades de X cambiaron… (ahora: …). Revisa el reparto» y el formulario **conserva lo escrito** (reporte: `old()` indexado por id de detalle; reasignación: el modal recarga el saldo y mantiene cantidades y reparto). Probado con procesos reales (RCC-P4).
5. **Compatibilidad**: columnas nuevas *nullable*. Los reportes y reasignaciones anteriores quedan con `null` («Reparto por prioridad no registrado (reporte anterior)»); **no se reinterpretan ni cambian sus cantidades**. El reverso de una reasignación antigua (sin reparto) usa lo que el destino pierde, como antes. Sin `urgente/alta/normal` en la petición, el servidor aplica el sugerido (clientes y pruebas anteriores siguen funcionando).
6. **Red de seguridad**: `recortar()` mantiene la invariante `urgente + alta ≤ saldo sin reportar`; con P1 no debería retirar nada, y si alguna vez hay exceso retira Urgente primero, luego Alta (mismo orden de consumo).
7. Anular una orden deja sus cantidades prioritarias en 0. No existe reverso de reportes en el módulo.

## 5. Notificaciones (sin modificar `packages/Webkul`)

Revisión de Bagisto 2.4.9: la tabla `notifications` exige un pedido (`order_id` NOT NULL con FK a `orders`), no tiene destinatario (es global) y la campana solo sabe pintar pedidos. Por eso:

- Los avisos viven en `bylopez_cc_notificaciones` (un destinatario, foto histórica del cambio, `leida_en`). Llave única destinatario + operación: un reintento no duplica la alerta. Se crean dentro de la misma transacción del cambio, así que una transacción fallida no deja aviso.
- **Campana**: evento oficial `bagisto.admin.layout.vue-app-mount.before` → `views/admin/notificaciones/campana.blade.php`. Extiende el **mismo** componente `v-notifications`: inserta los avisos en su lista, suma los no leídos a su contador e incluye los avisos en «marcar todo como leído». No copia la plantilla de Bagisto. Si una actualización cambia el punto de anclaje, no toca nada: la campana sigue igual y los avisos siguen en el listado.
- **Listado**: evento oficial `bagisto.admin.marketing.notifications.create.before` → sección «Corte y confección» encima del listado nativo.
- Al pulsar: se marca leída (no significa que el trabajo comenzó) y abre el Consolidado filtrado por taller y variante, con la orden resaltada y desplegada. Un recuadro separa **lo que cambió** (histórico) de **lo que hay hoy**: si las prendas ya se reportaron o se reasignaron, lo explica sin mostrar pendientes antiguos.
- Contenido: quién cambió, orden, variante, cantidad, prioridad anterior y nueva, fecha y nota. Una operación = un aviso, aunque incluya varios cambios.
- **Sin usuario vinculado** (o usuario inactivo): el cambio queda registrado como `sin_destinatario`, no se crea aviso y el administrador ve: «nadie recibió la alerta. Avísale por otro medio». El historial lo indica.
- **Archivos de `packages/Webkul` modificados: ninguno.**

## 6. Seguridad

- `ConsolidadoConfeccionGate`: el alcance depende del **vínculo** `talleres.usuario_id`, no del nombre del rol. Un usuario vinculado ve solo sus talleres aunque su rol se llame distinto o tenga `permission_type=all`, y nunca cambia prioridades. Un rol de prestador sin vínculo no ve nada. El personal ve todo con `corte_confeccion.ordenes_confeccion`.
- Cambiar prioridad: llave nueva `corte_confeccion.ordenes_confeccion.prioridad` (**asignarla en Roles** a quien corresponda; los roles `all` ya la tienen).
- Listado, totales, detalles, cambios y avisos se validan en servidor. Un filtro de taller ajeno no amplía el alcance. Los avisos solo los ve y abre su destinatario (404 para los demás).
- Además se cerró el mismo hueco en el **listado y la ficha de órdenes existentes**: un usuario vinculado a un taller queda aislado aunque su rol no se llame «TALLER CONFECCION» (`OrdenConfeccionGate`).
- Las rutas nuevas están en la ACL: el middleware de Bagisto 2.4.9 rechaza rutas sin entrada.
- Las notas y nombres se muestran escapados y con `v-pre` para que Vue no los interprete.

## 7. Pruebas

- `tests/Feature/ConsolidadoPrioridadesTest.php` (P01–P15): creación, ejemplo 80 → 50 Alta / 30 Normal, validaciones, idempotencia, consumo al reportar, reasignación y reverso, tránsito, no duplicar con varias entregas ni revivir defectuosas, terminada pendiente de recibir, filtros e indicadores sobre todo lo filtrado, producto inactivo, sin destinatario, aislamiento por vínculo, permisos, apertura de la alerta con estado actual, campana y listado nativos.
- `tests/Concurrencia/ReasignacionConcurrenteTest.php` RCC-P1..P3 con procesos PHP reales.

## 8. Pendientes (histórico 2026-11-02; ver §16 para el estado actual)

- ~~P1~~: aprobada el 2026-11-03 (§4).
- ~~Debugbar tapa la modal~~: corregido (§13).
- ~~Medir tiempos con miles de pendientes~~: medido (§12).
- Recorrido de navegador reproducible: `tests/Demo/demo_prioridades.php` (preparar / stock / cambiar / estado / restaurar, solo bases `lpz_saas_demo_*`) + `tests/Demo/recorrido_consolidado.cjs` y `tests/Demo/recorrido_sugerencia.cjs`.
- La campana de Bagisto muestra pedidos de venta a cualquier usuario del panel, incluidos los talleres. Es un comportamiento existente de Bagisto; no se cambió.

## 9. Sugerencia por stock (2026-11-03)

Dos prioridades visibles, separadas, en **distribución**, **ficha de la orden** y **consolidado** (administración y taller):

| Columna | Qué es | Quién la cambia |
|---|---|---|
| **Prioridad asignada** | Normal / Alta / Urgente **con sus cantidades** («Urgente 20 · Alta 30 · Normal 50»). Nunca una sola etiqueta si la línea mezcla niveles. | Solo el administrador (asignación o «Cambiar prioridad»). |
| **Sugerencia por stock** | Nivel sugerido + motivo breve («Urgente · Sin disponible»). | Nadie: se calcula. **Nunca** modifica prioridades, cantidades, órdenes ni notificaciones. |

La prioridad asignada **manda**. Entre trabajos con la misma prioridad asignada, la lista se ordena por la sugerencia (Urgente, Alta, Normal, sin mínimo, sin información) y luego por antigüedad, para que el taller sepa qué atender primero.

### Reglas V1 (aprobadas; por disponibilidad, no es un pronóstico de ventas)

| Disponible | Mínimo | Sugerencia | Motivo |
|---|---|---|---|
| 0 | cualquiera / ninguno | Urgente | Sin disponible |
| negativo | cualquiera / ninguno | Urgente | Disponible negativo |
| > 0 y < mínimo | definido | Alta | Bajo el mínimo |
| ≥ mínimo (incluido mínimo 0) | definido | Normal | Cubre el mínimo |
| > 0 | **sin mínimo** | «Sin mínimo configurado» | — |
| sin índice / consulta fallida | — | «Sin información» | **Nunca se asume cero** |

Mínimo ausente ≠ mínimo 0: sin fila = «Sin mínimo configurado»; 0 es un mínimo explícito (con disponible positivo → Normal).

### Fuente del disponible (alcance del inventario usado)

- `VarianteConsultaService::stockVendible()` — la misma lectura que ya usaba la pantalla de distribución (Doc. 04A): **`product_inventory_indices.qty` del canal por defecto** (`core()->getDefaultChannel()`, determinista; nunca el canal del host). Es el índice de Bagisto = existencias de las **fuentes (bodegas) activas** del canal **menos las reservas** de pedidos (`product_ordered_inventories`). Por eso **no se vuelven a restar reservas** (prueba S03). Mismo número que ve Nueva venta (`totalQuantity()`).
- Sin fila en el índice (variante sin indexar, configurables) → «Sin información».
- El ajuste F5 de VentasAsesor (`CercoInventario::pendienteSinReserva`, pedidos anteriores a F5 con la variante del configurable en cantidad 0 y aún sin despachar) **no** entra: Nueva venta tampoco lo muestra (solo lo descuenta el cerco al confirmar). Comparación por variante en §9.1.

### 9.1 Comparación Nueva venta ↔ sugerencia (2026-11-03)

Herramienta de **solo lectura** `tests/Demo/comparar_disponible.php` (corre en cualquier base, también producción: `DB_DATABASE=… php comparar_disponible.php`). Para cada variante simple compara Nueva venta (`totalQuantity()`), la sugerencia (`stockVendible()`), la fórmula del indexador (fuentes activas − reservas) y el cerco (indexador − pendiente F5), y lista los pedidos F5 afectados.

| Base | Variantes | Pedidos F5 con pendiente | Nueva venta ≠ sugerencia | Índice desactualizado | Sin índice |
|---|---|---|---|---|---|
| dev `lpz_saas` | 308 | **0** (218 líneas antiguas en cantidad 0, todas despachadas, canceladas o sin pendiente) | **0** | 0 | 3 |
| copia demo `lpz_saas_demo_cc_val_20261103` | 355 | **0** | **0** | 4 | 39 |

- Nueva venta y la sugerencia leen la **misma** fuente (índice del canal): coinciden en todas las variantes; no se cambió el cálculo ni se resta nada dos veces.
- Si en producción aparecen pedidos F5 con pendiente, el cerco ya los descuenta al confirmar, pero ni Nueva venta ni la sugerencia los muestran; la forma coherente sería que ambas usen una misma lectura compartida (decisión de Ventas Asesor, no de este módulo). Correr la herramienta antes de activar.
- Hallazgo ajeno: en la demo 4 variantes (116DURZ-H-S/M, 116-DURZ-H-XL, 124DURZ-H-M) tienen el índice por **debajo** de físico − reservas (índices del 28-oct, anteriores a este trabajo). Nueva venta y la sugerencia muestran lo mismo (el índice); solo el cerco permitiría más. Se arregla reindexando inventario; no se tocó.
- Resultados: `evidencia_2026-11-03_sugerencia/comparacion_disponible_*.json`.
- Variantes sin `manage_stock` también tienen índice; la sugerencia se calcula igual.
- Global por variante: si la variante está en varios talleres la sugerencia **se repite** en cada uno; no es una necesidad adicional por taller ni autoriza aumentar cantidades (la pantalla lo dice).
- Se calcula **en cada consulta** (sin caché) y muestra «calculada a las HH:MM:SS». Tras guardar una prioridad la parte de resultados se vuelve a pedir, así que también se recalcula. **No hay alertas** por cambios de stock.

### Prendas terminadas pendientes de recibir

- Se cruzan las cantidades de la misma variante en reportes `pendiente_bodega`. Si la sugerencia es Urgente o Alta y hay pendientes: «**Priorizar recepción · 100 prendas pendientes**». No se suman al disponible ni se consideran recibidas; no se afirma que estén listas para recoger (no hay información de ubicación).
- Administración: total global con enlace al detalle (consolidado filtrado por la variante, con las órdenes y su «Por recibir»).
- Taller: solo sus propias cantidades; sin enlace global.

### Qué ve cada rol

- **Administración** (`alcance = todos`): nivel, motivo y cifras («Disponible 4 de mínimo 10»), y «Cambiar prioridad» desde la sugerencia: con una sola orden abre la modal existente; con varias, despliega la variante y enfoca el primer botón. La modal muestra «Sugerencia: …» como referencia y **no copia el nivel** sugerido. La cantidad arranca con **el total de la prioridad de origen** (decisión de Camilo, 2026-11-04) y se puede bajar (p. ej. solo 30 a Urgente); al cambiar el origen se actualiza a su total salvo que ya se haya escrito otra cantidad. Al enfocar el campo se selecciona el número para reescribirlo.
- **Taller vinculado** (aunque su rol se llame distinto o tenga `permission_type=all`): nivel y motivo, **sin cifras globales** de inventario (decidido en servidor: `ConsolidadoConfeccionGate::puedeVerCifrasStock()`; el HTML no las contiene, prueba S07).
- Filtro nuevo «Sugerencia por stock»: Urgente, Alta, Normal, **Sin mínimo configurado** y **Sin información de inventario** (distintos). Los indicadores se calculan sobre todo lo filtrado.

## 10. Mínimos de stock por variante (configuración nueva)

- No existía una configuración con el mismo significado (`catalog.inventory.stock_options.out_of_stock_threshold` de Bagisto es global y define «agotado», no un mínimo por variante). Se creó `bylopez_cc_stock_minimos` (una fila por `product_id` simple) + historial `bylopez_cc_stock_minimo_cambios` (anterior/nuevo, quién, cuándo).
- Pantalla **Corte y Confección → Mínimos de stock** (`/admin/corte-confeccion/stock-minimos`): busca por SKU o nombre, «Variantes en confección» (por defecto) o «Todas»; muestra disponible, mínimo y sugerencia. Vacío = sin mínimo; 0 = mínimo cero. Solo se envían las filas cambiadas; si otra persona cambió el mismo mínimo mientras se editaba, **no se guarda nada** y se muestra el valor vigente (sin pisar su cambio). No acepta configurables, decimales ni negativos.
- **Ningún mínimo se creó ni se asignó masivamente**: hay que configurarlos. Sin mínimos la sugerencia **ya funciona para el stock agotado**: disponible 0 o negativo sale Urgente aunque no haya mínimo (prueba S01 «cero sin mínimo»). En dev hoy, con 0 mínimos: 46 Urgente, 259 «Sin mínimo configurado» (disponible positivo) y 3 «Sin información»; Alta y Normal solo aparecen cuando hay mínimos.

## 11. Permisos

| Llave (Roles) | Permite | Notas |
|---|---|---|
| `corte_confeccion.ordenes_confeccion` | Ver consolidado, ficha (con sugerencia) | Taller vinculado: solo sus talleres, sin cifras |
| `corte_confeccion.ordenes_confeccion.prioridad` | Cambiar prioridad (también desde la sugerencia) | Nunca a usuarios vinculados a taller |
| `corte_confeccion.ordenes_confeccion.reportar_taller` | Reportar con reparto P1 | Ya existía |
| `corte_confeccion.ordenes_confeccion.reasignar` | Reasignar con reparto P1 | Ya existía |
| **`corte_confeccion.stock_minimos`** (nueva) | Consultar mínimos | Solo personal (no vinculado a taller) |
| **`corte_confeccion.stock_minimos.editar`** (nueva) | Configurar mínimos | Requiere también la anterior |

Los roles `permission_type=all` ya las tienen. Un usuario vinculado a un taller recibe 403 aunque su rol tenga todo (S11). No se otorgó ningún privilegio a roles existentes.

## 12. Rendimiento con volumen (medido 2026-11-03)

Entorno: Windows + XAMPP, PHP 8.3.33 CLI **sin opcache**, MariaDB 10.4.28; base aislada `lpz_saas_cc_val_20261103` con datos sintéticos (`tests/Demo/medir_consolidado.php sembrar 1000 40 500 10`): **1.000 variantes, 40 talleres, 500 órdenes, 5.000 detalles pendientes (330.462 prendas por confeccionar, 4.719 grupos taller+variante), 100 reportes pendientes de bodega**, stock entre −5 y 60, 10 % sin índice y 1/3 sin mínimo. Otra sesión ejecutaba Pest en la misma instancia de MariaDB durante la medición (los tiempos de SQL varían por esa carga).

| Escenario | Consultas | Tiempo |
|---|---|---|
| Servicio sin filtros (admin) | 9 | 1,15–1,4 s (antes de optimizar: 5,8 s) |
| Servicio filtro taller / sugerencia / texto | 9 | 0,7–1,1 s |
| Servicio con alcance de un taller | 9 | 0,1–0,2 s |
| Página completa (HTTP, página 1 / 20 / filtros) | 29–30 | 1,5–3,1 s (≈ 460 KB con el layout de Bagisto) |

- **Sin consultas por fila**: el número de consultas no depende del volumen (sugerencias, mínimos, pendientes de recibir y variantes se leen en lote).
- Coherencia comprobada: indicador = suma de filas = suma de grupos; las páginas cubren exactamente las variantes; la suma de los cinco filtros de sugerencia = total sin filtro (`evidencia_2026-11-03_sugerencia/medicion_volumen.json`).
- Optimización aplicada (sin cambiar resultados): fechas memorizadas por valor, grupos armados en un solo recorrido y orden con una clave precalculada.
- Límite conocido: el filtrado sigue en PHP sobre todos los detalles con saldo visibles para el usuario; para un taller es rápido, para administración con decenas de miles de detalles pendientes convendría pasar filtros a SQL.

## 13. Debugbar (desarrollo)

Causa: la barra de Laravel Debugbar se pinta con `z-index: 10000000000` y tapaba el pie de las modales (`x-admin::modal` usa `z-[10002]`) y los últimos botones en celular. Solución **localizada**: `views/admin/partials/debugbar-local.blade.php`, incluido solo en consolidado, ficha, distribución y mínimos. Con `APP_DEBUG` la baja a `z-index: 9000` y deja 7 rem de espacio inferior a la página (en WebKit/iPhone la barra minimizada mide ~97 px; con 3 rem el botón final de una página corta quedaba debajo — lo detectó el recorrido); sin `APP_DEBUG` no emite nada. No se tocó el layout de Bagisto ni la configuración de Debugbar. Verificado en navegador: «Guardar prioridad», «Guardar cambios» y «Confirmar entrega» quedan encima de la barra (S13 y recorrido).

## 14. Prueba omitida del módulo

`CarteraLecturaFormatosTest` → «PDF con texto: se lee su texto sin OCR…» se omite porque falta **poppler (`pdftotext`)** en este equipo (`where pdftotext` falla); Tesseract sí está. Es de la lectura de comprobantes de Cartera (no relacionada con este trabajo). Para ejecutarla hay que instalar poppler (por ejemplo `winget install oschwartz10612.Poppler`) y volver a correr el archivo; no se instaló software en el equipo sin autorización. Las demás condiciones de omisión (Node, Tesseract, pdo_mysql) se cumplen y esas pruebas sí corren.

## 15. Migración de Ventas Asesor aplicada por accidente (2026-11-02)

- Qué pasó: al correr `php artisan migrate` (sin `--path`) para las migraciones de este documento en **dev (`lpz_saas`)** y en la **demo `lpz_saas_demo_cc_20261029`** se aplicó también `VentasAsesor/…/2026_11_02_000001_create_bylopez_va_comision_soportes_and_trace_columns`, que estaba pendiente.
- Impacto evaluado: es **aditiva** (crea `bylopez_va_comision_soportes` y agrega columnas *nullable* a `bylopez_va_comision_abonos`), no transforma datos y tiene `down()`. En ambas bases: 0 soportes y 0 abonos con las columnas nuevas usadas. El código que la usa ya está confirmado en git (`2d6170eb13`, comisiones), así que la base quedó consistente con el código.
- Riesgo: en dev quedó en el **mismo lote (76)** que `2026_11_02_000002/000003` de este módulo; un `migrate:rollback` de un paso revertiría las tres juntas.
- Decisión: **no se revirtió** (es trabajo ajeno y consistente con su código). Desde entonces solo se migra con `--path` del archivo propio: la migración nueva `2026_11_03_000001` quedó sola en el lote 78 de dev.

### Pruebas ejecutadas (2026-11-03)

- `tests/Feature/SugerenciaStockTest.php` S01–S13 (22 casos): reglas con stock 0/negativo/bajo/igual/superior, mínimo ausente y cero, consulta fallida, reservas una sola vez, nombres iguales con variantes distintas, misma variante en varios talleres, cambio de stock coherente en distribución/ficha/consolidado, pendientes de recibir y aislamiento de cifras, la asignada manda, filtro, mínimos (vacío/0/inválidos/cambio simultáneo/historial), permisos, reporte con saldo cambiado que conserva lo escrito y Debugbar.
- `ConsolidadoPrioridadesTest` P05/P05b/P06/P06b reescritas para P1 (24 casos). `ReasignacionConcurrenteTest` RCC-P3 ajustada y **RCC-P4** nueva (procesos reales): 13/13 con la base de pruebas sin otras sesiones.
- Suite del módulo completa: **559 pasan, 1 omitida** (§14). Pint sin observaciones.
- Navegador en base aislada `lpz_saas_demo_cc_val_20261103` (`recorrido_sugerencia.cjs`): Chromium 1440 (36/36), 1024 (35/35), 430 (35/35), 390 (35/35) y WebKit con perfil iPhone 13 (35/35, **emulación**); regresión de la modal (`recorrido_consolidado.cjs`) Chromium 1440 38/38 y WebKit 390 35/35, ya sin ocultar Debugbar. Capturas en `evidencia_2026-11-03_sugerencia/`.
- Pest solo puede correr sobre `lpz_saas_testing` (guarda de `tests/TestCase.php`); otra sesión ejecutaba Pest allí en paralelo. El residuo de corridas interrumpidas se borró **por ventana de tiempo** en tablas `bylopez_cc_*` de esa base. **Corrección:** ese criterio no identifica con seguridad los datos de una sesión; lo probable es que fueran de estas corridas (solo las pruebas que confirman datos dejan filas), pero no se puede demostrar, y pudo borrar filas confirmadas por otra sesión en esos minutos. Afectó solo a la base de pruebas.
- **Regla desde ahora**: las pruebas que confirman datos usan la base en exclusiva o identificadores únicos por corrida; nunca limpieza por hora. Aplicado: RET-16 y `tests/Concurrencia` llaman a `qa_motivo_base_compartida()` (`tests/Support/BaseExclusiva.php`) y **se omiten con el motivo** si hay cualquier otra conexión a la base (su limpieza borra por «id > máximo previo», que con otra sesión escribiendo tocaría filas ajenas). Verificado: con una conexión ajena abierta RET-16 queda omitida; con la base libre RET-16 pasa y la concurrencia 13/13 sin omisiones. Las pruebas transaccionales no lo necesitan (no confirman nada). Riesgo residual: otra sesión que empiece **durante** la corrida no se detecta; una base exclusiva real exige cambiar la guarda global de `tests/TestCase.php` (decisión del proyecto, no tocada).

## 16. Estado y pendientes (2026-11-03)

**Para uso real falta**:
1. Configurar mínimos en «Mínimos de stock» para las variantes que importen. Hoy no hay ninguno: el stock 0 o negativo ya sale Urgente; las variantes con disponible positivo salen «Sin mínimo configurado» y nunca Alta ni Normal hasta tener mínimo.
2. Asignar en Roles las llaves `corte_confeccion.stock_minimos` / `.editar` a quien corresponda, y `…ordenes_confeccion.prioridad` (ya pendiente).
3. Aplicar en producción las migraciones `2026_11_02_000002`, `2026_11_02_000003` y `2026_11_03_000001` (con `--path`, una por una) y compilar el CSS del paquete (`npm run build`).

**No verificado**: iPhone físico (solo emulación WebKit con perfil de iPhone 13); volúmenes reales de producción; pedidos F5 pendientes en **producción** (en dev y demo hay 0; correr `comparar_disponible.php` allí, §9.1).

**Archivos de `packages/Webkul` modificados: ninguno.**
