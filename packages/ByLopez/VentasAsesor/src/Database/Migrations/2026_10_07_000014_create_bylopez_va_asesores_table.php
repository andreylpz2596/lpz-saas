<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASESOR-01: perfil comercial del asesor. La IDENTIDAD sigue siendo el usuario de Bagisto (`admins.id`), que es lo que
 * guarda `bylopez_va_order_attributions.advisor_id`; este perfil le agrega lo que no existia: nombre comercial,
 * telefono y estado activo/inactivo.
 *
 * `admin_id` es UNICO y se considera INMUTABLE: un usuario tiene como mucho un perfil de asesor en toda su vida y el
 * perfil nunca cambia de usuario. Asi, la atribucion historica por `advisor_id` (admins.id) siempre apunta al mismo
 * asesor, sin reescribir pedidos. Nunca se borra un asesor: se inactiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_asesores', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('admin_id');
            $table->string('nombre', 120);
            $table->string('telefono', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('creado_por_admin_id')->nullable();
            $table->timestamps();

            $table->unique('admin_id', 'bvaas_admin_unique');
            $table->index('activo', 'bvaas_activo_idx');
            $table->foreign('admin_id', 'bvaas_admin_fk')->references('id')->on('admins')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_asesores');
    }
};
