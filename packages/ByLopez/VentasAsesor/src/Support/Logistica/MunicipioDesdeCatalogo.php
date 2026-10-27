<?php

namespace ByLopez\VentasAsesor\Support\Logistica;

use ByLopez\Logistica\Contracts\ResuelveMunicipio;
use Illuminate\Support\Facades\DB;

/**
 * Municipio DIVIPOLA de la direccion de envio de un pedido, con el catalogo geografico local (D-58).
 *
 * La venta nueva guarda en la direccion EXACTAMENTE el nombre oficial del municipio y el `code` del estado de Bagisto
 * equivalente a su departamento (CatalogoUbicaciones::resolver). Aqui se exige esa misma coincidencia exacta (byte a
 * byte en el nombre, no por intercalacion de la base) y un unico candidato: un texto libre historico como «BOGOTA» / «MI»
 * no coincide y devuelve null. Nunca se aproxima ni se adivina.
 */
class MunicipioDesdeCatalogo implements ResuelveMunicipio
{
    public function municipioDePedido(int $orderId): ?string
    {
        $direccion = DB::table('addresses')->where('order_id', $orderId)->where('address_type', 'order_shipping')
            ->first(['city', 'state', 'country']);

        return $direccion ? $this->municipioDeDireccion($direccion->city, $direccion->state, $direccion->country) : null;
    }

    /** Misma regla para cualquier direccion (p. ej. la de envio del carrito de una venta en construccion). */
    public function municipioDeDireccion(?string $ciudad, ?string $estado, ?string $pais): ?string
    {
        if ($pais !== 'CO' || trim((string) $ciudad) === '' || trim((string) $estado) === '') {
            return null;
        }

        $candidatos = DB::table('bylopez_geo_municipios as m')
            ->join('bylopez_geo_departamentos as d', 'd.codigo_divipola', '=', 'm.departamento_codigo')
            ->join('country_states as s', 's.id', '=', 'd.country_state_id')
            ->where('m.activo', true)
            ->where('s.country_code', 'CO')
            ->where('s.code', $estado)
            ->where('m.nombre_oficial', $ciudad)
            ->get(['m.codigo_divipola', 'm.nombre_oficial'])
            ->filter(fn ($m) => $m->nombre_oficial === $ciudad);

        return $candidatos->count() === 1 ? (string) $candidatos->first()->codigo_divipola : null;
    }
}
