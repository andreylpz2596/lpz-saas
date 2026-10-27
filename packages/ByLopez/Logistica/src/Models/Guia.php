<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Enums\EstadoRecaudo;
use ByLopez\Logistica\Enums\OrigenGuia;
use ByLopez\Logistica\Enums\PagadorFlete;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * GUIA del envio (dato): numero, tracking, transportadora, flete y recaudo esperado. El documento PDF es otra cosa
 * (GuiaArchivo). Una guia por Shipment. Solo la escriben DespachoService y, en el futuro, un adaptador de API.
 *
 * Inmutable salvo `tracking`, `proveedor_referencia` y `recaudo_estado`: lo unico que una API o la conciliacion de
 * recaudos completaran despues. Numero y transportadora solo cambian por una CORRECCION auditada (corrigiendo()), que
 * deja el historial en GuiaCorreccion. Alcance honesto: eventos de Eloquent; SQL directo los salta.
 */
class Guia extends Model
{
    /** Campos que pueden cambiar despues del registro (y nada mas). */
    private const ACTUALIZABLES = ['tracking', 'proveedor_referencia', 'recaudo_estado', 'updated_at'];

    protected $table = 'bylopez_lg_guias';

    protected $fillable = [
        'order_id', 'shipment_id', 'transportadora_codigo', 'numero_guia', 'consecutivo_interno', 'tracking', 'origen_guia', 'generada_por',
        'proveedor_referencia', 'valor_flete', 'flete_pagador', 'recaudo_esperado', 'recaudo_estado', 'moneda', 'observacion',
        // Domicilios locales F1: snapshot de condicion, pagador deducido, zona y costo (inmutables como el resto).
        'transportadora_tipo', 'liquida_cuenta_corriente', 'condicion_envio', 'pagador_deducido', 'motivo_pagador', 'zona_id',
        'zona_nombre_snapshot', 'zona_origen', 'tarifa_sugerida', 'costo_adicional', 'motivo_costo',
        // C1: recaudo que calculo el servidor para ESTA guia y, si se modifico, por que (inmutables como el resto).
        'recaudo_sugerido', 'motivo_recaudo',
        // F6: decision de cobro con la que salio la guia (cobrar al entregar / credito autorizado), si el pedido la necesitaba.
        'disposicion_cobro_id',
        'actor_admin_id', 'actor_name_snapshot', 'idempotency_key', 'huella',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'shipment_id' => 'integer',
        'origen_guia' => OrigenGuia::class,
        'consecutivo_interno' => 'integer',
        'flete_pagador' => PagadorFlete::class,
        'recaudo_estado' => EstadoRecaudo::class,
        'valor_flete' => 'decimal:2',
        'recaudo_esperado' => 'decimal:2',
        'recaudo_sugerido' => 'decimal:2',
        'tarifa_sugerida' => 'decimal:2',
        'costo_adicional' => 'decimal:2',
        'liquida_cuenta_corriente' => 'boolean',
        'zona_id' => 'integer',
        'actor_admin_id' => 'integer',
    ];

    /** Campos que SOLO puede cambiar una correccion auditada (CorregirGuiaService, dentro de `corrigiendo()`). */
    private const CORREGIBLES = ['transportadora_codigo', 'numero_guia'];

    private static bool $corrigiendo = false;

    /**
     * Abre la unica ventana en la que numero y transportadora pueden cambiar. La usa CorregirGuiaService, que escribe en
     * la misma transaccion la fila de historial. Cualquier otro update de esos campos sigue lanzando REGISTRO_INMUTABLE.
     */
    public static function corrigiendo(callable $operacion): mixed
    {
        self::$corrigiendo = true;

        try {
            return $operacion();
        } finally {
            self::$corrigiendo = false;
        }
    }

    protected static function booted(): void
    {
        static::updating(function (self $guia) {
            $permitidos = self::$corrigiendo ? array_merge(self::ACTUALIZABLES, self::CORREGIBLES) : self::ACTUALIZABLES;

            // GI (guia interna): el numero lo asigna el sistema y NUNCA cambia, ni siquiera por una correccion auditada.
            if ($guia->origen_guia === OrigenGuia::INTERNA && $guia->isDirty('numero_guia')) {
                throw new Invalida(Invalida::GUIA_INTERNA_INMUTABLE, ['guia' => $guia->getKey()]);
            }

            if (array_diff(array_keys($guia->getDirty()), $permitidos) !== []) {
                throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['guia' => $guia->getKey()]);
            }
        });

        static::deleting(fn (self $guia) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['guia' => $guia->getKey()]));
    }

    /** Guia generada por ByLopez (GI-000001): lo decide el origen GUARDADO en la guia, no la configuracion actual de la transportadora. */
    public function esInterna(): bool
    {
        return $this->origen_guia === OrigenGuia::INTERNA;
    }

    public function correcciones(): HasMany
    {
        return $this->hasMany(GuiaCorreccion::class, 'guia_id')->orderByDesc('id');
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(GuiaArchivo::class, 'guia_id')->orderByDesc('version');
    }

    public function archivoVigente(): HasOne
    {
        return $this->hasOne(GuiaArchivo::class, 'guia_id')->where('vigente', 1);
    }
}
