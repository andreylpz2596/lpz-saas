<?php

namespace ByLopez\VentasAsesor\Support\Venta;

use ByLopez\VentasAsesor\Exceptions\PedidoRechazadoPorCercoException as Rechazo;
use ByLopez\VentasAsesor\Services\Cupo\CupoCarteraClienteService;
use ByLopez\VentasAsesor\Services\Cupo\ExcesoCupoService;
use Illuminate\Support\Facades\DB;

/**
 * Cupo de cartera DENTRO del cerco de creacion de pedidos (Doc. 35). Corre en la transaccion de
 * OrderRepository::createOrderIfNotThenRetry(), en `checkout.order.save.before`, ANTES de insertar el pedido, y por eso cubre tienda,
 * panel, Nueva venta y autoservicio. Solo actua con `ventasasesor.cupo.activo`; apagado no consulta nada.
 *
 * Dos pasos, en este orden (lo fija CercoCreacionPedido):
 *
 * 1. `bloquear()`: `customers … FOR UPDATE` del cliente del pedido. Serializa TODA confirmacion del mismo cliente y todo cambio de su
 *    cupo (que toma el mismo bloqueo), tenga o no fila de politica. Orden global de bloqueos: borrador → products (ascendente) →
 *    cliente. Es una lectura BLOQUEANTE: no crea la instantanea de REPEATABLE READ.
 * 2. `exigir()`: recalcula la exposicion y decide. Sus lecturas son las primeras NO bloqueantes de la transaccion (el cerco debe ser
 *    el primer listener; lo afirma una prueba), asi que la instantanea nace DESPUES de tener el bloqueo y ve lo que la venta anterior
 *    del cliente confirmo mientras se esperaba. Misma justificacion que el cerco de inventario.
 *
 * `nueva_exposicion` = `base_grand_total` del pedido a crear (neto de descuentos, con flete y domicilio): al confirmar todavia no hay
 * pagos ni credito aplicado, esos pasos son posteriores al pedido (Doc. 35 §6). Un pedido de total 0 nunca se rechaza.
 *
 * Excepcion «Autorizar exceso» (Doc. 35 §8): si la venta se rechazaria por monto (`cupo_excedido` / `cupo_sin_asignar`) y hay una
 * autorizacion vigente que alcance, se CONSUME aqui mismo (bajo el bloqueo del cliente, misma transaccion) y la venta pasa; nunca cubre
 * un cliente bloqueado ni «no verificable».
 */
class CercoCupoCartera
{
    public function __construct(
        protected CupoCarteraClienteService $cupo,
        protected ConsumoExcesoEnCurso $consumo,
    ) {}

    /**
     * @param  array<string, mixed>  $pedido  Datos de OrderResource (los de OrderRepository::create)
     */
    public function bloquear(array $pedido): void
    {
        $cliente = $this->clienteDe($pedido);

        if ($cliente === null || ! $this->cupo->bloqueoActivo() || $this->nuevaObligacion($pedido) <= 0) {
            return;
        }

        DB::table('customers')->where('id', $cliente)->lockForUpdate()->value('id');
    }

    /**
     * @param  array<string, mixed>  $pedido
     *
     * @throws Rechazo
     */
    public function exigir(array $pedido): void
    {
        // Cada intento del repositorio empieza sin ninguna autorizacion consumida (un intento anterior pudo fallar y hacer rollback).
        $this->consumo->reiniciar();

        $cliente = $this->clienteDe($pedido);

        if ($cliente === null || ! $this->cupo->bloqueoActivo()) {
            return;
        }

        $cupo = $this->cupo->delCliente($cliente);
        $evaluacion = $this->cupo->evaluar($cupo, $this->nuevaObligacion($pedido));

        if ($evaluacion->permitido) {
            return;
        }

        // Solo el rechazo por MONTO admite excepcion. Bloqueado y «no verificable» no.
        if (in_array($evaluacion->codigo, [Rechazo::CUPO_EXCEDIDO, Rechazo::CUPO_SIN_ASIGNAR], true)) {
            $excesoId = app(ExcesoCupoService::class)->consumir($cliente, $evaluacion, $cupo->autorizado, $cupo->exposicion);

            if ($excesoId !== null) {
                $this->consumo->registrar($excesoId);

                return;
            }
        }

        throw new Rechazo((string) $evaluacion->codigo, ['customer_id' => $cliente] + $evaluacion->detalles());
    }

    /** Obligacion que crea el pedido. */
    public function nuevaObligacion(array $pedido): float
    {
        return max(0.0, round((float) ($pedido['base_grand_total'] ?? $pedido['grand_total'] ?? 0), 2));
    }

    /** Cliente del pedido; los invitados (sin cliente) no tienen cupo que limitar. */
    protected function clienteDe(array $pedido): ?int
    {
        $id = (int) ($pedido['customer_id'] ?? 0);

        return $id > 0 ? $id : null;
    }
}
