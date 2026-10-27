# 06 — Decisiones: aprobadas y pendientes

Revisión 3.3 (2026-10-14). **Aprobado para E1 el 2026-10-14** (lo no listado sigue pendiente): intermedia persistente aislada por carga; cola dedicada `cargues`; PhpSpreadsheet + `fgetcsv` sin dependencias nuevas; ambas políticas de errores implementadas, obligatorias al configurar y sin selección automática (la política de **cada tipo** sigue sin decidir). Plan vigente: `07_PLAN_CONSOLIDADO.md`; evidencia en `08`; primera fase en `09`; archivo de referencia de Envíos completos en `10`. **Recibir la auditoría no equivale a aprobarla.**

## A. Aprobado por Camilo

| # | Decisión |
|---|---|
| A1 | Motor de cargues masivos **parametrizable y dinámico**: un tipo nuevo configura estructura, conexión y destino sin reprogramar el motor. Solo los procedimientos particulares se desarrollan. 99 Envíos y Clientes son ejemplos |
| A2 | Configuración: nombre, descripción, activo, conexión, tabla o procedimiento, acción y `map` JSON. Se rechazan las configuraciones contradictorias |
| A3 | **Modo TABLA:** destino existente y procedimiento vacío; los campos salen de la estructura real de la tabla, consultada por backend; acciones que incluyen **TRUNCATE** y **REPLACE** (con semántica documentada antes de implementarlas) |
| A4 | **Modo PROCEDIMIENTO:** procedimiento configurado y estructura intermedia definida en el map y **creada dinámicamente por el motor**; sin tablas fijas con migración manual. El procedimiento puede afectar varias tablas |
| A5 | `tabla_destino` ≠ `map.tabla` (tabla intermedia) |
| A6 | Conexión existente y autorizada en `database.php`, sin credenciales en la configuración; inicialmente MySQL/MariaDB, con separación por motor |
| A7 | Cabecera y datos originales por fila en JSON antes de aplicar cambios de negocio |
| A8 | Output descargable de no cargados con datos originales, fila, campo, etapa, motivo y estado inequívoco; los resultados inciertos no se presentan como "no cargados" |
| A9 | Solo administrador (visual y endpoints) |
| A10 | Ejecución manual, cada 15 días o a demanda; volumen por medir con archivos reales |
| A11 | Diseño del mockup aprobado (tarjetas a la izquierda, archivo y lectura a la derecha, tabla de comparación, recuperar y guardar comparaciones, validar, ejecutar, resultado, historial). Imagen **recibida y revisada** el 2026-10-13: `docs/mockups/cargues_nuevo_cargue_relacionar_columnas.png`, contraste en `04` §1.1 |
| A12 | Tipos ya descritos: "99 Envíos — Envíos completos" (procedimiento a desarrollar, actualiza el estado del envío por guía, sin tocar estados comerciales ni recaudos) y "99 Envíos — Cartera" (validar abonos; reglas con su archivo). Llave por tipo. El procedimiento resuelve inserciones, actualizaciones y reprocesos sin retroceder información |
| A13 | No modificar `packages/Webkul/` |
| A14 | **Envíos completos (2026-10-15)**, ver `13` §4: 99 Envíos es intermediario (proveedor ≠ transportadora efectiva); cruce por transportadora efectiva + guía como texto; códigos del proveedor por configuración; guía inexistente, transportadora desconocida o coincidencia múltiple = rechazada con motivo; no crear pedidos ni despachos; estado original con fecha y procedencia, sin equipararlo a entrega validada, devolución recibida, pago o comisión; discrepancia de valores = **se actualiza el estado y se registra**; política **procesar las válidas**; `valor_comercial` contra el **recaudo esperado** de la guía; `valor_servicio` **solo se registra** |

## B. Decisiones pendientes (únicas)

1. **Política de errores por tipo y acción**: bloquear todo o procesar las válidas. Recomendación en `07` §9 (bloquear obligatorio en `vaciar_y_cargar`); **no aprobada, sin respuesta**. El motor implementa **las dos**. Elegirla es requisito para **activar** cada tipo, no para desarrollar (`09` R3).
2. **Catálogo de tablas destino autorizadas** y acciones permitidas por tabla (en especial `vaciar_y_cargar` y `reemplazar` sobre tablas de Bagisto u otros módulos).
3. **Tipos que crean entidades con servicios** (p. ej. Clientes): solo procedimiento, híbrido con post-proceso PHP o procedimiento con reemisión de eventos (`07` §10).
4. **Retención** de datos originales, filas intermedias y respaldos.
5. **Recomendaciones técnicas por aprobar** (`07` §14.5):
   - prefijos `bylopez_cg_`/`bylopez_cgi_`;
   - intermedia persistente en vez de `TEMPORARY`;
   - creación al activar + verificación al preparar;
   - archivado por `RENAME` con reconciliación;
   - estrategias S1/S2 de vaciado;
   - bloqueo con nombre con `wait_timeout` de sesión;
   - inmutabilidad de entrada y regla de marcas;
   - `utf8mb4_nopad_bin` en identificadores;
   - `sql_mode` en la huella del procedimiento.

   Validadas técnicamente en `08` **no significa aprobadas**.
