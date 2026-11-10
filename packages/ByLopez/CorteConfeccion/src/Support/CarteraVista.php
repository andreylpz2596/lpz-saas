<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Services\CarteraCuentaService;
use Illuminate\Support\Facades\DB;

/**
 * Datos de cartera para las fichas y las ordenes. Devuelven null si el usuario no puede ver esa cuenta (la autorizacion se
 * decide aqui, en servidor; el Blade solo pinta lo que recibe).
 */
class CarteraVista
{
    /** Resumen compacto para la ficha del prestador. */
    public static function resumenFicha(Taller|Cortador $prestador): ?array
    {
        if (! CarteraGate::puedeVerCuenta($prestador)) {
            return null;
        }

        $cuenta = app(CarteraCuentaService::class)->cuenta($prestador);
        $propia = CarteraGate::prestadorVinculado()?->is($prestador) ?? false;

        return [
            'iniciada' => $cuenta['iniciada'],
            'a_favor' => $cuenta['a_favor_bylopez'],
            'por_pagar_estimado' => $cuenta['por_pagar_estimado'],
            'por_pagar_recibido' => $cuenta['por_pagar_recibido'],
            'trabajo_pendiente' => $cuenta['trabajo_pendiente'],
            'cantidad_pendiente' => $cuenta['cantidad_pendiente'],
            'prestamo_pendiente' => $cuenta['prestamo_pendiente'],
            'sin_tarifa' => $cuenta['sin_tarifa'],
            'propia' => $propia,
            'url' => $propia
                ? route('admin.corte_confeccion.cartera.mi_cuenta')
                : route('admin.corte_confeccion.cartera.cuenta', ['tipo' => $prestador instanceof Taller ? 'taller' : 'cortador', 'id' => $prestador->id]),
        ];
    }

    /** «Cuenta de esta orden»: recepcion de origen, prendas reconocidas, tarifa, valor, pagos, descuentos y saldo. */
    public static function cuentaOrden(OrdenConfeccion|OrdenCorte $orden): ?array
    {
        $esConfeccion = $orden instanceof OrdenConfeccion;
        $prestador = $esConfeccion
            ? Taller::query()->find($orden->taller_id)
            : Cortador::query()->where('usuario_id', $orden->cortador_id)->first();

        if (! $prestador || ! CarteraGate::puedeVerCuenta($prestador)) {
            return null;
        }

        $servicio = app(CarteraCuentaService::class);
        $cuenta = $servicio->cuenta($prestador);

        $propia = CarteraGate::prestadorVinculado()?->is($prestador) ?? false;

        if (! $cuenta['iniciada']) {
            return [
                'iniciada' => false, 'detalle' => null, 'recepciones' => [], 'propia' => $propia, 'origen' => null,
                'url' => $propia
                    ? route('admin.corte_confeccion.cartera.mi_cuenta')
                    : route('admin.corte_confeccion.cartera.cuenta', ['tipo' => $esConfeccion ? 'taller' : 'cortador', 'id' => $prestador->id]),
            ];
        }

        $detalle = $servicio->cuentaDeOrden($prestador, $esConfeccion ? 'confeccion' : 'corte', (int) $orden->id);

        return [
            'iniciada' => true,
            'detalle' => $detalle,
            'recepciones' => self::recepciones($orden),
            'propia' => $propia,
            'url' => $propia
                ? route('admin.corte_confeccion.cartera.mi_cuenta')
                : route('admin.corte_confeccion.cartera.cuenta', ['tipo' => $esConfeccion ? 'taller' : 'cortador', 'id' => $prestador->id]),
            'origen' => $esConfeccion ? self::origenConfeccion($orden) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    protected static function recepciones(OrdenConfeccion|OrdenCorte $orden): array
    {
        if ($orden instanceof OrdenConfeccion) {
            return DB::table('bylopez_cc_ordenes_confeccion_retornos as r')
                ->join('bylopez_cc_ordenes_confeccion_retorno_detalles as rd', 'rd.retorno_id', '=', 'r.id')
                ->where('r.orden_confeccion_id', $orden->id)
                ->groupBy('r.id', 'r.codigo', 'r.estado', 'r.fecha_recepcion_bodega', 'r.fecha_reporte_taller')
                ->orderBy('r.id')
                ->get(['r.codigo', 'r.estado', 'r.fecha_recepcion_bodega', 'r.fecha_reporte_taller', DB::raw('SUM(rd.cantidad_reportada) as reportada'), DB::raw('SUM(COALESCE(rd.cantidad_recibida,0)) as recibida')])
                ->map(fn ($f) => ['codigo' => $f->codigo, 'recibida' => $f->estado === 'recibida_bodega', 'fecha' => $f->estado === 'recibida_bodega' ? $f->fecha_recepcion_bodega : $f->fecha_reporte_taller, 'cantidad' => $f->estado === 'recibida_bodega' ? (int) $f->recibida : (int) $f->reportada])
                ->all();
        }

        return DB::table('bylopez_cc_recepciones_corte as rc')
            ->join('bylopez_cc_recepciones_corte_detalles as rcd', 'rcd.recepcion_corte_id', '=', 'rc.id')
            ->where('rc.orden_corte_id', $orden->id)
            ->groupBy('rc.id', 'rc.fecha_recepcion')
            ->get(['rc.fecha_recepcion', DB::raw('SUM(rcd.cantidad_recibida) as recibida'), DB::raw('SUM(rcd.cantidad_defectuosa) as defectuosa')])
            ->map(fn ($f) => ['codigo' => 'Recepción del corte', 'recibida' => true, 'fecha' => $f->fecha_recepcion, 'cantidad' => (int) $f->recibida, 'defectuosas' => (int) $f->defectuosa])
            ->all();
    }

    protected static function origenConfeccion(OrdenConfeccion $orden): ?array
    {
        $corte = DB::table('bylopez_cc_ordenes_corte')->where('id', $orden->orden_corte_id)->first(['id', 'codigo']);

        return $corte ? ['codigo' => $corte->codigo, 'url' => route('admin.corte_confeccion.ordenes_corte.show', $corte->id)] : null;
    }
}
