# 22 · Precio mínimo, rentabilidad y comisiones: reglas para proteger la ganancia

**Fecha:** 2026-10-15 · **Tipo:** auditoría y diseño, **sin implementación** · **Responsable funcional:** Camilo Gaitán

> **Actualización 2026-10-19:** Camilo eligió la **opción A** para la base (P3 = P − C; los costos directos se descuentan después,
> en la contribución), la ganancia mínima **después** de comisión y costos variables (P1 = B) y aprobó un **umbral mensual de
> unidades sin comisión por asesor**. R1–R4 implementados en desarrollo: `23_IMPLEMENTACION_RENTABILIDAD_COMISIONES_UMBRAL.md`.
>
> Amplía los Docs. **19** (costos) y **21** (flujo de comisiones). No repite sus hallazgos: los actualiza contra el código
> de hoy y agrega lo nuevo: **precio mínimo con ganancia mínima**, definición de «ganancia», fuentes reales de costo y el
> efecto de Cartera y de las **cuentas de transportadora** (ya aplicadas en desarrollo) sobre las comisiones.
>
> **Marcas de evidencia:** **[C]** visto en el código · **[D]** dato consultado en `lpz_saas` (desarrollo, 2026-10-15, solo
> `SELECT` agregados, sin datos personales) · **[A]** regla **aprobada** por Camilo · **[P]** propuesta técnica, **no
> vinculante** · **[?]** pendiente de decisión. No se ejecutaron tests, simulaciones ni navegador; no se cambió código ni datos.
>
> **Los valores $5.000 de costo, $1.500 de ganancia mínima y 16 % de comisión son SOLO EJEMPLOS** para explicar las reglas.
> No son parámetros aprobados ni deben cargarse.

---

## 0. Decisiones confirmadas por Camilo (2026-10-15) y su efecto sobre lo documentado

| Id | Decisión [A] | Efecto sobre documentos anteriores |
|---|---|---|
| A-01 | Porcentaje de comisión **configurable por asesor**, con vigencias e historial; ningún valor quemado (ni 20 % ni 16 %) | Reemplaza COM-D-06 «regla global» y COM-D-03 «20 %». El 20 % de la regla #1 queda como dato de desarrollo, no como política |
| A-02 | Se conserva el porcentaje **vigente al confirmar la venta** | Resuelve P4 del Doc. 21 (fecha de la venta) |
| A-03 | Mostrar el cálculo por camiseta / referencia y el total del pedido | Requisito de las vistas (§9) |
| A-04 | Habilitar cuando **todo el pedido** esté validado, el cobro confirmado y el dinero **efectivamente recibido por ByLopez** | Resuelve P1 del Doc. 21 (opción A) y **sustituye** la recomendación P3 «por envío»: la habilitación es por pedido completo |
| A-05 | Permitir **abonos** de comisiones y **saldo pendiente** al asesor | Sustituye P11 «un pago por liquidación» |
| A-06 | **No** permitir ventas con precios incorrectos o sin la ganancia mínima definida | Nueva regla de precio mínimo (este documento) |
| A-07 | $5.000 / $1.500 son ejemplos, no parámetros | — |

Siguen **documentadas el 2026-10-08 y pendientes de reconfirmar** (Doc. 21 P2): base = precio neto − costo (sin IVA ni envío),
`max(0, …)` por unidad sin compensar, sin comisión proporcional por cobro parcial, muestras/reposiciones/garantías sin
comisión, el asesor no ve costos, inicio desde la activación oficial, liquidación quincenal.

---

## 1. Qué significa «ganancia» (vocabulario único)

| Término | Fórmula por unidad | ¿Se conoce al vender? | Fuente hoy |
|---|---|---|---|
| **Costo del producto** (producción o adquisición) | Tela + corte + confección + transporte productivo + merma/defectos repartidos | Solo como **costo estándar** fijado por la empresa (`cost` de Bagisto) | `cost` [C]; costo productivo real solo parcial (tela) [C] |
| **Costo de vender y entregar** | Empaque + flete asumido + cargo de pasarela/recaudo + comisión del asesor | Parte sí (comisión, empaque estándar); parte solo **estimado** (flete real, recaudo 99 Envíos) | Flete real en cuentas de transportadora y guías [C]; empaque y pasarela: **no existen** |
| **Utilidad bruta** (margen comercial) | Precio neto sin IVA − costo del producto | Sí, con el costo estándar | COM-02 (`UtilidadVentaService`) la calcula con el costo congelado [C] |
| **Contribución** | Utilidad bruta − costos variables de vender y entregar − comisión | **Estimada** al vender; **real** después | No existe |
| **Utilidad operativa / neta del período** | Σ contribuciones − gastos generales (arriendo, nómina, servicios, plataformas) − devoluciones y pérdidas | **Nunca** al vender: solo al cerrar el período | No existe (radar del Doc. 19 §7) |
| **Caja** | Dinero que entró − dinero que salió | Solo con hechos de dinero | Cartera (entradas del cliente) + cuentas de transportadora (custodia); egresos: **no existen** |

**«Precio − costo del producto» se llama utilidad bruta o margen comercial, nunca «margen neto»:** le faltan los gastos.

### 1.1 Qué puede significar «me deben quedar mínimo $1.500 por camiseta»

