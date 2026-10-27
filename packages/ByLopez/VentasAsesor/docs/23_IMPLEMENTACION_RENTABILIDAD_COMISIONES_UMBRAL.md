# 23 · Implementación R1–R4: rentabilidad, comisiones por asesor, umbral mensual, liquidaciones y abonos

**Fecha:** 2026-10-19 (cierre técnico §10 el mismo día) · **Entorno:** solo desarrollo (`lpz_saas`, `APP_ENV=local`), **sin commit** · **Responsable funcional:** Camilo Gaitán

> Implementa el plan R1–R4 del Doc. 22 §12 con las reglas aprobadas. **Nada se probó funcionalmente:** no se crearon,
> modificaron ni ejecutaron tests, simulaciones ni navegador (lo prueba Camilo). Revisión estática hecha: `php -l` de todo lo
> tocado, resolución de todas las clases por el contenedor, compilación Blade de las vistas tocadas, mapeo ruta → clave ACL,
> registro de eventos y del programador, Pint y una corrida de **solo lectura** de panel, diagnóstico y configuración contra
> el esquema real (0 escrituras). `packages/Webkul/` y `packages/ByLopez/Logistica/` sin cambios.

---

## 0. Estado real (qué está hecho y con qué grado de verificación)

| Pieza | Implementado | Revisado estáticamente | Probado funcionalmente |
|---|---|---|---|
| R1 configuración, evaluador, diagnóstico | Sí | Sí | **No** |
| R2 advertencia + bloqueo en Nueva venta (apagado) + «Vender para» | Sí | Sí (Vue sin abrir en navegador) | **No** |
| R3 líneas, umbral, habilitación A-04 | Sí | Sí | **No** |
| R3 **reevaluación automática** por hechos (§10.2) | Sí | Sí (eventos y programador registrados) | **No** |
| R4 liquidaciones, abonos, ajustes | Sí | Sí | **No** |
| R4 **incidencias + aplicaciones guardadas** (§10.3) | Sí | Sí | **No** |
| Política por referencia **+ tipo de tela** (§10.4) | Sí | Sí | **No** |
| Panel mensual sin dobles conteos (§10.5) | Sí | Sí (lectura real: 0 escrituras) | **No** |
| Bloqueo en tienda / panel nativo (D8) | **No** (solo puntos identificados, §10.6) | — | — |

## 1. Decisiones aprobadas (vigentes)

| Tema | Regla aprobada | Dónde vive |
|---|---|---|
| Porcentaje | Por asesor, con vigencias, usuario, motivo e historial; se conserva el vigente **al confirmar** (A-01, A-02) | `bylopez_va_comision_acuerdos`; la línea guarda `acuerdo_id` + `porcentaje` |
| Base | **Opción A:** comisión = r × max(0, P − C). Los costos directos **no** reducen la base; se descuentan después para la contribución. Sin IVA ni flete como mercancía | `LineasComisionService`, `EvaluadorRentabilidad` |
| Ganancia mínima | Debe quedar **después** de comisión y costos variables; por línea; **sin permiso de excepción** | `EvaluadorRentabilidad` |
| Habilitación (A-04) | Todo el pedido validado + cobro confirmado + dinero recibido por ByLopez + costo suficiente + sin incidencias. Sin proporcional por cobro parcial. `completed`, `paid` o una liquidación creada no bastan | `ElegibilidadComisionService` |
| Abonos (A-05) | Pagos completos o parciales con saldo, aplicación e historial; ninguna compensación automática de ajustes negativos | `LiquidacionesComisionService`, `AplicacionesComisionService` |
| Umbral mensual | Primeras N unidades del mes sin comisión; desde la N+1 sí; **no retroactivo**; mes en America/Bogota por el instante real de confirmación; N por asesor con vigencia; vacío ≠ 0 | `UmbralMensualService`, `bylopez_va_umbral_movimientos` |
| Valores | Ningún %, ganancia, costo, umbral ni fecha se cargó. Sin parámetros aprobados, lo que depende de ellos sigue apagado | — |

## 2. Qué se implementó (por fase)

### R1 — Costos, configuración y diagnóstico

