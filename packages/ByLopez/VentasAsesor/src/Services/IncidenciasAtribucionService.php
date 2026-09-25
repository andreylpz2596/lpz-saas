<?php

namespace ByLopez\VentasAsesor\Services;

use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\Enums\CodigoIncidencia;
use ByLopez\VentasAsesor\Enums\EstadoIncidencia;
use ByLopez\VentasAsesor\Models\IncidenciaAtribucion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Unico punto autorizado para escribir incidencias de atribucion.
 *
 * No es un repositorio generico a proposito: expone solo dos operaciones con
 * reglas propias —detectar y resolver—, en vez de un create/update/delete que
 * cualquiera podria usar para dejar la cola en un estado imposible.
 *
 * Lo que NUNCA hace, aunque la incidencia lo describa:
 *   - modificar la atribucion comercial del pedido;
 *   - asignar, quitar o cambiar el asesor;
 *   - resolverse sola.
 *
 * Reasignar comercialmente sigue siendo trabajo de AsignacionAsesorService, que
 * exige permiso, motivo e historial. Esta tabla solo dice "mira esto".
 */
class IncidenciasAtribucionService
{
    /**
     * Nombres de campo que pueden persistirse en `campos_conflicto`. Cualquier otro
     * se descarta: la lista existe para que un nombre inventado por un llamador no
     * se convierta en un canal para meter datos libres en la tabla.
     *
     * @var list<string>
     */
    public const CAMPOS_PERMITIDOS = [
        ...ContextoComercial::CAMPOS_COMPARABLES,
        'codigo_tecnico',
    ];

    /**
     * Registra una deteccion. Es idempotente por `(order_id, codigo)`:
     *
     * - primera vez: crea la incidencia con attempt_count = 1;
     * - repeticiones: incrementa attempt_count de forma atomica y actualiza
     *   last_detected_at, sin crear filas nuevas;
     * - si estaba resuelta y vuelve a ocurrir: se REABRE y se limpian los datos de
     *   resolucion, porque una incidencia que reaparece no esta resuelta.
     *
     * @param  list<string>  $camposConflicto  Solo NOMBRES de campo, nunca valores.
     */
    public function registrar(
        int $orderId,
        CodigoIncidencia $codigo,
        ?int $atribucionId = null,
        array $camposConflicto = [],
    ): IncidenciaAtribucion {
        $ahora = now();
        $campos = $this->normalizarCampos($camposConflicto);

        $existente = $this->buscar($orderId, $codigo);

        if ($existente) {
            return $this->registrarRepeticion($existente, $atribucionId, $campos, $ahora);
        }

        try {
            return IncidenciaAtribucion::query()->create([
                'order_id' => $orderId,
                'order_attribution_id' => $atribucionId,
                'codigo' => $codigo,
                'campos_conflicto' => $campos === [] ? null : $campos,
                'estado' => EstadoIncidencia::ABIERTA,
                'attempt_count' => 1,
                'first_detected_at' => $ahora,
                'last_detected_at' => $ahora,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            /*
             * Dos procesos detectaron la misma incidencia a la vez. El indice unico
             * decide; el perdedor se comporta como una repeticion mas.
             *
             * lockForUpdate() por la misma razon que en RegistrarAtribucionService:
             * bajo REPEATABLE READ una lectura normal puede seguir sin ver la fila que
             * el ganador acaba de confirmar.
             */
            $ganadora = IncidenciaAtribucion::query()
                ->where('order_id', $orderId)
                ->where('codigo', $codigo->value)
                ->lockForUpdate()
                ->first();

            if (! $ganadora) {
                // La unicidad se violo por otra causa: no se disimula.
                throw $e;
            }

            return $this->registrarRepeticion($ganadora, $atribucionId, $campos, $ahora);
        }
    }

    /**
     * Cierra la incidencia. Idempotente: resolver dos veces no cambia quien la
     * resolvio ni cuando.
     *
     * Implementada pero NO expuesta por HTTP en esta fase: no hay ruta,
     * controlador ni bandeja. El consumidor previsto es la fase que construya la
     * bandeja operativa, y tendra que exigir permiso alli.
     *
     * Resolver una incidencia no corrige la atribucion: solo declara que alguien ya
     * la reviso.
     */
    public function resolver(IncidenciaAtribucion $incidencia, ?AdminContract $admin = null): IncidenciaAtribucion
    {
        if ($incidencia->estado === EstadoIncidencia::RESUELTA) {
            return $incidencia;
        }

        $incidencia->forceFill([
            'estado' => EstadoIncidencia::RESUELTA,
            'resolved_at' => now(),
            'resolved_by_admin_id' => $admin?->id ? (int) $admin->id : null,
            'resolved_by_name_snapshot' => $admin?->name,
        ])->save();

        return $incidencia;
    }

    public function buscar(int $orderId, CodigoIncidencia $codigo): ?IncidenciaAtribucion
    {
        return IncidenciaAtribucion::query()
            ->where('order_id', $orderId)
            ->where('codigo', $codigo->value)
            ->first();
    }

    /**
     * Fusiona los nombres de campo conocidos con los nuevos, filtra los no
     * permitidos, elimina duplicados y ordena. El resultado es estable: dos
     * detecciones con los mismos campos en distinto orden producen el mismo JSON.
     *
     * @param  list<string>  $campos
     * @return list<string>
     */
    public function normalizarCampos(array $campos): array
    {
        $validos = array_filter(
            $campos,
            fn ($campo) => is_string($campo) && in_array($campo, self::CAMPOS_PERMITIDOS, true),
        );

        $validos = array_values(array_unique($validos));

        sort($validos);

        return $validos;
    }

    /**
     * Incremento ATOMICO en la base de datos: `attempt_count + 1` se evalua en el
     * motor, no en PHP, para que dos procesos simultaneos no pisen la cuenta del
     * otro leyendo ambos el mismo valor.
     *
     * @param  list<string>  $campos
     */
    protected function registrarRepeticion(
        IncidenciaAtribucion $incidencia,
        ?int $atribucionId,
        array $campos,
        \DateTimeInterface $ahora,
    ): IncidenciaAtribucion {
        $fusionados = $this->normalizarCampos(array_merge(
            $incidencia->campos_conflicto ?? [],
            $campos,
        ));

        IncidenciaAtribucion::query()
            ->whereKey($incidencia->getKey())
            ->update([
                'attempt_count' => DB::raw('attempt_count + 1'),
                'last_detected_at' => $ahora,
                // Reaparecer reabre: el estado sigue al hecho, no al deseo.
                'estado' => EstadoIncidencia::ABIERTA->value,
                'resolved_at' => null,
                'resolved_by_admin_id' => null,
                'resolved_by_name_snapshot' => null,
                'order_attribution_id' => $atribucionId ?? $incidencia->order_attribution_id,
                'campos_conflicto' => $fusionados === [] ? null : json_encode($fusionados),
                'updated_at' => $ahora,
            ]);

        return $incidencia->refresh();
    }
}
