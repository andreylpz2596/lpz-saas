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

    // D10 V1: medios por los que ByLopez puede DEVOLVER el dinero de un saldo a favor (salida real; no son medios de cobro).
    'credito' => [
        // Unico rol que puede OPERAR dinero del credito (aplicar/reembolsar/reversar/ajustar). Un rol `all` que no sea este NO opera ni ve.
        'rol_administrador_id' => (int) env('VENTASASESOR_CREDITO_ROL_ADMIN_ID', 1),
        'medios_devolucion' => ['transferencia', 'efectivo', 'otro'],
        // Los que exigen referencia o comprobante de la salida de dinero.
        'medios_con_referencia' => ['transferencia'],
    ],

    'pagos' => [
        /*
         * D-49: metodos de pago permitidos para el canal asesor. Lo ofrecido al borrador
         * es esta lista INTERSECTADA con lo que Bagisto marca disponible ahora mismo
         * (Payment::getPaymentMethods()); nunca solo esta lista. Un gateway que Bagisto
         * active despues (p. ej. con credenciales) NO aparece si no esta aqui.
         */
        'metodos_permitidos' => ['cashondelivery', 'moneytransfer'],

        /*
         * PAG-B (D-59 / C-1, C-2): MEDIOS DE COBRO, la fuente UNICA y centralizada (Support\Cartera\MediosCobro).
         * `medio_cobro` NO es el metodo de pago del pedido (`order_payment.method`, lista de arriba): un pedido
         * contraentrega puede abonarse luego por transferencia, y un mismo metodo de Bagisto puede cubrir varios
         * medios reales. Se elige al registrar el pago y nunca se infiere del pedido.
         *
         * Por medio: `etiqueta` (clave de traduccion), `activo` y `referencia`: politica de la referencia del
         * pago (`requerida` | `opcional` | `no_aplica`). La politica vive aqui y no se dispersa en
         * controladores (C-1). Valores iniciales conceptuales (Doc. 05 §42.7); la lista final la confirma
         * Camilo. Sin integraciones con Nequi, Daviplata, bancos ni pasarelas.
         */
        /*
         * Plataformas donde entra el dinero de una TRANSFERENCIA (obligatoria en ese medio; los demas no la usan).
         * Para agregar una, basta una linea aqui y su traduccion en `cartera.plataformas`.
         */
        'plataformas_transferencia' => [
            'nequi' => ['etiqueta' => 'ventasasesor::app.cartera.plataformas.nequi', 'activo' => true],
            'bancolombia' => ['etiqueta' => 'ventasasesor::app.cartera.plataformas.bancolombia', 'activo' => true],
            'davivienda' => ['etiqueta' => 'ventasasesor::app.cartera.plataformas.davivienda', 'activo' => true],
        ],

        'medios_cobro' => [
            'transferencia' => ['etiqueta' => 'ventasasesor::app.cartera.medios.transferencia', 'activo' => true, 'referencia' => 'requerida', 'plataforma' => true],
            'efectivo' => ['etiqueta' => 'ventasasesor::app.cartera.medios.efectivo', 'activo' => true, 'referencia' => 'opcional'],
            'contraentrega_recaudo' => ['etiqueta' => 'ventasasesor::app.cartera.medios.contraentrega_recaudo', 'activo' => true, 'referencia' => 'opcional'],
            // D10: compensacion con saldo a favor. NUNCA seleccionable a mano (activo=false): solo lo crea AplicarCreditoService. No es dinero recibido.
            'credito_cliente' => ['etiqueta' => 'ventasasesor::app.cartera.medios.credito_cliente', 'activo' => false, 'referencia' => 'no_aplica'],
        ],

        /*
         * PAG-E: categorias SIMPLES de rechazo (codigo => clave de traduccion). El rechazo exige una categoria de
         * esta lista MAS un detalle en texto libre; el evento `rechazado` guarda ambos. No es un catalogo
         * complejo: si hace falta otra causa existe `otro`.
         */
        'motivos_rechazo' => [
            'no_encontrado' => 'ventasasesor::app.cartera.motivos.no_encontrado',
            'monto_no_coincide' => 'ventasasesor::app.cartera.motivos.monto_no_coincide',
            'referencia_incorrecta' => 'ventasasesor::app.cartera.motivos.referencia_incorrecta',
            'duplicado' => 'ventasasesor::app.cartera.motivos.duplicado',
            'efectivo_no_entregado' => 'ventasasesor::app.cartera.motivos.efectivo_no_entregado',
            'otro' => 'ventasasesor::app.cartera.motivos.otro',
        ],
    ],

    'cartera' => [
        /*
         * PAG-B (D-59 / C-3, Doc. 05 §42.8): FECHA DE CORTE de Cartera. Solo los pedidos con `created_at` igual
         * o posterior entran en Cartera; los anteriores son «historico» y no se tocan (sin pagos heredados,
         * sin backfill, `Invoice paid` jamas cuenta como pago).
         *
         * NULL (por defecto) = Cartera INACTIVA: falla cerrado, no se calculan saldos. NO se inventa una
         * fecha: el valor, el lugar definitivo donde se guarda (la propuesta es `core_config`, editable desde
         * el panel) y la incorporacion puntual de historicos estan PENDIENTES DE APROBACION (PAG-F). Mientras
         * tanto se lee de esta clave, que solo preve la capacidad. Formato: `Y-m-d` o `Y-m-d H:i:s`, en la
         * zona horaria de la aplicacion. Debe fijarse DESPUES de alinear la configuracion de `moneytransfer`.
         */
        'fecha_corte' => env('VENTASASESOR_CARTERA_FECHA_CORTE'),
    ],

    'nueva_venta' => [
        /*
         * 03G-B: canales con los que se puede iniciar una venta rapida (valores de CanalVenta).
         * Cada venta recibe su canal de forma explicita: no se recuerda el ultimo usado.
         */
        'canales' => ['tienda_fisica', 'whatsapp'],

        /*
         * Codigos de los atributos de variante. La talla ordena por el sort_order de sus
         * opciones en Bagisto.
         */
        'atributos' => ['color' => 'color', 'talla' => 'size'],

        /*
         * Accesos rapidos: lineas de producto del negocio. Se resuelven por SLUG (o, si no
         * hay, por nombre) de la categoria, nunca por id: los ids no son contrato. Un acceso
         * inexistente o ambiguo queda sin resolver y la UI lo muestra deshabilitado.
         */
        'accesos_rapidos' => [
            ['clave' => 'camiseta_caballero', 'etiqueta' => 'Camiseta caballero', 'slugs' => ['camiseta-caballero'], 'nombres' => ['CAMISETA CABALLERO']],
            ['clave' => 'camiseta_dama', 'etiqueta' => 'Camiseta dama', 'slugs' => ['camisetas-dama'], 'nombres' => ['CAMISETAS DAMA']],
            ['clave' => 'top_dama', 'etiqueta' => 'Top dama', 'slugs' => ['top-dama'], 'nombres' => ['TOP Dama']],
            ['clave' => 'camiseta_nino', 'etiqueta' => 'Camiseta niño', 'slugs' => ['camiseta-nino'], 'nombres' => ['CAMISETA NIÑO']],
        ],
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
        // Segundos que PHP deja correr UNA confirmacion (pedidos grandes pasan de los 30 s del php.ini). Siempre < lease.
        'tiempo_maximo_segundos' => (int) env('VENTASASESOR_CONFIRMACION_TIEMPO_MAXIMO', 120),
    ],

    /*
     * R2 (Doc. 23): proteccion de rentabilidad. TODO apagado por defecto: el bloqueo solo se activa cuando Camilo apruebe
     * los parametros reales y se revisen las listas de precios («Precios por grupo frente al mínimo»).
     */
    'rentabilidad' => [
        // true = la confirmacion de Nueva venta RECHAZA una venta normal con una referencia bajo su minimo (o sin datos).
        'bloqueo_activo' => (bool) env('VENTASASESOR_RENTABILIDAD_BLOQUEO', false),
        // P5 (pendiente): los costos por pedido (domicilio gratis, cargo sobre el total) bloquean o solo advierten.
        'bloquear_costos_pedido' => (bool) env('VENTASASESOR_RENTABILIDAD_BLOQUEAR_COSTOS_PEDIDO', false),
        // Doc. 23 §10.4: atributo (select) que da el TIPO DE TELA de la politica por referencia + tela. La familia de
        // Bagisto no garantiza una sola tela; se lee de la variante y, si no lo tiene, del producto padre.
        'atributo_tela' => 'tipo_tela',
    ],

    /*
     * R3/R4 (Doc. 23): comisiones. Sin fecha de inicio NINGUNA comision se habilita (la fecha de activacion y la politica de
     * historicos no estan aprobadas: nada retroactivo). Formato `Y-m-d H:i:s`, hora de America/Bogota.
     */
    'comisiones' => [
        'fecha_inicio' => env('VENTASASESOR_COMISIONES_FECHA_INICIO'),
        'zona_horaria' => 'America/Bogota',
        // Medios cuyo dinero queda en manos de un tercero (domiciliario/transportadora) hasta su liquidacion.
        'medios_custodia_tercero' => ['contraentrega_recaudo'],
        // Observacion con la que Logistica registra el cobro hecho al validar la entrega (CobroViaCartera).
        'observacion_cobro_en_entrega' => 'Cobro contra entrega · validacion de entrega',
        'comprobantes' => ['disco' => 'private', 'max_kb' => 5120, 'mimes' => ['pdf', 'jpg', 'jpeg', 'png']],

        /*
         * Doc. 23 §10.2 — reevaluacion AUTOMATICA tras los hechos reales (validacion, cobro, liquidacion, cancelacion,
         * reembolso, reasignacion). No es un parametro de negocio: sin fecha de inicio nada se habilita igual.
         * - `conexion` vacia = la de la aplicacion (QUEUE_CONNECTION; hoy `sync`: corre en la misma peticion, tras el
         *   commit). Con una cola real hace falta un worker: `php artisan queue:work --queue=<cola>`.
         * - Los reintentos los toma `ventas-asesor:comisiones:evaluar --bandeja`, programado cada 5 minutos: requiere el
         *   cron de Laravel (`* * * * * php artisan schedule:run`).
         */
        'reevaluacion' => [
            'automatica' => (bool) env('VENTASASESOR_COMISIONES_REEVALUACION_AUTOMATICA', true),
            'conexion' => env('VENTASASESOR_COMISIONES_COLA_CONEXION'),
            'cola' => env('VENTASASESOR_COMISIONES_COLA', 'default'),
            'lote' => 50,
            'max_intentos' => 8,
            'programar' => (bool) env('VENTASASESOR_COMISIONES_REEVALUACION_PROGRAMADA', true),
        ],
    ],
];
