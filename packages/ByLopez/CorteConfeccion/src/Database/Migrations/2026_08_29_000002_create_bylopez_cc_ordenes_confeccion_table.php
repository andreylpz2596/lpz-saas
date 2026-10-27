<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_ordenes_confeccion', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->foreignId('orden_corte_id')->constrained('bylopez_cc_ordenes_corte')->cascadeOnDelete();
            $table->foreignId('taller_id')->constrained('bylopez_cc_talleres')->restrictOnDelete();
            $table->date('fecha_entrega')->nullable();
            $table->string('estado')->default('activa')->index();
            $table->text('observacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_ordenes_confeccion');
    }
};
