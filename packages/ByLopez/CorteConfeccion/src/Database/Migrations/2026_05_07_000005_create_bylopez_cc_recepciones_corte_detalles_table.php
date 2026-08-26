<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_recepciones_corte_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recepcion_corte_id')->constrained('bylopez_cc_recepciones_corte')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('producto_sku')->nullable();
            $table->string('producto_nombre')->nullable();
            $table->string('talla');
            $table->unsignedInteger('cantidad_recibida')->default(0);
            $table->unsignedInteger('cantidad_defectuosa')->default(0);
            $table->text('observacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_recepciones_corte_detalles');
    }
};
