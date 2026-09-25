<?php

namespace ByLopez\VentasAsesor\Services;

use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\AtribucionInvalidaException;
use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Cambia la propiedad comercial de una venta ya atribuida y deja historial.
 *
 * Reglas aplicadas:
 * - Asignar una venta sin dueño exige 'ventas_asesor.pedidos.assign'.
 * - Cambiar una venta que ya tiene dueño exige 'ventas_asesor.pedidos.reassign'.
 * - Todo cambio exige motivo y produce exactamente una fila de historial.
 * - El asesor destino debe tener el permiso de asesor; un administrador de acceso
 *   total puede serlo solo por eleccion explicita (D-27).
 *
 * Concurrencia (Fase 02A1): la fila se relee con lockForUpdate() DENTRO de la
 * transaccion, y el estado anterior, los permisos y la comprobacion de "esto
 * cambia algo" se calculan sobre esa lectura bloqueada. Un modelo recibido
 * desactualizado ya no puede producir historiales contradictorios ni pisar en
 * silencio la decision mas reciente.
 *
 * La Fase 02A1 no expone esto por HTTP: no hay rutas, controladores ni pantallas.
 */
class AsignacionAsesorService
{
    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected HistorialAtribucionService $historial,
    ) {}

    public function asignarAsesor(
        AtribucionPedido $atribucion,
        ?AdminContract $actor,
        AdminContract $asesor,
        string $motivo,
    ): AtribucionPedido {
        if (! $asesor->id) {
            throw new AtribucionInvalidaException('El asesor destino debe tener id.');
        }

        if (! $this->autorizacion->esAsesor($asesor)) {
            throw new AtribucionInvalidaException('El asesor destino no tiene el permiso de asesor.');
        }

        return $this->cambiarPropiedad(
            $atribucion,
            $actor,
            TipoPropiedadComercial::ASESOR,
            (int) $asesor->id,
            $asesor->name,
            $motivo,
        );
    }

    public function marcarComoVentaDeEmpresa(
        AtribucionPedido $atribucion,
        ?AdminContract $actor,
        string $motivo,
    ): AtribucionPedido {
        return $this->cambiarPropiedad($atribucion, $actor, TipoPropiedadComercial::EMPRESA, null, null, $motivo);
    }

    public function dejarSinAsignar(
        AtribucionPedido $atribucion,
        ?AdminContract $actor,
        string $motivo,
    ): AtribucionPedido {
        return $this->cambiarPropiedad($atribucion, $actor, TipoPropiedadComercial::SIN_ASIGNAR, null, null, $motivo);
    }

    protected function cambiarPropiedad(
        AtribucionPedido $atribucion,
        ?AdminContract $actor,
        TipoPropiedadComercial $propiedad,
        ?int $advisorId,
        ?string $advisorNombre,
        string $motivo,
    ): AtribucionPedido {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new AtribucionInvalidaException('Todo cambio de propiedad comercial exige un motivo.');
        }

        return DB::transaction(function () use ($atribucion, $actor, $propiedad, $advisorId, $advisorNombre, $motivo) {
            /** @var AtribucionPedido $vigente */
            $vigente = AtribucionPedido::query()
                ->whereKey($atribucion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $propiedadAnterior = $vigente->commercial_owner_type;
            $advisorAnterior = $vigente->advisor_id;
            $advisorAnteriorSnapshot = $vigente->advisor_name_snapshot;

            if ($propiedadAnterior === $propiedad && $advisorAnterior === $advisorId) {
                throw new AtribucionInvalidaException('El cambio solicitado no altera la propiedad comercial.');
            }

            $this->exigirPermiso($actor, $propiedadAnterior);

            $vigente->forceFill([
                'commercial_owner_type' => $propiedad,
                'commercial_owner_source' => OrigenPropiedadComercial::REASIGNACION,
                'attribution_status' => EstadoAtribucion::desdePropiedad($propiedad),
                'advisor_id' => $advisorId,
                'advisor_name_snapshot' => $advisorNombre,
                'assigned_at' => $advisorId !== null ? now() : null,
                'assigned_by_admin_id' => $actor?->id ? (int) $actor->id : null,
            ])->save();

            $this->historial->registrarCambio(
                $vigente,
                $this->historial->operacionDelCambio($propiedadAnterior, $propiedad),
                $propiedadAnterior,
                $advisorAnterior,
                $advisorAnteriorSnapshot,
                $actor,
                $motivo,
            );

            return $vigente->refresh();
        });
    }

    protected function exigirPermiso(?AdminContract $actor, TipoPropiedadComercial $propiedadAnterior): void
    {
        $permiso = $propiedadAnterior === TipoPropiedadComercial::SIN_ASIGNAR
            ? AutorizacionVentasAsesor::PERMISO_PEDIDOS_ASIGNAR
            : AutorizacionVentasAsesor::PERMISO_PEDIDOS_REASIGNAR;

        if (! $this->autorizacion->tienePermiso($actor, $permiso)) {
            throw new AtribucionInvalidaException("Esta operacion exige el permiso {$permiso}.");
        }
    }
}
