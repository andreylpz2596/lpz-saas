<?php

namespace ByLopez\Logistica\Support\Cobro;

use ByLopez\Logistica\Contracts\GestionaCobroPedido;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\VentasAsesor\Exceptions\RegistroPagoInvalidoException as Rechazo;
use ByLopez\VentasAsesor\Exceptions\RevisionPagoInvalidaException as RechazoRevision;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use ByLopez\VentasAsesor\Support\Cartera\MediosCobro;
use Illuminate\Auth\Access\AuthorizationException;
use Webkul\User\Contracts\Admin;

/**
 * Adaptador de `GestionaCobroPedido` a Cartera (VentasAsesor). Junto a los adaptadores de CorteConfeccion, es el UNICO
 * archivo de Logistica que conoce ese paquete para el cobro. No reimplementa nada: delega en RegistrarPagoService y
 * ConfirmarPagoService, que ya hacen la factura `paid` y el recalculo del estado del pedido con los repositorios de Bagisto.
 */
class CobroViaCartera implements GestionaCobroPedido
{
    public function __construct(
        protected CarteraConsultaService $consulta,
        protected RegistrarPagoService $registrar,
        protected ConfirmarPagoService $confirmar,
        protected MediosCobro $medios,
    ) {}

    public function saldo(Admin $actor, int $orderId): ?array
    {
        try {
            $s = $this->consulta->saldoDePedido($orderId, $actor);
        } catch (AuthorizationException) {
            return null;
        }

        return $s === null ? null : [
            'en_cartera' => $s->enCartera,
            'motivo_fuera' => $s->motivoFuera,
            'total' => $s->total,
            'saldo' => $s->saldo,
            'sin_revisar' => $s->sinRevisar,
            'verificado' => $s->verificado,
            'requiere_revision' => $s->requiereRevision,
        ];
    }

    public function medios(): array
    {
        $salida = [];

        foreach ($this->medios->activos() as $m) {
            if (! $this->medios->exigePlataforma($m['codigo'])) {
                $salida[] = ['codigo' => $m['codigo'], 'etiqueta' => (string) trans($m['etiqueta']), 'referencia' => $m['referencia']];
            }
        }

        return $salida;
    }

    public function cobrar(Admin $actor, int $orderId, string $monto, string $medio, ?string $referencia, string $fecha, string $clave, ?int $guiaId = null): array
    {
        try {
            $r = $this->registrar->registrar($actor, $orderId, [
                'monto' => $monto, 'medio_cobro' => $medio, 'fecha_pago' => $fecha,
                'referencia' => $referencia, 'observacion' => 'Cobro contra entrega · validacion de entrega', 'guia_id' => $guiaId,
            ], $clave);
        } catch (Rechazo $e) {
            throw new Invalida(Invalida::COBRO_RECHAZADO, ['causa' => $e->getMessage()]);
        }

        if ($r->estado === 'confirmado') {
            return ['pago_id' => $r->pagoId, 'confirmado' => true, 'duplicado' => $r->duplicado, 'aviso' => null];
        }

        // Quien registra no siempre puede revisar: el pago queda `sin_revisar` en Cartera y alli se confirma (no se salta esa revision).
        try {
            $this->confirmar->confirmar($actor, $r->pagoId);

            return ['pago_id' => $r->pagoId, 'confirmado' => true, 'duplicado' => $r->duplicado, 'aviso' => null];
        } catch (Rechazo|RechazoRevision $e) {
            // F4: confirmar lanza RevisionPagoInvalidaException (p. ej. pedido que requiere revision). Antes no se atrapaba y el
            // cobro terminaba en HTTP 500 aunque el pago ya estaba registrado: ahora queda sin revisar en Cartera y se avisa.
            return ['pago_id' => $r->pagoId, 'confirmado' => false, 'duplicado' => $r->duplicado, 'aviso' => $e->getMessage()];
        }
    }
}
