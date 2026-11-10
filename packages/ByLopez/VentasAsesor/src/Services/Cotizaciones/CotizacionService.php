<?php

namespace ByLopez\VentasAsesor\Services\Cotizaciones;

use ByLopez\VentasAsesor\DataObjects\EstadoNuevaVenta;
use ByLopez\VentasAsesor\DataObjects\LineaBorradorVenta;
use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta;
use ByLopez\VentasAsesor\Exceptions\CotizacionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\Cotizacion;
use ByLopez\VentasAsesor\Services\Venta\NuevaVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cotizaciones\AjustesCotizacion;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\Admin;

/**
 * Cotizaciones de una venta en curso. NO crea pedido, NO reserva stock y NO toca el carrito: lee el estado que ya calculo Bagisto
 * (NuevaVentaService::estado) y guarda una foto. El total cotizado es el de los PRODUCTOS: el domicilio se cobra por aparte
 * (a lo sumo se muestra una estimacion), asi que se resta del gran total del carrito.
 *
 * Una cotizacion no se edita: si la venta cambia (lineas, cliente, descuento, entrega) o el administrador cambia los datos de
 * pago, la siguiente cotizacion es la version + 1. Pedirla dos veces sin cambios devuelve la misma mientras siga vigente.
 */
class CotizacionService
{
    public const VIGENTE = 'vigente';

    public const VENCIDA = 'vencida';

    public const CONVERTIDA = 'convertida';

    public const REEMPLAZADA = 'reemplazada';

    public function __construct(
        protected NuevaVentaService $nuevaVenta,
        protected AutorizacionVentasAsesor $autorizacion,
        protected AjustesCotizacion $ajustes,
    ) {}

    public function crear(int $borradorId, Admin $actor): Cotizacion
    {
        $borrador = BorradorVenta::query()->find($borradorId) ?? throw new Invalida(Invalida::BORRADOR_INEXISTENTE);

        $this->exigirAcceso((int) $borrador->propietario_admin_id, $actor);

        if ($borrador->estado !== EstadoBorradorVenta::ACTIVO) {
            throw new Invalida(Invalida::BORRADOR_NO_ACTIVO);
        }

        $estado = $this->nuevaVenta->estado($borradorId);
        $cliente = $estado->borrador->cliente ?? throw new Invalida(Invalida::SIN_CLIENTE);

        if ($estado->lineas === []) {
            throw new Invalida(Invalida::SIN_LINEAS);
        }

        $datos = [
            'customer_id' => (int) $cliente['customer_id'],
            'cliente' => [
                'nombre' => trim($cliente['nombre'].' '.$cliente['apellido']),
                'telefono' => $cliente['telefono_principal'] ?? null,
                'email' => $cliente['email'] ?? null,
            ],
            'lineas' => $this->lineas($estado->lineas),
            'totales' => $this->totales($estado),
            'entrega' => $this->entrega($estado),
            'datos_pago' => ['medios' => $this->ajustes->mediosDePago()],
        ];

        $huella = hash('sha256', json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($borradorId, $actor, $borrador, $datos, $huella) {
            // Serializa las versiones de ESTA venta: dos clics a la vez no pueden crear la misma version.
            BorradorVenta::query()->whereKey($borradorId)->lockForUpdate()->first();

            $ultima = Cotizacion::query()->where('borrador_id', $borradorId)->orderByDesc('version')->first();

            if ($ultima !== null && $ultima->huella === $huella && ! $ultima->valida_hasta->copy()->endOfDay()->isPast()) {
                return $ultima;
            }

            return Cotizacion::query()->create($datos + [
                'borrador_id' => $borradorId,
                'version' => ($ultima?->version ?? 0) + 1,
                'valida_hasta' => now()->addDays($this->ajustes->vigenciaDias())->toDateString(),
                'huella' => $huella,
                'creada_por_admin_id' => (int) $actor->id,
                'creada_por_nombre' => $actor->name,
                'propietario_admin_id' => (int) $borrador->propietario_admin_id,
            ]);
        });
    }

    public function obtener(int $cotizacionId, Admin $actor): Cotizacion
    {
        $cotizacion = Cotizacion::query()->find($cotizacionId) ?? throw new Invalida(Invalida::COTIZACION_INEXISTENTE);

        $this->exigirAcceso((int) $cotizacion->propietario_admin_id, $actor);

        return $cotizacion;
    }

    /** Cotizaciones de una venta, la mas nueva primero (para «Ver cotizacion» en Ventas en curso). */
    public function deLaVenta(int $borradorId, Admin $actor): array
    {
        $borrador = BorradorVenta::query()->find($borradorId) ?? throw new Invalida(Invalida::BORRADOR_INEXISTENTE);

        $this->exigirAcceso((int) $borrador->propietario_admin_id, $actor);

        $filas = Cotizacion::query()->where('borrador_id', $borradorId)->orderByDesc('version')->get();
        $maxima = (int) $filas->max('version');

        return $filas->map(fn (Cotizacion $c) => $this->resumen($c, $borrador, $maxima) + ['texto' => $this->textoParaCompartir($c)])->all();
    }

    /** @return array{id: int, numero: string, version: int, estado: string, valida_hasta: string, total: float} */
    public function resumen(Cotizacion $c, ?BorradorVenta $borrador = null, ?int $versionMaxima = null): array
    {
        return [
            'id' => (int) $c->id,
            'numero' => $c->numero(),
            'version' => (int) $c->version,
            'estado' => $this->estadoDe($c, $borrador, $versionMaxima),
            'valida_hasta' => $c->valida_hasta->toDateString(),
            'total' => (float) $c->totales['total_productos'],
        ];
    }

