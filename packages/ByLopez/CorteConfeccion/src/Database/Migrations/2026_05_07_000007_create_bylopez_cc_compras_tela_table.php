<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_compras_tela', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->foreignId('proveedor_id')->nullable()->constrained('bylopez_cc_proveedores_tela')->nullOnDelete();
            $table->string('proveedor_nombre');
            $table->string('numero_factura')->nullable();
            $table->date('fecha_compra');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('impuesto', 12, 2)->default(0);
            $table->decimal('total_factura', 12, 2)->default(0);
            $table->unsignedInteger('cantidad_rollos')->default(0);
            $table->decimal('peso_total', 12, 3)->default(0);
            $table->string('estado')->default('registrada')->index();
            $table->text('observacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_compras_tela');
    }
};
