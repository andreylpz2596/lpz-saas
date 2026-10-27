<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * INCIDENCIA DE STOCK EN EL DESPACHO — «el sistema dice 0, pero la prenda esta fisicamente en bodega» (2026-10-09).
 *
 * Flujo (decisiones de Camilo): stock insuficiente → BODEGA solicita autorizacion con el CONTEO FISICO → el Administrador
 * revisa y regulariza (o rechaza) → el drawer recalcula → despacho normal. Despachar sin stock NO existe, ni para el
 * Administrador: aqui solo se corrige el DATO de inventario, de forma explicita y auditada.
 *
 * Esta migracion guarda la SOLICITUD y la REVISION (quien, cuando, por que, que conteo). El movimiento de inventario
 * (cantidad anterior, diferencia, cantidad nueva, usuario, fecha, motivo) vive en el libro que ya existe,
 * `bylopez_cc_inventario_movimientos` de CorteConfeccion, que se REUTILIZA (no hay una segunda logica de stock); aqui
 * solo se enlaza su id (`movimiento_id`, sin FK para no acoplar el orden de migraciones entre paquetes).
 *
 * - UNA incidencia abierta por pedido: `abierta` vale 1 mientras esta solicitada y NULL despues (UNIQUE pedido+abierta).
 * - Estados: solicitada → regularizada | rechazada. Nada se borra; la fila solo transita una vez (lo impone el modelo).
 * - Las lineas guardan el SKU FISICO (la variante en un configurable) con sus cifras de antes y despues.
 * - Actor sin FK con nombre snapshot; FK RESTRICT a pedidos y lineas de pedido (ids int(10) unsigned de Bagisto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_incidencias_stock', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('inventory_source_id');
            $table->string('estado', 20)->default('solicitada');
            $table->unsignedTinyInteger('abierta')->nullable()->default(1);

            $table->string('nota_solicitud', 500);
            $table->unsignedInteger('solicitante_admin_id');
            $table->string('solicitante_name_snapshot', 191);

            $table->string('motivo_resolucion', 500)->nullable();
            $table->unsignedInteger('resuelta_por_admin_id')->nullable();
            $table->string('resuelta_por_name_snapshot', 191)->nullable();
            $table->timestamp('resuelta_en')->nullable();

            $table->string('idempotency_key', 100);
            $table->char('huella', 64);
            $table->timestamps();

            $table->unique('idempotency_key', 'bylgis_idem_unique');
            $table->unique(['order_id', 'abierta'], 'bylgis_una_abierta_unique');
            $table->index(['estado', 'created_at'], 'bylgis_estado_idx');

            $table->foreign('order_id', 'bylgis_order_fk')->references('id')->on('orders')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_lg_incidencias_stock ADD CONSTRAINT bylgis_estado_chk CHECK (estado IN ('solicitada', 'regularizada', 'rechazada'))");
        DB::statement("ALTER TABLE bylopez_lg_incidencias_stock ADD CONSTRAINT bylgis_abierta_chk CHECK ((estado = 'solicitada') = (abierta IS NOT NULL))");
        DB::statement("ALTER TABLE bylopez_lg_incidencias_stock ADD CONSTRAINT bylgis_resuelta_chk CHECK ((estado = 'solicitada') = (resuelta_en IS NULL AND resuelta_por_admin_id IS NULL AND motivo_resolucion IS NULL))");

        Schema::create('bylopez_lg_incidencia_stock_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incidencia_id');
            $table->unsignedInteger('order_item_id');
            $table->unsignedInteger('product_id');
            $table->string('sku_snapshot', 191)->nullable();

            $table->unsignedInteger('cantidad_necesaria');
            $table->integer('stock_sistema_solicitud');
            $table->unsignedInteger('conteo_declarado');

            $table->integer('stock_sistema_resolucion')->nullable();
            $table->unsignedInteger('conteo_confirmado')->nullable();
            $table->integer('diferencia')->nullable();
            $table->unsignedBigInteger('movimiento_id')->nullable();
            $table->timestamps();

            $table->unique(['incidencia_id', 'product_id'], 'bylgisl_incidencia_producto_unique');
            $table->index('movimiento_id', 'bylgisl_movimiento_idx');

            $table->foreign('incidencia_id', 'bylgisl_incidencia_fk')->references('id')->on('bylopez_lg_incidencias_stock')->restrictOnDelete();
            $table->foreign('order_item_id', 'bylgisl_order_item_fk')->references('id')->on('order_items')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_lg_incidencia_stock_lineas ADD CONSTRAINT bylgisl_diferencia_chk CHECK (diferencia IS NULL OR diferencia >= 0)');
    }

    /** Con incidencias reales, revertir borra la auditoria de regularizaciones: no hacerlo en produccion. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_incidencia_stock_lineas');
        Schema::dropIfExists('bylopez_lg_incidencias_stock');
    }
};
