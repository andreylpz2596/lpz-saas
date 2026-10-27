<?php

namespace ByLopez\Logistica\Services\Despachos;

use ByLopez\Logistica\Enums\TipoTransportadora;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Support\PuntosRecogida;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reglas UNICAS de domicilio local para la sugerencia de domiciliario (Nueva venta y drawer de despacho):
 *
 *  - Candidato local = metodo que permite entrega (no recogida) y no explicitamente nacional, Y direccion en una ZONA
 *    local configurada en Logistica. Sin zona (p. ej. Medellin) no hay sugerencia ni memoria: no se adivina.
 *  - SELECCIONABLES (eleccion manual): activas, clase domiciliario o plataforma, nunca el generico DOMICILIARIO.
 *  - SUGERIBLES por historial: solo clase domiciliario (una plataforma como PICAP se elige a mano, nunca se sugiere).
 *    El ultimo usado en la zona sale de la ultima guia REAL registrada (corregida, si se corrigio).
 *
 * Todo por clase/estado del catalogo y codigo de metodo; nunca por el nombre de la transportadora.
 */
class SugerenciaDomiciliario
{
    public const SELECCIONABLES = [TipoTransportadora::DOMICILIARIO, TipoTransportadora::PLATAFORMA];

    public const SUGERIBLES = [TipoTransportadora::DOMICILIARIO];

    /** Codigo del transportador generico de la semilla: nunca se elige ni se sugiere. */
    public const GENERICO = 'DOMICILIARIO';

    /** Metodo de envio explicitamente NACIONAL. */
    public const METODO_NACIONAL = 'envio_nacional';

    /** El metodo de envio admite domicilio local: hay metodo, no es recogida ni nacional. */
    public function metodoAdmiteLocal(?string $metodo): bool
    {
        return $metodo !== null && trim($metodo) !== '' && ! in_array($metodo, [PuntosRecogida::METODO, self::METODO_NACIONAL], true);
    }

    /** @return Collection<int, Transportadora> */
    public function seleccionables(): Collection
    {
        return $this->deClases(self::SELECCIONABLES)->get();
    }

    public function esSeleccionable(int $transportadoraId): bool
    {
        return $this->deClases(self::SELECCIONABLES)->whereKey($transportadoraId)->exists();
    }

    /** @return array<int, string> zona_id => codigo del ultimo domiciliario SUGERIBLE realmente usado en esa zona */
    public function ultimoPorZona(): array
    {
        $validas = $this->deClases(self::SUGERIBLES)->pluck('codigo')->all();

        if ($validas === []) {
            return [];
        }

        $ultimas = DB::table('bylopez_lg_guias')
            ->whereNotNull('zona_id')->whereIn('transportadora_codigo', $validas)
            ->groupBy('zona_id')->selectRaw('MAX(id)');

        return DB::table('bylopez_lg_guias')->whereIn('id', $ultimas)
            ->pluck('transportadora_codigo', 'zona_id')
            ->mapWithKeys(fn ($codigo, $zona) => [(int) $zona => (string) $codigo])
            ->all();
    }

    /** @param  list<TipoTransportadora>  $clases */
    private function deClases(array $clases)
    {
        return Transportadora::query()
            ->where('activo', true)
            ->whereIn('tipo', array_map(fn (TipoTransportadora $t) => $t->value, $clases))
            ->where('codigo', '!=', self::GENERICO)
            ->orderBy('nombre');
    }
}
