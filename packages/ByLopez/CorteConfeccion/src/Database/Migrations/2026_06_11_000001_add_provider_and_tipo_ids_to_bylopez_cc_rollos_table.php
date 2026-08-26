<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_rollos', function (Blueprint $table) {
            $table->foreignId('proveedor_tela_id')
                ->nullable()
                ->after('proveedor_tela_referencia_id')
                ->constrained('bylopez_cc_proveedores_tela')
                ->nullOnDelete();

            $table->unsignedInteger('tipo_tela_id')->nullable()->after('proveedor_tela_id');
            $table->foreign('tipo_tela_id')->references('id')->on('attribute_options')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_rollos', function (Blueprint $table) {
            $table->dropForeign(['tipo_tela_id']);
            $table->dropColumn('tipo_tela_id');
            $table->dropConstrainedForeignId('proveedor_tela_id');
        });
    }
};
