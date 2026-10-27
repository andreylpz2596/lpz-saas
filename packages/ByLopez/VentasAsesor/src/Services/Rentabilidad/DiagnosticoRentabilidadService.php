<?php

namespace ByLopez\VentasAsesor\Services\Rentabilidad;

use ByLopez\VentasAsesor\Models\Asesor;
use ByLopez\VentasAsesor\Models\CostoDirecto;
use ByLopez\VentasAsesor\Services\Comisiones\AcuerdosComisionService;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use ByLopez\VentasAsesor\Support\Costos\CostoComisionableCatalogo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * R1 — DIAGNOSTICO (solo lectura, nunca bloquea):
 *  - «Precios por grupo frente al minimo»: cada precio FIJO por grupo de cliente (`product_customer_group_prices`, con
 *    sus escalones de cantidad) y el precio base, contra el minimo de su referencia con la politica vigente, los costos
 *    directos POR UNIDAD configurados y la TASA DE REFERENCIA (mayor % de comision vigente) para que la lista sirva a
 *    cualquier asesor. Se agrupa por referencia + grupo + escalon + precio + costo (las variantes iguales se cuentan).
 *    Los costos por pedido (flete, % sobre el total) no se pueden atribuir a una lista: se evaluan en cada venta.
 *  - «Configuracion faltante»: lo que impide evaluar o habilitar.
 */
class DiagnosticoRentabilidadService
{
    public function __construct(
        protected EvaluadorRentabilidad $evaluador,
        protected ConfiguracionRentabilidadService $configuracion,
        protected AcuerdosComisionService $acuerdos,
        protected CostoComisionableCatalogo $catalogo,
        protected RentabilidadVentaService $venta,
    ) {}

    /**
     * @param  array{familia: ?int, grupo: ?int, solo_incumple: bool}  $filtros
     * @return array{filas: list<array<string, mixed>>, tasa: ?string, resumen: array<string, int>}
     */
    public function preciosPorGrupo(array $filtros): array
    {
        $ahora = CarbonImmutable::now();
        $tasa = $this->acuerdos->tasaDeReferencia($ahora);
        $politicas = $this->configuracion->politicasVigentes($ahora);
        $costos = $this->configuracion->costosDirectosVigentes($ahora)->filter(fn (CostoDirecto $c) => $c->metodo_envio === null && $c->metodo_pago === null)->values();
        $nombres = $this->configuracion->familias();
        $grupos = DB::table('customer_groups')->pluck('name', 'id');

        $variantes = DB::table('products')->where('type', 'simple')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $costosVar = $this->catalogo->costosDe($variantes);
        $familias = $this->venta->familiasDe($variantes);
        $telas = $this->venta->telasDe($variantes);
        $estados = $this->configuracion->estadosDeCosto(array_values(array_unique(array_filter($familias))), $ahora);

        $precios = DB::table('product_customer_group_prices')->where('value_type', 'fixed')
            ->when($filtros['grupo'] !== null, fn ($q) => $q->where('customer_group_id', $filtros['grupo']))
            ->get(['product_id', 'customer_group_id', 'qty', 'value']);
        $base = DB::table('product_flat')->whereIn('product_id', $variantes)->where('channel', core()->getDefaultChannelCode())
            ->where('locale', core()->getDefaultLocaleCodeFromDefaultChannel())->pluck('price', 'product_id');

        $entradas = [];
        $agregar = function (int $productId, string $grupo, int $qty, mixed $precio) use (&$entradas, $familias, $telas, $costosVar, $estados, $nombres, $filtros) {
            $familia = $familias[$productId] ?? null;

            if ($precio === null || ($filtros['familia'] !== null && $familia !== $filtros['familia'])) {
                return;
            }

            $costo = $costosVar[$productId] ?? null;
            $tela = $telas[$productId] ?? null;
            $clave = implode('|', [$familia, $tela, $grupo, $qty, (string) $precio, (string) $costo]);
            $entradas[$clave] ??= [
                'clave' => $clave, 'familia_id' => $familia, 'tipo_tela_id' => $tela, 'referencia' => $familia === null ? null : ($nombres[$familia] ?? null),
                'producto' => null, 'sku' => null, 'cantidad' => 1, 'precio_neto' => (string) $precio,
                'costo' => $costo === null ? null : (string) $costo,
                'estado_costo' => $costo === null ? 'sin_costo' : ($estados[$familia] ?? 'sin_estado'),
                'grupo' => $grupo, 'qty' => $qty, 'variantes' => 0,
            ];
            $entradas[$clave]['variantes']++;
        };

        foreach ($precios as $p) {
            $agregar((int) $p->product_id, (string) ($grupos[$p->customer_group_id] ?? $p->customer_group_id), (int) $p->qty, $p->value);
        }

        if ($filtros['grupo'] === null) {
            foreach ($base as $id => $precio) {
                $agregar((int) $id, (string) trans('ventasasesor::comisiones.diagnostico.precio-base'), 1, $precio);
            }
        }

        $r = $this->evaluador->evaluar(array_values($entradas), $politicas, $costos, [
            'tasa' => $tasa, 'origen_tasa' => 'referencia', 'metodo_envio' => null, 'metodo_pago' => null,
            'total_cobrado' => '0', 'valor_envio' => '0', 'aplica' => true,
        ]);

        $filas = [];
        $resumen = ['cumple' => 0, 'incumple' => 0, 'incompleta' => 0, 'sin_politica' => 0];

        foreach ($r['lineas'] as $i => $l) {
            $e = array_values($entradas)[$i];
            $resumen[$l['resultado']] = ($resumen[$l['resultado']] ?? 0) + 1;

            if ($filtros['solo_incumple'] && $l['resultado'] === EvaluadorRentabilidad::CUMPLE) {
                continue;
            }

            $filas[] = $l + ['grupo' => $e['grupo'], 'qty' => $e['qty'], 'variantes' => $e['variantes'],
                'diferencia' => $l['precio_minimo'] === null ? null : Dinero::texto(Dinero::de($l['precio_neto'])->minus(Dinero::de($l['precio_minimo'])))];
        }

        usort($filas, fn ($a, $b) => [$a['resultado'] === 'cumple', $a['referencia'], $a['grupo'], $a['qty']] <=> [$b['resultado'] === 'cumple', $b['referencia'], $b['grupo'], $b['qty']]);

        return ['filas' => $filas, 'tasa' => $tasa, 'resumen' => $resumen];
    }

