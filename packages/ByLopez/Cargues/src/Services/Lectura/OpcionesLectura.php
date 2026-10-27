<?php

namespace ByLopez\Cargues\Services\Lectura;

/** Parametros de lectura que SI puede elegir quien carga (09 §2.1): hoja, fila de encabezados y, en CSV, formato. */
final class OpcionesLectura
{
    public const CODIFICACIONES = ['UTF-8', 'Windows-1252', 'ISO-8859-1'];

    public const DELIMITADORES = [';', ',', "\t", '|'];

    public const ENTRECOMILLADOS = ['"', "'"];

    public function __construct(
        public readonly ?string $hoja = null,
        public readonly int $filaEncabezado = 1,
        public readonly string $delimitador = ';',
        public readonly string $entrecomillado = '"',
        public readonly string $codificacion = 'UTF-8',
    ) {}

    public static function desdeCarga($carga): self
    {
        return new self(
            $carga->hoja,
            (int) $carga->fila_encabezado ?: 1,
            $carga->delimitador ?: ';',
            $carga->entrecomillado ?: '"',
            $carga->codificacion ?: 'UTF-8',
        );
    }
}
