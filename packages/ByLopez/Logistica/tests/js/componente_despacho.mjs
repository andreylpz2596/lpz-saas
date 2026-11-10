// Comportamiento REAL del JavaScript del drawer «Despachar» (sin navegador ni Vue): se extrae el <script type="module"> del
// Blade, se registra el componente contra un `app` falso y se ejercitan sus metodos y propiedades calculadas sobre un
// `this` simulado. Lo invoca Log04CantidadesYFleteTest. Sale con codigo != 0 si algo falla.
import assert from 'node:assert/strict';
import fs from 'node:fs';

const ruta = process.argv[2];
const fuente = fs.readFileSync(ruta, 'utf8');
const js = fuente.split('<script type="module">')[1].split('</script>')[0];

let def = null;
const app = { component: (_nombre, definicion) => { def = definicion; } };
const almacen = {};

globalThis.window = {
    localStorage: {
        getItem: (k) => (k in almacen ? almacen[k] : null),
        setItem: (k, v) => { almacen[k] = String(v); },
        removeItem: (k) => { delete almacen[k]; },
    },
    crypto: { randomUUID: () => 'uuid-de-prueba' },
    // Formato de importes (parcial moneda): solo presentacion, no es objeto de esta prueba.
    ByLopezMoneda: { precio: (n) => '$ ' + n },
};

new Function('app', 'window', js)(app, globalThis.window);
assert.ok(def, 'el componente se registro');

/** Instancia simulada: metodos, estado inicial (data) y propiedades calculadas como getters. */
function instancia() {
    const vm = { $nextTick: (f) => f(), $refs: {}, textos: { modal: {} }, nombres: {}, mensajes: [], cerrado: false, refrescos: [] };
    Object.assign(vm, def.methods);
    Object.assign(vm, def.data.call(vm));
    for (const [nombre, fn] of Object.entries(def.computed)) {
        Object.defineProperty(vm, nombre, { get: () => fn.call(vm), configurable: true });
    }
    vm.$refs.drawer = { close: () => { vm.cerrado = true; } };
    vm.$emitter = { emit: (_e, m) => vm.mensajes.push(m) };
    vm.refrescar = (id, avanzar) => vm.refrescos.push([id, avanzar]);

    return vm;
}

function contexto(lineas, extra = {}) {
    return {
        modo: 'despachar',
        pedido: { es_contraentrega: false },
        transportadoras: [{ codigo: 'BYLOPEZ', nombre: 'ByLopez' }, { codigo: 'INTER_RAPIDISIMO', nombre: 'Inter Rapidísimo' }],
        pagadores: ['cliente_al_recibir', 'bylopez', 'incluido_en_venta', 'otro'],
        origenes: ['manual', 'directa_transportadora', '99envios'],
        sugerencias: { transportadora: null, flete_pagador: 'cliente_al_recibir' },
        envio: { condicion: 'por_pagar', pagador_deducido: 'cliente_al_recibir', zona_auto: null, zonas: [], tarifas: {}, pago_pendiente: { bloquea: false, sin_revisar: 0 } },
        recaudo_sugerido: 0,
        unidades: {
            pendientes_total: lineas.reduce((s, l) => s + l.pendientes, 0), despachadas: 0, entregadas: 0,
            lineas: lineas.map((l, i) => ({ id: i + 1, sku: 'SKU' + (i + 1), nombre: 'Prenda', ...l })),
        },
        ...extra,
    };
}

// ---- 1. Pedido 2, stock 20: precarga 2 (el pendiente), maximo 2 aunque haya 20 fisicas ----
{
    const vm = instancia();
    vm.preparar(contexto([{ pendientes: 2, disponibles: 20, maximo: 2 }]));

    assert.equal(vm.cantidades[1], 2, 'la cantidad inicial es la pendiente, no 0');
    assert.equal(vm.totalSeleccionado, 2);
    assert.equal(vm.quedanPendientes, 0);
    assert.equal(vm.sinStockTotal, false);

    const l = vm.ctx.unidades.lineas[0];
    vm.cantidades[1] = 3; // intenta superar el maximo (hay 20 en bodega, pero solo 2 pendientes)
    assert.equal(vm.cantidadDe(l), 2, 'no se puede superar el pendiente aunque haya mas stock');
    vm.clamparCantidad(l);
    assert.equal(vm.cantidades[1], 2);
    vm.cantidades[1] = -4;
    assert.equal(vm.cantidadDe(l), 0);
    vm.cantidades[1] = 'abc';
    assert.equal(vm.cantidadDe(l), 0);
    vm.cantidades[1] = ' 1 ';
    assert.equal(vm.cantidadDe(l), 1);
    vm.cantidades[1] = 999; // manipulacion del input: lo enviado siempre sale acotado
    assert.deepEqual(vm.unidadesAEnviar(), { 1: 2 });
}

// ---- 2. Despachos previos: pedido 2 con 1 despachado → 1; pedido 10 con 4 despachados → 6 ----
{
    const a = instancia();
    a.preparar(contexto([{ pendientes: 1, disponibles: 9, maximo: 1 }]));
    assert.equal(a.cantidades[1], 1);

    const b = instancia();
    b.preparar(contexto([{ pendientes: 6, disponibles: 40, maximo: 6 }]));
    assert.equal(b.cantidades[1], 6);
    assert.equal(b.totalSeleccionado, 6);
}

