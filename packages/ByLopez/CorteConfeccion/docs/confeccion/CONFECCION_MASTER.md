# CONFECCION_MASTER.md

# ByLopez — Reglas funcionales maestras de Corte y Confección

Este documento define las reglas funcionales aprobadas para el flujo de
distribución a talleres y confección dentro de:

packages/ByLopez/CorteConfeccion/

IMPORTANTE:

- Este documento representa la verdad funcional del negocio.
- NO representa necesariamente la estructura técnica actual.
- Antes de implementar cada punto se debe comprobar cómo está construido
  actualmente el módulo.
- Si el código actual contradice una regla de este documento, NO improvisar.
  Documentar la diferencia y solicitar decisión antes de modificar arquitectura.
- NO modificar packages/Webkul/.
- Webkul puede inspeccionarse únicamente como referencia de arquitectura,
  UX, componentes y patrones de Bagisto.

---

# 1. Flujo general del negocio

El flujo conceptual es:

Orden de Corte
→ Corte realizado
→ Recepción en Bodega
→ Cantidades aptas para confección
→ Decisión de distribución
→ Uno o varios Talleres de Confección
→ Órdenes de Confección
→ Confección
→ Recepción de prendas confeccionadas en Bodega
→ Inventario

El pago al taller pertenece a un flujo financiero separado del estado
productivo de la orden.

---

# 2. Recepción del corte

Después de que Bodega recibe las cantidades reportadas por el cortador,
el sistema debe conocer por producto/variante:

- producto
- SKU
- color
- talla
- cantidad recibida
- cantidad defectuosa
- cantidad apta para confección

Conceptualmente:

Apta confección =
Cantidad recibida - Cantidad defectuosa

No duplicar datos si actualmente pueden obtenerse de relaciones existentes.

---

# 3. No es obligatorio confeccionar todo inmediatamente

Una cantidad recibida en Bodega NO tiene que ser enviada inmediatamente
a un taller.

Bodega puede decidir:

- enviar toda la cantidad;
- enviar solo una parte;
- no enviar ninguna cantidad todavía.

Las cantidades no distribuidas deben permanecer disponibles para una
distribución futura.

Nunca deben desaparecer, marcarse como confeccionadas ni considerarse
entregadas mientras permanezcan en Bodega.

---

# 4. Una misma recepción puede distribuirse entre varios talleres

Una Orden de Corte o Recepción de Corte NO pertenece necesariamente a
un único Taller de Confección.

Ejemplo:

Hombre Verde Cali talla L
Cantidad apta: 100

Distribución:

- 60 → Taller 1
- 20 → Taller 4
- 20 → queda pendiente en Bodega

El modelo debe poder representar este caso sin pérdida ni duplicación
de cantidades.

---

# 5. Una misma variante puede dividirse entre varios talleres

La unidad producto/color/talla puede dividirse.

No asumir:

una variante = un único taller

Debe permitirse distribuir una misma variante entre múltiples talleres.

Ejemplo:

Hombre / Verde Cali / L / 100 unidades

- Taller 1: 60
- Taller 4: 20
- Sin distribuir: 20

---

# 6. Cantidades de control

Por cada producto/variante debe poder determinarse conceptualmente:

- Recibido Bodega
- Defectuosa
- Apta confección
- Ya distribuido
- Disponible para distribuir
- Entregar ahora
- Pendiente después de la distribución

Conceptualmente:

Disponible para distribuir =
Apta confección - Ya distribuido

Pendiente después =
Disponible para distribuir - Entregar ahora

Estas fórmulas deben validarse contra la estructura técnica actual.

No guardar campos redundantes si pueden calcularse correctamente.

---

# 7. Taller predeterminado

La pantalla de distribución debe permitir seleccionar un:

Taller predeterminado

Su objetivo es reducir trabajo manual cuando la mayoría de prendas
van hacia un mismo taller.

El taller predeterminado puede aplicarse automáticamente visualmente
a las filas, pero cada variante debe poder cambiarse individualmente.

Ejemplo:

Taller predeterminado: Taller 4

- Hombre L → Taller 4
- Hombre S → Taller 4
- Hombre XL → Taller 4
- Hombre M → cambiar manualmente a Taller 1

---

# 8. Seleccionar taller NO significa distribuir automáticamente todo

Elegir un Taller predeterminado NO debe obligar a enviar todas las
cantidades disponibles.

La cantidad "Entregar ahora" debe seguir siendo decisión de Bodega.