| Opción | Significado | Ventaja | Riesgo |
|---|---|---|---|
| **A. Antes de comisión y gastos de venta** | Precio − costo ≥ 1.500 | Simple; igual para todos los asesores | La comisión y los gastos se comen parte: con 16 % sobre utilidad quedan 1.260 |
| **B. Después de comisión y costos variables** | Precio − costo − costos variables − comisión ≥ 1.500 | Protege lo que de verdad le queda a ByLopez en cada venta; todo es conocible o estimable al vender | El mínimo cambia por asesor (porcentajes distintos) y por costos variables estimados |
| **C. Después de asignar gastos generales** | B − cuota de arriendo, nómina, etc. por unidad | Acerca a la utilidad neta | La cuota depende del volumen del período (si se vende menos, la cuota sube): **no** se puede garantizar al vender, y castiga precios por un problema de volumen |

**[P] Recomendación:** **B como mínimo obligatorio** y C solo como **análisis** del período (meta, no bloqueo). La utilidad neta
real no se puede garantizar con una estimación unitaria: se compara después (§3.7).

---

## 2. Costos reales: dónde están y qué incluyen

| Concepto | Fuente actual | Unidad | Quién registra | Cuándo existe | ¿Incluido en otro costo? |
|---|---|---|---|---|---|
| **Costo estándar del producto** | Atributo `cost` de Bagisto (tipo `price`, sin canal ni idioma), congelado al vender en `bylopez_va_costos_venta` [C] | Unidad (variante) | Administración (Control de costos, actualización masiva con bitácora) [C] | Al vender (snapshot) | **Es el paquete**: por definición debe contener tela + corte + confección + transporte productivo + merma |
| Tela | `bylopez_cc_rollos.valor_kilo`; costo por pieza parcial (Doc. 19 §3.1) [C] | Kilo → pieza | Compras de tela | Al recibir el corte | Dentro del costo estándar |
| Corte y confección | **Tarifas por referencia y tela** de cortador y taller con vigencias; cada orden conserva su tarifa (`bylopez_cc_orden_tarifas`) [C]. 11 tarifas activas + 2 generales antiguas sin efecto [D] | Prenda | Administración | Al crear la orden; el **pago** al prestador aún no existe (Fase 3 de CorteConfeccion) | Dentro del costo estándar. **Pagar al taller ≠ reconocer costo**: el costo se reconoce por prenda producida, el pago es una obligación aparte |
| Transporte de tela y prendas (talleres) | **No existe** | Traslado | — | — | Debe ir dentro del costo estándar, **repartido** |
| Merma, defectos, reprocesos | Merma en kilos de la orden de corte; defectuosas en recepciones y retornos [C] | Kilo / prenda | Bodega | Al recibir | Merma de tela: ya dentro del costo de tela por pieza (Doc. 19 §3.1). Defectuosas del taller: **sin política** (N1, pausada) |
| Bolsas, papel, etiquetas, empaques | **No existe** | Unidad de insumo | — | — | No |
| Flete de entrega asumido por ByLopez | `bylopez_lg_guias.valor_flete` + `flete_pagador` (snapshot al despachar); movimiento `servicio` en la cuenta de la transportadora [C] | Envío | Bodega al despachar | **Después** de vender (al despachar/entregar) | No. Por envío, no por unidad |
| Tarifa estimada de domicilio local | `bylopez_lg_tarifas` por domiciliario y zona [C] | Envío | Administración | **Al vender** (lo muestra Nueva venta) | Es la estimación del flete local |
| Cargo de recaudo / transporte nacional (99 Envíos) | Archivo de 99 Envíos (`valor_servicio` ≈ 7,3 % del valor recaudado, incluye flete + seguro + contrapago) importado por Cargues [C][D] | Guía | Cargues (en desarrollo) | Días después de entregar | No |
| Cargos de pasarela / datáfono | **No existe** (los medios de cobro no tienen tarifa) [C] | % del cobro | — | — | No |
| Comisión del asesor | No existe causada; solo diagnóstico [C] | Unidad | Sistema | Estimada al vender; habilitada después | No |
| Otros costos directos | No registrados | — | — | — | — |
| Arriendo, nómina, servicios, plataformas | **No existe** módulo de gastos | Período | — | Al cierre del período | No |

**Datos de apoyo [D]:** 306 de 307 productos simples con `cost` > 0; costo **uniforme por familia** (4.500 · 4.900 · 5.300 ·
5.500), salvo una familia con rango 3.900–4.900. Ningún precio de lista ni de grupo está por debajo del costo.

### 2.1 ¿Son suficientes `cost` y su copia histórica?

- **Fiables como «costo estándar fijado por la empresa»** (D-COST-02): se congelan al vender y no se reescriben [C].
- **No representan el costo completo:** no hay evidencia de que incluyan corte, confección, transporte ni merma; el costo
  productivo real hoy solo tiene la tela (≈ 881 por pieza frente a 5.500 de costo estándar, Doc. 19). **No se debe asumir**
  que `cost` = costo real.
- **Falta saber su estado:** no tiene fecha, vigencia ni estado. **[P]** Estados por referencia:

| Estado | Definición | Efecto en la venta |
|---|---|---|
| **Confirmado** | Aprobado por Administración, dentro de su vigencia | Se vende normal |
| **Provisional** | Valor temporal aprobado mientras se cierra el costo real | Se vende; el pedido queda marcado «costo provisional» para revisión |
| **Vencido** | Pasó la fecha de revisión definida | **[?]** advertir o bloquear (P7) |
| **Desconocido** | Sin valor | **Bloquea** la venta normal (no se puede saber si cumple el mínimo) |

### 2.2 Reparto de costos compartidos [P]

| Costo | Unidad de reparto | Regla | Evitar |
|---|---|---|---|
| Transporte de tela a corte / prendas a talleres | **Lote** (orden de corte / confección) | Costo del traslado ÷ prendas buenas recibidas del lote; si un viaje lleva varios lotes, reparto por prendas (o por kilos si es tela) | Cargar el viaje completo a cada orden |
| Merma y defectos | Lote | Ya incluida al dividir el costo del lote entre prendas buenas | Restarla dos veces |
| Bolsas, etiquetas, empaque | **Consumo estándar por unidad** (p. ej. 1 bolsa por prenda) × costo promedio de compra | La compra va a un inventario de insumos; el costo se reconoce al consumir | Tratar la compra como gasto del día |
| Flete de entrega | **Pedido** | Al pedido completo; si se quiere por unidad, proporcional al valor de cada línea | Cargarlo entero a cada unidad |
| Cargo de recaudo / pasarela | % del valor cobrado | Proporcional al valor de la línea | Aplicarlo cuando el medio no lo tiene |
| Gastos generales | **Período** | Solo en el análisis del período | Ponerlos en el precio mínimo obligatorio |

---

## 3. Precio mínimo: políticas y fórmulas

### 3.1 Margen sobre venta ≠ incremento sobre costo

- **Margen sobre venta** = (precio − costo) ÷ precio. Ejemplo: 6.500 con costo 5.000 → 1.500 ÷ 6.500 = **23,1 %**.
- **Incremento sobre costo (markup)** = (precio − costo) ÷ costo → 1.500 ÷ 5.000 = **30 %**.
- Confundirlos cambia el precio: «30 % de margen» exige 5.000 ÷ 0,70 = **7.143**, no 6.500.

### 3.2 Políticas comparadas

| Política | Regla | Bueno para | Problema |
|---|---|---|---|
| Ganancia mínima en **pesos** por unidad | Contribución ≥ G | Prendas baratas (asegura pesos reales) | En prendas caras el % queda muy bajo (costo 20.000 + 1.500 = 7 %) |
| Margen mínimo **porcentual** | Contribución ≥ m × precio | Prendas caras | En prendas baratas deja pocos pesos |
| **Ambas** | Contribución ≥ max(G, m × precio) | Protege los dos extremos | Dos parámetros que mantener |
| Mínimo obligatorio + **objetivo** | Mínimo bloquea; objetivo solo advierte o se usa para fijar listas | Separa protección de estrategia comercial | Hay que explicar las dos cifras |

**[P] Recomendación:** ganancia mínima **en pesos** por unidad como regla obligatoria (opción B), con un % mínimo opcional
para referencias caras, y un **margen objetivo** informativo para construir las listas de precios.

### 3.3 Parametrización [P]

- Por **referencia** (familia Bagisto) **+ tipo de tela**, con un valor por defecto global; con vigencias e historial
  (mismo patrón que las tarifas de taller del Doc. CorteConfeccion 05). **Sin** configuración por SKU: el costo ya es
  uniforme por familia [D].
- La política guarda: ganancia mínima por unidad, % mínimo opcional, costos variables estimados por unidad (empaque) y por
  pedido (flete según método), tasa de cargo porcentual (si el medio lo tiene) y la tasa de comisión a usar en el cálculo (§3.5).
- Cada pedido conserva la **versión** de la política usada.

### 3.4 Fórmulas (sin circularidad)

Notación por unidad: **P** precio neto sin IVA (después de descuentos), **C** costo del producto, **V** costos variables
por unidad, **f** cargo % sobre el precio, **r** % de comisión del asesor (sobre la utilidad), **G** ganancia mínima.

La comisión se calcula sobre una **utilidad base definida antes** de descontar la propia comisión. Dos variantes posibles [?]:

| Base de comisión | Comisión | Contribución | Precio mínimo |
|---|---|---|---|
| **U = P − C** (vigente en el Doc. 04) | r × (P − C) | (1 − r)(P − C) − V − f·P | **P ≥ [G + V + (1 − r)·C] ÷ (1 − r − f)** |
| **U = P − C − V** | r × (P − C − V) | (1 − r)(P − C − V) − f·P | **P ≥ [G + (1 − r)(C + V)] ÷ (1 − r − f)** |

Sin cargos ni variables (f = 0, V = 0): **P ≥ C + G ÷ (1 − r)**. **Nunca** calcular la comisión como r × precio: sobre
6.800 serían 1.088 en lugar de 288.

Pedido con costo fijo **F** (por ejemplo domicilio gratis): además de cada unidad, **Σ contribuciones − F ≥ n × G**, que
equivale a **Σ (contribución_i − G) ≥ F**.

Margen % después de comisión: **P ≥ (1 − r)·C ÷ [(1 − r) − m]**.

