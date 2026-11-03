# 29 · Comisiones claras, avance de la asesora, cuenta del asesor y comprobantes de abono

Fecha: 2026-11-02/03 · Entorno: desarrollo local y demo aislada (`lpz_saas_demo_*`). **No** es producción. Sin push ni despliegue.
Aprobación de Camilo: «implementar las mejoras de Comisiones, avance de la asesora en Pedidos y cuenta de pagos con lectura de comprobantes».

## 1. Qué cambió, por pantalla

| Pantalla | Cambio |
|---|---|
| **Comisiones** (`/admin/ventas-asesor/comisiones`) | Resumen por asesora y mes (umbral, avance, computables, comisionables, cuatro etapas que no se solapan). Lista **agrupada por pedido** (número, fecha, unidades, comisionables, comisión, situación) con «Ver cálculo» desplegable. Filtros compactos (plegables en móvil). Avisos generales una sola vez; motivos junto al pedido. Lo desconocido se muestra «no se puede calcular», nunca `$ 0`. Administración ve precio neto, costo histórico, base por unidad, % histórico, unidades comisionables y comisión; la asesora **no** ve costos ni márgenes. Si las comisiones están desactivadas se dice, sin ocultar estimaciones. |
| **Mis comisiones** | Misma lógica para la asesora; total del mes = suma de etapas (una sola vez cada peso). |
| **Mis ventas / Pedidos** | Tarjeta «Tu avance del mes»: umbral del acuerdo (no fijo), «Vas en X de N», barra, «Te faltan N… Comisionas desde la siguiente unidad», al superar: comisionables y estimada (calculada en servidor), **despachadas y entregadas por separado**, acceso a Mis comisiones. «Simular próxima venta» (secundaria, solo lectura, rotulada como simulación). Para administración no se atribuyen cifras de todos a una persona. |
| **Asesores** | Comparativo por persona: avance, computables, despachadas, entregadas, estimación y saldo aprobado pendiente. |
| **Cuenta del asesor** (en Liquidaciones y pagos) | Saldo de liquidaciones aprobadas (retenido vs pagable), habilitada sin liquidar, estimación pendiente, pagos realizados (reversos y compensaciones diferenciados); actividad del mes separada del saldo acumulado; historial de movimientos (fecha, medio, referencia, soporte, liquidación). Reutiliza el libro de comisiones: **no** hay segunda cartera ni otra fuente de saldos. No se copiaron préstamos/anticipos/sobrepagos. |
| **Registrar abono** | Flujo cargar comprobante → leer → revisar → confirmar → registrar. La lectura **sugiere** monto, fecha, entidad/medio, referencia; campos editables, dudosos marcados; el soporte se ve junto al formulario. Si la lectura falla o no está disponible, se diligencia a mano. |

## 2. Lectura de comprobantes (reutilización)

Se reutiliza, **sin modificarla**, la pieza de CorteConfeccion: `ComprobanteLector` (OCR local Tesseract, el archivo no sale del servidor), `BorradorOperacion`, `DeduplicadorOperaciones` y el contrato `FuenteOperacionesExistentes` (implementado aquí por `FuenteAbonosAsesor`). Lo propio de VentasAsesor: tabla `bylopez_va_comision_soportes`, disco privado, servicio `SoporteAbonoService`.

- Cargar y leer **no** registran ni aprueban nada; solo el abono confirmado por administración (permiso de pago).
- Duplicados: archivo idéntico (huella SHA-256) se reutiliza; misma operación (huella banco|referencia|fecha|valor|moneda) con otro archivo → advertencia y confirmación explícita; **no** se concluye duplicidad solo por monto y fecha. Un mismo soporte que cubre varias aplicaciones es válido (tope: Σ abonos activos ≤ valor de la operación). Registro simultáneo serializado con `GET_LOCK` por huella.
- Pagos a asesoras se separan de cobros de clientes (siempre egreso).
- Archivos privados con descarga autorizada; el contenido real (MIME) manda sobre la extensión.
- La lectura es una **ayuda**: no verifica autenticidad ni confirma movimientos bancarios.
- Trazabilidad detectado/corregido guardada en el abono.

## 3. Reglas conservadas
Venta neta − costo; % histórico vigente al confirmar; gastos comerciales aparte; umbral mensual; habilitación por entrega validada + pago confirmado + dinero recibido; límite de abono al pagable. **D3, D4, D5, D6, D10 y D12 siguen sin activar** (doc 27). Comisiones y C6 solo activos en la demo.

## 4. Pruebas ejecutadas (base `lpz_saas_testing`, una suite a la vez)

| Suite | Resultado |
|---|---|
| Com06PanelAvanceTest (panel, avance, simulador, permisos, HTTP) | 8 de 8 |
| Com07CuentaComprobantesTest (cuenta CU-1..3; comprobantes CO-1..4) | 7 de 7 (CO-1 legible, CO-2 ilegible/formatos, CO-3 duplicados, CO-4 separación y permisos) |
| Com05, Com04 (regresión del libro) | 3 de 3 y 8 de 8 |
| Asesor01Identidad | 3 de 3 |
| PagFRolAsesorVentas + Fase05aMisVentas | 14 de 14 (en una primera pasada fallaron por deadlock 1213 al coincidir con otra sesión usando la misma base de pruebas; repetidos sin esa carga, pasan) |
| Com03ReglasComision · COM03-1 | **Falla igual que el 2026-10-29**: afirma que la tabla `bylopez_va_comision_lineas` no existe (COM-04 ya la creó). Expectativa desactualizada, no regresión. |

Ejemplos numéricos verificados (expectativa calculada aparte): 998 + 5 con $10.000 / $6.000 / 10 % → 3 comisionables, $1.200; cambio de % sin alterar ventas anteriores; liquidación $1.200 con abonos $500 y $700 → saldo $700 y $0; reintento sin duplicar; exceso rechazado por causa correcta; dos asesoras sin ver datos ajenos; mismo importe en Pedidos, Comisiones, cuenta y detalle.

## 5. Pasos para probar
**Administrador:** Comisiones → elegir mes → abrir «Ver cálculo» de un pedido; Asesores → comparativo; Comisiones → Liquidaciones y pagos → Registrar abono → subir comprobante → revisar lo sugerido → confirmar.
**Asesora:** Mis ventas → «Tu avance del mes» → «Simular próxima venta» → «Ver mis comisiones».

## 6. Pendiente / límites reales
- Política D3/D4/D5/D6/D10/D12 y remesa manual: sin aprobar (el simulador lo dice y no inventa).
- Rol real «ASESOR VENTAS» sin la hoja `view_own` (decisión de asignación).
- **Concurrencia real** de dos solicitudes sobre la misma operación: probada en secuencia y con `GET_LOCK`; no se ejecutó una prueba con dos procesos simultáneos.
- **Rendimiento:** la vista de administración hace ~15 consultas por pedido (319 para 21 pedidos; 6,8 s en frío en este equipo). Aceptable en la demo; con cientos de pedidos al mes requiere precarga por lotes o paginación (no implementado).
- Safari/iPhone físico: **no probado** (solo Chrome headless a 390 px).
- Dependencia: la lectura usa código de CorteConfeccion **sin commit** de otra sesión; sin él, el lector queda «no disponible» y el abono es manual.
- La migración `2026_11_02_000001_create_bylopez_va_comision_soportes_and_trace_columns` está aplicada en testing y demo, **no** en desarrollo.
- Avisos: el `demo.sh php artisan migrate` aplicó en la demo también migraciones ajenas (CorteConfeccion y dos de VentasAsesor de otra sesión); solo base demo.
