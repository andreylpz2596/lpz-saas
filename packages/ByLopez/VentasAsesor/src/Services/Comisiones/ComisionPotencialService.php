<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use ByLopez\VentasAsesor\DataObjects\ComisionPotencialDiagnostica as Potencial;
use ByLopez\VentasAsesor\DataObjects\LineaUtilidad;
use ByLopez\VentasAsesor\Enums\EstadoUtilidadLinea;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * COM-03 — comision POTENCIAL DIAGNOSTICA. SOLO LECTURA; nunca persiste ni causa (COM-04 esta prohibido aqui).
 *
 *   monto_diagnostico = utilidad_diagnostica (COM-02) × porcentaje de la regla vigente (COM-03)
 *
 * - Solo ventas de asesor (`advisor`): empresa y sin asignar no tienen monto (D-27).
 * - La regla es la vigente en `$referencia` (por defecto, ahora): la comision real usara la vigente en la fecha
 *   de entrega (D-01), que hoy no existe.
 * - Siempre lleva motivos de no pagable: la entrega no existe (Logistica) y el pago no se evalua aqui; ademas,
 *   costo sin autoridad (COM-D-02), lineas sin costo o pedido sin snapshot (COM-D-13).
 */
class ComisionPotencialService
{
    public function __construct(
        protected UtilidadVentaService $utilidad,
        protected ReglasComisionService $reglas,
    ) {}

    /**
     * @param  list<int>  $orderIds
     * @return array<int, Potencial>
     */
    public function dePedidos(array $orderIds, ?CarbonInterface $referencia = null): array
    {
        $utilidades = $this->utilidad->dePedidos($orderIds);
        $regla = $this->reglas->vigenteEn($referencia ?? now());

        $atribuciones = DB::table('bylopez_va_order_attributions')
            ->whereIn('order_id', array_keys($utilidades))
            ->get(['order_id', 'advisor_id', 'commercial_owner_type'])
            ->keyBy('order_id');

        $resultado = [];

        foreach ($utilidades as $orderId => $u) {
            $atribucion = $atribuciones->get($orderId);
            $propiedad = $atribucion?->commercial_owner_type;
            $esAsesor = $propiedad === TipoPropiedadComercial::ASESOR->value;

            $motivos = [Potencial::MOTIVO_ENTREGA_NO_IMPLEMENTADA, Potencial::MOTIVO_PAGO_NO_EVALUADO];
            $estados = array_map(fn (LineaUtilidad $l) => $l->estado, $u->lineas);

            if (! $esAsesor) {
                $motivos[] = Potencial::MOTIVO_PROPIEDAD_NO_ASESOR;
            }

            if ($regla === null) {
                $motivos[] = Potencial::MOTIVO_SIN_REGLA;
            }

            if (in_array(EstadoUtilidadLinea::SIN_SNAPSHOT, $estados, true)) {
                $motivos[] = Potencial::MOTIVO_SIN_SNAPSHOT;
            }

            if (in_array(EstadoUtilidadLinea::SIN_COSTO, $estados, true)) {
                $motivos[] = Potencial::MOTIVO_LINEAS_SIN_COSTO;
            }

            if (in_array(EstadoUtilidadLinea::DIAGNOSTICA, $estados, true)) {
                $motivos[] = Potencial::MOTIVO_COSTO_NO_AUTORIZADO;
            }

            $aplica = $esAsesor && $regla !== null;
            $porLinea = [];

            foreach ($u->lineas as $l) {
                $porLinea[$l->orderItemId] = $aplica && $l->utilidadTotalDiagnostica !== null
                    ? round($l->utilidadTotalDiagnostica * $regla->fraccion(), 2)
                    : null;
            }

            $resultado[$orderId] = new Potencial(
                orderId: $orderId,
                advisorId: $atribucion?->advisor_id === null ? null : (int) $atribucion->advisor_id,
                propiedad: $propiedad,
                reglaId: $regla?->id,
                porcentaje: $regla === null ? null : (float) $regla->porcentaje,
                utilidadDiagnostica: $u->utilidadDiagnostica(),
                montoDiagnostico: $aplica ? round(array_sum(array_map(fn ($m) => $m ?? 0.0, $porLinea)), 2) : null,
                montoPorLinea: $porLinea,
                motivosNoPagable: array_values(array_unique($motivos)),
            );
        }

        return $resultado;
    }
}
