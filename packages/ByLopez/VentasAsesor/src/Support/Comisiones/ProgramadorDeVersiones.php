<?php

namespace ByLopez\VentasAsesor\Support\Comisiones;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Programa una VERSION nueva de una configuracion con vigencia, sin solapes ni retroactividad (mismo contrato que
 * ReglasComisionService::programar):
 *  - la fecha de inicio no puede estar en el pasado (margen de un minuto): nunca se reescribe un periodo ya vivido;
 *  - si ya hay una version programada desde esa fecha o despues, se rechaza (hay que cancelarla primero);
 *  - la version abierta anterior se CIERRA en la nueva fecha (solo `vigente_hasta`; sus valores no cambian);
 *  - todo bajo bloqueo de las filas del grupo (FOR UPDATE): dos programaciones simultaneas no se solapan.
 */
class ProgramadorDeVersiones
{
    /**
     * @param  class-string<Model>  $modelo
     * @param  callable(Builder): Builder  $grupo  acota las filas del mismo grupo (asesor, familia, codigo...)
     * @param  array<string, mixed>  $datos  valores de la version nueva (sin version ni vigencias)
     */
    public function programar(string $modelo, callable $grupo, array $datos, mixed $desde, ?Admin $actor, string $motivo): Model
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 5) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'Escriba el motivo del cambio (minimo 5 caracteres).');
        }

        $inicio = $desde === null || $desde === '' ? CarbonImmutable::now() : CarbonImmutable::parse($desde);

        if ($inicio->lt(CarbonImmutable::now()->subMinute())) {
            throw new Invalida(Invalida::RETROACTIVA, 'La vigencia no puede empezar en el pasado: cambiaria ventas ya hechas.');
        }

        return DB::transaction(function () use ($modelo, $grupo, $datos, $inicio, $actor, $motivo) {
            /** @var Model $m */
            $m = new $modelo;
            $conActivo = in_array('activo', $m->getFillable(), true);

            $filas = $grupo($modelo::query())->lockForUpdate()->get();
            $vivas = $conActivo ? $filas->where('activo', true) : $filas;

            if ($vivas->contains(fn (Model $f) => $f->vigente_desde->gte($inicio))) {
                throw new Invalida(Invalida::SOLAPE, 'Ya hay una version programada desde esa fecha o despues: cancelela antes.');
            }

            $abierta = $vivas->first(fn (Model $f) => $f->vigente_hasta === null && $f->vigente_desde->lt($inicio));
            $abierta?->update(['vigente_hasta' => $inicio]);

            return $modelo::query()->create($datos + [
                'version' => (int) $filas->max('version') + 1,
                'vigente_desde' => $inicio,
                'vigente_hasta' => null,
                'motivo' => mb_substr($motivo, 0, 500),
                'creado_por_admin_id' => $actor?->id,
                'creado_por_name_snapshot' => $actor ? mb_substr((string) $actor->name, 0, 191) : null,
            ] + ($conActivo ? ['activo' => true] : []));
        });
    }
}
