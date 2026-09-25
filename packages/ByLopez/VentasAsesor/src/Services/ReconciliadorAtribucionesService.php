<?php

namespace ByLopez\VentasAsesor\Services;

use ByLopez\VentasAsesor\DataObjects\AnalisisPedidoHistorico;
use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\Enums\CodigoIncidencia;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\EstadoIncidencia;
use ByLopez\VentasAsesor\Enums\ResultadoReconciliacion;
use ByLopez\VentasAsesor\Exceptions\AtribucionEnConflictoException;
use ByLopez\VentasAsesor\Support\FuenteEvidenciaHistorica;
use Generator;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Reconciliacion de atribuciones de pedidos EXISTENTES (Fase 02B1B).
 *
 * Regla que manda sobre todo el archivo: NO INVENTAR HISTORIA.
 *
 * Este servicio NO usa ClasificadorOrigenPedido, ContextoComercialDePeticion, la
 * peticion HTTP, el guard administrativo ni la ruta actual: esos son el contexto
 * EFIMERO de la peticion que creo el pedido, y esa peticion ya no existe. Un comando
 * ejecutado hoy no puede saber por donde entro un pedido de hace dias, y que en la
 * consola no haya request no significa que el pedido naciera sin HTTP. Aqui solo se
 * mira EVIDENCIA PERSISTIDA, y solo a traves de FuenteEvidenciaHistorica.
 *
 * Los campos que hoy conserva un pedido (cart_id, customer_id, is_guest, channel_id,
 * fechas, estado) NO prueban su origen: un pedido de la tienda y uno creado desde el
 * panel tienen exactamente los mismos. Se muestran como hechos del reporte, nunca
 * como fundamento de una atribucion.
 *
 * Escritura: solo a traves de RegistrarAtribucionService (idempotente, con conflicto
 * e historial inicial) e IncidenciasAtribucionService. Nunca sobrescribe, nunca
 * fuerza. analizar() es de solo lectura; en dry-run ni siquiera se llama a aplicar().
 */
class ReconciliadorAtribucionesService
{
    /** Nota que deja el registro de un pedido sin evidencia. Texto fijo, sin datos del cliente. */
    public const NOTA_SIN_EVIDENCIA = 'Reconciliacion historica: sin evidencia persistida suficiente sobre el origen del pedido.';

    /**
     * @param  iterable<FuenteEvidenciaHistorica>  $fuentes
     */
    public function __construct(
        protected RegistrarAtribucionService $registrar,
        protected ResolverAtribucionService $resolver,
        protected IncidenciasAtribucionService $incidencias,
        #[Tag('ventasasesor.fuentes_evidencia')]
        protected iterable $fuentes = [],
    ) {}

    /**
     * Recorre los pedidos por lotes (memoria constante) y entrega el veredicto de cada
     * uno. Con $persistir = false NO se llama a ninguna escritura. Un error en un pedido
     * se convierte en resultado ERROR de ESE pedido y el recorrido continua.
     *
     * @return Generator<int, AnalisisPedidoHistorico>
     */
    public function ejecutar(bool $persistir, ?int $orderId = null, ?int $limite = null): Generator
    {
        $entregados = 0;

        foreach ($this->consulta($orderId)->lazyById(200, 'o.id', 'id') as $fila) {
            if ($limite !== null && $entregados >= $limite) {
                return;
            }

            $entregados++;

            try {
                $analisis = $this->analizar($fila);
            } catch (Throwable $e) {
                yield $this->error($fila, $e, 'analisis_fallido');

                continue;
            }

            if (! $persistir) {
                yield $analisis;

                continue;
            }

            try {
                yield $this->aplicar($analisis);
            } catch (Throwable $e) {
                yield $this->error($fila, $e, 'escritura_fallida');
            }
        }
    }

