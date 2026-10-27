# 15 · «Cartera completa» de 99 Envíos: conciliación de pago (análisis y flujo; NO implementado)

Propósito reservado de esta exportación (`docs/Cartera_Completa_2026-09-12.xlsx`, hoja `Data`, 65 filas × 12 columnas): **conciliar si 99 Envíos reporta como pagado un envío que ya está registrado en nuestro sistema**. **No** es la fuente de clientes (eso es «Envíos completos», doc 14) y **no** habilita comisiones ni cobros. Este documento es análisis y decisiones pendientes: **no se implementó ningún movimiento financiero**, no se crearon pedidos ni saldos. Sin datos personales.

Convención: [E] comprobado en los archivos o la BD de dev · [DECIDIDO] criterio de este análisis · [PENDIENTE] decisión de Camilo.

## 1. Qué trae el archivo [E]

| Columna | Contenido |
|---|---|
| `Cód. Sucursal` | 668099 en las 65 filas |
| `Nº Guía` | 65 guías únicas (texto) |
| `Valor Comercial` | entero; suma 8.259.462 |
| `Flete` | número en 56 filas; **texto** en 9 (`"3022.00"`, …) que son el **valor del seguro** de las filas «Seguro Antidevoluciones» |
| `Consignado` | entero; suma 5.879.201 |
| `Fecha Consig.` | 64 textos `AAAA-MM-DD` (2026-08-14 → 2026-09-11) y **1 número de serie de Excel** |
| `Estado Envío` | `Entregada` 56 · `Devolución ratificada` 8 · `Devolucion Regional` 1 |
| `Estado Pago` | `pagado` 56 · `Seguro Antidevoluciones` 9 |
| `Destinatario`, `Dirección`, `Teléfono`, `Ciudad` | personales (no se usan aquí) |

- **No hay** referencia, comprobante ni número de consignación, ni transportadora, ni descuentos desglosados.
- Relaciones exactas: en las **56** filas `pagado`, `Consignado` = parte entera de (`Valor Comercial` − `Flete`) (56 de 56); en las **9** «Seguro Antidevoluciones», `Consignado` = 0 (9 de 9) y `Flete` = valor del seguro. Estado de envío y de pago coinciden siempre: `Entregada` ↔ `pagado`; devolución ↔ «Seguro Antidevoluciones».
- Contra «Envíos completos» (exportación P): las 65 guías coinciden, con igual `Valor Comercial`, estado y (doc 10 §9) `Flete` = `valor_servicio`.

## 2. «Pagado según la plataforma» ≠ «dinero recibido y confirmado por ByLopez»

| Concepto | Quién lo afirma | Dónde vive / estado |
|---|---|---|
| «pagado» / `Consignado` / `Fecha Consig.` | **99 Envíos** (dato del proveedor) | solo en el Excel; es un **reporte**, como el estado de envío |
| Recaudo esperado de la guía | ByLopez | `bylopez_lg_guias.recaudo_esperado` y `recaudo_estado` (`no_aplica`, `pendiente_liquidar`, `liquidado`) |
| Dinero recibido y confirmado | ByLopez (Cartera) | `bylopez_va_pagos` con estado `confirmado` (tras `sin_revisar`; también `rechazado`, `reversado`) y sus aplicaciones a pedidos |
| Liquidación con la transportadora | ByLopez (Cuentas de transportadora) | libro firmado, movimientos `LIQ` inmutables (doc de Logística) |

Reglas del análisis [DECIDIDO]: el estado «pagado» del Excel **nunca** crea un pago confirmado, no marca `liquidado`, no cambia un saldo y **no habilita comisiones** (una comisión nace de la entrega validada y del pago confirmado por ByLopez, no de este archivo). Una entrega «según 99 Envíos» tampoco es entrega registrada (doc 13 §14.3).

## 3. Cruce con lo interno [E en dev, 2026-10-19]

