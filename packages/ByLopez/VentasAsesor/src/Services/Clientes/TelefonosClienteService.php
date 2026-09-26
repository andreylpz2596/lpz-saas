<?php

namespace ByLopez\VentasAsesor\Services\Clientes;

use ByLopez\VentasAsesor\Contracts\NormalizadorTelefono;
use ByLopez\VentasAsesor\DataObjects\TelefonoNormalizado;
use ByLopez\VentasAsesor\Exceptions\OperacionTelefonoInvalidaException as Invalida;
use ByLopez\VentasAsesor\Exceptions\TelefonoPerteneceAOtroClienteException;
use ByLopez\VentasAsesor\Models\TelefonoCliente;
use ByLopez\VentasAsesor\Repositories\TelefonoClienteRepository;
use ByLopez\VentasAsesor\Support\Telefono\LocalizadorTitularTelefono;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\CustomerProxy;

/**
 * Unica puerta de escritura de telefonos de un cliente comercial (RT-04B1b).
 *
 * Invariantes que garantiza, siempre dentro de una transaccion y con el Customer
 * bloqueado (lockForUpdate) para serializar operaciones concurrentes:
 *   - customers.phone == E.164 de la fila principal (nunca divergen por este servicio);
 *   - un numero normalizado pertenece a un solo Customer (indice UNIQUE + fichas
 *     historicas sin indexar, ver LocalizadorTitularTelefono);
 *   - un solo principal por Customer (UNIQUE customer_id, principal);
 *   - el principal no se elimina: se reemplaza.
 *
 * Nunca reasigna un numero de otro Customer: lanza TelefonoPerteneceAOtroClienteException.
 */
class TelefonosClienteService
{
    public function __construct(
        protected NormalizadorTelefono $normalizador,
        protected TelefonoClienteRepository $telefonos,
        protected LocalizadorTitularTelefono $localizador,
    ) {}

    /**
     * Registra o cambia el principal. Si el numero ya es secundario del cliente, lo
     * promueve. El principal anterior (o el telefono historico de la ficha sin
     * indexar) queda como secundario si $conservarAnterior; si no, se retira.
     */
    public function establecerPrincipal(int $customerId, string $telefono, bool $conservarAnterior = true): TelefonoCliente
    {
        return DB::transaction(function () use ($customerId, $telefono, $conservarAnterior) {
            $cliente = $this->bloquearCliente($customerId);
            $nuevo = $this->normalizarObligatorio($telefono);
            $this->exigirQueNoSeaDeOtro($nuevo, $customerId);

            $actual = $this->telefonos->principalDe($customerId);
            $fila = $this->filaDelCliente($customerId, $nuevo->normalizado);

            if ($fila && $fila->esPrincipal()) {
                $this->escribirFicha($cliente, $nuevo->normalizado);

                return $fila;
            }

            if ($actual) {
                // Liberar el hueco del principal antes de ocupar otro (UNIQUE customer_id, principal).
                $conservarAnterior ? $this->telefonos->guardar($actual->fill(['principal' => false])) : $actual->delete();
            } elseif ($conservarAnterior) {
                $this->conservarFichaHistorica($cliente, $nuevo->normalizado);
            }

            $principal = $this->telefonos->guardar(($fila ?? new TelefonoCliente([
                'customer_id' => $customerId,
                'telefono_normalizado' => $nuevo->normalizado,
                'telefono_original' => $nuevo->original,
            ]))->fill(['principal' => true]));

            $this->escribirFicha($cliente, $nuevo->normalizado);

            return $principal;
        });
    }

    /** Solo promueve un numero que YA es secundario del cliente. */
    public function promoverAPrincipal(int $customerId, string $telefono): TelefonoCliente
    {
        return DB::transaction(function () use ($customerId, $telefono) {
            $this->bloquearCliente($customerId);
            $normalizado = $this->normalizarObligatorio($telefono);

            if (! $this->filaDelCliente($customerId, $normalizado->normalizado)) {
                throw new Invalida(Invalida::TELEFONO_NO_ES_DEL_CLIENTE);
            }

            return $this->establecerPrincipal($customerId, $telefono);
        });
    }

