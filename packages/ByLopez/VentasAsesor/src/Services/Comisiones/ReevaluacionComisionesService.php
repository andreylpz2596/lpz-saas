<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use ByLopez\VentasAsesor\Jobs\ReevaluarComisionesJob;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Models\ComisionReevaluacion;
use ByLopez\VentasAsesor\Models\RentabilidadEvaluacion;
use ByLopez\VentasAsesor\Services\Rentabilidad\RentabilidadVentaService;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Doc. 23 §10.2 — REEVALUACION AUTOMATICA de comisiones, desacoplada del hecho que la provoca.
 *
 *  1. `solicitar()` se llama DENTRO de la transaccion del hecho (validacion, cobro, liquidacion, cancelacion,
 *     reasignacion...): solo marca el pedido en la bandeja durable (upsert, una fila por pedido). Nunca lanza: si la marca
 *     falla, el hecho se confirma igual y la recuperacion manual (boton / comando) lo cubre.
 *  2. Tras el COMMIT se despacha `ReevaluarComisionesJob` a la cola configurada. Con `QUEUE_CONNECTION=sync` corre en la
 *     misma peticion, despues del commit y atrapando todo error: la entrega o el cobro ya quedaron confirmados.
 *  3. `procesar()` evalua cada pedido pendiente en su propia transaccion (EvaluarComisionesService, idempotente). Si falla,
 *     cuenta el intento y reprograma con espera creciente; el comando programado lo reintenta.
 *  4. CASCADA acotada: si la evaluacion cambio el libro del umbral de un asesor y mes (o el pedido es nuevo), se marcan los
 *     pedidos POSTERIORES de ese asesor y mes (su tramo pudo moverse). No hay bucle: reevaluar un pedido que no cambia su
 *     libro no genera cascada, y cada corrida tiene un tope.
 *
 * No se salta nada: la evaluacion sigue exigiendo fecha de inicio, A-04, politicas pendientes y alertas.
 */
class ReevaluacionComisionesService
{
    /** @var array<int, true> pedidos marcados en esta peticion, por despachar tras el commit */
    private array $porDespachar = [];

    private bool $procesando = false;

    public function __construct(
        protected EvaluarComisionesService $evaluar,
        protected RentabilidadVentaService $rentabilidad,
        protected LineasComisionService $lineas,
    ) {}

    /**
     * Marca pedidos para reevaluar. Seguro dentro de cualquier transaccion: nunca lanza.
     *
     * @param  iterable<int|string|null>  $orderIds
     */
    public function solicitar(iterable $orderIds, string $origen, bool $creacion = false): void
    {
        $ids = [];

        foreach ($orderIds as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }

        if ($ids === []) {
            return;
        }

        try {
            $ahora = now();
            $origen = mb_substr($origen, 0, 60);

            foreach (array_chunk(array_keys($ids), 200) as $lote) {
                DB::table('bylopez_va_comision_reevaluaciones')->upsert(
                    array_map(fn (int $id) => [
                        'order_id' => $id, 'version' => 1, 'version_procesada' => 0, 'origen' => $origen, 'creacion' => $creacion,
                        'intentos' => 0, 'created_at' => $ahora, 'updated_at' => $ahora,
                    ], $lote),
                    ['order_id'],
                    [
                        // ON DUPLICATE KEY UPDATE asigna de izquierda a derecha: `creacion` lee la version ANTERIOR.
                        'creacion' => DB::raw('IF(version_procesada >= version, VALUES(creacion), GREATEST(creacion, VALUES(creacion)))'),
                        'version' => DB::raw('version + 1'),
                        'origen' => DB::raw('VALUES(origen)'),
                        // Un hecho nuevo merece un intento inmediato aunque el anterior hubiera fallado.
                        'intentos' => 0,
                        'siguiente_intento_en' => null,
                        'updated_at' => $ahora,
                    ],
                );
            }

            $this->porDespachar += $ids;
            DB::afterCommit(fn () => $this->despachar());
        } catch (Throwable $e) {
            $this->registrarFallo('ventas_asesor.comisiones.reevaluacion.no_marcada', ['pedidos' => array_slice(array_keys($ids), 0, 20), 'origen' => $origen], $e);
        }
    }

