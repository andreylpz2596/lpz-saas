# 36 — Auditoría responsive, táctil y de accesibilidad en iPhone (Safari)

**Fecha:** 2026-11-26 · **Rama:** `responsive/iphone-2026-11` (base `d89f080f65`, la misma de `develop` y pruebas) ·
**Estado:** desplegada en pruebas; **producción sin cambios** hasta que se revise en un iPhone físico y se apruebe.

## 1. Alcance y método

- **Dispositivo objetivo:** iPhone 17 Pro Max con Safari (440 × 956 pt; área útil con las barras de Safari ≈ 440 × 830).
- **Motores:** Playwright 1.47.2 con **WebKit 18.0** (perfil iPhone táctil, 3x) como referencia y **Chrome 157** (perfil
  iPhone, gestos táctiles por CDP) para comparar. **Es emulación, no un iPhone físico**: el teclado de iOS, las barras
  dinámicas de Safari, el área segura y la inercia del desplazamiento no se reproducen tal cual (ver §6).
- **Ambiente:** `pruebas.alpz.com.co` (copia de la base de producción). En producción no se crearon sesiones ni datos;
  la comparación con producción queda para la revisión en el iPhone.
- **Roles (usuarios QA sintéticos, sin ampliar permisos):** admin, asesora, bodega, taller y cortador.
- **Pantallas:** 47 rutas de Ventas Asesor, Comisiones, Logística, Corte y Confección, Cartera de prestadores, Cargues y
  Autoservicio. Con acceso: admin 45, asesora 7, bodega 18, taller 9, cortador 6 (85 combinaciones). El resto responde
  401/403 por permisos, como corresponde.
- **Recorrido completo de la modal de tallas** en 7 configuraciones (WebKit iPhone vertical con pocas y con muchas tallas,
  WebKit horizontal 956 × 360, Chrome iPhone con pocas y muchas, WebKit iPad, Chrome escritorio): cliente → referencia →
  color → «Ver tallas» → fondo bloqueado → desplazamiento desde cinco zonas → cantidad en el primer y el último campo →
  teclado simulado → guardar → editar con el lápiz (cambiar a 3 y guardar) → abrir y cerrar tres veces (X y Cancelar)
  cambiando de color.
- **Sin regresiones en escritorio y tableta:** cada pantalla se midió y fotografió **con y sin** el ajuste en la misma
  sesión (se retira el estilo en caliente), además de los recorridos de tableta y escritorio.

## 2. Causas compartidas

| # | Causa | Dónde se veía |
|---|---|---|
| C1 | Campos con letra de 14 px. Safari acerca la pantalla al enfocar un campo de menos de 16 px y no vuelve a alejarla | Cantidades de la modal de tallas («hay que alejar con los dedos»); 58 campos en las pantallas del admin, 28 en bodega, 3 en cortador |
| C2 | El contenedor del layout de Bagisto (`overflow-x-hidden`) se vuelve un desplazador vertical diminuto cuando la página mide unos píxeles más | Trampa de desplazamiento en Cargues y su Configuración; además anula `position: sticky` en todas las pantallas ByLopez |
| C3 | Objetivos táctiles pequeños en la modal | X de 30 px, − / + de 28 px, campo de 64 × 30 |
| C4 | Utilidades Tailwind que el CSS del panel no trae (Tailwind no recorre ByLopez) | La barra «Ver tallas» debía quedar fija en el teléfono y nunca lo hizo |
| C5 | Modal de Bagisto alineada a un contenedor de `100vh` (en Safari, el alto con las barras ocultas) | Riesgo de que el botón principal quede bajo la barra inferior de Safari |
| C6 | `149disponible` sin espacio (Vue quita el salto de línea entre etiquetas) | 165 px mínimos en la columna Stock; VoiceOver lee una sola palabra |

## 3. Hallazgos y cambios

