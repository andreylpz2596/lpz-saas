<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use Brick\Math\BigDecimal;
use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ComisionAbono;
use ByLopez\VentasAsesor\Models\ComisionAjuste;
use ByLopez\VentasAsesor\Models\ComisionIncidencia;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Models\ComisionLiquidacion;
use ByLopez\VentasAsesor\Models\ComisionSoporte;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autorizacion;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Webkul\User\Contracts\Admin;

/**
 * R4 — liquidaciones, ajustes y abonos al asesor (A-05). Otra cuenta distinta de Cartera; no ejecuta transferencias.
 *
 * - Borrador: SOLO lineas habilitadas, sin liquidacion, sin politica pendiente, del asesor y con fecha de HABILITACION en
 *   el periodo (el periodo se elige al crear y es INDEPENDIENTE del umbral mensual: el umbral decide que unidades
 *   comisionan; el periodo, cuando se paga lo ya habilitado). Ajustes APROBADOS solo si se eligen expresamente. Una linea
 *   entra en una sola liquidacion (bloqueo FOR UPDATE + columna unica).
 * - NADA confia en un estado «habilitada» viejo: crear, aprobar y abonar REEVALUAN primero los pedidos implicados contra
 *   los hechos vigentes (entrega, cobro, dinero recibido, devoluciones); lo que dejo de cumplir sale del borrador o queda
 *   como incidencia retenida (Doc. 23 §10.3).
 * - Aprobar congela y aplica la compensacion de los ajustes negativos ELEGIDOS (unica compensacion; la aprobo una persona).
 *   Anular (con motivo, permiso `revertir`) solo si no tiene abonos netos: libera lineas y ajustes.
 * - Abonos: completos o parciales sobre una liquidacion aprobada, nunca por encima de lo PAGABLE (saldo menos lo retenido
 *   por incidencias abiertas); idempotentes por clave; comprobante en disco privado; aplicacion GUARDADA por linea.
 *   Reverso auditado (una sola vez por abono) que devuelve exactamente lo que aplico el abono.
 */
class LiquidacionesComisionService
{
    public const PERMISO_COMPROBANTES = 'ventas_asesor.comisiones.comprobantes';

    public function __construct(
        protected Autorizacion $autorizacion,
        protected EvaluarComisionesService $evaluar,
        protected ElegibilidadComisionService $elegibilidad,
        protected AplicacionesComisionService $aplicaciones,
        protected IncidenciasComisionService $incidencias,
    ) {}

