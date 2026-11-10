<?php

namespace ByLopez\CorteConfeccion\Comprobantes;

/** Lector por defecto: sin credenciales o sin activar. Nunca inventa datos. */
class LectorNoDisponible implements ComprobanteLector
{
    public function nombre(): string
    {
        return 'no_disponible';
    }

    public function disponible(): bool
    {
        return false;
    }

    public function leer(string $contenido, string $mime, string $nombre): array
    {
        throw new LecturaComprobanteException('La lectura asistida no esta activada. Registra los datos manualmente.');
    }
}
