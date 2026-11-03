<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use Brick\Math\BigDecimal;
use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Models\ComisionLiquidacion;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autorizacion;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * «Mis comisiones»: la vista de la ASESORA sobre su propia comision. Solo lectura y SOLO lo propio: el asesor nunca llega por
 * parametro (se toma del actor autenticado), de modo que no existe forma de pedir la cuenta de otra persona. Exige `view_own`
 * (el servicio lo vuelve a exigir aunque la ruta ya lo exija). Jamas incluye costo, base, margen ni evidencia interna.
 *
 * Estimada y habilitada NO son importes independientes: son etapas del MISMO importe. Para no sumarlas dos veces, cada peso de
 * cada linea del mes se reparte en UNA sola etapa (las etapas no se solapan y su suma es el total del mes):
 *
 *   bloqueada  = estimada que aun no cumple las condiciones (con el motivo comprensible de lo que falta)
 *   habilitada = cumple todo y aun no se paga (o esta en una liquidacion en borrador)
 *   por pagar  = liquidacion aprobada, saldo pendiente
 *   pagada     = abonos aplicados a la linea
 *
 * Las politicas pendientes (pedido mixto, desplazamiento del umbral, cambio de umbral en el mes, devolucion sin politica) se
 * muestran como bloqueo con su motivo; esta vista no decide ninguna politica ni cambia ningun calculo.
 */
class MisComisionesService
{
    public function __construct(
        protected Autorizacion $autorizacion,
        protected PanelComisionesService $panel,
        protected LiquidacionesComisionService $liquidaciones,
        protected AplicacionesComisionService $aplicaciones,
    ) {}

    /** @return array<string, mixed> */
    public function resumen(?Admin $actor, string $mes): array
    {
        if (! $actor?->id || ! $this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_COMISIONES_VER_PROPIAS)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tiene permiso para ver sus comisiones.');
        }

        $asesorId = (int) $actor->id;
        // El asesor sale del actor autenticado y `$conCalculo` es false: la asesora nunca recibe costo, base ni margen.
        $panel = $this->panel->deAsesor($asesorId, $mes, false);
        $cuenta = $this->cuenta($asesorId);

        return $panel + [
            'liquidaciones' => $cuenta['liquidaciones'],
            'saldo_por_pagar' => $cuenta['por_pagar'],
            'habilitado_sin_liquidar' => $cuenta['habilitado_sin_liquidar'],
        ];
    }

    /**
     * Liquidaciones propias con sus abonos y saldo (de todos los meses; el saldo es lo que ByLopez aun debe pagar).
     *
     * @return array{liquidaciones: list<array<string, mixed>>, por_pagar: string, habilitado_sin_liquidar: string}
     */
    protected function cuenta(int $asesorId): array
    {
        $liqs = ComisionLiquidacion::query()->with('abonos')->where('asesor_admin_id', $asesorId)->where('estado', ComisionLiquidacion::APROBADA)->orderByDesc('consecutivo')->get();
        $porPagar = BigDecimal::zero();
        $filas = [];

        foreach ($liqs as $liq) {
            $saldo = $this->liquidaciones->saldo($liq);
            $porPagar = $porPagar->plus($saldo);
            $filas[] = [
                'numero' => $liq->numero(),
                'estado' => $liq->estado,
                'periodo' => $liq->periodo_desde?->toDateString().' – '.$liq->periodo_hasta?->toDateString(),
                'total' => Dinero::texto(Dinero::de($liq->total)),
                'abonado' => Dinero::texto($this->liquidaciones->abonado($liq)),
                'saldo' => Dinero::texto($saldo),
                'abonos' => $liq->abonos->sortBy('id')->map(fn ($a) => [
                    'fecha' => $a->fecha_pago?->toDateString(), 'tipo' => $a->tipo, 'monto' => Dinero::texto(Dinero::de($a->monto)), 'medio' => $a->medio, 'referencia' => $a->referencia,
                ])->values()->all(),
            ];
        }

        $libres = ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('estado', ComisionLinea::HABILITADA)->whereNull('liquidacion_id');

        return ['liquidaciones' => $filas, 'por_pagar' => Dinero::texto($porPagar), 'habilitado_sin_liquidar' => Dinero::texto(Dinero::de($libres->sum('monto')))];
    }
}
