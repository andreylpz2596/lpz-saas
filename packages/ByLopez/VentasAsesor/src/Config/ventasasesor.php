<?php

return [
    'telefono' => [
        /*
         * Pais con el que se interpretan los numeros escritos SIN '+'. Solo 'CO' tiene
         * reglas propias en NormalizadorTelefonoBasico; con otro valor, un numero sin
         * '+' queda AMBIGUO (no se adivina el pais).
         */
        'pais_por_defecto' => 'CO',
    ],

    'borradores' => [
        /*
         * Dias habiles que un borrador de venta queda asignado a su propietario antes de
         * que otro asesor autorizado pueda reclamarlo (decision de Camilo Gaitan, 03B).
         */
        'dias_habiles_asignacion' => 1,
    ],

    'calendario_laboral' => [
        /*
         * Dias laborales ISO-8601 (1 = lunes ... 7 = domingo). D-35 (Camilo Gaitan): lunes a
         * sabado; el domingo no es laboral.
         */
        'dias_laborales' => [1, 2, 3, 4, 5, 6],

        /*
         * Festivos 'Y-m-d'. Vacio a proposito: no se codifican festivos en el codigo. Se
         * cargan por configuracion (o por una fuente futura detras del contrato
         * CalendarioLaboral).
         */
        'festivos' => [],
    ],
];
