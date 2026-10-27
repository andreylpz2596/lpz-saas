<?php

namespace ByLopez\VentasAsesor\Support\Venta;

use ByLopez\VentasAsesor\DataObjects\SeleccionPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\ConfirmacionVentaInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Models\Asesor;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Webkul\User\Contracts\Admin;
use Webkul\User\Models\AdminProxy;

/**
 * R2 — «Vender para» en Nueva venta (Doc. 21 P5 / Doc. 23). Reutiliza la atribucion existente (SeleccionPropiedadComercial
 * + ResolverAtribucionService): solo quien tiene `ventas_asesor.pedidos.assign` elige; sin eleccion manda la regla de
 * siempre (asesor automatico si el creador lo es; si no, sin asignar). Venta de EMPRESA y venta de ASESOR quedan
 * diferenciadas desde la confirmacion. Nunca reasigna pedidos historicos.
 */
class VenderPara
{
    public const AUTOMATICO = '';

    public const EMPRESA = 'empresa';

    public function __construct(protected AutorizacionVentasAsesor $autorizacion) {}

    public function puedeElegir(?Admin $actor): bool
    {
        return $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_PEDIDOS_ASIGNAR);
    }

    /** `$valor` = '' (automatico) | 'empresa' | admin_id de un asesor activo. Null = sin eleccion. */
    public function seleccion(?Admin $actor, mixed $valor): ?SeleccionPropiedadComercial
    {
        $valor = trim((string) $valor);

        if ($valor === self::AUTOMATICO) {
            return null;
        }

        if (! $this->puedeElegir($actor)) {
            throw new Rechazo(Rechazo::PROPIEDAD_COMERCIAL_INVALIDA, ['motivo' => 'sin_permiso_asignar']);
        }

        if ($valor === self::EMPRESA) {
            return SeleccionPropiedadComercial::ventaDeEmpresa();
        }

        $asesor = ctype_digit($valor) && Asesor::query()->where('admin_id', (int) $valor)->where('activo', true)->exists()
            ? AdminProxy::modelClass()::query()->find((int) $valor) : null;

        if ($asesor === null || ! $this->autorizacion->esAsesor($asesor)) {
            throw new Rechazo(Rechazo::PROPIEDAD_COMERCIAL_INVALIDA, ['motivo' => 'asesor_invalido']);
        }

        return (int) $asesor->id === (int) $actor->id ? SeleccionPropiedadComercial::asignarmeComoAsesor() : SeleccionPropiedadComercial::asignarAsesor($asesor);
    }

    /**
     * Propietario efectivo para EVALUAR la rentabilidad (mismas reglas que la atribucion).
     *
     * @return array{0: string, 1: ?int}
     */
    public function propietario(Admin $actor, ?SeleccionPropiedadComercial $s): array
    {
        if ($s === null) {
            return $this->autorizacion->esAsesorParaAtribucionAutomatica($actor)
                ? [TipoPropiedadComercial::ASESOR->value, (int) $actor->id]
                : [TipoPropiedadComercial::SIN_ASIGNAR->value, null];
        }

        return match ($s->propiedad) {
            TipoPropiedadComercial::ASESOR => [TipoPropiedadComercial::ASESOR->value, $s->esElCreador ? (int) $actor->id : (int) $s->asesor->id],
            default => [$s->propiedad->value, null],
        };
    }

    /** @return list<array{id: int, nombre: string}> asesores activos para el selector */
    public function opciones(): array
    {
        return Asesor::query()->where('activo', true)->orderBy('nombre')->get(['admin_id', 'nombre'])
            ->map(fn ($a) => ['id' => (int) $a->admin_id, 'nombre' => (string) $a->nombre])->all();
    }
}
