// Integracion de la mascara monetaria con el DOM: tecleo, cursor, borrado de separadores, pegado, campos mejorados en formularios
// y, sobre todo, el valor que SE ENVIA. Corre en Node sobre un DOM SIMULADO que reproduce lo que hace un navegador (insertar en el
// cursor, mover el cursor al asignar `value`, eventos `input` con `inputType`, sanear type=number, FormData y validez de campos).
// NO es un navegador real: la comprobacion visual en iPhone/escritorio sigue siendo manual. Sale con codigo != 0 si algo falla.
import assert from 'node:assert/strict';
import fs from 'node:fs';

const parcial = fs.readFileSync(process.argv[2], 'utf8');
const libreria = parcial.split('<script>')[1].split('</script>')[0].replace(/@json\([^;]*\);/, "'$ ';");

// ----------------------------------------------------------------------------------------------- DOM simulado
class Evento {
    constructor(tipo, opciones = {}) {
        this.type = tipo;
        this.bubbles = !!opciones.bubbles;
        this.inputType = opciones.inputType || '';
        this.data = opciones.data;
        this.target = null;
    }
}

class Elemento {
    constructor(etiqueta, documento) {
        this.tagName = etiqueta.toUpperCase();
        this.documento = documento;
        this.atributos = {};
        this.hijos = [];
        this.parentNode = null;
        this.oyentes = {};
        this.style = {};
        this.className = '';
        this.placeholder = '';
        this._value = '';
        this._type = 'text';
        this.required = false;
        this.readOnly = false;
        this.disabled = false;
        this.id = '';
        this.selectionStart = 0;
        this.selectionEnd = 0;
        this._mensaje = '';
        this.validity = { get valid() { return true; } };
    }

    get type() { return this._type; }

    set type(v) { this._type = v; }

    get value() { return this._value; }

    set value(v) {
        let texto = String(v);

        // type=number sanea: solo un numero valido; si no, vacio.
        if (this._type === 'number' && !/^-?\d+(\.\d+)?$/.test(texto)) {
            texto = '';
        }

        this._value = texto;
        // Como un navegador: al asignar `value`, el cursor pasa al final.
        this.selectionStart = this.selectionEnd = texto.length;
    }

    get valueAsNumber() { return this._type === 'number' && this._value !== '' ? parseFloat(this._value) : NaN; }

    get isConnected() {
        let n = this;

        while (n.parentNode) { n = n.parentNode; }

        return n === this.documento.body;
    }

    getAttribute(n) {
        if (n === 'required') { return this.required ? '' : null; }
        if (n === 'placeholder') { return this.placeholder || null; }

        return n in this.atributos ? this.atributos[n] : null;
    }

    hasAttribute(n) { return this.getAttribute(n) !== null; }

    setAttribute(n, v) {
        this.atributos[n] = String(v);

        if (n === 'required') { this.required = true; }
        if (n === 'type') { this._type = String(v); }
    }

    removeAttribute(n) {
        delete this.atributos[n];

        if (n === 'required') { this.required = false; }
    }

    addEventListener(t, f) { (this.oyentes[t] = this.oyentes[t] || []).push(f); }

    dispatchEvent(e) {
        e.target = e.target || this;
        (this.oyentes[e.type] || []).forEach((f) => f.call(this, e));

        if (e.bubbles && this.parentNode) { this.parentNode.dispatchEvent(e); }

        return true;
    }

    insertBefore(nuevo, ref) {
        nuevo.parentNode = this;
        this.hijos.splice(this.hijos.indexOf(ref), 0, nuevo);
    }

    appendChild(n) { n.parentNode = this; this.hijos.push(n); }

    remove() {
        if (this.parentNode) { this.parentNode.hijos.splice(this.parentNode.hijos.indexOf(this), 1); this.parentNode = null; }
    }

    setSelectionRange(a, b) { this.selectionStart = a; this.selectionEnd = b; }

    select() { this.selectionStart = 0; this.selectionEnd = this._value.length; }

    focus() { this.documento.activeElement = this; }

    blur() {
        if (this.documento.activeElement === this) { this.documento.activeElement = null; }
        this.dispatchEvent(new Evento('blur'));
    }

