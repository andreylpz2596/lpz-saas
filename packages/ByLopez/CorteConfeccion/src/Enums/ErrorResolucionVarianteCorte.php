<?php

namespace ByLopez\CorteConfeccion\Enums;

/**
 * Motivos por los que ResolvedorVarianteCorteService NO puede devolver un
 * simple para una seleccion (linea + color + valor del eje 2). Son errores
 * bloqueantes: nunca se "adivina" un producto.
 *
 * Los productos inactivos en tienda NO estan aqui a proposito: no bloquean
 * produccion, solo generan una AdvertenciaResolucionVarianteCorte.
 */
enum ErrorResolucionVarianteCorte: string
{
    case SinProductoParaColor = 'sin_producto_para_color';
    case ColorAmbiguo = 'color_ambiguo';
    case EjesNoSoportados = 'ejes_no_soportados';
    case TallaNoDisponible = 'talla_no_disponible';
    case VarianteDuplicada = 'variante_duplicada';
    case PostcondicionIncumplida = 'postcondicion_incumplida';

    public function mensaje(): string
    {
        return match ($this) {
            self::SinProductoParaColor => 'La linea no tiene ningun producto configurable para ese color y tipo de tela.',
            self::ColorAmbiguo => 'La linea tiene mas de un producto configurable para ese color y tipo de tela; hay que corregir el catalogo.',
            self::EjesNoSoportados => 'El producto configurable no tiene exactamente color + un eje adicional; no se soporta.',
            self::TallaNoDisponible => 'No existe una variante con ese valor de talla/eje para el color seleccionado.',
            self::VarianteDuplicada => 'Existe mas de una variante para ese color y valor de talla/eje; hay que corregir el catalogo.',
            self::PostcondicionIncumplida => 'La variante encontrada no cumple las postcondiciones esperadas (simple, hijo del configurable, misma familia).',
        };
    }
}
