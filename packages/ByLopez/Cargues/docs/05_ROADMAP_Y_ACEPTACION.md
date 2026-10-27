# 05 — Fases de implementación y criterios de aceptación

Todo **[PROPUESTO]**; ninguna fase está iniciada. Cada fase termina con pruebas Pest relevantes ejecutadas de verdad y su informe en `00_STATUS.md`.

## 1. Fases

Las fases vigentes están en **`07_PLAN_CONSOLIDADO.md` §12** y no se repiten aquí.

> **ANTECEDENTE retirado:** la versión anterior de esta sección proponía configurar el primer tipo por seeder/migración y **excluía la pantalla "Configurar tipos" del MVP**. **No implementar.** Contradice el requisito aprobado de un motor parametrizable y dinámico: la configuración de tipos desde la UI (con validación, activación y creación de la intermedia) forma parte del MVP.

## 2. MVP

1. **Motor completo de configuración:** pantalla "Configurar tipos" (borrador → activar), validador del map y de contradicciones, inspección de tablas reales, creación, reutilización y cambio controlado de intermedias, verificación de la firma del procedimiento.
2. **Operación:** selección de tipo, archivo XLSX/CSV, hoja y encabezado, comparaciones guardadas, auditoría JSON por fila, validación (motor + `VALIDAR` del procedimiento), confirmación, ejecución, consolidación, output de no cargados e inciertos, historial.
3. **Ambos modos** con al menos una acción de tabla de bajo riesgo (`insertar` o `insertar_o_actualizar`) y el modo procedimiento. `vaciar_y_cargar` y `reemplazar` entran cuando su pre-vuelo y sus pruebas (`07` §6) pasen.
4. **Primer tipo real:** el que Camilo priorice, configurado **desde la UI** con su archivo de referencia; su procedimiento, si aplica, se desarrolla aparte.

Fuera del MVP: `LOAD DATA`, cancelación en caliente, paralelización de la ejecución.

## 3. Criterios de aceptación

### Funcionales
- AC-F1 Un XLSX con 3 hojas permite elegir hoja; cambiarla relee encabezados y **invalida** la validación previa.
- AC-F2 Fila de encabezado distinta de 1 se respeta; filas vacías intermedias no cortan la lectura y conservan su número de fila.
- AC-F3 Un encabezado duplicado se muestra como ambiguo y no se asigna solo.
- AC-F4 Campo obligatorio sin columna: "Validar archivo" deshabilitado con razón visible.
- AC-F5 Guía `0012345` (texto) se conserva idéntica; guía numérica en XLSX genera advertencia y **no** se rellenan ceros ni se recupera precisión.
- AC-F6 Los contadores por estado final (`07` §9) suman el total de filas con datos en éxito, parcial y fallo.
- AC-F7 La validación no modifica ninguna tabla de negocio (comprobado por conteo/checksum antes-después).
- AC-F8 Ejecutar exige acción explícita; el paso Validar nunca lleva solo a Resultado.
- AC-F9 CSV con `;`, comillas, BOM, UTF-8 y Windows-1252 se lee correctamente; el delimitador y el entrecomillado son ajustes distintos.
- AC-F10 Cambiar archivo/hoja/encabezado/mapeo después de validar obliga a revalidar.
- AC-F11 Una carga hecha con la versión N conserva su configuración, comparación y definición del procedimiento (copias congeladas) tras activar la versión N+1.
- AC-F12 Editar una plantilla de mapeo no cambia la carga histórica.