    setCustomValidity(m) { this._mensaje = m; }

    get validationMessage() { return this._mensaje; }

    get esValido() { return this._mensaje === '' && !(this.required && this._value === ''); }

    descendientes() { return this.hijos.flatMap((h) => [h, ...h.descendientes()]); }

    // Solo el selector que usa la libreria: input[data-moneda]:not([data-moneda-listo])
    querySelectorAll(selector) {
        assert.equal(selector, 'input[data-moneda]:not([data-moneda-listo])');

        return this.descendientes().filter((e) => e.tagName === 'INPUT' && 'data-moneda' in e.atributos && !('data-moneda-listo' in e.atributos));
    }
}

class Documento {
    constructor() {
        this.body = new Elemento('body', this);
        this.activeElement = null;
        this.readyState = 'loading';
    }

    createElement(e) { return new Elemento(e, this); }

    querySelectorAll(s) { return this.body.querySelectorAll(s); }
}

const documento = new Documento();
const ventana = {
    document: documento,
    Event: Evento,
    setInterval,
    clearInterval,
    setTimeout,
    requestAnimationFrame: (f) => setTimeout(f, 0),
    MutationObserver: class { observe() {} },
    addEventListener() {},
};

new Function('window', libreria)(ventana);
const M = ventana.ByLopezMoneda;

// ------------------------------------------------------------------------------------------ simulacion de usuario
const espera = (ms) => new Promise((r) => setTimeout(r, ms));

function insertar(el, texto, tipo = 'insertText') {
    const antes = el.value.slice(0, el.selectionStart);
    const despues = el.value.slice(el.selectionEnd);

    el.value = antes + texto + despues;
    el.selectionStart = el.selectionEnd = antes.length + texto.length;
    el.dispatchEvent(new Evento('input', { bubbles: true, inputType: tipo, data: texto }));
}

function tipear(el, texto) {
    el.focus();

    for (const c of texto) { insertar(el, c); }
}

function borrar(el) {
    // Backspace: borra la seleccion o el caracter anterior al cursor.
    el.focus();

    const a = el.selectionStart === el.selectionEnd ? Math.max(0, el.selectionStart - 1) : el.selectionStart;
    const resto = el.value.slice(el.selectionEnd);

    el.value = el.value.slice(0, a) + resto;
    el.selectionStart = el.selectionEnd = a;
    el.dispatchEvent(new Evento('input', { bubbles: true, inputType: 'deleteContentBackward' }));
}

function vaciar(el) {
    el.focus();
    el.select();
    el.value = '';
    el.dispatchEvent(new Evento('input', { bubbles: true, inputType: 'deleteContentBackward' }));
}

function pegar(el, texto) {
    el.focus();
    el.value = texto;
    el.dispatchEvent(new Evento('input', { bubbles: true, inputType: 'insertFromPaste', data: texto }));
}

/** Formulario simulado: lo que se envia = campos con name (el visible no lo tiene, el original si). */
function formulario(...campos) {
    const f = new Elemento('form', documento);

    documento.body.appendChild(f);
    campos.forEach((c) => f.appendChild(c));

    return {
        el: f,
        datos: () => Object.fromEntries(f.descendientes().filter((e) => e.tagName === 'INPUT' && e.getAttribute('name')).map((e) => [e.getAttribute('name'), e.value])),
        valido: () => f.descendientes().filter((e) => e.tagName === 'INPUT').every((e) => e.esValido),
    };
}

function campo(atributos, valor) {
    const e = documento.createElement('input');

    e.type = atributos.type || 'text';
    Object.entries(atributos).forEach(([k, v]) => { if (k !== 'type' && k !== 'required' && k !== 'id' && k !== 'class') { e.setAttribute(k, v); } });
    e.required = !!atributos.required;
    e.id = atributos.id || '';
    e.className = atributos.class || '';
    e.placeholder = atributos.placeholder || '';
    e.value = valor;
    e.setAttribute('data-moneda', '');

    return e;
}

