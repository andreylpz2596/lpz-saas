<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Support\CortadorResolver;
use Illuminate\Validation\ValidationException;

class CortadorService
{
    public function create(array $data): Cortador
    {
        $this->validarUsuario($data['usuario_id'] ?? null);

        return Cortador::query()->create($data);
    }

    public function update(Cortador $cortador, array $data): Cortador
    {
        $this->validarUsuario($data['usuario_id'] ?? null);

        $cortador->update($data);

        return $cortador->refresh();
    }

    protected function validarUsuario(mixed $usuarioId): void
    {
        if (! $usuarioId) {
            throw ValidationException::withMessages([
                'usuario_id' => trans('validation.required', ['attribute' => 'usuario asociado']),
            ]);
        }

        if (! is_numeric($usuarioId)) {
            throw ValidationException::withMessages([
                'usuario_id' => trans('validation.integer', ['attribute' => 'usuario asociado']),
            ]);
        }

        if (! CortadorResolver::isCortador((int) $usuarioId)) {
            throw ValidationException::withMessages([
                'usuario_id' => trans('corteconfeccion::app.errors.usuario-sin-rol-cortador'),
            ]);
        }
    }
}
