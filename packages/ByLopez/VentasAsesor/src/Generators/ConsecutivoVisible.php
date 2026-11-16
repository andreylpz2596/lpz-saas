<?php

namespace ByLopez\VentasAsesor\Generators;

use Illuminate\Support\Facades\DB;
use Webkul\Sales\Contracts\Sequencer;

/**
 * Número visible consecutivo e independiente del id interno (punto de extensión «clase generadora» de Bagisto).
 *
 * Bagisto arma el número con el id de la última fila + 1: con la tabla vacía da 1, pero el siguiente documento toma el
 * AUTO_INCREMENT conservado (1, 105, 106…). Aquí el último número usado vive en bylopez_va_consecutivos_visibles y su fila
 * se bloquea (SELECT … FOR UPDATE) dentro de la transacción que crea el documento: dos creaciones simultáneas se
 * serializan y, si una se revierte, su número vuelve a quedar libre. Prefijo, longitud y sufijo siguen saliendo de la
 * configuración de Bagisto.
 */
abstract class ConsecutivoVisible implements Sequencer
{
    public const TABLA = 'bylopez_va_consecutivos_visibles';

    /** Clave del consecutivo en la tabla. */
    abstract protected function clave(): string;

    /** Prefijo de las claves de configuración, p. ej. «sales.order_settings.order_number.order_number». */
    abstract protected function configuracion(): string;

    /** Tabla de los documentos numerados. */
    abstract protected function tablaDocumentos(): string;

    public function generate(): string
    {
        $numero = DB::transactionLevel() > 0 ? $this->siguiente() : DB::transaction(fn () => $this->siguiente());
        $longitud = (int) core()->getConfigData($this->configuracion().'_length');

        return core()->getConfigData($this->configuracion().'_prefix')
            .sprintf("%0{$longitud}d", $numero)
            .core()->getConfigData($this->configuracion().'_suffix');
    }

    protected function siguiente(): int
    {
        DB::table(self::TABLA)->insertOrIgnore([
            'clave' => $this->clave(), 'ultimo' => $this->inicial(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ultimo = (int) DB::table(self::TABLA)->where('clave', $this->clave())->lockForUpdate()->value('ultimo');
        DB::table(self::TABLA)->where('clave', $this->clave())->update(['ultimo' => $ultimo + 1, 'updated_at' => now()]);

        return $ultimo + 1;
    }

    /**
     * Punto de partida la primera vez: el último id de los documentos, que es lo que usaría Bagisto. Con la tabla vacía
     * es 0 (el primero sale 1); en una base con documentos se continúa sin repetir números ya emitidos.
     */
    protected function inicial(): int
    {
        return (int) DB::table($this->tablaDocumentos())->max('id');
    }
}
