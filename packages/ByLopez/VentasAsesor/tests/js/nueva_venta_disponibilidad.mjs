// Comportamiento REAL del JavaScript de Nueva venta (sin navegador ni Vue): se extrae el <script type="module"> del Blade, se
// registra el componente contra un `app` falso y se ejercitan sus metodos sobre un `this` simulado. Cubre el estado de
// disponibilidad de los botones de color y el flujo «Continuar con tallas» con resultados parciales. Lo invoca
// Fase03gbDisponibilidadTest. Sale con codigo != 0 si algo falla.
import assert from 'node:assert/strict';
import fs from 'node:fs';

const fuente = fs.readFileSync(process.argv[2], 'utf8');
let js = fuente.split('<script type="module">')[1].split('</script>')[0];

// Blade del servidor → valores neutros (solo se prueba logica de cliente).
js = js.replace(/@json\([^)]*\)/g, 'null').replace(/\{\{[\s\S]*?\}\}/g, 'x');

let def = null;
const app = { component: (_nombre, definicion) => { def = definicion; } };

globalThis.window = { localStorage: { getItem: () => null, setItem: () => {}, removeItem: () => {} }, crypto: { randomUUID: () => 'u' } };
globalThis.document = { addEventListener: () => {}, removeEventListener: () => {}, getElementById: () => null };

new Function('app', 'window', 'document', js)(app, globalThis.window, globalThis.document);
assert.ok(def, 'el componente se registro');

/** Instancia simulada: metodos, data() y computadas como getters. */
function instancia() {
    const vm = { $nextTick: (f) => f(), $refs: {}, mensajes: [], abierto: false, peticiones: [] };
    Object.assign(vm, def.methods);
    Object.assign(vm, def.data.call(vm));
    for (const [nombre, fn] of Object.entries(def.computed || {})) {
        Object.defineProperty(vm, nombre, { get: () => fn.call(vm), configurable: true });
    }
    vm.$refs.drawerMatrices = { open: () => { vm.abierto = true; }, close: () => { vm.abierto = false; } };
    vm.$emitter = { emit: (_e, m) => vm.mensajes.push(m) };
    vm.estado = { borrador: { cliente: { customer_id: 9 } }, lineas: [] };
    vm.borradorId = 5;

    return vm;
}

const CABALLERO = { clave: 'camiseta_caballero', etiqueta: 'Camiseta caballero' };
const DAMA = { clave: 'camiseta_dama', etiqueta: 'Camiseta dama' };

// Lo que devuelve /lineas/{clave}/colores por producto (linea x color) con la disponibilidad que calcula el servidor.
const color = (productId, etiqueta, disponible, optionId = 1) => ({
    productId, tipo: 'configurable', sku: 'S' + productId, nombre: etiqueta, colorOptionId: optionId, colorEtiqueta: etiqueta,
    colorSwatch: '#c00', disponible, tallasConStock: disponible ? 3 : 0, tallasTotal: 4,
});

const ROJO = { clave: '1', etiqueta: 'Rojo', swatch: '#c00' };
const AZUL = { clave: '2', etiqueta: 'Azul', swatch: '#00c' };

function conLineas(vm, lineas, porLinea) {
    vm.lineasSeleccionadas = lineas;
    vm.coloresPorLinea = porLinea;
}

// ---- 1. Un producto + color totalmente agotado → SIN STOCK y deshabilitado ----
{
    const vm = instancia();
    conLineas(vm, [CABALLERO], { [CABALLERO.clave]: [color(10, 'Rojo', false), color(11, 'Azul', true, 2)] });

    assert.deepEqual(vm.disponibilidadColor(ROJO), { estado: 'agotado', texto: 'Sin stock' });
    assert.equal(vm.colorBloqueado(ROJO), true);
    assert.deepEqual(vm.disponibilidadColor(AZUL), { estado: 'ok', texto: null });
    assert.equal(vm.colorBloqueado(AZUL), false);

    // Un color agotado no se puede elegir.
    vm.toggleColor(ROJO);
    assert.equal(vm.coloresSeleccionados.length, 0, 'un color agotado no se selecciona');
    vm.toggleColor(AZUL);
    assert.equal(vm.coloresSeleccionados.length, 1);
}