### Seguridad
- AC-S1 Un usuario que no es el administrador configurado recibe 403 en **todas** las rutas del módulo (GET, POST y descargas), aunque conozca la URL. Incluye roles `custom` y otros roles con `permission_type = all`.
- AC-S2 El menú "Cargues" y el botón "Configurar tipos" no se renderizan para quien no es administrador.
- AC-S3 Ningún campo del request puede definir tabla, columna, conexión o procedimiento (se prueba enviando valores arbitrarios).
- AC-S4 Archivos en disco `private`, nombre uuid, no accesibles por URL pública.
- AC-S5 Un valor `=HYPERLINK(...)`/`@SUM` en una celda se exporta neutralizado en el reporte de errores.
- AC-S6 `.xlsm`, `.xls`, archivo con extensión falsa o zip bomb se rechazan con mensaje comprensible.
- AC-S7 El detalle técnico aparece plegado y nunca en los mensajes al operador.
- AC-F13 El archivo de filas fallidas contiene número de fila, todas las columnas originales y el motivo, tanto tras validar como tras ejecutar.
- AC-F14 Reprocesar un archivo con información más antigua que la vigente no la hace retroceder; esas filas se informan como `omitida_antigua`, no como fallo.
- AC-F15 Un tipo nuevo de modo procedimiento se configura y activa sin migración PHP: el motor crea `map.tabla` desde el map y la reutiliza si la huella coincide. Ante un cambio estructural, aplica el procedimiento de `07` §5.4: tabla nueva creada primero, intercambio atómico y bloqueo si hay cargas sin cerrar. Si falla la creación, la tabla vigente queda intacta.
- AC-F15b Obligatoriedad por acción (`07` §4.3):
  - con `actualizar`, solo la llave y al menos una columna a actualizar;
  - con `insertar` o `vaciar_y_cargar`, solo las `NOT NULL` **sin** default que no sean autoincrementales ni generadas. Una `NOT NULL` con default no relacionada toma su default, y una celda vacía en ella se envía como `DEFAULT`, nunca como `NULL` (error 1048 comprobado). Las generadas no se ofrecen para relacionar (error 1906 comprobado);
  - con `reemplazar`, las mismas, con aviso de las columnas no relacionadas que volverán a su default;
  - con `insertar_o_actualizar`, las de inserción solo para las filas cuya llave no exista.
- AC-F15c Llaves del map (`07` §5.5): una `primaria` o `unica` declarada se crea como restricción real con `cg_carga_id`; las filas que la violarían se marcan `rechazada` (motivo: llave duplicada en el archivo) sin perder su original en `carga_datos`.
- AC-F16 Configuraciones contradictorias de `07` §3.3 se rechazan con motivo (p. ej. modo tabla con procedimiento, `map.tabla` igual a una tabla existente no registrada).
- AC-F17 `vaciar_y_cargar` (S1): un error a mitad de carga deja el destino exactamente como estaba. Las filas de lotes ya enviados quedan `revertida` y las no alcanzadas, `no_ejecutada`. Con FKs entrantes `CASCADE`, el pre-vuelo bloquea.
- AC-F17b `vaciar_y_cargar` (S2): tras una falla, la restauración solo se declara correcta si la comparación de **contenido** (anti-join por llave en ambos sentidos + `CHECKSUM TABLE`) no encuentra diferencias. Si no, `incierta` y el respaldo se conserva. Durante S2 ninguna otra sesión puede escribir el destino.
- AC-F18 `reemplazar`: el pre-vuelo informa cuántos registros se reemplazan y qué columnas vuelven a su default; bloquea con FKs entrantes `CASCADE`/`RESTRICT` no autorizadas o con más de un índice único.
- AC-F19 Output: `rechazada`, `no_ejecutada` y `revertida` van al archivo de no cargados; `incierta` va a un archivo separado; `sin_cambio` y `omitida_antigua` no se presentan como fallos.
- AC-F20 Dos cargas del mismo tipo en la misma intermedia no se mezclan (`cg_carga_id`). Las filas duplicadas por una llave **no única** se conservan marcadas `cg_duplicado`; las que violarían una llave primaria o única declarada se rechazan con su original intacto en `carga_datos`.

### Concurrencia / integridad
- AC-C1 Doble clic o dos pestañas en "Ejecutar": una sola ejecución (CAS); la otra recibe estado actual.
- AC-C2 Dos cargas simultáneas no mezclan filas (intermedia filtrada por `cg_carga_id`); limpiar una no borra la otra.
- AC-C2b Una vez iniciada la ejecución (`07` §5.2):
  - ninguna operación (revalidar, preparar, reanudar, el procedimiento) borra filas intermedias de esa carga ni modifica sus **datos de entrada**: la huella de entrada es igual al iniciar y al terminar;
  - las **marcas de resultado** solo las escribe el ejecutor que tiene el bloqueo, de vacío a final;
  - toda marca confirmada antes de una reanudación queda idéntica después (comprobado en `08` §3.5).
