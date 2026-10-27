<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Envio local por ORIGEN: que zonas son LOCALES para cada bodega (`inventory_sources` de Bagisto). Un destino es local solo
 * si su zona (F1) esta vinculada al origen. La zona sigue siendo unica (municipio UNIQUE); lo que cambia por bodega es este
 * vinculo, asi Soacha puede ser local desde una bodega y no desde otra. ADITIVA: no toca zonas, municipios ni tarifas.
 *
 * Backfill: la bodega predeterminada del canal (la que hoy usa Nueva Venta) recibe las zonas activas existentes, para que
 * la operacion actual siga igual. Se toma de los datos reales; no hay ciudades fijas en el codigo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_origen_zonas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('inventory_source_id');
            $table->foreignId('zona_id');
            $table->timestamps();

            $table->unique(['inventory_source_id', 'zona_id'], 'bylgoz_origen_zona_unique');
            $table->foreign('inventory_source_id', 'bylgoz_origen_fk')->references('id')->on('inventory_sources')->cascadeOnDelete();
            $table->foreign('zona_id', 'bylgoz_zona_fk')->references('id')->on('bylopez_lg_zonas')->restrictOnDelete();
        });

        $origen = DB::table('inventory_sources as s')
            ->leftJoin('channel_inventory_sources as c', 'c.inventory_source_id', '=', 's.id')
            ->where('s.status', 1)
            ->orderByRaw('c.channel_id is null')->orderBy('s.priority')->orderBy('s.id')
            ->value('s.id');

        if ($origen === null) {
            return;
        }

        foreach (DB::table('bylopez_lg_zonas')->where('activo', true)->pluck('id') as $zonaId) {
            DB::table('bylopez_lg_origen_zonas')->insert([
                'inventory_source_id' => $origen, 'zona_id' => $zonaId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_origen_zonas');
    }
};
