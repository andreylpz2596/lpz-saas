<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use Brick\Math\BigDecimal;
use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autorizacion;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * «Simular próxima venta»: cuánto comisionaría un pedido hipotético de la asesora autenticada. SOLO LECTURA: no crea pedidos, no
 * reserva stock, no congela costos ni guarda movimientos (ni siquiera lineas de comision). El resultado se identifica como
 * SIMULACION y reutiliza los servicios existentes: el avance del umbral (UmbralMensualService), el % y el umbral del acuerdo vigente
 * (AcuerdosComisionService) y el costo vigente del catalogo (CapturarCostoVentaService).
 *
 * No inventa: si falta el acuerdo, el umbral, el % o el costo de una referencia, o si el pedido depende de una politica no aprobada
 * (pedido MIXTO que cruza el umbral con bases distintas), devuelve `comision = null` y dice por que. Tampoco promete un pago: la
 * comision real se habilita solo con entrega validada, pago confirmado y dinero recibido, y usa el costo congelado AL CONFIRMAR.
 *
 * Para la asesora solo sale el total y las unidades: ni costo ni base ni margen.
 */
class SimuladorProximaVentaService
{
    public const MAX_LINEAS = 20;

    public function __construct(
        protected Autorizacion $autorizacion,
        protected UmbralMensualService $umbral,
        protected AcuerdosComisionService $acuerdos,
        protected CapturarCostoVentaService $costos,
    ) {}

    /**
     * @param  list<array{sku?: mixed, cantidad?: mixed, precio_neto?: mixed}>  $lineas
     * @return array<string, mixed>
     */
    public function simular(?Admin $actor, array $lineas): array
    {
        if (! $actor?->id || ! $this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_COMISIONES_VER_PROPIAS)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tiene permiso para simular comisiones.');
        }

        $lineas = array_values($lineas);

        if ($lineas === [] || count($lineas) > self::MAX_LINEAS) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'Agrega entre 1 y '.self::MAX_LINEAS.' referencias para simular.');
        }

        $asesorId = (int) $actor->id;
        $mes = MesComercial::calendario($asesorId)->clave(now());
        $d = $this->umbral->distribucion($asesorId, $mes);
        $acuerdo = $this->acuerdos->vigenteEn($asesorId, now());
        $porcentaje = $acuerdo?->porcentaje;
        $limitaciones = [];

        if ($d['estado_umbral'] !== 'definido') {
            $limitaciones[] = $d['estado_umbral'];
        }

        if ($acuerdo === null || $porcentaje === null) {
            $limitaciones[] = 'sin_acuerdo';
        }

        $resueltas = array_map(fn ($l) => $this->resolver($l), $lineas);
        $unidades = array_sum(array_column($resueltas, 'cantidad'));
        $libres = $d['umbral'] === null ? null : max(0, $d['umbral'] - $d['acumulado']);
        $comisionables = $libres === null ? null : max(0, $unidades - $libres);

        foreach ($resueltas as $r) {
            $r['costo'] === null && $limitaciones[] = 'costo_sin_autoridad';
        }

        // Pedido mixto que cruza el umbral con bases distintas: el reparto proporcional es una propuesta NO aprobada.
        $bases = array_unique(array_map(fn ($r) => $r['costo'] === null ? 'sin' : (string) Dinero::maximo(Dinero::de($r['precio'])->minus(Dinero::de($r['costo'])), BigDecimal::zero()), $resueltas));
        $cruza = $libres !== null && $comisionables > 0 && $comisionables < $unidades;

        if ($cruza && count($resueltas) > 1 && count($bases) > 1) {
            $limitaciones[] = UmbralMensualService::POLITICA_PEDIDO_MIXTO;
        }

        $limitaciones = array_values(array_unique($limitaciones));
        $comision = null;

        if ($limitaciones === [] && $comisionables !== null) {
            $comision = $this->comision($resueltas, $comisionables, (string) $porcentaje);
        }

        return [
            'simulacion' => true,
            'mes' => $mes,
            'periodo' => MesComercial::periodo($asesorId, $mes)->aArreglo(),
            'umbral' => $d['umbral'],
            'computables_actuales' => $d['acumulado'],
            'unidades' => $unidades,
            'libres' => $libres,
            'comisionables' => $comisionables,
            'comision' => $comision === null ? null : Dinero::texto($comision),
            'limitaciones' => $limitaciones,
            'lineas' => array_map(fn ($r) => ['sku' => $r['sku'], 'nombre' => $r['nombre'], 'cantidad' => $r['cantidad'], 'precio_neto' => Dinero::texto(Dinero::de($r['precio']))], $resueltas),
        ];
    }

    /**
     * Comision de un pedido hipotetico SIN limitaciones. Con una sola referencia (o la misma base en todas) el reparto de las unidades
     * comisionables no cambia el monto; ya se descartó el caso mixto con bases distintas.
     *
     * @param  list<array<string, mixed>>  $lineas
     */
    protected function comision(array $lineas, int $comisionables, string $porcentaje): BigDecimal
    {
        $r = $lineas[0];
        $base = Dinero::maximo(Dinero::de($r['precio'])->minus(Dinero::de($r['costo'])), BigDecimal::zero());

        return Dinero::importe($base->multipliedBy($comisionables)->multipliedBy(Dinero::fraccion($porcentaje)));
    }

    /**
     * Valida una linea y la resuelve contra el catalogo: SKU exacto de un producto vendible (simple o variante), cantidad entera
     * positiva y precio neto unitario positivo.
     *
     * @param  array{sku?: mixed, cantidad?: mixed, precio_neto?: mixed}  $l
     * @return array{sku: string, nombre: string, cantidad: int, precio: string, costo: ?string}
     */
    protected function resolver(array $l): array
    {
        $sku = trim((string) ($l['sku'] ?? ''));
        $cantidad = (string) ($l['cantidad'] ?? '');
        $precio = trim((string) ($l['precio_neto'] ?? ''));

        if ($sku === '' || ! ctype_digit($cantidad) || (int) $cantidad < 1 || (int) $cantidad > 1000000) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'Cada línea necesita una referencia (SKU) y una cantidad entera mayor que 0.');
        }

        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $precio) || ! BigDecimal::of($precio)->isPositive()) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El precio neto de "'.$sku.'" debe ser un valor mayor que 0.');
        }

        $producto = DB::table('products')->where('sku', $sku)->first(['id', 'sku', 'type']);

        if ($producto === null) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'No existe la referencia "'.$sku.'". Usa el SKU exacto de la talla y color.');
        }

        if (! in_array($producto->type, ['simple', 'virtual'], true)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, '"'.$sku.'" agrupa varias tallas o colores: usa el SKU de la variante exacta.');
        }

        $nombre = DB::table('product_flat')->where('product_id', $producto->id)->whereNotNull('name')->value('name');
        $costo = $this->costos->costoVigenteDe((int) $producto->id);

        return [
            'sku' => (string) $producto->sku,
            'nombre' => (string) ($nombre ?? $producto->sku),
            'cantidad' => (int) $cantidad,
            'precio' => $precio,
            'costo' => $costo === null ? null : (string) $costo,
        ];
    }
}
