<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * COM-01 (Doc. 16 §4, Doc. 17 §7.1, COM-D-02) — decimoquinta migracion del modulo, ADITIVA: snapshot del costo
 * unitario de cada linea vendida, capturado al crear el pedido.
 *
 * - Una fila por linea vendible: `order_item_id` = fila PADRE (`parent_id IS NULL`), UNIQUE. En un configurable
 *   la variante real queda en `variante_order_item_id` / `variant_product_id` / `sku_snapshot`; nunca hay dos
 *   filas (padre + hija) para la misma unidad.
 * - COSTO CAPTURADO ≠ COSTO AUTORITATIVO: `autoridad_comision` nace en false. El valor observado del atributo
 *   `cost` de Bagisto es una REFERENCIA (mutable, sin historial, no demostrado como costo real); ninguna
 *   comision real puede causarse ni pagarse con una fila sin autoridad.
 * - Costo desconocido = `costo_unitario` NULL con `fuente = sin_costo`. Nunca 0 (CHECK costo > 0 o NULL).
 * - Inmutable: lo impone el MODELO (`CostoVenta`). Alcance honesto: son eventos de Eloquent; SQL directo los
 *   salta. Regla del modulo: solo escribe `CapturarCostoVentaService`.
 * - FK RESTRICT a `orders` y `order_items` (ids int(10) unsigned de Bagisto). `product_id` y `variant_product_id`
 *   SIN FK: el producto puede borrarse del catalogo y el snapshot debe sobrevivir.
 * - Sin backfill: los pedidos anteriores quedan sin snapshot (COM-D-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_costos_venta', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('order_id');
            $table->unsignedInteger('order_item_id');
            $table->unsignedInteger('variante_order_item_id')->nullable();

            $table->unsignedInteger('product_id')->nullable();
            $table->unsignedInteger('variant_product_id')->nullable();
            $table->string('sku_snapshot', 191)->nullable();
            $table->string('tipo_producto', 30)->nullable();

            $table->decimal('costo_unitario', 12, 4)->nullable();
            $table->string('moneda', 3)->nullable();
            $table->string('fuente', 40);
            $table->string('fuente_referencia', 120)->nullable();
            $table->boolean('autoridad_comision')->default(false);

            $table->timestamp('capturado_en')->useCurrent();
            $table->timestamps();

            $table->unique('order_item_id', 'bvacv_order_item_unique');
            $table->index('order_id', 'bvacv_order_idx');
            $table->index(['fuente', 'autoridad_comision'], 'bvacv_fuente_idx');

            $table->foreign('order_id', 'bvacv_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('order_item_id', 'bvacv_order_item_fk')->references('id')->on('order_items')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_va_costos_venta ADD CONSTRAINT bvacv_costo_positivo CHECK (costo_unitario IS NULL OR costo_unitario > 0)');
    }

    /** Solo esta tabla. Con snapshots reales, revertir borra historia irrecuperable: no hacerlo en produccion. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_costos_venta');
    }
};