    public function estadoDe(Cotizacion $c, ?BorradorVenta $borrador = null, ?int $versionMaxima = null): string
    {
        $borrador ??= $c->borrador;
        $versionMaxima ??= (int) Cotizacion::query()->where('borrador_id', $c->borrador_id)->max('version');

        return match (true) {
            $borrador?->order_id !== null => self::CONVERTIDA,
            (int) $c->version < $versionMaxima => self::REEMPLAZADA,
            $c->valida_hasta->copy()->endOfDay()->isPast() => self::VENCIDA,
            default => self::VIGENTE,
        };
    }

    /**
     * Lineas del PDF: una fila por producto y color, con las tallas juntas («S×2, M×4»). La foto guarda las lineas sueltas por variante.
     *
     * @return list<array{ref: string, producto: string, detalle: string, precio: float, cantidad: int, subtotal: float}>
     */
    public function filasPdf(Cotizacion $c): array
    {
        $grupos = [];

        foreach ($c->lineas as $l) {
            $clave = $l['producto_id'].'|'.($l['color'] ?? '').'|'.$l['precio_unitario'];
            $grupos[$clave] ??= ['ref' => $l['referencia'], 'producto' => $l['nombre'], 'color' => $l['color'] ?? null, 'tallas' => [], 'precio' => $l['precio_unitario'], 'cantidad' => 0, 'subtotal' => 0.0];
            $grupos[$clave]['cantidad'] += $l['cantidad'];
            $grupos[$clave]['subtotal'] += $l['subtotal'];

            if (($l['talla'] ?? '') !== '') {
                $grupos[$clave]['tallas'][] = $l['talla'].'×'.$l['cantidad'];
            }
        }

        return array_values(array_map(fn ($g) => [
            'ref' => (string) $g['ref'],
            'producto' => (string) $g['producto'],
            'detalle' => implode(' · ', array_filter([$g['color'] ? 'Color '.$g['color'] : null, $g['tallas'] ? 'Tallas: '.implode(', ', $g['tallas']) : null])),
            'precio' => (float) $g['precio'],
            'cantidad' => (int) $g['cantidad'],
            'subtotal' => (float) $g['subtotal'],
        ], $grupos));
    }

    /** Texto corto para pegar en WhatsApp junto al PDF. Sin enlaces: el PDF del panel exige sesion. */
    public function textoParaCompartir(Cotizacion $c): string
    {
        $filas = array_map(
            fn ($f) => '• '.$f['cantidad'].' × '.$f['producto'].($f['detalle'] !== '' ? ' ('.$f['detalle'].')' : ''),
            $this->filasPdf($c),
        );

        $primerNombre = explode(' ', (string) $c->cliente['nombre'])[0] ?? '';
        $encabezado = $this->ajustes->encabezado()['nombre'];

        return implode("\n", array_filter([
            'Hola '.$primerNombre.', esta es tu cotización '.$c->numero().' de '.$encabezado.':',
            '',
            ...$filas,
            '',
            'Total productos: '.self::dinero((float) $c->totales['total_productos']).(($c->entrega['cobra_domicilio'] ?? false) ? ' (el domicilio se cobra por aparte)' : ''),
            'Válida hasta el '.$c->valida_hasta->format('d/m/Y').'.',
        ], fn ($l) => $l !== null));
    }

    public static function dinero(float $valor): string
    {
        return '$ '.number_format($valor, 0, ',', '.');
    }

    /** @param  list<LineaBorradorVenta>  $lineas */
    protected function lineas(array $lineas): array
    {
        $codigoColor = (string) config('ventasasesor.nueva_venta.atributos.color', 'color');

        return array_map(function (LineaBorradorVenta $l) use ($codigoColor) {
            $color = $l->opciones[$codigoColor]['etiqueta'] ?? null;
            $talla = collect($l->opciones)->except($codigoColor)->pluck('etiqueta')->filter()->implode(' · ');

            return [
                'producto_id' => $l->productoId,
                'variante_id' => $l->varianteId,
                'referencia' => $l->sku,
                'nombre' => $l->nombre,
                'color' => $color,
                'talla' => $talla,
                'cantidad' => $l->cantidad,
                'precio_unitario' => $l->precioUnitario,
                'subtotal' => $l->subtotal,
                'descuento' => $l->descuento,
            ];
        }, $lineas);
    }

    /** El total cotizado excluye el envio: grand_total del carrito menos shipping_amount. */
    protected function totales(EstadoNuevaVenta $estado): array
    {
        $t = $estado->borrador->totales ?? [];
        $envio = (float) ($t['shipping_amount'] ?? 0);

        return [
            'subtotal' => (float) ($t['sub_total'] ?? 0),
            'descuento' => (float) ($t['discount_amount'] ?? 0),
            'impuesto' => (float) ($t['tax_total'] ?? 0),
            'total_productos' => round((float) ($t['grand_total'] ?? 0) - $envio, 2),
            'domicilio_estimado' => $envio > 0 ? $envio : null,
        ];
    }

    protected function entrega(EstadoNuevaVenta $estado): array
    {
        $e = $estado->entrega;
        $envio = $e->envio;
        $recogida = $envio?->origen === 'punto_recogida';

        return [
            'recogida' => $recogida,
            'cobra_domicilio' => $e->requiereEnvio && ! $recogida,
            'metodo' => $e->metodoSeleccionado?->titulo,
            'destino' => $envio ? implode(' · ', array_filter([$envio->lineas[0] ?? null, $envio->ciudad, $envio->departamento])) : null,
        ];
    }

    protected function exigirAcceso(int $propietarioId, Admin $actor): void
    {
        if ($propietarioId === (int) $actor->id) {
            return;
        }

        if (! $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_PEDIDOS_VER_TODOS)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }
    }
}