// ---- 3. Stock insuficiente: se precarga lo que hay y es un despacho PARCIAL ----
{
    const vm = instancia();
    vm.preparar(contexto([{ pendientes: 5, disponibles: 3, maximo: 3 }, { pendientes: 2, disponibles: 9, maximo: 2 }]));

    assert.deepEqual(vm.cantidades, { 1: 3, 2: 2 });
    assert.equal(vm.totalSeleccionado, 5);
    assert.equal(vm.quedanPendientes, 2, 'quedan 2 pendientes: despacho parcial');
    assert.equal(vm.sinStockTotal, false);
    assert.deepEqual(vm.unidadesAEnviar(), { 1: 3, 2: 2 });
}

// ---- 4. Stock 0: cantidad 0, linea bloqueada, "sin stock" y nada que despachar ----
{
    const vm = instancia();
    vm.preparar(contexto([{ pendientes: 4, disponibles: 0, maximo: 0 }]));

    assert.equal(vm.cantidades[1], 0);
    assert.equal(vm.totalSeleccionado, 0, 'el boton queda bloqueado (total 0)');
    assert.equal(vm.sinStockTotal, true);

    vm.cantidades[1] = 3;
    assert.equal(vm.cantidadDe(vm.ctx.unidades.lineas[0]), 0, 'no se puede forzar una cantidad en una linea sin stock');
    assert.deepEqual(vm.unidadesAEnviar(), { 1: 0 });

    // Una linea sin stock y otra con stock: solo la segunda se despacha.
    const mixto = instancia();
    mixto.preparar(contexto([{ pendientes: 4, disponibles: 0, maximo: 0 }, { pendientes: 2, disponibles: 5, maximo: 2 }]));
    assert.equal(mixto.sinStockTotal, false);
    assert.deepEqual(mixto.unidadesAEnviar(), { 1: 0, 2: 2 });
}

// ---- 5. Quien paga el flete: arranca en el pagador DEDUCIDO por el servidor (aqui «por pagar» → Cliente, al recibir); una excepcion previa no contamina ----
{
    // Version anterior del drawer guardaba el pagador: aqui queda ByLopez recordado.
    almacen['logistica.pagador'] = 'bylopez';
    almacen['logistica.transportadora'] = 'BYLOPEZ';
    almacen['logistica.origen'] = '99envios';

    const vm = instancia();
    vm.pedidoId = 10;
    vm.preparar(contexto([{ pendientes: 1, disponibles: 1, maximo: 1 }]));

    assert.equal(vm.form.flete_pagador, 'cliente_al_recibir', 'el valor inicial NO es el recordado');
    assert.equal(almacen['logistica.pagador'], undefined, 'se olvida lo que guardaban versiones previas');
    // Lo demas que se recuerda (transportadora y origen) sigue funcionando.
    assert.equal(vm.form.transportadora, 'BYLOPEZ');
    assert.equal(vm.form.origen_guia, '99envios');

    // Excepcion de ESTE despacho: ByLopez paga. Se registra y NO se recuerda.
    vm.form.flete_pagador = 'bylopez';
    vm.form.transportadora = 'INTER_RAPIDISIMO';
    vm.alExito({ mensaje: 'ok' });

    assert.equal(vm.cerrado, true);
    assert.equal(almacen['logistica.pagador'], undefined, 'la excepcion no se guarda');
    assert.equal(almacen['logistica.transportadora'], 'INTER_RAPIDISIMO');

    // El siguiente pedido vuelve a empezar en Cliente, al recibir.
    const siguiente = instancia();
    siguiente.preparar(contexto([{ pendientes: 3, disponibles: 3, maximo: 3 }]));
    assert.equal(siguiente.form.flete_pagador, 'cliente_al_recibir');
    assert.equal(siguiente.cantidades[1], 3);
}

