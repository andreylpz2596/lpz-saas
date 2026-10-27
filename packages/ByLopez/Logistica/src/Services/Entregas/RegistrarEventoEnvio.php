<?php

namespace ByLopez\Logistica\Services\Entregas;

use ByLopez\Logistica\DataObjects\ResultadoRegistroLogistico;
use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\Enums\EstadoEventoLogistico as Estado;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Entrega;
use ByLopez\Logistica\Models\Retorno;
use ByLopez\Logistica\Services\Cuentas\GeneradorMovimientosCuenta;
use ByLopez\Logistica\Services\Transportadoras\TransportadorasService;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Registro idempotente de un evento sobre las UNIDADES de un envio (entrega o retorno de transportadora). Comun a
 * los dos para que las invariantes sean identicas (bloques 3, 5 y 15):
 *
 * 1. Permiso `logistica.entregas.registrar` (el asesor nunca lo tiene; el servicio valida, no la vista).
 * 2. Idempotencia: misma `idempotency_key` + misma solicitud (`huella`) = el MISMO registro (`yaExistia`); misma
 *    clave con otra solicitud = IDEMPOTENCIA_CONFLICTO. El UNIQUE de la tabla es la ultima barrera.
 * 3. Bajo `FOR UPDATE` del Shipment (serializa entregas/retornos concurrentes del mismo envio): solo unidades
 *    despachadas EN ESE envio, cantidades enteras > 0 y ≤ pendiente (qty − entregado − retornado vigentes).
 * 4. Fecha real (Y-m-d): no futura y no anterior al despacho.
 * 5. Transportadora: la del envio (`carrier_code`) si existe; si no, una ACTIVA del catalogo es obligatoria.
 *
 * No toca Bagisto: ni orders.status, ni el Shipment, ni inventario, ni Refund, ni comisiones.
 */
