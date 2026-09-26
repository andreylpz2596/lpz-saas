<?php

namespace ByLopez\VentasAsesor\Services\Venta;

use ByLopez\VentasAsesor\DataObjects\DesgloseAbastecimiento;
use ByLopez\VentasAsesor\DataObjects\SolicitudBorradorVenta;
use ByLopez\VentasAsesor\DataObjects\VistaSolicitudesBorrador;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Exceptions\SolicitudBorradorInvalidaException as SolicitudInvalida;
use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException as VarianteInvalida;
use ByLopez\VentasAsesor\Models\SolicitudBorrador;
use ByLopez\VentasAsesor\Support\Venta\CantidadVenta;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Contracts\Product;
use Webkul\User\Contracts\Admin;

/**
 * Solicitudes comerciales del borrador (03C, D-39): lo que el cliente QUIERE, separado de
 * lo que el Cart de Bagisto puede procesar hoy (LineasBorradorService).
 *
 * - Crear o cambiar una solicitud NUNCA toca el Cart: pedir 100 con 30 vendibles guarda
 *   100 y deja el carrito como estaba. Llevar unidades al carrito es una accion explicita
 *   aparte (LineasBorradorService::agregar), limitada por la solicitud (I-13).
 * - Una solicitud por variante y borrador (UNIQUE): la misma granularidad con que Bagisto
 *   consolida lineas.
 * - Variante verificada por CatalogoVentaService (I-5) y cliente con grupo comercial apto.
 * - Nada se persiste salvo la cantidad: SKU, nombre, stock y precio se leen en tiempo real
 *   y el precio sale de Bagisto con RT-03.
 * - Concurrencia: toda mutacion bloquea la fila del borrador (el mismo punto que las
 *   lineas, el reclamo y el descarte); el UNIQUE resuelve la carrera de dos altas.
 */
class SolicitudBorradorService
{
    public function __construct(
        protected BorradorVentaService $borradores,
        protected CatalogoVentaService $catalogo,
        protected LineasBorradorService $lineas,
    ) {}

    public function listar(int $borradorId): VistaSolicitudesBorrador
    {
        $vista = $this->borradores->obtener($borradorId);
        $customerId = $vista->cliente['customer_id'] ?? null;

        $solicitudes = SolicitudBorrador::query()
            ->with('variante')
            ->where('borrador_id', $borradorId)
            ->orderBy('id')
            ->get()
            ->map(fn (SolicitudBorrador $s) => $this->aSolicitud($s, $vista->cartId, $customerId))
            ->values()
            ->all();

        return new VistaSolicitudesBorrador($vista, $solicitudes);
    }

