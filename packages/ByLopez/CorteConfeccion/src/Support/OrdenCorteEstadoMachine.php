<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;

/**
 * Unica fuente de verdad de las transiciones validas de OrdenCorte.
 *
 * Todo servicio o controlador que necesite decidir si una orden puede
 * pasar de un estado a otro (para permitir la accion o para mostrar/ocultar
 * un boton) debe consultar esta clase en vez de comparar strings de estado
 * de forma independiente.
 */
final class OrdenCorteEstadoMachine
{
    /**
     * Mapa estado_actual => estados_destino permitidos.
     *
     * La transicion hacia "anulado" no vive aqui: es una salida excepcional
     * permitida desde cualquier estado no terminal (ver puedeTransicionar).
     */
    private const TRANSICIONES = [
        'pendiente_entrega' => ['entregado_a_cortador'],
        'entregado_a_cortador' => ['en_corte'],
        'en_corte' => ['reportado_por_cortador'],
        'reportado_por_cortador' => ['recibido_bodega'],
        'recibido_bodega' => ['cerrado'],
        'cerrado' => [],
        'anulado' => [],
    ];

    public static function puedeTransicionar(string $estadoActual, string $estadoDestino): bool
    {
        if ($estadoDestino === EstadoOrdenCorte::Anulado->value) {
            return ! in_array($estadoActual, [EstadoOrdenCorte::Cerrado->value, EstadoOrdenCorte::Anulado->value], true);
        }

        return in_array($estadoDestino, self::TRANSICIONES[$estadoActual] ?? [], true);
    }

    /**
     * PUNTO DE NO RETORNO de los rollos: mientras la orden todavia puede llegar
     * a "en_corte" (pendiente_entrega, entregado_a_cortador) un rollo aun puede
     * retirarse formalmente de ella; desde "en_corte" queda historicamente
     * bloqueado. Se deriva del mapa de transiciones (no es una lista aparte):
     * "en_corte" mismo y todo lo posterior NO cuentan, y "anulado" tampoco
     * (no tiene transiciones).
     */
    public static function antesDeEnCorte(string $estado): bool
    {
        if ($estado === EstadoOrdenCorte::EnCorte->value) {
            return false;
        }

        $porVisitar = [$estado];
        $visitados = [];

        while ($porVisitar) {
            $actual = array_shift($porVisitar);

            if (isset($visitados[$actual])) {
                continue;
            }

            $visitados[$actual] = true;

            foreach (self::TRANSICIONES[$actual] ?? [] as $siguiente) {
                if ($siguiente === EstadoOrdenCorte::EnCorte->value) {
                    return true;
                }

                $porVisitar[] = $siguiente;
            }
        }

        return false;
    }

    /**
     * La orden ya inicio el corte (en_corte, reportado_por_cortador,
     * recibido_bodega, cerrado): desde aqui sus rollos no pueden modificarse,
     * eliminarse ni retirarse. Una orden anulada NO cuenta como "iniciada": se
     * puede haber anulado en cualquier punto y el estado previo no se guarda.
     */
    public static function haIniciadoCorte(string $estado): bool
    {
        return $estado !== EstadoOrdenCorte::Anulado->value && ! self::antesDeEnCorte($estado);
    }

    /**
     * Estados destino alcanzables desde el estado actual (sin incluir "anulado",
     * que se evalua aparte porque es una salida excepcional, no un paso del flujo).
     */
    public static function siguientesEstados(string $estadoActual): array
    {
        return self::TRANSICIONES[$estadoActual] ?? [];
    }
}
