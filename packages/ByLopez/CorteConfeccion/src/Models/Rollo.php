<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\Rollo as RolloContract;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Support\OrdenCorteEstadoMachine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\AttributeOption;

class Rollo extends Model implements RolloContract
{
    protected $table = 'bylopez_cc_rollos';

    protected $fillable = [
        'compra_tela_id',
        'proveedor_tela_referencia_id',
        'proveedor_tela_id',
        'tipo_tela_id',
        'codigo',
        'proveedor',
        'color',
        'color_option_id',
        'referencia',
        'tipo_tela',
        'gramaje',
        'peso_inicial',
        'peso_disponible',
        'valor_kilo',
        'costo_total',
        'fecha_compra',
        'estado',
        'observacion',
    ];

    protected $casts = [
        'fecha_compra' => 'date',
        'peso_inicial' => 'decimal:3',
        'peso_disponible' => 'decimal:3',
        'valor_kilo' => 'decimal:2',
        'costo_total' => 'decimal:2',
    ];

    public function compraTela(): BelongsTo
    {
        return $this->belongsTo(CompraTela::class, 'compra_tela_id');
    }

    public function referenciaTela(): BelongsTo
    {
        return $this->belongsTo(ProveedorTelaReferencia::class, 'proveedor_tela_referencia_id');
    }

    public function tipoTela(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'tipo_tela_id');
    }

    /**
     * F1: mismo criterio que ProveedorTelaReferencia::colorOption() --
     * nullable a proposito, ver comentario alla.
     */
    public function colorOption(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'color_option_id');
    }

    /**
     * Como esta vinculado un rollo a las Ordenes de Corte (PRE-F4). El vinculo
     * se calcula sobre AMBAS fuentes: el pivote real multi-rollo
     * `orden_corte_rollos` y la FK historica `ordenes_corte.rollo_id` (que solo
     * apunta al PRIMER rollo de cada orden), de modo que un 2.o..N.o rollo
     * nunca queda invisible.
     *
     * - INICIADA: alguna orden ya inicio el corte (en_corte o posterior);
     *   identidad historica bloqueada para siempre.
     * - RESERVADA: solo en ordenes que aun no inician corte; puede retirarse
     *   formalmente de la orden (OrdenCorteService::retirarRollo).
     * - SOLO_ANULADAS: solo en ordenes anuladas; el estado del rollo se libero
     *   pero pivote y FK se conservan, asi que sigue vinculado historicamente.
     */
    public const VINCULO_INICIADA = 'iniciada';

    public const VINCULO_RESERVADA = 'reservada_sin_iniciar';

    public const VINCULO_SOLO_ANULADAS = 'solo_anuladas';

    /**
     * Vinculos de varios rollos en 2 consultas (sin N+1). Solo aparecen los
     * rollos vinculados. Un rollo `reservado_en_corte` sin ninguna orden (dato
     * inconsistente) se trata como RESERVADA por seguridad.
     *
     * @param  iterable<Rollo>  $rollos
     * @return array<int, string> rollo_id => VINCULO_*
     */
    public static function vinculosConOrdenCorte(iterable $rollos): array
    {
        $rollos = collect($rollos)->keyBy('id');

        if ($rollos->isEmpty()) {
            return [];
        }

        $ids = $rollos->keys()->all();

        $filas = DB::table('bylopez_cc_orden_corte_rollos as ocr')
            ->join('bylopez_cc_ordenes_corte as oc', 'oc.id', '=', 'ocr.orden_corte_id')
            ->whereIn('ocr.rollo_id', $ids)
            ->select('ocr.rollo_id', 'oc.estado')
            ->union(
                DB::table('bylopez_cc_ordenes_corte as oc')
                    ->whereIn('oc.rollo_id', $ids)
                    ->select('oc.rollo_id', 'oc.estado')
            )
            ->get()
            ->groupBy('rollo_id');

        $vinculos = [];

        foreach ($filas as $rolloId => $ordenes) {
            $vinculos[(int) $rolloId] = self::clasificarVinculo($ordenes->pluck('estado')->all());
        }

        foreach ($rollos as $id => $rollo) {
            if (! isset($vinculos[$id]) && $rollo->estado === EstadoRollo::ReservadoEnCorte->value) {
                $vinculos[$id] = self::VINCULO_RESERVADA;
            }
        }

        return $vinculos;
    }

    /**
     * Prioridad: iniciada > reservada > solo anuladas. Un rollo que ya paso por
     * una orden iniciada sigue bloqueado aunque hoy este reservado en otra nueva.
     * Estados desconocidos cuentan como "iniciada" (lado seguro).
     */
    protected static function clasificarVinculo(array $estadosOrdenes): string
    {
        if (collect($estadosOrdenes)->contains(fn ($estado) => OrdenCorteEstadoMachine::haIniciadoCorte((string) $estado))) {
            return self::VINCULO_INICIADA;
        }

        if (collect($estadosOrdenes)->contains(fn ($estado) => OrdenCorteEstadoMachine::antesDeEnCorte((string) $estado))) {
            return self::VINCULO_RESERVADA;
        }

        return self::VINCULO_SOLO_ANULADAS;
    }

    public function vinculoConOrdenCorte(): ?string
    {
        return self::vinculosConOrdenCorte([$this])[$this->id] ?? null;
    }

    /**
     * Unica definicion de "este rollo esta vinculado a una Orden de Corte"
     * (reservado, usado historicamente o solo en ordenes anuladas). La usan
     * colorBloqueado(), RolloService y CompraTelaService.
     */
    public function estaVinculadoAOrdenCorte(): bool
    {
        return $this->vinculoConOrdenCorte() !== null;
    }

    /**
     * F1-D: el color (color_option_id y su snapshot `color`) es inmutable desde
     * el rollo cuando viene de una referencia de proveedor (se administra desde
     * la referencia) o cuando esta vinculado a una Orden de Corte (ver
     * estaVinculadoAOrdenCorte()). Unica definicion: la usan UpdateRolloRequest
     * y RolloService.
     */
    public function colorBloqueado(): bool
    {
        return (bool) $this->proveedor_tela_referencia_id || $this->estaVinculadoAOrdenCorte();
    }

    public function ordenesCorte(): HasMany
    {
        return $this->hasMany(OrdenCorte::class, 'rollo_id');
    }

    public function ordenesCorteRollos(): HasMany
    {
        return $this->hasMany(OrdenCorteRollo::class, 'rollo_id');
    }
}