    /** Registra cuanto quiere el cliente de una variante. No agrega nada al carrito. */
    public function crear(int $borradorId, int $productoId, int $varianteId, mixed $cantidad, Admin $actor): VistaSolicitudesBorrador
    {
        $cantidad = CantidadVenta::normalizar($cantidad);

        try {
            DB::transaction(function () use ($borradorId, $productoId, $varianteId, $cantidad, $actor) {
                [$borrador, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
                $variante = $this->catalogo->variante($productoId, $varianteId, (int) $carrito->customer_id, $cantidad);

                if (! $variante->activa) {
                    throw new VarianteInvalida(VarianteInvalida::PRODUCTO_NO_VENDIBLE);
                }

                if (SolicitudBorrador::query()->where('borrador_id', $borrador->id)->where('variante_id', $variante->productId)->exists()) {
                    throw new SolicitudInvalida(SolicitudInvalida::YA_EXISTE);
                }

                SolicitudBorrador::query()->create([
                    'borrador_id' => $borrador->id,
                    'variante_id' => $variante->productId,
                    'cantidad_solicitada' => $cantidad,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new SolicitudInvalida(SolicitudInvalida::YA_EXISTE);
        }

        return $this->listar($borradorId);
    }

    /**
     * Fija (no suma) la cantidad solicitada: repetir la misma orden no cambia nada. No
     * puede quedar por debajo de lo que ya esta en el carrito (primero se retira de alli).
     */
    public function cambiarCantidad(int $borradorId, int $solicitudId, mixed $cantidad, Admin $actor): VistaSolicitudesBorrador
    {
        $cantidad = CantidadVenta::normalizar($cantidad);

        DB::transaction(function () use ($borradorId, $solicitudId, $cantidad, $actor) {
            [$borrador, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
            $solicitud = $this->solicitudDelBorrador((int) $borrador->id, $solicitudId);
            $variante = $solicitud->variante;

            // Variante aun vendible y cliente con grupo apto.
            $v = $this->catalogo->variante($this->productoDe($variante), (int) $variante->id, (int) $carrito->customer_id, $cantidad);

            if (! $v->activa) {
                throw new VarianteInvalida(VarianteInvalida::PRODUCTO_NO_VENDIBLE);
            }

            if ($cantidad < $this->lineas->cantidadEnCarrito((int) $carrito->id, (int) $variante->id)) {
                throw new SolicitudInvalida(SolicitudInvalida::MENOR_QUE_CARRITO);
            }

            $solicitud->update(['cantidad_solicitada' => $cantidad]);
        });

        return $this->listar($borradorId);
    }

    /** Quita la solicitud. Si el carrito tiene unidades de esa variante, primero se retiran. */
    public function eliminar(int $borradorId, int $solicitudId, Admin $actor): VistaSolicitudesBorrador
    {
        DB::transaction(function () use ($borradorId, $solicitudId, $actor) {
            [$borrador, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
            $solicitud = $this->solicitudDelBorrador((int) $borrador->id, $solicitudId);

            if ($this->lineas->cantidadEnCarrito((int) $carrito->id, $solicitud->variante_id) > 0) {
                throw new SolicitudInvalida(SolicitudInvalida::EN_CARRITO);
            }

            $solicitud->delete();
        });

        return $this->listar($borradorId);
    }

    protected function solicitudDelBorrador(int $borradorId, int $solicitudId): SolicitudBorrador
    {
        $solicitud = SolicitudBorrador::query()->with('variante')->where('borrador_id', $borradorId)->find($solicitudId);

        if (! $solicitud) {
            throw new SolicitudInvalida(SolicitudInvalida::INEXISTENTE);
        }

        return $solicitud;
    }

    /** Producto de primer nivel: el padre del hijo, o el propio simple. */
    protected function productoDe(Product $variante): int
    {
        return (int) ($variante->parent_id ?? $variante->id);
    }

    private function aSolicitud(SolicitudBorrador $solicitud, ?int $cartId, ?int $customerId): SolicitudBorradorVenta
    {
        $variante = $solicitud->variante;
        $productoId = $this->productoDe($variante);
        $cantidad = $solicitud->cantidad_solicitada;
        $gestionaStock = (bool) $variante->manage_stock;

        // Vendible HOY: producto de primer nivel activo, hijo verificado y activo.
        try {
            $this->catalogo->hijoVerificado($this->catalogo->productoVendible($productoId), (int) $variante->id);
            $vendible = (bool) $variante->status;
        } catch (VarianteInvalida) {
            $vendible = false;
        }

        // D-36: lo que Bagisto no vende no esta disponible, aunque su indice tenga unidades.
        $stock = $vendible ? (int) $variante->getTypeInstance()->totalQuantity() : 0;

        $opciones = [];
        $precio = null;

        if ($vendible && $customerId !== null) {
            try {
                $v = $this->catalogo->variante($productoId, (int) $variante->id, $customerId, $cantidad);
                $opciones = $v->opciones;
                $precio = $v->precioUnitario;
            } catch (GrupoComercialInvalidoException) {
                // Sin grupo apto no hay precio comercial; la vista del borrador lo avisa.
            }
        }

        $enCarrito = $this->lineas->cantidadEnCarrito($cartId, (int) $variante->id);

        return new SolicitudBorradorVenta(
            solicitudId: (int) $solicitud->id,
            productoId: $productoId,
            varianteId: (int) $variante->id,
            sku: (string) $variante->sku,
            nombre: (string) $variante->name,
            opciones: $opciones,
            vendible: $vendible,
            cantidadSolicitada: $cantidad,
            cantidadEnCarrito: $enCarrito,
            gestionaStock: $gestionaStock,
            stockVendible: $stock,
            abastecimiento: DesgloseAbastecimiento::desdeStock($cantidad, $stock, $gestionaStock || ! $vendible),
            excesoCarritoSobreStock: ($gestionaStock || ! $vendible) ? max(0, $enCarrito - $stock) : 0,
            precioUnitarioReferencia: $precio,
        );
    }
}
