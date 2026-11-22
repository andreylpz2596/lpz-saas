# 32 · Costos y precios: precios por grupo

Fecha: 2026-11-22 · Rama: `develop` (worktree `lpzsan`) · Estado: implementado en desarrollo y probado en `lpz_saas_testing`. Sin push ni despliegue.

«Control de costos» pasa a llamarse **«Costos y precios»** y tiene dos pestañas:

- **Costos**: las mismas funciones de antes (D-COST-04/05/07), sin cambios.
- **Precios por grupo**: consulta y edición, individual o masiva, de los precios por grupo de clientes de Bagisto.

No hay un motor de precios nuevo ni una segunda fuente de verdad. El precio sigue guardado solo en `product_customer_group_prices` y lo sigue calculando el indexador de Bagisto.

## 1. Cómo funciona hoy (verificado)

Las reglas se confirmaron con el código y con `tests/Feature/PreciosGrupo01CaracterizacionTest.php` (12 casos), que usa el motor real y no cambia código.

| Pregunta | Respuesta | Evidencia |
|---|---|---|
| ¿Dónde se guarda? | `product_customer_group_prices`: `product_id`, `customer_group_id` (vacío = **todos los grupos**), `qty` (cantidad mínima), `value_type` (`fixed` o `discount`) y `value` (decimal 12,4). `unique_id` = `qty\|producto\|grupo` sin partes vacías, único. | Migraciones de `Webkul/Product`; `ProductCustomerGroupPriceRepository::saveCustomerGroupPrices` |
| ¿Padre o variante? | **Variante.** El carrito cobra la variante (`Configurable::prepareForCart` y `validateCartItem` usan la variante hija). El índice del padre configurable es el mínimo de sus variantes. Una regla en el padre no cambia nada. | PG01-9, PG01-10 |
| `fixed` | Precio unitario. **No sigue** al precio base. Solo aplica si es **menor** que el precio vigente: un fijo igual o mayor que el base se ignora. | PG01-2, PG01-3; `Price/AbstractType::getCustomerGroupPrice` |
| `discount` | **Porcentaje de descuento sobre el precio BASE** de la variante (no sobre el especial ni sobre el fijo anterior). Base $10.000 con 10 % da $9.000. Si la base pasa a $12.000, el precio queda en $10.800. | PG01-1, PG01-7 |
| Escalones | Gana el escalón de mayor cantidad mínima que no supere la cantidad de la **línea**, es decir, **por SKU**. Las tallas del mismo padre no se suman (6 S + 6 M no llegan al escalón 10). Sin un escalón aplicable rige el base. Un escalón fijo más caro que el anterior no tiene efecto; uno de descuento siempre reemplaza al anterior. Una fila con cantidad 0 nunca aplica. | PG01-4, PG01-5, PG01-6, PG01-12 |
| Precio especial y reglas de catálogo | Resultado = **mínimo** entre el precio de grupo y el precio con especial o regla de catálogo. **No se acumulan.** Un especial vencido no cuenta. | PG01-7; `Price/AbstractType::getMinimalPrice` |
| Promociones (CartRule) y cupones | Se aplican después, sobre el precio de la línea, que ya es el de grupo. | Flujo de `Cart::collectTotals` |
| Descuentos manuales | Nueva venta no permite precios manuales: `custom_price` se rechaza (D-18). | `ConfirmarVentaService::revalidarLineas` |
| Nueva venta | Usa el mismo motor (`getFinalPrice($cantidad)` de la variante) dentro del contexto RT-03. Con un grupo temporal (GRP-01) solo cambia el grupo que lee el motor. | PG01-11, Doc. 11, Doc. 20 |
| Índice | Con **cantidad 1** Bagisto lee `product_price_indices`; con 2 o más calcula en vivo. Sin reindexar, una venta de 1 unidad sigue con el precio viejo. | PG01-9 |
| Borrador abierto | Las líneas guardan el precio de cuando se agregaron. Se reprecian en cada operación sobre la venta y al confirmar. Si al confirmar el total cambió, se rechaza con `totales_cambiaron` y Nueva venta muestra el modal «Los totales cambiaron». | PG02-17, `ConfirmarVentaService::revalidarTotal` |
| Pedidos y comisiones | No cambian: el precio vive en `order_items` y las comisiones salen de ahí. | PG02-17, `LineasComisionService` |

