# 08 — Cartera de cortadores y satélites

Estado: **IMPLEMENTADA EN DESARROLLO Y PRUEBAS (2026-11-01). Sin commit, push ni despliegue. Sin saldos reales cargados.** Complementa el doc 05 (tarifas) y el doc 06 (reasignación).
Interfaz aprobada: mockups del 31-oct-2026 (administrador, Mi cuenta del satélite, Mi cuenta del cortador, cuenta de una orden cerrada). Lectura de comprobantes: `docs/LECTURA_COMPROBANTES_PAGOS.md`.

## 1. Reglas aprobadas (no se reabren)

- Tarifa por referencia (tela + familia) conservada por orden; satélite paga **solo buenas** aceptadas por bodega; cortador paga **todo lo recibido** (incluidas defectuosas).
- Trabajo = **reconocido** (recibido por bodega) + **pendiente de recibir** (estimado a la tarifa pactada). Lo solo asignado no cuenta. Al recibirse una entrega, sus unidades pasan de estimadas a reconocidas **una sola vez** (ambas salen del mismo reporte).
- **Total abonado por trabajo** = dinero real entregado por trabajo (incluye lo que quedó como anticipo). Aplicar un anticipo o descontar un préstamo **no** lo aumenta.
- Préstamo aparte del abono; solo baja con devolución real o descuento acordado en un corte.
- Estado productivo y estado de pago son independientes; «Cerrada y pagada» solo cuando ambos están completos.
- La reasignación conserva trabajo y pagos de cada titular (cada taller tiene su orden y su tarifa).
- Cada cortador o satélite consulta **solo su cuenta**.

## 2. Qué se reutilizó y qué se creó

Reutilizado: `OrdenTarifaService` (tarifa por orden), retornos de confección y recepción de corte (fuente del trabajo), reasignaciones (órdenes independientes), `cuenta_eventos` (bitácora append-only), patrón pago + aplicación de VentasAsesor, `Dinero` (nuevo, ver abajo), estilos Bagisto y el parcial de estilos del paquete.
Creado (migración **aditiva** `2026_11_01_000001`): `cartera_config`, `cartera_movimientos`, `cartera_operaciones`, `cartera_soportes`, `cartera_aplicaciones`, `cartera_liquidaciones`. No modifica ninguna tabla existente.

## 3. Modelo y derivados

El dinero vive en **un solo registro** (movimiento: abono, préstamo, devolución, saldo inicial de trabajo / anticipo / préstamo). Nada se edita ni se borra: se **reversa** con motivo, fecha y responsable (el original se conserva).
Saldos y estados de pago son **derivados** (`CarteraCuentaService`), nunca almacenados. Por orden: `R` reconocido, `P` pendiente de recibir, `A` pagos + descuentos aplicados.

- Saldo por pagar de lo recibido = `max(R − A, 0)`
- Anticipo ligado a pendiente = `min(max(A − R, 0), P)` (se pagó antes de recibir; **sigue «pendiente de recibir»**)
- Crédito de la orden = `max(A − R − P, 0)` (bodega aceptó menos de lo estimado; se muestra en su orden y **no se traslada solo** a otra)
- Faltaría por pagar (recibido) = deuda previa pendiente + Σ saldo recibido − anticipo disponible − Σ crédito
- Faltaría por pagar (estimado) = lo anterior + Σ (P − anticipo ligado a pendiente). Negativo = **saldo a favor de ByLopez**.

Ejemplo de control (Jairo, $ 1.450): recibido $ 870.000, pendiente $ 145.000, estimado $ 1.015.000; abono $ 70.000 → por pagar $ 800.000 / $ 945.000; con préstamo de $ 1.500.000 y descuento de $ 500.000 en el corte → $ 300.000 / $ 445.000 y préstamo $ 1.000.000, total abonado $ 70.000;
abono antes de recibir ($ 70.000 + $ 145.000 aplicados a OF-B): OF-A mantiene $ 800.000, OF-B queda cubierta con 100 prendas pendientes y el total estimado restante es $ 800.000; caso aparte (trabajo $ 2.000.000, abonos $ 2.200.000) → $ 200.000 a favor de ByLopez.
Todos están en `tests/Feature/CarteraCuentaTest.php`.

Importes: cadenas decimales con bcmath (`Support/Dinero`), nunca flotantes; formatos colombianos (`$ 1.234.567,89`, `1.500.000`) en lectura y presentación.

