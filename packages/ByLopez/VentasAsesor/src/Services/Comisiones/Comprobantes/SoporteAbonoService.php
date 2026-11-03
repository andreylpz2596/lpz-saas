<?php

namespace ByLopez\VentasAsesor\Services\Comisiones\Comprobantes;

use ByLopez\CorteConfeccion\Comprobantes\BorradorOperacion;
use ByLopez\CorteConfeccion\Comprobantes\ComprobanteLector;
use ByLopez\CorteConfeccion\Comprobantes\DeduplicadorOperaciones;
use ByLopez\CorteConfeccion\Comprobantes\LectorNoDisponible;
use ByLopez\CorteConfeccion\Comprobantes\LecturaComprobanteException;
use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ComisionLiquidacion;
use ByLopez\VentasAsesor\Models\ComisionSoporte;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autorizacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\User\Contracts\Admin;

/**
 * Comprobantes de PAGOS A ASESORAS: cargar -> leer -> analizar duplicados. NO registra ni aprueba nada: solo el abono confirmado
 * explicitamente por administracion (LiquidacionesComisionService::registrarAbono) lo hace.
 *
 * Reutiliza la lectura asistida de CorteConfeccion SIN modificarla: el lector (ComprobanteLector, OCR LOCAL con Tesseract; el archivo
 * no sale del servidor ni se envia a ningun servicio externo), el borrador (BorradorOperacion) y el deduplicador. Lo propio de aqui es
 * el almacenamiento (tabla de soportes y disco privado de comisiones) y la fuente de coincidencias (FuenteAbonosAsesor).
 *
 * La lectura es una AYUDA, no una verificacion: no confirma autenticidad ni que el dinero se movio en un banco. Si el lector no esta
 * disponible o falla, el soporte se conserva y el abono se diligencia a mano.
 */
class SoporteAbonoService
{
    /** Tipos admitidos por el modulo de comisiones (`ventasasesor.comisiones.comprobantes.mimes`). */
    private const MIME_POR_EXTENSION = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    public function __construct(
        protected Autorizacion $autorizacion,
        protected FuenteAbonosAsesor $fuente,
    ) {}

