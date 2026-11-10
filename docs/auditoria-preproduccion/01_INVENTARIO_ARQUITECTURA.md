# 01 · Inventario y arquitectura (Fase 1, solo lectura)

Fecha de la auditoría: 2026-11-09 · Rama: `upgrade/bagisto-2.4.9` · Último commit: `849fa26e23`.
Método: inspección de código, configuración y consultas de lectura (`artisan route:list`, `migrate:status`, `about`, `information_schema`). No se ejecutaron migraciones, pruebas, cargues ni escrituras.

## 1. Plataforma

| Elemento | Valor verificado | Fuente |
|---|---|---|
| Laravel | 12.62.0 | `php artisan --version` |
| Bagisto | 2.4.9 (rama de actualización) | rama + `composer.json` |
| PHP | 8.3.33 ZTS (composer exige `>=8.3 <8.5`) | `php -v` |
| Extensiones cargadas | bcmath, curl, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, zip, xml… (**sin** `opcache`, `exif`, `sodium`, `redis`) | `php -m` |
| Base de datos (dev) | **MariaDB 10.4.28** (XAMPP), `sql_mode=NO_ENGINE_SUBSTITUTION`, `innodb_lock_wait_timeout=50`; 121 tablas `bylopez_*` | consulta de lectura |
| Composer | 2.8.3 · Node 18.17.1 · Vite 6.4.x | `artisan about`, `package.json` |
| Pruebas | Pest 3; 153 archivos de prueba ByLopez (VentasAsesor 125, CorteConfeccion 58*, Logistica 21, Cargues 20 — *conteo por carpeta `tests`, incluye helpers) | `find` |
| Imagen de producción | `docker/production/` (nginx/apache/litespeed) del propio Bagisto, **no adaptada** a ByLopez (no menciona cron, cola `cargues`, Tesseract ni procedimientos almacenados) | lectura |

## 2. Paquetes propios

| Paquete | PHP | Blade | Migraciones | Rutas | Pruebas | Notas |
|---|---|---|---|---|---|---|
| VentasAsesor | 434 | 44 | 37 | 71 | 125 | pedidos, cartera, comisiones, crédito D10, cotizaciones |
| CorteConfeccion | 314 | 72 | 46 | 108 | 58 | rollos, corte, confección, cartera de prestadores, consolidado |
| Logistica | 192 | 25 | 22 | 46 | 21 | despacho, guías, entregas, devoluciones, cuentas de transportadoras |
| Cargues | 86 | 4 | 1 (+ 7 tablas) | 30 | 20 | motor de cargas + procedimientos almacenados |

Registro: los 4 providers están en `bootstrap/providers.php`. Los 255 endpoints ByLopez usan middleware `admin` (Bouncer) + `NoCache`; **ninguno** queda fuera de autenticación (cruce de `route:list`).

## 3. Roles, permisos y rutas

- Bagisto 2.4.9 **falla cerrado**: el `Bouncer` niega con 401 toda ruta sin clave ACL para roles personalizados (`checkIfAuthorized`). Roles `permission_type=all` pasan sin revisar claves.
- Cruce rutas ↔ ACL (script de lectura): de 255 rutas ByLopez, **32 no tienen clave ACL**: 30 de Cargues (se protegen con el middleware `SoloAdministrador`, rol id configurable `CARGUES_ROL_ADMIN_ID`, defecto 1) y 2 de Logística (`admin.logistica.cuentas.propuesta`, `admin.logistica.cuentas.cobro`). Ver AUD-008.
- Controladores con comprobaciones explícitas de permiso: VentasAsesor 20/14 controladores, CorteConfeccion 40/15; Logística y Cargues delegan la autorización en servicios/middleware (contrato `AccesoGuiaPedido`, `SoloAdministrador`).

## 4. Colas, scheduler, caché, archivos

