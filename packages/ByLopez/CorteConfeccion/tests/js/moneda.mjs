// Logica PURA de la mascara monetaria (sin DOM): formato de lectura, interpretacion de lo escrito e ida y vuelta del valor.
// Se extrae el primer <script> del parcial Blade y se ejecuta contra un `window` falso. Lo invoca MonedaMascaraTest.
// Sale con codigo != 0 si algo falla. La parte de DOM (cursor, pegado, borrado, formularios) la cubre moneda_navegador.mjs.
import assert from 'node:assert/strict';
import fs from 'node:fs';

const fuente = fs.readFileSync(process.argv[2], 'utf8');
const js = fuente.split('<script>')[1].split('</script>')[0].replace(/@json\([^;]*\);/, "'$ ';");

const ventana = { document: undefined };
new Function('window', js)(ventana);

const M = ventana.ByLopezMoneda;
assert.ok(M, 'la libreria se registro');
assert.equal(M.prefijo, '$ ');

// ---- 1. Lectura: lo que el usuario VE ----
const lectura = [
    [7300, '$ 7.300'], [14500, '$ 14.500'], [100000, '$ 100.000'], [1000000, '$ 1.000.000'], [0, '$ 0'],
    ['7300', '$ 7.300'], ['14500.00', '$ 14.500'], ['14500.0000', '$ 14.500'], ['7300.5', '$ 7.300,50'], [7300.5, '$ 7.300,50'],
    ['7300.125', '$ 7.300,125'], ['0.5', '$ 0,50'], ['999', '$ 999'], ['1000', '$ 1.000'],
    ['', ''], [null, ''], [undefined, ''],
    ['abc', 'abc'], ['1e21', '1e21'], ['-5', '-5'],
];

for (const [valor, esperado] of lectura) {
    assert.equal(M.formatear(valor), esperado, 'formatear(' + JSON.stringify(valor) + ')');
}

// ---- 2. Escritura: lo que el usuario ESCRIBE → valor crudo ----
const escritura = [
    ['$ 1.000', '1000'], ['100.000', '100000'], ['100000', '100000'], ['$ 100.000', '100000'], ['1.234.567', '1234567'],
    ['$ 100,5', '100.5'], ['100,50', '100.50'], ['$ 7.300,', '7300'], [',5', '0.5'], ['0', '0'], ['007', '7'], ['00,5', '0.5'],
    ['', ''], ['$ ', ''], ['abc', ''],
];

for (const [escrito, raw] of escritura) {
    assert.equal(M.interpretar(escrito).raw, raw, 'interpretar(' + JSON.stringify(escrito) + ')');
}

// Los puntos ESCRITOS son separadores de miles (nunca decimales): 51.000 son 51000, no 51.
assert.equal(M.interpretar('51.000').raw, '51000');
assert.equal(M.interpretar('51.5').raw, '515', 'un punto escrito a mano no es decimal');

// Pegado: un unico punto con 1-2 decimales al final es decimal; cualquier otro punto es separador de miles.
assert.equal(M.interpretar('7300.50', { pegado: true }).raw, '7300.50');
assert.equal(M.interpretar('7300.5', { pegado: true }).raw, '7300.5');
assert.equal(M.interpretar('100.000', { pegado: true }).raw, '100000');
assert.equal(M.interpretar('1.234.567', { pegado: true }).raw, '1234567');
assert.equal(M.interpretar('7300.125', { pegado: true }).raw, '7300125', 'tres digitos tras el punto = miles');

// Lo que no cabe se ignora (no se trunca ni se redondea): mas decimales de los permitidos, o demasiados enteros.
assert.equal(M.interpretar('1,234').invalido, true);
assert.equal(M.interpretar('1,234', { maxDecimales: 3 }).raw, '1.234');
assert.equal(M.interpretar('1,5', { maxDecimales: 0 }).invalido, true, 'sin decimales no se admite la coma');
assert.equal(M.interpretar('1'.repeat(16)).invalido, true);
assert.equal(M.interpretar('1'.repeat(11), { maxEnteros: 10 }).invalido, true);
assert.equal(M.interpretar('1'.repeat(10), { maxEnteros: 10 }).raw, '1'.repeat(10));

// Texto en pantalla mientras se escribe (coma colgante y decimales como se escriben).
assert.equal(M.interpretar('7300,').texto, '$ 7.300,');
assert.equal(M.interpretar('7300,5').texto, '$ 7.300,5');
assert.equal(M.interpretar('1234567').texto, '$ 1.234.567');

