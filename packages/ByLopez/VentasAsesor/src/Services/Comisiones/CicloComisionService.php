<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Models\ComisionMes;
use ByLopez\VentasAsesor\Support\Comisiones\CalendarioComision;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Guarda el día de inicio del período de comisión de una asesora (su fecha de ingreso) sin reescribir períodos vividos.
 *
 *  - La asesora NO tiene comisiones calculadas (ninguna línea ni mes de umbral): el día rige desde siempre (retroactivo).
 *    No hay nada guardado que cambie de período.
 *  - Ya tiene comisiones: el período en curso se ALARGA hasta el primer inicio del día nuevo que no sea anterior a su fin
 *    natural, y desde ahí rige el día nuevo (transición). Un cambio aún no vigente se reemplaza (no se apilan).
 *
 * El permiso lo exige quien llama (GuardarAsesorService, `asesores.manage`). Llamar dentro de una transacción.
 */
class CicloComisionService
{
    public const RETROACTIVO = 'retroactivo';

    public const TRANSICION = 'transicion';

    public const SIN_CAMBIO = 'sin_cambio';

    /**
     * @return array{modo: string, dia: int, desde: ?string, periodo_actual: array<string, mixed>}
     */
    public function fijar(int $asesorAdminId, ?string $fechaIngreso, ?AdminContract $actor): array
    {
        $dia = $fechaIngreso === null ? 1 : CalendarioComision::fecha($fechaIngreso)->day;
        $fila = fn (?string $desde, string $origen) => [
            'asesor_admin_id' => $asesorAdminId, 'dia_inicio' => $dia, 'vigente_desde' => $desde, 'fecha_ingreso' => $fechaIngreso,
            'origen' => $origen, 'creado_por_admin_id' => $actor?->id, 'creado_por_name_snapshot' => $actor === null ? null : mb_substr((string) $actor->name, 0, 191),
            'created_at' => now(), 'updated_at' => now(),
        ];

        $conComisiones = ComisionLinea::query()->where('asesor_admin_id', $asesorAdminId)->exists()
            || ComisionMes::query()->where('asesor_admin_id', $asesorAdminId)->exists();

        if (! $conComisiones) {
            $antes = MesComercial::calendario($asesorAdminId);
            DB::table('bylopez_va_comision_ciclos')->where('asesor_admin_id', $asesorAdminId)->delete();

            $cambia = $antes->diaEn(now()) !== $dia || $antes->cambioProgramado(now()) !== null;
            if ($dia !== 1 || $fechaIngreso !== null) {
                DB::table('bylopez_va_comision_ciclos')->insert($fila(null, self::RETROACTIVO));
            }

            return ['modo' => $cambia ? self::RETROACTIVO : self::SIN_CAMBIO, 'dia' => $dia, 'desde' => null,
                'periodo_actual' => MesComercial::calendario($asesorAdminId)->periodoEn(now())->aArreglo()];
        }

        // Con comisiones: un cambio aún no vigente se descarta y se decide contra el calendario vigente.
        $hoy = MesComercial::instante(now())->startOfDay();
        $programado = DB::table('bylopez_va_comision_ciclos')->where('asesor_admin_id', $asesorAdminId)->where('vigente_desde', '>', $hoy->toDateString())->delete() > 0;
        $calendario = MesComercial::calendario($asesorAdminId);
        $actual = $calendario->periodoEn(now());

        if ($calendario->diaEn(now()) === $dia) {
            return ['modo' => $programado ? self::TRANSICION : self::SIN_CAMBIO, 'dia' => $dia, 'desde' => null, 'periodo_actual' => $actual->aArreglo()];
        }

        $desde = CalendarioComision::primerInicioDesde($dia, $actual->fin)->toDateString();
        DB::table('bylopez_va_comision_ciclos')->insert($fila($desde, self::TRANSICION));

        return ['modo' => self::TRANSICION, 'dia' => $dia, 'desde' => $desde,
            'periodo_actual' => MesComercial::calendario($asesorAdminId)->periodoEn(now())->aArreglo()];
    }
}