**Redondeo comercial:** siempre **hacia arriba** al escalón definido (por ejemplo, $100), y verificar la regla otra vez con
el precio redondeado. Nunca redondear hacia abajo.

**Impuestos:** hoy ningún pedido tiene IVA [D]. Si algún día los precios incluyen IVA, P es el precio **sin** IVA y el costo
también sin IVA descontable.

### 3.5 Ejemplo con costo $5.000 y ganancia $1.500 (todas las cifras son hipótesis)

Hipótesis: comisión ilustrativa del **16 % sobre la utilidad (P − C)**; redondeo hacia arriba a $100; sin IVA.

| Caso | Cálculo | Precio mínimo | Comprobación |
|---|---|---|---|
| 1. Antes de otros gastos (opción A) | 5.000 + 1.500 | **6.500** | Utilidad 1.500. Con la comisión del 16 % (240) quedan **1.260**: no cumple B |
| 2. Después del 16 % sobre utilidad | 5.000 + 1.500 ÷ 0,84 = 6.785,71 | **6.800** | Utilidad 1.800 · comisión 288 · quedan **1.512** ✓ |
| 3a. + empaque 300 y transporte 200 por unidad (V = 500), comisión sobre P − C | 5.000 + (1.500 + 500) ÷ 0,84 = 7.380,95 | **7.400** | Utilidad 2.400 · comisión 384 · 7.400 − 5.000 − 500 − 384 = **1.516** ✓ |
| 3b. Igual, comisión sobre P − C − V | 5.500 + 1.500 ÷ 0,84 = 7.285,71 | **7.300** | Base 1.800 · comisión 288 · 7.300 − 5.500 − 288 = **1.512** ✓ |
| 4. Caso 3a + cargo del 3 % por pago con datáfono | (1.500 + 500 + 0,84 × 5.000) ÷ 0,81 = 7.654,32 | **7.700** | Utilidad 2.700 · comisión 432 · cargo 231 · 7.700 − 5.000 − 500 − 432 − 231 = **1.537** ✓ |
| 5. Pedido de 2 unidades a 6.800 con domicilio gratis de 12.000 | 2 × (1.512 − 1.500) = 24 < 12.000 | — | **El pedido no cumple** aunque cada unidad sí: hace falta cobrar el domicilio o un pedido mínimo para envío gratis |

### 3.6 Interacción con grupos, cantidades, canal y asesor

- **Grupos y cantidad:** el precio final sale de Bagisto. Pasa por grupo de cliente (5 grupos con 299 precios fijos cada uno [D]),
  escalones por cantidad (solo `Mayorista_VIP_3`, hasta 10 unidades [D]), grupo temporal por venta (GRP-01) y reglas de
  carrito (hoy ninguna activa [D]). Por eso la regla se evalúa **sobre el precio final de cada línea del carrito**, después
  de `Cart::collectTotals()`, nunca sobre el precio de lista.
- **Evidencia de riesgo [D]** (con el ejemplo de $1.500, opción A): **141 de 299** precios fijos de `Mayorista_VIP_1` y 5 de
  cada otro grupo dejarían menos de 1.500; **15 de 117** líneas ya vendidas con costo congelado también. Esto **no** es un
  incumplimiento (no hay política aprobada), pero muestra que activar el bloqueo **antes** de revisar las listas pararía ventas.
- **Asesor:** con el % por asesor (A-01), el mínimo cambia por asesor. **[P]** El bloqueo usa el % real del vendedor al
  confirmar (0 % para venta de empresa). La revisión de listas de precios usa una **tasa de referencia** de la política (por
  ejemplo, el mayor % vigente) para que las listas sirvan a todos.
- **Canal:** hoy todos los pedidos son de Nueva venta (27 de 27 atribuidos) [D], pero la **tienda** y el **panel nativo** de
  Bagisto pueden crear pedidos sin pasar por la validación [C]. Ver P8.

### 3.7 Estimado frente a real

Lo que solo se conoce después (flete real, cargo de 99 Envíos, costo real de producción, devoluciones) se maneja así:

1. Al vender se usa una **estimación autorizada** de la política vigente y se guarda con su versión.
2. Cuando llega el valor real (cuenta de transportadora, archivo de Cargues, costo productivo), se registra la **diferencia**
   en la rentabilidad del pedido y del período.
3. La diferencia **no** reescribe la venta, ni la comisión, ni el costo congelado (salvo regla aprobada).
4. Un panel compara estimado contra real por referencia y período, para ajustar la política (§9).

---

## 4. Control en el flujo de venta

### 4.1 Dónde se calcula hoy el precio [C]

| Punto | Código | Efecto en el precio |
|---|---|---|
| Catálogo y precios por grupo | `CatalogoVentaService` (matriz, variante) con `ContextoPrecioComercial` | Precio por grupo efectivo |
| Grupo temporal por venta | `GrupoVentaService` → `Cart::collectTotals()` | Cambia todos los precios del carrito |
| Líneas del borrador | `LineasBorradorService` (sin `custom_price`: el asesor **no** escribe precios, D-18) | Cantidades → escalones |
| Cambio de cliente | `BorradorVentaService::cambiarCliente` (cancela el grupo temporal) | Cambia el grupo |
| Descuentos, cupones, promociones | Reglas de carrito y de catálogo de Bagisto; **ninguna activa**; Nueva venta no tiene cupones | `base_discount_amount` por línea |
| Flete y condición | `EntregaBorradorService`, carriers de Logística | Envío (fuera de la base comisionable) |
| Confirmación | `ConfirmarVentaService::crearPedido()`: `collectTotals()` → `revalidarStock/Entrega/Pago/Total` → crea el pedido | Precio definitivo |
| Otros canales | Tienda (checkout de Bagisto) y creación de pedidos del panel nativo | **Sin validación de ByLopez** |

