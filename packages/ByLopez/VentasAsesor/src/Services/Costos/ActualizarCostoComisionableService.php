<?php

namespace ByLopez\VentasAsesor\Services\Costos;

use ByLopez\VentasAsesor\DataObjects\FilaControlCosto;
use ByLopez\VentasAsesor\DataObjects\VistaPreviaCostos;
use ByLopez\VentasAsesor\Exceptions\CostoComisionableInvalidoException as Invalido;
use ByLopez\VentasAsesor\Models\CambioCostoComisionable;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Costos\CostoComisionableCatalogo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Product\Repositories\ProductAttributeValueRepository;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Actualizacion MASIVA del costo comisionable (D-COST-05): seleccion → vista previa → confirmacion → actualizacion →
 * auditoria. Nunca automatica.
 *
 * - Solo con `ventas_asesor.costos.actualizar` (el servicio valida, no solo la ruta). El asesor nunca cambia costos.
 * - Costo nuevo: decimal > 0 con hasta 2 decimales; nunca NULL, 0 ni negativo.
 * - Solo productos `simple` (las variantes vendibles: el costo vive en la variante, no en el configurable).
 * - Confirmar exige el token de la vista previa; se recalcula bajo bloqueo y, si algun costo cambio entretanto, se
 *   rechaza (`vista_previa_requerida`).
 * - Escribe el EAV con el repositorio de Bagisto (solo la fila del atributo `cost`; no dispara el guardado completo
 *   del producto ni reindexa: `cost` no participa en precios ni en product_flat) y deja una fila de bitacora por
 *   variante cambiada, con un `lote` comun.
 * - Las ventas ya hechas NO cambian: su costo vive en su snapshot (D-COST-06).
 */
class ActualizarCostoComisionableService
{
    public const MAX_SELECCION = 1000;

