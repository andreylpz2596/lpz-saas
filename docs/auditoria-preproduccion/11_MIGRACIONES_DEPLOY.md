# 11 · Migraciones y despliegue

## 1. Estado de migraciones [C]

- `migrate:status` en **dev**: 0 pendientes. Total ByLopez: VentasAsesor 37, CorteConfeccion 46, Logística 22, Cargues 1.
- **11 migraciones sin versionar** (ejecutadas en dev):

| Paquete | Migración |
|---|---|
| CorteConfeccion | `2026_10_29_000001_create_bylopez_cc_reasignaciones_tables`, `…000002_add_attribute_family_snapshot_to_detalles`, `2026_10_31_000001_add_constancia_cierre_to_reasignaciones`, `2026_11_01_000001_create_bylopez_cc_cartera_tables`, `2026_11_02_000001_add_autorizacion_remitente_to_cartera_operaciones`, `…000002_add_cantidades_prioridad_to_ordenes_confeccion_detalles`, `…000003_create_prioridad_cambios_y_notificaciones`, `2026_11_03_000001_create_stock_minimos_y_reparto_prioridad` |
| VentasAsesor | `2026_11_03_000001_create_…creditos_cliente_movimientos`, `2026_11_03_000002_create_…ajustes_obligacion`, `2026_11_04_000001_create_…cotizaciones` |

- Además la migración `2026_11_02_000001_create_bylopez_va_comision_soportes_and_trace_columns` (mía) está commiteada y aplicada en testing/demo y dev.
- Procedimientos almacenados de Cargues: **no son migraciones**; se instalan fuera del migrador (ver doc 06).
- Migraciones destructivas: no se hizo revisión de `down()`/`dropColumn`/`dropIfExists` migración a migración (V-20). Los `down()` observados (p. ej. transportadoras) borran la tabla: **no ejecutar `migrate:rollback` en producción**.
- Migración que siembra datos: `…create_bylopez_lg_transportadoras_table` hace `insertOrIgnore` de catálogo (idempotente).

## 2. Orden seguro propuesto para producción

1. Congelar y **versionar** todo el trabajo pendiente (AUD-001) en commits por objetivo; etiquetar un candidato a release.
2. Restaurar copia reciente de producción en un entorno de ensayo (misma versión de motor).
3. Ejecutar `php artisan migrate --pretend` y revisar SQL; luego `migrate --force` **por orden cronológico** (el orden de nombre ya es el orden de dependencia dentro y entre paquetes; revisar cruces VentasAsesor↔Logística↔CorteConfeccion).
4. Instalar procedimientos de Cargues (v1…v3 y productos v1) y verificar con `SHOW CREATE PROCEDURE`; correr `cargues:proteccion-validar` (sin `--aplicar` primero).
5. Sembrar/configurar: claves ACL a roles reales (sin activar políticas pendientes ni comisiones globalmente), tipos de cargue, tarifas.
6. Humo de datos (§4 del doc 14) y recién entonces abrir.

## 3. Requisitos de despliegue [C/S]

| Tema | Hoy (dev) | Requerido en producción |
|---|---|---|
| `APP_ENV`/`APP_DEBUG` | `local` / `true` | `production` / `false` |
| Motor | MariaDB 10.4.28 | mismo motor/versión probada o ensayo completo; 10.4 ya salió de soporte (verificar) |
| Modo SQL | no estricto | decisión pendiente tras medir (doc 07 §3): `strict=true` solo si las suites de Bagisto y ByLopez lo toleran; no bloquea |
| Cola | default `sync`; Cargues en conexión `database` `cargues` | worker supervisado `queue:work cargues --queue=cargues --tries=1 --timeout=900` (obligatorio) y, si se quiere sacar la reevaluación de comisiones de la petición, `VENTASASESOR_COMISIONES_COLA_CONEXION` + segundo worker |
| Cron | no instalado | `* * * * * php artisan schedule:run` |
| Sesión/caché | `file` | `database`/`redis` si hay >1 nodo; `SESSION_SECURE_COOKIE`, HTTPS, `SESSION_ENCRYPT` |
| PHP | `upload_max_filesize=2M`, `post_max_size=8M`, `max_execution_time=30`, sin OPcache listado | ≥10 MB por archivo y ≥ (20×10 MB) si se permiten lotes; OPcache; revisar `max_execution_time` de OCR |
| Dependencias | `composer install --no-dev --optimize-autoloader` | sin Debugbar/Boost/Pest |
| Assets | `build` de Bagisto versionado; `build-bylopez` sin versionar | `npm ci && npm run build` en admin; decidir versionado de `build-bylopez` |
| Almacenamiento | `storage/app/private`, `storage/app` | volumen persistente con copia; `serve=false` |
| OCR | `CC_TESSERACT_BIN`, `CC_TESSDATA`, `CC_POPPLER_DIR` de Windows | instalar equivalentes Linux o desactivar la lectura |
| Imagen Docker | genérica de Bagisto | adaptar o no usar; no incluye cron/worker/Tesseract |
| Backups | no verificados | volcado + `storage/app`, prueba de restauración documentada |

## 4. Requisitos mínimos y configuración recomendada de producción (2.ª etapa)

Base: lo verificado en el repositorio y en el entorno de dev (volumen pequeño: 79 pedidos, 408 productos, 47 clientes, tabla ByLopez más grande ≈1.900 filas). Lo marcado **(estimación)** es criterio de ingeniería, no una medición; los números de versión de terceros deben **confirmarse** en la documentación oficial de Laravel 12 / Bagisto 2.4 antes de contratar.

