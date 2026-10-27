# Guía visual del panel Admin — Corte y Confección

**Objetivo:** que cualquier vista nueva o rediseñada de este módulo se vea como una
parte más del Admin de Bagisto, no como una interfaz aparte. Esta guía fija el
criterio para no tener que redescubrirlo cada vez.

## Regla general

Antes de diseñar o tocar una vista de este módulo: **busca primero si Bagisto ya
resuelve ese mismo tipo de pantalla** (listado, detalle, formulario) y copia su
estructura real. No se inventa un layout propio si el Admin ya tiene uno
equivalente. **Toda vista detalle de una entidad de este módulo (taller,
proveedor, y cualquier otra que se agregue después) sigue el patrón estructural
de Cliente/Taller** de la sección siguiente — no es opcional ni se reinterpreta
por pantalla.

```
1. Identificar el tipo de pantalla (listado / detalle / formulario).
2. Abrir la vista nativa de Webkul más parecida y leerla completa.
3. Reproducir su distribución, sus wrappers y sus clases tal cual.
4. Adaptar solo el contenido (datos, columnas, campos) al dominio del módulo.
```

`packages/Webkul/` se usa como **referencia de lectura**, nunca se modifica.

## Referencias aprobadas

| Tipo de pantalla | Referencia nativa de Bagisto | Implementación de este módulo que ya la sigue |
|---|---|---|
| Listado con varias columnas relacionadas | `admin::sales.orders.index` (Pedidos), `admin::customers.customers.index` (Clientes) | `rollos/index.blade.php`, `compras-tela/index.blade.php`, `ordenes-corte/index.blade.php`, `proveedores-tela/index.blade.php`, `talleres/index.blade.php` |
| Detalle de un registro con acciones, tabla principal y panel lateral | `admin::customers.customers.view` (Cliente) | `talleres/show.blade.php`, `proveedores-tela/show.blade.php` |

**`rollos/index.blade.php` sigue siendo la referencia aprobada para tablas
compactas con columnas agrupadas** (código/referencia, especificación,
inventario, estado/relaciones, acción). Cualquier tabla nueva de este módulo —
incluidas las que viven DENTRO de una ficha de detalle, como Compras de tela o
Referencias que ofrece en el detalle de Proveedor — parte de ese patrón, no de
uno nuevo.

**El detalle de Proveedor sigue el mismo patrón estructural que Cliente/Taller**,
en cuatro partes fijas, en este orden:

1. **Resumen** — franja de indicadores de solo lectura (compras realizadas,
   total histórico comprado, rollos comprados, referencias activas), en el
   lugar donde Cliente pone sus acciones rápidas.
2. **Compras de tela** — bloque principal, equivalente a "Pedidos" en Cliente.
3. **Referencias que ofrece** — segundo bloque, equivalente a "Facturas"/"Reseñas"
   en Cliente, con su propio botón "Agregar referencia".
4. **Panel lateral "Proveedor"** — identidad, contacto, tipos de tela como texto
   compacto y acción "Editar proveedor"; nunca un formulario abierto en la ficha.

Con una distinción de dominio que hay que preservar en cualquier ampliación
futura de este módulo (y de cualquier módulo que agregue proveedores o catálogos
similares): un **proveedor** ofrece **referencias comerciales** (catálogo: color,
gramaje, precio sugerido); una **compra de tela** es la que recibe **rollos
físicos** (inventario real). Una referencia nunca es un rollo, y crear o editar
una referencia **nunca** debe escribir en `bylopez_cc_rollos`. Si una fase futura
junta ambos conceptos en una sola pantalla o un solo formulario, está rompiendo
esta separación — y si agrega una ficha de detalle nueva, mantiene este mismo
lenguaje visual de cuatro partes, no uno propio.

Estas quedan como **ejemplo a copiar**, no solo como inspiración. Si una vista nueva
necesita alguno de estos dos patrones, se parte del archivo ya aprobado de esta
lista, no de la vista nativa desde cero.

## Patrón de listado (referencia: Rollos)

- Columnas agrupadas: cada celda del grid junta 2–4 datos relacionados en varias
  líneas, igual que hace Pedidos con "Total / Vía de pago / Canal".