- **Acuerdo por asesor** (% + umbral) versionado: `Services/Comisiones/AcuerdosComisionService.php` (Comisiones › % y umbral por asesor). Programar una versión **marca para reevaluar** el mes en que empieza (su umbral puede cambiar: D4).
- **Políticas de ganancia mínima por referencia (familia) + tipo de tela** (§10.4), costos directos estimados con base explícita y fuente real, y **estado del costo** por referencia: `Services/Rentabilidad/ConfiguracionRentabilidadService.php` (Rentabilidad › Configuración).
- Versionado común sin solapes ni retroactividad: `Support/Comisiones/ProgramadorDeVersiones.php` + `Models/Concerns/VersionConVigencia.php`.
- **Evaluador central** (puro, BigDecimal): `Services/Rentabilidad/EvaluadorRentabilidad.php`.
  `Comisión = r·max(0, P − C)` · `Contribución = P − C − Comisión − V − f·P` · `Precio mínimo = [G + V + (1 − r)C] ÷ (1 − r − f)`; si 1 − r − f ≤ 0 → «fuera de dominio». Con margen m: además `[V + (1 − r)C] ÷ (1 − r − f − m)`. Cumplimiento con valores **exactos**; mínimo redondeado **siempre hacia arriba** (§10.4).
- **Diagnóstico** (solo lectura): precios por grupo y base frente al mínimo, y configuración faltante: `Services/Rentabilidad/DiagnosticoRentabilidadService.php` (Rentabilidad › Precios frente al mínimo).

### R2 — Protección de la venta y atribución

- **Advertencia en el borrador** (`GET nueva-venta/{borrador}/rentabilidad`) tras cada cambio; al asesor solo le nombra la referencia; el detalle numérico solo con `rentabilidad.view`.
- **Bloqueo en el servidor** dentro de `ConfirmarVentaService::crearPedido()`, **antes** de crear el pedido (`RentabilidadVentaService::exigirEnConfirmacion()`). **Gobernado por `VENTASASESOR_RENTABILIDAD_BLOQUEO` (apagado).** Cubre **solo Nueva venta** (D8).
- **Snapshot inmutable** de la evaluación de todo pedido en su transacción: `Listeners/RegistrarRentabilidadYComision.php`. Si falla, el pedido igual se crea y queda marcado en la bandeja como *creación*: el snapshot se registra después con los parámetros **vigentes al confirmar** (§10.2).
- **«Vender para»** (`Support/Venta/VenderPara.php`): solo con `pedidos.assign`, validado en servidor.
- **Cambio de asesor posterior**: `AsignacionAsesorService` rechaza reasignar con comisión habilitada; con líneas estimadas se anulan (motivo e historial), se revierten sus unidades y se crea la del nuevo asesor (§10.4.C).

### R3 — Comisiones y umbral

- **Líneas por referencia** creadas al confirmar con fuentes congeladas (asesor, acuerdo, %, mes comercial, P, C con autoridad, base). Solo pedidos con snapshot: nada retroactivo. `Services/Comisiones/LineasComisionService.php`.
- **Libro del umbral** (venta, cancelación, no entregadas, reverso por reasignación, corrección) único por origen; cancelación y retorno de la misma unidad se restan **una vez** (§10.4.A).
- **Distribución determinista** por (confirmado_en, order_id, order_item_id) y cerrojo del mes (`bylopez_va_comision_meses` FOR UPDATE), tomado **antes** de leer (§10.2).
- **Habilitación A-04**: `Services/Comisiones/ElegibilidadComisionService.php` (§10.4.A–B).
- **Evaluación idempotente**: `Services/Comisiones/EvaluarComisionesService.php`. Disparo **automático** por hechos (§10.2) + botón **Reevaluar pendientes** + comando.
- **Bandeja, detalle y «Mis comisiones»** con aislamiento en el servicio: `Services/Comisiones/ConsultaComisionesService.php`.

### R4 — Liquidaciones, abonos y rentabilidad

- `Services/Comisiones/LiquidacionesComisionService.php` + vista `admin/comisiones/liquidaciones`: borrador → aprobada → anulada; abonos parciales; reverso; ajustes que nacen pendientes. Crear, aprobar y abonar **reevalúan antes** los pedidos implicados (§10.3).
- **Aplicaciones guardadas** (`Services/Comisiones/AplicacionesComisionService.php`) e **incidencias** (`Services/Comisiones/IncidenciasComisionService.php`) (§10.3).
- **Panel mensual**: `Services/Rentabilidad/PanelMensualService.php` (§10.5).

## 3. Preparado pero DESACTIVADO

