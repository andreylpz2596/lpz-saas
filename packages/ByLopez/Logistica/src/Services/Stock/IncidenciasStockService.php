<?php

namespace ByLopez\Logistica\Services\Stock;

use ByLopez\Logistica\Contracts\AjustaInventario;
use ByLopez\Logistica\Enums\EstadoIncidenciaStock as Estado;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\IncidenciaStock;
use ByLopez\Logistica\Models\IncidenciaStockLinea;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Despachos\UnidadesDespachables;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Models\OrderProxy;
use Webkul\User\Contracts\Admin;

/**
 * EXCEPCION DE DESPACHO: «el sistema dice 0 (o menos de lo necesario), pero la prenda esta en bodega».
 *
 *   BODEGA   : stock insuficiente → SOLICITA autorizacion con el conteo fisico (solo solicita; nunca toca inventario).
 *   ADMIN    : revisa → REGULARIZA (sube el stock hasta el conteo fisico confirmado) o RECHAZA. Tambien puede hacer ambas
 *              cosas en una sola operacion (regularizarDirecto), con la misma auditoria.
 *   DESPUES  : el drawer recalcula el maximo y el despacho es el NORMAL. Nunca se despacha por encima del stock.
 *
 * Reglas:
 *  - No hay segunda logica de stock: el inventario se corrige SOLO por el contrato AjustaInventario (que delega en el
 *    ajuste manual de CorteConfeccion: libro de movimientos con cantidad anterior, diferencia, cantidad nueva, usuario,
 *    fecha y motivo; bloqueo de fila; sin negativos).
 *  - Solo INCREMENTOS hacia el conteo fisico. Un conteo menor que el sistema se rechaza; uno igual no es una diferencia.
 *  - Toda regularizacion exige conteo fisico por linea, motivo y la confirmacion expresa de que se verifico.
 *  - Si fisicamente no existen las prendas, se RECHAZA: no cambia nada y el pedido sigue pendiente.
 *  - Una incidencia abierta por pedido. Es todo-o-nada: si una linea falla, no se ajusta ninguna.
 *  - No se mezcla con la anulacion de Shipment (que no existe) ni con el despacho: este servicio no crea Shipments.
 */
