<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * «Costos y precios › Precios por grupo» (Doc. 32). ADITIVA: bitacora de las ediciones de precios por grupo hechas
 * desde el panel. NO es una fuente de precios: el precio vive solo en `product_customer_group_prices` de Bagisto.
 *
 * - `bylopez_va_precio_grupo_lotes`: una fila por confirmacion. `token` UNICO = huella de la vista previa confirmada:
 *   reenviar el mismo formulario (doble clic, recarga) no aplica dos veces. `indice_estado` es lo unico que cambia
 *   despues (pendiente → actualizado | error), porque la reindexacion de Bagisto corre tras el commit.
 * - `bylopez_va_precio_grupo_cambios`: una fila por regla creada, actualizada o quitada (antes y despues). Solo de adicion.
 * - Sin FK a productos, grupos ni usuarios: la traza sobrevive al borrado (hay snapshots de nombre y SKU).
 * - Limite conocido: una edicion desde la ficha nativa del producto en Bagisto NO pasa por aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_precio_grupo_lotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('lote')->unique('bvapgl_lote_uq');
            $table->char('token', 64)->unique('bvapgl_token_uq');

            $table->unsignedInteger('customer_group_id');
            $table->string('grupo_nombre_snapshot', 191);
            $table->string('operacion', 30);
            $table->json('parametros');
            $table->string('alcance', 20);
            $table->json('filtros')->nullable();
            $table->unsignedInteger('variantes');

            $table->unsignedInteger('creadas')->default(0);
            $table->unsignedInteger('actualizadas')->default(0);
            $table->unsignedInteger('quitadas')->default(0);
            $table->unsignedInteger('sin_cambio')->default(0);

            $table->string('indice_estado', 20)->default('pendiente');
            $table->string('indice_error', 500)->nullable();

            $table->unsignedInteger('actor_admin_id')->nullable();
            $table->string('actor_nombre_snapshot', 191)->nullable();
            $table->string('motivo', 500)->nullable();

            $table->timestamps();

            $table->index(['customer_group_id', 'created_at'], 'bvapgl_grupo_fecha_idx');
            $table->index('created_at', 'bvapgl_fecha_idx');
        });

        Schema::create('bylopez_va_precio_grupo_cambios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lote_id')->constrained('bylopez_va_precio_grupo_lotes', 'id', 'bvapgc_lote_fk')->cascadeOnDelete();

            $table->unsignedInteger('product_id');
            $table->string('sku_snapshot', 191)->nullable();
            $table->unsignedInteger('customer_group_id');
            $table->unsignedInteger('qty');
            $table->string('accion', 20);

            $table->string('tipo_anterior', 20)->nullable();
            $table->decimal('valor_anterior', 12, 4)->nullable();
            $table->string('tipo_nuevo', 20)->nullable();
            $table->decimal('valor_nuevo', 12, 4)->nullable();

            $table->decimal('precio_base_snapshot', 12, 4)->nullable();
            $table->decimal('precio_anterior', 12, 4)->nullable();
            $table->decimal('precio_resultante', 12, 4)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'customer_group_id', 'created_at'], 'bvapgc_variante_grupo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_precio_grupo_cambios');
        Schema::dropIfExists('bylopez_va_precio_grupo_lotes');
    }
};