// ------------------------------------------------------------------------------------------------- A. vincular
{
    const cambios = [];
    const libre = documento.createElement('input');
    documento.body.appendChild(libre);
    const enlace = M.vincular(libre, { alCambiar: (raw) => cambios.push(raw) });

    enlace.poner('100000');
    assert.deepEqual([libre.value, enlace.crudo(), cambios.length], ['$ 100.000', '100000', 0], 'el valor inicial se muestra formateado y no se emite');

    libre.focus(); libre.blur();
    assert.deepEqual([libre.value, enlace.crudo(), cambios.length], ['$ 100.000', '100000', 0], 'entrar y salir sin cambios no emite nada');

    // Escribir 120000 sobre lo existente.
    libre.focus(); libre.select();
    tipear(libre, '120000');
    assert.equal(libre.value, '$ 120.000');
    assert.equal(enlace.crudo(), '120000');
    assert.equal(cambios[cambios.length - 1], '120000');
    assert.equal(libre.selectionStart, libre.value.length, 'el cursor queda al final al escribir');

    // Cursor en medio: "$ 1|20.000" → escribir 5 → "$ 1.520.000", cursor junto al 5.
    libre.setSelectionRange(3, 3);
    insertar(libre, '5');
    assert.equal(libre.value, '$ 1.520.000');
    assert.equal(enlace.crudo(), '1520000');
    // Se inserto un punto de miles antes del 5: "$ 1.5|20.000" (posicion 5), justo despues del digito escrito.
    assert.equal(libre.selectionStart, 5, 'el cursor se queda junto al digito escrito, no salta al final');

    // Borrar un separador: "$ 100.000", cursor tras el punto, Backspace → borra el digito vecino.
    enlace.poner('100000');
    libre.focus(); libre.setSelectionRange(6, 6);
    borrar(libre);
    assert.deepEqual([libre.value, enlace.crudo()], ['$ 10.000', '10000'], 'borrar un separador borra el digito vecino');

    // Borrar un digito normal (el ultimo).
    enlace.poner('100000');
    libre.focus(); libre.setSelectionRange(libre.value.length, libre.value.length);
    borrar(libre);
    assert.deepEqual([libre.value, enlace.crudo()], ['$ 10.000', '10000']);

    vaciar(libre);
    assert.deepEqual([libre.value, enlace.crudo()], ['', '']);

    // Decimales: coma = decimal; un tercer decimal se ignora.
    tipear(libre, '7300,505');
    assert.deepEqual([libre.value, enlace.crudo()], ['$ 7.300,50', '7300.50']);

    vaciar(libre);
    tipear(libre, '7300,');
    assert.deepEqual([libre.value, enlace.crudo()], ['$ 7.300,', '7300']);
    libre.blur();
    assert.deepEqual([libre.value, enlace.crudo()], ['$ 7.300', '7300'], 'la coma colgante desaparece al salir');

    // Letras ignoradas; el punto escrito no es decimal.
    vaciar(libre);
    tipear(libre, 'ab1c2');
    assert.deepEqual([libre.value, enlace.crudo()], ['$ 12', '12']);
    vaciar(libre);
    tipear(libre, '51.5');
    assert.equal(enlace.crudo(), '515', 'un punto escrito a mano es separador de miles, no decimal');

    // Pegado.
    pegar(libre, '7300.50');
    assert.deepEqual([libre.value, enlace.crudo()], ['$ 7.300,50', '7300.50']);
    pegar(libre, '100.000');
    assert.deepEqual([libre.value, enlace.crudo()], ['$ 100.000', '100000']);
    pegar(libre, '$ 1.234.567');
    assert.deepEqual([libre.value, enlace.crudo()], ['$ 1.234.567', '1234567']);
    pegar(libre, '1234567890123456');
    assert.deepEqual([libre.value, enlace.crudo()], ['$ 1.234.567', '1234567'], 'un pegado que no cabe se rechaza');

    // Valor de la BD con ceros: se ve formateado y NO se reescribe.
    const emisiones = [];
    const otro = documento.createElement('input');
    documento.body.appendChild(otro);
    const e2 = M.vincular(otro, { alCambiar: (raw) => emisiones.push(raw) });

    e2.poner('14500.0000');
    otro.focus(); otro.blur();
    assert.deepEqual([otro.value, e2.crudo(), emisiones.length], ['$ 14.500', '14500.0000', 0], 'un valor de la BD sin tocar conserva su forma exacta');

    // Limite de enteros del campo (p. ej. monto de pago: 10 digitos).
    const lim = documento.createElement('input');
    documento.body.appendChild(lim);
    const e3 = M.vincular(lim, { maxEnteros: 10 });
    tipear(lim, '12345678901');
    assert.equal(e3.crudo(), '1234567890', 'el digito 11 no cabe y se ignora');
}

