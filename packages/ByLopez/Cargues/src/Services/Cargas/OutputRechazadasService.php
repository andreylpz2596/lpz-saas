<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as Escritor;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Outputs descargables (A8, 07 §9), con TODAS las columnas originales con su encabezado y en su orden:
 *  - NO CARGADAS: solo las filas rechazadas (en la validacion o en la ejecucion); una fila por incidencia;
 *  - PENDIENTES (tras ejecutar): las filas APLICADAS (o sin cambio) que quedaron con una OBSERVACION que pide una accion humana
 *    (p. ej. una direccion pendiente de completar). NO son rechazadas: se aplicaron; esta salida lista que falta, con las columnas originales.
 *  - RESULTADO COMPLETO (tras ejecutar, E2): todas las filas del archivo con su estado final (aplicada, sin_cambio,
 *    omitida_antigua, rechazada, vacia, no_ejecutada), su detalle y una linea por incidencia u observacion.
 * Contenido neutralizado contra formulas: en CSV se antepone «'» a lo que empiece por = + - @ tab o CR; en XLSX todo va
 * como texto explicito con prefijo de comilla.
 */
class OutputRechazadasService
{
    public const FIJAS = ['fila_origen', 'estado_final', 'etapa', 'campo', 'codigo', 'motivo'];

    public const FIJAS_PENDIENTES = ['fila_origen', 'estado_final', 'detalle', 'codigo', 'pendiente'];

    public const FIJAS_RESULTADO = ['fila_origen', 'estado_final', 'detalle', 'etapa', 'severidad', 'codigo', 'motivo'];

    public function __construct(protected Normalizador $normalizador) {}