// ---- 3. IDA Y VUELTA: BD → pantalla → (sin tocar) → guardado, y BD → pantalla → cambio → guardado ----
const valoresDeBD = ['100000', '14500.00', '14500.0000', '7300', '7300.5', '0.5', '1', '1000000', '123456789.99', '7300.125', '0'];

for (const bd of valoresDeBD) {
    // Mostrar y volver a interpretar lo MOSTRADO da el mismo numero (si cabe en 2 decimales).
    const mostrado = M.formatear(bd);
    const vuelta = M.interpretar(mostrado, { maxDecimales: 3 });

    assert.equal(Number(vuelta.raw), Number(bd), 'ida y vuelta de ' + bd + ' (' + mostrado + ')');
    // Y re-formatear lo crudo reproduce lo mostrado: la mascara es estable.
    assert.equal(M.formatear(vuelta.raw), mostrado);
}

// Caso obligatorio: BD 100000 → "$ 100.000" → sin cambios se guarda 100000 → cambia a "$ 120.000" → se guarda 120000.
assert.equal(M.formatear('100000'), '$ 100.000');
assert.equal(M.interpretar('$ 120.000').raw, '120000');
assert.equal(M.formatear(M.interpretar('$ 120.000').raw), '$ 120.000');

// Nunca se produce un valor con simbolo, separadores de miles ni coma decimal.
for (const escrito of ['$ 1.000.000', '$ 100.000', '1.000,50', '$ 7.300,5', '100,00']) {
    const { raw } = M.interpretar(escrito);

    assert.match(raw, /^\d+(\.\d+)?$/, 'crudo valido: ' + raw);
    assert.doesNotMatch(raw, /[$,\s]|\.\d{3}\./);
}

// ---- 4. Tipos que emite el componente ----
assert.strictEqual(M.aNumero('7300'), 7300);
assert.strictEqual(M.aNumero('7300.5'), 7300.5);
assert.strictEqual(M.aNumero('7300.50'), 7300.5);
assert.strictEqual(M.aNumero(''), '', 'vacio como un input number: cadena vacia');
assert.strictEqual(M.aNumero('0'), 0);

assert.equal(M.iguales('7300.50', 7300.5), true);
assert.equal(M.iguales('7300', '7300'), true);
assert.equal(M.iguales('', 0), false);
assert.equal(M.iguales('', ''), true);
assert.equal(M.iguales('abc', 'abc'), true);

// ---- 5. crudo(): lo que no es un numero plano no se toca ----
assert.equal(M.crudo('12.5'), '12.5');
assert.equal(M.crudo(' 12 '), '12');
assert.equal(M.crudo('1,5'), null);
assert.equal(M.crudo('$ 5'), null);
assert.equal(M.crudo(null), '');

// ---- 6. precio(): lectura de importes calculados en JS (Nueva Venta, Despacho, Entregas) ----
// `Intl` en espanol no agrupa los miles de 4 cifras («8500 COP» junto a «17.000 COP»): precio() siempre agrupa y nunca altera el valor.
const precios = [
    [8500, '$ 8.500'], [17000, '$ 17.000'], [433500, '$ 433.500'], ['46000.00', '$ 46.000'], ['69500', '$ 69.500'], [0, '$ 0'],
    [12.1, '$ 12,10'], [7300.5, '$ 7.300,50'], [1234567, '$ 1.234.567'], [-1200, '-$ 1.200'], [-8500.5, '-$ 8.500,50'],
    [null, '—'], [undefined, '—'], ['', '—'],
];

for (const [valor, esperado] of precios) {
    assert.equal(M.precio(valor), esperado, 'precio(' + JSON.stringify(valor) + ')');
}

// Un importe sucio por coma flotante se muestra con 2 decimales como mucho, sin cambiar la cifra.
assert.equal(M.precio(0.1 + 0.2), '$ 0,30');
assert.equal(M.precio(8499.999999), '$ 8.500');

// «69.500» (el monto que cobra un domiciliario) se escribe o se pega como 69500, nunca como 69,5.
assert.equal(M.interpretar('69.500').raw, '69500');
assert.equal(M.interpretar('69.500', { pegado: true }).raw, '69500');
assert.equal(M.interpretar('$ 69.500', { pegado: true }).raw, '69500');
assert.equal(M.interpretar('69,5').raw, '69.5', 'la coma SI es decimal');
assert.equal(M.interpretar('69500').raw, '69500');

console.log('JS-OK');
