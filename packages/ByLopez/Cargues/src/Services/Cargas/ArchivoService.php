<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Services\Lectura\LimitesLectura;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Subida segura: disco PRIVADO (nunca `public`), nombre UUID, extension y firma comprobadas, tamano comprobado ANTES de
 * leerlo y hash SHA-256. El nombre original solo se guarda (saneado) para mostrarlo.
 */
class ArchivoService
{
    public const EXTENSIONES = ['xlsx', 'csv', 'txt'];

    /** @return array{ruta:string, nombre:string, hash:string, bytes:int, formato:string} */
    public function guardar(UploadedFile $archivo, LimitesLectura $limites): array
    {
        if (! $archivo->isValid()) {
            throw new OperacionInvalidaException('ARCHIVO_INVALIDO', 'La subida del archivo falló.');
        }

        $bytes = (int) $archivo->getSize();

        if ($bytes <= 0) {
            throw new OperacionInvalidaException('ARCHIVO_VACIO', 'El archivo está vacío.');
        }

        if ($bytes > $limites->maxBytes) {
            throw new OperacionInvalidaException('ARCHIVO_EXCEDE_BYTES', 'El archivo supera el máximo permitido de '.number_format($limites->maxBytes / 1048576, 1).' MB.');
        }

        $ext = strtolower($archivo->getClientOriginalExtension());

        if (! in_array($ext, self::EXTENSIONES, true)) {
            throw new OperacionInvalidaException('ARCHIVO_EXTENSION', 'Formatos admitidos: XLSX, CSV y TXT.');
        }

        $this->firma($archivo->getRealPath(), $ext);

        $disco = (string) config('cargues.disco', 'local');

        if ($disco === 'public') {
            throw new OperacionInvalidaException('DISCO_PUBLICO', 'Los archivos de Cargues no pueden guardarse en el disco público.');
        }

        $nombre = Str::uuid()->toString().'.'.$ext;
        $dir = trim((string) config('cargues.directorio', 'cargues'), '/').'/'.now()->format('Y/m');
        $ruta = Storage::disk($disco)->putFileAs($dir, $archivo, $nombre);

        if ($ruta === false) {
            throw new OperacionInvalidaException('ARCHIVO_NO_GUARDADO', 'No se pudo guardar el archivo.');
        }

        return [
            'ruta' => $ruta,
            'nombre' => $this->sanear($archivo->getClientOriginalName()),
            'hash' => hash_file('sha256', $archivo->getRealPath()),
            'bytes' => $bytes,
            'formato' => $ext === 'xlsx' ? 'xlsx' : 'csv',
        ];
    }

    public function rutaReal(string $ruta): string
    {
        return Storage::disk((string) config('cargues.disco', 'local'))->path($ruta);
    }

    public function eliminar(string $ruta): void
    {
        Storage::disk((string) config('cargues.disco', 'local'))->delete($ruta);
    }

    protected function firma(string $real, string $ext): void
    {
        $h = fopen($real, 'rb');
        $cabeza = (string) fread($h, 8192);
        fclose($h);

        if ($ext === 'xlsx') {
            if (! str_starts_with($cabeza, "PK\x03\x04")) {
                throw new OperacionInvalidaException('ARCHIVO_FIRMA', 'El contenido no corresponde a un XLSX (firma ZIP ausente).');
            }

            return;
        }

        if (str_contains($cabeza, "\0") || str_starts_with($cabeza, "PK\x03\x04") || str_starts_with($cabeza, '%PDF') || str_starts_with($cabeza, 'MZ')) {
            throw new OperacionInvalidaException('ARCHIVO_FIRMA', 'El contenido no es un texto delimitado.');
        }
    }

    protected function sanear(string $nombre): string
    {
        $nombre = basename(str_replace('\\', '/', $nombre));
        $nombre = preg_replace('/[\x00-\x1F\x7F]/u', '', $nombre) ?? '';

        return mb_substr($nombre === '' ? 'archivo' : $nombre, 0, 255);
    }
}
