<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\RecepcionCorte;
use ByLopez\CorteConfeccion\Repositories\RecepcionCorteRepository;
use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use ByLopez\CorteConfeccion\Support\OrdenCorteEstadoMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecepcionCorteService
{
    public function __construct(
        protected RecepcionCorteRepository $recepcionCorteRepository,
    ) {}

    public function create(OrdenCorte $orden, array $data): RecepcionCorte
    {
        if (! CantidadCortadaGate::userCanConfirmarRecepcion()) {
            throw ValidationException::withMessages([
                'orden_corte_id' => trans('corteconfeccion::app.errors.recepcion-no-autorizada'),
            ]);
        }

        return DB::transaction(function () use ($orden, $data) {
            $orden->load(['recepcion', 'detalles']);

            if (! OrdenCorteEstadoMachine::puedeTransicionar($orden->estado, EstadoOrdenCorte::RecibidoBodega->value)) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.orden-no-reportada')]);
            }

            if ($orden->recepcion) {
                throw ValidationException::withMessages(['orden_corte_id' => trans('corteconfeccion::app.errors.recepcion-existente')]);
            }

            // F3: la recepcion registra UNA sola merma total no reutilizable
            // de toda la orden. Ya no acepta sobrante usable/retazos/
            // desperdicio por separado (esos pesos solo existen en recepciones
            // historicas). Se rechazan explicitamente en vez de ignorarlos:
            // descartar en silencio un "sobrante usable" ocultaria tela que
            // el usuario cree que volvera al inventario.
            $this->rechazarPesosLegacy($data);

            $pesoBase = round($orden->pesoBase(), 3);
            $mermaTotal = $this->validarMermaTotal($data, $pesoBase);

            $detallesInput = $data['detalles'];
            unset($data['detalles']);

            // Identidad real de cada fila por orden_corte_detalle_id, nunca
            // por posicion de array. Se resuelve e integra ANTES de escribir
            // nada: el Service tambien se llama directamente (p.ej. desde
            // tests) sin pasar por StoreRecepcionCorteRequest, asi que no
            // puede confiar unicamente en esa validacion -- debe repetir aqui
            // las comprobaciones de identidad que le corresponden a el, igual
            // que ya hace mas abajo con la autorizacion de diferencias.
            $detallesOrdenPorId = $orden->detalles->keyBy('id');
            $idsUsados = [];

            foreach ($detallesInput as $detalleInput) {
                $detalleOrdenId = (int) ($detalleInput['orden_corte_detalle_id'] ?? 0);

                if (! $detalleOrdenId || ! $detallesOrdenPorId->has($detalleOrdenId)) {
                    throw ValidationException::withMessages([
                        'detalles' => trans('corteconfeccion::app.errors.recepcion-detalle-no-pertenece-orden'),
                    ]);
                }

                if (isset($idsUsados[$detalleOrdenId])) {
                    throw ValidationException::withMessages([
                        'detalles' => trans('corteconfeccion::app.errors.recepcion-detalle-duplicado'),
                    ]);
                }

                $idsUsados[$detalleOrdenId] = true;
            }

            // Payload explicito (nunca $data completo): los pesos historicos
            // (peso_sobrante_usable/peso_retasos/peso_desperdicio) quedan en
            // su default 0 y solo merma_total lleva el dato F3.
            $recepcion = $this->recepcionCorteRepository->create([
                'orden_corte_id' => $orden->id,
                'fecha_recepcion' => $data['fecha_recepcion'],
                'merma_total' => $mermaTotal,
                'observacion' => $data['observacion'] ?? null,
                'recibido_por' => $data['recibido_por'],
            ]);

            foreach ($detallesInput as $detalleInput) {
                // Producto, SKU, nombre y talla NUNCA se leen del payload del
                // cliente: se derivan del OrdenCorteDetalle real, que ya trae
                // su propio snapshot tomado al crear la solicitud (mismo dato
                // que vio el cortador). Bodega no puede sustituir el producto
                // ni la talla de una fila via manipulacion del formulario.
                $detalleOrden = $detallesOrdenPorId->get((int) $detalleInput['orden_corte_detalle_id']);

                $recepcion->detalles()->create([
                    'orden_corte_detalle_id' => $detalleOrden->id,
                    'product_id' => $detalleOrden->product_id,
                    'producto_sku' => $detalleOrden->producto_sku,
                    'producto_nombre' => $detalleOrden->producto_nombre,
                    'talla' => $detalleOrden->talla,
                    'cantidad_recibida' => (int) $detalleInput['cantidad_recibida'],
                    'cantidad_defectuosa' => (int) $detalleInput['cantidad_defectuosa'],
                    'observacion' => $detalleInput['observacion'] ?? null,
                ]);
            }

            // La cantidad reportada por el cortador (OrdenCorteDetalle.cantidad_real_cortada) es un dato
            // historico inmutable a partir de aqui: la recepcion de bodega NUNCA la sobreescribe, solo
            // se compara contra ella (ver RecepcionCorteDetalle.cantidad_recibida) para calcular la
            // diferencia. La comparacion es por orden_corte_detalle_id (identidad real), no por
            // posicion dentro del array enviado -- ver AUD-02 §12.2 para el bug que esto corrige.
            foreach ($detallesInput as $detalleInput) {
                $detalleOrden = $detallesOrdenPorId->get((int) $detalleInput['orden_corte_detalle_id']);

                $cantidadReportada = (int) $detalleOrden->cantidad_real_cortada;
                $cantidadRecibida = (int) $detalleInput['cantidad_recibida'];

                if ($cantidadReportada !== $cantidadRecibida && ! CantidadCortadaGate::userCanEdit(null, $orden)) {
                    throw ValidationException::withMessages([
                        'cantidad_cortada' => trans('corteconfeccion::app.errors.cantidad-cortada-no-autorizada'),
                    ]);
                }
            }

            $totalPiezas = (int) collect($detallesInput)->sum(fn ($detalle) => (int) $detalle['cantidad_recibida']);

            // Sin sobrante reutilizable: todo el peso que no termino en
            // prendas es merma de la orden. La merma se guarda UNA vez a
            // nivel de orden y jamas se atribuye a un rollo, color o gramaje.
            $pesoConsumido = round($pesoBase - $mermaTotal, 3);

            $orden->update([
                'peso_sobrante_total' => 0,
                'merma_total' => $mermaTotal,
                'merma_unica' => true,
                'peso_consumido_total' => $pesoConsumido,
                'total_piezas_cortadas' => $totalPiezas,
                'peso_promedio_pieza' => $totalPiezas > 0 ? round($pesoConsumido / $totalPiezas, 6) : 0,
                'estado' => EstadoOrdenCorte::RecibidoBodega->value,
            ]);

            return $recepcion->refresh();
        });
    }

    protected function rechazarPesosLegacy(array $data): void
    {
        foreach (['peso_sobrante_usable', 'peso_retasos', 'peso_desperdicio'] as $campoLegacy) {
            if (array_key_exists($campoLegacy, $data)) {
                throw ValidationException::withMessages([
                    $campoLegacy => trans('corteconfeccion::app.errors.recepcion-pesos-legacy'),
                ]);
            }
        }
    }

    /**
     * Repite en el Service la validacion del Request (el Service tambien se
     * llama directamente): numerica, >= 0, a lo mas 3 decimales y sin superar
     * el peso de tela asignado a la orden.
     */
    protected function validarMermaTotal(array $data, float $pesoBase): float
    {
        $valor = $data['merma_total'] ?? null;

        if (! is_numeric($valor)) {
            throw ValidationException::withMessages(['merma_total' => trans('corteconfeccion::app.errors.merma-total-invalida')]);
        }

        $merma = round((float) $valor, 3);

        if ($merma < 0 || abs((float) $valor - $merma) > 0.0000001) {
            throw ValidationException::withMessages(['merma_total' => trans('corteconfeccion::app.errors.merma-total-invalida')]);
        }

        if ($merma > $pesoBase) {
            throw ValidationException::withMessages(['merma_total' => trans('corteconfeccion::app.errors.merma-excede-peso-asignado')]);
        }

        return $merma;
    }
}
