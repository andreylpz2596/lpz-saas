<?php

use ByLopez\CorteConfeccion\Http\Controllers\Admin\CarteraController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\CompraTelaController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\ConsolidadoConfeccionController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\CortadorController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\InventarioMovimientoController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\NotificacionController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\OrdenConfeccionController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\OrdenCorteController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\ProveedorTelaController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\RecepcionCorteController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\RolloController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\StockMinimoController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\TallerController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\TarifaPrestadorController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\UsuarioRolController;
use Illuminate\Support\Facades\Route;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;

Route::group(['middleware' => ['admin', NoCacheMiddleware::class], 'prefix' => config('app.admin_url')], function () {
    Route::prefix('corte-confeccion')->group(function () {
        Route::controller(CompraTelaController::class)->prefix('compras-tela')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.compras_tela.index');
            Route::get('referencias', 'referencias')->name('admin.corte_confeccion.compras_tela.referencias');
            Route::get('create', 'create')->name('admin.corte_confeccion.compras_tela.create');
            Route::post('create', 'store')->name('admin.corte_confeccion.compras_tela.store');
            Route::get('view/{id}', 'show')->name('admin.corte_confeccion.compras_tela.show');
            Route::get('edit/{id}', 'edit')->name('admin.corte_confeccion.compras_tela.edit');
            Route::put('edit/{id}', 'update')->name('admin.corte_confeccion.compras_tela.update');
        });

        Route::controller(ProveedorTelaController::class)->prefix('proveedores-tela')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.proveedores_tela.index');
            Route::get('referencias', 'referencias')->name('admin.corte_confeccion.proveedores_tela.referencias');
            Route::get('referencias/crear/{proveedorId}', 'referenciaCreate')->name('admin.corte_confeccion.proveedores_tela.referencia.create');
            Route::get('referencias/editar/{referenciaId}', 'referenciaEdit')->name('admin.corte_confeccion.proveedores_tela.referencia.edit');
            Route::get('create', 'create')->name('admin.corte_confeccion.proveedores_tela.create');
            Route::post('create', 'store')->name('admin.corte_confeccion.proveedores_tela.store');
            Route::get('view/{id}', 'show')->name('admin.corte_confeccion.proveedores_tela.show');
            Route::get('edit/{id}', 'edit')->name('admin.corte_confeccion.proveedores_tela.edit');
            Route::put('edit/{id}', 'update')->name('admin.corte_confeccion.proveedores_tela.update');
        });

        Route::controller(RolloController::class)->prefix('rollos')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.rollos.index');
            Route::get('view/{id}', 'show')->name('admin.corte_confeccion.rollos.show');
            Route::put('edit/{id}', 'update')->name('admin.corte_confeccion.rollos.update');
            Route::post('cancel/{id}', 'cancel')->name('admin.corte_confeccion.rollos.cancel');
        });

        Route::controller(OrdenCorteController::class)->prefix('ordenes-corte')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.ordenes_corte.index');
            Route::get('productos/buscar', 'productos')->name('admin.corte_confeccion.ordenes_corte.productos');
            Route::get('create', 'create')->name('admin.corte_confeccion.ordenes_corte.create');
            Route::post('create', 'store')->name('admin.corte_confeccion.ordenes_corte.store');
            Route::get('opciones', 'opciones')->name('admin.corte_confeccion.ordenes_corte.opciones');
            Route::post('previsualizar', 'previsualizar')->name('admin.corte_confeccion.ordenes_corte.previsualizar');
            Route::get('operar', 'operarIndex')->name('admin.corte_confeccion.ordenes_corte.operar_index');
            Route::get('view/{id}', 'show')->name('admin.corte_confeccion.ordenes_corte.show');
            Route::post('view/{id}/detalles', 'storeDetalle')->name('admin.corte_confeccion.ordenes_corte.detalles.store');
            Route::get('view/{id}/adicionales/opciones', 'adicionalesOpciones')->name('admin.corte_confeccion.ordenes_corte.adicionales.opciones');
            Route::post('view/{id}/adicionales/previsualizar', 'adicionalesPrevisualizar')->name('admin.corte_confeccion.ordenes_corte.adicionales.previsualizar');
            Route::post('view/{id}/rollos', 'storeRollo')->name('admin.corte_confeccion.ordenes_corte.rollos.store');
            Route::post('view/{id}/rollos/{rolloId}/retirar', 'retirarRollo')->name('admin.corte_confeccion.ordenes_corte.rollos.retirar');
            Route::post('entregar/{id}', 'entregar')->name('admin.corte_confeccion.ordenes_corte.entregar');
            Route::post('en-corte/{id}', 'marcarEnCorte')->name('admin.corte_confeccion.ordenes_corte.en_corte');
            Route::post('reportar/{id}', 'reportar')->name('admin.corte_confeccion.ordenes_corte.reportar');
            Route::post('cerrar/{id}', 'cerrar')->name('admin.corte_confeccion.ordenes_corte.cerrar');
            Route::post('cancel/{id}', 'cancel')->name('admin.corte_confeccion.ordenes_corte.cancel');
        });

        Route::controller(RecepcionCorteController::class)->prefix('recepciones-corte')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.recepciones_corte.index');
            Route::get('create/{ordenCorteId}', 'create')->name('admin.corte_confeccion.recepciones_corte.create');
            Route::post('create/{ordenCorteId}', 'store')->name('admin.corte_confeccion.recepciones_corte.store');
            Route::get('view/{id}', 'show')->name('admin.corte_confeccion.recepciones_corte.show');
        });

        Route::controller(TallerController::class)->prefix('talleres')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.talleres.index');
            Route::get('buscar', 'buscar')->name('admin.corte_confeccion.talleres.buscar');
            Route::get('create', 'create')->name('admin.corte_confeccion.talleres.create');
            Route::post('create', 'store')->name('admin.corte_confeccion.talleres.store');
            Route::get('view/{id}', 'show')->name('admin.corte_confeccion.talleres.show');
            Route::get('edit/{id}', 'edit')->name('admin.corte_confeccion.talleres.edit');
            Route::put('edit/{id}', 'update')->name('admin.corte_confeccion.talleres.update');
        });

        Route::controller(CortadorController::class)->prefix('cortadores')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.cortadores.index');
            Route::get('create', 'create')->name('admin.corte_confeccion.cortadores.create');
            Route::post('create', 'store')->name('admin.corte_confeccion.cortadores.store');
            Route::get('view/{id}', 'show')->name('admin.corte_confeccion.cortadores.show');
            Route::get('edit/{id}', 'edit')->name('admin.corte_confeccion.cortadores.edit');
            Route::put('edit/{id}', 'update')->name('admin.corte_confeccion.cortadores.update');
        });

        Route::controller(OrdenConfeccionController::class)->prefix('ordenes-confeccion')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.ordenes_confeccion.index');
            Route::get('create', 'createIndex')->name('admin.corte_confeccion.ordenes_confeccion.create_index');
            Route::get('create/{ordenCorteId}', 'create')->name('admin.corte_confeccion.ordenes_confeccion.create');
            Route::post('create', 'store')->name('admin.corte_confeccion.ordenes_confeccion.store');
            Route::get('contexto/{ordenCorteId}', 'contexto')->name('admin.corte_confeccion.ordenes_confeccion.contexto');
            Route::post('distribuir', 'distribuir')->name('admin.corte_confeccion.ordenes_confeccion.distribuir');
            Route::get('operar', 'operarIndex')->name('admin.corte_confeccion.ordenes_confeccion.operar_index');
            Route::get('mis-ordenes', 'misOrdenes')->name('admin.corte_confeccion.ordenes_confeccion.mis_ordenes');
            Route::get('mis-ordenes/view/{id}', 'misOrdenesShow')->name('admin.corte_confeccion.ordenes_confeccion.mis_ordenes.show');
            Route::get('view/{id}', 'show')->name('admin.corte_confeccion.ordenes_confeccion.show');
            Route::post('reportar/{id}', 'reportarTaller')->name('admin.corte_confeccion.ordenes_confeccion.reportar_taller');
            Route::post('retornos/{retornoId}/recibir', 'recibirBodega')->name('admin.corte_confeccion.ordenes_confeccion.recibir_bodega');
            Route::post('cancel/{id}', 'cancel')->name('admin.corte_confeccion.ordenes_confeccion.cancel');
            Route::get('reasignar/{id}', 'reasignarContexto')->name('admin.corte_confeccion.ordenes_confeccion.reasignar.contexto');
            Route::post('reasignar/{id}', 'reasignarStore')->name('admin.corte_confeccion.ordenes_confeccion.reasignar.store');
            Route::post('reasignaciones/{id}/recibir', 'reasignacionRecibir')->name('admin.corte_confeccion.ordenes_confeccion.reasignaciones.recibir');
            Route::post('reasignaciones/{id}/revertir', 'reasignacionRevertir')->name('admin.corte_confeccion.ordenes_confeccion.reasignaciones.revertir');
        });

        // Consolidado general y prioridad por cantidad (Doc. 09).
        Route::controller(ConsolidadoConfeccionController::class)->prefix('ordenes-confeccion')->group(function () {
            Route::get('consolidado', 'index')->name('admin.corte_confeccion.ordenes_confeccion.consolidado');
            Route::post('prioridad', 'cambiarPrioridad')->name('admin.corte_confeccion.ordenes_confeccion.prioridad');
        });

        // Minimos de stock por variante: base de la sugerencia por stock (Doc. 09 §9).
        Route::controller(StockMinimoController::class)->prefix('stock-minimos')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.stock_minimos.index');
            Route::post('', 'guardar')->name('admin.corte_confeccion.stock_minimos.guardar');
        });

        // Avisos del modulo dentro de la campana y el listado de notificaciones de Bagisto.
        Route::controller(NotificacionController::class)->prefix('notificaciones')->group(function () {
            Route::get('', 'lista')->name('admin.corte_confeccion.notificaciones.lista');
            Route::get('abrir/{id}', 'abrir')->name('admin.corte_confeccion.notificaciones.abrir');
            Route::post('leer-todas', 'leerTodas')->name('admin.corte_confeccion.notificaciones.leer_todas');
        });

        // Referencias y precios (Doc. 05). `tipo`: 'taller' = taller / orden de confeccion; 'cortador' = cortador /
        // orden de corte. Es un default de la ruta (no viene del cliente) y decide el permiso en TarifaPrestadorGate.
        Route::controller(TarifaPrestadorController::class)->group(function () {
            Route::get('talleres/view/{id}/referencias', 'index')->defaults('tipo', 'taller')->name('admin.corte_confeccion.talleres.referencias.index');
            Route::get('talleres/view/{id}/referencias/historial', 'historial')->defaults('tipo', 'taller')->name('admin.corte_confeccion.talleres.referencias.historial');
            Route::post('talleres/view/{id}/tarifas', 'store')->defaults('tipo', 'taller')->name('admin.corte_confeccion.talleres.tarifas.store');
            Route::post('talleres/view/{id}/tarifas/{tarifaId}/anular', 'anular')->defaults('tipo', 'taller')->name('admin.corte_confeccion.talleres.tarifas.anular');
            Route::get('cortadores/view/{id}/referencias', 'index')->defaults('tipo', 'cortador')->name('admin.corte_confeccion.cortadores.referencias.index');
            Route::get('cortadores/view/{id}/referencias/historial', 'historial')->defaults('tipo', 'cortador')->name('admin.corte_confeccion.cortadores.referencias.historial');
            Route::post('cortadores/view/{id}/tarifas', 'store')->defaults('tipo', 'cortador')->name('admin.corte_confeccion.cortadores.tarifas.store');
            Route::post('cortadores/view/{id}/tarifas/{tarifaId}/anular', 'anular')->defaults('tipo', 'cortador')->name('admin.corte_confeccion.cortadores.tarifas.anular');
            Route::post('ordenes-confeccion/view/{id}/tarifa', 'pactarEnOrden')->defaults('tipo', 'taller')->name('admin.corte_confeccion.ordenes_confeccion.tarifa');
            Route::post('ordenes-corte/view/{id}/tarifa', 'pactarEnOrden')->defaults('tipo', 'cortador')->name('admin.corte_confeccion.ordenes_corte.tarifa');
        });

        Route::controller(InventarioMovimientoController::class)->prefix('inventario-movimientos')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.inventario_movimientos.index');
            Route::post('ajuste-manual', 'ajusteManual')->name('admin.corte_confeccion.inventario_movimientos.ajuste_manual');
            Route::get('view/{id}', 'show')->name('admin.corte_confeccion.inventario_movimientos.show');
        });

        // Cartera de talleres y cortadores. `tipo`: taller | cortador. Cada accion se autoriza ademas en servidor (CarteraGate).
        Route::controller(CarteraController::class)->prefix('cartera')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.cartera.index');
            Route::get('mi-cuenta', 'miCuenta')->name('admin.corte_confeccion.cartera.mi_cuenta');
            Route::post('arranque', 'arranque')->name('admin.corte_confeccion.cartera.arranque');
            Route::get('soportes/{id}', 'soporte')->name('admin.corte_confeccion.cartera.soporte');
            Route::post('movimientos/{id}/aplicar', 'aplicar')->name('admin.corte_confeccion.cartera.movimientos.aplicar');
            Route::post('movimientos/{id}/reversar', 'reversarMovimiento')->name('admin.corte_confeccion.cartera.movimientos.reversar');
            Route::post('aplicaciones/{id}/anular', 'anularAplicacion')->name('admin.corte_confeccion.cartera.aplicaciones.anular');
            Route::post('cortes/{id}/reversar', 'corteReversar')->name('admin.corte_confeccion.cartera.corte.reversar');
            Route::get('{tipo}/{id}', 'cuenta')->whereIn('tipo', ['taller', 'cortador'])->name('admin.corte_confeccion.cartera.cuenta');
            Route::post('{tipo}/{id}/movimientos', 'registrarMovimiento')->whereIn('tipo', ['taller', 'cortador'])->name('admin.corte_confeccion.cartera.movimientos.store');
            Route::post('{tipo}/{id}/prestamos', 'registrarPrestamo')->whereIn('tipo', ['taller', 'cortador'])->name('admin.corte_confeccion.cartera.prestamos.store');
            Route::post('{tipo}/{id}/comprobantes', 'leerComprobantes')->whereIn('tipo', ['taller', 'cortador'])->name('admin.corte_confeccion.cartera.comprobantes.leer');
            Route::get('{tipo}/{id}/corte', 'corteVista')->whereIn('tipo', ['taller', 'cortador'])->name('admin.corte_confeccion.cartera.corte.vista');
            Route::post('{tipo}/{id}/corte', 'corteConfirmar')->whereIn('tipo', ['taller', 'cortador'])->name('admin.corte_confeccion.cartera.corte.confirmar');
        });

        Route::controller(UsuarioRolController::class)->prefix('usuarios')->group(function () {
            Route::get('cortadores/buscar', 'cortadores')->name('admin.corte_confeccion.usuarios.cortadores');
            Route::get('taller-confeccion/buscar', 'usuariosTaller')->name('admin.corte_confeccion.usuarios.taller_confeccion');
        });
    });
});
