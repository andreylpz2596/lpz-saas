// Logica de PRESENTACION del drawer «Despachar» (sin navegador ni Vue): se extrae el <script type="module"> del Blade y se prueban
// solo las propiedades calculadas nuevas de la mejora visual. No decide nada de negocio. Lo invoca Log13PresentacionDespachoTest.
import assert from 'node:assert/strict';
import fs from 'node:fs';

const fuente = fs.readFileSync(process.argv[2], 'utf8');
const js = fuente.split('<script type="module">')[1].split('</script>')[0];

let def = null;
const app = { component: (_nombre, definicion) => { def = definicion; } };

globalThis.window = {
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    crypto: { randomUUID: () => 'uuid-de-prueba' },
    ByLopezMoneda: { precio: (n) => '$ ' + n },
};

new Function('app', 'window', js)(app, globalThis.window);
assert.ok(def, 'el componente se registro');

const textos = {
    modal: {
        'asume-incluido': 'Lo paga ByLopez. El flete esta incluido en el total del pedido.',
        asume: { cobrado_en_pedido: 'Lo paga ByLopez (el cliente ya lo pago en el pedido)', gratis: 'Lo asume ByLopez' },
        'recaudo-parcial-nota': 'Esta guia cobra :guia de :saldo',
    },
};

function instancia(ctx) {
    const vm = { textos, ctx };
    Object.assign(vm, def.methods);
    for (const [nombre, fn] of Object.entries(def.computed)) {
        Object.defineProperty(vm, nombre, { get: () => fn.call(vm), configurable: true });
    }
    vm.t = (txt, p) => Object.keys(p || {}).reduce((s, k) => s.split(':' + k).join(p[k]), txt || '');

    return vm;
}

const envio = (extra = {}) => ({ condicion: 'por_pagar', pago_pendiente: { bloquea: false, sin_revisar: 0, saldo: null, estado: null }, ...extra });

// ---- Metodo de pago: el titulo configurado se parte solo para leerlo; nunca se renombra ----
{
    const largo = instancia({ pedido: { tipo_pago: 'Transferencia de dinero(NEQUI,DAVIPLATA,EFECTIVO,BANCO)' }, envio: envio() });

    assert.equal(largo.pagoPartido.nombre, 'Transferencia de dinero', 'el nombre configurado, sin recortes ni rebautizos');
    assert.equal(largo.pagoPartido.medios, 'Nequi, Daviplata, Efectivo, Banco', 'incluye efectivo: se dice tal cual');

    const simple = instancia({ pedido: { tipo_pago: 'Pago contra Entrega' }, envio: envio() });

    assert.deepEqual({ ...simple.pagoPartido }, { nombre: 'Pago contra Entrega', medios: '' });
    assert.deepEqual({ ...instancia({ pedido: { tipo_pago: null }, envio: envio() }).pagoPartido }, { nombre: '', medios: '' });
}

// ---- Flete incluido en el pedido: «ya lo pago» solo se afirma con el pedido PAGADO ----
{
    const pagado = instancia({ envio: envio({ condicion: 'cobrado_en_pedido', pago_pendiente: { bloquea: false, sin_revisar: 0, saldo: 0, estado: 'pagado' } }) });
    const pendiente = instancia({ envio: envio({ condicion: 'cobrado_en_pedido', pago_pendiente: { bloquea: false, sin_revisar: 0, saldo: 55000, estado: 'pendiente' } }) });
    const validacion = instancia({ envio: envio({ condicion: 'cobrado_en_pedido', pago_pendiente: { bloquea: false, sin_revisar: 40000, saldo: 55000, estado: 'en_validacion' } }) });
    const fuera = instancia({ envio: envio({ condicion: 'cobrado_en_pedido' }) });

    assert.match(pagado.textoAsume, /ya lo pago/);
    assert.match(pendiente.textoAsume, /incluido en el total del pedido/);
    assert.doesNotMatch(pendiente.textoAsume, /ya lo pago/);
    assert.match(validacion.textoAsume, /incluido en el total/);
    assert.match(fuera.textoAsume, /incluido en el total/, 'fuera de Cartera tampoco se afirma que ya pago');
    assert.equal(instancia({ envio: envio({ condicion: 'gratis' }) }).textoAsume, 'Lo asume ByLopez', 'las demas condiciones no cambian');
}

// ---- Destino: el drawer solo lee la clasificacion del servidor; sin dato queda null (comportamiento de siempre) ----
{
    assert.equal(instancia({ envio: envio({ destino_cobertura: 'fuera_cobertura' }) }).destinoCobertura, 'fuera_cobertura');
    assert.equal(instancia({ envio: envio({ destino_cobertura: 'no_identificado' }) }).destinoCobertura, 'no_identificado');
    assert.equal(instancia({ envio: envio() }).destinoCobertura, null);
}

// ---- Despacho parcial: saldo / comprometido en otras guias / cobro de esta guia; todo del servidor, nada restado aqui ----
{
    const base = { pedido: { es_contraentrega: true }, recaudo_sugerido: 61000, envio: envio(), unidades: { lineas: [{ id: 1, pendientes: 3 }] } };
    const unica = instancia({ ...base, recaudo_guia: { contra_entrega: true, exigible: 61000, comprometido: 0, disponible: 61000, hay_previas: false, flete: 0, unitarios: { 1: 20000 } } });
    unica.cantidades = { 1: 3 };
    unica.cantidadDe = (l) => unica.cantidades[l.id];

    assert.equal(unica.repartoRecaudo, null, 'despacho unico sin guias previas: no hay reparto que mostrar');

    const segunda = instancia({ ...base, recaudo_guia: { contra_entrega: true, exigible: 61000, comprometido: 30500, disponible: 30500, hay_previas: true, flete: 0, unitarios: { 1: 10000 } } });
    segunda.cantidades = { 1: 3 };
    segunda.cantidadDe = (l) => segunda.cantidades[l.id];

    assert.deepEqual({ ...segunda.repartoRecaudo }, { saldo: 61000, comprometido: 30500, esta: 30000 });
    assert.ok(segunda.cobroPedido <= 30500, 'el cobro de esta guia nunca supera lo disponible que dice el servidor');
}

console.log('JS-OK');
