<?php

namespace ByLopez\Cargues\Http\Controllers\Admin\Concerns;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RespondeJson
{
    /** Codigos que son un CONFLICTO de estado (409), no un dato invalido (422). */
    protected const CONFLICTOS = [
        'CARGA_OCUPADA', 'CARGA_NO_EDITABLE', 'CARGA_NO_CANCELABLE', 'CARGAS_ABIERTAS', 'ESTADO_NO_EJECUTABLE', 'EJECUCION_BLOQUEADA_POR_POLITICA',
        'VERSION_CAMBIADA', 'TIPO_NO_ACTIVO', 'OUTPUT_MUY_GRANDE', 'ESTRUCTURA_INTERVENCION_MANUAL', 'VERSION_NO_EDITABLE',
        'EJECUCION_EN_CURSO', 'NADA_QUE_RECUPERAR', 'RESULTADO_INCIERTO',
    ];

    /** Ejecuta la accion y devuelve JSON; un error de negocio se convierte en 422 o 409 con su codigo estable. */
    protected function responder(Closure $accion, int $codigoOk = 200): JsonResponse
    {
        try {
            return response()->json($accion(), $codigoOk);
        } catch (OperacionInvalidaException $e) {
            return $this->error($e);
        }
    }

    protected function error(OperacionInvalidaException $e): JsonResponse
    {
        return response()->json(['ok' => false, 'codigo' => $e->codigo, 'mensaje' => $e->getMessage(), 'detalles' => $e->detalles], in_array($e->codigo, self::CONFLICTOS, true) ? 409 : 422);
    }

    /** Datos del request sin los campos de transporte de Laravel (CSRF y metodo). */
    protected function datos(Request $request): array
    {
        return array_diff_key($request->all(), ['_token' => 1, '_method' => 1]);
    }
}
