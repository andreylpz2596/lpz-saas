# 30 · Testing por asesora (ventas, guías reales de 99 Envíos, comisiones) y % de devolución

Fecha: 2026-11-04 · Entorno: **base demo exclusiva** `lpz_saas_demo_asesores_20261104` (copia de estructura y catálogo de desarrollo; sin pedidos ni clientes reales). No es producción. Sin commit, push ni despliegue. `packages/Webkul/` sin cambios.

## 1. Qué se probó

Tres asesoras ficticias con acuerdos distintos (para ver el umbral en cada caso), cada una con sus clientes:

| Asesora | Acuerdo | Pedidos (uds) | Qué hace |
|---|---|---|---|
| DEMO Karen | 10 % · umbral 6 | K1 local transf. (3) · K2 local contra entrega (2) · K3 Inter c/e (3) · K4 Inter transf. (2) · K5 Inter c/e (2) **devuelto** · K6 Inter transf. (3) · K7 local transf. (2) | mezcla completa, 1 devolución recibida |
| DEMO Wendy | 8 % · umbral 10 | W1 Inter c/e (4) · W2 Inter c/e (3) **devuelto, recibido** · W3 Inter c/e (3) **devuelto, por recibir** · W4 local transf. (5) · W5 Inter c/e (2) **en tránsito** · W6 Inter transf. (4) | alta devolución |
| DEMO Laura | 12 % · umbral 4 | L1 local c/e (2) · L2 Inter c/e (2) · L3 Inter transf. (3) · L4 Inter c/e (3) | sin devoluciones |

Todo por HTTP con las sesiones reales de cada rol (asesora → Nueva venta y pagos; Bodega → despacho, entrega y recepción de devoluciones; Administración → confirmación de pagos, cobros, cuentas de transportadoras). Nada se forzó por SQL salvo la preparación de usuarios/acuerdos/stock y la **confirmación del costo** (ver §4, hallazgo 1).

**Guías reales.** Se tomaron de `Envios_Completos_2026-11-03.xlsx` (1.133 filas de 99 Envíos; Inter Rapidísimo): número de guía, estado y flete reales. Nombre, teléfono y dirección del destinatario se sustituyeron por datos ficticios y las fechas se llevaron a hoy (el hito no puede ser anterior al despacho). Estados usados: **Entregada** (→ entrega registrada y validada), **Devolución ratificada** (→ retorno pendiente de recepción), **Creado** (solo estado). El cargue se hizo con el tipo «99 Envíos — Envíos completos **v3**» y el worker real; las 12 guías produjeron exactamente el hito esperado (`[entrega, validación, retorno]` = `[1,1,0]`, `[0,0,1]` o `[0,0,0]`).

## 2. Resultados numéricos (leídos de la base, verificados a mano)

| Asesora | Uds vendidas | Devueltas | Computables | Umbral | Comisionables | Comisión estimada |
|---|---|---|---|---|---|---|
| Karen | 17 | 2 | 15 | 6 | 9 | 9 × $ 4.000 × 10 % = **$ 3.600** |
| Wendy | 21 | 6 | 15 | 10 | 5 | 5 × $ 4.000 × 8 % = **$ 1.600** |
| Laura | 10 | 0 | 10 | 4 | 6 | 6 × $ 4.000 × 12 % = **$ 2.880** |

Precio $ 10.000, costo $ 6.000 (ficticios) → base $ 4.000 por unidad. Las primeras *umbral* unidades del mes no comisionan; las devoluciones restan de las computables. Habilitadas al cierre de la prueba (cumplen entrega validada + pago confirmado + dinero recibido): K6 $ 1.200, K7 $ 800, W6 $ 1.280, L4 $ 1.440.

## 3. Porcentaje de devolución por asesora (nuevo)

**Definición** (`AvanceAsesorService::devolucionTotal`): paquetes (envíos) con **todas** sus unidades devueltas ÷ paquetes despachados de los pedidos del mes de la asesora. La devolución parcial no cuenta (queda en el detalle del pedido). Un paquete cuenta como devuelto cuando la transportadora lo devolvió (retorno confirmado); se informa aparte cuántos ya recibió Bodega y cuántos vienen «por recibir».

- Franjas visuales (solo guía de lectura, no bloquean ni pagan nada): < 10 % **Bajo control** (verde), 10–25 % **Atención** (ámbar), ≥ 25 % **Alto** (rojo). Constantes `DEVOLUCION_AVISO` / `DEVOLUCION_ALERTA`.
- Dónde se ve: **Asesores** (tarjeta comparativa de cada asesora, para Administración) y **Mis ventas** (tarjeta «Tu avance del mes», para la propia asesora). Parcial reutilizable `asesores/devolucion.blade.php`; textos en es y en.
- Resultado en la demo: Karen 1/7 = 14,3 % · Wendy 2/6 = 33,3 % (1 por recibir) · Laura 0/4 = 0 %. Coincide con un SQL independiente (envíos y retornos confirmados por pedido).
- **No** cambia comisiones: la política de devoluciones sigue sin aprobar (el pedido queda «en revisión»).

## 4. Hallazgos

