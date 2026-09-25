<?php

namespace ByLopez\VentasAsesor\Services;

use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\TipoOperacionAtribucion;
use ByLopez\VentasAsesor\Exceptions\AtribucionEnConflictoException;
use ByLopez\VentasAsesor\Exceptions\HistorialIncoherenteException;
use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Models\LogAsignacionAsesor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\User\Models\AdminProxy;

/**
 * Persiste la atribucion comercial de un pedido ya existente.
 *
 * Idempotencia real (Fase 02A1, afinada en la 02A2):
 * - reintento con el MISMO contexto: devuelve la fila existente, sin tocarla y
 *   sin escribir otro historial;
 * - reintento con un contexto DISTINTO: lanza AtribucionEnConflictoException. No
 *   sobrescribe ni finge exito. Una reasignacion legitima usa
 *   AsignacionAsesorService, que exige permiso, motivo e historial;
 * - en un fallo tecnico se compara ademas el CODIGO, que no vive en la atribucion
 *   sino en su evento de historial inicial.
 *
 * Toda atribucion nueva escribe, en la MISMA transaccion, su evento de historial
 * `initial_assignment`.
 *
 * Ninguna fase ha conectado esto a un evento de Bagisto todavia.
 */
class RegistrarAtribucionService
{
    public function __construct(protected HistorialAtribucionService $historial) {}

    public function registrar(int $orderId, ContextoComercial $contexto, ?string $motivo = null): AtribucionPedido
    {
        $existente = AtribucionPedido::query()->where('order_id', $orderId)->first();

        if ($existente) {
            return $this->exigirEquivalencia($existente, $contexto);
        }

        try {
            return DB::transaction(function () use ($orderId, $contexto, $motivo) {
                $atribucion = AtribucionPedido::query()->create(array_merge($contexto->aAtributos(), [
                    'order_id' => $orderId,
                    'created_by_name_snapshot' => $this->nombreDeAdmin($contexto->createdByAdminId),
                    'advisor_name_snapshot' => $this->nombreDeAdmin($contexto->advisorId),
                    'assigned_at' => $contexto->advisorId !== null ? now() : null,
                    'assigned_by_admin_id' => $contexto->advisorId !== null ? $contexto->createdByAdminId : null,
                ]));

                $this->historial->registrarAsignacionInicial(
                    $atribucion,
                    $contexto,
                    $motivo ?? $this->motivoPorDefecto($contexto),
                );

                return $atribucion;
            });
        } catch (UniqueConstraintViolationException $e) {
            /*
             * Otro proceso gano la carrera. No se asume que su contexto fuera el
             * correcto: se compara igual que cualquier reintento.
             *
             * La relectura usa lockForUpdate() a proposito: bajo REPEATABLE READ una
             * lectura consistente puede seguir sin ver la fila que el ganador acaba de
             * confirmar, mientras que la lectura con bloqueo si ve la ultima version.
             */
            $ganadora = AtribucionPedido::query()
                ->where('order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $ganadora) {
                // La unicidad se violo por otra causa: no se disimula.
                throw $e;
            }

            return $this->exigirEquivalencia($ganadora, $contexto);
        }
    }

    /**
     * Regla D: pedido sin evidencia suficiente. Queda sin asignar y aparecera en
     * la bandeja de la fase que la implemente.
     */
    public function registrarSinEvidencia(int $orderId, ResolverAtribucionService $resolver, ?string $notas = null): AtribucionPedido
    {
        return $this->registrar($orderId, $resolver->paraPedidoSinEvidencia($notas));
    }

