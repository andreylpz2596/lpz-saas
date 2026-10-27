<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_talleres', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('nit')->nullable()->index();
            $table->string('contacto')->nullable();
            $table->string('telefono')->nullable();
            $table->string('direccion')->nullable();
            $table->string('estado')->default('activo')->index();
            $table->text('observacion')->nullable();
            $table->unsignedInteger('usuario_id')->nullable();
            $table->timestamps();

            $table->foreign('usuario_id')->references('id')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_talleres');
    }
};