    /**
     * @param  list<int>  $ajusteIds
     */
    public function crearBorrador(?Admin $actor, int $asesorId, string $desde, string $hasta, array $ajusteIds, ?string $observacion, string $clave): ComisionLiquidacion
    {
        $this->exigir($actor, Autorizacion::PERMISO_COMISIONES_APROBAR);
        $clave = $this->clave($clave);

        if ($existente = ComisionLiquidacion::query()->where('idempotency_key', $clave)->first()) {
            return $existente;
        }

        [$d, $h] = $this->periodo($desde, $hasta);

        // Condiciones VIGENTES: se reevaluan antes los pedidos candidatos (una linea que dejo de cumplir vuelve a estimada).
        $this->verificarPedidos(ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('estado', ComisionLinea::HABILITADA)
            ->whereNull('liquidacion_id')->whereBetween('habilitada_en', [$d->startOfDay(), $h->endOfDay()])
            ->distinct()->pluck('order_id')->all(), $actor);

        return DB::transaction(function () use ($actor, $asesorId, $d, $h, $ajusteIds, $observacion, $clave) {
            $lineas = ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('estado', ComisionLinea::HABILITADA)
                ->whereNull('liquidacion_id')->whereNull('alerta')
                ->whereBetween('habilitada_en', [$d->startOfDay(), $h->endOfDay()])
                ->orderBy('habilitada_en')->orderBy('id')->lockForUpdate()->get();

            // Defensa ante una carrera entre la reevaluacion y este bloqueo: solo pedidos que cumplen AHORA.
            $cumplen = $lineas->pluck('order_id')->unique()->filter(fn ($id) => $this->elegibilidad->dePedido((int) $id)['habilitable'])->flip();
            $lineas = $lineas->filter(fn (ComisionLinea $l) => $cumplen->has($l->order_id))->values();

            $ajustes = ComisionAjuste::query()->whereIn('id', $ajusteIds ?: [0])->where('asesor_admin_id', $asesorId)
                ->where('estado', ComisionAjuste::APROBADO)->whereNull('liquidacion_id')->lockForUpdate()->get();

            if (count(array_unique($ajusteIds)) !== $ajustes->count()) {
                throw new Invalida(Invalida::DATOS_INVALIDOS, 'Algun ajuste elegido no esta aprobado, ya fue liquidado o es de otro asesor.');
            }

            if ($lineas->isEmpty() && $ajustes->isEmpty()) {
                throw new Invalida(Invalida::SIN_LINEAS, 'No hay comisiones habilitadas disponibles para ese asesor y periodo.');
            }

            $totalLineas = $lineas->reduce(fn (BigDecimal $t, ComisionLinea $l) => $t->plus(Dinero::de($l->monto)), BigDecimal::zero());
            $totalAjustes = $ajustes->reduce(fn (BigDecimal $t, ComisionAjuste $a) => $t->plus(Dinero::de($a->monto)), BigDecimal::zero());
            $total = $totalLineas->plus($totalAjustes);

            if ($total->isNegative()) {
                throw new Invalida(Invalida::DATOS_INVALIDOS, 'El total quedaria negativo: un ajuste negativo no se compensa en automatico. Revise los ajustes elegidos.');
            }

            $consecutivo = (int) ComisionLiquidacion::query()->lockForUpdate()->max('consecutivo') + 1;

            $liq = ComisionLiquidacion::query()->create([
                'consecutivo' => $consecutivo,
                'asesor_admin_id' => $asesorId,
                'asesor_name_snapshot' => DB::table('bylopez_va_asesores')->where('admin_id', $asesorId)->value('nombre'),
                'periodo_desde' => $d->toDateString(),
                'periodo_hasta' => $h->toDateString(),
                'estado' => ComisionLiquidacion::BORRADOR,
                'lineas_count' => $lineas->count(),
                'total_lineas' => Dinero::texto($totalLineas),
                'total_ajustes' => Dinero::texto($totalAjustes),
                'total' => Dinero::texto($total),
                'observacion' => $observacion === null ? null : mb_substr(trim($observacion), 0, 500),
                'idempotency_key' => $clave,
                'creado_por_admin_id' => (int) $actor->id,
                'creado_por_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
            ]);

            ComisionLinea::query()->whereIn('id', $lineas->pluck('id'))->update(['liquidacion_id' => $liq->id]);
            ComisionAjuste::query()->whereIn('id', $ajustes->pluck('id'))->update(['liquidacion_id' => $liq->id]);
            DB::table('bylopez_va_comision_eventos')->insert($lineas->map(fn ($l) => [
                'linea_id' => $l->id, 'tipo' => 'incluida_en_liquidacion', 'detalle' => json_encode(['liquidacion' => $liq->numero()]),
                'actor_admin_id' => $actor->id, 'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191), 'created_at' => now(),
            ])->all());

            return $liq;
        });
    }

