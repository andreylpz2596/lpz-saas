# 12 · Hallazgos priorizados (revisado en la 2.ª etapa, 2026-11-09)

Estados: **CONFIRMADO** (verificado con evidencia) · **POTENCIAL** (mecanismo real, daño no demostrado) · **DESCARTADO**. Ningún hallazgo se corrigió; no se ejecutaron migraciones, pruebas ni escrituras; `packages/Webkul/` intacto.

## Cambios de prioridad respecto a la 1.ª etapa

| ID | Antes | Ahora | Motivo |
|---|---|---|---|
| AUD-002 `strict=false` | P1 | **P2** | Daño real hallado: 1 texto truncado y 11 fechas `0000-00-00` históricas; 0 montos saturados. Activarlo a ciegas puede romper el núcleo de Bagisto (doc 07 §3) |
| AUD-006 doble deuda 99↔física | P1 sospecha | **P3, DESCARTADO** | `UNIQUE(shipment_id)`, libro por envío, tope por pedido bajo bloqueo (doc 05 §4) |
| AUD-007 sin `throttle` | P2 | **P3** | Todo exige sesión; login con `throttle:5,1` |
| AUD-008 2 rutas sin ACL | P2 | **P3** | Falla cerrado + servicios revalidan; defecto funcional |
| AUD-004 | P1 | **P1 (ampliado)** | Se corrige el diagnóstico de la cola de Cargues (conexión `database`, worker obligatorio) |
| Nuevos | — | AUD-023 (P2), AUD-026 (P2), AUD-029 (P2), AUD-027 (P3), AUD-028 (P3) | Ver abajo |

Totales: **1 P0 · 3 P1 · 11 P2 · 12 P3**.

---

## P0

### AUD-001 · Trabajo sin versionar — CONFIRMADO
- **Evidencia exacta:** `git status --short -uall`: **456 archivos** (113 modificados + 343 nuevos; 268 entradas colapsadas), distribuidos así: VentasAsesor 87, CorteConfeccion 296 (141 son evidencia), Logística 18, Cargues 48, otros 7. Incluye 11 migraciones, 4 archivos de procedimientos (`sp_cg_envios_completos_v3.*`, `sp_cg_productos_camisetas_v1.*`), el módulo D10 y un contrato que cruza Logística↔VentasAsesor (`Logistica/Contracts/GuardaAnulacionRecepcion.php`). Detalle en doc 01 §6.
- **Archivo y método:** no aplica (repositorio); ejemplos críticos: `VentasAsesor/src/Database/Migrations/2026_11_03_000001_…`, `Logistica/src/Contracts/GuardaAnulacionRecepcion.php` (usado por `AnularEventoLogisticoService`).
- **Riesgo real:** (1) un despliegue desde Git llegaría sin tablas ni clases que el código modificado ya referencia → errores 500; (2) un `reset`/cambio de rama destruiría semanas de trabajo; (3) imposible hacer rollback a un estado conocido; (4) varios archivos compartidos (`routes.php`, `acl.php`, `lang/*`, `00_STATUS.md`) mezclan hunks de 4-5 líneas de trabajo y de más de una sesión (p. ej. `AvanceAsesorService` ya lleva +61 líneas de «devolución total» sobre lo commiteado).
- **Prioridad revisada:** P0 (se mantiene). **¿Bloquea?** Sí.
- **Corrección recomendada:** estrategia de versionado seguro del doc 13 (C-01): respaldo → commits por línea con hunks → verificación por línea → etiqueta. **Impacto:** nulo en comportamiento; trabajo de orden. **Prueba necesaria:** `git diff --stat` del árbol antes/después = vacío; suite de cada paquete sobre el commit limpio en BD de pruebas exclusiva.

---

## P1

### AUD-003 · Datos personales sin cobertura de versionado — CONFIRMADO (exposición potencial; sin filtración)
- **Evidencia exacta:** `docs/Cartera_Completa_2026-09-12.xlsx`, `docs/Envios_Completos_2026-10-03.xlsx`, `docs/pruebas_cargues/Envios_Completos_2026-10-03_PRUEBA_240099900001.xlsx`; `CorteConfeccion/docs/confeccion/evidencia_2026-10-29/lectura_local_2026-11-02/*.png` (9) y `evaluacion_ocr_comprobantes_reales.json` (el `LEEME.md` de esa carpeta dice «no subir a git»). `.gitignore` modificado solo ignora `/packages/ByLopez/**/*.xlsx`. `git log --all`: ninguno versionado nunca; `origin` = GitHub.
- **Archivo y método:** `.gitignore` (reglas añadidas); no hay método de código.
- **Riesgo real:** nombres, direcciones, teléfonos y referencias bancarias de terceros terminarían en un repositorio remoto con un `git add -A`/`git add docs` (Ley 1581 de 2012). Una vez subidos, borrarlos del historial es costoso.
- **Prioridad revisada:** P1 (se mantiene). **¿Bloquea?** Bloquea **commit/push** de esas rutas; no bloquea la puesta en servidor.
- **Corrección recomendada:** excluir (`.gitignore` ampliado) y mover los archivos fuera del repositorio con autorización; sustituir las evidencias con datos reales por versiones anonimizadas. **Impacto:** nulo en la aplicación; las docs que citan esos archivos pierden el enlace local. **Prueba:** `git status --short` y `git check-ignore -v <ruta>` para cada ruta sensible; escaneo previo a cada commit.

