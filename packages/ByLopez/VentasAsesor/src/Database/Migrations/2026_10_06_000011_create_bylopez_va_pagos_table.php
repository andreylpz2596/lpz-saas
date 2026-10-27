<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * PAG-B (D-59 / Doc. 05 §42.2) — undecima migracion del modulo, ADITIVA: primera de las tres tablas del
 * dominio de Pagos / Cartera. Solo toca tablas propias.
 *
 * `bylopez_va_pagos` es el INGRESO COMERCIAL registrado (P-01): ni es una `order_transaction` ni la crea.
 * La Transaction nativa nacera solo al CONFIRMAR (bloques posteriores). Aqui no se guarda saldo ni deuda
 * (P-03): son derivados.
 *
 * Tipos verificados contra el esquema real de Bagisto 2.4.9 (lpz_saas): `customers.id` es int(10)
 * unsigned, asi que `customer_id` es unsignedInteger (no bigint). El dinero usa decimal(12,4), igual que
 * `orders.base_grand_total` y `order_transactions.amount`. Moneda base, sin multimoneda.
 *
 * - UNIQUE(mecanismo, idempotency_key): misma clave de idempotencia de D-53 (Doc. 05 §35.3), ahora por pago.
 * - `customer_id` con FK RESTRICT: la historia financiera no se cascadea ni se borra con el cliente.
 * - Los ids de administrador van SIN clave foranea (como la atribucion): si el administrador se elimina,
 *   la auditoria debe seguir diciendo quien fue.
 * - `referencia_normalizada` es solo un indice para detectar duplicados mas adelante (aviso, no bloqueo).
 * - `estado` guarda el valor de `Enums\EstadoPago` (sin_revisar, confirmado, rechazado, reversado).
 * - CHECK monto > 0 (MariaDB 10.2+ / MySQL 8.0.16+).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_pagos', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('customer_id');
            $table->decimal('monto', 12, 4);
            $table->string('medio_cobro', 40);
            $table->date('fecha_pago');
            $table->string('referencia', 100)->nullable();
            $table->string('referencia_normalizada', 100)->nullable();
            $table->text('observacion')->nullable();

            $table->string('estado', 20)->default('sin_revisar');

            $table->string('mecanismo', 30);
            $table->string('idempotency_key', 100);
            $table->char('huella_solicitud', 64);

            $table->unsignedInteger('registrado_por_admin_id');
            $table->unsignedInteger('revisado_por_admin_id')->nullable();
            $table->timestamp('revisado_en')->nullable();
            $table->string('motivo_revision', 500)->nullable();

            $table->timestamps();

            $table->unique(['mecanismo', 'idempotency_key'], 'bvapag_idem_unique');
            $table->index(['customer_id', 'estado'], 'bvapag_cliente_estado_idx');
            $table->index(['estado', 'created_at'], 'bvapag_estado_fecha_idx');
            $table->index(['medio_cobro', 'referencia_normalizada'], 'bvapag_referencia_idx');
            $table->index('registrado_por_admin_id', 'bvapag_registrador_idx');

            $table->foreign('customer_id', 'bvapag_customer_fk')->references('id')->on('customers')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_va_pagos ADD CONSTRAINT bvapag_monto_positivo CHECK (monto > 0)');
    }

    /**
     * Solo esta tabla. Falla (FK RESTRICT) si ya hay aplicaciones o eventos: la historia financiera no se
     * pierde por un rollback. Para revertir el bloque hay que deshacer las tres migraciones en orden inverso.
     */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_pagos');
    }
};
