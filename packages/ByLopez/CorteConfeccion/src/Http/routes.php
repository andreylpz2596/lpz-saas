<?php

use ByLopez\CorteConfeccion\Http\Controllers\Admin\CompraTelaController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\OrdenCorteController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\ProveedorTelaController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\RecepcionCorteController;
use ByLopez\CorteConfeccion\Http\Controllers\Admin\RolloController;
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
            Route::get('create', 'create')->name('admin.corte_confeccion.proveedores_tela.create');
            Route::post('create', 'store')->name('admin.corte_confeccion.proveedores_tela.store');
            Route::get('edit/{id}', 'edit')->name('admin.corte_confeccion.proveedores_tela.edit');
            Route::put('edit/{id}', 'update')->name('admin.corte_confeccion.proveedores_tela.update');
        });

        Route::controller(RolloController::class)->prefix('rollos')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.rollos.index');
            Route::get('create', 'create')->name('admin.corte_confeccion.rollos.create');
            Route::post('create', 'store')->name('admin.corte_confeccion.rollos.store');
            Route::get('view/{id}', 'show')->name('admin.corte_confeccion.rollos.show');
            Route::get('edit/{id}', 'edit')->name('admin.corte_confeccion.rollos.edit');
            Route::put('edit/{id}', 'update')->name('admin.corte_confeccion.rollos.update');
            Route::post('cancel/{id}', 'cancel')->name('admin.corte_confeccion.rollos.cancel');
        });

        Route::controller(OrdenCorteController::class)->prefix('ordenes-corte')->group(function () {
            Route::get('', 'index')->name('admin.corte_confeccion.ordenes_corte.index');
            Route::get('create', 'create')->name('admin.corte_confeccion.ordenes_corte.create');
            Route::post('create', 'store')->name('admin.corte_confeccion.ordenes_corte.store');
            Route::get('view/{id}', 'show')->name('admin.corte_confeccion.ordenes_corte.show');
            Route::get('entregar/{id}', 'entregarForm')->name('admin.corte_confeccion.ordenes_corte.entregar_form');
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
    });
});
