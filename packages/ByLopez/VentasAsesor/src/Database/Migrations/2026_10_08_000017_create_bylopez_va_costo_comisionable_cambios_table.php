<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * D-COST-05 — decimoseptima migracion del modulo, ADITIVA: bitacora de las actualizaciones ADMINISTRATIVAS del costo
 * comisionable (atributo `cost` de Bagisto) hechas desde «Control de costos».
 *
 * - Bagisto NO conserva historial del EAV: ProductAttributeValueRepository::saveValues() sobrescribe la fila. Esta
 *   tabla es la unica traza de quien cambio que, cuando y en que lote.
 * - Es AUDITORIA, nunca fuente del costo de una venta: el costo de cada venta vive en su snapshot
 *   (`bylopez_va_costos_venta`, D-COST-06).
 * - Solo de adicion: sin updated_at; la inmutabilidad la impone el modelo (`CambioCostoComisionable`).
 * - `product_id` y `actor_admin_id` SIN FK: la traza sobrevive al borrado del producto o del usuario.
 * - Limite conocido: una edicion manual del costo desde la ficha nativa del producto NO pasa por aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_costo_comisionable_cambios', function (Blueprint $table) {
            $table->id();

            $table->uuid('lote');
            $table->unsignedInteger('product_id');
            $table->string('sku_snapshot', 191)->nullable();
            $table->string('producto_snapshot', 255)->nullable();

            $table->decimal('costo_anterior', 12, 4)->nullable();
            $table->decimal('costo_nuevo', 12, 4);

            $table->unsignedInteger('actor_admin_id')->nullable();
            $table->string('actor_nombre_snapshot', 191)->nullable();
            $table->string('mecanismo', 30);
            $table->string('motivo', 500)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('lote', 'bvaccc_lote_idx');
            $table->index(['product_id', 'created_at'], 'bvaccc_producto_fecha_idx');
            $table->index('created_at', 'bvaccc_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_costo_comisionable_cambios');
    }
};