| Función | Cómo se activa | Por qué está apagada |
|---|---|---|
| Bloqueo de ventas bajo el mínimo | `VENTASASESOR_RENTABILIDAD_BLOQUEO=true` | Faltan parámetros aprobados (D1) y revisar listas |
| Bloqueo por costos del pedido | `VENTASASESOR_RENTABILIDAD_BLOQUEAR_COSTOS_PEDIDO=true` | D7 |
| Habilitación de comisiones | `VENTASASESOR_COMISIONES_FECHA_INICIO="AAAA-MM-DD HH:MM:SS"` (hora Bogotá) | D2. **La fecha `2026-11-01` que aparecía antes en este documento era ilustrativa, no aprobada** |
| Reparto en pedido mixto que cruza el umbral | Aprobar D3 | Se previsualiza; la línea queda «política pendiente» |
| Cambio de umbral a mitad de mes | Aprobar D4 | El mes se marca y no se habilita |
| Desplazamiento del umbral sobre líneas habilitadas | Aprobar D5 | Antes de liquidar: se conserva lo congelado y queda **en revisión** (no liquidable); en liquidación: **incidencia** |
| Retener o descontar definitivamente lo afectado por una incidencia | Aprobar D6 | Solo existe «Mantener»; lo demás queda retenido |
| Devoluciones posteriores a la entrega y reembolsos | Aprobar D10 | El pedido queda con `devolucion_sin_politica` |
| Bloqueo en tienda y panel nativo | Aprobar D8 e implementar §10.6 | No hay protección global |

Lo que **sí** queda activo por defecto, porque no es un parámetro de negocio: la **reevaluación automática** (`VENTASASESOR_COMISIONES_REEVALUACION_AUTOMATICA`, por defecto `true`). Sin fecha de inicio no habilita nada; solo mantiene al día estimaciones, libro del umbral y alertas. Tras cambiar `.env`: `php artisan optimize:clear`.

## 4. Migraciones (creadas y aplicadas solo en `lpz_saas`, una por una con `--path`, sin otras pendientes)

`2026_10_19_000001` configuración · `000002` evaluaciones de rentabilidad · `000003` líneas, eventos, libro del umbral, meses ·
`000004` liquidaciones, ajustes, abonos · **`000005`** bandeja de reevaluación, incidencias, aplicaciones de abonos, tipo de tela
en políticas y en el snapshot (tablas vacías; no carga datos). Todas aditivas.

## 5. Permisos (claves en Configuración › Roles; ningún rol se modificó)

`ventas_asesor.comisiones` (abre la pantalla) · `.view_own` · `.view_all` · `.manage` (% y umbral) · `.calcular` (reevaluar y ver la bandeja) ·
`.approve` (liquidaciones, ajustes **e incidencias**) · `.pay` (abonos) · `.ajustar` · `.revertir` · `.comprobantes` ·
`ventas_asesor.rentabilidad` · `.rentabilidad.view` · `.rentabilidad.configurar` · `ventas_asesor.kpis.view_all` (panel).
Al asesor: `comisiones` + `comisiones.view_own` (+ `comprobantes` para descargar los suyos). «Vender para» usa `pedidos.assign`.
La ruta nueva `comisiones/incidencias/{id}/resolver` usa la clave existente `.approve`: **no se creó ninguna clave nueva** en este cierre.

## 6. Configuración necesaria para activar (en este orden)

1. Rentabilidad › Configuración: política **por defecto** (referencia y tela vacías) y, solo donde difiera, por referencia, por tela o por ambas; **estado del costo** de cada referencia (sin estado o vencido no habilita); costos directos reales.
2. Comisiones › % y umbral: acuerdo de cada asesor (porcentaje y umbral; vacío = sin definir).
3. Rentabilidad › Precios frente al mínimo: corregir las listas que no cumplen.
4. Fijar `VENTASASESOR_COMISIONES_FECHA_INICIO` (D2); después, si se aprueba, `VENTASASESOR_RENTABILIDAD_BLOQUEO=true`.
5. Infraestructura de la reevaluación (§10.2): con la cola actual (`sync`) no hace falta worker; **sí** el cron de Laravel para los reintentos.

## 7. Decisiones pendientes de Camilo (nada de esto quedó aprobado)

