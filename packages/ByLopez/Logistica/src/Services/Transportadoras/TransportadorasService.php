<?php

namespace ByLopez\Logistica\Services\Transportadoras;

use ByLopez\Logistica\Enums\ModoGuia;
use ByLopez\Logistica\Enums\TipoTransportadora;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Catalogo de transportadoras (LOG-D-04). El `codigo` es la clave estable (shipments.carrier_code y snapshots); no se
 * edita ni se borra. Los `carrier_title` historicos NO se convierten: solo se muestran para revision (bloque 10).
 */
class TransportadorasService
{
    public const PATRON_CODIGO = '/^[A-Z0-9][A-Z0-9_]{1,39}$/';

    public function __construct(protected AutorizacionLogistica $autorizacion) {}

    /** @return Collection<int, Transportadora> */
    public function todas(): Collection
    {
        return Transportadora::query()->orderByDesc('activo')->orderBy('nombre')->get();
    }

    /** @return Collection<int, Transportadora> */
    public function activas(): Collection
    {
        return Transportadora::query()->where('activo', true)->orderBy('nombre')->get();
    }

    public function activaPorCodigo(?string $codigo): ?Transportadora
    {
        return $codigo === null || $codigo === ''
            ? null
            : Transportadora::query()->where('codigo', $codigo)->where('activo', true)->first();
    }

    /** Nombre visible de un codigo (activo o no); null si no esta en el catalogo. */
    public function nombreDe(?string $codigo): ?string
    {
        return $codigo === null || $codigo === '' ? null : Transportadora::query()->where('codigo', $codigo)->value('nombre');
    }

    /**
     * Despachos NUEVOS por el formulario nativo de Bagisto (solo tiene texto libre): el codigo se asigna SOLO si el texto
     * escrito es EXACTAMENTE el codigo o el nombre de una transportadora activa (mayusculas/espacios/guion bajo
     * normalizados; sin quitar tildes ni aproximar). «INTERRAPIDISMO» no es INTER_RAPIDISIMO: queda NULL.
     */
    public function codigoParaTitulo(?string $titulo): ?string
    {
        $buscado = $this->normalizar($titulo);

        if ($buscado === '') {
            return null;
        }

        return $this->activas()->first(fn (Transportadora $t) => $this->normalizar($t->codigo) === $buscado
            || $this->normalizar($t->nombre) === $buscado)?->codigo;
    }

    /**
     * Revision de historicos: texto libre de despachos sin codigo, con su conteo. Solo lectura.
     *
     * @return Collection<int, object{carrier_title: ?string, envios: int}>
     */
    public function titulosHistoricosSinCodigo(): Collection
    {
        return DB::table('shipments')->whereNull('carrier_code')
            ->groupBy('carrier_title')->orderByDesc('envios')
            ->get(['carrier_title', DB::raw('COUNT(*) as envios')]);
    }

    /**
     * Capacidades independientes del tipo (F1): la operaria y los servicios preguntan por la capacidad, nunca por el
     * nombre ni solo por el tipo. Un cambio aqui afecta solo despachos NUEVOS: la guia guarda el snapshot.
     */
    public function cambiarCapacidades(?Admin $actor, int $id, bool $liquidaCuentaCorriente, bool $generaGuiaInterna): Transportadora
    {
        $this->exigirGestion($actor);

        $t = Transportadora::query()->find($id) ?? throw new Invalida(Invalida::REGISTRO_INEXISTENTE);
        $t->update(['liquida_cuenta_corriente' => $liquidaCuentaCorriente, 'genera_guia_interna' => $generaGuiaInterna]);

        return $t;
    }

    /**
     * `modo_guia` (externa | interna) es la eleccion EXCLUYENTE de que guia usa la transportadora; se guarda en la capacidad
     * `genera_guia_interna`. Sin `modo_guia` rige el booleano `genera_guia_interna` (compatibilidad). Un cambio de modo afecta solo
     * despachos NUEVOS: cada guia conserva su origen.
     *
     * @param  array{codigo?: mixed, nombre?: mixed, tipo?: mixed, liquida_cuenta_corriente?: mixed, genera_guia_interna?: mixed, modo_guia?: mixed}  $datos
     */
    public function crear(?Admin $actor, array $datos): Transportadora
    {
        $this->exigirGestion($actor);

        $codigo = strtoupper(trim((string) ($datos['codigo'] ?? '')));
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $tipo = TipoTransportadora::tryFrom((string) ($datos['tipo'] ?? ''));
        $modo = isset($datos['modo_guia'])
            ? ModoGuia::tryFrom((string) $datos['modo_guia'])
            : ModoGuia::deCapacidad(filter_var($datos['genera_guia_interna'] ?? false, FILTER_VALIDATE_BOOL));

        if (! preg_match(self::PATRON_CODIGO, $codigo)) {
            throw new Invalida(Invalida::CODIGO_INVALIDO);
        }

        if ($nombre === '' || mb_strlen($nombre) > 120 || $tipo === null || $modo === null) {
            throw new Invalida(Invalida::DATOS_INVALIDOS);
        }

        if (Transportadora::query()->where('codigo', $codigo)->exists()) {
            throw new Invalida(Invalida::CODIGO_EN_USO, ['codigo' => $codigo]);
        }

        return Transportadora::query()->create([
            'codigo' => $codigo, 'nombre' => $nombre, 'tipo' => $tipo, 'activo' => true,
            'liquida_cuenta_corriente' => filter_var($datos['liquida_cuenta_corriente'] ?? false, FILTER_VALIDATE_BOOL),
            'genera_guia_interna' => $modo->generaGuiaInterna(),
        ]);
    }

    /** Activar / desactivar. Una inactiva no se ofrece en registros nuevos; los snapshots existentes no cambian. */
    public function cambiarActivo(?Admin $actor, int $id, bool $activo): Transportadora
    {
        $this->exigirGestion($actor);

        $t = Transportadora::query()->find($id) ?? throw new Invalida(Invalida::REGISTRO_INEXISTENTE);
        $t->update(['activo' => $activo]);

        return $t;
    }

    private function exigirGestion(?Admin $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_TRANSPORTADORAS_GESTIONAR)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }
    }

    private function normalizar(?string $valor): string
    {
        return mb_strtoupper(trim(preg_replace('/[\s_]+/u', ' ', (string) $valor)));
    }
}
