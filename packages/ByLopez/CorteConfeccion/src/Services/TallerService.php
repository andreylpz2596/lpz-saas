<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Repositories\TallerRepository;
use ByLopez\CorteConfeccion\Support\TallerConfeccionResolver;
use Illuminate\Validation\ValidationException;

class TallerService
{
    public function __construct(protected TallerRepository $tallerRepository) {}

    public function create(array $data): Taller
    {
        $this->validarUsuario($data['usuario_id'] ?? null);

        return $this->tallerRepository->create($data);
    }

    public function update(Taller $taller, array $data): Taller
    {
        $this->validarUsuario($data['usuario_id'] ?? null);

        $taller->update($data);

        return $taller->refresh();
    }

    protected function validarUsuario(mixed $usuarioId): void
    {
        if ($usuarioId === '') {
            $usuarioId = null;
        }

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

        $usuarioId = (int) $usuarioId;

        if (! TallerConfeccionResolver::tieneRol($usuarioId)) {
            throw ValidationException::withMessages([
                'usuario_id' => trans('corteconfeccion::app.errors.usuario-sin-rol-taller'),
            ]);
        }
    }
}