| # | Decisión pendiente | Recomendación | Ejemplo corto | Impacto mientras no se decida |
|---|---|---|---|---|
| D1 | Valores reales: ganancia mínima (G), margen opcional, escalón, costos directos, estado de cada costo, % y umbral de cada asesor | Una política por defecto y excepciones solo por referencia/tela que realmente difieran; umbral explícito por asesor (0 si no aplica) | Con G, V, r y f definidos, una prenda de costo C solo se puede vender a ≥ [G + V + (1−r)C] ÷ (1−r−f) | No se puede activar el bloqueo ni habilitar: todo queda «sin política / sin acuerdo» |
| D2 | Fecha de inicio de comisiones y trato de históricos | Primer día de un mes futuro a las 00:00 Bogotá; sin retroactividad (lo anterior no tiene snapshot y no comisiona) | Fecha F: una venta confirmada 1 minuto antes de F nunca se habilita | Ninguna comisión se habilita (`sin_fecha_inicio`) |
| D3 | Reparto de la parte comisionable de un pedido mixto que cruza el umbral | Proporcional a las unidades computables, resto mayor, desempate por ítem (ya se previsualiza) | Faltan 3 para el umbral; pedido de 4 u. de A y 4 u. de B → 5 comisionables: A 3, B 2 | Esas líneas quedan «política pendiente» y no se habilitan |
| D4 | Cambio de umbral a mitad de mes | Rige el umbral vigente al inicio del mes; el nuevo, desde el mes siguiente | Umbral 10 el día 1, 6 desde el día 15 → todo el mes con 10 | El mes queda marcado y no se habilita |
| D5 | Desplazamiento del tramo por cancelación/devolución de un pedido anterior | Antes de liquidar: recalcular y dejar evento; ya liquidada: incidencia + ajuste aprobado | Umbral 10; pedido 1 de 6 u., pedido 2 de 6 u. (2 comisionan); se cancelan 2 u. del pedido 1 → el pedido 2 debería comisionar 0 | Antes de liquidar: la línea conserva lo congelado y queda **en revisión** (no liquidable). Liquidada: incidencia que retiene lo no pagado |
| D6 | Qué hacer con lo afectado por una incidencia y con ajustes negativos tras pagar | Solo con aprobación expresa: incluir el ajuste negativo aprobado en la siguiente liquidación y decidir si lo no pagado se anula | Línea de 100 con 40 pagados pierde condiciones → ajuste propuesto −40; los 60 quedan retenidos | Hoy solo existe «Mantener»; lo demás sigue retenido y el ajuste, pendiente |
| D7 | Costos del pedido (domicilio gratis, % sobre el total): ¿bloquean o advierten? | Advertir hasta tener costos directos reales configurados | Flete asumido mayor que la holgura de las prendas → aviso | Solo advierte |
| D8 | Tienda y panel nativo: ¿bloqueo previo a crear el pedido? | Primero solo advertencia y registro; bloquear después de probar Nueva venta | Pedido de tienda bajo el mínimo hoy se crea y queda su snapshot «incumple» | No hay protección global; no presentar el bloqueo como global |
| D9 | Tasa para el precio mínimo: contractual o efectiva según el umbral | Contractual (implementado; conservador) | Asesor de 10 % aún bajo su umbral: el mínimo se calcula igual con 10 % | Mínimos algo más altos que lo estrictamente necesario |
| D10 *(nuevo)* | Devoluciones posteriores a la entrega y reembolsos de Bagisto | Definir si descuentan unidades del umbral y la comisión antes de pagar | Cliente devuelve 1 de 3 prendas ya entregadas | El pedido queda `devolucion_sin_politica`; si ya estaba liquidado, incidencia |
| D11 *(nuevo, confirmar)* | ¿Un costo **provisional** vigente basta para habilitar? | Sí (implementado); sin estado o vencido, no | Costo provisional con revisión hasta fin de mes → habilita | Si se decide que no, las líneas con costo provisional no se habilitarán |
| D12 *(nuevo, confirmar)* | ¿Un recaudo compensado contra fletes (liquidación en la que ByLopez paga o queda al día) cuenta como dinero recibido? | Sí (implementado): la deuda quedó saldada en una liquidación cerrada y conciliada | Recaudo 100, fletes 120 → ByLopez paga 20; el recaudo se considera recibido | Si se decide que no, esos pedidos no se habilitarán |

Aclaraciones: la periodicidad de liquidación (quincenal, mensual, a demanda) es **independiente** del umbral mensual: el umbral decide qué unidades comisionan dentro del mes comercial; la liquidación solo agrupa lo ya habilitado por fecha de habilitación.

## 8. Guía manual de revisión (Camilo) — no se ejecutó

**Qué necesitas para probar** (valores de prueba elegidos por ti, no de negocio): un asesor de prueba con ficha y acuerdo (p. ej. un % cualquiera y un umbral **pequeño**, como 3); política por defecto y estado de costo de una referencia; costos de catálogo en sus variantes; un rol de administración con todas las claves de §5 y un rol de asesor con `comisiones` + `view_own` (+ `comprobantes`); y, en tu `.env` de desarrollo, `VENTASASESOR_COMISIONES_FECHA_INICIO` con un instante **anterior** a tus ventas de prueba.

**Aislar y restaurar sin borrar historia:** usa un cliente y un asesor de prueba (sus pedidos quedan identificables); al terminar, vacía `VENTASASESOR_COMISIONES_FECHA_INICIO` y `optimize:clear`; programa una versión nueva del acuerdo de prueba (no se borra la anterior); anula las liquidaciones de prueba con motivo (antes revierte sus abonos); resuelve o deja documentadas las incidencias. Nada se borra: todo queda con su historial.

