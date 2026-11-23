<?php

namespace ByLopez\VentasAsesor\Services\Cartera;

use ByLopez\VentasAsesor\Exceptions\RevisionPagoInvalidaException;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Pago registrado por el ADMINISTRADOR = pago aprobado (decision de Camilo, 2026-11-22). Registrar sigue sin confirmar
 * (RegistrarPagoService no cambia); este paso, invocado por el controlador DESPUES del registro, confirma con el mismo
 * ConfirmarPagoService que usa la bandeja de Cartera: misma Transaction nativa, factura `paid` y evento `confirmado` con el
 * administrador como revisor. Es el patron que ya usa el cobro contra entrega (CobroViaCartera).
 *
 * Solo el administrador AUTORIZADO (rol administrador configurado, no cualquier rol `all`). Si la confirmacion no es posible
 * (p. ej. el pedido no tiene factura abierta o requiere revision), el pago queda `sin_revisar` en Cartera y se devuelve el
 * motivo: el registro nunca se pierde por esto.
 */
class AutoConfirmacionAdministrador
{
    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected ConfirmarPagoService $confirmacion,
    ) {}

    public function aplica(AdminContract $actor): bool
    {
        return (bool) config('ventasasesor.cartera.auto_confirmar_administrador', true)
            && $this->autorizacion->esAdministradorAutorizado($actor);
    }

    /**
     * @return array{estado: string, aviso: ?string}
     */
    public function despuesDeRegistrar(AdminContract $actor, int $pagoId, string $estado, bool $duplicado): array
    {
        if ($duplicado || $estado !== 'sin_revisar' || ! $this->aplica($actor)) {
            return ['estado' => $estado, 'aviso' => null];
        }

        try {
            $resultado = $this->confirmacion->confirmar($actor, $pagoId);

            return ['estado' => $resultado->estado, 'aviso' => null];
        } catch (RevisionPagoInvalidaException $e) {
            return ['estado' => $estado, 'aviso' => $e->getMessage()];
        }
    }
}
