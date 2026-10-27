<?php

namespace ByLopez\Logistica\Services\Entregas;

use ByLopez\Logistica\Contracts\ReingresaInventario;
use ByLopez\Logistica\DataObjects\ResultadoRecepcion;
use ByLopez\Logistica\DataObjects\SolicitudRecepcion;
use ByLopez\Logistica\Enums\EstadoEventoLogistico as Estado;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\CatalogoDefecto;
use ByLopez\Logistica\Models\RecepcionDevolucion;
use ByLopez\Logistica\Models\RecepcionDevolucionItem as Linea;
use ByLopez\Logistica\Models\Retorno;
use ByLopez\Logistica\Models\RetornoItem;
use ByLopez\Logistica\Models\UnidadDefectuosa;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use ByLopez\Logistica\Support\ProductoFisicoDeLinea;
use ByLopez\Logistica\Support\ValorDevolucionesEnvio;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * RECEPCION FISICA de una devolucion en bodega + REINGRESO de las unidades APTAS (Entregas · Fase B/C).
 *
 *   devolucion REGISTRADA (Retorno)  ≠  RECIBIDA en bodega (esta operacion)  ≠  reingreso (consecuencia, solo de las aptas)
 *
 * Una sola transaccion, todo o nada: validar → crear la recepcion y sus lineas → por cada linea con aptas, entrada de
 * inventario en la MISMA bodega del Shipment (shipments.inventory_source_id; si no se puede determinar, NO se toca el
 * stock y se rechaza). Si el reingreso de cualquier linea falla, se revierte la recepcion completa.
 *
 * Invariantes por linea (tambien CHECK en la base): recibidas ≤ esperadas · aptas + novedad = recibidas ·
 * faltantes = esperadas − recibidas · con novedad o faltantes, motivo obligatorio. Solo las APTAS suben el inventario.
 *
 * Idempotencia: `idempotency_key` + `huella` (la misma solicitud devuelve la misma recepcion; otra solicitud con la
 * clave → conflicto); UNIQUE de recepcion vigente por devolucion; bloqueo del Shipment y de la devolucion; y una clave
 * determinista por linea en el libro de inventario (`reingreso:recepcion:{id}:retorno_item:{id}`, UNIQUE).
 *
 * C5 · EFECTO FINANCIERO: lo RECIBIDO (aptas + defectuosas) deja de ser exigible al cliente. Cada linea guarda un SNAPSHOT del
 * valor historico de lo recibido (`valor_unitario` / `valor_devuelto`, formula de ValorDevolucionesEnvio sobre la linea del
 * pedido); Cartera lo deriva de las recepciones confirmadas, asi que el efecto nace y se revierte (anulacion) con la
 * recepcion, sin una segunda escritura que pueda perderse. Estado fisico ≠ efecto comercial: aptas y defectuosas reducen la
 * obligacion; los faltantes NO.
 *
 * C5 · DEFECTUOSAS: cada unidad con novedad queda en `bylopez_lg_unidades_defectuosas` (inventario NO vendible: no suma al
 * stock) con motivo estructurado (catalogo), origen probable (predeterminado «por_determinar»), detalle y estado inicial
 * `pendiente_revision`. Sin estructura (llamadores anteriores) la novedad se registra como motivo «otro» con el texto de la
 * linea como detalle: nunca se pierde.
 *
 * No toca pagos, comisiones, Refund ni el estado del pedido; no reembolsa ni genera saldo aplicable (eso es D10).
 */
