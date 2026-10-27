<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Exceptions\RegistroPagoInvalidoException;
use ByLopez\VentasAsesor\Http\Requests\Cartera\RegistrarAbonoClienteRequest;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarAbonoClienteService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\MediosCobro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Cartera del cliente + abono general (PAG-D). Controlador delgado: la cartera sale de CarteraConsultaService y el
 * abono de RegistrarAbonoClienteService. Una sola ruta GET (`?cliente=ID`, como `pedidos?pedido=ID`) para que la
 * clave agrupadora `ventas_asesor.cartera` apunte a una ruta sin parametros (regla 3 de Config/acl.php).
 */
class CarteraClienteController extends Controller
{
    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected CarteraConsultaService $consulta,
        protected RegistrarAbonoClienteService $abonos,
        protected MediosCobro $medios,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $admin = auth()->guard('admin')->user();

        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_MODULO), 401);

        // Sin view_own ni view_all no hay cartera (tener solo la clave agrupadora no basta).
        abort_if($this->autorizacion->alcanceDeCartera($admin) === AlcanceVisibilidad::NINGUNO, 403);

        $clienteId = (int) $request->query('cliente');

        if ($clienteId < 1) {
            return redirect()->route('admin.ventas_asesor.inicio.index');
        }

        $abono = $request->query('abono');
        $ficha = $this->consulta->fichaDelCliente($clienteId, $admin, is_numeric($abono) ? (int) $abono : null);

        abort_if($ficha === null, 404);

        return view('ventasasesor::admin.cartera.cliente', $ficha + [
            'puedeAbonar' => $this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_CARTERA_ABONO_GENERAL),
            'mediosCobro' => collect($this->medios->activos())->map(fn (array $m) => [
                'codigo' => $m['codigo'],
                'etiqueta' => $this->medios->etiqueta($m['codigo']),
                'referencia' => $m['referencia'],
                'plataforma' => $m['plataforma'],
            ])->values()->all(),
            'plataformasTransferencia' => collect($this->medios->plataformasActivas())
                ->map(fn (string $etiqueta, string $codigo) => ['codigo' => $codigo, 'etiqueta' => $etiqueta])->values()->all(),
        ]);
    }

    public function abonar(int $cliente, RegistrarAbonoClienteRequest $request): JsonResponse
    {
        try {
            $resultado = $this->abonos->registrarAbono(
                auth()->guard('admin')->user(),
                $cliente,
                $request->safe()->only(['monto', 'medio_cobro', 'plataforma', 'fecha_pago', 'referencia', 'observacion']),
                $request->validated('distribucion'),
                (string) $request->input('idempotency_key'),
            );
        } catch (RegistroPagoInvalidoException $e) {
            return response()->json(array_filter([
                'codigo' => $e->codigo,
                'mensaje' => $e->getMessage(),
                'detalles' => $e->detalles === [] ? null : $e->detalles,
            ], fn ($v) => $v !== null), $e->estadoHttp());
        }

        return response()->json($resultado->toArray(), $resultado->duplicado ? 200 : 201);
    }
}
