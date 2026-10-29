<?php

use ByLopez\VentasAsesor\Http\Controllers\Admin\AsesoresController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\CarteraClienteController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\CarteraController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\ComisionesController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\ControlCostosController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\GuiaPedidoController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\InicioController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\NuevaVentaController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\PagosController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\PedidosAsesorController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\RentabilidadController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\UbicacionesController;
use Illuminate\Support\Facades\Route;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;

Route::group(['middleware' => ['admin', NoCacheMiddleware::class], 'prefix' => config('app.admin_url')], function () {
    Route::prefix('ventas-asesor')->group(function () {
        Route::get('', [InicioController::class, 'index'])->name('admin.ventas_asesor.inicio.index');

        // D-58: autocomplete de ubicaciones colombianas (catalogo DIVIPOLA local), reutilizable.
        Route::get('ubicaciones', [UbicacionesController::class, 'buscar'])->name('admin.ventas_asesor.ubicaciones.buscar');

        // OPERACION ASESOR: "Mis ventas / Pedidos" (listado + detalle con ?pedido=ID).
        Route::get('pedidos', [PedidosAsesorController::class, 'index'])->name('admin.ventas_asesor.pedidos.index');

        // Despacho V1: el asesor ve / descarga la guia (PDF) de SUS pedidos. La autorizacion real la hace Logistica.
        Route::get('pedidos/{pedido}/guias/{guia}/{modo}', [GuiaPedidoController::class, 'archivo'])
            ->whereNumber(['pedido', 'guia'])->where('modo', 'ver|descargar')
            ->name('admin.ventas_asesor.pedidos.guia');

        // PAG-C: registrar un pago desde el pedido (clave ACL ventas_asesor.cartera.registrar).
        Route::post('pedidos/{pedido}/pagos', [PagosController::class, 'store'])
            ->whereNumber('pedido')
            ->name('admin.ventas_asesor.pedidos.pagos.store');

        // 03G-C: API de "Nueva venta rapida". Controller delgado sobre NuevaVentaService
        // (03G-B/03G-B2); cada ruta esta autorizada en Config/acl.php bajo la MISMA clave
        // que ya exige BorradorVentaService::PERMISO ('ventas_asesor.pedidos.create').
        // PAG-D: cartera de un cliente (`?cliente=ID`, clave agrupadora ventas_asesor.cartera) y abono general
        // multipedido (clave ventas_asesor.cartera.abono_general).
        // PAG-E: la MISMA ruta es la bandeja Cartera / Pagos (sin parametros), el detalle de un pago (`?pago=ID`) y la
        // cartera de un cliente (`?cliente=ID`). Confirmar y rechazar exigen la hoja ventas_asesor.cartera.revisar.
        Route::get('cartera', [CarteraController::class, 'index'])->name('admin.ventas_asesor.cartera.index');
        Route::post('cartera/pagos/{pago}/confirmar', [CarteraController::class, 'confirmar'])
            ->whereNumber('pago')
            ->name('admin.ventas_asesor.cartera.pagos.confirmar');
        Route::post('cartera/pagos/{pago}/rechazar', [CarteraController::class, 'rechazar'])
            ->whereNumber('pago')
            ->name('admin.ventas_asesor.cartera.pagos.rechazar');
        Route::post('cartera/clientes/{cliente}/abonos', [CarteraClienteController::class, 'abonar'])
            ->whereNumber('cliente')
            ->name('admin.ventas_asesor.cartera.abonos.store');

        // ASESOR-01: listado (`view_all`) y ficha (`?asesor=ID`, o la propia con `view_own`); alta, edicion y estado
        // exigen la hoja ventas_asesor.asesores.manage.
        Route::get('asesores', [AsesoresController::class, 'index'])->name('admin.ventas_asesor.asesores.index');
        Route::post('asesores', [AsesoresController::class, 'store'])->name('admin.ventas_asesor.asesores.store');
        Route::post('asesores/{asesor}', [AsesoresController::class, 'update'])->whereNumber('asesor')->name('admin.ventas_asesor.asesores.update');
        Route::post('asesores/{asesor}/estado', [AsesoresController::class, 'estado'])->whereNumber('asesor')->name('admin.ventas_asesor.asesores.estado');

        // D-COST-04/05: «Control de costos» (clave ventas_asesor.costos); vista previa y confirmacion de la actualizacion
        // masiva exigen la hoja ventas_asesor.costos.actualizar.
        Route::get('costos', [ControlCostosController::class, 'index'])->name('admin.ventas_asesor.costos.index');
        Route::post('costos/vista-previa', [ControlCostosController::class, 'previsualizar'])->name('admin.ventas_asesor.costos.previsualizar');
        Route::post('costos/actualizar', [ControlCostosController::class, 'actualizar'])->name('admin.ventas_asesor.costos.actualizar');

        // Doc. 23 (R3/R4): Comisiones. Una pantalla con pestañas (?vista=, ?pedido=); cada accion con su clave ACL hoja.
        Route::prefix('comisiones')->name('admin.ventas_asesor.comisiones.')->group(function () {
            Route::get('', [ComisionesController::class, 'index'])->name('index');
            Route::get('mias', [ComisionesController::class, 'mias'])->name('mias');
            Route::post('evaluar', [ComisionesController::class, 'evaluar'])->name('evaluar');
            Route::post('acuerdos/{asesor}', [ComisionesController::class, 'guardarAcuerdo'])->whereNumber('asesor')->name('acuerdos.store');
            Route::post('liquidaciones', [ComisionesController::class, 'crearLiquidacion'])->name('liquidaciones.store');
            Route::post('liquidaciones/{liquidacion}/aprobar', [ComisionesController::class, 'aprobarLiquidacion'])->whereNumber('liquidacion')->name('liquidaciones.aprobar');
            Route::post('liquidaciones/{liquidacion}/anular', [ComisionesController::class, 'anularLiquidacion'])->whereNumber('liquidacion')->name('liquidaciones.anular');
            Route::post('liquidaciones/{liquidacion}/abonos', [ComisionesController::class, 'registrarAbono'])->whereNumber('liquidacion')->name('abonos.store');
            Route::post('abonos/{abono}/revertir', [ComisionesController::class, 'revertirAbono'])->whereNumber('abono')->name('abonos.revertir');
            Route::get('abonos/{abono}/comprobante', [ComisionesController::class, 'comprobante'])->whereNumber('abono')->name('abonos.comprobante');
            Route::post('ajustes', [ComisionesController::class, 'crearAjuste'])->name('ajustes.store');
            Route::post('ajustes/{ajuste}/resolver', [ComisionesController::class, 'resolverAjuste'])->whereNumber('ajuste')->name('ajustes.resolver');
            // Doc. 23 §10.3: resolver una incidencia («mantener»). Misma clave que aprobar liquidaciones y ajustes.
            Route::post('incidencias/{incidencia}/resolver', [ComisionesController::class, 'resolverIncidencia'])->whereNumber('incidencia')->name('incidencias.resolver');
        });

        // Doc. 23 (R1/R4): Rentabilidad (diagnostico, configuracion y panel mensual). Solo administracion.
        Route::prefix('rentabilidad')->name('admin.ventas_asesor.rentabilidad.')->group(function () {
            Route::get('', [RentabilidadController::class, 'index'])->name('index');
            Route::post('politicas', [RentabilidadController::class, 'guardarPolitica'])->name('politicas.store');
            Route::post('costos-directos', [RentabilidadController::class, 'guardarCostoDirecto'])->name('costos_directos.store');
            Route::post('estados-costo', [RentabilidadController::class, 'guardarEstadoCosto'])->name('estados_costo.store');
        });

        Route::prefix('nueva-venta')->name('admin.ventas_asesor.nueva_venta.')->group(function () {
            // 03G-D: la pantalla HTML. Registrada ANTES de la ruta {borrador} (catch-all) para
            // que 'pantalla' y 'en-curso' no la confundan con un id de borrador.
            Route::get('pantalla', [NuevaVentaController::class, 'pantalla'])->name('pantalla');
            Route::get('en-curso', [NuevaVentaController::class, 'enCurso'])->name('en_curso');
            Route::get('', [NuevaVentaController::class, 'estadoInicial'])->name('estado_inicial');
            Route::post('clientes/buscar', [NuevaVentaController::class, 'buscarCliente'])->name('clientes.buscar');
            Route::post('clientes', [NuevaVentaController::class, 'crearCliente'])->name('clientes.crear');
            Route::post('iniciar', [NuevaVentaController::class, 'iniciar'])->name('iniciar');
            Route::post('iniciar-cliente-nuevo', [NuevaVentaController::class, 'iniciarConClienteNuevo'])->name('iniciar_cliente_nuevo');
            Route::get('lineas/{clave}/colores', [NuevaVentaController::class, 'colores'])->name('lineas.colores');
            Route::get('productos/{producto}/matriz', [NuevaVentaController::class, 'matriz'])->name('productos.matriz');
            Route::get('matrices', [NuevaVentaController::class, 'matrices'])->name('matrices');
            Route::get('clientes/{cliente}/direcciones', [NuevaVentaController::class, 'direccionesCliente'])->name('clientes.direcciones');
            Route::get('{borrador}', [NuevaVentaController::class, 'estado'])->name('estado');
            Route::post('{borrador}/tallas', [NuevaVentaController::class, 'aplicarTallas'])->name('tallas');
            Route::post('{borrador}/entrega', [NuevaVentaController::class, 'entrega'])->name('entrega');
            Route::post('{borrador}/domiciliario', [NuevaVentaController::class, 'domiciliario'])->name('domiciliario');
            Route::post('{borrador}/pago', [NuevaVentaController::class, 'pago'])->name('pago');
            Route::post('{borrador}/confirmar', [NuevaVentaController::class, 'confirmar'])->name('confirmar');
            Route::post('{borrador}/eliminar', [NuevaVentaController::class, 'eliminar'])->name('eliminar');
            Route::post('{borrador}/direcciones', [NuevaVentaController::class, 'agregarDireccion'])->name('direcciones.agregar');
            // GRP-01: grupo comercial TEMPORAL de la venta (nunca modifica al cliente).
            Route::post('{borrador}/grupo', [NuevaVentaController::class, 'cambiarGrupo'])->name('grupo.cambiar');
            Route::post('{borrador}/grupo/restaurar', [NuevaVentaController::class, 'restaurarGrupo'])->name('grupo.restaurar');
            Route::get('{borrador}/grupo/historial', [NuevaVentaController::class, 'historialGrupo'])->name('grupo.historial');
            // R2: advertencia de rentabilidad del borrador (al asesor, sin costos).
            Route::get('{borrador}/rentabilidad', [NuevaVentaController::class, 'rentabilidad'])->name('rentabilidad');
        });
    });
});
