<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Atribucion comercial del pedido. Tabla PROPIA: el core no guarda ningun usuario
 * administrativo ni asesor [A-§5.1, A-§5.2, A-§14.2]. NO se agregan columnas a
 * `orders`: la relacion 1:1 se materializa con UNIQUE(order_id) [M-§5.1].
 *
 * Tipos: `orders.id` y `admins.id` son `increments()` (int unsigned), por eso las
 * claves foraneas usan unsignedInteger y no bigint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_order_attributions', function (Blueprint $table) {
            $table->id();

            // 1:1 con el pedido oficial. Base de la idempotencia del listener (Fase 02B).
            $table->unsignedInteger('order_id');

            // Quien ejecuto la creacion. Nulo en storefront y automatizaciones.
            $table->unsignedInteger('created_by_admin_id')->nullable();
            $table->string('created_by_name_snapshot')->nullable();

            // Dueño comercial. Nulo en venta de empresa y en sin asignar.
            $table->unsignedInteger('advisor_id')->nullable();
            $table->string('advisor_name_snapshot')->nullable();

            $table->string('commercial_owner_type')->index('bvaoa_owner_type_idx');
            $table->string('commercial_owner_source')->nullable();

            $table->string('sales_channel')->index('bvaoa_sales_channel_idx');
            $table->string('creation_source')->index('bvaoa_creation_source_idx');
            $table->string('attribution_status')->index('bvaoa_status_idx');

            $table->string('external_reference')->nullable()->index('bvaoa_external_ref_idx');

            // Correlacion con el carrito de origen (M-4). Sin clave foranea a proposito:
            // Cart::removeCart() borra la fila de `cart` justo despues de crear el pedido.
            $table->unsignedInteger('cart_id')->nullable()->index('bvaoa_cart_idx');

            $table->timestamp('assigned_at')->nullable();
            $table->unsignedInteger('assigned_by_admin_id')->nullable();

            $table->boolean('is_excluded')->default(false)->index('bvaoa_excluded_idx');
            $table->string('exclusion_reason')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique('order_id', 'bvaoa_order_unique');

            $table->foreign('order_id', 'bvaoa_order_fk')
                ->references('id')->on('orders')->cascadeOnDelete();

            // nullOnDelete + snapshot de nombre: dar de baja a un administrador no
            // puede destruir el historico comercial [M-§11.2].
            $table->foreign('created_by_admin_id', 'bvaoa_created_by_fk')
                ->references('id')->on('admins')->nullOnDelete();

            $table->foreign('advisor_id', 'bvaoa_advisor_fk')
                ->references('id')->on('admins')->nullOnDelete();

            $table->foreign('assigned_by_admin_id', 'bvaoa_assigned_by_fk')
                ->references('id')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_order_attributions');
    }
};
