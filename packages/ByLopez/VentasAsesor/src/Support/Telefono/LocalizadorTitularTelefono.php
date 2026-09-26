<?php

namespace ByLopez\VentasAsesor\Support\Telefono;

use ByLopez\VentasAsesor\Contracts\NormalizadorTelefono;
use ByLopez\VentasAsesor\DataObjects\TelefonoNormalizado;
use ByLopez\VentasAsesor\DataObjects\TitularTelefono;
use ByLopez\VentasAsesor\Exceptions\TelefonoEnVariasFichasException;
use ByLopez\VentasAsesor\Repositories\TelefonoClienteRepository;
use Webkul\Customer\Models\CustomerProxy;

/**
 * Encuentra el Customer dueño de un telefono normalizado (RT-04B1b).
 *
 * 1. Indice comercial (bylopez_va_customer_phones): principal o secundario.
 * 2. Transicion para clientes historicos sin indexar: `customers.phone` con las
 *    representaciones que el core permite guardar para ese numero. Bagisto solo
 *    admite ^\+?\d+$ en alta, edicion, perfil e importador (Doc. 13 §36.3), asi que
 *    las variantes posibles son finitas: se generan y se conservan solo las que el
 *    propio normalizador devuelve al mismo E.164. La consulta usa el indice UNIQUE
 *    de customers.phone. No escribe nada.
 *
 * Si el indice y la ficha apuntan a Customers distintos, o varias fichas tienen el
 * numero, se lanza TelefonoEnVariasFichasException: nunca se elige titular.
 */
class LocalizadorTitularTelefono
{
    public function __construct(
        protected NormalizadorTelefono $normalizador,
        protected TelefonoClienteRepository $telefonos,
    ) {}

    /** @throws TelefonoEnVariasFichasException */
    public function titular(TelefonoNormalizado $telefono): ?TitularTelefono
    {
        if (! $telefono->esBuscable()) {
            return null;
        }

        $fila = $this->telefonos->buscarPorNormalizado($telefono->normalizado);

        $fichas = CustomerProxy::modelClass()::query()
            ->whereIn('phone', $this->variantesEnFicha($telefono->normalizado))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $duenos = array_values(array_unique(array_merge($fila ? [$fila->customer_id] : [], $fichas)));

        if (count($duenos) > 1) {
            throw new TelefonoEnVariasFichasException($duenos);
        }

        if ($fila) {
            return new TitularTelefono($fila->customer_id,
                $fila->esPrincipal() ? TitularTelefono::INDICE_PRINCIPAL : TitularTelefono::INDICE_SECUNDARIO);
        }

        return $duenos === [] ? null : new TitularTelefono($duenos[0], TitularTelefono::FICHA_SIN_INDEXAR);
    }

    /**
     * Cadenas que el core pudo guardar en customers.phone para este E.164.
     *
     * @return list<string>
     */
    public function variantesEnFicha(string $e164): array
    {
        $digitos = substr($e164, 1);
        $candidatas = ['+'.$digitos, '00'.$digitos, $digitos];

        if (str_starts_with($digitos, '57')) {
            $candidatas[] = substr($digitos, 2);
        }

        return array_values(array_filter(
            array_unique($candidatas),
            fn (string $c) => $this->normalizador->normalizar($c)->normalizado === $e164,
        ));
    }
}
