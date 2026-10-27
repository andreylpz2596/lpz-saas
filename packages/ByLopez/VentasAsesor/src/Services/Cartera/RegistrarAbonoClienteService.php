<?php

namespace ByLopez\VentasAsesor\Services\Cartera;

use ByLopez\VentasAsesor\DataObjects\ResultadoAbonoCliente;
use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\RegistroPagoInvalidoException as Rechazo;
use ByLopez\VentasAsesor\Models\AplicacionPago;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Models\PagoEvento;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Abono general multipedido de UN cliente (PAG-D, D-59 / Doc. 05 §42.5 y §42.10): un solo Pago `sin_revisar` con N
 * aplicaciones, una por pedido. Mismo contrato que PAG-C (atomico, idempotente, auditable, sin Transactions, sin
 * tocar Invoice ni `orders.status`, sin saldo a favor) y MISMAS reglas de forma, medio de cobro, referencia, fecha,
 * idempotencia y rechazos: reutiliza (hereda) la normalizacion y la resolucion de repeticiones de
 * RegistrarPagoService sin modificarlo.
 *
 * Distribucion: llega EXPLICITA (la propuesta FIFO la calcula la interfaz o DistribucionFifo y el usuario puede
 * editarla). No se acepta una distribucion implicita: el calculo FIFO depende del saldo de ese momento y haria que
 * un reintento con la misma clave pareciera una solicitud distinta. El backend revalida TODO bajo bloqueo:
 * suma = monto, cada linea > 0 y <= saldo real del pedido, pedidos del mismo cliente, en Cartera, sin revision
 * pendiente y dentro del alcance del actor.
 *
 * Autorizacion en el servicio: `ventas_asesor.cartera.abono_general` MAS alcance de cartera sobre CADA pedido
 * (`view_own` = solo pedidos atribuidos al actor; `view_all` = todos). Poder ver al cliente no basta: un pedido
 * fuera del alcance rechaza todo el abono sin revelar su existencia.
 *
 * Bloqueos (Doc. 05 §42.5): pago (INSERT que reserva la clave) → pedidos en orden de id ascendente. Una sola
 * transaccion; cualquier fallo es rollback total (nunca un Pago parcialmente aplicado).
 */
class RegistrarAbonoClienteService extends RegistrarPagoService
{
    private const REINTENTOS = 3;

    /**
     * @param  array{monto: int|float|string, medio_cobro: string, fecha_pago: string, referencia?: ?string, observacion?: ?string}  $datos
     * @param  list<array{pedido_id: int|string, monto: int|float|string}>  $distribucion
     *
     * @throws Rechazo
     */
    public function registrarAbono(
        AdminContract $actor,
        int $customerId,
        array $datos,
        array $distribucion,
        string $idempotencyKey,
        string $mecanismo = MecanismoCreacion::VENTAS_ASESOR->value,
    ): ResultadoAbonoCliente {
        $this->exigirPermiso($actor);

        // Falla cerrado ANTES de tocar ninguna tabla de pagos.
        if (! $this->fechaCorte->activa()) {
            throw new Rechazo(Rechazo::CARTERA_INACTIVA, 'Cartera todavia no esta activa (no hay fecha de corte). No se pueden registrar pagos.');
        }

        $clave = trim($idempotencyKey);

        if (! preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $clave)) {
            throw new Rechazo(Rechazo::CLAVE_IDEMPOTENCIA_INVALIDA, 'La clave de idempotencia debe tener entre 16 y 100 caracteres seguros.');
        }

        if (! DB::table('customers')->where('id', $customerId)->exists()) {
            throw new Rechazo(Rechazo::CLIENTE_INEXISTENTE, 'El cliente no existe o no esta disponible para ti.');
        }

        $solicitud = $this->normalizar($datos);
        $lineas = $this->normalizarDistribucion($distribucion, $solicitud['monto']);

        $this->exigirAlcanceSobre($actor, $customerId, array_keys($lineas));

        $huella = $this->huellaAbono($customerId, $solicitud, $lineas);

        if ($existente = $this->resolverRepeticion($mecanismo, $clave, $huella)) {
            return $this->resultadoDeAbono($existente, true);
        }

