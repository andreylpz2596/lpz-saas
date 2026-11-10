<?php

use ByLopez\Cargues\Http\Controllers\Admin\CarguesController;
use ByLopez\Cargues\Http\Controllers\Admin\TiposController;
use ByLopez\Cargues\Http\Middleware\SoloAdministrador;
use Illuminate\Support\Facades\Route;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;

/*
 * Rutas de Cargues. SOLO administrador, en TODAS (GET, POST, progreso y descargas): `SoloAdministrador` va ANTES del
 * middleware `admin` de Bagisto, asi un rol con `permission_type = all` que no es el administrador recibe 403. No hay
 * clave ACL a proposito: una clave se podria asignar a roles personalizados.
 */
Route::group(['middleware' => [SoloAdministrador::class, 'admin', NoCacheMiddleware::class], 'prefix' => config('app.admin_url').'/cargues'], function () {
    Route::get('/', [CarguesController::class, 'index'])->name('admin.cargues.index');
    Route::get('tipos-activos', [CarguesController::class, 'tipos'])->name('admin.cargues.tipos_activos');
    Route::get('historial', [CarguesController::class, 'historial'])->name('admin.cargues.historial');
    Route::get('tipos/{tipo}/plantilla.{formato}', [CarguesController::class, 'plantilla'])->whereNumber('tipo')->where('formato', 'csv|xlsx')->name('admin.cargues.tipos.plantilla');

    Route::post('cargas', [CarguesController::class, 'store'])->name('admin.cargues.cargas.store');

    Route::prefix('cargas/{carga}')->whereNumber('carga')->group(function () {
        Route::get('/', [CarguesController::class, 'show'])->name('admin.cargues.cargas.show');
        Route::get('progreso', [CarguesController::class, 'progreso'])->name('admin.cargues.cargas.progreso');
        Route::get('filas', [CarguesController::class, 'filas'])->name('admin.cargues.cargas.filas');
        Route::get('agrupado', [CarguesController::class, 'agrupado'])->name('admin.cargues.cargas.agrupado');
        Route::post('lectura', [CarguesController::class, 'lectura'])->name('admin.cargues.cargas.lectura');
        Route::post('validar', [CarguesController::class, 'validar'])->name('admin.cargues.cargas.validar');
        Route::post('reintentar', [CarguesController::class, 'reintentar'])->name('admin.cargues.cargas.reintentar');
        Route::post('cancelar', [CarguesController::class, 'cancelar'])->name('admin.cargues.cargas.cancelar');
        Route::post('ejecutar', [CarguesController::class, 'ejecutar'])->name('admin.cargues.cargas.ejecutar');
        Route::post('recuperar', [CarguesController::class, 'recuperar'])->name('admin.cargues.cargas.recuperar');
        Route::get('comparaciones', [CarguesController::class, 'comparaciones'])->name('admin.cargues.cargas.comparaciones');
        Route::get('comparaciones/{comparacion}', [CarguesController::class, 'aplicarComparacion'])->whereNumber('comparacion')->name('admin.cargues.cargas.comparaciones.aplicar');
        Route::get('descargas/rechazadas.{formato}', [CarguesController::class, 'descargarRechazadas'])->where('formato', 'csv|xlsx')->name('admin.cargues.cargas.rechazadas');
        Route::get('descargas/resultado.{formato}', [CarguesController::class, 'descargarResultado'])->where('formato', 'csv|xlsx')->name('admin.cargues.cargas.resultado');
        Route::get('descargas/pendientes.{formato}', [CarguesController::class, 'descargarPendientes'])->where('formato', 'csv|xlsx')->name('admin.cargues.cargas.pendientes');
    });

    // Configurar tipos (alta y edicion en borrador; activacion con validacion).
    Route::prefix('configuracion')->group(function () {
        Route::get('/', [TiposController::class, 'index'])->name('admin.cargues.tipos.index');
        Route::get('crear', [TiposController::class, 'crear'])->name('admin.cargues.tipos.crear');
        Route::post('/', [TiposController::class, 'store'])->name('admin.cargues.tipos.store');

        Route::prefix('{tipo}')->whereNumber('tipo')->group(function () {
            Route::get('/', [TiposController::class, 'editar'])->name('admin.cargues.tipos.editar');
            Route::put('borrador', [TiposController::class, 'guardar'])->name('admin.cargues.tipos.guardar');
            Route::post('validar', [TiposController::class, 'validar'])->name('admin.cargues.tipos.validar');
            Route::post('confirmar-contrato', [TiposController::class, 'confirmarContrato'])->name('admin.cargues.tipos.confirmar_contrato');
            Route::post('activar', [TiposController::class, 'activar'])->name('admin.cargues.tipos.activar');
            Route::post('activo', [TiposController::class, 'activo'])->name('admin.cargues.tipos.activo');
            Route::post('cancelar-cargas', [TiposController::class, 'cancelarCargas'])->name('admin.cargues.tipos.cancelar_cargas');
        });
    });
});
