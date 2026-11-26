<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Exceptions\RevisionPagoInvalidaException;
use ByLopez\VentasAsesor\Http\Requests\Cartera\ListarPagosRequest;
use ByLopez\VentasAsesor\Http\Requests\Cartera\RechazarPagoRequest;
use ByLopez\VentasAsesor\Services\Cartera\BandejaPagosService;
use ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RechazarPagoService;
use ByLopez\VentasAsesor\Services\Cupo\CupoCarteraPanelService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use ByLopez\VentasAsesor\Support\Cartera\RespuestaErrorNoControlado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cartera / Pagos (PAG-E). Una sola ruta GET (`cartera`) como `pedidos`: sin parametros = bandeja central de pagos;
 * `?pago=ID` = detalle de un pago; `?cliente=ID` = cartera de un cliente (CarteraClienteController, PAG-D). Controlador
 * delgado: la lectura sale de BandejaPagosService y las acciones de ConfirmarPagoService / RechazarPagoService.
 */
class CarteraController extends Controller
{
    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected BandejaPagosService $bandeja,
        protected ConfirmarPagoService $confirmacion,
        protected RechazarPagoService $rechazo,
        protected FechaCorteCartera $fechaCorte,
        protected CarteraClienteController $cliente,
    ) {}

    public function index(ListarPagosRequest $request): View|RedirectResponse|StreamedResponse
    {
        $admin = auth()->guard('admin')->user();

        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_MODULO), 401);

        // Sin view_own ni view_all no hay cartera (tener solo la clave agrupadora no basta).
        abort_if($this->autorizacion->alcanceDeCartera($admin) === AlcanceVisibilidad::NINGUNO, 403);

        if ($request->filled('comprobante')) {
            $request->validate(['pago' => ['required', 'integer', 'min:1'], 'comprobante' => ['required', 'integer', 'min:1']]);

            return app(PagosController::class)->comprobante((int) $request->input('pago'), (int) $request->input('comprobante'));
        }

        if ($request->filled('cliente')) {
            return $this->cliente->show($request);
        }

        $comunes = [
            'puedeRevisar' => $this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_CARTERA_REVISAR),
            'motivosRechazo' => collect((array) config('ventasasesor.pagos.motivos_rechazo'))
                ->map(fn ($clave, $codigo) => ['codigo' => $codigo, 'etiqueta' => trans($clave)])->values()->all(),
            'carteraActiva' => $this->fechaCorte->activa(),
        ];

        // Cartera inactiva: falla cerrado, sin tocar las tablas de pagos.
        if (! $this->fechaCorte->activa()) {
            return view('ventasasesor::admin.pagos.index', $comunes + ['pagos' => null, 'filtros' => $request->filtros(), 'indicadores' => null, 'opciones' => ['medios' => [], 'registradores' => []]]);
        }

        if ($request->filled('pago')) {
            $detalle = $this->bandeja->detalle($admin, (int) $request->input('pago'));

            abort_if($detalle === null, 404);

            return view('ventasasesor::admin.pagos.detalle', $detalle + $comunes);
        }

        return view('ventasasesor::admin.pagos.index', $comunes + [
            // Doc. 35: panel compacto de cupos (solo con la hoja cartera.cupo.ver y alcance de Cartera «todos»; null = no se muestra).
            'cupoPanel' => app(CupoCarteraPanelService::class)->resumen($admin),
            'pagos' => $this->bandeja->listar($admin, $request->filtros(), (int) $request->input('page', 1))->withQueryString(),
            'filtros' => $request->filtros(),
            'indicadores' => $this->bandeja->indicadores($admin),
            'opciones' => $this->bandeja->opcionesDeFiltro($admin),
        ]);
    }

    public function confirmar(int $pago): JsonResponse
    {
        return $this->responder(fn () => $this->confirmacion->confirmar(auth()->guard('admin')->user(), $pago));
    }

    public function rechazar(int $pago, RechazarPagoRequest $request): JsonResponse
    {
        return $this->responder(fn () => $this->rechazo->rechazar(
            auth()->guard('admin')->user(),
            $pago,
            (string) $request->input('categoria'),
            (string) $request->input('motivo'),
        ));
    }

    private function responder(callable $accion): JsonResponse
    {
        try {
            $resultado = $accion();
        } catch (RevisionPagoInvalidaException $e) {
            return response()->json(array_filter([
                'codigo' => $e->codigo,
                'mensaje' => $e->getMessage(),
                'detalles' => $e->detalles === [] ? null : $e->detalles,
            ], fn ($v) => $v !== null), $e->estadoHttp());
        } catch (\Throwable $e) {
            if (RespuestaErrorNoControlado::esDelFramework($e)) {
                throw $e;
            }

            return RespuestaErrorNoControlado::json($e);
        }

        return response()->json($resultado->toArray());
    }
}
