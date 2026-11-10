<?php

namespace ByLopez\Cargues\Services\Cargas\Complementos;

use ByLopez\Cargues\Contracts\ComplementaResultado;
use ByLopez\Cargues\Models\Carga;
use Illuminate\Support\Facades\DB;

/**
 * Complemento del tipo «Clientes históricos — 99 Envíos»: SOLO LEE lo que quedó persistido. Por fila muestra el cliente (id), el
 * nombre COMPLETO original del archivo (la separación nombre/apellido de la ficha es una propuesta), la dirección pendiente de
 * completar con su texto original (si la hay) y el antecedente registrado. Una dirección pendiente NO es un rechazo: la fila se aplicó.
 */
class ClientesHistoricosResultado implements ComplementaResultado
{
    public function complementar(Carga $carga, array $filas): array
    {
        $guias = [];

        foreach ($filas as $n => $f) {
            if (isset($f['valores']['numero_de_guia'])) {
                $guias[$n] = (string) $f['valores']['numero_de_guia'];
            }
        }

        if ($guias === [] || ! \Illuminate\Support\Facades\Schema::hasTable('bylopez_va_cliente_envios_historicos')) {
            return [];
        }

        $proveedor = (string) config('cargues.clientes_historicos.proveedor', '99envios');
        $registros = DB::table('bylopez_va_cliente_envios_historicos')->where('proveedor', $proveedor)->whereIn('numero_guia', array_values($guias))->get()->groupBy('numero_guia');
        $pendientes = DB::table('bylopez_va_cliente_direcciones_pendientes')->where('carga_id', $carga->id)->whereIn('fila_origen', array_keys($guias))->get()->groupBy('fila_origen');
        $salida = [];

        foreach ($guias as $n => $g) {
            $pares = [];
            $reg = ($registros->get($g) ?? collect())->first();

            if ($reg) {
                $pares[] = $this->par('res_cliente', '#'.$reg->customer_id);
                $pares[] = $this->par('res_envio', ($reg->transportadora_clave === '' ? trans('cargues::app.res.sin_determinar') : (str_starts_with($reg->transportadora_clave, 'PEND:') ? '«'.$reg->transportadora_original.'» ('.trans('cargues::app.res.sin_equivalencia_corta').')' : $reg->transportadora_clave)).' · '.$reg->estado_proveedor.' · '.($reg->fecha_estado ?: trans('cargues::app.res.sin_fecha')));

                if ($reg->nombre_reportado) {
                    $pares[] = $this->par('res_nombre_reportado', $reg->nombre_reportado.' — '.trans('cargues::app.res.nombre_propuesta'));
                }
            }

            foreach ($pendientes->get($n, collect()) as $p) {
                $pares[] = $this->par('res_direccion_pendiente', trans('cargues::app.res.direccion_pendiente').': «'.$p->direccion_original.($p->ciudad_original ? ' — '.$p->ciudad_original : '').'» ('.$p->motivo.')');
            }

            if ($pares !== []) {
                $salida[$n] = $pares;
            }
        }

        return $salida;
    }

    protected function par(string $clave, string $valor): array
    {
        return ['etiqueta' => trans('cargues::app.res.'.$clave), 'valor' => $valor];
    }
}