    /** @return list<array{nivel: string, clave: string, detalle: string}> */
    public function faltantes(): array
    {
        $ahora = CarbonImmutable::now();
        $salida = [];
        $agregar = function (string $nivel, string $clave, array $params = []) use (&$salida) {
            $salida[] = ['nivel' => $nivel, 'clave' => $clave, 'detalle' => (string) trans('ventasasesor::comisiones.faltantes.'.$clave, $params)];
        };

        if (empty(config('ventasasesor.comisiones.fecha_inicio'))) {
            $agregar('bloquea', 'fecha-inicio');
        }

        if (! config('ventasasesor.rentabilidad.bloqueo_activo')) {
            $agregar('info', 'bloqueo-inactivo');
        }

        $politicas = $this->configuracion->politicasVigentes($ahora);

        if (! isset($politicas[EvaluadorRentabilidad::clavePolitica(null, null)])) {
            $agregar('bloquea', 'politica-defecto');
        }

        foreach (Asesor::query()->where('activo', true)->orderBy('nombre')->get() as $a) {
            $acuerdo = $this->acuerdos->vigenteEn((int) $a->admin_id, $ahora);

            if ($acuerdo === null) {
                $agregar('bloquea', 'asesor-sin-acuerdo', ['asesor' => $a->nombre]);
            } elseif (! $acuerdo->umbralDefinido()) {
                $agregar('bloquea', 'asesor-sin-umbral', ['asesor' => $a->nombre]);
            }
        }

        $familiasVendidas = DB::table('bylopez_va_costos_venta as c')->join('products as p', 'p.id', '=', 'c.product_id')
            ->distinct()->pluck('p.attribute_family_id')->filter()->map(fn ($id) => (int) $id)->all();
        $estados = $this->configuracion->estadosDeCosto($familiasVendidas, $ahora);
        $nombres = $this->configuracion->familias();

        foreach ($estados as $familia => $estado) {
            if (in_array($estado, ['sin_estado', 'vencido'], true)) {
                $agregar('advierte', 'costo-'.str_replace('_', '-', $estado), ['referencia' => $nombres[$familia] ?? $familia]);
            }
        }

        $variantes = DB::table('products')->where('type', 'simple')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sinCosto = count($variantes) - count($this->catalogo->costosDe($variantes));

        if ($sinCosto > 0) {
            $agregar('advierte', 'variantes-sin-costo', ['cantidad' => $sinCosto]);
        }

        if ($this->configuracion->costosDirectosVigentes($ahora)->isEmpty()) {
            $agregar('advierte', 'sin-costos-directos');
        }

        return $salida;
    }
}