    /**
     * Camino autorizado para dejar constancia de que el modulo no pudo determinar
     * el contexto: `unassigned` + `fallo_tecnico`, con codigo tecnico seguro,
     * historial inicial e idempotencia. Nunca lleva asesor.
     *
     * Fase 02A2: sin notas. Lo unico que se persiste es el codigo ya validado; el
     * detalle operativo va al log tecnico de Laravel, con campos acotados.
     */
    public function registrarFalloTecnico(
        int $orderId,
        ResolverAtribucionService $resolver,
        string $codigoTecnico,
    ): AtribucionPedido {
        $contexto = $resolver->paraFalloTecnico($codigoTecnico);

        $atribucion = $this->registrar($orderId, $contexto);

        Log::warning('ventas_asesor.atribucion.fallo_tecnico', [
            'order_id' => $orderId,
            'codigo_tecnico' => $contexto->codigoTecnico,
            'attribution_id' => $atribucion->id,
        ]);

        return $atribucion;
    }

    /**
     * Compara los campos comerciales persistibles del contexto con la fila ya
     * guardada. Devuelve la fila intacta si son equivalentes; si no, conflicto.
     */
    protected function exigirEquivalencia(AtribucionPedido $atribucion, ContextoComercial $contexto): AtribucionPedido
    {
        $conflictos = [];

        foreach ($contexto->valoresComparables() as $campo => $valorEsperado) {
            if (ContextoComercial::normalizar($atribucion->getAttribute($campo)) !== $valorEsperado) {
                $conflictos[] = $campo;
            }
        }

        if ($this->intervieneFalloTecnico($atribucion, $contexto)
            && $this->codigoTecnicoRegistrado($atribucion) !== $contexto->codigoTecnico
        ) {
            $conflictos[] = 'codigo_tecnico';
        }

        if ($conflictos !== []) {
            throw new AtribucionEnConflictoException($atribucion->order_id, $conflictos, $atribucion->id);
        }

        return $atribucion;
    }

    protected function intervieneFalloTecnico(AtribucionPedido $atribucion, ContextoComercial $contexto): bool
    {
        return $contexto->esFalloTecnico()
            || $atribucion->attribution_status === EstadoAtribucion::FALLO_TECNICO;
    }

    /**
     * El codigo tecnico no se guarda en la atribucion: vive en su evento de
     * asignacion inicial. Un historial ausente, duplicado o sin codigo en un fallo
     * tecnico es un problema de integridad, no un reintento valido.
     */
    protected function codigoTecnicoRegistrado(AtribucionPedido $atribucion): ?string
    {
        $iniciales = LogAsignacionAsesor::query()
            ->where('order_attribution_id', $atribucion->id)
            ->where('tipo_operacion', TipoOperacionAtribucion::ASIGNACION_INICIAL->value)
            ->orderBy('id')
            ->get();

        if ($iniciales->count() !== 1) {
            throw new HistorialIncoherenteException(sprintf(
                'La atribucion %d del pedido %d tiene %d eventos de asignacion inicial; se esperaba exactamente 1.',
                $atribucion->id,
                $atribucion->order_id,
                $iniciales->count(),
            ));
        }

        $codigo = $iniciales->first()->codigo_tecnico;

        if ($atribucion->attribution_status === EstadoAtribucion::FALLO_TECNICO
            && ($codigo === null || trim($codigo) === '')
        ) {
            throw new HistorialIncoherenteException(sprintf(
                'La atribucion %d del pedido %d esta en fallo_tecnico pero su asignacion inicial no guarda codigo tecnico.',
                $atribucion->id,
                $atribucion->order_id,
            ));
        }

        return $codigo;
    }

    protected function motivoPorDefecto(ContextoComercial $contexto): string
    {
        if ($contexto->esFalloTecnico()) {
            // Texto fijo mas el codigo ya validado: nada que venga del llamador.
            return 'Atribucion inicial con fallo tecnico: '.$contexto->codigoTecnico;
        }

        return sprintf(
            'Atribucion inicial automatica (%s / %s / %s)',
            $contexto->propiedad->value,
            $contexto->mecanismo->value,
            $contexto->origenPropiedad->value,
        );
    }

    protected function nombreDeAdmin(?int $adminId): ?string
    {
        if ($adminId === null) {
            return null;
        }

        return AdminProxy::modelClass()::query()->find($adminId)?->name;
    }
}
