<?php

use ByLopez\Logistica\Contracts\ResuelveMunicipio;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\Tarifa;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Models\Zona;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Tarifas\ZonaLocalDeOrigen;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Domicilios locales F1: catalogo de prueba compartido por Logistica y VentasAsesor (solo lpz_saas_testing).
*/

if (! function_exists('log6_catalogo')) {
    /** Zonas Bogota/Soacha, Jeison y Brian (domiciliarios con cuenta) y PICAP (plataforma sin cuenta), con sus tarifas. */
    function log6_catalogo(): array
    {
        $sufijo = strtoupper(Str::random(5));
        $zona = fn (string $codigo, string $nombre, string $municipio) => tap(Zona::query()->create(['codigo' => $codigo.$sufijo, 'nombre' => $nombre, 'activo' => true]),
            function (Zona $z) use ($municipio) {
                DB::table('bylopez_lg_zona_municipios')->updateOrInsert(['municipio_codigo' => $municipio], ['zona_id' => $z->id, 'created_at' => now(), 'updated_at' => now()]);
                // Las zonas del catalogo de prueba son LOCALES para la bodega predeterminada del canal (como la operacion real).
                DB::table('bylopez_lg_origen_zonas')->insertOrIgnore(['inventory_source_id' => app(ZonaLocalDeOrigen::class)->origenPredeterminadoId(), 'zona_id' => $z->id, 'created_at' => now(), 'updated_at' => now()]);
            });
        $t = fn (string $codigo, string $tipo, bool $cuenta) => Transportadora::query()->create([
            'codigo' => $codigo.'_'.$sufijo, 'nombre' => $codigo, 'tipo' => $tipo, 'activo' => true,
            'liquida_cuenta_corriente' => $cuenta, 'genera_guia_interna' => true,
        ]);

        $c = [
            'bogota' => $zona('BOG', 'Bogotá', '11001'),
            'soacha' => $zona('SOA', 'Soacha', '25754'),
            'jeison' => $t('JEISON', 'domiciliario', true),
            'brian' => $t('BRIAN', 'domiciliario', true),
            'picap' => $t('PICAP', 'plataforma', false),
        ];

        foreach ([['jeison', 'bogota', 10000], ['jeison', 'soacha', 12000], ['brian', 'bogota', 12000], ['brian', 'soacha', 12000]] as [$quien, $donde, $valor]) {
            Tarifa::query()->create(['transportadora_id' => $c[$quien]->id, 'zona_id' => $c[$donde]->id, 'valor' => $valor, 'vigente_desde' => now()->subDay()->toDateString()]);
        }

        return $c;
    }
}

if (! function_exists('log6_municipio')) {
    /** El municipio del pedido lo decide la prueba (el catalogo geografico real se prueba aparte). */
    function log6_municipio(?string $codigo): void
    {
        app()->instance(ResuelveMunicipio::class, new class($codigo) implements ResuelveMunicipio
        {
            public function __construct(private ?string $codigo) {}

            public function municipioDePedido(int $orderId): ?string
            {
                return $this->codigo;
            }

            public function municipioDeDireccion(?string $ciudad, ?string $estado, ?string $pais): ?string
            {
                return $this->codigo;
            }
        });
    }
}

if (! function_exists('log6_despachar')) {
    function log6_despachar(array $e, Transportadora $t, array $extra = []): Guia
    {
        return app(DespachoService::class)->despachar(log2_bodega(), log2_s($e, $extra + ['transportadora' => $t->codigo, 'fletePagador' => '']))->guia;
    }
}
