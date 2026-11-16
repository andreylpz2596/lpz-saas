<?php

namespace ByLopez\Cargues\Extensiones\InventarioConteo;

use ByLopez\Cargues\Contracts\ExtensionTipo;
use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Extensiones\Concerns\AplicaPorFila;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use ByLopez\Cargues\Support\Estados;
use ByLopez\CorteConfeccion\Services\InventarioMovimientoService;
use Closure;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webkul\User\Models\Admin;

/**
 * Extension del tipo «Inventario — Actualizar cantidades (conteo fisico)». Fija el stock de variantes EXISTENTES en la cantidad del
 * archivo; NUNCA crea productos (los productos nuevos se cargan con el tipo de productos).
 *
 *  derivar(): al PREPARAR la carga (solo lectura) cruza cada fila con el catalogo por ID y/o SKU, calcula el stock vigente de la bodega y la
 *             diferencia, y deja el plan o el motivo de bloqueo. El analisis es del archivo COMPLETO (un producto repetido bloquea todas sus filas).
 *  aplicar(): tras el plan del procedimiento, UNA TRANSACCION POR PRODUCTO con la marca de la fila dentro de ella. Escribe SOLO por el libro de
 *             inventario de CorteConfeccion (`registrarConteoFisico`: stock fisico + indice vendible + movimiento auditado con cantidad anterior,
 *             nueva, usuario y cargue). Idempotente: la clave del movimiento es fija por cargue y producto.
 *
 * Reglas del cruce: ID y SKU, si vienen los dos, deben apuntar al MISMO producto (si no, se bloquea: no se adivina). Los configurables se ignoran
 * (su cantidad es la suma de sus variantes). Solo `simple`. Bodega: la unica fuente activa del canal por defecto (la misma del ajuste manual).
 * El actor es el administrador que creo la carga. No toca precios, estados, atributos ni pedidos.
 */
class InventarioConteoExtension implements ExtensionTipo
{
    use AplicaPorFila, DetectsLostConnections;

    public const ORIGEN = 'cargue_inventario';

    /** @var array<int, array<int, array<string,mixed>>> carga_id => (carga_dato_id => derivados) */
    protected array $analisis = [];

    public function __construct(protected ConexionTipo $conexiones) {}

    // ---------------------------------------------------------------------------------------------------------------------
    // Derivar (solo lectura)
    // ---------------------------------------------------------------------------------------------------------------------

    public function derivar(Carga $carga, array $filas): array
    {
        $this->exigirModulo();
        $this->analisis[$carga->id] ??= $this->analizarCarga($carga);

        return array_intersect_key($this->analisis[$carga->id], $filas);
    }

    /** @return array<int, array<string,mixed>> */
    protected function analizarCarga(Carga $carga): array
    {
        $datos = DB::table('bylopez_cg_carga_datos')->where('carga_id', $carga->id)->where('estado_final', Estados::FILA_PENDIENTE)->orderBy('fila_origen')
            ->get(['id', 'fila_origen', 'datos_normalizados']);
        $filas = [];

        foreach ($datos as $d) {
            $filas[(int) $d->id] = (array) (json_decode((string) $d->datos_normalizados, true)['valores'] ?? []);
        }

        $bodega = $this->bodega();

        return $this->analizar($filas, $bodega['id'], $bodega['error']);
    }

