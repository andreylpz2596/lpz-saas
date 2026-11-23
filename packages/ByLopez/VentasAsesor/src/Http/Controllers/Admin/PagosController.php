<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\RegistroPagoInvalidoException;
use ByLopez\VentasAsesor\Http\Requests\Cartera\RegistrarPagoRequest;
use ByLopez\VentasAsesor\Models\PagoEvento;
use ByLopez\VentasAsesor\Services\Cartera\AutoConfirmacionAdministrador;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use ByLopez\VentasAsesor\Support\Cartera\AlcanceDePagos;
use ByLopez\VentasAsesor\Support\Cartera\RespuestaErrorNoControlado;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pagos / Cartera (PAG-C). Controlador delgado: toda la logica (autorizacion, bloqueo, saldo, idempotencia)
 * vive en RegistrarPagoService. Aqui solo se traduce el resultado o el rechazo a JSON.
 */
class PagosController extends Controller
{
    public function __construct(
        protected RegistrarPagoService $registrar,
        protected AutoConfirmacionAdministrador $autoConfirmacion,
    ) {}

    public function comprobante(int $pago, int $evento)
    {
        abort_unless(app(AlcanceDePagos::class)->incluye(auth()->guard('admin')->user(), $pago), 404);
        $evidencia = PagoEvento::query()->where('pago_id', $pago)->where('tipo', TipoEventoPago::EVIDENCIA_AGREGADA->value)->findOrFail($evento);
        $ruta = $evidencia->datos['ruta'] ?? '';
        abort_unless(str_starts_with($ruta, 'ventas-asesor/pagos/') && ! str_contains($ruta, '..') && Storage::disk('private')->exists($ruta), 404);

        return Storage::disk('private')->download($ruta, $evidencia->datos['nombre'] ?? 'comprobante', ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function store(int $pedido, RegistrarPagoRequest $request): JsonResponse
    {
        try {
            if ($request->esCombinado()) {
                $combinado = $this->registrar->registrarCombinado(
                    auth()->guard('admin')->user(),
                    $pedido,
                    $request->validated('partes'),
                    $request->safe()->only(['fecha_pago', 'observacion']),
                    (string) $request->input('idempotency_key'),
                );

                $ultimo = end($combinado['pagos']);
                $actor = auth()->guard('admin')->user();
                $pagos = array_map(fn ($r) => $this->conAutoConfirmacion($actor, $r->toArray()), $combinado['pagos']);

                return response()->json([
                    'combinado' => true,
                    'grupo' => $combinado['grupo'],
                    'pagos' => $pagos,
                    'confirmado' => collect($pagos)->every(fn ($p) => $p['estado'] === 'confirmado'),
                    'aviso_confirmacion' => collect($pagos)->pluck('aviso_confirmacion')->filter()->first(),
                    'duplicado' => collect($combinado['pagos'])->every(fn ($r) => $r->duplicado),
                    'saldo' => $ultimo->toArray()['saldo'] ?? null,
                ], 201);
            }

            $resultado = $this->registrar->registrar(
                auth()->guard('admin')->user(),
                $pedido,
                $request->safe()->only(['monto', 'medio_cobro', 'plataforma', 'fecha_pago', 'referencia', 'observacion', 'comprobante']),
                (string) $request->input('idempotency_key'),
            );
        } catch (RegistroPagoInvalidoException $e) {
            return response()->json(array_filter([
                'codigo' => $e->codigo,
                'mensaje' => $e->getMessage(),
                'detalles' => $e->detalles === [] ? null : $e->detalles,
            ], fn ($v) => $v !== null), $e->estadoHttp());
        } catch (Throwable $e) {
            if (RespuestaErrorNoControlado::esDelFramework($e)) {
                throw $e;
            }

            return RespuestaErrorNoControlado::json($e);
        }

        $datos = $this->conAutoConfirmacion(auth()->guard('admin')->user(), $resultado->toArray());

        return response()->json($datos + ['confirmado' => $datos['estado'] === 'confirmado'], $resultado->duplicado ? 200 : 201);
    }

    /** Administrador: el pago recien registrado se confirma en el acto (si no se puede, queda sin revisar con el motivo). */
    protected function conAutoConfirmacion($actor, array $pago): array
    {
        $r = $this->autoConfirmacion->despuesDeRegistrar($actor, (int) $pago['pago_id'], (string) $pago['estado'], (bool) $pago['duplicado']);

        return ['estado' => $r['estado'], 'aviso_confirmacion' => $r['aviso']] + $pago;
    }
}