Datos de desarrollo al 2026-11-21 (solo lectura):

- 1.495 reglas, todas `fixed` y todas en variantes, con escalones de 1 y 10.
- 35 variantes de Mayorista_VIP_3 solo tienen el escalón 10: con 1 a 9 unidades rige el precio base.
- «Recurrente» tiene un fijo de $72.000. Es mayor que el base, así que Bagisto lo ignora (posible error de digitación, **no se modificó**).

## 2. Tres operaciones distintas (no se mezclan)

| | Qué es | Dónde |
|---|---|---|
| A. Precio fijo de grupo | `fixed`: el precio por unidad. | «Establecer precio fijo» |
| B. Regla porcentual de grupo | `discount`: % de descuento sobre el base que **sigue** al base cuando este cambia. | «Establecer descuento %» |
| C. Ajuste masivo de fijos | Cambio **único** (por importe o por %) sobre los fijos existentes, con redondeo opcional (al peso, a $50 o a $100). Escribe fijos nuevos: no crea reglas %, no toca escalones %, no convierte tipos y omite las variantes sin ese escalón. | «Ajustar precios fijos existentes» |

Además: «Completar solo faltantes» crea el escalón solo donde no existe, y «Quitar escalón» lo borra mostrando antes el precio que quedará.

## 3. Pantalla

`Ventas Asesor › Costos y precios › Precios por grupo` (`costos?pestana=precios`).

- **Grupo**: selector con buscador. Su nombre queda fijo en una barra arriba. Se trabaja con **un** grupo existente: no se crean grupos ni se reasignan clientes. El invitado no aparece (mismo criterio que `ValidadorGrupoComercial`).
- **Filtros** tomados del catálogo, sin listas fijas:
  - Público: categorías de primer nivel del árbol del canal (HOMBRE, MUJER, NIÑO).
  - Referencia: familia de atributos.
  - Tipo de tela: atributo de Rentabilidad, de la variante o de su padre.
  - Color y talla: la talla es cualquier super atributo distinto de color, así que en Niño salen 2-4, 6-8, 10-12 y 14-16, en el orden de sus opciones.
  - SKU o nombre, «solo sin precio específico» y «cantidad a consultar».
- **Agrupación**: una tarjeta desplegable por referencia+color (el configurable padre), con las tallas dentro. El resumen muestra el precio, «Precios variados» (con el rango) o «Sin precio específico».
- **Por variante**:
  - talla y SKU, precio base;
  - reglas del grupo por escalón (las que no tienen efecto aparecen tachadas con su motivo) y las de «todos los grupos»;
  - precio para la cantidad consultada con su **origen**: grupo · desde N u., precio especial, regla de catálogo o precio base;
  - aviso de **índice desactualizado**;
  - comparación con los otros grupos (en el editor);
  - costo y precio − costo solo con permiso de costos. Si no hay costo dice «Sin costo», nunca $0.
- **Editor individual** (botón *Editar*):
  - lista los escalones con «Cambiar» (precarga el formulario) y «Quitar»;
  - formulario de escalón, tipo y valor. Si el escalón existe se reemplaza; si no, se agrega.
  - Todo pasa por la vista previa.
- **Edición masiva**:
  - acción, escalón, valor, motivo (obligatorio con más de una variante) y alcance;
  - el alcance es «Variantes seleccionadas» (la selección se recuerda entre páginas por grupo en `sessionStorage`) o «Todos los resultados del filtro (N)»;
  - «Seleccionar esta página» es distinto de «todos los resultados»;
  - hay casillas por talla, por referencia y por página.
