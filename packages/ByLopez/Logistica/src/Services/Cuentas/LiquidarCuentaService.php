<?php

namespace ByLopez\Logistica\Services\Cuentas;

use ByLopez\Logistica\Enums\ConceptoMovimientoCuenta as Concepto;
use ByLopez\Logistica\Enums\EstadoMovimientoCuenta;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\LiquidacionTransportadora as Liquidacion;
use ByLopez\Logistica\Models\MovimientoCuenta;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Services\Entregas\RegistrarEventoEnvio;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use ByLopez\Logistica\Support\Moneda;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * LIQUIDAR la cuenta de una transportadora (solo ADMIN, `logistica.cuentas.liquidar`): cierra INMUTABLEMENTE el conjunto
 * de movimientos abiertos que el usuario REVISO (sus ids), con el valor efectivamente entregado.
 *
 * Todo se RECALCULA en el servidor bajo transaccion: la transportadora se bloquea (FOR UPDATE, serializa dos liquidaciones
 * de la misma cuenta), los movimientos tambien, y cada uno debe seguir abierto y ser de esa cuenta. Si otro usuario ya los
 * liquido → `movimientos_ya_liquidados`; el mismo envio repetido (misma clave) devuelve la misma liquidacion.
 *
 * Cuadre sin doble conteo (los movimientos de una liquidacion SIEMPRE suman 0):
 *   saldo esperado S = Σ movimientos incluidos (+ la transportadora entrega / - ByLopez paga)
 *   pago P           = -valor entregado si S > 0; +valor pagado si S < 0           (movimiento `pago_liquidacion`)
 *   diferencia D     = S + P  (lo que no cuadro)
 *   si D ≠ 0: un movimiento `diferencia_liquidacion` de -D CIERRA la liquidacion y otro de +D se ABRE en la cuenta siguiente
 *   (observacion obligatoria). Nada se modifica: el saldo vivo de la cuenta es exactamente D.
 */