        try {
            return DB::transaction(
                fn () => $this->abonarBajoBloqueo($actor, $customerId, $solicitud, $lineas, $clave, $huella, $mecanismo),
                self::REINTENTOS,
            );
        } catch (QueryException $e) {
            // Carrera: otra peticion con la misma clave confirmo entre la lectura y el INSERT.
            if ($this->esClaveDuplicada($e) && ($existente = $this->resolverRepeticion($mecanismo, $clave, $huella))) {
                return $this->resultadoDeAbono($existente, true);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $s  solicitud normalizada
     * @param  array<int, string>  $lineas  order_id => monto, por id ascendente
     */
    protected function abonarBajoBloqueo(
        AdminContract $actor,
        int $customerId,
        array $s,
        array $lineas,
        string $clave,
        string $huella,
        string $mecanismo,
    ): ResultadoAbonoCliente {
        $ids = array_keys($lineas);

        // 1) El pago nace primero: reserva la clave de idempotencia.
        $pago = Pago::query()->create([
            'customer_id' => $customerId,
            'monto' => $s['monto'],
            'medio_cobro' => $s['medio_cobro'],
            'plataforma' => $s['plataforma'],
            'fecha_pago' => $s['fecha_pago'],
            'referencia' => $s['referencia'],
            'referencia_normalizada' => $s['referencia_normalizada'],
            'observacion' => $s['observacion'],
            'estado' => EstadoPago::SIN_REVISAR->value,
            'mecanismo' => $mecanismo,
            'idempotency_key' => $clave,
            'huella_solicitud' => $huella,
            'registrado_por_admin_id' => (int) $actor->id,
        ]);

        // 2) Bloqueo de TODOS los pedidos en orden determinista (id ascendente).
        $bloqueados = DB::table('orders')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'customer_id', 'increment_id']);

        if ($bloqueados->count() !== count($ids) || $bloqueados->contains(fn ($o) => (int) ($o->customer_id ?? 0) !== $customerId)) {
            throw new Rechazo(Rechazo::PEDIDO_INEXISTENTE, 'Alguno de los pedidos no existe o no esta disponible para ti.');
        }

        // 3) Validar con el saldo REAL, recalculado ahora y bajo bloqueo.
        $antes = $this->consulta->saldosDePedidos($ids);

        foreach ($lineas as $orderId => $monto) {
            $saldo = $antes[$orderId] ?? null;

            $this->validarPedido($saldo, $customerId);

            if ((float) $monto > $saldo->saldo + 0.000001) {
                throw new Rechazo(
                    Rechazo::MONTO_EXCEDE_SALDO,
                    'Una de las aplicaciones supera el saldo pendiente de su pedido. No se crean saldos a favor.',
                    ['pedido_id' => $orderId, 'pedido' => $saldo->incrementId, 'saldo' => $saldo->saldo, 'monto' => (float) $monto],
                );
            }
        }

        // 4) N aplicaciones y el evento. Un fallo cualquiera deshace TODO (rollback de la transaccion).
        $posicion = 1;

        foreach ($lineas as $orderId => $monto) {
            AplicacionPago::query()->create([
                'pago_id' => $pago->id,
                'order_id' => $orderId,
                'monto' => $monto,
                'posicion' => $posicion++,
            ]);
        }

        $despues = $this->consulta->saldosDePedidos($ids);

        PagoEvento::query()->create([
            'pago_id' => $pago->id,
            'tipo' => TipoEventoPago::REGISTRADO->value,
            'actor_admin_id' => (int) $actor->id,
            'mecanismo' => $mecanismo,
            'motivo' => null,
            'datos' => [
                'customer_id' => $customerId,
                'monto' => (float) $s['monto'],
                'medio_cobro' => $s['medio_cobro'],
                'plataforma' => $s['plataforma'],
                'fecha_pago' => $s['fecha_pago'],
                'distribucion' => collect($lineas)->map(fn ($monto, $orderId) => [
                    'order_id' => $orderId,
                    'monto' => (float) $monto,
                    'saldo_antes' => $antes[$orderId]->saldo,
                    'saldo_despues' => $despues[$orderId]->saldo,
                ])->values()->all(),
                'huella_solicitud' => $huella,
            ],
        ]);

        return $this->resultadoDeAbono($pago, false);
    }

