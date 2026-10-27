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
                'estado' => EstadoCompraTela::Registrada->value,
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

            // PRE-F4: TODA validacion de rollos vinculados a Ordenes de Corte
            // ocurre antes de la primera escritura, y todo el metodo corre en
            // una sola transaccion: un rollo bloqueado en el lote aborta la
            // edicion completa (ni encabezado, ni otros rollos, ni borrados).
            $vinculados = $this->rollosVinculadosAOrdenes($compra);
            $this->validarRollosVinculados($vinculados, $rollos);

            $rollos = $this->normalizarRollosBloqueados($rollos, $vinculados);
            $this->validarCodigosUnicos($rollos);

            $data = array_merge($data, $this->totales($rollos, $data), [
                'proveedor_nombre' => $proveedor->nombre,
            ]);

            $compra->update($data);
            $this->sincronizarRollos($compra->refresh(), $rollos, $vinculados);

            return $compra->load(['proveedor', 'rollos']);
        });
    }

    /**
     * PRE-F4: rollos de la compra vinculados a alguna Orden de Corte, sobre
     * AMBAS fuentes (pivote `orden_corte_rollos` y FK historica
     * `ordenes_corte.rollo_id`, que solo apunta al primer rollo de cada
     * orden). Bloquea las filas de los rollos de la compra hasta el commit
     * para serializar contra OrdenCorteService::agregarRollo/retirarRollo.
     *
     * @return Collection<int, array{rollo: Rollo, vinculo: string}> por rollo_id
     */
    protected function rollosVinculadosAOrdenes(CompraTela $compra): Collection
    {
        $rollosCompra = $compra->rollos()->lockForUpdate()->get();
        $vinculos = Rollo::vinculosConOrdenCorte($rollosCompra);

        return $rollosCompra
            ->filter(fn (Rollo $rollo) => isset($vinculos[$rollo->id]))
            ->mapWithKeys(fn (Rollo $rollo) => [$rollo->id => ['rollo' => $rollo, 'vinculo' => $vinculos[$rollo->id]]]);
    }

    /**
     * Un rollo vinculado a una Orden de Corte NUNCA puede cambiarse, reactivarse,
     * eliminarse ni desvincularse desde la compra. Falla de forma explicita en
     * vez de ignorar en silencio:
     *  - si viene omitido del payload (equivale a eliminarlo);
     *  - si el payload cambia su identidad fisica (ver identidadFisicaCambia()).
     * Solo `observacion` sigue siendo editable. La liberacion de un rollo
     * reservado es una accion explicita desde la Orden (retirarRollo()), nunca
     * un efecto de guardar la compra.
     */
    protected function validarRollosVinculados(Collection $vinculados, Collection $rollos): void
    {
        $payloadPorId = $rollos
            ->filter(fn ($rolloData) => ! empty($rolloData['id']))
            ->keyBy(fn ($rolloData) => (int) $rolloData['id']);

        foreach ($vinculados as $rolloId => $info) {
            $payload = $payloadPorId->get($rolloId);

            if ($payload === null || $this->identidadFisicaCambia($info['rollo'], $payload)) {
                throw $this->errorRolloVinculado($info['rollo'], $info['vinculo']);
            }
        }
    }

    /**
     * Compara el payload contra la identidad guardada del rollo: codigo,
     * referencia de proveedor, color (snapshot), referencia (texto), gramaje,
     * peso inicial y valor por kilo; y el tipo de tela solo en rollos sin
     * referencia (con referencia se deriva de ella). Los campos que no vienen
     * en el payload cuentan como sin cambio. Tambien lo usa
     * UpdateCompraTelaRequest para no exigir consistencia con la referencia
     * ACTUAL a un rollo bloqueado cuyo snapshot no cambia.
     */
    public function identidadFisicaCambia(Rollo $rollo, array $payload): bool
    {
        $texto = fn ($valor) => trim((string) $valor);
        $numero = fn ($valor) => round((float) $valor, 3);

        foreach (['codigo', 'color', 'referencia', 'gramaje'] as $campo) {
            if (array_key_exists($campo, $payload) && $texto($payload[$campo]) !== $texto($rollo->{$campo})) {
                return true;
            }
        }

        foreach (['peso_inicial', 'valor_kilo'] as $campo) {
            if (array_key_exists($campo, $payload) && $numero($payload[$campo]) !== $numero($rollo->{$campo})) {
                return true;
            }
        }

        $referenciaActual = (int) $rollo->proveedor_tela_referencia_id;

        if (array_key_exists('referencia_tela_id', $payload) && (int) $payload['referencia_tela_id'] !== $referenciaActual) {
            return true;
        }

        return ! $referenciaActual
            && array_key_exists('tipo_tela_id', $payload)
            && (int) $payload['tipo_tela_id'] !== (int) $rollo->tipo_tela_id;
    }

    protected function errorRolloVinculado(Rollo $rollo, string $vinculo): ValidationException
    {
        $clave = match ($vinculo) {
            Rollo::VINCULO_RESERVADA => 'rollo-reservado-retirar-primero',
            Rollo::VINCULO_SOLO_ANULADAS => 'rollo-orden-anulada',
            default => 'rollo-orden-iniciada',
        };

        return ValidationException::withMessages([
            'rollos' => trans('corteconfeccion::app.errors.'.$clave, ['codigo' => $rollo->codigo]),
        ]);
    }

    protected function sincronizarRollos(CompraTela $compra, Collection $rollos, Collection $vinculados): void
    {
        $idsRecibidos = $rollos->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        $compra->rollos()->whereNotIn('id', $idsRecibidos)->get()->each(function (Rollo $rollo) use ($vinculados) {
            // Ya rechazado en validarRollosVinculados(); ultima red de seguridad
            // antes de un delete() que arrastraria el pivote por cascada.
            if ($vinculados->has($rollo->id) || $rollo->estaVinculadoAOrdenCorte()) {
                throw $this->errorRolloVinculado($rollo, $vinculados->get($rollo->id)['vinculo'] ?? ($rollo->vinculoConOrdenCorte() ?? Rollo::VINCULO_INICIADA));
            }

            $rollo->delete();
        });

        foreach ($rollos as $rolloData) {
            if (! empty($rolloData['id'])) {
                $rollo = $compra->rollos()->findOrFail($rolloData['id']);

                if ($vinculados->has($rollo->id)) {
                    // Identidad historica intacta: solo la observacion.
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

    /**
     * Para rollos vinculados a ordenes, el payload (ya validado como sin
     * cambios) se reemplaza por lo guardado, de modo que totales y codigos
     * unicos usen siempre los datos reales del rollo.
     */
    protected function normalizarRollosBloqueados(Collection $rollos, Collection $vinculados): Collection
    {
        return $rollos->map(function (array $rolloData) use ($vinculados) {
            if (empty($rolloData['id']) || ! $vinculados->has((int) $rolloData['id'])) {
                return $rolloData;
            }

            $rollo = $vinculados[(int) $rolloData['id']]['rollo'];

            return array_merge($rolloData, [
                'codigo' => $rollo->codigo,
                'tipo_tela_id' => $rollo->tipo_tela_id,
                'referencia_tela_id' => $rollo->proveedor_tela_referencia_id,
                'tipo_tela' => $rollo->tipo_tela,
                'color' => $rollo->color,
                'referencia' => $rollo->referencia,
                'gramaje' => $rollo->gramaje,
                'peso_inicial' => $rollo->peso_inicial,
                'valor_kilo' => $rollo->valor_kilo,
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
            'subtotal' => $subtotal,
            'descuento' => $descuento,
            'impuesto' => $impuesto,
            'total_factura' => max($subtotal - $descuento + $impuesto, 0),
            'cantidad_rollos' => $rollos->count(),
            'peso_total' => $rollos->sum(fn ($rollo) => (float) $rollo['peso_inicial']),
        ];
    }

    protected function datosRollo(CompraTela $compra, array $rolloData, ?Rollo $rolloExistente = null): array
    {
        $pesoInicial = (float) $rolloData['peso_inicial'];
        $valorKilo = (float) $rolloData['valor_kilo'];
        $referenciaTela = $this->referenciaTela($compra, $rolloData, $rolloExistente);

        return [
            'proveedor_tela_referencia_id' => $referenciaTela?->id,
            'proveedor_tela_id' => $compra->proveedor_id,
            'tipo_tela_id' => $referenciaTela?->tipo_tela_id ?? ($rolloData['tipo_tela_id'] ?? null),
            'codigo' => ! empty($rolloData['codigo']) ? $rolloData['codigo'] : $this->generarCodigoRollo($compra),
            'proveedor' => $compra->proveedor_nombre,
            'color' => $referenciaTela?->color ?? $rolloData['color'],
            // F1-A: identidad estructurada del color -- SIEMPRE copiada de la
            // referencia (nunca del payload del cliente). referenciaTela()
            // bloquea referencias historicas sin color vinculado antes de que
            // un rollo nuevo pueda nacer con color_option_id NULL.
            'color_option_id' => $referenciaTela?->color_option_id,
            'referencia' => $referenciaTela?->referencia ?? ($rolloData['referencia'] ?? null),
            'tipo_tela' => $referenciaTela?->tipoTela?->admin_name ?? $rolloData['tipo_tela'],
            'gramaje' => $referenciaTela?->gramaje ?? ($rolloData['gramaje'] ?? null),
            'peso_inicial' => $pesoInicial,
            'peso_disponible' => $pesoInicial,
            'valor_kilo' => $valorKilo,
            'costo_total' => round($pesoInicial * $valorKilo, 2),
            'fecha_compra' => $compra->fecha_compra,
            'estado' => EstadoRollo::Disponible->value,
            'observacion' => $rolloData['observacion'] ?? null,
        ];
    }

    protected function referenciaTela(CompraTela $compra, array $rolloData, ?Rollo $rolloExistente = null): ?ProveedorTelaReferencia
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

        $mismaReferenciaHistorica = $rolloExistente
            && (int) $rolloExistente->proveedor_tela_referencia_id === (int) $referencia->id
            && empty($rolloExistente->color_option_id);

        if (empty($referencia->color_option_id) && ! $mismaReferenciaHistorica) {
            throw ValidationException::withMessages(['rollos' => trans('corteconfeccion::app.errors.referencia-color-sin-vincular')]);
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
        $datos = $this->datosRollo($compra, $rolloData, $rollo);
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