    /** Idempotente: si el numero ya es del cliente (principal o secundario) lo devuelve sin cambios. */
    public function agregarSecundario(int $customerId, string $telefono): TelefonoCliente
    {
        return DB::transaction(function () use ($customerId, $telefono) {
            $this->bloquearCliente($customerId);
            $nuevo = $this->normalizarObligatorio($telefono);
            $this->exigirQueNoSeaDeOtro($nuevo, $customerId);

            return $this->filaDelCliente($customerId, $nuevo->normalizado)
                ?? $this->telefonos->guardar(new TelefonoCliente([
                    'customer_id' => $customerId,
                    'telefono_normalizado' => $nuevo->normalizado,
                    'telefono_original' => $nuevo->original,
                    'principal' => false,
                ]));
        });
    }

    public function eliminarSecundario(int $customerId, string $telefono): void
    {
        DB::transaction(function () use ($customerId, $telefono) {
            $this->bloquearCliente($customerId);
            $fila = $this->filaDelCliente($customerId, $this->normalizarObligatorio($telefono)->normalizado);

            if (! $fila) {
                throw new Invalida(Invalida::TELEFONO_NO_ES_DEL_CLIENTE);
            }

            if ($fila->esPrincipal()) {
                throw new Invalida(Invalida::PRINCIPAL_NO_ELIMINABLE);
            }

            $fila->delete();
        });
    }

    protected function bloquearCliente(int $customerId): Model
    {
        $cliente = CustomerProxy::modelClass()::query()->lockForUpdate()->find($customerId);

        if (! $cliente) {
            throw new Invalida(Invalida::CLIENTE_INEXISTENTE);
        }

        return $cliente;
    }

    protected function normalizarObligatorio(string $telefono): TelefonoNormalizado
    {
        $normalizado = $this->normalizador->normalizar($telefono);

        if (! $normalizado->esBuscable()) {
            throw new Invalida(Invalida::TELEFONO_NO_NORMALIZABLE);
        }

        return $normalizado;
    }

    protected function exigirQueNoSeaDeOtro(TelefonoNormalizado $telefono, int $customerId): void
    {
        $titular = $this->localizador->titular($telefono);

        if ($titular && $titular->customerId !== $customerId) {
            throw new TelefonoPerteneceAOtroClienteException($titular->customerId, $customerId);
        }
    }

    protected function filaDelCliente(int $customerId, string $normalizado): ?TelefonoCliente
    {
        $fila = $this->telefonos->buscarPorNormalizado($normalizado);

        return $fila && $fila->customer_id === $customerId ? $fila : null;
    }

    /**
     * Cliente historico: su customers.phone aun no esta indexado. Al cambiar el
     * principal se conserva como secundario si es normalizable y no es de otro; si no,
     * se deja de lado (la ficha pasa a tener el nuevo principal).
     */
    protected function conservarFichaHistorica(Model $cliente, string $nuevoNormalizado): void
    {
        $historico = $this->normalizador->normalizar($cliente->phone);

        if (! $historico->esBuscable() || $historico->normalizado === $nuevoNormalizado) {
            return;
        }

        $titular = $this->telefonos->buscarPorNormalizado($historico->normalizado);

        if ($titular) {
            return;   // ya indexado (de este u otro cliente): no se toca
        }

        $this->telefonos->guardar(new TelefonoCliente([
            'customer_id' => $cliente->id,
            'telefono_normalizado' => $historico->normalizado,
            'telefono_original' => (string) $cliente->phone,
            'principal' => false,
        ]));
    }

    /** customers.phone = E.164 del principal. El UNIQUE del core es la ultima barrera. */
    protected function escribirFicha(Model $cliente, string $e164): void
    {
        if ($cliente->phone === $e164) {
            return;
        }

        try {
            $cliente->forceFill(['phone' => $e164])->save();
        } catch (UniqueConstraintViolationException) {
            $otro = CustomerProxy::modelClass()::query()->where('phone', $e164)->value('id');

            throw new TelefonoPerteneceAOtroClienteException((int) $otro, (int) $cliente->id);
        }
    }
}