Prioridad: **1** bloqueo · **2** teclado, desplazamiento o controles tapados · **3** espacio y legibilidad · **4** consistencia.
Rutas: pruebas `https://pruebas.alpz.com.co/admin/ventas-asesor/nueva-venta/pantalla` (rol asesora o admin); producción
(versión actual, para comparar) `https://bylopez.alpz.com.co/admin/ventas-asesor/nueva-venta/pantalla`.

### P1-1 · Modal de tallas: hay que alejar con los dedos para seguir escribiendo cantidades
- **Antes:** el campo de cantidad tenía letra de 14 px; al tocarlo Safari acercaba la pantalla y no la devolvía.
- **Cambio:** en pantallas táctiles y solo en rutas ByLopez, todos los campos a 16 px
  (`Logistica/.../admin/responsive-movil.blade.php`, §1). No se tocó la etiqueta viewport: el zoom manual sigue disponible.
- **Resultado:** 14 → 16 px en la modal; **0 campos de menos de 16 px** en las 85 combinaciones (antes 58 + 28 + 3).
- **En el iPhone:** Nueva venta → cliente → referencia → color → «Ver tallas» → tocar la cantidad de la primera talla.
  La pantalla no debe acercarse.

### P1-2 · Controles de la matriz difíciles de acertar
- **Antes:** − / + de 28 × 28 px, campo de 64 × 30, papelera de unos 16 px, X de 30 × 30; 9 botones de menos de 32 px.
- **Cambio:** − / + de 36 × 40 (32 × 40 en pantallas de 380 px o menos), campo de 48 × 40 con **teclado numérico**
  (`inputmode="numeric"`), papelera de 32 × 40 y área de la X de 44 × 44 sin moverla. Nombres accesibles con la talla
  («Sumar una unidad a la talla M», «Cantidad de la talla M»…). Precio en una sola línea (antes «$ / 8.500»).
- **Resultado:** 0 botones de menos de 32 px en la modal; X de 44 × 44.
- **En el iPhone:** tocar − y + de varias tallas sin acertar la vecina; cerrar con la X usando el pulgar.

### P1-3 · La tabla de tallas debe caber a lo ancho (encontrado al verificar el cambio anterior)
- **Antes del ajuste:** con los controles nuevos la tabla medía 416 px en 382 disponibles (456 con la papelera de una talla
  guardada): la columna Stock no bajaba de 165 px por C6.
- **Cambio:** espacio real entre el número y «disponible» y, en pantallas táctiles, la señal «en otras ventas…» en su
  propia línea.
- **Resultado (WebKit):**

  | Ancho | Antes (sin / con papelera) | Después (sin / con papelera) |
  |---|---|---|
  | 440 px (iPhone 17 Pro Max) | desborda 34 / 74 px | **cabe** (ancho mínimo 302 / 338 de 382) |
  | 390 px | desborda 84 / 124 px | cabe / 6 px dentro del margen interior de la tarjeta |
  | 375 px | desborda 99 / 139 px | cabe / 9 px dentro del margen interior de la tarjeta |

  En 390 y 375 px, con la papelera, la tabla entra en el margen interior de la tarjeta (12 px) sin cruzar su borde ni crear
  desplazamiento lateral. En pantallas angostas las filas son más altas porque la señal ocupa varias líneas.

### P2-1 · «Ver tallas» se pierde al recorrer muchos colores
- **Antes:** la barra debía quedar fija bajo la cabecera en el teléfono (`max-lg:sticky …`), pero esas utilidades no
  existen en el CSS del panel (C4) y el contenedor de C2 lo impedía: al desplazar, el botón salía de la pantalla.
- **Cambio:** clase propia `.nv-cta-fija` (sticky bajo la cabecera, que mide 49-53 px por debajo de 640 px y 61 px hasta
  1023 px), con fondo y borde en claro y oscuro. Con 500 px de alto o menos (teléfono horizontal) no se fija, para no
  tapar la lista. Depende del ajuste C2.