// ---- 6. Stock insuficiente: BODEGA solicita; el Administrador regulariza (directo o sobre una solicitud) o rechaza ----
{
    const tick = () => new Promise((r) => setTimeout(r, 0));
    const stockCtx = (stock, lineas = [{ pendientes: 3, disponibles: 0, maximo: 0 }]) => contexto(lineas, { stock });
    const conAxios = (vm, respuestas = {}) => {
        vm.llamadas = [];
        vm.pedidoId = 7;
        vm.urlContexto = '/ctx/__ID__';
        vm.urlStockSolicitar = '/sol/__ID__';
        vm.urlStockDirecta = '/dir/__ID__';
        vm.urlIncidencia = '/inc/__ID__';
        vm.urlIncidenciaRegularizar = '/inc/__ID__/reg';
        vm.urlIncidenciaRechazar = '/inc/__ID__/rech';
        vm.textos = { modal: {}, stock: { 'enviar-solicitud': 'Enviar', aplicar: 'Aplicar', 'confirmar-rechazo': 'Rechazo' } };
        vm.$axios = {
            get: (u) => { vm.llamadas.push(['get', u]); return Promise.resolve({ data: respuestas[u] || { mensaje: 'ok' } }); },
            post: (u, d) => { vm.llamadas.push(['post', u, d]); return Promise.resolve({ data: { mensaje: 'hecho' } }); },
        };

        return vm;
    };
    const faltante = [{ id: 1, sku: 'SKU1', nombre: 'Prenda', necesaria: 3, stock_sistema: 0 }];

    // 6a. BODEGA: el input sigue bloqueado en 0; solicitar exige nota y conteo; no manda nada si falta algo.
    const b = conAxios(instancia());
    b.preparar(stockCtx({ hay_faltante: true, lineas: faltante, abierta: null, ultima: null, puede_solicitar: true, puede_regularizar: false }));

    assert.equal(b.cantidades[1], 0, 'con stock 0 la cantidad sigue en 0');
    assert.equal(b.sinStockTotal, true);
    b.abrirStock('solicitar');
    assert.equal(b.stockListo, false);
    b.stockForm.conteos[1] = 3;
    assert.equal(b.stockListo, false, 'falta la nota');
    b.stockForm.nota = 'Estan en el estante B';
    assert.equal(b.stockListo, true);
    assert.equal(b.textoBotonStock, 'Enviar');
    b.enviarStock();
    assert.deepEqual(b.llamadas[0], ['post', '/sol/7', { idempotency_key: b.stockForm.clave, conteos: { 1: 3 }, nota: 'Estan en el estante B' }]);
    assert.ok(b.stockForm.enviando);
    await tick();
    assert.equal(b.stockForm.abierto, false, 'tras enviar se cierra el formulario');
    assert.equal(b.mensajes.at(-1).message, 'hecho');

    // 6b. Administrador SIN solicitud: regularizar directo exige conteo, motivo y confirmacion; recalcula sin perder la guia escrita.
    const a = conAxios(instancia(), {
        '/ctx/7': { contexto: contexto([{ pendientes: 3, disponibles: 3, maximo: 3 }], { stock: { hay_faltante: false, lineas: [], abierta: null, ultima: null, puede_solicitar: false, puede_regularizar: false } }) },
    });
    a.preparar(stockCtx({ hay_faltante: true, lineas: faltante, abierta: null, ultima: null, puede_solicitar: false, puede_regularizar: true }));
    a.form.numero_guia = 'G-123';
    a.abrirStock('regularizar');
    a.stockForm.conteos[1] = 3;
    a.stockForm.motivo = 'Conteo fisico';
    assert.equal(a.stockListo, false, 'falta confirmar el conteo fisico');
    a.stockForm.confirma = true;
    assert.equal(a.stockListo, true);
    a.enviarStock();
    assert.deepEqual(a.llamadas[0], ['post', '/dir/7', { idempotency_key: a.stockForm.clave, conteos: { 1: 3 }, motivo: 'Conteo fisico', confirma_conteo_fisico: 1 }]);
    await tick();
    await tick();
    assert.equal(a.cantidades[1], 3, 'el drawer recalcula el maximo tras regularizar');
    assert.equal(a.form.numero_guia, 'G-123', 'no se pierde lo ya escrito');

    // 6c. Administrador sobre una solicitud abierta: trae el detalle (stock en vivo), parte del conteo declarado y puede rechazar.
    const abierta = { id: 9, solicitante: 'Bodega', fecha: '2026-10-09 10:00', nota: 'x', lineas: [{ id: 21, order_item_id: 1, sku: 'SKU1', conteo_declarado: 3 }] };
    const r = conAxios(instancia(), {
        '/inc/9': { incidencia: { lineas: [{ id: 21, sku: 'SKU1', necesaria: 3, stock_vivo: 0, conteo_declarado: 3, movimientos: [], despachos_recientes: [], reservado: 0, otros_pedidos: 0 }] } },
    });
    r.preparar(stockCtx({ hay_faltante: true, lineas: faltante, abierta, ultima: null, puede_solicitar: false, puede_regularizar: true }));
    r.abrirStock('regularizar');
    assert.equal(r.stockForm.conteos[21], 3, 'parte del conteo declarado por la bodega');
    await tick();
    assert.equal(r.stockFilas[0].vivo, 0, 'muestra el stock en vivo');
    r.stockForm.rechazando = true;
    assert.equal(r.stockListo, false, 'rechazar exige motivo');
    r.stockForm.motivo = 'No existen';
    assert.equal(r.stockListo, true);
    assert.equal(r.textoBotonStock, 'Rechazo');
    r.enviarStock();
    assert.deepEqual(r.llamadas.at(-1), ['post', '/inc/9/rech', { motivo: 'No existen' }]);

    // 6d. Resolver la solicitud abierta usa SU endpoint, con los conteos por linea de la incidencia.
    const s = conAxios(instancia(), { '/inc/9': { incidencia: { lineas: [] } } });
    s.preparar(stockCtx({ hay_faltante: true, lineas: faltante, abierta, ultima: null, puede_solicitar: false, puede_regularizar: true }));
    s.abrirStock('regularizar');
    s.stockForm.motivo = 'Verificado';
    s.stockForm.confirma = true;
    s.enviarStock();
    assert.deepEqual(s.llamadas.at(-1), ['post', '/inc/9/reg', { conteos: { 21: 3 }, motivo: 'Verificado', confirma_conteo_fisico: 1 }]);
}

