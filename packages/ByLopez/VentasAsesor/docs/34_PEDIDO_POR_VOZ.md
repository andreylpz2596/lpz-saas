# 34 · Pedido por voz en Nueva venta

Estado: **implementado en develop y en pruebas** (2026-11-24), **sin servicios externos**. Decisión de Camilo: nada de API;
todo con reglas propias. La imagen pegada queda para una fase 2.

## 1. Cómo funciona

1. La asesora busca o crea el cliente en Nueva venta, como hoy.
2. Abre **«Dictar pedido»**, toca **🎤 Hablar** y dicta. El navegador pasa la voz a texto en un cuadro **editable**. Si el navegador
   no reconoce voz, se usa el micrófono del teclado del celular sobre el mismo cuadro.
3. **«Interpretar»** envía el texto al servidor. `InterpreteDictado` lo lee con reglas y `ResolutorDictado` valida cada línea contra
   el catálogo con el código único.
4. Se ve una lista con un estado por línea:
   - ✅ variante exacta;
   - ⚠️ resuelta asumiendo el género, porque el color y la talla solo existen en una línea;
   - ❌ con el motivo.
5. Cada línea se puede **corregir** con selectores (color, tipo Dama/Caballero/Niño/Crop top, talla del tipo, cantidad) que se
   validan otra vez contra el catálogo (`POST nueva-venta/dictado/corregir`), u **omitir**. Las líneas ❌ abren los selectores
   solas. Mientras quede una ❌ sin corregir ni omitir, no se puede agregar.
6. Después de agregar, la cantidad de cualquier talla se cambia con el lápiz ✏️ del producto en el resumen (modal solo de ese
   producto).
7. **«Agregar N líneas a la venta»** usa el mismo «aplicar tallas» de la matriz: un lote por producto, y la cantidad final es lo que ya había más lo dictado. Precio, stock y permisos son los de siempre. Si aún no hay venta, la primera prenda la crea, igual que con la matriz.

## 2. Qué entiende (InterpreteDictado)

| Pieza | Acepta |
|---|---|
| Color | Nombre exacto del catálogo (`bylopez_va_sku_colores` + etiqueta de la opción), sin importar tildes, en femenino o plural («roja», «negras»). Una palabra que solo está en un color lo identifica («turquesa» → Azul Turquesa, «vino» → Vino Tinto). Una palabra de varios colores queda **ambigua** y se pide elegir («azul», «verde», «beige»). Alias en `ventasasesor.dictado.alias_colores` («rosa» → Rosado / Palo de Rosa). |
| Género | dama, mujer, señora, femenino → D · caballero, hombre, señor, masculino → H · niño, niña, infantil → N |
| Talla | S, M, L, XL, XXL; deletreadas después de «talla» («ese», «eme», «ele»); «equis ele», «extra grande»; niño 2-4, 6-8, 10-12, 14-16 («2 a 4», «2 4», «dos cuatro», «talla 10»); «única». La talla de niño implica Niño. |
| Cantidad | Cifras o palabras («quince», «veinticinco», «treinta y dos»), con o sin «unidades». «una blanca talla M» = 1. |
| Top | «top», «crop top» → línea TP, Dama, talla única. |

**Cómo arma las líneas:** una línea se cierra cuando llega una pieza que ya tenía: otro color, otra talla u otra cantidad.
La siguiente hereda el color y el género no repetidos: «caballero negro M 10, L 5, XL 3 y blanco M 4» da 4 líneas. Tras una
línea de niño, la talla de adulto vuelve al último género adulto dicho.

## 3. Validación contra el catálogo (ResolutorDictado)

`número del color` + `D/H/N` + `-DZ-` o `-TP-` + `talla` → SKU exacto de la variante (Doc. 33). El ejemplo de Camilo da
110N-DZ-2, 110N-DZ-6 y 108D-DZ-S.

Mensajes:
- «Azul Rey Dama no tiene talla XL.»
- «No hay Rojo en Caballero.»
- «¿Cuál color? Azul Oscuro, Azul Rey…»
- «Di si es dama o caballero…»
- «Falta la talla / la cantidad.»
- «El producto … no está activo.»

## 4. Piezas

| Pieza | Archivo |
|---|---|
| Reglas | `src/Support/Dictado/InterpreteDictado.php` (sin base de datos) |
| Validación | `src/Services/Venta/Dictado/ResolutorDictado.php` (solo lectura) |
| Ruta | `POST nueva-venta/dictado/interpretar` (`NuevaVentaController::interpretarDictado`), con el permiso de Nueva venta (`ventas_asesor.pedidos.create`) |
| Pantalla | Panel «Dictar pedido» en `nueva-venta/index.blade.php` (Web Speech API, `es-CO`); no aparece en autoservicio |
| Configuración | `ventasasesor.dictado.activo` (`VENTASASESOR_DICTADO_ACTIVO`, activo por defecto) y `alias_colores` |
| Pruebas | `tests/Unit/InterpreteDictadoTest.php` (reglas) y `tests/Feature/Dictado01Test.php` (catálogo, ruta, permiso, pantalla) |

## 5. Límites conocidos

- La voz depende del navegador:
  - Chrome y Edge en computador y Android funcionan bien.
  - En iPhone (Safari), el dictado de Siri debe estar activo.
  - Con internet lento, el reconocimiento se corta: se puede seguir con el teclado.
- Solo productos del patrón del código único (camisetas Piel Durazno y Crop Top). Lo demás se agrega con la matriz.
- El selector de talla ofrece las tallas del tipo (S–XL, 2-4…14-16, Única), no solo las que existen en ese color: si no existe,
  «Validar» lo dice.
- Fase 2, sin hacer: pegar una imagen o captura del pedido. Sin API haría falta reconocimiento de texto local (Tesseract, como en los comprobantes), con menos exactitud en fotos.
