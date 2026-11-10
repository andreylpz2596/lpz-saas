<?php

namespace ByLopez\Cargues\Extensiones\EnviosCompletos;

use ByLopez\Cargues\Contracts\ExtensionTipo;
use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Extensiones\Concerns\AplicaPorFila;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\Enums\EstadoEventoLogistico;
use ByLopez\Logistica\Enums\FuenteEventoLogistico;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Entrega;
use ByLopez\Logistica\Models\Retorno;
use ByLopez\Logistica\Models\ValidacionEntrega;
use ByLopez\Logistica\Services\Entregas\ConsultaEntregaService;
use ByLopez\Logistica\Services\Entregas\RegistrarEntregaService;
use ByLopez\Logistica\Services\Entregas\RegistrarRetornoTransportadoraService;
use ByLopez\Logistica\Services\Entregas\ValidarEntregaService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\Admin;

/**
 * Extension del tipo «99 Envios — Envios completos» (version 3, docs/16 §15). El procedimiento escribe el ESTADO REPORTADO y deja
 * PLANEADAS las filas «Entregada» / «Devolucion ratificada» que son el reporte vigente de su guia; aqui se registra el HITO
 * logistico con los servicios de Logistica, sin saltarse ninguna de sus reglas (permiso, unidades pendientes bajo bloqueo del
 * envio, fecha no anterior al despacho ni futura, idempotencia, cuenta corriente, validacion completa):
 *
 *  - Entregada → entrega REGISTRADA de las unidades pendientes de ESE envio + entrega VALIDADA. Si la validacion no procede, la
 *    entrega queda registrada y la fila lo dice (VALIDACION_PENDIENTE): al repetir el archivo se completa SOLO la validacion.
 *  - Devolucion ratificada → retorno de transportadora de las unidades pendientes, PENDIENTE de recepcion fisica (sin stock).
 *  - Contradiccion (Entregada con devolucion vigente, o Devolucion ratificada con entrega vigente) o un hito automatico ANULADO
 *    a mano → incidencia, sin revertir ni volver a registrar nada.
 *
 * Idempotencia: claves FIJAS por envio (`99envios:entrega:<envio>`, `99envios:validacion:<envio>`, `99envios:retorno:<envio>`) que se
 * consultan ANTES de registrar: repetir el archivo, re-ejecutar o recuperar la carga nunca duplica un hito. El actor es el
 * administrador que creo la carga (Cargues es solo de administradores). No crea pagos ni movimientos de Cartera ni habilita
 * comisiones: validar dispara la reevaluacion existente, que sigue exigiendo cobro confirmado y dinero recibido.
 */
class EnviosCompletosHitosExtension implements ExtensionTipo
{
    use AplicaPorFila;

    public const PROVEEDOR = '99envios';

    public const ENTREGADA = 'Entregada';

    public const DEVOLUCION_RATIFICADA = 'Devolución ratificada';

    public const PLANES = ['insertada', 'actualizada', 'ya_registrado'];

    public function __construct(
        protected ConexionTipo $conexiones,
        protected ConsultaEntregaService $consulta,
        protected RegistrarEntregaService $entregas,
        protected ValidarEntregaService $validaciones,
        protected RegistrarRetornoTransportadoraService $retornos,
    ) {}

    public static function claveEntrega(int $envio): string
    {
        return self::PROVEEDOR.':entrega:'.$envio;
    }

    public static function claveValidacion(int $envio): string
    {
        return self::PROVEEDOR.':validacion:'.$envio;
    }

    public static function claveRetorno(int $envio): string
    {
        return self::PROVEEDOR.':retorno:'.$envio;
    }

    /** Sin columnas derivadas: el plan lo hace el procedimiento y todo se re-comprueba al aplicar. */
    public function derivar(Carga $carga, array $filas): array
    {
        return [];
    }

