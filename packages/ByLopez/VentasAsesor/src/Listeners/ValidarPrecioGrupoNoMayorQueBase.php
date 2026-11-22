<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\Logistica\Support\Moneda;
use ByLopez\VentasAsesor\Support\Precios\PrecioGrupoMaximo;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Webkul\Product\Models\ProductProxy;

/**
 * Ficha nativa del producto (Catalogo › Productos › Editar › Precio por grupo): impide guardar un precio FIJO de grupo
 * mayor que el precio base de la variante (PrecioGrupoMaximo). Sin tocar Webkul: escucha `catalog.product.update.before`,
 * que Bagisto dispara DESPUES de validar el formulario y ANTES de escribir nada.
 *
 * - Solo actua en la ruta `admin.catalog.products.update` y si el formulario trae `customer_group_prices`.
 * - El base es el que se esta guardando en el mismo formulario (`price`) o, si no viene, el actual del producto.
 * - Rechaza con ValidationException (nada se guarda) y deja el mensaje como aviso de error del panel.
 */
class ValidarPrecioGrupoNoMayorQueBase
{
    public const EVENTO = 'catalog.product.update.before';

    public function handle(mixed $productId): void
    {
        if (Route::currentRouteName() !== 'admin.catalog.products.update' || ! is_array($filas = request()->input('customer_group_prices'))) {
            return;
        }

        $base = is_numeric(request()->input('price'))
            ? (float) request()->input('price')
            : (($p = ProductProxy::modelClass()::query()->find((int) $productId)) && is_numeric($p->price) ? (float) $p->price : null);

        foreach ($filas as $fila) {
            $valor = (float) ($fila['value'] ?? 0);

            if (PrecioGrupoMaximo::excede((string) ($fila['value_type'] ?? ''), $valor, $base)) {
                $mensaje = trans('ventasasesor::app.precios.ficha-mayor-que-base', [
                    'valor' => Moneda::formatear($valor),
                    'base' => Moneda::formatear($base),
                    'qty' => (int) ($fila['qty'] ?? 1),
                ]);

                session()->flash('error', $mensaje);

                throw ValidationException::withMessages(['customer_group_prices' => [$mensaje]]);
            }
        }
    }
}
