<?php

use ByLopez\Logistica\Http\Controllers\Admin\CuentasTransportadoraController;
use ByLopez\Logistica\Http\Controllers\Admin\DespachosController;
use ByLopez\Logistica\Http\Controllers\Admin\EntregasController;
use ByLopez\Logistica\Http\Controllers\Admin\EntregasDrawerController;
use ByLopez\Logistica\Http\Controllers\Admin\GuiasController;
use ByLopez\Logistica\Http\Controllers\Admin\IncidenciasStockController;
use ByLopez\Logistica\Http\Controllers\Admin\MensajesPedidoController;
use ByLopez\Logistica\Http\Controllers\Admin\TransportadorasController;
use ByLopez\Logistica\Http\Controllers\Admin\ZonasLocalesController;
use Illuminate\Support\Facades\Route;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;

/*
 * Rutas del panel de Logistica. Cada una esta mapeada a una clave ACL en Config/acl.php (el Bouncer de Bagisto deja
 * entrar) y su servicio vuelve a validar el permiso (la autorizacion real).
 */
Route::group(['middleware' => ['admin', NoCacheMiddleware::class], 'prefix' => config('app.admin_url').'/logistica'], function () {
    // Despachos: bandeja de trabajo + el contexto y las dos operaciones del modal «Despachar» (el MISMO modal lo usa
    // el Administrador desde el listado de Pedidos).
    Route::get('despachos', [DespachosController::class, 'index'])->name('admin.logistica.despachos.index');

    Route::prefix('despachos/pedidos/{pedido}')->whereNumber('pedido')->group(function () {
        Route::get('contexto', [DespachosController::class, 'contexto'])->name('admin.logistica.despachos.contexto');
        // Mensajes al cliente (WhatsApp, solo lectura): las reglas viven en el contrato MensajesPedidoCliente.
        Route::get('mensajes', [MensajesPedidoController::class, 'opciones'])->name('admin.logistica.despachos.mensajes.opciones');
        Route::get('mensajes/{clave}', [MensajesPedidoController::class, 'mensaje'])->where('clave', '[a-z0-9\-]+')->name('admin.logistica.despachos.mensajes.mensaje');
        Route::post('despachar', [DespachosController::class, 'despachar'])->name('admin.logistica.despachos.despachar');
        Route::post('entregar-en-bodega', [DespachosController::class, 'entregarEnBodega'])->name('admin.logistica.despachos.entregar_bodega');
        // F6: decision de cobro de un pedido que no es contra entrega con dinero sin confirmar.
        Route::post('disposicion-cobro', [DespachosController::class, 'disposicionCobro'])->name('admin.logistica.despachos.disposicion_cobro');

        // Cancelar lo pendiente de despacho (permiso propio; el servicio vuelve a validarlo bajo el bloqueo del pedido).
        Route::post('cancelar-pendiente', [DespachosController::class, 'cancelarPendiente'])->name('admin.logistica.despachos.cancelar_pendiente');

        // Stock insuficiente: BODEGA solicita autorizacion; el Administrador regulariza en una sola operacion.
        Route::post('stock/solicitar', [IncidenciasStockController::class, 'solicitar'])->name('admin.logistica.stock.solicitar');
        Route::post('stock/regularizar', [IncidenciasStockController::class, 'regularizarDirecto'])->name('admin.logistica.stock.directa');
    });

    // Incidencias de stock: revision (JSON) y resolucion por el Administrador.
    Route::prefix('despachos/incidencias/{incidencia}')->whereNumber('incidencia')->group(function () {
        Route::get('/', [IncidenciasStockController::class, 'detalle'])->name('admin.logistica.incidencias.detalle');
        Route::post('regularizar', [IncidenciasStockController::class, 'regularizar'])->name('admin.logistica.incidencias.regularizar');
        Route::post('rechazar', [IncidenciasStockController::class, 'rechazar'])->name('admin.logistica.incidencias.rechazar');
    });

    // PDF de la guia: ver / descargar (autorizado en el servicio) y adjuntar o reemplazar.
    Route::get('guias/{guia}/archivo/{modo}', [GuiasController::class, 'archivo'])
        ->whereNumber('guia')->where('modo', 'ver|descargar')->name('admin.logistica.guias.archivo');
    Route::post('guias/{guia}/archivo', [GuiasController::class, 'guardarArchivo'])
        ->whereNumber('guia')->name('admin.logistica.guias.archivo.guardar');

    // Corregir guia: accion explicita y auditada (motivo obligatorio). No toca el Shipment ni el inventario.
    Route::post('guias/{guia}/corregir', [GuiasController::class, 'corregir'])
        ->whereNumber('guia')->name('admin.logistica.guias.corregir');

    // Bandeja de entregas y, con ?envio=ID, el detalle del envio (historial de entregas y retornos).
    Route::get('entregas', [EntregasController::class, 'index'])->name('admin.logistica.entregas.index');

    // Mensajes al cliente desde la bandeja de Entregas (mismo controlador y contrato que Despachos; ACL propia de Entregas).
    Route::prefix('entregas/pedidos/{pedido}')->whereNumber('pedido')->group(function () {
        Route::get('mensajes', [MensajesPedidoController::class, 'opciones'])->name('admin.logistica.entregas.mensajes.opciones');
        Route::get('mensajes/{clave}', [MensajesPedidoController::class, 'mensaje'])->where('clave', '[a-z0-9\-]+')->name('admin.logistica.entregas.mensajes.mensaje');
    });

    Route::prefix('entregas/envios/{envio}')->whereNumber('envio')->group(function () {
        Route::get('entregar', [EntregasController::class, 'crearEntrega'])->name('admin.logistica.entregas.crear');
        Route::post('entregar', [EntregasController::class, 'guardarEntrega'])->name('admin.logistica.entregas.store');
        Route::get('retorno', [EntregasController::class, 'crearRetorno'])->name('admin.logistica.retornos.crear');
        Route::post('retorno', [EntregasController::class, 'guardarRetorno'])->name('admin.logistica.retornos.store');

        // Drawer unico de Entregas (JSON): mismas autoridades de servicio que las paginas clasicas, que siguen como respaldo.
        Route::get('contexto', [EntregasDrawerController::class, 'contexto'])->name('admin.logistica.entregas.drawer.contexto');
        Route::post('drawer/entregar', [EntregasDrawerController::class, 'entregar'])->name('admin.logistica.entregas.drawer.entregar');
        Route::post('drawer/devolver', [EntregasDrawerController::class, 'devolver'])->name('admin.logistica.entregas.drawer.devolver');
    });

    // Recepcion fisica de una devolucion en bodega (reingresa las aptas) y anulaciones desde el drawer.
    Route::post('entregas/devoluciones/{retorno}/recibir', [EntregasDrawerController::class, 'recibir'])->whereNumber('retorno')->name('admin.logistica.entregas.recepcion.store');
    Route::post('entregas/drawer/entregas/{entrega}/anular', [EntregasDrawerController::class, 'anularEntrega'])->whereNumber('entrega')->name('admin.logistica.entregas.drawer.anular_entrega');
    Route::post('entregas/drawer/devoluciones/{retorno}/anular', [EntregasDrawerController::class, 'anularDevolucion'])->whereNumber('retorno')->name('admin.logistica.entregas.drawer.anular_devolucion');
    Route::post('entregas/envios/{envio}/validar', [EntregasDrawerController::class, 'validar'])->whereNumber('envio')->name('admin.logistica.entregas.drawer.validar');
    Route::post('entregas/envios/{envio}/cobrar', [EntregasDrawerController::class, 'cobrar'])->whereNumber('envio')->name('admin.logistica.entregas.drawer.cobrar');
    Route::post('entregas/drawer/validaciones/{validacion}/anular', [EntregasDrawerController::class, 'anularValidacion'])->whereNumber('validacion')->name('admin.logistica.entregas.drawer.anular_validacion');
    Route::post('entregas/drawer/recepciones/{recepcion}/anular', [EntregasDrawerController::class, 'anularRecepcion'])->whereNumber('recepcion')->name('admin.logistica.entregas.drawer.anular_recepcion');

    Route::post('entregas/{entrega}/anular', [EntregasController::class, 'anularEntrega'])->whereNumber('entrega')->name('admin.logistica.entregas.anular');
    Route::post('retornos/{retorno}/anular', [EntregasController::class, 'anularRetorno'])->whereNumber('retorno')->name('admin.logistica.retornos.anular');

    Route::get('transportadoras', [TransportadorasController::class, 'index'])->name('admin.logistica.transportadoras.index');
    Route::post('transportadoras', [TransportadorasController::class, 'store'])->name('admin.logistica.transportadoras.store');
    Route::post('transportadoras/{transportadora}/estado', [TransportadorasController::class, 'estado'])
        ->whereNumber('transportadora')
        ->name('admin.logistica.transportadoras.estado');
    Route::post('transportadoras/{transportadora}/capacidades', [TransportadorasController::class, 'capacidades'])
        ->whereNumber('transportadora')
        ->name('admin.logistica.transportadoras.capacidades');
    Route::post('tarifas', [TransportadorasController::class, 'tarifa'])->name('admin.logistica.tarifas.store');

    // Cuentas corrientes de transportadoras: listado + drawer (contexto JSON, liquidar, ajustar, resolver revision, historico).
    Route::get('cuentas', [CuentasTransportadoraController::class, 'index'])->name('admin.logistica.cuentas.index');

    Route::prefix('cuentas/{transportadora}')->whereNumber('transportadora')->group(function () {
        Route::get('contexto', [CuentasTransportadoraController::class, 'contexto'])->name('admin.logistica.cuentas.contexto');
        Route::post('liquidar', [CuentasTransportadoraController::class, 'liquidar'])->name('admin.logistica.cuentas.liquidar');
        Route::post('propuesta', [CuentasTransportadoraController::class, 'propuesta'])->name('admin.logistica.cuentas.propuesta');
        Route::post('cobro-reportado', [CuentasTransportadoraController::class, 'cobro'])->name('admin.logistica.cuentas.cobro');
        Route::post('ajustes', [CuentasTransportadoraController::class, 'ajustar'])->name('admin.logistica.cuentas.ajustar');
        Route::post('envios/{envio}/revision', [CuentasTransportadoraController::class, 'revision'])->whereNumber('envio')->name('admin.logistica.cuentas.revision');
    });

    Route::get('cuentas/liquidaciones/{liquidacion}', [CuentasTransportadoraController::class, 'liquidacion'])
        ->whereNumber('liquidacion')->name('admin.logistica.cuentas.liquidacion');

    Route::get('zonas-locales', [ZonasLocalesController::class, 'index'])->name('admin.logistica.zonas_locales.index');
    Route::post('zonas-locales/{origen}', [ZonasLocalesController::class, 'guardar'])->whereNumber('origen')->name('admin.logistica.zonas_locales.guardar');
    // Pantalla unificada «Zonas locales y tarifas» (JSON): estado, buscador de municipios, ciudades por bodega y tarifas.
    Route::get('zonas-locales/estado', [ZonasLocalesController::class, 'estado'])->name('admin.logistica.zonas_locales.estado');
    Route::get('zonas-locales/municipios', [ZonasLocalesController::class, 'municipios'])->name('admin.logistica.zonas_locales.municipios');
    Route::post('zonas-locales/{origen}/ciudades', [ZonasLocalesController::class, 'agregarCiudad'])->whereNumber('origen')->name('admin.logistica.zonas_locales.ciudades.agregar');
    Route::post('zonas-locales/{origen}/ciudades/{zona}/quitar', [ZonasLocalesController::class, 'quitarCiudad'])->whereNumber(['origen', 'zona'])->name('admin.logistica.zonas_locales.ciudades.quitar');
    Route::post('zonas-locales/{origen}/tarifas', [ZonasLocalesController::class, 'crearTarifa'])->whereNumber('origen')->name('admin.logistica.zonas_locales.tarifas.store');
});
