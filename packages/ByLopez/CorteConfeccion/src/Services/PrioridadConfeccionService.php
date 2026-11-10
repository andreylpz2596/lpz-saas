<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\PrioridadOrdenConfeccion;
use ByLopez\CorteConfeccion\Exceptions\ReasignacionConflictoException;
use ByLopez\CorteConfeccion\Models\Notificacion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionDetalle;
use ByLopez\CorteConfeccion\Models\PrioridadOperacion;
use ByLopez\CorteConfeccion\Models\RecepcionCorte;
use ByLopez\CorteConfeccion\Models\Taller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\User\Models\Admin;

/**
 * Prioridad POR CANTIDAD de lo que un taller aun debe confeccionar (docs/confeccion/09_CONSOLIDADO_PRIORIDADES.md).
 *
 * Guardado en el detalle: `cantidad_urgente` y `cantidad_alta`; Normal es el resto. Invariante unica:
 * urgente + alta <= saldoSinReportar() (vigente - reportada). Nunca toca asignaciones, inventario, tarifas ni cartera.
 *
 * REGLA DE CONSUMO P1 (aprobada por Camilo el 2026-11-03; reemplaza la propuesta «Normal primero»):
 *   - Al reportar prendas terminadas se SUGIERE descontar Urgente -> Alta -> Normal y el taller puede ajustar el reparto
 *     si confecciono otro nivel. El reparto confirmado se guarda en el reporte (auditoria) y es el que se descuenta.
 *   - Al reasignar se eligen las cantidades por prioridad que se trasladan (sugerencia: el mismo orden). El destino nace
 *     con exactamente esas cantidades y la linea de reasignacion las guarda.
 *   - El reverso de una reasignacion devuelve al origen el reparto REGISTRADO en la linea (no lo reconstruye con
 *     prioridades posteriores), con las validaciones actuales: nunca mas que el saldo del origen.
 *   - Filas anteriores a P1 (reparto null) no se reinterpretan: un reverso de una reasignacion antigua usa lo que el
 *     destino pierde, como antes.
 *   - Anular una orden deja sus cantidades prioritarias en 0. No existe reverso de reportes en el modulo.
 */
class PrioridadConfeccionService
{
    public function __construct(protected VarianteConsultaService $varianteConsultaService) {}

    /**
     * Reparto sugerido de una cantidad sobre lo disponible por nivel: Urgente primero, luego Alta, luego Normal.
     *
     * @param  array{urgente: int, alta: int, normal: int}  $disponibles
     * @return array{urgente: int, alta: int, normal: int}
     */
    public static function repartoSugerido(array $disponibles, int $cantidad): array
    {
        $u = min(max($cantidad, 0), (int) $disponibles['urgente']);
        $a = min($cantidad - $u, (int) $disponibles['alta']);

        return ['urgente' => $u, 'alta' => $a, 'normal' => max($cantidad - $u - $a, 0)];
    }

    /** «20 Urgentes + 10 Altas»: texto compacto de un reparto (sin niveles en cero). */
    public static function repartoTexto(array $reparto): string
    {
        $partes = [];

        foreach (['urgente' => ['Urgente', 'Urgentes'], 'alta' => ['Alta', 'Altas'], 'normal' => ['Normal', 'Normales']] as $nivel => [$uno, $varios]) {
            $n = (int) ($reparto[$nivel] ?? 0);

            if ($n > 0) {
                $partes[] = $n.' '.($n === 1 ? $uno : $varios);
            }
        }

        return $partes === [] ? '0' : implode(' + ', $partes);
    }

