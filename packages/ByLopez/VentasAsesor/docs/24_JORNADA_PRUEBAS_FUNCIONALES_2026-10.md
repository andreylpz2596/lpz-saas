# 24 · Jornada de pruebas funcionales en navegador — mes comercial completo (venta → comisión → abono)

**Fecha:** 2026-10-21/22 · **Responsable funcional:** Camilo Gaitán · **Ejecutó:** Claude (Claude Code) con autorización expresa para navegador y pruebas.
**Alcance:** solo pruebas y reporte. **No se modificó código de negocio, Webkul, migraciones ni estructura. Sin commit, push ni producción.**

> Entorno **aislado** (copia de la BD de desarrollo + almacenamiento propio). La BD de desarrollo `lpz_saas` **no recibió escrituras de la jornada** (verificado al cierre, §F.4). Los números de pedido de esta jornada (#81–#110) existen **solo en `lpz_saas_jornada`**; en desarrollo hay otros pedidos con los mismos números creados en paralelo por usuarios reales.

---

## A. Resumen ejecutivo

**¿Terminó el flujo completo?** **Sí**, para pagos directos (transferencia/recogida) y para contra entrega con domiciliario de cuenta corriente (Jeison/Brian): venta → despacho → entrega reportada → validada → cobro confirmado → liquidación de transportadora → comisión habilitada → liquidación de comisiones → abono parcial → reverso → abono → comprobante. **No** para contra entrega por 99 Envíos/Inter Rapidísimo (bloqueado por diseño: no hay forma de probar la recepción del dinero).

**Qué funciona bien (probado en navegador y contrastado con SQL)**

- **Umbral y comisiones:** coinciden exactamente con el cálculo independiente (A sep.: 34 computables, 31 comisionables, $ 8.170 estimada; B sep.: 1.005 / 5 / $ 1.136). Límite **999 → 1.000 → 1.001** correcto. No retroactivo, separado por asesora y mes, cambio de % sin alterar pedidos anteriores (10 % → 12 %), «Vender para» con atribución explícita.
- **Reevaluación automática** sin pulsar el botón después de validar, cobrar, liquidar, anular y reembolsar (bandeja con 0 pendientes).
- **Habilitación (A-04):** entrega validada sin dinero no habilita; comprobante sin aprobar no habilita; pago global multipedido con aplicaciones identificables; cobro de domiciliario sin liquidar = «recaudo en custodia».
- **Cargues 99 Envíos:** VALIDAR no toca negocio (CHECKSUM idéntico en 5 cargas); EJECUTAR solo escribe las 2 tablas de estado del proveedor; repetir no duplica; antiguo/reciente, duplicados, conflicto, guía de otra transportadora y guía inexistente se rechazan con motivo; nunca se crea una entrega ni una validación.
- **Idempotencia:** doble clic en confirmar pago, liquidar transportadora, abonar comisión → una sola operación; segunda liquidación sobre los mismos movimientos → 409.
- **Permisos:** la asesora no ve ni opera pedidos, pagos, carteras ni comisiones de la otra; no puede confirmar su propio cobro, validar, cobrar, liquidar ni entrar a Cargues/Rentabilidad/Cuentas.
- **Devoluciones e incidencias (R4):** retenido de lo no pagado, ajuste propuesto **solo por lo pagado**, nada se descuenta solo, «Mantener comisión» libera, se siguen pagando las líneas válidas, el abono se limita a lo pagable (cliente y servidor).

**Qué falla (detalle en §D)**

1. **[CRÍTICO dinero] Despacho parcial duplica el recaudo:** cada guía guarda como recaudo esperado el **total del pedido** (no editable). Pedido de $ 401.200 en 2 envíos → la cuenta de Brian esperó $ 401.200 + $ 366.700 y quedó una **diferencia fantasma de $ 34.500** a cargo del domiciliario.
2. **[ALTO dinero] Ajuste negativo aprobado no se invalida tras revertir el abono** que lo originó (queda «aprobado» por −$ 200 con $ 0 pagado y sigue elegible).
3. **[ALTO operación] Ventas simultáneas fallan por deadlock** en `cart_shipping_rates` (4 de 6 intentos concurrentes) sin reintento.
4. **[MEDIO proceso] Liquidar la cuenta del domiciliario no registra el cobro en Cartera**: hay que registrar el «cobro contra entrega» pedido por pedido; si se olvida, la comisión queda atascada (#86).
5. **[MEDIO proceso] Las unidades devueltas no reducen el saldo de Cartera**: #87 quedó con $ 34.500 «pendientes» para siempre; la única salida (reembolso Bagisto) cae en D10.
6. Funcionales menores: «El campo canal es obligatorio» al crear cliente rápido (2/10), aviso de «configuración pendiente» invisible con el bloqueo apagado, texto engañoso «al validar se habilita la comisión», rol ASESOR VENTAS sin acceso a «Mis comisiones».

**Qué quedó bloqueado por decisión o flujo pendiente (no se aprobó nada)**

| Bloqueo | Evidencia |
|---|---|
| D3 pedido mixto que cruza el umbral | #97: «política pendiente», no se habilita |
| D10 devolución posterior / reembolso | #98, #90, #92, #99: `devolucion_sin_politica`; un reembolso de 1 unidad bloquea **todas** las líneas del pedido |
| D6 ajustes negativos | Se pueden aprobar aunque D6 está pendiente (ver D.2) |
| Contra entrega 99 Envíos sin conciliación de Cartera (doc. Cargues 15) | #83: `recaudo_sin_cuenta_corriente`, nunca habilita |
| D4 cambio de umbral a mitad de mes | Visto en datos copiados de Karen («Umbral cambió en el mes»); no se probó con cuentas QA |

**Rendimiento:** la base de datos **no** es el cuello de botella (SQL 9–124 ms por pantalla). El tiempo se va en PHP: **OPcache no está cargado** en este XAMPP, Debugbar activa y un **N+1** en «Colores» de Nueva venta (221–231 consultas, 5,7–6,6 s). Cada interacción de Nueva venta tarda **5–18 s**; una venta completa, **80–165 s** de espera.

---

## B. Matriz de casos

Clave de método: **NAV** = navegador real (Chromium headless sobre el servidor aislado), **API-UI** = mismo endpoint que usa la pantalla, llamado desde la sesión del navegador, **SRV** = servicio oficial por CLI (preparación), **SQL** = solo contraste.

| ID | Rol | Pedido/carga/guía | Pasos | Esperado | Obtenido | Evidencia | Estado |
|---|---|---|---|---|---|---|---|
| V-01 | Asesora A | #81 | Crear cliente General → dama 2 colores → domicilio local Jeison → contra entrega → confirmar | Pedido con precio del grupo y atribución A | $ 55.000, atribución A, costo 4.900 congelado, % 10 | `nv_confirmada.png`, SQL `pedido.sh 81` | APROBADO |
| V-02 | Asesora B | #82 | Cliente VIP_1, caballero 22 u., recogida, transferencia | Precio VIP_1 6.900 | Correcto; exige dirección de facturación para recogida | `ventas/ui02.png` | APROBADO |
| V-03 | A | #83 | Niño, Medellín, «envío por pagar», contra entrega | Sin tarifa nacional se ofrece por pagar/gratis | Correcto | `ventas/ui03.png` | APROBADO |
| V-04 | B | #84 | Cliente existente, dama + caballero, Brian, transferencia | Dos líneas | Correcto; segunda línea exige «Editar colores» | `ventas/ui04*.png` | APROBADO (UX U2) |
| V-05 | A | #85 | Tres líneas, VIP_3, recogida | 12 u. | Correcto; niño 10-12 sin precio de grupo (6.500) | `ventas/ui05.png` | APROBADO (dato F10) |
| V-06 | A | #86 | Crop Top (sin estado de costo) + dama, envío gratis Jeison | Aviso «configuración pendiente» | Snapshot `incompleta/costo_sin_estado` ✓ pero **ningún aviso en pantalla** | `ventas/ui_borrador_crop.png`, JSON rentabilidad | FALLÓ (F3) |
| V-07 | B | #87 | 58 u. mayorista con niño 6-8 bajo mínimo | Aviso «Precio bajo el mínimo» | Aviso visible nombrando la referencia | `ventas/ui07.png` | APROBADO |
| V-08 | A | #104 | Vender a cliente de B | Regla de propiedad | Se permite sin aviso; atribución a A (`auto_asesor`) | SQL atribución | OBSERVACIÓN (F11) |
| V-09 | Admin | #109 | «Vender para» QA A | Atribución explícita, % vigente | `seleccion_explicita`, 12 % | SQL | APROBADO |
| V-10 | A | #110 | Venta tras cambio de % | 12 % | 12 %; anteriores conservan 10 % | SQL | APROBADO |
| V-11 | A | — | Crear cliente rápido apenas abre la pantalla | Crear | «El campo canal es obligatorio» (2/10) | `ventas/ui05_atasco.png`, `ventas/ui10_atasco.png` | FALLÓ (F2) |
| V-12 | A | — | Cambiar grupo / Vender para como asesora | No permitido | No se muestran | snapshot | APROBADO |
| H-01 | SRV | #88–#103, #95, #96 | 16 ventas sep./1-oct. con reloj simulado de proceso | Historia del mes | Creadas; tarifas locales no existían en sep. → recogida | `scripts/historia_ventas.php` | PREPARACIÓN |
| U-01 | SQL | A sep. | Cálculo independiente umbral 3 | 34 / 31 / $ 8.170 | Idéntico | `com_resumen_sep.png` | APROBADO |
| U-02 | SQL | B sep. | 400+400+199 → +1 → +2 → +3 (umbral 1.000) | 1.000.ª no comisiona; 1.001+ sí; $ 1.136 | Idéntico | idem | APROBADO |
| U-03 | NAV | #97 | Pedido mixto que cruza umbral | Bloqueo D3 | «Política pendiente», no habilita | SQL | BLOQUEADO (decisión D3) |
| U-04 | NAV | #92 | Venta 30-sep 23:55, cobro en octubre | Mes septiembre, habilita al cobrar | Habilitada $ 1.200 en mes 2026-09 | SQL | APROBADO |
| U-05 | NAV | #96/#81/#106 | Separación por mes y por asesora | Octubre reinicia | A oct. 42 computables; B oct. 88 | SQL | APROBADO |
| U-06 | NAV | acuerdo A | Cambiar 10 % → 12 % | No retroactivo | Correcto, versión anterior cerrada al instante | SQL acuerdos | APROBADO |
| U-07 | NAV | #81 | Pedido de una referencia que cruza el umbral | Reparto | Total correcto (5 u.); reparto proporcional entre variantes, no secuencial por ítem | SQL | OBSERVACIÓN (aclarar alcance D3) |
| R-01 | NAV | varios | Validar, cobrar, liquidar sin pulsar «Reevaluar» | Reevaluación automática | Estados cambian solos; bandeja 0 | SQL | APROBADO |
| R-02 | SRV | 4 ventas simultáneas | Mismo asesor/mes y asesores distintos | Sin duplicados | Ledger sin claves duplicadas, pero 4/6 fallan por **deadlock** | `/tmp/conc_*.txt` | FALLÓ (F1) |
| D-01 | Bodega | #81 | Despachar domicilio local | GI automática, sin dinero | GI-000010, 0 movimientos | SQL | APROBADO |
| D-02 | Bodega | #83/#89 | Guía nacional 99 Envíos | Guía manual | QA99E0083/0089 | SQL | APROBADO |
| D-03 | Bodega | 12 recogidas | Entregar en bodega | Envío + entrega, sin validar | Correcto (confirm nativo) | `bodega_82*.png` | APROBADO |
| D-04 | Bodega | #87 | Despacho parcial 30/58 y «Completar despacho» 28 | Recaudo repartido | **Ambas guías con $ 401.200** | SQL guías 30/31 | FALLÓ (C1) |
| D-05 | Bodega | #85 | Entrega parcial en bodega (4/12) | Despacho parcial | Correcto | SQL | APROBADO |
| E-01 | Bodega | #81 | Registrar entrega completa | +recaudo −servicio | +55.000 −10.000 | SQL movimientos | APROBADO |
| E-02 | Bodega | #87 env. 74 | Entrega 25 + devolución 5 («cliente rechaza») | Revisión con sugerido | Revisión; sugerido $ 366.700 (sobre recaudo duplicado) | `brian_revision.png` | APROBADO (efecto de C1) |
| E-03 | Bodega | #87 | Recepción de devolución: 4 aptas, 1 con novedad | Reingresan 4 | Stock 139 → 143; motivo por línea obligatorio | SQL inventario | APROBADO |
| E-04 | Admin | 17 envíos | Validar entregas | Validación ratificada | 16 OK; env. 74 sin acción hasta recibir devolución | `/tmp/val.txt` | APROBADO |
| E-05 | Admin | #82 | Anular validación | Vuelve a «envío sin validar» | Correcto; no deja anular la entrega con validación vigente | SQL | APROBADO |
| E-06 | Asesora | env. 59 | Validar/cobrar/liquidar por API | 401/403 | 401 | `/tmp/ais.txt` | APROBADO |
| C-01 | Admin | cargas 20–24 | Cargar → validar → ejecutar → repetir | Ver §5 del encargo | Todo según reglas; VALIDAR CHECKSUM idéntico | `cargues/*.png`, `cargues/ck_*` | APROBADO |
| C-02 | Admin | carga 21 | Reintentar validación tras fallo | Reintento | Funciona | idem | APROBADO |
| C-03 | — | — | Cargue de Cartera | No existe | No implementado (doc. Cargues 15) | — | NO EJECUTADO (no existe) |
| P-01 | Asesoras | 13 pagos | Registrar transferencias con comprobante | Sin revisar | Correcto; comprobante en almacenamiento privado | SQL pagos 19–31 | APROBADO |
| P-02 | B | abono 32 | Abono multipedido FIFO editable | Aplicaciones identificables | 82: 51.800 / 84: 60.300 | SQL | APROBADO |
| P-03 | Admin | 12 pagos | Confirmar (doble clic en el primero) | Una transacción | Una transacción por pago | SQL | APROBADO |
| P-04 | Admin | pago 27 | Rechazar con motivo | Aplicación anulada | Correcto | SQL | APROBADO |
| P-05 | Admin | #81/#87/#83 | «Registrar y confirmar cobro» contra entrega | Cobro confirmado | Correcto; #87 queda con saldo 34.500 (unidades devueltas) | SQL | APROBADO (proceso C5) |
| P-06 | — | — | Sobrepago / anticipo | No existe (PAG-H) | — | NO EJECUTADO (no existe) |
| L-01 | Admin | LIQ-000001 Jeison | Liquidación conciliada $ 65.000 + repetición | Cierra, idempotente | Correcto; #81 habilita solo | SQL | APROBADO |
| L-02 | Admin | LIQ-000002 Brian | Diferencia $ 34.500 sin y con observación | Exige observación, traslada diferencia | Correcto (+34.500 abierto) — diferencia fantasma por C1 | SQL | APROBADO (efecto C1) |
| L-03 | Admin | cuenta Jeison | Liquidar desde el drawer | Seleccionar | Solo «todo lo abierto» (incluye pedidos de Karen) → no se confirmó en el drawer; se usó el mismo endpoint con subconjunto QA | `cuenta_jeison_liquidar.png` | OBSERVACIÓN (F7) |
| L-04 | Admin | #86 | Liquidación conciliada sin cobro en Cartera | ¿Habilita? | No: `saldo_pendiente` | SQL | FALLÓ proceso (C6) |
| K-01 | Admin | LQC-000001 A | Crear → aprobar → abono 2.000 (doble clic) | Un abono, FIFO | Correcto (90 1.000, 91 520, 92 480) | SQL aplicaciones | APROBADO |
| K-02 | Admin | LQC-000001 | Abono > saldo (cliente y servidor) | Rechazo | Rechazado en ambos | SQL abonos | APROBADO |
| K-03 | Admin | #98 | Reembolso 1 u. antes de liquidar | No liquidable | Todas las líneas `devolucion_sin_politica` | SQL | BLOQUEADO (D10) |
| K-04 | Admin | #99 | Reembolso con comisión liquidada sin pagar | Incidencia, retención | Incidencias, retenido, sin ajuste | SQL incidencias | APROBADO |
| K-05 | Admin | #92 | Reembolso con abono parcial | Ajuste solo por lo pagado | −300 / −180 propuestos | idem | APROBADO |
| K-06 | Admin | #90 | Reembolso con comisión pagada | Ajuste pendiente, sin descuento | −200/−400/−400 pendientes | idem | APROBADO |
| K-07 | Admin | ajuste #3 | Aprobar ajuste negativo con D6 pendiente | Bloqueado | **Se aprueba** | SQL ajustes | FALLÓ / decisión (C3) |
| K-08 | Admin | incidencia #1 | «Mantener comisión» | Libera retenido | Retenido 2.400 → 1.620; pagable 1.300 → 2.080 | captura | APROBADO |
| K-09 | Admin | abono 1 | Revertir abono | Espejo exacto | −2.000 por aplicación | SQL | APROBADO |
| K-10 | Admin | ajuste #3/#4 | Tras reverso | Invalidar | #4 pendiente: bloqueado ✓; **#3 ya aprobado sigue vigente** | SQL | FALLÓ (C2) |
| K-11 | Admin | LQC-000002 B | Ciclo completo y pago total | Saldo 0 | Correcto | `com_liq_B.png` | APROBADO |
| K-12 | Admin / asesora | abono 5 | Comprobante de abono | Descarga propia | Admin 200 (PNG); asesora rol 2: 401 (sin clave) | SQL | APROBADO / F5 |
| K-13 | B (rol QA con view_own) | «Mis comisiones» | Ver solo lo propio | Solo B, sin costo/base | Correcto; 404 a A | `mis_comisiones_B.png` | APROBADO |
| N-01 | NAV | 11 pantallas | Carga + Debugbar | — | Ver §D.4 | `/tmp/perf.txt` | MEDIDO |

---

## C. Conciliación por asesora y mes (entorno aislado, al cierre)

| Asesora · mes | Pedidos | Unid. vendidas | Restadas en umbral | Computables | Comisionables | Estimada (panel) | Habilitada | Liquidada | Pagada (neto) | Retenida | Saldo liquidaciones |
|---|---|---|---|---|---|---|---|---|---|---|---|
| A · 2026-09 | 8 | 34 | 0 (4 u. reembolsadas **no** restan: D10) | 34 | 31 | $ 8.170 | $ 4.400 | $ 4.400 | $ 880 (99: 780 · 91: 100) | $ 3.100 (90, 92, 99 l.94) | ver fila oct. |
| A · 2026-10 | 10 | 42 | 0 | 42 | 39 | $ 9.258 | $ 1.300 | $ 1.300 | $ 1.300 | $ 0 | LQC-000001: total $ 5.700 − pagado $ 2.180 = **$ 3.520** |
| B · 2026-09 | 6 | 1.005 | 0 | 1.005 | 5 | $ 1.136 | $ 1.136 | $ 1.136 | $ 1.136 | $ 0 | LQC-000002: **$ 0** |
| B · 2026-10 | 6 | 93 | 5 (no entregadas #87) | 88 | 0 | $ 0 | $ 0 | — | — | — | — |

**Categorías superpuestas (no sumar):** «Estimada» del resumen es el cálculo provisional de **todas** las líneas del mes, incluidas las ya habilitadas (A sep.: $ 8.170 contiene los $ 4.400 habilitados). Habilitada ⊂ Estimada; Liquidada ⊂ Habilitada; Pagada ⊂ Liquidada; Retenida ⊂ (Liquidada − Pagada). El saldo de liquidación es uno solo por liquidación (no por mes). Abonos registrados $ 6.280 brutos = 4.080 + 100 + 1.136 + reverso 2.000 tratado como resta → **neto A $ 2.180, B $ 1.136**.

---

## D. Hallazgos priorizados

### D.1 Críticos de dinero o permisos

| # | Hallazgo | Evidencia | Corrección propuesta (no implementada) |
|---|---|---|---|
| **C1** | En despacho parcial cada guía hereda `recaudo_esperado` = total del pedido y el drawer no permite editarlo («Solo lectura»). La cuenta del domiciliario espera el total por cada envío; la revisión sugiere sobre esa base; al liquidar con el dinero real queda una diferencia que **no existe** trasladada al domiciliario. | #87: GI-000011 y GI-000012 con 401.200; movimiento +401.200 por 28/58 u.; LIQ-000002 diferencia 34.500 | Proponer recaudo = total − recaudo ya asignado a otras guías (y editable con motivo); validar Σ recaudo de guías ≤ saldo del pedido. Requiere aprobación (regla de dinero). |
| **C2** | Un ajuste negativo **ya aprobado** no se invalida cuando se revierte el abono que lo originó: queda −$ 200 «aprobado» con $ 0 pagado y sigue elegible para la próxima liquidación. (El pendiente sí se bloquea.) | ajuste #3, abono 1 revertido | Al revertir un abono, devolver a «pendiente/obsoleto» los ajustes aprobados no aplicados de esas líneas, o validar al incluirlos que lo pagado sigue igual. |
| **C3** | Ajustes negativos aprobables aunque D6 sigue pendiente. | ajuste #3 aprobado | Decidir D6; mientras tanto, ocultar «Aprobar» en negativos o exigir permiso específico. Decisión de Camilo. |
| **C4** | Contra entrega por 99 Envíos/Inter nunca habilita comisión (`recaudo_sin_cuenta_corriente`): no hay registro que pruebe la recepción. | #83 | Implementar la conciliación de Cartera con 99 Envíos (doc. Cargues 15) o habilitar la cuenta corriente del proveedor. Decisión. |
| **C5** | Las unidades devueltas no reducen lo que debe el cliente: el pedido queda con saldo y la comisión atascada; la única salida (reembolso) bloquea por D10. | #87 saldo 34.500 | Flujo de «no entregadas» que ajuste el saldo exigible del pedido (nota crédito/cancelación de lo no entregado). Requiere aprobación. |
| **C6** | Liquidar la cuenta del domiciliario no registra el cobro de los pedidos en Cartera; hay que hacerlo uno por uno. | #86 sigue `saldo_pendiente` tras LIQ-000001 | Al cerrar una liquidación conciliada, proponer el cobro de los pedidos incluidos (un clic, con confirmación). |

Permisos: **sin fallas** de aislamiento entre asesoras (pedidos, carteras, pagos, comprobantes, comisiones, validación, cobro, liquidación, Cargues).

### D.2 Errores funcionales

| # | Hallazgo | Evidencia |
|---|---|---|
| F1 | Deadlock en `cart_shipping_rates` con ventas simultáneas (4/6 intentos), sin reintento; el usuario vería un error. En producción con varios workers ocurrirá. | `/tmp/conc_*.txt` |
| F2 | «El campo canal es obligatorio» si se pulsa «Crear y continuar» antes de que cargue el estado inicial (2/10). | capturas `ui05_atasco`, `ui10_atasco` |
| F3 | El aviso «configuración pendiente» solo aparece con el bloqueo activo (contradice Doc. 23 §8.2). | JSON `configuracion_pendiente:true`, sin aviso |
| F4 | Texto «Al validar… se habilita la comisión del asesor» en el drawer de validación: falso (faltan cobro y dinero). | `validar_81*.png` |
| F5 | El rol ASESOR VENTAS no tiene `comisiones` / `view_own` / `comprobantes`: las asesoras no ven «Mis comisiones» ni sus comprobantes (Doc. 23 §5 lo indica, sin aplicar). | 401 |
| F6 | En desarrollo, pedidos anteriores al corte de Cartera (2026-10-06) no admiten pagos → nunca habilitan. Para la jornada se adelantó el corte solo en el entorno aislado. | detalle #90 sin «Registrar pago» |
| F7 | La liquidación de transportadora desde el drawer incluye siempre **todo** lo abierto (sin selección ni rango), mezclando pedidos de varias asesoras. | `cuenta_jeison_liquidar.png` |
| F8 | Un reembolso parcial bloquea **todas** las líneas del pedido (D10). | #98 |
| F9 | El producto «Camiseta Hombre Rojo» (145) respondió `producto_no_vendible` por servicio. No se verificó en pantalla. | salida `historia_ventas` |
| F10 | Datos de catálogo: niño 10-12 sin precios por grupo; niño 14-16 VIP_2 (6.900) > VIP_3 (6.100); niño 6-8 VIP_1 (5.800) bajo el mínimo con los valores de hipótesis. | SQL precios |
| F11 | Una asesora puede vender a cliente de otra sin aviso; la venta queda a nombre de quien vende. Confirmar la regla comercial. | #104 |
| F12 | Listado de Cuentas: columnas «Recaudado/Servicios» en $ 0 aunque hay saldo; el monto grande del drawer se superpone al texto. | captura |
| F13 | Validación del request antes de la autorización (422 por formato antes del 404/401) y, con APP_DEBUG, respuestas con rutas internas. | `/tmp/ais.txt` |

### D.3 Problemas de uso

| # | Pantalla | Problema |
|---|---|---|
| U1 | Nueva venta, Despachos | El primer clic se pierde si la pantalla aún no terminó de cargar («Despachar», «Crear y continuar»). |
| U2 | Nueva venta | Las líneas son interruptores acumulativos: al pasar de dama a caballero quedan ambas activas y los colores se mezclan («1 de 2 disponible»); además hay que encontrar «Editar colores». |
| U3 | Nueva venta | Recogida con cliente nuevo exige dirección de facturación (paso extra en mostrador). |
| U4 | Todo | Moneda inconsistente: «7500 COP», «$ 10.000», «10.000,00 $». |
| U5 | Despachos, Cargues | Confirmaciones con `confirm`/`prompt` nativos; en Cargues aparece «No se pudo ejecutar» antes de intentar. |
| U6 | Cartera | El abono general no permite adjuntar comprobante (el pago desde el pedido sí). |
| U7 | Despachos | En un envío a Medellín sugiere elegir zona Bogotá/Soacha. |
| U8 | Entregas (recepción) | Motivo por línea obligatorio además de la observación general. |

Clics medidos (venta completa): cliente nuevo + 1 línea + domicilio ≈ **17 clics y 8 campos**; 3 líneas + recogida ≈ **24 clics y 12 campos**; despacho precargado = **2–3 clics**; validar entrega = 2; confirmar pago = 2.

### D.4 Rendimiento (medido con Debugbar del request principal)

| Operación | Navegador | PHP | Consultas | SQL | Comentario |
|---|---|---|---|---|---|
| Nueva venta (pantalla) | 2,9 s | 1,4 s | 7 | 9 ms | — |
| Mis ventas | 5,0 s | 1,7 s | 28 | 47 ms | — |
| Despachos | 5,7 s | 3,0 s | 18 | 35 ms | — |
| Entregas | 5,7 s | 2,5 s | 25 | 124 ms | — |
| Comisiones · resumen | 4,2 s | 1,6 s | 34 | 31 ms | — |
| Colores (Nueva venta, XHR) | 5,7–6,6 s | 5,7–6,6 s | 221–231 | ~0,2 s | **N+1**: `attribute_families` 175 veces, `attribute_option_translations` 35 |
| Tallas al carrito (XHR) | 5–10 s | — | — | — | POST + GET rentabilidad en serie |
| Confirmar venta | 5,4–9,7 s | — | — | — | — |
| Guardar usuario Bagisto | 8–11 s | — | — | — | — |
| Drawer de envío | 2–6 s (un caso 62 s) | — | — | — | outlier no reproducido |

Conclusión con evidencia: **SQL < 0,13 s** en todas las pantallas; el costo es PHP. Causas medidas: arranque 0,73 s (**OPcache no cargado**), Debugbar activa, N+1 en catálogo de colores. El servidor de pruebas (php -S, un hilo) serializa las peticiones; los tiempos reales con un servidor multiproceso y OPcache deberían ser menores (no medido).

---

## E. Mejoras recomendadas (sin implementar)

| Prioridad | Pantalla | Problema observado | Propuesta | Clics/tiempo ahorrados | Riesgo / ¿requiere aprobación? |
|---|---|---|---|---|---|
| 1 | Despachos (parcial) | Recaudo duplicado por guía (C1) | Recaudo restante propuesto y editable con motivo; validar Σ ≤ saldo | Evita diferencias fantasma y ajustes manuales | Regla de dinero: **sí** |
| 2 | Cuentas de transportadoras ↔ Cartera | Doble registro del mismo dinero (C6) | Al cerrar la liquidación conciliada, proponer el cobro de cada pedido incluido | ~3 clics × pedido | Preserva A-04 (sigue exigiendo liquidación cerrada): **sí** |
| 3 | Nueva venta | 5–18 s por interacción; colores 6 s | OPcache + sin Debugbar en producción; eager loading en colores; devolver rentabilidad en la misma respuesta | 4–10 s por interacción; ~1 min por venta | Técnico, sin cambio de reglas: no |
| 4 | Nueva venta / confirmación | Deadlocks con ventas simultáneas (F1) | Reintento automático de la transacción (2–3 intentos) y mensaje claro | Evita reprocesos | Técnico: no |
| 5 | Comisiones | Ajuste aprobado obsoleto tras reverso (C2) y aprobación de negativos con D6 pendiente (C3) | Invalidar al revertir; ocultar «Aprobar» en negativos hasta decidir D6 | — | **Sí** (D6) |
| 6 | Nueva venta | Clic perdido / «canal obligatorio» (F2, U1) | Deshabilitar acciones hasta cargar el estado inicial y mostrar «Cargando…» | 1 reintento | No |
| 7 | Nueva venta | Líneas acumulativas (U2) | Una línea activa a la vez o separar claramente «agregar otra línea» | 1–2 clics | No |
| 8 | Validación de entrega | Texto engañoso (F4) | «Al validar, la entrega queda ratificada; la comisión se habilita cuando además el cobro esté confirmado y el dinero recibido» | — | No |
| 9 | Rentabilidad | Aviso de configuración pendiente invisible (F3) | Mostrarlo también con el bloqueo apagado | — | No (alinea con Doc. 23) |
| 10 | Roles | Asesoras sin «Mis comisiones» (F5) | Agregar `comisiones`, `view_own`, `comprobantes` al rol ASESOR VENTAS | — | **Sí** (cambio de rol real) |

---

## F. Datos de prueba, configuración y cómo volver al estado previo

### F.1 Entorno aislado

| Pieza | Valor |
|---|---|
| Base de datos | `lpz_saas_jornada` (copia de `lpz_saas` del 2026-10-21 21:02; procedimientos recreados con comentarios para conservar su huella) |
| Almacenamiento | `C:\xampp\jornada_storage` (sesiones, logs, comprobantes, archivos de cargue) |
| Servidor | `http://localhost:8010` (`php -d variables_order=EGPCS -S`), worker `cargues` y `schedule:work` con el entorno aislado |
| Arranque | `C:\xampp\jornada_start.ps1` y `C:\xampp\jornada_env.sh` (solo variables de proceso; **`.env` sin cambios**) |
| Cuenta restringida de VALIDAR | `cargues_validar_jornada@127.0.0.1` con permisos **solo** sobre `lpz_saas_jornada` (la cuenta compartida `cargues_validar` quedó con sus permisos originales) |
| Navegador | Chromium headless (patchright) con el Node de VS Code; scripts en `C:\xampp\jornada_evidencia\scripts` |
| Evidencia | `C:\xampp\jornada_evidencia` (capturas, CSV de cargues, checksums, SQL de línea base y cierre) |

### F.2 Hipótesis de prueba (no son reglas aprobadas)

| Parámetro | Valor en el entorno aislado |
|---|---|
| `VENTASASESOR_COMISIONES_FECHA_INICIO` | `2026-09-01 00:00:00` |
| `VENTASASESOR_CARTERA_FECHA_CORTE` | `2026-08-31` (dev usa 2026-10-06) |
| Acuerdo QA A | 10 % umbral 3 desde 2026-09-01; 12 % umbral 3 desde 2026-10-22 21:20 |
| Acuerdo QA B | 8 % umbral 1.000 desde 2026-09-01 |
| Política por defecto | ganancia mínima $ 800/u.; costo directo «Bolsa QA» $ 100/u. |
| Estado del costo | Hombre y Mujer confirmado; Niño provisional (revisar 2026-11-30); Crop Top **sin estado** |
| Rol de prueba | «QA ASESOR + MIS COMISIONES (jornada)» (id 12109) = rol 2 + `comisiones`, `view_own`, `comprobantes`, asignado a QA Asesora B |

### F.3 Datos creados (solo en `lpz_saas_jornada`)

- Usuarios: QA Admin Jornada (12179), QA Asesora A — equivalente al rol de Karen (12180), QA Asesora B (12181), QA Bodega (12182); fichas de asesor 3 y 4. **Karen y sus datos no se tocaron** (sus pedidos no se incluyeron en ninguna liquidación QA).
- 30 pedidos #81–#110: **10 por navegador** (#81–#87, #104, #109, #110), **16 por servicio con reloj simulado de proceso** (#88–#103; #95 y #96 son de inicio de octubre) y **4 por servicio en concurrencia** (#105–#108). Clientes «QA Cliente …», «QA Hist …», «QA Conc …».
- Logística: 20 envíos, guías GI-000010…14 y QA99E0083/0089, 19 entregas, 1 devolución + recepción, 19 validaciones (1 anulada), 2 liquidaciones de transportadora (LIQ-000001/2, solo movimientos QA).
- Cartera: 14 pagos de asesoras (1 rechazado, 1 sin revisar, incluido 1 abono multipedido) + 3 cobros contra entrega del administrador.
- Comisiones: LQC-000001 (A, aprobada, saldo $ 3.520), LQC-000002 (B, pagada), 5 abonos (1 reverso), 9 incidencias, 5 ajustes; 5 cargas (20–24).
- Reembolsos Bagisto: #90, #92, #98, #99 (1 u. cada uno).

### F.4 Verificación de no contaminación

- **BD de desarrollo:** sin escrituras de la jornada. Al cierre tenía cambios frente a la línea base (pedidos 63 → 70, 3 liquidaciones de transportadora, 1 admin menos), **todos hechos por usuarios reales** en `http://127.0.0.1:8000` («karen Asesora», «Admin»); 0 usuarios y 0 clientes QA en desarrollo.
- **Almacenamiento de desarrollo:** antes de corregir el arranque del servidor (el `php -S` no recibía `LARAVEL_STORAGE_PATH`), la jornada escribió **11 archivos de sesión, entradas de caché con prefijo `jornada_` y trazas de Debugbar** en `storage/` de desarrollo, y **1 CSV de cargue** que se movió al almacenamiento aislado. Sin datos de negocio. Lista de sesiones: `C:\xampp\jornada_evidencia\sql\sesiones_en_storage_dev.txt`.
- `packages/Webkul/`: sin cambios. Código de negocio: sin cambios. Único archivo nuevo en el repositorio: este documento.

### F.5 Cómo volver al estado previo (sin borrar historia ni datos ajenos)

1. Detener los procesos del entorno aislado (servidor 8010, worker `--sleep=1`, `schedule:work`).
2. Eliminar la cuenta `cargues_validar_jornada@127.0.0.1` (no afecta la cuenta compartida).
3. Opcional: `DROP DATABASE lpz_saas_jornada` y borrar `C:\xampp\jornada_storage` (todo es copia de prueba; la historia de desarrollo no vive ahí). Conservar `C:\xampp\jornada_evidencia` como evidencia.
4. Opcional: borrar de `storage/framework/sessions` de desarrollo los 11 archivos listados en F.4 (no afecta sesiones de usuarios reales).
5. Nada que revertir en `.env`, código, roles reales ni en la BD de desarrollo.

### F.6 Qué quedó pendiente / no probado

- Pantalla de Bagisto «Ventas › Pedidos» para cancelar unidades no despachadas (solo se usó el reembolso).
- Cargue de Cartera (no existe) y sobrepagos/anticipos (no existen).
- D4 (cambio de umbral a mitad de mes) con cuentas QA.
- Concurrencia por navegador real: el servidor de pruebas es de un solo hilo; la concurrencia se probó por servicio.
- Medición con OPcache/sin Debugbar (requiere cambiar la configuración de PHP).
