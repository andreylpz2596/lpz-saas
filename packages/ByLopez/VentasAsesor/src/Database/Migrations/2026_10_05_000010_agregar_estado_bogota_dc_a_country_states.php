<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * D-58 (aprobada por Camilo Gaitan) — DATO, no estructura: Bogota, D.C. es una entidad territorial
 * propia (DIVIPOLA 11), no parte de Cundinamarca. `country_states` de Bagisto trae 32
 * departamentos de Colombia y NO la incluye; sin ella un municipio de Bogota tendria que colgar de
 * `CUN`, que fue rechazado expresamente.
 *
 * Codigo elegido: `BOG`. Convencion verificada en `country_states` de CO: tres letras mayusculas
 * (AMA, ANT, BOY, CUN, …). Sin colisiones: ningun `code = 'BOG'` en ningun pais, y
 * `country_states.code` no tiene indice unico. El nombre sigue la escritura de DANE: «Bogotá, D.C.».
 *
 * No toca migraciones ni codigo de Webkul, no cambia ni borra ningun estado existente. Idempotente:
 * si ya existe un estado `BOG` de Colombia no inserta nada. `down()` solo lo elimina si es el que
 * esta migracion define y nadie lo usa (catalogo geografico ni direcciones).
 */
return new class extends Migration
{
    private const CODIGO = 'BOG';

    private const NOMBRE = 'Bogotá, D.C.';

    public function up(): void
    {
        $paisId = DB::table('countries')->where('code', 'CO')->value('id');

        // Instalacion sin el pais Colombia: nada que hacer (el importador falla cerrado y lo indica).
        if ($paisId === null) {
            return;
        }

        $existe = DB::table('country_states')->where('country_code', 'CO')->where('code', self::CODIGO)->exists();

        if (! $existe) {
            DB::table('country_states')->insert([
                'country_id' => $paisId,
                'country_code' => 'CO',
                'code' => self::CODIGO,
                'default_name' => self::NOMBRE,
            ]);
        }
    }

    public function down(): void
    {
        $estado = DB::table('country_states')
            ->where('country_code', 'CO')->where('code', self::CODIGO)->where('default_name', self::NOMBRE)->first();

        if (! $estado) {
            return;
        }

        $enCatalogo = Schema::hasTable('bylopez_geo_departamentos')
            && DB::table('bylopez_geo_departamentos')->where('country_state_id', $estado->id)->exists();
        $enDirecciones = DB::table('addresses')->where('country', 'CO')->where('state', self::CODIGO)->exists();

        if (! $enCatalogo && ! $enDirecciones) {
            DB::table('country_states')->where('id', $estado->id)->delete();
        }
    }
};
