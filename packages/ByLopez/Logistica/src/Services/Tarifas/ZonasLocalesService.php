<?php

namespace ByLopez\Logistica\Services\Tarifas;

use ByLopez\Logistica\Contracts\BuscaMunicipios;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Tarifa;
use ByLopez\Logistica\Models\Zona;
use ByLopez\Logistica\Services\Despachos\SugerenciaDomiciliario;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Pantalla unica «Zonas locales y tarifas»: destinos LOCALES por bodega de origen y tarifas SUGERIDAS de los operadores.
 *
 * Nada nuevo en el modelo: una «ciudad local» es una `Zona` de un solo municipio DIVIPOLA vinculada a la bodega
 * (`bylopez_lg_origen_zonas`). Agregar una ciudad reutiliza la zona del municipio si ya existe (Bogota, Soacha) o crea una de un
 * solo municipio con el nombre oficial del catalogo. Las tarifas siguen siendo por OPERADOR y ZONA (globales: no dependen de la
 * bodega), inmutables y con historial; esta clase solo las consulta y delega la creacion en `ZonasYTarifasService`.
 *
 * Quitar una ciudad de una bodega borra SOLO el vinculo bodega-zona: la zona, sus tarifas y las guias ya registradas (que guardan su
 * propio snapshot de zona y flete) no se tocan. Despachos nuevos hacia esa ciudad dejan de ser locales para esa bodega.
 */
class ZonasLocalesService
{
    public const MAX_TARIFA = 9999999;

    public const MAX_DIAS_FUTURO = 366;

    public function __construct(
        protected ZonasYTarifasService $tarifas,
        protected ZonaLocalDeOrigen $locales,
        protected SugerenciaDomiciliario $operadores,
        protected BuscaMunicipios $catalogo,
        protected AutorizacionLogistica $autorizacion,
    ) {}

    // ---------------------------------------------------------------------------------------------------------------------
    // Lectura
    // ---------------------------------------------------------------------------------------------------------------------

    /**
     * Estado completo de la pantalla (pocas filas: bodegas, ciudades locales y tarifas de esas ciudades).
     *
     * @return array{bodegas: list<array<string,mixed>>, operadores: list<array<string,mixed>>, tarifas: array<int, list<array<string,mixed>>>}
     */
    public function estado(): array
    {
        $bodegas = DB::table('inventory_sources')->where('status', 1)->orderBy('priority')->orderBy('id')->get(['id', 'name', 'city', 'state']);
        $vinculos = DB::table('bylopez_lg_origen_zonas as oz')
            ->join('bylopez_lg_zonas as z', 'z.id', '=', 'oz.zona_id')
            ->where('z.activo', true)
            ->orderBy('z.nombre')
            ->get(['oz.inventory_source_id', 'z.id as zona_id', 'z.nombre']);
        $municipios = DB::table('bylopez_lg_zona_municipios')->whereIn('zona_id', $vinculos->pluck('zona_id')->unique())->get()->groupBy('zona_id');
        $zonaIds = $vinculos->pluck('zona_id')->unique()->map(fn ($i) => (int) $i)->values()->all();
        $departamentos = [];

        $ciudad = function (object $v) use ($municipios, &$departamentos): array {
            $codigos = ($municipios->get($v->zona_id) ?? collect())->pluck('municipio_codigo')->all();
            $unico = count($codigos) === 1 ? (string) $codigos[0] : null;

            if ($unico !== null && ! array_key_exists($unico, $departamentos)) {
                $departamentos[$unico] = $this->catalogo->resolver($unico)['departamento'] ?? null;
            }

            return [
                'zona_id' => (int) $v->zona_id, 'nombre' => (string) $v->nombre, 'municipio_codigo' => $unico,
                'departamento' => $unico === null ? null : $departamentos[$unico], 'municipios' => count($codigos),
            ];
        };

        return [
            'bodegas' => $bodegas->map(fn ($b) => [
                'id' => (int) $b->id, 'nombre' => (string) $b->name, 'lugar' => trim($b->city.' '.$b->state),
                'ciudades' => $vinculos->where('inventory_source_id', $b->id)->map($ciudad)->values()->all(),
            ])->all(),
            'operadores' => $this->operadores->seleccionables()->map(fn ($t) => ['id' => (int) $t->id, 'nombre' => (string) $t->nombre, 'tipo' => $t->tipo instanceof \BackedEnum ? $t->tipo->value : (string) $t->tipo])->values()->all(),
            'tarifas' => $this->tarifasDeZonas($zonaIds),
            'hoy' => now()->toDateString(),
        ];
    }

