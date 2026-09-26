<?php

namespace ByLopez\VentasAsesor\Support;

use Webkul\Customer\Repositories\CustomerRepository;

/**
 * CustomerRepository de Bagisto con UNA sola diferencia (RT-03B): si hay un
 * ContextoPrecioComercial explicito, el grupo usado para precios y reglas es el del
 * cliente comercial del carrito. Sin contexto, delega en el core sin tocar nada.
 *
 * Todos los consumidores del core (AbstractType, Configurable, CartRule,
 * ProductRepository, ElasticSearchRepository, indexadores) reciben esta clase por el
 * binding de VentasAsesorServiceProvider: CustomerRepository se resuelve por el
 * contenedor y no tiene binding propio en Webkul.
 *
 * El contexto se obtiene del contenedor en cada llamada, nunca por constructor: los
 * repositorios pueden vivir dentro de objetos mas longevos que la instancia `scoped`
 * del contexto.
 */
class CustomerRepositoryConContextoComercial extends CustomerRepository
{
    public function getCurrentGroup()
    {
        $contexto = app(ContextoPrecioComercial::class);

        if (! $contexto->activo()) {
            return parent::getCurrentGroup();
        }

        return $contexto->grupo();
    }
}
