<?php

namespace ByLopez\Logistica\Services\Guias;

use ByLopez\Logistica\Contracts\AccesoGuiaPedido;
use ByLopez\Logistica\Enums\OrigenArchivoGuia;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\GuiaArchivo;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Webkul\User\Contracts\Admin;

/**
 * PDF de la guia: el DOCUMENTO del envio (el numero de guia es un dato aparte, en `Guia`).
 *
 *  - Solo PDF, validado por CONTENIDO (firma `%PDF-` al inicio, tolerando BOM/espacios/saltos de linea, y tipo detectado), nunca por extension ni por el tipo que declara
 *    el navegador. Maximo 5 MB.
 *  - Disco PRIVADO, nombre generado (nunca el del usuario). Se sirve solo por una ruta autenticada y autorizada.
 *  - Versionado: un reemplazo crea una version nueva (motivo obligatorio, quien, cuando) y deja la anterior como
 *    reemplazada. Nunca se sobrescribe ni se borra. Subir el mismo archivo otra vez no crea otra version.
 *  - La API de una transportadora (futuro) usa `almacenar()` con `OrigenArchivoGuia::API`: misma tabla, mismo flujo.
 */
class GuiaArchivoService
{
    public const DISCO = 'private';

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Bytes iniciales que se leen para comprobar la firma `%PDF-`. */
    public const VENTANA_FIRMA = 1024;

    /** Bytes iniciales que se leen para reconocer un preambulo HTTP guardado delante del PDF. */
    private const VENTANA_PREAMBULO = 16384;

