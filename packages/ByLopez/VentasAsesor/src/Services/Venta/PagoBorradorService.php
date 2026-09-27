<?php

namespace ByLopez\VentasAsesor\Services\Venta;

use ByLopez\VentasAsesor\DataObjects\MetodoPagoDisponible;
use ByLopez\VentasAsesor\DataObjects\VistaPagoBorrador;
use ByLopez\VentasAsesor\Exceptions\PagoBorradorInvalidoException as PagoInvalido;
use ByLopez\VentasAsesor\Support\Venta\CarritoComercial;
use Webkul\Checkout\Contracts\Cart as CartContract;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\CartProxy;
use Webkul\Payment\Facades\Payment;
use Webkul\User\Contracts\Admin;

/**
 * Metodo de pago del borrador (03E), sobre el Cart REAL de Bagisto:
 *
 * - Fuente de metodos: Payment::getPaymentMethods() (unica fuente; sin catalogo propio).
 *   Un carrier nuevo de Bagisto aparece solo si tambien esta en la allowlist (D-49).
 * - Ofrecido = allowlist de VentasAsesor (config `ventasasesor.pagos.metodos_permitidos`)
 *   INTERSECTADA con lo disponible AHORA segun Bagisto. Ninguna de las dos por separado.
 * - Seleccion: Cart::savePaymentMethod(), que Bagisto NO valida contra disponibilidad
 *   (guarda cualquier texto). Por eso esta clase valida el codigo ANTES de llamarlo.
 * - NO registra dinero: no crea Transaction ni toca Invoice u Order (D-50, D-53 - fuera
 *   de 03E). `estadoInicialEsperado` es solo informativo, leido de la configuracion
 *   VIGENTE de Bagisto para ese metodo (D-48 sigue pendiente de desplegarse).
 * - No cambia precio, stock ni solicitud: no llama a collectTotals ni reglas propias.
 * - Concurrencia: bloqueo de borrador y carrito (bloquearParaEditar), RT-03 y grupo
 *   comercial apto, igual que 03D.
 */
class PagoBorradorService
{
    public function __construct(
        protected BorradorVentaService $borradores,
        protected CarritoComercial $carritos,
    ) {}

    public function vista(int $borradorId): VistaPagoBorrador
    {
        $vista = $this->borradores->obtener($borradorId);
        $carrito = $vista->cartId === null ? null : CartProxy::modelClass()::query()->with('payment')->find($vista->cartId);

        $ofrecidos = $this->metodosOfrecidos($carrito);
        $seleccionado = null;

        if ($carrito?->payment) {
            $seleccionado = collect($ofrecidos)->firstWhere('codigo', $carrito->payment->method)
                ?? $this->aMetodo((string) $carrito->payment->method, (string) $carrito->payment->method_title);
        }

        return new VistaPagoBorrador($vista, $ofrecidos, $seleccionado);
    }

    /** Guarda el metodo en el Cart. Rechaza si no esta ofrecido: ni inventado ni fuera de la allowlist. */
    public function seleccionar(int $borradorId, string $metodo, Admin $actor): VistaPagoBorrador
    {
        $metodo = trim($metodo);

        [$borrador, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
        $customerId = (int) $carrito->customer_id;
        $this->carritos->exigirGrupoApto($customerId);

        $this->carritos->operar($carrito, $customerId, function () use ($metodo) {
            $disponibles = collect($this->ofrecidosConCartYaFijado())->pluck('codigo');

            if (! $disponibles->contains($metodo)) {
                throw new PagoInvalido(
                    $this->existeEnBagisto($metodo) ? PagoInvalido::METODO_NO_DISPONIBLE : PagoInvalido::METODO_INEXISTENTE
                );
            }

            Cart::savePaymentMethod(['method' => $metodo]);
        });

        return $this->vista((int) $borrador->id);
    }

    /**
     * Wrapper de un solo uso: fija el Cart del facade (setCart/resetCart) para calcular
     * los metodos ofrecidos. NO anidar dentro de otro CarritoComercial::operar: su
     * finally resetearia el Cart del facade antes de que el llamador externo pueda usarlo
     * (p. ej. Cart::savePaymentMethod() en seleccionar()).
     *
     * @return list<MetodoPagoDisponible>
     */
    protected function metodosOfrecidos(?CartContract $carrito): array
    {
        if (! $carrito) {
            return [];
        }

        return $this->carritos->operar($carrito, (int) $carrito->customer_id,
            fn () => $this->ofrecidosConCartYaFijado());
    }

    /**
     * Igual que metodosOfrecidos, pero asume que el Cart del facade YA esta fijado por el
     * llamador (dentro de un CarritoComercial::operar en curso).
     *
     * isAvailable() de cada metodo lee Cart::getCart() (p. ej. cashondelivery exige
     * hasOnlyStockableItems), de ahi la dependencia del Cart ya fijado.
     *
     * @return list<MetodoPagoDisponible>
     */
    protected function ofrecidosConCartYaFijado(): array
    {
        $permitidos = array_map('strval', (array) config('ventasasesor.pagos.metodos_permitidos', []));

        return collect(Payment::getPaymentMethods())
            ->filter(fn (array $m) => in_array($m['method'], $permitidos, true))
            ->map(fn (array $m) => $this->aMetodo($m['method'], $m['method_title'], $m['description'] ?? null))
            ->values()
            ->all();
    }

    protected function aMetodo(string $codigo, string $titulo, ?string $descripcion = null): MetodoPagoDisponible
    {
        return new MetodoPagoDisponible($codigo, $titulo, $descripcion, $this->estadoInicialEsperado($codigo));
    }

    /**
     * Informativo: deriva de la configuracion VIGENTE de Bagisto para ese metodo, sin
     * cambiarla ni suponer D-48 desplegado.
     */
    protected function estadoInicialEsperado(string $codigo): string
    {
        if (! core()->getConfigData("sales.payment_methods.$codigo.generate_invoice")) {
            return MetodoPagoDisponible::ESTADO_SIN_FACTURA_AUTOMATICA;
        }

        $estadoFactura = core()->getConfigData("sales.payment_methods.$codigo.invoice_status");

        return $estadoFactura === 'paid'
            ? MetodoPagoDisponible::ESTADO_PAGO_CONFIRMADO
            : MetodoPagoDisponible::ESTADO_PAGO_PENDIENTE;
    }

    /** Si Bagisto registra ese codigo (para distinguir inexistente de no disponible ahora). */
    protected function existeEnBagisto(string $metodo): bool
    {
        return array_key_exists($metodo, config('payment_methods', []));
    }
}
