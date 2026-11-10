# Cierre de validación Corte y Confección — 2026-10-31

Complementa `INFORME_VALIDACION_2026-10-29.md`. Sin commit, push ni despliegue. `packages/Webkul/`, `vendor/` y `bootstrap/` sin cambios.

## 1. H11 — anulación por el rol CORTADOR (corregido)

- Causa: `OrdenConfeccionGate::puedeOperar()` solo exigía la ACL `operar` + ownership; un CORTADOR con `operar` o `permission_type=all` pasaba.
- Corrección: el rol `cortador` queda vetado en servidor (`Support/OrdenConfeccionGate.php`).
- Reproducción previa: con el gate original la prueba devolvió **302** (la anulación pasaba) en lugar de 403.
- Pruebas (`CierreValidacionH11CustodiaBodegaTest`): POST directo sobre orden ajena como CORTADOR `all` y CORTADOR `operar` → **403**, estado, cantidades y disponible del corte idénticos; usuario autorizado (BODEGA con `operar`) anula y el disponible pasa de 60 a 100; siguen bloqueadas las órdenes con ENT y con reasignación vigente.

## 2. H10 con concurrencia real (dos procesos, dos conexiones)

Base `lpz_saas_testing`, barrera de arranque simultáneo, petición HTTP real por el kernel (middleware + FormRequest + controlador + servicio). Endpoints probados:

| Prueba | Endpoint | Resultado |
|---|---|---|
| RCC-5 | `POST admin/corte-confeccion/ordenes-corte/reportar/{id}` (`OrdenCorteController@reportar`), mismo resultado con 1 adicional | una 302 y una **422**; 2 detalles (1 adicional), cantidades 20 y 6, 2 filas de auditoría, estado `reportado_por_cortador` |
| RCC-6 | `POST admin/corte-confeccion/recepciones-corte/create/{id}` (`RecepcionCorteController@store`), 2 productos | una 302 y una **422**; 1 recepción, 2 detalles, 50 unidades, estado `recibido_bodega` |

Mutación de control: sin el `lockForUpdate` de `OrdenCorteService::reportarResultado` y `RecepcionCorteService::create` ambas pruebas **fallan**; con él pasan. Evidencia: `evidencia_2026-10-29/cierre_2026-10-31/RCC-5.json`, `RCC-6.json`.

## 3. Custodia física (fallo hallado y corregido)

- Hallazgo: «Cancelar traslado» / «Revertir» solo exigía un motivo de texto; con las prendas aún en carretera bastaba pulsar el botón para devolverlas al saldo operativo de Jairo.
- Lo que sí se cumplía: con 100 M en tránsito, ni el destino (sin confirmar recepción) ni el origen (máximo 200) pueden reportarlas.
- Corrección: si la reasignación es `entregada_verificada` (las prendas salieron del taller origen), revertir o cancelar exige **constancia de devolución** (quién/qué traslado) y casilla de **confirmación de que están físicamente de regreso**; se guardan en `constancia_cierre` (migración aditiva `2026_10_31_000001`, aplicada en `lpz_saas`, `lpz_saas_testing` y la demo). Si nunca hubo salida física (`sin_entrega_fisica`) no se exige.
- Pruebas: POST sin confirmación → error de validación; con confirmación sin constancia → error; servicio sin constancia → error; estado, `cantidad_reasignada_salida` y pendiente intactos; con constancia → `cancelada`, Jairo vuelve a 300 pendientes, `cerrada_por` y constancia auditados. R16 actualizada para pasar la constancia (se endureció, no se debilitó).
- Límite: la constancia es declarativa (texto + confirmación de quien opera con la ACL); el sistema no puede verificar físicamente las prendas.

## 4. Migraciones y H07 (conciliado)

- Consulta de solo lectura: conexión `mysql`, host 127.0.0.1, base `lpz_saas`; tabla `migrations` con `2026_10_29_000001` (id 284) y `…000002` (id 285), batch 72; 0 detalles sin familia, 0 reasignaciones. El documento 06 y `STATUS.md` decían «pendiente en dev»: eran textos desactualizados y ya están corregidos. Hoy se añade `2026_10_31_000001` (batch 73).
- H07: la prueba existente se escribió y ejecutó **después** del cambio (el fallo no se observó antes; solo se identificó por lectura de código). Las filas históricas recibieron la familia **vigente del producto al migrar (29-oct-2026)**, no necesariamente la original; desde entonces se conserva al crear cada detalle.

