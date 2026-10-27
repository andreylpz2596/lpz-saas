<?php

namespace ByLopez\Logistica\Http\Controllers\Admin;

use ByLopez\Logistica\DataObjects\SolicitudDespacho;
use ByLopez\Logistica\DataObjects\SolicitudRecogida;
use ByLopez\Logistica\Http\Controllers\Concerns\ResponderOperacionesLogisticas;
use ByLopez\Logistica\Services\Despachos\ConsultaDespachoService;
use ByLopez\Logistica\Services\Despachos\ContextoDespachoService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Stock\IncidenciasStockService;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Webkul\User\Contracts\Admin;

/**
 * Logistica › Despachos. Controlador delgado: la bandeja lee de ConsultaDespachoService; el contexto del modal, de
 * ContextoDespachoService; registrar, de DespachoService (que valida permiso, cantidades, inventario e idempotencia).
 * El MISMO endpoint sirve a la bandeja de BODEGA y al listado de Pedidos del Administrador.
 */
class DespachosController extends Controller
{
    use ResponderOperacionesLogisticas;

    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected ConsultaDespachoService $consulta,
        protected ContextoDespachoService $contexto,
        protected DespachoService $despachos,
        protected IncidenciasStockService $incidencias,
    ) {}

    public function index(Request $request): View
    {
        $admin = $this->actor();
        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_DESPACHOS), 401);

        $filtros = [
            'q' => ($q = trim((string) $request->query('q', ''))) === '' ? null : mb_substr($q, 0, 100),
            'estado' => in_array($request->query('estado'), ConsultaDespachoService::ESTADOS, true) ? $request->query('estado') : 'por_despachar',
            'tipo' => in_array($request->query('tipo'), ConsultaDespachoService::TIPOS, true) ? $request->query('tipo') : 'todos',
        ];

        // Pestana «Incidencias de stock»: solo para quien puede verlas; el resto la ve como la bandeja normal.
        $puedeVerIncidencias = $this->incidencias->puedeVer($admin);
        $vista = $puedeVerIncidencias && $request->query('vista') === 'incidencias' ? 'incidencias' : 'pedidos';

        if ($vista === 'incidencias') {
            $filtrosIncidencias = [
                'estado' => $request->query('estado') === 'todas' ? 'todas' : 'abiertas',
                'q' => $filtros['q'],
            ];
        }

        return view('logistica::admin.despachos.index', [
            'vista' => $vista,
            'puedeVerIncidencias' => $puedeVerIncidencias,
            'incidenciasAbiertas' => $puedeVerIncidencias ? $this->incidencias->abiertas() : 0,
            'incidencias' => $vista === 'incidencias' ? $this->incidencias->listar($filtrosIncidencias, (int) $request->query('page', 1))->withQueryString() : null,
            'filtrosIncidencias' => $filtrosIncidencias ?? ['estado' => 'abiertas', 'q' => null],
            'pedidos' => $vista === 'pedidos' ? $this->consulta->listar($filtros, (int) $request->query('page', 1))->withQueryString() : collect(),
            'filtros' => $filtros,
            'indicadores' => $this->consulta->indicadores(),
            'puedeDespachar' => $this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_DESPACHAR),
            'puedeVerGuia' => $this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_GUIAS_VER),
        ]);
    }

    public function contexto(int $pedido): JsonResponse
    {
        return $this->envolver(fn () => ['contexto' => $this->contexto->paraPedido($pedido, $this->actor())]);
    }

    public function despachar(int $pedido, Request $request): JsonResponse
    {
        return $this->envolver(function () use ($pedido, $request) {
            $this->exigirCuerpoCompleto($request);

            $r = $this->despachos->despachar($this->actor(), new SolicitudDespacho(
                orderId: $pedido,
                idempotencyKey: (string) $request->input('idempotency_key'),
                transportadora: (string) $request->input('transportadora'),
                numeroGuia: (string) $request->input('numero_guia'),
                origenGuia: (string) $request->input('origen_guia'),
                fletePagador: (string) $request->input('flete_pagador'),
                unidades: (array) $request->input('unidades', []),
                valorFlete: $request->input('valor_flete'),
                recaudoEsperado: $request->input('recaudo_esperado'),
                tracking: $request->input('tracking'),
                observacion: $request->input('observacion'),
                zonaId: $request->filled('zona_id') ? (int) $request->input('zona_id') : null,
                motivoPagador: $request->input('motivo_pagador'),
                motivoCosto: $request->input('motivo_costo'),
                motivoRecaudo: $request->input('motivo_recaudo'),
                archivo: $request->file('archivo'),
            ));

            return [
                'pedido_id' => $r->orderId,
                'envio_id' => $r->shipmentId,
                'guia_id' => $r->guia?->id,
                'ya_existia' => $r->yaExistia,
                'mensaje' => trans($r->yaExistia ? 'logistica::app.despachos.flash.ya-registrado' : 'logistica::app.despachos.flash.despachado', ['guia' => $r->guia?->numero_guia]),
            ];
        });
    }

    public function entregarEnBodega(int $pedido, Request $request): JsonResponse
    {
        return $this->envolver(function () use ($pedido, $request) {
            $r = $this->despachos->entregarEnBodega($this->actor(), new SolicitudRecogida(
                orderId: $pedido,
                idempotencyKey: (string) $request->input('idempotency_key'),
                unidades: (array) $request->input('unidades', []),
                quienRetira: $request->input('quien_retira'),
            ));

            return [
                'pedido_id' => $r->orderId,
                'envio_id' => $r->shipmentId,
                'entrega_id' => $r->entrega?->id,
                'ya_existia' => $r->yaExistia,
                'mensaje' => trans($r->yaExistia ? 'logistica::app.despachos.flash.ya-registrado' : 'logistica::app.despachos.flash.entregado-bodega'),
            ];
        });
    }

    private function actor(): ?Admin
    {
        return auth()->guard('admin')->user();
    }
}
