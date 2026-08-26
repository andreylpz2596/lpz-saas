<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_recepciones_corte', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_corte_id')->unique()->constrained('bylopez_cc_ordenes_corte')->cascadeOnDelete();
            $table->date('fecha_recepcion');
            $table->decimal('peso_sobrante_usable', 12, 3)->default(0);
            $table->decimal('peso_retasos', 12, 3)->default(0);
            $table->decimal('peso_desperdicio', 12, 3)->default(0);
            $table->decimal('merma_total', 12, 3)->default(0);
            $table->text('observacion')->nullable();
            $table->string('recibido_por');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_recepciones_corte');
    }
};
