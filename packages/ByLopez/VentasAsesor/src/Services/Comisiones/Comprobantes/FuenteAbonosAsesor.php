<?php

namespace ByLopez\VentasAsesor\Services\Comisiones\Comprobantes;

use ByLopez\CorteConfeccion\Comprobantes\BorradorOperacion;
use ByLopez\CorteConfeccion\Comprobantes\FuenteOperacionesExistentes;
use ByLopez\VentasAsesor\Models\ComisionAbono;
use Illuminate\Support\Facades\DB;

/**
 * Que operaciones y soportes conoce YA el libro de PAGOS A ASESORAS (`bylopez_va_comision_abonos` y sus soportes). Es la implementacion
 * de Ventas Asesor del contrato que consume DeduplicadorOperaciones. Solo mira pagos a asesoras: los cobros de clientes (Cartera) y
 * los pagos a talleres viven en otros libros y NO se mezclan aqui.
 *
 * `prestador` es la clave opaca de la asesora («asesora:ID»); quien consume el resultado filtra el detalle por permisos.
 */
class FuenteAbonosAsesor implements FuenteOperacionesExistentes
{
    /** Soportes con el mismo archivo (misma huella SHA-256), aunque tengan otro nombre. */
    public function soportesPorHuellaArchivo(string $sha256): array
    {
        $filas = DB::table('bylopez_va_comision_soportes as s')
            ->leftJoin('bylopez_va_comision_abonos as a', fn ($j) => $j->on('a.soporte_id', '=', 's.id')->where('a.tipo', ComisionAbono::ABONO))
            ->where('s.sha256', $sha256)
            ->orderBy('s.id')
            ->get(['s.id as soporte_id', 's.asesor_admin_id', 'a.id as movimiento_id', 'a.fecha_pago as fecha', 'a.monto as valor']);

        return $filas->map(fn ($f) => $this->fila((array) $f))->all();
    }

    /** Abonos con la MISMA huella de operacion (referencia + medio + fecha + valor + moneda + egreso). Coincidencia fuerte. */
    public function operacionesPorHuella(string $huella): array
    {
        return $this->consulta()->where('a.operacion_huella', $huella)->get()->map(fn ($f) => $this->fila((array) $f))->all();
    }

    /** Coincidencias parciales: misma referencia, o (si alguna no tiene referencia) mismo valor y fecha. Nunca solo monto y fecha con referencias distintas. */
    public function operacionesCandidatas(BorradorOperacion $borrador): array
    {
        $referencia = $borrador->referenciaNormalizada();

        if ($referencia === null && ($borrador->valor === null || $borrador->fecha === null)) {
            return [];
        }

        return $this->consulta()->where(function ($q) use ($borrador, $referencia) {
            if ($referencia !== null) {
                $q->whereRaw("UPPER(REGEXP_REPLACE(COALESCE(a.operacion_referencia, ''), '[^A-Za-z0-9]', '')) = ?", [$referencia]);
            }

            if ($borrador->valor !== null && $borrador->fecha !== null) {
                $q->orWhere(fn ($w) => $w->where('a.operacion_valor', $borrador->valor)->where('a.fecha_pago', $borrador->fecha)
                    ->where(function ($r) use ($referencia) {
                        $referencia === null ? $r->whereRaw('1 = 1') : $r->whereRaw("COALESCE(a.operacion_referencia, '') = ''");
                    }));
            }
        })->get()->map(fn ($f) => $this->fila((array) $f))->all();
    }

    protected function consulta()
    {
        return DB::table('bylopez_va_comision_abonos as a')
            ->leftJoin('bylopez_va_comision_abonos as r', fn ($j) => $j->on('r.revierte_id', '=', 'a.id')->where('r.tipo', ComisionAbono::REVERSO))
            ->where('a.tipo', ComisionAbono::ABONO)
            ->select(['a.id as movimiento_id', 'a.soporte_id', 'a.fecha_pago as fecha', 'a.monto as valor', 'a.asesor_admin_id', 'a.liquidacion_id', DB::raw('(r.id IS NOT NULL) as reversado')]);
    }

    /** @param  array<string, mixed>  $f */
    protected function fila(array $f): array
    {
        $f['movimiento_codigo'] = isset($f['movimiento_id']) ? 'ABONO-'.$f['movimiento_id'] : null;
        $f['estado_movimiento'] = ! empty($f['reversado']) ? 'reversado' : 'registrado';
        $f['prestador'] = ! empty($f['asesor_admin_id']) ? 'asesora:'.$f['asesor_admin_id'] : null;
        unset($f['reversado']);

        return $f;
    }
}