    /**
     * Forma de la distribucion: pares pedido/monto unicos, cada monto > 0 con maximo 2 decimales y la SUMA exacta
     * igual al monto del pago. Aritmetica en centavos enteros.
     *
     * @param  list<array{pedido_id: int|string, monto: int|float|string}>  $distribucion
     * @return array<int, string> order_id => monto con 2 decimales, por id ascendente
     */
    protected function normalizarDistribucion(array $distribucion, string $montoTotal): array
    {
        if ($distribucion === []) {
            throw new Rechazo(Rechazo::DISTRIBUCION_INVALIDA, 'Indica a que pedidos se aplica el abono.');
        }

        $lineas = [];
        $centavos = 0;

        foreach ($distribucion as $linea) {
            $id = filter_var($linea['pedido_id'] ?? null, FILTER_VALIDATE_INT);
            $texto = is_string($linea['monto'] ?? null)
                ? trim($linea['monto'])
                : (is_int($linea['monto'] ?? null) || is_float($linea['monto'] ?? null) ? rtrim(rtrim(number_format((float) $linea['monto'], 4, '.', ''), '0'), '.') : '');

            if ($id === false || $id < 1 || isset($lineas[$id]) || ! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $texto) || (float) $texto <= 0) {
                throw new Rechazo(Rechazo::DISTRIBUCION_INVALIDA, 'La distribucion tiene un pedido repetido o un monto invalido (cada aplicacion debe ser mayor que cero, con maximo dos decimales).');
            }

            $lineas[$id] = number_format((float) $texto, 2, '.', '');
            $centavos += (int) round(((float) $texto) * 100);
        }

        $esperado = (int) round(((float) $montoTotal) * 100);

        if ($centavos !== $esperado) {
            throw new Rechazo(
                Rechazo::DISTRIBUCION_INVALIDA,
                'La suma de las aplicaciones debe ser igual al monto del abono.',
                ['suma' => $centavos / 100, 'monto' => $esperado / 100],
            );
        }

        ksort($lineas);

        return $lineas;
    }

    /**
     * Cada pedido debe existir, estar en el alcance de cartera del actor y ser del cliente. Un pedido fuera de
     * alcance se responde igual que uno inexistente (no se revela).
     *
     * @param  list<int>  $ids
     */
    protected function exigirAlcanceSobre(AdminContract $actor, int $customerId, array $ids): void
    {
        $pedidos = DB::table('orders')->whereIn('id', $ids)->pluck('customer_id', 'id');
        $asesores = DB::table('bylopez_va_order_attributions')->whereIn('order_id', $ids)->pluck('advisor_id', 'order_id');

        foreach ($ids as $id) {
            $asesor = $asesores[$id] ?? null;

            if (! $pedidos->has($id) || ! $this->autorizacion->puedeVerCarteraDe($actor, $asesor === null ? null : (int) $asesor)) {
                throw new Rechazo(Rechazo::PEDIDO_INEXISTENTE, 'Alguno de los pedidos no existe o no esta disponible para ti.');
            }

            if ((int) ($pedidos[$id] ?? 0) !== $customerId) {
                throw new Rechazo(Rechazo::PEDIDO_DE_OTRO_CLIENTE, 'Todos los pedidos de un abono deben ser del mismo cliente.');
            }
        }
    }

    /**
     * La huella cubre cliente, medio, fecha, referencia normalizada, monto y la distribucion completa
     * (`order_id:monto`, ordenada por pedido).
     *
     * @param  array<string, mixed>  $s
     * @param  array<int, string>  $lineas
     */
    protected function huellaAbono(int $customerId, array $s, array $lineas): string
    {
        return hash('sha256', json_encode([
            'cliente' => $customerId,
            'medio' => $s['medio_cobro'],
            'fecha' => $s['fecha_pago'],
            'referencia' => $s['referencia_normalizada'],
            'monto' => $s['monto'],
            'distribucion' => collect($lineas)->map(fn ($monto, $id) => $id.':'.$monto)->values()->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    protected function resultadoDeAbono(Pago $pago, bool $duplicado): ResultadoAbonoCliente
    {
        $aplicaciones = DB::table('bylopez_va_aplicaciones_pago as a')
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->where('a.pago_id', $pago->id)->orderBy('a.order_id')
            ->get(['a.order_id', 'o.increment_id', 'a.monto'])
            ->map(fn ($f) => ['order_id' => (int) $f->order_id, 'increment_id' => (string) $f->increment_id, 'monto' => (float) $f->monto])
            ->all();

        return new ResultadoAbonoCliente((int) $pago->id, $pago->estado->value, (float) $pago->monto, $duplicado, $aplicaciones);
    }

    protected function exigirPermiso(AdminContract $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_CARTERA_ABONO_GENERAL)) {
            throw new Rechazo(Rechazo::SIN_PERMISO, 'No tienes permiso para registrar abonos generales.');
        }
    }
}