1. **Configuración y acceso.** Con el rol de asesor: ve solo sus comisiones, sin costo, base ni %; no ve «Vender para», acuerdos ni Rentabilidad; abrir sus URLs da 403. Con administración: Rentabilidad › Configuración permite política por referencia, por tela o ambas.
2. **Venta y diagnóstico de rentabilidad.** En Nueva venta, una venta sobre el mínimo no avisa; bajo el mínimo avisa «Precio bajo el mínimo» nombrando la referencia. Una referencia sin estado de costo dice «configuración pendiente». Cambiar grupo o «Vender para» actualiza el aviso.
3. **Umbral pequeño y cruce.** Con umbral 3: acumulado 2 y un pedido de 2 u. de una referencia → 1 completa, 1 comisiona (Comisiones › Resumen). Un pedido nuevo del mismo mes aparece sin pulsar nada (reevaluación automática tras crear el pedido).
4. **Entrega sin dinero recibido.** Valida la entrega de un pedido contra entrega con recaudo abierto: el detalle muestra «recaudo en custodia» y la línea sigue estimada. Anula la validación: vuelve «envío sin validar».
5. **Ingreso confirmado y reevaluación automática.** Liquida el recaudo en la cuenta de la transportadora (sin diferencia) o confirma en Cartera un pago directo: la línea pasa a **habilitada sin pulsar «Reevaluar»**. Con diferencia: «diferencia sin conciliar» hasta liquidar la diferencia en otra liquidación sin nueva diferencia.
6. **Liquidación y abono parcial.** Crea el borrador del asesor, apruébalo, abona una parte: saldo y «pagado/pendiente» por línea cuadran con lo abonado; doble clic no duplica; abonar más que lo pagable se rechaza.
7. **Reintento, reverso e incidencia posterior.** Revierte el abono (pide motivo; la aplicación vuelve a 0). Abona de nuevo y luego anula la validación de entrega de ese pedido: se abre una **incidencia**, lo no pagado de esa línea queda **retenido**, se propone un ajuste solo por lo pagado y las demás líneas siguen pagables. «Mantener comisión» con motivo la libera.
8. **Pedido mixto y políticas pendientes.** Un pedido con dos referencias de base distinta que cruza el umbral queda «política pendiente»; un cambio de umbral a mitad de mes marca el mes; un reembolso marca `devolucion_sin_politica`: **ninguno se habilita**.

Rutas: `admin/ventas-asesor/comisiones` (`?vista=resumen|liquidaciones|acuerdos`, detalle `?pedido=ID`) · `admin/ventas-asesor/rentabilidad` (`?vista=diagnostico|configuracion|panel`) · Nueva venta.

## 9. Riesgos y limitaciones reales

- Nada se probó funcionalmente; Nueva venta (Vue) no se abrió en navegador.
- `tests/Unit/AclYMenuConfiguracionTest.php` **fallará** en 5 pruebas (impacto exacto y actualización necesaria en §10.7). No se modificó.
- Con la cola `sync`, la reevaluación corre en la misma petición del hecho, **después** del commit: suma unos cientos de milisegundos a validar, cobrar o liquidar; un error no afecta al hecho.
- El bloqueo solo protege **Nueva venta** (D8).
- El costo es el **estándar** congelado; el costo real completo no existe aún (§10.5).
- Pedidos anteriores a esta versión no tienen snapshot ni líneas (sin retroactividad).

---

## 10. Cierre técnico de R1–R4 (2026-10-19)

### 10.1 Pendientes encontrados al contrastar con el código

1. La evaluación solo corría por botón o comando: ningún hecho la disparaba.
2. Crear/aprobar liquidaciones y abonar confiaban en el estado «habilitada» guardado.
3. La aplicación de abonos se **derivaba** (FIFO solo sobre líneas): no cuadraba con ajustes negativos o positivos y un hecho posterior podía cambiar en silencio a qué se aplicó un pago.
4. Una línea liquidada que perdía condiciones creaba un ajuste por el **total** aunque solo se hubiera pagado una parte (recuperaría dos veces), y nada impedía seguir pagándola.
5. Habilitada sin liquidar con su tramo desplazado: pasaba a estimada y en la siguiente evaluación se rehabilitaba con otro monto (aplicaba D5 sin aprobar).
6. Una unidad devuelta por la transportadora y además cancelada (Bagisto permite cancelar lo despachado no facturado) se restaba dos veces.
7. Reembolsos/devoluciones posteriores no se distinguían.
8. Una diferencia de liquidación arrastrada a otra liquidación con nueva diferencia contaba como conciliada.
9. La política era solo por familia de Bagisto; se pidió por referencia y tipo de tela.
10. Cumplimiento con valores redondeados a 4 decimales y mínimo dividido con HALF_UP: vender justo al mínimo podía «no cumplir».
11. Costo sin estado o vencido no cambiaba el resultado; venta sin asesor y sin acuerdos vigentes quedaba «sin tasa» aunque ninguna comisión pudiera nacer.
12. Panel: comisión estimada incluía la de líneas habilitadas junto a la habilitada; recaudo de transportadora solo en bruto.
13. El comprobante del asesor dependía solo de la ruta para la clave `comprobantes`.

