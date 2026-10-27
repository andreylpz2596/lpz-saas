<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * C5 + INVENTARIO NO VENDIBLE «DEFECTUOSAS» (ADITIVA; lo historico no se toca ni se reinterpreta).
 *
 * 1) `bylopez_lg_catalogo_defectos`: catalogo PARAMETRIZABLE de motivos del defecto (que tiene la prenda) y de origenes
 *    probables (donde creemos que ocurrio). Motivo ≠ responsabilidad. `requiere_detalle` = «otro» exige descripcion.
 *    El codigo es estable (lo referencian las unidades); el nombre y el orden se pueden cambiar sin migrar.
 * 2) `bylopez_lg_unidades_defectuosas`: LA zona de inventario NO VENDIBLE. Una fila por grupo de unidades defectuosas de una
 *    linea recibida (motivo + origen + detalle). Existen fisicamente, son de ByLopez, no suman al stock vendible
 *    (`product_inventories` no se toca) y no se pierden: estado inicial `pendiente_revision` (preparado para `recuperada` /
 *    `perdida`; esa gestion es una fase posterior). Guarda variante fisica, pedido, envio, bodega y quien recibio: base para
 *    medir defectuosas por mes / referencia / variante / origen / motivo y, mas adelante, el costo de las perdidas.
 *    Suma de `cantidad` por linea = `novedad` de la linea (lo valida el servicio de recepcion).
 * 3) `bylopez_lg_recepcion_devolucion_items.valor_unitario / valor_devuelto`: SNAPSHOT historico del valor de lo recibido
 *    (precio efectivo de la linea del pedido: (base_total − descuento + impuesto) / qty_ordered × recibidas). Es el AJUSTE
 *    trazable de C5: Cartera lo deriva de las recepciones confirmadas; el pedido original no se modifica. NULL en las
 *    recepciones anteriores a esta migracion: no afectan Cartera (sin backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_catalogo_defectos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 10);
            $table->string('codigo', 40);
            $table->string('nombre', 80);
            $table->boolean('requiere_detalle')->default(false);
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->unique(['tipo', 'codigo'], 'bylgcd_tipo_codigo_unique');
        });

        DB::statement("ALTER TABLE bylopez_lg_catalogo_defectos ADD CONSTRAINT bylgcd_tipo_chk CHECK (tipo IN ('motivo', 'origen'))");

        $ahora = now();
        $filas = [];

        foreach ([
            ['mancha', 'Mancha'], ['suciedad', 'Suciedad'], ['costura', 'Costura'], ['rota', 'Rota'],
            ['defecto_tela', 'Defecto de tela'], ['estampado', 'Estampado / personalización'], ['color_afectado', 'Color afectado'],
            ['talla_etiqueta', 'Talla / etiqueta'], ['otro', 'Otro'],
        ] as $i => [$codigo, $nombre]) {
            $filas[] = ['tipo' => 'motivo', 'codigo' => $codigo, 'nombre' => $nombre, 'requiere_detalle' => $codigo === 'otro', 'activo' => true, 'orden' => ($i + 1) * 10, 'created_at' => $ahora, 'updated_at' => $ahora];
        }

        foreach ([
            ['por_determinar', 'Por determinar'], ['cliente', 'Cliente'], ['transporte', 'Transporte'], ['confeccion', 'Confección'],
            ['corte', 'Corte'], ['tela_proveedor', 'Tela / proveedor'], ['bodega', 'Bodega'], ['otro', 'Otro'],
        ] as $i => [$codigo, $nombre]) {
            $filas[] = ['tipo' => 'origen', 'codigo' => $codigo, 'nombre' => $nombre, 'requiere_detalle' => false, 'activo' => true, 'orden' => ($i + 1) * 10, 'created_at' => $ahora, 'updated_at' => $ahora];
        }

        DB::table('bylopez_lg_catalogo_defectos')->insert($filas);

        Schema::create('bylopez_lg_unidades_defectuosas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recepcion_id');
            $table->foreignId('recepcion_item_id');
            $table->unsignedSmallInteger('grupo')->default(1);
            $table->unsignedInteger('shipment_id');
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('order_item_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('inventory_source_id');
            $table->unsignedInteger('cantidad');
            $table->string('motivo_codigo', 40);
            $table->string('origen_codigo', 40)->default('por_determinar');
            $table->string('detalle', 500)->nullable();
            $table->string('observacion', 500)->nullable();
            $table->string('estado', 20)->default('pendiente_revision');
            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_name_snapshot', 191);
            $table->timestamps();

            $table->unique(['recepcion_item_id', 'grupo'], 'bylgud_item_grupo_unique');
            $table->index('product_id', 'bylgud_product_idx');
            $table->index('order_id', 'bylgud_order_idx');
            $table->index(['estado', 'created_at'], 'bylgud_estado_idx');
            $table->index('motivo_codigo', 'bylgud_motivo_idx');
            $table->index('origen_codigo', 'bylgud_origen_idx');

            $table->foreign('recepcion_id', 'bylgud_recepcion_fk')->references('id')->on('bylopez_lg_recepciones_devolucion')->restrictOnDelete();
            $table->foreign('recepcion_item_id', 'bylgud_item_fk')->references('id')->on('bylopez_lg_recepcion_devolucion_items')->restrictOnDelete();
            $table->foreign('product_id', 'bylgud_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('order_id', 'bylgud_order_fk')->references('id')->on('orders')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_lg_unidades_defectuosas ADD CONSTRAINT bylgud_cantidad_chk CHECK (cantidad > 0)');
        DB::statement("ALTER TABLE bylopez_lg_unidades_defectuosas ADD CONSTRAINT bylgud_estado_chk CHECK (estado IN ('pendiente_revision', 'recuperada', 'perdida'))");

        Schema::table('bylopez_lg_recepcion_devolucion_items', function (Blueprint $table) {
            $table->decimal('valor_unitario', 14, 4)->nullable()->after('faltantes');
            $table->decimal('valor_devuelto', 12, 2)->nullable()->after('valor_unitario');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_lg_recepcion_devolucion_items', function (Blueprint $table) {
            $table->dropColumn(['valor_unitario', 'valor_devuelto']);
        });

        Schema::dropIfExists('bylopez_lg_unidades_defectuosas');
        Schema::dropIfExists('bylopez_lg_catalogo_defectos');
    }
};