    /** SOLO LECTURA. Decide el veredicto de un pedido sin tocar nada. */
    public function analizar(object $pedido): AnalisisPedidoHistorico
    {
        $base = new AnalisisPedidoHistorico(
            orderId: (int) $pedido->id,
            incrementId: $pedido->increment_id ?? null,
            cartId: isset($pedido->cart_id) ? (int) $pedido->cart_id : null,
            estadoPedido: $pedido->status ?? null,
            resultado: ResultadoReconciliacion::SIN_EVIDENCIA,
            codigo: 'sin_evidencia_persistida',
            accionPersistente: 'registrar sin_asesor / canal desconocido / mecanismo desconocido (sin evidencia persistida)',
            atribucionId: isset($pedido->atribucion_id) ? (int) $pedido->atribucion_id : null,
            hechos: $this->hechos($pedido),
        );

        if ($base->atribucionId !== null) {
            return $this->analizarConAtribucion($pedido, $base);
        }

        [$contexto, $fuente, $contradictorias] = $this->evidencia($pedido);

        if ($contradictorias) {
            return $this->conflicto($base, 'fuentes_de_evidencia_contradictorias', []);
        }

        if ($contexto === null) {
            return $base;
        }

        return new AnalisisPedidoHistorico(
            $base->orderId, $base->incrementId, $base->cartId, $base->estadoPedido,
            ResultadoReconciliacion::RECONCILIABLE,
            'evidencia_persistida_inequivoca',
            sprintf(
                'registrar atribucion desde la fuente "%s": propiedad=%s, canal=%s, mecanismo=%s',
                $fuente, $contexto->propiedad->value, $contexto->canal->value, $contexto->mecanismo->value,
            ),
            $contexto, $fuente, null, $base->hechos,
        );
    }

    /**
     * Ejecuta lo que analizar() anuncio. Idempotente: no escribe nada que ya exista y
     * reutiliza las garantias de RegistrarAtribucionService (equivalente devuelve,
     * incompatible conflicta). Cada pedido es independiente: RegistrarAtribucionService
     * ya abre su propia transaccion, asi que no se anida otra ni se revierte un lote.
     */
    public function aplicar(AnalisisPedidoHistorico $analisis): AnalisisPedidoHistorico
    {
        try {
            return match ($analisis->resultado) {
                ResultadoReconciliacion::RECONCILIABLE => $this->aplicarReconciliable($analisis),
                ResultadoReconciliacion::SIN_EVIDENCIA => $this->aplicarSinEvidencia($analisis),
                ResultadoReconciliacion::CONFLICTO => $this->registrarIncidencia($analisis),
                default => $analisis,
            };
        } catch (AtribucionEnConflictoException $e) {
            // La atribucion aparecio (p. ej. la creo el listener) entre el analisis y la escritura.
            return $this->registrarIncidencia(
                $this->conflicto($analisis, 'atribucion_existente_distinta', $e->camposEnConflicto, $e->atribucionId)
            );
        }
    }

    protected function aplicarReconciliable(AnalisisPedidoHistorico $analisis): AnalisisPedidoHistorico
    {
        $this->registrar->registrar(
            $analisis->orderId,
            $analisis->contexto,
            'Reconciliacion historica desde la fuente '.$analisis->fuente,
        );

        return $analisis->con(ResultadoReconciliacion::RECONCILIADO, $analisis->codigo, 'atribucion registrada');
    }

    protected function aplicarSinEvidencia(AnalisisPedidoHistorico $analisis): AnalisisPedidoHistorico
    {
        $this->registrar->registrarSinEvidencia($analisis->orderId, $this->resolver, self::NOTA_SIN_EVIDENCIA);

        return $analisis->con(ResultadoReconciliacion::SIN_EVIDENCIA, $analisis->codigo, 'registrada sin_asesor / desconocido');
    }

    /**
     * Incidencia de conflicto SOLO si no hay ya una abierta para ese pedido: repetir el
     * comando no aumenta attempt_count ni crea filas.
     */
    protected function registrarIncidencia(AnalisisPedidoHistorico $analisis): AnalisisPedidoHistorico
    {
        $existente = $this->incidencias->buscar($analisis->orderId, CodigoIncidencia::ATRIBUCION_CONFLICTO);

        if ($existente === null || $existente->estado !== EstadoIncidencia::ABIERTA) {
            $this->incidencias->registrar(
                $analisis->orderId,
                CodigoIncidencia::ATRIBUCION_CONFLICTO,
                $analisis->atribucionId,
                $analisis->camposConflicto,
            );

            return $analisis->con(ResultadoReconciliacion::CONFLICTO, $analisis->codigo, 'incidencia atribucion_conflicto abierta');
        }

        return $analisis->con(ResultadoReconciliacion::CONFLICTO, $analisis->codigo, 'incidencia ya abierta; sin cambios');
    }