- **Resultado (WebKit 440 × 830, prueba con la clase inyectada):** al desplazar, la barra queda fija bajo la cabecera
  (con la misma clase y sin el ajuste C2, sale de la pantalla: `top: -157`). Valor desplegado: `top: 48px` en teléfono.
- **En el iPhone:** elegir una referencia con muchos colores, marcar uno y desplazar: «Ver tallas» queda visible bajo la
  cabecera.

### P2-2 · Trampa de desplazamiento (Cargues)
- **Antes:** el contenedor de C2 tenía un recorrido de menos de 60 px que se comía el primer gesto (Cargues y su
  Configuración). `guardia-scroll` solo lo corregía en Logística y pedidos.
- **Cambio:** `overflow-x: clip` en ese contenedor en todas las rutas ByLopez: recorta igual sin crear un desplazador.
- **Resultado:** 0 trampas (antes 2).
- **En el iPhone:** Cargues (admin): deslizar empezando sobre la tabla; la página debe moverse al primer gesto.

### P2-3 · Teclado sobre el último campo
- **Medido (emulado):** con el teclado simulado (alto 490 px), el foco programático de WebKit no desplaza el contenedor
  interno del drawer y el último campo queda bajo el pie (igual antes y después). En Safari real, iOS desplaza la vista
  hasta el campo enfocado.
- **Decisión:** no se agregó JavaScript para seguir el teclado; sin un iPhone físico no se puede verificar y podría
  empeorar iOS 26. **Queda para la revisión física** (§6, punto 3).
- **Compromiso:** el teclado numérico de iOS no tiene tecla Intro. Para guardar: «Listo» y luego «Agregar productos al
  carrito».

### P2-4 · Fondo de la página con la modal abierta
- **Medido:** Bagisto ya bloquea el `body` mientras la modal está abierta, y al cerrar (X o Cancelar, tres ciclos y con
  cambio de color) el desplazamiento de la página se recupera. Igual antes y después.
- **Cambio preventivo:** `overscroll-behavior: contain` en drawers y modales, para que al llegar al final de su contenido
  no se arrastre la página de fondo.

### P3-1 · Modal con `100vh` y teléfono en horizontal
- **Cambio:** la caja de la modal de Bagisto usa `min-height: 100dvh` (alto visible real). En horizontal (500 px de alto o
  menos) el pie del drawer pasa de 32 a 12 px de margen inferior.
- **En el iPhone:** Nueva venta → «+ Crear cliente» con las barras de Safari visibles: «Crear y continuar» se ve; girar
  a horizontal y abrir tallas: lista y botones alcanzables.

### P4-1 · Casillas pequeñas
- **Antes:** casillas nativas de 12-16 px. **Cambio:** 20 × 20 en pantallas táctiles (sin tocar las casillas que Bagisto
  dibuja con iconos). Están dentro de etiquetas tocables, que son el objetivo real.

### Escritorio y tableta (comparación con y sin el ajuste, misma sesión)
- **Escritorio (Chrome 1366):** 24 de 47 pantallas idénticas píxel a píxel. En las otras 23 solo cambia la sombra inferior
  de la última tarjeta, que antes recortaba el contenedor de C2 y ahora se ve completa. Mismo ancho y alto de documento.
- **Tableta (iPad, táctil):** cambian, como en el iPhone, los campos a 16 px y las casillas a 20 px. Ninguna pantalla gana
  ancho ni desborde.
- **Efecto secundario cosmético:** algunos textos de ayuda largos dentro de campos pierden los puntos suspensivos finales.

## 4. Fuera del alcance (observado, sin cambiar)

