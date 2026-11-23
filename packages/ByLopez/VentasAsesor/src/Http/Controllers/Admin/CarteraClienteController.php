<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Exceptions\RegistroPagoInvalidoException;
use ByLopez\VentasAsesor\Http\Requests\Cartera\RegistrarAbonoClienteRequest;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cartera\AutoConfirmacionAdministrador;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarAbonoClienteService;
use ByLopez\VentasAsesor\Services\Credito\CreditoClienteService;
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

        // D10 V1: credito del cliente. Solo se calcula y se muestra con permiso de ver y alcance «todos»; los servicios revalidan al operar.
        $alcanceTotal = $this->autorizacion->alcanceDeCartera($admin) === AlcanceVisibilidad::TODOS;
        $verCredito = $alcanceTotal && $this->autorizacion->puedeCredito($admin, AutorizacionVentasAsesor::PERMISO_CREDITO_VER);
        $credito = $verCredito && $ficha['cartera']->carteraActiva ? app(CreditoClienteService::class)->delCliente($clienteId) : null;
        $tiene = fn (string $permiso) => $alcanceTotal && $this->autorizacion->puedeCredito($admin, $permiso);

        return view('ventasasesor::admin.cartera.cliente', $ficha + [
            'credito' => $credito,
            'puedeAplicar' => $tiene(AutorizacionVentasAsesor::PERMISO_CREDITO_APLICAR),
            'puedeReembolsar' => $tiene(AutorizacionVentasAsesor::PERMISO_CREDITO_REEMBOLSAR),
            'puedeReversar' => $tiene(AutorizacionVentasAsesor::PERMISO_CREDITO_REVERSAR),
            'puedeAbonar' => $this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_CARTERA_ABONO_GENERAL),
            // Estado de cuenta de WhatsApp: solo con alcance «todos» y Cartera activa (el servicio vuelve a autorizar).
            'puedeMensaje' => $alcanceTotal && $ficha['cartera']->carteraActiva,
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

        // Administrador: el abono recien registrado se confirma en el acto (si no se puede, queda sin revisar con el motivo).
        $auto = app(AutoConfirmacionAdministrador::class)
            ->despuesDeRegistrar(auth()->guard('admin')->user(), $resultado->pagoId, $resultado->estado, $resultado->duplicado);

        return response()->json(
            ['estado' => $auto['estado'], 'aviso_confirmacion' => $auto['aviso']] + $resultado->toArray(),
            $resultado->duplicado ? 200 : 201,
        );
    }
}
