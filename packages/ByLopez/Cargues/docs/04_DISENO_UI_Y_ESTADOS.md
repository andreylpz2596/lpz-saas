# 04 — Diseño aprobado, flujos y estados de interfaz

## 1. Referencia visual obligatoria

**[APROBADO]** Mockup de Camilo: pantalla "Cargues › Nuevo cargue › Relacionar columnas". Es guía **obligatoria**: conservar distribución, jerarquía, colores, tarjetas, iconografía, espaciado y tabla de relaciones, adaptándolos a componentes reales de Bagisto. No se rediseña ni se cambian las tarjetas por otro patrón.

> **Mockup recibido y revisado (2026-10-13):** [`mockups/cargues_nuevo_cargue_relacionar_columnas.png`](mockups/cargues_nuevo_cargue_relacionar_columnas.png) (PNG, 1.300.975 bytes, SHA-256 `09A96030…6946BB585`). La imagen se abrió y se leyó correctamente. Su pie dice "Propuesta visual · Datos de ejemplo": los tipos (99 Envíos, Servientrega, Otros envíos), los campos y los valores son **ejemplos**. No definen el motor ni los campos de ningún tipo, que salen de la configuración (`07` §0).
>
> **Origen de los campos destino** (revisión 3, `07` §7): en modo TABLA, la estructura real de la tabla (columnas obligatorias = `NOT NULL` sin default, no autoincrementales ni generadas); en modo PROCEDIMIENTO, `map.campos`. El backend devuelve campos y comparaciones guardadas; el frontend presenta y ayuda a validar; el backend vuelve a comprobar todo. Delimitador, entrecomillado y codificación solo en formatos delimitados; hoja y fila de encabezado según el formato. Pantallas adicionales que requieren mockup: pre-vuelo de `vaciar_y_cargar`/`reemplazar` (registros a eliminar o reemplazar, confirmación reforzada) y descarga separada de resultados inciertos.

### 1.0 Implementación E1 (2026-10-14)

La pantalla «Nuevo cargue» está implementada con la distribución del mockup (tarjetas con píldora «Activo» y aviso «Antes de cargar» a la izquierda; pasos, archivo, hoja y fila de encabezados, tabla de 3 columnas con `*` y ✓, contador, «Usar mapeo guardado», «Guardar esta relación», pie con aviso y botones). Se abrió en Chrome headless con datos sintéticos (`capturas_e1/`, sin datos personales): sin errores de consola ni de red, sin desbordamiento horizontal a 390 px y con modo oscuro. Diferencias conocidas respecto del mockup: el menú lateral es el del Admin de Bagisto; los iconos son los `icon-*` existentes (caja = `icon-product`, carpeta = `icon-folder` en el menú; el mockup usa una nube); las pantallas **«Configurar tipos» y el resultado de la validación no tienen mockup** y se construyeron con los mismos componentes. **La revisión visual formal es de Camilo.**

### 1.1 Contraste mockup ↔ propuesta (revisado con la imagen)

