<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3 - Merma total de tela por Orden de Corte.
 *
 * `merma_unica` distingue, de forma inequivoca, como se capturaron los pesos
 * en la recepcion de Bodega:
 *
 *  - false (default, TODAS las ordenes existentes): contrato historico. La
 *    recepcion registro sobrante usable + retazos + desperdicio;
 *    `peso_sobrante_total` es tela reutilizable y al cerrar se reparte en
 *    cascada entre los rollos (OrdenCorteService::actualizarRollosAlCerrar).
 *  - true (solo recepciones creadas desde F3): se registro UNA merma total no
 *    reutilizable de toda la orden en `merma_total`; `peso_sobrante_total` = 0,
 *    no vuelve tela a ningun rollo y no se reparte peso entre rollos.
 *
 * No se usa `merma_total = 0` ni `peso_sobrante_total = 0` para distinguirlas
 * porque 0 es un valor real en ambos contratos. No se convierte ni recalcula
 * ninguna orden existente: el default false las deja como historicas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_ordenes_corte', function (Blueprint $table) {
            $table->boolean('merma_unica')->default(false)->after('merma_total');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_ordenes_corte', function (Blueprint $table) {
            $table->dropColumn('merma_unica');
        });
    }
};