    /** Todos los pedidos con lineas vivas de un asesor en un mes (p. ej. al cambiar su acuerdo). */
    public function solicitarAsesorMes(int $asesorId, string $mes, string $origen): void
    {
        try {
            $ids = ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('mes_comercial', $mes)
                ->where('estado', '!=', ComisionLinea::ANULADA)->distinct()->pluck('order_id')->all();
        } catch (Throwable $e) {
            $this->registrarFallo('ventas_asesor.comisiones.reevaluacion.no_marcada', ['asesor' => $asesorId, 'mes' => $mes], $e);

            return;
        }

        $this->solicitar($ids, $origen);
    }

    /**
     * Procesa la bandeja: los pedidos indicados (o todos los vencidos) y sus cascadas, hasta `$limite` evaluaciones.
     *
     * @param  list<int>|null  $orderIds
     * @return array{evaluados: int, fallidos: int, cascada: int, pendientes: int}
     */
    public function procesar(?array $orderIds = null, ?int $limite = null): array
    {
        $limite ??= (int) config('ventasasesor.comisiones.reevaluacion.lote', 50);
        $resumen = ['evaluados' => 0, 'fallidos' => 0, 'cascada' => 0, 'pendientes' => 0];
        $filtro = $orderIds;
        $previo = $this->procesando;
        $this->procesando = true;

        try {
            while ($limite > $resumen['evaluados'] + $resumen['fallidos']) {
                $filas = $this->vencidas($filtro, $limite - $resumen['evaluados'] - $resumen['fallidos']);

                if ($filas->isEmpty()) {
                    break;
                }

                $cascada = [];

                foreach ($filas as $fila) {
                    $this->procesarFila($fila, $resumen, $cascada);
                }

                // Siguiente vuelta: solo lo que la cascada marco (si se pidieron pedidos concretos).
                if ($filtro !== null) {
                    $filtro = array_keys($cascada);

                    if ($filtro === []) {
                        break;
                    }
                }
            }
        } finally {
            $this->procesando = $previo;
        }

        $resumen['pendientes'] = $this->pendientes();

        return $resumen;
    }

    public function pendientes(): int
    {
        return ComisionReevaluacion::query()->whereColumn('version', '>', 'version_procesada')->count();
    }

    /** Pendientes que agotaron sus reintentos automaticos (requieren el boton o el comando). */
    public function agotadas(): int
    {
        return ComisionReevaluacion::query()->whereColumn('version', '>', 'version_procesada')
            ->where('intentos', '>=', $this->maxIntentos())->count();
    }

    /** Recuperacion manual: las que agotaron sus reintentos vuelven a intentarse ya. */
    public function reintentarAgotadas(): int
    {
        return ComisionReevaluacion::query()->whereColumn('version', '>', 'version_procesada')->where('intentos', '>=', $this->maxIntentos())
            ->update(['intentos' => 0, 'siguiente_intento_en' => null, 'updated_at' => now()]);
    }

    /** Despacho tras el commit. Con cola `sync` procesa aqui mismo; nunca lanza. */
    public function despachar(): void
    {
        if ($this->porDespachar === [] || $this->procesando) {
            return;
        }

        $ids = array_keys($this->porDespachar);
        $this->porDespachar = [];

        if (! (bool) config('ventasasesor.comisiones.reevaluacion.automatica', true)) {
            return;
        }

        try {
            $job = new ReevaluarComisionesJob($ids);
            $conexion = config('ventasasesor.comisiones.reevaluacion.conexion');
            $conexion && $job->onConnection((string) $conexion);
            $job->onQueue((string) config('ventasasesor.comisiones.reevaluacion.cola', 'default'));

            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $e) {
            // La fila sigue pendiente en la bandeja: la toma el comando programado o el boton.
            $this->registrarFallo('ventas_asesor.comisiones.reevaluacion.no_despachada', ['pedidos' => array_slice($ids, 0, 20)], $e);
        }
    }

