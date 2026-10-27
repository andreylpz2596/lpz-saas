// Comportamiento REAL del JavaScript del drawer unico de Entregas (sin navegador ni Vue): se extrae el <script type="module"> del
// Blade, se registra el componente contra un `app` falso y se ejercitan sus metodos y propiedades calculadas sobre un `this`
// simulado. Lo invoca Log07RecepcionDevolucionTest. Sale con codigo != 0 si algo falla. NO es una prueba de navegador.
import assert from 'node:assert/strict';
import fs from 'node:fs';

const ruta = process.argv[2];
const fuente = fs.readFileSync(ruta, 'utf8');
const js = fuente.split('<script type="module">')[1].split('</script>')[0];

let def = null;
const app = { component: (_nombre, definicion) => { def = definicion; } };
let confirmaciones = [];

// El confirm() NATIVO ya no se usa: la confirmacion es el modal del proyecto (`open-confirm-modal`). Si algo lo llamara, la prueba falla.
globalThis.window = {
    crypto: { randomUUID: (() => { let n = 0; return () => 'uuid-' + (++n); })() },
    confirm: () => { throw new Error('no debe usarse window.confirm: usar el modal de confirmacion del proyecto'); },
    // Formato de importes (parcial moneda): lo que usa dinero(); no es objeto de esta prueba.
    ByLopezMoneda: { precio: (n) => '$ ' + n },
    location: { href: 'http://x/admin/logistica/entregas' },
};
globalThis.document = { addEventListener() {}, removeEventListener() {} };

new Function('app', 'window', 'document', js)(app, globalThis.window, globalThis.document);
assert.ok(def, 'el componente se registro');

const textos = new Proxy({
    dev: {}, cabecera: ':pedido',
    confirmar_titulo: 'Confirmar recepcion', confirmar_resumen: 'Recibidas :recibidas de :esperadas', confirmar_aptas: ':n aptas a :bodega vendible',
    confirmar_sin_aptas: 'ninguna apta', confirmar_novedad: ':n con novedad NO vuelven', confirmar_faltantes: ':n faltantes siguen sin recibir',
    cancelar: 'Cancelar', confirmar_recepcion: 'Confirmar recepcion',
}, { get: (o, k) => (k in o ? o[k] : String(k)) });

function instancia(ctx) {
    const posts = [];
    const vm = {
        $refs: { drawer: { open() {}, close() { vm.cerrado = true; } } },
        $emitter: { emit: (evento, m) => (evento === 'open-confirm-modal' ? confirmaciones.push(m) : vm.mensajes.push(m)) },
        $axios: {
            get: () => Promise.resolve({ data: { contexto: ctx } }),
            post: (url, datos) => { posts.push({ url, datos }); return vm.respuesta(url, datos); },
        },
        textos, estados: { entrega: {}, validacion: {} },
        urlContexto: '/ctx/__ID__', urlEntregar: '/entregar/__ID__', urlDevolver: '/devolver/__ID__', urlRecibir: '/recibir/__ID__',
        urlAnularEntrega: '/ae/__ID__', urlAnularDevolucion: '/ad/__ID__', urlAnularRecepcion: '/ar/__ID__',
        mensajes: [], posts, refrescos: [], cerrado: false,
        respuesta: (_url, _datos) => Promise.resolve({ data: { mensaje: 'ok', contexto: ctx } }),
    };
    Object.assign(vm, def.methods);
    Object.assign(vm, def.data.call(vm));
    for (const [nombre, fn] of Object.entries(def.computed)) {
        Object.defineProperty(vm, nombre, { get: () => fn.call(vm), configurable: true });
    }
    vm.refrescar = (id) => vm.refrescos.push(id);
    vm.ctx = ctx;
    vm.envioId = ctx.envio.id;

    return vm;
}

