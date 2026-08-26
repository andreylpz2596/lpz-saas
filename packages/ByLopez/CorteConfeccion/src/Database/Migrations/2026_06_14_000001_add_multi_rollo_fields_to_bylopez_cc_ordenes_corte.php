<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_ordenes_corte', function (Blueprint $table) {
            $table->decimal('peso_total_rollos', 12, 3)->default(0)->after('peso_entregado');
            $table->decimal('peso_sobrante_total', 12, 3)->default(0)->after('peso_total_rollos');
            $table->decimal('merma_total', 12, 3)->default(0)->after('peso_sobrante_total');
            $table->decimal('peso_consumido_total', 12, 3)->default(0)->after('merma_total');
            $table->unsignedInteger('total_piezas_cortadas')->default(0)->after('peso_consumido_total');
            $table->decimal('peso_promedio_pieza', 12, 6)->default(0)->after('total_piezas_cortadas');
        });

        Schema::create('bylopez_cc_orden_corte_rollos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_corte_id')->constrained('bylopez_cc_ordenes_corte')->cascadeOnDelete();
            $table->foreignId('rollo_id')->constrained('bylopez_cc_rollos')->cascadeOnDelete();
            $table->decimal('peso_inicial_usado', 12, 3)->default(0);
            $table->decimal('peso_sobrante', 12, 3)->default(0);
            $table->decimal('peso_consumido', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['orden_corte_id', 'rollo_id']);
        });

        Schema::table('bylopez_cc_ordenes_corte_detalles', function (Blueprint $table) {
            $table->unsignedBigInteger('variant_id')->nullable()->after('product_id')->index();
            $table->string('genero_linea')->nullable()->after('producto_nombre');
            $table->unsignedInteger('cantidad_real_cortada')->default(0)->after('cantidad_proyectada');
            $table->text('observacion')->nullable()->after('cantidad_real_cortada');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_ordenes_corte_detalles', function (Blueprint $table) {
            $table->dropIndex(['variant_id']);
            $table->dropColumn(['variant_id', 'genero_linea', 'cantidad_real_cortada', 'observacion']);
        });

        Schema::dropIfExists('bylopez_cc_orden_corte_rollos');

        Schema::table('bylopez_cc_ordenes_corte', function (Blueprint $table) {
            $table->dropColumn([
                'peso_total_rollos',
                'peso_sobrante_total',
                'merma_total',
                'peso_consumido_total',
                'total_piezas_cortadas',
                'peso_promedio_pieza',
            ]);
        });
    }
};
