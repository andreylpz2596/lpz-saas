<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Contracts\ComplementaResultado;
use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Support\Facades\DB;

/**
 * Resultado POR FILA del paso «Resultado» (lectura de lo persistido: T6 filas, T7 incidencias y el complemento opcional del
 * tipo). Sirve igual tras validar (filas validas / rechazadas) que tras ejecutar (aplicadas, sin cambio, antiguas...), y
 * por eso lo que se ve al recargar o entrar desde el Historial es exactamente lo mismo.
 */
class ResultadoFilasService
{
    public const POR_PAGINA = 25;

    public const FILTROS = ['todas', 'pendiente', 'aplicada', 'sin_cambio', 'omitida_antigua', 'rechazada', 'no_ejecutada', 'vacia', 'observadas'];

    /** @return array{filas: list<array<string,mixed>>, total:int, pagina:int, paginas:int, filtro:string} */
    public function pagina(Carga $carga, string $filtro = 'todas', ?string $q = null, int $pagina = 1): array
    {
        if (! in_array($carga->estado, [Estados::VALIDADA, Estados::COMPLETADA, Estados::FALLIDA_EJECUCION], true)) {
            throw new OperacionInvalidaException('ESTADO_NO_EJECUTABLE', 'El detalle por fila existe cuando la carga está validada o ejecutada.');
        }

        $filtro = in_array($filtro, self::FILTROS, true) ? $filtro : 'todas';
        $consulta = DB::table('bylopez_cg_carga_datos')->where('carga_id', $carga->id);

        if ($filtro === 'observadas') {
            $consulta->whereIn('fila_origen', DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)
                ->where('etapa', Estados::ETAPA_EJECUCION)->where('severidad', 'advertencia')->select('fila_origen'));
        } elseif ($filtro !== 'todas') {
            $consulta->where('estado_final', $filtro);
        }

        if ($q !== null && ($q = trim($q)) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)).'%';
            $consulta->where(fn ($w) => $w->where('datos_normalizados', 'like', $like)->orWhere('datos_originales', 'like', $like)
                ->orWhere('fila_origen', ctype_digit($q) ? (int) $q : -1));
        }

        $total = (int) (clone $consulta)->count();
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);
        $datos = $consulta->orderBy('fila_origen')->offset(($pagina - 1) * self::POR_PAGINA)->limit(self::POR_PAGINA)
            ->get(['fila_origen', 'estado_final', 'detalle', 'datos_normalizados']);

        $map = (array) ($carga->config_congelada['map'] ?? []);
        $etiquetas = [];

        foreach ((array) ($map['campos'] ?? []) as $c) {
            $etiquetas[$c['nombre']] = $c['etiqueta'] ?? $c['nombre'];
        }

        $llaves = (array) ($map['llaves']['primaria'] ?? []);
        $incidencias = DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)->whereIn('fila_origen', $datos->pluck('fila_origen'))
            ->orderBy('id')->get(['fila_origen', 'etapa', 'severidad', 'codigo', 'campo', 'motivo'])->groupBy('fila_origen');

        $porFila = [];
        $filas = [];

        foreach ($datos as $d) {
            $valores = (array) (json_decode((string) $d->datos_normalizados, true)['valores'] ?? []);
            $porFila[(int) $d->fila_origen] = ['estado' => $d->estado_final, 'valores' => $valores];
            $filas[(int) $d->fila_origen] = [
                'fila_origen' => (int) $d->fila_origen, 'estado' => $d->estado_final, 'detalle' => $d->detalle,
                'identificacion' => array_values(array_filter(array_map(
                    fn ($k) => isset($valores[$k]) && $valores[$k] !== '' ? ['etiqueta' => $etiquetas[$k] ?? $k, 'valor' => $this->texto($valores[$k])] : null, $llaves,
                ))),
                'valores' => array_values(array_filter(array_map(
                    fn ($k, $v) => $v === null || $v === '' ? null : ['etiqueta' => $etiquetas[$k] ?? $k, 'valor' => $this->texto($v)], array_keys($valores), $valores,
                ))),
                'incidencias' => ($incidencias->get($d->fila_origen) ?? collect())->map(fn ($i) => [
                    'etapa' => $i->etapa, 'severidad' => $i->severidad, 'codigo' => $i->codigo,
                    'campo' => $i->campo !== '' ? ($etiquetas[$i->campo] ?? $i->campo) : null, 'motivo' => $i->motivo,
                ])->values()->all(),
                'complemento' => [],
            ];
        }

        foreach ($this->complemento($map)?->complementar($carga, $porFila) ?? [] as $fila => $pares) {
            if (isset($filas[$fila])) {
                $filas[$fila]['complemento'] = $pares;
            }
        }

        return ['filas' => array_values($filas), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas, 'filtro' => $filtro];
    }

    protected function complemento(array $map): ?ComplementaResultado
    {
        $clase = config('cargues.resultado.complementos.'.($map['tabla'] ?? ''));

        return $clase && is_subclass_of($clase, ComplementaResultado::class) ? app($clase) : null;
    }

    protected function texto(mixed $v): string
    {
        return match (true) {
            is_bool($v) => $v ? 'Sí' : 'No',
            is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
            default => (string) $v,
        };
    }
}