Preferiblemente iniciar en 0, salvo que el usuario utilice una acción
explícita como:

- Asignar todo disponible
- Asignar seleccionados

Esto evita entregas accidentales.

---

# 9. Operaciones masivas

Para mantener una interfaz rápida e intuitiva se deben estudiar acciones
masivas como:

- seleccionar varias filas;
- asignar Taller a seleccionados;
- asignar Prioridad a seleccionados;
- asignar cantidades cuando sea apropiado;
- limpiar asignación.

No obligar al usuario a editar decenas de filas una por una.

---

# 10. Dividir entre talleres

Cada variante debe poder tener una acción equivalente a:

Dividir entre talleres

Ejemplo:

Disponible: 100

Taller 1: 60
Taller 4: 20

Asignado: 80
Pendiente: 20

La suma asignada nunca puede superar la cantidad disponible.

---

# 11. Inventario actual de Bagisto

Durante la distribución, Bodega debe poder consultar el stock actual
de la variante/SKU correspondiente.

Ejemplo:

Producto: Hombre Verde Cali L
Stock actual: 10
Cantidad apta para confección: 60

El objetivo es apoyar la decisión de producción.

IMPORTANTE:

El stock debe provenir del sistema real de inventario de Bagisto.

No crear una copia independiente del stock dentro del módulo
CorteConfeccion salvo que exista una razón técnica justificada.

---

# 12. Stock actual NO es producción terminada

Crear una Orden de Confección NO debe aumentar el inventario disponible
para venta.

Las prendas asignadas a confección todavía no están terminadas.

Solo cuando el flujo correspondiente confirme el regreso/recepción de
las prendas confeccionadas debe evaluarse la entrada al inventario.

La forma técnica de hacerlo se definirá en una fase independiente.

---

# 13. Stock proyectado

La interfaz puede mostrar un dato informativo:

Stock proyectado

Conceptualmente:

Stock proyectado =
Stock actual + cantidad enviada a confección

Este valor es únicamente informativo.

NO representa stock vendible real.

Debe quedar visualmente claro que es una proyección.

---

# 14. Prioridad de confección

Cada asignación o variante puede tener prioridad:

- Normal
- Alta
- Urgente

La prioridad indica al Taller qué prendas deberían confeccionarse primero.

Ejemplo:

Hombre Verde Cali L
Stock actual: 10
Prioridad: URGENTE

Hombre Verde Cali M
Stock actual: 48
Prioridad: NORMAL

---

# 15. Prioridad sugerida vs prioridad real

El sistema puede utilizar el stock para sugerir visualmente una prioridad.

Ejemplo:

Stock muy bajo → sugerir URGENTE

Pero la prioridad definitiva siempre debe ser decisión del usuario.

No asumir automáticamente que menor stock siempre significa mayor prioridad,
porque pueden existir:

- pedidos especiales;
- campañas;
- ventas comprometidas;
- clientes grandes;
- decisiones comerciales externas al sistema.

Sistema recomienda.
Bodega decide.

---

# 16. La prioridad no controla los estados

La prioridad es una instrucción operativa.

NO debe impedir ni obligar transiciones de estado.

Ejemplo:

Una variante URGENTE no necesita obligatoriamente pasar por un estado
"En confección" antes de poder terminarse.

---

# 17. Fecha de entrega

Debe existir una:

Fecha de entrega predeterminada

pero diferentes talleres pueden necesitar fechas diferentes.

La interfaz debe permitir sobreescribir la fecha cuando sea necesario.

Evitar llenar innecesariamente cada fila con campos repetidos si puede
resolverse por Taller/Orden.

---

# 18. Observaciones

Debe poder existir:

- Observación general de distribución
- Observación de una Orden de Confección

Y, si técnicamente tiene sentido:

- observación específica de una asignación prioritaria

Ejemplo:

"Confeccionar primero las 30 unidades talla L para despacho del martes."

---

# 19. Generación de órdenes

Una sola operación de distribución puede generar múltiples
Órdenes de Confección.

Las órdenes deben agruparse por Taller cuando corresponda.

Ejemplo:

Distribución:

Taller 1
- Hombre L: 60

Taller 4
- Hombre L: 20
- Hombre M: 40

Resultado:

Orden Confección A → Taller 1
Orden Confección B → Taller 4

No crear una única Orden de Confección mezclando talleres diferentes.

---

# 20. Resumen antes de confirmar

Antes de generar las órdenes debe mostrarse un resumen suficientemente claro.

Ejemplo:

