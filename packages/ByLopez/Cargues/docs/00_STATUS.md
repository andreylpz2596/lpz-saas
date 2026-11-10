# Cargues — STATUS e índice

**Última actualización:** 2026-10-15 · **Fase actual:** E1 + **núcleo de ejecución E2** + tipo **«99 Envíos — Envíos completos»** (procedimiento `sp_cg_envios_completos_v1`) implementados en desarrollo (`13`), con datos sintéticos en la instancia aislada (suite de 144 pruebas + 1). Tipo activo en dev con política «procesar las válidas» (decisión de Camilo 2026-10-15). **El cargue real lo prueba Camilo** (no se ejecutó). Protección de VALIDAR en `12`.
**2026-10-28 — Ejecución en la demo aislada (sin cambios de código en el módulo):** el tipo «99 Envíos — Envíos completos» (`sp_cg_envios_completos_v2`) se creó, confirmó y activó por los servicios del módulo en la base `lpz_saas_demo_*`, con una cuenta restringida propia (`cargues_validar_demo`; la de desarrollo tiene permisos sobre la base de desarrollo y el módulo la rechaza) y el **worker real** de la cola `cargues`. Un archivo **ficticio** de 8 filas pasó por HTTP (subir → relacionar → validar → ejecutar): 4 aplicadas (una con `DISCREPANCIA_RECAUDO`, una con un estado nuevo guardado literal, una sobre un pedido despachado sin entrega ni cobro), 4 rechazadas con motivo (`GUIA_NO_REGISTRADA`, `TRANSPORTADORA_SIN_EQUIVALENCIA`, `OBLIGATORIO_VACIO`, `FECHA_INVALIDA`); el mismo archivo otra vez dio 4 «sin cambio» y 0 efectos nuevos; el CHECKSUM de guías, entregas, retornos, validaciones, pagos, aplicaciones, comisiones y cuentas no cambió (un «Entregada» del proveedor no registra entrega, no confirma pagos, no mueve custodia y no habilita comisión). Es evidencia de la **demo**, no el cargue real de Camilo (sigue pendiente). Guion y salida: `VentasAsesor/docs/26_JORNADA_F3_F6_CANCELACION_COMISIONES_DEMO.md` §7.4 (recorrido I). `packages/Webkul/` sin cambios.

**Dueño funcional:** Camilo Gaitán · **Paquete previsto:** `packages/ByLopez/Cargues/` (`ByLopez\Cargues`). *Propuesto; en disco solo existe `docs/` (incluido `docs/mockups/`).*

## Leyenda de estado (se usa en todos los documentos)

| Marca | Significado |
|---|---|
| **[APROBADO]** | Lo dijo o aprobó Camilo explícitamente. |
| **[PROPUESTO]** / **[RECOMENDADO]** | Recomendación técnica. **No vinculante hasta aprobación expresa.** Que una prueba la valide técnicamente no la aprueba. |
| **[PENDIENTE]** / **[ABIERTO]** | Decisión o dato que falta. No se inventa regla. |
| **[V]** / **[COMPROBADO]** | Ejecutado en la validación aislada de `08`, con el resultado citado. |
| **[DOC]** | Según documentación oficial; no ejecutado. |
| **[IMPLEMENTADO]** | Existe en código del módulo y fue probado. **Hoy: nada.** |
| **[C]** / **[S]** | Comprobado en código o entorno del proyecto vs. supuesto. |

## Orden de lectura

