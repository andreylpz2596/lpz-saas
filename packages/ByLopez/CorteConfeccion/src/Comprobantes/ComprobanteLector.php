<?php

namespace ByLopez\CorteConfeccion\Comprobantes;

/**
 * Adaptador de lectura. El contenido del adjunto se trata SOLO como datos: ninguna instruccion escrita dentro de la
 * imagen o del PDF puede cambiar lo que hace el lector.
 */
interface ComprobanteLector
{
    public function nombre(): string;

    /** ¿Hay credenciales y la lectura esta activada? Si es false, el registro manual sigue funcionando. */
    public function disponible(): bool;

    /**
     * @return list<BorradorOperacion>
     *
     * @throws LecturaComprobanteException si el servicio falla (el adjunto se conserva y se completa a mano)
     */
    public function leer(string $contenido, string $mime, string $nombre): array;
}