    public function aplicar(Carga $carga, array $map, Closure $progreso): void
    {
        $cfg = (array) $carga->config_congelada;
        $tabla = (string) $map['tabla'];
        $this->exigirMismaBase((string) $cfg['conexion']);
        $actor = $this->actor($carga);

        $total = (int) DB::table($tabla)->where('cg_carga_id', $carga->id)->whereNull('cg_estado')->count();
        $hechas = 0;
        $progreso(0, $total);

        while (true) {
            $filas = DB::table($tabla)->where('cg_carga_id', $carga->id)->whereNull('cg_estado')->orderBy('cg_fila_origen')->limit(100)->get();

            if ($filas->isEmpty()) {
                break;
            }

            foreach ($filas as $f) {
                // Un error INESPERADO (no una regla de negocio) revierte la fila y sube: la carga queda «fallida_ejecucion» con las filas
                // ya aplicadas marcadas, y «Ejecutar de nuevo» procesa solo las pendientes (sin duplicar: claves fijas por envio).
                DB::transaction(fn () => $this->aplicarFila($carga, $tabla, $f, $actor));
                $progreso(++$hechas, $total);
            }
        }
    }

    protected function aplicarFila(Carga $carga, string $tabla, object $f, Admin $actor): void
    {
        $obs = $f->cg_codigo ? [[(string) $f->cg_codigo, (string) $f->cg_mensaje]] : [];   // observacion del procedimiento (recaudo)

        if (! in_array($f->cg_detalle, self::PLANES, true)) {
            $this->marcar($tabla, (int) $f->cg_id, 'rechazada', null, 'PLAN_DESCONOCIDO', 'La fila llegó sin un plan reconocido del procedimiento; no se aplicó nada.');

            return;
        }

        $guia = $this->guia($f);

        if ($guia === null) {
            $this->marcar($tabla, (int) $f->cg_id, 'rechazada', null, 'GUIA_NO_REGISTRADA', 'La guía ya no se encuentra con esa transportadora en Logística; no se registró ningún hito.');

            return;
        }

        // El reporte debe seguir siendo el VIGENTE de la guia (otra carga pudo aplicar uno posterior entre el plan y este paso).
        $actual = DB::table('bylopez_lg_guia_estados_proveedor')->where('guia_id', $guia->id)->where('proveedor', self::PROVEEDOR)->lockForUpdate()->first();

        if (! $actual || $actual->fecha_estado_proveedor !== (string) $f->fecha_actualizacion || ! hash_equals((string) $actual->estado_proveedor, (string) $f->estado_del_envio)) {
            $this->marcar($tabla, (int) $f->cg_id, 'omitida_antigua', 'reporte_superado', null, 'Otro reporte posterior de esta guía ya está registrado'.($actual ? ' («'.$actual->estado_proveedor.'» del '.$actual->fecha_estado_proveedor.')' : '').'; este no genera hitos.');

            return;
        }

        // ¿El estado reportado lo aplico ESTA fila (ahora o en una ejecucion anterior de la misma carga que se interrumpio)?
        $estadoAplicado = $f->cg_detalle !== 'ya_registrado' || ((int) $actual->carga_id === (int) $carga->id && (int) $actual->fila_origen === (int) $f->cg_fila_origen);

        [$detalle, $cambio, $hitoObs] = match (true) {
            ! $guia->shipment_id => ['sin_envio', false, [['HITO_SIN_ENVIO', 'La guía no está ligada a un envío: no hay unidades sobre las que registrar el hito.']]],
            in_array($guia->estado_pedido, ['canceled', 'closed', 'fraud'], true) => ['pedido_no_vigente', false, [['PEDIDO_NO_VIGENTE', 'El pedido está «'.$guia->estado_pedido.'»: no se registra ningún hito logístico; revíselo manualmente.']]],
            hash_equals(self::ENTREGADA, (string) $f->estado_del_envio) => $this->entregada($carga, $f, (int) $guia->shipment_id, (int) $guia->order_id, $actor),
            hash_equals(self::DEVOLUCION_RATIFICADA, (string) $f->estado_del_envio) => $this->devolucion($carga, $f, (int) $guia->shipment_id, (int) $guia->order_id, $actor),
            default => ['sin_hito', false, []],
        };

        $obs = array_merge($obs, $hitoObs);
        // Varias observaciones en una fila: codigos separados por coma (maximo 60) y mensajes unidos (mismo formato que Clientes historicos).
        $codigo = $obs === [] ? null : mb_substr(implode(',', array_column($obs, 0)), 0, 60);
        $mensaje = $obs === [] ? null : implode(' | ', array_column($obs, 1));

        $this->marcar($tabla, (int) $f->cg_id, $estadoAplicado || $cambio ? 'aplicada' : 'sin_cambio', $detalle, $codigo, $mensaje);
    }

