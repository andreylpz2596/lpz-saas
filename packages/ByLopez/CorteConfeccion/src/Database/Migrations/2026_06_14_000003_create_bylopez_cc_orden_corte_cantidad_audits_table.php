<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_orden_corte_cantidad_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('orden_corte_id');
            $table->unsignedBigInteger('orden_corte_detalle_id');
            $table->unsignedBigInteger('usuario_modificacion_id')->nullable();
            $table->string('usuario_modificacion')->nullable();
            $table->timestamp('fecha_modificacion');
            $table->unsignedInteger('valor_anterior')->default(0);
            $table->unsignedInteger('valor_nuevo')->default(0);
            $table->timestamps();

            $table->index('usuario_modificacion_id', 'bccoca_usuario_idx');

            $table->foreign('orden_corte_id', 'bccoca_orden_fk')
                ->references('id')
                ->on('bylopez_cc_ordenes_corte')
                ->cascadeOnDelete();

            $table->foreign('orden_corte_detalle_id', 'bccoca_detalle_fk')
                ->references('id')
                ->on('bylopez_cc_ordenes_corte_detalles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_orden_corte_cantidad_audits');
    }
};
