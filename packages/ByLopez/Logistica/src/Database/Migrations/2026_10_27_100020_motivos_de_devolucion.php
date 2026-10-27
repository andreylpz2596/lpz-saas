<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * MOTIVOS DE DEVOLUCION estandarizados (aprobado 2026-10-26). ADITIVA; lo historico no se toca ni se reclasifica.
 *
 * Se reutiliza la infraestructura del catalogo parametrizable (`bylopez_lg_catalogo_defectos`: codigo estable, nombre, orden,
 * activo, requiere_detalle) con un TIPO PROPIO `devolucion`. La separacion se conserva: los diagnosticos de la prenda siguen
 * siendo `motivo` / `origen` (los usa la recepcion de bodega) y todos los consumidores filtran por tipo; un motivo comercial de
 * devolucion nunca aparece como defecto ni al reves.
 *
 * `bylopez_lg_retornos.motivo_codigo` (nullable): el codigo elegido al registrar la devolucion. `motivo` (texto) se conserva:
 * en los historicos es lo que se escribio; en los nuevos es el nombre del motivo o el detalle escrito. Sin backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bylopez_lg_catalogo_defectos DROP CONSTRAINT bylgcd_tipo_chk');
        DB::statement("ALTER TABLE bylopez_lg_catalogo_defectos ADD CONSTRAINT bylgcd_tipo_chk CHECK (tipo IN ('motivo', 'origen', 'devolucion'))");

        $ahora = now();
        $filas = [];

        foreach ([
            ['cliente_ausente', 'Cliente ausente o no contesta', false],
            ['rechazado', 'El cliente rechazó el pedido', false],
            ['direccion_errada', 'Dirección errada o incompleta', false],
            ['talla', 'Talla o medida no le quedó', false],
            ['no_corresponde', 'El producto no corresponde a lo pedido', false],
            ['sin_dinero', 'El cliente no tenía el dinero', false],
            ['cambio_opinion', 'El cliente cambió de opinión', false],
            ['otro', 'Otro', true],
        ] as $i => [$codigo, $nombre, $detalle]) {
            $filas[] = ['tipo' => 'devolucion', 'codigo' => $codigo, 'nombre' => $nombre, 'requiere_detalle' => $detalle, 'activo' => true, 'orden' => ($i + 1) * 10, 'created_at' => $ahora, 'updated_at' => $ahora];
        }

        DB::table('bylopez_lg_catalogo_defectos')->insertOrIgnore($filas);

        Schema::table('bylopez_lg_retornos', function (Blueprint $table) {
            $table->string('motivo_codigo', 40)->nullable()->after('motivo');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_lg_retornos', function (Blueprint $table) {
            $table->dropColumn('motivo_codigo');
        });

        DB::table('bylopez_lg_catalogo_defectos')->where('tipo', 'devolucion')->delete();
        DB::statement('ALTER TABLE bylopez_lg_catalogo_defectos DROP CONSTRAINT bylgcd_tipo_chk');
        DB::statement("ALTER TABLE bylopez_lg_catalogo_defectos ADD CONSTRAINT bylgcd_tipo_chk CHECK (tipo IN ('motivo', 'origen'))");
    }
};