6. ~~**Lector XLSX**~~ **Aprobado para E1 (2026-10-14): PhpSpreadsheet detrás de `LectorArchivo`, `fgetcsv` para CSV, sin dependencias nuevas.** OpenSpout queda como alternativa si un tipo supera el límite. Evidencia anterior: (PhpSpreadsheet, OpenSpout o ambos detrás de una interfaz) y, si aplica, la **dependencia OpenSpout**. Evidencia en `08` §3.8 y `10` §5. **Recomendación:** PhpSpreadsheet, sin dependencia nueva, con `max_celdas` 300 000 y un worker de 512M (`09` §5). Para CSV basta `fgetcsv`.
7. **Reglas del procedimiento de Envíos completos** (`10` §7):
   - **D-ENV-1** destino del estado (tablas propias informativas o eventos de Logística);
   - **D-ENV-2** llave `(transportadora, numero_de_guia)`;
   - **D-ENV-3** no retroceso por `fecha_actualizacion`, con su significado y zona horaria;
   - **D-ENV-4** guía conocida con otra `fecha_envio`;
   - **D-ENV-5** códigos de transportadora frente al catálogo de Logística;
   - **D-ENV-6** columnas gemelas y celdas vacías;
   - **D-ENV-7** enlace con pedidos o despachos.

   **Estado 2026-10-15:** D-ENV-1 resuelta (tablas propias del estado reportado, sin eventos de Logística); D-ENV-2 aprobada; D-ENV-3 aplicada por la fecha del proveedor (zona horaria y significado **pendientes**); D-ENV-4 implementada como rechazo `GUIA_FECHA_ENVIO_DISTINTA` (**por confirmar**); D-ENV-5 por configuración (coordinadora sin equivalencia: **pendiente**); D-ENV-6: se relaciona una columna de cada par, sin verificar gemelas (**por confirmar**); D-ENV-7: guía inexistente se rechaza (aprobado). Detalle en `13`.
11. **Envíos completos — pendientes (`13` §11):** composición de `valor_servicio`; coordinadora en el catálogo; guías registradas con `99ENVIOS` como transportadora; zona horaria de `fecha_actualizacion`; mostrar el estado reportado en Logística; cuenta de ejecución restringida para producción.
10. **Protección de `VALIDAR` (`12`) por confirmar:** cuenta de BD restringida por conexión con credenciales en `.env`; solo procedimientos `SQL SECURITY INVOKER`; concesión automática de `SELECT`/`UPDATE`/`EXECUTE` mínimos al activar (o solo por comando); si se admite `CREATE TEMPORARY TABLES` en `VALIDAR`; la cuenta y sus permisos en producción (R5). **No se autorizaron tipos reales.**
9. **Decisiones de implementación de E1 por confirmar** (`11` §2, D1–D12): creación de la intermedia en dos pasos, llaves únicas solo con identificador/numéricos/fechas, filas vacías conservadas como `vacia`, regla `vacio`, advertencia de identificador numérico, decimales sin redondeo, booleanos por defecto, menú sin ACL.
8. **Nuevas recomendaciones técnicas de la rev. 3.3 por aprobar** (entran en R1):
   - regla `vacio` y `cg_ausentes` (`07` §4.4);
   - jobs en la cola `cargues` con `tries = 1` y `retry_after` > `timeout`;
   - límites de `09` §5.

## C. Insumos

- Mockup: **recibido** (2026-10-13).
- **Envíos completos:** el adjunto "(2)" **no llegó** a la sesión. Se analizaron exportaciones del mismo reporte presentes en el equipo (`10` §0). **Confirmar** que el "(2)" coincide con alguna de ellas o reenviarlo.
- **Cartera:** hoja no recibida.
- Verificación técnica de producción (**requisito de despliegue**, no de desarrollo; `09` §6):
  - versión exacta de MariaDB/MySQL (el DDL atómico ante caídas existe desde MariaDB 10.6.1);
  - topología (primario único o clúster);
  - privilegios DDL, `DROP` y `CREATE ROUTINE`;
  - `local_infile`, `memory_limit` y `wait_timeout`;
  - worker de la cola `cargues` en ejecución y supervisado, con su `retry_after`.

## D. Hallazgos fuera de alcance (no corregidos)

- `FILESYSTEM_DISK=public` por defecto en `.env`.
- Dev usa `DB_USERNAME=root` con `ALL PRIVILEGES`.
- Varios roles con `permission_type = all` en dev.
- `packages/ByLopez/CorteConfeccion.rar` en `packages/ByLopez/`.
- Cola `sync` en dev.
