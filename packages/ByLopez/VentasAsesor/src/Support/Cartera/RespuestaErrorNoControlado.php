<?php

namespace ByLopez\VentasAsesor\Support\Cartera;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * F3/F4 (auditoria 2026-10-21): respuesta de las operaciones de dinero ante un error que NO es de negocio. Nunca muestra
 * trazas ni rutas; deja en el log una referencia con el detalle para diagnosticar. Bloqueo o espera agotados despues de
 * los reintentos de la transaccion → 409 «vuelve a intentarlo»; cualquier otro → 500 con la referencia.
 */
class RespuestaErrorNoControlado
{
    /** Errores que Laravel ya responde bien (403/404/422) y se relanzan tal cual. */
    public static function esDelFramework(Throwable $e): bool
    {
        return $e instanceof HttpExceptionInterface || $e instanceof ValidationException || $e instanceof AuthorizationException
            || $e instanceof AuthenticationException || $e instanceof ModelNotFoundException;
    }

    public static function json(Throwable $e): JsonResponse
    {
        $referencia = (string) Str::uuid();
        $concurrencia = $e instanceof QueryException && in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true);

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
            'codigo' => $codigo,
            'mensaje' => trans('ventasasesor::app.cartera.errores.'.$codigo, ['referencia' => $referencia]),
            'referencia' => $referencia,
        ], $concurrencia ? 409 : 500);
    }
}