1. `00_STATUS.md` (este archivo).
2. **`07_PLAN_CONSOLIDADO.md`: plan vigente (revisión 3.3).** Si `01`–`06` lo contradicen, prevalece `07`.
3. **`08_VALIDACION_TECNICA.md`: evidencia de las pruebas aisladas** (qué se comprobó, qué no y los hallazgos).
4. **`09_FASE1_PLAN.md`: primera fase implementable**, dividida en entregas E1–E3 (requisitos según lo que bloquean, segundo plano, criterios de aceptación y límites).
5. **`16_AUTOMATIZACION_ENVIOS_COMPLETOS.md` (2026-11-02; §15 A1+A2+A3 [IMPLEMENTADO en dev, versión 3 del tipo ACTIVA], despacho faltante NO implementado):** casos de prueba 46/47/48 del archivo 2026-11-03, matriz de estados, despacho faltante, entrega reportada, devolución ratificada, contradicciones, dinero y Cartera, decisiones D0–D7. §12 (fechas internas del pedido 102) **descartada por Camilo**; §13 archivo **SINTÉTICO** del pedido 102 (`docs/pruebas_cargues/SINTETICO_…pedido102…xlsx`), cargue no ejecutado; §14 plan corregido; **§15 implementado**: «Entregada» → entrega registrada + validada, «Devolución ratificada» → retorno pendiente de recepción, contradicciones → incidencia (Cargues 172/172, Logística 56/56; prueba manual de Camilo en §15.7). **§16 (2026-11-03): diagnóstico del cargue 20** (pedido 102 bloqueado por la regla de fecha D3; contrapago sin relacionar; defecto latente al corregir guías con reportes) **y propuesta de despachos + entregas** (preparación de Bodega, referencia explícita, confirmación agrupada); decisiones D0, D-A…D-H.
5. **`17_PRODUCTOS_CAMISETAS_PIEL_DURAZNO.md` (2026-11-04) — tipo «Productos — Camisetas piel de durazno» [IMPLEMENTADO, ACTIVO en dev: tipo 4 v1]:** producto configurable + variantes color/talla por los repositorios y eventos de Bagisto (extensión `productos_camisetas`, SP `sp_cg_productos_camisetas_v1` que solo clasifica), una transacción por producto base, sin stock, sin sobrescribir ni borrar; motor ampliado (genérico) con plantilla descargable por tipo, selector de acción y revisión agrupada. Probado: Pest 8/8, regresión 180/180, y por la interfaz en dev (cargas 21–24: 2 productos y 5 variantes sintéticas `PRUEBA-CARGUE-CAM-…` inactivos; repetición sin duplicados; huella de 132 tablas sin cambios). **§13 (2026-11-04, versión 2 activa):** niño con su eje real `grupo_de_edad_ninos` (2-4…14-16, sin tocar la familia); descripciones opcionales o armadas solo con datos confirmados (el producto importado ya se guarda desde el formulario); variantes con precio activas en productos nuevos; corrección en VentasAsesor (`buscarProductos`/`coloresDeAcceso` filtran por `products.parent_id`; `product_flat.parent_id` no lo escribe Bagisto 2.4); aviso `COLOR_YA_EN_LINEA`; marca BYLOPEZ verificada, manga/cuello/composición solo por `.env` explícito. Probado en navegador: importar → abrir → editar y guardar → chip en Nueva venta; matriz por endpoint; repetición sin duplicados (cargas 25–30). «Crear inactivos» sigue por defecto; productos de prueba inactivos.
5. **`13_ENVIOS_COMPLETOS_Y_E2.md`: archivo real (qué es cada dato), cruce y reglas del procedimiento, conciliación del recaudo, núcleo de ejecución E2, prueba manual, Cartera (solo identificación) y pendientes.**
5. **`12_PROTECCION_VALIDAR.md`: privilegios efectivos, `SQL SECURITY` y cuenta restringida de `VALIDAR`** (requisito previo para habilitar procedimientos reales).
5. **`11_E1_IMPLEMENTACION.md`: lo implementado en E1**, decisiones de implementación, límites del contrato, cola y worker (cómo iniciarlo y verificarlo), pruebas y **prueba manual**.
6. **`10_REFERENCIA_ENVIOS_COMPLETOS.md`: análisis del archivo real** de Envíos completos, sin datos personales (llave, estados, fechas, lectura y decisiones D-ENV).
6. `06_DECISIONES_PENDIENTES.md`: lo aprobado y los únicos pendientes.
7. `04_DISENO_UI_Y_ESTADOS.md`: mockup aprobado y contrastado, componentes reales, estados.
8. `05_ROADMAP_Y_ACEPTACION.md`: MVP y criterios de aceptación generales.
9. `01_AUDITORIA_BACKEND_FRONTEND.md` y `02_COMPARATIVA_IMPORTADOR_BAGISTO.md`: evidencia de la auditoría.
10. `03_ARQUITECTURA_Y_MODELO_DATOS.md`: **solo antecedentes; no implementar nada de él.**

Documentación existente que **no se duplica** (se referencia):
- `packages/ByLopez/CorteConfeccion/docs/GUIA_VISUAL_ADMIN_BAGISTO.md`: reglas visuales del Admin.
- `packages/ByLopez/Logistica/src/Config/acl.php`: reglas de ACL de Bagisto 2.4.9.
- `packages/ByLopez/VentasAsesor/docs/13_RT04B_DISENO_CLIENTE_COMERCIAL.md`: reglas del cliente comercial (relevante para un tipo Clientes, `07` §10).

