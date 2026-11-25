<?php

use ByLopez\Logistica\Http\Controllers\Admin\MensajesPedidoController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\AsesoresController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\AutoservicioController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\CarteraClienteController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\CarteraController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\ClienteAccesoController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\ComisionesController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\ControlCostosController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\CotizacionController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\CreditoClienteController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\FacturaPedidoController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\GuiaPedidoController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\InicioController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\MensajesCarteraController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\NuevaVentaController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\PagosController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\PedidosAsesorController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\PreciosGrupoController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\RentabilidadController;
use ByLopez\VentasAsesor\Http\Controllers\Admin\UbicacionesController;
use Illuminate\Support\Facades\Route;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;

Route::group(['middleware' => ['admin', NoCacheMiddleware::class], 'prefix' => config('app.admin_url')], function () {
    // Doc. 33 P3: autoservicio del CLIENTE FRECUENTE. El cliente sale del vinculo (nunca de la peticion). Clave ACL autoservicio.comprar.
    Route::prefix('autoservicio')->name('admin.autoservicio.')->group(function () {
        Route::get('', [AutoservicioController::class, 'pantalla'])->name('pantalla');
        Route::get('pedidos', [AutoservicioController::class, 'pedidosPantalla'])->name('pedidos.index');
        Route::get('pedidos/datos', [AutoservicioController::class, 'pedidosData'])->name('pedidos.data');
        Route::get('pedidos/{pedido}', [AutoservicioController::class, 'pedidoDetalle'])->whereNumber('pedido')->name('pedidos.show');
        Route::get('bootstrap', [AutoservicioController::class, 'bootstrap'])->name('estado_inicial');
        Route::get('lineas/{clave}/colores', [AutoservicioController::class, 'colores'])->name('lineas.colores');
        Route::get('matrices', [AutoservicioController::class, 'matrices'])->name('matrices');
        Route::get('productos/{producto}/matriz', [AutoservicioController::class, 'matriz'])->whereNumber('producto')->name('productos.matriz');
        Route::get('en-curso', [AutoservicioController::class, 'enCurso'])->name('en_curso');
        // Cliente SIN direccion registrada: puede crear UNA (autocomplete de municipios + guardado sobre el vinculo).
        Route::get('ubicaciones', [UbicacionesController::class, 'buscar'])->name('ubicaciones');
        Route::post('iniciar-con-tallas', [AutoservicioController::class, 'iniciarConTallas'])->name('iniciar_con_tallas');
        Route::get('{borrador}', [AutoservicioController::class, 'estado'])->whereNumber('borrador')->name('estado');
        Route::post('{borrador}/tallas', [AutoservicioController::class, 'aplicarTallas'])->whereNumber('borrador')->name('tallas');
        Route::post('{borrador}/eliminar', [AutoservicioController::class, 'eliminar'])->whereNumber('borrador')->name('eliminar');
        Route::post('{borrador}/direccion', [AutoservicioController::class, 'guardarDireccion'])->whereNumber('borrador')->name('direccion');
        Route::post('{borrador}/preparar', [AutoservicioController::class, 'preparar'])->whereNumber('borrador')->name('preparar');
        Route::post('{borrador}/confirmar', [AutoservicioController::class, 'confirmar'])->whereNumber('borrador')->name('confirmar');
    });

    Route::prefix('ventas-asesor')->group(function () {
        Route::get('', [InicioController::class, 'index'])->name('admin.ventas_asesor.inicio.index');

        // Doc. 33 P1: acceso de un cliente al aplicativo (autoservicio), desde la ficha del cliente. Clave ventas_asesor.clientes_acceso.manage.
        Route::prefix('clientes/{cliente}/acceso')->whereNumber('cliente')->name('admin.ventas_asesor.clientes_acceso.')->group(function () {
            Route::get('', [ClienteAccesoController::class, 'mostrar'])->name('mostrar');
            Route::post('crear-usuario', [ClienteAccesoController::class, 'crearUsuario'])->name('crear_usuario');
            Route::post('vincular', [ClienteAccesoController::class, 'vincular'])->name('vincular');
            Route::post('suspender', [ClienteAccesoController::class, 'suspender'])->name('suspender');
            Route::post('reactivar', [ClienteAccesoController::class, 'reactivar'])->name('reactivar');
            Route::post('desvincular', [ClienteAccesoController::class, 'desvincular'])->name('desvincular');
        });

        // D-58: autocomplete de ubicaciones colombianas (catalogo DIVIPOLA local), reutilizable.
        Route::get('ubicaciones', [UbicacionesController::class, 'buscar'])->name('admin.ventas_asesor.ubicaciones.buscar');

        // OPERACION ASESOR: "Mis ventas / Pedidos" (listado + detalle con ?pedido=ID).
        Route::get('pedidos', [PedidosAsesorController::class, 'index'])->name('admin.ventas_asesor.pedidos.index');

        // Despacho V1: el asesor ve / descarga la guia (PDF) de SUS pedidos. La autorizacion real la hace Logistica.
        // El asesor abre la factura PAGADA de SUS pedidos en otra pestaña (mismo PDF que la factura de Bagisto, solo lectura).
        Route::get('pedidos/{pedido}/factura', [FacturaPedidoController::class, 'ver'])
            ->whereNumber('pedido')->name('admin.ventas_asesor.pedidos.factura');

        Route::get('pedidos/{pedido}/guias/{guia}/{modo}', [GuiaPedidoController::class, 'archivo'])
            ->whereNumber(['pedido', 'guia'])->where('modo', 'ver|descargar')
            ->name('admin.ventas_asesor.pedidos.guia');

        // Mensajes al cliente (WhatsApp, solo lectura): MISMO controlador y servicio que las bandejas de Logistica; aqui la puerta de
        // Ventas (clave view_own). El servicio exige que el pedido sea del asesor (o view_all) y no ofrece mensajes que no correspondan.
        Route::get('pedidos/{pedido}/mensajes', [MensajesPedidoController::class, 'opciones'])
            ->whereNumber('pedido')->name('admin.ventas_asesor.pedidos.mensajes.opciones');
        Route::get('pedidos/{pedido}/mensajes/{clave}', [MensajesPedidoController::class, 'mensaje'])
            ->whereNumber('pedido')->where('clave', '[a-z0-9\-]+')->name('admin.ventas_asesor.pedidos.mensajes.mensaje');

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
        // Estado de cuenta de WhatsApp del cliente (clave view_all: con «ver propios» el total saldria incompleto). Solo lectura.
        Route::get('cartera/clientes/{cliente}/mensajes', [MensajesCarteraController::class, 'opciones'])
            ->whereNumber('cliente')->name('admin.ventas_asesor.cartera.mensajes.opciones');
        Route::get('cartera/clientes/{cliente}/mensajes/{clave}', [MensajesCarteraController::class, 'mensaje'])
            ->whereNumber('cliente')->where('clave', '[a-z0-9\-]+')->name('admin.ventas_asesor.cartera.mensajes.mensaje');

        // D10 V1: credito del cliente (ver / aplicar / reembolsar / reversar / ajustar). Cada hoja ACL autoriza SOLO su ruta;
        // los servicios vuelven a validar permiso y alcance «todos».
        Route::get('cartera/credito/pedido/{pedido}', [CreditoClienteController::class, 'pedido'])->whereNumber('pedido')->name('admin.ventas_asesor.cartera.credito.pedido');
        Route::get('cartera/credito/cliente/{cliente}', [CreditoClienteController::class, 'cliente'])->whereNumber('cliente')->name('admin.ventas_asesor.cartera.credito.cliente');
        Route::get('cartera/credito/{movimiento}/comprobante', [CreditoClienteController::class, 'comprobante'])->whereNumber('movimiento')->name('admin.ventas_asesor.cartera.credito.comprobante');
        Route::post('cartera/credito/aplicar', [CreditoClienteController::class, 'aplicar'])->name('admin.ventas_asesor.cartera.credito.aplicar');
        Route::post('cartera/credito/reembolsar', [CreditoClienteController::class, 'reembolsar'])->name('admin.ventas_asesor.cartera.credito.reembolsar');
        Route::post('cartera/credito/{movimiento}/reversar', [CreditoClienteController::class, 'reversar'])->whereNumber('movimiento')->name('admin.ventas_asesor.cartera.credito.reversar');
        Route::post('cartera/credito/ajustes', [CreditoClienteController::class, 'ajustar'])->name('admin.ventas_asesor.cartera.credito.ajustar');
        Route::post('cartera/credito/ajustes/{ajuste}/reversar', [CreditoClienteController::class, 'reversarAjuste'])->whereNumber('ajuste')->name('admin.ventas_asesor.cartera.credito.ajuste_reversar');

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
        // Doc. 32: «Costos y precios › Precios por grupo», con permisos PROPIOS (separados de costos): la consulta con la
        // agrupadora ventas_asesor.precios_grupo; vista previa, confirmacion y reintento de reindexacion con la hoja `editar`.
        Route::get('precios-grupo', [PreciosGrupoController::class, 'index'])->name('admin.ventas_asesor.precios_grupo.index');
        Route::post('precios-grupo/vista-previa', [PreciosGrupoController::class, 'previsualizar'])->name('admin.ventas_asesor.precios_grupo.previsualizar');
        Route::post('precios-grupo/confirmar', [PreciosGrupoController::class, 'confirmar'])->name('admin.ventas_asesor.precios_grupo.confirmar');
        Route::post('precios-grupo/lotes/{lote}/reindexar', [PreciosGrupoController::class, 'reindexar'])
            ->whereUuid('lote')->name('admin.ventas_asesor.precios_grupo.reindexar');

        // Doc. 23 (R3/R4): Comisiones. Una pantalla con pestañas (?vista=, ?pedido=); cada accion con su clave ACL hoja.
        Route::prefix('comisiones')->name('admin.ventas_asesor.comisiones.')->group(function () {
            Route::get('', [ComisionesController::class, 'index'])->name('index');
            Route::get('mias', [ComisionesController::class, 'mias'])->name('mias');
            // «Simular próxima venta»: solo lectura (no crea pedidos ni guarda movimientos).
            Route::post('simular', [ComisionesController::class, 'simular'])->name('simular');
            Route::post('evaluar', [ComisionesController::class, 'evaluar'])->name('evaluar');
            Route::post('acuerdos/{asesor}', [ComisionesController::class, 'guardarAcuerdo'])->whereNumber('asesor')->name('acuerdos.store');
            Route::post('liquidaciones', [ComisionesController::class, 'crearLiquidacion'])->name('liquidaciones.store');
            Route::post('liquidaciones/{liquidacion}/aprobar', [ComisionesController::class, 'aprobarLiquidacion'])->whereNumber('liquidacion')->name('liquidaciones.aprobar');
            Route::post('liquidaciones/{liquidacion}/anular', [ComisionesController::class, 'anularLiquidacion'])->whereNumber('liquidacion')->name('liquidaciones.anular');
            Route::post('liquidaciones/{liquidacion}/abonos', [ComisionesController::class, 'registrarAbono'])->whereNumber('liquidacion')->name('abonos.store');
            // Lectura asistida de comprobantes: cargar+leer (NO registra nada) y ver el archivo cargado antes de confirmar el abono.
            Route::post('liquidaciones/{liquidacion}/soportes', [ComisionesController::class, 'leerSoporte'])->whereNumber('liquidacion')->name('soportes.leer');
            Route::get('soportes/{soporte}', [ComisionesController::class, 'verSoporte'])->whereNumber('soporte')->name('soportes.ver');
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
            Route::post('iniciar-con-tallas', [NuevaVentaController::class, 'iniciarConTallas'])->name('iniciar_con_tallas');
            Route::post('iniciar-cliente-nuevo', [NuevaVentaController::class, 'iniciarConClienteNuevo'])->name('iniciar_cliente_nuevo');
            // Doc. 34: pedido por voz (interpreta y valida el texto dictado; no toca la venta).
            Route::post('dictado/interpretar', [NuevaVentaController::class, 'interpretarDictado'])->name('dictado.interpretar');
            Route::post('dictado/corregir', [NuevaVentaController::class, 'corregirDictado'])->name('dictado.corregir');
            Route::get('lineas/{clave}/colores', [NuevaVentaController::class, 'colores'])->name('lineas.colores');
            Route::get('productos/{producto}/matriz', [NuevaVentaController::class, 'matriz'])->name('productos.matriz');
            Route::get('matrices', [NuevaVentaController::class, 'matrices'])->name('matrices');
            Route::get('clientes/{cliente}/direcciones', [NuevaVentaController::class, 'direccionesCliente'])->name('clientes.direcciones');
            // Cotizaciones: el PDF sale de la foto guardada; crear/listar son de UNA venta en curso.
            // Enlace del menu hacia Configuracion > Ventas Asesor > Cotizaciones (la pantalla es la de Bagisto, con su propio ACL).
            Route::get('cotizaciones/datos', [CotizacionController::class, 'datos'])->name('cotizaciones.datos');
            Route::get('cotizaciones/{cotizacion}/pdf', [CotizacionController::class, 'pdf'])->whereNumber('cotizacion')->name('cotizaciones.pdf');
            Route::get('{borrador}', [NuevaVentaController::class, 'estado'])->name('estado');
            Route::get('{borrador}/cotizaciones', [CotizacionController::class, 'index'])->whereNumber('borrador')->name('cotizaciones.index');
            Route::post('{borrador}/cotizaciones', [CotizacionController::class, 'store'])->whereNumber('borrador')->name('cotizaciones.store');
            Route::post('{borrador}/tallas', [NuevaVentaController::class, 'aplicarTallas'])->name('tallas');
            Route::post('{borrador}/entrega', [NuevaVentaController::class, 'entrega'])->name('entrega');
            Route::post('{borrador}/domiciliario', [NuevaVentaController::class, 'domiciliario'])->name('domiciliario');
            Route::post('{borrador}/pago', [NuevaVentaController::class, 'pago'])->name('pago');
            Route::post('{borrador}/confirmar', [NuevaVentaController::class, 'confirmar'])->name('confirmar');
            Route::post('{borrador}/eliminar', [NuevaVentaController::class, 'eliminar'])->name('eliminar');
            Route::post('{borrador}/direcciones', [NuevaVentaController::class, 'agregarDireccion'])->name('direcciones.agregar');
            Route::post('{borrador}/direcciones/{direccion}', [NuevaVentaController::class, 'editarDireccion'])->whereNumber('direccion')->name('direcciones.editar');
            // GRP-01: grupo comercial TEMPORAL de la venta (nunca modifica al cliente).
            Route::post('{borrador}/grupo', [NuevaVentaController::class, 'cambiarGrupo'])->name('grupo.cambiar');
            Route::post('{borrador}/grupo/restaurar', [NuevaVentaController::class, 'restaurarGrupo'])->name('grupo.restaurar');
            Route::get('{borrador}/grupo/historial', [NuevaVentaController::class, 'historialGrupo'])->name('grupo.historial');
            // R2: advertencia de rentabilidad del borrador (al asesor, sin costos).
            Route::get('{borrador}/rentabilidad', [NuevaVentaController::class, 'rentabilidad'])->name('rentabilidad');
        });
    });
});
