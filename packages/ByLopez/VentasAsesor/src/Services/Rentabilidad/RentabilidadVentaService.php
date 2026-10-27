<?php

namespace ByLopez\VentasAsesor\Services\Rentabilidad;

use Brick\Math\BigDecimal;
use ByLopez\VentasAsesor\Enums\TipoOperacionPedido;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\ConfirmacionVentaInvalidaException;
use ByLopez\VentasAsesor\Models\RentabilidadEvaluacion;
use ByLopez\VentasAsesor\Models\RentabilidadEvaluacionLinea;
use ByLopez\VentasAsesor\Services\Comisiones\AcuerdosComisionService;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use ByLopez\VentasAsesor\Support\Costos\CostoComisionableCatalogo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * R2 — arma las entradas del EvaluadorRentabilidad desde el carrito de un borrador (advertencia y bloqueo) o desde un
 * pedido ya creado (snapshot), y guarda el snapshot. Siempre con los parametros VIGENTES en el instante evaluado: el
 * borrador puede ser viejo, por eso la confirmacion vuelve a evaluar (A-02).
 *
 * Fuentes: precio neto = `base_price` − `base_discount_amount` / cantidad (sin IVA, como COM-02); costo = `cost` vigente
 * del catalogo en el carrito y el snapshot congelado (COM-01) en el pedido; referencia = familia de Bagisto del producto
 * padre; estado del costo = Control de costos (R1).
 */
class RentabilidadVentaService
{
    public function __construct(
        protected EvaluadorRentabilidad $evaluador,
        protected ConfiguracionRentabilidadService $configuracion,
        protected AcuerdosComisionService $acuerdos,
        protected CostoComisionableCatalogo $catalogo,
    ) {}

    public function bloqueoActivo(): bool
    {
        return (bool) config('ventasasesor.rentabilidad.bloqueo_activo', false);
    }

