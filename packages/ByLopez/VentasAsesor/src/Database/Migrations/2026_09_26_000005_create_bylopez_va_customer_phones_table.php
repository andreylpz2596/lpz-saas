<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * RT-04B1a — quinta migracion del modulo, ADITIVA. No toca tablas del core.
 *
 * Indice comercial de telefonos del Customer de Bagisto. NO es una identidad ni un
 * contacto paralelo: no guarda nombre, email, grupo ni direccion.
 *
 * Invariantes protegidas POR LA BASE, no por PHP:
 *   - un telefono normalizado pertenece a UN solo Customer (D-RT04-PHONE-01):
 *     UNIQUE (telefono_normalizado);
 *   - un Customer tiene como mucho UN principal: `principal` vale 1 o NULL (nunca 0)
 *     y UNIQUE (customer_id, principal). MySQL/MariaDB admiten varios NULL en un
 *     indice unico, asi que los secundarios (NULL) no chocan entre si.
 *
 * El principal es el reflejo normalizado de `customers.phone`, que sigue siendo el
 * telefono del Customer para el core (panel, perfil de tienda, exportaciones).
 * Solo se indexan telefonos normalizables (E.164): los ambiguos o invalidos no
 * entran y el indexador historico los reporta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_customer_phones', function (Blueprint $table) {
            $table->id();

            // `customers.id` es increments() (int unsigned).
            $table->unsignedInteger('customer_id');

            // E.164: '+' y como maximo 15 digitos.
            $table->string('telefono_normalizado', 16);

            // Tal como se escribio (o como esta en customers.phone). Permite volver a
            // normalizar si cambian las reglas y detectar divergencias con la ficha.
            $table->string('telefono_original', 255);

            // 1 = principal; NULL = secundario. Nunca 0 (ver cabecera).
            $table->boolean('principal')->nullable();

            $table->timestamps();

            $table->unique('telefono_normalizado', 'bvacp_normalizado_unique');

            // Tambien sirve de indice para la clave foranea (prefijo customer_id).
            $table->unique(['customer_id', 'principal'], 'bvacp_customer_principal_unique');

            /*
             * CASCADE, no RESTRICT: es un indice derivado. Borrar un cliente desde el
             * panel de Bagisto no debe fallar por el, y sin cliente no tiene sentido.
             */
            $table->foreign('customer_id', 'bvacp_customer_fk')
                ->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    /** Solo su propia tabla: no revierte nada de las migraciones anteriores. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_customer_phones');
    }
};