    /**
     * Tarifas por zona con su estado: vigente (la ultima con inicio <= hoy), programada (inicio futuro) o historica. `hasta` solo existe
     * cuando una tarifa posterior del mismo operador y zona la reemplaza (el dia anterior a esa); si no, es null (no se inventa).
     *
     * @param  list<int>  $zonaIds
     * @return array<int, list<array<string,mixed>>>
     */
    public function tarifasDeZonas(array $zonaIds): array
    {
        if ($zonaIds === []) {
            return [];
        }

        $hoy = now()->toDateString();
        $filas = DB::table('bylopez_lg_tarifas as t')
            ->join('bylopez_lg_transportadoras as tr', 'tr.id', '=', 't.transportadora_id')
            ->whereIn('t.zona_id', $zonaIds)
            ->orderBy('t.zona_id')->orderBy('tr.nombre')->orderByDesc('t.vigente_desde')->orderByDesc('t.id')
            ->get(['t.id', 't.zona_id', 't.transportadora_id', 't.valor', 't.vigente_desde', 'tr.nombre as operador', 'tr.activo as operador_activo']);
        $salida = [];

        foreach ($filas->groupBy(fn ($f) => $f->zona_id.':'.$f->transportadora_id) as $serie) {
            // `serie` viene de la mas reciente a la mas antigua.
            $vigente = $serie->first(fn ($f) => $f->vigente_desde <= $hoy);
            $siguiente = null;

            foreach ($serie as $f) {
                $estado = $f->vigente_desde > $hoy ? 'programada' : ($vigente !== null && $vigente->id === $f->id ? 'vigente' : 'historica');
                $hasta = $siguiente === null ? null : Carbon::parse($siguiente->vigente_desde)->subDay()->toDateString();
                $salida[(int) $f->zona_id][] = [
                    'id' => (int) $f->id, 'operador_id' => (int) $f->transportadora_id, 'operador' => (string) $f->operador, 'operador_activo' => (bool) $f->operador_activo,
                    'valor' => (string) (int) round((float) $f->valor), 'valor_exacto' => (string) $f->valor, 'desde' => (string) $f->vigente_desde, 'hasta' => $hasta, 'estado' => $estado,
                ];
                $siguiente = $f;
            }
        }

        // Ya vienen ordenadas: por operador (nombre) y, dentro de cada uno, de la inicio mas reciente a la mas antigua.
        return $salida;
    }

