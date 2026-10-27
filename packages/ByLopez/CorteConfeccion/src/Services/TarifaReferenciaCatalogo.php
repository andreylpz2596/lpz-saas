<?php

namespace ByLopez\CorteConfeccion\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;

/**
 * Catalogos para configurar tarifas por referencia (Doc. 05). No crea datos propios:
 *  - Tipo de tela = opciones del atributo Bagisto `tipo_tela` (el mismo catalogo de Rollo y de las referencias del
 *    proveedor de tela).
 *  - Referencia del producto = familia Bagisto («linea de corte» de Orden de Corte V2), solo las que tienen
 *    configurables soportados para esa tela (ResolvedorVarianteCorteService::lineasPorTipoTela).
 */
class TarifaReferenciaCatalogo
{
    public function __construct(protected ResolvedorVarianteCorteService $resolvedor) {}

    /** @return Collection<int, AttributeOption> */
    public function tiposTela(): Collection
    {
        return Attribute::query()
            ->where('code', 'tipo_tela')
            ->with(['options' => fn ($query) => $query->orderBy('sort_order')->orderBy('admin_name')])
            ->first()
            ?->options
            ?? collect();
    }

    /**
     * Opciones para el modal: cada tela con sus referencias.
     *
     * @return list<array{id: int, nombre: string, referencias: list<array{id: int, nombre: string}>}>
     */
    public function opciones(): array
    {
        return $this->tiposTela()
            ->map(fn ($tipo) => [
                'id' => (int) $tipo->id,
                'nombre' => (string) $tipo->admin_name,
                'referencias' => collect($this->resolvedor->lineasPorTipoTela((int) $tipo->id))
                    ->map(fn (array $linea) => [
                        'id' => (int) $linea['attribute_family_id'],
                        'nombre' => trim((string) ($linea['nombre'] ?: $linea['codigo'])),
                    ])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $tipo) => $tipo['referencias'] !== [])
            ->values()
            ->all();
    }

    /** La referencia (familia) debe ser una linea soportada de ese tipo de tela; si no, no hay tarifa posible. */
    public function existeCombinacion(int $tipoTelaId, int $attributeFamilyId): bool
    {
        if (! $this->tiposTela()->contains('id', $tipoTelaId)) {
            return false;
        }

        return collect($this->resolvedor->lineasPorTipoTela($tipoTelaId))
            ->contains(fn (array $linea) => (int) $linea['attribute_family_id'] === $attributeFamilyId);
    }

    /** @return array{tipo_tela: ?string, referencia: ?string} */
    public function nombres(int $tipoTelaId, int $attributeFamilyId): array
    {
        return [
            'tipo_tela' => DB::table('attribute_options')->where('id', $tipoTelaId)->value('admin_name'),
            'referencia' => DB::table('attribute_families')->where('id', $attributeFamilyId)->value('name'),
        ];
    }
}
