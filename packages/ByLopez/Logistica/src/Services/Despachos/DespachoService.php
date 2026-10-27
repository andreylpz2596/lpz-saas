<?php

namespace ByLopez\Logistica\Services\Despachos;

use ByLopez\Logistica\DataObjects\ResultadoDespacho;
use ByLopez\Logistica\DataObjects\SolicitudDespacho;
use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\DataObjects\SolicitudRecogida;
use ByLopez\Logistica\Enums\EstadoRecaudo;
use ByLopez\Logistica\Enums\FuenteEventoLogistico;
use ByLopez\Logistica\Enums\OrigenArchivoGuia;
use ByLopez\Logistica\Enums\OrigenGuia;
use ByLopez\Logistica\Enums\PagadorFlete;
use ByLopez\Logistica\Enums\TipoDisposicionCobro;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Entrega;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Services\Entregas\RegistrarEntregaService;
use ByLopez\Logistica\Services\Guias\GuiaArchivoService;
use ByLopez\Logistica\Services\Transportadoras\TransportadorasService;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use ByLopez\Logistica\Support\ConsecutivoGuiaInterna;
use ByLopez\Logistica\Support\FuentePanel;
use ByLopez\Logistica\Support\PuntosRecogida;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Sales\Repositories\ShipmentRepository;
use Webkul\User\Contracts\Admin;

/**
 * DESPACHO V1. Un solo servicio para el panel de Despachos (BODEGA) y para el listado de Pedidos (Administrador): el
 * mismo componente llama a las mismas dos operaciones.
 *
 *  - despachar(): «Registrar guia y despachar». Crea el Shipment REAL de Bagisto (que descuenta inventario, mueve el
 *    estado del pedido y dispara sus eventos), la guia (dato) y, si viene, el PDF (documento), en UNA transaccion.
 *  - entregarEnBodega(): para pedidos de recogida. Sin transportadora, guia ni PDF. Crea el Shipment real (la salida de
 *    inventario) y la entrega con fuente `recogida_bodega`, sin inventar un envio ficticio.
 *
 * Reglas comunes:
 *  - El permiso se valida aqui (no basta con ocultar botones).
 *  - Idempotencia: la misma `idempotency_key` con la misma solicitud devuelve el mismo resultado; con otra solicitud,
 *    conflicto. El pedido se bloquea (FOR UPDATE): dos operarias sobre el mismo pedido se serializan y la segunda ve
 *    lo que ya se despacho.
 *  - Cantidades e inventario: las mismas reglas del despacho nativo (UnidadesDespachables).
 *  - El recaudo es lo que la transportadora DEBE cobrar; NO es un pago recibido y no toca Cartera.
 *  - No hay llamadas a APIs externas: la guia manual es el mismo registro que completara una API despues.
 */
class DespachoService
{
    public const PATRON_IDEMPOTENCIA = '/^[A-Za-z0-9:_.\-]{8,90}$/';

    public const PATRON_GUIA = '/^[\pL\pN][\pL\pN \-_.\/]{0,99}$/u';

