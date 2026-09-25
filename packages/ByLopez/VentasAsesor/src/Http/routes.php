<?php

use ByLopez\VentasAsesor\Http\Controllers\Admin\InicioController;
use Illuminate\Support\Facades\Route;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;

Route::group(['middleware' => ['admin', NoCacheMiddleware::class], 'prefix' => config('app.admin_url')], function () {
    Route::prefix('ventas-asesor')->group(function () {
        Route::get('', [InicioController::class, 'index'])->name('admin.ventas_asesor.inicio.index');
    });
});
