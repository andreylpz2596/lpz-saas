<?php

namespace ByLopez\CorteConfeccion\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductLookupService
{
    public function options(): Collection
    {
        return DB::table('products')
            ->leftJoin('product_flat', function ($join) {
                $join->on('products.id', '=', 'product_flat.product_id')
                    ->where('product_flat.locale', app()->getLocale());
            })
            ->select(
                'products.id',
                'products.sku',
                DB::raw('COALESCE(product_flat.name, products.sku) as name')
            )
            ->groupBy('products.id', 'products.sku', 'product_flat.name')
            ->orderBy('products.sku')
            ->limit(500)
            ->get();
    }

    public function snapshot(?int $productId): array
    {
        if (! $productId) {
            return [
                'product_id'       => null,
                'producto_sku'     => null,
                'producto_nombre'  => null,
            ];
        }

        $product = DB::table('products')
            ->leftJoin('product_flat', function ($join) {
                $join->on('products.id', '=', 'product_flat.product_id')
                    ->where('product_flat.locale', app()->getLocale());
            })
            ->where('products.id', $productId)
            ->select('products.id', 'products.sku', DB::raw('COALESCE(product_flat.name, products.sku) as name'))
            ->first();

        return [
            'product_id'      => $product?->id,
            'producto_sku'    => $product?->sku,
            'producto_nombre' => $product?->name,
        ];
    }
}
