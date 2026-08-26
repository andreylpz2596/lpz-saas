<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_ordenes_corte', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->foreignId('rollo_id')->constrained('bylopez_cc_rollos')->cascadeOnDelete();
            $table->unsignedBigInteger('cortador_id')->nullable()->index();
            $table->string('cortador_nombre')->nullable();
            $table->string('color');
            $table->string('genero_linea');
            $table->date('fecha_entrega')->nullable();
            $table->decimal('peso_entregado', 12, 3)->default(0);
            $table->string('estado')->index();
            $table->text('observacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_ordenes_corte');
    }
};
