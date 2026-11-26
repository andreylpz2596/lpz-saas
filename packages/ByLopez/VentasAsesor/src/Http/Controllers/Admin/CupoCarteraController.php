<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Exceptions\OperacionCupoInvalidaException;
use ByLopez\VentasAsesor\Services\Cupo\CupoCarteraClienteService;
use ByLopez\VentasAsesor\Services\Cupo\ExcesoCupoService;
use ByLopez\VentasAsesor\Services\Cupo\GestionCupoCarteraService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autorizacion;
use ByLopez\VentasAsesor\Support\Cartera\RespuestaErrorNoControlado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cupo de cartera por cliente (Doc. 35). Controlador delgado: la autoridad (permiso, administrador autorizado, bloqueo, historial) es de
 * los servicios; aqui solo se valida la FORMA y se traducen los rechazos a JSON con su codigo estable. Ninguna cifra se calcula aqui.
 */
class CupoCarteraController extends Controller
{
    public function __construct(
        protected Autorizacion $autorizacion,
        protected CupoCarteraClienteService $cupo,
        protected GestionCupoCarteraService $gestion,
        protected ExcesoCupoService $excesos,
    ) {}

    /**
     * Cupo de un cliente (+ decision para una obligacion nueva con `?monto=`). La usan Nueva venta (informativa; la autoridad es el
     * cerco del servidor) y la ficha. Sin la hoja `cupo.ver` responde 200 `visible:false` (no 403: el panel de Bagisto avisa por cada
     * 403 y aqui solo significa «no se ofrece»). El detalle por pedido solo con alcance de Cartera «todos».
     */
    public function resumen(int $cliente, Request $request): JsonResponse
    {
        $admin = auth()->guard('admin')->user();

        if (! $this->autorizacion->puedeCupo($admin, Autorizacion::PERMISO_CUPO_VER) || ! DB::table('customers')->where('id', $cliente)->exists()) {
            return response()->json(['visible' => false]);
        }

        $conDetalle = $request->boolean('detalle') && $this->autorizacion->alcanceDeCartera($admin) === AlcanceVisibilidad::TODOS;
        $cupo = $this->cupo->delCliente($cliente, $conDetalle);
        $puedeHistorial = $this->autorizacion->puedeCupo($admin, Autorizacion::PERMISO_CUPO_HISTORIAL);
        $puedeExceso = $this->autorizacion->puedeCupo($admin, Autorizacion::PERMISO_CUPO_AUTORIZAR_EXCESO);

        // «Quien lo cambio y por que» (y quien dio el exceso) es auditoria: solo con la hoja del historial o la de autorizar excesos.
        $datos = ($puedeHistorial || $puedeExceso ? $cupo : $cupo->sinAuditoria())->toArray();

        return response()->json([
            'visible' => true,
            'bloqueo_activo' => $this->cupo->bloqueoActivo(),
            'umbral_alerta_pct' => $this->cupo->umbralAlertaPct(),
            'cupo' => $datos,
            'evaluacion' => $request->filled('monto') ? $this->cupo->evaluar($cupo, (float) $request->input('monto'))->toArray() : null,
            'puede_editar' => $this->autorizacion->puedeCupo($admin, Autorizacion::PERMISO_CUPO_EDITAR),
            'puede_historial' => $puedeHistorial,
            'puede_exceso' => $puedeExceso,
        ]);
    }

    /** Historial de cambios del cupo + autorizaciones de exceso del cliente (auditoria completa; nada se borra). */
    public function historial(int $cliente): JsonResponse
    {
        return $this->ejecutar(function () use ($cliente) {
            $actor = auth()->guard('admin')->user();

            return [
                'historial' => $this->gestion->historial($actor, $cliente),
                'excesos' => $this->excesos->deCliente($actor, $cliente),
            ];
        });
    }

    /** «Autorizar exceso»: permite UNA venta por encima del cupo (monto y motivo obligatorios). */
    public function exceso(int $cliente, Request $request): JsonResponse
    {
        $datos = $request->validate([
            'exceso' => ['required', 'string', 'max:20'],
            'motivo' => ['required', 'string', 'max:'.ExcesoCupoService::MOTIVO_MAX],
        ]);

        return $this->ejecutar(fn () => ['exceso' => $this->excesos->autorizar(auth()->guard('admin')->user(), $cliente, $datos['exceso'], $datos['motivo'])]);
    }

    public function excesoAnular(int $excepcion, Request $request): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:'.ExcesoCupoService::MOTIVO_MAX]]);

        return $this->ejecutar(fn () => ['exceso' => $this->excesos->anular(auth()->guard('admin')->user(), $excepcion, $datos['motivo'])]);
    }

    public function editar(int $cliente, Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cupo' => ['required', 'string', 'max:20'],
            'estado' => ['required', 'string', 'in:activo,bloqueado'],
            'motivo' => ['required', 'string', 'max:'.GestionCupoCarteraService::MOTIVO_MAX],
            'cupo_esperado' => ['nullable', 'string', 'max:20'],
            'estado_esperado' => ['nullable', 'string', 'in:activo,bloqueado'],
        ]);

        return $this->ejecutar(fn () => $this->gestion->establecer(
            auth()->guard('admin')->user(), $cliente, $datos['cupo'], $datos['estado'], $datos['motivo'],
            $datos['cupo_esperado'] ?? null, $datos['estado_esperado'] ?? null,
        ));
    }

    /** @param  callable(): array<string, mixed>  $operacion */
    protected function ejecutar(callable $operacion): JsonResponse
    {
        try {
            return response()->json($operacion());
        } catch (OperacionCupoInvalidaException $e) {
            return response()->json(array_filter(['codigo' => $e->codigo, 'mensaje' => $e->getMessage(), 'detalles' => $e->detalles ?: null], fn ($v) => $v !== null), $e->estadoHttp());
        } catch (Throwable $e) {
            if (RespuestaErrorNoControlado::esDelFramework($e)) {
                throw $e;
            }

            return RespuestaErrorNoControlado::json($e);
        }
    }
}
