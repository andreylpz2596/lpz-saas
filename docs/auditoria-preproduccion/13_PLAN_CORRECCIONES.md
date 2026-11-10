# 13 · Plan de correcciones (propuesta; nada ejecutado, espera aprobación)

Principios: una corrección por objetivo y por módulo; ninguna mezcla de módulos; ningún cambio de fórmulas ni reglas comerciales; primero lo que no altera comportamiento. Cada paso con commit local propio y reversión por `git revert`.

## Bloque 1 · Integridad y reproducibilidad

| # | Corrección | Alcance / archivos | Riesgo | Dependencias | Pruebas | Aceptación | Reversión |
|---|---|---|---|---|---|---|---|
| C-01 (AUD-001) | Versionar el trabajo pendiente **por objetivo** | solo `git add` selectivo de cada línea (D10, Cargues v3/productos, Cartera de prestadores, Consolidado, Cotizaciones, Reasignación); sin cambiar código | bajo; requiere que cada dueño confirme su parte | decisión de qué sesión commitea qué | suite del paquete sobre el commit | `git status` limpio; etiqueta de release | `git revert` |
| C-02 (AUD-003, 016) | Higiene del árbol | `.gitignore` (`/docs/*.xlsx`, `*.rar`, `*.stackdump`); mover xlsx/rar fuera | nulo | OK de Camilo para mover datos | `git status` | ningún dato personal rastreable | restaurar archivos |

## Bloque 2 · Seguridad y configuración

| # | Corrección | Alcance | Riesgo | Pruebas | Aceptación |
|---|---|---|---|---|---|
| C-03 (AUD-002) | Activar `strict` | `config/database.php` (app, no Webkul) con variable `DB_STRICT` | **medio** | suites ByLopez completas en BD de pruebas con strict; pruebas de límites de campos | 0 fallos nuevos; fallos previos documentados |
| C-04 (AUD-007) | `throttle` por usuario en rutas de escritura y archivos | `routes.php` de cada paquete (4 cambios independientes) | bajo | prueba de 429 y de uso normal | no afecta doble clic legítimo (idempotencia sigue) |
| C-05 (AUD-008) | Mapear 2 rutas de cuentas a ACL | `Logistica/src/Config/acl.php` | bajo | permisos por rol | rol con cuentas usa propuesta/cobro; sin permiso: 401 |
| C-06 (AUD-010, 017) | Apagar lector externo y `serve` | `.env` de producción; `config/filesystems.php` | bajo | humo de comprobantes | sin tráfico saliente |
| C-07 (AUD-014) | `mimes/max` en el request de cartera | `CarteraController` | bajo | prueba de subida inválida | 422 temprano |

## Bloque 3 · Concurrencia e idempotencia (validar antes de cambiar)

| # | Acción | Resultado esperado |
|---|---|---|
| C-08 (V-01, V-07, AUD-013) | Pruebas de **dos procesos reales**: abono simultáneo, confirmación simultánea de ventas con stock limitado, recepción simultánea de confección | evidencia; si falla, corrección específica en propuesta aparte |
| C-09 (AUD-006) | Prueba de la misma guía en 99 y Inter; si el tope no cubre, añadir regla única por pedido+guía | doble deuda imposible |
| C-10 (V-04) | IDOR con dos usuarios | 403/404 en todos los cruces |

## Bloque 4 · Rendimiento

| # | Acción | Riesgo |
|---|---|---|
| C-11 (AUD-012) | Precarga por lotes en `PanelComisionesService` | bajo (cubierto por Com06) |
| C-12 (V-17, V-18) | `EXPLAIN` con volumen sintético y índices solo si procede | bajo |

## Bloque 5 · Código duplicado / innecesario / limpieza

C-13 renumerar docs 30; C-14 verificar y retirar las 2 vistas sin uso (clase B); C-15 componente Blade de píldoras; **no** tocar SP v1/v2, lang, vistas monolíticas.

## Orden y dependencias

C-01 → C-02 → (C-03 requiere C-01) → C-04/05/06/07 en paralelo → C-08/09/10 (solo diagnóstico) → C-11/12 → C-13…15. Despliegue (doc 14) solo cuando C-01, C-16, C-17 y el bloque 3 estén cerrados (C-03 ya no es prerrequisito: ver «Actualización 2.ª etapa»).

---

## Actualización 2.ª etapa

### Cambios al plan
- **C-03 (strict):** baja a P2 y deja de ser prerequisito del despliegue. Nuevo alcance: *medir antes de decidir* (suites de Bagisto Admin/Shop y ByLopez bajo modo estricto **de sesión** en la BD de pruebas, sin tocar `config/database.php`), corregir `''`→`null` en ByLopez, limitar longitudes (campos de 500) y, con autorización, normalizar 11 fechas `0000-00-00` a `NULL`.
- **C-09 (99↔física):** **se cancela como corrección** (no hay ruta de duplicación). Queda como prueba de confirmación en Fase 5 (doc 05 §4.5) y como decisión de negocio AUD-026/027.
- **C-16 (nuevo, AUD-005):** versionar los procedimientos de Cargues y escribir el paso de instalación; fijar motor de producción.
- **C-17 (nuevo, AUD-004/029):** unidad de despliegue: cron, worker `cargues`, cuenta MySQL restringida, `.env` de producción, php.ini (doc 11 §4).
- **C-18 (nuevo, AUD-023):** revisar los roles con permisos `all` (en especial «BODEGA» id 12071) en producción.