    protected function analizarConAtribucion(object $pedido, AnalisisPedidoHistorico $base): AnalisisPedidoHistorico
    {
        if ($pedido->atribucion_estado !== EstadoAtribucion::FALLO_TECNICO->value) {
            return $base->con(ResultadoReconciliacion::YA_ATRIBUIDO, 'atribucion_existente', 'ninguna (ya atribuido)');
        }

        // fallo_tecnico: nunca se sobrescribe. Corregirlo es una operacion de dominio
        // que la 02B1B no define; se informa y queda para una decision humana.
        [$contexto] = $this->evidencia($pedido);

        if ($contexto !== null) {
            return $this->conflicto($base, 'fallo_tecnico_con_evidencia_distinta', ['attribution_status'], $base->atribucionId);
        }

        return $base->con(ResultadoReconciliacion::FALLO_TECNICO, 'fallo_tecnico_existente', 'ninguna (requiere decision humana)');
    }

    /**
     * @param  list<string>  $campos
     */
    protected function conflicto(AnalisisPedidoHistorico $base, string $codigo, array $campos, ?int $atribucionId = null): AnalisisPedidoHistorico
    {
        return new AnalisisPedidoHistorico(
            $base->orderId, $base->incrementId, $base->cartId, $base->estadoPedido,
            ResultadoReconciliacion::CONFLICTO, $codigo,
            'abrir incidencia atribucion_conflicto (si no hay una abierta); la atribucion existente no se toca',
            $base->contexto, $base->fuente, $atribucionId ?? $base->atribucionId, $base->hechos, $campos,
        );
    }

    /**
     * @return array{0: ?ContextoComercial, 1: ?string, 2: bool} contexto, nombre de la fuente, contradiccion
     */
    protected function evidencia(object $pedido): array
    {
        $encontrados = [];

        foreach ($this->fuentes as $fuente) {
            $contexto = $fuente->contextoPara($pedido);

            if ($contexto === null) {
                continue;
            }

            if ($contexto->esFalloTecnico()) {
                throw new LogicException('Una fuente de evidencia no puede devolver un fallo tecnico.');
            }

            $encontrados[] = [$contexto, $fuente->nombre()];
        }

        if ($encontrados === []) {
            return [null, null, false];
        }

        $distintos = collect($encontrados)
            ->map(fn (array $par) => json_encode($par[0]->valoresComparables()))
            ->unique();

        if ($distintos->count() > 1) {
            return [null, null, true];
        }

        return [$encontrados[0][0], $encontrados[0][1], false];
    }

    /**
     * Hechos persistidos del pedido para el reporte. Ninguno es dato personal y
     * NINGUNO se usa para decidir: describen, no prueban.
     *
     * @return array<string, mixed>
     */
    protected function hechos(object $pedido): array
    {
        return [
            'cart_id_presente' => ! empty($pedido->cart_id),
            'carrito_existe' => isset($pedido->cart_existente) && $pedido->cart_existente !== null,
            'cliente_invitado' => (bool) ($pedido->is_guest ?? false),
            'tiene_cliente' => ! empty($pedido->customer_id ?? null),
            'canal_bagisto_id' => isset($pedido->channel_id) ? (int) $pedido->channel_id : null,
            'creado_en' => $pedido->created_at ?? null,
        ];
    }

    protected function error(object $pedido, Throwable $e, string $codigo): AnalisisPedidoHistorico
    {
        // Solo la clase de la excepcion: su mensaje puede arrastrar SQL con datos.
        Log::warning('ventas_asesor.reconciliacion.'.$codigo, [
            'order_id' => (int) $pedido->id,
            'excepcion' => $e::class,
        ]);

        return new AnalisisPedidoHistorico(
            (int) $pedido->id, $pedido->increment_id ?? null,
            isset($pedido->cart_id) ? (int) $pedido->cart_id : null, $pedido->status ?? null,
            ResultadoReconciliacion::ERROR, $codigo.':'.class_basename($e), 'ninguna (error; el pedido queda como estaba)',
            atribucionId: isset($pedido->atribucion_id) ? (int) $pedido->atribucion_id : null,
        );
    }

    /**
     * Proyeccion de SOLO LECTURA: columnas justas, sin hidratar modelos y sin datos
     * personales del cliente.
     */
    protected function consulta(?int $orderId)
    {
        $consulta = DB::table('orders as o')
            ->leftJoin('bylopez_va_order_attributions as a', 'a.order_id', '=', 'o.id')
            ->leftJoin('cart as c', 'c.id', '=', 'o.cart_id')
            ->select(
                'o.id', 'o.increment_id', 'o.cart_id', 'o.status', 'o.customer_id',
                'o.is_guest', 'o.channel_id', 'o.created_at',
                'a.id as atribucion_id', 'a.attribution_status as atribucion_estado',
                'c.id as cart_existente',
            );

        return $orderId !== null ? $consulta->where('o.id', $orderId) : $consulta;
    }
}