    public const MAX_MONTO = 99999999.99;

    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected UnidadesDespachables $unidades,
        protected TransportadorasService $transportadoras,
        protected GuiaArchivoService $archivos,
        protected RegistrarEntregaService $entregas,
        protected ShipmentRepository $envios,
        protected CondicionDespacho $condicion,
        protected ConsecutivoGuiaInterna $consecutivos,
        protected RecaudoPorGuia $recaudos,
        protected DisposicionCobroService $disposiciones,
    ) {}

    public function despachar(?Admin $actor, SolicitudDespacho $s): ResultadoDespacho
    {
        $this->exigir($actor, AutorizacionLogistica::PERMISO_DESPACHAR);
        $clave = $this->clave($s->idempotencyKey);

        $transportadora = $this->transportadoraValida($s->transportadora);
        // El MODO de guia lo decide la transportadora (capacidad `genera_guia_interna`), nunca el cliente ni su nombre.
        $interna = (bool) $transportadora->genera_guia_interna;

        if ($interna) {
            // Guia interna: el numero GI se asigna en el servidor dentro de la transaccion; lo que mande el cliente
            // (numero, origen, tracking, PDF) se ignora.
            $numero = '';
            $origen = OrigenGuia::INTERNA;
        } else {
            $numero = $this->numeroGuia($s->numeroGuia);
            $origen = OrigenGuia::tryFrom(trim($s->origenGuia));

            if ($origen === null || $origen === OrigenGuia::INTERNA) {
                throw new Invalida(Invalida::ORIGEN_GUIA_INVALIDO);
            }

            if (ConsecutivoGuiaInterna::esReservado($numero)) {
                throw new Invalida(Invalida::GUIA_RESERVADA, ['numero_guia' => $numero]);
            }
        }
        $pagadorPedido = trim($s->fletePagador);

        if ($pagadorPedido !== '' && PagadorFlete::tryFrom($pagadorPedido) === null) {
            throw new Invalida(Invalida::PAGADOR_FLETE_INVALIDO);
        }

        $flete = $this->monto($s->valorFlete, Invalida::FLETE_INVALIDO);
        // C1: sin valor escrito (null) el servidor aplica el recaudo que el mismo calcula para ESTA guia; un valor escrito que se
        // aparte de el exige motivo. Para lo que no depende de las unidades (pago pendiente, tope del pedido) null cuenta como 0.
        $recaudoEscrito = $this->monto($s->recaudoEsperado, Invalida::RECAUDO_INVALIDO);
        $recaudo = $recaudoEscrito ?? 0.0;
        $motivoRecaudo = $this->texto($s->motivoRecaudo, 500);
        $motivoPagador = $this->texto($s->motivoPagador, 500);
        $motivoCosto = $this->texto($s->motivoCosto, 500);

        $tracking = $interna ? null : $this->texto($s->tracking, 500);
        $observacion = $this->texto($s->observacion, 500);
        $meta = (! $interna && $s->archivo) ? $this->archivos->inspeccionar($s->archivo) : null;

        $huella = hash('sha256', json_encode([
            'despacho', $s->orderId, $transportadora->codigo, $numero, $origen->value, $pagadorPedido, $flete, $recaudoEscrito, $motivoRecaudo,
            $tracking, $observacion, $meta['sha256'] ?? null, $this->ordenado($s->unidades),
            $s->zonaId, $motivoPagador, $motivoCosto,
        ]));

        if ($previo = $this->guiaPrevia($clave, $huella)) {
            return $previo;
        }

        $rutaEscrita = null;

        try {
            return DB::transaction(function () use ($actor, $s, $clave, $huella, $transportadora, $interna, $numero, $origen, $pagadorPedido, $motivoPagador, $motivoCosto, $flete, $recaudo, $recaudoEscrito, $motivoRecaudo, $tracking, $observacion, $meta, &$rutaEscrita) {
                $pedido = $this->pedidoBloqueado($s->orderId);

                if ($previo = $this->guiaPrevia($clave, $huella)) {
                    return $previo;
                }

                if ($pedido->shipping_method === PuntosRecogida::METODO) {
                    throw new Invalida(Invalida::ES_RECOGIDA);
                }

                $this->exigirDespachable($pedido);

                // F6: un pedido que NO es contra entrega con dinero sin confirmar no sale con «no cobrar nada» sin una decision
                // vigente (cobrar al entregar o credito autorizado). Se revalida aqui, bajo el bloqueo del pedido.
                $disposicion = $this->disposiciones->exigirResuelta((int) $pedido->id);
                $cobrarAlEntregar = $disposicion?->tipo === TipoDisposicionCobro::COBRAR_AL_ENTREGAR;

                // F1: el saldo pendiente no bloquea; un pago EN VALIDACION solo si quien entrega cobra dinero del pedido
                // (contra entrega, cobrar al entregar o recaudo escrito): el valor a cobrar es incierto hasta que Cartera decida.
                $this->condicion->exigirSinPagoPendiente((int) $pedido->id, $recaudo > 0 || $cobrarAlEntregar || $this->condicion->cobraAlEntregar((int) $pedido->id));

                // F1: condicion de envio → pagador (motivo si se aparta), zona, tarifa sugerida y costo final (snapshot).
                $c = $this->condicion->resolver($pedido, $transportadora, $pagadorPedido, $motivoPagador, $s->zonaId, $flete, $motivoCosto);
                $pagador = $c['pagador'];
                $flete = $c['valor_flete'];

                if ($pagador->exigeValor() && ($flete === null || $flete <= 0)) {
                    throw new Invalida(Invalida::FLETE_REQUERIDO);
                }

                if (! $interna && Guia::query()->where('transportadora_codigo', $transportadora->codigo)->where('numero_guia', $numero)->exists()) {
                    throw new Invalida(Invalida::GUIA_DUPLICADA, ['numero_guia' => $numero]);
                }

                $maximo = (float) $pedido->base_grand_total + ($flete ?? 0.0);

                if ($recaudo > $maximo + 0.001) {
                    throw new Invalida(Invalida::RECAUDO_INVALIDO, ['maximo' => $maximo]);
                }

                $unidades = $this->unidades->normalizar($pedido, $s->unidades);

                // C1: recaudo de ESTA guia (mercancia historica de sus unidades + flete una sola vez, acotado a lo que aun no comprometen
                // las otras guias). La suma de las guias NUNCA supera el saldo exigible; apartarse del sugerido exige motivo.
                $calculo = $this->recaudos->calcular($pedido, $unidades, $cobrarAlEntregar);
                $recaudo = $recaudoEscrito ?? $calculo['sugerido'];

                if ($recaudo > $calculo['disponible'] + 0.001) {
                    throw new Invalida(Invalida::RECAUDO_SUPERA_SALDO, ['maximo' => $calculo['disponible'], 'exigible' => $calculo['exigible'], 'comprometido' => $calculo['comprometido']]);
                }

                $modificado = abs($recaudo - $calculo['sugerido']) > 0.01;

                if ($modificado && $motivoRecaudo === null) {
                    throw new Invalida(Invalida::MOTIVO_RECAUDO_REQUERIDO, ['sugerido' => $calculo['sugerido']]);
                }

                $bodega = $this->unidades->elegirBodega($pedido, $unidades);
                // Guia interna: el GI se toma aqui (bloqueo del contador dentro de ESTA transaccion) y viaja al Shipment nativo como
                // track_number desde su creacion; un reintento con la misma clave devuelve la guia ya creada (guiaPrevia).
                $consecutivo = null;

                if ($interna) {
                    [$consecutivo, $numero] = $this->consecutivos->siguiente();
                }

                $envio = $this->crearEnvio($pedido, $bodega, $unidades, (string) $transportadora->nombre, $numero, (string) $transportadora->codigo);

                $guia = Guia::query()->create([
                    'order_id' => (int) $pedido->id,
                    'shipment_id' => (int) $envio->id,
                    'transportadora_codigo' => $transportadora->codigo,
                    'numero_guia' => $numero,
                    'consecutivo_interno' => $consecutivo,
                    'tracking' => $tracking,
                    'origen_guia' => $origen,
                    'generada_por' => $interna ? 'sistema' : 'operaria',
                    'transportadora_tipo' => $transportadora->tipo?->value,
                    'liquida_cuenta_corriente' => (bool) $transportadora->liquida_cuenta_corriente,
                    'valor_flete' => $flete,
                    'flete_pagador' => $pagador,
                    'condicion_envio' => $c['condicion']->value,
                    'pagador_deducido' => $c['pagador_deducido'],
                    'motivo_pagador' => $c['motivo_pagador'],
                    'zona_id' => $c['zona_id'],
                    'zona_nombre_snapshot' => $c['zona_nombre'],
                    'zona_origen' => $c['zona_origen'],
                    'tarifa_sugerida' => $c['tarifa_sugerida'],
                    'costo_adicional' => $c['costo_adicional'],
                    'motivo_costo' => $c['motivo_costo'],
                    'recaudo_esperado' => $recaudo,
                    'recaudo_estado' => $recaudo > 0 ? EstadoRecaudo::PENDIENTE_LIQUIDAR : EstadoRecaudo::NO_APLICA,
                    'recaudo_sugerido' => $calculo['sugerido'],
                    'motivo_recaudo' => $modificado ? $motivoRecaudo : null,
                    'moneda' => $pedido->base_currency_code ? mb_substr((string) $pedido->base_currency_code, 0, 3) : null,
                    'observacion' => $observacion,
                    'actor_admin_id' => (int) $actor->id,
                    'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
                    'idempotency_key' => $clave,
                    'huella' => $huella,
                    // F6: solo con decision (la columna existe donde existe la tabla de decisiones).
                ] + ($disposicion !== null ? ['disposicion_cobro_id' => (int) $disposicion->id] : []));

                if ($meta !== null && $s->archivo !== null) {
                    $rutaEscrita = $this->archivos->almacenar($guia, $s->archivo, $meta, OrigenArchivoGuia::MANUAL, $actor)->ruta;
                }

                return new ResultadoDespacho((int) $pedido->id, (int) $envio->id, $guia->load('archivoVigente'), null, false);
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->borrar($rutaEscrita);

            // Carrera con otra peticion: o es la misma clave (se devuelve la ya registrada) o la guia ya existe.
            return $this->guiaPrevia($clave, $huella)
                ?? throw new Invalida(Invalida::GUIA_DUPLICADA, ['numero_guia' => $numero !== '' ? $numero : ConsecutivoGuiaInterna::PREFIJO]);
        } catch (Throwable $e) {
            $this->borrar($rutaEscrita);

            throw $e;
        }
    }

    public function entregarEnBodega(?Admin $actor, SolicitudRecogida $s): ResultadoDespacho
    {
        $this->exigir($actor, AutorizacionLogistica::PERMISO_DESPACHAR);
        $this->exigir($actor, AutorizacionLogistica::PERMISO_ENTREGAS_REGISTRAR);
        $clave = $this->clave($s->idempotencyKey);
        $claveEntrega = 'bodega:'.$clave;

        if ($previa = Entrega::query()->where('idempotency_key', $claveEntrega)->first()) {
            return new ResultadoDespacho((int) $previa->order_id, (int) $previa->shipment_id, null, $previa, true);
        }

        return DB::transaction(function () use ($actor, $s, $claveEntrega) {
            $pedido = $this->pedidoBloqueado($s->orderId);

            if ($previa = Entrega::query()->where('idempotency_key', $claveEntrega)->first()) {
                return new ResultadoDespacho((int) $previa->order_id, (int) $previa->shipment_id, null, $previa, true);
            }

            if ($pedido->shipping_method !== PuntosRecogida::METODO) {
                throw new Invalida(Invalida::NO_ES_RECOGIDA);
            }

            // Ni el saldo pendiente ni un pago en validacion impiden entregar: no hay cobro de terceros y Cartera conserva su estado.
            $this->exigirDespachable($pedido);

            $unidades = $this->unidades->normalizar($pedido, $s->unidades);
            $bodega = $this->unidades->elegirBodega($pedido, $unidades);
            $envio = $this->crearEnvio($pedido, $bodega, $unidades, 'Recogida en bodega', '', FuentePanel::TRANSPORTADORA_RECOGIDA);

            $itemsEnvio = DB::table('shipment_items')->where('shipment_id', $envio->id)->pluck('id', 'order_item_id');
            $porItem = [];

            foreach ($unidades as $lineaId => $cantidad) {
                $porItem[(int) $itemsEnvio[$lineaId]] = $cantidad;
            }

            $entrega = $this->entregas->registrar($actor, new SolicitudEventoEnvio(
                shipmentId: (int) $envio->id,
                orderId: (int) $pedido->id,
                fecha: now()->toDateString(),
                items: $porItem,
                fuente: FuenteEventoLogistico::RECOGIDA_BODEGA,
                idempotencyKey: $claveEntrega,
                evidenciaReferencia: $s->quienRetira,
            ))->registro;

            return new ResultadoDespacho((int) $pedido->id, (int) $envio->id, null, $entrega, false);
        });
    }

    /** Crea el Shipment NATIVO: descuenta inventario, actualiza el estado del pedido y dispara los eventos de Bagisto. */
    private function crearEnvio(Order $pedido, object $bodega, array $unidades, string $titulo, string $numero, string $codigo): object
    {
        $items = [];

        foreach ($unidades as $lineaId => $cantidad) {
            $items[$lineaId] = [(int) $bodega->id => $cantidad];
        }

        try {
            $envio = $this->envios->create([
                'order_id' => (int) $pedido->id,
                'shipment' => [
                    'carrier_title' => $titulo,
                    'track_number' => $numero,
                    'source' => (int) $bodega->id,
                    'items' => $items,
                ],
            ]);
        } catch (Throwable $e) {
            throw new Invalida(Invalida::DESPACHO_FALLIDO, ['causa' => $e->getMessage()], 'No se pudo crear el despacho: '.$e->getMessage());
        }

        // Codigo estable del catalogo (el formulario nativo solo tiene texto libre). Misma columna que ya existe.
        DB::table('shipments')->where('id', $envio->id)->update(['carrier_code' => $codigo]);

        return $envio;
    }

    private function pedidoBloqueado(int $orderId): Order
    {
        return OrderProxy::modelClass()::query()->lockForUpdate()->find($orderId)
            ?? throw new Invalida(Invalida::PEDIDO_INEXISTENTE);
    }

    private function exigirDespachable(Order $pedido): void
    {
        if (! $pedido->canShip()) {
            throw new Invalida(Invalida::PEDIDO_NO_DESPACHABLE, ['estado' => $pedido->status]);
        }

        if (! $pedido->shipping_address) {
            throw new Invalida(Invalida::PEDIDO_NO_DESPACHABLE, ['motivo' => 'sin_direccion_de_envio']);
        }
    }

    private function guiaPrevia(string $clave, string $huella): ?ResultadoDespacho
    {
        $previa = Guia::query()->with('archivoVigente')->where('idempotency_key', $clave)->first();

        if ($previa === null) {
            return null;
        }

        if (! hash_equals($previa->huella, $huella)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_CONFLICTO, ['id' => $previa->id]);
        }

        return new ResultadoDespacho((int) $previa->order_id, (int) $previa->shipment_id, $previa, null, true);
    }

    private function exigir(?Admin $actor, string $permiso): void
    {
        if (! $this->autorizacion->tienePermiso($actor, $permiso)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }
    }

    private function clave(string $clave): string
    {
        $clave = trim($clave);

        if (! preg_match(self::PATRON_IDEMPOTENCIA, $clave)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_INVALIDA);
        }

        return $clave;
    }

    private function transportadoraValida(string $codigo): object
    {
        $codigo = trim($codigo);

        if ($codigo === '') {
            throw new Invalida(Invalida::TRANSPORTADORA_REQUERIDA);
        }

        // La recogida en bodega tiene su propio flujo («Entregar en bodega»): no es una transportadora de despacho.
        if ($codigo === FuentePanel::TRANSPORTADORA_RECOGIDA) {
            throw new Invalida(Invalida::TRANSPORTADORA_INVALIDA, ['codigo' => $codigo]);
        }

        return $this->transportadoras->activaPorCodigo($codigo) ?? throw new Invalida(Invalida::TRANSPORTADORA_INVALIDA, ['codigo' => $codigo]);
    }

    private function numeroGuia(string $numero): string
    {
        $numero = trim($numero);

        if ($numero === '') {
            throw new Invalida(Invalida::GUIA_REQUERIDA);
        }

        if (! preg_match(self::PATRON_GUIA, $numero)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, ['campo' => 'numero_guia']);
        }

        return $numero;
    }

    private function monto(int|float|string|null $valor, string $codigoError): ?float
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }

        if (! is_numeric($valor) || (float) $valor < 0 || (float) $valor > self::MAX_MONTO) {
            throw new Invalida($codigoError);
        }

        return round((float) $valor, 2);
    }

    private function texto(?string $valor, int $max): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : mb_substr($valor, 0, $max);
    }

    /** Unidades en orden estable y sin las lineas en 0, para que la huella no cambie con el orden del formulario. */
    private function ordenado(array $unidades): array
    {
        $limpias = array_filter($unidades, fn ($c) => ! in_array(trim((string) $c), ['', '0'], true));
        ksort($limpias);

        return array_map('strval', $limpias);
    }

    private function borrar(?string $ruta): void
    {
        if ($ruta !== null) {
            Storage::disk(GuiaArchivoService::DISCO)->delete($ruta);
        }
    }
}