class LiquidarCuentaService
{
    public const CLAVE_CONSECUTIVO = 'liquidacion_transportadora';

    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected AjusteCuentaService $cuentas,
        protected ConciliacionCuenta $conciliacion,
    ) {}

    /** @param  array{movimientos?: mixed, valor?: mixed, observacion?: mixed, referencia?: mixed, idempotency_key?: mixed}  $datos */
    public function liquidar(?Admin $actor, int $transportadoraId, array $datos): Liquidacion
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_CUENTAS_LIQUIDAR)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $clave = trim((string) ($datos['idempotency_key'] ?? ''));

        if (! preg_match(RegistrarEventoEnvio::PATRON_IDEMPOTENCIA, $clave)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_INVALIDA);
        }

        $ids = $this->ids($datos['movimientos'] ?? null);
        $valor = $this->valor($datos['valor'] ?? null);
        $observacion = $this->texto($datos['observacion'] ?? null, 500);
        $referencia = $this->texto($datos['referencia'] ?? null, 120);
        // C6: reasignacion manual de la atribucion (opcional): forma parte de lo que se confirma, asi que entra en la huella.
        $atribucion = $this->atribucion($datos['atribucion'] ?? null);
        $motivoAtribucion = $this->texto($datos['motivo_atribucion'] ?? null, 500);

        if ($atribucion !== null && $motivoAtribucion === null) {
            throw new Invalida(Invalida::OBSERVACION_REQUERIDA, ['motivo' => 'atribucion']);
        }

        $huella = hash('sha256', json_encode(['liquidacion', $transportadoraId, $ids, $valor, $observacion, $referencia] + ($atribucion === null ? [] : [6 => $atribucion, 7 => $motivoAtribucion])));

        if ($previa = $this->previa($clave, $huella)) {
            return $previa;
        }

        try {
            return DB::transaction(function () use ($actor, $transportadoraId, $ids, $valor, $observacion, $referencia, $clave, $huella, $atribucion, $motivoAtribucion) {
                $transportadora = Transportadora::query()->lockForUpdate()->find($transportadoraId) ?? throw new Invalida(Invalida::CUENTA_INEXISTENTE);
                $this->cuentas->cuenta((int) $transportadora->id);

                if ($previa = $this->previa($clave, $huella)) {
                    return $previa;
                }

                $movimientos = MovimientoCuenta::query()->whereIn('id', $ids)->lockForUpdate()->orderBy('id')->get();

                if ($movimientos->count() !== count($ids) || $movimientos->contains(fn ($m) => $m->transportadora_id !== (int) $transportadora->id)) {
                    throw new Invalida(Invalida::MOVIMIENTOS_INVALIDOS);
                }

                if ($movimientos->contains(fn ($m) => $m->estado !== EstadoMovimientoCuenta::ABIERTO)) {
                    throw new Invalida(Invalida::MOVIMIENTOS_YA_LIQUIDADOS);
                }

                $saldo = round((float) $movimientos->sum('importe'), 2);
                $sentido = match (true) {
                    $saldo > 0.004 => Liquidacion::SENTIDO_TRANSPORTADOR_ENTREGA,
                    $saldo < -0.004 => Liquidacion::SENTIDO_BYLOPEZ_PAGA,
                    default => Liquidacion::SENTIDO_AL_DIA,
                };

                if ($sentido === Liquidacion::SENTIDO_AL_DIA && $valor > 0) {
                    throw new Invalida(Invalida::VALOR_INVALIDO);
                }

                $pago = $sentido === Liquidacion::SENTIDO_BYLOPEZ_PAGA ? $valor : -$valor;
                $diferencia = round($saldo + $pago, 2);

                if (abs($diferencia) >= 0.005 && $observacion === null) {
                    throw new Invalida(Invalida::OBSERVACION_REQUERIDA, ['diferencia' => Moneda::formatear(abs($diferencia))]);
                }

                $totales = $this->totales($movimientos);

                // C6: como se satisface cada movimiento (efectivo / compensado / pendiente del transportador). Se calcula ANTES de cerrar
                // (la liquidacion es inmutable) y se escribe despues con la liquidacion ya creada.
                $conciliacion = $this->conciliacion->propuesta($movimientos, $valor, $atribucion, true);
                $consecutivo = $this->siguienteConsecutivo();
                $numero = Liquidacion::formatear($consecutivo);

                $liquidacion = Liquidacion::query()->create([
                    'consecutivo' => $consecutivo,
                    'transportadora_id' => (int) $transportadora->id,
                    'periodo_desde' => $movimientos->min(fn ($m) => $m->fecha_operativa->toDateString()),
                    'periodo_hasta' => $movimientos->max(fn ($m) => $m->fecha_operativa->toDateString()),
                    'movimientos_count' => $movimientos->count(),
                    'total_recaudos' => $totales['recaudos'],
                    'total_servicios' => $totales['servicios'],
                    'total_ajustes' => $totales['ajustes'],
                    'saldo_esperado' => $saldo,
                    'sentido' => $sentido,
                    'valor_liquidado' => $valor,
                    'diferencia' => $diferencia,
                    'observacion' => $observacion,
                    'referencia' => $referencia,
                    'resumen' => [
                        'transportadora' => ['codigo' => $transportadora->codigo, 'nombre' => $transportadora->nombre],
                        'por_concepto' => $totales['por_concepto'],
                        'pedidos' => $movimientos->pluck('order_id')->filter()->unique()->count(),
                        'envios' => $movimientos->pluck('shipment_id')->filter()->unique()->count(),
                        'conciliacion' => $conciliacion['totales'] + ['atribucion' => $conciliacion['manual'] ? 'manual' : 'propuesta'],
                    ],
                    'estado' => 'cerrada',
                    'idempotency_key' => $clave,
                    'huella' => $huella,
                    'actor_admin_id' => (int) $actor->id,
                    'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
                    'cerrada_en' => now(),
                ]);

                foreach ($movimientos as $m) {
                    $m->forceFill(['estado' => EstadoMovimientoCuenta::LIQUIDADO, 'liquidacion_id' => $liquidacion->id])->save();
                }

                $base = ['transportadora_id' => (int) $transportadora->id, 'origen_tipo' => MovimientoCuenta::ORIGEN_LIQUIDACION, 'origen_id' => $liquidacion->id,
                    'fecha_operativa' => now()->toDateString(), 'referencia' => $referencia, 'actor_admin_id' => (int) $actor->id,
                    'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191)];

                if ($valor > 0) {
                    MovimientoCuenta::query()->create($base + [
                        'concepto' => Concepto::PAGO_LIQUIDACION, 'importe' => $pago, 'estado' => EstadoMovimientoCuenta::LIQUIDADO,
                        'liquidacion_id' => $liquidacion->id, 'idempotency_key' => 'liquidacion:'.$liquidacion->id.':pago',
                        'descripcion' => trans('logistica::app.cuentas.desc.pago-'.$sentido, ['liquidacion' => $numero]),
                    ]);
                }

                if (abs($diferencia) >= 0.005) {
                    $texto = trans('logistica::app.cuentas.desc.diferencia', ['liquidacion' => $numero]);

                    MovimientoCuenta::query()->create($base + [
                        'concepto' => Concepto::DIFERENCIA_LIQUIDACION, 'importe' => -$diferencia, 'estado' => EstadoMovimientoCuenta::LIQUIDADO,
                        'liquidacion_id' => $liquidacion->id, 'idempotency_key' => 'liquidacion:'.$liquidacion->id.':diferencia:cierre',
                        'descripcion' => $texto, 'motivo' => $observacion,
                    ]);
                    MovimientoCuenta::query()->create($base + [
                        'concepto' => Concepto::DIFERENCIA_LIQUIDACION, 'importe' => $diferencia, 'estado' => EstadoMovimientoCuenta::ABIERTO,
                        'liquidacion_id' => null, 'idempotency_key' => 'liquidacion:'.$liquidacion->id.':diferencia:apertura',
                        'descripcion' => $texto, 'motivo' => $observacion,
                    ]);
                }

                $this->conciliacion->registrar($liquidacion, $movimientos, $valor, $atribucion, $motivoAtribucion, (int) $actor->id);

                // Invariante del cierre: lo liquidado suma exactamente 0.
                if (abs((float) MovimientoCuenta::query()->where('liquidacion_id', $liquidacion->id)->sum('importe')) >= 0.005) {
                    throw new Invalida(Invalida::DATOS_INVALIDOS, ['motivo' => 'liquidacion_descuadrada']);
                }

                return $liquidacion;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->previa($clave, $huella) ?? throw new Invalida(Invalida::MOVIMIENTOS_YA_LIQUIDADOS);
        }
    }

    /** @return array{recaudos: float, servicios: float, ajustes: float, por_concepto: array<string, array{n: int, total: float}>} */
    public function totales(Collection $movimientos): array
    {
        $grupos = ['recaudos' => 0.0, 'servicios' => 0.0, 'ajustes' => 0.0];
        $porConcepto = [];

        foreach ($movimientos as $m) {
            $grupo = $m->concepto->grupo();

            if (isset($grupos[$grupo])) {
                $grupos[$grupo] += (float) $m->importe;
            }

            $porConcepto[$m->concepto->value]['n'] = ($porConcepto[$m->concepto->value]['n'] ?? 0) + 1;
            $porConcepto[$m->concepto->value]['total'] = round(($porConcepto[$m->concepto->value]['total'] ?? 0) + (float) $m->importe, 2);
        }

        return array_map(fn ($v) => round($v, 2), $grupos) + ['por_concepto' => $porConcepto];
    }

    /** LIQ-000001: contador bloqueado dentro de la transaccion + UNIQUE en la tabla (nunca MAX+1 sin proteccion). */
    private function siguienteConsecutivo(): int
    {
        DB::table('bylopez_lg_consecutivos')->insertOrIgnore(['clave' => self::CLAVE_CONSECUTIVO, 'ultimo' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $ultimo = (int) DB::table('bylopez_lg_consecutivos')->where('clave', self::CLAVE_CONSECUTIVO)->lockForUpdate()->value('ultimo');
        $siguiente = max($ultimo, (int) Liquidacion::query()->max('consecutivo')) + 1;

        DB::table('bylopez_lg_consecutivos')->where('clave', self::CLAVE_CONSECUTIVO)->update(['ultimo' => $siguiente, 'updated_at' => now()]);

        return $siguiente;
    }

    private function previa(string $clave, string $huella): ?Liquidacion
    {
        $previa = Liquidacion::query()->where('idempotency_key', $clave)->first();

        if ($previa === null) {
            return null;
        }

        if (! hash_equals($previa->huella, $huella)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_CONFLICTO, ['id' => $previa->id]);
        }

        return $previa;
    }

    /** @return list<int> ids unicos y ordenados de los movimientos revisados */
    private function ids(mixed $valor): array
    {
        if (! is_array($valor) || $valor === []) {
            throw new Invalida(Invalida::SIN_MOVIMIENTOS);
        }

        $ids = [];

        foreach ($valor as $id) {
            if (! is_numeric($id) || (int) $id <= 0 || (string) (int) $id !== trim((string) $id)) {
                throw new Invalida(Invalida::MOVIMIENTOS_INVALIDOS);
            }

            $ids[(int) $id] = (int) $id;
        }

        ksort($ids);

        return array_values($ids);
    }

    private function valor(mixed $valor): float
    {
        $texto = trim((string) $valor);

        if (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', $texto)) {
            throw new Invalida(Invalida::VALOR_INVALIDO);
        }

        return round((float) $texto, 2);
    }

    /** @return list<array{movimiento_id: mixed, efectivo: mixed, compensado: mixed}>|null */
    private function atribucion(mixed $valor): ?array
    {
        if (! is_array($valor) || $valor === []) {
            return null;
        }

        $filas = [];

        foreach ($valor as $a) {
            if (! is_array($a) || ! isset($a['movimiento_id'])) {
                throw new Invalida(Invalida::DATOS_INVALIDOS, ['motivo' => 'atribucion_invalida']);
            }

            $filas[(int) $a['movimiento_id']] = ['movimiento_id' => (int) $a['movimiento_id'], 'efectivo' => $a['efectivo'] ?? 0, 'compensado' => $a['compensado'] ?? 0];
        }

        ksort($filas);

        return array_values($filas);
    }

    private function texto(mixed $valor, int $max): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, $max);
    }
}
