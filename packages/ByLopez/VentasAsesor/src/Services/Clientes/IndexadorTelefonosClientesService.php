<?php

namespace ByLopez\VentasAsesor\Services\Clientes;

use ByLopez\VentasAsesor\Contracts\NormalizadorTelefono;
use ByLopez\VentasAsesor\DataObjects\AnalisisTelefonoCliente;
use ByLopez\VentasAsesor\DataObjects\TelefonoNormalizado;
use ByLopez\VentasAsesor\Enums\EstadoNormalizacionTelefono;
use ByLopez\VentasAsesor\Enums\ResultadoIndexacionTelefono as Resultado;
use ByLopez\VentasAsesor\Exceptions\TelefonoPerteneceAOtroClienteException;
use ByLopez\VentasAsesor\Models\TelefonoCliente;
use ByLopez\VentasAsesor\Repositories\TelefonoClienteRepository;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Customer\Models\CustomerProxy;

/**
 * Indexa `customers.phone` como telefono PRINCIPAL en bylopez_va_customer_phones
 * (RT-04B1a).
 *
 * `customers.phone` es la fuente: se LEE y nunca se modifica. La fila principal es su
 * reflejo normalizado, asi que re-derivarla cuando diverge (porque el panel, el perfil
 * de la tienda o el importador cambiaron la ficha) es correcto. Lo que no se resuelve
 * aqui se informa: numeros no normalizables, un mismo numero en varias fichas
 * (DUPLICADO: no se elige titular) y numeros que ya son de otro cliente (CONFLICTO).
 *
 * Con $persistir = false no se escribe nada. Cada Customer se escribe en su propia
 * transaccion; un error en uno se reporta como ERROR y el recorrido continua.
 */
class IndexadorTelefonosClientesService
{
    private const LOTE = 200;

    public function __construct(
        protected NormalizadorTelefono $normalizador,
        protected TelefonoClienteRepository $telefonos,
    ) {}

    /** @return Generator<int, AnalisisTelefonoCliente> */
    public function ejecutar(bool $persistir, ?int $customerId = null, ?int $limite = null): Generator
    {
        $titularesPorNumero = $this->fichasPorNumero();
        $analizados = 0;

        $clientes = CustomerProxy::modelClass()::query()
            ->select(['id', 'phone'])
            ->whereNotNull('phone')
            ->when($customerId !== null, fn ($q) => $q->whereKey($customerId));

        foreach ($clientes->lazyById(self::LOTE) as $cliente) {
            if ($limite !== null && $analizados >= $limite) {
                break;
            }

            $analizados++;

            yield $this->protegido((int) $cliente->id, (string) $cliente->phone,
                fn () => $this->analizar((int) $cliente->id, (string) $cliente->phone, $titularesPorNumero, $persistir));
        }

        foreach ($this->principalesSinFicha($customerId) as $huerfana) {
            yield $this->protegido($huerfana->customer_id, $huerfana->telefono_original,
                fn () => $this->retirarHuerfana($huerfana, $persistir));
        }
    }

    /** @param  array<string, list<int>>  $titularesPorNumero */
    protected function analizar(int $customerId, string $ficha, array $titularesPorNumero, bool $persistir): AnalisisTelefonoCliente
    {
        $telefono = $this->normalizador->normalizar($ficha);
        $principal = $this->telefonos->principalDe($customerId);

        if (! $telefono->esBuscable()) {
            if ($principal) {
                return $this->reindexar($customerId, $telefono, 'ficha_'.$telefono->estado->value, $persistir,
                    fn () => $principal->delete());
            }

            return new AnalisisTelefonoCliente($customerId,
                $telefono->estado === EstadoNormalizacionTelefono::AMBIGUO ? Resultado::AMBIGUO : Resultado::INVALIDO,
                $telefono->enmascarado(), $telefono->motivo);
        }

        $titular = $this->telefonos->buscarPorNormalizado($telefono->normalizado);

        if ($titular && $titular->customer_id !== $customerId) {
            return new AnalisisTelefonoCliente($customerId, Resultado::CONFLICTO, $telefono->enmascarado(),
                'pertenece_a_otro_cliente', $titular->customer_id);
        }

        if ($titular && ! $titular->esPrincipal()) {
            return new AnalisisTelefonoCliente($customerId, Resultado::CONFLICTO, $telefono->enmascarado(), 'igual_a_un_secundario');
        }

        if ($titular) {
            if ($titular->telefono_original === $ficha) {
                return new AnalisisTelefonoCliente($customerId, Resultado::YA_INDEXADO, $telefono->enmascarado());
            }

            return $this->reindexar($customerId, $telefono, 'original_distinto', $persistir,
                fn () => $this->telefonos->guardar($titular->fill(['telefono_original' => $ficha])));
        }

        $otros = array_values(array_diff($titularesPorNumero[$telefono->normalizado] ?? [], [$customerId]));

        if ($otros !== []) {
            return new AnalisisTelefonoCliente($customerId, Resultado::DUPLICADO, $telefono->enmascarado(),
                'mismo_numero_en_otra_ficha', $otros[0]);
        }

        if ($principal) {
            return $this->reindexar($customerId, $telefono, 'numero_distinto', $persistir,
                fn () => $this->telefonos->guardar($principal->fill([
                    'telefono_normalizado' => $telefono->normalizado,
                    'telefono_original' => $ficha,
                ])));
        }

        if (! $persistir) {
            return new AnalisisTelefonoCliente($customerId, Resultado::INDEXABLE, $telefono->enmascarado());
        }

        return $this->escribir($customerId, $telefono, Resultado::INDEXADO, null, function () use ($customerId, $telefono, $ficha) {
            $this->telefonos->guardar(new TelefonoCliente([
                'customer_id' => $customerId,
                'telefono_normalizado' => $telefono->normalizado,
                'telefono_original' => $ficha,
                'principal' => true,
            ]));
        });
    }

