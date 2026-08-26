<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_proveedores_tela', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('nit')->nullable()->index();
            $table->string('telefono')->nullable();
            $table->string('contacto')->nullable();
            $table->string('direccion')->nullable();
            $table->string('tipo')->nullable();
            $table->string('estado')->default('activo')->index();
            $table->text('observacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_proveedores_tela');
    }
};
