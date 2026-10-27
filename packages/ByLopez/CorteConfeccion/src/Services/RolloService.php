<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Repositories\RolloRepository;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;

class RolloService
{
    public function __construct(protected RolloRepository $rolloRepository) {}

    public function create(array $data): Rollo
    {
        $data['peso_disponible'] = $data['peso_disponible'] ?? $data['peso_inicial'];
        $data['estado'] = EstadoRollo::Disponible->value;

        // F1-D: si el alta manual vuelve a exponerse por ruta, los datos
        // nuevos nacen con identidad de color estructurada. El snapshot se
        // deriva de Bagisto; el texto libre no identifica el color.
        [$data['color_option_id'], $data['color']] = $this->resolverColor($data);

        return $this->rolloRepository->create($data);
    }

    public function update(Rollo $rollo, array $data): Rollo
    {
        if ($rollo->estado === EstadoRollo::ReservadoEnCorte->value) {
            $pesoInicialCambio = array_key_exists('peso_inicial', $data) && (float) $data['peso_inicial'] !== (float) $rollo->peso_inicial;
            $pesoDisponibleCambio = array_key_exists('peso_disponible', $data) && (float) $data['peso_disponible'] !== (float) $rollo->peso_disponible;

            if ($pesoInicialCambio || $pesoDisponibleCambio) {
                throw ValidationException::withMessages([
                    'peso_disponible' => trans('corteconfeccion::app.errors.rollo-reservado-no-editable'),
                ]);
            }
        }

        $this->validarCambioColor($rollo, $data);

        if ($rollo->colorBloqueado()) {
            // F1-D: en un rollo con color bloqueado (referencia, reservado o ya
            // usado) el color y su snapshot son inmutables. Si el payload trae el
            // mismo color_option_id, NO se re-deriva el snapshot desde Bagisto
            // (una opcion renombrada lo cambiaria en silencio y rompe la
            // trazabilidad); cualquier cambio real ya se rechazo arriba.
            unset($data['color_option_id'], $data['color']);
        } elseif (array_key_exists('color_option_id', $data)) {
            [$data['color_option_id'], $data['color']] = $this->resolverColor($data);
        } else {
            unset($data['color']);
        }

        $data['estado'] = in_array($rollo->estado, [EstadoRollo::Anulado->value, EstadoRollo::ReservadoEnCorte->value], true)
            ? $rollo->estado
            : $this->estadoPorPeso((float) ($data['peso_disponible'] ?? $rollo->peso_disponible), (float) ($data['peso_inicial'] ?? $rollo->peso_inicial));

        $rollo->update($data);

        return $rollo->refresh();
    }

    /**
     * F1 (docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md §5.4): mismo criterio
     * de resolucion que ProveedorTelaService::resolverColorReferencia() --
     * si llega color_option_id, es la unica fuente de verdad (el snapshot
     * `color` se deriva de la opcion real, nunca del texto del navegador).
     * Revalida aqui de forma independiente al FormRequest (defensa en
     * profundidad, mismo criterio de F0/ProveedorTelaService).
     *
     * @return array{0: int, 1: string}
     */
    protected function resolverColor(array $data): array
    {
        $colorOptionId = (int) ($data['color_option_id'] ?? 0);

        if (! $colorOptionId) {
            throw ValidationException::withMessages([
                'color_option_id' => trans('corteconfeccion::app.errors.rollo-color-requerido'),
            ]);
        }

        $colorAttributeId = Attribute::query()->where('code', 'color')->value('id');

        $opcion = AttributeOption::query()
            ->where('id', $colorOptionId)
            ->where('attribute_id', $colorAttributeId ?: 0)
            ->first();

        if (! $opcion) {
            throw ValidationException::withMessages([
                'color_option_id' => 'El color seleccionado no es una opcion valida del atributo color de Bagisto.',
            ]);
        }

        return [$opcion->id, (string) $opcion->admin_name];
    }

    /**
     * F1, CASOS B/C/D del encargo. Bloquea el cambio de color_option_id
     * (en cualquier direccion, incluido vincular por primera vez uno que
     * hoy es NULL) cuando:
     * - el rollo viene de una referencia de proveedor (CASO B: "el color se
     *   corrige desde la referencia, no desde cada rollo" -- no hay hoy
     *   mecanismo para una "razon funcional explicita" de divergencia, asi
     *   que se bloquea por completo mientras no exista uno);
     * - el rollo esta reservado en una orden de corte abierta (CASO C);
     * - el rollo ya participo alguna vez en una orden de corte, este o no
     *   reservado ahora mismo (CASO D), tanto por el pivote real
     *   `ordenesCorteRollos()` como por la FK historica
     *   `ordenes_corte.rollo_id`.
     *
     * No aplica a peso (ya validado arriba, antes de llamar a este metodo)
     * ni a ningun otro campo.
     */
    protected function validarCambioColor(Rollo $rollo, array $data): void
    {
        $intentaColorOption = array_key_exists('color_option_id', $data);
        $intentaSnapshot = array_key_exists('color', $data)
            && (string) $data['color'] !== (string) $rollo->color;

        if (! $intentaColorOption && ! $intentaSnapshot) {
            return;
        }

        $nuevoColorOptionId = $intentaColorOption && $data['color_option_id'] ? (int) $data['color_option_id'] : null;
        $colorOptionIdActual = $rollo->color_option_id ? (int) $rollo->color_option_id : null;
        $cambiaColorOptionId = $intentaColorOption && $nuevoColorOptionId !== $colorOptionIdActual;

        if (! $cambiaColorOptionId && ! $intentaSnapshot) {
            return;
        }

        if ($rollo->proveedor_tela_referencia_id) {
            throw ValidationException::withMessages([
                'color_option_id' => trans('corteconfeccion::app.errors.rollo-color-referencia-no-editable'),
            ]);
        }

        if ($rollo->estaVinculadoAOrdenCorte()) {
            throw ValidationException::withMessages([
                'color_option_id' => trans('corteconfeccion::app.errors.rollo-color-no-editable'),
            ]);
        }

        if (! $intentaColorOption) {
            throw ValidationException::withMessages([
                'color_option_id' => trans('corteconfeccion::app.errors.rollo-color-requerido'),
            ]);
        }
    }

    public function cancel(Rollo $rollo): Rollo
    {
        $rollo->update(['estado' => EstadoRollo::Anulado->value]);

        return $rollo->refresh();
    }

    public function estadoPorPeso(float $pesoDisponible, float $pesoInicial): string
    {
        if ($pesoDisponible <= 0) {
            return EstadoRollo::Agotado->value;
        }

        if ($pesoDisponible < $pesoInicial) {
            return EstadoRollo::ParcialmenteUsado->value;
        }

        return EstadoRollo::Disponible->value;
    }
}
