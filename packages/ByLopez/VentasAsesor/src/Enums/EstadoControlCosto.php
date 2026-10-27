<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Estado de la comparacion costo comisionable (Bagisto) vs costo productivo (CorteConfeccion) de una variante
 * (D-COST-04/07). Solo informa: nunca cambia el costo.
 *
 * Con costo productivo PARCIAL (hoy solo tela) el valor es una COTA INFERIOR del costo real: si aun asi supera al
 * comisionable, la alerta es cierta (REVISAR); si no lo supera, no se puede concluir nada (PARCIAL), nunca «OK».
 * OK y FAVORABLE solo existen con un costo productivo COMPLETO, que CorteConfeccion todavia no registra.
 * Sin tolerancia: se alerta apenas productivo > comisionable (una tolerancia seria configuracion futura).
 */
enum EstadoControlCosto: string
{
    case SIN_COSTO_COMISIONABLE = 'sin_costo_comisionable';

    case SIN_DATOS_PRODUCCION = 'sin_datos_produccion';

    /** Costo productivo (aun parcial) MAYOR que el comisionable: Camilo decide si actualiza (D-COST-04). */
    case REVISAR = 'revisar';

    /** Costo productivo parcial menor o igual: no concluyente (faltan componentes). */
    case PARCIAL = 'parcial';

    /** Costo productivo completo igual al comisionable. */
    case OK = 'ok';

    /** Costo productivo completo menor: la eficiencia es de la empresa, la comision no sube (D-COST-03). */
    case FAVORABLE = 'favorable';

    public static function evaluar(?float $comisionable, ?float $productivo, bool $productivoCompleto): self
    {
        if ($comisionable === null || $comisionable <= 0) {
            return self::SIN_COSTO_COMISIONABLE;
        }

        if ($productivo === null) {
            return self::SIN_DATOS_PRODUCCION;
        }

        if ($productivo > $comisionable) {
            return self::REVISAR;
        }

        if (! $productivoCompleto) {
            return self::PARCIAL;
        }

        return $productivo < $comisionable ? self::FAVORABLE : self::OK;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(fn (self $e) => $e->value, self::cases());
    }
}
