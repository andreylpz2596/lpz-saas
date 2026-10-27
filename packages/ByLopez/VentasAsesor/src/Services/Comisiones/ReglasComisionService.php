<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use ByLopez\VentasAsesor\Enums\TipoReglaComision;
use ByLopez\VentasAsesor\Exceptions\ReglaComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ReglaComision;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * COM-03 — reglas de comision con vigencia. No causa comisiones.
 *
 * - `vigenteEn()`: la regla que rige en una fecha (la de mayor prioridad y, a igualdad, la mas reciente).
 * - `programar()`: crea una regla nueva desde una fecha no pasada. Si hay una regla abierta del mismo alcance,
 *   se CIERRA en esa fecha (solo se fija su `vigente_hasta`: su porcentaje no cambia). Rechaza solapes con reglas
 *   programadas a futuro y fechas retroactivas: una regla nunca reescribe periodos ya vividos.
 *
 * El porcentaje inicial (20 %, COM-D-03) se crea con `ventas-asesor:comisiones-programar-regla`, de forma explicita
 * y auditada; ningun servicio tiene un porcentaje por defecto.
 */
class ReglasComisionService
{
    public function vigenteEn(CarbonInterface $fecha, string $alcance = TipoReglaComision::ALCANCE_GLOBAL): ?ReglaComision
    {
        return ReglaComision::query()
            ->vigentesEn($fecha, $alcance)
            ->orderByDesc('prioridad')
            ->orderByDesc('vigente_desde')
            ->first();
    }

    /**
     * @param  array{codigo: string, nombre: string, porcentaje: float|string, vigente_desde?: CarbonInterface|string|null, motivo?: ?string}  $datos
     */
    public function programar(array $datos, ?int $actorAdminId, string $mecanismo): ReglaComision
    {
        $porcentaje = (float) $datos['porcentaje'];

        if (! is_numeric($datos['porcentaje']) || $porcentaje <= 0 || $porcentaje > 100) {
            throw new Invalida(Invalida::PORCENTAJE_INVALIDO, 'El porcentaje debe ser mayor que 0 y como maximo 100.');
        }

        $desde = isset($datos['vigente_desde']) && $datos['vigente_desde'] !== null
            ? CarbonImmutable::parse($datos['vigente_desde'])
            : CarbonImmutable::now();

        // Margen de un minuto para el reloj entre quien escribe la fecha y el servidor.
        if ($desde->lt(CarbonImmutable::now()->subMinute())) {
            throw new Invalida(Invalida::RETROACTIVA, 'Una regla no puede empezar en el pasado: cambiaria comisiones de periodos ya vividos.');
        }

        $alcance = TipoReglaComision::ALCANCE_GLOBAL;

        return DB::transaction(function () use ($datos, $porcentaje, $desde, $alcance, $actorAdminId, $mecanismo) {
            if (ReglaComision::query()->where('codigo', $datos['codigo'])->lockForUpdate()->exists()) {
                throw new Invalida(Invalida::CODIGO_DUPLICADO, "Ya existe una regla con codigo «{$datos['codigo']}».");
            }

            $delAlcance = ReglaComision::query()->where('alcance', $alcance)->where('activo', true)->lockForUpdate();

            if ((clone $delAlcance)->where('vigente_desde', '>=', $desde)->exists()) {
                throw new Invalida(Invalida::SOLAPE, 'Ya hay una regla programada desde esa fecha o despues; cancelela antes de programar otra.');
            }

            $abierta = (clone $delAlcance)->whereNull('vigente_hasta')->where('vigente_desde', '<', $desde)->first();
            $abierta?->update(['vigente_hasta' => $desde]);

            return ReglaComision::query()->create([
                'codigo' => $datos['codigo'],
                'nombre' => $datos['nombre'],
                'tipo' => TipoReglaComision::PORCENTAJE_UTILIDAD,
                'base' => TipoReglaComision::BASE_UTILIDAD_POSITIVA,
                'porcentaje' => $porcentaje,
                'alcance' => $alcance,
                'prioridad' => 0,
                'vigente_desde' => $desde,
                'vigente_hasta' => null,
                'activo' => true,
                'creado_por_admin_id' => $actorAdminId,
                'mecanismo' => $mecanismo,
                'motivo' => $datos['motivo'] ?? null,
            ]);
        });
    }
}