| Observación | Detalle | Responsable |
|---|---|---|
| 401 en `/admin/ventas-asesor/cartera/credito/cliente/{id}` al elegir cliente con la asesora | Error en consola; no bloquea la venta | Decisión pendiente: si la asesora ve el crédito del cliente |
| 500 en `/admin/autoservicio` para un admin sin vínculo de cliente | `AutoservicioInvalidoException` («Tu acceso no está disponible») sin capturar en `ClienteActualService.php:32`; debería ser un 403 o un mensaje | Autoservicio (en revisión) |
| 404 en `/cache/logo/bagisto.png` en todas las pantallas | Falta el logo del panel en pruebas | Configuración |
| Cortador QA: 403 en el detalle de una orden de corte | La orden no está asignada al cortador QA (permiso por vínculo); esperado | — |
| Objetivos táctiles de menos de 24 px en componentes nativos de Bagisto | Filtros y paginación del datagrid, enlaces del pie | Propuesta aparte (son de Webkul) |

## 5. Pruebas automáticas y sus límites

- `Logistica/tests/Feature/Log19AjustesMovilesTest.php` (10 casos): el ajuste se inyecta en las cinco familias de rutas
  ByLopez (RESP-1); no en pedidos, clientes, tablero de Bagisto ni sin ruta (RESP-2); no quita el zoom, no usa
  `overflow: hidden` ni `touch-action: none`, y trae 16 px, `clip`, `contain` y `100dvh` (RESP-3).
- `VentasAsesor/tests/Feature/Resp01NuevaVentaMovilTest.php` (RESP-4): Nueva venta trae el ajuste, la matriz táctil (clases,
  teclado numérico, nombres accesibles, espacio del stock), la barra fija y el viewport con zoom.
- Regresión: `Fase03gdPantallaNuevaVentaTest.php` (4 casos).
- **Límites:** Pest no ejecuta CSS ni JavaScript; el efecto visual se midió con WebKit y Chrome emulando el iPhone.

## 6. Lista para el iPhone físico (unos 5 minutos)

1. Safari → pruebas → Nueva venta (asesora o admin) → cliente → referencia → color → «Ver tallas».
2. Tocar la cantidad de la primera talla: **no hay acercamiento** y aparece el **teclado numérico**.
3. Con el teclado abierto, tocar la cantidad de la **última** talla: el campo queda visible; escribir.
4. «Listo» → «Agregar productos al carrito»: se guarda una sola vez y el resumen muestra las tallas.
5. Lápiz del resumen: aparecen las cantidades guardadas y la papelera; **sin desplazamiento lateral**; cambiar una y guardar.
6. Cerrar con la X (pulgar) y con Cancelar: la página vuelve a desplazarse normalmente.
7. Referencia con muchos colores: al desplazar, «Ver tallas» queda fijo bajo la cabecera.
8. Girar a horizontal y abrir tallas: la lista y los botones se alcanzan.
9. «+ Crear cliente»: con las barras de Safari visibles se ve «Crear y continuar».
10. Cargues (admin): la página se desplaza desde el primer gesto.
11. Repetir 1-6 en producción (misma ruta en `bylopez.alpz.com.co`) para comparar con la versión actual.

## 7. Datos sintéticos en pruebas

- Usuarios QA `qa.resp.<rol>@example.test` (admin 12185, asesora 12186, bodega 12187, taller 12188, cortador 12189), con
  los roles existentes de cada perfil.
- Perfil de asesor «QA Responsive Asesora» (creado desde la pantalla Asesores para que la asesora QA pueda guardar).
- Cliente «QA Responsive Tallas» (id 835, teléfono 3009990001, grupo General). Las ventas en curso que dejaron las
  pruebas se eliminaron desde «Ventas en curso».
- Las capturas de evidencia no se guardan en Git: las pantallas muestran datos de la copia de producción.

## 8. Despliegue

- Pruebas: la rama se desplegó con `git switch` a `responsive/iphone-2026-11` y avance rápido (sin `develop`, sin
  migraciones, sin dependencias ni recursos compilados) y `view:clear`. Se verificó que el HTML servido trae
  `data-bylopez="responsive-movil"` y la matriz nueva, y que las 11 plantillas Vue de Nueva venta compilan.
- Producción: llevar **solo los commits de esta rama** con cherry-pick sobre la rama saneada (no avanzar a `develop`), tras
  la aprobación.