    public const MECANISMO_PANEL = 'panel';

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected ControlCostosService $control,
        protected CostoComisionableCatalogo $catalogo,
        protected ProductAttributeValueRepository $valores,
    ) {}

    /** @param  list<int|string>  $ids */
    public function previsualizar(?AdminContract $actor, array $ids, mixed $nuevoCosto): VistaPreviaCostos
    {
        $this->exigirPermiso($actor);
        $nuevo = $this->validarCosto($nuevoCosto);
        $ids = $this->validarIds($ids);

        return $this->construirVista($ids, $nuevo);
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{lote: string, actualizados: int, sin_cambio: int}
     */
    public function confirmar(?AdminContract $actor, array $ids, mixed $nuevoCosto, string $token, ?string $motivo = null): array
    {
        $this->exigirPermiso($actor);
        $nuevo = $this->validarCosto($nuevoCosto);
        $ids = $this->validarIds($ids);
        $motivo = ($m = trim((string) $motivo)) === '' ? null : mb_substr($m, 0, 500);

        return DB::transaction(function () use ($actor, $ids, $nuevo, $token, $motivo) {
            $atributo = $this->catalogo->atributo() ?? throw new Invalido(Invalido::ATRIBUTO_INEXISTENTE, 'No existe el atributo «cost» en Bagisto.');

            // Bloquea las filas EAV existentes para que nadie cambie un costo entre la verificacion y la escritura.
            DB::table('product_attribute_values')->where('attribute_id', $atributo->id)->whereIn('product_id', $ids)->lockForUpdate()->get(['id']);

            $vista = $this->construirVista($ids, $nuevo);

            if (! hash_equals($vista->token, $token)) {
                throw new Invalido(Invalido::VISTA_PREVIA_REQUERIDA, 'Los costos cambiaron desde la vista previa (o no hubo vista previa). Genere una vista previa nueva.');
            }

            if ($vista->conCambio === 0) {
                throw new Invalido(Invalido::SIN_CAMBIOS, 'Todas las variantes seleccionadas ya tienen ese costo.');
            }

            $lote = (string) Str::uuid();
            $canal = $atributo->value_per_channel ? core()->getDefaultChannelCode() : null;
            $idioma = $atributo->value_per_locale ? core()->getDefaultLocaleCodeFromDefaultChannel() : null;
            $actualizados = 0;

            foreach ($vista->filas as $fila) {
                if ($fila['actual'] !== null && round($fila['actual'], 4) === round((float) $nuevo, 4)) {
                    continue;
                }

                $existente = DB::table('product_attribute_values')
                    ->where(['product_id' => $fila['id'], 'attribute_id' => $atributo->id, 'channel' => $canal, 'locale' => $idioma])
                    ->value('id');

                $uniqueId = implode('|', array_filter([$canal, $idioma, $fila['id'], $atributo->id]));

                if ($existente !== null) {
                    $this->valores->update(['float_value' => $nuevo, 'unique_id' => $uniqueId], $existente);
                } else {
                    $this->valores->create([
                        'product_id' => $fila['id'], 'attribute_id' => $atributo->id, 'channel' => $canal,
                        'locale' => $idioma, 'float_value' => $nuevo, 'unique_id' => $uniqueId,
                    ]);
                }

                CambioCostoComisionable::query()->create([
                    'lote' => $lote,
                    'product_id' => $fila['id'],
                    'sku_snapshot' => $fila['sku'],
                    'producto_snapshot' => $fila['producto'] ?? $fila['nombre'],
                    'costo_anterior' => $fila['actual'],
                    'costo_nuevo' => $nuevo,
                    'actor_admin_id' => $actor?->id ? (int) $actor->id : null,
                    'actor_nombre_snapshot' => $actor?->name,
                    'mecanismo' => self::MECANISMO_PANEL,
                    'motivo' => $motivo,
                ]);

                $actualizados++;
            }

            return ['lote' => $lote, 'actualizados' => $actualizados, 'sin_cambio' => $vista->sinCambio];
        });
    }

    /** @param  list<int>  $ids */
    protected function construirVista(array $ids, string $nuevo): VistaPreviaCostos
    {
        $filas = $this->control->filas([], $ids)
            ->map(fn (FilaControlCosto $f) => [
                'id' => $f->productId,
                'sku' => $f->sku,
                'nombre' => $f->nombre,
                'producto' => $f->producto,
                'actual' => $f->costoComisionable,
                'nuevo' => (float) $nuevo,
                'diferencia' => $f->costoComisionable === null ? null : round((float) $nuevo - $f->costoComisionable, 2),
            ])
            ->sortBy('id')
            ->values()
            ->all();

        if (count($filas) !== count($ids)) {
            throw new Invalido(Invalido::PRODUCTO_NO_VALIDO, 'Solo se pueden actualizar variantes (productos simples) existentes.');
        }

        $actuales = array_values(array_filter(array_column($filas, 'actual'), fn ($v) => $v !== null));
        $sinCambio = count(array_filter($filas, fn ($f) => $f['actual'] !== null && round($f['actual'], 4) === round((float) $nuevo, 4)));

        $firma = json_encode([$ids, $nuevo, array_map(fn ($f) => [$f['id'], $f['actual']], $filas)]);

        return new VistaPreviaCostos(
            ids: $ids,
            nuevoCosto: $nuevo,
            filas: $filas,
            conCambio: count($filas) - $sinCambio,
            sinCambio: $sinCambio,
            sinCostoActual: count($filas) - count($actuales),
            actualMinimo: $actuales === [] ? null : min($actuales),
            actualMaximo: $actuales === [] ? null : max($actuales),
            token: hash_hmac('sha256', $firma, (string) config('app.key')),
        );
    }

    protected function exigirPermiso(?AdminContract $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_COSTOS_ACTUALIZAR)) {
            throw new Invalido(Invalido::SIN_PERMISO, 'No tiene permiso para actualizar costos.');
        }
    }

    /** Decimal > 0, hasta 8 enteros y 2 decimales, con punto. Devuelve el valor normalizado como texto. */
    protected function validarCosto(mixed $valor): string
    {
        $texto = trim((string) $valor);

        if (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', $texto) || (float) $texto <= 0) {
            throw new Invalido(Invalido::COSTO_INVALIDO, 'El costo nuevo debe ser un valor mayor que 0 (hasta 2 decimales).');
        }

        return number_format((float) $texto, 2, '.', '');
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    protected function validarIds(array $ids): array
    {
        $limpios = array_values(array_unique(array_filter(array_map(fn ($id) => ctype_digit((string) $id) ? (int) $id : 0, $ids))));
        sort($limpios);

        if ($limpios === []) {
            throw new Invalido(Invalido::SELECCION_VACIA, 'Seleccione al menos una variante.');
        }

        if (count($limpios) > self::MAX_SELECCION) {
            throw new Invalido(Invalido::SELECCION_EXCESIVA, 'Seleccione como maximo '.self::MAX_SELECCION.' variantes por actualizacion.');
        }

        return $limpios;
    }
}
