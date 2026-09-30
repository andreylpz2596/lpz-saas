<?php

use ByLopez\VentasAsesor\Http\Controllers\Admin\InicioController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\NuevaVentaController;
use Illuminate\Support\Facades\Route;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;

Route::group(['middleware' => ['admin', NoCacheMiddleware::class], 'prefix' => config('app.admin_url')], function () {
    Route::prefix('ventas-asesor')->group(function () {
        Route::get('', [InicioController::class, 'index'])->name('admin.ventas_asesor.inicio.index');

        // 03G-C: API de "Nueva venta rapida". Controller delgado sobre NuevaVentaService
        // (03G-B/03G-B2); cada ruta esta autorizada en Config/acl.php bajo la MISMA clave
        // que ya exige BorradorVentaService::PERMISO ('ventas_asesor.pedidos.create').
        Route::prefix('nueva-venta')->name('admin.ventas_asesor.nueva_venta.')->group(function () {
            Route::get('', [NuevaVentaController::class, 'estadoInicial'])->name('estado_inicial');
            Route::post('clientes/buscar', [NuevaVentaController::class, 'buscarCliente'])->name('clientes.buscar');
            Route::post('clientes', [NuevaVentaController::class, 'crearCliente'])->name('clientes.crear');
            Route::post('iniciar', [NuevaVentaController::class, 'iniciar'])->name('iniciar');
            Route::post('iniciar-cliente-nuevo', [NuevaVentaController::class, 'iniciarConClienteNuevo'])->name('iniciar_cliente_nuevo');
            Route::get('lineas/{clave}/colores', [NuevaVentaController::class, 'colores'])->name('lineas.colores');
            Route::get('productos/{producto}/matriz', [NuevaVentaController::class, 'matriz'])->name('productos.matriz');
            Route::get('{borrador}', [NuevaVentaController::class, 'estado'])->name('estado');
            Route::post('{borrador}/tallas', [NuevaVentaController::class, 'aplicarTallas'])->name('tallas');
            Route::post('{borrador}/entrega', [NuevaVentaController::class, 'entrega'])->name('entrega');
            Route::post('{borrador}/pago', [NuevaVentaController::class, 'pago'])->name('pago');
            Route::post('{borrador}/confirmar', [NuevaVentaController::class, 'confirmar'])->name('confirmar');
        });
    });
});