## 5. BODEGA y iPhone

- Prueba `BODEGA: sin la ACL reasignar…`: sin la ACL la ficha abre pero **no hay botón**, y `GET contexto` / `POST reasignar` responden 401/403 sin crear nada; con la ACL aparece el botón y ambos endpoints funcionan.
- Dónde asignarla (entorno operativo): menú **Configuración → Roles** (`/admin/settings/roles`) → editar el rol BODEGA → Permisos personalizados → módulo Corte y Confección → Órdenes de confección → **«Reasignar prendas»** (`corte_confeccion.ordenes_confeccion.reasignar`) → Guardar. Cerrar sesión y entrar de nuevo.
- Hallazgos de iOS corregidos en el modal (`reasignaciones-orden.blade.php`): (a) los listeners se ligaban a nodos que Bagisto/Vue reemplaza después; en WebKit **el botón no abría el modal** → ahora hay delegación de eventos y el modal se resuelve en cada evento; (b) los campos eran de 14 px y Safari hace zoom al enfocarlos → ahora 16 px en móvil; (c) se añade área segura inferior en la barra de botones. CSS reconstruido con `npm run build` del paquete.
- Ensayo previo (no es Safari físico): WebKit con perfil iPhone 15 Pro Max, 13/13 pasos OK (`iphone_webkit_salida.txt`, capturas) y flujo del modal en Chromium OK (390 y 1440). WebKit móvil no emula rueda/teclado virtual: el desplazamiento se probó por `scrollBy`; el gesto táctil y el teclado reales solo los valida el iPhone.

### Caso de demo para tu iPhone

Base aislada `lpz_saas_demo_cc_20261029`. Orden de Jairo **730 no se tocó** (se ensayó en otras órdenes). Si se consume, `tests/Demo/preparar_demo_iphone.php` crea otra.

1. En el PC, con el PC y el iPhone en la misma Wi‑Fi (PowerShell, en `C:\xampp\htdocs\lpz-saas\public`):
   `$env:DB_DATABASE='lpz_saas_demo_cc_20261029'; $env:APP_DEBUG='false'; $env:DEBUGBAR_ENABLED='false'; php -d zend_extension=opcache -d opcache.enable_cli=1 -S 0.0.0.0:8030 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php`
   (sin `APP_DEBUG=false` la barra Debugbar tapa los botones). Si Windows pregunta por el firewall, permitir red privada.
2. En Safari: `http://192.168.1.4:8030/admin/login` (IP del PC al 2026‑10‑31; verificar con `ipconfig`). Usuario `cc.bodega@bylopez.test`; la clave está en `storage/logs/demo/credenciales_demo_iphone.txt` (ignorado por git). Usuario sin ACL para contraste: `cc.bodega.sin@bylopez.test` (no debe ver el botón).
3. Abrir `http://192.168.1.4:8030/admin/corte-confeccion/ordenes-confeccion/view/730`.
4. Recorrido: desplazar la ficha desde el centro → «Reasignar prendas» → desplazar el modal desde el centro → destino «Taller Andrea (iPhone)» → en la fila M escribir `100` y en Motivo un texto, con el teclado abierto (comprobar que no hay zoom y que los botones son alcanzables) → Cancelar (cierra) → desplazar la ficha → abrir de nuevo y **Reasignar prendas** → ver el historial. Extra: la reasignación RA «en tránsito» de la ficha → «Cancelar traslado» debe pedir constancia y casilla. Repetir en modo oscuro.
5. Detener el servidor con Ctrl+C (o cerrar la ventana).

## 6. Pendientes diferenciados

- **Requiere tu acción:** prueba en iPhone físico y modo oscuro; asignar la ACL `reasignar` a BODEGA en el entorno operativo.
- **Fuera de esta fase (por tu instrucción):** cuenta/pagos a prestadores y N1.
- **No medido:** rendimiento con volumen/N+1.
- **Observado, no del módulo:** el script de Laravel Boost (`/_boost/browser-logs`) falla en la demo y la imagen `storage/configuration/*.png` da 403 en la demo clonada.
