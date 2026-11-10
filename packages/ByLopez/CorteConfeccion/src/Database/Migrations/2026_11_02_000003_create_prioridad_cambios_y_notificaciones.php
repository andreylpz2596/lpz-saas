<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de cambios de prioridad por cantidad y avisos del modulo en la campana de Bagisto.
 *
 * La tabla `notifications` de Bagisto 2.4 exige un pedido de venta (order_id NOT NULL + FK) y no tiene destinatario,
 * por eso los avisos del modulo viven aqui (uno por destinatario y operacion) y se muestran en la MISMA campana y en el
 * MISMO listado de notificaciones (ver views/admin/notificaciones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_prioridad_operaciones', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('taller_id');
            $table->unsignedInteger('usuario_id')->nullable();
            $table->string('nota', 500)->nullable();
            // notificado | sin_destinatario: si el taller no tiene usuario vinculado activo no se afirma que fue avisado.
            $table->string('notificacion_estado', 20);
            $table->unsignedInteger('destinatario_id')->nullable();
            $table->timestamps();

            $table->foreign('taller_id')->references('id')->on('bylopez_cc_talleres');
            $table->index(['taller_id', 'created_at']);
        });

        Schema::create('bylopez_cc_prioridad_cambios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operacion_id')->constrained('bylopez_cc_prioridad_operaciones');
            $table->unsignedBigInteger('orden_confeccion_id');
            $table->unsignedBigInteger('orden_confeccion_detalle_id');
            $table->unsignedInteger('product_id')->nullable();
            $table->unsignedInteger('cantidad');
            $table->string('prioridad_anterior', 20);
            $table->string('prioridad_nueva', 20);
            $table->timestamps();

            $table->foreign('orden_confeccion_id')->references('id')->on('bylopez_cc_ordenes_confeccion');
            $table->foreign('orden_confeccion_detalle_id')->references('id')->on('bylopez_cc_ordenes_confeccion_detalles');
            $table->index('orden_confeccion_detalle_id');
        });

        Schema::create('bylopez_cc_notificaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('admin_id');
            $table->string('tipo', 40);
            $table->foreignId('prioridad_operacion_id')->nullable()->constrained('bylopez_cc_prioridad_operaciones');
            // Foto del cambio al momento de notificar: la alerta conserva lo historico; la pantalla muestra lo actual.
            $table->json('datos');
            $table->timestamp('leida_en')->nullable();
            $table->timestamps();

            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            // Un aviso por destinatario y operacion: un reintento no duplica la alerta.
            $table->unique(['admin_id', 'tipo', 'prioridad_operacion_id'], 'bylopez_cc_notif_unica');
            $table->index(['admin_id', 'leida_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_notificaciones');
        Schema::dropIfExists('bylopez_cc_prioridad_cambios');
        Schema::dropIfExists('bylopez_cc_prioridad_operaciones');
    }
};