class RecibirDevolucionService
{
    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected ReingresaInventario $inventario,
        protected ProductoFisicoDeLinea $fisico,
        protected ValorDevolucionesEnvio $valores,
    ) {}

    public function recibir(?Admin $actor, SolicitudRecepcion $s): ResultadoRecepcion
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_RECIBIR_DEVOLUCION)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $clave = trim($s->idempotencyKey);

        if (! preg_match(RegistrarEventoEnvio::PATRON_IDEMPOTENCIA, $clave)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_INVALIDA);
        }

        $fecha = $this->fecha($s->fecha);
        $lineas = $this->lineasNormalizadas($s->lineas);
        $observacion = $this->texto($s->observacion, 500);
        $huella = hash('sha256', json_encode(['recepcion', $s->retornoId, $fecha, $lineas, $observacion]));

        if ($previo = $this->previo($clave, $huella)) {
            return $previo;
        }

        $sinLock = Retorno::query()->find($s->retornoId) ?? throw new Invalida(Invalida::REGISTRO_INEXISTENTE);

        try {
            return DB::transaction(function () use ($actor, $s, $clave, $huella, $fecha, $lineas, $observacion, $sinLock) {
                // Mismo orden de bloqueo que entregas/retornos (Shipment primero): serializa con cualquier otro evento del envio.
                $envio = DB::table('shipments')->where('id', $sinLock->shipment_id)->lockForUpdate()->first()
                    ?? throw new Invalida(Invalida::ENVIO_INEXISTENTE);
                $retorno = Retorno::query()->lockForUpdate()->find($s->retornoId) ?? throw new Invalida(Invalida::REGISTRO_INEXISTENTE);

                if ($previo = $this->previo($clave, $huella)) {
                    return $previo;
                }

                if (! $retorno->vigente()) {
                    throw new Invalida(Invalida::RETORNO_ANULADO, ['id' => $retorno->id]);
                }

                if (RecepcionDevolucion::query()->where('retorno_id', $retorno->id)->where('estado', Estado::CONFIRMADO->value)->exists()) {
                    throw new Invalida(Invalida::RECEPCION_YA_REGISTRADA, ['id' => $retorno->id]);
                }

                if ($fecha < $retorno->fecha_retorno->toDateString()) {
                    throw new Invalida(Invalida::FECHA_INVALIDA);
                }

                $bodega = $this->bodegaDelEnvio($envio);
                $esperadas = RetornoItem::query()->where('retorno_id', $retorno->id)->orderBy('id')->get()->keyBy('id');

                $lineas = $this->validarLineas($esperadas->all(), $lineas);

                $recepcion = RecepcionDevolucion::query()->create([
                    'retorno_id' => (int) $retorno->id,
                    'shipment_id' => (int) $envio->id,
                    'order_id' => (int) $envio->order_id,
                    'inventory_source_id' => $bodega,
                    'fecha_recepcion' => $fecha,
                    'observacion' => $observacion,
                    'actor_admin_id' => (int) $actor->id,
                    'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
                    'estado' => Estado::CONFIRMADO,
                    'idempotency_key' => $clave,
                    'huella' => $huella,
                ]);

                foreach ($esperadas as $itemId => $item) {
                    $l = $lineas[$itemId];
                    $fisico = $this->fisico->deLineaId((int) $item->order_item_id);
                    $estado = Linea::SIN_APTAS;
                    $movimiento = null;

                    if ($l['aptas'] > 0) {
                        // El Shipment solo descuenta stock de productos que gestionan inventario: si no lo gestionan, no hay que reponer nada.
                        if (! $fisico['gestiona_stock']) {
                            $estado = Linea::NO_APLICA;
                        } else {
                            $movimiento = $this->inventario->reingresar(
                                $actor, $fisico['id'], $bodega, $l['aptas'],
                                sprintf('Reingreso por devolucion · pedido #%s · envio #%d · recepcion #%d', $this->numeroPedido((int) $envio->order_id), $envio->id, $recepcion->id),
                                'recepcion_devolucion', (int) $recepcion->id,
                                sprintf('reingreso:recepcion:%d:retorno_item:%d', $recepcion->id, $itemId),
                            );
                            $estado = Linea::REINGRESADO;
                        }
                    }

                    $unitario = $this->valores->unitarioDeLinea((int) $item->order_item_id);

                    $fila = Linea::query()->create([
                        'recepcion_id' => (int) $recepcion->id,
                        'retorno_item_id' => (int) $itemId,
                        'shipment_item_id' => (int) $item->shipment_item_id,
                        'order_item_id' => (int) $item->order_item_id,
                        'product_id' => $fisico['id'],
                        'esperadas' => (int) $item->cantidad,
                        'recibidas' => $l['recibidas'],
                        'aptas' => $l['aptas'],
                        'novedad' => $l['novedad'],
                        'faltantes' => (int) $item->cantidad - $l['recibidas'],
                        'valor_unitario' => round($unitario, 4),
                        'valor_devuelto' => round($unitario * $l['recibidas'], 2),
                        'motivo_novedad' => $l['motivo'],
                        'reingreso_estado' => $estado,
                        'movimiento_inventario_id' => $movimiento['movimiento_id'] ?? null,
                        'reingresado_en' => $movimiento ? now() : null,
                    ]);

                    foreach ($l['defectos'] as $n => $d) {
                        UnidadDefectuosa::query()->create([
                            'recepcion_id' => (int) $recepcion->id,
                            'recepcion_item_id' => (int) $fila->id,
                            'grupo' => $n + 1,
                            'shipment_id' => (int) $envio->id,
                            'order_id' => (int) $envio->order_id,
                            'order_item_id' => (int) $item->order_item_id,
                            'product_id' => $fisico['id'],
                            'inventory_source_id' => $bodega,
                            'cantidad' => $d['cantidad'],
                            'motivo_codigo' => $d['motivo'],
                            'origen_codigo' => $d['origen'],
                            'detalle' => $d['detalle'],
                            'observacion' => $d['observacion'],
                            'estado' => UnidadDefectuosa::PENDIENTE_REVISION,
                            'actor_admin_id' => (int) $actor->id,
                            'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
                        ]);
                    }
                }

                return new ResultadoRecepcion($recepcion->load('items'), false);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Otra peticion gano la carrera: la misma clave devuelve su recepcion; si fue otra clave, ya hay una vigente.
            return $this->previo($clave, $huella) ?? throw new Invalida(Invalida::RECEPCION_YA_REGISTRADA, ['id' => $s->retornoId]);
        }
    }

    private function previo(string $clave, string $huella): ?ResultadoRecepcion
    {
        $previo = RecepcionDevolucion::query()->with('items')->where('idempotency_key', $clave)->first();

        if ($previo === null) {
            return null;
        }

        if (! hash_equals($previo->huella, $huella)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_CONFLICTO, ['id' => $previo->id]);
        }

        return new ResultadoRecepcion($previo, true);
    }

    /** Bodega REAL del despacho. Sin ella no se adivina nada: no se recibe (y no se toca el stock). */
    private function bodegaDelEnvio(object $envio): int
    {
        $fuente = (int) ($envio->inventory_source_id ?? 0);

        if ($fuente <= 0 || ! DB::table('inventory_sources')->where('id', $fuente)->exists()) {
            throw new Invalida(Invalida::BODEGA_ORIGEN_INDETERMINADA, ['envio' => (int) $envio->id]);
        }

        return $fuente;
    }

    /**
     * @param  array<int, RetornoItem>  $esperadas
     * @param  array<int, array{recibidas: int, aptas: int, novedad: int, motivo: ?string, defectos: list<array<string, mixed>>}>  $lineas
     * @return array<int, array{recibidas: int, aptas: int, novedad: int, motivo: ?string, defectos: list<array{cantidad: int, motivo: string, origen: string, detalle: ?string, observacion: ?string}>}>
     */
    private function validarLineas(array $esperadas, array $lineas): array
    {
        $catalogo = CatalogoDefecto::query()->where('activo', true)->get()->groupBy('tipo');
        $motivos = ($catalogo[CatalogoDefecto::MOTIVO] ?? collect())->keyBy('codigo');
        $origenes = ($catalogo[CatalogoDefecto::ORIGEN] ?? collect())->keyBy('codigo');

        $pedidas = array_map('intval', array_keys($esperadas));
        $recibidas = array_map('intval', array_keys($lineas));
        sort($pedidas);
        sort($recibidas);

        if ($pedidas !== $recibidas) {
            throw new Invalida(Invalida::RECEPCION_LINEAS_INCOMPLETAS);
        }

        foreach ($esperadas as $itemId => $item) {
            $l = $lineas[$itemId];
            $cantidad = (int) $item->cantidad;

            if ($l['recibidas'] > $cantidad || $l['recibidas'] !== $l['aptas'] + $l['novedad']) {
                throw new Invalida(Invalida::RECEPCION_CANTIDADES_INVALIDAS, ['linea' => $itemId, 'esperadas' => $cantidad, 'recibidas' => $l['recibidas']]);
            }

            $faltantes = $cantidad - $l['recibidas'];

            // Con novedad CLASIFICADA el motivo estructurado hace de motivo; sin clasificar (o con faltantes) basta el texto de la linea.
            if (($faltantes > 0 || ($l['novedad'] > 0 && $l['defectos'] === [])) && $l['motivo'] === null) {
                throw new Invalida(Invalida::RECEPCION_MOTIVO_REQUERIDO, ['linea' => $itemId]);
            }

            if ($l['novedad'] === 0) {
                if ($l['defectos'] !== []) {
                    throw new Invalida(Invalida::DEFECTO_CANTIDADES_INVALIDAS, ['linea' => $itemId]);
                }

                continue;
            }

            // Novedad sin clasificar (llamadores anteriores): se conserva como «otro / por determinar» con el texto como detalle.
            $defectos = $l['defectos'] !== [] ? $l['defectos'] : [['cantidad' => $l['novedad'], 'motivo' => 'otro', 'origen' => CatalogoDefecto::ORIGEN_PREDETERMINADO, 'detalle' => $l['motivo'], 'observacion' => null]];

            if (array_sum(array_column($defectos, 'cantidad')) !== $l['novedad']) {
                throw new Invalida(Invalida::DEFECTO_CANTIDADES_INVALIDAS, ['linea' => $itemId]);
            }

            foreach ($defectos as $n => $d) {
                $motivo = $motivos->get($d['motivo']) ?? throw new Invalida(Invalida::DEFECTO_MOTIVO_INVALIDO, ['linea' => $itemId, 'motivo' => $d['motivo']]);

                if (! $origenes->has($d['origen'])) {
                    throw new Invalida(Invalida::DEFECTO_ORIGEN_INVALIDO, ['linea' => $itemId, 'origen' => $d['origen']]);
                }

                if ($motivo->requiere_detalle && $d['detalle'] === null) {
                    throw new Invalida(Invalida::DEFECTO_DETALLE_REQUERIDO, ['linea' => $itemId]);
                }
            }

            $lineas[$itemId]['defectos'] = array_values($defectos);
        }

        return $lineas;
    }

    /** @return array<int, array{recibidas: int, aptas: int, novedad: int, motivo: ?string, defectos: list<array<string, mixed>>}> ordenado por retorno_item_id */
    private function lineasNormalizadas(array $lineas): array
    {
        $salida = [];

        foreach ($lineas as $itemId => $l) {
            $id = filter_var($itemId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $l = (array) $l;
            $n = fn (string $k) => filter_var($l[$k] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $recibidas = $n('recibidas');
            $aptas = $n('aptas');
            $novedad = $n('novedad');

            if ($id === false || $recibidas === false || $aptas === false || $novedad === false) {
                throw new Invalida(Invalida::RECEPCION_CANTIDADES_INVALIDAS, ['linea' => $itemId]);
            }

            $defectos = [];

            foreach ((array) ($l['defectos'] ?? []) as $d) {
                $d = (array) $d;
                $cantidad = filter_var($d['cantidad'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                if ($cantidad === false) {
                    throw new Invalida(Invalida::DEFECTO_CANTIDADES_INVALIDAS, ['linea' => $itemId]);
                }

                $defectos[] = [
                    'cantidad' => $cantidad,
                    'motivo' => trim((string) ($d['motivo'] ?? '')),
                    'origen' => trim((string) ($d['origen'] ?? '')) ?: CatalogoDefecto::ORIGEN_PREDETERMINADO,
                    'detalle' => $this->texto($d['detalle'] ?? null, 500),
                    'observacion' => $this->texto($d['observacion'] ?? null, 500),
                ];
            }

            $salida[$id] = ['recibidas' => $recibidas, 'aptas' => $aptas, 'novedad' => $novedad, 'motivo' => $this->texto($l['motivo'] ?? null, 500), 'defectos' => $defectos];
        }

        if ($salida === []) {
            throw new Invalida(Invalida::RECEPCION_LINEAS_INCOMPLETAS);
        }

        ksort($salida);

        return $salida;
    }

    private function fecha(string $fecha): string
    {
        $f = CarbonImmutable::createFromFormat('!Y-m-d', trim($fecha));

        if (! $f || $f->format('Y-m-d') !== trim($fecha) || $f->greaterThan(CarbonImmutable::today())) {
            throw new Invalida(Invalida::FECHA_INVALIDA);
        }

        return $f->format('Y-m-d');
    }

    private function numeroPedido(int $orderId): string
    {
        return (string) DB::table('orders')->where('id', $orderId)->value('increment_id');
    }

    private function texto(?string $valor, int $max): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : mb_substr($valor, 0, $max);
    }
}
