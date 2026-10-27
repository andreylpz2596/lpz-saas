<?php

namespace ByLopez\VentasAsesor\Support\Geo;

/**
 * UNICA equivalencia entre el departamento DIVIPOLA (DANE) y el estado de Bagisto
 * (`country_states.code`, pais CO) — D-58. Es dato estructural y revisable, no logica de la venta.
 *
 * Se usa SOLO al importar: el importador la valida contra `country_states` (falla cerrado si un
 * departamento no tiene equivalencia o su estado no existe) y deja la relacion resuelta en
 * `bylopez_geo_departamentos.country_state_id`. En la venta no se vuelve a comparar nombres.
 *
 * Bogota, D.C. (11) es una entidad propia y NUNCA se mapea a Cundinamarca (25): su estado `BOG`
 * lo crea la migracion `…000010_agregar_estado_bogota_dc_a_country_states`.
 */
final class EquivalenciaDepartamentos
{
    /** Codigo del estado de Bagisto creado para Bogota, D.C. */
    public const ESTADO_BOGOTA = 'BOG';

    /** @var array<string, string> codigo DIVIPOLA (2) => country_states.code */
    public const MAPA = [
        '05' => 'ANT', // Antioquia
        '08' => 'ATL', // Atlántico
        '11' => self::ESTADO_BOGOTA, // Bogotá, D.C.
        '13' => 'BOL', // Bolívar
        '15' => 'BOY', // Boyacá
        '17' => 'CAL', // Caldas
        '18' => 'CAQ', // Caquetá
        '19' => 'CAU', // Cauca
        '20' => 'CES', // Cesar
        '23' => 'COR', // Córdoba
        '25' => 'CUN', // Cundinamarca
        '27' => 'CHO', // Chocó
        '41' => 'HUI', // Huila
        '44' => 'LAG', // La Guajira
        '47' => 'MAG', // Magdalena
        '50' => 'MET', // Meta
        '52' => 'NAR', // Nariño
        '54' => 'NSA', // Norte de Santander
        '63' => 'QUI', // Quindío
        '66' => 'RIS', // Risaralda
        '68' => 'SAN', // Santander
        '70' => 'SUC', // Sucre
        '73' => 'TOL', // Tolima
        '76' => 'VAC', // Valle del Cauca
        '81' => 'ARA', // Arauca
        '85' => 'CAS', // Casanare
        '86' => 'PUT', // Putumayo
        '88' => 'SAP', // San Andrés, Providencia y Santa Catalina
        '91' => 'AMA', // Amazonas
        '94' => 'GUA', // Guainía
        '95' => 'GUV', // Guaviare
        '97' => 'VAU', // Vaupés
        '99' => 'VID', // Vichada
    ];

    public static function estadoDe(string $codigoDivipola): ?string
    {
        return self::MAPA[$codigoDivipola] ?? null;
    }
}