// ---- 7. Domicilios locales F1: condicion deducida, zona, tarifa + adicional, motivos, capacidades y pago pendiente ----
{
    const envio = (extra = {}) => ({
        condicion: 'gratis', pagador_deducido: 'bylopez',
        zona_auto: { id: 1, nombre: 'Bogotá' },
        zonas: [{ id: 1, nombre: 'Bogotá' }, { id: 2, nombre: 'Soacha' }],
        tarifas: { JEISON: { 1: 10000, 2: 12000 } },
        pago_pendiente: { bloquea: false, sin_revisar: 0 },
        ...extra,
    });
    const transportadoras = [
        { codigo: 'JEISON', nombre: 'Jeison', tipo: 'domiciliario', liquida_cuenta_corriente: true, genera_guia_interna: true },
        { codigo: 'PICAP', nombre: 'PICAP', tipo: 'plataforma', liquida_cuenta_corriente: false, genera_guia_interna: true },
        { codigo: 'INTER_RAPIDISIMO', nombre: 'Inter', tipo: 'transportadora', liquida_cuenta_corriente: false, genera_guia_interna: false },
    ];
    const textos = { modal: {
        'pago-pendiente-titulo': 'PENDIENTE', 'error-pagador-requerido': 'PAGADOR', 'error-motivo-pagador': 'MOTIVO-PAGADOR',
        'error-costo-requerido': 'COSTO', 'error-motivo-costo': 'MOTIVO-COSTO',
    } };
    const ctx7 = (extra = {}) => contexto([{ pendientes: 1, disponibles: 1, maximo: 1 }], {
        transportadoras, sugerencias: { transportadora: null, flete_pagador: (extra.envio || envio()).pagador_deducido }, envio: envio(), ...extra,
    });

    // 7a. Envio gratuito + Jeison en Bogota: pagador ByLopez deducido, tarifa 10.000, costo final 10.000, sin motivos.
    const a = instancia();
    a.textos = textos;
    a.preparar(ctx7());
    a.form.transportadora = 'JEISON';
    a.aplicarTarifa();
    assert.equal(a.form.flete_pagador, 'bylopez', 'gratis → ByLopez, nunca «Cliente, al recibir» por defecto');
    assert.equal(a.mostrarPagador, false, 'el pagador deducido no se pregunta');
    assert.equal(a.tarifaSugerida, 10000);
    assert.equal(a.costoFinal, 10000);
    assert.equal(a.difiereTarifa, false);
    assert.equal(a.guiaInterna, true, 'capacidad, no nombre');
    assert.equal(a.exigeCosto, true);
    assert.equal(a.validarEnvio(), null);

    // 7b. Adicional de 5.000 → costo final 15.000 y motivo obligatorio.
    a.form.costo_adicional = '5000';
    assert.equal(a.costoFinal, 15000);
    assert.equal(a.difiereTarifa, true);
    assert.equal(a.validarEnvio(), 'MOTIVO-COSTO');
    a.form.motivo_costo = 'Pedido de alto volumen';
    assert.equal(a.validarEnvio(), null);

    // 7c. Cambiar el pagador deducido exige motivo.
    a.cambiarPagador = true;
    a.form.flete_pagador = 'cliente_al_recibir';
    assert.equal(a.pagadorCambiado, true);
    assert.equal(a.validarEnvio(), 'MOTIVO-PAGADOR');

    // 7d. Lo que se envia: costo FINAL, sin zona manual (hay automatica) y los motivos solo cuando aplican.
    const enviado = {};
    a.form.motivo_pagador = 'El cliente pidio pagarlo';
    a.pedidoId = 3;
    a.clave = 'k-1';
    a.urlDespachar = '/d/__ID__';
    a.$axios = { post: (url, datos) => { for (const [k, v] of datos.entries()) { enviado[k] = v; } return { then: () => ({ catch: () => ({ finally: () => {} }) }) }; } };
    a.enviar();
    assert.equal(enviado.valor_flete, '15000');
    assert.equal(enviado.zona_id, '');
    assert.equal(enviado.motivo_costo, 'Pedido de alto volumen');
    assert.equal(enviado.motivo_pagador, 'El cliente pidio pagarlo');
    assert.equal(enviado.flete_pagador, 'cliente_al_recibir');

    // 7e. Sin zona automatica: la zona se elige a mano y la tarifa sale de esa zona (Soacha 12.000).
    const b = instancia();
    b.textos = textos;
    b.preparar(ctx7({ envio: envio({ zona_auto: null }) }));
    b.form.transportadora = 'JEISON';
    assert.equal(b.tarifaSugerida, null, 'sin zona no hay tarifa: no se adivina');
    b.form.zona_id = '2';
    b.aplicarTarifa();
    assert.equal(b.tarifaSugerida, 12000);
    assert.equal(b.costoFinal, 12000);

    // 7f. Metodo desconocido: el pagador no se deduce, se elige y lleva motivo.
    const c = instancia();
    c.textos = textos;
    c.preparar(ctx7({ envio: envio({ condicion: 'sin_definir', pagador_deducido: null }) }));
    c.form.transportadora = 'JEISON';
    assert.equal(c.form.flete_pagador, '', 'no se adivina el pagador');
    assert.equal(c.mostrarPagador, true);
    assert.equal(c.validarEnvio(), 'PAGADOR');
    c.form.flete_pagador = 'bylopez';
    assert.equal(c.validarEnvio(), 'MOTIVO-PAGADOR');

    // 7g. PICAP (plataforma sin cuenta corriente): sin tarifa ni costo obligatorio; nacional: flujo de guia externa intacto.
    const d = instancia();
    d.textos = textos;
    d.preparar(ctx7());
    d.form.transportadora = 'PICAP';
    assert.equal(d.exigeCosto, false);
    assert.equal(d.tarifaSugerida, null);
    assert.equal(d.validarEnvio(), null);
    d.form.transportadora = 'INTER_RAPIDISIMO';
    assert.equal(d.guiaInterna, false, 'la nacional conserva numero, origen, tracking y PDF');

    // 7h. Pago EN VALIDACION (aunque sea parcial): no se despacha.
    const e = instancia();
    e.textos = textos;
    e.preparar(ctx7({ envio: envio({ pago_pendiente: { bloquea: true, sin_revisar: 20000, saldo: 31000, estado: 'en_validacion' } }) }));
    e.form.transportadora = 'JEISON';
    assert.equal(e.bloqueoPago, true);
    assert.equal(e.validarEnvio(), 'PENDIENTE');
    assert.equal(e.estadoPago, 'en_validacion');

    // 7i. Saldo pendiente sin pagos en revision: NO bloquea (la deuda se muestra, no impide entregar); pagado tampoco.
    const f = instancia();
    f.textos = textos;
    f.preparar(ctx7({ envio: envio({ condicion: 'recogida', pagador_deducido: null, pago_pendiente: { bloquea: false, sin_revisar: 0, saldo: 51000, estado: 'pendiente' } }) }));
    assert.equal(f.bloqueoPago, false, 'deber dinero no bloquea');
    assert.equal(f.estadoPago, 'pendiente');
    f.preparar(ctx7({ envio: envio({ pago_pendiente: { bloquea: false, sin_revisar: 0, saldo: 0, estado: 'pagado' } }) }));
    assert.equal(f.bloqueoPago, false);
    assert.equal(f.estadoPago, 'pagado');
    f.preparar(ctx7({ envio: envio({ pago_pendiente: { bloquea: false, sin_revisar: 0, saldo: null, estado: null } }) }));
    assert.equal(f.estadoPago, null, 'fuera de Cartera: no se muestra estado');
}

