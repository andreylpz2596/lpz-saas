<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ANTECEDENTES DE ENVIOS DEL CLIENTE segun un proveedor intermediario (p. ej. 99 Envios) — INFORMATIVO y ADITIVO.
 *
 *   ESTADO REPORTADO POR EL PROVEEDOR  ≠  ENTREGA REGISTRADA / VALIDADA (Logistica)  ≠  PAGO (Cartera)
 *
 * - `bylopez_va_cliente_envios_historicos`: UN renglon por ENVIO unico (proveedor + transportadora efectiva + guia) con el
 *   ultimo estado reportado (texto literal), su fecha si el archivo la trae y la PROCEDENCIA (cargue y fila). Lo escribe
 *   solo el cargue «Clientes historicos». No crea pedidos, entregas, pagos, abonos ni comisiones. Cuando la transportadora
 *   efectiva no esta en el archivo queda '' (clave) y se completa si un cargue posterior la trae sin ambiguedad.
 * - `bylopez_va_estados_envio_clase`: equivalencias EXPLICITAS texto de estado del proveedor → clase
 *   (entregado | devolucion | pendiente), cada una con su evidencia. Un estado sin equivalencia es «desconocido»: nunca se
 *   clasifica por parecido. La clase se calcula al LEER, asi que corregir una equivalencia corrige todos los conteos.
 *
 * Sin FK a Cargues (frontera entre paquetes). FK a `customers` con RESTRICT: no se borra un cliente con antecedentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_cliente_envios_historicos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id');
            $table->string('proveedor', 40);
            // '' = transportadora efectiva desconocida (el archivo no la trae). Va en la clave unica: un NULL permitiria duplicados.
            $table->string('transportadora_clave', 40)->default('');
            $table->string('numero_guia', 60)->collation('utf8mb4_bin');
            $table->string('estado_proveedor', 191);
            $table->dateTime('fecha_estado')->nullable();
            $table->unsignedBigInteger('primer_carga_id')->nullable();
            $table->unsignedBigInteger('carga_id')->nullable();
            $table->unsignedInteger('fila_origen')->nullable();
            $table->unsignedInteger('reportes')->default(1);
            $table->timestamps();

            $table->unique(['proveedor', 'transportadora_clave', 'numero_guia'], 'bylvach_envio_unique');
            $table->index(['customer_id', 'proveedor'], 'bylvach_cliente_idx');
            $table->index('numero_guia', 'bylvach_guia_idx');
            $table->foreign('customer_id', 'bylvach_customer_fk')->references('id')->on('customers')->restrictOnDelete();
        });

        Schema::create('bylopez_va_estados_envio_clase', function (Blueprint $table) {
            $table->id();
            $table->string('proveedor', 40);
            $table->string('estado_texto', 191);
            $table->string('clase', 20);
            $table->string('evidencia', 255);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['proveedor', 'estado_texto'], 'bylvaec_estado_unique');
        });

        DB::statement("ALTER TABLE bylopez_va_estados_envio_clase ADD CONSTRAINT bylvaec_clase_chk CHECK (clase IN ('entregado', 'devolucion', 'pendiente'))");

        // Solo equivalencias con evidencia en los archivos reales de 99 Envios (docs/10 y docs/13). Lo no listado es «desconocido».
        $ahora = now();
        $filas = [
            ['Entregada', 'entregado', 'Estado final «Entregada» en las exportaciones Cartera y Envíos completos de 99 Envíos.'],
            ['Devolución ratificada', 'devolucion', 'En Cartera, las filas «Devolución ratificada» llevan «Seguro Antidevoluciones» (el seguro cubre devoluciones).'],
            ['Devolucion Regional', 'devolucion', 'En Cartera, la fila «Devolucion Regional» lleva «Seguro Antidevoluciones» (devolución en curso).'],
            ['Admitida', 'pendiente', 'Estado intermedio de transporte en Envíos completos: ni entregada ni devuelta todavía.'],
            ['Creado', 'pendiente', 'Estado intermedio: guía creada, sin entrega ni devolución.'],
            ['Transito nacional', 'pendiente', 'Estado intermedio de transporte.'],
            ['Reparto', 'pendiente', 'Estado intermedio: en reparto, sin entrega confirmada.'],
            ['Intento de entrega', 'pendiente', 'Estado intermedio: intento sin entrega confirmada.'],
            ['Reclame en oficina', 'pendiente', 'Estado intermedio: el envío espera ser reclamado; no está entregado.'],
        ];

        DB::table('bylopez_va_estados_envio_clase')->insert(array_map(fn ($f) => [
            'proveedor' => '99envios', 'estado_texto' => $f[0], 'clase' => $f[1], 'evidencia' => $f[2], 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
        ], $filas));
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_cliente_envios_historicos');
        Schema::dropIfExists('bylopez_va_estados_envio_clase');
    }
};