| Tema | Estado en dev | Observación para producción |
|---|---|---|
| Cola | Default `QUEUE_CONNECTION=sync`. **Cargues usa su propia conexión `cargues` (driver `database`, tabla `jobs`, cola `cargues`, `retry_after` 1500 s)**, verificado con `config()`; `jobs`/`failed_jobs` existen y están vacías | **Corrección de la 1.ª etapa:** `ProcesoCargaJob` NO corre en la petición: necesita un worker `queue:work cargues --queue=cargues --tries=1 --timeout=900` incluso en dev (así lo arranca `demo.sh`). `ReevaluarComisionesJob` sí usa la conexión por defecto (`conexion=null` → `sync`) y la reevalúa el scheduler cada 5 min |
| Scheduler | 1 tarea ByLopez: `ventas-asesor:comisiones:evaluar --bandeja` cada 5 min (`withoutOverlapping(30)`), registrada en `VentasAsesorServiceProvider` | depende de `schedule:run` por cron; sin cron la bandeja de reevaluación no avanza |
| Caché / sesión | `file` / `file` (sesión sin cifrar) | en varios workers o contenedores, `file` no se comparte |
| Disco | `local` (storage/app), `private` (storage/app/private), `public`; **`serve=true` en local y private** | Comisiones → `private`; Pagos → `private`; Guías PDF → `private`; Cargues → `local/private` configurable; comprobantes de CorteConfeccion → `local` |
| Config/rutas/eventos | no cacheados (vistas sí) | en producción: `config:cache`, `route:cache`, `event:cache` (probar: algún `env()` fuera de config rompería con caché) |
| Correo | `log` | notificaciones no salen |

## 5. Integraciones externas (qué es real y qué es catálogo)

- Transportadoras sembradas: `BYLOPEZ`, `99ENVIOS`, `INTER_RAPIDISIMO`, `DOMICILIARIO`, `RECOGIDA_BODEGA`, `OTRA`. **No existen** como catálogo Coordinadora, Servientrega ni Envía (Servientrega solo aparece en un comentario). Detalle en doc 05.
- **No hay clientes HTTP** hacia transportadoras en Logística, Cargues, VentasAsesor (búsqueda de `Http::`, `curl`, `guzzle`). Todo es manual o por cargue de archivos.
- Única referencia a servicio externo: `CorteConfeccion/src/Comprobantes/LectorClaudeVision.php` (API de Anthropic), apagada por defecto (`CC_COMPROBANTES_ACTIVO=false`, lector por defecto `tesseract` local). Ver AUD-010.

## 6. Estado de Git (clasificación de la segunda etapa, 2026-11-09)

Conteo: `git status` colapsado muestra 268 entradas (más la carpeta nueva de estos documentos); expandido son **456 archivos** (113 modificados + 343 nuevos) sin contar los 16 documentos de la auditoría. Webkul: 0. Cuentas: «archivos» = rutas individuales.

| Módulo | Modificados | Nuevos | Migraciones | Procedimientos | Pruebas | Docs | Binarios/evidencia |
|---|---|---|---|---|---|---|---|
| VentasAsesor | 44 | 43 | 3 | 0 | 22 (10 mod + 12 nuevas, incluye Concurrencia, Demo y helpers) | 4 (1 mod + 3) | `.rar` (1,6 MB) y `bash.exe.stackdump` |
| CorteConfeccion | 41 | 255 | 8 | 0 | 35 (4 mod + 31) | 148 (2 mod + 146, de ellos **141 son evidencia** `evidencia_*`: 105 PNG, 34 JSON) | `.rar` (6,9 MB), CSS compilado |
| Logística | 15 | 3 | 0 | 0 | 5 | 1 | — |
| Cargues | 12 | 36 | 0 | **4 archivos** (`sp_cg_envios_completos_v3` y `sp_cg_productos_camisetas_v1`: `.sql` + `.map.json`) | 3 | 21 | 9 PNG, 1 plantilla `.xlsx` sintética, 8 `.xlsx` SINTETICO |
| Otros | 1 (`.gitignore`) | 7 (+16 de esta auditoría) | 0 | 0 | 0 | `docs/LECTURA_COMPROBANTES_PAGOS.md` | 3 `.xlsx` en `docs/`, `grep.exe.stackdump`, `build-bylopez/corteconfeccion.css` |

### Líneas de trabajo identificadas (por nombre y contenido de archivos)

