<?php

namespace ByLopez\VentasAsesor\Support\Venta;

/**
 * Autorizacion de exceso de cupo CONSUMIDA por el pedido que se esta creando (Doc. 35 §8). Transporta el id desde el cerco
 * (`checkout.order.save.before`, donde se consume bajo el bloqueo del cliente) hasta el listener de `checkout.order.save.after`, que
 * la liga al pedido recien creado, dentro de la misma transaccion.
 *
 * Mismo patron que ConfirmacionEnCurso: `scoped` (nunca singleton ni estado estatico). Si el pedido falla despues de consumir, la
 * transaccion hace rollback (el consumo tambien) y el siguiente intento del repositorio vuelve a pasar por el cerco, que reinicia esto.
 */
class ConsumoExcesoEnCurso
{
    protected ?int $excesoId = null;

    public function registrar(int $excesoId): void
    {
        $this->excesoId = $excesoId;
    }

    /** Devuelve el id consumido por este pedido (una sola vez) y lo retira. */
    public function tomar(): ?int
    {
        $id = $this->excesoId;
        $this->excesoId = null;

        return $id;
    }

    public function reiniciar(): void
    {
        $this->excesoId = null;
    }
}
