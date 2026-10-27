<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\CortadorAccess;
use Illuminate\Validation\Rule;

class UpdateCortadorRequest extends StoreCortadorRequest
{
    public function authorize(): bool
    {
        return ! CortadorAccess::esUsuarioCortador()
            && bouncer()->hasPermission('corte_confeccion.cortadores.edit');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['usuario_id'] = [
            'required',
            'integer',
            'exists:admins,id',
            Rule::unique('bylopez_cc_cortadores', 'usuario_id')->ignore((int) $this->route('id')),
        ];

        return $rules;
    }
}