### 4.2 Propuesta [P]

1. **Servicio único** `EvaluadorRentabilidadVenta` que recibe las líneas finales del carrito (precio neto por unidad),
   costo, política vigente, % del vendedor y costos de pedido, y devuelve por **línea/referencia**: cumple o no, cuánto falta y
   qué hacer. Se usa en el borrador, en la confirmación y en las pantallas de administración.
2. **Advertencia anticipada:** al agregar o cambiar líneas, grupo, cliente, cantidades o método de envío, Nueva venta
   muestra la referencia que no cumple **sin mostrar costos al asesor**. Ejemplo: «La referencia *Camiseta Hombre DURZ* no
   alcanza el precio mínimo con el grupo *Mayorista VIP 1*. Cambia el grupo, la cantidad o pide autorización a
   Administración». Al administrador se le muestra el detalle.
3. **Bloqueo efectivo en el servidor:** dentro de `crearPedido()`, después de `collectTotals()` y de las revalidaciones, y
   **antes** de `pedidos->create()`. Con un nuevo motivo de rechazo y sin crear el pedido.
4. **Revalidación siempre en la confirmación:** el borrador puede ser viejo. Si cambió el costo, la política o el % mientras
   la venta estaba abierta, manda el valor vigente **al confirmar** (A-02), y el asesor ve la diferencia antes de reintentar.
5. **Por línea, no por promedio:** cada referencia debe cumplir su mínimo; una línea rentable **no** compensa otra. El
   control por pedido (costos fijos) es **adicional**.
6. **Conservación:** en la misma transacción del pedido se guarda por línea precio neto, costo y estado del costo, versión de
   la política, % del asesor, costos variables estimados y el resultado de la evaluación.
7. **Sin permiso para saltarse el mínimo** mientras Camilo no lo apruebe expresamente.
8. **Muestras, garantías y reposiciones:** ya se clasifican (G-11). **No** deben venderse con precio ficticio como venta
   normal: necesitan su propio flujo autorizado, que hoy solo el administrador puede marcar [C]. La regla de precio mínimo
   aplica solo a `venta_normal`.
9. **Concurrencia:** la confirmación ya bloquea borrador y carrito y es idempotente (D-55). La evaluación vive dentro de esa
   transacción, así que dos confirmaciones no pueden saltarse la regla.

---

## 5. Comisiones y efectivo (actualización del Doc. 21)

1. **Utilidad base antes de la comisión:** U = P − C (o P − C − V según P3). Comisión = r × max(0, U) por unidad, sumada por
   referencia y por pedido. Cálculo con `brick/math`; redondeo **HALF_UP** a 2 decimales por línea; el total del pedido es la
   suma de líneas ya redondeadas.
2. **% histórico por asesor:** nueva tabla de porcentajes por asesor con vigencias, sin solapes, sin retroactividad (mismo
   patrón de `ReglasComisionService::programar()`). Hoy la tabla de reglas solo admite alcance **global** [C]: falta el alcance
   por asesor. El % se congela **al confirmar** (A-02).
3. **Estados separados:** **estimada** (derivada) → **habilitada** → **liquidada** → **pagada**. Más **anulada** y **ajuste**.
4. **Habilitación (A-04)**, todas a la vez:
   - Todo el pedido validado: cada unidad vendida (`qty_ordered − qty_canceled`) está en envíos **validados** como entregada o
     devuelta, y no queda nada sin despachar.
   - Cobro confirmado: Cartera en saldo 0, sin pagos sin revisar ni «requiere revisión».
   - **Dinero recibido por ByLopez:**
     - Si el envío tiene `recaudo_esperado` > 0, su movimiento de `recaudo` en la cuenta de la transportadora debe estar
       **liquidado** (dentro de una liquidación cerrada) [C].
     - Si no hay recaudo, basta el pago confirmado en Cartera (transferencia o efectivo recibido por ByLopez).
     - No se decide por el **medio** del pago: en el drawer de Entregas un cobro «efectivo» puede estar en manos del
       domiciliario.
5. **Liquidación neta de transportadoras:** la transportadora entrega recaudo − servicio. Para la comisión cuenta el
   **recaudo** liquidado. El **servicio** (flete) es un gasto logístico y no reduce la venta ni la utilidad comisionable,
   salvo que P3 lo incluya. **[?]** Si la liquidación cerró con **diferencia** (el transportador entregó menos), no se puede
   saber qué pedido faltó. **[P]** Sus pedidos quedan «dinero en revisión» hasta que Administración resuelva (P9).
6. **Abonos al asesor (A-05):** una **cuenta del asesor** con importe firmado (patrón de cuentas de transportadora): + comisión
   habilitada, − abono pagado, ± ajuste. Cada abono se **aplica** a líneas concretas (FIFO por fecha de habilitación, editable)
   para saber qué comisión está pagada. Clave de idempotencia por abono. Saldo = suma.
