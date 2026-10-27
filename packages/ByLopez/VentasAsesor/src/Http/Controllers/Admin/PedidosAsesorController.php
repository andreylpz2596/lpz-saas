<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\Logistica\Support\AutorizacionLogistica;
use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Http\Requests\Pedidos\ListarPedidosAsesorRequest;
use ByLopez\VentasAsesor\Services\Pedidos\ConsultaPedidosAsesorService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\MediosCobro;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Webkul\User\Contracts\Admin;

/**
 * "Mis ventas / Pedidos" (OPERACION ASESOR). Una sola ruta GET:
 *  - sin `pedido`: listado filtrado y paginado en backend, acotado por alcance;
 *  - con `pedido=ID`: detalle de solo lectura, autorizado registro a registro.
 * Un unico endpoint evita una segunda ruta en la clave de agrupacion
 * `ventas_asesor.pedidos` (ver Config/acl.php, regla del redirect de login).
 */
class PedidosAsesorController extends Controller
{
    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected ConsultaPedidosAsesorService $consulta,
        protected MediosCobro $medios,
        protected AutorizacionLogistica $logistica,
    ) {}

    /**
     * Despacho V1: lo que el usuario puede hacer sobre el envio desde esta pantalla. Solo decide que botones se ofrecen;
     * la autorizacion real la repite el servicio de Logistica en cada operacion.
     *
     * @return array{puedeDespachar: bool, puedeVerEnvio: bool}
     */
    private function permisosDeEnvio(?Admin $admin): array
    {
        return [
            'puedeDespachar' => $this->logistica->tienePermiso($admin, AutorizacionLogistica::PERMISO_DESPACHAR),
            'puedeVerEnvio' => $this->logistica->tienePermiso($admin, AutorizacionLogistica::PERMISO_DESPACHOS),
        ];
    }

    public function index(ListarPedidosAsesorRequest $request): View
    {
        $admin = auth()->guard('admin')->user();

        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_MODULO), 401);

        $alcance = $this->autorizacion->alcanceDeVisibilidad($admin);

        // Sin view_own ni view_all no hay listado (tener solo la clave de agrupacion no basta).
        abort_if($alcance === AlcanceVisibilidad::NINGUNO, 403);

        $verTodos = $alcance === AlcanceVisibilidad::TODOS;

        if ($request->filled('pedido')) {
            $detalle = $this->consulta->detalle((int) $request->input('pedido'));

            abort_if($detalle === null, 404);

            // RA-6: sin atribucion valida y sin view_all → denegado; view_own → solo lo suyo.
            abort_unless($this->autorizacion->puedeVerRegistroDe($admin, $detalle['advisor_id']), 403);

            // PAG-C: el bloque Pago / Cartera lo ve quien tenga alcance de cartera sobre ESTE pedido; el boton
            // solo se ofrece con `cartera.registrar` (la autoridad real es RegistrarPagoService).
            $verCartera = $this->autorizacion->puedeVerCarteraDe($admin, $detalle['advisor_id']);

            return view('ventasasesor::admin.pedidos.detalle', $detalle + $this->permisosDeEnvio($admin) + [
                'verCartera' => $verCartera,
                // C6: «¿donde esta el dinero?» (custodia / conciliado); solo lectura, por la misma regla de alcance que Cartera.
                'caja' => $verCartera ? (app(\ByLopez\VentasAsesor\Services\Cartera\CajaPedidoService::class)->dePedidos([(int) $detalle['pedido']->id])[(int) $detalle['pedido']->id] ?? null) : null,
                'puedeRegistrarPago' => $verCartera
                    && $this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_CARTERA_REGISTRAR),
                'mediosCobro' => collect($this->medios->activos())->map(fn (array $m) => [
                    'codigo' => $m['codigo'],
                    'etiqueta' => $this->medios->etiqueta($m['codigo']),
                    'referencia' => $m['referencia'],
                    'plataforma' => $m['plataforma'],
                ])->values()->all(),
                'plataformasTransferencia' => collect($this->medios->plataformasActivas())
                    ->map(fn (string $etiqueta, string $codigo) => ['codigo' => $codigo, 'etiqueta' => $etiqueta])->values()->all(),
                'verTodos' => $verTodos,
                'volver' => route('admin.ventas_asesor.pedidos.index', $request->except(['pedido'])),
            ]);
        }

        $filtros = $request->filtros();

        return view('ventasasesor::admin.pedidos.index', $this->permisosDeEnvio($admin) + [
            'pedidos' => $this->consulta->listar($alcance, $admin, $filtros, (int) $request->input('page', 1))
                ->withQueryString(),
            'filtros' => $filtros,
            'indicadores' => $this->consulta->indicadores($alcance, $admin),
            'metodosEntrega' => $this->consulta->metodosEntrega($alcance, $admin),
            'asesores' => $verTodos ? $this->consulta->asesoresConPedidos() : [],
            'verTodos' => $verTodos,
        ]);
    }
}
