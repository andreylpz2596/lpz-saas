<?php

namespace ByLopez\Cargues\Extensiones\Concerns;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use Illuminate\Support\Facades\DB;

/**
 * Comun a las extensiones que APLICAN fila por fila con los servicios de la aplicacion (Contracts/ExtensionTipo): la marca
 * final de cada fila va en la MISMA transaccion que sus efectos, asi que la intermedia debe vivir en la base de la aplicacion.
 * Requiere la propiedad `$conexiones` (ConexionTipo).
 */
trait AplicaPorFila
{
    /** Marca final de una fila PLANEADA (solo si sigue sin marca: una fila ya marcada no se toca). */
    protected function marcar(string $tabla, int $cgId, string $estado, ?string $detalle, ?string $codigo = null, ?string $mensaje = null): void
    {
        DB::table($tabla)->where('cg_id', $cgId)->whereNull('cg_estado')->update([
            'cg_estado' => $estado, 'cg_detalle' => $detalle, 'cg_codigo' => $codigo,
            'cg_mensaje' => $mensaje === null ? null : mb_substr($mensaje, 0, 500), 'cg_procesado_en' => now(),
        ]);
    }

    /** La marca de cada fila va en la MISMA transaccion que sus efectos: la intermedia debe estar en la base de la aplicacion. */
    protected function exigirMismaBase(string $conexion): void
    {
        $c = $this->conexiones->conexion($conexion);
        $app = DB::connection();

        if ($c->getName() !== $app->getName() && ($c->getDatabaseName() !== $app->getDatabaseName() || ($c->getConfig('host') ?? null) !== ($app->getConfig('host') ?? null) || ($c->getConfig('port') ?? null) !== ($app->getConfig('port') ?? null))) {
            throw new OperacionInvalidaException('EXTENSION_CONEXION_DISTINTA', 'Este tipo aplica por los servicios de la aplicación y su intermedia debe estar en la base de la aplicación (conexión «'.$app->getName().'»). La conexión del tipo es otra.');
        }
    }
}