7. **Devoluciones, reversos y costos tardíos:** antes de pagar, la línea se anula. Después de pagar, se crea un ajuste. Las
   pagadas nunca se reescriben. **No** se descuenta al asesor un error de costeo de la empresa, ni se compensa contra
   liquidaciones pagadas sin regla aprobada (P10).

---

## 6. Qué existe y qué falta (actualizado al 2026-10-15)

| Pieza | Estado | Respaldo |
|---|---|---|
| Costo estándar congelado por línea | Existe | `bylopez_va_costos_venta` [C] |
| Estado del costo (provisional, vencido) y vigencia | **Falta** | — |
| Costo productivo completo | Parcial: tela + tarifas de corte y confección por orden; sin transporte, empaque ni defectos | CorteConfeccion [C] |
| Costo de empaque / insumos | **Falta** | — |
| Flete real por envío | Existe | Guías + movimiento `servicio` en cuentas de transportadora [C]; 0 movimientos en dev (sin backfill) [D] |
| Cargo de recaudo nacional (99 Envíos) | En desarrollo | Cargues [C] |
| Cargo de pasarela | **Falta** | — |
| Política de precio mínimo | **Falta** | — |
| Validación de rentabilidad en venta | **Falta** (el asesor no puede escribir precios, pero nada valida la ganancia) | `ConfirmarVentaService` [C] |
| % de comisión por asesor con vigencias | **Falta** (solo global) | `bylopez_va_comision_reglas` [C] |
| Comisión por línea, habilitación, liquidación, abonos | **Falta** | Doc. 21 |
| Dinero recibido por pedido | Fuente lista (recaudo liquidado), **falta el contrato** que lo exponga por pedido | `bylopez_lg_cuenta_movimientos` [C] |
| Validación en tienda / panel nativo | **Falta** | — |
| Gastos generales y caja | **Falta** | — |

---

## 7. Riesgos priorizados

| Id | Impacto | Riesgo | Evidencia |
|---|---|---|---|
| G-01 | **Crítico** | No existe ninguna regla que impida vender sin ganancia mínima | Nada valida la ganancia en `crearPedido()` [C]. 15 líneas vendidas bajo el umbral de ejemplo [D] |
| G-02 | **Crítico** | Activar el bloqueo sin revisar las listas pararía ventas mayoristas | 141 de 299 precios de VIP_1 bajo el umbral de ejemplo [D] |
| G-03 | **Alto** | `cost` se trata como costo completo sin serlo, y sin estado ni vigencia | Costo productivo conocido ≈ 881 de tela frente a 5.500 [C] |
| G-04 | **Alto** | Comisiones sobre dinero no recibido | Cobro contra entrega confirmado en Cartera antes de la liquidación (Doc. 21 R-01); las cuentas existen, falta exponerlo por pedido [C] |
| G-05 | **Alto** | % por asesor aprobado pero el esquema solo admite global | `TipoReglaComision::ALCANCE_GLOBAL` [C] |
| G-06 | **Alto** | Costos de pedido (domicilio gratis) ocultos en un control por unidad | Movimiento `servicio` de 10.000–12.000 por envío [C]; ver §3.5 caso 5 |
| G-07 | **Alto** | Otros canales sin validación | Checkout de tienda y panel nativo [C] |
| G-08 | **Medio** | Cargo de 99 Envíos ≈ 7,3 % del recaudo, desconocido al vender | Cargues [C][D] |
| G-09 | **Medio** | Cálculos monetarios en `float` | COM-02, Cartera [C] |
| G-10 | **Medio** | Liquidación de transportadora con diferencia, sin poder atribuirla a un pedido | `diferencia_liquidacion` [C] |
| G-11 | **Medio** | Mensajes que expongan el costo al asesor | Requisito COM-D-12 |
| G-12 | **Bajo** | Pasarela y empaque no registrados | — |

---

## 8. Decisiones

| Id | Decisión | Estado |
|---|---|---|
| A-01…A-07 | §0 | **Aprobadas** (2026-10-15) |
| COM-D-01, 05, 07, 08, 09, 10, 12, 13, 14, 15 · D-COST | Doc. 04 | **Documentadas, por reconfirmar** |
| COM-D-03 (20 %) y COM-D-06 (global) | Doc. 04 | **Reemplazadas** por A-01 |
| Doc. 21 P1, P3, P4, P11 | — | **Resueltas** por A-04, A-02 y A-05 |
| Ganancia mínima opción B, en pesos, por referencia + tela | §1.1, §3.2 | **Propuesta** |
| Estados del costo | §2.1 | **Propuesta** |
| Bloqueo en confirmación, advertencia en borrador, sin permiso de excepción | §4.2 | **Propuesta** |
| Cuenta del asesor con abonos aplicados | §5.6 | **Propuesta** |
| Preguntas P1–P12 | §11 | **Pendientes** |

---

## 9. Vistas y KPIs [P]

**Vistas** (componentes Bagisto: DataGrid, drawer, modal; mismo patrón que tarifas de taller, Control de costos y Cartera):