function contexto(extra = {}) {
    return {
        envio: { id: 41, pedido_id: 63, pedido: 'BYL-63', cliente: 'Ana', bodega: 'Bodega Bogota', transportadora: { codigo: 'BYLOPEZ', nombre: 'ByLopez' } },
        resumen: { despachadas: 8, entregadas: 2, devueltas: 0, pendientes: 6 },
        estado_entrega: 'parcial', validacion: 'registrada', estado_devolucion: null,
        productos: [
            { clave: 'p1', nombre: 'Camiseta', sku: 'CAM', color: 'Mostaza', filas: [
                { shipment_item_id: 1, talla: 'S', despachadas: 2, entregadas: 2, devueltas: 0, pendientes: 0 },
                { shipment_item_id: 2, talla: 'M', despachadas: 2, entregadas: 0, devueltas: 0, pendientes: 2 },
                { shipment_item_id: 3, talla: 'L', despachadas: 4, entregadas: 0, devueltas: 0, pendientes: 4 },
            ] },
        ],
        devoluciones: [],
        historial: [],
        catalogo_defectos: { motivos: [{ codigo: 'mancha', nombre: 'Mancha', requiere_detalle: false }, { codigo: 'otro', nombre: 'Otro', requiere_detalle: true }], origenes: [{ codigo: 'por_determinar', nombre: 'Por determinar' }, { codigo: 'transporte', nombre: 'Transporte' }] },
        permisos: { registrar: true, anular: false, recibir: true },
        form: { hoy: '2026-10-13', fecha_min: '2026-10-01', requiere_transportadora: false, transportadoras: [] },
        ...extra,
    };
}

const devolucion = (estado = 'pendiente_recibir', extra = {}) => ({
    id: 7, vigente: true, fecha: '2026-10-12', fecha_texto: '12 oct 2026', motivo: 'Cliente ausente', esperadas: 6, estado, bodega_determinada: true, recepcion: null,
    lineas: [
        { retorno_item_id: 10, shipment_item_id: 2, esperadas: 2, etiqueta: { producto: 'Camiseta', color: 'Mostaza', talla: 'M', sku: 'CAM-M' } },
        { retorno_item_id: 11, shipment_item_id: 3, esperadas: 4, etiqueta: { producto: 'Camiseta', color: 'Mostaza', talla: 'L', sku: 'CAM-L' } },
    ],
    ...extra,
});

// ---- 1. modos: entregar precarga lo pendiente, devolver arranca en 0; sin permiso o sin pendientes cae a «ver» ----
{
    const v = instancia(contexto());

    v.entrar('entregar');
    assert.equal(v.modo, 'entregar');
    assert.deepEqual(v.cantidades, { 2: 2, 3: 4 }, 'solo filas con pendiente, precargadas');
    assert.equal(v.totalForm, 6);
    assert.deepEqual(v.productosConPendientes[0].filas.map(f => f.talla), ['M', 'L'], 'la fila S (sin pendiente) no se ofrece');

    v.entrar('devolver');
    assert.deepEqual(v.cantidades, { 2: 0, 3: 0 }, 'la devolucion nunca se precarga');

    v.llenarTodo();
    assert.equal(v.totalForm, 6);
    v.llenarNada();
    assert.equal(v.totalForm, 0);

    const sinPermiso = instancia(contexto({ permisos: { registrar: false, anular: false, recibir: false } }));
    sinPermiso.entrar('entregar');
    assert.equal(sinPermiso.modo, 'ver', 'sin permiso de registrar no entra al modo');

    const completo = instancia(contexto({ resumen: { despachadas: 8, entregadas: 8, devueltas: 0, pendientes: 0 } }));
    completo.entrar('devolver');
    assert.equal(completo.modo, 'ver', 'sin pendientes no hay nada que entregar ni devolver');
}