// ------------------------------------------------------------------------------------- B. mejorar: formulario Blade
documento.readyState = 'complete';

const nuevo = campo({ type: 'number', id: 'nuevo', name: 'nuevo_costo', step: '0.01', min: '0.01', required: true, class: 'w-full' }, '100000.00');
const f1 = formulario(nuevo);
let eventosOriginal = 0;
f1.el.addEventListener('input', () => { eventosOriginal++; });
let cambiosOriginal = 0;
f1.el.addEventListener('change', () => { cambiosOriginal++; });

const r = M.mejorar(nuevo);
const visible = r.visible;

assert.equal(nuevo.style.display, 'none', 'el original queda oculto');
assert.equal(nuevo.type, 'number', 'el original sigue siendo number (no cambian los eventos ni los numeros de Vue/VeeValidate)');
assert.equal(visible.value, '$ 100.000');
assert.equal(visible.id, 'nuevo', 'el id (y su label) pasan al campo visible');
assert.equal(visible.className, 'w-full');
assert.equal(visible.required, true);
assert.equal(nuevo.required, false, 'required pasa al visible (un campo oculto invalido bloquearia el envio sin avisar)');
assert.deepEqual(f1.datos(), { nuevo_costo: '100000.00' }, 'sin tocar: se envia EXACTAMENTE el valor original de la BD');
assert.equal(eventosOriginal, 0, 'mejorar no dispara eventos');
assert.equal(M.mejorar(nuevo), null, 'no se mejora dos veces');

// Escribir 120000: se envia 120000.
visible.focus(); visible.select();
tipear(visible, '120000');
assert.equal(visible.value, '$ 120.000');
assert.equal(nuevo.value, '120000');
assert.deepEqual(f1.datos(), { nuevo_costo: '120000' }, 'se envia 120000, nunca 120.000, "$ 120.000" ni 120');
assert.ok(eventosOriginal >= 1 && cambiosOriginal >= 1, 'Vue/VeeValidate reciben input y change del original');
assert.strictEqual(nuevo.valueAsNumber, 120000, 'como numero, igual que si el usuario hubiera escrito en el input number');

// Decimales con step 0.01.
vaciar(visible);
tipear(visible, '120000,5');
assert.deepEqual([visible.value, f1.datos()], ['$ 120.000,5', { nuevo_costo: '120000.5' }]);

// required y min pasaron al visible.
vaciar(visible);
assert.deepEqual(f1.datos(), { nuevo_costo: '' });
assert.equal(f1.valido(), false, 'required sigue bloqueando el envio vacio');
tipear(visible, '0');
assert.equal(f1.valido(), false, 'min sigue aplicando');
assert.match(visible.validationMessage, /mayor o igual a 0.01/);
vaciar(visible);
tipear(visible, '5');
assert.equal(f1.valido(), true);
assert.deepEqual(f1.datos(), { nuevo_costo: '5' });

// El codigo (Vue) cambia el valor del original sin eventos: el visible lo refleja.
nuevo.value = '55000';
await espera(700);
assert.equal(visible.value, '$ 55.000', 'un cambio hecho por codigo se refleja en el campo visible');

// Un cambio externo con el mismo numero no reescribe lo que el usuario ve (7300.50 vs 7300.5).
vaciar(visible);
tipear(visible, '7300,50');
const antes = visible.value;
nuevo.value = '7300.5';
await espera(700);
assert.equal(visible.value, antes, 'igual numero: no se reescribe lo que el usuario ve');

