<?php

namespace ByLopez\Logistica\Services\Entregas;

use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\CatalogoDefecto;
use ByLopez\Logistica\Models\Retorno;
use Illuminate\Support\Facades\Schema;

/**
 * RETORNO DE TRANSPORTADORA (LOG-D-03): la transportadora no entrego y devolvio las unidades. Evento separado de la
 * entrega; `motivo` obligatorio. No borra el Shipment, no crea Refund, no toca comisiones ni inventario (el reingreso
 * fisico a stock es otra decision, fuera de LOG-01).
 *
 * Motivo ESTANDARIZADO (2026-10-26): con `motivoCodigo` el motivo sale del catalogo de motivos de DEVOLUCION (tipo propio,
 * separado de los diagnosticos de defectos de la recepcion). `motivo` (texto) guarda el detalle escrito o, si no hay, el
 * nombre del motivo; «otro» exige detalle. Sin codigo (integraciones o historicos) sigue valiendo el texto libre obligatorio.
 */
class RegistrarRetornoTransportadoraService extends RegistrarEventoEnvio
{
    protected function tipo(): string
    {
        return 'retorno';
    }

    protected function modelo(): string
    {
        return Retorno::class;
    }

    protected function validarEspecifico(SolicitudEventoEnvio $s, Fuente $fuente): void
    {
        if (! $fuente->validaParaRetorno()) {
            throw new Invalida(Invalida::FUENTE_INVALIDA);
        }

        $codigo = $this->texto($s->motivoCodigo, 40);

        if ($codigo !== null) {
            $motivo = $this->motivoDelCatalogo($codigo) ?? throw new Invalida(Invalida::MOTIVO_DEVOLUCION_INVALIDO, ['codigo' => $codigo]);

            if ($motivo->requiere_detalle && $this->texto($s->motivo, 500) === null) {
                throw new Invalida(Invalida::MOTIVO_REQUERIDO);
            }

            return;
        }

        if ($this->texto($s->motivo, 500) === null) {
            throw new Invalida(Invalida::MOTIVO_REQUERIDO);
        }
    }

    protected function atributos(SolicitudEventoEnvio $s, string $fecha): array
    {
        $codigo = $this->texto($s->motivoCodigo, 40);
        $detalle = $this->texto($s->motivo, 500);

        if ($codigo === null) {
            return ['fecha_retorno' => $fecha, 'motivo' => $detalle];
        }

        return ['fecha_retorno' => $fecha, 'motivo' => $detalle ?? $this->motivoDelCatalogo($codigo)?->nombre]
            + (self::conCodigo() ? ['motivo_codigo' => $codigo] : []);
    }

    private function motivoDelCatalogo(string $codigo): ?CatalogoDefecto
    {
        return CatalogoDefecto::query()->where('tipo', CatalogoDefecto::DEVOLUCION)->where('codigo', $codigo)->where('activo', true)->first();
    }

    /** Base sin la migracion de motivos (2026_10_27_100020): se guarda solo el texto, como antes. En cache por proceso. */
    private static function conCodigo(): bool
    {
        static $hay = null;

        return $hay ??= Schema::hasColumn('bylopez_lg_retornos', 'motivo_codigo');
    }
}
