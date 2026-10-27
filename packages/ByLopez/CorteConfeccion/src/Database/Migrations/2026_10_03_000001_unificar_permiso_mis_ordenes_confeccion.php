<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('permission_type', 'custom')
            ->where('permissions', 'like', '%corte_confeccion.mis_ordenes%')
            ->orderBy('id')
            ->each(function ($role) {
                $permissions = json_decode($role->permissions ?: '[]', true);

                if (! is_array($permissions)) {
                    return;
                }

                $permissions = array_values(array_unique(array_merge(
                    array_diff($permissions, ['corte_confeccion.mis_ordenes']),
                    ['corte_confeccion.ordenes_confeccion']
                )));

                DB::table('roles')
                    ->where('id', $role->id)
                    ->update(['permissions' => json_encode($permissions)]);
            });
    }

    public function down(): void
    {
        // Permission normalization is intentionally not reversible.
    }
};