class IncidenciasStockService
{
    public const MAX_CONTEO = 1000000;

    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected UnidadesDespachables $unidades,
        protected AjustaInventario $inventario,
    ) {}

    // ------------------------------------------------------------------------------------------------ BODEGA

    /**
     * @param  array<int|string, mixed>  $conteos  order_item_id => unidades que HAY fisicamente en bodega (conteo del SKU)
     */
    public function solicitar(?Admin $actor, int $orderId, array $conteos, ?string $nota, string $clave): IncidenciaStock
    {
        $this->exigir($actor, AutorizacionLogistica::PERMISO_STOCK_SOLICITAR);
        $clave = $this->clave($clave);
        $nota = $this->textoObligatorio($nota);
        $conteos = $this->conteos($conteos);
        $huella = $this->huella('solicitud', $orderId, $conteos, $nota);

        if ($previa = $this->previa($clave, $huella)) {
            return $previa;
        }

        try {
            return DB::transaction(function () use ($actor, $orderId, $conteos, $nota, $clave, $huella) {
                $pedido = $this->pedidoBloqueado($orderId);

                if ($previa = $this->previa($clave, $huella)) {
                    return $previa;
                }

                return $this->crear($actor, $pedido, $conteos, $nota, $clave, $huella);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->previa($clave, $huella) ?? throw new Invalida(Invalida::INCIDENCIA_ABIERTA);
        }
    }

    // --------------------------------------------------------------------------------------------- ADMINISTRADOR

    /**
     * Regulariza una solicitud abierta: sube el stock de cada SKU hasta el conteo FISICO CONFIRMADO.
     *
     * @param  array<int|string, mixed>  $confirmados  linea_id => conteo fisico confirmado (explicito para cada linea)
     */
    public function regularizar(?Admin $actor, int $incidenciaId, array $confirmados, ?string $motivo, bool $confirmaConteoFisico): IncidenciaStock
    {
        $this->exigir($actor, AutorizacionLogistica::PERMISO_STOCK_REGULARIZAR);
        $this->exigirConfirmacion($confirmaConteoFisico);
        $motivo = $this->textoObligatorio($motivo);

        return DB::transaction(function () use ($actor, $incidenciaId, $confirmados, $motivo) {
            $incidencia = IncidenciaStock::query()->lockForUpdate()->find($incidenciaId) ?? throw new Invalida(Invalida::INCIDENCIA_INEXISTENTE);

            return $this->resolver($actor, $incidencia, $confirmados, $motivo);
        });
    }

    public function rechazar(?Admin $actor, int $incidenciaId, ?string $motivo): IncidenciaStock
    {
        $this->exigir($actor, AutorizacionLogistica::PERMISO_STOCK_REGULARIZAR);
        $motivo = $this->textoObligatorio($motivo);

        return DB::transaction(function () use ($actor, $incidenciaId, $motivo) {
            $incidencia = IncidenciaStock::query()->lockForUpdate()->find($incidenciaId) ?? throw new Invalida(Invalida::INCIDENCIA_INEXISTENTE);

            if (! $incidencia->abiertaAun()) {
                throw new Invalida(Invalida::INCIDENCIA_YA_RESUELTA, ['id' => $incidencia->id]);
            }

            $incidencia->forceFill([
                'estado' => Estado::RECHAZADA, 'abierta' => null, 'motivo_resolucion' => mb_substr($motivo, 0, 500),
                'resuelta_por_admin_id' => (int) $actor->id, 'resuelta_por_name_snapshot' => mb_substr((string) $actor->name, 0, 191), 'resuelta_en' => now(),
            ])->save();

            return $incidencia->load('lineas');
        });
    }

    /**
     * El Administrador solicita Y resuelve en una sola operacion (misma auditoria: queda como incidencia con solicitante y
     * resolutor). Si el pedido ya tiene una solicitud abierta, esa es la que se resuelve.
     *
     * @param  array<int|string, mixed>  $conteos  order_item_id => conteo fisico confirmado
     */
    public function regularizarDirecto(?Admin $actor, int $orderId, array $conteos, ?string $motivo, bool $confirmaConteoFisico, string $clave): IncidenciaStock
    {
        $this->exigir($actor, AutorizacionLogistica::PERMISO_STOCK_REGULARIZAR);
        $this->exigirConfirmacion($confirmaConteoFisico);
        $clave = $this->clave($clave);
        $motivo = $this->textoObligatorio($motivo);
        $conteos = $this->conteos($conteos);
        $huella = $this->huella('directa', $orderId, $conteos, $motivo);

        if ($previa = $this->previa($clave, $huella)) {
            return $previa;
        }

        try {
            return DB::transaction(function () use ($actor, $orderId, $conteos, $motivo, $clave, $huella) {
                $pedido = $this->pedidoBloqueado($orderId);

                if ($previa = $this->previa($clave, $huella)) {
                    return $previa;
                }

                $abierta = IncidenciaStock::query()->where('order_id', $orderId)->where('abierta', 1)->lockForUpdate()->first();
                $incidencia = $abierta ?? $this->crear($actor, $pedido, $conteos, $motivo, $clave, $huella);
                $incidencia->load('lineas');

                // Confirmado por linea: lo indicado para su linea de pedido; si falta, el conteo ya declarado.
                $confirmados = [];

                foreach ($incidencia->lineas as $linea) {
                    $confirmados[$linea->id] = $conteos[$linea->order_item_id] ?? $linea->conteo_declarado;
                }

                return $this->resolver($actor, $incidencia, $confirmados, $motivo);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->previa($clave, $huella) ?? throw new Invalida(Invalida::INCIDENCIA_ABIERTA);
        }
    }

    // ------------------------------------------------------------------------------------------------- LECTURA

    public function puedeVer(?Admin $actor): bool
    {
        foreach ([AutorizacionLogistica::PERMISO_STOCK, AutorizacionLogistica::PERMISO_STOCK_SOLICITAR, AutorizacionLogistica::PERMISO_STOCK_REGULARIZAR] as $permiso) {
            if ($this->autorizacion->tienePermiso($actor, $permiso)) {
                return true;
            }
        }

        return false;
    }

    /** Solicitud abierta y ultima resuelta de un pedido, para el drawer. @return array{abierta: ?array, ultima: ?array} */
    public function resumenParaPedido(int $orderId): array
    {
        $abierta = IncidenciaStock::query()->with('lineas')->where('order_id', $orderId)->where('abierta', 1)->first();
        $ultima = IncidenciaStock::query()->where('order_id', $orderId)->where('estado', '!=', Estado::SOLICITADA->value)->orderByDesc('id')->first();

        return [
            'abierta' => $abierta ? [
                'id' => (int) $abierta->id,
                'solicitante' => $abierta->solicitante_name_snapshot,
                'fecha' => $abierta->created_at?->format('Y-m-d H:i'),
                'nota' => $abierta->nota_solicitud,
                'lineas' => $abierta->lineas->map(fn (IncidenciaStockLinea $l) => [
                    'id' => (int) $l->id, 'order_item_id' => (int) $l->order_item_id, 'sku' => $l->sku_snapshot, 'conteo_declarado' => (int) $l->conteo_declarado,
                ])->all(),
            ] : null,
            'ultima' => $ultima ? [
                'id' => (int) $ultima->id,
                'estado' => $ultima->estado->value,
                'motivo' => $ultima->motivo_resolucion,
                'resolutor' => $ultima->resuelta_por_name_snapshot,
                'fecha' => $ultima->resuelta_en?->format('Y-m-d H:i'),
            ] : null,
        ];
    }

    public function abiertas(): int
    {
        return IncidenciaStock::query()->where('abierta', 1)->count();
    }

    /** Bandeja de incidencias (pestaña de Despachos). @param array{estado: string, q: ?string} $filtros  estado = abiertas | todas */
    public function listar(array $filtros, int $pagina = 1): LengthAwarePaginator
    {
        $consulta = IncidenciaStock::query()->with('lineas')
            ->join('orders as o', 'o.id', '=', 'bylopez_lg_incidencias_stock.order_id')
            ->select('bylopez_lg_incidencias_stock.*', 'o.increment_id')
            ->selectRaw("TRIM(CONCAT(COALESCE(o.customer_first_name, ''), ' ', COALESCE(o.customer_last_name, ''))) as cliente");

        if (($filtros['estado'] ?? 'abiertas') === 'abiertas') {
            $consulta->where('bylopez_lg_incidencias_stock.abierta', 1);
        }

        if (($texto = $filtros['q'] ?? null) !== null) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto).'%';
            $consulta->where(fn ($w) => $w->where('o.increment_id', 'like', $like)->orWhere('bylopez_lg_incidencias_stock.solicitante_name_snapshot', 'like', $like));
        }

        // Lo mas antiguo primero cuando esta pendiente (cola); el historial, lo mas reciente primero.
        ($filtros['estado'] ?? 'abiertas') === 'abiertas'
            ? $consulta->orderBy('bylopez_lg_incidencias_stock.id')
            : $consulta->orderByDesc('bylopez_lg_incidencias_stock.id');

        return $consulta->paginate(25, ['*'], 'page', max(1, $pagina));
    }

    /**
     * Todo lo que el Administrador necesita para decidir: cifras de la solicitud, stock EN VIVO y contexto para encontrar la
     * causa del descuadre (ultimos movimientos del libro, reservas, demanda de otros pedidos y despachos recientes).
     *
     * @return array<string, mixed>
     */
    public function detalle(?Admin $actor, int $incidenciaId): array
    {
        if (! $this->puedeVer($actor)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $incidencia = IncidenciaStock::query()->with('lineas')->find($incidenciaId) ?? throw new Invalida(Invalida::INCIDENCIA_INEXISTENTE);
        $numero = DB::table('orders')->where('id', $incidencia->order_id)->value('increment_id');

        return [
            'id' => (int) $incidencia->id,
            'pedido_id' => (int) $incidencia->order_id,
            'pedido' => $numero,
            'estado' => $incidencia->estado->value,
            'nota' => $incidencia->nota_solicitud,
            'solicitante' => $incidencia->solicitante_name_snapshot,
            'solicitada_en' => $incidencia->created_at?->format('Y-m-d H:i'),
            'resolucion' => $incidencia->abiertaAun() ? null : [
                'motivo' => $incidencia->motivo_resolucion, 'por' => $incidencia->resuelta_por_name_snapshot, 'en' => $incidencia->resuelta_en?->format('Y-m-d H:i'),
            ],
            'lineas' => $incidencia->lineas->map(fn (IncidenciaStockLinea $l) => [
                'id' => (int) $l->id,
                'sku' => $l->sku_snapshot,
                'necesaria' => (int) $l->cantidad_necesaria,
                'stock_solicitud' => (int) $l->stock_sistema_solicitud,
                'stock_vivo' => $this->inventario->stockActual((int) $l->product_id),
                'conteo_declarado' => (int) $l->conteo_declarado,
                'stock_resolucion' => $l->stock_sistema_resolucion,
                'conteo_confirmado' => $l->conteo_confirmado,
                'diferencia' => $l->diferencia,
                'movimiento_id' => $l->movimiento_id,
                'reservado' => (int) DB::table('product_ordered_inventories')->where('product_id', $l->product_id)->sum('qty'),
                'otros_pedidos' => $this->demandaDeOtros((int) $l->product_id, (int) $incidencia->order_id),
                'movimientos' => $this->inventario->ultimosMovimientos((int) $l->product_id),
                'despachos_recientes' => $this->despachosRecientes((int) $l->product_id),
            ])->all(),
            'puede_resolver' => $incidencia->abiertaAun() && $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_STOCK_REGULARIZAR),
        ];
    }

    // ------------------------------------------------------------------------------------------------ INTERNOS

    /** @param array<int, int> $conteos  order_item_id => conteo declarado */
    private function crear(Admin $actor, Order $pedido, array $conteos, string $nota, string $clave, string $huella): IncidenciaStock
    {
        if ($abierta = IncidenciaStock::query()->where('order_id', $pedido->id)->where('abierta', 1)->first()) {
            throw new Invalida(Invalida::INCIDENCIA_ABIERTA, ['id' => $abierta->id]);
        }

        if (! $pedido->canShip()) {
            throw new Invalida(Invalida::PEDIDO_NO_DESPACHABLE, ['estado' => $pedido->status]);
        }

        $pendientes = $this->unidades->pendientes($pedido);
        $plan = $this->unidades->plan($pedido)['lineas'];
        $lineas = [];

        foreach ($conteos as $lineaId => $conteo) {
            /** @var OrderItem $linea */
            $linea = $pendientes->get($lineaId) ?? throw new Invalida(Invalida::LINEA_NO_DEL_PEDIDO, ['order_item_id' => $lineaId]);
            $cifras = $plan[$lineaId];

            // Solo se regulariza lo que realmente falta por STOCK; si alcanza no hay incidencia que abrir.
            if ($cifras['disponibles'] >= $cifras['pendientes']) {
                throw new Invalida(Invalida::SIN_FALTANTE, ['sku' => $linea->sku]);
            }

            $fisico = $this->productoFisico($linea);
            $stock = $this->inventario->stockActual($fisico['id']);

            if ($conteo <= $stock) {
                throw new Invalida(Invalida::CONTEO_NO_SUPERA_STOCK, ['sku' => $fisico['sku'], 'sistema' => $stock, 'conteo' => $conteo]);
            }

            $lineas[] = [
                'order_item_id' => (int) $linea->id, 'product_id' => $fisico['id'], 'sku_snapshot' => $fisico['sku'],
                'cantidad_necesaria' => $cifras['pendientes'], 'stock_sistema_solicitud' => $stock, 'conteo_declarado' => $conteo,
            ];
        }

        $incidencia = IncidenciaStock::query()->create([
            'order_id' => (int) $pedido->id,
            'inventory_source_id' => (int) $this->inventario->fuente()->id,
            'estado' => Estado::SOLICITADA,
            'abierta' => 1,
            'nota_solicitud' => mb_substr($nota, 0, 500),
            'solicitante_admin_id' => (int) $actor->id,
            'solicitante_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
            'idempotency_key' => $clave,
            'huella' => $huella,
        ]);

        foreach ($lineas as $datos) {
            $incidencia->lineas()->create($datos);
        }

        return $incidencia->load('lineas');
    }

    /**
     * Pasada 1: valida TODO sin tocar nada. Pasada 2: ajusta. Todo-o-nada dentro de la transaccion del llamador.
     *
     * @param  array<int|string, mixed>  $confirmados  linea_id => conteo fisico confirmado
     */
    private function resolver(Admin $actor, IncidenciaStock $incidencia, array $confirmados, string $motivo): IncidenciaStock
    {
        if (! $incidencia->abiertaAun()) {
            throw new Invalida(Invalida::INCIDENCIA_YA_RESUELTA, ['id' => $incidencia->id]);
        }

        $incidencia->loadMissing('lineas');
        $numero = (string) DB::table('orders')->where('id', $incidencia->order_id)->value('increment_id');
        $plan = [];
        $hayAumento = false;

        foreach ($incidencia->lineas as $linea) {
            if (! array_key_exists($linea->id, $confirmados) && ! array_key_exists((string) $linea->id, $confirmados)) {
                throw new Invalida(Invalida::CONTEO_INVALIDO, ['linea' => $linea->id, 'sku' => $linea->sku_snapshot]);
            }

            $conteo = $this->entero($confirmados[$linea->id] ?? $confirmados[(string) $linea->id], ['linea' => $linea->id]);
            $stock = $this->inventario->stockActual((int) $linea->product_id);

            if ($conteo < $stock) {
                throw new Invalida(Invalida::CONTEO_MENOR_AL_SISTEMA, ['sku' => $linea->sku_snapshot, 'sistema' => $stock, 'conteo' => $conteo]);
            }

            $hayAumento = $hayAumento || $conteo > $stock;
            $plan[$linea->id] = ['conteo' => $conteo, 'stock' => $stock];
        }

        if (! $hayAumento) {
            throw new Invalida(Invalida::SIN_DIFERENCIA);
        }

        foreach ($incidencia->lineas as $linea) {
            ['conteo' => $conteo, 'stock' => $stock] = $plan[$linea->id];

            if ($conteo === $stock) {
                $linea->forceFill(['stock_sistema_resolucion' => $stock, 'conteo_confirmado' => $conteo, 'diferencia' => 0])->save();

                continue;
            }

            $ajuste = $this->inventario->elevarAConteo(
                $actor,
                (int) $linea->product_id,
                $conteo,
                mb_substr(sprintf('Regularización de stock para despacho · pedido #%s · incidencia #%d · %s', $numero, $incidencia->id, $motivo), 0, 1000),
                'lg-inc-'.$incidencia->id.'-'.$linea->product_id,
            );

            $linea->forceFill([
                'stock_sistema_resolucion' => $ajuste['anterior'], 'conteo_confirmado' => $conteo,
                'diferencia' => $ajuste['diferencia'], 'movimiento_id' => $ajuste['movimiento_id'],
            ])->save();
        }

        $incidencia->forceFill([
            'estado' => Estado::REGULARIZADA, 'abierta' => null, 'motivo_resolucion' => mb_substr($motivo, 0, 500),
            'resuelta_por_admin_id' => (int) $actor->id, 'resuelta_por_name_snapshot' => mb_substr((string) $actor->name, 0, 191), 'resuelta_en' => now(),
        ])->save();

        return $incidencia->load('lineas');
    }

    /**
     * SKU fisico de la linea: el propio producto (simple) o la variante hija (configurable), que es lo que el Shipment
     * descuenta. Una linea compuesta con varios hijos no se soporta (no existe en el catalogo actual).
     *
     * @return array{id: int, sku: ?string}
     */
    private function productoFisico(OrderItem $linea): array
    {
        if (! $linea->getTypeInstance()->isComposite()) {
            return ['id' => (int) $linea->product_id, 'sku' => $linea->sku];
        }

        $hijos = $linea->children->filter(fn (OrderItem $h) => $h->product_id);

        if ($hijos->count() !== 1) {
            throw new Invalida(Invalida::LINEA_NO_SOPORTADA, ['sku' => $linea->sku]);
        }

        return ['id' => (int) $hijos->first()->product_id, 'sku' => $hijos->first()->sku];
    }

    private function demandaDeOtros(int $productId, int $excluirPedido): int
    {
        return (int) DB::table('order_items as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->leftJoin('order_items as c', 'c.parent_id', '=', 'p.id')
            ->whereNull('p.parent_id')
            ->where('p.order_id', '!=', $excluirPedido)
            ->whereNotIn('o.status', ['canceled', 'closed', 'fraud'])
            ->whereRaw('COALESCE(c.product_id, p.product_id) = ?', [$productId])
            ->selectRaw('COALESCE(SUM(GREATEST(p.qty_ordered - p.qty_shipped - p.qty_canceled - p.qty_refunded, 0)), 0) as total')
            ->value('total');
    }

    /** @return list<array<string, mixed>> */
    private function despachosRecientes(int $productId): array
    {
        return DB::table('shipment_items as si')
            ->join('shipments as s', 's.id', '=', 'si.shipment_id')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->where('si.product_id', $productId)
            ->orderByDesc('s.id')->limit(5)
            ->get(['s.id as envio', 'o.increment_id as pedido', 'si.qty', 's.created_at'])
            ->map(fn ($f) => (array) $f)->all();
    }

    private function pedidoBloqueado(int $orderId): Order
    {
        return OrderProxy::modelClass()::query()->lockForUpdate()->find($orderId) ?? throw new Invalida(Invalida::PEDIDO_INEXISTENTE);
    }

    private function previa(string $clave, string $huella): ?IncidenciaStock
    {
        $previa = IncidenciaStock::query()->with('lineas')->where('idempotency_key', $clave)->first();

        if ($previa === null) {
            return null;
        }

        if (! hash_equals($previa->huella, $huella)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_CONFLICTO, ['id' => $previa->id]);
        }

        return $previa;
    }

    /** @return array<int, int> order_item_id => conteo, ordenado */
    private function conteos(array $conteos): array
    {
        $normalizados = [];

        foreach ($conteos as $lineaId => $conteo) {
            $id = filter_var($lineaId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id === false) {
                throw new Invalida(Invalida::LINEA_NO_DEL_PEDIDO, ['order_item_id' => $lineaId]);
            }

            if (in_array(trim((string) $conteo), ['', null], true)) {
                continue; // el drawer envia todas las lineas cortas; la que no se cuenta no es parte de la solicitud.
            }

            $normalizados[$id] = $this->entero($conteo, ['order_item_id' => $id]);
        }

        if ($normalizados === []) {
            throw new Invalida(Invalida::SIN_UNIDADES);
        }

        ksort($normalizados);

        return $normalizados;
    }

    /** @param array<string, mixed> $detalles */
    private function entero(mixed $valor, array $detalles = []): int
    {
        $n = filter_var($valor, FILTER_VALIDATE_INT);

        if ($n === false || $n < 0 || $n > self::MAX_CONTEO) {
            throw new Invalida(Invalida::CONTEO_INVALIDO, $detalles);
        }

        return $n;
    }

    private function textoObligatorio(?string $texto): string
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            throw new Invalida(Invalida::MOTIVO_REQUERIDO);
        }

        return mb_substr($texto, 0, 500);
    }

    private function exigirConfirmacion(bool $confirma): void
    {
        if (! $confirma) {
            throw new Invalida(Invalida::CONFIRMACION_REQUERIDA);
        }
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

        if (! preg_match(DespachoService::PATRON_IDEMPOTENCIA, $clave)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_INVALIDA);
        }

        return $clave;
    }

    private function huella(string $tipo, int $orderId, array $conteos, string $texto): string
    {
        return hash('sha256', json_encode([$tipo, $orderId, $conteos, $texto]));
    }
}