    /** @param  array<int, true>  $cascada */
    protected function procesarFila(ComisionReevaluacion $fila, array &$resumen, array &$cascada): void
    {
        $version = $fila->version;

        try {
            if ($fila->creacion) {
                $this->registrarSnapshotTardio($fila->order_id);
            }

            $r = $this->evaluar->evaluarPedido($fila->order_id);

            ComisionReevaluacion::query()->whereKey($fila->id)->where('version_procesada', '<', $version)->update([
                'version_procesada' => $version, 'creacion' => false, 'intentos' => 0, 'siguiente_intento_en' => null,
                'ultimo_error' => null, 'procesada_en' => now(), 'updated_at' => now(),
            ]);
            $resumen['evaluados']++;

            $meses = $r['meses_cambiados'];

            if ($fila->creacion) {
                $meses = array_values(array_unique([...$meses, ...$r['meses_pedido']]));
            }

            $nuevos = array_values(array_filter($this->posteriores($fila->order_id, $meses), fn (int $id) => ! isset($cascada[$id])));

            if ($nuevos !== []) {
                $this->solicitar($nuevos, 'cascada_umbral');
                $cascada += array_fill_keys($nuevos, true);
                $resumen['cascada'] += count($nuevos);
            }
        } catch (Throwable $e) {
            $intentos = $fila->intentos + 1;

            ComisionReevaluacion::query()->whereKey($fila->id)->update([
                'intentos' => $intentos,
                'siguiente_intento_en' => now()->addMinutes(min(60, 2 ** min($intentos, 6))),
                'ultimo_error' => mb_substr($e::class.': '.$e->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);
            $resumen['fallidos']++;
            $this->registrarFallo('ventas_asesor.comisiones.reevaluacion.fallida', ['order_id' => $fila->order_id, 'intento' => $intentos], $e);
        }
    }

    /**
     * Si el listener de creacion fallo, el snapshot y las lineas se registran ahora, con los parametros VIGENTES AL
     * CONFIRMAR (RentabilidadVentaService::dePedido usa la fecha del pedido). Solo pedidos marcados por su creacion: nunca
     * crea snapshots de pedidos historicos.
     */
    protected function registrarSnapshotTardio(int $orderId): void
    {
        if (RentabilidadEvaluacion::query()->where('order_id', $orderId)->exists()) {
            return;
        }

        DB::transaction(function () use ($orderId) {
            $this->rentabilidad->registrarDePedido($orderId);
            $this->lineas->asegurar($orderId);
        });
    }

    /**
     * Pedidos con lineas vivas del mismo asesor y mes, confirmados DESPUES de `$orderId` (su tramo depende del acumulado).
     *
     * @param  list<string>  $meses  claves «asesor|mes»
     * @return list<int>
     */
    protected function posteriores(int $orderId, array $meses): array
    {
        if ($meses === []) {
            return [];
        }

        $confirmado = DB::table('orders')->where('id', $orderId)->value('created_at');
        $ids = [];

        foreach ($meses as $clave) {
            [$asesor, $mes] = explode('|', $clave, 2);

            if (! ctype_digit($asesor) || ! MesComercial::valido($mes)) {
                continue;
            }

            $ids = [...$ids, ...ComisionLinea::query()->where('asesor_admin_id', (int) $asesor)->where('mes_comercial', $mes)
                ->where('estado', '!=', ComisionLinea::ANULADA)->where('order_id', '!=', $orderId)
                ->when($confirmado !== null, fn ($q) => $q->where(fn ($w) => $w->where('confirmado_en', '>', $confirmado)
                    ->orWhere(fn ($e) => $e->where('confirmado_en', $confirmado)->where('order_id', '>', $orderId))))
                ->distinct()->pluck('order_id')->map(fn ($id) => (int) $id)->all()];
        }

        return array_values(array_unique($ids));
    }

    /** @param  list<int>|null  $ids */
    protected function vencidas(?array $ids, int $limite)
    {
        return ComisionReevaluacion::query()->whereColumn('version', '>', 'version_procesada')
            ->where('intentos', '<', $this->maxIntentos())
            ->where(fn ($q) => $q->whereNull('siguiente_intento_en')->orWhere('siguiente_intento_en', '<=', now()))
            ->when($ids !== null, fn ($q) => $q->whereIn('order_id', $ids ?: [0]))
            ->orderBy('updated_at')->orderBy('id')->limit(max(1, $limite))->get();
    }

    protected function maxIntentos(): int
    {
        return max(1, (int) config('ventasasesor.comisiones.reevaluacion.max_intentos', 8));
    }

    private function registrarFallo(string $mensaje, array $contexto, Throwable $e): void
    {
        try {
            Log::warning($mensaje, $contexto + ['excepcion' => $e::class, 'detalle' => mb_substr($e->getMessage(), 0, 300)]);
        } catch (Throwable) {
            // Sin log disponible no se arriesga el hecho de origen.
        }
    }
}