    /**
     * Entregada → entrega registrada (unidades pendientes) + validada.
     *
     * @return array{0: string, 1: bool, 2: list<array{0: string, 1: string}>} [detalle, hubo cambio, observaciones]
     */
    protected function entregada(Carga $carga, object $f, int $envio, int $pedido, Admin $actor): array
    {
        $propia = Entrega::query()->where('idempotency_key', self::claveEntrega($envio))->first();

        if ($propia && ! $propia->vigente()) {
            return ['entrega_anulada_en_logistica', false, [['HITO_ANULADO_EN_LOGISTICA', 'La entrega automática de este envío fue anulada en Logística: no se vuelve a registrar ni a validar. Revísela manualmente.']]];
        }

        if (Retorno::query()->where('shipment_id', $envio)->where('estado', EstadoEventoLogistico::CONFIRMADO->value)->exists()) {
            return ['contradiccion', false, [['CONTRADICCION_PROVEEDOR', '99 Envíos reporta «Entregada», pero el envío tiene una devolución registrada en Logística. No se registra ni se revierte nada: revise el caso.']]];
        }

        $creada = false;
        $pendientes = array_filter(array_map(fn ($s) => $s['pendientes'], $this->consulta->saldosDeEnvio($envio)));

        if (! $propia && $pendientes !== []) {
            try {
                $this->entregas->registrar($actor, new SolicitudEventoEnvio(
                    shipmentId: $envio, orderId: $pedido, fecha: $this->fecha($f), items: $pendientes,
                    fuente: FuenteEventoLogistico::REPORTE_PROVEEDOR, idempotencyKey: self::claveEntrega($envio), evidenciaReferencia: $this->evidencia($carga, $f),
                ));
                $creada = true;
            } catch (Invalida $e) {
                return ['entrega_no_registrada', false, [['ENTREGA_NO_REGISTRADA', 'No se registró la entrega: '.$this->motivo($e).' No se validó nada.']]];
            }
        }

        if (ValidacionEntrega::query()->where('shipment_id', $envio)->where('estado', EstadoEventoLogistico::CONFIRMADO->value)->exists()) {
            return [$creada ? 'entrega_registrada' : 'hito_ya_registrado', $creada, []];
        }

        if (($v = ValidacionEntrega::query()->where('idempotency_key', self::claveValidacion($envio))->first()) && ! $v->vigente()) {
            return [$creada ? 'entrega_registrada' : 'validacion_anulada_en_logistica', $creada, [['HITO_ANULADO_EN_LOGISTICA', 'La validación automática de este envío fue anulada en Logística: no se vuelve a validar. Revísela manualmente.']]];
        }

        try {
            $this->validaciones->validar($actor, $envio, self::claveValidacion($envio), 'Validada automáticamente: '.$this->evidencia($carga, $f));
        } catch (Invalida $e) {
            return [$creada ? 'entrega_registrada_validacion_pendiente' : 'validacion_pendiente', $creada, [[
                'VALIDACION_PENDIENTE',
                ($creada ? 'La entrega quedó registrada, pero' : 'La entrega ya estaba registrada, pero').' la validación no se pudo completar: '.$this->motivo($e)
                    .' Cuando se resuelva, vuelva a cargar el archivo (solo se completará la validación) o valídela en Logística › Entregas.',
            ]]];
        }

        return [$creada ? 'entrega_registrada_y_validada' : 'validacion_completada', true, []];
    }