// ---- 2. Dos productos + color agotado solo en uno → habilitado y disponibilidad parcial ----
{
    const vm = instancia();
    conLineas(vm, [CABALLERO, DAMA], {
        [CABALLERO.clave]: [color(10, 'Rojo', false)],
        [DAMA.clave]: [color(20, 'Rojo', true)],
    });

    assert.deepEqual(vm.disponibilidadColor(ROJO), { estado: 'parcial', texto: '1 de 2 disponible' });
    assert.equal(vm.colorBloqueado(ROJO), false, 'Rojo NO queda deshabilitado globalmente: Dama si tiene stock');
    vm.toggleColor(ROJO);
    assert.equal(vm.coloresSeleccionados.length, 1);
}

// ---- 3. Dos productos + color agotado en ambos → SIN STOCK y deshabilitado ----
{
    const vm = instancia();
    conLineas(vm, [CABALLERO, DAMA], {
        [CABALLERO.clave]: [color(10, 'Rojo', false)],
        [DAMA.clave]: [color(20, 'Rojo', false)],
    });

    assert.deepEqual(vm.disponibilidadColor(ROJO), { estado: 'agotado', texto: 'Sin stock' });
    assert.equal(vm.colorBloqueado(ROJO), true);
}

// ---- 4. Cambiar los productos seleccionados recalcula el estado de los colores al instante ----
{
    const vm = instancia();
    const porLinea = {
        [CABALLERO.clave]: [color(10, 'Rojo', false), color(11, 'Azul', true, 2)],
        [DAMA.clave]: [color(20, 'Rojo', true), color(21, 'Azul', true, 2)],
    };

    conLineas(vm, [CABALLERO], porLinea);
    assert.equal(vm.disponibilidadColor(ROJO).estado, 'agotado');

    conLineas(vm, [CABALLERO, DAMA], porLinea);
    assert.equal(vm.disponibilidadColor(ROJO).texto, '1 de 2 disponible');
    assert.equal(vm.disponibilidadColor(AZUL).estado, 'ok');

    conLineas(vm, [DAMA], porLinea);
    assert.equal(vm.disponibilidadColor(ROJO).estado, 'ok', 'solo Dama: Rojo vuelve a estar disponible');

    // Una linea que no tiene ese color no cuenta (no es «0 de 2»).
    conLineas(vm, [CABALLERO, DAMA], { [CABALLERO.clave]: [color(11, 'Azul', true, 2)], [DAMA.clave]: [color(20, 'Rojo', true)] });
    assert.equal(vm.disponibilidadColor(ROJO).estado, 'ok');

    // Un color ya marcado que pasa a agotado no se pierde: se puede desmarcar.
    conLineas(vm, [DAMA, CABALLERO], porLinea);
    vm.coloresSeleccionados = [ROJO];
    conLineas(vm, [CABALLERO], porLinea);
    assert.equal(vm.colorBloqueado(ROJO), false, 'marcado: se puede desmarcar aunque este agotado');
}

// ---- 5. «Continuar con tallas»: resultados parciales → la modal abre con lo vendible y avisa lo demas ----
function matrizDe(productId) {
    return { productId, celdas: [{ variante: { productId: productId * 100, activa: true, stockVendible: 5, opciones: {}, sku: 'X' } }] };
}

async function continuar(vm, respuesta, seleccion = null) {
    vm.$axios = { get: (url, opciones) => { vm.peticiones.push([url, opciones]); return Promise.resolve({ data: respuesta }); } };
    conLineas(vm, [CABALLERO, DAMA], {
        [CABALLERO.clave]: [color(10, 'Rojo', false), color(11, 'Azul', true, 2)],
        [DAMA.clave]: [color(20, 'Rojo', true), color(21, 'Azul', true, 2)],
    });
    vm.coloresSeleccionados = seleccion || [ROJO];
    vm.verTallas();
    await new Promise((r) => setTimeout(r, 0));
}

{
    // Caballero/Rojo sin stock + Dama/Rojo con stock → abre con Dama y avisa de Caballero (una sola consulta).
    const vm = instancia();
    await continuar(vm, { matrices: { 20: matrizDe(20) }, excluidas: [{ producto_id: 10, motivo: 'sin_stock' }] });

    assert.equal(vm.abierto, true, 'la modal abre: la combinacion valida no queda bloqueada');
    assert.equal(vm.peticiones.length, 1, 'una consulta por lote, no una por producto');
    assert.deepEqual(vm.peticiones[0][1].params.productos, [10, 20]);
    assert.equal(vm.peticiones[0][1].params.solo_vendibles, 1);
    assert.deepEqual(vm.gruposModal.map((g) => g.tarjetas.map((t) => t.productId)), [[20]], 'solo Dama/Rojo en la modal');
    assert.equal(vm.avisoModal, '⚠ Algunas combinaciones no tienen disponibilidad:\n• Camiseta caballero · Rojo — Sin stock');
    assert.equal(vm.avisoSinTallas, null);
    assert.equal(vm.errorMatrices, null);
}

