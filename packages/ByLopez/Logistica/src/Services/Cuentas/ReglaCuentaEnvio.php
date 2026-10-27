<?php

namespace ByLopez\Logistica\Services\Cuentas;

use ByLopez\Logistica\Enums\ConceptoMovimientoCuenta as Concepto;
use ByLopez\Logistica\Enums\PagadorFlete;

/**
 * Regla UNICA (pura, sin base de datos) de lo que un ENVIO debe aportar a la cuenta corriente de su transportadora.
 * Las entradas son hechos ya registrados por Logistica; aqui no se inventa ninguna tarifa ni proporcion:
 *
 * RECAUDO (+): el dinero del pedido que el transportador DEBIA cobrar (`guia.recaudo_esperado`, instruccion del despacho).
 *   - Nace solo con la ENTREGA COMPLETA del envio (nada pendiente, algo entregado, ninguna devolucion). Despachado ≠ entregado.
 *   - Varias entregas parciales que completan el envio = UN solo recaudo (por envio, no por evento): nunca doble cobro.
 *   - Todo devuelto = 0. Con unidades aun pendientes = 0 (todavia no se sabe).
 *   - Entregado EN PARTE y devuelto en parte con recaudo: el valor cobrado no se puede deducir con seguridad (no hay
 *     precio por unidad en la guia) → «Requiere revision» (lo confirma el Administrador).
 *   - Recaudo mayor que el total del pedido (el domicilio se mezclo en el recaudo) → «Requiere revision».
 * SERVICIO (-): el costo real del domicilio (`guia.valor_flete`, snapshot del despacho), solo si lo paga ByLopez
 *   (`bylopez` o `incluido_en_venta`: envio gratis o cobrado en el pedido). Se devenga con la primera unidad ENTREGADA.
 *   - Envio por pagar (`cliente_al_recibir`): el cliente le paga el domicilio directo al transportador → sin movimiento.
 *   - Todo devuelto: sin servicio automatico (un costo de devolucion existe solo por registro explicito autorizado).
 *   - Pagador `otro` o sin costo registrado → «Requiere revision».
 *
 * Resultado por concepto: float (importe firmado deseado; 0 = nada) o null (ambiguo: se conserva lo que haya y se
 * pide revision, con el motivo en `revision`).
 */
class ReglaCuentaEnvio
{
    public const MOTIVO_ENTREGA_CON_DEVOLUCION = 'entrega_con_devolucion';

    public const MOTIVO_RECAUDO_SUPERA_PEDIDO = 'recaudo_supera_pedido';

    public const MOTIVO_SERVICIO_SIN_COSTO = 'servicio_sin_costo';

    public const MOTIVO_PAGADOR_OTRO = 'pagador_otro';

    /**
     * @param  array{despachadas: int, entregadas: int, retornadas: int, pendientes: int}  $unidades
     * @return array{recaudo: ?float, servicio: ?float, revision: array<string, string>}
     */
    public function evaluar(float $recaudoEsperado, ?float $valorFlete, ?PagadorFlete $pagador, float $totalPedido, array $unidades): array
    {
        $revision = [];

        $entregadas = (int) $unidades['entregadas'];
        $retornadas = (int) $unidades['retornadas'];
        $pendientes = (int) $unidades['pendientes'];

        // ---- Recaudo ----
        if ($recaudoEsperado <= 0.004 || $pendientes > 0 || $entregadas === 0) {
            $recaudo = 0.0;
        } elseif ($retornadas > 0) {
            $recaudo = null;
            $revision[Concepto::RECAUDO->value] = self::MOTIVO_ENTREGA_CON_DEVOLUCION;
        } elseif ($recaudoEsperado > $totalPedido + 0.004) {
            $recaudo = null;
            $revision[Concepto::RECAUDO->value] = self::MOTIVO_RECAUDO_SUPERA_PEDIDO;
        } else {
            $recaudo = round($recaudoEsperado, 2);
        }

        // ---- Servicio ----
        if ($entregadas === 0 || $pagador === PagadorFlete::CLIENTE_AL_RECIBIR) {
            $servicio = 0.0;
        } elseif ($pagador === PagadorFlete::BYLOPEZ || $pagador === PagadorFlete::INCLUIDO_EN_VENTA) {
            if ($valorFlete === null || $valorFlete <= 0.004) {
                $servicio = null;
                $revision[Concepto::SERVICIO->value] = self::MOTIVO_SERVICIO_SIN_COSTO;
            } else {
                $servicio = -round($valorFlete, 2);
            }
        } else {
            $servicio = null;
            $revision[Concepto::SERVICIO->value] = self::MOTIVO_PAGADOR_OTRO;
        }

        return ['recaudo' => $recaudo, 'servicio' => $servicio, 'revision' => $revision];
    }
}
