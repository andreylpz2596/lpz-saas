<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ComisionAjuste;
use ByLopez\VentasAsesor\Models\ComisionEvento;
use ByLopez\VentasAsesor\Models\ComisionIncidencia;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autorizacion;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Doc. 23 §10.3 — INCIDENCIAS sobre lineas ya incluidas en una liquidacion (borrador o aprobada).
 *
 * Una devolucion, anulacion de entrega, reverso de cobro o desplazamiento del umbral posterior NO cambia lo liquidado:
 *  - se conserva el importe de la linea y su historial;
 *  - se abre una incidencia que RETIENE lo no pagado de esa linea (ningun abono nuevo se le aplica; las demas lineas de
 *    la liquidacion siguen pagandose con normalidad);
 *  - si parte ya se pago y la causa es una perdida de condiciones, se PROPONE un ajuste negativo pendiente SOLO por lo
 *    pagado (lo no pagado ya quedo retenido; proponer el total recuperaria dos veces). Para un desplazamiento del umbral
 *    no se propone importe: depende de D5;
 *  - nada se descuenta ni se borra solo.
 *
 * Resolucion disponible hoy (decision humana con `comisiones.approve` y motivo): «mantener» — el hecho no afecta la
 * comision; se rechaza el ajuste propuesto y la linea vuelve a ser pagable. Retener definitivamente o descontar dependen
 * de D5/D6 y no se implementan: mientras tanto la incidencia sigue abierta y la parte afectada, retenida.
 */
class IncidenciasComisionService
{
    public function __construct(
        protected Autorizacion $autorizacion,
        protected AplicacionesComisionService $aplicaciones,
    ) {}

    /**
     * Abre la incidencia si no hay una abierta del mismo tipo y si una persona no decidio ya «mantener» con los mismos hechos.
     * Llamar dentro de la transaccion de la evaluacion.
     */
    public function abrir(ComisionLinea $linea, string $tipo, array $detalle, ?int $actorId): ?ComisionIncidencia
    {
        if ($linea->liquidacion_id === null) {
            return null;
        }

        $clave = ComisionIncidencia::claveAbierta($linea->id, $tipo);

        if (ComisionIncidencia::query()->where('abierta_clave', $clave)->exists()) {
            return null;
        }

        $motivos = array_values((array) ($detalle['motivos'] ?? []));
        sort($motivos);
        $huella = sha1(json_encode([$tipo, $motivos]));

        $decidida = ComisionIncidencia::query()->where('linea_id', $linea->id)->where('tipo', $tipo)
            ->where('estado', ComisionIncidencia::RESUELTA)->where('resolucion', ComisionIncidencia::RESOLUCION_MANTENER)
            ->orderByDesc('id')->first();

        if ($decidida !== null && ($decidida->detalle['huella'] ?? null) === $huella) {
            return null;
        }

        $pagado = $this->aplicaciones->pagadoDeLinea($linea->id);

        try {
            $incidencia = ComisionIncidencia::query()->create([
                'linea_id' => $linea->id,
                'liquidacion_id' => $linea->liquidacion_id,
                'order_id' => $linea->order_id,
                'asesor_admin_id' => $linea->asesor_admin_id,
                'tipo' => $tipo,
                'detalle' => $detalle + ['huella' => $huella, 'monto_linea' => (string) $linea->monto, 'pagado_al_abrir' => Dinero::texto($pagado)],
                'estado' => ComisionIncidencia::ABIERTA,
                'abierta_clave' => $clave,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if ($tipo === ComisionIncidencia::PERDIO_CONDICIONES && $pagado->isPositive()) {
            $devolucion = collect($motivos)->contains(fn ($m) => str_starts_with((string) $m, 'devolucion'));
            $ajuste = ComisionAjuste::query()->create([
                'asesor_admin_id' => $linea->asesor_admin_id,
                'linea_id' => $linea->id,
                'order_id' => $linea->order_id,
                'monto' => (string) $pagado->negated(),
                'origen' => $devolucion ? 'devolucion_posterior' : 'anulacion_posterior',
                'motivo' => mb_substr('Incidencia #'.$incidencia->id.': la linea ya pagada dejo de cumplir ('.implode(', ', $motivos).'). Propuesta por lo pagado.', 0, 500),
                'estado' => ComisionAjuste::PENDIENTE,
                'idempotency_key' => 'incidencia:'.$incidencia->id.':ajuste',
            ]);
            $incidencia->update(['ajuste_id' => $ajuste->id]);
        }

        $this->evento($linea->id, 'incidencia_abierta', ['incidencia' => $incidencia->id, 'tipo' => $tipo, 'pagado' => Dinero::texto($pagado)], $actorId);

        return $incidencia;
    }

    /** «Mantener»: el hecho no afecta la comision. Rechaza el ajuste propuesto (si sigue pendiente) y libera la linea. */
    public function resolverMantener(?Admin $actor, int $id, string $motivo): ComisionIncidencia
    {
        if (! $actor?->id || ! $this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_COMISIONES_APROBAR)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tiene permiso para resolver incidencias.');
        }

        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 5) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'Escriba el motivo (minimo 5 caracteres).');
        }