// ---- 2. enviar: validaciones tempranas, payload exacto, misma clave en el reintento, exito → vuelve a «ver» y refresca ----
{
    const v = instancia(contexto());

    v.entrar('devolver');
    v.enviar();
    assert.equal(v.error, 'err_sin_unidades');
    assert.equal(v.posts.length, 0);

    v.cantidades[2] = 1;
    v.enviar();
    assert.equal(v.error, 'err_motivo', 'la devolucion exige motivo');

    v.form.motivo = 'Cliente ausente';
    v.cantidades[3] = '2';
    // Primer intento: se corta la red (timeout). El reintento debe llevar la MISMA clave.
    v.respuesta = () => Promise.reject({ response: { status: 500, data: {} } });
    v.enviar();
    const p = v.posts[0];
    assert.equal(p.url, '/devolver/41');
    assert.deepEqual(p.datos.items, { 2: 1, 3: 2 });
    assert.equal(p.datos.order_id, 63);
    assert.equal(p.datos.motivo, 'Cliente ausente');
    assert.match(p.datos.idempotency_key, /^uuid-/);

    // Reintento tras un error de red: la MISMA clave (idempotencia).
    const clave = v.clave;
    await new Promise(r => setTimeout(r, 0));
    assert.equal(v.error, 'error_generico');
    v.enviar();
    await new Promise(r => setTimeout(r, 0));
    assert.equal(v.clave, clave);
    assert.equal(v.posts[1].datos.idempotency_key, clave);

    // Exito: el contexto fresco reemplaza, vuelve a ver, avisa y refresca la fila/KPIs.
    v.respuesta = () => Promise.resolve({ data: { mensaje: 'Devolucion registrada', contexto: contexto({ estado_devolucion: 'pendiente_recibir' }) } });
    v.enviar();
    await new Promise(r => setTimeout(r, 0));
    assert.equal(v.modo, 'ver');
    assert.equal(v.ctx.estado_devolucion, 'pendiente_recibir');
    assert.deepEqual(v.mensajes, [{ type: 'success', message: 'Devolucion registrada' }]);
    assert.deepEqual(v.refrescos, [41]);
    assert.notEqual(v.clave, clave, 'tras el exito la siguiente accion usa otra clave');

    // Entrega con envio sin transportadora: la transportadora es obligatoria (el servidor tambien lo exige).
    const sin = instancia(contexto({ form: { hoy: '2026-10-13', fecha_min: '2026-10-01', requiere_transportadora: true, transportadoras: [{ codigo: 'A', nombre: 'A' }] } }));
    sin.entrar('entregar');
    sin.enviar();
    assert.equal(sin.error, 'err_transportadora');
    sin.form.carrier_code = 'A';
    sin.enviar();
    assert.equal(sin.posts[0].url, '/entregar/41');
    assert.equal(sin.posts[0].datos.carrier_code, 'A');
}

// ---- 3. recibir devolucion: el sistema propone, Bodega trabaja las excepciones; novedad y faltantes se DERIVAN ----
{
    const dev = devolucion();
    const v = instancia(contexto({ devoluciones: [dev], estado_devolucion: 'pendiente_recibir' }));

    assert.deepEqual(v.devolucionesPendientes.map(d => d.id), [7]);

    v.entrarRecibir(dev);
    assert.equal(v.modo, 'recibir');
    assert.deepEqual(v.recibido[10], { recibidas: 2, aptas: 2, motivo: '', defecto_motivo: '', defecto_origen: 'por_determinar', defecto_detalle: '' });
    assert.deepEqual(v.totalesRecepcion, { esperadas: 6, recibidas: 6, aptas: 6, novedad: 0, faltantes: 0 });
    assert.equal(v.hayDiferencias, false);
    assert.equal(v.gruposRecepcion.length, 1, 'mismo producto/color en un solo bloque');
    assert.equal(v.gruposRecepcion[0].lineas.length, 2);

    // 4 recibidas de 4 con 3 aptas → 1 con novedad; 2 recibidas de 2 pero llegaron 1 → 1 faltante.
    v.recibido[11].aptas = 3;
    v.recibido[10].recibidas = 1;
    v.alCambiarRecibidas(dev.lineas[0]);
    assert.equal(v.novedadDe(dev.lineas[1]), 1);
    assert.equal(v.faltantesDe(dev.lineas[0]), 1);
    assert.equal(v.recibido[10].aptas, 1, 'lo apto nunca supera lo recibido');
    assert.deepEqual(v.totalesRecepcion, { esperadas: 6, recibidas: 5, aptas: 4, novedad: 1, faltantes: 1 });
    assert.equal(v.hayDiferencias, true);

    // Exceso y negativos se acotan; aptas no supera recibidas.
    v.recibido[11].recibidas = 99;
    v.recibido[11].aptas = 99;
    v.acotarRecepcion(dev.lineas[1]);
    assert.deepEqual([v.recibido[11].recibidas, v.recibido[11].aptas], [4, 4]);

    // C5: toda defectuosa se clasifica (motivo del catalogo; «otro» exige descripcion) y los faltantes llevan motivo (misma regla del servidor).
    v.recibido[11].aptas = 3;
    v.confirmarRecepcion();
    assert.equal(v.error, 'err_defecto');
    assert.equal(v.errorDefecto, 11);
    assert.equal(v.posts.length, 0);

    v.recibido[11].defecto_motivo = 'otro';
    assert.equal(v.detalleDefectoRequerido(dev.lineas[1]), true, '«otro» exige descripcion');
    v.confirmarRecepcion();
    assert.equal(v.error, 'err_defecto');

    v.recibido[11].defecto_motivo = 'mancha';
    v.recibido[11].defecto_origen = 'transporte';
    assert.equal(v.detalleDefectoRequerido(dev.lineas[1]), false);
    v.confirmarRecepcion();
    assert.equal(v.error, 'err_motivo_linea', 'el faltante sigue exigiendo su motivo');
    assert.equal(v.errorLinea, 10);
    assert.equal(v.posts.length, 0);
    assert.equal(confirmaciones.length, 0, 'no pregunta mientras falte algo');

    v.recibido[10].motivo = 'Llego 1 de 2';
    v.recibido[11].motivo = 'Una manchada';
    v.confirmarRecepcion();

    // Abrir el modal NO escribe nada; el resumen es el REAL (aptas que vuelven, con novedad que NO vuelven, faltantes).
    assert.equal(v.posts.length, 0, 'abrir la confirmacion no envia nada');
    assert.equal(confirmaciones.length, 1);
    assert.equal(confirmaciones[0].title, 'Confirmar recepcion');
    assert.match(confirmaciones[0].message, /Recibidas 5 de 6/);
    assert.match(confirmaciones[0].message, /4 aptas a Bodega Bogota vendible/);
    assert.match(confirmaciones[0].message, /1 con novedad NO vuelven/);
    assert.match(confirmaciones[0].message, /1 faltantes siguen sin recibir/);
    assert.deepEqual(confirmaciones[0].options, { btnDisagree: 'Cancelar', btnAgree: 'Confirmar recepcion' });

    // Cancelar el modal (no llamar a `agree`) deja todo como estaba: sin POST ni cambios.
    assert.equal(v.posts.length, 0, 'si Bodega cancela la confirmacion no se envia nada');

    const clave = v.clave;
    confirmaciones[0].agree();
    const p = v.posts[0];
    assert.equal(p.url, '/recibir/7');
    assert.deepEqual(p.datos.lineas, {
        10: { recibidas: 1, aptas: 1, novedad: 0, motivo: 'Llego 1 de 2', defectos: [] },
        11: { recibidas: 4, aptas: 3, novedad: 1, motivo: 'Una manchada', defectos: [{ cantidad: 1, motivo: 'mancha', origen: 'transporte', detalle: '', observacion: 'Una manchada' }] },
    });
    assert.equal(p.datos.idempotency_key, clave);

    // «Marcar todo recibido y apto» restablece la propuesta sin perder los motivos escritos.
    v.marcarTodoApto();
    assert.deepEqual(v.totalesRecepcion, { esperadas: 6, recibidas: 6, aptas: 6, novedad: 0, faltantes: 0 });
    assert.equal(v.recibido[10].motivo, 'Llego 1 de 2');
}

