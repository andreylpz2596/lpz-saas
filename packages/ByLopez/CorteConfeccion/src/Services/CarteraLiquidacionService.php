<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Exceptions\CarteraConflictoException;
use ByLopez\CorteConfeccion\Models\CarteraAplicacion;
use ByLopez\CorteConfeccion\Models\CarteraLiquidacion;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\CuentaEvento;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Support\CarteraConfig;
use ByLopez\CorteConfeccion\Support\Dinero;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Corte de cuentas. Reglas:
 *  - Confirmarlo NO registra dinero nuevo ni una transferencia: congela las cifras y, si se acordo, descuenta un prestamo.
 *  - El descuento arranca en cero. Solo se aplica un monto acordado expresamente, limitado por el prestamo pendiente Y por
 *    el saldo real elegible (lo RECIBIDO y sin pagar); nunca se descuenta sobre una estimacion pendiente de recepcion.
 *  - El descuento baja el prestamo y el saldo de trabajo por pagar en el mismo monto, y queda en ambos historiales.
 *  - Una referencia «Sin tarifa» no vale cero: se excluye y se lista; el corte se marca incompleto.
 *  - Se puede cortar aunque existan ordenes abiertas.
 */
class CarteraLiquidacionService
{
    public function __construct(protected CarteraCuentaService $cuentas) {}

    /**
     * Vista previa (sin escribir nada): que se reconoce, cuanto se abono, cuanto se descuenta y cuanto queda por pagar.
     *
     * @return array<string, mixed>
     */
    public function previsualizar(Taller|Cortador $prestador, string $descuento = '0.00', ?int $prestamoId = null): array
    {
        $cuenta = $this->cuentas->cuenta($prestador);

        return $this->calcular($cuenta, $descuento, $prestamoId);
    }

    /**
     * @param  array<string, mixed>  $data  fecha, descuento, prestamo_id, observacion, idempotency_key
     */
    public function confirmar(Taller|Cortador $prestador, array $data): CarteraLiquidacion
    {
        $key = trim((string) ($data['idempotency_key'] ?? ''));

        if ($key === '') {
            $this->error('idempotency_key', 'Falta la llave de idempotencia.');
        }

        $descuento = Dinero::parsear(isset($data['descuento']) && is_scalar($data['descuento']) && $data['descuento'] !== '' ? (string) $data['descuento'] : '0') ?? $this->error('descuento', 'El descuento no es un importe válido.');
        $prestamoId = ! empty($data['prestamo_id']) ? (int) $data['prestamo_id'] : null;
        $hash = hash('sha256', json_encode([$prestador::class, $prestador->getKey(), $descuento, $prestamoId, $data['observacion'] ?? null, $data['fecha'] ?? null]));

        return DB::transaction(function () use ($prestador, $data, $key, $descuento, $prestamoId, $hash) {
            $modelo = $prestador::class;
            $bloqueado = $modelo::query()->lockForUpdate()->findOrFail($prestador->getKey());

            $previa = CarteraLiquidacion::query()->where('idempotency_key', $key)->first();

            if ($previa) {
                if (($previa->cifras['hash'] ?? null) !== $hash) {
                    throw new CarteraConflictoException('La llave de idempotencia ya se usó con un contenido distinto.');
                }

                return $previa;
            }

            if (! CarteraConfig::fechaArranque()) {
                $this->error('fecha', 'La cartera aún no tiene fecha de arranque.');
            }

            $cuenta = $this->cuentas->cuenta($bloqueado);
            $calculo = $this->calcular($cuenta, $descuento, $prestamoId);

            if ($calculo['errores']) {
                throw ValidationException::withMessages(['descuento' => $calculo['errores']]);
            }

            $col = $bloqueado instanceof Taller ? 'taller_id' : 'cortador_id';
            $fecha = ! empty($data['fecha']) ? (string) $data['fecha'] : now()->toDateString();

            $liq = CarteraLiquidacion::query()->create([
                'codigo' => 'TMP-'.Str::uuid(),
                $col => $bloqueado->id,
                'fecha' => $fecha,
                'trabajo_reconocido' => $calculo['trabajo_reconocido'],
                'abonos_aplicados' => $calculo['abonos_aplicados'],
                'descuento_prestamo' => $calculo['descuento'],
                'queda_por_pagar' => $calculo['queda_por_pagar'],
                'cifras' => $calculo['congelado'] + ['hash' => $hash],
                'observacion' => isset($data['observacion']) ? mb_substr(trim((string) $data['observacion']), 0, 2000) ?: null : null,
                'estado' => 'registrado',
                'creado_por' => auth()->guard('admin')->id(),
                'idempotency_key' => $key,
            ]);

            $liq->update(['codigo' => 'LIQ-'.str_pad((string) $liq->id, 4, '0', STR_PAD_LEFT)]);

            foreach ($calculo['reparto'] as $r) {
                CarteraAplicacion::query()->create([
                    'movimiento_id' => $prestamoId,
                    'concepto' => CarteraAplicacion::DESCUENTO_PRESTAMO,
                    $r['campo'] => $r['id'],
                    'valor' => $r['valor'],
                    'liquidacion_id' => $liq->id,
                    'creado_por' => auth()->guard('admin')->id(),
                ]);
            }

            CuentaEvento::query()->create([
                $col => $bloqueado->id, 'tipo' => 'cartera_corte', 'actor_admin_id' => auth()->guard('admin')->id(),
                'motivo' => null, 'datos' => ['codigo' => $liq->codigo, 'descuento' => $calculo['descuento'], 'queda_por_pagar' => $calculo['queda_por_pagar'], 'completo' => $calculo['completo']],
            ]);

            return $liq->refresh();
        }, 3);
    }