## 4. Abonos, anticipos y consolidados

- Un abono puede cubrir varias órdenes de la misma persona; lo no aplicado queda como **anticipo disponible**. Aplicarlo después crea otra aplicación **del mismo movimiento** ($ 0 de dinero nuevo) y conserva el vínculo.
- Aplicación automática = primero deuda previa, luego lo **ya recibido** (la más antigua primero). **Nunca** adelanta a lo pendiente sin que se elija explícitamente.
- Consolidado: **solo total** (valor + n.º de transferencias) o **con detalle** por operación (total = suma exacta de las aceptadas). Ver doc de comprobantes.

## 5. Préstamos y corte de cuentas

- Préstamo: fecha, valor, cada devolución o descuento y saldo restante; ambos historiales muestran fecha, corte y órdenes afectadas.
- Corte de cuentas (`LIQ-####`): muestra qué se reconoce, cuánto se abonó, cuánto se descuenta y cuánto **queda por pagar**. **Confirmarlo no registra dinero nuevo ni una transferencia**; congela las cifras (inmutable; se corrige con reverso).
- **Descuento**: parte en **cero**; solo un monto acordado expresamente; tope = **menor** entre préstamo pendiente y **saldo real recibido sin pagar** (nunca se descuenta sobre trabajo pendiente de recibir). Baja el préstamo y el saldo de trabajo en el mismo monto.
- Se puede cortar con órdenes abiertas. **Sin tarifa nunca vale cero** (D7, propuesta): esas referencias quedan fuera del corte, se listan y el corte se marca *Incompleto*.

## 6. Arranque (D2 — fecha PENDIENTE de definir por Camilo)

El mecanismo está listo; **no se inventó ninguna fecha**. Sin fecha de arranque la cartera «no ha iniciado»: no calcula cuentas ni registra movimientos.
- Todo lo anterior a la fecha entra por persona como **saldo inicial** (deuda previa por trabajo, anticipo previo, préstamos anteriores), cargado **una vez**; no simula transferencias nuevas.
- Movimientos con fecha anterior al arranque se rechazan («regístralo como saldo inicial»).
- **Una orden anterior al arranque no se excluye por serlo**: el trabajo se reconoce **por evento de recepción**. Recepciones de bodega anteriores a la fecha las cubre el saldo inicial; las posteriores sí cuentan, aunque la orden sea anterior; lo pendiente de recibir siempre cuenta. Probado con una orden con entregas antes y después del arranque.
- La fecha no se puede cambiar una vez hay movimientos. Quién carga los saldos iniciales: llave `cartera.arranque` (a definir el responsable).

## 7. Permisos y aislamiento (denegar por defecto)

Llaves nuevas (`Config/acl.php`): `corte_confeccion.cartera` (ver), `.registrar`, `.prestamos`, `.corte`, `.reversar`, `.arranque`, y `corte_confeccion.mi_cuenta` (prestador, solo consulta). Se asignan en **Configuración → Roles**; el administrador (`all`) las tiene.
`Support/CarteraGate`: la autorización se decide por el **vínculo del usuario con el prestador** (`usuario_id` del taller/cortador) y por permiso concreto en servidor, **no por el nombre del rol**:
- un usuario vinculado a un prestador solo puede **ver su cuenta** (`mi-cuenta`); nunca registra, reversa, descuenta, define arranque, lee comprobantes ni abre soportes, aunque su rol sea `all`, esté renombrado o tenga todas las llaves;
- un rol llamado «TALLER CONFECCION»/«CORTADOR» sin vínculo tampoco tiene acceso administrativo;
- la ruta de «Mi cuenta» resuelve el prestador por el vínculo; un id enviado por el cliente se ignora.
Verificado: la edición de ficha ya estaba bloqueada por rol en `UpdateCortadorRequest`/`UpdateTallerRequest`. Nota de riesgo: el módulo solo **vincula** usuarios con rol «Taller Confeccion»/«Cortador», pero un rol puede renombrarse después; el vínculo queda y la cartera lo respeta.

## 8. Pantallas

