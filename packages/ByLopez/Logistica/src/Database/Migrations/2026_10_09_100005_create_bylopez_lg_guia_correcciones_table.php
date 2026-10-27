<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * CORREGIR GUIA — historial auditado (decision de Camilo, 2026-10-09).
 *
 * Una guia mal digitada se corrige SIN anular el Shipment: el Shipment (salida de inventario) y el numero de guia son
 * conceptos distintos. Cada correccion deja una fila SOLO DE ADICION con lo anterior y lo nuevo (numero y, si cambia,
 * transportadora), motivo obligatorio, quien y cuando. La tabla `bylopez_lg_guias` conserva la guia VIGENTE; aqui queda
 * toda la cadena (el «anterior» de una correccion es el «nuevo» de la previa).
 *
 * - FK RESTRICT a la guia: el historial impide borrarla. Actor sin FK con nombre snapshot (como el resto de ByLopez).
 * - Transportadora anterior/nueva: codigos del catalogo como TEXTO (sin FK): el catalogo puede desactivar filas.
 * - Sin updated_at: nunca se edita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_guia_correcciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guia_id');
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('shipment_id');

            $table->string('numero_anterior', 100);
            $table->string('numero_nuevo', 100);
            $table->string('transportadora_anterior', 40);
            $table->string('transportadora_nueva', 40);
            $table->string('motivo', 500);

            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_name_snapshot', 191);
            $table->timestamp('created_at')->useCurrent();

            $table->index('guia_id', 'bylggc_guia_idx');
            $table->index('order_id', 'bylggc_order_idx');

            $table->foreign('guia_id', 'bylggc_guia_fk')->references('id')->on('bylopez_lg_guias')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_lg_guia_correcciones ADD CONSTRAINT bylggc_cambio_chk CHECK (numero_anterior <> numero_nuevo OR transportadora_anterior <> transportadora_nueva)');
    }

    /** Con correcciones reales, revertir borra la auditoria de guias: no hacerlo en produccion. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_guia_correcciones');
    }
};