    public function aprobar(?Admin $actor, int $id): ComisionLiquidacion
    {
        $this->exigir($actor, Autorizacion::PERMISO_COMISIONES_APROBAR);
        $this->verificarPedidos(ComisionLinea::query()->where('liquidacion_id', $id)->distinct()->pluck('order_id')->all(), $actor);

        return DB::transaction(function () use ($actor, $id) {
            $liq = $this->bloquear($id);

            if ($liq->estado === ComisionLiquidacion::APROBADA) {
                return $liq;
            }

            if ($liq->estado !== ComisionLiquidacion::BORRADOR) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'Solo se aprueba un borrador.');
            }

            // Ninguna linea pudo cambiar estando en la liquidacion (el modelo lo impide), pero si quedo marcada, con una
            // incidencia abierta o sin cumplir HOY las condiciones, no se aprueba.
            if ($liq->lineas()->whereNotNull('alerta')->exists() || $this->aplicaciones->lineasRetenidas($liq->id) !== []) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'Hay lineas marcadas para revision: anule el borrador y vuelva a crearlo.');
            }

            foreach ($liq->lineas()->distinct()->pluck('order_id') as $orderId) {
                if (! $this->elegibilidad->dePedido((int) $orderId)['habilitable']) {
                    throw new Invalida(Invalida::ESTADO_INVALIDO, 'Un pedido del borrador dejo de cumplir las condiciones: anule el borrador y vuelva a crearlo.');
                }
            }

            if ($liq->ajustes()->where('estado', '!=', ComisionAjuste::APROBADO)->exists()) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'Un ajuste del borrador ya no esta aprobado.');
            }

            $liq->update([
                'estado' => ComisionLiquidacion::APROBADA,
                'aprobada_por_admin_id' => (int) $actor->id,
                'aprobada_por_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
                'aprobada_en' => now(),
            ]);

            // Los ajustes negativos que quien liquido eligio se compensan aqui, una vez y con registro.
            $this->aplicaciones->compensarAlAprobar($liq);

            return $liq;
        });
    }

    public function anular(?Admin $actor, int $id, string $motivo): ComisionLiquidacion
    {
        $this->exigir($actor, Autorizacion::PERMISO_COMISIONES_REVERTIR);
        $motivo = $this->motivo($motivo);

        return DB::transaction(function () use ($actor, $id, $motivo) {
            $liq = $this->bloquear($id);

            if ($liq->estado === ComisionLiquidacion::ANULADA) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'La liquidacion ya esta anulada.');
            }

            if ($this->abonado($liq)->isPositive()) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'Tiene abonos registrados: reviertalos antes de anular.');
            }

            $lineas = $liq->lineas()->pluck('id');

            // Sus incidencias abiertas se cierran con la liquidacion (sin abonos netos no hay nada retenido que pagar); la
            // linea liberada vuelve a evaluarse sola y, si entra en otra liquidacion, abre allí las suyas.
            ComisionIncidencia::query()->where('liquidacion_id', $liq->id)->where('estado', ComisionIncidencia::ABIERTA)->update([
                'estado' => ComisionIncidencia::RESUELTA, 'abierta_clave' => null, 'resolucion' => ComisionIncidencia::RESOLUCION_LIQUIDACION_ANULADA,
                'motivo_resolucion' => mb_substr('Liquidacion '.$liq->numero().' anulada: '.$motivo, 0, 500), 'resuelta_por_admin_id' => (int) $actor->id,
                'resuelta_por_name_snapshot' => mb_substr((string) $actor->name, 0, 191), 'resuelta_en' => now(), 'updated_at' => now(),
            ]);

            ComisionLinea::query()->whereIn('id', $lineas)->update(['liquidacion_id' => null]);
            ComisionAjuste::query()->where('liquidacion_id', $liq->id)->update(['liquidacion_id' => null]);
            DB::table('bylopez_va_comision_eventos')->insert($lineas->map(fn ($l) => [
                'linea_id' => $l, 'tipo' => 'liberada_de_liquidacion', 'detalle' => json_encode(['liquidacion' => $liq->numero(), 'motivo' => $motivo]),
                'actor_admin_id' => $actor->id, 'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191), 'created_at' => now(),
            ])->all());

            $liq->update(['estado' => ComisionLiquidacion::ANULADA, 'anulada_por_admin_id' => (int) $actor->id, 'anulada_en' => now(), 'motivo_anulacion' => $motivo]);

            return $liq;
        });
    }

    /**
     * Registra un abono (pago) a una liquidacion aprobada. Es la UNICA via que contabiliza un pago: cargar o leer un comprobante nunca lo hace.
     *
     * Con `soporte_id` el comprobante ya cargado y leido respalda el abono (no se sube otro archivo); se guarda lo que el lector detecto y lo
     * que administracion corrigio. La OPERACION (referencia + medio + fecha + valor) se aplica una sola vez: la misma operacion no puede
     * respaldar mas que su valor total, ni siquiera con dobles clics o solicitudes simultaneas (se serializa por huella de la operacion).
     *
     * @param  array{monto: mixed, fecha_pago: mixed, medio?: ?string, referencia?: ?string, observacion?: ?string, soporte_id?: mixed, operacion_valor?: mixed, confirmar_misma_operacion?: mixed}  $datos
     */
    public function registrarAbono(?Admin $actor, int $liquidacionId, array $datos, ?UploadedFile $comprobante, string $clave): ComisionAbono
    {
        $this->exigir($actor, Autorizacion::PERMISO_COMISIONES_PAGAR);
        $clave = $this->clave($clave);

        if ($existente = ComisionAbono::query()->where('idempotency_key', $clave)->first()) {
            return $existente;
        }

        $monto = trim((string) ($datos['monto'] ?? ''));

        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $monto) || ! BigDecimal::of($monto)->isPositive()) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El valor del abono debe ser mayor que 0.');
        }

        $fecha = $this->fecha($datos['fecha_pago'] ?? null);

        // Antes de pagar se reevaluan los pedidos de la liquidacion: un hecho posterior abre su incidencia y retiene lo afectado.
        $this->verificarPedidos(ComisionLinea::query()->where('liquidacion_id', $liquidacionId)->distinct()->pluck('order_id')->all(), $actor);

        $soporte = $this->soporteDe($datos['soporte_id'] ?? null, $liquidacionId);
        $archivo = $soporte !== null
            ? ['disco' => $soporte->disco, 'ruta' => $soporte->ruta, 'nombre' => $soporte->nombre_original, 'mime' => $soporte->mime, 'sha256' => $soporte->sha256]
            : ($comprobante === null ? null : $this->guardarComprobante($comprobante));
        $subioArchivo = $soporte === null && $archivo !== null;

        $medio = $this->texto($datos['medio'] ?? null, 40);
        $referencia = $this->texto($datos['referencia'] ?? null, 120);
        $operacion = $this->operacion($medio, $referencia, $fecha->toDateString(), $monto, $datos['operacion_valor'] ?? null);
        $trazabilidad = $soporte === null ? [null, null] : $this->trazabilidad($soporte, $operacion['valor'], $fecha->toDateString(), $medio, $referencia);
        $cerrojo = $operacion['huella'] === null ? null : $this->cerrojoOperacion($operacion['huella']);

        try {
            return DB::transaction(function () use ($actor, $liquidacionId, $monto, $fecha, $datos, $archivo, $clave, $soporte, $medio, $referencia, $operacion, $trazabilidad) {
                $liq = $this->bloquear($liquidacionId);

                if ($liq->estado !== ComisionLiquidacion::APROBADA) {
                    throw new Invalida(Invalida::ESTADO_INVALIDO, 'Solo se registran pagos a una liquidacion aprobada.');
                }

                if (BigDecimal::of($monto)->isGreaterThan($this->saldo($liq))) {
                    throw new Invalida(Invalida::MONTO_EXCEDE_SALDO, 'El abono supera el saldo pendiente de la liquidacion.');
                }

                $pagable = $this->aplicaciones->pagable($liq);

                if (BigDecimal::of($monto)->isGreaterThan($pagable)) {
                    throw new Invalida(Invalida::MONTO_EXCEDE_SALDO, 'Hoy solo se pueden pagar '.Dinero::texto($pagable).': el resto esta retenido por incidencias abiertas.');
                }

                $this->exigirOperacionNoRepetida($operacion, $monto, $soporte?->id, ! empty($datos['confirmar_misma_operacion']));

                $abono = ComisionAbono::query()->create([
                    'liquidacion_id' => $liq->id,
                    'asesor_admin_id' => $liq->asesor_admin_id,
                    'tipo' => ComisionAbono::ABONO,
                    'monto' => $monto,
                    'fecha_pago' => $fecha,
                    'medio' => $medio,
                    'referencia' => $referencia,
                    'observacion' => $this->texto($datos['observacion'] ?? null, 500),
                    'comprobante_disco' => $archivo['disco'] ?? null,
                    'comprobante_ruta' => $archivo['ruta'] ?? null,
                    'comprobante_nombre' => $archivo['nombre'] ?? null,
                    'comprobante_mime' => $archivo['mime'] ?? null,
                    'comprobante_sha256' => $archivo['sha256'] ?? null,
                    'idempotency_key' => $clave,
                    'actor_admin_id' => (int) $actor->id,
                    'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
                ] + $this->columnasDeOperacion($soporte, $operacion, $trazabilidad));

                $this->aplicaciones->aplicarAbono($liq, $abono);

                return $abono;
            });
        } catch (Throwable $e) {
            // Solo se borra el archivo que ESTA llamada subio; el de un soporte cargado antes se conserva.
            if ($subioArchivo) {
                Storage::disk($archivo['disco'])->delete($archivo['ruta']);
            }

            throw $e;
        } finally {
            $cerrojo !== null && $this->soltarCerrojo($cerrojo);
        }
    }

    /**
     * Huella de la operacion (null si falta referencia, fecha, valor o medio: sin contexto suficiente no hay huella) y valor TOTAL de la
     * operacion (una transferencia puede cubrir varios abonos: nunca menor que este abono).
     *
     * @return array{huella: ?string, referencia: ?string, valor: string}
     */
    protected function operacion(?string $medio, ?string $referencia, string $fecha, string $monto, mixed $valorOperacion): array
    {
        $valor = $valorOperacion === null || trim((string) $valorOperacion) === '' ? $monto : trim((string) $valorOperacion);

        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $valor) || BigDecimal::of($valor)->isLessThan(BigDecimal::of($monto))) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El valor de la operación no puede ser menor que el abono.');
        }

        if (! $this->trazabilidadDisponible()) {
            return ['huella' => null, 'referencia' => null, 'valor' => $valor];
        }

        $soportes = app(Comprobantes\SoporteAbonoService::class);

        return [
            'huella' => $soportes->huella($medio, $referencia, $fecha, $valor),
            'referencia' => $soportes->referenciaNormalizada($referencia),
            'valor' => $valor,
        ];
    }

    /**
     * La misma operacion no respalda mas que su valor total y, si ya respalda otros abonos, exige confirmar que es la MISMA operacion
     * (otra liquidacion o parte de un mismo pago). Sin huella (falta referencia) solo se vigila el reuso del mismo ARCHIVO.
     * Llamar DENTRO de la transaccion y con el cerrojo de la operacion tomado.
     *
     * @param  array{huella: ?string, referencia: ?string, valor: string}  $operacion
     */
    protected function exigirOperacionNoRepetida(array $operacion, string $monto, ?int $soporteId, bool $confirmada): void
    {
        $activos = fn () => ComisionAbono::query()->where('tipo', ComisionAbono::ABONO)
            ->whereNotIn('id', ComisionAbono::query()->where('tipo', ComisionAbono::REVERSO)->select('revierte_id'));

        if ($operacion['huella'] !== null) {
            $previos = $activos()->where('operacion_huella', $operacion['huella'])->get(['id', 'monto', 'liquidacion_id']);

            if ($previos->isEmpty()) {
                return;
            }

            $aplicado = Dinero::de($previos->sum('monto'));
            $restante = Dinero::de($operacion['valor'])->minus($aplicado);

            if (BigDecimal::of($monto)->isGreaterThan($restante)) {
                throw new Invalida(Invalida::OPERACION_DUPLICADA, 'Esta operación ya está registrada por '.Dinero::texto($aplicado).' ('.$previos->map(fn ($a) => 'abono #'.$a->id)->implode(', ').') y su valor total es '.Dinero::texto(Dinero::de($operacion['valor'])).': solo quedan '.Dinero::texto(Dinero::maximo($restante, BigDecimal::zero())).' por aplicar de ella.');
            }

            if (! $confirmada) {
                throw new Invalida(Invalida::OPERACION_DUPLICADA, 'Esta operación ya respalda '.$previos->map(fn ($a) => 'el abono #'.$a->id)->implode(', ').'. Si este abono es otra parte del mismo pago, confirma que es la misma operación.');
            }

            return;
        }

        if ($soporteId !== null && ! $confirmada && $activos()->where('soporte_id', $soporteId)->exists()) {
            throw new Invalida(Invalida::OPERACION_DUPLICADA, 'Este comprobante ya respalda otro abono y no se pudo comparar la operación (falta la referencia). Confirma que corresponde a este pago.');
        }
    }

    /** Soporte cargado ANTES para esta liquidacion; el abono lo respalda sin subir otro archivo. */
    protected function soporteDe(mixed $id, int $liquidacionId): ?ComisionSoporte
    {
        if ($id === null || $id === '') {
            return null;
        }

        $soporte = ctype_digit((string) $id) ? ComisionSoporte::query()->find((int) $id) : null;

        if ($soporte === null || (int) $soporte->liquidacion_id !== $liquidacionId || ! Storage::disk($soporte->disco)->exists($soporte->ruta)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El comprobante cargado ya no está disponible para esta liquidación: súbelo de nuevo.');
        }

        return $soporte;
    }

    /**
     * Lo que el lector DETECTO y lo que administracion CORRIGIO (comparado en el servidor, no confiado al navegador): trazabilidad de datos.
     *
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}
     */
    protected function trazabilidad(ComisionSoporte $soporte, string $valor, string $fecha, ?string $medio, ?string $referencia): array
    {
        $d = (array) (($soporte->lectura_resultado ?? [])[0] ?? []);

        if ($d === []) {
            return [null, null];
        }

        $detectado = [
            'valor' => $d['valor'] ?? null,
            'fecha' => $d['fecha'] ?? null,
            'medio' => $d['medio'] ?? $d['banco_origen'] ?? $d['banco_destino'] ?? null,
            'referencia' => $d['referencia'] ?? $d['autorizacion'] ?? null,
            'destinatario' => $d['contraparte'] ?? null,
            'advertencias' => $d['advertencias'] ?? [],
        ];
        $final = ['valor' => $valor, 'fecha' => $fecha, 'medio' => $medio, 'referencia' => $referencia];
        $iguales = fn ($a, $b) => $a === null || $b === null
            ? $a === $b
            : (is_numeric($a) && is_numeric($b) ? bccomp((string) $a, (string) $b, 2) === 0 : mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b)));
        $corregido = [];

        foreach ($final as $campo => $nuevo) {
            if (! $iguales($detectado[$campo], $nuevo)) {
                $corregido[$campo] = ['detectado' => $detectado[$campo], 'final' => $nuevo];
            }
        }

        return [$detectado, $corregido];
    }

    /**
     * Columnas de la operacion y su trazabilidad. Solo se incluyen cuando hay algo que guardar: el abono manual de siempre no las toca.
     *
     * @param  array{huella: ?string, referencia: ?string, valor: string}  $operacion
     * @param  array{0: ?array<string, mixed>, 1: ?array<string, mixed>}  $trazabilidad
     * @return array<string, mixed>
     */
    protected function columnasDeOperacion(?ComisionSoporte $soporte, array $operacion, array $trazabilidad): array
    {
        if (! $this->trazabilidadDisponible()) {
            return [];
        }

        return array_filter([
            'soporte_id' => $soporte?->id,
            'operacion_huella' => $operacion['huella'],
            'operacion_referencia' => $operacion['referencia'],
            'operacion_valor' => $operacion['huella'] === null && $soporte === null ? null : $operacion['valor'],
            'lectura_detectada' => $trazabilidad[0],
            'lectura_corregida' => $trazabilidad[1],
        ], fn ($v) => $v !== null);
    }

    /** ¿Ya esta aplicada la migracion de soportes y trazabilidad en ESTE entorno? Sin ella el abono manual de siempre sigue igual. */
    protected function trazabilidadDisponible(): bool
    {
        static $disponible = null;

        return $disponible ??= \Illuminate\Support\Facades\Schema::hasColumn('bylopez_va_comision_abonos', 'operacion_huella');
    }

    /** Serializa los registros de UNA misma operacion (huella) entre solicitudes simultaneas, aunque sean de liquidaciones distintas. */
    protected function cerrojoOperacion(string $huella): string
    {
        $nombre = 'va_op_'.substr($huella, 0, 40);

        if (! (int) (DB::selectOne('SELECT GET_LOCK(?, 15) AS l', [$nombre])->l ?? 0)) {
            throw new Invalida(Invalida::ESTADO_INVALIDO, 'Otro registro de esta misma operación está en curso. Espera unos segundos y verifica el estado.');
        }

        return $nombre;
    }

    protected function soltarCerrojo(string $nombre): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS l', [$nombre]);
    }

    public function revertirAbono(?Admin $actor, int $abonoId, string $motivo, string $clave): ComisionAbono
    {
        $this->exigir($actor, Autorizacion::PERMISO_COMISIONES_REVERTIR);
        $motivo = $this->motivo($motivo);
        $clave = $this->clave($clave);

        if ($existente = ComisionAbono::query()->where('idempotency_key', $clave)->first()) {
            return $existente;
        }

        return DB::transaction(function () use ($actor, $abonoId, $motivo, $clave) {
            $abono = ComisionAbono::query()->lockForUpdate()->find($abonoId) ?? throw new Invalida(Invalida::NO_ENCONTRADO, 'Abono inexistente.');
            $this->bloquear($abono->liquidacion_id);

            if ($abono->tipo !== ComisionAbono::ABONO || ComisionAbono::query()->where('revierte_id', $abono->id)->exists()) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'Ese abono ya fue revertido o no es un abono.');
            }

            $reverso = ComisionAbono::query()->create([
                'liquidacion_id' => $abono->liquidacion_id,
                'asesor_admin_id' => $abono->asesor_admin_id,
                'tipo' => ComisionAbono::REVERSO,
                'monto' => $abono->monto,
                'revierte_id' => $abono->id,
                'fecha_pago' => now()->toDateString(),
                'observacion' => $motivo,
                'idempotency_key' => $clave,
                'actor_admin_id' => (int) $actor->id,
                'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
            ]);

            $this->aplicaciones->aplicarReverso($reverso, $abono);

            return $reverso;
        });
    }

    public function crearAjuste(?Admin $actor, int $asesorId, mixed $monto, string $motivo, ?int $lineaId, string $clave): ComisionAjuste
    {
        $this->exigir($actor, Autorizacion::PERMISO_COMISIONES_AJUSTAR);
        $motivo = $this->motivo($motivo);
        $clave = $this->clave($clave);
        $monto = trim((string) $monto);

        if (! preg_match('/^-?\d{1,12}(\.\d{1,2})?$/', $monto) || BigDecimal::of($monto)->isZero()) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El ajuste debe tener un valor distinto de 0 (negativo para descontar).');
        }

        $linea = $lineaId === null ? null : ComisionLinea::query()->find($lineaId);

        if ($lineaId !== null && ($linea === null || $linea->asesor_admin_id !== $asesorId)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'La linea indicada no es de ese asesor.');
        }

        return ComisionAjuste::query()->firstOrCreate(['idempotency_key' => $clave], [
            'asesor_admin_id' => $asesorId,
            'linea_id' => $linea?->id,
            'order_id' => $linea?->order_id,
            'monto' => $monto,
            'origen' => 'manual',
            'motivo' => $motivo,
            'estado' => ComisionAjuste::PENDIENTE,
            'creado_por_admin_id' => (int) $actor->id,
            'creado_por_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
        ]);
    }

    public function resolverAjuste(?Admin $actor, int $id, bool $aprobar, string $motivo): ComisionAjuste
    {
        $this->exigir($actor, Autorizacion::PERMISO_COMISIONES_APROBAR);
        $motivo = $this->motivo($motivo);

        return DB::transaction(function () use ($actor, $id, $aprobar, $motivo) {
            $ajuste = ComisionAjuste::query()->lockForUpdate()->find($id) ?? throw new Invalida(Invalida::NO_ENCONTRADO, 'Ajuste inexistente.');

            if ($ajuste->estado !== ComisionAjuste::PENDIENTE) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'El ajuste ya fue resuelto.');
            }

            $aprobar && $this->incidencias->exigirAjusteCoherente($ajuste);

            $ajuste->update([
                'estado' => $aprobar ? ComisionAjuste::APROBADO : ComisionAjuste::RECHAZADO,
                'resuelto_por_admin_id' => (int) $actor->id,
                'resuelto_por_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
                'resuelto_en' => now(),
                'motivo_resolucion' => $motivo,
            ]);

            return $ajuste;
        });
    }

    public function abonado(ComisionLiquidacion $liq): BigDecimal
    {
        $filas = ComisionAbono::query()->where('liquidacion_id', $liq->id)->groupBy('tipo')->selectRaw('tipo, SUM(monto) AS t')->pluck('t', 'tipo');

        return Dinero::de($filas[ComisionAbono::ABONO] ?? 0)->minus(Dinero::de($filas[ComisionAbono::REVERSO] ?? 0));
    }

    public function saldo(ComisionLiquidacion $liq): BigDecimal
    {
        return $liq->estado === ComisionLiquidacion::ANULADA ? BigDecimal::zero() : Dinero::de($liq->total)->minus($this->abonado($liq));
    }

    /**
     * Comprobante PRIVADO: exige la clave `comprobantes` (tambien aqui, no solo en la ruta) y ademas pagar o ver todas, o
     * ser el propio asesor (view_own) del abono.
     */
    public function comprobante(?Admin $actor, int $abonoId): Response
    {
        $abono = ComisionAbono::query()->find($abonoId);

        $puede = $abono !== null && $this->autorizacion->tienePermiso($actor, self::PERMISO_COMPROBANTES) && (
            $this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_COMISIONES_PAGAR)
            || $this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_COMISIONES_VER_TODAS)
            || ($this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_COMISIONES_VER_PROPIAS) && (int) $actor?->id === $abono->asesor_admin_id)
        );

        if (! $puede || $abono->comprobante_ruta === null || ! Storage::disk($abono->comprobante_disco)->exists($abono->comprobante_ruta)) {
            throw new Invalida(Invalida::NO_ENCONTRADO, 'Comprobante no disponible.');
        }

        return Storage::disk($abono->comprobante_disco)->response($abono->comprobante_ruta, $abono->comprobante_nombre ?: 'comprobante', [
            'Content-Type' => $abono->comprobante_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array{disco: string, ruta: string, nombre: string, mime: string, sha256: string} */
    protected function guardarComprobante(UploadedFile $archivo): array
    {
        $cfg = (array) config('ventasasesor.comisiones.comprobantes');
        $ext = strtolower((string) $archivo->guessExtension());

        if (! $archivo->isValid() || ! in_array($ext, (array) $cfg['mimes'], true) || $archivo->getSize() > ((int) $cfg['max_kb']) * 1024) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El comprobante debe ser PDF, JPG o PNG de hasta '.((int) $cfg['max_kb'] / 1024).' MB.');
        }

        $disco = (string) $cfg['disco'];
        $ruta = Storage::disk($disco)->putFileAs('ventas-asesor/comisiones/'.now()->format('Y/m'), $archivo, Str::uuid().'.'.$ext);

        if ($ruta === false) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'No se pudo guardar el comprobante.');
        }

        return [
            'disco' => $disco,
            'ruta' => $ruta,
            'nombre' => mb_substr((string) $archivo->getClientOriginalName(), 0, 191),
            'mime' => (string) $archivo->getMimeType(),
            'sha256' => hash_file('sha256', $archivo->getRealPath()),
        ];
    }

    /**
     * Reevalua los pedidos contra los hechos VIGENTES antes de liquidar, aprobar o pagar. Si la verificacion falla, la
     * operacion no sigue: pagar sin saber si la comision sigue en pie es justo lo que se quiere evitar.
     *
     * @param  list<int|string>  $orderIds
     */
    protected function verificarPedidos(array $orderIds, ?Admin $actor): void
    {
        foreach (array_unique(array_map('intval', $orderIds)) as $orderId) {
            try {
                $this->evaluar->evaluarPedido($orderId, $actor?->id === null ? null : (int) $actor->id);
            } catch (Invalida $e) {
                throw $e;
            } catch (Throwable) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'No se pudieron verificar las condiciones del pedido #'.$orderId.'. Intente de nuevo.');
            }
        }
    }

    protected function bloquear(int $id): ComisionLiquidacion
    {
        return ComisionLiquidacion::query()->lockForUpdate()->find($id) ?? throw new Invalida(Invalida::NO_ENCONTRADO, 'Liquidacion inexistente.');
    }

    protected function exigir(?Admin $actor, string $permiso): void
    {
        if (! $actor?->id || ! $this->autorizacion->tienePermiso($actor, $permiso)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tiene permiso para esta accion.');
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function periodo(string $desde, string $hasta): array
    {
        $d = $this->fecha($desde);
        $h = $this->fecha($hasta);

        if ($h->lt($d)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El periodo termina antes de empezar.');
        }

        return [$d, $h];
    }

    protected function fecha(mixed $v): CarbonImmutable
    {
        $v = trim((string) $v);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'Fecha no valida (AAAA-MM-DD).');
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $v)->startOfDay();
    }

    protected function motivo(string $motivo): string
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 5) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'Escriba el motivo (minimo 5 caracteres).');
        }

        return mb_substr($motivo, 0, 500);
    }

    protected function clave(string $clave): string
    {
        $clave = trim($clave);

        if ($clave === '' || mb_strlen($clave) > 120) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'Falta la clave de la operacion: recargue la pantalla.');
        }

        return $clave;
    }

    protected function texto(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