### 10.2 Cómo se dispara ahora la reevaluación

```
hecho (en su transacción) ──► marca el pedido en bylopez_va_comision_reevaluaciones (upsert, nunca lanza)
                               │
                       commit ─┴─► ReevaluarComisionesJob (cola configurada; hoy sync = misma petición, tras el commit)
                                     └─► por pedido: EvaluarComisionesService::evaluarPedido (transacción propia)
                                           ├─ éxito: version_procesada = version
                                           ├─ error: intentos+1, espera 2,4,8…60 min (máx. 8), ultimo_error
                                           └─ si cambió el libro del umbral (o es un pedido nuevo):
                                              marca los pedidos POSTERIORES del mismo asesor y mes
cada 5 min: php artisan ventas-asesor:comisiones:evaluar --bandeja   (reintentos vencidos)
manual:     botón «Reevaluar pendientes» (todo + agotadas) · comando sin opciones · --pedido=ID
```

**Hechos conectados** (`Listeners/SolicitarReevaluacionComision.php`, reutilizando eventos existentes; sin tocar Webkul ni Logística):

| Hecho | Evento reutilizado |
|---|---|
| Validación de entrega y su anulación | `eloquent.saved` de `Logistica\Models\ValidacionEntrega` |
| Retorno de transportadora y su anulación | `eloquent.saved` de `Logistica\Models\Retorno` |
| Corrección de guía (recaudo esperado) | `eloquent.saved` de `Logistica\Models\Guia` |
| Liquidación de recaudos; liquidación de una diferencia (sigue la cadena hasta los pedidos de la liquidación de origen) | `eloquent.saved` de `Logistica\Models\MovimientoCuenta` |
| Registro, confirmación y rechazo de cobros; aplicación a pedidos | `eloquent.saved` de `Pago` y `AplicacionPago` |
| Cancelación, cambio de estado, reembolso, envío (Bagisto) | `sales.order.cancel.after`, `sales.order.update-status.after`, `sales.refund.save.after`, `sales.shipment.save.after` |
| Reasignación de asesor, tipo de operación | `eloquent.saved` de `AtribucionPedido` y `ClasificacionPedido` |
| Creación del pedido | `RegistrarRentabilidadYComision` (marca *creación*) |
| Nueva versión del acuerdo | `AcuerdosComisionService::programar` (todos los pedidos del asesor en ese mes) |

**Reverso de un cobro confirmado:** Cartera aún no tiene esa operación (`RechazarPagoService` lo dice). Cuando exista, si cambia `Pago` o `AplicacionPago` por Eloquent quedará conectada sin más.

**Garantías:** el hecho nunca depende del cálculo (la marca es un upsert protegido y el job atrapa todo); idempotencia (evaluar es idempotente, la bandeja es una fila por pedido); sin bucles (la cascada solo nace si cambia el libro y solo hacia pedidos posteriores; tope por corrida); sin trabajo global (solo pedidos marcados y su asesor‑mes); la automatización no se salta la fecha de inicio, A-04, políticas pendientes ni alertas.
**Concurrencia:** la evaluación toma el cerrojo del pedido y de cada asesor‑mes **antes** de cualquier lectura no bloqueante, para que la foto de la transacción (REPEATABLE READ) no sea anterior a otra evaluación del mismo mes.

**Infraestructura:** hoy `QUEUE_CONNECTION=sync` → no hace falta worker. Si se usa una cola real: `VENTASASESOR_COMISIONES_COLA_CONEXION=<conexión>` y un worker `php artisan queue:work <conexión> --queue=default`. En ambos casos, para los reintentos: cron `* * * * * php artisan schedule:run` (si no hay cron, el botón y el comando cubren). La pantalla muestra cuántos pedidos esperan y cuántos agotaron reintentos.

### 10.3 Aprobaciones, abonos e incidencias

