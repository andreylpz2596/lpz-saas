<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\CortadorAccess;
use ByLopez\CorteConfeccion\Support\CortadorResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCortadorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $estado = strtolower(trim((string) $this->input('estado', '')));
        $usuarioId = $this->input('usuario_id');

        $this->merge([
            'estado' => in_array($estado, ['activo', 'inactivo'], true) ? $estado : $this->input('estado'),
            'usuario_id' => $usuarioId === '' ? null : $usuarioId,
        ]);
    }

    public function authorize(): bool
    {
        return ! CortadorAccess::esUsuarioCortador()
            && bouncer()->hasPermission('corte_confeccion.cortadores.create');
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:255'],
            'contacto' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'estado' => ['required', 'in:activo,inactivo'],
            'observacion' => ['nullable', 'string'],
            'usuario_id' => ['required', 'integer', 'exists:admins,id', Rule::unique('bylopez_cc_cortadores', 'usuario_id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $usuarioId = (int) $this->input('usuario_id', 0);

            if ($usuarioId && ! CortadorResolver::isCortador($usuarioId)) {
                $validator->errors()->add('usuario_id', trans('corteconfeccion::app.errors.usuario-sin-rol-cortador'));
            }
        });
    }

    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'usuario_id' => 'usuario asociado',
        ];
    }
}
