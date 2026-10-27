<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Models\ProveedorTela;
use ByLopez\CorteConfeccion\Models\ProveedorTelaReferencia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Webkul\Attribute\Models\Attribute;

class StoreProveedorTelaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $tipoTelaIds = collect($this->input('tipo_tela_ids', []))
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $referencias = collect($this->input('referencias', []))
            ->filter(function ($referencia) {
                return ! empty($referencia['id'])
                    || ! empty($referencia['tipo_tela_id'])
                    || ! empty($referencia['color'])
                    || ! empty($referencia['color_option_id'])
                    || ! empty($referencia['referencia'])
                    || ! empty($referencia['gramaje'])
                    || ! empty($referencia['_delete']);
            })
            ->map(function ($referencia) {
                if (isset($referencia['estado'])) {
                    $estado = strtolower(trim((string) $referencia['estado']));

                    if (in_array($estado, ['activo', 'inactivo'], true)) {
                        $referencia['estado'] = $estado;
                    }
                }

                // F1-A: el sentinel historico ya no es valido para crear o
                // guardar referencias nuevas. Se normaliza a null para que la
                // validacion condicional de abajo lo rechace con un mensaje de
                // dominio y no como "integer" generico.
                if (($referencia['color_option_id'] ?? null) === '__sin_vincular__') {
                    $referencia['color_option_id'] = null;
                }

                return $referencia;
            })
            ->values()
            ->all();

        $estado = $this->route('id')
            ? strtolower(trim((string) $this->input('estado', '')))
            : 'activo';

        $this->merge([
            'tipo_tela_ids' => $tipoTelaIds,
            'referencias' => $referencias,
            'estado' => in_array($estado, ['activo', 'inactivo'], true) ? $estado : $this->input('estado'),
        ]);
    }

    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.proveedores_tela.create');
    }

    public function rules(): array
    {
        $tipoTelaAttributeId = Attribute::query()
            ->where('code', 'tipo_tela')
            ->value('id');

        $colorAttributeId = Attribute::query()
            ->where('code', 'color')
            ->value('id');

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'nit' => ['required', 'string', 'max:255', 'regex:/^[0-9.\-\s]+$/', Rule::unique('bylopez_cc_proveedores_tela', 'nit')->ignore($this->route('id'))],
            'telefono' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s().-]{7,30}$/'],
            'contacto' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'tipo_tela_ids' => ['required', 'array', 'min:1'],
            'tipo_tela_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('attribute_options', 'id')->where(fn ($query) => $query->where('attribute_id', $tipoTelaAttributeId ?: 0)),
            ],
            'referencias' => ['nullable', 'array'],
            'referencias.*.id' => ['nullable', 'integer', 'exists:bylopez_cc_proveedor_tela_referencias,id'],
            'referencias.*.tipo_tela_id' => [
                'nullable',
                'integer',
                Rule::exists('attribute_options', 'id')->where(fn ($query) => $query->where('attribute_id', $tipoTelaAttributeId ?: 0)),
            ],
            'referencias.*.color' => ['nullable', 'string', 'max:255'],
            // F1: nunca un attribute_options.id de otro atributo (size,
            // tipo_tela, etc.) -- se exige explicitamente que pertenezca al
            // atributo Bagisto 'color', resuelto por codigo, nunca por ID
            // hardcodeado (los IDs difieren entre ambientes, ver AUD-02 §8).
            'referencias.*.color_option_id' => [
                'nullable',
                'integer',
                Rule::exists('attribute_options', 'id')->where(fn ($query) => $query->where('attribute_id', $colorAttributeId ?: 0)),
            ],
            'referencias.*.referencia' => ['nullable', 'string', 'max:255'],
            'referencias.*.gramaje' => ['nullable', 'numeric', 'gt:0'],
            'referencias.*.valor_kilo_referencia' => ['nullable', 'numeric', 'gte:0'],
            'referencias.*.estado' => ['nullable', 'in:activo,inactivo'],
            'referencias.*._delete' => ['nullable', 'boolean'],
            'estado' => ['required', 'in:activo,inactivo'],
            'observacion' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tipoTelaIds = collect($this->input('tipo_tela_ids', []))->map(fn ($id) => (int) $id);
            $proveedor = $this->proveedorTela();

            foreach ($this->input('referencias', []) as $index => $referencia) {
                $isDelete = filter_var($referencia['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $tipoTelaId = (int) ($referencia['tipo_tela_id'] ?? 0);

                if (! $tipoTelaId) {
                    $validator->errors()->add("referencias.$index.tipo_tela_id", 'La referencia debe tener un tipo de tela valido.');

                    continue;
                }

                if (! $tipoTelaIds->contains($tipoTelaId)) {
                    $validator->errors()->add("referencias.$index.tipo_tela_id", 'La referencia debe pertenecer a uno de los tipos de tela seleccionados.');
                }

                if (! empty($referencia['id'])) {
                    $referenciaModel = ProveedorTelaReferencia::query()->find($referencia['id']);

                    if ($proveedor && $referenciaModel && (int) $referenciaModel->proveedor_tela_id !== (int) $proveedor->id) {
                        $validator->errors()->add("referencias.$index.id", 'La referencia no pertenece a este proveedor.');
                    }

                    if ($isDelete && $referenciaModel?->rollos()->exists()) {
                        $validator->errors()->add("referencias.$index.id", trans('corteconfeccion::app.errors.referencia-usada'));
                    }
                }

                if ($isDelete) {
                    continue;
                }

                foreach (['referencia' => 'referencia', 'gramaje' => 'gramaje'] as $field => $label) {
                    if (blank($referencia[$field] ?? null)) {
                        $validator->errors()->add("referencias.$index.$field", "El campo {$label} es obligatorio.");
                    }
                }

                // F1-A: para datos nuevos o editados ya no se acepta texto
                // libre como identidad. Las referencias historicas con NULL
                // siguen existiendo si no se tocan; al editarlas deben
                // vincularse a una opcion real de Bagisto.
                if (empty($referencia['color_option_id'])) {
                    $validator->errors()->add("referencias.$index.color_option_id", trans('corteconfeccion::app.errors.referencia-color-requerido'));
                }

                if (blank($referencia['estado'] ?? null)) {
                    $validator->errors()->add("referencias.$index.estado", 'El campo estado es obligatorio.');
                }
            }

            $this->validarReferenciasDuplicadas($validator);

            if (! $proveedor) {
                return;
            }

            $tiposRemovidos = $proveedor->tiposTela()
                ->pluck('attribute_options.id')
                ->map(fn ($id) => (int) $id)
                ->diff($tipoTelaIds);

            foreach ($tiposRemovidos as $tipoTelaId) {
                $tipoTelaNombre = $proveedor->tiposTela()->where('attribute_options.id', $tipoTelaId)->value('admin_name') ?: (string) $tipoTelaId;
                $tieneReferencias = $proveedor->referenciasTela()->where('tipo_tela_id', $tipoTelaId)->exists();
                $tieneCompras = $proveedor->comprasTela()
                    ->whereHas('rollos', fn ($query) => $query->whereIn('tipo_tela', [$tipoTelaNombre, (string) $tipoTelaId]))
                    ->exists();

                if ($tieneReferencias || $tieneCompras) {
                    $validator->errors()->add('tipo_tela_ids', trans('corteconfeccion::app.errors.tipo-tela-con-datos'));
                }
            }
        });
    }

    protected function validarReferenciasDuplicadas(Validator $validator): void
    {
        $proveedor = $this->proveedorTela();
        $vistos = [];
        $idsEnviados = [];

        foreach ($this->input('referencias', []) as $index => $referencia) {
            $isDelete = filter_var($referencia['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($isDelete) {
                continue;
            }

            if (! empty($referencia['id'])) {
                $idsEnviados[] = (int) $referencia['id'];
            }

            if (
                blank($referencia['tipo_tela_id'] ?? null)
                || empty($referencia['color_option_id'])
                || blank($referencia['referencia'] ?? null)
                || blank($referencia['gramaje'] ?? null)
            ) {
                continue;
            }

            $key = $this->referenciaKey($referencia);

            if (isset($vistos[$key])) {
                $validator->errors()->add("referencias.$index.referencia", trans('corteconfeccion::app.errors.referencia-duplicada'));
            }

            $vistos[$key] = $index;
        }

        if (! $proveedor || empty($vistos)) {
            return;
        }

        $existentes = ProveedorTelaReferencia::query()
            ->where('proveedor_tela_id', $proveedor->id)
            ->when($idsEnviados, fn ($query) => $query->whereNotIn('id', $idsEnviados))
            ->get(['tipo_tela_id', 'color', 'color_option_id', 'referencia', 'gramaje']);

        foreach ($existentes as $existente) {
            $key = $this->referenciaKey($existente->toArray());

            if (isset($vistos[$key])) {
                $validator->errors()->add("referencias.{$vistos[$key]}.referencia", trans('corteconfeccion::app.errors.referencia-duplicada'));
            }
        }
    }

    /**
     * F1: la identidad de color para detectar duplicados es color_option_id
     * cuando existe (estructurado, insensible a mayusculas/tildes/alias por
     * construccion); solo cae al texto normalizado para referencias sin
     * vincular (escape hatch de color inexistente en el catalogo). Un
     * prefijo distinto por rama ('opt:'/'txt:') evita que un texto libre
     * coincida por casualidad con un ID numerico de otra referencia.
     */
    protected function referenciaKey(array $referencia): string
    {
        $colorOptionId = (int) ($referencia['color_option_id'] ?? 0);
        $colorClave = $colorOptionId
            ? 'opt:'.$colorOptionId
            : 'txt:'.strtolower(trim((string) ($referencia['color'] ?? '')));

        return implode('|', [
            (int) ($referencia['tipo_tela_id'] ?? 0),
            $colorClave,
            strtolower(trim((string) ($referencia['referencia'] ?? ''))),
            number_format((float) ($referencia['gramaje'] ?? 0), 3, '.', ''),
        ]);
    }

    protected function proveedorTela(): ?ProveedorTela
    {
        $id = $this->route('id');

        if (! $id) {
            return null;
        }

        return ProveedorTela::query()->with('tiposTela')->find($id);
    }

    public function attributes(): array
    {
        return [
            'nombre' => 'proveedor',
            'nit' => 'NIT',
            'telefono' => 'telefono',
            'tipo_tela_ids' => 'tipos de tela',
        ];
    }
}
