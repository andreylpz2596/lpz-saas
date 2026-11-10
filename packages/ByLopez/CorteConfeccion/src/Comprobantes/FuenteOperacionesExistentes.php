<?php

namespace ByLopez\CorteConfeccion\Comprobantes;

/**
 * Contrato que cada modulo implementa para decir que operaciones y soportes ya conoce (cartera de prestadores hoy;
 * pagos de Ventas Asesor en el futuro). El servicio de comprobantes NO conoce tablas financieras.
 *
 * Cada coincidencia: ['operacion_id'?, 'soporte_id'?, 'movimiento_id', 'movimiento_codigo', 'fecha', 'valor',
 * 'estado_movimiento' ('registrado'|'reversado'), 'prestador' (clave opaca para filtrar por permisos)].
 */
interface FuenteOperacionesExistentes
{
    /** Soportes ya cargados con el mismo archivo (misma huella SHA-256), aunque tengan otro nombre. */
    public function soportesPorHuellaArchivo(string $sha256): array;

    /** Operaciones con la MISMA huella (referencia + contexto). Coincidencia fuerte. */
    public function operacionesPorHuella(string $huella): array;

    /** Operaciones que solo coinciden en parte (misma referencia, o mismo importe + fecha + moneda + direccion). */
    public function operacionesCandidatas(BorradorOperacion $borrador): array;
}