    /**
     * @param  array<int, array<string,mixed>>  $filas  cg_carga_dato_id => valores del archivo
     * @return array<int, array<string,mixed>>
     */
    public function analizar(array $filas, ?int $bodegaId, ?string $errorBodega = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($v) => self::entero($v['id_producto'] ?? null), $filas))));
        $skus = array_values(array_unique(array_filter(array_map(fn ($v) => self::texto($v['sku'] ?? null), $filas), fn ($s) => $s !== null)));
        $productos = DB::table('products')->where(function ($q) use ($ids, $skus) {
            $ids === [] || $q->whereIn('id', $ids);
            $skus === [] || $q->orWhereIn('sku', $skus);
        })->get(['id', 'sku', 'type']);
        $porId = $productos->keyBy('id');
        $porSku = $productos->groupBy(fn ($p) => mb_strtolower((string) $p->sku));
        $nombres = $this->nombres($productos->pluck('id')->all());
        $stock = $bodegaId === null ? collect() : DB::table('product_inventories')->where('inventory_source_id', $bodegaId)->where('vendor_id', 0)
            ->whereIn('product_id', $productos->pluck('id'))->pluck('qty', 'product_id');

        // 1) cruce fila → producto
        $cruce = [];

        foreach ($filas as $cgId => $v) {
            $cruce[$cgId] = $this->cruzar($v, $porId, $porSku);
        }

        // 2) un producto en varias filas: se bloquean todas (no se adivina cual vale)
        $repetidos = [];

        foreach ($cruce as $c) {
            if ($c['producto'] !== null && $c['plan'] !== 'bloqueo') {
                $repetidos[(int) $c['producto']->id] = ($repetidos[(int) $c['producto']->id] ?? 0) + 1;
            }
        }

        $salida = [];

        foreach ($filas as $cgId => $v) {
            $c = $cruce[$cgId];
            $p = $c['producto'];
            $fila = $p === null ? [] : [
                'producto_id' => (int) $p->id, 'producto_sku' => (string) $p->sku, 'producto_nombre' => $nombres[(int) $p->id] ?? null,
            ];
            $r = $fila + ['fila_plan' => null, 'bloqueo_codigo' => null, 'bloqueo_mensaje' => null, 'stock_actual' => null, 'diferencia' => null];

            if ($c['plan'] === 'bloqueo') {
                [$r['bloqueo_codigo'], $r['bloqueo_mensaje']] = $c['motivo'];
                $r['fila_plan'] = 'bloqueo';
            } elseif ($c['plan'] === 'ignorar') {
                $r['fila_plan'] = 'ignorar';
            } elseif ($repetidos[(int) $p->id] > 1) {
                $r['fila_plan'] = 'bloqueo';
                $r['bloqueo_codigo'] = 'PRODUCTO_REPETIDO';
                $r['bloqueo_mensaje'] = 'El producto «'.$p->sku.'» aparece '.$repetidos[(int) $p->id].' veces en el archivo con cantidades que pueden diferir: no se sabe cuál es la correcta. No se aplicó ninguna de sus filas.';
            } elseif ($bodegaId === null) {
                $r['fila_plan'] = 'bloqueo';
                $r['bloqueo_codigo'] = 'BODEGA_NO_RESUELTA';
                $r['bloqueo_mensaje'] = (string) $errorBodega;
            } else {
                $actual = (int) ($stock[(int) $p->id] ?? 0);
                $cantidad = self::entero($v['cantidad'] ?? null);
                $r['stock_actual'] = $actual;
                $r['diferencia'] = $cantidad - $actual;
                $r['fila_plan'] = $cantidad === $actual ? 'sin_cambio' : 'actualizar';
            }

            $salida[$cgId] = $r;
        }

        return $salida;
    }

    /**
     * @return array{plan:string, producto:?object, motivo?:array{0:string,1:string}}
     */
    protected function cruzar(array $v, $porId, $porSku): array
    {
        $id = self::entero($v['id_producto'] ?? null);
        $sku = self::texto($v['sku'] ?? null);
        $cantidad = $v['cantidad'] ?? null;
        $bloqueo = fn (string $codigo, string $mensaje, ?object $p = null) => ['plan' => 'bloqueo', 'producto' => $p, 'motivo' => [$codigo, $mensaje]];

        if ($id === null && $sku === null) {
            return $bloqueo('NO_IDENTIFICA', 'La fila no trae ni ID ni SKU: no se sabe qué producto es.');
        }

        $porIdEncontrado = $id === null ? null : $porId->get($id);
        $porSkuEncontrado = null;

        if ($sku !== null) {
            $coinciden = $porSku->get(mb_strtolower($sku), collect());
            $porSkuEncontrado = $coinciden->count() === 1 ? $coinciden->first() : null;

            if ($coinciden->count() > 1) {
                return $bloqueo('SKU_AMBIGUO', 'El SKU «'.$sku.'» corresponde a más de un producto del sistema.');
            }
        }

        if ($id !== null && $porIdEncontrado === null && $sku === null) {
            return $bloqueo('PRODUCTO_NO_ENCONTRADO', 'No existe un producto con ID '.$id.' en el sistema. Este cargue no crea productos.');
        }

        if ($sku !== null && $porSkuEncontrado === null && $id === null) {
            return $bloqueo('PRODUCTO_NO_ENCONTRADO', 'No existe un producto con SKU «'.$sku.'» en el sistema. Este cargue no crea productos.');
        }

        if ($id !== null && $sku !== null) {
            if ($porIdEncontrado === null && $porSkuEncontrado === null) {
                return $bloqueo('PRODUCTO_NO_ENCONTRADO', 'No existe ningún producto con ID '.$id.' ni con SKU «'.$sku.'». Este cargue no crea productos.');
            }

            if ($porIdEncontrado !== null && $porSkuEncontrado !== null && (int) $porIdEncontrado->id !== (int) $porSkuEncontrado->id) {
                return $bloqueo('ID_SKU_DISTINTOS', 'El ID '.$id.' es del producto «'.$porIdEncontrado->sku.'» pero el SKU «'.$sku.'» es de otro producto (ID '.$porSkuEncontrado->id.'). No se adivina cuál es: corrija la fila.');
            }

            if ($porIdEncontrado === null || $porSkuEncontrado === null) {
                // Uno coincide y el otro no existe: el SKU de la fila quedo desactualizado o el ID es de otro entorno. Se bloquea: seria adivinar.
                $encontrado = $porIdEncontrado ?? $porSkuEncontrado;

                return $bloqueo('ID_SKU_NO_COINCIDEN', $porIdEncontrado === null
                    ? 'El ID '.$id.' no existe, aunque el SKU «'.$sku.'» sí (ID '.$encontrado->id.'). No se adivina cuál es: corrija la fila.'
                    : 'El SKU «'.$sku.'» no existe, aunque el ID '.$id.' sí (producto «'.$encontrado->sku.'»). No se adivina cuál es: corrija la fila.', $encontrado);
            }
        }

        $p = $porIdEncontrado ?? $porSkuEncontrado;

        if ($p->type === 'configurable') {
            return ['plan' => 'ignorar', 'producto' => $p];
        }

        if ($p->type !== 'simple') {
            return $bloqueo('TIPO_NO_SOPORTADO', 'El producto «'.$p->sku.'» es de tipo «'.$p->type.'»: este cargue solo actualiza productos simples (variantes).', $p);
        }

        if ($cantidad === null || $cantidad === '') {
            return $bloqueo('SIN_CANTIDAD', 'La fila no trae cantidad. Para dejar un producto en cero escriba 0.', $p);
        }

        if ((int) $cantidad < 0) {
            return $bloqueo('CANTIDAD_NEGATIVA', 'La cantidad no puede ser negativa ('.(int) $cantidad.').', $p);
        }

        return ['plan' => 'ok', 'producto' => $p];
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Aplicar (libro de inventario de CorteConfeccion, una transaccion por producto)
    // ---------------------------------------------------------------------------------------------------------------------

    public function aplicar(Carga $carga, array $map, Closure $progreso): void
    {
        $this->exigirModulo();
        $cfg = (array) $carga->config_congelada;
        $tabla = (string) $map['tabla'];
        $this->exigirMismaBase((string) $cfg['conexion']);
        $actor = $this->actor($carga);
        $bodega = $this->bodega();

        $total = (int) DB::table($tabla)->where('cg_carga_id', $carga->id)->whereNull('cg_estado')->count();
        $hechas = 0;
        $progreso(0, $total);

        if ($bodega['id'] === null) {
            throw new OperacionInvalidaException('BODEGA_NO_RESUELTA', (string) $bodega['error'].' No se aplicó nada.');
        }

        DB::table($tabla)->where('cg_carga_id', $carga->id)->whereNull('cg_estado')->orderBy('cg_fila_origen')->chunkById(200, function ($lote) use ($carga, $tabla, $actor, $bodega, $total, &$hechas, $progreso) {
            foreach ($lote as $f) {
                try {
                    DB::transaction(fn () => $this->aplicarFila($carga, $tabla, $f, $actor, (int) $bodega['id']));
                } catch (Throwable $e) {
                    $this->clasificarFallo($e, $tabla, $f);
                }

                $progreso(++$hechas, $total);
            }
        }, 'cg_id');
    }

    protected function aplicarFila(Carga $carga, string $tabla, object $f, Admin $actor, int $bodegaId): void
    {
        $producto = DB::table('products')->where('id', (int) $f->producto_id)->lockForUpdate()->first(['id', 'sku', 'type']);

        if (! $producto || $producto->type !== 'simple') {
            $this->marcar($tabla, (int) $f->cg_id, Estados::FILA_RECHAZADA, null, 'PRODUCTO_NO_ENCONTRADO', 'El producto ya no existe o dejó de ser una variante simple. No se aplicó nada.');

            return;
        }

        $movimiento = app(InventarioMovimientoService::class)->registrarConteoFisico([
            'product_id' => (int) $producto->id,
            'inventory_source_id' => $bodegaId,
            'cantidad_objetivo' => (int) $f->cantidad,
            'motivo' => 'Conteo físico — cargue #'.$carga->id.', fila '.$f->cg_fila_origen,
            'idempotency_key' => 'cargue-inventario:'.$carga->id.':'.$producto->id,
            'origen_tipo' => self::ORIGEN,
            'origen_id' => (int) $carga->id,
            'admin_id' => (int) $actor->id,
            'admin_nombre' => (string) $actor->name,
        ]);

        $movimiento === null
            ? $this->marcar($tabla, (int) $f->cg_id, Estados::FILA_SIN_CAMBIO, 'ya_en_cantidad', 'YA_EN_CANTIDAD', 'El sistema ya tenía esa cantidad. No se modificó nada.')
            : $this->marcar($tabla, (int) $f->cg_id, Estados::FILA_APLICADA, 'actualizado', null,
                'Stock '.$movimiento->cantidad_anterior.' → '.$movimiento->cantidad_nueva.' ('.($movimiento->cantidad_movimiento > 0 ? '+' : '').$movimiento->cantidad_movimiento.'). Movimiento #'.$movimiento->id.'.');
    }

    /** Una conexion perdida sube (la carga queda reanudable y la fila SIN marca); cualquier otro fallo rechaza solo esa fila. */
    protected function clasificarFallo(Throwable $e, string $tabla, object $f): void
    {
        if ($this->causedByLostConnection($e)) {
            throw $e;
        }

        if ($e instanceof ValidationException) {
            $this->marcar($tabla, (int) $f->cg_id, Estados::FILA_RECHAZADA, null, 'CONTEO_RECHAZADO', mb_substr(collect($e->errors())->flatten()->implode(' '), 0, 500));

            return;
        }

        report($e);
        $this->marcar($tabla, (int) $f->cg_id, Estados::FILA_RECHAZADA, null, $e instanceof QueryException ? 'ERROR_BD' : 'ERROR_INESPERADO',
            'Error '.($e instanceof QueryException ? 'de base de datos' : 'inesperado').' ('.class_basename($e).'): no se aplicó nada de este producto.');
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Apoyo
    // ---------------------------------------------------------------------------------------------------------------------

    protected function exigirModulo(): void
    {
        if (! class_exists(InventarioMovimientoService::class)) {
            throw new OperacionInvalidaException('EXTENSION_MODULO_AUSENTE', 'Este tipo escribe por el libro de inventario de Corte y Confección, que no está instalado.');
        }
    }

    /** @return array{id:?int, error:?string} */
    protected function bodega(): array
    {
        try {
            return ['id' => (int) app(InventarioMovimientoService::class)->resolverFuenteInventario()->id, 'error' => null];
        } catch (ValidationException $e) {
            return ['id' => null, 'error' => collect($e->errors())->flatten()->implode(' ')];
        }
    }

    /** Quien registra los movimientos: el administrador que creo la carga (Cargues es exclusivo del administrador). */
    protected function actor(Carga $carga): Admin
    {
        $admin = Admin::query()->find((int) $carga->admin_id);

        if (! $admin || (int) $admin->role_id !== (int) config('cargues.rol_administrador_id') || ! $admin->status) {
            throw new OperacionInvalidaException('ACTOR_NO_ADMINISTRADOR', 'Los movimientos de este cargue los registra el administrador que creó la carga, y esa cuenta ya no es un administrador activo. No se aplicó nada.');
        }

        return $admin;
    }

    /** @return array<int, string> product_id => nombre (idioma por defecto) */
    protected function nombres(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('product_flat')->whereIn('product_id', $ids)->whereNotNull('name')->orderBy('id')->pluck('name', 'product_id')->map(fn ($n) => (string) $n)->all();
    }

    protected static function entero(mixed $v): ?int
    {
        return $v === null || $v === '' || ! is_numeric($v) ? null : (int) $v;
    }

    protected static function texto(mixed $v): ?string
    {
        $t = trim((string) $v);

        return $t === '' ? null : $t;
    }
}
