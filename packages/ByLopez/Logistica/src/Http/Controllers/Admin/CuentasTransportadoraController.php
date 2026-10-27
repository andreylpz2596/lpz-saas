<?php

namespace ByLopez\Logistica\Http\Controllers\Admin;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Http\Controllers\Concerns\ResponderOperacionesLogisticas;
use ByLopez\Logistica\Services\Cuentas\AjusteCuentaService;
use ByLopez\Logistica\Services\Cuentas\CobroReportadoService;
use ByLopez\Logistica\Services\Cuentas\ConsultaCuentasService;
use ByLopez\Logistica\Services\Cuentas\LiquidarCuentaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Cuentas de transportadoras: listado (Blade) + drawer (JSON). Capa delgada: cada servicio vuelve a validar permiso,
 * importes, movimientos incluidos, estado e idempotencia. Del navegador no se acepta ningun saldo.
 */
class CuentasTransportadoraController extends Controller
{
    use ResponderOperacionesLogisticas;

    public function __construct(
        protected ConsultaCuentasService $consulta,
        protected LiquidarCuentaService $liquidar,
        protected AjusteCuentaService $ajustes,
        protected CobroReportadoService $cobros,
    ) {}

    public function index(): View
    {
        $actor = auth()->guard('admin')->user();

        try {
            $this->consulta->exigirVer($actor);
        } catch (Invalida) {
            abort(403);
        }

        return view('logistica::admin.cuentas.index', $this->consulta->listado());
    }

    public function contexto(int $transportadora, Request $request): JsonResponse
    {
        return $this->envolver(fn () => ['contexto' => $this->consulta->contexto(
            auth()->guard('admin')->user(), $transportadora, $request->only(['estado', 'concepto', 'desde', 'hasta', 'q']),
        )]);
    }

    public function liquidar(int $transportadora, Request $request): JsonResponse
    {
        return $this->envolver(function () use ($transportadora, $request) {
            $actor = auth()->guard('admin')->user();
            $l = $this->liquidar->liquidar($actor, $transportadora, $request->only(['movimientos', 'valor', 'observacion', 'referencia', 'idempotency_key', 'atribucion', 'motivo_atribucion']));

            return [
                'mensaje' => trans('logistica::app.cuentas.ok-liquidada', ['numero' => $l->numero()]),
                'liquidacion_id' => (int) $l->id,
                'contexto' => $this->consulta->contexto($actor, $transportadora),
            ];
        });
    }

    /** C6: vista previa de la conciliacion (no escribe nada). */
    public function propuesta(int $transportadora, Request $request): JsonResponse
    {
        return $this->envolver(fn () => ['propuesta' => $this->consulta->propuesta(auth()->guard('admin')->user(), $transportadora, $request->only(['valor', 'atribucion']))]);
    }

    /** C6: cobro REPORTADO por una transportadora externa (hecho distinto de liquidar). */
    public function cobro(int $transportadora, Request $request): JsonResponse
    {
        return $this->envolver(function () use ($transportadora, $request) {
            $actor = auth()->guard('admin')->user();
            $r = $this->cobros->registrar($actor, $transportadora, $request->only(['guia_id', 'valor', 'fecha', 'referencia', 'idempotency_key']));

            return ['mensaje' => trans($r['confirmado'] ? 'logistica::app.cuentas.ok-cobro' : 'logistica::app.cuentas.ok-cobro-por-confirmar'), 'contexto' => $this->consulta->contexto($actor, $transportadora)];
        });
    }

    public function ajustar(int $transportadora, Request $request): JsonResponse
    {
        return $this->envolver(function () use ($transportadora, $request) {
            $actor = auth()->guard('admin')->user();
            $this->ajustes->registrarAjuste($actor, $transportadora, $request->only(['tipo', 'concepto', 'valor', 'motivo', 'referencia', 'guia_id', 'idempotency_key']));

            return ['mensaje' => trans('logistica::app.cuentas.ok-ajuste'), 'contexto' => $this->consulta->contexto($actor, $transportadora)];
        });
    }

    public function revision(int $transportadora, int $envio, Request $request): JsonResponse
    {
        return $this->envolver(function () use ($transportadora, $envio, $request) {
            $actor = auth()->guard('admin')->user();
            $this->ajustes->confirmarRevision($actor, $envio, (string) $request->input('concepto'), $request->input('valor'), $request->input('motivo'), $request->input('idempotency_key'));

            return ['mensaje' => trans('logistica::app.cuentas.ok-revision'), 'contexto' => $this->consulta->contexto($actor, $transportadora)];
        });
    }

    public function liquidacion(int $liquidacion): JsonResponse
    {
        return $this->envolver(fn () => $this->consulta->liquidacion(auth()->guard('admin')->user(), $liquidacion));
    }
}