// ------------------------------------------------------------------------------------------------- C. step
const tres = campo({ type: 'number', name: 'costo_total', step: '0.001' }, '7300.125');
const f3 = formulario(tres);
const v3 = M.mejorar(tres).visible;
assert.deepEqual([v3.value, f3.datos()], ['$ 7.300,125', { costo_total: '7300.125' }], 'step 0.001 conserva sus 3 decimales');
v3.focus(); v3.select();
tipear(v3, '7300,1259');
assert.deepEqual([v3.value, f3.datos()], ['$ 7.300,125', { costo_total: '7300.125' }], 'un cuarto decimal no cabe y se ignora');

const uno = campo({ type: 'number', name: 'entero', step: '1' }, '5000');
const f4 = formulario(uno);
const v4 = M.mejorar(uno).visible;
assert.deepEqual([v4.value, f4.datos()], ['$ 5.000', { entero: '5000' }]);
v4.focus(); v4.select();
tipear(v4, '12,5');
assert.deepEqual([v4.value, f4.datos()], ['$ 125', { entero: '125' }], 'con step 1 la coma se ignora');

// ------------------------------------------------------------------------------- D. campo de texto (valor_kilo_referencia)
const cinco = campo({ type: 'text', name: 'valor_kilo_referencia', placeholder: 'Opcional' }, '');
const f5 = formulario(cinco);
const v5 = M.mejorar(cinco).visible;
assert.deepEqual([v5.value, v5.placeholder, f5.datos()], ['', 'Opcional', { valor_kilo_referencia: '' }]);
tipear(v5, '7300');
assert.deepEqual([v5.value, f5.datos()], ['$ 7.300', { valor_kilo_referencia: '7300' }]);

// Valor existente con decimales en un campo de texto.
const seis = campo({ type: 'text', name: 'valor_kilo_referencia' }, '7300.50');
const f6 = formulario(seis);
const v6 = M.mejorar(seis).visible;
assert.deepEqual([v6.value, f6.datos()], ['$ 7.300,50', { valor_kilo_referencia: '7300.50' }], 'sin tocar se envia el valor original');

// ---------------------------------------------------------------------------------------------- E. mejorarTodo / limpieza
const a = campo({ type: 'number', name: 'a', step: '0.01' }, '1');
const b = campo({ type: 'number', name: 'b', step: '0.01' }, '2');
formulario(a, b);
M.mejorarTodo();
assert.equal(a.getAttribute('data-moneda-listo'), '1');
assert.equal(b.getAttribute('data-moneda-listo'), '1');

tres.remove();
await espera(700);
assert.equal(v3.isConnected, false, 'si el original sale del DOM, el visible tambien');

