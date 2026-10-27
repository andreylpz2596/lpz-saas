<?php

namespace ByLopez\VentasAsesor\Support\Cartera;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Fecha de corte de Cartera (D-59 / C-3, Doc. 05 §42.8).
 *
 * FALLA CERRADO: sin fecha configurada, Cartera esta INACTIVA y nada entra en ella. PAG-B no inventa ninguna
 * fecha ni decide donde se guarda definitivamente (propuesta: `core_config`; pendiente de aprobacion en
 * PAG-F): solo lee `ventasasesor.cartera.fecha_corte` y expone la regla de pertenencia. Los pedidos anteriores
 * a la fecha son «historico»: sin pagos heredados, sin backfill, y `Invoice paid` jamas cuenta como pago.
 */
class FechaCorteCartera
{
    /** Fecha de corte en la zona horaria de la aplicacion, o null si Cartera esta inactiva. */
    public function valor(): ?CarbonImmutable
    {
        $crudo = config('ventasasesor.cartera.fecha_corte');

        if ($crudo === null || trim((string) $crudo) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $crudo, config('app.timezone'));
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("ventasasesor.cartera.fecha_corte invalida: '{$crudo}' (use Y-m-d o Y-m-d H:i:s).", 0, $e);
        }
    }

    public function activa(): bool
    {
        return $this->valor() !== null;
    }

    /** Un pedido creado en `$creadoEn` entra en Cartera si hay fecha de corte y `creadoEn >= fecha_corte`. */
    public function incluye(CarbonInterface $creadoEn): bool
    {
        $corte = $this->valor();

        return $corte !== null && $creadoEn->greaterThanOrEqualTo($corte);
    }
}
