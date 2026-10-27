<?php

namespace ByLopez\VentasAsesor\Services\Cartera;

use ByLopez\VentasAsesor\DataObjects\ResultadoRegistroPago;
use ByLopez\VentasAsesor\DataObjects\SaldoPedido;
use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\RegistroPagoInvalidoException as Rechazo;
use ByLopez\VentasAsesor\Models\AplicacionPago;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Models\PagoEvento;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use ByLopez\VentasAsesor\Support\Cartera\MediosCobro;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Registrar un pago desde UN pedido (PAG-C, D-59 / Doc. 05 §42.5). Atomico, idempotente y auditable.
 *
 * Que hace: crea el Pago `sin_revisar`, su AplicacionPago sobre el pedido y el evento `registrado`, todo en una
 * sola transaccion. El saldo comercial baja de inmediato porque la consulta de Cartera cuenta las aplicaciones
 * `sin_revisar` (P-01).
 *
 * Que NO hace: confirmar (registrar jamas confirma, I-8, aunque el actor tenga `revisar`), rechazar, reversar,
 * crear Transactions ni tocar Invoice u Order (`orders.status` no cambia). Es dinero REPORTADO y aplicado, no
 * verificado.
 *
 * Orden de bloqueos (Doc. 05 §42.5): pago → pedido. 1) `INSERT` del pago reserva `UNIQUE(mecanismo,
 * idempotency_key)`; 2) se bloquea el pedido; 3) bajo el bloqueo se RECALCULA el saldo real con
 * CarteraConsultaService (nunca se confia en el saldo que vio el navegador) y se valida; 4) aplicacion y
 * evento. Dos registros simultaneos sobre el mismo pedido se serializan en el bloqueo: el segundo recalcula y,
 * si ya no alcanza el saldo, se rechaza (sin sobreaplicar ni saldo a favor).
 *
 * Idempotencia: misma clave + misma huella → devuelve el pago existente (`duplicado`); misma clave + huella
 * distinta → conflicto. La huella cubre cliente, medio, fecha, referencia normalizada y la distribucion
 * (`order_id:monto`). El reintento se resuelve ANTES de revalidar saldo: reenviar una solicitud ya registrada
 * no debe fallar porque el saldo ya bajo.
 *
 * Autorizacion en el servicio (no solo en la ruta ni en el boton): `ventas_asesor.cartera.registrar` MAS
 * alcance de lectura de cartera (`view_own` = pedido atribuido al actor; `view_all` = todos). Sin hardcodear
 * roles ni personas.
 *
 * Falla cerrado: sin `cartera.fecha_corte` (Cartera inactiva), pedido anterior al corte o en estado que no
 * genera saldo, la operacion se rechaza; no se salta la proteccion.
 */
