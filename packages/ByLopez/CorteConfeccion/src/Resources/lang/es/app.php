<?php

return [
    'menu' => [
        'title'             => 'Corte y Confeccion',
        'compras-tela'      => 'Compras de tela',
        'proveedores-tela'  => 'Proveedores de tela',
        'rollos'            => 'Rollos',
        'ordenes-corte'     => 'Ordenes de corte',
        'recepciones-corte' => 'Recepciones de corte',
    ],

    'acl' => [
        'title'             => 'Corte y Confeccion',
        'compras-tela'      => 'Compras de tela',
        'proveedores-tela'  => 'Proveedores de tela',
        'rollos'            => 'Rollos',
        'ordenes-corte'     => 'Ordenes de corte',
        'recepciones-corte' => 'Recepciones de corte',
        'operar'            => 'Operar',
    ],

    'messages' => [
        'created'   => 'Registro creado correctamente.',
        'updated'   => 'Registro actualizado correctamente.',
        'cancelled' => 'Registro anulado correctamente.',
        'closed'    => 'Orden cerrada correctamente.',
    ],

    'errors' => [
        'rollo-no-disponible'   => 'El rollo no esta disponible para corte.',
        'rollo-reservado'       => 'Uno de los rollos ya esta reservado en otra orden abierta.',
        'rollos-incompatibles'  => 'Los rollos seleccionados deben tener el mismo tipo de tela, color y gramaje.',
        'orden-no-pendiente'    => 'La orden no esta pendiente de entrega.',
        'peso-insuficiente'     => 'El peso entregado supera el peso disponible del rollo.',
        'transicion-invalida'   => 'La orden no permite esta transicion de estado.',
        'orden-terminal'        => 'La orden esta anulada o cerrada.',
        'orden-sin-recepcion'   => 'La orden debe tener recepcion y estar recibida en bodega.',
        'orden-sin-cantidades-reales' => 'No se puede cerrar una orden sin cantidades reales cortadas.',
        'orden-cerrada'         => 'Una orden cerrada no puede anularse.',
        'no-recibir-terminal'   => 'No se puede recibir una orden anulada o cerrada.',
        'recepcion-existente'   => 'La orden ya tiene recepcion registrada.',
        'peso-recepcion-excede' => 'La suma de pesos de recepcion supera el peso entregado.',
        'cantidad-cortada-no-autorizada' => 'No tienes un rol autorizado para modificar la cantidad cortada.',
        'rollo-con-cortes'      => 'No se puede eliminar un rollo que ya tiene ordenes de corte.',
        'rollo-codigo-duplicado'=> 'Ya existe un rollo con uno de los codigos ingresados.',
        'peso-menor-al-usado'   => 'El peso inicial no puede ser menor al peso ya usado en corte.',
        'tipo-tela-con-datos'   => 'No se puede desmarcar un tipo de tela que ya tiene referencias o compras asociadas.',
        'referencia-usada'      => 'No se puede eliminar una referencia que ya fue usada en una compra de tela.',
        'referencia-duplicada'  => 'Ya existe una referencia con el mismo tipo de tela, color, referencia y gramaje.',
    ],
];
