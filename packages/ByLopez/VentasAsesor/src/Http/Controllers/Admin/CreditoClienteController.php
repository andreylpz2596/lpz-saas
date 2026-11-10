<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Exceptions\OperacionCreditoInvalidaException;
use ByLopez\VentasAsesor\Services\Credito\AjusteObligacionService;
use ByLopez\VentasAsesor\Services\Credito\AplicarCreditoService;
use ByLopez\VentasAsesor\Services\Credito\CreditoClienteService;
use ByLopez\VentasAsesor\Services\Credito\ReembolsarCreditoService;
use ByLopez\VentasAsesor\Services\Credito\ReversarAplicacionCreditoService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\RespuestaErrorNoControlado;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Credito del cliente (D10 V1). Controlador delgado: la autoridad (permiso, alcance «todos», bloqueos, idempotencia, montos) es de
 * los servicios; aqui solo se valida la FORMA y se traducen los rechazos de negocio a JSON con su codigo estable.
 */
class CreditoClienteController extends Controller
{
    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected CreditoClienteService $credito,
    ) {}

    /** Credito de un pedido (como origen) + su historial; y, con `?cliente=1`, el disponible del cliente y de donde proviene. */
    public function pedido(int $pedido, Request $request): JsonResponse
    {
        $admin = auth()->guard('admin')->user();

        abort_unless($this->autorizacion->puedeCredito($admin, AutorizacionVentasAsesor::PERMISO_CREDITO_VER)
            && $this->autorizacion->alcanceDeCartera($admin) === AlcanceVisibilidad::TODOS, 403);

        $cliente = DB::table('orders')->where('id', $pedido)->value('customer_id');
        abort_if($cliente === null, 404);

        return response()->json([
            'pedido' => $this->credito->dePedido($pedido),
            'movimientos' => $this->credito->movimientosDe($pedido),
            'ajustes' => $this->credito->ajustesDe($pedido),
            'cliente' => $request->boolean('cliente') ? $this->credito->delCliente((int) $cliente) : null,
        ]);
    }

    /** Credito disponible de un cliente (Nueva venta). Sin permiso de ver o sin alcance «todos» responde `visible:false`: la pantalla simplemente no lo ofrece. */
    public function cliente(int $cliente): JsonResponse
    {
        $admin = auth()->guard('admin')->user();
        $alcanceTotal = $this->autorizacion->alcanceDeCartera($admin) === AlcanceVisibilidad::TODOS;

        // 200 con `visible:false` (y no 403): el panel de Bagisto muestra un aviso por cada 403 y aqui solo significa «no se ofrece».
        if (! $alcanceTotal || ! $this->autorizacion->puedeCredito($admin, AutorizacionVentasAsesor::PERMISO_CREDITO_VER)) {
            return response()->json(['visible' => false]);
        }

        $c = $this->credito->delCliente($cliente);

        return response()->json([
            'visible' => true,
            'total_disponible' => $c['total_disponible'],
            'puede_aplicar' => $this->autorizacion->puedeCredito($admin, AutorizacionVentasAsesor::PERMISO_CREDITO_APLICAR),
            'origenes' => array_values(array_map(fn ($f) => ['order_id' => $f['order_id'], 'increment_id' => $f['increment_id'], 'disponible' => $f['disponible']],
                array_filter($c['pedidos'], fn ($f) => $f['disponible'] > 0))),
        ]);
    }

    /** Comprobante de un reembolso: disco privado, solo con permiso de ver credito + alcance «todos». Nunca hay URL publica. */
    public function comprobante(int $movimiento)
    {
        $admin = auth()->guard('admin')->user();

        abort_unless($this->autorizacion->puedeCredito($admin, AutorizacionVentasAsesor::PERMISO_CREDITO_VER)
            && $this->autorizacion->alcanceDeCartera($admin) === AlcanceVisibilidad::TODOS, 403);

        $ruta = DB::table('bylopez_va_creditos_cliente_movimientos')->where('id', $movimiento)->where('tipo', 'reembolso')->value('comprobante_ruta');
        $disco = Storage::disk((string) config('ventasasesor.comisiones.comprobantes.disco', 'private'));

        abort_if($ruta === null || ! $disco->exists($ruta), 404);

        return $disco->response($ruta, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function aplicar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'origen' => ['required', 'integer', 'min:1'], 'destino' => ['required', 'integer', 'min:1'],
            'monto' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'], 'motivo' => ['nullable', 'string', 'max:500'], 'idempotency_key' => ['required', 'string', 'min:16', 'max:80'],
        ]);

        return $this->ejecutar(fn () => app(AplicarCreditoService::class)->aplicar(
            auth()->guard('admin')->user(), (int) $d['origen'], (int) $d['destino'], $d['monto'], $d['idempotency_key'], $d['motivo'] ?? null,
        ), 201);
    }

    public function reembolsar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'origen' => ['required', 'integer', 'min:1'], 'monto' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'medio' => ['required', 'string', 'max:40'], 'referencia' => ['nullable', 'string', 'max:100'], 'motivo' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:80'], 'comprobante' => ['nullable', 'file', 'mimes:'.implode(',', (array) config('ventasasesor.comisiones.comprobantes.mimes')), 'max:'.(int) config('ventasasesor.comisiones.comprobantes.max_kb')],
        ]);

        return $this->ejecutar(function () use ($d, $request) {
            $disco = (string) config('ventasasesor.comisiones.comprobantes.disco', 'private');
            // store() genera un nombre unico: jamas sobrescribe otro comprobante.
            $ruta = $request->file('comprobante')?->store('ventas-asesor/credito', $disco) ?: null;

            try {
                $r = app(ReembolsarCreditoService::class)->reembolsar(
                    auth()->guard('admin')->user(), (int) $d['origen'], $d['monto'], $d['medio'], $d['referencia'] ?? null, $d['motivo'], $d['idempotency_key'], $ruta,
                );
            } catch (Throwable $e) {
                $ruta !== null && Storage::disk($disco)->delete($ruta);

                throw $e;
            }

            // Repeticion idempotente: el movimiento ya tenia su comprobante; el archivo recien subido sobra.
            ($r['duplicado'] ?? false) && $ruta !== null && Storage::disk($disco)->delete($ruta);

            return $r;
        }, 201);
    }

    public function reversar(int $movimiento, Request $request): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500'], 'idempotency_key' => ['required', 'string', 'min:16', 'max:80']]);

        return $this->ejecutar(fn () => app(ReversarAplicacionCreditoService::class)->reversar(
            auth()->guard('admin')->user(), $movimiento, $d['motivo'], $d['idempotency_key'],
        ));
    }

    public function ajustar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'pedido' => ['required', 'integer', 'min:1'], 'monto' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'], 'causa' => ['required', 'string', 'max:40'],
            'motivo' => ['required', 'string', 'max:500'], 'idempotency_key' => ['required', 'string', 'min:16', 'max:80'],
        ]);

        return $this->ejecutar(fn () => app(AjusteObligacionService::class)->condonarFlete(
            auth()->guard('admin')->user(), (int) $d['pedido'], $d['monto'], $d['causa'], $d['motivo'], $d['idempotency_key'],
        ), 201);
    }

    public function reversarAjuste(int $ajuste, Request $request): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500'], 'idempotency_key' => ['required', 'string', 'min:16', 'max:80']]);

        return $this->ejecutar(fn () => app(AjusteObligacionService::class)->reversar(
            auth()->guard('admin')->user(), $ajuste, $d['motivo'], $d['idempotency_key'],
        ));
    }

    /** @param  Closure(): array<string, mixed>  $operacion */
    protected function ejecutar(Closure $operacion, int $estadoNuevo = 200): JsonResponse
    {
        try {
            $r = $operacion();
        } catch (OperacionCreditoInvalidaException $e) {
            return response()->json(array_filter(['codigo' => $e->codigo, 'mensaje' => $e->getMessage(), 'detalles' => $e->detalles ?: null], fn ($v) => $v !== null), $e->estadoHttp());
        } catch (Throwable $e) {
            if (RespuestaErrorNoControlado::esDelFramework($e)) {
                throw $e;
            }

            return RespuestaErrorNoControlado::json($e);
        }

        return response()->json($r, ($r['duplicado'] ?? false) ? 200 : $estadoNuevo);
    }
}
