<?php

namespace ByLopez\Cargues\Services\Lectura;

/**
 * Limites efectivos de una lectura: el maximo GLOBAL de `config/cargues.php`, que un tipo solo puede BAJAR.
 * Ningun valor es un limite de negocio: son salvaguardas de memoria y de tiempo, ajustables.
 */
final class LimitesLectura
{
    public function __construct(
        public readonly int $maxBytes,
        public readonly int $maxCeldas,
        public readonly int $maxFilas,
        public readonly int $maxColumnas,
        public readonly int $maxLongitudCelda,
        public readonly int $xlsxMaxBytesHoja,
        public readonly int $xlsxMaxBytesTotal,
        public readonly int $xlsxMaxEntradas,
        public readonly int $xlsxMaxRatio,
        public readonly int $bytesPorCelda,
        public readonly int $memoriaBaseBytes,
        public readonly float $memoriaFraccion,
    ) {}

    public static function para(?array $limitesTipo = null): self
    {
        $g = (array) config('cargues.limites');
        $min = fn (string $k) => isset($limitesTipo[$k]) ? min((int) $g[$k], (int) $limitesTipo[$k]) : (int) $g[$k];

        return new self(
            $min('max_bytes'), $min('max_celdas'), $min('max_filas'), (int) $g['max_columnas'], (int) $g['max_longitud_celda'],
            (int) $g['xlsx_max_bytes_hoja'], (int) $g['xlsx_max_bytes_total'], (int) $g['xlsx_max_entradas'], (int) $g['xlsx_max_ratio'],
            (int) $g['bytes_por_celda_xlsx'], (int) $g['memoria_base_mb'] * 1048576, (float) $g['memoria_maxima_fraccion'],
        );
    }

    /** Bytes de memoria que PhpSpreadsheet puede usar sin pasar de la fraccion del memory_limit (null = sin limite). */
    public function memoriaDisponible(?int $memoryLimit = null): ?int
    {
        $limite = $memoryLimit ?? self::memoryLimitActual();

        return $limite === null ? null : max(0, (int) ($limite * $this->memoriaFraccion) - $this->memoriaBaseBytes);
    }

    public static function memoryLimitActual(): ?int
    {
        $v = trim((string) ini_get('memory_limit'));

        if ($v === '' || $v === '-1') {
            return null;
        }

        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