    public function csv(Carga $carga, bool $completo = false, bool $pendientes = false): StreamedResponse
    {
        $cabecera = $this->cabecera($carga);

        return response()->streamDownload(function () use ($carga, $cabecera, $completo, $pendientes) {
            $h = fopen('php://output', 'wb');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, array_map([$this, 'neutralizar'], array_merge($this->fijas($completo, $pendientes), $cabecera['textos'])), ';', '"', '');

            foreach ($this->origen($carga, $cabecera['ancho'], $completo, $pendientes) as $fila) {
                fputcsv($h, array_map([$this, 'neutralizar'], $fila), ';', '"', '');
            }

            fclose($h);
        }, $this->nombre($carga, $completo, 'csv', $pendientes), ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function xlsx(Carga $carga, bool $completo = false, bool $pendientes = false): StreamedResponse
    {
        $max = (int) config('cargues.output.xlsx_max_filas', 20000);
        $cabecera = $this->cabecera($carga);
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle($pendientes ? 'Pendientes' : ($completo ? 'Resultado' : 'No cargadas'));
        $fila = 1;

        $escribir = function (array $valores) use ($hoja, &$fila) {
            foreach (array_values($valores) as $i => $v) {
                $texto = (string) $v;
                $celda = Coordinate::stringFromColumnIndex($i + 1).$fila;
                $hoja->setCellValueExplicit($celda, $texto, DataType::TYPE_STRING);

                if ($texto !== '' && strpbrk($texto[0], "=+-@\t\r") !== false) {
                    $hoja->getStyle($celda)->setQuotePrefix(true);
                }
            }

            $fila++;
        };

        $escribir(array_merge($this->fijas($completo, $pendientes), $cabecera['textos']));

        foreach ($this->origen($carga, $cabecera['ancho'], $completo, $pendientes) as $valores) {
            if ($fila > $max + 1) {
                throw new OperacionInvalidaException('OUTPUT_MUY_GRANDE', "Hay más de {$max} filas: descargue el CSV.");
            }

            $escribir($valores);
        }

        return response()->streamDownload(function () use ($libro) {
            (new Escritor($libro))->save('php://output');
        }, $this->nombre($carga, $completo, 'xlsx', $pendientes), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff']);
    }

    protected function nombre(Carga $carga, bool $completo, string $ext, bool $pendientes = false): string
    {
        return ($pendientes ? 'pendientes_carga_' : ($completo ? 'resultado_carga_' : 'rechazadas_carga_')).$carga->id.'.'.$ext;
    }

    protected function fijas(bool $completo, bool $pendientes): array
    {
        return $pendientes ? self::FIJAS_PENDIENTES : ($completo ? self::FIJAS_RESULTADO : self::FIJAS);
    }

    /** @return iterable<array<int, string>> */
    protected function origen(Carga $carga, int $ancho, bool $completo, bool $pendientes = false): iterable
    {
        return $pendientes ? $this->filasPendientes($carga, $ancho) : ($completo ? $this->filasResultado($carga, $ancho) : $this->filas($carga, $ancho));
    }

    /** @return array{textos: string[], ancho: int} encabezados originales en su orden (o la letra si no tienen texto) */
    protected function cabecera(Carga $carga): array
    {
        $cols = (array) ($carga->encabezados['columnas'] ?? []);
        $textos = [];

        foreach ($cols as $c) {
            $textos[] = $c['texto'] !== '' ? $c['texto'] : '(columna '.$c['letra'].')';
        }

        return ['textos' => $textos, 'ancho' => count($textos)];
    }

    /** @return iterable<array<int, string>> */
    protected function filas(Carga $carga, int $ancho): iterable
    {
        $consulta = DB::table('bylopez_cg_carga_incidencias as i')
            ->join('bylopez_cg_carga_datos as d', function ($j) {
                $j->on('d.carga_id', '=', 'i.carga_id')->on('d.fila_origen', '=', 'i.fila_origen');
            })
            ->where('i.carga_id', $carga->id)->where('i.severidad', 'error')->where('d.estado_final', 'rechazada')
            ->orderBy('i.fila_origen')->orderBy('i.id')
            ->select('i.id', 'i.fila_origen', 'i.etapa', 'i.campo', 'i.codigo', 'i.motivo', 'd.estado_final', 'd.datos_originales');

        foreach ($consulta->lazyById(500, 'i.id', 'id') as $r) {
            yield array_merge([$r->fila_origen, $r->estado_final, $r->etapa, $r->campo, $r->codigo, $r->motivo], $this->originales($r->datos_originales, $ancho));
        }
    }

    /**
     * PENDIENTES: filas con una OBSERVACION de la ejecucion (advertencia) que NO fueron rechazadas; una linea por observacion, con las
     * columnas originales. El texto de la observacion dice que falta (p. ej. «Dirección pendiente de completar. Texto original: …»).
     *
     * @return iterable<array<int, string>>
     */
    protected function filasPendientes(Carga $carga, int $ancho): iterable
    {
        $consulta = DB::table('bylopez_cg_carga_incidencias as i')
            ->join('bylopez_cg_carga_datos as d', function ($j) {
                $j->on('d.carga_id', '=', 'i.carga_id')->on('d.fila_origen', '=', 'i.fila_origen');
            })
            ->where('i.carga_id', $carga->id)->where('i.etapa', 'ejecucion')->where('i.severidad', 'advertencia')->where('d.estado_final', '!=', 'rechazada')
            ->orderBy('i.fila_origen')->orderBy('i.id')
            ->select('i.id', 'i.fila_origen', 'i.codigo', 'i.motivo', 'd.estado_final', 'd.detalle', 'd.datos_originales');

        foreach ($consulta->lazyById(500, 'i.id', 'id') as $r) {
            yield array_merge([$r->fila_origen, $r->estado_final, (string) $r->detalle, $r->codigo, $r->motivo], $this->originales($r->datos_originales, $ancho));
        }
    }

    /**
     * Todas las filas del archivo, en orden, con su estado final y una linea por incidencia u observacion (sin
     * incidencias, una sola linea). Las incidencias se leen por lotes del mismo rango de filas.
     *
     * @return iterable<array<int, string>>
     */
    protected function filasResultado(Carga $carga, int $ancho): iterable
    {
        $lote = 500;
        $desde = 0;

        do {
            $datos = DB::table('bylopez_cg_carga_datos')->where('carga_id', $carga->id)->where('id', '>', $desde)->orderBy('id')->limit($lote)
                ->get(['id', 'fila_origen', 'estado_final', 'detalle', 'datos_originales']);

            if ($datos->isEmpty()) {
                break;
            }

            $desde = (int) $datos->last()->id;
            $porFila = DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)
                ->whereIn('fila_origen', $datos->pluck('fila_origen')->all())->orderBy('id')
                ->get(['fila_origen', 'etapa', 'severidad', 'codigo', 'motivo', 'campo'])->groupBy('fila_origen');

            foreach ($datos as $d) {
                $cols = $this->originales($d->datos_originales, $ancho);
                $incidencias = $porFila->get($d->fila_origen, collect());

                if ($incidencias->isEmpty()) {
                    yield array_merge([$d->fila_origen, $d->estado_final, (string) $d->detalle, '', '', '', ''], $cols);

                    continue;
                }

                foreach ($incidencias as $i) {
                    yield array_merge([$d->fila_origen, $d->estado_final, (string) $d->detalle, $i->etapa, $i->severidad, $i->codigo, $i->campo !== '' ? '['.$i->campo.'] '.$i->motivo : $i->motivo], $cols);
                }
            }
        } while (true);
    }

    /** @return string[] */
    protected function originales(?string $json, int $ancho): array
    {
        $originales = json_decode((string) $json, true) ?: [];
        $cols = [];

        for ($i = 0; $i < max($ancho, count($originales)); $i++) {
            $cols[] = $this->crudo($originales[$i] ?? null);
        }

        return $cols;
    }

    /** Valor original TAL COMO VINO (sin recortar). */
    protected function crudo(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'VERDADERO' : 'FALSO',
            is_array($v) => (string) ($v['d'] ?? $v['f'] ?? $v['e'] ?? $v['n'] ?? ''),
            is_float($v) => rtrim(rtrim(sprintf('%.15F', $v), '0'), '.'),
            default => (string) $v,
        };
    }

    public function neutralizar(mixed $v): string
    {
        $t = (string) $v;

        return ($t !== '' && strpbrk($t[0], "=+-@\t\r") !== false) ? "'".$t : $t;
    }
}
