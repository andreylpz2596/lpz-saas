# 06 · Auditoría de Cargues masivos

Cobertura: configuración, rutas/ACL, middleware, restricciones, archivo de subida, jobs, listado de procedimientos y documentación (docs 00–17). **No se ejecutó ningún procedimiento ni cargue.**

## 1. Implementado vs documentado [C]

| Tipo / pieza | Implementado | Versionado en Git |
|---|---|---|
| Motor de cargas (intermedias, VALIDAR/EJECUTAR, descargas, reanudar/recuperar/cancelar) | sí (86 PHP, 30 rutas, 20 pruebas) | parcialmente |
| Clientes históricos v1/v2 | sí | sí |
| Envíos completos v1/v2 | sí | sí |
| **Envíos completos v3** (hitos) y **Productos camisetas v1** | sí (SP + extensión + pruebas) | **no** (`.sql`, `.map.json`, extensiones y pruebas sin versionar) |
| Cartera 99 (conciliación) | **no** (doc 15, solo diseño) | — |
| Plantillas/ejemplos sintéticos | sí | sin versionar (`docs/plantillas`, `ejemplos`) |

## 2. Controles de seguridad y robustez [C]

- Solo administrador: middleware `SoloAdministrador` (rol id `CARGUES_ROL_ADMIN_ID`, defecto 1), antes del `admin` de Bagisto.
- Subida: disco privado, nombre UUID, extensión y firma verificadas, tamaño comprobado antes de guardar (`ArchivoService`).
- Job: `tries=1`, `timeout` 900 s configurable, `failOnTimeout`, cola dedicada `cargues` (`retry_after` > timeout, verificado por `cargues:diagnostico`); reanudación explícita por el administrador.
- `GET_LOCK` (2 usos) contra cargas concurrentes del mismo tipo; 13 transacciones; `UNIQUE(tipo_id, numero)` de versiones y `UNIQUE(uuid)` de cargas.
- Los 7 modelos usan `$guarded = []`; el riesgo es bajo porque nunca se llenan desde el request crudo (`RespondeJson` toma `all()` solo para configuración de tipos administrada por admin). Ver AUD-020.

## 3. Hallazgos

| ID | Prioridad | Estado | Resumen |
|---|---|---|---|
| AUD-001 | **P0** | [C] | Procedimientos v3 y productos v1, extensiones y pruebas sin versionar; `Cargues` tiene **1 migración** y el código solo *lee* los SP (`SHOW CREATE PROCEDURE` en `ProcedimientoService`); ningún migrador ni comando los instala. Viven en la BD y los `.sql` son su única fuente: sin ellos en Git y sin un paso explícito de instalación en el despliegue, producción no tendrá los procedimientos |
| AUD-005 | P1 | [C] | Los SP son específicos de MySQL/MariaDB; la configuración dice «solo MySQL/MariaDB tienen motor habilitado». Producción debe usar un motor equivalente al probado (10.4.28) o repetir la validación |
| AUD-004 | P1 | [C] | **Corregido en la 2.ª etapa:** Cargues no usa `sync`; tiene su conexión `database` `cargues` y necesita un worker dedicado (sin él los cargues quedan en cola). Además el SP corre con una cuenta MySQL restringida (`CARGUES_VALIDAR_DB_USERNAME`) que debe existir en producción |
| AUD-003 | P1 | [C] | Exportaciones reales con datos personales (`docs/*.xlsx`) fuera de la cobertura del `.gitignore` |
| AUD-020 | P3 | [C] | `$guarded=[]` en 7 modelos: aceptable hoy; endurecer con `$fillable` si se exponen a entrada nueva |
| V-10 | — | [S] | Idempotencia de EJECUTAR ante reintento tras corte de red: validar con carga sintética en BD de prueba (sin tocar datos reales) |
| V-11 | — | [S] | Tamaño máximo de archivo vs. `upload_max_filesize` / `post_max_size` de producción |

## 4. Restricciones de esta auditoría

No se leyeron cargas reales ni archivos `.xlsx`; no se ejecutó `cargues:*`. Las tablas intermedias más grandes (`carga_datos` 1857, `carga_incidencias` 1540 filas) son pequeñas: no hay evidencia de problemas de volumen.