- **Crear borrador, aprobar y abonar reevalúan primero** los pedidos implicados; además, dentro de la transacción, solo entran (o se aprueban) pedidos que cumplen A-04 en ese momento. Si la verificación falla, la operación no sigue.
- **Antes de liquidar** se aplican solo reglas aprobadas: pierde condiciones → vuelve a estimada; lo ambiguo (desplazamiento, mixto, cambio de umbral, devolución) queda **en revisión** y no es liquidable.
- **En liquidación o pagada:** el importe y el historial se conservan; se abre una **incidencia** (`bylopez_va_comision_incidencias`). Mientras está abierta, **lo no pagado de esa línea queda retenido**: el abono se limita a lo *pagable* (saldo − retenido) y se aplica solo a las demás líneas. Si ya se pagó una parte y la causa es una pérdida de condiciones, se **propone** un ajuste negativo **solo por lo pagado**; nunca se aprueba ni descuenta solo, y no se puede aprobar si lo pagado cambió después (p. ej. un reverso).
- **Liquidación con otras líneas válidas:** se siguen pagando hasta su importe; la afectada queda con su saldo retenido y visible. Se resuelve con **«Mantener comisión»** (permiso `approve`, motivo; rechaza el ajuste propuesto y libera la línea) o, cuando se apruebe D6, con la regla que se decida. En un **borrador**, lo correcto es anular y recrear (al anular se cierran sus incidencias).
- **Aplicaciones guardadas** (`bylopez_va_comision_aplicaciones`): abono FIFO (líneas por habilitación, luego ajustes positivos) saltando lo retenido; al aprobar, compensación **única y registrada** de los ajustes negativos que quien liquidó eligió; reverso = espejo exacto del abono. Se verifica Σ aplicaciones = monto y que ningún destino supere su importe.
- **Doble clic / reintento:** clave de idempotencia única en liquidaciones y abonos; liquidación bloqueada FOR UPDATE en aprobar, abonar, revertir y anular; un reverso por abono (UNIQUE).

### 10.4 Riesgos del Doc. 23 revisados

**A. Cantidades.** Envíos contados por ítem padre (Bagisto guarda ahí el envío de la variante); retornos de variantes llevados al padre. Cancelación y retorno de la misma unidad se restan **una vez**. Se distinguen: canceladas (dejan de ser exigibles), no entregadas (restan en el umbral; el envío se valida igual) y devueltas después de entregar / reembolsadas (`devolucion_sin_politica`, D10). «Pedido completo» = toda unidad no cancelada despachada y cada envío validado; nada devuelto después se aprueba sin política.

**B. Dinero recibido.** Lo que acredita fondos: contra entrega → movimiento de recaudo de **cada guía** en una liquidación **cerrada** y **conciliada** (sin diferencia, o diferencia liquidada en otra que a su vez está conciliada; se sigue la cadena); transferencia directa o recogida → pago **confirmado** en Cartera; guía con recaudo que no liquida por cuenta corriente o cobro de domiciliario sin recaudo → bloqueo explícito. «Liquidado» no se toma por el nombre. Compensación contra fletes = recibido (D12, confirmar).

**C. Historia.** `confirmado_en` = `orders.created_at` (instante de la confirmación), también si las líneas se crean después. Porcentaje y acuerdo se toman con esa misma fecha. En una reasignación de pedido estimado: acuerdo **del nuevo asesor vigente en la fecha de confirmación** (no su tasa actual) y el **mismo mes comercial**; sin acuerdo entonces → sin comisión (`sin_acuerdo_al_confirmar`). Alertas de D3–D6 intactas.

**D. Configuración.** La familia de Bagisto (p. ej. «Camiseta Hombre DURZ») **no** equivale a referencia + tela: el atributo `tipo_tela` existe aparte (13 opciones) y una familia puede mezclar telas. Se añadió `tipo_tela_id` a la política (gana la más específica: referencia+tela › referencia › tela › defecto), sin políticas por SKU. Venta de empresa: tasa 0; venta sin asesor y **sin acuerdos vigentes**: tasa 0 exacta (ninguna comisión puede nacer). Fórmula por tramos: con P ≤ C la contribución es ≤ 0 < G, así que el mínimo siempre cae en P ≥ C y la fórmula es válida; cumplimiento con valores exactos y mínimo redondeado hacia arriba. Estado del costo con significado propio: sin costo / sin estado / vencido → incompleta; provisional → se evalúa y advierte.

### 10.5 Costos y panel mensual

