<?php

namespace ByLopez\Cargues\Services\Cargas\Complementos;

use ByLopez\Cargues\Contracts\ComplementaResultado;
use ByLopez\Cargues\Models\Carga;
use Illuminate\Support\Facades\DB;

/**
 * Complemento del tipo «99 Envíos — Envíos completos»: SOLO LEE lo que el procedimiento dejo persistido en Logistica
 * (guia, estado reportado y su historial). Resuelve la transportadora EFECTIVA con la misma equivalencia y la misma
 * igualdad exacta que el procedimiento (codigo o nombre del proveedor), y muestra, segun el resultado de la fila, el
 * estado anterior y el nuevo, o el ya registrado, y el recaudo comparado.
 */
class EnviosCompletosResultado implements ComplementaResultado
{
    public function complementar(Carga $carga, array $filas): array
    {
        $salida = [];
        $equivalencias = DB::table('bylopez_lg_proveedor_transportadoras')->where('proveedor', '99envios')->where('activo', 1)
            ->get(['codigo_externo', 'nombre_externo', 'transportadora_codigo']);
        $eventos = DB::table('bylopez_lg_guia_estado_proveedor_eventos')->where('carga_id', $carga->id)->whereIn('fila_origen', array_keys($filas))->get()->keyBy('fila_origen');

        foreach ($filas as $n => $f) {
            $v = $f['valores'];
            $tr = (string) ($v['transportadora'] ?? '');
            $guia = (string) ($v['numero_de_guia'] ?? '');

            if ($tr === '' && $guia === '') {
                continue;
            }

            $coinciden = $tr === '' ? collect() : $equivalencias->filter(fn ($e) => strcmp((string) $e->codigo_externo, $tr) === 0 || strcmp((string) $e->nombre_externo, $tr) === 0);
            $efectiva = $coinciden->count() === 1 ? $coinciden->first()->transportadora_codigo : null;
            $registro = $efectiva && $guia !== ''
                ? DB::table('bylopez_lg_guias')->where('transportadora_codigo', $efectiva)->whereRaw('BINARY numero_guia = ?', [$guia])->first(['id', 'order_id', 'recaudo_esperado'])
                : null;

            $pares = [
                $this->par('res_transportadora_efectiva', $efectiva ? $efectiva.' ('.trans('cargues::app.res.via_99').')' : trans('cargues::app.res.sin_equivalencia', ['valor' => $tr])),
                $this->par('res_guia_logistica', $registro ? '#'.$registro->id : trans('cargues::app.res.sin_guia')),
            ];

            $ev = $eventos->get($n);
            $actual = $registro ? DB::table('bylopez_lg_guia_estados_proveedor')->where('guia_id', $registro->id)->where('proveedor', '99envios')->first() : null;
            $archivo = ($v['estado_del_envio'] ?? '—').' · '.($v['fecha_actualizacion'] ?? '—');

            if ($ev) {
                $pares[] = $this->par('res_estado_anterior', $ev->estado_anterior ? $ev->estado_anterior.' · '.$ev->fecha_estado_anterior : trans('cargues::app.res.ninguno'));
                $pares[] = $this->par('res_estado_nuevo', $ev->estado_proveedor.' · '.$ev->fecha_estado_proveedor);
                $pares[] = $this->par('res_recaudo', trans('cargues::app.res.recaudo_texto', [
                    'rep' => $ev->recaudo_reportado === null ? '—' : $this->dinero($ev->recaudo_reportado), 'int' => $this->dinero($ev->recaudo_interno),
                    'dif' => $ev->recaudo_diferencia === null ? '—' : $this->dinero($ev->recaudo_diferencia), 'est' => trans('cargues::app.res.conc_'.$ev->conciliacion_recaudo),
                ]));
            } elseif ($actual && in_array($f['estado'], ['sin_cambio', 'omitida_antigua'], true)) {
                $pares[] = $this->par('res_estado_registrado', $actual->estado_proveedor.' · '.$actual->fecha_estado_proveedor);
                $pares[] = $this->par('res_estado_archivo', $archivo);
            } elseif ($f['estado'] === 'pendiente') {
                $pares[] = $this->par('res_estado_archivo', $archivo);
                $pares[] = $this->par('res_estado_registrado', $actual ? $actual->estado_proveedor.' · '.$actual->fecha_estado_proveedor : trans('cargues::app.res.ninguno'));
                $pares[] = $this->par('res_recaudo_previo', trans('cargues::app.res.recaudo_previo', [
                    'com' => isset($v['valor_comercial']) ? $this->dinero($v['valor_comercial']) : '—', 'int' => $registro ? $this->dinero($registro->recaudo_esperado) : '—',
                ]));
            }

            $salida[$n] = $pares;
        }

        return $salida;
    }

    protected function par(string $clave, string $valor): array
    {
        return ['etiqueta' => trans('cargues::app.res.'.$clave), 'valor' => $valor];
    }

    protected function dinero(mixed $v): string
    {
        return '$ '.number_format((float) $v, 0, ',', '.');
    }
}