| Vista | Permiso propuesto | Contenido |
|---|---|---|
| **Políticas de precio mínimo** | `ventas_asesor.rentabilidad.politicas` | Por referencia + tela: ganancia mínima, % opcional, costos variables, vigencia, historial |
| **% de comisión por asesor** | `ventas_asesor.comisiones.porcentajes` | Vigencias e historial por asesor (no editable hacia atrás) |
| **Referencias con costo faltante, vencido o provisional** | `ventas_asesor.costos` | Extiende Control de costos |
| **Precios por grupo frente al mínimo** | `ventas_asesor.rentabilidad.view` | Por referencia y grupo: precio, mínimo requerido (tasa de referencia), diferencia y acción |
| **Rentabilidad por pedido** (solo admin) | `ventas_asesor.rentabilidad.view` | Por línea: precio, costo, variables, comisión, contribución, versión de política; estimado vs real |
| **Costos adicionales y reparto** | `ventas_asesor.rentabilidad.costos` | Registro de traslados, insumos y su reparto por lote o pedido |
| **Comisiones** | `comisiones.view_all` / `view_own` | Por pedido y referencia (A-03), estados, evidencias; el asesor sin costos |
| **Cuenta del asesor, liquidaciones y abonos** | `comisiones.approve` / `pay` | Saldo, abonos aplicados, comprobantes privados |
| **Rentabilidad estimada vs real** | `kpis.view_all` | Panel por período y referencia |

**KPIs:** ventas, utilidad, recaudo recibido y caja **nunca** se suman entre sí.

| KPI | Fórmula | Fecha | Fuente | Datos incompletos |
|---|---|---|---|---|
| Ventas netas | Σ P × unidades no canceladas | Venta | `order_items` | — |
| Utilidad bruta estándar | Σ (P − C) × unidades | Venta | Costo congelado | Líneas sin costo excluidas y contadas aparte; no se muestra un total «completo» |
| Contribución estimada | Σ utilidad − variables estimados − comisión | Venta | Snapshot de la evaluación | Pedidos sin snapshot: «sin estimación» |
| Contribución real | Igual con valores reales | Cierre del costo real | Cuentas de transportadora, Cargues, costos | Mientras falte un real: «parcial» |
| Desviación estimado vs real | Real − estimado | Cierre | Ambos | Solo pedidos con ambos |
| Ventas bajo el mínimo (intentos bloqueados) | Conteo de rechazos por referencia y grupo | Intento | Bitácora de evaluación | — |
| Referencias sin costo o vencidas | Conteo | Hoy | Costos | — |
| Recaudo recibido | Σ pagos confirmados sin recaudo + recaudos liquidados | Fecha de recepción | Cartera + cuentas | Recaudo en custodia aparte |
| Recaudo en custodia | Σ recaudos abiertos | Hoy | Cuentas de transportadora | — |
| Comisión estimada / habilitada / liquidada / pagada | Σ por estado (excluyentes) | Del hito | Comisiones | No sumar estados |
| Saldo por pagar al asesor | Σ cuenta del asesor | Hoy | Comisiones | — |
| Utilidad del período | Contribución real − gastos generales | Período | Futuro módulo de gastos | **No calculable** hoy |

Incumplimientos por logística o por falta de costo **no** se atribuyen al asesor: se muestran como causa propia.

---

## 10. Casos límite (resultado esperado; no ejecutados)

| # | Caso | Resultado esperado |
|---|---|---|
| 1 | Descuento que rompe el mínimo | La línea con descuento no cumple → advertencia en el borrador y bloqueo al confirmar |
| 2 | Cambio temporal de grupo | `collectTotals()` reprecia; se reevalúa; si una referencia no cumple, se advierte y bloquea |
| 3 | Precio mayorista por cantidad | Se evalúa el precio final del escalón; si la cantidad baja el precio bajo el mínimo, se bloquea esa línea |
| 4 | Dos asesores con % distinto | Mismo precio puede cumplir para uno y no para otro; mensaje sin mostrar el % ni el costo; las listas se revisan con la tasa de referencia |
| 5 | Cambio de % o costo con venta en borrador | Manda el vigente al confirmar (A-02); el asesor ve el aviso y reintenta |
| 6 | Pedido pequeño con flete alto | Unidades cumplen, el pedido no (§3.5 caso 5) → bloqueo o advertencia según P5 |
| 7 | Pedido mixto con una referencia bajo el mínimo | Se bloquea identificando esa referencia; las demás no la compensan |
| 8 | Costos compartidos entre productos distintos | Reparto por prendas o por valor según §2.2; nunca el total a cada uno |
| 9 | Costo desconocido o tardío | Desconocido: bloquea la venta normal. Tardío: diferencia en rentabilidad real, sin tocar venta ni comisión |
| 10 | Cancelación o devolución parcial | Las unidades salen; la comisión se recalcula antes de habilitar; después, ajuste |
| 11 | Venta de empresa | r = 0: el mínimo se calcula sin comisión |
| 12 | Muestras y reposiciones | Flujo propio autorizado, fuera del precio mínimo y sin comisión |
| 13 | Reintentos y concurrencia | Evaluación dentro de la transacción idempotente de confirmación; abonos con clave idempotente |
| 14 | Históricos sin datos o pagados fuera | Sin evaluación retroactiva; fuera de comisiones antes de la fecha de inicio; marca «pagada fuera» |

---

## 11. Preguntas indispensables para aprobar las reglas

