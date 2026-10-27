<?php

/*
 * C6 — transicion del RECAUDO de la cuenta corriente. Una guia creada a partir de esta fecha (inclusive) es una guia C6: su
 * movimiento `recaudo` nace del COBRO REAL (origen_tipo = 'cobro'), nunca del `recaudo_esperado`. Sin fecha (por defecto) todo
 * sigue como antes de C6. Una guia que ya tiene un recaudo de origen `envio` es SIEMPRE legado, sin importar la fecha: nada
 * historico se reinterpreta. La fecha solo decide hacia adelante; el origen del movimiento es lo que lo distingue despues.
 */
return [
    'recaudo_desde_cobro_desde' => env('LOGISTICA_RECAUDO_DESDE_COBRO'),
];
