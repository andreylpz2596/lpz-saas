<?php

namespace ByLopez\VentasAsesor\Support\Http;

use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException;
use ByLopez\VentasAsesor\Exceptions\ClienteComercialExistenteException;
use ByLopez\VentasAsesor\Exceptions\ConfirmacionVentaInvalidaException;
use ByLopez\VentasAsesor\Exceptions\ContextoPrecioComercialInvalidoException;
use ByLopez\VentasAsesor\Exceptions\DatosClienteComercialInvalidosException;
use ByLopez\VentasAsesor\Exceptions\EntregaBorradorInvalidaException;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Exceptions\LineaBorradorInvalidaException;
use ByLopez\VentasAsesor\Exceptions\NuevaVentaInvalidaException;
use ByLopez\VentasAsesor\Exceptions\OperacionTelefonoInvalidaException;
use ByLopez\VentasAsesor\Exceptions\PagoBorradorInvalidoException;
use ByLopez\VentasAsesor\Exceptions\PedidoRechazadoPorCercoException;
use ByLopez\VentasAsesor\Exceptions\SolicitudBorradorInvalidaException;
use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Contrato JSON unico de errores comerciales para 03G-C. NO crea codigos nuevos: cada
 * excepcion del dominio (03B-03F) ya tiene un `codigo` estable (docblock de
 * NuevaVentaInvalidaException: "03G-C los mapea todos") y esto solo lo traduce a HTTP,
 * sin repetir ninguna regla de negocio.
 *
 * El estado HTTP se deriva del propio codigo (permiso→403, inexistente→404,
 * conflicto de estado→409, el resto→422) para no mantener una lista manual por
 * excepcion; el codigo en el JSON es lo unico que el cliente de la API debe leer.
 */
final class RespuestaErrorNuevaVenta
{
    /** Ejecuta $accion y traduce cualquier excepcion comercial conocida a JSON estable. */
    public static function envolver(callable $accion): JsonResponse
    {
        try {
            $resultado = $accion();

            return $resultado instanceof JsonResponse ? $resultado : response()->json($resultado);
        } catch (Throwable $e) {
            return self::desdeExcepcion($e);
        }
    }

    public static function desdeExcepcion(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ClienteComercialExistenteException => response()->json([
                'codigo' => 'cliente_existente_por_'.$e->por,
                'mensaje' => $e->getMessage(),
                'customer_id' => $e->customerId,
            ], 409),

            $e instanceof DatosClienteComercialInvalidosException => response()->json([
                'codigo' => 'datos_cliente_invalidos',
                'mensaje' => $e->getMessage(),
                'errores' => $e->errores,
            ], 422),

            // LOTE_RECHAZADO envuelve el fallo de UNA talla (aplicarTallas, 03G-B): `causa` trae
            // el codigo REAL de esa falla (p. ej. no_es_propietario, stock_insuficiente) y es lo
            // que decide el estado HTTP; `codigo` sigue siendo 'lote_rechazado', tal como lo
            // define el dominio, sin inventar uno nuevo.
            $e instanceof NuevaVentaInvalidaException => response()->json(array_filter([
                'codigo' => $e->codigo,
                'mensaje' => $e->getMessage(),
                'variante_id' => $e->varianteId,
                'causa' => $e->causa,
            ], fn ($v) => $v !== null), self::estadoPara($e->causa ?? $e->codigo)),

            $e instanceof LineaBorradorInvalidaException => response()->json(array_filter([
                'codigo' => $e->codigo,
                'mensaje' => $e->getMessage(),
                'abastecimiento' => $e->abastecimiento?->toArray(),
            ], fn ($v) => $v !== null), self::estadoPara($e->codigo)),

            $e instanceof EntregaBorradorInvalidaException => response()->json(array_filter([
                'codigo' => $e->codigo,
                'mensaje' => $e->getMessage(),
                'lineas_afectadas' => $e->lineasAfectadas === [] ? null : $e->lineasAfectadas,
            ], fn ($v) => $v !== null), self::estadoPara($e->codigo)),

            $e instanceof ConfirmacionVentaInvalidaException => response()->json(array_filter([
                'codigo' => $e->codigo,
                'mensaje' => $e->getMessage(),
                'detalles' => $e->detalles === [] ? null : $e->detalles,
            ], fn ($v) => $v !== null), self::estadoPara($e->codigo)),

            $e instanceof PedidoRechazadoPorCercoException => response()->json(array_filter([
                'codigo' => $e->codigo,
                'mensaje' => $e->getMessage(),
                'detalles' => $e->detalles === [] ? null : $e->detalles,
            ], fn ($v) => $v !== null), self::estadoPara($e->codigo)),

            $e instanceof BorradorVentaInvalidoException
                || $e instanceof GrupoComercialInvalidoException
                || $e instanceof VarianteInvalidaException
                || $e instanceof SolicitudBorradorInvalidaException
                || $e instanceof PagoBorradorInvalidoException
                || $e instanceof OperacionTelefonoInvalidaException => response()->json([
                    'codigo' => $e->codigo,
                    'mensaje' => $e->getMessage(),
                ], self::estadoPara($e->codigo)),

            // ContextoPrecioComercialInvalidoException ya expone su propio render(): se deja pasar.
            $e instanceof ContextoPrecioComercialInvalidoException => throw $e,

            default => throw $e,
        };
    }

    private static function estadoPara(string $codigo): int
    {
        return match (true) {
            str_contains($codigo, 'permiso') => 403,
            str_contains($codigo, 'inexistente') || $codigo === 'sin_carrito' => 404,
            str_contains($codigo, 'proceso')
                || str_contains($codigo, 'obsoleta')
                || str_contains($codigo, 'no_editable')
                || str_contains($codigo, 'aun_asignado')
                || str_contains($codigo, 'no_es_propietario')
                || str_contains($codigo, 'ya_existe')
                || str_contains($codigo, 'en_carrito') => 409,
            default => 422,
        };
    }
}