// ------------------------------------------------------------------------------ E2. sin prefijo (el formulario ya pinta el $)
{
    const sp = campo({ type: 'number', name: 'valor_kilo', step: '0.01' }, '7300.50');
    sp.setAttribute('data-moneda-sin-prefijo', '');
    const f = formulario(sp);
    const v = M.mejorar(sp).visible;

    assert.deepEqual([v.value, f.datos()], ['7.300,50', { valor_kilo: '7300.50' }], 'sin prefijo: separadores de miles pero sin repetir el simbolo');
    vaciar(v);
    tipear(v, '1250000');
    assert.deepEqual([v.value, f.datos()], ['1.250.000', { valor_kilo: '1250000' }]);
}
// ------------------------------------------------------------------------------------ F. componente Vue <v-moneda>
{
    const modulo = parcial.split('<script type="module">')[1].split('</script>')[0];
    let def = null;

    new Function('app', 'window', modulo)({ component: (nombre, d) => { assert.equal(nombre, 'v-moneda'); def = d; } }, ventana);
    assert.ok(def, 'el componente se registro');
    assert.equal(def.inheritAttrs, false, 'las clases y atributos del uso van al input');
    assert.match(def.template, /^<input /, 'la raiz es el input (clases, readonly y data-testid pasan por $attrs)');

    /** Instancia simulada del componente (como la monta Vue): props, $refs, $emit y el v-model del padre. */
    function montar(modelValue, props = {}) {
        const campo = documento.createElement('input');
        documento.body.appendChild(campo);

        const vm = { $refs: { campo }, modelValue, numero: false, maxEnteros: 15, maxDecimales: 2, ...props, emitidos: [] };
        vm.$emit = (evento, valor) => { vm.emitidos.push([evento, valor]); vm.modelValue = valor; };

        def.mounted.call(vm);
        // Vue ejecuta el watcher cuando el padre cambia el modelo.
        vm.sincronizar = (nuevo) => def.watch.modelValue.call(vm, nuevo);

        return { vm, campo };
    }

    // Modo texto (monto de un pago): emite el texto crudo, igual que el input de texto de siempre.
    {
        const { vm, campo } = montar('100000');

        assert.deepEqual([campo.value, vm.emitidos.length], ['$ 100.000', 0], 'el valor inicial se muestra formateado y no se emite');
        campo.focus(); campo.blur();
        assert.equal(vm.emitidos.length, 0, 'entrar y salir sin tocar no cambia el modelo');
        assert.strictEqual(vm.modelValue, '100000');

        campo.focus(); campo.select();
        tipear(campo, '120000');
        assert.deepEqual(vm.emitidos[vm.emitidos.length - 1], ['update:modelValue', '120000'], 'emite el texto crudo');
        assert.strictEqual(typeof vm.modelValue, 'string');
        assert.equal(campo.value, '$ 120.000');

        vm.sincronizar(vm.modelValue);   // el padre devuelve el mismo valor: no se repone lo que se ve
        assert.equal(campo.value, '$ 120.000');

        vaciar(campo);
        assert.deepEqual(vm.emitidos[vm.emitidos.length - 1], ['update:modelValue', ''], 'vacio = cadena vacia');

        // El padre reinicia el formulario (p. ej. al abrir otro registro).
        vm.sincronizar('51000');
        assert.equal(campo.value, '$ 51.000');
        vm.sincronizar('');
        assert.equal(campo.value, '');
    }

    // Modo numero (valor kilo, antes input type=number): emite Number o '', como v-model sobre un input number.
    {
        const { vm, campo } = montar('14500.00', { numero: true });

        assert.deepEqual([campo.value, vm.emitidos.length], ['$ 14.500', 0]);
        assert.strictEqual(vm.modelValue, '14500.00', 'sin tocar, el modelo conserva la forma original');

        campo.focus(); campo.select();
        tipear(campo, '7300,50');
        assert.deepEqual(vm.emitidos[vm.emitidos.length - 1], ['update:modelValue', 7300.5]);
        assert.strictEqual(typeof vm.modelValue, 'number');
        vm.sincronizar(vm.modelValue);
        assert.equal(campo.value, '$ 7.300,50', 'escribir 7300,50 y recibir 7300.5 del padre no reescribe lo que el usuario ve');

        vaciar(campo);
        assert.deepEqual(vm.emitidos[vm.emitidos.length - 1], ['update:modelValue', '']);
        tipear(campo, '0');
        assert.strictEqual(vm.modelValue, 0);
    }

    // Sin prefijo (edit-form de compras: el simbolo $ ya esta junto al campo).
    {
        const { vm, campo } = montar('7300', { numero: true, sinPrefijo: true });

        assert.equal(campo.value, '7.300');
        tipear(campo, '5');
        assert.equal(campo.value, '73.005');
        assert.strictEqual(vm.modelValue, 73005);
    }
    // Filas independientes (v-for): cada instancia con su propio valor.
    {
        const a = montar('1000', { numero: true });
        const b = montar('2000', { numero: true });

        a.campo.focus(); a.campo.select();
        tipear(a.campo, '9');
        assert.strictEqual(a.vm.modelValue, 9);
        assert.strictEqual(b.vm.modelValue, '2000');
        assert.equal(b.campo.value, '$ 2.000');
    }

    // Limites propios del campo: monto de un pago (10 enteros, 2 decimales).
    {
        const { vm, campo } = montar('', { maxEnteros: 10 });

        tipear(campo, '12345678901');
        assert.strictEqual(vm.modelValue, '1234567890');
    }
}

console.log('JS-OK');
process.exit(0);