    /** @return list<array{municipio_codigo: string, municipio: string, departamento: string, etiqueta: string}> */
    public function buscarMunicipios(string $texto): array
    {
        return $this->catalogo->buscar($texto, 15);
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Ciudades locales de una bodega
    // ---------------------------------------------------------------------------------------------------------------------

    /** Habilita un municipio del catalogo como destino local de la bodega. Devuelve el id de la zona. */
    public function agregarCiudad(?Admin $actor, int $origenId, string $municipioCodigo): int
    {
        $this->exigirGestion($actor);
        $this->exigirOrigen($origenId);

        $municipio = $this->catalogo->resolver(trim($municipioCodigo)) ?? throw new Invalida(Invalida::MUNICIPIO_INVALIDO);

        return DB::transaction(function () use ($origenId, $municipio) {
            // Serializa a quien habilite a la vez en esta bodega (doble clic, dos pestanas).
            DB::table('inventory_sources')->where('id', $origenId)->lockForUpdate()->value('id');

            $existente = DB::table('bylopez_lg_zona_municipios')->where('municipio_codigo', $municipio['municipio_codigo'])->lockForUpdate()->first();

            if ($existente !== null) {
                $zona = Zona::query()->lockForUpdate()->findOrFail($existente->zona_id);

                if (DB::table('bylopez_lg_zona_municipios')->where('zona_id', $zona->id)->count() > 1) {
                    throw new Invalida(Invalida::ZONA_MULTIMUNICIPIO);
                }

                if ($zona->activo && DB::table('bylopez_lg_origen_zonas')->where('inventory_source_id', $origenId)->where('zona_id', $zona->id)->exists()) {
                    throw new Invalida(Invalida::CIUDAD_YA_HABILITADA);
                }

                $zona->activo || $zona->update(['activo' => true]);
            } else {
                $zona = Zona::query()->create([
                    'codigo' => 'MUN_'.$municipio['municipio_codigo'], 'nombre' => mb_substr($municipio['municipio'], 0, 120), 'activo' => true,
                ]);
                DB::table('bylopez_lg_zona_municipios')->insert([
                    'zona_id' => $zona->id, 'municipio_codigo' => $municipio['municipio_codigo'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('bylopez_lg_origen_zonas')->insertOrIgnore([
                'inventory_source_id' => $origenId, 'zona_id' => $zona->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return (int) $zona->id;
        });
    }

    /** Deshabilita la ciudad para ESA bodega (solo el vinculo; la zona, las tarifas y el historial se conservan). */
    public function quitarCiudad(?Admin $actor, int $origenId, int $zonaId): void
    {
        $this->exigirGestion($actor);
        $this->exigirOrigen($origenId);

        $borradas = DB::table('bylopez_lg_origen_zonas')->where('inventory_source_id', $origenId)->where('zona_id', $zonaId)->delete();

        if ($borradas === 0) {
            throw new Invalida(Invalida::CIUDAD_NO_HABILITADA);
        }
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Tarifas
    // ---------------------------------------------------------------------------------------------------------------------

    /**
     * Nueva tarifa SUGERIDA para un operador local en una ciudad habilitada de la bodega. Nunca modifica una existente: una fila nueva
     * con su vigencia (la anterior pasa a historica sola). Valida operador, destino, valor y vigencia; delega el alta (permiso, duplicado
     * por operador + zona + fecha, auditoria) en `ZonasYTarifasService::crearTarifa`.
     *
     * @param  array{operador_id?: mixed, zona_id?: mixed, valor?: mixed, vigente_desde?: mixed}  $datos
     */
    public function crearTarifa(?Admin $actor, int $origenId, array $datos): Tarifa
    {
        $this->exigirGestion($actor);
        $this->exigirOrigen($origenId);

        $zonaId = (int) ($datos['zona_id'] ?? 0);
        $operadorId = (int) ($datos['operador_id'] ?? 0);
        $valor = trim((string) ($datos['valor'] ?? ''));
        $desde = trim((string) ($datos['vigente_desde'] ?? '')) ?: now()->toDateString();

        if (! in_array($zonaId, $this->locales->zonaIdsDeOrigen($origenId), true) || $this->tarifas->zonaActiva($zonaId) === null) {
            throw new Invalida(Invalida::ZONA_INVALIDA);
        }

        if (! $this->operadores->esSeleccionable($operadorId)) {
            throw new Invalida(Invalida::OPERADOR_NO_LOCAL);
        }

        if (preg_match('/^\d{1,7}$/', $valor) !== 1 || (int) $valor < 1 || (int) $valor > self::MAX_TARIFA) {
            throw new Invalida(Invalida::TARIFA_VALOR_INVALIDO);
        }

        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) === 1 ? Carbon::createFromFormat('!Y-m-d', $desde) : false;

        if ($fecha === false || $fecha->format('Y-m-d') !== $desde || $desde < now()->toDateString() || $fecha->gt(now()->startOfDay()->addDays(self::MAX_DIAS_FUTURO))) {
            throw new Invalida(Invalida::TARIFA_FECHA_INVALIDA);
        }

        return DB::transaction(function () use ($actor, $zonaId, $operadorId, $valor, $desde) {
            // Serializa altas simultaneas sobre la misma zona (doble envio): el duplicado por fecha se comprueba ya con el candado.
            Zona::query()->whereKey($zonaId)->lockForUpdate()->value('id');

            return $this->tarifas->crearTarifa($actor, ['transportadora_id' => $operadorId, 'zona_id' => $zonaId, 'valor' => $valor, 'vigente_desde' => $desde]);
        });
    }

    protected function exigirGestion(?Admin $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_TRANSPORTADORAS_GESTIONAR)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }
    }

    protected function exigirOrigen(int $origenId): void
    {
        if (! DB::table('inventory_sources')->where('id', $origenId)->where('status', 1)->exists()) {
            throw new Invalida(Invalida::ORIGEN_INVALIDO);
        }
    }
}
