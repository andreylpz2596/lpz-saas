<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\AplicarTallasNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\BuscarClienteNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\ColoresNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\ConfirmarNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\CrearClienteNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\EntregaNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\IniciarConClienteNuevoNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\IniciarNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\MatrizNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\PagoNuevaVentaRequest;
use ByLopez\VentasAsesor\Services\Clientes\BuscarClienteComercialService;
use ByLopez\VentasAsesor\Services\Clientes\CrearClienteComercialService;
use ByLopez\VentasAsesor\Services\Venta\CatalogoVentaService;
use ByLopez\VentasAsesor\Services\Venta\NuevaVentaService;
use ByLopez\VentasAsesor\Support\Http\RespuestaErrorNuevaVenta as Respuesta;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Webkul\User\Contracts\Admin;

/**
 * API de "Nueva venta rapida" (03G-C): Request → autorizacion/estructura → los servicios
 * de dominio ya existentes (03B-03G-B2) → JSON. Ninguna regla de precio, stock, grupo,
 * variantes, solicitudes, Cart, envio, pago, confirmacion o atribucion vive aqui: todas
 * siguen en NuevaVentaService y los servicios que orquesta.
 *
 * Autorizacion: el permiso ACL de la ruta ('ventas_asesor.pedidos.create', Bouncer) mas
 * la del propio dominio (BorradorVentaService::PERMISO, propiedad del borrador, ventana
 * de reclamo) que cada metodo de NuevaVentaService ya exige antes de tocar nada. Un
 * borrador ajeno lanza BorradorVentaInvalidoException::NO_ES_PROPIETARIO (409), igual que
 * en el panel; esta capa no relaja ni repite esa regla.
 */
class NuevaVentaController extends Controller
{
    public function __construct(
        protected NuevaVentaService $nuevaVenta,
        protected CatalogoVentaService $catalogo,
        protected BuscarClienteComercialService $buscarCliente,
        protected CrearClienteComercialService $crearCliente,
    ) {}

    /** A. Bootstrap: canales y accesos rapidos permitidos, sin borrador todavia. */
    public function estadoInicial(): JsonResponse
    {
        return Respuesta::envolver(fn () => [
            'canales' => array_values((array) config('ventasasesor.nueva_venta.canales', [])),
            'accesos_rapidos' => array_map(fn ($a) => $a->toArray(), $this->catalogo->accesosRapidos()),
        ]);
    }

    /** B. Buscar cliente por telefono, email o nombre (identidad fuerte: telefono). */
    public function buscarCliente(BuscarClienteNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->buscarCliente->buscar($request->aConsulta())->toArray());
    }

    /** C. Alta minima de cliente comercial (RT-04), sin iniciar venta todavia. */
    public function crearCliente(CrearClienteNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(function () use ($request) {
            $cliente = $this->crearCliente->crear($request->aDatos());

            return ['customer_id' => (int) $cliente->id];
        });
    }

    /** D. Iniciar venta con un cliente ya identificado. Sin Cart hasta tener cliente valido. */
    public function iniciar(IniciarNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->iniciar(
            (int) $request->integer('customer_id'),
            $this->actor(),
            (string) $request->string('canal'),
            $request->string('referencia_externa')->trim()->value() ?: null,
        )->toArray());
    }

    /** D. Alta minima de cliente + venta nueva en una transaccion (telefono no encontrado). */
    public function iniciarConClienteNuevo(IniciarConClienteNuevoNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->iniciarConClienteNuevo(
            $request->aDatos(),
            $this->actor(),
            (string) $request->string('canal'),
            $request->string('referencia_externa')->trim()->value() ?: null,
        )->toArray());
    }

    /** E. Colores de un acceso rapido (linea x color), resuelto por config, nunca por id. */
    public function colores(string $clave, ColoresNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => array_map(
            fn ($c) => $c->toArray(),
            $this->catalogo->coloresDeAcceso($clave, $request->string('q')->trim()->value() ?: null, (int) $request->input('limite', 50)),
        ));
    }

    /** G. Matriz de tallas/variantes de un color (producto de primer nivel). */
    public function matriz(int $producto, MatrizNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->catalogo->matriz(
            $producto,
            (int) $request->integer('customer_id'),
            $this->actor(),
            1,
            $request->integer('borrador_id') ?: null,
        )->toArray());
    }

    /** F. Estado/resumen actual de la venta (K). */
    public function estado(int $borrador): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->estado($borrador, conMetodosDePago: (bool) request()->boolean('con_metodos_pago'))->toArray());
    }

    /** H. Lote de cantidades por variante (M6/L6): todo o nada. */
    public function aplicarTallas(int $borrador, AplicarTallasNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->aplicarTallas(
            $borrador,
            (int) $request->integer('producto_id'),
            $request->cantidadesPorVariante(),
            $this->actor(),
        )->toArray());
    }

    /** I. Facturacion + envio + metodo en un solo viaje. */
    public function entrega(int $borrador, EntregaNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->entregaRapida(
            $borrador,
            $this->actor(),
            $request->envioComoOrigen(),
            $request->string('metodo')->trim()->value() ?: null,
            $request->facturacionComoOrigen(),
        )->toArray());
    }

    /** J. Seleccionar metodo de pago (los ofrecidos se resuelven una vez). */
    public function pago(int $borrador, PagoNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->seleccionarPago(
            $borrador,
            (string) $request->string('metodo'),
            $this->actor(),
        )->toArray());
    }

    /** L. Confirmar con el total que el asesor vio en el estado. M. Resultado: Order real. */
    public function confirmar(int $borrador, ConfirmarNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->confirmar(
            $borrador,
            $this->actor(),
            (float) $request->input('total_esperado'),
        )->toArray());
    }

    protected function actor(): Admin
    {
        return auth()->guard('admin')->user();
    }
}
