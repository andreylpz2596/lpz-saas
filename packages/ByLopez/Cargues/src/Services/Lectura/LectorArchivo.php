<?php

namespace ByLopez\Cargues\Services\Lectura;

/**
 * Interfaz de lectura (09 §2.1). Permite cambiar de implementacion (hoy PhpSpreadsheet para XLSX y fgetcsv para CSV)
 * sin tocar el resto del motor. Todas las filas conservan su NUMERO real y las filas vacias se devuelven.
 */
interface LectorArchivo
{
    /**
     * Revisa el archivo y sus limites sin cargarlo entero. Lanza OperacionInvalidaException si lo supera.
     */
    public function inspeccionar(string $ruta, OpcionesLectura $opciones, LimitesLectura $limites): InspeccionArchivo;

    /**
     * Fila de encabezados y las primeras `$n` filas de datos, para la pantalla de relacion de columnas.
     *
     * @return array{encabezados: FilaLeida|null, filas: FilaLeida[]}
     */
    public function vistaPrevia(string $ruta, OpcionesLectura $opciones, LimitesLectura $limites, int $n = 5): array;

    /**
     * TODAS las filas de datos (despues del encabezado), una a una y con su numero real.
     *
     * @return iterable<FilaLeida>
     */
    public function filas(string $ruta, OpcionesLectura $opciones, LimitesLectura $limites): iterable;
}