## Alcance

**[APROBADO]:** lista completa en `06` §A. En síntesis:
- motor **parametrizable y dinámico**;
- configuración con nombre, descripción, activo, conexión, tabla o procedimiento, acción y `map`;
- modo TABLA sobre la estructura real, con TRUNCATE y REPLACE;
- modo PROCEDIMIENTO con **intermedia creada dinámicamente desde `map.tabla`**;
- auditoría JSON por fila antes de cualquier cambio de negocio;
- output de no cargados con estados inequívocos;
- solo administrador; ejecución manual.

**Pendientes:** únicamente los de `06` §B. En especial, **procesar las válidas o bloquear todo** sigue sin decidir.

**[APROBADO] Diseño (mockup):** "Nuevo cargue → Relacionar columnas". **Recibido y revisado** el 2026-10-13: `docs/mockups/cargues_nuevo_cargue_relacionar_columnas.png` (contraste en `04` §1.1). Guía visual **obligatoria**.

**Fuera de alcance hasta autorizar E2 y E3:** ejecución, recuperación, consolidación, actualización real de envíos, cartera o pedidos, modo tabla, dependencias nuevas en `composer.json`, commit/push.

## Estado por área

| Área | Estado |
|---|---|
| Mockup | Recibido y contrastado |
| Hoja de Envíos completos | Adjunto "(2)" **no llegó**. Analizadas en solo lectura las exportaciones del mismo reporte que había en el equipo (`10`). Falta confirmar si el "(2)" coincide |
| Hoja de Cartera | **No recibida** |
| Procedimiento real de Envíos completos | **[IMPLEMENTADO] v2 en dev** (`13` §13–14; v2: transportadora por código o nombre; paso «Resultado» por fila y confirmación en Logística › Entregas): reglas aprobadas el 2026-10-15; probado con datos sintéticos; prueba real pendiente de Camilo |
| Tipo «Clientes históricos — 99 Envíos» (Cartera) | **[IMPLEMENTADO] v2 en dev, fuente «Envíos completos»** (`14` §14; Cartera solo para conciliación financiera, `15`, sin implementar): extensión genérica del motor (`derivados` + aplicación por servicios de Laravel, una transacción por fila), SP `sp_cg_clientes_historicos_v1`, antecedentes en la ficha del cliente; **probado** (suite aislada 5/5 + 2 pruebas en dev con 9 filas reales); **no desplegado en producción, archivo completo sin importar**. Ajuste 2026-10-19 (`14` §15): asociación de personas **estricta** (solo mayúsculas/tildes/espacios) y transportadora original conservada (`transportadora_original`, clave `PEND:`); carga 18 revalidada (138 válidas = 126 clientes únicos, 14 rechazadas = 7 teléfonos), sin ejecutar |
| Procesamiento en segundo plano y progreso | [IMPLEMENTADO] cola `cargues` (validar y ejecutar) |
| Política de errores | El motor implementa las dos; elegir una bloquea **activar** el tipo, no el desarrollo |
| Verificación de producción | Requisito de **despliegue** (`09` §6) |
| Modos tabla y procedimiento | [APROBADO] |
| Intermedia dinámica, llaves y duplicados | Mecanismo [PROPUESTO], **validado** [V] (`08` §3.1–3.3) |
| Procedimiento, marcas, caídas, reanudación y consolidación en otro servidor | Contrato [PROPUESTO], **validado** en seis escenarios de caída [V] (`08` §3.4–3.6) |
| Cambio de estructura y `RENAME` | **Validado** en operación normal [V]; ante caída del servidor solo desde MariaDB 10.6.1 [DOC]; dev 10.4.28 |
| Lectura XLSX/CSV | Medida [V] (`08` §3.8–3.9, y con el archivo real en `10` §5). **Recomendación:** PhpSpreadsheet + límites. **Decisión pendiente** |
| `TRUNCATE` con respaldo y `LOCK TABLES` (S2) | **Pendiente de validación**; fuera de la fase 1 |
| Output y estados | Requisito [APROBADO]; estados [PROPUESTO]; política de errores [PENDIENTE] |
| Acceso | Solo administrador [APROBADO] |
| Código del módulo | **[IMPLEMENTADO] E1** (`packages/ByLopez/Cargues/src`, 125 pruebas en instancia aislada). Aplicado en la BD de desarrollo (escenario «Prueba E1»); protección de `VALIDAR` con cuenta restringida (`12`). E2/E3: ninguno |
| `packages/Webkul/` | Sin cambios |
