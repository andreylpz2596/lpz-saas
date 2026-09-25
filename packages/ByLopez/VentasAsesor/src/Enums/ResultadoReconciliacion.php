<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Resultado del analisis de un pedido por el comando de reconciliacion (02B1B).
 * No es un estado de atribucion: describe QUE encontro el analisis y que hizo
 * (o habria hecho) el comando. Los estados persistidos siguen siendo
 * EstadoAtribucion.
 */
enum ResultadoReconciliacion: string
{
    /** Ya tiene atribucion valida: se ignora, nunca se sobrescribe. */
    case YA_ATRIBUIDO = 'ya_atribuido';

    /** Hay evidencia persistida inequivoca; el modo persistente registraria esa atribucion. */
    case RECONCILIABLE = 'reconciliable';

    /** Igual que RECONCILIABLE, pero ya escrito (solo modo persistente). */
    case RECONCILIADO = 'reconciliado';

    /** Sin evidencia suficiente: no determinable. El modo persistente lo deja sin asignar/desconocido. */
    case SIN_EVIDENCIA = 'sin_evidencia';

    /** Ya estaba en fallo_tecnico: no se sobrescribe; requiere una decision humana. */
    case FALLO_TECNICO = 'fallo_tecnico';

    /** Existe una atribucion distinta de la que dicta la evidencia: se informa, no se resuelve. */
    case CONFLICTO = 'conflicto';

    /** El analisis o la escritura de ese pedido fallo; los demas pedidos no se ven afectados. */
    case ERROR = 'error';

    /** Resultados que en modo persistente terminan con una escritura. */
    public function escribiria(): bool
    {
        return in_array($this, [self::RECONCILIABLE, self::SIN_EVIDENCIA], true);
    }
}