    /**
     * Algunas descargas de transportadoras (p. ej. 99 Envios) guardan la RESPUESTA HTTP CRUDA: una cabecera `HTTP/1.x 200 OK`, de 1 a 30 lineas
     * `Nombre: valor` y una linea en blanco, pegadas a `%PDF-`. Solo ese preambulo, estrictamente formado, se tolera y se descarta; se valida y se
     * guarda el PDF limpio. Cualquier otro prefijo sigue rechazandose.
     */
    private const PREAMBULO_HTTP = '/\A(?:\xEF\xBB\xBF)?HTTP\/\d(?:\.\d)? 200[^\r\n]*\r?\n(?:[A-Za-z0-9-]{1,60}:[^\r\n]{0,400}\r?\n){1,30}\r?\n(?=%PDF-)/';

    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected AccesoGuiaPedido $acceso,
    ) {}

    /**
     * Validacion previa (sin tocar disco ni base).
     *
     * @return array{tamano: int, sha256: string, mime: string, nombre: ?string, offset: int}
     */
    public function inspeccionar(UploadedFile $archivo): array
    {
        if (! $archivo->isValid()) {
            // PHP lo rechazo ANTES de llegar aqui por el limite del servidor (distinto del maximo logico de 5 MB).
            if (in_array($archivo->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new Invalida(Invalida::ARCHIVO_LIMITE_SERVIDOR, ['limite' => (string) ini_get('upload_max_filesize')]);
            }

            throw new Invalida(Invalida::ARCHIVO_NO_RECIBIDO, ['error' => $archivo->getError()]);
        }

        $ruta = (string) $archivo->getRealPath();
        $tamano = (int) @filesize($ruta);

        if ($tamano <= 0) {
            throw new Invalida(Invalida::ARCHIVO_INVALIDO);
        }

        if ($tamano > self::MAX_BYTES) {
            throw new Invalida(Invalida::ARCHIVO_MUY_GRANDE, ['maximo_mb' => self::MAX_BYTES / 1024 / 1024]);
        }

        // La firma `%PDF-` debe abrir el archivo, tolerando solo lo que los lectores admiten antes de ella y que generan algunas
        // transportadoras: BOM UTF-8, espacios o saltos de linea. Cualquier otro prefijo (HTML, binarios, imagenes con un
        // `%PDF-` incrustado) se rechaza: libmagic por si solo es mas laxo y NO basta. El tipo detectado tambien es obligatorio.
        $inicio = (string) file_get_contents($ruta, false, null, 0, self::VENTANA_FIRMA);
        $offset = 0;

        if (preg_match('/\A(?:\xEF\xBB\xBF)?[\t\n\r\f ]*%PDF-/', $inicio) !== 1) {
            // Respuesta HTTP cruda delante del PDF: se descarta SOLO ese preambulo y se valida lo que queda.
            $inicio = (string) file_get_contents($ruta, false, null, 0, self::VENTANA_PREAMBULO);

            if (preg_match(self::PREAMBULO_HTTP, $inicio, $m) !== 1 || ! $this->preambuloDeclaraPdf($m[0])) {
                throw new Invalida(Invalida::ARCHIVO_NO_PDF);
            }

            $offset = strlen($m[0]);
        }

        $contenido = $offset === 0 ? null : (string) file_get_contents($ruta, false, null, $offset);
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($contenido === null ? $detector->file($ruta) : $detector->buffer($contenido));

        if ($mime !== 'application/pdf') {
            throw new Invalida(Invalida::ARCHIVO_NO_PDF);
        }

        return [
            'offset' => $offset,
            'tamano' => $contenido === null ? $tamano : strlen($contenido),
            'sha256' => $contenido === null ? hash_file('sha256', $ruta) : hash('sha256', $contenido),
            'mime' => $mime,
            'nombre' => $archivo->getClientOriginalName() === '' ? null : mb_substr(basename($archivo->getClientOriginalName()), 0, 191),
        ];
    }

    /**
     * Adjunta el primer PDF o reemplaza el vigente. Reemplazar exige motivo. Un reemplazo con el MISMO contenido no crea
     * una version nueva (idempotente ante doble clic).
     */
    public function adjuntarOReemplazar(?Admin $actor, int $guiaId, UploadedFile $archivo, ?string $motivo): GuiaArchivo
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_GUIAS_ARCHIVO)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $meta = $this->inspeccionar($archivo);
        $rutaEscrita = null;

        try {
            return DB::transaction(function () use ($actor, $guiaId, $archivo, $motivo, $meta, &$rutaEscrita) {
                $guia = Guia::query()->lockForUpdate()->find($guiaId) ?? throw new Invalida(Invalida::GUIA_INEXISTENTE);
                $vigente = $guia->archivoVigente()->first();

                if ($vigente !== null) {
                    if (hash_equals($vigente->sha256, $meta['sha256'])) {
                        return $vigente;
                    }

                    if (trim((string) $motivo) === '') {
                        throw new Invalida(Invalida::MOTIVO_REQUERIDO);
                    }
                }

                $nuevo = $this->almacenar($guia, $archivo, $meta, OrigenArchivoGuia::MANUAL, $actor, $vigente ? trim((string) $motivo) : null);
                $rutaEscrita = $nuevo->ruta;

                return $nuevo;
            });
        } catch (Throwable $e) {
            if ($rutaEscrita !== null) {
                Storage::disk(self::DISCO)->delete($rutaEscrita);
            }

            throw $e;
        }
    }

    /**
     * Escribe el archivo y crea la version. Debe llamarse DENTRO de una transaccion con la guia bloqueada. Si el
     * llamador revierte, es el responsable de borrar `$nuevo->ruta` del disco.
     *
     * @param  array{tamano: int, sha256: string, mime: string, nombre: ?string, offset?: int}|null  $meta
     */
    public function almacenar(Guia $guia, UploadedFile $archivo, ?array $meta, OrigenArchivoGuia $origen, ?Admin $actor, ?string $motivo = null): GuiaArchivo
    {
        $meta ??= $this->inspeccionar($archivo);

        $directorio = 'logistica/guias/'.now()->format('Y/m');
        $nombreDisco = Str::uuid().'.pdf';

        // Con preambulo HTTP se guarda el PDF limpio (lo ya validado), nunca los bytes originales.
        if (($meta['offset'] ?? 0) > 0) {
            $contenido = (string) file_get_contents((string) $archivo->getRealPath(), false, null, (int) $meta['offset']);
            $ruta = $contenido !== '' && Storage::disk(self::DISCO)->put($directorio.'/'.$nombreDisco, $contenido) ? $directorio.'/'.$nombreDisco : false;
        } else {
            $ruta = Storage::disk(self::DISCO)->putFileAs($directorio, $archivo, $nombreDisco);
        }

        if ($ruta === false) {
            throw new Invalida(Invalida::ARCHIVO_NO_RECIBIDO, ['motivo' => 'no_se_pudo_guardar']);
        }

        try {
            $anterior = GuiaArchivo::query()->where('guia_id', $guia->id)->where('vigente', 1)->first();
            $version = (int) GuiaArchivo::query()->where('guia_id', $guia->id)->max('version') + 1;

            // Primero se retira la vigencia de la anterior: el UNIQUE (guia, vigente) admite varias reemplazadas (NULL).
            $anterior?->forceFill(['vigente' => null, 'reemplazado_en' => now()])->save();

            return GuiaArchivo::query()->create([
                'guia_id' => $guia->id,
                'version' => $version,
                'vigente' => 1,
                'origen_archivo' => $origen,
                'disco' => self::DISCO,
                'ruta' => $ruta,
                'nombre_original' => $meta['nombre'],
                'mime' => $meta['mime'],
                'tamano_bytes' => $meta['tamano'],
                'sha256' => $meta['sha256'],
                'subido_por_admin_id' => $actor?->id,
                'subido_por_name_snapshot' => $actor ? mb_substr((string) $actor->name, 0, 191) : null,
                'motivo_reemplazo' => $anterior ? $motivo : null,
            ]);
        } catch (Throwable $e) {
            Storage::disk(self::DISCO)->delete($ruta);

            throw $e;
        }
    }

    /** Si el preambulo trae `Content-Type`, debe ser PDF; sin esa cabecera se confia en el tipo detectado del contenido. */
    private function preambuloDeclaraPdf(string $preambulo): bool
    {
        if (preg_match('/^Content-Type:\s*([^\r\n;]+)/mi', $preambulo, $t) !== 1) {
            return true;
        }

        return strtolower(trim($t[1])) === 'application/pdf';
    }

    /**
     * Respuesta con el PDF vigente (`ver` en linea o `descargar`). La autorizacion es SIEMPRE de backend: el permiso
     * `logistica.guias.ver` o, para el asesor, la propiedad del pedido (contrato AccesoGuiaPedido).
     */
    public function responder(?Admin $actor, int $guiaId, string $modo, ?int $pedidoId = null): Response
    {
        $guia = Guia::query()->find($guiaId);

        if ($guia === null || ($pedidoId !== null && (int) $guia->order_id !== $pedidoId)) {
            throw new Invalida(Invalida::GUIA_INEXISTENTE);
        }

        if (! $this->acceso->puedeVer($actor, (int) $guia->order_id)) {
            throw new Invalida(Invalida::SIN_ACCESO_GUIA);
        }

        $archivo = $guia->archivoVigente()->first();
        $disco = $archivo ? Storage::disk($archivo->disco) : null;

        if ($archivo === null || ! $disco->exists($archivo->ruta)) {
            throw new Invalida(Invalida::GUIA_SIN_ARCHIVO);
        }

        $nombre = 'guia-'.Str::slug($guia->transportadora_codigo).'-'.Str::slug($guia->numero_guia).'.pdf';

        return $disco->response($archivo->ruta, $nombre, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $modo === 'descargar' ? 'attachment' : 'inline');
    }
}