    protected function retirarHuerfana(TelefonoCliente $huerfana, bool $persistir): AnalisisTelefonoCliente
    {
        $telefono = $this->normalizador->normalizar($huerfana->telefono_original);

        return $this->reindexar($huerfana->customer_id, $telefono, 'ficha_sin_telefono', $persistir,
            fn () => $huerfana->delete());
    }

    protected function reindexar(int $customerId, TelefonoNormalizado $telefono, string $motivo, bool $persistir, callable $escritura): AnalisisTelefonoCliente
    {
        if (! $persistir) {
            return new AnalisisTelefonoCliente($customerId, Resultado::DIVERGENTE, $telefono->enmascarado(), $motivo);
        }

        return $this->escribir($customerId, $telefono, Resultado::REINDEXADO, $motivo, $escritura);
    }

    protected function escribir(int $customerId, TelefonoNormalizado $telefono, Resultado $resultado, ?string $motivo, callable $escritura): AnalisisTelefonoCliente
    {
        try {
            DB::transaction(fn () => $escritura());
        } catch (TelefonoPerteneceAOtroClienteException $e) {
            // Otro proceso indexo el mismo numero para otro cliente entre la lectura y la escritura.
            return new AnalisisTelefonoCliente($customerId, Resultado::CONFLICTO, $telefono->enmascarado(),
                'pertenece_a_otro_cliente', $e->customerIdTitular);
        }

        return new AnalisisTelefonoCliente($customerId, $resultado, $telefono->enmascarado(), $motivo);
    }

    protected function protegido(int $customerId, string $telefono, callable $analisis): AnalisisTelefonoCliente
    {
        try {
            return $analisis();
        } catch (Throwable $e) {
            // Sin el numero ni el mensaje (pueden contener datos): solo cliente y tipo de error.
            Log::error('ventas_asesor.indexar_telefonos.error', ['customer_id' => $customerId, 'error' => $e::class]);

            return new AnalisisTelefonoCliente($customerId, Resultado::ERROR,
                $this->normalizador->normalizar($telefono)->enmascarado(), $e::class);
        }
    }

    /**
     * Numero normalizado => ids de TODAS las fichas que lo tienen, para detectar el
     * mismo numero en varias fichas aunque se analice un solo cliente.
     *
     * @return array<string, list<int>>
     */
    protected function fichasPorNumero(): array
    {
        $mapa = [];

        $consulta = CustomerProxy::modelClass()::query()->select(['id', 'phone'])->whereNotNull('phone');

        foreach ($consulta->lazyById(self::LOTE) as $cliente) {
            $telefono = $this->normalizador->normalizar((string) $cliente->phone);

            if ($telefono->esBuscable()) {
                $mapa[$telefono->normalizado][] = (int) $cliente->id;
            }
        }

        return $mapa;
    }

    /** Filas principales cuyo Customer ya no tiene telefono en la ficha. */
    protected function principalesSinFicha(?int $customerId): iterable
    {
        $tablaClientes = (new (CustomerProxy::modelClass()))->getTable();

        return TelefonoCliente::query()
            ->principales()
            ->whereIn('customer_id', fn ($q) => $q->select('id')->from($tablaClientes)->whereNull('phone'))
            ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
            ->orderBy('customer_id')
            ->get();
    }
}