    /**
     * Resuelve y valida el reparto por prioridad de $cantidad unidades que salen de lo POR CONFECCIONAR de un detalle
     * (reporte o reasignacion). Debe llamarse bajo el bloqueo de la orden y ANTES de registrar la salida.
     *
     * - Sin reparto en $entrada (urgente/alta/normal ausentes): se usa el sugerido (Urgente -> Alta -> Normal).
     * - Con reparto: enteros estrictos, suma = $cantidad y cada nivel <= lo disponible en ese nivel.
     * - Con `base` («u:a:n» que vio la pantalla): si el saldo por nivel cambio desde entonces se rechaza para que la
     *   persona revise el reparto (su formulario conserva lo escrito).
     *
     * @return array{urgente: int, alta: int, normal: int}
     */
    public function resolverReparto(OrdenConfeccionDetalle $detalle, int $cantidad, array $entrada, string $campo): array
    {
        $disponibles = $detalle->prioridadesPorConfeccionar();
        $etiqueta = trim(($detalle->producto_sku ?: $detalle->producto_nombre).' '.($detalle->talla ? 'talla '.$detalle->talla : ''));
        $base = isset($entrada['base']) && $entrada['base'] !== '' ? (string) $entrada['base'] : null;

        if ($base !== null && $base !== implode(':', $disponibles)) {
            throw ValidationException::withMessages([$campo => trans('corteconfeccion::app.errors.prioridad-reparto-cambio', [
                'variante' => $etiqueta,
                'ahora' => self::repartoTexto($disponibles),
            ])]);
        }

        $indicado = array_filter(['urgente', 'alta', 'normal'], fn ($nivel) => isset($entrada[$nivel]) && $entrada[$nivel] !== '' && $entrada[$nivel] !== null);

        if ($indicado === []) {
            return self::repartoSugerido($disponibles, $cantidad);
        }

        $reparto = [];

        foreach (['urgente', 'alta', 'normal'] as $nivel) {
            $valor = $this->entero($entrada[$nivel] ?? 0);

            if ($valor === null) {
                throw ValidationException::withMessages([$campo => trans('corteconfeccion::app.errors.prioridad-reparto-invalido', ['variante' => $etiqueta])]);
            }

            $reparto[$nivel] = $valor;
        }

        if (array_sum($reparto) !== $cantidad) {
            throw ValidationException::withMessages([$campo => trans('corteconfeccion::app.errors.prioridad-reparto-suma', [
                'variante' => $etiqueta,
                'suma' => array_sum($reparto),
                'cantidad' => $cantidad,
            ])]);
        }

        foreach ($reparto as $nivel => $valor) {
            if ($valor > $disponibles[$nivel]) {
                throw ValidationException::withMessages([$campo => trans('corteconfeccion::app.errors.prioridad-reparto-excede', [
                    'variante' => $etiqueta,
                    'prioridad' => PrioridadOrdenConfeccion::from($nivel)->label(),
                    'disponible' => $disponibles[$nivel],
                    'ahora' => self::repartoTexto($disponibles),
                ])]);
            }
        }

        return $reparto;
    }

    /**
     * Descuenta de lo guardado las cantidades prioritarias de un reparto ya validado (reporte o reasignacion) y luego
     * asegura la invariante. Debe llamarse DESPUES de registrar la salida (reporte creado o cedido sumado).
     */
    public function descontar(OrdenConfeccionDetalle $detalle, array $reparto): void
    {
        $fresco = OrdenConfeccionDetalle::query()->findOrFail($detalle->id);
        $fresco->update([
            'cantidad_urgente' => max((int) $fresco->cantidad_urgente - (int) ($reparto['urgente'] ?? 0), 0),
            'cantidad_alta' => max((int) $fresco->cantidad_alta - (int) ($reparto['alta'] ?? 0), 0),
        ]);

        $this->recortar($fresco);
    }

