<?php

use ByLopez\VentasAsesor\Services\Catalogo\CodigoSkuService;
use ByLopez\VentasAsesor\Services\Venta\Dictado\ResolutorDictado;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../helpersFase03b.php';

uses(TestCase::class);

/*
| DIC-04 — Doc. 34 · Resolutor contra el catalogo (jornada 2026-11-26): crop top sin talla segun lo que exista, SKU dicho buscado
| EXACTO, opciones concretas de talla/tipo, tipo predeterminado «Crop top» y palabras no usadas como aviso.
*/

function dic04_opcion(string $texto): int
{
    $aid = DB::table('attributes')->where('code', 'color')->value('id');
    $id = DB::table('attribute_options')->where('attribute_id', $aid)->where('admin_name', $texto)->value('id');

    return (int) ($id ?: DB::table('attribute_options')->insertGetId(['attribute_id' => $aid, 'admin_name' => $texto, 'sort_order' => 999]));
}

function dic04_producto(string $base, array $tallas): array
{
    $padre = f03b_configurable(10000.0);
    DB::table('products')->where('id', $padre->id)->update(['sku' => $base]);
    DB::table('product_flat')->where('product_id', $padre->id)->update(['name' => 'Producto '.$base, 'status' => 1]);
    $ids = ['padre' => (int) $padre->id];
    foreach ($padre->variants()->orderBy('id')->get()->values() as $i => $v) {
        if (! isset($tallas[$i])) {
            break;
        }
        DB::table('products')->where('id', $v->id)->update(['sku' => $base.'-'.$tallas[$i]]);
        $ids[$tallas[$i]] = (int) $v->id;
    }

    return $ids;
}

beforeEach(function () {
    DB::table(CodigoSkuService::TABLA_COLORES)->delete();
    foreach (['Negro' => 941, 'Lila' => 942, 'Rojo' => 943] as $color => $codigo) {
        DB::table(CodigoSkuService::TABLA_COLORES)->insert(['attribute_option_id' => dic04_opcion($color), 'codigo' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->negroTop = dic04_producto('941D-TP', ['M', 'U']);     // crop top con M y Única
    $this->lilaTop = dic04_producto('942D-TP', ['U']);           // crop top solo Única
    $this->rojoDama = dic04_producto('943D-DZ', ['S', 'M']);
    $this->resolutor = app(ResolutorDictado::class);
});

test('DIC04-1 crop top sin talla: con M y Única pregunta con las dos opciones; con solo Única la toma con aviso', function () {
    [$negro] = $this->resolutor->interpretar('crop top negro 2');
    [$lila] = $this->resolutor->interpretar('crop top lila 3');

    expect($negro['estado'])->toBe('error')->and($negro['variante_id'])->toBeNull()
        ->and($negro['opciones_talla'])->toBe(['M', 'U'])->and($negro['mensaje'])->toContain('M y Única')
        ->and($lila['estado'])->toBe('aviso')->and($lila['sku'])->toBe('942D-TP-U')->and($lila['mensaje'])->toContain('única del crop top');

    // La Única heredada del crop top anterior tambien se decide con el catalogo (antes pasaba como dicha).
    $dos = $this->resolutor->interpretar('crop top lila 3, negro 2');
    expect([$dos[0]['estado'], $dos[1]['estado']])->toBe(['aviso', 'error'])->and($dos[1]['opciones_talla'])->toBe(['M', 'U']);
});

test('DIC04-2 SKU dicho: se busca EXACTO; si no existe no se reemplaza por uno parecido; incompleto ofrece las tallas', function () {
    [$exacto] = $this->resolutor->interpretar('943D-DZ-M 4');
    [$noExiste] = $this->resolutor->interpretar('943D-DZ-XL 4');
    [$incompleto] = $this->resolutor->interpretar('943D-DZ 4');
    [$contradictorio] = $this->resolutor->interpretar('943D-DZ-M 4 caballero');

    expect([$exacto['estado'], $exacto['sku'], $exacto['variante_id']])->toBe(['ok', '943D-DZ-M', $this->rojoDama['M']])
        ->and($noExiste['estado'])->toBe('error')->and($noExiste['variante_id'])->toBeNull()->and($noExiste['mensaje'])->toContain('«943D-DZ-XL» no existe')
        ->and($incompleto['estado'])->toBe('error')->and($incompleto['opciones_talla'])->toBe(['S', 'M'])
        ->and($contradictorio['estado'])->toBe('error')->and($contradictorio['mensaje'])->toContain('no coincide');
});

test('DIC04-3 tipo predeterminado «Crop top» por HTTP: completa lo no dicho y lo marca como contexto', function () {
    $r = $this->actingAs(f03b_admin(), 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.dictado.interpretar'), ['texto' => 'lila 2', 'tipo_defecto' => 'TP'])
        ->assertOk()->json('lineas.0');

    expect([$r['estado'], $r['sku'], $r['tipo_por_contexto'], $r['linea']])->toBe(['aviso', '942D-TP-U', true, 'TP'])
        ->and($r['nota'])->toContain('Crop top');

    $this->actingAs(f03b_admin(), 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.dictado.interpretar'), ['texto' => 'lila 2', 'tipo_defecto' => 'X'])
        ->assertUnprocessable();
});

test('DIC04-4 palabras no usadas y correcciones se muestran; la duda de tipo no la resuelve el predeterminado', function () {
    [$camel] = $this->resolutor->interpretar('roja camel dama M 2');
    [$corregida] = $this->resolutor->interpretar('roja dama M diez, no, mejor ocho');
    $duda = $this->resolutor->interpretar('roja M dos dama negra M 3', 'H');

    expect($camel['estado'])->toBe('aviso')->and($camel['mensaje'])->toContain('camel')
        ->and([$corregida['estado'], $corregida['cantidad']])->toBe(['ok', 8])->and($corregida['nota'])->toContain('10 → 8')
        // «dama» entre dos prendas: la primera queda en duda aunque haya tipo predeterminado; se ofrecen los tipos que existen.
        ->and($duda[0]['estado'])->toBe('error')->and($duda[0]['problemas'])->toContain('tipo_dudoso')
        ->and($duda[0]['opciones_tipo'])->toContain('D');
});
