<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Enums\CodigoIncidencia;
use ByLopez\VentasAsesor\Exceptions\AtribucionEnConflictoException;
use ByLopez\VentasAsesor\Exceptions\AtribucionInvalidaException;
use ByLopez\VentasAsesor\Exceptions\ContextoDePeticionInvalidoException;
use ByLopez\VentasAsesor\Exceptions\HistorialIncoherenteException;
use ByLopez\VentasAsesor\Services\IncidenciasAtribucionService;
use ByLopez\VentasAsesor\Services\RegistrarAtribucionService;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use ByLopez\VentasAsesor\Support\ClasificadorOrigenPedido;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

/**
 * Enganche real entre el motor de pedidos de Bagisto y la atribucion comercial.
 *
 * Es SINCRONO y corre dentro de la transaccion abierta por
 * OrderRepository::createOrderIfNotThenRetry() [D-§4]. Eso tiene dos
 * consecuencias que mandan sobre todo el diseño de esta clase:
 *
 * 1. Si lanza, el repositorio hace rollback y REINTENTA la creacion completa,
 *    generando un pedido con id distinto; agotados los intentos, la venta se
 *    pierde y el cliente ve un error. Medido en [D-§13]. Por eso este listener
 *    NUNCA relanza sus propios errores.
 * 2. Encolarlo no serviria: el job correria fuera de la transaccion, cuando la
 *    peticion HTTP —y con ella el contexto comercial— ya no existe.
 *
 * Limite honesto de `catch (Throwable)`: atrapar la excepcion evita que ESTE
 * listener tumbe la venta, pero no resucita una transaccion que la base de datos
 * ya marco como invalida. Si la conexion esta rota, lo unico que queda es el log
 * [D-§16.5].
 *
 * Lo que este listener no hace, nunca: modificar el pedido o el carrito, borrar
 * el contexto comercial de la peticion (eso lo retira quien lo registro, en su
 * `finally`), ni escribir la atribucion por su cuenta. El unico motor autorizado
 * es RegistrarAtribucionService: nada de updateOrCreate, firstOrCreate ni INSERT
 * directo, que convertirian un conflicto en una sobrescritura silenciosa.
 */
class AtribuirPedidoCreado
{
    public const EVENTO = 'checkout.order.save.after';

    public function __construct(
        protected ClasificadorOrigenPedido $clasificador,
        protected ResolverAtribucionService $resolver,
        protected RegistrarAtribucionService $registrar,
        protected IncidenciasAtribucionService $incidencias,
    ) {}

    public function handle(mixed $pedido): void
    {
        $orderId = $this->enteroPositivo($pedido->id ?? null);

        if ($orderId === null) {
            /*
             * Sin order_id no hay nada que atribuir ni donde anclar una incidencia:
             * la tabla exige el pedido. Solo queda dejar rastro.
             */
            $this->registroSeguro(null, null, 'listener.pedido_sin_id');

            return;
        }

        $cartId = $this->enteroPositivo($pedido->cart_id ?? null);

        try {
            $contexto = $this->clasificador->clasificar($cartId);

            if ($contexto->esFalloTecnico()) {
                // Camino autorizado: deja el pedido marcado y registra su propio log.
                $this->registrar->registrarFalloTecnico(
                    $orderId,
                    $this->resolver,
                    (string) $contexto->codigoTecnico,
                );

                return;
            }

            $this->registrar->registrar($orderId, $contexto);
        } catch (AtribucionEnConflictoException $e) {
            /*
             * El pedido ya tenia una atribucion distinta. Se respeta la existente: el
             * modulo no decide por su cuenta cual de las dos verdades es la buena.
             * Tampoco es un fallo_tecnico —la atribucion es valida, solo discutida—,
             * asi que la unica salida es dejarlo visible para un humano.
             */
            $this->incidenciaSegura(
                $orderId,
                $cartId,
                CodigoIncidencia::ATRIBUCION_CONFLICTO,
                $e,
                $e->atribucionId,
                $e->camposEnConflicto,
            );
        } catch (HistorialIncoherenteException $e) {
            $this->incidenciaSegura($orderId, $cartId, CodigoIncidencia::HISTORIAL_INCOHERENTE, $e);
        } catch (AtribucionInvalidaException|ContextoDePeticionInvalidoException $e) {
            /*
             * No se pudo construir un contexto valido. Se intenta dejar el pedido
             * marcado como fallo tecnico y, ademas, la incidencia para revisarlo.
             * Ambas escrituras van protegidas: si la conexion ya no sirve, no se
             * insiste.
             */
            $atribucionId = $this->falloTecnicoSeguro($orderId, $cartId, 'listener.contexto_invalido');

            $this->incidenciaSegura($orderId, $cartId, CodigoIncidencia::CONTEXTO_INVALIDO, $e, $atribucionId);
        } catch (QueryException|PDOException $e) {
            /*
             * Error de base de datos o de conexion. NO se intenta escribir el fallo
             * tecnico ni la incidencia: irian por la misma conexion que acaba de
             * fallar y, dentro de una transaccion abortada, solo producirian una
             * segunda excepcion. El log es lo unico que queda.
             */
            $this->registroSeguro($orderId, $cartId, 'listener.error_base_datos', $e);
        } catch (Throwable $e) {
            $this->incidenciaSegura($orderId, $cartId, CodigoIncidencia::LISTENER_ERROR, $e);
        }
    }