| Aspecto | Mockup (real) | Propuesta actual (§2) | ¿Coincide? |
|---|---|---|---|
| Distribución | Menú lateral; contenido con encabezado, pestañas y **dos paneles**: "Tipos de cargue" (izquierda, ~⅓) y el asistente (derecha, ~⅔) | panel tipos izquierda / pasos derecha | Sí |
| Encabezado | Migas "Administración / Cargues" con candado; título "Cargues" grande; subtítulo "Importa archivos, relaciona columnas y valida tus datos."; botón con borde azul **"Configurar tipos"** con engranaje, arriba a la derecha | `x-admin::layouts` + botón secundario | Sí. Las migas y el candado se toman del layout real del Admin |
| Pestañas | "Nuevo cargue" (activa, subrayado azul) · "Historial" | pestañas | Sí |
| Tarjetas de tipo | Buscador "Buscar tipo…"; cada tarjeta lleva icono de línea a la izquierda, nombre en negrita, descripción gris y píldora verde **"Activo"** a la derecha; la seleccionada tiene borde azul y fondo azul muy claro | `cg-card` tipo radio | Sí. Faltaba la píldora "Activo": se añade |
| Aviso lateral | Caja azul clara con icono "i": "Antes de cargar · Revisa las columnas y valida el archivo antes de aplicar cambios." | no estaba | **Se añade** (`cg-alert` informativo) |
| Stepper | 4 pasos en línea con conectores: **1. Archivo** (círculo azul con ✓), **2. Relacionar columnas** (círculo azul relleno, texto azul), 3 Validar y 4 Resultado (gris) | patrón `import.blade.php` | Sí |
| Archivo | Título "Archivo de \<tipo\>"; tarjeta con icono de Excel, nombre, "XLSX • Hoja: …" y botón con borde "Cambiar archivo" | tarjeta de archivo | Sí |
| Lectura | Dos selects en una fila: "Hoja del archivo" y "Fila de encabezados" | selects | Sí (XLSX; en CSV/TXT van delimitador, entrecomillado y codificación, §1) |
| Tabla de relaciones | Título "Relacionar columnas" + subtítulo; píldora verde **"5 de 5 campos relacionados"**; columnas **Campo de destino** (negrita, `*` rojo si es obligatorio) · **Columna del archivo** (select) · **Ejemplo del archivo** · icono ✓ circular azul | `cg-table` + selects + píldoras | Sí |
| Comparaciones | Enlace subrayado con icono de documento "Usar mapeo guardado" (izquierda); casilla "Guardar esta relación para próximos cargues" (derecha) | recuperar/guardar | Sí |
| Pie del asistente | Barra gris clara: icono "i" + "Archivo pendiente de validación. Todavía no se han aplicado cambios."; botones "Cancelar" (secundario) y **"Validar archivo"** (primario azul con icono de escudo) | `cg-alert`, `x-admin::button` | Sí |
| Colores | Azul primario (selección, stepper, botón principal), verde para "Activo" y el contador, rojo solo en `*`, fondos grises muy claros | paleta `cg-*` | Sí. Los tonos exactos se toman de los tokens reales del Admin |
| Menú lateral | Ítem "Cargues" con fondo azul relleno e icono de nube | menú del Admin | El menú real de Bagisto 2.4 tiene su propio estilo de ítem activo; **se respeta el del Admin** y se documenta esta diferencia |

Toda diferencia se resuelve **a favor del mockup**, salvo que contradiga un componente real de Bagisto; en ese caso se documenta aquí y se consulta a Camilo, sin rediseñar.

**Observaciones que no cambian reglas:**
- El ejemplo muestra "Valor del recaudo" y "Nombre del cliente" para 99 Envíos. Son **datos de ejemplo**; los campos de cada tipo los define su configuración.
- La marca ✓ de cada fila significa **"relacionado"**, no "validado"; lo confirma el texto del pie.

Contenido visible (confirmado con la imagen):
- Navegación lateral con "Cargues" seleccionado; título "Cargues" + descripción breve; botón "Configurar tipos" (según permiso).
- Pestañas "Nuevo cargue" e "Historial".
- Panel izquierdo: buscador + tarjetas de tipos habilitados.
- Panel derecho: pasos **Archivo → Relacionar columnas → Validar → Resultado**; archivo seleccionado con "cambiar", hoja y fila de encabezados.
- Tabla **Campo de destino | Columna del archivo | Ejemplo del archivo**; obligatorios identificados; contador de campos relacionados; "Usar mapeo guardado"; opción "guardar relación para próximos cargues".
- Aviso "todavía no se han aplicado cambios"; botones "Cancelar" y "Validar archivo".
- XLSX: sin delimitador. CSV/TXT delimitado: debe aparecer la configuración correspondiente (delimitador, entrecomillado, codificación).
- Marcas de relación = campos asociados, **no** datos validados.

## 2. Correspondencia mockup → componentes reales