{
    // Todas disponibles: sin aviso, sin cambios respecto al comportamiento de siempre.
    const vm = instancia();
    await continuar(vm, { matrices: { 10: matrizDe(10), 20: matrizDe(20) }, excluidas: [] });

    assert.equal(vm.abierto, true);
    assert.equal(vm.avisoModal, null);
    assert.deepEqual(vm.gruposModal.map((g) => g.tarjetas.length), [2]);
}

{
    // Dos productos y dos colores, mezcla: Caballero/Rojo y Dama/Azul con stock; Caballero/Azul y Dama/Rojo sin stock.
    const vm = instancia();
    await continuar(vm, {
        matrices: { 10: matrizDe(10), 21: matrizDe(21) },
        excluidas: [{ producto_id: 11, motivo: 'sin_stock' }, { producto_id: 20, motivo: 'no_disponible' }],
    }, [ROJO, AZUL]);

    assert.equal(vm.abierto, true);
    assert.deepEqual(vm.gruposModal.map((g) => [g.etiqueta, g.tarjetas.map((t) => t.productId)]), [['Rojo', [10]], ['Azul', [21]]]);
    assert.equal(vm.avisoModal, '⚠ Algunas combinaciones no tienen disponibilidad:\n• Camiseta caballero · Azul — Sin stock\n• Camiseta dama · Rojo — No disponible');
}

{
    // Ninguna combinacion con tallas → la modal NO abre; mensaje claro (nunca un codigo tecnico).
    const vm = instancia();
    await continuar(vm, { matrices: {}, excluidas: [{ producto_id: 10, motivo: 'sin_stock' }, { producto_id: 20, motivo: 'sin_stock' }] });

    assert.equal(vm.abierto, false);
    assert.equal(vm.avisoSinTallas, 'No hay tallas disponibles para la selección realizada. Prueba otro color o producto.');
    assert.equal(vm.errorMatrices, null);
    assert.doesNotMatch(vm.avisoSinTallas, /producto_no_vendible|variante/);
}

{
    // Una sola combinacion elegida y sin tallas: mensaje especifico en lenguaje comercial.
    const vm = instancia();
    vm.$axios = { get: () => Promise.resolve({ data: { matrices: {}, excluidas: [{ producto_id: 10, motivo: 'sin_stock' }] } }) };
    conLineas(vm, [CABALLERO], { [CABALLERO.clave]: [color(10, 'Rojo', false)] });
    vm.coloresSeleccionados = [ROJO];
    vm.verTallas();
    await new Promise((r) => setTimeout(r, 0));

    assert.equal(vm.abierto, false);
    assert.equal(vm.avisoSinTallas, 'Rojo no tiene tallas disponibles para Camiseta caballero.');
}

// ---- 6. Editar desde el resumen no se limita por stock (hay que poder bajar o quitar lo que ya esta en la venta) ----
{
    const vm = instancia();
    vm.$axios = { get: (url, opciones) => { vm.peticiones.push(opciones); return Promise.resolve({ data: { matrices: { 10: matrizDe(10) }, excluidas: [] } }); } };
    vm.abrirMatrices([10], { productId: 10, etiqueta: 'Rojo', etiquetaLinea: 'Camiseta caballero', swatch: null });
    await new Promise((r) => setTimeout(r, 0));

    assert.equal(vm.peticiones[0].params.solo_vendibles, 0);
    assert.equal(vm.abierto, true);
}

// ---- 7. Los codigos tecnicos de producto/variante se traducen para el asesor ----
{
    const vm = instancia();
    const error = (codigo) => ({ response: { status: 422, data: { codigo, mensaje: 'Variante no valida: ' + codigo } } });

    for (const codigo of ['producto_no_vendible', 'producto_inexistente', 'variante_no_encontrada', 'variante_no_pertenece_al_padre']) {
        const texto = vm.mensajeDeError(error(codigo));

        assert.doesNotMatch(texto, new RegExp(codigo), codigo + ' no se muestra tal cual');
        assert.doesNotMatch(texto, /Variante no valida/);
    }
}

console.log('JS-OK');