- AC-C3 Reintento del job tras caída no duplica filas ya aplicadas.
- AC-C3b **Ventana de falla** (`07` §8): simular la caída del proceso PHP durante el `CALL` y después del `COMMIT` del procedimiento. Mientras el bloqueo con nombre de la carga siga tomado, la recuperación **no** clasifica filas. Cuando se libera, las marcadas conservan su estado y las no marcadas quedan `no_ejecutada` solo si el tipo cumple el contrato; si no, `incierta`.
- AC-C3c Un tipo no reanudable no ofrece reejecutar tras `interrumpida`; solo revisión manual.
- AC-C3d "Envíos completos": ejecutar dos veces la misma información no deja estados ni historial distintos de una sola ejecución (el procedimiento resuelve los reprocesos sin retroceder).
- AC-C3e Conexiones distintas: con la intermedia o el destino en otra conexión, una caída entre el `COMMIT` del procedimiento y la consolidación se recupera releyendo las marcas `cg_*` de la conexión del tipo, sin reejecutar.
- AC-C4 Proceso caído en `ejecutando` → `interrumpido`; no se reejecuta solo.
- AC-C5 Si cambian las condiciones entre validar y ejecutar, se vuelve a `validado_*` y no se aplica.
- AC-C6 Archivo idéntico ya cargado: aviso y confirmación; la clave de negocio evita duplicar efectos.
- AC-C7 Falla parcial: los lotes aplicados quedan reportados, sin afirmar rollback global.

**Estado a 2026-10-14:** los criterios de E1 (F1-S, C, L, D, V, B, P) están **cumplidos con pruebas ejecutadas** (125 en instancia aislada; tabla de resultados en `11` §6). F1-E* y F1-R* son de E2 y E3 y no están implementados. F1-U se comprobó en Chrome headless (capturas en `capturas_e1/`); la revisión visual formal es de Camilo.

Los criterios concretos de la **primera fase** (F1-*) están en `09` §4 y se derivan de estos y de la evidencia de `08`. Rev. 3.3:
- se añaden segundo plano y progreso (F1-B*), las dos políticas de errores (F1-P*, F1-E1b), las cuatro situaciones de vacío (F1-L6) y la prueba integrada de Envíos completos (F1-R1–R6);
- F1-R3 concreta AC-C3d.

### Fidelidad visual (mockup recibido: `mockups/cargues_nuevo_cargue_relacionar_columnas.png`)
- AC-V1 Comparación lado a lado con el mockup: distribución, jerarquía, colores, tarjetas, iconografía, espaciado y columnas de la tabla.
- AC-V2 Sin componentes ajenos al Admin; claro/oscuro correctos.
- AC-V3 Móvil: sin scroll horizontal de página; teclado y foco operables; estados no dependen solo del color.
- AC-V4 Marca de relación ≠ validado (texto del aviso presente).

## 4. Pruebas sugeridas (Pest, por fase)

Un `Feature/Cargues0xTest.php` por bloque como Logistica; BD de pruebas MariaDB (ver memoria del proyecto: cuidado con commits prematuros si el SP hace `COMMIT`).

### Datos de prueba: dos fuentes separadas

| Fuente | Uso | Ubicación propuesta | Regla |
|---|---|---|---|
| **Archivos de referencia reales anonimizados**, uno por tipo ("Envíos completos", "Cartera") | Configurar cada tipo: campos, encabezados, formatos y hojas reales. **No** definen el motor | `tests/fixtures/reales/<tipo>_<fecha>_anonimizado.xlsx` + nota de origen (fecha, quién anonimizó, qué se sustituyó) | No se editan para meter casos límite; si cambia el formato del proveedor, se agrega otro archivo |
| **Casos artificiales** | Casos límite: cero a la izquierda, teléfono largo/`+57`, fecha serial, decimales, fórmula, duplicado, fila vacía intermedia, encabezado duplicado, línea > 4096, BOM, Windows-1252, archivo corrupto, zip bomb | `tests/fixtures/sinteticos/<caso>.xlsx/csv` | Cada archivo indica en su nombre el caso que cubre; generados con script versionado; nunca se presentan como datos reales |

Los archivos reales no tienen que contener todos los casos límite. Los sintéticos se preparan después, con la forma de columnas de cada referencia. Las pruebas del **motor** usan además tipos ficticios, para demostrar que no depende de 99 Envíos.