- Cabecera y cuerpo comparten la **misma** plantilla de grid (`grid-cols-[...]`
  literal, escrita igual en los dos sitios — nunca por variable de Blade: el
  compilador de Tailwind lee texto, no ejecuta PHP).
- Acciones en su propia columna al final.
- Sin partials propios para la cabecera/cuerpo del DataGrid: se escribe inline,
  copiando el `v-for` de `columnGroups` tal como aparece en la vista nativa.
- Mínimo scroll horizontal: se prefiere agrupar contenido antes que agregar más
  columnas angostas.

## Patrón de detalle (referencia: Cliente → Taller)

| Bloque de Cliente | Equivalente en este módulo |
|---|---|
| Título + estado + botón Volver | Igual, sin cambios |
| Franja superior de acciones (Crear Pedido, Login as customer, Eliminar) | Solo para acciones/indicadores que tengan sentido en ese dominio. **No copiar botones CRUD del ejemplo de Cliente si no aplican** (ver Taller: ahí son 4 indicadores de solo lectura) |
| Card principal (Pedidos) | Card principal del dominio, con el patrón de listado agrupado de arriba, **sin repetir la columna del registro padre** (en Taller no se repite "Taller"; en Proveedor no se repite "Proveedor" dentro de sus propias Compras) |
| Card secundaria (Facturas) | Zona reservada para lo que siga (histórico, movimientos, incidencias), **o una segunda sección igual de real** cuando el dominio ya tiene algo que mostrar (Referencias que ofrece, en Proveedor). Si todavía no hay un dato real, se deja un placeholder honesto — **nunca una métrica inventada** |
| Panel lateral (Cliente) | Panel lateral único con el nombre del registro (ej. "Taller", "Proveedor"), sin fragmentarlo en accordions redundantes si los datos son pocos. La selección de catálogos completos (tipos de tela, listas largas) **no vive aquí**: se muestra como texto o lista compacta de lo ya asociado, y la edición de esa selección se hace desde la pantalla de edición completa, nunca desde el panel lateral |

**Editar sin abrir un formulario gigante.** Cuando el detalle necesita una
acción de edición, se usa un modal Vue pequeño (`x-admin::modal` +
`x-admin::form`, igual que `v-taller-edit`/`v-proveedor-edit`) para los campos
básicos, reenviando por AJAX al mismo endpoint de actualización que ya existe.
Si el detalle necesita crear o editar UN elemento de una colección hija (una
referencia, por ejemplo) desde dentro de un DataGrid nativo, y no hay un patrón
Vue ya probado para abrir un modal desde una fila de ese DataGrid, la alternativa
igualmente válida es una **página pequeña y dedicada** (ver
`proveedores-tela/referencia-form.blade.php`): reenvía al mismo endpoint y a las
mismas reglas de validación que el formulario completo, con los demás campos
del registro padre sin cambios (ocultos). Ninguna de las dos vías debe convertir
la ficha de detalle en el formulario grande de edición.

## Reglas que no se negocian

1. **No modificar `packages/Webkul/`** bajo ningún motivo, ni para "solo mirar cómo
   lo hacen" con una copia editable.
2. **No inventar componentes visuales ajenos al Admin.** Si algo no existe en
   Bagisto, se resuelve con las mismas utilidades (`box-shadow`, `x-admin::accordion`,
   `label-active`/`label-canceled`, iconos ya existentes en
   `packages/Webkul/Admin/src/Resources/assets/css/app.css`), no con CSS o
   estructuras propias.
3. **Cualquier clase Tailwind nueva usada en una vista de este módulo exige
   recompilar** `packages/ByLopez/CorteConfeccion` (`npm run build`, ver su
   `package.json`) y verificar que la regla exista bajo `.bylopez-scope` en
   `public/themes/admin/default/build-bylopez/corteconfeccion.css` antes de
   darla por terminada. El Tailwind de `packages/Webkul/Admin/` no escanea este
   módulo.
4. **No repetir información** que ya se sabe por el contexto de la pantalla (el
   taller en su propio detalle, el proveedor en su propia fila, etc.).
5. Ante la duda entre "cómo se vería mejor" y "cómo ya lo hace Bagisto en una
   pantalla equivalente": **gana Bagisto.**