    /**
     * Devolucion ratificada → retorno de transportadora de las unidades pendientes. Sin recepcion ni reingreso de stock.
     *
     * @return array{0: string, 1: bool, 2: list<array{0: string, 1: string}>}
     */
    protected function devolucion(Carga $carga, object $f, int $envio, int $pedido, Admin $actor): array
    {
        $propio = Retorno::query()->where('idempotency_key', self::claveRetorno($envio))->first();

        if ($propio && ! $propio->vigente()) {
            return ['retorno_anulado_en_logistica', false, [['HITO_ANULADO_EN_LOGISTICA', 'La devolución automática de este envío fue anulada en Logística: no se vuelve a registrar. Revísela manualmente.']]];
        }

        if (Entrega::query()->where('shipment_id', $envio)->where('estado', EstadoEventoLogistico::CONFIRMADO->value)->exists()) {
            return ['contradiccion', false, [['CONTRADICCION_PROVEEDOR', '99 Envíos reporta «Devolución ratificada», pero el envío tiene una entrega registrada en Logística. No se registra ni se revierte nada: revise el caso.']]];
        }

        $pendientes = array_filter(array_map(fn ($s) => $s['pendientes'], $this->consulta->saldosDeEnvio($envio)));

        if ($propio || $pendientes === []) {
            return ['hito_ya_registrado', false, []];
        }

        try {
            $this->retornos->registrar($actor, new SolicitudEventoEnvio(
                shipmentId: $envio, orderId: $pedido, fecha: $this->fecha($f), items: $pendientes,
                fuente: FuenteEventoLogistico::REPORTE_PROVEEDOR, idempotencyKey: self::claveRetorno($envio), evidenciaReferencia: $this->evidencia($carga, $f),
                motivo: 'Devolución ratificada (99 Envíos)',
            ));
        } catch (Invalida $e) {
            return ['retorno_no_registrado', false, [['RETORNO_NO_REGISTRADO', 'No se registró la devolución: '.$this->motivo($e)]]];
        }

        return ['retorno_registrado_pendiente_recepcion', true, []];
    }

    // ---------------------------------------------------------------------------------------------------------------------

    /** La guia por transportadora EFECTIVA + numero, con la misma equivalencia e igualdad exacta que el procedimiento. */
    protected function guia(object $f): ?object
    {
        $efectivas = DB::table('bylopez_lg_proveedor_transportadoras')->where('proveedor', self::PROVEEDOR)->where('activo', 1)->get(['codigo_externo', 'nombre_externo', 'transportadora_codigo'])
            ->filter(fn ($e) => strcmp((string) $e->codigo_externo, (string) $f->transportadora) === 0 || strcmp((string) $e->nombre_externo, (string) $f->transportadora) === 0);

        if ($efectivas->count() !== 1) {
            return null;
        }

        $guias = DB::table('bylopez_lg_guias as g')->leftJoin('orders as o', 'o.id', '=', 'g.order_id')
            ->where('g.transportadora_codigo', $efectivas->first()->transportadora_codigo)->whereRaw('BINARY g.numero_guia = ?', [(string) $f->numero_de_guia])
            ->get(['g.id', 'g.order_id', 'g.shipment_id', 'o.status as estado_pedido']);

        return $guias->count() === 1 ? $guias->first() : null;
    }

    /** Fecha del hito = fecha del ESTADO que reporta el proveedor (sin hora). Logistica exige que no sea futura ni anterior al despacho. */
    protected function fecha(object $f): string
    {
        return CarbonImmutable::parse((string) $f->fecha_actualizacion)->toDateString();
    }

    protected function evidencia(Carga $carga, object $f): string
    {
        return mb_substr('99 Envíos «'.$f->estado_del_envio.'» del '.$f->fecha_actualizacion.' · cargue #'.$carga->id.', fila '.$f->cg_fila_origen.' · guía '.$f->numero_de_guia, 0, 500);
    }

    protected function motivo(Invalida $e): string
    {
        $clave = 'logistica::app.errores.'.$e->codigo;
        $texto = trans($clave, $e->detalles + ['pendientes' => '', 'solicitadas' => '', 'codigo' => '', 'maximo' => '', 'envio' => '', 'id' => '']);

        return ($texto === $clave ? $e->getMessage() : $texto).' ('.$e->codigo.')';
    }

    /** Quien registra los hitos: el administrador que creo la carga (Cargues es exclusivo del administrador). */
    protected function actor(Carga $carga): Admin
    {
        $admin = Admin::query()->find((int) $carga->admin_id);

        if (! $admin || (int) $admin->role_id !== (int) config('cargues.rol_administrador_id') || ! $admin->status) {
            throw new OperacionInvalidaException('ACTOR_NO_ADMINISTRADOR', 'Los hitos logísticos de este cargue los registra el administrador que creó la carga, y esa cuenta ya no es un administrador activo. No se aplicó nada.');
        }

        return $admin;
    }
}
