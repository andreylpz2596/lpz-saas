# 00 · Base limpia para el arranque en producción — entrega

Fechas: 2026-11-15 (ronda 1) y 2026-11-16 (ronda 2, decisiones de Camilo). Fuente: `lpz_saas` (MariaDB 10.4.28, 127.0.0.1:3306), **intacta** (comparada al final con la restauración del respaldo: 271 tablas, rutinas y AUTO_INCREMENT iguales; la única diferencia de CHECKSUM es `bylopez_lg_validaciones_entrega`, idéntica fila a fila). Código: rama `upgrade/bagisto-2.4.9`; commit integrado en §8.

**No se declara «lista para producción»**: quedan los puntos de §6 y el despliegue no se ha hecho.

## 1. Resultado
| | |
|---|---|
| Base limpia | **`lpz_saas_preproduccion_limpia`** (272 tablas, 8 procedimientos `sp_cg_*`, AUTO_INCREMENT conservados) |
| Exportación final | `C:\Users\Andrey Lopez\ByLopez_respaldos\20261115\lpz_saas_preproduccion_limpia_20261116_r2.sql` — SHA-256 `3c31b3a36143a9de5e288a5100aaa8d4d59e8cf8c96f77cbf50b9993e491e566` (archivo `.sha256` al lado). Restaurada en `lpz_saas_preproduccion_limpia_r2verif`: 272 tablas, 0 CHECKSUM distintos, rutinas y AUTO_INCREMENT iguales |
| Respaldo completo de `lpz_saas` | `…\20261115\lpz_saas_completo_20261115.sql` (+ `.sha256`), restauración verificada en `lpz_saas_restore_verif_20261115` |
| Registros de ejecución | `…\20261115\registros\*.json` (simulaciones, ejecuciones y repeticiones de ambas rondas) |
| Evidencia de pruebas | `…\20261115\evidencia_pruebas\` (ronda 1) y `…\evidencia_ronda2\` (JSON por corrida, capturas del navegador, CSV sintéticos) |
| Cuentas y fichas eliminadas | `…\20261115\fichas_y_cuentas_eliminadas_r2.txt` (privado: datos para recrear la ficha de asesora, su acuerdo y las cuentas) |
| Herramientas (sin datos) | `tools/limpieza-preproduccion/`: `limpiar_base.php`, `limpiar_base_listas.php`, `verificar_copia.php`, `generar_matriz.php`, `pruebas_ronda2.php` (y `pruebas_copia_desechable.php`, de la ronda 1, que supone clientes conservados) |

Todo lo privado está fuera del repositorio. Matriz por tabla: `01_MATRIZ.md`.

### Conteos (respaldo original → base final)
Filas totales **36.519 → 21.119** (ronda 1 dejó 23.054).

| Módulo | Antes | Después |
|---|---|---|
| Pedidos / facturas / pagos / comisiones generadas | 79 / 77 / 33 / 58 líneas | 0 |
| Logística (guías) / Corte y Confección (rollos, órdenes) | 42 / 39 rollos, 25 órdenes | 0 |
| Existencias físicas: filas / unidades | 311 / 10.879 | 304 / **0** |
| Índice vendible: filas / unidades · reservas | 404 / 10.870 · 168 | 382 / **0** · **0** |
| Clientes / direcciones / teléfonos | 47 / 270 (44 de cliente) / 42 | **0 / 0 / 0** |
| Clientes históricos (staging) / ejecuciones de Cargues | 258+18+197 / 33 | **0 / 0** |
| Cuentas de acceso / roles | 13 / 12 | **1 / 8** |
| Fichas de asesor / acuerdos de comisión | 2 / 1 | 0 / 0 (ver §3) |
| Talleres / cortadores / proveedores de tela | 2 / 1 / 3 | **iguales** (talleres y cortador sin cuenta vinculada) |
| Productos (simples / configurables / agrupados) | 408 | **383** (304 / 78 / 1) |
| Categorías / atributos / opciones / familias / imágenes | 19 / 34 / 71 / 5 / 5 | **iguales** |
| Tipos de cargue / tarifas logísticas / tarifas de prestador / configuración | 5 / 5 / 14 / 123 | **iguales** (configuración: 3 valores cambiados, §3) |
| Consecutivos de guía interna y liquidación | 18 / 4 | **iguales** (la próxima guía sale GI-000019) |

## 2. Cómo se hizo
1. Inventario de solo lectura: 271 tablas, 412 FK, sin vistas, triggers ni eventos, 8 procedimientos; relaciones sin FK, JSON y archivos (`01_MATRIZ.md`).
2. Respaldo con `mysqldump --single-transaction --routines --events --triggers --hex-blob`, restaurado y comparado (restaurar con `mysql --comments`, o se pierden los comentarios de las rutinas).
3. Copia `lpz_saas_preproduccion_limpia` desde ese respaldo. Destino comprobado en cada etapa (`SELECT DATABASE()`, servidor, usuario).
4. **Credencial restringida** `lpz_limpieza@127.0.0.1`: solo SELECT/INSERT/UPDATE/DELETE por tabla en la copia. El limpiador comprueba que no pueda leer `lpz_saas` ni `lpz_saas_testing`.
5. **Limpiador** (`limpiar_base.php`):
   - Se niega si la base no es `lpz_saas_preproduccion_*` (antes de conectar), si el usuario es root o puede leer la fuente, o si hay una tabla sin clasificar o no InnoDB.
   - También se niega si alguna fila conservada apunta a una que se borra (descontando los vínculos que se anulan), o si la cuenta conservada no existe, está inactiva o no tiene acceso total.
   - Orden de borrado según el grafo de FK; todo en **una transacción** (solo DELETE/UPDATE; sin TRUNCATE, DDL, `migrate:fresh` ni `db:wipe`), con verificación interna antes del COMMIT.
6. Ronda 1: ejecución de 13.727 filas, repetición 0. Ronda 2: simulación → ejecución de 1.940 filas → repetición 0. Después se activaron los generadores (2 filas) y su repetición dio 0.
7. Índice de inventario reconstruido con `php artisan indexer:index --type=inventory --mode=full`.
8. Migración aditiva `2026_11_16_000001_create_bylopez_va_consecutivos_visibles_table` aplicada a la copia (`migrate --path`). `migrate:status --pending` sobre la copia: ninguna pendiente.

## 3. Decisiones aplicadas en la ronda 2
**Facturas y numeración**
- **Sufijo de las nuevas facturas `-2026`.**
  - Dónde se cambia: Configuración › Ventas › Configuración de facturas › Número de factura › **Sufijo**.
  - En la base es `core_config.code = 'sales.invoice_settings.invoice_number.invoice_number_suffix'`, canal `default`, idioma `es`.
- **Numeración visible desde 1 conservando los contadores internos.**
  - Hallazgo de las pruebas: Bagisto arma el número con *id de la última fila + 1*. Con las tablas vacías el primero sale 1, pero el segundo salía `BYL-00000105-CO`, porque toma el AUTO_INCREMENT conservado (104).
  - Solución, sin tocar Webkul: el punto de extensión «clase generadora» de Bagisto, con dos clases nuevas en `packages/ByLopez/VentasAsesor/src/Generators/` (`ConsecutivoPedido`, `ConsecutivoFactura`).
  - Guardan el último número usado en `bylopez_va_consecutivos_visibles`. La fila se bloquea (`FOR UPDATE`) dentro de la transacción que crea el documento: dos creaciones simultáneas se serializan, y si una se revierte el número no se pierde.
  - Prefijo, longitud y sufijo siguen saliendo de la configuración.
  - Se activan en Configuración › Ventas › Número de pedido › **Generador** y en Número de factura › **Clase generadora**. Vaciar esos campos vuelve al comportamiento de Bagisto.
  - El primer pedido saldrá `BYL-00000001-CO` (id interno 104) y la primera factura `FAC-BYL-00000001-2026` (id interno 102).
  - Si se activa en una base con documentos, el contador arranca desde el último id, sin repetir números ya emitidos.

**Clientes**
- Todos eliminados, incluido el 16, con sus direcciones, teléfonos, antecedentes, pendientes y suscripciones.
- También los clientes históricos, su staging y **todas** las ejecuciones de Cargues con sus filas e incidencias.
- Se conservan grupos de clientes, reglas de precio y definiciones, versiones y procedimientos de Cargues.

**Productos eliminados** (25 filas, procedencia confirmada)

| Id | SKU | Evidencia |
|---|---|---|
| 2445, 2446, 2447 | UUID v3 (`8b63bc92-…`, `6227ccb9-…`, `57f6644f-…`) | `Product::factory()` de Bagisto que usan las pruebas Pest: SKU `faker->uuid`, familia 1, creados el mismo segundo, sin nombre ni ficha, solo atributo `cost` |
| 6146 | `PRUEBA-CARGUES-99ENV` | «PRUEBA CARGUES 99 ENVÍOS (no vender)», inactivo |
| 6147–6167 | `PRUEBA-CARGUE-CAM-*` (7 configurables y 14 variantes) | Creados por las pruebas del tipo «Productos — Camisetas» (doc. 17 de Cargues, ids 6147/6151 citados allí) |

Con ellos salieron sus valores de atributo, categorías, canales, `product_flat`, índices de precio e inventario, súper atributos y 4 filas de historial de costo. El cargue de productos nunca crea opciones, así que no había opciones exclusivas que borrar.

**Cuentas y roles**
- Se conserva solo la **cuenta 1**, la que registra toda la configuración: los 5 tipos y 13 versiones de Cargues, 14 tarifas de prestador, tarifas logísticas, el acuerdo de comisión y 73 borradores.
  - La cuenta 2, también administradora, no tenía ninguna actividad.
  - Su contraseña y hash no se tocaron (la verificación compara la fila completa con el respaldo).
- Las otras 12 cuentas se eliminaron, y con ellas sus `api_token` y `remember_token`. `sessions` y `personal_access_tokens` quedaron vacías.
  - Las sesiones en archivos de `storage/framework/sessions` son de la instancia de desarrollo; en el servidor nuevo no existirán.
- Talleres (2) y cortador (1) se conservan con `usuario_id = NULL` (FK nulable).
- Las 2 fichas de asesor y el acuerdo de comisión **se eliminaron**: `bylopez_va_asesores.admin_id` es NOT NULL con FK RESTRICT, así que el modelo no permite una ficha sin cuenta. Los datos para recrearlos están en el archivo privado de §1.
- Roles eliminados: los 4 «QA Fase 1» sin usuarios (12059, 12060, 12061, 12065). Se conservan Administrador, ASESOR VENTAS, BODEGA, TALLER CONFECCION, CORTADOR y los 3 ambiguos de §6.

## 4. Verificación de la base final (`verificar_copia.php`: todo correcto)
- 103 tablas conservadas completas, idénticas fila a fila al respaldo. `migrations` es idéntica salvo la migración nueva nombrada.
- Tablas parciales o ajustadas: las filas conservadas son idénticas salvo las columnas ajustadas (qty, valor de configuración, `usuario_id`, índice reconstruido), y no queda ninguna fila del alcance de borrado.
- Stock físico 0, índice vendible 0, sin reservas, rollos ni movimientos. 144 tablas vaciadas en 0.
- 412 FK y 16 referencias lógicas sin huérfanos.
- Estado final:
  - Solo la cuenta 1, con rol de acceso total.
  - Sufijo `-2026` y las dos clases generadoras configuradas.
  - Sin productos de prueba ni roles QA.
- Re-verificada después de todas las pruebas: las pruebas solo tocaron copias desechables.

## 5. Pruebas (copia desechable `lpz_saas_preproduccion_pruebas_20261116b`, datos sintéticos)
Dos servidores `php -S` y el worker de Cargues, con el usuario restringido, caché y sesión aislados y correo `array`, apuntando a la copia desechable.

Para el acceso de la cuenta 1 se fijó una **contraseña temporal solo en esa copia**; al terminar se repuso el hash original. La contraseña real no se usó ni se cambió en ninguna otra base.

**HTTP (`pruebas_ronda2.php`): 11 de 11 pasan.** La primera corrida falló en R5/R6 por la numeración (hallazgo de §3) y en R2 por las ciudades del CSV; ver §6.

| Caso | Resultado |
|---|---|
| R1 Acceso de la cuenta 1 y 10 pantallas (panel, clientes, productos, Nueva venta, Cargues, usuarios, roles, configuración de facturas, despachos, asesores) | 302 al iniciar sesión; 200 en las 10 |
| R2 Carga de clientes desde cero con «Clientes históricos — 99 Envíos» (3 filas); repetirla | 3 clientes con teléfono E.164 indexado, dirección y antecedente; repetir no duplica nada |
| R3 Carga inicial de inventario 20/20; repetirla | Stock e índice 20/20, un movimiento por variante; repetir no cambia nada |
| R4 Crear 4 usuarios (ASESOR VENTAS, BODEGA, TALLER, CORTADOR), ficha de asesor, crear y eliminar un rol | Los 4 inician sesión y reciben 401 en Usuarios; el asesor abre Nueva venta; el rol se crea y se elimina |
| R5 Tres pedidos y tres facturas seguidos | `BYL-00000001/2/3-CO`, `FAC-BYL-00000001/2/3-2026` |
| R6 Dos pedidos y dos facturas simultáneos, uno a cada servidor (peticiones en vuelo a la vez) | HTTP 200/200 y 302/302; `BYL-00000004/5-CO`, `FAC-BYL-00000004/5-2026`; sin duplicados ni errores |

**Navegador real (Chrome sin cabeza, formularios y pantallas): 8 de 8 pasan.** Capturas en `evidencia_ronda2\nav_*.png`.
- Inicio de sesión por el formulario.
- Listado con los 3 clientes cargados.
- Listados de pedidos y facturas con la numeración nueva.
- Configuración de facturas mostrando `-2026` y la clase generadora.
- Formulario «Crear Usuario» (rol BODEGA) guarda y el usuario aparece en el listado.
- Nueva venta encuentra por teléfono a un cliente cargado.
- Cargues abre.
- Sin errores de consola ni respuestas 5xx.

Ronda 1 (base con clientes; `lpz_saas_preproduccion_pruebas_20261115`): conteo físico absoluto e idempotente, Nueva venta sin stock rechazada y despacho sin existencias rechazado (409 `stock_insuficiente`). Detalle en la evidencia de esa ronda.

Después de las pruebas se borraron los 8 archivos de carga subidos (comprobados contra `archivo_ruta` de las copias) y se revocaron los permisos temporales, incluido `SELECT` sobre `mysql.proc`. `lpz_limpieza` conserva solo DML sobre la base limpia.

## 6. Pendientes y casos ambiguos (no se tocaron)
1. **Productos ambiguos conservados:**
   - 6141 «Copia de Camiseta Piel Durazno Azul rey i Niño» (`temporary-sku-179332`, activo, 4 variantes): tiene ficha completa y 12 partidas vendidas en la base original, así que no se puede confirmar que sea de prueba.
   - 6140, agrupado `315794` sin ficha ni atributos: no coincide con la fábrica de pruebas y parece una creación abandonada.
2. **Roles ambiguos conservados:** «CLIENTE FRECUENTE» (12070), «CLIENTE FRECUENTE 2» (12069) y «BODEGA» (12071, **acceso total**; AUD-023). Los crearon las pruebas del 2026-09-20 junto con cuentas sintéticas, pero su nombre no es de QA y tienen permisos configurados. Hoy no tienen usuarios. Se recomienda eliminar o corregir el 12071 antes de asignarlo.
3. **Tipo de cargue 1 «Prueba E1 (sintético)»**: definición de prueba, activa. No estaba en las decisiones; se conservó. Se recomienda desactivarlo.
4. **Nombres de ciudad en la recarga de clientes:** «Bogotá» y «Cali» no se resuelven contra DIVIPOLA (los oficiales son «Bogotá, D.C.» y «Santiago de Cali»). El cliente se crea, pero la dirección queda «pendiente de completar» (`UBICACION_NO_ENCONTRADA`). Conviene revisar el archivo real antes de cargarlo.
5. **Generadores de numeración:**
   - Probados en la copia (secuencial y 2 simultáneos), sin suite Pest propia.
   - Serializan la creación de pedidos y facturas mientras dura la transacción; con el volumen actual es aceptable.
   - En la base de desarrollo `lpz_saas` la migración nueva queda pendiente y los generadores no están activados (código inactivo hasta configurarlo).
6. Fotos de perfil de cuentas eliminadas en `storage/app/admins/`: no copiarlas al servidor.

## 7. Pasos para el servidor (sin desplegar todavía)
1. Copiar la exportación final y su `.sha256`; verificar con `sha256sum -c`.
2. Crear la base vacía y restaurar cambiando el nombre en las líneas `CREATE DATABASE` / `USE` (22 y 24), con `mysql --comments --default-character-set=utf8mb4`.
3. Confirmar:
   - 272 tablas y 8 procedimientos.
   - `orders` = 0, `customers` = 0, `SUM(qty)` de `product_inventories` = 0.
   - `admins` = 1 fila.
4. Desplegar el commit de §8 y correr `php artisan migrate:status` (0 pendientes).
5. La cuenta MySQL de la aplicación debe poder **leer la definición de los procedimientos** (ser su DEFINER o tener `SELECT` sobre `mysql.proc`). Si no, Cargues rechaza toda carga con `PROCEDIMIENTO_CAMBIO`. Revisar también el DEFINER `root@localhost`.
6. Archivos: solo las carpetas «Incluir» de `01_MATRIZ.md`.
7. `php artisan optimize:clear`, worker de Cargues (`queue:work cargues --queue=cargues`) y cron.
8. Orden de carga:
   - Usuarios definitivos (y fichas de asesor y acuerdos).
   - Clientes con «Clientes históricos — 99 Envíos».
   - Inventario con «Inventario — Actualizar cantidades (conteo físico)», con un conteo del mismo día y solo variantes `simple`.

## 8. Commit integrado
El cargue de inventario (tipo 5, con su procedimiento, extensión y el movimiento `conteo_fisico` de CorteConfeccion), los generadores de numeración con su migración, las herramientas y esta documentación quedan en un solo commit de la rama `upgrade/bagisto-2.4.9`: **`c5527ebe94`** (este documento se actualizó en el commit siguiente solo para anotar el hash). No incluye SQL, credenciales ni datos personales. La exportación final se tomó con exactamente ese código y su migración aplicada.

## 9. Incidencias del entorno (informadas, no corregidas)
- **`mysql.db` (Aria) corrupta en el XAMPP**: `CREATE USER` y los `GRANT … ON base.*` fallan; por tabla y por procedimiento funcionan. No se reparó (afecta a todas las sesiones).
- Bases auxiliares que siguen existiendo (eliminarlas cuando Camilo autorice):
  - `lpz_saas_restore_verif_20261115`: copia completa de la fuente, **con datos personales**.
  - `lpz_saas_preproduccion_pruebas_20261115`, `…_20261116` y `…_20261116b`: datos sintéticos.
  - `lpz_saas_preproduccion_limpia_r2verif`: restauración de la exportación final.
- La exportación de la ronda 1 (`lpz_saas_preproduccion_limpia_20261115.sql`) aún contiene clientes y queda superada por la de la ronda 2.