| Línea | Archivos clave | Notas de dependencia |
|---|---|---|
| **D10 crédito del cliente** (VentasAsesor + gancho en Logística) | `Services/Credito/*`, `CreditoClienteController`, migraciones `2026_11_03_000001/2`, `Models/CreditoClienteMovimiento`/`AjusteObligacion`, `lang/*/credito.php`, `Listeners/BloquearCancelacionConCredito`, `docs/28`, `tests/Concurrencia/D10*`, `helpersD10`, `D10*Test` (**también uno dentro de Logística**) | **Cruza paquetes**: `Logistica/Contracts/GuardaAnulacionRecepcion` y `Support/SinGuardaAnulacionRecepcion` (nuevos) los usan `AnularEventoLogisticoService`/`LogisticaServiceProvider` (modificados) y `VentasAsesor/Support/Logistica/GuardaAnulacionRecepcionConCredito`. Un commit de Logística sin el contrato rompe la app |
| **Cotizaciones** | `CotizacionController`, `Cotizacion*`, `Services/Cotizaciones`, migración `2026_11_04_000001`, `cotizaciones/pdf.blade`, `docs/30_COTIZACIONES`, `CotizacionTest` | toca `routes.php`, `acl.php`, `system.php`, `nueva-venta/index.blade` (archivos compartidos) |
| **Com08 devolución total / Avance** | `AvanceAsesorService` (+61 líneas), `Com08DevolucionTotalTest`, `asesores/devolucion.blade`, `estilos-panel`, `avance*.blade`, `PanelMensualService` | **se apoya sobre archivos que yo ya había commiteado**: el diff mezcla trabajo posterior de otra sesión |
| **Cargues v3 + Productos camisetas** | `Extensiones/EnviosCompletos`, `Extensiones/ProductosCamisetas`, SP v3 y camisetas, `ProductosCamisetasTest`, `EnviosCompletosHitosTest`, docs 16/17, `CargasService`, `EjecucionService`, `CarguesController`, vistas | los SP **no** los instala ningún código |
| **Reasignación de prendas** (CorteConfeccion) | migraciones `2026_10_29_*`, `2026_10_31_*`, `Reasignacion*`, docs 06/07, evidencia 10-29/cierre | — |
| **Cartera de prestadores** | migraciones `2026_11_01_*`, `2026_11_02_000001`, `Cartera*`, `Http/Controllers/Admin/CarteraController`, docs 08, evidencia cartera | — |
| **Comprobantes (lectura local)** | `src/Comprobantes/*`, `Config/comprobantes.php`, `docs/LECTURA_COMPROBANTES_PAGOS.md`, evidencia `lectura_local_2026-11-02` | **evidencia con datos reales** |
| **Consolidado y prioridades** | migraciones `2026_11_02_000002/3`, `2026_11_03_000001`, `ConsolidadoConfeccionController`, `StockMinimo*`, `Notificacion*`, docs 09, evidencia 11-03 | — |
| Compartidos | `routes.php` y `acl.php` (VentasAsesor y CorteConfeccion), `lang/*`, `00_STATUS.md`/`STATUS.md`, `menu.php`, `ServiceProvider` de cada paquete | cada archivo contiene hunks de varias líneas |

### Archivos sensibles o no versionables en el árbol

| Archivo / carpeta | Contenido | Estado Git | Riesgo |
|---|---|---|---|
| `docs/Cartera_Completa_2026-09-12.xlsx`, `docs/Envios_Completos_2026-10-03.xlsx`, `docs/pruebas_cargues/Envios_Completos_2026-10-03_PRUEBA_240099900001.xlsx` | exportaciones reales de 99 Envíos (destinatario, dirección, teléfono; doc 15 de Cargues §1) | **sin versionar, sin ignorar** (el `.gitignore` nuevo solo cubre `/packages/ByLopez/**`) | publicación accidental de datos personales |
| `CorteConfeccion/docs/confeccion/evidencia_2026-10-29/lectura_local_2026-11-02/` (9 PNG + `evaluacion_ocr_comprobantes_reales.json`) | **comprobantes reales** (nombres, teléfonos, referencias); su propio `LEEME.md` dice «no subir a git» | sin versionar, sin ignorar | ídem |
| `packages/ByLopez/*.rar` (8,5 MB), 2 `*.stackdump` | copias de código, volcados de crash | sin versionar, sin ignorar | peso, ruido, copias desactualizadas |
| `Cargues/docs/pruebas_cargues/*.xlsx` (8) y `plantillas/*.xlsx` | etiquetados SINTETICO | sin versionar; **el `.gitignore` nuevo los ignora** salvo `plantillas/*.xlsx` | verificar que de verdad sean sintéticos antes de versionar |
| `VentasAsesor/tests/Demo/demo.env` | **rastreado**; sin secretos (verificado: solo nombres de variables de demo) | rastreado | ninguno |

Verificación de historial: `git log --all` no muestra ningún `.xlsx/.rar/.zip/.env/.pem` propio agregado jamás (solo `.sql` de procedimientos, `demo.env` y los de Bagisto/upstream). El historial de `.env`/`.env.testing` corresponde a commits de 2022 del proyecto upstream. **No hay filtración previa en Git.** El remoto `origin` es GitHub (`andreylpz2596/lpz-saas`): cualquier `push` publicaría lo que se versione.

## 7. Migraciones (resumen; detalle en doc 11)

Todas las migraciones de ByLopez figuran ejecutadas en dev. Producción se desconoce. Orden y riesgos en el doc 11.