- **Costo vendido = costo estándar** de Bagisto congelado al vender (COM-01); se rotula así. No incluye conciliación con el costo real de producción: tela, corte y confección por orden existen en Corte y Confección pero no se suman aquí (se contarían dos veces con el estándar). Transporte productivo, empaque consumido y gastos generales no tienen registro.
- Falta de costo ≠ 0: la línea sale de costo y contribución y se cuenta aparte («parcial»).
- Estimado y real separados: costos directos estimados (snapshot) frente a reales (servicios y costo de devolución en cuentas de transportadora).
- Comisión del mes **una vez por línea**: habilitada = monto congelado; resto = estimada. Pagar al asesor no vuelve a reconocerla.
- Recaudo sin duplicar: contra entrega por la liquidación (bruto, fletes/servicios, ajustes, diferencia, neto pagado o compensado); pagos directos aparte; cobros en manos de terceros se muestran sin sumar.
- **Para medir el resultado mensual real falta:** conciliar costo real de producción con el estándar, registrar transporte productivo y empaque, gastos generales, costos reales de pasarela y la política de devoluciones. No se construyó contabilidad ni se repartieron gastos.

### 10.6 Tienda y panel nativo (D8, sin implementar)

Puntos posibles sin tocar Webkul: (1) listener propio de **`checkout.order.save.before`** (ya lo usa `CercoCreacionPedido`): corre en el servidor antes de crear el pedido en tienda y panel nativo; lanzar ahí impide la venta, pero hay que traducir el error para el cliente; (2) **`checkout.cart.collect.totals.after`** para avisar en el carrito sin bloquear. El snapshot posterior (`checkout.order.save.after`) **no** impide la venta y no se presenta como protección.

### 10.7 Impacto exacto en `tests/Unit/AclYMenuConfiguracionTest.php` (no modificado)

Leídas sus expectativas, con el estado actual (45 claves del módulo; 2 entradas de menú nuevas; 1 hijo raíz nuevo; ninguna ruta sin ACL; toda clave apunta a una GET sin parámetros):

| Prueba | Resultado esperado | Por qué | Actualización necesaria (sin debilitar el control) |
|---|---|---|---|
| «la ACL del paquete se fusiona…» | Falla | `toHaveCount(38)`; hay 45 | 38 → 45 |
| «el árbol ACL del módulo es exactamente…» | Falla | faltan 7 claves en la lista | añadir `comisiones.calcular`, `.ajustar`, `.revertir`, `.comprobantes`, `rentabilidad`, `rentabilidad.view`, `rentabilidad.configurar` |
| «el menú del paquete tiene un nodo raíz…» | Falla | la lista exacta no incluye las entradas nuevas | insertar `ventas_asesor.comisiones` y `ventas_asesor.rentabilidad` entre `ventas_asesor.costos` y `ventas_asesor.pedidos.create` (el resto del bucle pasa: rutas GET sin parámetros y nombres traducidos) |
| «el árbol ACL se construye completo…» | Falla | los hijos de la raíz tienen `ventas_asesor.rentabilidad` al final | añadirlo al final de la lista |
| «03G-C: la API de nueva venta rápida…» | Falla solo su última expectativa | `toHaveCount(38)` | 38 → 45 (sus rutas siguen con `pedidos.create`) |
| Las otras 6 | Deberían pasar | ruta de inicio, Pedidos, middleware `admin` y mapeo de toda ruta, claves → GET sin parámetros, «Ver pedido», claves sin guiones | — |

### 10.8 Archivos de este cierre

Nuevos: `Database/Migrations/2026_10_19_000005_…`, `Models/{ComisionReevaluacion,ComisionIncidencia,ComisionAplicacion}.php`, `Services/Comisiones/{ReevaluacionComisionesService,AplicacionesComisionService,IncidenciasComisionService}.php`, `Jobs/ReevaluarComisionesJob.php`, `Listeners/SolicitarReevaluacionComision.php`.
Modificados: `EvaluarComisionesService`, `ElegibilidadComisionService`, `LineasComisionService`, `LiquidacionesComisionService`, `ConsultaComisionesService`, `AcuerdosComisionService`, `EvaluadorRentabilidad`, `RentabilidadVentaService`, `ConfiguracionRentabilidadService`, `DiagnosticoRentabilidadService`, `PanelMensualService`, `RegistrarRentabilidadYComision`, `EvaluarComisionesCommand`, `ComisionesController`, `RentabilidadController`, modelos `RentabilidadPolitica` y `RentabilidadEvaluacionLinea`, `routes.php`, `Config/{acl,ventasasesor}.php`, `Providers/{EventServiceProvider,VentasAsesorServiceProvider}.php`, vistas `comisiones/{encabezado,liquidaciones}` y `rentabilidad/{configuracion,panel}`, `lang/{es,en}/comisiones.php`.
