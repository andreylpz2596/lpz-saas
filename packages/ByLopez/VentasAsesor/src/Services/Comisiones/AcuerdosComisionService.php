<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\Asesor;
use ByLopez\VentasAsesor\Models\ComisionAcuerdo;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use ByLopez\VentasAsesor\Support\Comisiones\ProgramadorDeVersiones;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Webkul\User\Contracts\Admin;

/**
 * R1 — acuerdo de comision POR ASESOR (A-01): porcentaje sobre la utilidad (P − C, opcion A) y umbral mensual de unidades
 * sin comision. Versionado con vigencia, usuario y motivo; nunca retroactivo; un cambio NO toca ventas ya confirmadas (cada
 * linea conserva el acuerdo vigente al confirmar, A-02). Ningun valor por defecto: sin acuerdo, el asesor queda «sin
 * configuracion» y nada se habilita.
 */
class AcuerdosComisionService
{
    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected ProgramadorDeVersiones $versiones,
    ) {}

    public function vigenteEn(int $asesorId, CarbonInterface $fecha): ?ComisionAcuerdo
    {
        return ComisionAcuerdo::query()->where('asesor_admin_id', $asesorId)->vigentesEn($fecha)->orderByDesc('version')->first();
    }

    /**
     * Acuerdos que rigen en algun momento del intervalo [desde, hasta) (para saber si el umbral cambio dentro de un mes).
     *
     * @return Collection<int, ComisionAcuerdo>
     */
    public function vigentesEntre(int $asesorId, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return ComisionAcuerdo::query()
            ->where('asesor_admin_id', $asesorId)
            ->where('activo', true)
            ->where('vigente_desde', '<', $hasta)
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>', $desde))
            ->orderBy('vigente_desde')
            ->get();
    }

    /** Mayor % vigente entre todos los asesores: TASA DE REFERENCIA conservadora para ventas sin asesor y revision de listas. */
    public function tasaDeReferencia(CarbonInterface $fecha): ?string
    {
        $max = ComisionAcuerdo::query()->vigentesEn($fecha)->max('porcentaje');

        return $max === null ? null : (string) $max;
    }

    /**
     * @param  array{porcentaje: mixed, umbral?: mixed, vigente_desde?: ?string, motivo: string}  $datos
     */
    public function programar(?Admin $actor, int $asesorId, array $datos): ComisionAcuerdo
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_COMISIONES_CONFIGURAR)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tiene permiso para configurar comisiones.');
        }

        if (! Asesor::query()->where('admin_id', $asesorId)->exists()) {
            throw new Invalida(Invalida::NO_ENCONTRADO, 'El usuario no tiene ficha de asesor.');
        }

        $porcentaje = trim((string) ($datos['porcentaje'] ?? ''));

        if (! preg_match('/^\d{1,2}(\.\d{1,4})?$/', $porcentaje)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El porcentaje debe estar entre 0 y 99,9999.');
        }

        $umbral = trim((string) ($datos['umbral'] ?? ''));

        if ($umbral !== '' && ! ctype_digit($umbral)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El umbral debe ser un numero entero de unidades (0 o mas), o quedar vacio si aun no se define.');
        }

        $acuerdo = $this->versiones->programar(
            ComisionAcuerdo::class,
            fn ($q) => $q->where('asesor_admin_id', $asesorId),
            [
                'asesor_admin_id' => $asesorId,
                'porcentaje' => $porcentaje,
                // Vacio = umbral SIN DEFINIR (no es 0): bloquea la habilitacion hasta que se defina.
                'umbral_mensual_unidades' => $umbral === '' ? null : (int) $umbral,
            ],
            $datos['vigente_desde'] ?? null,
            $actor,
            (string) ($datos['motivo'] ?? ''),
        );

        // El % nuevo nunca cambia ventas ya confirmadas (A-02), pero el UMBRAL del mes en que empieza si puede (D4: el mes
        // queda marcado). Se reevaluan los pedidos de ese mes del asesor. Resolucion tardia para no crear un ciclo de
        // dependencias (la reevaluacion usa este servicio).
        app(ReevaluacionComisionesService::class)->solicitarAsesorMes($asesorId, MesComercial::calendario($asesorId)->clave($acuerdo->vigente_desde), 'acuerdo');

        return $acuerdo;
    }

    /**
     * Asesores con su acuerdo vigente hoy y su historial completo.
     *
     * @return list<array{asesor: Asesor, vigente: ?ComisionAcuerdo, historial: Collection<int, ComisionAcuerdo>}>
     */
    public function panel(): array
    {
        $asesores = Asesor::query()->orderBy('nombre')->get();
        $acuerdos = ComisionAcuerdo::query()->whereIn('asesor_admin_id', $asesores->pluck('admin_id'))
            ->orderByDesc('version')->get()->groupBy('asesor_admin_id');
        $ahora = now();
        $salida = [];

        foreach ($asesores as $a) {
            $historial = $acuerdos->get($a->admin_id, collect());

            $salida[] = [
                'asesor' => $a,
                'vigente' => $historial->first(fn (ComisionAcuerdo $c) => $c->vigenteEn($ahora)),
                'historial' => $historial,
            ];
        }

        return $salida;
    }
}