| # | Pregunta | Recomendación | Ejemplo |
|---|---|---|---|
| **P1** | ¿La ganancia mínima es A (antes de comisión), B (después de comisión y variables) o C (con gastos generales)? | **B** | Costo 5.000, G 1.500, 16 %: A → 6.500 (quedan 1.260); B → 6.800 (quedan 1.512) |
| **P2** | ¿En pesos, en %, o ambos? ¿Por referencia + tela con valor por defecto? | Pesos por referencia + tela; % opcional | — |
| **P3** | ¿La comisión se calcula sobre P − C o sobre P − C − costos variables? | **P − C** (simple y estable para el asesor) | §3.5 casos 3a y 3b |
| **P4** | ¿El mínimo usa el % real del asesor o una tasa de referencia? | Real al confirmar; referencia para revisar listas | — |
| **P5** | Costos por pedido (domicilio gratis, flete asumido): ¿bloquean o advierten? | Bloquean, con mensaje «cobra el domicilio o agrega unidades» | §3.5 caso 5 |
| **P6** | ¿Qué valores reales de G, empaque y flete estimado se cargan, y desde qué fecha se activa el bloqueo (después de corregir listas)? | Revisar primero la vista «Precios por grupo frente al mínimo» | 141 precios de VIP_1 |
| **P7** | ¿Se vende con costo provisional o vencido? ¿Cada cuánto vence un costo? | Provisional sí (marcado); vencido: advertir | — |
| **P8** | ¿Se bloquean también la tienda y el panel nativo de Bagisto? | Sí, con el mismo evaluador | — |
| **P9** | Si una liquidación de transportadora cierra con faltante, ¿qué pasa con las comisiones de sus pedidos? | Quedan «dinero en revisión» hasta que Administración resuelva | Faltan 20.000 en una liquidación de 10 pedidos |
| **P10** | Ajuste negativo después de pagar (devolución): ¿se compensa con abonos futuros? | Solo con regla aprobada; mientras tanto, saldo a cargo visible | — |
| **P11** | Escalón de redondeo comercial | $100 hacia arriba | 6.785,71 → 6.800 |
| **P12** | Si el costo real resulta mayor que el estándar, ¿cambia la comisión ya calculada? | No (error de costeo de la empresa) | — |

---

## 12. Plan (máximo cuatro fases; backend y frontend juntos)

El orden asegura la **base de costos y la protección del precio antes** de cualquier pago de comisión, y las **salvaguardas
de reversos y ajustes antes del primer pago**.

| Fase | Contenido | Aceptación manual (Camilo) |
|---|---|---|
| **R1 · Base de costos y políticas** | Estado y vigencia del costo por referencia; políticas de precio mínimo por referencia + tela con versiones; % de comisión por asesor con vigencias; evaluador único (`brick/math`); vistas: políticas, % por asesor, costos faltantes/vencidos, **precios por grupo frente al mínimo** (solo diagnóstico, sin bloquear) | Crear una política y ver qué precios por grupo no cumplen; cambiar el % de un asesor desde mañana sin alterar lo anterior; una referencia sin costo aparece en la lista |
| **R2 · Protección en la venta** | Advertencia en Nueva venta; **bloqueo en `crearPedido()`**; snapshot de evaluación por línea; desglose de rentabilidad por pedido (admin); validación en tienda y panel nativo (si P8); fecha de activación | Una venta con una referencia bajo el mínimo no se confirma y el mensaje nombra la referencia sin mostrar el costo; corregir el grupo o la cantidad permite confirmar; el pedido guarda la versión de la política |
| **R3 · Comisiones con salvaguardas** | Líneas por referencia (estimada / habilitada / anulada / ajuste); habilitación A-04 con contrato «dinero recibido» (recaudo liquidado); anulación y ajuste automáticos (validación anulada, pago rechazado, devolución); bandeja admin y «Mis comisiones» | Pedido validado, cobrado y con recaudo liquidado → habilitada con el cálculo por referencia; mientras el recaudo esté en custodia → no; anular la validación → anulada o ajuste |
| **R4 · Cuenta del asesor, abonos y KPIs** | Cuenta firmada del asesor, liquidaciones quincenales como estado de cuenta, abonos aplicados con comprobante privado; panel de rentabilidad estimada vs real y KPIs (§9) | Un abono parcial deja saldo pendiente y marca qué líneas paga; un ajuste posterior aparece como saldo; los KPIs no suman categorías distintas |

---

## 13. Relación con otros documentos

- **Doc. 19:** su §1 (cuatro conceptos) se amplía con §1 de este documento; su §7 (radar) es la base de «utilidad del período».
- **Doc. 21:** la habilitación y el plan C1–C4 se ajustan con A-02, A-04 y A-05; este plan R1–R4 lo **reemplaza** (C1 queda
  dentro de R3; C2 y C4 dentro de R4; C3 se reparte entre R3 y R4).
- **Doc. 04:** registra A-01…A-07 y remite aquí.
- **Logística:** `CUENTAS_TRANSPORTADORAS.md` (recaudo y servicio). **Cargues:** `13_ENVIOS_COMPLETOS_Y_E2.md` (99 Envíos).
- **CorteConfeccion:** `docs/confeccion/05_TARIFAS_CUENTAS_PRESTADOR.md` (tarifas por referencia).