| Elemento del mockup | Componente / patrón real | Origen |
|---|---|---|
| Layout de página, título, acciones | `x-admin::layouts` + slot de título | `Admin/.../components/layouts` |
| Pestañas Nuevo cargue / Historial | `x-admin::tabs` o enlaces a rutas con la misma apariencia | `Admin/.../components/tabs` |
| "Configurar tipos" | `x-admin::button` secundario. Todo el módulo (menú, pantalla y botón) se renderiza solo para el administrador, y las rutas lo exigen en backend (`07` §10) | Middleware propio + ACL |
| Panel izquierdo de tarjetas | Panel `cg-panel` + tarjetas `cg-card` (clon del patrón `lg-panel`), seleccionable con `role="radio"` | `Logistica/.../estilos.blade.php` |
| Buscador de tipos | filtro local en cliente (pocos tipos); si crece, `record-search` | `CorteConfeccion/.../components/record-search.blade.php` |
| Pasos | stepper del importador nativo (done/active/pending, spinner) | `Webkul/Admin/.../imports/import.blade.php:85-160` |
| Archivo + "cambiar" | zona de carga nativa de formulario (`x-admin::form.control-group` + input file) con resumen del archivo | `Admin/.../components/form` |
| Hoja / fila de encabezados | `x-admin::form.control-group.control type="select"` / número | idem |
| Delimitador / entrecomillado / codificación | mismos controles, **visibles solo si formato CSV/TXT** | idem |
| Tabla de relaciones | `cg-table` (clon de `lg-table`) con `<select>` por fila | `Logistica/.../estilos.blade.php` |
| Obligatorio / relacionado / ambiguo | `cg-pill` (clon de `lg-pill--*`) **con texto o icono además de color** | idem |
| Contador "X de Y relacionados" | texto en cabecera de la tabla + `aria-live="polite"` | — |
| Avisos | `x-admin::flash-group` para acciones; bloque `cg-alert` en línea para estado persistente | Admin |
| Botones Cancelar / Validar | `x-admin::button` (secundario / primario) | Admin |
| Iconos | clases `icon-*` de `Admin/src/Resources/assets/css/app.css`. El mockup usa iconos de línea: caja, camión, Excel, engranaje, intercambio, documento, escudo, información y ✓ circular. Se toma el equivalente más cercano existente en `icon-*`; si alguno no existe, se consulta antes de introducir otro set | Admin |

Estilos: bloque `<style>` prefijado `cg-` sin build (patrón Logistica), con modo oscuro `.dark` como el resto. No introducir Tailwind propio salvo necesidad (si se hace, aplica la regla 3 de `GUIA_VISUAL_ADMIN_BAGISTO.md`). Reglas generales de esa guía aplican (gana Bagisto ante la duda). Vue 3 dentro de Blade con `@pushOnce('scripts')`.

## 3. Comportamiento de los controles

| Control | Comportamiento |
|---|---|
| Tarjeta de tipo | Un solo tipo activo (radio). Cambiar de tipo con archivo/mapeo en curso → confirmar "se descartará el avance" (modal). Teclado: flechas/Enter/Espacio |
| Buscador | Filtra por nombre/descr.; sin resultados → estado vacío con texto |
| Cambiar archivo | Descarta lectura, filas, validación y mapeo no guardado (confirmar si hay mapeo editado); mantiene el tipo |
| Hoja / encabezado | Releer encabezados y ejemplos; **invalida** validación; mapeo sugerido se recalcula pero lo ya elegido por el operador se conserva si la columna sigue existiendo (si no, se marca "columna ya no existe") |
| Select de columna | Muestra encabezado + indicador de ambiguo; no permite la misma columna en dos campos (aviso) |
| "Usar mapeo guardado" | Lista plantillas del tipo; muestra diferencias antes de aplicar |
| "Guardar para próximos cargues" | Solo guarda plantilla; no afecta esta carga. Pide nombre y alcance si hay permiso |
| Validar archivo | Habilitado si: archivo leído, obligatorios relacionados, sin ambiguos pendientes. Deshabilitado con texto de razón (no solo color) |
| Ejecutar cargue | Solo en paso Validar con estado `listo`/`validado_advertencias`; requiere confirmación (modal con resumen y "no se podrá deshacer globalmente" si aplica) |
| Cancelar | Antes de ejecutar: descarta (estado `cancelado`, archivo se retiene según política). Durante ejecución: **no hay cancelar**; solo "solicitar detención" si el tipo lo soporta **[ABIERTO]** |