// ---- 8. Ultimo ajuste UX de F1: domiciliario sugerido por zona, UNA instruccion de cobro, costo y observacion plegados ----
{
    const transportadoras = [
        { codigo: 'JEISON', nombre: 'Jeison', tipo: 'domiciliario', liquida_cuenta_corriente: true, genera_guia_interna: true },
        { codigo: 'BRIAN', nombre: 'Brian', tipo: 'domiciliario', liquida_cuenta_corriente: true, genera_guia_interna: true },
        { codigo: 'INTER_RAPIDISIMO', nombre: 'Inter', tipo: 'transportadora', liquida_cuenta_corriente: false, genera_guia_interna: false },
    ];
    const envio = (extra = {}) => ({
        condicion: 'gratis', pagador_deducido: 'bylopez', zona_auto: null,
        zonas: [{ id: 1, nombre: 'Bogotá' }, { id: 2, nombre: 'Soacha' }],
        tarifas: { JEISON: { 1: 10000, 2: 12000 }, BRIAN: { 1: 12000, 2: 12000 } },
        pago_pendiente: { bloquea: false, sin_revisar: 0, saldo: 0, estado: 'pagado' },
        admite_domicilio_local: true, elegido_venta: null, ultimo_por_zona: { 1: 'JEISON', 2: 'BRIAN' },
        ...extra,
    });
    const ctx8 = (e, extra = {}) => contexto([{ pendientes: 1, disponibles: 1, maximo: 1 }], {
        transportadoras, sugerencias: { transportadora: e.elegido_venta || (e.zona_auto ? (e.ultimo_por_zona[e.zona_auto.id] || null) : null), flete_pagador: e.pagador_deducido }, envio: e, ...extra,
    });

    // 8a. Bogota automatica: Jeison preseleccionado (sugerencia) con su tarifa; el adicional no aparece en el flujo normal.
    const a = instancia();
    a.preparar(ctx8(envio({ zona_auto: { id: 1, nombre: 'Bogotá' } })));
    assert.equal(a.form.transportadora, 'JEISON');
    assert.equal(a.esSugerido, true);
    assert.equal(a.zonaNombre, 'Bogotá');
    assert.equal(a.costoFinal, 10000);
    assert.equal(a.mostrarAjusteCosto, false, 'costo adicional oculto hasta «Ajustar costo»');
    a.ajustarCosto = true;
    assert.equal(a.mostrarAjusteCosto, true);

    // 8b. Un clic cambia a Brian: deja de ser «sugerido» y queda fijo.
    a.form.transportadora = 'BRIAN';
    a.elegirTransportadora();
    assert.equal(a.esSugerido, false);
    assert.equal(a.transportadoraElegida, true);
    assert.equal(a.costoFinal, 12000);
    assert.equal(a.ajustarCosto, false, 'cambiar de transportadora vuelve a la tarifa sin adicional');

    // 8c. Zona elegida a mano: la sugerencia se recalcula por zona (Soacha → Brian, Bogota → Jeison) mientras no se elija a mano.
    const b = instancia();
    b.preparar(ctx8(envio()));
    assert.equal(b.form.transportadora, '', 'sin zona: sin seleccionar');
    b.form.zona_id = '2';
    b.cambiarZona();
    assert.equal(b.form.transportadora, 'BRIAN');
    b.form.zona_id = '1';
    b.cambiarZona();
    assert.equal(b.form.transportadora, 'JEISON');
    b.form.transportadora = 'BRIAN';
    b.elegirTransportadora();
    b.form.zona_id = '2';
    b.cambiarZona();
    assert.equal(b.form.transportadora, 'BRIAN', 'la eleccion manual no se pisa al cambiar de zona');

    // 8d. Zona sin historial: queda sin seleccionar. La memoria local del navegador NUNCA sugiere un domiciliario.
    window.localStorage.setItem('logistica.transportadora', 'JEISON');
    const c = instancia();
    c.preparar(ctx8(envio({ zona_auto: { id: 2, nombre: 'Soacha' }, ultimo_por_zona: {} })));
    assert.equal(c.form.transportadora, '');
    // ... pero un envio nacional si recuerda su transportadora externa.
    window.localStorage.setItem('logistica.transportadora', 'INTER_RAPIDISIMO');
    const d = instancia();
    d.preparar(ctx8(envio({ admite_domicilio_local: false, ultimo_por_zona: {} })));
    assert.equal(d.form.transportadora, 'INTER_RAPIDISIMO');
    window.localStorage.removeItem('logistica.transportadora');

    // 8e. Instruccion de cobro: contra entrega 51.000 + domicilio por pagar 10.000; el saldo de Cartera no entra.
    const e = instancia();
    // Desde C1 el cobro de la guia sale de `recaudo_guia` (despacho unico sin guias previas: todo el saldo exigible).
    e.preparar(ctx8(envio({ condicion: 'por_pagar', pagador_deducido: 'cliente_al_recibir', zona_auto: { id: 1, nombre: 'Bogotá' } }), {
        pedido: { es_contraentrega: true }, recaudo_sugerido: 51000,
        recaudo_guia: { contra_entrega: true, exigible: 51000, comprometido: 0, disponible: 51000, hay_previas: false, flete: 0, unitarios: {} },
    }));
    assert.equal(e.cobroPedido, 51000);
    assert.equal(e.cobroDomicilio, 10000);
    assert.equal(e.admiteRecaudo, true, '«Corregir» disponible');
    assert.equal(e.corregirCobro, false, 'sin input abierto en el flujo normal');

    // 8f. Transferencia con saldo pendiente en Cartera: estado «pendiente» visible, pero NO hay nada que cobrar.
    const f = instancia();
    f.preparar(ctx8(envio({ zona_auto: { id: 1, nombre: 'Bogotá' }, pago_pendiente: { bloquea: false, sin_revisar: 0, saldo: 51000, estado: 'pendiente' } })));
    assert.equal(f.estadoPago, 'pendiente');
    assert.equal(f.cobroPedido, 0, 'deuda en Cartera ≠ cobrar al entregar');
    assert.equal(f.cobroDomicilio, 0);
    assert.equal(f.admiteRecaudo, false);

    // 8g. Pago en validacion sin recaudo: aviso, no bloquea. Con recaudo lo decide el servidor (bloquea).
    f.preparar(ctx8(envio({ zona_auto: { id: 1, nombre: 'Bogotá' }, pago_pendiente: { bloquea: false, sin_revisar: 51000, saldo: 0, estado: 'en_validacion' } })));
    assert.equal(f.estadoPago, 'en_validacion');
    assert.equal(f.bloqueoPago, false);
    assert.equal(f.validarEnvio(), null);

    // 8h. Al abrir otro pedido se reinician las excepciones plegadas.
    f.ajustarCosto = true; f.corregirCobro = true; f.verObservacion = true; f.transportadoraElegida = true;
    f.$refs.drawer.open = () => {};
    f.urlContexto = '/c/__ID__';
    f.$axios = { get: () => ({ then: () => ({ catch: () => ({ finally: () => {} }) }) }) };
    f.abrir(9, false);
    assert.deepEqual([f.ajustarCosto, f.corregirCobro, f.verObservacion, f.transportadoraElegida], [false, false, false, false]);
    // 8i. Elegido por el asesor en la venta: gana al historial, se rotula distinto y no lo pisa un cambio de zona.
    const g = instancia();
    g.preparar(ctx8(envio({ zona_auto: { id: 1, nombre: 'Bogotá' }, elegido_venta: 'BRIAN' })));
    assert.equal(g.form.transportadora, 'BRIAN', 'la eleccion del asesor llega preseleccionada');
    assert.equal(g.esElegidoVenta, true);
    g.form.transportadora = 'JEISON';
    g.elegirTransportadora();
    assert.equal(g.esElegidoVenta, false, 'BODEGA puede cambiarla con un clic');
    const h = instancia();
    h.preparar(ctx8(envio({ elegido_venta: 'BRIAN' })));
    h.form.zona_id = '1';
    h.cambiarZona();
    assert.equal(h.form.transportadora, 'BRIAN', 'zona manual: la eleccion de la venta sigue mandando');

    // 8j. Fuera de zona local (free_free a Medellin): sin sugerencia y SIN memoria del navegador.
    window.localStorage.setItem('logistica.transportadora', 'INTER_RAPIDISIMO');
    const m = instancia();
    m.preparar(ctx8(envio({ zona_auto: null, ultimo_por_zona: {} })));
    assert.equal(m.form.transportadora, '');
    window.localStorage.removeItem('logistica.transportadora');
}