### Estrategia segura para organizar y versionar el trabajo (C-01 detallada; **nada se ejecutó**)

Principio: *primero respaldar, después ordenar; nunca `reset`, `checkout --`, `stash drop`, `clean`.* Cada commit es local y pequeño; no hay `push` hasta la aprobación.

**Paso 0 — Respaldo fuera del repositorio (lectura, no destructivo).**
1. `git diff HEAD --binary > respaldo_tracked.patch` (cambios de archivos rastreados).
2. Comprimir en un `.zip` fuera del repo **todos los no rastreados** (`git ls-files --others --exclude-standard`), incluidos los sensibles, y guardarlo cifrado (contiene datos personales).
3. `git bundle create respaldo.bundle --all`.
4. Verificar: contar archivos del zip = 343 + 16 docs de auditoría; `git apply --check` del parche sobre un clon temporal.

**Paso 1 — Sacar lo sensible antes de cualquier `git add`** (con autorización): ampliar `.gitignore` (`/docs/*.xlsx`, `/docs/pruebas_cargues/`, `*.rar`, `*.stackdump`, `evidencia_*/lectura_local_*`, `evaluacion_ocr_*.json`) y mover los archivos; verificar con `git check-ignore -v`.

**Paso 2 — Mapa de propiedad.** Confirmar con Camilo qué sesión es dueña de cada línea (tabla del doc 01 §6). Los archivos compartidos (`routes.php`, `acl.php`, `menu.php`, `ServiceProvider`, `lang/*`, `00_STATUS.md`/`STATUS.md`) se parten por *hunks* con `git add -p` o con el método `git apply --cached --unidiff-zero --recount` ya usado en esta rama, de modo que cada commit contenga solo sus líneas.

**Paso 3 — Orden de commits (cada uno compilable y con su prueba).**

| # | Commit | Contenido | Dependencia |
|---|---|---|---|
| 1 | Higiene | `.gitignore` | — |
| 2 | Logística: contrato de guarda | `Contracts/GuardaAnulacionRecepcion`, `Support/SinGuardaAnulacionRecepcion`, cambios en `AnularEventoLogisticoService`, `LogisticaServiceProvider` | debe ir **antes** de D10 (D10 lo implementa) |
| 3 | VentasAsesor: D10 crédito | migraciones `2026_11_03_*`, `Services/Credito`, controlador, modelos, lang `credito`, `GuardaAnulacionRecepcionConCredito`, ACL/rutas (solo hunks D10), tests, `docs/28` | 2 |
| 4 | VentasAsesor: Com08 / avance | hunks de `AvanceAsesorService`, `PanelMensualService`, vistas, `Com08*Test` | — |
| 5 | VentasAsesor: Cotizaciones | migración `2026_11_04`, servicio, controlador, PDF, ACL/rutas/system.php (hunks), tests, `docs/30_COTIZACIONES` | — |
| 6 | Cargues: SP v3 y camisetas | `.sql` + `.map.json`, extensiones, `CargasService`/`EjecucionService`/controlador, vistas, lang, tests, docs 16/17 | — |
| 7 | CorteConfeccion: reasignación | migraciones `2026_10_29_*`, `2026_10_31_*`, modelos, servicio, docs 06/07 | — |
| 8 | CorteConfeccion: cartera de prestadores | migraciones `2026_11_01_*`, `2026_11_02_000001`, modelos, controlador, vistas, docs 08 | 7 (comparten ACL/menú) |
| 9 | CorteConfeccion: comprobantes (lectura local) | `src/Comprobantes`, `Config/comprobantes.php`, doc `LECTURA_COMPROBANTES_PAGOS.md` | 8 (se apoya en la cartera) |
| 10 | CorteConfeccion: consolidado y prioridades | migraciones `2026_11_02_000002/3`, `2026_11_03_000001`, controladores, vistas, docs 09 | 7 |
| 11 | Evidencia **sanitizada** | solo PNG/JSON sin datos reales | 1 |
| 12 | Docs de auditoría | `docs/auditoria-preproduccion/` | — |

**Paso 4 — Verificación por commit.** Tras cada commit: `git status` debe mostrar solo lo que falta; `php -l` de los archivos del commit; `php artisan route:list` y `php artisan migrate:status` (sin migrar); la suite del paquete afectado en `lpz_saas_testing` exclusiva. Al final: `git diff --stat <respaldo>` vacío (el árbol final = árbol de respaldo).

**Paso 5 — Etiqueta y congelación.** `git tag pre-prod-AAAA-MM-DD` sobre el último commit; solo después, `push` si se autoriza.

**Riesgos de la estrategia y mitigación:** (1) un hunk mal asignado deja un commit que no compila → la verificación del paso 4 lo detecta antes del siguiente; (2) trabajo de otra sesión sin terminar → confirmar con su dueño antes de incluirlo («no preservar» no existe: todo lo no incluido queda en el respaldo y en el árbol); (3) los 4 archivos de SP deben viajar con su pareja `.map.json`.