    /**
     * Guarda el archivo (disco privado) y devuelve el soporte. Un archivo IDENTICO (misma huella) ya cargado para esta liquidacion se
     * reutiliza en vez de duplicarlo y se informa en `ya_cargado`.
     *
     * @return array{soporte: ComisionSoporte, ya_cargado: bool}
     */
    public function subir(?Admin $actor, int $liquidacionId, UploadedFile $archivo): array
    {
        $this->exigirPagar($actor);
        $liq = ComisionLiquidacion::query()->find($liquidacionId) ?? throw new Invalida(Invalida::NO_ENCONTRADO, 'La liquidación no existe.');

        if ($liq->estado !== ComisionLiquidacion::APROBADA) {
            throw new Invalida(Invalida::ESTADO_INVALIDO, 'Solo se cargan comprobantes para una liquidación aprobada.');
        }

        [$mime, $ext] = $this->validar($archivo);
        $sha = hash_file('sha256', $archivo->getRealPath());

        if ($previo = ComisionSoporte::query()->where('sha256', $sha)->where('liquidacion_id', $liq->id)->orderBy('id')->first()) {
            return ['soporte' => $previo, 'ya_cargado' => true];
        }

        $cfg = (array) config('ventasasesor.comisiones.comprobantes');
        $disco = (string) $cfg['disco'];
        $ruta = Storage::disk($disco)->putFileAs('ventas-asesor/comisiones/soportes/'.now()->format('Y/m'), $archivo, Str::uuid().'.'.$ext);

        if ($ruta === false) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'No se pudo guardar el comprobante.');
        }

        $soporte = ComisionSoporte::query()->create([
            'asesor_admin_id' => (int) $liq->asesor_admin_id,
            'liquidacion_id' => (int) $liq->id,
            'disco' => $disco,
            'ruta' => $ruta,
            'nombre_original' => mb_substr((string) $archivo->getClientOriginalName(), 0, 190),
            'mime' => $mime,
            'tamano' => (int) $archivo->getSize(),
            'sha256' => $sha,
            'lectura_estado' => 'sin_leer',
            'subido_por_admin_id' => (int) $actor->id,
        ]);

        return ['soporte' => $soporte, 'ya_cargado' => false];
    }

    /**
     * Lee el soporte y deja el resultado como BORRADOR. Un archivo ya leido con las reglas vigentes reutiliza su lectura (`$forzar` es la
     * relectura explicita). Si el lector no esta disponible o falla, el soporte queda y se completa a mano.
     *
     * @return array{estado: string, borrador: ?BorradorOperacion, mensaje: ?string}
     */
    public function leer(?Admin $actor, int $soporteId, bool $forzar = false): array
    {
        $this->exigirPagar($actor);
        $soporte = ComisionSoporte::query()->find($soporteId) ?? throw new Invalida(Invalida::NO_ENCONTRADO, 'El comprobante no existe.');
        $lector = $this->lector();

        if (! $forzar && $soporte->lectura_estado === 'leida' && $soporte->lectura_proveedor === $lector->nombre() && ! empty($soporte->lectura_resultado)) {
            return ['estado' => 'leida', 'borrador' => $this->primero((array) $soporte->lectura_resultado, $soporte), 'mensaje' => null];
        }

        if (! $lector->disponible()) {
            $soporte->update(['lectura_estado' => 'no_disponible', 'lectura_proveedor' => $lector->nombre()]);

            return ['estado' => 'no_disponible', 'borrador' => null, 'mensaje' => 'La lectura automática no está disponible en este servidor. Completa los datos mirando el comprobante.'];
        }

        try {
            @set_time_limit((int) config('corteconfeccion.comprobantes.tiempo_max', 120) + 30);
            $contenido = (string) Storage::disk($soporte->disco)->get($soporte->ruta);
            $borradores = $lector->leer($contenido, $soporte->mime, $soporte->nombre_original);

            foreach ($borradores as $b) {
                $this->comoEgreso($b);
                $b->soporteId = $soporte->id;
            }

            $soporte->update([
                'lectura_estado' => 'leida',
                'lectura_proveedor' => $lector->nombre(),
                'lectura_resultado' => array_map(fn (BorradorOperacion $b) => $b->aArray(), $borradores),
                'lectura_error' => null,
            ]);

            return [
                'estado' => 'leida',
                'borrador' => $borradores[0] ?? null,
                'mensaje' => $borradores === [] ? 'No se encontraron datos legibles. Completa los datos mirando el comprobante.' : null,
            ];
        } catch (LecturaComprobanteException $e) {
            $soporte->update(['lectura_estado' => 'error', 'lectura_proveedor' => $lector->nombre(), 'lectura_error' => mb_substr($e->getMessage(), 0, 500)]);

            return ['estado' => 'error', 'borrador' => null, 'mensaje' => 'No se pudo leer el comprobante. Quedó guardado: completa los datos manualmente o reintenta.'];
        }
    }

    /**
     * Coincidencias del borrador con lo ya registrado, SIN concluir duplicidad por monto y fecha. Estados: nueva | posible_duplicado |
     * duplicado (misma huella). Aparte informa si el ARCHIVO ya respalda otro abono (misma huella de archivo).
     *
     * @return array{estado: string, coincidencias: list<array<string, mixed>>, archivo_repetido: list<array<string, mixed>>}
     */
    public function analizar(?Admin $actor, int $soporteId, ?BorradorOperacion $borrador): array
    {
        $this->exigirPagar($actor);
        $soporte = ComisionSoporte::query()->find($soporteId) ?? throw new Invalida(Invalida::NO_ENCONTRADO, 'El comprobante no existe.');

        $archivo = array_values(array_filter(
            $this->fuente->soportesPorHuellaArchivo($soporte->sha256),
            fn (array $f) => (int) $f['soporte_id'] !== (int) $soporte->id || ! empty($f['movimiento_id'])
        ));

        if ($borrador === null) {
            return ['estado' => DeduplicadorOperaciones::NUEVA, 'coincidencias' => [], 'archivo_repetido' => $archivo];
        }

        $r = app(DeduplicadorOperaciones::class)->analizar([$this->comoEgreso(clone $borrador)], $this->fuente)[0];

        return ['estado' => $r['estado'], 'coincidencias' => $r['coincidencias'], 'archivo_repetido' => $archivo];
    }

    /** Huella de la operacion de un abono: referencia + medio + fecha + valor + moneda + egreso (la MISMA regla en el analisis y en el registro). */
    public function huella(?string $medio, ?string $referencia, ?string $fecha, mixed $valor): ?string
    {
        return $this->comoEgreso(new BorradorOperacion(valor: $valor === null ? null : (string) $valor, fecha: $fecha, medio: $medio, referencia: $referencia))->huella();
    }

    public function referenciaNormalizada(?string $referencia): ?string
    {
        return (new BorradorOperacion(referencia: $referencia))->referenciaNormalizada();
    }

    /** Un pago a una asesora es SIEMPRE un egreso de ByLopez; el banco se normaliza al medio para que la huella sea comparable. */
    protected function comoEgreso(BorradorOperacion $b): BorradorOperacion
    {
        $b->direccion = BorradorOperacion::EGRESO;
        $b->medio = $b->medio ?? $b->bancoOrigen ?? $b->bancoDestino;
        $b->bancoOrigen = null;
        $b->bancoDestino = null;

        return $b;
    }

    /** @param  array<int, array<string, mixed>>  $filas */
    protected function primero(array $filas, ComisionSoporte $soporte): ?BorradorOperacion
    {
        if ($filas === []) {
            return null;
        }

        $b = $this->comoEgreso(BorradorOperacion::desdeArray($filas[0]));
        $b->soporteId = $soporte->id;

        return $b;
    }

    protected function lector(): ComprobanteLector
    {
        return interface_exists(ComprobanteLector::class) && app()->bound(ComprobanteLector::class) ? app(ComprobanteLector::class) : new LectorNoDisponible;
    }

    /** @return array{0: string, 1: string} mime real y extension */
    protected function validar(UploadedFile $archivo): array
    {
        $cfg = (array) config('ventasasesor.comisiones.comprobantes');
        $ext = strtolower((string) $archivo->guessExtension());
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;

        if (! $archivo->isValid() || ! in_array($ext, array_map(fn ($e) => $e === 'jpeg' ? 'jpg' : $e, (array) $cfg['mimes']), true) || $archivo->getSize() > ((int) $cfg['max_kb']) * 1024) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El comprobante debe ser PDF, JPG o PNG de hasta '.((int) $cfg['max_kb'] / 1024).' MB.');
        }

        $mime = (string) $archivo->getMimeType();

        // El contenido manda sobre el nombre: un .png que no es imagen (o un PDF disfrazado) se rechaza.
        if ($mime !== self::MIME_POR_EXTENSION[$ext]) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El contenido del archivo no corresponde a su extensión. Sube el PDF, JPG o PNG original.');
        }

        return [$mime, $ext];
    }

    protected function exigirPagar(?Admin $actor): void
    {
        if (! $actor?->id || ! $this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_COMISIONES_PAGAR)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tiene permiso para registrar pagos de comisiones.');
        }
    }
}