- **Guías de Cartera en Logística** (`bylopez_lg_guias`): **0 de 65**. Sin guía interna no hay pedido ni recaudo esperado → **«sin vínculo para conciliar»** para las 65. No se crean pedidos, guías ni saldos para forzar la conciliación.
- Hoy hay 22 guías internas (20 `pendiente_liquidar`, 2 `no_aplica`) y 14 pagos en Cartera (12 `confirmado`, 2 `sin_revisar`); **ninguno** corresponde a las 65 guías de Cartera.
- **Los antecedentes de cliente no son cuentas por cobrar** [DECIDIDO]: `bylopez_va_cliente_envios_historicos` (doc 14) es evidencia reportada por el proveedor; **no** genera deuda, saldo ni relación financiera. 7 de las 65 guías existen como antecedente de un cliente: eso **no** las convierte en conciliables.
- **Transportadora:** Cartera no la trae. Cruzando **solo por guía** con «Envíos completos» P, las 65 se resuelven de forma **inequívoca** (65 únicas, 0 ambiguas, 0 sin coincidencia; código `1` = Inter Rapidísimo por la equivalencia configurada). Regla [DECIDIDO]: el cruce por transportadora efectiva + guía solo se acepta si **una** fila de Envíos completos coincide; con 0 o más de 1, la fila queda **pendiente** (no se adivina).

## 4. Flujo propuesto (para una fase futura; nada de esto está construido)

1. **Cargar** Cartera con un tipo propio «99 Envíos — Cartera (conciliación)» sobre el mismo motor (intermedia, VALIDAR, EJECUTAR, descargas): lee guía, valor comercial, flete/seguro, consignado, fecha de consignación y estado de pago.
2. **Resolver la transportadora** solo por cruce inequívoco con Envíos completos (§3); si no, pendiente.
3. **Buscar la guía interna** (transportadora efectiva + guía en `bylopez_lg_guias`):
   - sin guía interna → **«sin vínculo para conciliar»** (no se crea nada);
   - con guía interna → comparar con el recaudo esperado (valor comercial, flete/seguro, consignado = parte entera de valor − flete, **sin tolerancia**, como en Envíos completos).
4. **Registrar el resultado como reporte** (estado de pago según la plataforma, importes comparados, diferencia, fecha de consignación y procedencia del cargue), igual que el estado de envío: tabla aditiva, historial y observaciones. **No** crear pagos, aplicaciones, liquidaciones ni comisiones.
5. **Mostrar** en Logística (guía) y en Cartera una indicación «99 Envíos reporta pagado / consignado $X el AAAA-MM-DD — pendiente de confirmación de ByLopez», separada del pago confirmado.
6. La **confirmación** del dinero la sigue haciendo una persona con el flujo existente de Cartera (revisión de pagos) o la liquidación de la cuenta de la transportadora.

## 5. Decisiones pendientes [PENDIENTE]

1. **Valor del servicio / flete:** su composición con el valor comercial sigue sin confirmar (doc 13 §11): ¿incluye seguro u otros cargos? Hasta saberlo se registra literal.
2. **Qué se compara** exactamente contra el recaudo esperado de la guía: valor comercial, valor comercial − flete (= consignado) o ambos.
3. **Sin vínculo:** ¿se descartan, se guardan como «consignación sin envío registrado» para un futuro vínculo, o se exige primero registrar el despacho? (hoy: solo se reporta).
4. **Consignación sin referencia:** sin comprobante no hay forma de enlazar con un pago de Cartera confirmado; ¿se pide la referencia al proveedor o se concilia solo por guía?
5. **Fecha de consignación:** interpretación de la fecha numérica (serie de Excel) y zona horaria (doc 13 §11).
6. **Devoluciones con seguro:** cómo se reflejan «Seguro Antidevoluciones» (consignado 0) frente a la cuenta de la transportadora; no se implementa nada hasta definirlo.
7. **Comisiones:** confirmar que **ninguna** regla de comisión lee este reporte (hoy no lo lee); la habilitación sigue dependiendo de entrega validada + pago confirmado por ByLopez.
