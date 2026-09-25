<?php

namespace ByLopez\VentasAsesor\Services;

use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\Enums\TipoOperacionAtribucion;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Models\LogAsignacionAsesor;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Unico punto autorizado para escribir historial de propiedad comercial.
 *
 * Cada evento guarda contexto suficiente para reconstruir por que ocurrio sin
 * consultar otras tablas: tipo de operacion, propiedad anterior y nueva, asesor
 * anterior y nuevo con snapshots, actor con snapshot, origen de la propiedad,
 * canal, mecanismo, codigo tecnico cuando aplica, motivo y fecha.
 */
class HistorialAtribucionService
{
    /** Evento de la PRIMERA atribucion del pedido. Los campos "anteriores" van nulos. */
    public function registrarAsignacionInicial(
        AtribucionPedido $atribucion,
        ContextoComercial $contexto,
        string $motivo,
    ): LogAsignacionAsesor {
        return LogAsignacionAsesor::escribirDesdeServicio([
            'order_attribution_id' => $atribucion->id,
            'order_id' => $atribucion->order_id,
            'tipo_operacion' => TipoOperacionAtribucion::ASIGNACION_INICIAL,
            'advisor_id_anterior' => null,
            'advisor_anterior_snapshot' => null,
            'advisor_id_nuevo' => $atribucion->advisor_id,
            'advisor_nuevo_snapshot' => $atribucion->advisor_name_snapshot,
            'tipo_propiedad_anterior' => null,
            'tipo_propiedad_nuevo' => $atribucion->commercial_owner_type,
            'origen_propiedad' => $atribucion->commercial_owner_source,
            'sales_channel' => $atribucion->sales_channel,
            'creation_source' => $atribucion->creation_source,
            'changed_by_admin_id' => $contexto->createdByAdminId,
            'changed_by_name_snapshot' => $atribucion->created_by_name_snapshot,
            'codigo_tecnico' => $contexto->codigoTecnico,
            'motivo' => $motivo,
        ]);
    }

    /** Evento de cambio de propiedad sobre una atribucion existente. */
    public function registrarCambio(
        AtribucionPedido $atribucion,
        TipoOperacionAtribucion $operacion,
        TipoPropiedadComercial $propiedadAnterior,
        ?int $advisorAnterior,
        ?string $advisorAnteriorSnapshot,
        ?AdminContract $actor,
        string $motivo,
    ): LogAsignacionAsesor {
        return LogAsignacionAsesor::escribirDesdeServicio([
            'order_attribution_id' => $atribucion->id,
            'order_id' => $atribucion->order_id,
            'tipo_operacion' => $operacion,
            'advisor_id_anterior' => $advisorAnterior,
            'advisor_anterior_snapshot' => $advisorAnteriorSnapshot,
            'advisor_id_nuevo' => $atribucion->advisor_id,
            'advisor_nuevo_snapshot' => $atribucion->advisor_name_snapshot,
            'tipo_propiedad_anterior' => $propiedadAnterior,
            'tipo_propiedad_nuevo' => $atribucion->commercial_owner_type,
            'origen_propiedad' => $atribucion->commercial_owner_source,
            'sales_channel' => $atribucion->sales_channel,
            'creation_source' => $atribucion->creation_source,
            'changed_by_admin_id' => $actor?->id ? (int) $actor->id : null,
            'changed_by_name_snapshot' => $actor?->name,
            'codigo_tecnico' => null,
            'motivo' => $motivo,
        ]);
    }

    /**
     * Tipo de operacion que corresponde a un cambio, deducido del estado real
     * anterior y del destino.
     */
    public function operacionDelCambio(
        TipoPropiedadComercial $propiedadAnterior,
        TipoPropiedadComercial $propiedadNueva,
    ): TipoOperacionAtribucion {
        return match ($propiedadNueva) {
            TipoPropiedadComercial::EMPRESA => TipoOperacionAtribucion::MARCAR_EMPRESA,
            TipoPropiedadComercial::SIN_ASIGNAR => TipoOperacionAtribucion::MARCAR_SIN_ASIGNAR,
            TipoPropiedadComercial::ASESOR => $propiedadAnterior === TipoPropiedadComercial::ASESOR
                ? TipoOperacionAtribucion::REASIGNAR_ASESOR
                : TipoOperacionAtribucion::ASIGNAR_ASESOR,
        };
    }
}
