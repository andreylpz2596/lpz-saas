<?php

namespace ByLopez\CorteConfeccion\Comprobantes;

use ByLopez\CorteConfeccion\Models\CarteraSoporte;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\Taller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Servicio reutilizable: adjuntar (archivo privado + huella) -> leer (borrador) -> detectar duplicados. No contabiliza nada.
 * Cada modulo toma el borrador, lo muestra para revisar, y lo confirma con sus propias reglas.
 */
class ComprobantesService
{
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public const DISCO = 'local';

    public function __construct(
        protected ComprobanteLector $lector,
        protected DeduplicadorOperaciones $deduplicador,
    ) {}

    public function lectorDisponible(): bool
    {
        return $this->lector->disponible();
    }

    public function nombreLector(): string
    {
        return $this->lector->nombre();
    }

    /**
     * Guarda el adjunto en disco PRIVADO. Si el mismo archivo (misma huella) ya esta cargado, reutiliza ese soporte
     * (no se duplica el archivo) y lo indica en `ya_cargado`.
     *
     * @return array{soporte: CarteraSoporte, ya_cargado: bool}
     */
    public function adjuntar(UploadedFile $archivo, Taller|Cortador|null $prestador, FuenteOperacionesExistentes $fuente): array
    {
        $mime = (string) $archivo->getMimeType();
        $maxMb = (int) config('corteconfeccion.comprobantes.max_mb', 10);

        if (! in_array($mime, self::MIMES, true)) {
            throw ValidationException::withMessages(['comprobantes' => 'Solo se aceptan imágenes (JPG, PNG, WEBP) o PDF.']);
        }

        if ($archivo->getSize() > $maxMb * 1024 * 1024) {
            throw ValidationException::withMessages(['comprobantes' => "Cada comprobante debe pesar máximo {$maxMb} MB."]);
        }

        $sha = hash_file('sha256', $archivo->getRealPath());
        $previos = $fuente->soportesPorHuellaArchivo($sha);

        if ($previos) {
            $existente = CarteraSoporte::query()->find($previos[0]['soporte_id']);

            if ($existente) {
                return ['soporte' => $existente, 'ya_cargado' => true];
            }
        }

        $ruta = $archivo->storeAs('cartera_soportes/'.date('Y/m'), $sha.'.'.($archivo->extension() ?: 'bin'), self::DISCO);

        $soporte = CarteraSoporte::query()->create([
            'taller_id' => $prestador instanceof Taller ? $prestador->id : null,
            'cortador_id' => $prestador instanceof Cortador ? $prestador->id : null,
            'ruta' => $ruta,
            'nombre_original' => mb_substr($archivo->getClientOriginalName(), 0, 250),
            'mime' => $mime,
            'tamano' => (int) $archivo->getSize(),
            'sha256' => $sha,
            'lectura_estado' => 'sin_leer',
            'subido_por' => auth()->guard('admin')->id(),
        ]);

        return ['soporte' => $soporte, 'ya_cargado' => false];
    }

    /**
     * Lee el soporte y deja el resultado como borrador. Si el servicio no esta disponible o falla, el soporte se conserva y
     * se completa a mano; nunca se obliga a empezar de nuevo.
     *
     * Si el mismo archivo ya tiene una lectura utilizable (hecha con las reglas vigentes) se REUTILIZA sin ejecutar el OCR de
     * nuevo; `$forzar` es la relectura explicita.
     *
     * @return array{estado: string, borradores: list<BorradorOperacion>, mensaje: ?string, reutilizada: bool}
     */
    public function leer(CarteraSoporte $soporte, bool $forzar = false): array
    {
        if (! $forzar && $soporte->lectura_estado === 'leida' && $soporte->lectura_proveedor === $this->lector->nombre() && ! empty($soporte->lectura_resultado)) {
            $borradores = array_map(function (array $fila) use ($soporte) {
                $b = BorradorOperacion::desdeArray($fila);
                $b->soporteId = $soporte->id;

                return $b;
            }, (array) $soporte->lectura_resultado);

            return ['estado' => 'leida', 'borradores' => $borradores, 'mensaje' => null, 'reutilizada' => true];
        }

        if (! $this->lector->disponible()) {
            $soporte->update(['lectura_estado' => 'no_disponible', 'lectura_proveedor' => $this->lector->nombre()]);

            return ['estado' => 'no_disponible', 'borradores' => [], 'mensaje' => 'La lectura automática no está disponible en este servidor. Completa los datos manualmente.', 'reutilizada' => false];
        }

        try {
            $contenido = Storage::disk(self::DISCO)->get($soporte->ruta);
            $borradores = $this->lector->leer((string) $contenido, $soporte->mime, $soporte->nombre_original);

            foreach ($borradores as $b) {
                $b->soporteId = $soporte->id;
            }

            $soporte->update([
                'lectura_estado' => 'leida',
                'lectura_proveedor' => $this->lector->nombre(),
                'lectura_resultado' => array_map(fn (BorradorOperacion $b) => $b->aArray(), $borradores),
                'lectura_error' => null,
            ]);

            return ['estado' => 'leida', 'borradores' => $borradores, 'mensaje' => $borradores ? null : 'No se encontraron datos legibles. Completa los datos mirando el comprobante.', 'reutilizada' => false];
        } catch (LecturaComprobanteException $e) {
            $soporte->update(['lectura_estado' => 'error', 'lectura_proveedor' => $this->lector->nombre(), 'lectura_error' => mb_substr($e->getMessage(), 0, 500)]);

            return ['estado' => 'error', 'borradores' => [], 'mensaje' => 'No se pudo leer el comprobante ('.$e->getMessage().') Quedó guardado: completa los datos manualmente o reintenta.', 'reutilizada' => false];
        }
    }

    /**
     * @param  list<BorradorOperacion>  $lote
     * @return list<array<string, mixed>>
     */
    public function analizarDuplicados(array $lote, FuenteOperacionesExistentes $fuente): array
    {
        return $this->deduplicador->analizar($lote, $fuente);
    }
}
