<?php

namespace ByLopez\Logistica\Services\Despachos;

use ByLopez\Logistica\Contracts\DomiciliarioDeVenta;
use ByLopez\Logistica\Models\PreferenciaEntrega;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Services\Tarifas\TarifaDomicilioLocal;
use ByLopez\Logistica\Services\Tarifas\ZonasYTarifasService;
use Illuminate\Support\Facades\DB;

/**
 * Domiciliario elegido en la venta (D1). Prioridad del despacho: 1) elegido por el asesor (esta tabla), 2) ultimo
 * domiciliario realmente usado en la zona, 3) ninguno. Las reglas de candidato local y de clases son las de
 * SugerenciaDomiciliario; la tarifa (transportadora + zona) es la de F1 (TarifaDomicilioLocal). Aqui no se repiten.
 */
class DomiciliarioDeVentaService implements DomiciliarioDeVenta
{
    public function __construct(
        protected SugerenciaDomiciliario $reglas,
        protected ZonasYTarifasService $zonas,
        protected TarifaDomicilioLocal $tarifas,
    ) {}

    public function opcionesParaVenta(?string $municipioCodigo, ?string $metodoEnvio, ?int $elegidoId): ?array
    {
        $sinMetodo = $metodoEnvio === null || trim($metodoEnvio) === '';
        $zona = ($sinMetodo || $this->reglas->metodoAdmiteLocal($metodoEnvio)) ? $this->zonas->zonaDeMunicipio($municipioCodigo) : null;

        if ($zona === null) {
            return null;
        }

        $opciones = $this->reglas->seleccionables()->map(fn (Transportadora $t) => [
            'id' => (int) $t->id,
            'nombre' => $t->nombre,
            'codigo' => $t->codigo,
            'tarifa' => $this->tarifas->tarifa((int) $t->id, $zona),
        ]);

        // Sugerido: el ultimo domiciliario usado en la zona, solo si tiene tarifa alli (si no, la venta no podria continuar con el).
        $ultimo = $this->reglas->ultimoPorZona()[(int) $zona->id] ?? null;
        $sugerido = $opciones->first(fn (array $o) => $o['codigo'] === $ultimo && $o['tarifa'] !== null);

        // Una eleccion que dejo de ser valida (transportadora desactivada) no se muestra como elegida.
        $elegido = $elegidoId === null ? null : $opciones->firstWhere('id', $elegidoId);

        return [
            'zona' => ['id' => (int) $zona->id, 'nombre' => $zona->nombre],
            'opciones' => $opciones->map(fn (array $o) => ['id' => $o['id'], 'nombre' => $o['nombre'], 'tarifa' => $o['tarifa']])->values()->all(),
            'sugerido_id' => $sugerido['id'] ?? null,
            'elegido_id' => $elegido['id'] ?? null,
            'tarifa' => $elegido['tarifa'] ?? null,
            'requerido' => true,
            'listo' => $elegido !== null && $elegido['tarifa'] !== null,
        ];
    }

    public function esSeleccionable(int $transportadoraId): bool
    {
        return $this->reglas->esSeleccionable($transportadoraId);
    }

    public function registrarDesdeVenta(int $orderId, int $transportadoraId, ?int $actorAdminId): void
    {
        $metodo = DB::table('orders')->where('id', $orderId)->value('shipping_method');

        if (! $this->reglas->metodoAdmiteLocal($metodo)
            || $this->zonas->zonaAutomatica($orderId) === null
            || ! $this->reglas->esSeleccionable($transportadoraId)) {
            return;
        }

        PreferenciaEntrega::query()->firstOrCreate(['order_id' => $orderId], [
            'transportadora_id' => $transportadoraId,
            'origen' => PreferenciaEntrega::ORIGEN_VENTA,
            'seleccionado_por_admin_id' => $actorAdminId,
        ]);
    }

    /** Codigo de la transportadora elegida en la venta para el pedido, si sigue siendo seleccionable. */
    public function elegidaParaPedido(int $orderId): ?string
    {
        $preferencia = PreferenciaEntrega::query()->with('transportadora')->where('order_id', $orderId)->first();

        return $preferencia && $this->reglas->esSeleccionable((int) $preferencia->transportadora_id)
            ? $preferencia->transportadora->codigo
            : null;
    }
}
