<?php

namespace ByLopez\Logistica\Services\Tarifas;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Tarifa;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Models\Zona;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Zonas de entrega y tarifas SUGERIDAS por transportadora y zona (domicilios locales F1).
 *
 * - La zona AUTOMATICA de un pedido sale del municipio DIVIPOLA que resuelve el contrato `ResuelveMunicipio` (solo con
 *   coincidencia exacta con el catalogo). Si no hay municipio o no pertenece a ninguna zona activa: null (sin zona).
 * - La tarifa vigente es la de `vigente_desde` mas reciente <= hoy. Es una SUGERENCIA: el costo real lo fija el despacho.
 * - Nada aqui depende del nombre de una transportadora ni de su tipo.
 */
class ZonasYTarifasService
{
    public function __construct(
        protected ZonaLocalDeOrigen $locales,
        protected AutorizacionLogistica $autorizacion,
    ) {}

    /** @return Collection<int, Zona> */
    public function zonasActivas(): Collection
    {
        return Zona::query()->where('activo', true)->orderBy('nombre')->get();
    }

    /** Zona LOCAL del pedido para el origen (null = bodega predeterminada del canal); null si no es local. */
    public function zonaAutomatica(int $orderId, ?int $origenId = null): ?Zona
    {
        return $this->locales->zonaDePedido($origenId, $orderId);
    }

    /** Zona LOCAL de un municipio DIVIPOLA para el origen; null si no es local. La regla vive en ZonaLocalDeOrigen. */
    public function zonaDeMunicipio(?string $municipio, ?int $origenId = null): ?Zona
    {
        return $this->locales->zonaDeMunicipio($origenId, $municipio);
    }

    public function zonaActiva(int $zonaId): ?Zona
    {
        return Zona::query()->where('activo', true)->find($zonaId);
    }

    public function tarifaVigente(int $transportadoraId, int $zonaId): ?Tarifa
    {
        return Tarifa::query()
            ->where('transportadora_id', $transportadoraId)->where('zona_id', $zonaId)
            ->whereDate('vigente_desde', '<=', now()->toDateString())
            ->orderByDesc('vigente_desde')->orderByDesc('id')
            ->first();
    }

    /**
     * Tarifas vigentes de las transportadoras activas, para el drawer: [codigo_transportadora][zona_id] => valor.
     *
     * @return array<string, array<int, float>>
     */
    public function tarifasVigentes(): array
    {
        $salida = [];
        $zonas = $this->zonasActivas()->pluck('id')->all();

        foreach (Transportadora::query()->where('activo', true)->get(['id', 'codigo']) as $t) {
            foreach ($zonas as $zonaId) {
                if ($tarifa = $this->tarifaVigente((int) $t->id, (int) $zonaId)) {
                    $salida[$t->codigo][(int) $zonaId] = (float) $tarifa->valor;
                }
            }
        }

        return $salida;
    }

    /**
     * Historial de tarifas para la pantalla de Transportadoras (las vigentes marcadas).
     *
     * @return Collection<int, object>
     */
    public function historial(): Collection
    {
        $filas = DB::table('bylopez_lg_tarifas as t')
            ->join('bylopez_lg_transportadoras as tr', 'tr.id', '=', 't.transportadora_id')
            ->join('bylopez_lg_zonas as z', 'z.id', '=', 't.zona_id')
            ->orderBy('tr.nombre')->orderBy('z.nombre')->orderByDesc('t.vigente_desde')
            ->get(['t.id', 't.transportadora_id', 't.zona_id', 't.valor', 't.vigente_desde', 't.creado_por_name_snapshot', 'tr.nombre as transportadora', 'z.nombre as zona']);

        $vigentes = [];

        foreach ($filas as $f) {
            $vigente = $this->tarifaVigente((int) $f->transportadora_id, (int) $f->zona_id);
            $vigentes[$f->transportadora_id.':'.$f->zona_id] = $vigente?->id;
        }

        return $filas->map(function ($f) use ($vigentes) {
            $f->vigente = $vigentes[$f->transportadora_id.':'.$f->zona_id] === $f->id;

            return $f;
        });
    }

    /**
     * Nueva tarifa (nunca se edita una existente). `vigente_desde` por defecto hoy; la misma transportadora, zona y fecha
     * no se repite.
     *
     * @param  array{transportadora_id?: mixed, zona_id?: mixed, valor?: mixed, vigente_desde?: mixed}  $datos
     */
    public function crearTarifa(?Admin $actor, array $datos): Tarifa
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_TRANSPORTADORAS_GESTIONAR)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $transportadora = Transportadora::query()->find((int) ($datos['transportadora_id'] ?? 0)) ?? throw new Invalida(Invalida::TRANSPORTADORA_INVALIDA);
        $zona = Zona::query()->find((int) ($datos['zona_id'] ?? 0)) ?? throw new Invalida(Invalida::ZONA_INVALIDA);
        $valor = trim((string) ($datos['valor'] ?? ''));
        $desde = trim((string) ($datos['vigente_desde'] ?? '')) ?: now()->toDateString();

        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS);
        }

        if (Tarifa::query()->where('transportadora_id', $transportadora->id)->where('zona_id', $zona->id)->whereDate('vigente_desde', $desde)->exists()) {
            throw new Invalida(Invalida::TARIFA_DUPLICADA);
        }

        return Tarifa::query()->create([
            'transportadora_id' => $transportadora->id,
            'zona_id' => $zona->id,
            'valor' => $valor,
            'vigente_desde' => $desde,
            'creado_por_admin_id' => $actor?->id,
            'creado_por_name_snapshot' => $actor ? mb_substr((string) $actor->name, 0, 191) : null,
        ]);
    }
}