- `Cartera` (menú): listado con resultado por persona y definición del arranque.
- `Cuenta` por persona (`cartera/{taller|cortador}/{id}`) y `Mi cuenta` (`cartera/mi-cuenta`): cifra principal que al tocarla muestra la cuenta, bloques Trabajo / Abonos / Préstamos, órdenes con estado de producción y de pago (una cerrada con deuda sigue visible), historial con filtros y cortes. Botones **Registrar movimiento** y **Hacer corte de cuentas** solo para quien puede.
- Ficha del taller/cortador: resumen de cuenta con enlace; órdenes de confección y de corte: sección **«Cuenta de esta orden»** (recepción de origen, referencias con tarifa, pagos y descuentos aplicados, saldo, «Cerrada y pagada» con fecha de liquidación).
- Móvil: modales de pantalla completa, campos de 16 px (sin zoom de Safari), operaciones del consolidado como tarjetas apiladas, eventos delegados en `document` (Bagisto monta Vue después y reemplaza nodos; ya había ocurrido con la reasignación).

## 9. Lo que NO está hecho (honesto)

- **D2** fecha de arranque y responsable de saldos iniciales (decisión de Camilo). No se cargó ningún saldo real.
- **D7** (liquidar solo referencias con tarifa conocida): implementada como la propuesta, **marcada como propuesta** hasta confirmación.
- **D8** («Listas para recoger» / «Enviadas a bodega»): **no implementada**; es propuesta pendiente de confirmación y tocaría el flujo de reporte del taller. Hoy el sistema dice **«Pendiente de recibir»**; no sabe dónde están físicamente las prendas.
- **N1 «Piezas que no se pueden confeccionar»** (regla aprobada): no implementada en esta entrega (vive en la orden de confección y toca producción/bodega; se pidió no duplicar captura de producción). La cartera ya la tratará como cualquier cantidad no recibida (no genera pago).
- **Lectura asistida de comprobantes**: código, adaptador y pruebas con respuestas simuladas; **desactivada** (sin credenciales ni proveedor aprobado). No se midió exactitud con comprobantes reales.
- Pagos en Ventas Asesor: solo documentado (`docs/LECTURA_COMPROBANTES_PAGOS.md` §9).
- Si bodega acepta menos de lo estimado en una orden con anticipo ligado, el crédito queda **en esa orden** y visible. Mover esa aplicación a otra orden exige anularla (el servicio `anularAplicacion` y su ruta existen y se verifican por permiso) y volver a aplicar; **la pantalla todavía no tiene botón para anular una aplicación** (hoy se corrige reversando el abono con motivo y registrándolo de nuevo).
- Pruebas en iPhone físico y rendimiento con volumen: no medidos.

## 10. Pruebas

`CarteraCuentaTest` (cifras del plan, arranque, corte, reversos, idempotencia), `CarteraComprobantesTest` (lectura y duplicados), `CarteraHttpTest` (permisos, aislamiento, soportes privados, pantallas), `tests/Concurrencia` RCC-7..9 (procesos reales), recorrido visual `tests/Demo/visual_cartera.cjs` (Chromium 1440/390 y WebKit iPhone). Datos de demostración: `tests/Demo/preparar_cartera_demo.php` (base demo aislada).

## 11. Resultados (2026-11-01)

- Suite del módulo (`CorteConfeccion Unit/Feature Test`): **497 pasan, 2.880 aserciones, 0 fallos** (antes 458; +39 de cartera). Dos ajustes legítimos a pruebas existentes: `MenuVisibilidadPorRolTest` (el árbol de menú pasó de 8 a 10 entradas: «Cartera» y «Mi cuenta») y corrección de la vista «Cuenta de esta orden» cuando la cartera no ha iniciado (detectado por la regresión).
- Concurrencia con procesos reales (`tests/Concurrencia`): **9 pasan** (RCC-7: misma transferencia con llaves distintas → una sola; RCC-8: doble clic → un movimiento; RCC-9: dos abonos al mismo saldo → solo cabe uno). Cuidado: la base `lpz_saas_testing` es compartida; otra sesión corriendo `pest` a la vez produjo bloqueos y dejó residuos (limpiados) — no ejecutar en paralelo.
- Visual (demo aislada): Chromium 1440 y 390 y WebKit (perfil iPhone 15 Pro Max), 20/20 pasos cada uno; evidencia en `evidencia_2026-10-29/cartera_2026-11-01/`. No es Safari físico.
- Pint pasa. `packages/Webkul/`, `vendor/`, `bootstrap/`, `config/` sin cambios.
