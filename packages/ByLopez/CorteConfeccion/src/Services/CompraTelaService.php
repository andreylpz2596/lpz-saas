<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoCompraTela;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Models\CompraTela;
use ByLopez\CorteConfeccion\Models\ProveedorTelaReferencia;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Repositories\CompraTelaRepository;
use ByLopez\CorteConfeccion\Repositories\ProveedorTelaRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompraTelaService
{
    public function __construct(
        protected CompraTelaRepository $compraTelaRepository,
        protected ProveedorTelaRepository $proveedorTelaRepository,
    ) {}

    public function create(array $data): CompraTela
    {
        return DB::transaction(function () use ($data) {
            $proveedor = $this->proveedorTelaRepository->findOrFail($data['proveedor_id']);
            $rollos = collect($data['rollos']);
            unset($data['rollos']);

            $data = array_merge($data, $this->totales($rollos, $data), [
                'proveedor_nombre' => $proveedor->nombre,
                'estado'           => EstadoCompraTela::Registrada->value,
            ]);

            $compra = $this->compraTelaRepository->create($data);

            foreach ($rollos as $rolloData) {
                $compra->rollos()->create($this->datosRollo($compra, $rolloData));
            }

            return $compra->load(['proveedor', 'rollos']);
        });
    }

    public function update(CompraTela $compra, array $data): CompraTela
    {
        return DB::transaction(function () use ($compra, $data) {
            $proveedor = $this->proveedorTelaRepository->findOrFail($data['proveedor_id']);
            $rollos = collect($data['rollos']);
            unset($data['rollos']);

            $rollos = $this->normalizarRollosBloqueados($compra, $rollos);
            $this->validarCodigosUnicos($rollos);

            $data = array_merge($data, $this->totales($rollos, $data), [
                'proveedor_nombre' => $proveedor->nombre,
            ]);

            $compra->update($data);
            $this->sincronizarRollos($compra->refresh(), $rollos);

            return $compra->load(['proveedor', 'rollos']);
        });
    }

    protected function sincronizarRollos(CompraTela $compra, Collection $rollos): void
    {
        $idsRecibidos = $rollos->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        $compra->rollos()->with('ordenesCorte')->whereNotIn('id', $idsRecibidos)->get()->each(function (Rollo $rollo) {
            if ($rollo->ordenesCorte->isNotEmpty()) {
                throw ValidationException::withMessages(['rollos' => trans('corteconfeccion::app.errors.rollo-con-cortes')]);
            }

            $rollo->delete();
        });

        foreach ($rollos as $rolloData) {
            if (! empty($rolloData['id'])) {
                $rollo = $compra->rollos()->with('ordenesCorte')->findOrFail($rolloData['id']);

                if ($rollo->ordenesCorte->isNotEmpty()) {
                    $rollo->update([
                        'observacion' => $rolloData['observacion'] ?? null,
                    ]);

                    continue;
                }

                $rollo->update($this->datosRolloActualizado($compra, $rollo, $rolloData));

                continue;
            }

            $compra->rollos()->create($this->datosRollo($compra, $rolloData));
        }
    }

    protected function normalizarRollosBloqueados(CompraTela $compra, Collection $rollos): Collection
    {
        $rollosBloqueados = $compra->rollos()
            ->with('ordenesCorte')
            ->whereIn('id', $rollos->pluck('id')->filter()->all())
            ->get()
            ->filter(fn (Rollo $rollo) => $rollo->ordenesCorte->isNotEmpty())
            ->keyBy('id');

        return $rollos->map(function (array $rolloData) use ($rollosBloqueados) {
            if (empty($rolloData['id']) || ! $rollosBloqueados->has((int) $rolloData['id'])) {
                return $rolloData;
            }

            $rollo = $rollosBloqueados[(int) $rolloData['id']];

            return array_merge($rolloData, [
                'codigo'              => $rollo->codigo,
                'tipo_tela_id'        => $rollo->tipo_tela_id,
                'referencia_tela_id'  => $rollo->proveedor_tela_referencia_id,
                'tipo_tela'           => $rollo->tipo_tela,
                'color'               => $rollo->color,
                'referencia'          => $rollo->referencia,
                'gramaje'             => $rollo->gramaje,
                'peso_inicial'        => $rollo->peso_inicial,
                'valor_kilo'          => $rollo->valor_kilo,
            ]);
        });
    }

    protected function validarCodigosUnicos(Collection $rollos): void
    {
        foreach ($rollos as $rolloData) {
            if (empty($rolloData['codigo'])) {
                continue;
            }

            $query = Rollo::query()->where('codigo', $rolloData['codigo']);

            if (! empty($rolloData['id'])) {
                $query->where('id', '!=', $rolloData['id']);
            }

            if ($query->exists()) {
                throw ValidationException::withMessages(['rollos' => trans('corteconfeccion::app.errors.rollo-codigo-duplicado')]);
            }
        }
    }

    protected function totales(Collection $rollos, array $data): array
    {
        $subtotal = $rollos->sum(fn ($rollo) => round((float) $rollo['peso_inicial'] * (float) $rollo['valor_kilo'], 2));
        $descuento = (float) ($data['descuento'] ?? 0);
        $impuesto = (float) ($data['impuesto'] ?? 0);

        return [
            'subtotal'        => $subtotal,
            'descuento'       => $descuento,
            'impuesto'        => $impuesto,
            'total_factura'   => max($subtotal - $descuento + $impuesto, 0),
            'cantidad_rollos' => $rollos->count(),
            'peso_total'      => $rollos->sum(fn ($rollo) => (float) $rollo['peso_inicial']),
        ];
    }

    protected function datosRollo(CompraTela $compra, array $rolloData): array
    {
        $pesoInicial = (float) $rolloData['peso_inicial'];
        $valorKilo = (float) $rolloData['valor_kilo'];
        $referenciaTela = $this->referenciaTela($compra, $rolloData);

        return [
            'proveedor_tela_referencia_id' => $referenciaTela?->id,
            'proveedor_tela_id'            => $compra->proveedor_id,
            'tipo_tela_id'                 => $referenciaTela?->tipo_tela_id ?? ($rolloData['tipo_tela_id'] ?? null),
            'codigo'                       => ! empty($rolloData['codigo']) ? $rolloData['codigo'] : $this->generarCodigoRollo($compra),
            'proveedor'                    => $compra->proveedor_nombre,
            'color'                        => $referenciaTela?->color ?? $rolloData['color'],
            'referencia'                   => $referenciaTela?->referencia ?? ($rolloData['referencia'] ?? null),
            'tipo_tela'                    => $referenciaTela?->tipoTela?->admin_name ?? $rolloData['tipo_tela'],
            'gramaje'                      => $referenciaTela?->gramaje ?? ($rolloData['gramaje'] ?? null),
            'peso_inicial'                 => $pesoInicial,
            'peso_disponible'              => $pesoInicial,
            'valor_kilo'                   => $valorKilo,
            'costo_total'                  => round($pesoInicial * $valorKilo, 2),
            'fecha_compra'                 => $compra->fecha_compra,
            'estado'                       => EstadoRollo::Disponible->value,
            'observacion'                  => $rolloData['observacion'] ?? null,
        ];
    }

    protected function referenciaTela(CompraTela $compra, array $rolloData): ?ProveedorTelaReferencia
    {
        if (empty($rolloData['referencia_tela_id'])) {
            return null;
        }

        $referencia = ProveedorTelaReferencia::query()
            ->with('tipoTela')
            ->where('proveedor_tela_id', $compra->proveedor_id)
            ->find($rolloData['referencia_tela_id']);

        if (! $referencia) {
            throw ValidationException::withMessages(['rollos' => 'La referencia seleccionada no pertenece al proveedor de la compra.']);
        }

        return $referencia;
    }

    protected function generarCodigoRollo(CompraTela $compra): string
    {
        $base = $compra->codigo.'-R';
        $consecutivo = 1;

        do {
            $codigo = $base.str_pad((string) $consecutivo, 3, '0', STR_PAD_LEFT);
            $consecutivo++;
        } while (Rollo::query()->where('codigo', $codigo)->exists());

        return $codigo;
    }

    protected function datosRolloActualizado(CompraTela $compra, Rollo $rollo, array $rolloData): array
    {
        $datos = $this->datosRollo($compra, $rolloData);
        $pesoUsado = max((float) $rollo->peso_inicial - (float) $rollo->peso_disponible, 0);
        $nuevoDisponible = (float) $datos['peso_inicial'] - $pesoUsado;

        if ($nuevoDisponible < 0) {
            throw ValidationException::withMessages(['rollos' => trans('corteconfeccion::app.errors.peso-menor-al-usado')]);
        }

        $datos['peso_disponible'] = $rollo->ordenesCorte->isEmpty()
            ? $datos['peso_inicial']
            : $nuevoDisponible;

        $datos['estado'] = $this->estadoPorPeso($datos['peso_disponible'], $datos['peso_inicial'], $rollo->estado);

        return $datos;
    }

    protected function estadoPorPeso(float $pesoDisponible, float $pesoInicial, string $estadoActual): string
    {
        if ($estadoActual === EstadoRollo::Anulado->value) {
            return EstadoRollo::Anulado->value;
        }

        if ($pesoDisponible <= 0) {
            return EstadoRollo::Agotado->value;
        }

        if ($pesoDisponible < $pesoInicial) {
            return EstadoRollo::ParcialmenteUsado->value;
        }

        return EstadoRollo::Disponible->value;
    }
}
