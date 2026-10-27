<?php

namespace ByLopez\Logistica\Services\Despachos;

use ByLopez\Logistica\Contracts\PagosPendientesDeConfirmar;
use ByLopez\Logistica\Contracts\ResuelveMunicipio;
use ByLopez\Logistica\Enums\CondicionEnvio;
use ByLopez\Logistica\Enums\PagadorFlete;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Services\Tarifas\ZonasYTarifasService;
use ByLopez\Logistica\Support\CondicionesEnvio;
use ByLopez\Logistica\Support\RecaudoSugeridoBagisto;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\Order;

/**
 * Domicilios locales F1: lo que el DESPACHO deduce en vez de preguntarlo, con UNA sola regla para el drawer (contexto) y
 * para el servidor (despachar), que siempre la vuelve a aplicar:
 *
 *  - Condicion de envio (por codigo de metodo) → pagador del flete. Elegir otro pagador, o elegir uno cuando no se
 *    puede deducir (metodo desconocido), exige motivo.
 *  - Zona: automatica solo con coincidencia exacta del municipio; si no, la elige la operaria (manual) o no se usa.
 *  - Tarifa SUGERIDA vigente de la transportadora en esa zona → costo final (editable). Apartarse de la sugerencia exige
 *    motivo; el resultado queda como snapshot en la guia.
 *  - Saldo pendiente NO bloquea (contraentrega, recogida o domiciliario pueden salir con deuda). Un pago EN VALIDACION
 *    solo bloquea el despacho cuando quien entrega debe cobrar dinero del pedido (el valor a cobrar es incierto).
 *  - Domicilio local (metodo que admite entrega local + direccion en una zona local): domiciliario sugerido = el elegido
 *    por el asesor en la venta; si no hay, el ultimo domiciliario realmente usado en la zona; si no, ninguno.
 */
class CondicionDespacho
{
    public function __construct(
        protected CondicionesEnvio $condiciones,
        protected ZonasYTarifasService $zonas,
        protected PagosPendientesDeConfirmar $pagos,
        protected SugerenciaDomiciliario $domiciliarios,
        protected DomiciliarioDeVentaService $elegidos,
        protected ResuelveMunicipio $municipios,
    ) {}

    /** Quien entrega debe cobrar dinero del pedido: contra entrega (misma regla que el recaudo sugerido). */
    public function cobraAlEntregar(int $orderId): bool
    {
        return DB::table('order_payment')->where('order_id', $orderId)->value('method') === RecaudoSugeridoBagisto::METODO_CONTRAENTREGA;
    }

    /**
     * Estado FINANCIERO del pedido en lenguaje operativo (informativo; no es la instruccion de cobro):
     *  - `pendiente`: el cliente debe `saldo`. Nunca bloquea: la mercancia puede salir con deuda (Cartera la conserva).
     *  - `pagado`: saldo 0 con dinero confirmado.
     *  - `en_validacion`: hay un pago reportado sin confirmar. Solo `bloquea` si quien entrega debe COBRAR dinero del
     *    pedido (`$conRecaudo`): ese pago cambia el valor a cobrar y todavia no se sabe cual es. Sin recaudo (recogida,
     *    transferencia, envio gratis) es solo un aviso.
     *  - null: pedido fuera de Cartera.
     *
     * @return array{bloquea: bool, sin_revisar: float, saldo: ?float, estado: ?string}
     */
    public function pagoPendiente(int $orderId, bool $conRecaudo): array
    {
        $estado = $this->pagos->estado($orderId);
        $sinRevisar = round((float) ($estado['sin_revisar'] ?? 0), 2);
        $saldo = isset($estado['saldo']) ? round((float) $estado['saldo'], 2) : null;
        $enValidacion = $sinRevisar > 0.001;

        return [
            'bloquea' => $enValidacion && $conRecaudo,
            'sin_revisar' => $sinRevisar,
            'saldo' => $saldo,
            'estado' => match (true) {
                $enValidacion => 'en_validacion',
                $saldo === null => null,
                $saldo > 0.001 => 'pendiente',
                default => 'pagado',
            },
        ];
    }

    public function exigirSinPagoPendiente(int $orderId, bool $conRecaudo): void
    {
        $pago = $this->pagoPendiente($orderId, $conRecaudo);

        if ($pago['bloquea']) {
            throw new Invalida(Invalida::PAGO_PENDIENTE_CONFIRMAR, ['monto' => $pago['sin_revisar']]);
        }
    }