Salir/volver durante ejecución: el proceso corre en servidor; al volver, la página consulta el estado de la carga (por `uuid`) y reanuda la vista de progreso; `beforeunload` solo avisa en pasos previos con trabajo no guardado. Doble pestaña: el CAS de estado evita doble ejecución y la segunda pestaña muestra el estado real.

## 4. Estados (cada uno requiere definición visual)

| Estado | Contenido | ¿En mockup? |
|---|---|---|
| Sin tipos habilitados | Vacío: "No hay tipos de cargue habilitados" + (si permiso) enlace a Configurar tipos | No |
| Sin archivo | Tipo elegido, zona de carga con formatos y tamaño máx. del tipo | Parcial |
| Leyendo archivo | Spinner + etapa "Leyendo hoja…" (con filas leídas si se conoce); controles bloqueados | No |
| Archivo inválido | Alerta roja: razón comprensible (formato, vacío, protegido con contraseña, corrupto, excede límites) + "Cambiar archivo" | No |
| Columnas faltantes / ambiguas | En la tabla: píldora "Falta" / "Ambigua — elige una"; contador no llega a completo; Validar deshabilitado con razón | Parcial (obligatorios sí) |
| Mapeo listo | Mockup | **Sí** |
| Validación en curso | Stepper en "Validar"; etapas reales (persistiendo filas, normalizando, reglas) con conteo `x de y` real | No |
| Errores que bloquean | Resumen contadores + tabla de errores paginada (fila, campo, mensaje) + descargar errores; sin botón Ejecutar | No |
| Advertencias que requieren revisión | Lista agrupada por código; checkbox "He revisado las advertencias" antes de Ejecutar | No |
| Listo para ejecutar | Resumen (filas válidas, advertencias, omitidas) + aviso "aún no se aplicaron cambios" + botón Ejecutar | No |
| Procesando | Etapas y contadores reales (aplicadas/omitidas/fallidas); sin porcentaje inventado: si no hay total fiable, mostrar conteo sin barra | No |
| Éxito total | Resumen + enlace a detalle e historial | No |
| Éxito parcial | Aplicadas vs fallidas + descarga de fallidas + qué hacer | No |
| Fallo | Mensaje comprensible; detalle técnico plegado (el módulo es solo para administrador) | No |
| Interrumpido / expirado | Aviso con acción para administrador | No |

Accesibilidad: foco al primer error tras validar; `aria-live` para progreso y contadores; estados con texto/icono además de color; `<label>` en todo control; tabla de mapeo usable con teclado; móvil: panel de tipos arriba (colapsable) y tabla de relaciones como lista de tarjetas por campo (**requiere mockup móvil**).

## 5. Historial

Tabla `x-admin::datagrid` (patrón DataGrid de Bagisto, como `CompraTelaDataGrid`): Fecha, Usuario, Tipo (+versión), Archivo, Estado (píldora con texto), Contadores (leídas / aplicadas / errores), Acción "Ver detalle". Filtros por tipo, estado, rango de fechas y usuario. Detalle (patrón detalle de `GUIA_VISUAL_ADMIN_BAGISTO.md`): resumen, mapeo y configuración usados (snapshot), línea de tiempo de etapas, errores paginados y descarga del archivo de filas fallidas (`07` §9). Acceso: solo administrador.

## 6. Vistas que necesitan mockup complementario antes de implementar

1. Historial (lista) y Detalle de carga.
2. Paso Validar: resumen, errores bloqueantes, advertencias y confirmación de ejecución.
3. Paso Resultado (total / parcial / fallo).
4. Estados Leyendo / Procesando con etapas.
5. CSV/TXT: panel de delimitador/entrecomillado/codificación.
6. Configurar tipos (lista, nueva versión, provisión) — pantalla técnica separada del flujo operativo.
7. Versión móvil de Nuevo cargue.
8. Estados vacíos y de error (sin tipos, archivo inválido, mapeo ambiguo).

Sin esos mockups **no** se implementan; se aprueban primero (mismo lenguaje visual).
