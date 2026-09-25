<?php

namespace ByLopez\VentasAsesor\Support;

use ByLopez\VentasAsesor\DataObjects\ContextoComercial;

/**
 * Punto de extension de la reconciliacion historica: una FUENTE DETERMINISTA de
 * evidencia sobre como nacio un pedido.
 *
 * Una fuente devuelve un ContextoComercial solo si un dato PERSISTIDO del pedido
 * prueba, sin ambiguedad, su origen (y, si corresponde, su asesor o su creador).
 * Devuelve null cuando no puede probarlo. Nunca adivina, nunca usa la peticion en
 * curso ni al usuario que ejecuta el comando.
 *
 * HOY NO EXISTE NINGUNA: el esquema de Bagisto no guarda quien ni por donde creo un
 * pedido (auditado en la 02B1B, doc 10), asi que los pedidos anteriores al listener
 * quedan sin evidencia. Las fuentes se registran con el tag
 * `ventasasesor.fuentes_evidencia` cuando una fase futura cree un dato persistido
 * que las justifique (p. ej. una referencia externa o un contexto durable).
 */
interface FuenteEvidenciaHistorica
{
    /** Nombre estable de la fuente, para el motivo del historial y el reporte. */
    public function nombre(): string;

    /**
     * @param  object  $pedido  Fila del pedido con solo hechos persistidos (id, cart_id, status, ...).
     */
    public function contextoPara(object $pedido): ?ContextoComercial;
}
