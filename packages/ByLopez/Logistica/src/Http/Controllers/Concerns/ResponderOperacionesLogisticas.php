<?php

namespace ByLopez\Logistica\Http\Controllers\Concerns;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Support\ConflictoDeConcurrencia;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Contrato JSON unico del panel de Despachos: `{ok: true, ...}` o `{ok: false, codigo, mensaje, detalles}` con el
 * codigo HTTP que corresponde. El modal muestra `mensaje` tal cual; `codigo` es estable para pruebas.
 */
trait ResponderOperacionesLogisticas
{
    protected function envolver(Closure $operacion): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $operacion());
        } catch (Invalida $e) {
            return response()->json([
                'ok' => false,
                'codigo' => $e->codigo,
                'mensaje' => trans('logistica::app.errores.'.$e->codigo, $this->parametros($e)),
                'detalles' => $e->detalles,
            ], $this->estado($e->codigo));
        } catch (HttpExceptionInterface|ValidationException|AuthorizationException|AuthenticationException|ModelNotFoundException $e) {
            throw $e; // Laravel ya los responde (403/404/422) sin trazas.
        } catch (Throwable $e) {
            return $this->errorNoControlado($e);
        }
    }

    /**
     * F4: un error que no es de negocio no se muestra con trazas ni rutas. Bloqueo/espera agotados tras los reintentos de la
     * transaccion → 409 «vuelve a intentarlo»; cualquier otro → 500 con una referencia que queda en el log con el detalle
     * para diagnosticar (excepcion, mensaje, ruta, actor y traza).
     */
    protected function errorNoControlado(Throwable $e): JsonResponse
    {
        $referencia = (string) Str::uuid();
        $concurrencia = ConflictoDeConcurrencia::es($e);

        Log::error('bylopez.operacion_no_controlada', [
            'referencia' => $referencia,
            'excepcion' => $e::class,
            'mensaje' => $e->getMessage(),
            'ruta' => request()->method().' '.request()->path(),
            'actor_admin_id' => auth()->guard('admin')->id(),
            'traza' => $e->getTraceAsString(),
        ]);

        $codigo = $concurrencia ? 'operacion_concurrente' : 'error_inesperado';

        return response()->json([
            'ok' => false,
            'codigo' => $codigo,
            'mensaje' => trans('logistica::app.errores.'.$codigo, ['referencia' => $referencia]),
            'referencia' => $referencia,
        ], $concurrencia ? 409 : 500);
    }

    /**
     * Si el cuerpo supera `post_max_size`, PHP descarta TODO el formulario sin avisar y la peticion llega vacia: se
     * distingue de un formulario incompleto para dar el mensaje correcto (limite del SERVIDOR, no el logico de 5 MB).
     */
    protected function exigirCuerpoCompleto(Request $request): void
    {
        if ((int) $request->server('CONTENT_LENGTH') > 0 && $request->all() === [] && $request->allFiles() === []) {
            throw new Invalida(Invalida::ARCHIVO_LIMITE_SERVIDOR, ['limite' => (string) ini_get('post_max_size')]);
        }
    }

    private function estado(string $codigo): int
    {
        return match (true) {
            $codigo === Invalida::SIN_PERMISO, $codigo === Invalida::SIN_ACCESO_GUIA, $codigo === Invalida::AJUSTE_NO_AUTORIZADO => 403,
            in_array($codigo, [Invalida::PEDIDO_INEXISTENTE, Invalida::GUIA_INEXISTENTE, Invalida::ENVIO_INEXISTENTE, Invalida::REGISTRO_INEXISTENTE, Invalida::GUIA_SIN_ARCHIVO, Invalida::INCIDENCIA_INEXISTENTE, Invalida::CUENTA_INEXISTENTE, Invalida::LIQUIDACION_INEXISTENTE], true) => 404,
            in_array($codigo, [
                Invalida::IDEMPOTENCIA_CONFLICTO, Invalida::GUIA_DUPLICADA, Invalida::CANTIDAD_EXCEDE_PENDIENTE,
                Invalida::STOCK_INSUFICIENTE, Invalida::PEDIDO_NO_DESPACHABLE, Invalida::DESPACHO_FALLIDO,
                Invalida::INCIDENCIA_ABIERTA, Invalida::INCIDENCIA_YA_RESUELTA, Invalida::AJUSTE_FALLIDO,
                Invalida::RETORNO_ANULADO, Invalida::RECEPCION_YA_REGISTRADA, Invalida::BODEGA_ORIGEN_INDETERMINADA, Invalida::RETORNO_CON_RECEPCION,
                Invalida::RECEPCION_YA_REINGRESADA, Invalida::REINGRESO_FALLIDO,
                Invalida::VALIDACION_YA_REGISTRADA, Invalida::VALIDACION_CON_PENDIENTES, Invalida::VALIDACION_DEVOLUCION_SIN_RECIBIR, Invalida::ENVIO_VALIDADO,
                Invalida::MOVIMIENTOS_YA_LIQUIDADOS, Invalida::LIQUIDACION_CERRADA, Invalida::REVISION_NO_APLICA,
            ], true) => 409,
            in_array($codigo, [Invalida::ARCHIVO_MUY_GRANDE, Invalida::ARCHIVO_LIMITE_SERVIDOR], true) => 413,
            default => 422,
        };
    }

    /** @return array<string, mixed> valores para los marcadores del mensaje (nunca faltan) */
    private function parametros(Invalida $e): array
    {
        return $e->detalles + [
            'pendientes' => '', 'solicitadas' => '', 'codigo' => '', 'numero_guia' => '', 'sku' => '', 'necesarias' => '',
            'disponibles' => '', 'conteo' => '', 'sistema' => '', 'maximo_mb' => 5, 'maximo' => '', 'causa' => '', 'limite' => (string) ini_get('upload_max_filesize'),
        ];
    }
}
