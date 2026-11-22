<?php

namespace ByLopez\VentasAsesor\Support\Precios;

use Illuminate\Support\Collection;
use Webkul\Product\Contracts\Product;

/**
 * Puente de SOLO LECTURA al motor de precios de Bagisto para «Precios por grupo» (Doc. 32). No es un motor paralelo:
 *
 * - El precio sale SIEMPRE del indexador de precios de Bagisto del tipo de la variante
 *   (`getPriceIndexer()->setChannel()->setCustomerGroup()->setProduct()->getMinimalPrice($qty)`), lo mismo que usa
 *   `Configurable::validateCartItem` al repreciar el carrito y Nueva venta en `CatalogoVentaService`.
 * - Para la VISTA PREVIA se le entrega al mismo indexador un CLON de la variante con las reglas propuestas en memoria
 *   (`setRelation('customer_group_prices', ...)`): Bagisto calcula sobre lo que quedaria guardado, sin escribir nada.
 * - `reglaGanadora()` repite el recorrido de `Price\AbstractType::getCustomerGroupPrice` SOLO para decir que fila gana;
 *   si su resultado no coincide con el del indexador, el origen es «indeterminado» (no se inventa una explicacion).
 *
 * Reglas verificadas (PreciosGrupo01CaracterizacionTest): `discount` = % de descuento sobre el precio BASE; `fixed` =
 * precio unitario y solo cuenta si es MENOR que el precio vigente; escalon por cantidad de la linea (SKU); el resultado
 * final es el MENOR entre el precio de grupo y el precio con especial / regla de catalogo (no se acumulan).
 */
class MotorPrecioGrupo
{
    private const TOLERANCIA = 0.00005;

    /** @var array<string, object> indexador de precios de Bagisto por tipo de producto */
    private array $indexadores = [];

    /** @var array<int, array<string, object>> atributos de cada familia, por codigo */
    private array $familias = [];

    /**
     * @param  object  $grupo  CustomerGroup (o cualquier objeto con `id`)
     * @param  Collection|null  $reglas  reglas propuestas (todas las filas de la variante, en el orden en que quedarian)
     */
    public function evaluar(Product $variante, object $grupo, int $qty, ?Collection $reglas = null): EvaluacionPrecioGrupo
    {
        $producto = $reglas === null ? $variante : $this->conReglas($variante, $reglas);
        $qty = max(1, $qty);

        // Un indexador por tipo de producto, como hace Bagisto en Indexers\Price::getTypeIndexer (es sin estado entre llamadas:
        // canal, grupo y producto se fijan siempre antes de calcular).
        $indexador = ($this->indexadores[$variante->type] ??= $variante->getTypeInstance()->getPriceIndexer())
            ->setChannel(core()->getCurrentChannel())
            ->setCustomerGroup($grupo)
            ->setProduct($producto);

        $final = (float) $indexador->getMinimalPrice($qty);
        $precioGrupo = (float) $indexador->getCustomerGroupPrice($qty);
        $reglaCatalogo = $indexador->getCatalogRulePrice();
        $base = (float) $producto->price;

        [$replica, $ganadora] = $this->reglaGanadora($producto, (int) $grupo->id, $qty);

        $especial = (float) $producto->special_price;
        $especialVigente = $especial > 0 && core()->isChannelDateInInterval($producto->special_price_from, $producto->special_price_to)
            ? $especial
            : null;

        return new EvaluacionPrecioGrupo(
            qty: $qty,
            base: $base,
            final: $final,
            precioGrupo: $precioGrupo,
            regla: $ganadora,
            origen: $this->origen($final, $precioGrupo, $replica, $ganadora, $especialVigente, $reglaCatalogo?->price === null ? null : (float) $reglaCatalogo->price),
            especialVigente: $especialVigente,
            precioReglaCatalogo: $reglaCatalogo?->price === null ? null : (float) $reglaCatalogo->price,
        );
    }

    /**
     * Rendimiento (medido con datos de desarrollo: de ~10 ms a <1 ms por evaluacion). `Product::getAttribute` de Bagisto
     * vuelve a resolver un atributo EAV en CADA acceso si su valor es null (`isset(null)` es falso), y cada resolucion
     * recorre los atributos de la familia (~1,7 ms). El indexador lee `special_price` y sus fechas varias veces.
     * Aqui se resuelven una vez y, si son null, se dejan como '' EN MEMORIA (la instancia nunca se guarda): para el
     * indexador es equivalente (`empty('')`, `(float) '' === 0.0`, `core()->is_empty_date('')`). `price` solo se
     * precarga (si es null se deja null: '' no es numerico).
     *
     * La resolucion es LA MISMA de `Product::getAttribute`: el atributo de la familia de la variante y
     * `getCustomAttributeValue($atributo)` (API publica del modelo); solo se evita buscar el atributo en la familia en cada
     * acceso (se indexa por codigo una vez por familia).
     */
    public function preparar(Product $variante): Product
    {
        $familia = $this->familias[(int) $variante->attribute_family_id] ??= $variante->checkInLoadedFamilyAttributes()->keyBy('code')->all();
        $crudos = $variante->getAttributes();

        foreach (['price', 'special_price', 'special_price_from', 'special_price_to'] as $codigo) {
            if (isset($crudos[$codigo])) {
                continue;
            }

            $valor = isset($familia[$codigo]) ? $variante->getCustomAttributeValue($familia[$codigo]) : null;
            $crudos[$codigo] = ($valor === null && $codigo !== 'price') ? '' : $valor;
        }

        $variante->setRawAttributes($crudos, true);

        return $variante;
    }