    public function reversar(CarteraLiquidacion $liquidacion, string $motivo): CarteraLiquidacion
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            $this->error('motivo', 'Indica el motivo del reverso.');
        }

        return DB::transaction(function () use ($liquidacion, $motivo) {
            $fresca = CarteraLiquidacion::query()->lockForUpdate()->findOrFail($liquidacion->id);

            if ($fresca->estado === 'reversado') {
                $this->error('liquidacion', 'El corte ya fue reversado.');
            }

            $uid = auth()->guard('admin')->id();

            // Se anulan las aplicaciones del descuento: el prestamo y el saldo de trabajo recuperan su valor.
            CarteraAplicacion::query()->where('liquidacion_id', $fresca->id)->whereNull('anulada_en')
                ->update(['anulada_en' => now(), 'anulada_por' => $uid, 'motivo_anulacion' => 'Reverso del corte '.$fresca->codigo.': '.$motivo]);

            $fresca->update(['estado' => 'reversado', 'reversado_en' => now(), 'reversado_por' => $uid, 'motivo_reverso' => $motivo]);

            CuentaEvento::query()->create([
                ($fresca->taller_id ? 'taller_id' : 'cortador_id') => $fresca->taller_id ?: $fresca->cortador_id,
                'tipo' => 'cartera_corte_reverso', 'actor_admin_id' => $uid, 'motivo' => mb_substr($motivo, 0, 500), 'datos' => ['codigo' => $fresca->codigo],
            ]);

            return $fresca->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $cuenta
     * @return array<string, mixed>
     */
    protected function calcular(array $cuenta, string $descuento, ?int $prestamoId): array
    {
        $errores = [];
        $ordenes = collect($cuenta['ordenes']);

        // Incluidas: con saldo recibido por pagar y con tarifa conocida. «Sin tarifa» queda fuera (nunca como cero).
        $sinTarifa = $ordenes->filter(fn ($o) => ! empty($o['sin_tarifa']))->values();
        $incluidas = $ordenes->filter(fn ($o) => empty($o['sin_tarifa']) && Dinero::esPositivo($o['saldo_recibido']))->values();
        $conPendiente = $ordenes->filter(fn ($o) => (int) $o['cantidad_pendiente'] > 0)->values();

        $reconocido = Dinero::sumar($cuenta['deuda_previa_pendiente'], ...$incluidas->pluck('valor_reconocido')->all());
        $abonos = Dinero::sumar(...$incluidas->pluck('aplicado')->all() ?: ['0']);
        $saldoElegible = Dinero::sumar($cuenta['deuda_previa_pendiente'], ...$incluidas->pluck('saldo_recibido')->all());

        $descuento = Dinero::normalizar($descuento);

        if (Dinero::comparar($descuento, Dinero::cero()) < 0) {
            $errores[] = 'El descuento no puede ser negativo.';
        }

        $reparto = [];

        if (Dinero::esPositivo($descuento)) {
            $prestamo = $prestamoId ? ($cuenta['prestamos'][$prestamoId] ?? null) : null;

            if (! $prestamo) {
                $errores[] = 'Indica el préstamo contra el que se descuenta.';
            } else {
                if (Dinero::comparar($descuento, $prestamo['pendiente']) > 0) {
                    $errores[] = 'El descuento supera el préstamo pendiente ('.Dinero::formatear($prestamo['pendiente']).').';
                }

                if (Dinero::comparar($descuento, $saldoElegible) > 0) {
                    $errores[] = 'El descuento supera el saldo de trabajo ya recibido y sin pagar ('.Dinero::formatear($saldoElegible).'). No se descuenta sobre trabajo pendiente de recibir.';
                }
            }

            if (! $errores) {
                $resto = $descuento;

                foreach ($incluidas->sortBy('orden_id') as $o) {
                    if (! Dinero::esPositivo($resto)) {
                        break;
                    }

                    $v = Dinero::menor($resto, $o['saldo_recibido']);
                    $reparto[] = ['campo' => $o['tipo'] === 'confeccion' ? 'orden_confeccion_id' : 'orden_corte_id', 'id' => $o['orden_id'], 'valor' => $v, 'codigo' => $o['codigo']];
                    $resto = Dinero::restar($resto, $v);
                }

                // Lo que no cabe en ordenes se descuenta de la deuda previa por trabajo.
                if (Dinero::esPositivo($resto)) {
                    $saldoIni = DB::table('bylopez_cc_cartera_movimientos')
                        ->where($cuenta['tipo'] === 'taller' ? 'taller_id' : 'cortador_id', $this->prestadorId($cuenta))
                        ->where('tipo', 'saldo_inicial_trabajo')->where('estado', 'registrado')->value('id');

                    if (! $saldoIni) {
                        $errores[] = 'No hay saldo de trabajo contra el cual descontar.';
                    } else {
                        // Los descuentos contra deuda previa se registran sobre el movimiento de saldo inicial.
                        $reparto[] = ['campo' => 'saldo_inicial_id', 'id' => $saldoIni, 'valor' => $resto, 'codigo' => 'Deuda previa'];
                    }
                }
            }
        }

        $queda = Dinero::restar($saldoElegible, $descuento);

        return [
            'trabajo_reconocido' => $reconocido,
            'abonos_aplicados' => $abonos,
            'descuento' => $descuento,
            'queda_por_pagar' => $queda,
            'prestamo_pendiente_despues' => $prestamoId && isset($cuenta['prestamos'][$prestamoId]) ? Dinero::restar($cuenta['prestamos'][$prestamoId]['pendiente'], $descuento) : $cuenta['prestamo_pendiente'],
            'saldo_elegible_descuento' => $saldoElegible,
            'incluidas' => $incluidas->map(fn ($o) => ['codigo' => $o['codigo'], 'saldo' => $o['saldo_recibido']])->all(),
            'sin_tarifa' => $sinTarifa->map(fn ($o) => ['codigo' => $o['codigo'], 'referencias' => $o['sin_tarifa']])->all(),
            'con_pendiente' => $conPendiente->map(fn ($o) => ['codigo' => $o['codigo'], 'cantidad' => $o['cantidad_pendiente'], 'valor' => $o['valor_pendiente']])->all(),
            // Completo solo si no deja trabajo sin valorar. Lo pendiente de recibir no se liquida (aun no es reconocido).
            'completo' => $sinTarifa->isEmpty(),
            'reparto' => $reparto,
            'errores' => $errores,
            'congelado' => [
                'fecha_arranque' => $cuenta['fecha_arranque'],
                'ordenes_incluidas' => $incluidas->pluck('codigo')->all(),
                'ordenes_excluidas_sin_tarifa' => $sinTarifa->pluck('codigo')->all(),
                'ordenes_con_pendiente_de_recibir' => $conPendiente->pluck('codigo')->all(),
                'completo' => $sinTarifa->isEmpty(),
                'movimientos_ids' => collect($cuenta['movimientos'])->where('estado', 'registrado')->whereIn('tipo', ['abono', 'saldo_inicial_anticipo', 'saldo_inicial_trabajo'])->pluck('id')->map(fn ($i) => (int) $i)->values()->all(),
                'prestamo_id' => $prestamoId,
            ],
        ];
    }

    protected function prestadorId(array $cuenta): int
    {
        return (int) ($cuenta['prestador_id'] ?? 0);
    }

    protected function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