    /**
     * Red de seguridad de la invariante (urgente + alta <= saldo sin reportar). Con P1 los flujos descuentan un reparto
     * explicito y esto no deberia retirar nada; si alguna vez hay exceso se retira Urgente primero y luego Alta, el mismo
     * orden de consumo de P1. Debe llamarse dentro de la transaccion que cambio el saldo, con la orden ya bloqueada.
     *
     * @return array{urgente: int, alta: int} lo retirado
     */
    public function recortar(OrdenConfeccionDetalle $detalle): array
    {
        $fresco = OrdenConfeccionDetalle::query()->findOrFail($detalle->id);
        $saldo = $fresco->saldoSinReportar();
        $urgente = (int) $fresco->cantidad_urgente;
        $alta = (int) $fresco->cantidad_alta;
        $exceso = $urgente + $alta - $saldo;

        if ($exceso <= 0) {
            return ['urgente' => 0, 'alta' => 0];
        }

        $quitarUrgente = min($urgente, $exceso);
        $quitarAlta = min($alta, $exceso - $quitarUrgente);

        $fresco->update(['cantidad_alta' => $alta - $quitarAlta, 'cantidad_urgente' => $urgente - $quitarUrgente]);

        return ['urgente' => $quitarUrgente, 'alta' => $quitarAlta];
    }

    /** Devuelve cantidades prioritarias a un detalle (reverso de reasignacion), sin superar su saldo. */
    public function sumar(OrdenConfeccionDetalle $detalle, array $cantidades): void
    {
        if (($cantidades['urgente'] ?? 0) <= 0 && ($cantidades['alta'] ?? 0) <= 0) {
            return;
        }

        $fresco = OrdenConfeccionDetalle::query()->findOrFail($detalle->id);
        $fresco->update([
            'cantidad_urgente' => (int) $fresco->cantidad_urgente + (int) ($cantidades['urgente'] ?? 0),
            'cantidad_alta' => (int) $fresco->cantidad_alta + (int) ($cantidades['alta'] ?? 0),
        ]);

        $this->recortar($fresco);
    }