// ---- 4. anulaciones y banners ----
{
    const conRecepcion = devolucion('reintegrada', { recepcion: { id: 3, anulable: false } });
    const sinReingreso = devolucion('recibida_novedades', { id: 8, recepcion: { id: 4, anulable: true } });
    const v = instancia(contexto({ devoluciones: [conRecepcion, sinReingreso, devolucion('requiere_revision', { id: 9 })], permisos: { registrar: true, anular: true, recibir: true } }));

    assert.equal(v.puedeAnular({ tipo: 'recepcion', id: 3 }), false, 'una recepcion ya reintegrada no se anula');
    assert.equal(v.puedeAnular({ tipo: 'recepcion', id: 4 }), true);
    assert.equal(v.puedeAnular({ tipo: 'devolucion', id: 7 }), false, 'una devolucion con recepcion no se anula');
    assert.equal(v.puedeAnular({ tipo: 'entrega', id: 1 }), true);
    assert.equal(v.devolucionesRevision.length, 1);
    assert.equal(v.devolucionesPendientes.length, 0);

    v.abrirAnular({ tipo: 'recepcion', id: 4 });
    v.confirmarAnular();
    assert.equal(v.posts[0].url, '/ar/4');
    assert.deepEqual(v.posts[0].datos, { motivo: '' });
}

// ---- 5. delegacion de clics: abre el drawer con el envio y el modo del boton ----
{
    const v = instancia(contexto());
    let abierto = null;
    v.abrir = (id, modo) => { abierto = [id, modo]; };
    let prevenido = false;
    const boton = { dataset: { envio: '41', modo: 'devolver' } };

    v.alClic({ target: { closest: (s) => (s === '[data-logistica-entregas]' ? boton : null) }, preventDefault: () => { prevenido = true; } });
    assert.deepEqual(abierto, [41, 'devolver']);
    assert.equal(prevenido, true);

    abierto = null;
    v.alClic({ target: { closest: () => null }, preventDefault: () => { prevenido = 'no debia'; } });
    assert.equal(abierto, null, 'clics ajenos no abren nada');
}