### AUD-004 · Configuración no apta para producción y dependencia de cron/worker — CONFIRMADO
- **Evidencia exacta:** `APP_ENV=local`, `APP_DEBUG=true`, sesión/caché `file`, correo `log`, `QUEUE_CONNECTION=sync` (default). **Corrección del diagnóstico previo:** Cargues usa la conexión `cargues` (driver `database`, `retry_after` 1500) y **requiere worker aunque la cola por defecto sea `sync`**; `ReevaluarComisionesJob` usa la cola por defecto (`conexion=null`) y depende del scheduler (`VentasAsesorServiceProvider` líneas ≈160-163, cada 5 min).
- **Archivo y método:** `.env`, `Cargues/src/Config/cargues.php` (`cola`), `VentasAsesorServiceProvider::boot()` (`callAfterResolving(Schedule::class…)`).
- **Riesgo real:** con `APP_DEBUG=true` se exponen rutas, variables y trazas; sin cron la bandeja de comisiones deja de reevaluar y las comisiones no se habilitan; sin worker los cargues quedan «en cola» sin avisar (hoy 0 jobs y 0 fallidos porque no hay cargues en cola).
- **Prioridad revisada:** P1 (se mantiene). **¿Bloquea?** Sí (es parte de la puesta en marcha, no de código).
- **Corrección recomendada:** doc 11 §4 y doc 14. **Impacto:** ninguno en código. **Prueba:** `schedule:list`, `cargues:diagnostico`, un cargue sintético de VALIDAR, comprobar que `APP_DEBUG=false` devuelve página de error genérica.

### AUD-005 · Motor y procedimientos almacenados — CONFIRMADO (versión) / POTENCIAL (incompatibilidad)
- **Evidencia exacta:** `SELECT version()` = `10.4.28-MariaDB`; `Cargues/src/Services/Configuracion/ProcedimientoService.php` solo ejecuta `SHOW CREATE PROCEDURE`; los `.sql` de `Cargues/src/Database/Procedures/` no los instala ninguna migración ni comando; los de v3 y camisetas están sin versionar; los servicios usan `GET_LOCK` (3 sitios), `LEAST`, `COALESCE`, `NULLIF`.
- **Riesgo real:** producción sin los SP → todos los cargues fallan; versión de motor distinta → comportamiento de SP, `GET_LOCK` y bloqueos no probado.
- **Prioridad revisada:** P1. **¿Bloquea?** Sí, hasta definir motor y paso de instalación de SP.
- **Corrección recomendada:** fijar MariaDB 10.11 LTS o MySQL 8.0/8.4 (confirmar), versionar los SP y documentar su instalación (`mysql < archivo.sql`) con verificación `SHOW CREATE PROCEDURE`; repetir suites de Cargues y de concurrencia en el motor destino. **Impacto:** medio (migrar de 10.4 a 10.11 exige ensayo de restauración). **Prueba:** suite Cargues completa + `cargues:proteccion-validar` + dos procesos con `GET_LOCK`.

---

## P2

| ID | Estado | Hallazgo y evidencia | Acción |
|---|---|---|---|
| AUD-002 | CONFIRMADO config / POTENCIAL daño | `strict=false` (`config/database.php:58,78`); `bootstrap/app.php:34` quita `ConvertEmptyStringsToNull`; datos: 1 texto cortado a 500, 11 fechas `0000-00-00` (órdenes de corte 169, 311, 1624, 2754, 2762; rollos 176, 318, 1621, 2737), 612 columnas NOT NULL sin default | medir con suites en modo estricto de sesión, corregir `''`→`null`, decidir (doc 07 §3). No bloquea |
| AUD-023 | CONFIRMADO en dev | rol «BODEGA» id 12071 con `permission_type=all` y 1 usuario; 4 roles QA `all` | revisar en producción antes de abrir usuarios |
| AUD-026 | CONFIRMADO | 99 e Inter con `liquida_cuenta_corriente=0`: 17 guías con recaudo sin libro de saldo; `CobroReportadoService` rechaza guías de 99 | decisión de negocio |
| AUD-009 | CONFIRMADO | PHP dev `upload_max_filesize=2M`, `post_max_size=8M` frente a Cargues 20 MB y comprobantes 10 MB × 20 | ajustar php.ini (doc 11 §4) |
| AUD-010 | CONFIRMADO | `LectorClaudeVision` con API externa; apagado por defecto | mantener apagado, sin clave |
| AUD-011 | CONFIRMADO | conciliación de Cartera de 99 no implementada | decidir alcance |
| AUD-012 | CONFIRMADO | ~15 consultas/pedido en vista admin de comisiones | carga por lotes |
| AUD-013 | CONFIRMADO | suites sobre BD compartida; sin concurrencia multiproceso | BD exclusiva; prueba de 2 procesos |
| AUD-015 | CONFIRMADO | `build-bylopez/` sin versionar; Tailwind no escanea ByLopez | decidir versionado/CI |
| AUD-021 | CONFIRMADO | sin reporte de reconciliación pedidos↔cartera↔recaudo | consulta de solo lectura |
| AUD-029 | CONFIRMADO | la cola de Cargues necesita worker propio y cuenta MySQL restringida (`CARGUES_VALIDAR_DB_*`) | incluido en doc 11 §4 |

