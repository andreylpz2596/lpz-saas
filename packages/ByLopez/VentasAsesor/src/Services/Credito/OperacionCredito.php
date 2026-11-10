<?php

namespace ByLopez\VentasAsesor\Services\Credito;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Exceptions\OperacionCreditoInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Models\CreditoClienteMovimiento;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Base de las operaciones financieras de credito (D10 V1): autorizacion en el SERVIDOR, idempotencia, bloqueo de pedidos y
 * validaciones comunes. Reglas que comparten todas las operaciones:
 *
 *  - Permiso especifico (nunca se depende de ocultar botones) Y alcance de cartera «todos»: un asesor con `view_own` no opera credito.
 *  - Clave de idempotencia obligatoria. Misma clave + misma huella → resultado estable (`duplicado`); misma clave + otra huella → conflicto.
 *  - Bloqueo de pedidos `FOR UPDATE` en orden de ID ascendente (el mismo orden que ConfirmarPagoService: sin deadlock cruzado) y
 *    DESPUES de bloquear toda validacion financiera se lee con lectura BLOQUEANTE (`lecturaActual: true`); jamas se reutiliza una
 *    lectura normal previa (en REPEATABLE READ fija la instantanea y permitiria doble consumo; probado en el spike PAG-I-04).
 */
abstract class OperacionCredito
{
    protected const INTENTOS = 3;

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected CarteraConsultaService $cartera,
        protected CreditoClienteService $credito,
        protected FechaCorteCartera $fechaCorte,
    ) {}

    protected function exigir(AdminContract $actor, string $permiso): void
    {
        if (! $this->autorizacion->puedeCredito($actor, $permiso) || $this->autorizacion->alcanceDeCartera($actor) !== AlcanceVisibilidad::TODOS) {
            throw new Rechazo(Rechazo::SIN_PERMISO, 'No tienes permiso para esta operacion de credito.');
        }

        if (! $this->fechaCorte->activa()) {
            throw new Rechazo(Rechazo::CARTERA_INACTIVA, 'Cartera todavia no esta activa (no hay fecha de corte).');
        }
    }

    protected function clave(string $clave): string
    {
        $clave = trim($clave);

        if (! preg_match('/^[A-Za-z0-9._:-]{16,80}$/', $clave)) {
            throw new Rechazo(Rechazo::CLAVE_INVALIDA, 'La clave de idempotencia debe tener entre 16 y 80 caracteres seguros.');
        }

        return $clave;
    }

    protected function monto(mixed $monto): string
    {
        $texto = is_string($monto) ? trim($monto) : (is_int($monto) || is_float($monto) ? rtrim(rtrim(number_format((float) $monto, 4, '.', ''), '0'), '.') : '');

        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $texto) || (float) $texto <= 0) {
            throw new Rechazo(Rechazo::MONTO_INVALIDO, 'El monto debe ser mayor que cero y tener maximo dos decimales.');
        }

        return $texto;
    }

    protected function motivo(?string $motivo, bool $obligatorio): ?string
    {
        $motivo = trim((string) $motivo);

        if ($motivo === '' && $obligatorio) {
            throw new Rechazo(Rechazo::MOTIVO_REQUERIDO, 'El motivo es obligatorio.');
        }

        return $motivo === '' ? null : mb_substr($motivo, 0, 500);
    }

    /** @param  array<string, mixed>  $partes */
    protected function huella(array $partes): string
    {
        ksort($partes);

        return hash('sha256', json_encode($partes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Bloquea los pedidos (id ascendente) y exige que existan y sean de UN mismo cliente.
     *
     * @param  list<int>  $ids
     * @return Collection<int, object>
     */
    protected function bloquearPedidos(array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        $pedidos = DB::table('orders')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'customer_id', 'increment_id'])->keyBy('id');

        if ($pedidos->count() !== count($ids)) {
            throw new Rechazo(Rechazo::PEDIDO_INEXISTENTE, 'El pedido no existe.');
        }

        if ($pedidos->contains(fn ($o) => $o->customer_id === null) || $pedidos->pluck('customer_id')->unique()->count() > 1) {
            throw new Rechazo(Rechazo::CLIENTE_DISTINTO, 'El credito solo se puede usar entre pedidos del mismo cliente.');
        }

        return $pedidos;
    }

    /**
     * Movimiento existente de esta clave: igual huella → estable; otra huella → conflicto. Null si la clave es nueva.
     */
    protected function repeticion(string $claveInterna, string $huella, bool $bloqueante = false): ?object
    {
        $consulta = DB::table('bylopez_va_creditos_cliente_movimientos')->where('idempotency_key', $claveInterna);
        $fila = ($bloqueante ? $consulta->sharedLock() : $consulta)->first();

        if ($fila === null) {
            return null;
        }

        if (! hash_equals((string) $fila->huella_solicitud, $huella)) {
            throw new Rechazo(Rechazo::IDEMPOTENCIA_CONFLICTO, 'Esa clave de idempotencia ya se uso con una solicitud distinta.');
        }

        return $fila;
    }

    protected function registrarMovimiento(array $datos): CreditoClienteMovimiento
    {
        return CreditoClienteMovimiento::query()->create($datos);
    }

    protected function nombre(AdminContract $actor): string
    {
        return mb_substr((string) $actor->name, 0, 191);
    }
}