Taller 1
- Hombre Verde Cali L: 60
Prioridad: Urgente

Taller 4
- Hombre Verde Cali L: 20
Prioridad: Urgente

Sin distribuir:
- Hombre Verde Cali L: 20

Total apto: 100
Total distribuido: 80
Pendiente: 20

El usuario debe poder revisar antes de confirmar.

---

# 21. Estados de producción

El flujo debe mantenerse simple.

Conceptualmente:

Entregada al taller
→ En confección
→ Terminada
→ Recibida en Bodega

Pero:

"En confección" debe ser OPCIONAL.

Debe ser válido:

Entregada al taller
→ Terminada

sin necesidad de pasar obligatoriamente por:

En confección

No crear estados innecesarios que compliquen la operación.

---

# 22. Estados que NO queremos duplicar sin necesidad

No crear por defecto estados redundantes como:

- Entregada por Bodega
- Recibida por Taller
- Aceptada por Taller
- Preparando
- Procesando
- Finalizando

si no aportan valor operativo real.

Se prioriza simplicidad y trazabilidad útil.

---

# 23. Estado productivo y estado financiero son independientes

No mezclar:

Terminada
y
Pagada

como si fueran el mismo concepto.

Una Orden de Confección puede estar:

Estado producción: Terminada
Estado pago: Pendiente

o:

Estado producción: Recibida en Bodega
Estado pago: Pagada

La implementación financiera debe tratarse de forma separada.

Reglas y plan de tarifas, pagos y anticipos de talleres y cortadores:
`05_TARIFAS_CUENTAS_PRESTADOR.md`.

---

# 24. Retorno del Taller

Posteriormente debe poder conocerse:

- cantidad entregada al taller;
- cantidad confeccionada;
- cantidad defectuosa;
- cantidad faltante;
- cantidad recibida nuevamente en Bodega.

Ejemplo:

Entregadas al Taller: 500
Confeccionadas: 494
Defectuosas: 3
Faltantes: 3

La implementación exacta se diseñará después de auditar la estructura actual.

---

# 25. Vista del Taller

La vista detalle del Taller debe seguir visualmente el patrón de la
vista Cliente de Bagisto/Webkul, pero adaptada al dominio de confección.

Debe mostrar conceptualmente:

- Nombre del Taller
- Estado
- Datos de contacto
- Dirección
- Crear Confección
- Órdenes activas
- Corte/Recepción de origen dentro de la trazabilidad de la orden
- Órdenes terminadas
- Estado de pago cuando exista

No copiar campos irrelevantes del Cliente como género o fecha de nacimiento
si no tienen sentido para un Taller.

Webkul solo será referencia visual y arquitectónica.

---

# 26. Trazabilidad de origen

La unidad principal del Taller debe ser la Orden de Confección.

Cada Orden de Confección debe poder rastrearse hacia:

Orden de Confección
→ Orden de Corte origen
→ Recepción de Corte origen

cuando las relaciones reales lo permitan.

No crear una relación falsa:

RecepcionCorte → Taller

porque una misma recepción puede distribuirse entre múltiples talleres.

---

# 27. UX / interfaz

La interfaz debe ser:

- intuitiva;
- rápida para Bodega;
- consistente con Bagisto Admin;
- clara visualmente;
- usable con cortes grandes;
- responsive.

Debe evitar:

- exceso de columnas innecesarias;
- exceso de estados;
- campos repetitivos;
- clics innecesarios;
- acciones peligrosas automáticas.

Usar patrones ya existentes en Bagisto cuando sea apropiado.

---

# 28. Producto, color y talla

Para operación de Bodega es preferible mostrar claramente:

Producto
Color
Talla

en lugar de depender únicamente de un nombre largo de producto/SKU.

El SKU puede mostrarse como información secundaria.

Ejemplo preferido:

Producto: Camiseta Hombre
Color: Verde Cali
Talla: L
SKU: 115DURZ-H-L

---

# 29. Integridad de cantidades

Reglas obligatorias:

- No distribuir cantidades negativas.
- No distribuir más que lo disponible.
- No permitir que la suma entre varios talleres supere lo disponible.
- Una cantidad ya distribuida no debe poder distribuirse nuevamente.
- Las cantidades pendientes deben permanecer disponibles.
- Cancelaciones/reversiones deben mantener integridad de cantidades.
- La generación de múltiples órdenes debe ser atómica/transaccional
  cuando técnicamente corresponda.

---

# 30. Caso funcional principal de referencia