    /**
     * Lo que el drawer necesita para MOSTRAR la deduccion (el servidor la repite al despachar).
     *
     * @return array<string, mixed>
     */
    public function contexto(Order $pedido): array
    {
        $condicion = $this->condiciones->deOrden($pedido);
        $zona = $this->zonas->zonaAutomatica((int) $pedido->id);
        $admiteLocal = $this->domiciliarios->metodoAdmiteLocal($pedido->shipping_method);
        $ultimos = $admiteLocal ? $this->domiciliarios->ultimoPorZona() : [];
        $elegido = $admiteLocal ? $this->elegidos->elegidaParaPedido((int) $pedido->id) : null;
        $local = $admiteLocal && $zona !== null;

        return [
            'condicion' => $condicion->value,
            'pagador_deducido' => $condicion->pagador()?->value,
            'zona_auto' => $zona ? ['id' => (int) $zona->id, 'nombre' => $zona->nombre] : null,
            // Solo informativo (no cambia zona, tarifa ni reglas): local = zona local del origen; fuera_cobertura = municipio reconocido
            // que no es local; no_identificado = la direccion no coincide con el catalogo (no se adivina por texto).
            'destino_cobertura' => $zona !== null
                ? 'local'
                : ($this->municipios->municipioDePedido((int) $pedido->id) !== null ? 'fuera_cobertura' : 'no_identificado'),
            'zonas' => $this->zonas->zonasActivas()->map(fn ($z) => ['id' => (int) $z->id, 'nombre' => $z->nombre])->values()->all(),
            'tarifas' => $this->zonas->tarifasVigentes(),
            'pago_pendiente' => $this->pagoPendiente((int) $pedido->id, $this->cobraAlEntregar((int) $pedido->id)),
            // Domiciliario (cierre F1): `admite_domicilio_local` = el metodo admite entrega local (si la zona se elige a mano,
            // el drawer recalcula con `ultimo_por_zona`); `domicilio_local` = ademas la direccion cae en una zona local.
            // Prioridad: elegido por el asesor en la venta → ultimo domiciliario usado en la zona → ninguno.
            'admite_domicilio_local' => $admiteLocal,
            'domicilio_local' => $local,
            'ultimo_por_zona' => (object) $ultimos,
            'elegido_venta' => $elegido,
            'domiciliario_sugerido' => $elegido ?? ($local ? ($ultimos[(int) $zona->id] ?? null) : null),
        ];
    }

    /**
     * Resuelve y VALIDA pagador, zona y costo de un despacho. Devuelve los valores a guardar en la guia.
     *
     * @return array{pagador: PagadorFlete, condicion: CondicionEnvio, pagador_deducido: ?string, motivo_pagador: ?string,
     *               zona_id: ?int, zona_nombre: ?string, zona_origen: string, tarifa_sugerida: ?float, costo_adicional: ?float,
     *               motivo_costo: ?string, valor_flete: ?float}
     */
    public function resolver(Order $pedido, Transportadora $transportadora, string $pagador, ?string $motivoPagador, ?int $zonaId, ?float $flete, ?string $motivoCosto): array
    {
        $condicion = $this->condiciones->deOrden($pedido);
        $deducido = $condicion->pagador();
        $pagador = trim($pagador);

        if ($pagador === '') {
            $elegido = $deducido ?? throw new Invalida(Invalida::PAGADOR_REQUERIDO);
        } else {
            $elegido = PagadorFlete::tryFrom($pagador) ?? throw new Invalida(Invalida::PAGADOR_FLETE_INVALIDO);
        }

        // Sin deduccion posible, o eligiendo otro pagador: siempre con motivo (queda en la guia).
        if (($deducido === null || $elegido !== $deducido) && $motivoPagador === null) {
            throw new Invalida(Invalida::MOTIVO_PAGADOR_REQUERIDO);
        }

        // Zona: la automatica manda (no se reemplaza a mano); si no la hay, la elegida por la operaria o ninguna.
        $zona = $this->zonas->zonaAutomatica((int) $pedido->id);
        $origen = 'auto';

        if ($zona === null && $zonaId !== null) {
            $zona = $this->zonas->zonaActiva($zonaId) ?? throw new Invalida(Invalida::ZONA_INVALIDA);
            $origen = 'manual';
        } elseif ($zona === null) {
            $origen = 'sin_zona';
        }

        $tarifa = $zona ? $this->zonas->tarifaVigente((int) $transportadora->id, (int) $zona->id) : null;
        $sugerida = $tarifa ? round((float) $tarifa->valor, 2) : null;

        // Sin costo escrito se acepta la sugerencia (la interfaz siempre envia el costo final).
        $final = $flete ?? $sugerida;

        if ($final === null && $transportadora->liquida_cuenta_corriente) {
            throw new Invalida(Invalida::COSTO_REQUERIDO);
        }

        $adicional = ($sugerida !== null && $final !== null) ? round($final - $sugerida, 2) : null;

        if ($adicional !== null && abs($adicional) > 0.001 && $motivoCosto === null) {
            throw new Invalida(Invalida::MOTIVO_COSTO_REQUERIDO, ['sugerida' => $sugerida]);
        }

        return [
            'pagador' => $elegido,
            'condicion' => $condicion,
            'pagador_deducido' => $deducido?->value,
            'motivo_pagador' => ($deducido === null || $elegido !== $deducido) ? $motivoPagador : null,
            'zona_id' => $zona ? (int) $zona->id : null,
            'zona_nombre' => $zona?->nombre,
            'zona_origen' => $origen,
            'tarifa_sugerida' => $sugerida,
            'costo_adicional' => $adicional,
            'motivo_costo' => ($adicional !== null && abs($adicional) > 0.001) ? $motivoCosto : null,
            'valor_flete' => $final,
        ];
    }
}