    /**
     * Cambia la prioridad de cantidades concretas (normalmente una: «50 de Normal a Alta»). Todo o nada, bajo bloqueo
     * recepcion -> ordenes -> detalles, idempotente por llave: un doble clic o reintento devuelve la misma operacion y
     * no genera otra alerta. Notifica solo al usuario vinculado al taller; si no hay, lo deja registrado.
     *
     * @param  array{idempotency_key: string, nota?: ?string, cambios: array<int, array{detalle_id: int|string, cantidad: int|string, desde: string, hacia: string}>}  $data
     * @return array{operacion: PrioridadOperacion, repetida: bool}
     */
    public function cambiar(array $data): array
    {
        $n = $this->normalizar($data);

        $detallesPrevios = OrdenConfeccionDetalle::query()->with('ordenConfeccion')->whereIn('id', array_column($n['cambios'], 'detalle_id'))->get()->keyBy('id');

        if ($detallesPrevios->count() !== count($n['cambios'])) {
            throw ValidationException::withMessages(['cambios' => trans('corteconfeccion::app.errors.detalle-no-pertenece-orden')]);
        }

        $talleres = $detallesPrevios->map(fn ($d) => (int) $d->ordenConfeccion->taller_id)->unique();

        if ($talleres->count() !== 1) {
            throw ValidationException::withMessages(['cambios' => trans('corteconfeccion::app.errors.prioridad-un-taller')]);
        }

        return DB::transaction(function () use ($n, $detallesPrevios, $talleres) {
            $ordenes = $detallesPrevios->pluck('ordenConfeccion');

            foreach ($ordenes->pluck('orden_corte_id')->unique()->sort() as $ordenCorteId) {
                RecepcionCorte::query()->where('orden_corte_id', $ordenCorteId)->lockForUpdate()->first();
            }

            $ordenesFrescas = OrdenConfeccion::query()->whereIn('id', $ordenes->pluck('id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $previa = PrioridadOperacion::query()->where('idempotency_key', $n['key'])->first();

            if ($previa) {
                if (! hash_equals($previa->payload_hash, $n['hash'])) {
                    throw new ReasignacionConflictoException(trans('corteconfeccion::app.errors.prioridad-llave-en-conflicto'));
                }

                return ['operacion' => $previa->load(['cambios', 'destinatario', 'taller']), 'repetida' => true];
            }

            $detalles = OrdenConfeccionDetalle::query()->whereIn('id', $detallesPrevios->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($n['cambios'] as $cambio) {
                $detalle = $detalles->get($cambio['detalle_id']);
                $orden = $ordenesFrescas->get($detalle->orden_confeccion_id);

                if (! in_array($orden->estado, [EstadoOrdenConfeccion::Activa->value, EstadoOrdenConfeccion::EnConfeccion->value], true)) {
                    throw ValidationException::withMessages(['cambios' => trans('corteconfeccion::app.errors.prioridad-orden-no-modificable')]);
                }

                $disponible = $detalle->prioridadesPorConfeccionar()[$cambio['desde']];

                if ($cambio['cantidad'] > $disponible) {
                    throw ValidationException::withMessages(['cambios' => trans('corteconfeccion::app.errors.prioridad-excede-saldo', [
                        'disponible' => $disponible,
                        'prioridad' => PrioridadOrdenConfeccion::from($cambio['desde'])->label(),
                    ])]);
                }

                $valores = [];

                if ($columna = PrioridadOrdenConfeccion::from($cambio['desde'])->columna()) {
                    $valores[$columna] = (int) $detalle->{$columna} - $cambio['cantidad'];
                }

                if ($columna = PrioridadOrdenConfeccion::from($cambio['hacia'])->columna()) {
                    $valores[$columna] = (int) $detalle->{$columna} + $cambio['cantidad'];
                }

                $detalle->update($valores);

                if ((int) $detalle->cantidad_urgente + (int) $detalle->cantidad_alta > $detalle->saldoSinReportar()) {
                    throw ValidationException::withMessages(['cambios' => trans('corteconfeccion::app.errors.prioridad-excede-saldo', ['disponible' => $disponible, 'prioridad' => PrioridadOrdenConfeccion::from($cambio['desde'])->label()])]);
                }
            }

            $taller = Taller::query()->findOrFail($talleres->first());
            $destinatario = $taller->usuario_id ? Admin::query()->whereKey($taller->usuario_id)->where('status', 1)->first() : null;
            $usuario = auth()->guard('admin')->user();

            $operacion = PrioridadOperacion::query()->create([
                'idempotency_key' => $n['key'],
                'payload_hash' => $n['hash'],
                'taller_id' => $taller->id,
                'usuario_id' => $usuario?->id,
                'nota' => $n['nota'],
                'notificacion_estado' => $destinatario ? PrioridadOperacion::NOTIFICADO : PrioridadOperacion::SIN_DESTINATARIO,
                'destinatario_id' => $destinatario?->id,
            ]);

            $variantes = $this->varianteConsultaService->porProductIds($detalles->pluck('product_id'));
            $foto = [];

            foreach ($n['cambios'] as $cambio) {
                $detalle = $detalles->get($cambio['detalle_id']);
                $orden = $ordenesFrescas->get($detalle->orden_confeccion_id);

                $operacion->cambios()->create([
                    'orden_confeccion_id' => $orden->id,
                    'orden_confeccion_detalle_id' => $detalle->id,
                    'product_id' => $detalle->product_id,
                    'cantidad' => $cambio['cantidad'],
                    'prioridad_anterior' => $cambio['desde'],
                    'prioridad_nueva' => $cambio['hacia'],
                ]);

                $variante = $variantes->get((int) $detalle->product_id, []);
                $foto[] = [
                    'orden_id' => $orden->id,
                    'orden_codigo' => $orden->codigo,
                    'detalle_id' => $detalle->id,
                    'product_id' => $detalle->product_id,
                    'sku' => $detalle->producto_sku,
                    'nombre' => $variante['nombre'] ?? $detalle->producto_nombre,
                    'color' => $variante['color'] ?? null,
                    'talla' => $detalle->talla ?: ($variante['talla'] ?? null),
                    'cantidad' => $cambio['cantidad'],
                    'desde' => $cambio['desde'],
                    'hacia' => $cambio['hacia'],
                ];
            }

            if ($destinatario) {
                Notificacion::query()->create([
                    'admin_id' => $destinatario->id,
                    'tipo' => Notificacion::TIPO_PRIORIDAD,
                    'prioridad_operacion_id' => $operacion->id,
                    'datos' => [
                        'taller_id' => $taller->id,
                        'taller' => $taller->nombre,
                        'usuario' => $usuario?->name,
                        'fecha' => $operacion->created_at?->toIso8601String(),
                        'nota' => $n['nota'],
                        'cambios' => $foto,
                    ],
                ]);
            }

            return ['operacion' => $operacion->load(['cambios', 'destinatario', 'taller']), 'repetida' => false];
        }, 3);
    }

    /** Forma, enteros estrictos y hash canonico (sin tocar la base). */
    protected function normalizar(array $data): array
    {
        $key = trim((string) ($data['idempotency_key'] ?? ''));

        if ($key === '' || mb_strlen($key) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => trans('corteconfeccion::app.errors.reasignacion-llave-invalida')]);
        }

        $nota = trim((string) ($data['nota'] ?? ''));

        if (mb_strlen($nota) > 500) {
            throw ValidationException::withMessages(['nota' => trans('corteconfeccion::app.errors.prioridad-nota-larga')]);
        }

        $entradas = array_values((array) ($data['cambios'] ?? []));

        if ($entradas === []) {
            throw ValidationException::withMessages(['cambios' => trans('corteconfeccion::app.errors.prioridad-sin-cambios')]);
        }

        $cambios = [];

        foreach ($entradas as $entrada) {
            $detalleId = $this->entero($entrada['detalle_id'] ?? null);
            $cantidad = $this->entero($entrada['cantidad'] ?? null);
            $desde = PrioridadOrdenConfeccion::tryFrom((string) ($entrada['desde'] ?? ''));
            $hacia = PrioridadOrdenConfeccion::tryFrom((string) ($entrada['hacia'] ?? ''));

            if (! $detalleId || ! $cantidad || $cantidad < 1) {
                throw ValidationException::withMessages(['cambios' => trans('corteconfeccion::app.errors.prioridad-cantidad-invalida')]);
            }

            if (! $desde || ! $hacia) {
                throw ValidationException::withMessages(['cambios' => trans('corteconfeccion::app.errors.prioridad-invalida')]);
            }

            // Una operacion sin cambio real no se registra ni se notifica.
            if ($desde === $hacia) {
                throw ValidationException::withMessages(['cambios' => trans('corteconfeccion::app.errors.prioridad-sin-cambios')]);
            }

            if (isset($cambios[$detalleId])) {
                throw ValidationException::withMessages(['cambios' => trans('corteconfeccion::app.errors.reasignacion-detalle-repetido')]);
            }

            $cambios[$detalleId] = ['detalle_id' => $detalleId, 'cantidad' => $cantidad, 'desde' => $desde->value, 'hacia' => $hacia->value];
        }

        ksort($cambios);
        $nota = $nota !== '' ? $nota : null;

        return [
            'key' => $key,
            'nota' => $nota,
            'cambios' => array_values($cambios),
            'hash' => hash('sha256', json_encode(['nota' => $nota, 'cambios' => array_values($cambios)], JSON_UNESCAPED_UNICODE)),
        ];
    }

    /** Entero estricto: acepta int o texto de solo digitos; rechaza decimales, negativos y basura. */
    protected function entero(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor >= 0 ? $valor : null;
        }

        if (is_string($valor) && preg_match('/^\d{1,9}$/', trim($valor))) {
            return (int) trim($valor);
        }

        return null;
    }
}
