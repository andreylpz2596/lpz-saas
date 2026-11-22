<?php

namespace ByLopez\VentasAsesor\Support\Precios;

use ByLopez\Logistica\Support\Moneda;

/**
 * Textos de «Precios por grupo» (Doc. 32): moneda legible ($ 5.800, nunca 5800.0000), porcentajes sin ceros de sobra y
 * etiquetas de regla, origen y advertencias. Solo presentacion: los importes guardados no cambian.
 */
final class FormatoPrecioGrupo
{
    public static function dinero(?float $valor): string
    {
        return $valor === null ? '—' : Moneda::formatear($valor);
    }

    public static function porcentaje(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, ',', '.'), '0'), ',').' %';
    }

    /** «$ 5.800 fijo» o «10 % de descuento sobre el base ($ 10.000) = $ 9.000». */
    public static function regla(?array $regla, ?float $base = null): string
    {
        if ($regla === null) {
            return trans('ventasasesor::app.precios.sin-precio-especifico');
        }

        if ($regla['tipo'] === 'discount') {
            return $base === null
                ? trans('ventasasesor::app.precios.regla-descuento', ['valor' => self::porcentaje($regla['valor'])])
                : trans('ventasasesor::app.precios.regla-descuento-base', [
                    'valor' => self::porcentaje($regla['valor']),
                    'base' => self::dinero($base),
                    'precio' => self::dinero($base - $base * $regla['valor'] / 100),
                ]);
        }

        return trans('ventasasesor::app.precios.regla-fijo', ['valor' => self::dinero($regla['valor'])]);
    }

    public static function tipo(string $tipo): string
    {
        return trans('ventasasesor::app.precios.tipos.'.($tipo === 'discount' ? 'discount' : 'fixed'));
    }

    public static function origen(EvaluacionPrecioGrupo $e): string
    {
        return trans('ventasasesor::app.precios.origen.'.$e->origen, [
            'qty' => $e->regla['qty'] ?? 1,
            'todos' => ($e->regla['todos'] ?? false) ? trans('ventasasesor::app.precios.origen.todos-los-grupos') : '',
        ]);
    }

    /** Color de la insignia del origen. */
    public static function colorOrigen(EvaluacionPrecioGrupo $e): string
    {
        return match ($e->origen) {
            EvaluacionPrecioGrupo::ORIGEN_GRUPO => 'verde',
            EvaluacionPrecioGrupo::ORIGEN_ESPECIAL, EvaluacionPrecioGrupo::ORIGEN_REGLA_CATALOGO => 'indigo',
            EvaluacionPrecioGrupo::ORIGEN_INDETERMINADO => 'rojo',
            default => 'gris',
        };
    }

    /** @param array{codigo: string, parametros: array<string, mixed>} $aviso */
    public static function advertencia(array $aviso): string
    {
        $p = $aviso['parametros'];

        foreach (['base', 'precio', 'costo'] as $clave) {
            if (isset($p[$clave])) {
                $p[$clave] = self::dinero((float) $p[$clave]);
            }
        }

        return trans('ventasasesor::app.precios.advertencias.'.$aviso['codigo'], $p);
    }

    public static function motivo(?string $codigo, array $parametros = []): string
    {
        return $codigo === null ? '' : trans('ventasasesor::app.precios.motivos.'.$codigo, $parametros);
    }
}