    /** Clon de la variante con otras reglas en memoria (no toca la instancia original ni la base). */
    public function conReglas(Product $variante, Collection $reglas): Product
    {
        $clon = clone $variante;
        $clon->setRelation('customer_group_prices', $reglas->values());

        return $clon;
    }

    /**
     * Mismo recorrido que `Price\AbstractType::getCustomerGroupPrice` (filas del grupo y de «todos los grupos», en su
     * orden): devuelve el precio de grupo y la fila que lo produce.
     *
     * @return array{0: float, 1: array{id: ?int, qty: int, tipo: string, valor: float, todos: bool}|null}
     */
    public function reglaGanadora(Product $producto, int $grupoId, int $qty): array
    {
        $base = (float) $producto->price;
        $ultimaCantidad = 1;
        $ultimoPrecio = $base;
        $ganadora = null;

        foreach ($producto->customer_group_prices as $r) {
            if (! ($r->customer_group_id === null || (int) $r->customer_group_id === $grupoId)) {
                continue;
            }

            if ((int) $r->qty > $qty || (int) $r->qty < $ultimaCantidad) {
                continue;
            }

            $valor = (float) $r->value;

            if ($r->value_type === 'discount') {
                if ($valor >= 0 && $valor <= 100) {
                    $ultimoPrecio = $base - ($base * $valor) / 100;
                    $ultimaCantidad = (int) $r->qty;
                    $ganadora = $r;
                }
            } elseif ($valor >= 0 && $valor < $ultimoPrecio) {
                $ultimoPrecio = $valor;
                $ultimaCantidad = (int) $r->qty;
                $ganadora = $r;
            }
        }

        return [$ultimoPrecio, $ganadora === null ? null : [
            'id' => $ganadora->id === null ? null : (int) $ganadora->id,
            'qty' => (int) $ganadora->qty,
            'tipo' => (string) $ganadora->value_type,
            'valor' => (float) $ganadora->value,
            'todos' => $ganadora->customer_group_id === null,
        ]];
    }

    /**
     * Escalones del grupo que NO tienen efecto en su propia cantidad (p. ej., un fijo de 10 u. mas caro que el de 1 u.,
     * o un fijo mayor que el precio base). @return list<int> cantidades
     */
    public function escalonesSinEfecto(Product $producto, int $grupoId): array
    {
        $sinEfecto = [];

        foreach ($producto->customer_group_prices as $r) {
            if ($r->customer_group_id === null || (int) $r->customer_group_id !== $grupoId) {
                continue;
            }

            [, $ganadora] = $this->reglaGanadora($producto, $grupoId, max(1, (int) $r->qty));

            $esElla = $ganadora !== null && (int) $ganadora['qty'] === (int) $r->qty && ! $ganadora['todos']
                && $ganadora['tipo'] === $r->value_type && abs($ganadora['valor'] - (float) $r->value) < self::TOLERANCIA;

            if (! $esElla) {
                $sinEfecto[] = (int) $r->qty;
            }
        }

        sort($sinEfecto);

        return array_values(array_unique($sinEfecto));
    }

    private function origen(float $final, float $precioGrupo, float $replica, ?array $ganadora, ?float $especial, ?float $reglaCatalogo): string
    {
        if (abs($replica - $precioGrupo) > self::TOLERANCIA) {
            return EvaluacionPrecioGrupo::ORIGEN_INDETERMINADO;
        }

        if ($ganadora !== null && abs($precioGrupo - $final) <= self::TOLERANCIA) {
            return EvaluacionPrecioGrupo::ORIGEN_GRUPO;
        }

        if ($reglaCatalogo !== null && abs($reglaCatalogo - $final) <= self::TOLERANCIA) {
            return EvaluacionPrecioGrupo::ORIGEN_REGLA_CATALOGO;
        }

        if ($especial !== null && abs($especial - $final) <= self::TOLERANCIA) {
            return EvaluacionPrecioGrupo::ORIGEN_ESPECIAL;
        }

        return EvaluacionPrecioGrupo::ORIGEN_BASE;
    }
}
