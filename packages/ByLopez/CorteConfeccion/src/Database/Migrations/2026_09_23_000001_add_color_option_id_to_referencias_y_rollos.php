<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F1 - Color estructurado en referencia de proveedor y rollo (ver
 * docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md §5.4/§12.1 y
 * docs/confeccion/STATUS.md).
 *
 * Agrega `color_option_id` (FK -> attribute_options.id, para el atributo
 * Bagisto `code = 'color'`) a las dos tablas, con el mismo patron ya usado
 * por `tipo_tela_id` en ambas: columna `unsignedInteger` nullable +
 * `restrictOnDelete()`. `color` (texto) NO se elimina: sigue siendo el
 * snapshot visual/historico: la migracion solo AGREGA la columna nueva.
 *
 * Politica de borrado: restrictOnDelete(), no nullOnDelete() ni
 * cascadeOnDelete(). A diferencia de la FK que agrego F0
 * (orden_corte_detalle_id -> un registro interno de ByLopez que nunca se
 * borra individualmente), esta FK apunta a una opcion de CATALOGO Bagisto
 * (attribute_options), que un administrador SI puede intentar borrar desde
 * el admin de atributos. Si esa opcion ya esta referenciada por una tela o
 * un rollo, borrarla en silencio dejaria la referencia/rollo con una FK
 * rota (o, con nullOnDelete, con color_option_id=NULL sin que nadie lo note,
 * perdiendo la identidad estructurada que esta fase existe para garantizar).
 * `restrictOnDelete()` bloquea ese borrado con un error de integridad
 * referencial explicito, exactamente el mismo criterio ya aplicado a
 * `tipo_tela_id` en las dos tablas (ver 2026_06_08_000001_... y
 * 2026_06_11_000001_...) y consistente con la recomendacion explicita del
 * encargo de esta fase ("proteger la integridad y evitar que borrar una
 * opcion destruya silenciosamente la relacion").
 *
 * Backfill (ver seccion BACKFILL de docs/confeccion/STATUS.md y el propio
 * encargo de F1): estrictamente 1-a-1, nunca fuzzy/contains/LIKE.
 * - Referencias: exactamente 1 attribute_options.admin_name (atributo
 *   code='color') coincide con LOWER(TRIM(referencia.color)) -> vincular.
 *   0 o >1 coincidencias -> queda NULL. La unica normalizacion permitida en
 *   este backfill historico es LOWER(TRIM(...)); no hay fuzzy matching,
 *   contains ni LIKE.
 * - Rollos CON referencia (proveedor_tela_referencia_id no nulo): copian
 *   color_option_id de esa referencia, sea cual sea (incluido NULL si la
 *   referencia quedo sin vincular) -- nunca reinterpretan el texto propio
 *   del rollo.
 * - Rollos SIN referencia (alta manual): mismo match estricto 1-a-1 sobre
 *   su propio texto `color`.
 *
 * No reescribe ningun texto `color` existente. No crea opciones Bagisto.
 * No elige "la mas parecida" cuando hay 0 o >1 coincidencias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_proveedor_tela_referencias', function (Blueprint $table) {
            $table->unsignedInteger('color_option_id')->nullable()->after('color');
            $table->foreign('color_option_id', 'bccptr_color_fk')
                ->references('id')->on('attribute_options')
                ->restrictOnDelete();
        });

        Schema::table('bylopez_cc_rollos', function (Blueprint $table) {
            $table->unsignedInteger('color_option_id')->nullable()->after('color');
            $table->foreign('color_option_id', 'bccr_color_fk')
                ->references('id')->on('attribute_options')
                ->restrictOnDelete();
        });

        $this->backfillColorOptionIds();
    }

    public function backfillColorOptionIds(): void
    {
        $colorAttributeId = DB::table('attributes')->where('code', 'color')->value('id');

        if (! $colorAttributeId) {
            return;
        }

        // 1) Referencias: match estricto por texto contra las opciones reales.
        DB::table('bylopez_cc_proveedor_tela_referencias')
            ->orderBy('id')
            ->get(['id', 'color'])
            ->each(function ($referencia) use ($colorAttributeId) {
                $coincidencias = DB::table('attribute_options')
                    ->where('attribute_id', $colorAttributeId)
                    ->whereRaw('LOWER(TRIM(admin_name)) = LOWER(TRIM(?))', [(string) $referencia->color])
                    ->pluck('id');

                if ($coincidencias->count() === 1) {
                    DB::table('bylopez_cc_proveedor_tela_referencias')
                        ->where('id', $referencia->id)
                        ->update(['color_option_id' => $coincidencias->first()]);
                }
            });

        // 2) Rollos: prioridad a la referencia (sin reinterpretar texto propio);
        // solo se intenta match por texto propio cuando NO hay referencia.
        DB::table('bylopez_cc_rollos')
            ->orderBy('id')
            ->get(['id', 'color', 'proveedor_tela_referencia_id'])
            ->each(function ($rollo) use ($colorAttributeId) {
                if ($rollo->proveedor_tela_referencia_id) {
                    $colorOptionId = DB::table('bylopez_cc_proveedor_tela_referencias')
                        ->where('id', $rollo->proveedor_tela_referencia_id)
                        ->value('color_option_id');
                } else {
                    $coincidencias = DB::table('attribute_options')
                        ->where('attribute_id', $colorAttributeId)
                        ->whereRaw('LOWER(TRIM(admin_name)) = LOWER(TRIM(?))', [(string) $rollo->color])
                        ->pluck('id');

                    $colorOptionId = $coincidencias->count() === 1 ? $coincidencias->first() : null;
                }

                if ($colorOptionId) {
                    DB::table('bylopez_cc_rollos')
                        ->where('id', $rollo->id)
                        ->update(['color_option_id' => $colorOptionId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_rollos', function (Blueprint $table) {
            $table->dropForeign('bccr_color_fk');
            $table->dropColumn('color_option_id');
        });

        Schema::table('bylopez_cc_proveedor_tela_referencias', function (Blueprint $table) {
            $table->dropForeign('bccptr_color_fk');
            $table->dropColumn('color_option_id');
        });
    }
};