- **Vista previa**:
  - grupo, acción en palabras, alcance con los filtros;
  - cuenta las reglas que se crean, se actualizan, se quitan, quedan iguales o tienen error;
  - por fila: regla actual del escalón, regla nueva, precio resultante (anterior tachado), origen, estado y advertencias;
  - avisa de las ventas en curso del grupo con esas variantes;
  - «Cancelar sin guardar» no escribe nada.
- **Resultado del lote**: conteos, estado del índice y detalle de cada regla cambiada. «Últimas ediciones» lista el historial.

## 4. Seguridad e integridad

- **Fuente única**: el repositorio de Bagisto (`ProductCustomerGroupPriceRepository`) con el mismo `unique_id`. Solo se tocan las filas de la variante, el grupo y el escalón elegidos.
- **Vista previa = precio real**: `MotorPrecioGrupo` le pasa al **indexador de Bagisto** un clon de la variante con las reglas propuestas en memoria. La pantalla solo replica el recorrido para decir qué fila gana; si no coincide con el motor, dice «Origen no determinado».
- **Conflictos**:
  - el token de la vista previa es un HMAC de la operación y del estado leído (reglas del grupo y de «todos», base y especial);
  - al confirmar se toman **primero los bloqueos** (variantes y sus reglas) y después se lee y se compara;
  - si otro usuario, otra pestaña o la ficha de Bagisto cambió algo, la respuesta es `conflicto`, se muestra la vista previa actualizada y no se escribe nada.
- **Atómico**: todo el lote va en una transacción. Una fila con error bloquea la confirmación, así que nunca hay aplicaciones parciales.
- **Idempotente**: el token confirmado es único en la bitácora. Un doble clic o un reenvío devuelve «ya se había aplicado». El botón también se desactiva al enviar.
- **Lotes grandes**: máximo 1.000 variantes por operación, con un solo resultado claro. Con más resultados, «todos los resultados» se desactiva y pide acotar los filtros.
- **Después del commit**:
  - `UpdateCreatePriceIndex::dispatchSync` (job de Bagisto) para las variantes y sus padres;
  - el job de búsqueda (solo actúa con Elasticsearch);
  - el listener FPC de Bagisto olvida las páginas de producto de la tienda.
  - Si la reindexación falla, el lote queda en `indice_estado = error` y la pantalla dice que los precios **se guardaron**, que con 2 o más unidades ya rigen y que hay que correr `php artisan indexer:index --type=price`.
- **Bitácora**:
  - `bylopez_va_precio_grupo_lotes`: actor, fecha, grupo, operación y parámetros, alcance, filtros, motivo, conteos e índice;
  - `bylopez_va_precio_grupo_cambios`: una fila por regla, con antes/después, base y precio resultante;
  - es solo de adición (los modelos rechazan cambios y borrados).
  - Límite: una edición desde la ficha nativa de Bagisto no queda en esta bitácora, aunque sí invalida una vista previa abierta.
- **Rentabilidad**: no se activan bloqueos ni se inventan mínimos. Solo se advierte «por debajo del costo comisionable» a quien ve costos. El mínimo por política sigue en *Rentabilidad › Diagnóstico*.

## 5. Permisos (no se creó ninguna clave nueva)

| Acción | Exige |
|---|---|
| Ver la pestaña | `ventas_asesor.costos` (la misma ruta GET de la pantalla) |
| Vista previa y confirmar | Bouncer: `ventas_asesor.costos.actualizar` (rutas agregadas a esa hoja en `Config/acl.php`). Servicio: además `catalog.products.edit` de Bagisto. |
| Ver costo y diferencia | `ventas_asesor.costos` |

El rol de asesor no tiene `ventas_asesor.costos`: recibe 401 en la pantalla y en las acciones (PG02-15).