// ---- 9. Modal reorganizada: resumen por producto/talla y mensajes claros del PDF (solo presentacion) ----
{
    const v = instancia();
    v.preparar(contexto([
        { producto_id: 7, nombre: 'Camisa Azul', sku: 'CAM-AZ', talla: 'S', pendientes: 2, maximo: 2 },
        { producto_id: 7, nombre: 'Camisa Azul', sku: 'CAM-AZ', talla: 'M', pendientes: 2, maximo: 2 },
        { producto_id: 8, nombre: 'Buso Rojo', sku: 'BUS-RJ', talla: '', pendientes: 3, maximo: 1 },
    ]));
    const r = v.productosResumen;
    assert.deepEqual(r.map(p => [p.nombre, p.filas.map(f => [f.talla, f.cantidad])]), [['Camisa Azul', [['S', 2], ['M', 2]]], ['Buso Rojo', [['', 1]]]], 'agrupa por producto y limita al maximo');
    assert.equal(v.totalSeleccionado, 5);

    // PDF: razon concreta en cada rechazo del navegador (el servidor repite la validacion por contenido).
    v.textos = { modal: { 'error-pdf-tipo': 'Solo se permiten archivos PDF.', 'error-pdf-grande': 'El archivo supera el máximo de 5 MB.', 'error-limite-servidor': 'Límite :limite' } };
    v.t = (txt, p) => txt.replace(/:(\w+)/g, (_m, k) => p[k]);
    v.ctx.limite_subida = { archivo: '2M', cuerpo: '8M' };
    assert.equal(v.limiteServidorBytes(), 2 * 1024 * 1024);
    assert.equal(v.motivoPdfInvalido({ type: 'image/png', name: 'foto.png', size: 10 }), 'Solo se permiten archivos PDF.');
    assert.equal(v.motivoPdfInvalido({ type: '', name: 'guia.PDF', size: 1000 }), null, 'sin tipo declarado pero extension .pdf: pasa al servidor');
    assert.equal(v.motivoPdfInvalido({ type: 'application/pdf', name: 'g.pdf', size: 6 * 1024 * 1024 }), 'El archivo supera el máximo de 5 MB.');
    assert.equal(v.motivoPdfInvalido({ type: 'application/pdf', name: 'g.pdf', size: 3 * 1024 * 1024 }), 'Límite 2M', 'entre el limite del servidor y 5 MB: se explica el limite real');
    assert.equal(v.tamanoLegible(1536 * 1024), '1.5 MB');
    assert.equal(v.tamanoLegible(100), '1 KB');

    const ok = { type: 'application/pdf', name: 'guia.pdf', size: 1000 };
    v.alElegirPdf({ target: { files: [ok], value: 'x' } });
    assert.equal(v.archivo, ok);
    assert.equal(v.errorPdf, null);
    const malo = { target: { files: [{ type: 'text/plain', name: 'a.txt', size: 5 }], value: 'x' } };
    v.alElegirPdf(malo);
    assert.equal(v.archivo, null);
    assert.equal(v.errorPdf, 'Solo se permiten archivos PDF.');
    assert.equal(malo.target.value, '');

    // Rechazo del servidor por contenido: el motivo queda junto al campo, no en el error general.
    v.alFallo({ response: { data: { codigo: 'archivo_no_pdf', mensaje: 'El archivo seleccionado no es un PDF válido.' } } });
    assert.equal(v.errorPdf, 'El archivo seleccionado no es un PDF válido.');
    assert.equal(v.error, null);
    v.alFallo({ response: { data: { codigo: 'stock_insuficiente', mensaje: 'Sin stock' } } });
    assert.equal(v.error, 'Sin stock');
}
// ---- 10. Refinamiento UX: cabecera, resumen de preparacion y errores junto a su campo (misma validacion, mejor presentada) ----
{
    const txt = {
        'cab-unidades': ':n unidades', 'cab-unidad': '1 unidad', 'prep-unidades': ':n unidades listas', 'prep-sin-unidades': 'Sin unidades para despachar',
        'prep-transportadora': 'Transportadora seleccionada', 'prep-falta-transportadora': 'Falta transportadora', 'prep-guia': 'Guía lista',
        'prep-falta-guia': 'Falta el número de guía', 'prep-pdf': 'Guía PDF adjunta', 'prep-sin-pdf': 'Sin PDF (opcional)', 'prep-envio': 'Condición y costo definidos',
        'error-pagador-requerido': 'Indica quién paga el flete.', 'error-costo-requerido': 'Falta el costo.', 'error-pdf-invalido': 'El archivo seleccionado no es un PDF válido.',
        'pago-pendiente-titulo': 'Pago en validación',
    };
    const nueva = (lineas, envioExtra = {}) => {
        const v = instancia();
        v.textos = { modal: txt };
        v.t = (t, p) => t.replace(/:(\w+)/g, (_m, k) => p[k]);
        v.preparar(contexto(lineas, { pedido: { numero: 'BYL-61', ciudad: 'Barrancabermeja', es_contraentrega: false }, envio: { condicion: 'por_pagar', pagador_deducido: 'cliente_al_recibir', zona_auto: null, zonas: [], tarifas: {}, pago_pendiente: { bloquea: false, sin_revisar: 0 }, ...envioExtra } }));

        return v;
    };
    const estados = (v) => Object.fromEntries(v.preparacion.map(p => [p.clave, p.estado]));

    const v = nueva([{ pendientes: 4, maximo: 4 }, { pendientes: 4, maximo: 4 }]);
    assert.equal(v.cabecera, '#BYL-61 · 8 unidades · Barrancabermeja');
    // Sin transportadora ni guia: lo que falta se ve ANTES de presionar el boton.
    assert.deepEqual(estados(v), { unidades: 'ok', transportadora: 'falta', guia: 'falta', pdf: 'neutro', envio: 'ok' });

    v.form.transportadora = 'BYLOPEZ';
    v.form.numero_guia = '  ';
    assert.equal(estados(v).transportadora, 'ok');
    assert.equal(estados(v).guia, 'falta', 'guia en blanco sigue faltando');
    v.form.numero_guia = 'G-1';
    v.archivo = { name: 'g.pdf', size: 10 };
    v.pdfFirma = 'ok';
    assert.deepEqual(estados(v), { unidades: 'ok', transportadora: 'ok', guia: 'ok', pdf: 'ok', envio: 'ok' });

    // El item de envio sale de validarEnvioCampo (misma validacion): sin pagador, falta.
    v.form.flete_pagador = '';
    assert.deepEqual(v.validarEnvioCampo(), { campo: 'flete_pagador', mensaje: 'Indica quién paga el flete.' });
    assert.equal(estados(v).envio, 'falta');
    assert.equal(v.preparacion.find(p => p.clave === 'envio').texto, 'Indica quién paga el flete.');

    // Sin unidades: la cabecera no las nombra y el resumen lo advierte (el boton ya se deshabilitaba por esto).
    v.ajustar = true;
    v.ctx.unidades.lineas.forEach(l => { v.cantidades[l.id] = 0; });
    assert.equal(v.cabecera, '#BYL-61 · Barrancabermeja');
    assert.equal(estados(v).unidades, 'falta');

    // Errores por campo: el del cliente y los del servidor (mapeados por codigo) aparecen junto al campo; corregirlo lo limpia.
    const w = nueva([{ pendientes: 1, maximo: 1 }]);
    w.form.flete_pagador = '';
    w.enviar();
    assert.deepEqual(w.errores, { flete_pagador: 'Indica quién paga el flete.' });
    assert.equal(w.error, 'Indica quién paga el flete.', 'el error general inferior se mantiene');
    w.form.flete_pagador = 'bylopez';
    w.limpiarError('flete_pagador');
    assert.deepEqual(w.errores, {});

    w.alFallo({ response: { data: { codigo: 'transportadora_requerida', mensaje: 'Elige la transportadora del catálogo.' } } });
    assert.deepEqual(w.errores, { transportadora: 'Elige la transportadora del catálogo.' });
    w.alFallo({ response: { data: { codigo: 'guia_requerida', mensaje: 'Escribe el número de guía.' } } });
    assert.deepEqual(w.errores, { numero_guia: 'Escribe el número de guía.' });
    w.alFallo({ response: { data: { codigo: 'flete_requerido', mensaje: 'Falta flete' } } });
    assert.deepEqual(Object.keys(w.errores).sort(), ['costo_adicional', 'valor_flete']);
    w.alFallo({ response: { data: { codigo: 'stock_insuficiente', mensaje: 'Sin stock' } } });
    assert.deepEqual(w.errores, {}, 'un error sin campo no marca ninguno');

    // PDF: la firma se comprueba en el navegador con la misma regla del servidor (BOM/espacios si; otro prefijo no).
    const lecturas = {
        valido: '%PDF-1.4', bom: '\xEF\xBB\xBF%PDF-1.4', espacios: '  \r\n%PDF-1.7', html: '<html>%PDF-</html>', gif: 'GIF89a%PDF-1.4',
        // Descarga con la respuesta HTTP cruda delante: se acepta (el servidor la descarta); sin linea en blanco o con otro estado, no.
        http: 'HTTP/1.0 200 OK\r\nCache-Control: no-cache\r\nContent-Type: application/pdf\r\n\r\n%PDF-1.4',
        http404: 'HTTP/1.0 404 Not Found\r\nContent-Type: application/pdf\r\n\r\n%PDF-1.4',
        httpSinBlanco: 'HTTP/1.0 200 OK\r\nContent-Type: application/pdf\r\n%PDF-1.4',
    };
    for (const [clave, contenido] of Object.entries(lecturas)) {
        globalThis.FileReader = class { readAsBinaryString() { this.result = contenido; this.onload(); } };
        const p = nueva([{ pendientes: 1, maximo: 1 }]);
        const archivo = { type: 'application/pdf', name: clave + '.pdf', size: 100, slice: () => ({}) };
        const campo = { files: [archivo], value: 'x' };

        p.alElegirPdf({ target: campo });

        if (['valido', 'bom', 'espacios', 'http'].includes(clave)) {
            assert.equal(p.archivo, archivo, clave + ' se acepta');
            assert.equal(p.pdfFirma, 'ok');
        } else {
            assert.equal(p.archivo, null, clave + ' se rechaza');
            assert.equal(p.errorPdf, 'El archivo seleccionado no es un PDF válido.');
            assert.equal(campo.value, '');
        }
    }
    delete globalThis.FileReader;
}
console.log('JS-OK');
