<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Models\ProveedorTela;
use ByLopez\CorteConfeccion\Models\ProveedorTelaReferencia;
use ByLopez\CorteConfeccion\Models\Rollo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Webkul\Attribute\Models\Attribute;

class UpdateCompraTelaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $rollos = collect($this->input('rollos', []))
            ->filter(fn ($rollo) => ! empty($rollo['id']) || ! empty($rollo['codigo']) || ! empty($rollo['color']) || ! empty($rollo['tipo_tela']) || ! empty($rollo['tipo_tela_id']) || ! empty($rollo['referencia_tela_id']) || ! empty($rollo['peso_inicial']) || ! empty($rollo['valor_kilo']))
            ->values()
            ->all();

        $this->merge(['rollos' => $rollos]);
    }

    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.compras_tela.edit');
    }

    public function rules(): array
    {
        $tipoTelaAttributeId = Attribute::query()
            ->where('code', 'tipo_tela')
            ->value('id');

        return [
            'codigo'                 => ['required', 'string', 'max:255', Rule::unique('bylopez_cc_compras_tela', 'codigo')->ignore($this->route('id'))],
            'proveedor_id'           => ['required', 'exists:bylopez_cc_proveedores_tela,id'],
            'numero_factura'         => ['nullable', 'string', 'max:255'],
            'fecha_compra'           => ['required', 'date'],
            'descuento'              => ['nullable', 'numeric', 'gte:0'],
            'impuesto'               => ['nullable', 'numeric', 'gte:0'],
            'observacion'            => ['nullable', 'string'],
            'rollos'                 => ['required', 'array', 'min:1'],
            'rollos.*.id'            => ['nullable', 'integer', 'exists:bylopez_cc_rollos,id'],
            'rollos.*.codigo'        => ['nullable', 'string', 'max:255', 'distinct'],
            'rollos.*.tipo_tela_id'  => [
                'required',
                'integer',
                Rule::exists('attribute_options', 'id')->where(fn ($query) => $query->where('attribute_id', $tipoTelaAttributeId ?: 0)),
            ],
            'rollos.*.referencia_tela_id' => ['nullable', 'integer', 'exists:bylopez_cc_proveedor_tela_referencias,id'],
            'rollos.*.color'         => ['required', 'string', 'max:255'],
            'rollos.*.referencia'    => ['required', 'string', 'max:255'],
            'rollos.*.tipo_tela'     => ['required', 'string', 'max:255'],
            'rollos.*.gramaje'       => ['required', 'string', 'max:255'],
            'rollos.*.peso_inicial'  => ['required', 'numeric', 'gt:0'],
            'rollos.*.valor_kilo'    => ['required', 'numeric', 'gt:0'],
            'rollos.*.observacion'   => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $proveedor = ProveedorTela::query()->with('tiposTela')->find($this->input('proveedor_id'));

            if (! $proveedor) {
                return;
            }

            $tipoTelaIds = $proveedor->tiposTela->pluck('id')->map(fn ($id) => (int) $id);

            foreach ($this->input('rollos', []) as $index => $rollo) {
                $tipoTelaId = (int) ($rollo['tipo_tela_id'] ?? 0);
                $referenciaId = (int) ($rollo['referencia_tela_id'] ?? 0);
                $rolloExistente = ! empty($rollo['id'])
                    ? Rollo::query()->find($rollo['id'])
                    : null;

                if (
                    $tipoTelaId
                    && ! $tipoTelaIds->contains($tipoTelaId)
                    && (! $rolloExistente || (int) $rolloExistente->tipo_tela_id !== $tipoTelaId)
                ) {
                    $validator->errors()->add("rollos.$index.tipo_tela_id", 'El tipo de tela no esta asociado al proveedor seleccionado.');
                }

                if (! $referenciaId) {
                    if (! $rolloExistente || ! blank($rolloExistente->proveedor_tela_referencia_id)) {
                        $validator->errors()->add("rollos.$index.referencia_tela_id", 'La referencia es obligatoria para rollos nuevos o vinculados al catalogo.');
                    }

                    continue;
                }

                $referencia = ProveedorTelaReferencia::query()->find($referenciaId);

                if (! $referencia || (int) $referencia->proveedor_tela_id !== (int) $proveedor->id) {
                    $validator->errors()->add("rollos.$index.referencia_tela_id", 'La referencia no pertenece al proveedor seleccionado.');

                    continue;
                }

                if ($tipoTelaId && (int) $referencia->tipo_tela_id !== $tipoTelaId) {
                    $validator->errors()->add("rollos.$index.referencia_tela_id", 'La referencia no pertenece al tipo de tela seleccionado.');
                }

                if (! empty($rollo['color']) && $referencia->color !== $rollo['color']) {
                    $validator->errors()->add("rollos.$index.color", 'El color no pertenece a la referencia seleccionada.');
                }
            }
        });
    }
}
