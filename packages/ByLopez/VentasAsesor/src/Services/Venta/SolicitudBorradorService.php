<?php

namespace ByLopez\VentasAsesor\Services\Venta;

use ByLopez\VentasAsesor\DataObjects\DesgloseAbastecimiento;
use ByLopez\VentasAsesor\DataObjects\SolicitudBorradorVenta;
use ByLopez\VentasAsesor\DataObjects\VarianteVenta;
use ByLopez\VentasAsesor\DataObjects\VistaBorradorVenta;
use ByLopez\VentasAsesor\DataObjects\VistaSolicitudesBorrador;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Exceptions\SolicitudBorradorInvalidaException as SolicitudInvalida;
use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException as VarianteInvalida;
use ByLopez\VentasAsesor\Models\SolicitudBorrador;
use ByLopez\VentasAsesor\Support\Venta\CantidadVenta;
use ByLopez\VentasAsesor\Support\Venta\LoteCatalogo;
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

    /**
     * Con $lote (03G-B2) las lecturas de catalogo se hacen una vez por producto y no por
     * solicitud; $vista reutiliza la vista del borrador ya leida en la misma operacion. El
     * resultado es el mismo que sin ellos.
     */
    public function listar(int $borradorId, ?LoteCatalogo $lote = null, ?VistaBorradorVenta $vista = null): VistaSolicitudesBorrador
    {
        $vista = $vista !== null && $vista->id === $borradorId ? $vista : $this->borradores->obtener($borradorId);
        $customerId = $vista->cliente['customer_id'] ?? null;

        $solicitudes = SolicitudBorrador::query()
            ->with('variante')
            ->where('borrador_id', $borradorId)
            ->orderBy('id')
            ->get()
            ->map(fn (SolicitudBorrador $s) => $this->aSolicitud($s, $vista->cartId, $customerId, $lote))
            ->values()
            ->all();

        return new VistaSolicitudesBorrador($vista, $solicitudes);
    }

    /** Registra cuanto quiere el cliente de una variante. No agrega nada al carrito. */
    public function crear(int $borradorId, int $productoId, int $varianteId, mixed $cantidad, Admin $actor): VistaSolicitudesBorrador
    {
        $this->crearSinVista($borradorId, $productoId, $varianteId, $cantidad, $actor);

        return $this->listar($borradorId);
    }

    /** Misma operacion que crear() sin reconstruir la vista (uso por lotes, 03G-B). */
    public function crearSinVista(int $borradorId, int $productoId, int $varianteId, mixed $cantidad, Admin $actor, ?LoteCatalogo $lote = null): void
    {
        $cantidad = CantidadVenta::normalizar($cantidad);

        try {
            DB::transaction(function () use ($borradorId, $productoId, $varianteId, $cantidad, $actor, $lote) {
                [$borrador, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
                $variante = $this->variante($lote, $productoId, $varianteId, (int) $carrito->customer_id, $cantidad);

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
    }

    /**
     * Fija (no suma) la cantidad solicitada: repetir la misma orden no cambia nada. No
     * puede quedar por debajo de lo que ya esta en el carrito (primero se retira de alli).
     */
    public function cambiarCantidad(int $borradorId, int $solicitudId, mixed $cantidad, Admin $actor): VistaSolicitudesBorrador
    {
        $this->cambiarCantidadSinVista($borradorId, $solicitudId, $cantidad, $actor);

        return $this->listar($borradorId);
    }

    public function cambiarCantidadSinVista(int $borradorId, int $solicitudId, mixed $cantidad, Admin $actor, ?LoteCatalogo $lote = null): void
    {
        $cantidad = CantidadVenta::normalizar($cantidad);

        DB::transaction(function () use ($borradorId, $solicitudId, $cantidad, $actor, $lote) {
            [$borrador, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
            $solicitud = $this->solicitudDelBorrador((int) $borrador->id, $solicitudId);
            $variante = $solicitud->variante;

            // Variante aun vendible y cliente con grupo apto.
            $v = $this->variante($lote, $this->productoDe($variante), (int) $variante->id, (int) $carrito->customer_id, $cantidad);

            if (! $v->activa) {
                throw new VarianteInvalida(VarianteInvalida::PRODUCTO_NO_VENDIBLE);
            }

            if ($cantidad < $this->lineas->cantidadEnCarrito((int) $carrito->id, (int) $variante->id)) {
                throw new SolicitudInvalida(SolicitudInvalida::MENOR_QUE_CARRITO);
            }

            $solicitud->update(['cantidad_solicitada' => $cantidad]);
        });
    }

    /** Quita la solicitud. Si el carrito tiene unidades de esa variante, primero se retiran. */
    public function eliminar(int $borradorId, int $solicitudId, Admin $actor): VistaSolicitudesBorrador
    {
        $this->eliminarSinVista($borradorId, $solicitudId, $actor);

        return $this->listar($borradorId);
    }

    public function eliminarSinVista(int $borradorId, int $solicitudId, Admin $actor): void
    {
        DB::transaction(function () use ($borradorId, $solicitudId, $actor) {
            [$borrador, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
            $solicitud = $this->solicitudDelBorrador((int) $borrador->id, $solicitudId);

            if ($this->lineas->cantidadEnCarrito((int) $carrito->id, $solicitud->variante_id) > 0) {
                throw new SolicitudInvalida(SolicitudInvalida::EN_CARRITO);
            }

            $solicitud->delete();
        });
    }

    /** Solicitud de una variante en el borrador, o null. Lectura simple para el orquestador. */
    public function solicitudDeVariante(int $borradorId, int $varianteId): ?SolicitudBorrador
    {
        return SolicitudBorrador::query()->where('borrador_id', $borradorId)->where('variante_id', $varianteId)->first();
    }

    protected function solicitudDelBorrador(int $borradorId, int $solicitudId): SolicitudBorrador
    {
        $solicitud = SolicitudBorrador::query()->with('variante')->where('borrador_id', $borradorId)->find($solicitudId);

        if (! $solicitud) {
            throw new SolicitudInvalida(SolicitudInvalida::INEXISTENTE);
        }

        return $solicitud;
    }

    private function variante(?LoteCatalogo $lote, int $productoId, int $varianteId, int $customerId, int $cantidad): VarianteVenta
    {
        return $lote === null
            ? $this->catalogo->variante($productoId, $varianteId, $customerId, $cantidad)
            : $this->catalogo->varianteEnLote($lote, $productoId, $varianteId, $customerId, $cantidad);
    }

    /** Producto de primer nivel: el padre del hijo, o el propio simple. */
    protected function productoDe(Product $variante): int
    {
        return (int) ($variante->parent_id ?? $variante->id);
    }

    private function aSolicitud(SolicitudBorrador $solicitud, ?int $cartId, ?int $customerId, ?LoteCatalogo $lote = null): SolicitudBorradorVenta
    {
        $variante = $solicitud->variante;
        $productoId = $this->productoDe($variante);
        $cantidad = $solicitud->cantidad_solicitada;

        // Vendible HOY: producto de primer nivel activo, hijo verificado y activo.
        try {
            if ($lote === null) {
                $this->catalogo->hijoVerificado($this->catalogo->productoVendible($productoId), (int) $variante->id);
            } else {
                // Mismo registro, ya cargado con sus relaciones en esta operacion.
                $variante = $this->catalogo->hijoVerificadoEnLote($lote, $this->catalogo->productoVendibleEnLote($lote, $productoId), (int) $variante->id);
            }

            $vendible = (bool) $variante->status;
        } catch (VarianteInvalida) {
            $vendible = false;
        }

        $gestionaStock = (bool) $variante->manage_stock;

        // D-36: lo que Bagisto no vende no esta disponible, aunque su indice tenga unidades.
        $stock = $vendible ? (int) $variante->getTypeInstance()->totalQuantity() : 0;

        $opciones = [];
        $precio = null;

        if ($vendible && $customerId !== null) {
            try {
                $v = $this->variante($lote, $productoId, (int) $variante->id, $customerId, $cantidad);
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