1. **Sin costo confirmado no hay comisión.** Si Administración no confirma el costo estándar de la referencia (Control de costos / Rentabilidad), la comisión queda «Falta confirmar un dato interno» (`costo_sin_estado`) aunque todo lo demás esté cumplido, y **no se corrige hacia atrás** (se mira al confirmar la venta). Los pedidos de la 1.ª tanda quedaron así a propósito; la 2.ª, con el costo confirmado, sí se habilitó. **Antes de producción hay que confirmar el costo de cada referencia vendida.**
2. **Flete a cargo de ByLopez en todas las guías de Inter** (como en el recorrido D2): la cuenta de Inter queda con saldo a favor de Inter porque los fletes (incluidos paquetes devueltos o aún en tránsito) superan el recaudo. Mi liquidación de la 1.ª tanda usó un efectivo que no coincidía con la propuesta del sistema y dejó una diferencia de $ 3.835 «pendiente de ByLopez» (artefacto de la prueba, el sistema lo mostró correctamente).
3. **Tiempos de pantalla** (administrador, servidor `php -S` de un solo hilo, 21 pedidos, medidos con una sesión HTTP): Asesores 5,6 s · Pedidos 4,3 s · Comisiones 5,6 s. Aceptable en la demo; la advertencia del doc 29 (≈15 consultas por pedido en Comisiones) sigue vigente para cientos de pedidos al mes. Al capturar con el navegador al mismo tiempo, las peticiones se encolan en ese servidor y superaron el límite de 30 s (se subió a 300 s solo en la demo): no es lentitud del módulo. **No se resolvió ni se optimizó aquí.**
4. Un envío de correo de Bagisto falla en modo `log` («Attempt to read property "title" on array» en `emails/orders/inventory-source.blade.php`, core). No afecta al flujo (los clientes de prueba no tienen correo); se informa, no se tocó.
5. Durante la prueba otra sesión dejó por un momento un nombre de ruta del menú sin registrar (`admin.ventas_asesor.cotizaciones.datos`), lo que rompía el encabezado de todas las pantallas del panel; ya está corregido por esa sesión. Ajeno a este trabajo.

## 5. Archivos de esta jornada (solo `packages/ByLopez/VentasAsesor/`)

| Archivo | Cambio |
|---|---|
| `src/Services/Comisiones/AvanceAsesorService.php` | `devolucion` en `deAsesor()` + `devolucionTotal()` |
| `src/Resources/views/admin/asesores/devolucion.blade.php` (nuevo) | insignia con color, icono y texto |
| `…/asesores/avance-comparativo.blade.php`, `…/pedidos/avance.blade.php` | incluyen el parcial |
| `…/comisiones/estilos-panel.blade.php` | estilos `.va-dev*` (claro y oscuro) |
| `src/Resources/lang/{es,en}/comisiones.php` | clave `devolucion` |
| `tests/Demo/preparar_asesores.php`, `preparar_cargues_v3.php`, `confirmar_costos_demo.php`, `recorridos/l.php` (nuevos) y `recorridos.php`, `visual/run_demo.cjs` (ajustes) | preparación y recorrido L reproducibles |

## 6. Cómo repetirlo
```
DEMO_ENV_ARCHIVO=<env con DB_DATABASE=lpz_saas_demo_… y DEMO_PORT propio> DEMO_CLAVE_ARCHIVO=<archivo con la clave>
demo.sh crear-base · demo.sh php artisan migrate --force · demo.sh preparar · demo.sh php …/preparar_asesores.php
demo.sh php …/preparar_cargues_v3.php · demo.sh php …/confirmar_costos_demo.php   (tras la 1.ª tanda)
servidor con router_demo.php (max_execution_time alto) + worker de Cargues · demo.sh php …/recorridos.php L
```
`recorridos.php L` es reanudable (estado en `storage/logs/demo/estado_L.json`; borrarlo al cambiar de base).

## 7. Límites / pendiente
- La definición de devolución total (retorno confirmado, no solo recepción) y las franjas 10 % / 25 % son propuesta: **pendiente de que Camilo las confirme**.
- Vistas de móvil: Chrome headless con viewport 390 px; iPhone físico no probado.
- Nueva venta se ejerció por la API que usa la pantalla; la revisión visual de la pantalla está en las capturas (ver `storage/logs/demo/capturas/asesores_b/`).
- **Pruebas automáticas (base `lpz_saas_testing`, una suite a la vez):** `Com08DevolucionTotalTest` (nueva) **3 de 3** — paquete devuelto completo cuenta, devolución parcial no cuenta, sin envíos → sin porcentaje (nunca 0 %), franjas 10 % / 25 %. `Com06PanelAvanceTest` tras el cambio **8 de 8** (94 aserciones, 401 s; un primer intento coincidió con otra sesión en la misma base y se detuvo). Pint pasa en los archivos tocados.
- Capturas (Chrome headless, HTTP 200, desbordamiento horizontal 0 px, sin errores de consola inesperados): Karen (Mis ventas 390, Mis comisiones 1440/390, Nueva venta 1440/390), Wendy (Mis ventas 390), Laura (Mis comisiones 1440), Asesores del administrador (1440 claro, 1440 oscuro, 390) y Comisiones del administrador (1440, 390). No se capturó Wendy/Laura en el resto de pantallas ni todo el flujo de Nueva venta clic a clic.
- Producción no verificada. La migración `2026_11_02_000001` (soportes de abono) y la de cotizaciones siguen sin aplicar en desarrollo.