    /**
     * Escribe la incidencia sin dejar que su propio fallo tumbe la venta. Si no se
     * puede escribir, se degrada a log y se termina: nunca se reintenta manejar el
     * error del manejo del error.
     *
     * @param  list<string>  $campos
     */
    protected function incidenciaSegura(
        int $orderId,
        ?int $cartId,
        CodigoIncidencia $codigo,
        Throwable $original,
        ?int $atribucionId = null,
        array $campos = [],
    ): void {
        try {
            $incidencia = $this->incidencias->registrar($orderId, $codigo, $atribucionId, $campos);

            $this->registroSeguro($orderId, $cartId, 'listener.'.$codigo->value, $original, [
                'attribution_id' => $atribucionId,
                'incident_id' => $incidencia->id,
            ]);
        } catch (Throwable $fallo) {
            $this->registroSeguro($orderId, $cartId, 'listener.incidencia_no_registrada', $fallo, [
                'codigo_incidencia' => $codigo->value,
            ]);
        }
    }

    /** @return int|null Id de la atribucion si se pudo registrar el fallo tecnico. */
    protected function falloTecnicoSeguro(int $orderId, ?int $cartId, string $codigoTecnico): ?int
    {
        try {
            return $this->registrar->registrarFalloTecnico($orderId, $this->resolver, $codigoTecnico)->id;
        } catch (Throwable $fallo) {
            $this->registroSeguro($orderId, $cartId, 'listener.fallo_tecnico_no_registrado', $fallo);

            return null;
        }
    }

    /**
     * Log con campos ACOTADOS: identificadores, codigo estable y clase de la
     * excepcion. Nunca el mensaje ni la traza, que arrastran consultas SQL con
     * valores, correos o telefonos hacia un archivo que suele compartirse entero.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function registroSeguro(
        ?int $orderId,
        ?int $cartId,
        string $codigo,
        ?Throwable $excepcion = null,
        array $extra = [],
    ): void {
        try {
            Log::warning('ventas_asesor.'.$codigo, array_filter([
                'order_id' => $orderId,
                'cart_id' => $cartId,
                'codigo' => $codigo,
                'excepcion' => $excepcion === null ? null : $excepcion::class,
                ...$extra,
            ], fn ($valor) => $valor !== null));
        } catch (Throwable) {
            // Ni el log esta disponible. Aqui se acaba lo que se puede hacer sin
            // arriesgar la venta: no hay a quien avisar por otro medio.
        }
    }

    protected function enteroPositivo(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor > 0 ? $valor : null;
        }

        if (is_string($valor) && ctype_digit($valor)) {
            return (int) $valor > 0 ? (int) $valor : null;
        }

        return null;
    }
}