| Tema | Requisito mínimo | Recomendación | Origen |
|---|---|---|---|
| Servidor | Linux 64 bits, 2 vCPU, 4 GB RAM, 40 GB SSD **(estimación)** | 4 vCPU / 8 GB si el OCR (Tesseract) y los cargues corren en el mismo host **(estimación)** | datos de dev + OCR local |
| PHP | 8.3 (el `composer.json` exige `>=8.3 <8.5`), extensiones: calendar, curl, intl, mbstring, openssl, pdo, pdo_mysql, tokenizer + fileinfo, gd, zip, xml, bcmath, **OPcache** | PHP-FPM con OPcache activo; `memory_limit` ≥ 256M | `composer.json`, `php -m` |
| Límites PHP (dev: 2M / 8M / 30 s) | `upload_max_filesize` ≥ **20M** (Cargues acepta hasta 20 MB: `CARGUES_MAX_BYTES`), `post_max_size` ≥ 24M | `post_max_size` mayor si se mantienen 20 comprobantes × 10 MB por lote (o bajar `CC_COMPROBANTES_MAX_ARCHIVOS`); `max_execution_time` 120 s (OCR `tiempo_max` 120) | `cargues.php`, `comprobantes.php` |
| Base de datos | MySQL/MariaDB con InnoDB, utf8mb4; **solo MySQL/MariaDB** (Cargues los exige) | **MariaDB 10.11 LTS o MySQL 8.0/8.4**, nunca la 10.4 de dev (fuera de soporte; verificar fecha en mariadb.org). Toda versión distinta de 10.4.28 exige repetir las suites de Cargues y concurrencia, porque se probó solo en 10.4.28 | `SELECT version()` |
| Cuenta restringida de Cargues | existe y con permisos mínimos (`CARGUES_VALIDAR_DB_USERNAME`/`_PASSWORD`, `cargues:proteccion-validar`) | crearla antes del primer cargue; en dev la tabla `procs_priv` del XAMPP se corrompió (memoria) | `cargues.php` líneas ≈141-144 |
| Servidor web | Nginx o Apache con HTTPS | Nginx + PHP-FPM; el repo trae variantes `docker/production/*` (genéricas) | `docker/production` |
| **Cron** | una línea: `* * * * * cd /ruta && php artisan schedule:run >> /dev/null 2>&1` | verificar con `php artisan schedule:list`: debe aparecer `ventas-asesor:comisiones:evaluar --bandeja` cada 5 min (única tarea ByLopez) | `VentasAsesorServiceProvider` líneas ≈160-163 |
| **Workers / colas** | 1 worker obligatorio para Cargues: `php artisan queue:work cargues --queue=cargues --tries=1 --timeout=900 --sleep=1` (conexión `database`; `retry_after` 1500 > timeout 900) | Supervisor/systemd con reinicio; segundo worker `queue:work` por defecto **solo** si se activa `VENTASASESOR_COMISIONES_COLA_CONEXION` (hoy la reevaluación corre `sync`). Tras cada despliegue: `php artisan queue:restart`. Monitorear `failed_jobs` (existe, vacía) | `cargues.php`, `config()` |
| Cola por defecto | `QUEUE_CONNECTION=database` (o Redis) | evitar `sync` en producción para no ejecutar trabajos dentro de la petición | `.env` |
| Sesión / caché | `database` o Redis si hay más de un nodo; `file` solo en un nodo | `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `same_site=lax` (ya por defecto) | `config/session.php` |
| SSL | certificado válido y redirección 80→443 | HSTS ya lo emite Bagisto (`SecureHeaders`, `max-age=31536000`): **no activar HTTPS a medias** (el navegador recordará el HSTS) | `SecureHeaders.php` |
| Almacenamiento privado | `storage/app/private` y `storage/app` con permisos del usuario del servidor, **fuera** del docroot; `serve=false` en discos `local` y `private` | volumen persistente y respaldado; los PDF de guías, comprobantes de pago, comisiones y cartera de prestadores viven ahí (no son recuperables desde la BD) | `config/filesystems.php` |
| Backups | volcado lógico diario de la BD (incluye procedimientos: `--routines`) **+** copia de `storage/app` **+** `.env` guardado aparte y cifrado | retención ≥ 30 días, copia fuera del servidor, **prueba de restauración trimestral**; ensayar la restauración en el modo SQL de producción (hay 11 fechas `0000-00-00` que un modo estricto rechaza al restaurar) | doc 07 §3 |
| Logging | `LOG_CHANNEL=daily`, `LOG_LEVEL=warning`/`error`, `LOG_DAILY_DAYS=14` | rotación y alerta sobre errores; no registrar cuerpos de petición (hoy no se hace) | `config/logging.php` |
| Laravel | `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` propio y respaldado, `APP_URL` https | `php artisan config:cache route:cache event:cache view:cache` (probar antes: cualquier `env()` fuera de `config/` dejaría de funcionar), `composer install --no-dev -o`, `storage:link` solo si se usa disco público | `artisan about` |
| Correo | `MAIL_MAILER` real (hoy `log`) | definir antes de abrir (notificaciones) | `.env` |
| OCR de comprobantes | instalar Tesseract + Poppler **o** `CC_COMPROBANTES_ACTIVO` apagado y lectura manual | `CC_COMPROBANTES_LECTOR=tesseract`; sin `ANTHROPIC_API_KEY` | `comprobantes.php` |
| Roles | revisar roles `all` (AUD-023) y asignar claves D10/`view_own` solo a roles aprobados | 2FA para administradores | doc 10 |