**Decisión pendiente (P1):** ¿se quiere una hoja propia «Editar precios por grupo»? Eso implica tocar el árbol de permisos y necesita aprobación.

## 6. Archivos

- `Support/Precios/MotorPrecioGrupo.php`, `EvaluacionPrecioGrupo.php`, `FormatoPrecioGrupo.php`
- `Services/Precios/ConsultaPreciosGrupoService.php`, `EdicionPreciosGrupoService.php`
- `DataObjects/SolicitudPrecioGrupo.php`, `VistaPreviaPreciosGrupo.php`; `Exceptions/PrecioGrupoInvalidoException.php`
- `Models/LotePrecioGrupo.php`, `CambioPrecioGrupo.php`; migración `2026_11_21_000001_create_bylopez_va_precio_grupo_lotes_tables.php` (aditiva)
- `Http/Controllers/Admin/PreciosGrupoController.php`. Además:
  - `ControlCostosController::index` delega `?pestana=precios`;
  - rutas POST `costos/precios/vista-previa` y `costos/precios/confirmar`.
- Vistas `admin/costos/pestanas.blade.php` y `admin/costos/precios/*`. Textos `precios.*` en es y en; el menú dice «Costos y precios».

## 7. Pruebas

- `PreciosGrupo01CaracterizacionTest` (12): reglas reales de Bagisto (§1).
- `PreciosGrupo02EdicionTest` (18):
  - VIP 2 sin tocar VIP 1;
  - una talla, todas las tallas, selección entre páginas y por filtros;
  - sin regla y precio alternativo;
  - fijo y % calculados a mano, cambio de base, escalones antes, en y después;
  - especial, y vista previa igual a Nueva venta con 1 y con 12 unidades;
  - cancelar sin escrituras, doble envío y vista previa vieja (conflicto, también por cambio del base);
  - fila con error sin lote parcial, ajuste con redondeo, validaciones;
  - permisos HTTP y costos ocultos, HTTP de punta a punta;
  - pedido intacto y borrador con `totales_cambiaron`, consultas constantes con más variantes.
- `Concurrencia/PreciosGrupoConcurrenteTest` (2, procesos reales): dos ediciones distintas a la vez (una aplica y la otra recibe `conflicto`) y la misma edición dos veces (un solo lote).

## 8. Rendimiento (medido en desarrollo, `lpz_saas`, VIP_2: 322 variantes, 89 referencias)

| Operación | Consultas | Tiempo (en caliente) |
|---|---|---|
| Página de 20 referencias (71 variantes), cantidad 1 | 29, constantes | ~0,6 s |
| «Todos los resultados» (ids del filtro) | 1 | ~10 ms |
| Vista previa de las 322 variantes | constantes | ~2,2 s |

Hubo tres causas medidas y corregidas sin tocar Bagisto:

1. `Product::getAttribute` vuelve a resolver un atributo nulo en cada acceso. Con `special_price` vacío costaba unos 2 ms por lectura. `MotorPrecioGrupo::preparar` hace la misma resolución una vez por variante.
2. El indexador se creaba en cada evaluación. Ahora se reutiliza uno por tipo, como hace `Indexers\Price::getTypeIndexer`.
3. Los datos del catálogo se cruzaban de forma cuadrática. Ahora se agrupan.

La comparación entre grupos se carga solo al abrir el editor. PG02-18 verifica que las consultas no crecen con el número de variantes.

## 9. Verificación visual (Chromium headless, servidor local de desarrollo)

Se recorrió con datos reales de desarrollo, **sin confirmar** (cero lotes escritos):

- VIP 2 → HOMBRE → Negro, marcar la referencia (4 tallas) y abrir el editor de la talla S (comparación de 6 grupos);
- vista previa masiva «fijo $7.300» (4 se actualizan) → «Cancelar sin guardar» vuelve con los filtros;
- vista previa de «Quitar» la S: queda el precio base $8.500.

También se revisó:

- modo claro y oscuro;
- móvil de 390 px: sin scroll horizontal y filas de talla en dos columnas;
- la selección se conserva al recargar;
- con el teclado (foco en la casilla y Espacio) cambia la selección.

**No se probó en Safari ni en un iPhone físico.**

Hallazgo de esta prueba: Bagisto monta Vue sobre `#app` en `load` y vuelve a crear el DOM. El JavaScript de la pantalla usa delegación en `document` y se inicializa después del montaje.

## 10. Pendientes reales

- **P1:** hoja ACL propia para editar precios (hoy son `costos.actualizar` y `catalog.products.edit`). Necesita aprobación porque toca permisos.
- **P2:** redondeo por defecto del ajuste masivo. Hoy es «al peso»; se ofrecen $50 y $100. Es una decisión comercial.
- **P3:** datos de desarrollo para revisar con el responsable (no se tocaron): el fijo de $72.000 de «Recurrente», sin efecto, y las 35 variantes de VIP_3 que solo tienen el escalón 10.
- **P4:** las ediciones hechas desde la ficha nativa de Bagisto no quedan en la bitácora.
- Probar en un dispositivo iOS real.

## 11. Despliegue (cuando se apruebe)

```bash
php8.3 artisan migrate --force --path=packages/ByLopez/VentasAsesor/src/Database/Migrations/2026_11_21_000001_create_bylopez_va_precio_grupo_lotes_tables.php
php8.3 artisan optimize:clear && php8.3 artisan optimize
```

No necesita seeders ni cambios de roles. En producción la cola es `sync`; la reindexación usa `dispatchSync`, así que no depende del worker.

## 12. Cierre 2026-11-22 (versión para revisión en pruebas.alpz)

- **Permisos propios** (reemplazan §5):
  - `ventas_asesor.precios_grupo` (ver, ruta `admin/ventas-asesor/precios-grupo`);
  - `ventas_asesor.precios_grupo.editar` (vista previa, confirmar y reintentar la reindexación);
  - separados de `ventas_asesor.costos`: editar precios no da costos ni márgenes, y ya no se exige `catalog.products.edit`;
  - una sola entrada de menú «Costos y precios»: con solo precios, la misma entrada abre la pestaña de precios (compositor del layout, como Cargues);
  - las pestañas se muestran según el permiso;
  - los enlaces viejos `costos?pestana=precios` redirigen.
- **Regla ByLopez (Camilo):** un precio **fijo** de grupo no puede ser **mayor** que el precio base de la variante.
  - Se bloquea en esta pantalla (la fila con error bloquea el lote) y en la ficha nativa (listener `catalog.product.update.before`).
  - Igual al base se permite, con advertencia. No se corrigen filas existentes (Recurrente $72.000, VIP_3).
- **Filtro** «Precio específico»: todas, con o sin.
- **Redondeo** (predeterminado al peso; opciones $50 y $100): al múltiplo más cercano y justo en la mitad sube. La vista previa muestra «Calculado → redondeado».
- **Índice:**
  - si la reindexación falla, el lote queda en `error`;
  - el aviso dice que los precios se guardaron pero que con 1 unidad puede seguir el anterior;
  - el botón «Reintentar reindexación» lo recupera (PG02-21/22).
- **Historial:** se llama «Historial de cambios desde esta pantalla» y aclara que no incluye la ficha nativa.
- **Interfaz:**
  - cambiar de grupo con una selección o una edición pendiente pide confirmación (la selección no pasa a otro grupo);
  - aviso al salir con una edición sin previsualizar;
  - botones con estado «Cargando…» y «Guardando…».
- **Pruebas nuevas:** PG02-19 a 26 (regla del máximo, ficha nativa, falla y recuperación del índice, redondeos, cupón después del precio de grupo, conflicto con la ficha nativa, filtro con/sin y conteos). La prueba ACL/menú quedó en 11/11 con las 54 claves.