        return DB::transaction(function () use ($actor, $id, $motivo) {
            $inc = ComisionIncidencia::query()->lockForUpdate()->find($id) ?? throw new Invalida(Invalida::NO_ENCONTRADO, 'Incidencia inexistente.');

            if ($inc->estado !== ComisionIncidencia::ABIERTA) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'La incidencia ya fue resuelta.');
            }

            $ajuste = $inc->ajuste_id === null ? null : ComisionAjuste::query()->lockForUpdate()->find($inc->ajuste_id);

            if ($ajuste !== null && $ajuste->estado === ComisionAjuste::APROBADO) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'Su ajuste ya fue aprobado: «mantener» lo contradiria. Registre un ajuste compensatorio con motivo.');
            }

            $nombre = mb_substr((string) $actor->name, 0, 191);

            if ($ajuste !== null && $ajuste->estado === ComisionAjuste::PENDIENTE) {
                $ajuste->update([
                    'estado' => ComisionAjuste::RECHAZADO, 'resuelto_por_admin_id' => (int) $actor->id, 'resuelto_por_name_snapshot' => $nombre,
                    'resuelto_en' => now(), 'motivo_resolucion' => mb_substr('Incidencia #'.$inc->id.' resuelta: se mantiene la comision. '.$motivo, 0, 500),
                ]);
            }

            $inc->update([
                'estado' => ComisionIncidencia::RESUELTA, 'abierta_clave' => null, 'resolucion' => ComisionIncidencia::RESOLUCION_MANTENER,
                'motivo_resolucion' => mb_substr($motivo, 0, 500), 'resuelta_por_admin_id' => (int) $actor->id,
                'resuelta_por_name_snapshot' => $nombre, 'resuelta_en' => now(),
            ]);

            $this->evento($inc->linea_id, 'incidencia_resuelta', ['incidencia' => $inc->id, 'resolucion' => ComisionIncidencia::RESOLUCION_MANTENER, 'motivo' => $motivo], (int) $actor->id);

            return $inc;
        });
    }

    /** Ajuste propuesto por una incidencia: no se aprueba si recuperaria mas de lo que hoy esta pagado a la linea. */
    public function exigirAjusteCoherente(ComisionAjuste $ajuste): void
    {
        $inc = ComisionIncidencia::query()->where('ajuste_id', $ajuste->id)->first();

        if ($inc === null || $ajuste->linea_id === null) {
            return;
        }

        if (Dinero::de($ajuste->monto)->abs()->isGreaterThan($this->aplicaciones->pagadoDeLinea($ajuste->linea_id))) {
            throw new Invalida(Invalida::ESTADO_INVALIDO, 'Lo pagado a la linea cambio (p. ej. se revirtio un abono): rechace este ajuste y registre uno por el valor actual.');
        }
    }

    private function evento(int $lineaId, string $tipo, array $detalle, ?int $actorId): void
    {
        ComisionEvento::query()->create([
            'linea_id' => $lineaId,
            'tipo' => $tipo,
            'detalle' => $detalle,
            'actor_admin_id' => $actorId,
            'actor_name_snapshot' => $actorId === null ? null : DB::table('admins')->where('id', $actorId)->value('name'),
        ]);
    }
}
