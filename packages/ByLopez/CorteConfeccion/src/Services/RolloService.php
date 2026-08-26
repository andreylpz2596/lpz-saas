<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Repositories\RolloRepository;

class RolloService
{
    public function __construct(protected RolloRepository $rolloRepository) {}

    public function create(array $data): Rollo
    {
        $data['peso_disponible'] = $data['peso_disponible'] ?? $data['peso_inicial'];
        $data['estado'] = EstadoRollo::Disponible->value;

        return $this->rolloRepository->create($data);
    }

    public function update(Rollo $rollo, array $data): Rollo
    {
        $data['estado'] = in_array($rollo->estado, [EstadoRollo::Anulado->value, EstadoRollo::ReservadoEnCorte->value], true)
            ? $rollo->estado
            : $this->estadoPorPeso((float) ($data['peso_disponible'] ?? $rollo->peso_disponible), (float) ($data['peso_inicial'] ?? $rollo->peso_inicial));

        $rollo->update($data);

        return $rollo->refresh();
    }

    public function cancel(Rollo $rollo): Rollo
    {
        $rollo->update(['estado' => EstadoRollo::Anulado->value]);

        return $rollo->refresh();
    }

    public function estadoPorPeso(float $pesoDisponible, float $pesoInicial): string
    {
        if ($pesoDisponible <= 0) {
            return EstadoRollo::Agotado->value;
        }

        if ($pesoDisponible < $pesoInicial) {
            return EstadoRollo::ParcialmenteUsado->value;
        }

        return EstadoRollo::Disponible->value;
    }
}