## P3

| ID | Estado | Hallazgo | Acción |
|---|---|---|---|
| AUD-006 | **DESCARTADO** (como duplicación) | doc 05 §4 | ninguna; ver AUD-026/027 |
| AUD-007 | CONFIRMADO | sin `throttle` en ByLopez (todo autenticado) | `throttle` en escrituras/archivos |
| AUD-008 | CONFIRMADO (funcional) | 2 rutas de Logística sin ACL (falla cerrado) | mapear a `logistica.cuentas.*` |
| AUD-014 | CONFIRMADO | `CarteraController:276` solo `file` en request | añadir `mimes/max` |
| AUD-016 | CONFIRMADO | `.rar` (8,5 MB), `.stackdump`, 2 vistas sin referencias, doc 30 duplicado | doc 08 |
| AUD-017 | CONFIRMADO | discos `local`/`private` con `serve=true` | `false` en producción |
| AUD-018 | CONFIRMADO | vistas >1000 líneas | no tocar |
| AUD-019 | CONFIRMADO | Com03·COM03-1 falla igual que el 29-oct | decidir regla vs prueba |
| AUD-020 | CONFIRMADO | `$guarded=[]` en 7 modelos de Cargues | `$fillable` |
| AUD-022 | CONFIRMADO | `docker/production` genérico | adaptar o descartar |
| AUD-027 | CONFIRMADO | `99ENVIOS` sembrado como `transportadora` | «transportadora efectiva» antes de cuentas |
| AUD-028 | CONFIRMADO | 2.º `bash.exe.stackdump` | doc 08 |

---

## Tabla final

| ID | Hallazgo | Confirmado / Sospecha | Impacto | Acción recomendada | ¿Bloquea producción? |
|---|---|---|---|---|---|
| AUD-001 | 456 archivos sin versionar (11 migraciones, SP v3/camisetas, D10, contrato Logística↔Ventas) | **Confirmado** | Despliegue irreproducible; pérdida de trabajo; sin rollback | Respaldo + commits por línea con hunks + etiqueta (doc 13 C-01) | **Sí** |
| AUD-003 | Excel y comprobantes reales sin ignorar | **Confirmado** (sin filtración) | Fuga de datos personales si se hace `git add`/push | Ampliar `.gitignore`, mover/anonimizar con autorización | **Sí para commit/push**; no para el servidor |
| AUD-004 | Config de dev, cron y worker de Cargues ausentes | **Confirmado** | Debug expuesto; comisiones sin reevaluar; cargues sin procesar | Checklist de entorno (doc 11 §4, doc 14) | **Sí** |
| AUD-005 | MariaDB 10.4.28 y SP fuera de migraciones | **Confirmado** (versión) / **Potencial** (fallo) | Cargues inoperantes sin SP; comportamiento no probado en otro motor | Fijar motor, versionar e instalar SP, repetir suites | **Sí** (hasta decidir) |
| AUD-002 | `strict=false` | **Confirmado** config; daño **Potencial** | 1 texto cortado, 11 fechas inválidas; riesgo al activar en el núcleo | Medir con modo estricto de sesión; normalizar `''`→`null`; decidir | No |
| AUD-006 | Doble deuda 99↔física | **Descartado** | Ninguno | Ninguna | No |
| AUD-026 | 99/Inter sin cuenta corriente | **Confirmado** | 17 guías con recaudo sin saldo por transportadora | Decisión de negocio | No |
| AUD-023 | Rol «BODEGA» con permisos `all` | **Confirmado** (dev) | Un usuario de bodega con acceso total | Revisar roles en producción | No (verificar antes de abrir) |
| AUD-008 | 2 rutas sin ACL | **Confirmado**; no es vulnerabilidad | 401 para roles personalizados cuando se asigne el permiso | Mapear a ACL | No |
| AUD-007 | Sin `throttle` | **Confirmado** | Abuso solo con cuenta válida | `throttle` en escrituras | No |