class RegistrarPagoService
{
    /** Reintentos ante deadlock / lock wait timeout de la transaccion. */
    private const INTENTOS = 3;

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected CarteraConsultaService $consulta,
        protected MediosCobro $medios,
        protected FechaCorteCartera $fechaCorte,
    ) {}

    /**
     * @param  array{monto: int|float|string, medio_cobro: string, fecha_pago: string, referencia?: ?string, observacion?: ?string}  $datos
     * @param  string  $mecanismo  Valor de MecanismoCreacion (quien origina la operacion; hoy el panel de Ventas Asesor)
     *
     * @throws Rechazo
     */
    public function registrar(
        AdminContract $actor,
        int $orderId,
        array $datos,
        string $idempotencyKey,
        string $mecanismo = MecanismoCreacion::VENTAS_ASESOR->value,
    ): ResultadoRegistroPago {
        $this->exigirPermiso($actor);

        // Falla cerrado ANTES de tocar ninguna tabla de pagos: sin fecha de corte, Cartera no admite registros.
        if (! $this->fechaCorte->activa()) {
            throw new Rechazo(Rechazo::CARTERA_INACTIVA, 'Cartera todavia no esta activa (no hay fecha de corte). No se pueden registrar pagos.');
        }

        $clave = trim($idempotencyKey);

        if (! preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $clave)) {
            throw new Rechazo(Rechazo::CLAVE_IDEMPOTENCIA_INVALIDA, 'La clave de idempotencia debe tener entre 16 y 100 caracteres seguros.');
        }

        $pedido = DB::table('orders')->where('id', $orderId)->first(['id', 'customer_id']);

        // Pedido inexistente o fuera del alcance del actor: misma respuesta (no se revela si existe).
        if (! $pedido || ! $this->puedeVer($actor, $orderId)) {
            throw new Rechazo(Rechazo::PEDIDO_INEXISTENTE, 'El pedido no existe o no esta disponible para ti.');
        }

        // El pago pertenece a un cliente (FK): un pedido de invitado no puede recibirlo.
        if ($pedido->customer_id === null) {
            throw new Rechazo(Rechazo::PEDIDO_SIN_CLIENTE, 'El pedido no tiene un cliente registrado; no se puede aplicar un pago.');
        }

        $solicitud = $this->normalizar($datos);
        $solicitud['guia_id'] = $this->guiaDelPedido($datos['guia_id'] ?? null, $orderId);
        $customerId = (int) $pedido->customer_id;
        $huella = $this->huella($customerId, $orderId, $solicitud);
        if (($datos['comprobante'] ?? null) instanceof UploadedFile) {
            $huella = hash('sha256', $huella.'|'.hash_file('sha256', $datos['comprobante']->getRealPath()));
        }

        if ($existente = $this->resolverRepeticion($mecanismo, $clave, $huella)) {
            return $this->resultadoExistente($existente, $orderId);
        }

        $archivo = $datos['comprobante'] ?? null;
        $ruta = $archivo instanceof UploadedFile ? $archivo->store('ventas-asesor/pagos', 'private') : null;
        if ($archivo && ! $ruta) {
            throw new \RuntimeException('No se pudo guardar el comprobante. Intenta de nuevo.');
        }
        $guardado = false;
        try {
            $resultado = DB::transaction(
                function () use ($actor, $orderId, $customerId, $solicitud, $clave, $huella, $mecanismo, $ruta, $archivo) {
                    $resultado = $this->registrarBajoBloqueo($actor, $orderId, $customerId, $solicitud, $clave, $huella, $mecanismo);
                    if ($ruta) {
                        PagoEvento::query()->create([
                            'pago_id' => $resultado->pagoId, 'tipo' => TipoEventoPago::EVIDENCIA_AGREGADA->value,
                            'actor_admin_id' => (int) $actor->id, 'mecanismo' => $mecanismo,
                            'datos' => ['ruta' => $ruta, 'nombre' => basename($archivo->getClientOriginalName()), 'mime' => $archivo->getMimeType(), 'sha256' => hash_file('sha256', $archivo->getRealPath())],
                        ]);
                    }

                    return $resultado;
                },
                self::INTENTOS,
            );
            $guardado = true;

            return $resultado;
        } catch (QueryException $e) {
            // Carrera: otra peticion con la misma clave confirmo entre la lectura y el INSERT.
            if ($this->esClaveDuplicada($e) && ($existente = $this->resolverRepeticion($mecanismo, $clave, $huella))) {
                return $this->resultadoExistente($existente, $orderId);
            }

            throw $e;
        } finally {
            if ($ruta && ! $guardado) {
                Storage::disk('private')->delete($ruta);
            }
        }
    }

    /**
     * @param  array{monto: string, medio_cobro: string, fecha_pago: string, referencia: ?string, referencia_normalizada: ?string, observacion: ?string}  $s
     */
    protected function registrarBajoBloqueo(
        AdminContract $actor,
        int $orderId,
        ?int $customerId,
        array $s,
        string $clave,
        string $huella,
        string $mecanismo,
    ): ResultadoRegistroPago {
        // 1) El pago nace primero: reserva la clave de idempotencia y es el primer eslabon del orden de bloqueos.
        $pago = Pago::query()->create([
            'customer_id' => $customerId,
            'monto' => $s['monto'],
            'medio_cobro' => $s['medio_cobro'],
            'plataforma' => $s['plataforma'],
            'grupo_pago' => $s['grupo_pago'],
            'guia_id' => $s['guia_id'] ?? null,
            'fecha_pago' => $s['fecha_pago'],
            'referencia' => $s['referencia'],
            'referencia_normalizada' => $s['referencia_normalizada'],
            'observacion' => $s['observacion'],
            'estado' => EstadoPago::SIN_REVISAR->value,
            'mecanismo' => $mecanismo,
            'idempotency_key' => $clave,
            'huella_solicitud' => $huella,
            'registrado_por_admin_id' => (int) $actor->id,
        ]);

        // 2) Bloqueo del pedido. A partir de aqui nadie mas aplica dinero a este pedido hasta el COMMIT.
        $bloqueado = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first(['id', 'customer_id']);

        if (! $bloqueado || (int) ($bloqueado->customer_id ?? 0) !== (int) ($customerId ?? 0)) {
            throw new Rechazo(Rechazo::PEDIDO_INEXISTENTE, 'El pedido no existe o no esta disponible para ti.');
        }

        // 3) Validar con el saldo REAL, recalculado ahora y bajo el bloqueo.
        $antes = $this->consulta->saldosDePedidos([$orderId], lecturaActual: true)[$orderId] ?? null;

        $this->validarPedido($antes, $customerId);

        $monto = (float) $s['monto'];

        if ($monto > $antes->saldo + 0.000001) {
            throw new Rechazo(
                Rechazo::MONTO_EXCEDE_SALDO,
                'El monto supera el saldo pendiente del pedido. No se crean saldos a favor.',
                ['saldo' => $antes->saldo, 'monto' => $monto],
            );
        }

        // 4) Aplicacion y evento. Un Pago sin aplicacion no puede existir: si algo falla, rollback completo.
        AplicacionPago::query()->create([
            'pago_id' => $pago->id,
            'order_id' => $orderId,
            'monto' => $s['monto'],
            'posicion' => 1,
        ]);

        $despues = $this->consulta->saldosDePedidos([$orderId], lecturaActual: true)[$orderId];

        PagoEvento::query()->create([
            'pago_id' => $pago->id,
            'tipo' => TipoEventoPago::REGISTRADO->value,
            'actor_admin_id' => (int) $actor->id,
            'mecanismo' => $mecanismo,
            'motivo' => null,
            'datos' => [
                'order_id' => $orderId,
                'monto' => $monto,
                'medio_cobro' => $s['medio_cobro'],
                'plataforma' => $s['plataforma'],
                'grupo_pago' => $s['grupo_pago'],
                'guia_id' => $s['guia_id'] ?? null,
                'fecha_pago' => $s['fecha_pago'],
                'saldo_antes' => $antes->saldo,
                'saldo_despues' => $despues->saldo,
                'huella_solicitud' => $huella,
            ],
        ]);

        return new ResultadoRegistroPago((int) $pago->id, EstadoPago::SIN_REVISAR->value, $monto, false, $despues);
    }

    protected function validarPedido(?SaldoPedido $saldo, ?int $customerId): void
    {
        if ($saldo === null) {
            throw new Rechazo(Rechazo::PEDIDO_INEXISTENTE, 'El pedido no existe o no esta disponible para ti.');
        }

        if (! $saldo->enCartera) {
            throw match ($saldo->motivoFuera) {
                SaldoPedido::FUERA_CARTERA_INACTIVA => new Rechazo(
                    Rechazo::CARTERA_INACTIVA,
                    'Cartera todavia no esta activa (no hay fecha de corte). No se pueden registrar pagos.',
                ),
                default => new Rechazo(
                    Rechazo::PEDIDO_FUERA_DE_CARTERA,
                    'Este pedido no admite registrar pagos en Cartera.',
                    ['motivo' => $saldo->motivoFuera],
                ),
            };
        }

        if ($customerId === null) {
            throw new Rechazo(Rechazo::PEDIDO_SIN_CLIENTE, 'El pedido no tiene un cliente registrado; no se puede aplicar un pago.');
        }

        // P-10 y casos que Cartera no resuelve sola (Refund, sobreaplicacion, factura pagada sin evidencia...).
        if ($saldo->requiereRevision) {
            throw new Rechazo(
                Rechazo::PEDIDO_REQUIERE_REVISION,
                'El pedido requiere revision antes de aplicarle pagos.',
                ['motivos' => $saldo->motivosRevision],
            );
        }

        if ($saldo->saldo <= 0) {
            throw new Rechazo(Rechazo::MONTO_EXCEDE_SALDO, 'El pedido no tiene saldo pendiente.', ['saldo' => 0.0]);
        }
    }

    /**
     * Valida la forma y normaliza. El backend es la autoridad: la interfaz solo refleja estas reglas.
     *
     * @return array{monto: string, medio_cobro: string, fecha_pago: string, referencia: ?string, referencia_normalizada: ?string, observacion: ?string}
     */
    protected function normalizar(array $datos): array
    {
        $monto = $datos['monto'] ?? null;
        $texto = is_string($monto)
            ? trim($monto)
            : (is_int($monto) || is_float($monto) ? rtrim(rtrim(number_format((float) $monto, 4, '.', ''), '0'), '.') : '');

        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $texto) || (float) $texto <= 0) {
            throw new Rechazo(Rechazo::MONTO_INVALIDO, 'El monto debe ser mayor que cero y tener maximo dos decimales.');
        }

        $medio = trim((string) ($datos['medio_cobro'] ?? ''));

        if ($medio === '' || ! $this->medios->estaActivo($medio)) {
            throw new Rechazo(Rechazo::MEDIO_COBRO_INVALIDO, 'El medio de cobro no es valido.');
        }

        $referencia = trim((string) ($datos['referencia'] ?? ''));
        $referencia = $referencia === '' ? null : mb_substr($referencia, 0, 100);

        $archivo = $datos['comprobante'] ?? null;
        Validator::make(['comprobante' => $archivo], ['comprobante' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240']])->validate();
        if ($this->medios->exigePlataforma($medio) && $referencia === null && ! $archivo) {
            throw new Rechazo(Rechazo::REFERENCIA_REQUERIDA, 'Ingresa la referencia o adjunta el comprobante de la transferencia.');
        }
        if ($referencia === null && ! $this->medios->exigePlataforma($medio) && $this->medios->exigeReferencia($medio)) {
            throw new Rechazo(Rechazo::REFERENCIA_REQUERIDA, 'Este medio de cobro exige una referencia.');
        }

        if ($referencia !== null && ! $this->medios->admiteReferencia($medio)) {
            throw new Rechazo(Rechazo::REFERENCIA_NO_ADMITIDA, 'Este medio de cobro no admite referencia.');
        }

        $plataforma = trim((string) ($datos['plataforma'] ?? ''));
        $plataforma = $plataforma === '' ? null : $plataforma;

        if ($this->medios->exigePlataforma($medio)) {
            if ($plataforma === null) {
                throw new Rechazo(Rechazo::PLATAFORMA_REQUERIDA, 'Indica la plataforma donde entro la transferencia.');
            }

            if (! array_key_exists($plataforma, $this->medios->plataformasActivas())) {
                throw new Rechazo(Rechazo::PLATAFORMA_INVALIDA, 'La plataforma no es valida.');
            }
        } elseif ($plataforma !== null) {
            throw new Rechazo(Rechazo::PLATAFORMA_INVALIDA, 'Este medio de cobro no usa plataforma.');
        }

        $fecha = $this->fechaValida((string) ($datos['fecha_pago'] ?? ''));

        $observacion = trim((string) ($datos['observacion'] ?? ''));

        return [
            'monto' => number_format((float) $texto, 2, '.', ''),
            'medio_cobro' => $medio,
            'plataforma' => $plataforma,
            'grupo_pago' => isset($datos['grupo_pago']) ? (string) $datos['grupo_pago'] : null,
            'fecha_pago' => $fecha,
            'referencia' => $referencia,
            'referencia_normalizada' => MediosCobro::normalizarReferencia($referencia),
            'observacion' => $observacion === '' ? null : mb_substr($observacion, 0, 1000),
        ];
    }

    protected function fechaValida(string $valor): string
    {
        try {
            $fecha = CarbonImmutable::createFromFormat('!Y-m-d', trim($valor));
        } catch (Throwable) {
            $fecha = false;
        }

        if (! $fecha || $fecha->format('Y-m-d') !== trim($valor) || $fecha->isAfter(CarbonImmutable::today())) {
            throw new Rechazo(Rechazo::FECHA_PAGO_INVALIDA, 'La fecha del pago debe ser valida y no puede ser futura.');
        }

        return $fecha->format('Y-m-d');
    }

    /**
     * @param  array{monto: string, medio_cobro: string, fecha_pago: string, referencia_normalizada: ?string}  $s
     */
    protected function huella(?int $customerId, int $orderId, array $s): string
    {
        return hash('sha256', json_encode([
            'cliente' => $customerId,
            'medio' => $s['medio_cobro'],
            'plataforma' => $s['plataforma'],
            'fecha' => $s['fecha_pago'],
            'referencia' => $s['referencia_normalizada'],
            'distribucion' => [$orderId.':'.$s['monto']],
        ] + (empty($s['guia_id']) ? [] : ['guia' => (int) $s['guia_id']]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * C6: guia a la que pertenece un cobro hecho por el transportador. Debe ser una guia de ESTE pedido; sin guia (transferencia,
     * efectivo directo) queda NULL. El envio se deriva de la guia (1:1).
     */
    protected function guiaDelPedido(mixed $guiaId, int $orderId): ?int
    {
        if ($guiaId === null || $guiaId === '') {
            return null;
        }

        $id = (int) $guiaId;

        if ($id <= 0 || ! DB::table('bylopez_lg_guias')->where('id', $id)->where('order_id', $orderId)->exists()) {
            throw new Rechazo(Rechazo::DISTRIBUCION_INVALIDA, 'La guia no pertenece a este pedido.');
        }

        return $id;
    }

    /** Pago ya registrado con esa clave: mismo contenido → devolverlo; contenido distinto → conflicto. */
    protected function resolverRepeticion(string $mecanismo, string $clave, string $huella): ?Pago
    {
        $existente = Pago::query()->where('mecanismo', $mecanismo)->where('idempotency_key', $clave)->first();

        if ($existente === null) {
            return null;
        }

        if (! hash_equals((string) $existente->huella_solicitud, $huella)) {
            throw new Rechazo(
                Rechazo::IDEMPOTENCIA_EN_CONFLICTO,
                'Esa clave de idempotencia ya se uso con una solicitud distinta. Recarga la pantalla e intenta de nuevo.',
            );
        }

        return $existente;
    }

    protected function resultadoExistente(Pago $pago, int $orderId): ResultadoRegistroPago
    {
        $saldo = $this->consulta->saldosDePedidos([$orderId])[$orderId];

        return new ResultadoRegistroPago((int) $pago->id, $pago->estado->value, (float) $pago->monto, true, $saldo);
    }

    /**
     * Pago COMBINADO: varias transferencias (cada una con su plataforma, monto y referencia) que juntas pagan un
     * pedido. Cada parte es un Pago independiente (se confirma o rechaza por separado) y comparten `grupo_pago`.
     * Todo en UNA transaccion: si una parte falla (p. ej. la suma supera el saldo) no queda ninguna.
     *
     * Las claves de idempotencia por parte se derivan de la clave del intento, asi que reenviarlo no duplica nada.
     *
     * @param  array<int, array{monto: int|float|string, plataforma: string, referencia?: ?string}>  $partes
     * @param  array{fecha_pago: string, observacion?: ?string}  $comunes
     * @return array{grupo: string, pagos: list<ResultadoRegistroPago>}
     *
     * @throws Rechazo
     */
    public function registrarCombinado(
        AdminContract $actor,
        int $orderId,
        array $partes,
        array $comunes,
        string $idempotencyKey,
        string $mecanismo = MecanismoCreacion::VENTAS_ASESOR->value,
    ): array {
        $clave = trim($idempotencyKey);

        if (! preg_match('/^[A-Za-z0-9._:-]{16,90}$/', $clave)) {
            throw new Rechazo(Rechazo::CLAVE_IDEMPOTENCIA_INVALIDA, 'La clave de idempotencia debe tener entre 16 y 90 caracteres seguros.');
        }

        if (count($partes) < 2) {
            throw new Rechazo(Rechazo::PAGO_COMBINADO_INVALIDO, 'Un pago combinado necesita al menos dos partes.');
        }

        if (count($partes) > 6) {
            throw new Rechazo(Rechazo::PAGO_COMBINADO_INVALIDO, 'Un pago combinado admite como maximo seis partes.');
        }

        $grupo = 'cmb-'.substr(hash('sha256', $mecanismo.'|'.$clave), 0, 32);

        $medio = $this->medios->medioParaCombinado()
            ?? throw new Rechazo(Rechazo::PAGO_COMBINADO_INVALIDO, 'No hay un medio de cobro con plataforma activo para el pago combinado.');

        $archivosNuevos = [];
        try {
            return DB::transaction(function () use ($actor, $orderId, $partes, $comunes, $clave, $mecanismo, $grupo, $medio, &$archivosNuevos) {
                $resultados = [];

                foreach (array_values($partes) as $i => $parte) {
                    $resultados[] = $this->registrar($actor, $orderId, [
                        'monto' => $parte['monto'] ?? null,
                        'medio_cobro' => $medio,
                        'plataforma' => $parte['plataforma'] ?? null,
                        'referencia' => $parte['referencia'] ?? null,
                        'comprobante' => $parte['comprobante'] ?? null,
                        'fecha_pago' => $comunes['fecha_pago'] ?? '',
                        'observacion' => $comunes['observacion'] ?? null,
                        'grupo_pago' => $grupo,
                    ], $clave.'-p'.($i + 1), $mecanismo);
                    $ultimo = end($resultados);
                    if (! $ultimo->duplicado && ($parte['comprobante'] ?? null)) {
                        $evento = PagoEvento::query()->where('pago_id', $ultimo->pagoId)->where('tipo', TipoEventoPago::EVIDENCIA_AGREGADA->value)->firstOrFail();
                        $archivosNuevos[] = $evento->datos['ruta'];
                    }
                }

                return ['grupo' => $grupo, 'pagos' => $resultados];
            });
        } catch (Throwable $e) {
            Storage::disk('private')->delete($archivosNuevos);
            throw $e;
        }
    }

    protected function exigirPermiso(AdminContract $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_CARTERA_REGISTRAR)) {
            throw new Rechazo(Rechazo::SIN_PERMISO, 'No tienes permiso para registrar pagos.');
        }
    }

    /** Alcance de lectura de cartera sobre el pedido (view_all = todo; view_own = atribuido al actor). */
    protected function puedeVer(AdminContract $actor, int $orderId): bool
    {
        $asesorId = DB::table('bylopez_va_order_attributions')->where('order_id', $orderId)->value('advisor_id');

        return $this->autorizacion->puedeVerCarteraDe($actor, $asesorId === null ? null : (int) $asesorId);
    }

    protected function esClaveDuplicada(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
