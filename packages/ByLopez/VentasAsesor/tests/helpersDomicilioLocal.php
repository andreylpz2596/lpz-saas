<?php

use ByLopez\Logistica\Contracts\DomiciliarioElegidoEnCarrito;
use ByLopez\Logistica\Contracts\ResuelveMunicipio;
use ByLopez\Logistica\Models\Tarifa;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Models\Zona;
use ByLopez\Logistica\Services\Tarifas\ZonaLocalDeOrigen;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Escenario de domicilio local con la regla VIGENTE (Envío local por origen, 2026-10-12, y cierre F1):
| el domicilio local solo se ofrece si el destino cae en una zona LOCAL del origen y el domiciliario elegido tiene tarifa
| en esa zona; nunca con `default_rate`. Las pruebas 03D/03F/03G se escribieron cuando la tarifa salía de `default_rate`
| y no tratan de zonas. Este escenario deja Bogotá (11001) como zona local de la bodega predeterminada, con un domiciliario
| «elegido» cuya tarifa es la del escenario. Zonas, tarifas, carrier y totales son los reales; F1 prueba aparte la elección
| real del domiciliario, el catálogo DIVIPOLA y que nunca se use un valor genérico.
*/

if (! function_exists('dl_domicilioLocal')) {
    /** Monta el escenario. `$valor` null = el domiciliario no tiene tarifa (el método no se ofrece). */
    function dl_domicilioLocal(?float $valor = 8000.0): void
    {
        $real = app(ResuelveMunicipio::class);

        // Direcciones de prueba en Bogotá («Bogotá»/«DC») → 11001; el resto, el resolvedor real.
        app()->instance(ResuelveMunicipio::class, new class($real) implements ResuelveMunicipio
        {
            public function __construct(private ResuelveMunicipio $real) {}

            public function municipioDePedido(int $orderId): ?string
            {
                return $this->real->municipioDePedido($orderId);
            }

            public function municipioDeDireccion(?string $ciudad, ?string $estado, ?string $pais): ?string
            {
                return $this->real->municipioDeDireccion($ciudad, $estado, $pais)
                    ?? (str_starts_with(Str::lower(Str::ascii((string) $ciudad)), 'bogota') ? '11001' : null);
            }
        });

        $sufijo = strtoupper(Str::random(6));
        $zona = Zona::query()->create(['codigo' => 'DLBOG'.$sufijo, 'nombre' => 'Bogotá (prueba)', 'activo' => true]);
        DB::table('bylopez_lg_zona_municipios')->updateOrInsert(['municipio_codigo' => '11001'], ['zona_id' => $zona->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bylopez_lg_origen_zonas')->insertOrIgnore([
            'inventory_source_id' => app(ZonaLocalDeOrigen::class)->origenPredeterminadoId(), 'zona_id' => $zona->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $domiciliario = Transportadora::query()->create([
            'codigo' => 'DL_'.$sufijo, 'nombre' => 'DL '.$sufijo, 'tipo' => 'domiciliario', 'activo' => true,
            'liquida_cuenta_corriente' => true, 'genera_guia_interna' => true,
        ]);

        app()->instance(DomiciliarioElegidoEnCarrito::class, new class($domiciliario->id) implements DomiciliarioElegidoEnCarrito
        {
            public function __construct(private int $id) {}

            public function transportadoraId(int $cartId): ?int
            {
                return $this->id;
            }
        });

        app()->instance('pruebas.domicilio_local', ['zona' => $zona, 'domiciliario' => $domiciliario]);

        dl_tarifaDomicilio($valor);
    }
}

if (! function_exists('dl_tarifaDomicilio')) {
    /** Cambia la tarifa del domiciliario del escenario en su zona (como Logística › Tarifas). null = sin tarifa. */
    function dl_tarifaDomicilio(?float $valor): void
    {
        ['zona' => $zona, 'domiciliario' => $domiciliario] = app('pruebas.domicilio_local');

        Tarifa::query()->where('transportadora_id', $domiciliario->id)->where('zona_id', $zona->id)->delete();

        if ($valor !== null) {
            Tarifa::query()->create([
                'transportadora_id' => $domiciliario->id, 'zona_id' => $zona->id, 'valor' => $valor,
                'vigente_desde' => now()->subDay()->toDateString(),
            ]);
        }
    }
}