abstract class RegistrarEventoEnvio
{
    public const PATRON_IDEMPOTENCIA = '/^[A-Za-z0-9:_.\-]{8,100}$/';

    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected ConsultaEntregaService $consulta,
        protected TransportadorasService $transportadoras,
        protected GeneradorMovimientosCuenta $cuentas,
    ) {}

    /** 'entrega' | 'retorno' (entra en la huella: la misma clave nunca vale para los dos). */
    abstract protected function tipo(): string;

    /** @return class-string<Entrega|Retorno> */
    abstract protected function modelo(): string;

    abstract protected function validarEspecifico(SolicitudEventoEnvio $s, Fuente $fuente): void;

    /** Atributos propios del evento (fecha, motivo...). */
    abstract protected function atributos(SolicitudEventoEnvio $s, string $fecha): array;

    public function registrar(?Admin $actor, SolicitudEventoEnvio $s): ResultadoRegistroLogistico
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_REGISTRAR)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $clave = trim($s->idempotencyKey);

        if (! preg_match(self::PATRON_IDEMPOTENCIA, $clave)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_INVALIDA);
        }

        $fuente = $s->fuente instanceof Fuente ? $s->fuente : Fuente::tryFrom((string) $s->fuente);

        if ($fuente === null || ! $fuente->disponible()) {
            throw new Invalida(Invalida::FUENTE_INVALIDA);
        }

        $this->validarEspecifico($s, $fuente);
        $items = $this->itemsNormalizados($s->items);
        $fecha = $this->fechaValida($s->fecha);
        $huella = $this->huella($s, $fuente, $items, $fecha);

        if ($previo = $this->previo($clave, $huella)) {
            return $previo;
        }

        try {
            return DB::transaction(function () use ($actor, $s, $fuente, $items, $fecha, $clave, $huella) {
                $envio = DB::table('shipments')->where('id', $s->shipmentId)->lockForUpdate()->first()
                    ?? throw new Invalida(Invalida::ENVIO_INEXISTENTE);

                // Reintento concurrente que espero el bloqueo: devuelve el registro del primero.
                if ($previo = $this->previo($clave, $huella)) {
                    return $previo;
                }

                if ($s->orderId !== null && $s->orderId !== (int) $envio->order_id) {
                    throw new Invalida(Invalida::ENVIO_DE_OTRO_PEDIDO);
                }

                if ($fecha < CarbonImmutable::parse($envio->created_at)->toDateString()) {
                    throw new Invalida(Invalida::FECHA_ANTERIOR_AL_DESPACHO);
                }

                $saldos = $this->consulta->saldosDeEnvio((int) $envio->id);

                foreach ($items as $itemId => $cantidad) {
                    $saldo = $saldos[$itemId] ?? throw new Invalida(Invalida::ITEM_NO_DESPACHADO, ['shipment_item_id' => $itemId]);

                    if ($saldo['order_item_id'] === null) {
                        throw new Invalida(Invalida::ITEM_NO_DESPACHADO, ['shipment_item_id' => $itemId, 'motivo' => 'sin_order_item']);
                    }

                    if ($cantidad > $saldo['pendientes']) {
                        throw new Invalida(Invalida::CANTIDAD_EXCEDE_PENDIENTE, [
                            'shipment_item_id' => $itemId, 'pendientes' => $saldo['pendientes'], 'solicitadas' => $cantidad,
                        ]);
                    }
                }

                $registro = $this->modelo()::query()->create($this->atributos($s, $fecha) + [
                    'order_id' => (int) $envio->order_id,
                    'shipment_id' => (int) $envio->id,
                    'fuente' => $fuente,
                    'actor_admin_id' => (int) $actor->id,
                    'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
                    'carrier_code_snapshot' => $this->codigoTransportadora($envio, $s->carrierCode),
                    'carrier_title_snapshot' => $this->texto($envio->carrier_title, 191),
                    'track_number_snapshot' => $this->texto($envio->track_number, 191),
                    'evidencia_referencia' => $this->texto($s->evidenciaReferencia, 500),
                    'estado' => Estado::CONFIRMADO,
                    'idempotency_key' => $clave,
                    'huella' => $huella,
                ]);

                foreach ($items as $itemId => $cantidad) {
                    $registro->items()->create([
                        'shipment_item_id' => $itemId,
                        'order_item_id' => $saldos[$itemId]['order_item_id'],
                        'cantidad' => $cantidad,
                    ]);
                }

                // Cuenta corriente de la transportadora: el dinero nace de los HECHOS del envio, en esta misma transaccion.
                $this->cuentas->sincronizarEnvio((int) $envio->id, $actor);

                return new ResultadoRegistroLogistico($registro->load('items'), false);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Otra peticion con la misma clave gano la carrera fuera del bloqueo del envio.
            return $this->previo($clave, $huella) ?? throw $e;
        }
    }

    private function previo(string $clave, string $huella): ?ResultadoRegistroLogistico
    {
        $previo = $this->modelo()::query()->with('items')->where('idempotency_key', $clave)->first();

        if ($previo === null) {
            return null;
        }

        if (! hash_equals($previo->huella, $huella)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_CONFLICTO, ['id' => $previo->id]);
        }

        return new ResultadoRegistroLogistico($previo, true);
    }

    /** @return array<int, int> shipment_item_id => cantidad, ordenado */
    private function itemsNormalizados(array $items): array
    {
        $normalizados = [];

        foreach ($items as $itemId => $cantidad) {
            $id = filter_var($itemId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $n = filter_var($cantidad, FILTER_VALIDATE_INT);

            if ($id === false || $n === false || $n <= 0) {
                throw new Invalida(Invalida::CANTIDAD_INVALIDA, ['shipment_item_id' => $itemId]);
            }

            $normalizados[$id] = $n;
        }

        if ($normalizados === []) {
            throw new Invalida(Invalida::SIN_UNIDADES);
        }

        ksort($normalizados);

        return $normalizados;
    }

    private function fechaValida(string $fecha): string
    {
        $f = CarbonImmutable::createFromFormat('!Y-m-d', trim($fecha));

        if (! $f || $f->format('Y-m-d') !== trim($fecha) || $f->greaterThan(CarbonImmutable::today())) {
            throw new Invalida(Invalida::FECHA_INVALIDA);
        }

        return $f->format('Y-m-d');
    }

    private function codigoTransportadora(object $envio, ?string $solicitado): string
    {
        if (($envio->carrier_code ?? '') !== '') {
            return (string) $envio->carrier_code;
        }

        $codigo = trim((string) $solicitado);

        if ($codigo === '') {
            throw new Invalida(Invalida::TRANSPORTADORA_REQUERIDA);
        }

        return $this->transportadoras->activaPorCodigo($codigo)?->codigo ?? throw new Invalida(Invalida::TRANSPORTADORA_INVALIDA, ['codigo' => $codigo]);
    }

    private function huella(SolicitudEventoEnvio $s, Fuente $fuente, array $items, string $fecha): string
    {
        return hash('sha256', json_encode([
            $this->tipo(), $s->shipmentId, $s->orderId, $fecha, $items, $fuente->value,
            trim((string) $s->carrierCode), $this->texto($s->evidenciaReferencia, 500), $this->texto($s->motivo, 500),
        ]));
    }

    protected function texto(?string $valor, int $max): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : mb_substr($valor, 0, $max);
    }
}