    /**
     * Evaluacion del carrito con la propiedad comercial indicada. `$propiedad` = advisor | company | unassigned.
     *
     * @return array<string, mixed>
     */
    public function deCarrito(int $cartId, string $propiedad, ?int $asesorId, TipoOperacionPedido $tipo = TipoOperacionPedido::VENTA_NORMAL): array
    {
        $carrito = DB::table('cart')->where('id', $cartId)->first(['id', 'shipping_method', 'base_grand_total', 'base_shipping_amount']);

        if ($carrito === null) {
            return $this->vacio();
        }

        $items = DB::table('cart_items')->where('cart_id', $cartId)->orderBy('id')
            ->get(['id', 'parent_id', 'product_id', 'sku', 'name', 'type', 'quantity', 'base_price', 'base_discount_amount']);
        $lineas = $items->whereNull('parent_id');
        $hijas = $items->whereNotNull('parent_id')->keyBy('parent_id');

        $variantes = [];
        foreach ($lineas as $l) {
            $variantes[(int) $l->id] = (int) ($l->type === 'configurable' ? ($hijas->get($l->id)->product_id ?? 0) : $l->product_id);
        }

        $costos = $this->catalogo->costosDe(array_values(array_filter($variantes)));
        $familias = $this->familiasDe($lineas->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $telas = $this->telasDe(array_values(array_filter($variantes)));
        $ahora = CarbonImmutable::now();
        $estados = $this->configuracion->estadosDeCosto(array_values(array_unique(array_filter($familias))), $ahora);
        $nombres = $this->configuracion->familias();

        $entrada = [];
        foreach ($lineas as $l) {
            $q = max(0, (int) $l->quantity);
            $familia = $familias[(int) $l->product_id] ?? null;
            $costo = $costos[$variantes[(int) $l->id]] ?? null;

            $entrada[] = [
                'clave' => (int) $l->id,
                'familia_id' => $familia,
                'tipo_tela_id' => $telas[$variantes[(int) $l->id]] ?? null,
                'referencia' => $familia === null ? null : ($nombres[$familia] ?? null),
                'producto' => (string) $l->name,
                'sku' => (string) ($hijas->get($l->id)->sku ?? $l->sku),
                'cantidad' => $q,
                'precio_neto' => $this->precioNeto($l->base_price, $l->base_discount_amount, $q),
                'costo' => $costo === null ? null : (string) $costo,
                'estado_costo' => $costo === null ? 'sin_costo' : ($estados[$familia] ?? 'sin_estado'),
            ];
        }

        $pago = DB::table('cart_payment')->where('cart_id', $cartId)->value('method');

        return $this->evaluar($entrada, $ahora, $propiedad, $asesorId, $tipo, $carrito->shipping_method, $pago, (string) $carrito->base_grand_total, (string) ($carrito->base_shipping_amount ?? '0'));
    }

    /**
     * Evaluacion de un pedido YA creado, con su costo congelado (COM-01) y su atribucion. No guarda nada.
     *
     * @return array<string, mixed>
     */
    public function dePedido(int $orderId): array
    {
        $pedido = DB::table('orders')->where('id', $orderId)->first(['id', 'created_at', 'shipping_method', 'base_grand_total', 'base_shipping_amount']);

        if ($pedido === null) {
            return $this->vacio();
        }

        $items = DB::table('order_items')->where('order_id', $orderId)->whereNull('parent_id')->orderBy('id')
            ->get(['id', 'product_id', 'sku', 'name', 'qty_ordered', 'qty_canceled', 'base_price', 'base_discount_amount']);
        $snap = DB::table('bylopez_va_costos_venta')->whereIn('order_item_id', $items->pluck('id'))->get()->keyBy('order_item_id');
        $familias = $this->familiasDe($items->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        // Variante vendida (item hijo de un configurable) o el propio producto: de ahi sale el tipo de tela.
        $variantes = DB::table('order_items')->where('order_id', $orderId)->whereNotNull('parent_id')->pluck('product_id', 'parent_id');
        $telas = $this->telasDe($items->map(fn ($i) => (int) ($variantes[$i->id] ?? $i->product_id))->all());
        $fecha = CarbonImmutable::parse($pedido->created_at, (string) config('app.timezone'));
        $estados = $this->configuracion->estadosDeCosto(array_values(array_unique(array_filter($familias))), $fecha);
        $nombres = $this->configuracion->familias();

        $entrada = [];
        foreach ($items as $i) {
            $qPedida = (int) $i->qty_ordered;
            $familia = $familias[(int) $i->product_id] ?? null;
            $s = $snap->get($i->id);
            $costo = $s !== null && $s->costo_unitario !== null && $s->autoridad_comision ? (string) $s->costo_unitario : null;

            $entrada[] = [
                'clave' => (int) $i->id,
                'familia_id' => $familia,
                'tipo_tela_id' => $telas[(int) ($variantes[$i->id] ?? $i->product_id)] ?? null,
                'referencia' => $familia === null ? null : ($nombres[$familia] ?? null),
                'producto' => (string) $i->name,
                'sku' => (string) ($s->sku_snapshot ?? $i->sku),
                'cantidad' => max(0, $qPedida - (int) $i->qty_canceled),
                'precio_neto' => $this->precioNeto($i->base_price, $i->base_discount_amount, $qPedida),
                'costo' => $costo,
                'estado_costo' => $costo === null ? 'sin_costo' : ($estados[$familia] ?? 'sin_estado'),
            ];
        }

        $atribucion = DB::table('bylopez_va_order_attributions')->where('order_id', $orderId)->first(['commercial_owner_type', 'advisor_id']);
        $tipo = TipoOperacionPedido::tryFrom((string) DB::table('bylopez_va_clasificaciones_pedido')->where('order_id', $orderId)->value('tipo_operacion'))
            ?? TipoOperacionPedido::VENTA_NORMAL;
        $pago = DB::table('order_payment')->where('order_id', $orderId)->value('method');

        $r = $this->evaluar(
            $entrada, $fecha,
            (string) ($atribucion->commercial_owner_type ?? TipoPropiedadComercial::SIN_ASIGNAR->value),
            $atribucion?->advisor_id === null ? null : (int) $atribucion->advisor_id,
            $tipo, $pedido->shipping_method, $pago, (string) $pedido->base_grand_total, (string) $pedido->base_shipping_amount,
        );

        return $r + ['canal' => DB::table('bylopez_va_clasificaciones_pedido')->where('order_id', $orderId)->exists() ? 'nueva_venta' : 'otro_canal'];
    }

    /** Snapshot inmutable de la evaluacion del pedido. Idempotente: si ya existe, no hace nada. */
    public function registrarDePedido(int $orderId): ?RentabilidadEvaluacion
    {
        if (RentabilidadEvaluacion::query()->where('order_id', $orderId)->exists()) {
            return null;
        }

        $r = $this->dePedido($orderId);

        if ($r['lineas'] === []) {
            return null;
        }

        $evaluacion = RentabilidadEvaluacion::query()->create([
            'order_id' => $orderId,
            'resultado' => $r['resultado'],
            'bloqueo_activo' => $this->bloqueoActivo(),
            'canal' => $r['canal'],
            'propiedad' => $r['propiedad'],
            'asesor_admin_id' => $r['asesor_id'],
            'acuerdo_id' => $r['acuerdo_id'],
            'porcentaje' => $r['tasa'],
            'costo_pedido_estimado' => $r['costo_pedido'],
            'holgura_pedido' => $r['holgura'],
            'costos_directos' => $r['costos_aplicados'],
            'motivos' => $r['motivos'],
            'evaluado_en' => now(),
        ]);

        foreach ($r['lineas'] as $l) {
            RentabilidadEvaluacionLinea::query()->create([
                'evaluacion_id' => $evaluacion->id,
                'order_item_id' => $l['clave'],
                'familia_id' => $l['familia_id'],
                'tipo_tela_id' => $l['tipo_tela_id'] ?? null,
                'referencia' => $l['referencia'] === null ? null : mb_substr($l['referencia'], 0, 191),
                'sku' => $l['sku'] === null ? null : mb_substr($l['sku'], 0, 191),
                'cantidad' => $l['cantidad'],
                'precio_neto_unitario' => $l['precio_neto'],
                'costo_unitario' => $l['costo'],
                'estado_costo' => $l['estado_costo'],
                'politica_id' => $l['politica_id'],
                'ganancia_requerida_unitaria' => $l['requerido'],
                'variable_unitario' => $l['variable'],
                'cargo_pct' => $l['cargo_pct'],
                'comision_unitaria' => $l['comision'],
                'contribucion_unitaria' => $l['contribucion'],
                'precio_minimo' => $l['precio_minimo'],
                'resultado' => $l['resultado'],
            ]);
        }

        return $evaluacion;
    }

    /**
     * BLOQUEO EN SERVIDOR (R2), gobernado por `ventasasesor.rentabilidad.bloqueo_activo` (apagado hasta aprobar parametros y
     * revisar listas). Se llama dentro de la confirmacion, despues de recalcular el carrito y ANTES de crear el pedido. Con
     * el bloqueo activo, una venta normal que no CUMPLE (incluida la falta de politica, costo o tasa) se rechaza: no hay
     * permiso de excepcion. Muestras, reposiciones y garantias no pasan por aqui (flujo propio).
     *
     * @throws ConfirmacionVentaInvalidaException RENTABILIDAD_INSUFICIENTE con la version para el asesor (sin costos)
     */
    public function exigirEnConfirmacion(int $cartId, string $propiedad, ?int $asesorId, TipoOperacionPedido $tipo): void
    {
        if (! $this->bloqueoActivo() || $tipo !== TipoOperacionPedido::VENTA_NORMAL) {
            return;
        }

        $r = $this->deCarrito($cartId, $propiedad, $asesorId, $tipo);

        if ($r['resultado'] !== EvaluadorRentabilidad::CUMPLE) {
            throw new ConfirmacionVentaInvalidaException(ConfirmacionVentaInvalidaException::RENTABILIDAD_INSUFICIENTE, $this->paraAsesor($r));
        }
    }

    /**
     * Version para el ASESOR: solo referencias que no cumplen y motivos genericos. Nunca costos, margenes ni tasas.
     *
     * @return array{resultado: string, bloqueo_activo: bool, referencias: list<string>, configuracion_pendiente: bool, costos_pedido: bool}
     */
    public function paraAsesor(array $r): array
    {
        return [
            'resultado' => $r['resultado'],
            'bloqueo_activo' => $this->bloqueoActivo(),
            'referencias' => $r['referencias_incumplen'],
            'configuracion_pendiente' => in_array($r['resultado'], [EvaluadorRentabilidad::INCOMPLETA, EvaluadorRentabilidad::SIN_POLITICA], true),
            'costos_pedido' => in_array('costos_del_pedido_sin_cubrir', $r['motivos'], true),
        ];
    }

    /** @return array<string, mixed> */
    protected function evaluar(array $entrada, CarbonInterface $fecha, string $propiedad, ?int $asesorId, TipoOperacionPedido $tipo, ?string $envio, ?string $pago, string $total, string $valorEnvio): array
    {
        [$tasa, $origen, $acuerdoId] = match ($propiedad) {
            TipoPropiedadComercial::ASESOR->value => $this->tasaAsesor($asesorId, $fecha),
            TipoPropiedadComercial::EMPRESA->value => ['0', 'empresa', null],
            default => $this->tasaSinAsesor($fecha),
        };

        $r = $this->evaluador->evaluar($entrada, $this->configuracion->politicasVigentes($fecha), $this->configuracion->costosDirectosVigentes($fecha), [
            'tasa' => $tasa,
            'origen_tasa' => $origen,
            'metodo_envio' => $envio,
            'metodo_pago' => $pago,
            'total_cobrado' => $total,
            'valor_envio' => $valorEnvio,
            // Muestras, reposiciones y garantias no son venta comercial: no aplica el minimo (flujo propio, G-11).
            'aplica' => $tipo === TipoOperacionPedido::VENTA_NORMAL,
        ]);

        foreach (array_unique(array_column($r['lineas'], 'estado_costo')) as $estado) {
            if (in_array($estado, ['provisional', 'vencido', 'sin_estado'], true)) {
                $r['motivos'][] = 'costo_'.$estado;
            }
        }

        return $r + ['propiedad' => $propiedad, 'asesor_id' => $asesorId, 'acuerdo_id' => $acuerdoId, 'tasa' => $tasa, 'origen_tasa' => $origen];
    }

    /** @return array{0: ?string, 1: string, 2: ?int} */
    protected function tasaAsesor(?int $asesorId, CarbonInterface $fecha): array
    {
        $acuerdo = $asesorId === null ? null : $this->acuerdos->vigenteEn($asesorId, $fecha);

        return $acuerdo === null ? [null, 'asesor_sin_acuerdo', null] : [(string) $acuerdo->porcentaje, 'acuerdo', (int) $acuerdo->id];
    }

    /**
     * Venta aun sin asesor (puede asignarse despues): la tasa de referencia (mayor % vigente), conservadora. Si NINGUN
     * acuerdo rige en la fecha, ninguna comision puede nacer de esta venta (la linea toma el acuerdo vigente AL CONFIRMAR,
     * tambien tras una reasignacion): la tasa es 0 exacta, no «falta de tasa».
     *
     * @return array{0: string, 1: string, 2: null}
     */
    protected function tasaSinAsesor(CarbonInterface $fecha): array
    {
        $referencia = $this->acuerdos->tasaDeReferencia($fecha);

        return $referencia === null ? ['0', 'sin_acuerdos_vigentes', null] : [$referencia, 'referencia', null];
    }

    protected function precioNeto(mixed $basePrice, mixed $descuento, int $cantidad): string
    {
        $p = Dinero::de($basePrice);

        if ($cantidad > 0) {
            $p = $p->minus(Dinero::dividir(Dinero::de($descuento), BigDecimal::of($cantidad)));
        }

        return (string) Dinero::escala($p);
    }

    /**
     * Familia (referencia) de cada producto: la del padre si es variante, la propia si no.
     *
     * @param  list<int>  $productIds
     * @return array<int, ?int>
     */
    public function familiasDe(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('products as p')->leftJoin('products as padre', 'padre.id', '=', 'p.parent_id')
            ->whereIn('p.id', $productIds)
            ->get(['p.id', DB::raw('COALESCE(padre.attribute_family_id, p.attribute_family_id) as familia')])
            ->mapWithKeys(fn ($f) => [(int) $f->id => $f->familia === null ? null : (int) $f->familia])->all();
    }

    /**
     * Tipo de tela (opcion del atributo `ventasasesor.rentabilidad.atributo_tela`) de cada producto: el de la variante y, si
     * no lo tiene, el del producto padre. Sin atributo o sin valor: null (la politica cae a la de la referencia o defecto).
     *
     * @param  list<int>  $productIds
     * @return array<int, ?int>
     */
    public function telasDe(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));
        $atributo = DB::table('attributes')->where('code', (string) config('ventasasesor.rentabilidad.atributo_tela', 'tipo_tela'))->value('id');

        if ($productIds === [] || $atributo === null) {
            return array_fill_keys($productIds, null);
        }

        $padres = DB::table('products')->whereIn('id', $productIds)->pluck('parent_id', 'id');
        $valores = DB::table('product_attribute_values')->where('attribute_id', $atributo)
            ->whereIn('product_id', [...$productIds, ...$padres->filter()->values()->all()])
            ->whereNotNull('integer_value')->pluck('integer_value', 'product_id');

        $salida = [];

        foreach ($productIds as $id) {
            $padre = $padres[$id] ?? null;
            $v = $valores[$id] ?? ($padre ? ($valores[$padre] ?? null) : null);
            $salida[$id] = $v === null ? null : (int) $v;
        }

        return $salida;
    }

    protected function vacio(): array
    {
        return ['resultado' => EvaluadorRentabilidad::NO_APLICA, 'lineas' => [], 'costo_pedido' => '0.00', 'holgura' => null, 'motivos' => [],
            'costos_aplicados' => [], 'referencias_incumplen' => [], 'propiedad' => null, 'asesor_id' => null, 'acuerdo_id' => null,
            'tasa' => null, 'origen_tasa' => 'ninguna', 'canal' => 'otro_canal'];
    }
}
