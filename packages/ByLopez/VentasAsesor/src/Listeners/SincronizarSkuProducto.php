<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Services\Catalogo\CodigoSkuService;
use ByLopez\VentasAsesor\Services\Catalogo\RenombradorSkuService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Doc. 33 — Al crear o guardar un producto (ficha de Bagisto, copia de producto o Cargues) de una familia del patrón, su SKU y el de
 * sus variantes quedan como «115D-DZ» / «115D-DZ-M». Escucha `catalog.product.create.after` y `catalog.product.update.after`, que
 * Bagisto dispara al terminar de guardar.
 *
 * - Calcula el configurable completo (padre + variantes); si un dato falta (color, talla, tela) ese producto conserva su SKU.
 * - Un color sin número recibe el siguiente libre.
 * - Producto COPIADO en Bagisto («Copia de …»): el nombre pasa a la plantilla con su color real; un url_key «copia-de-…» se rehace
 *   con el nombre.
 *   Fuera de ese caso el nombre que escribió el usuario se respeta.
 * - El SKU nuevo también se lleva a las copias guardadas (pedidos, costos…), igual que la normalización.
 * - Nunca hace fallar el guardado: un error se reporta y el producto queda con el SKU que tenía.
 */
class SincronizarSkuProducto
{
    public const EVENTOS = ['catalog.product.create.after', 'catalog.product.update.after'];

    public function __construct(protected RenombradorSkuService $renombrador) {}

    public function handle(mixed $producto): void
    {
        if (! config('ventasasesor.sku.automatico', true) || ! is_object($producto) || empty($producto->id)) {
            return;
        }

        try {
            $cambios = DB::transaction(fn () => $this->sincronizar((int) ($producto->parent_id ?: $producto->id)));
        } catch (Throwable $e) {
            report($e);

            return;
        }

        // Bagisto puede volver a escribir product_flat con el modelo en memoria: que lleve el SKU nuevo.
        if (isset($cambios[(int) $producto->id]['sku'])) {
            $producto->sku = $cambios[(int) $producto->id]['sku'];
            $producto->syncOriginalAttribute('sku');
        }
    }

    /** @return array<int, array<string,string>> cambios aplicados */
    public function sincronizar(int $raizId): array
    {
        $codigos = app(CodigoSkuService::class);
        $plan = $codigos->plan($raizId);

        if (! $plan['padre']) {
            return [];
        }

        $cambios = [];

        foreach ([$plan['padre'], ...$plan['variantes']] as $p) {
            if ($p['nuevo'] !== null && $p['nuevo'] !== $p['actual']) {
                $cambios[$p['id']]['sku'] = $p['nuevo'];
            }
        }

        // Copias de Bagisto: nombre por plantilla (con el color real) y url_key sin «copia-de-».
        $ids = [$plan['padre']['id'], ...array_column($plan['variantes'], 'id')];
        $nombres = $codigos->nombres($plan);
        $actuales = DB::table('product_flat')->whereIn('product_id', $ids)->get(['product_id', 'name', 'url_key'])->keyBy('product_id');
        $reservados = [];

        foreach ($ids as $id) {
            $fila = $actuales->get($id);

            if ($fila && preg_match('/^\s*copia de\s/iu', (string) $fila->name) && isset($nombres[$id])) {
                $cambios[$id]['name'] = $nombres[$id];
                $clave = $this->renombrador->urlKeyLibre($nombres[$id], $id, $reservados);
                $reservados[$clave] = $id;
                $cambios[$id]['url_key'] = $clave;
            } elseif ($fila && preg_match('/^(copia-de-)+/i', (string) $fila->url_key)) {
                // La dirección se arma con el nombre ACTUAL (la de la copia suele traer el color del producto original).
                $clave = $this->renombrador->urlKeyLibre((string) $fila->name, $id, $reservados);
                $reservados[$clave] = $id;
                $cambios[$id]['url_key'] = $clave;
            }
        }

        $this->renombrador->aplicar($cambios);

        return $cambios;
    }
}
