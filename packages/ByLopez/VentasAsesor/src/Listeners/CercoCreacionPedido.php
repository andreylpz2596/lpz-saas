<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta as Estado;
use ByLopez\VentasAsesor\Exceptions\PedidoRechazadoPorCercoException as Rechazo;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Support\Venta\CercoCupoCartera;
use ByLopez\VentasAsesor\Support\Venta\CercoInventario;
use ByLopez\VentasAsesor\Support\Venta\ConfirmacionEnCurso;
use Illuminate\Support\Facades\Schema;

/**
 * Cerco de creacion de pedidos (03F): primer y unico listener de
 * `checkout.order.save.before`, dentro de la transaccion de
 * OrderRepository::createOrderIfNotThenRetry() y ANTES de insertar el pedido, sus items,
 * la reserva, la factura o cualquier correo. Si lanza, el repositorio revierte todo y
 * reintenta; cada reintento vuelve a pasar por aqui y falla igual.
 *
 * Orden fijo (el de los bloqueos en todo el modulo: borrador antes que inventario):
 *
 * 1. D-55, solo si el pedido es una confirmacion ByLopez (ConfirmacionEnCurso): la fila del
 *    borrador con el token vigente, `confirmando` y sin pedido, bajo FOR UPDATE. Si no
 *    existe, el intento es obsoleto. La fila queda bloqueada hasta el commit o rollback del
 *    pedido: una recuperacion concurrente espera.
 * 2. D-54, para TODO pedido: bloqueo de las filas `products` y revalidacion del stock.
 *    Doc. 35 (cupo de cartera, solo con `ventasasesor.cupo.activo`): entre el bloqueo de products y la revalidacion se toma el
 *    bloqueo de la fila del cliente (`customers`), y despues de la revalidacion se decide el cupo con la exposicion recalculada.
 * 3. Sin confirmacion ByLopez: si el carrito pertenece a un borrador `activo` o
 *    `confirmando`, se rechaza (la ruta nativa del panel saltaria la idempotencia). Los
 *    carritos normales de la tienda y del panel no tienen borrador y pasan.
 *
 * Los pasos 1 y 2 hacen solo lecturas bloqueantes antes del FOR UPDATE de `products` (ver
 * CercoInventario). El paso 3 va despues a proposito.
 */
class CercoCreacionPedido
{
    public const EVENTO = 'checkout.order.save.before';

    /** Existencia de la tabla de borradores: solo cambia al migrar (se consulta una vez por proceso). */
    protected static ?bool $tablaDeBorradores = null;

    public function __construct(
        protected ConfirmacionEnCurso $confirmaciones,
        protected CercoInventario $inventario,
        protected CercoCupoCartera $cupo,
    ) {}

    public function handle(mixed $pedido): void
    {
        $pedido = is_array($pedido) ? $pedido : [];
        $cartId = (int) ($pedido['cart_id'] ?? 0);
        $intento = $cartId > 0 ? $this->confirmaciones->para($cartId) : null;

        if ($intento !== null) {
            $this->exigirIntentoVigente($cartId, $intento['borrador_id'], $intento['token']);
        }

        $this->inventario->bloquear($pedido);
        // Doc. 35: el bloqueo del cliente va tras el de products y ANTES de la primera lectura no bloqueante (la de validar()), para
        // que la instantanea de la transaccion nazca con el bloqueo ya tomado. Solo actua con `ventasasesor.cupo.activo`.
        $this->cupo->bloquear($pedido);
        $this->inventario->validar($pedido);
        $this->cupo->exigir($pedido);

        if ($intento === null && $cartId > 0) {
            $this->rechazarCarritoDeBorrador($cartId);
        }
    }

    protected function exigirIntentoVigente(int $cartId, int $borradorId, string $token): void
    {
        $vigente = BorradorVenta::query()
            ->whereKey($borradorId)
            ->where('cart_id', $cartId)
            ->where('estado', Estado::CONFIRMANDO->value)
            ->where('confirmation_token', $token)
            ->whereNull('order_id')
            ->lockForUpdate()
            ->value('id') !== null; // FOR UPDATE de nivel superior sobre la fila del borrador

        if (! $vigente) {
            throw new Rechazo(Rechazo::CONFIRMACION_OBSOLETA, ['borrador_id' => $borradorId]);
        }
    }

    protected function rechazarCarritoDeBorrador(int $cartId): void
    {
        /*
         * Este listener corre en TODO pedido. En un entorno sin las migraciones de borradores
         * (000006+; p. ej. lpz_saas hoy) no puede existir un carrito de borrador: se omite la
         * comprobacion en vez de romper la tienda y el panel. El cerco de inventario (paso 2)
         * no depende de tablas propias y sigue activo. Una confirmacion ByLopez sin la tabla
         * falla antes, en su paso A.
         */
        if (! $this->hayBorradores()) {
            return;
        }

        $borradorId = BorradorVenta::query()
            ->where('cart_id', $cartId)
            ->whereIn('estado', [Estado::ACTIVO->value, Estado::CONFIRMANDO->value])
            ->value('id');

        if ($borradorId !== null) {
            throw new Rechazo(Rechazo::CARRITO_DE_BORRADOR, ['borrador_id' => (int) $borradorId]);
        }
    }

    protected function hayBorradores(): bool
    {
        return self::$tablaDeBorradores ??= Schema::hasTable((new BorradorVenta)->getTable());
    }
}
