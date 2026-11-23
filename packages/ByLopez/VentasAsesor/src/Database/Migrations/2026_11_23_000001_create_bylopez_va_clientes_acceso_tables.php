<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Acceso de un cliente comercial al aplicativo (autoservicio, doc. 33). ADITIVA.
 *
 * - `customers` (cliente comercial) y `admins` (usuario de acceso) siguen separados; esta tabla es el vinculo explicito.
 * - Un usuario pertenece a UN solo cliente (UNIQUE admin_id). Un cliente PUEDE tener varios usuarios en el futuro: no hay
 *   UNIQUE sobre customer_id (en V1 el limite es funcional, en el servicio).
 * - Nada se borra: desvincular deja la fila con su motivo y fecha. Los eventos son la auditoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_clientes_acceso', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id');
            $table->unsignedInteger('admin_id');
            $table->string('estado', 20)->default('activo'); // activo | suspendido | desvinculado
            $table->unsignedInteger('habilitado_por_admin_id')->nullable();
            $table->timestamp('habilitado_at')->nullable();
            $table->timestamp('suspendido_at')->nullable();
            $table->string('suspendido_motivo', 500)->nullable();
            $table->timestamp('desvinculado_at')->nullable();
            $table->string('desvinculado_motivo', 500)->nullable();
            $table->timestamps();

            $table->unique('admin_id', 'bvaca_admin_uq');
            $table->index(['customer_id', 'estado'], 'bvaca_cliente_estado_idx');
            $table->foreign('customer_id', 'bvaca_customer_fk')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('admin_id', 'bvaca_admin_fk')->references('id')->on('admins')->restrictOnDelete();
        });

        Schema::create('bylopez_va_clientes_acceso_eventos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('acceso_id')->nullable();
            $table->unsignedInteger('customer_id');
            $table->unsignedInteger('admin_id')->nullable();
            $table->string('accion', 40); // crear_usuario | vincular | suspender | reactivar | desvincular | enlace_enviado
            $table->unsignedInteger('actor_admin_id')->nullable();
            $table->string('motivo', 500)->nullable();
            $table->json('detalle')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'id'], 'bvacae_cliente_idx');
            $table->index('acceso_id', 'bvacae_acceso_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_clientes_acceso_eventos');
        Schema::dropIfExists('bylopez_va_clientes_acceso');
    }
};
