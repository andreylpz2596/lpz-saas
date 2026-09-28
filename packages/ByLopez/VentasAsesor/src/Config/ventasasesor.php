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

    'pagos' => [
        /*
         * D-49: metodos de pago permitidos para el canal asesor. Lo ofrecido al borrador
         * es esta lista INTERSECTADA con lo que Bagisto marca disponible ahora mismo
         * (Payment::getPaymentMethods()); nunca solo esta lista. Un gateway que Bagisto
         * active despues (p. ej. con credenciales) NO aparece si no esta aqui.
         */
        'metodos_permitidos' => ['cashondelivery', 'moneytransfer'],
    ],

    'confirmacion' => [
        /*
         * D-55: segundos del lease de un intento de confirmacion (03F). Parametro TECNICO,
         * no regla de negocio: debe superar lo que tarda una confirmacion real (revalidacion
         * + pedido con SMTP y reindexado sincronos). Solo decide cuando otro intento puede
         * RECUPERAR un `confirmando` abandonado; la seguridad no depende de el (el
         * confirmation_token es el fencing token). Ajustable por entorno.
         */
        'lease_segundos' => (int) env('VENTASASESOR_CONFIRMACION_LEASE_SEGUNDOS', 300),
    ],
];