Este caso debe utilizarse para validar el diseño técnico.

## Recepción

Hombre Verde Cali L
Recibido: 100
Stock Bagisto: 10

Hombre Verde Cali M
Recibido: 80
Stock Bagisto: 48

Hombre Verde Cali S
Recibido: 70
Stock Bagisto: 55

## Decisión de Bodega

Hombre L:

- 60 → Taller 1
- Prioridad: Urgente

- 20 → Taller 4
- Prioridad: Urgente

- 20 → sin distribuir

Hombre M:

- 0 distribuido
- 80 pendientes

Hombre S:

- 0 distribuido
- 70 pendientes

## Resultado obligatorio

Orden de Confección Taller 1:
- Hombre Verde Cali L: 60

Orden de Confección Taller 4:
- Hombre Verde Cali L: 20

Pendiente de distribución:
- Hombre L: 20
- Hombre M: 80
- Hombre S: 70

El modelo técnico propuesto debe poder representar este caso correctamente
sin duplicar ni perder cantidades.

---

# 31. Regla de implementación por fases

No implementar todo este documento de una sola vez.

Cada fase debe:

1. revisar el estado actual;
2. utilizar únicamente el contexto necesario;
3. implementar su alcance;
4. ejecutar pruebas;
5. documentar resultados;
6. actualizar STATUS.md;
7. detenerse.

No avanzar automáticamente a la siguiente fase.

---

# 32. Código actual vs objetivo funcional

Este documento define:

QUÉ debe poder hacer el negocio.

No define obligatoriamente:

CÓMO debe implementarse técnicamente.

El código actual es la fuente de verdad técnica inicial.

Si una regla requiere:

- nuevas tablas;
- nuevas relaciones;
- migrations;
- cambios de estados;
- cambios en inventario;
- nuevos Services;

primero debe documentarse durante la fase correspondiente.

No crear arquitectura innecesaria sin análisis previo.


# Principios permanentes de UX / Frontend

Toda interfaz nueva del flujo de Corte y Confección debe diseñarse pensando
primero en el usuario operativo de Bodega/Taller y no únicamente en la
estructura técnica del sistema.

## Objetivo

La interfaz debe sentirse moderna, limpia, rápida e intuitiva y mantener
coherencia visual con Bagisto Admin.

Un usuario de Bodega debe poder entender qué hacer sin necesitar conocer
la arquitectura interna del módulo.

## Principios

1. Reducir clics y digitación innecesaria.

2. Priorizar información útil para tomar decisiones:
   - producto
   - color
   - talla
   - cantidad disponible
   - stock
   - cantidad a distribuir
   - taller
   - prioridad

3. Evitar mostrar información técnica que no aporte a la operación.

4. Usar jerarquía visual clara:
   - información principal visible;
   - detalles secundarios discretos;
   - SKU como dato secundario cuando corresponda.

5. Utilizar badges, estados y alertas de forma moderada.

6. No saturar la interfaz de colores.

7. Las acciones importantes deben ser evidentes y las acciones destructivas
   deben requerir confirmación.

8. Las operaciones masivas deben reducir trabajo repetitivo.

9. La interfaz debe prevenir errores:
   - no permitir cantidades superiores al disponible;
   - mostrar pendientes;
   - mostrar resumen antes de generar varias órdenes;
   - advertir acciones con consecuencias operativas.

10. La UI nunca sustituye las validaciones backend.

11. Debe ser responsive y conservar correctamente el ancho del Admin de
    Bagisto.

12. Evitar clases CSS/Tailwind inexistentes o dinámicas no compiladas.

13. NO modificar packages/Webkul/Admin ni su configuración Tailwind para
    resolver estilos.

14. Inspeccionar las vistas oficiales de Webkul únicamente como referencia
    de:
    - layout
    - cards
    - DataGrids
    - inputs
    - selects
    - badges
    - modales
    - dropdowns
    - responsive
    - spacing

15. El objetivo no es copiar literalmente una pantalla Webkul sino hacer que
    el módulo ByLopez se sienta parte nativa del Admin de Bagisto.

16. En procesos complejos, preferir divulgación progresiva:
    mostrar primero lo necesario y revelar opciones avanzadas solamente
    cuando el usuario las necesite.

17. Evitar formularios excesivamente largos cuando una tabla interactiva o
    acciones contextualizadas sean más claras.

18. Ante varias alternativas de UX, priorizar:
    claridad > velocidad > estética.

La estética nunca debe sacrificar integridad de datos ni comprensión del
flujo.