// ---- 6. validar entrega y cobro contra entrega (cierre) ----
{
    const cierre = (extra = {}) => ({ pedido_estado: 'pending_payment', factura_estado: 'pending', cerrado: false, cobro: { disponible: true, motivo: null, total: 300000, saldo: 300000, sin_revisar: 0, verificado: 0, medios: [{ codigo: 'efectivo', etiqueta: 'Efectivo', referencia: 'opcional' }, { codigo: 'contraentrega_recaudo', etiqueta: 'Recaudo', referencia: 'opcional' }, { codigo: 'banco', etiqueta: 'Banco', referencia: 'requerida' }], ...extra } });
    const lista = (extra = {}) => contexto({ resumen: { despachadas: 8, entregadas: 8, devueltas: 0, pendientes: 0 }, validacion: 'pendiente_confirmar', lista_para_validar: true, validada: null, cierre: cierre(), permisos: { registrar: true, anular: false, recibir: true, validar: true }, ...extra });

    // Entrar a validar solo con permiso y entrega lista; si no, cae a «Ver».
    let v = instancia(lista());
    v.entrar('validar');
    assert.equal(v.modo, 'validar');

    v = instancia(lista({ permisos: { registrar: true, anular: false, recibir: true, validar: false } }));
    v.entrar('validar');
    assert.equal(v.modo, 'ver', 'sin permiso no se abre validar');

    v = instancia(lista({ lista_para_validar: false }));
    v.entrar('validar');
    assert.equal(v.modo, 'ver', 'una entrega incompleta no se valida');

    // Confirmar: una sola peticion con la clave del modo; una segunda llamada mientras envia se ignora.
    v = instancia(lista());
    v.urlValidar = '/validar/__ID__';
    v.entrar('validar');
    v.form.observacion = 'Confirmado';
    v.confirmarValidacion();
    assert.equal(v.posts.length, 1);
    assert.equal(v.posts[0].url, '/validar/41');
    assert.deepEqual(v.posts[0].datos, { idempotency_key: v.clave, observacion: 'Confirmado' });
    v.enviando = true;
    v.confirmarValidacion();
    assert.equal(v.posts.length, 1, 'doble clic no duplica');

    // Cobro: monto por defecto = saldo, medio recaudo contra entrega, validaciones previas al servidor.
    v = instancia(lista());
    v.urlCobrar = '/cobrar/__ID__';
    v.entrar('cobrar');
    assert.equal(v.modo, 'cobrar');
    assert.equal(v.cobro.monto, '300000');
    assert.equal(v.cobro.medio_cobro, 'contraentrega_recaudo');

    v.cobro.monto = '400000';
    v.confirmarCobro();
    assert.equal(v.posts.length, 0, 'un monto mayor al saldo ni se envia');
    assert.ok(v.error);

    v.cobro.monto = '0';
    v.confirmarCobro();
    assert.equal(v.posts.length, 0);

    v.cobro.medio_cobro = 'banco';
    v.cobro.monto = '300000';
    v.confirmarCobro();
    assert.equal(v.posts.length, 0, 'el medio con referencia requerida exige referencia');

    v.cobro.referencia = 'REF-1';
    v.confirmarCobro();
    assert.equal(v.posts.length, 1);
    assert.equal(v.posts[0].url, '/cobrar/41');
    assert.equal(v.posts[0].datos.monto, '300000');
    assert.equal(v.posts[0].datos.medio_cobro, 'banco');

    // Sin cobro disponible no se entra a cobrar.
    v = instancia(lista({ cierre: cierre({ disponible: false, motivo: 'sin_saldo', saldo: 0 }) }));
    v.entrar('cobrar');
    assert.equal(v.modo, 'ver');

    // Con la entrega validada nada de lo que la sostiene se anula; la validacion si.
    v = instancia(lista({ validada: { id: 5, actor: 'Camilo' }, permisos: { registrar: true, anular: true, recibir: true, validar: true } }));
    assert.equal(v.puedeAnular({ tipo: 'entrega', id: 1 }), false);
    assert.equal(v.puedeAnular({ tipo: 'validacion', id: 5 }), true);
    assert.equal(v.motivoNoAnulable({ tipo: 'entrega', id: 1 }), 'anular_validada_bloqueada');
}

console.log('JS-OK');
