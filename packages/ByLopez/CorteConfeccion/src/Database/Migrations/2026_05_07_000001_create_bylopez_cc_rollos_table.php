<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_rollos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('proveedor');
            $table->string('color');
            $table->string('tipo_tela');
            $table->decimal('peso_inicial', 12, 3);
            $table->decimal('peso_disponible', 12, 3);
            $table->decimal('costo_total', 12, 2)->default(0);
            $table->date('fecha_compra')->nullable();
            $table->string('estado')->index();
            $table->text('observacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_rollos');
    }
};
