# 09 · Rendimiento y base de datos

Cobertura: indicadores estructurales y una medición propia previa. **No** se ejecutó `EXPLAIN` masivo, ni pruebas de carga, ni consultas pesadas; los volúmenes de dev son pequeños (la mayor tabla ByLopez tiene ~1.900 filas), así que **la ausencia de lentitud hoy no predice producción**.

## 1. Base de datos

| Aspecto | Evidencia [C] | Lectura |
|---|---|---|
| Motor | MariaDB 10.4.28, `sql_mode=NO_ENGINE_SUBSTITUTION`, `strict=false` en `config/database.php` | AUD-002, AUD-005 |
| Integridad referencial | ByLopez: 291 `foreign/foreignId/constrained` y 109 `unique` en migraciones (VentasAsesor 93/44, CorteConfeccion 106/26, Logística 82/34, Cargues 10/5) | buena cobertura de FK; Logística guarda códigos de transportadora **como texto sin FK** (decisión documentada) |
| Índices | 171 `->index()` en 121 tablas | suficiente a la vista; falta validar con `EXPLAIN` sobre consultas reales (V-17) |
| Tipos monetarios | 94 `decimal`, 0 `float` | correcto |
| CHECK | ~110 coincidencias | protección de combinaciones |
| Bloqueos | `lockForUpdate`: VentasAsesor 47, CorteConfeccion 46, Logística 29, Cargues 4; `GET_LOCK`: 3 | atención a orden de bloqueo; deadlocks 1213 vistos solo cuando dos sesiones comparten la BD de pruebas |
| `innodb_lock_wait_timeout` | 50 s | tolera esperas largas; fijar valor explícito en producción |

## 2. Backend

| ID | Hallazgo | Estado | Prioridad |
|---|---|---|---|
| AUD-012 | Admin de comisiones: ~15 consultas por pedido (319 para 21 pedidos; 6,8 s en frío), sin paginación a escala. Causa: construcción por pedido en `PanelComisionesService`/`ConsultaComisionesService` | [C] medido en demo | P2 |
| V-17 | Listados de Logística/Entregas usan subconsultas con `whereRaw` y agregados (`despachadas/entregadas/retornadas`); validar con volumen 50k pedidos | [S] | por definir |
| V-18 | `ConsolidadoConfeccionService` calcula `por_confeccionar`/`LEAST` en SQL sobre detalles; revisar índices y tiempos con 5.000+ detalles | [S] | por definir |
| V-19 | Transacciones largas: `ConfirmarVentaService` evita transacción exterior (correcto), `ReevaluarComisionesJob` hasta 300 s: verificar que no mantenga bloqueos de filas durante todo el trabajo | [S] | P2 |
| — | Operaciones síncronas pesadas: cargues (cola `database` `cargues`, requiere worker), PDF de guías/cotizaciones, lectura OCR de comprobantes (Tesseract local, `tiempo_max` 120 s) | [C] | exigen worker |

## 3. Frontend

- Tailwind del admin **no escanea** `packages/ByLopez` (memoria): clases nuevas no existen salvo que estén ya compiladas o en CSS propio (`build-bylopez/corteconfeccion.css`, sin versionar) → riesgo de pantallas rotas tras un `npm run build` limpio (AUD-015).
- Archivos de vista monolíticos con JS embebido (4630, 2136, 1693, 1463 líneas): el costo es de mantenimiento y de tiempo de parseo; no se recomienda refactor previo a publicar.
- Scroll en iPhone: causa demostrada (contenedor `overflow-x-hidden` de Bagisto, 12 px) y mitigada en vistas propias; Safari/iPhone **físico** no probado (memoria).
- Debugbar solo en `require-dev` (se retira con `composer install --no-dev`).

## 4. Procesos de fondo

| Proceso | Frecuencia | Protección | Riesgo |
|---|---|---|---|
| `ventas-asesor:comisiones:evaluar --bandeja` | 5 min | `withoutOverlapping(30)`, `tries=1` y reintentos por bandeja | sin cron no corre; sin monitoreo, fallos silenciosos |
| `ProcesoCargaJob` | bajo demanda | `tries=1`, timeout 900, `failOnTimeout`, reanudación manual | worker caído → cargas en cola indefinida |
| Lectura de comprobantes | bajo demanda | sin cola, en la petición | puede exceder `max_execution_time` |

## 5. Optimizaciones seguras (sin cambiar resultados)

1. Precargar relaciones en `PanelComisionesService` (consultas por lote en vez de por pedido) — cubierto por pruebas Com06.
2. Cache de configuración/rutas/eventos de Laravel en producción.
3. OPcache habilitado (hoy el CLI no lo lista).
4. Índices adicionales solo tras `EXPLAIN` con datos de producción.
