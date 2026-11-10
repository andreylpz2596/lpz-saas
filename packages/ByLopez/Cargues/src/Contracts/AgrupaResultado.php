<?php

namespace ByLopez\Cargues\Contracts;

use ByLopez\Cargues\Models\Carga;

/**
 * Revision AGRUPADA opcional de un tipo (se registra por la tabla intermedia en `cargues.resultado.agrupados`). El motor sigue
 * siendo generico: sin ella, la validacion y el resultado muestran solo el resumen y el detalle por fila. SOLO LEE lo persistido
 * (la intermedia, sus derivados y lo que la ejecucion dejo) y devuelve una estructura ya redactada que la interfaz pinta tal cual:
 *
 *   [
 *     'titulo' => string,
 *     'indicadores' => list<{etiqueta:string, n:int}>,
 *     'secciones' => list<{titulo:string, items:list<string>}>,          // p. ej. opciones desconocidas
 *     'grupos' => list<{
 *         titulo:string, subtitulo:?string, estado:string, estado_texto:string, tono:'verde'|'azul'|'gris'|'rojo'|'amarillo',
 *         enlace:?string, enlace_texto:?string, elementos:list<{texto:string, estado_texto:string, tono:string}>, motivos:list<string>, avisos:list<string>,
 *     }>,
 *     'filtros' => list<{estado:string, etiqueta:string}>,
 *     'truncado' => bool,
 *   ]
 */
interface AgrupaResultado
{
    public function agrupar(Carga $carga): array;
}
