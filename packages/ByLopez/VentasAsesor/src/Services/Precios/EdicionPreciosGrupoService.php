<?php

namespace ByLopez\VentasAsesor\Services\Precios;

use ByLopez\VentasAsesor\DataObjects\SolicitudPrecioGrupo as Solicitud;
use ByLopez\VentasAsesor\DataObjects\VistaPreviaPreciosGrupo as Vista;
use ByLopez\VentasAsesor\Exceptions\PrecioGrupoInvalidoException as Invalido;
use ByLopez\VentasAsesor\Models\CambioPrecioGrupo;
use ByLopez\VentasAsesor\Models\LotePrecioGrupo;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Costos\CostoComisionableCatalogo;
use ByLopez\VentasAsesor\Support\Precios\EvaluacionPrecioGrupo;
use ByLopez\VentasAsesor\Support\Precios\MotorPrecioGrupo;
use ByLopez\VentasAsesor\Support\Precios\PrecioGrupoMaximo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Product\Contracts\Product;
use Webkul\Product\Jobs\ElasticSearch\UpdateCreateIndex as ReindexarBusqueda;
use Webkul\Product\Jobs\UpdateCreatePriceIndex as ReindexarPrecios;
use Webkul\Product\Models\ProductCustomerGroupPriceProxy;
use Webkul\Product\Models\ProductProxy;
use Webkul\Product\Repositories\ProductCustomerGroupPriceRepository;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * «Costos y precios › Precios por grupo» (Doc. 32): EDICION individual o masiva de las reglas de precio por grupo de
 * Bagisto. Seleccion → vista previa → confirmacion → bitacora → reindexacion. Nunca automatica.
 *
 * - Fuente unica: `product_customer_group_prices` (repositorio de Bagisto, mismo `unique_id` que la ficha del producto).
 *   Solo variantes `simple`: el precio que cobra el carrito es el de la variante (una regla en el padre no lo cambia).
 * - Toca SOLO las filas (variante, grupo elegido, escalon elegido). Otros grupos, otros escalones y las filas de «todos
 *   los grupos» no se modifican. Un valor vacio nunca se convierte en 0.
 * - La vista previa calcula el precio con el indexador de Bagisto sobre las reglas propuestas EN MEMORIA (MotorPrecioGrupo):
 *   lo que muestra es lo que cobrara Nueva venta. Cancelarla no escribe nada.
 * - Confirmar: primero los BLOQUEOS (variantes y sus reglas) y despues las lecturas, para que la transaccion lea el estado
 *   vigente y no una foto anterior; se recalcula la vista y se compara su token. Si otro usuario (u otra pestana, o la
 *   ficha de Bagisto) cambio algo: `conflicto`, sin escribir. Todo el lote en UNA transaccion: o se aplica completo o
 *   nada. Filas con error bloquean la confirmacion.
 * - Idempotente: el token confirmado es UNICO en la bitacora; reenviar el mismo formulario devuelve el lote ya aplicado.
 * - Despues del commit se reindexan precios con el job de Bagisto (con cantidad 1 el carrito lee el indice) y se olvida
 *   la cache de paginas de la tienda. Si la reindexacion falla, el lote queda con `indice_estado = error` y se informa:
 *   los precios ya estan guardados y con 2 o mas unidades ya rigen.
 * - Permisos PROPIOS (validados aqui, no solo en la ruta): ver = `ventas_asesor.precios_grupo`; editar = ademas
 *   `ventas_asesor.precios_grupo.editar`. Costo y diferencia solo con `ventas_asesor.costos` (separado).
 * - Pedidos confirmados y comisiones no cambian (salen de `order_items`). Un borrador abierto se reprecia al operar o al
 *   confirmar, y si el total cambio la confirmacion se rechaza (`totales_cambiaron`) para que el asesor lo revise.
 */
class EdicionPreciosGrupoService
{
    public const MAX_VARIANTES = 1000;

    public const MAX_VALOR = 99999999.99;

    private const TOLERANCIA = 0.00005;

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected ConsultaPreciosGrupoService $consulta,
        protected MotorPrecioGrupo $motor,
        protected CostoComisionableCatalogo $costos,
        protected ProductCustomerGroupPriceRepository $reglas,
    ) {}

    public function puedeVer(?AdminContract $actor): bool
    {
        return $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_PRECIOS_GRUPO);
    }

    /**
     * Editar = ver + la hoja `ventas_asesor.precios_grupo.editar` (la misma que el Bouncer exige en las rutas de vista
     * previa, confirmacion y reindexacion). No exige `catalog.products.edit`: editar precios por grupo no debe obligar a dar
     * la edicion completa de productos de Bagisto (base, nombre, stock…). Tampoco da costos ni margenes.
     */
    public function puedeEditar(?AdminContract $actor): bool
    {
        return $this->puedeVer($actor)
            && $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_PRECIOS_GRUPO_EDITAR);
    }

    public function puedeVerCostos(?AdminContract $actor): bool
    {
        return $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_COSTOS);
    }

    /**
     * Valida y normaliza la entrada del formulario.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function solicitud(array $entrada): Solicitud
    {
        $grupo = $this->consulta->grupo(ctype_digit((string) ($entrada['grupo'] ?? '')) ? (int) $entrada['grupo'] : null)
            ?? throw new Invalido(Invalido::GRUPO_INVALIDO, 'Seleccione un grupo de clientes existente.');

        $operacion = (string) ($entrada['operacion'] ?? '');

        if (! in_array($operacion, Solicitud::OPERACIONES, true)) {
            throw new Invalido(Invalido::OPERACION_INVALIDA, 'Elija una accion valida.');
        }

        $qtyTexto = trim((string) ($entrada['qty'] ?? ''));

        if (! preg_match('/^\d{1,4}$/', $qtyTexto) || (int) $qtyTexto < 1) {
            throw new Invalido(Invalido::CANTIDAD_INVALIDA, 'La cantidad minima del escalon debe ser un entero entre 1 y 9999.');
        }

        $tipo = null;
        $valor = null;
        $modo = null;
        $redondeo = 1;

        switch ($operacion) {
            case Solicitud::FIJO:
                [$tipo, $valor] = ['fixed', $this->valorFijo($entrada['valor'] ?? null)];
                break;
            case Solicitud::PORCENTAJE:
                [$tipo, $valor] = ['discount', $this->valorPorcentaje($entrada['valor'] ?? null)];
                break;
            case Solicitud::COMPLETAR:
                $tipo = ($entrada['tipo'] ?? '') === 'discount' ? 'discount' : (($entrada['tipo'] ?? '') === 'fixed' ? 'fixed' : null);
                $tipo ?? throw new Invalido(Invalido::OPERACION_INVALIDA, 'Elija si completa con precio fijo o con descuento %.');
                $valor = $tipo === 'fixed' ? $this->valorFijo($entrada['valor'] ?? null) : $this->valorPorcentaje($entrada['valor'] ?? null);
                break;
            case Solicitud::AJUSTAR:
                $modo = in_array($entrada['modo_ajuste'] ?? null, [Solicitud::AJUSTE_IMPORTE, Solicitud::AJUSTE_PORCENTAJE], true)
                    ? $entrada['modo_ajuste']
                    : throw new Invalido(Invalido::OPERACION_INVALIDA, 'Elija si ajusta por importe o por porcentaje.');
                $valor = $this->valorAjuste($entrada['valor'] ?? null, $modo);
                $pedido = (int) ($entrada['redondeo'] ?? 1);
                $redondeo = in_array($pedido, Solicitud::REDONDEOS, true) ? $pedido : 1;
                break;
        }

        $ids = $this->ids((array) ($entrada['ids'] ?? []));
        $motivo = ($m = trim((string) ($entrada['motivo'] ?? ''))) === '' ? null : mb_substr($m, 0, 500);

        if (count($ids) > 1 && $motivo === null) {
            throw new Invalido(Invalido::MOTIVO_REQUERIDO, 'Escriba el motivo de la edicion masiva.');
        }

        return new Solicitud(
            grupoId: (int) $grupo->id,
            operacion: $operacion,
            qty: (int) $qtyTexto,
            tipo: $tipo,
            valor: $valor,
            modoAjuste: $modo,
            redondeo: $redondeo,
            ids: $ids,
            alcance: ($entrada['alcance'] ?? '') === 'filtro' ? 'filtro' : 'seleccion',
            filtros: array_filter((array) ($entrada['filtros'] ?? []), fn ($v, $k) => $v !== null && $v !== '' && $v !== false && ! ($k === 'qty' && (int) $v === 1), ARRAY_FILTER_USE_BOTH),
            motivo: $motivo,
        );
    }

    public function previsualizar(?AdminContract $actor, Solicitud $s): Vista
    {
        $this->exigirEdicion($actor);

        return $this->construir($s, $this->puedeVerCostos($actor));
    }

    /**
     * @return array{lote: LotePrecioGrupo, ya_aplicado: bool}
     *
     * @throws Invalido conflicto (con `detalles['vista']`), filas_con_error, sin_cambios, sin_permiso
     */
    public function confirmar(?AdminContract $actor, Solicitud $s, string $token): array
    {
        $this->exigirEdicion($actor);
        $conCostos = $this->puedeVerCostos($actor);

        try {
            $resultado = DB::transaction(function () use ($actor, $s, $token, $conCostos) {
                // 1) Bloqueos ANTES de cualquier lectura: la foto de la transaccion se toma despues de obtenerlos, asi que
                //    una confirmacion que espero a otra lee lo que la otra dejo (y detecta el conflicto).
                DB::table('products')->whereIn('id', $s->ids)->orderBy('id')->lockForUpdate()->pluck('id');
                DB::table('product_customer_group_prices')->whereIn('product_id', $s->ids)->orderBy('id')->lockForUpdate()->pluck('id');

                if ($existente = LotePrecioGrupo::query()->where('token', $token)->first()) {
                    return ['lote' => $existente, 'ya_aplicado' => true];
                }

                $vista = $this->construir($s, $conCostos);

                if (! hash_equals($vista->token, $token)) {
                    throw new Invalido(Invalido::CONFLICTO, 'Los precios cambiaron desde la vista previa. Revise la vista previa actualizada antes de confirmar.', ['vista' => $vista]);
                }

                if ($vista->conteo[Vista::ERROR] > 0) {
                    throw new Invalido(Invalido::FILAS_CON_ERROR, 'Hay filas con error: no se aplico ningun cambio.', ['vista' => $vista]);
                }

                if ($vista->cambios() === 0) {
                    throw new Invalido(Invalido::SIN_CAMBIOS, 'No hay nada que crear, actualizar ni quitar.', ['vista' => $vista]);
                }

                return ['lote' => $this->aplicar($actor, $s, $vista), 'ya_aplicado' => false];
            });
        } catch (QueryException $e) {
            // Doble envio simultaneo con el MISMO token: el indice unico de la bitacora frena al segundo.
            if (($e->errorInfo[1] ?? null) === 1062 && ($lote = LotePrecioGrupo::query()->where('token', $token)->first())) {
                return ['lote' => $lote, 'ya_aplicado' => true];
            }

            throw $e;
        }

        if (! $resultado['ya_aplicado']) {
            $this->reindexar($resultado['lote'], $s->ids);
        }

        return ['lote' => $resultado['lote']->fresh(), 'ya_aplicado' => $resultado['ya_aplicado']];
    }

    /** Recuperacion desde la pantalla: reintenta la reindexacion de las variantes cambiadas en un lote. */
    public function reindexarLote(?AdminContract $actor, LotePrecioGrupo $lote): LotePrecioGrupo
    {
        $this->exigirEdicion($actor);

        $ids = $lote->cambios()->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids !== []) {
            $this->reindexar($lote, $ids);
        }

        return $lote->fresh();
    }

    /** Reindexacion de precios de Bagisto (variantes y padres) y cache de paginas de la tienda. Publica para reintentar. */
    public function reindexar(LotePrecioGrupo $lote, array $ids): void
    {
        $padres = DB::table('products')->whereIn('id', $ids)->whereNotNull('parent_id')->distinct()->pluck('parent_id')->map(fn ($id) => (int) $id)->all();
        $todos = array_values(array_unique([...$ids, ...$padres]));

        try {
            ReindexarPrecios::dispatchSync($todos);
            ReindexarBusqueda::dispatchSync($todos);
            $this->olvidarCacheTienda(array_merge($padres, DB::table('products')->whereIn('id', $ids)->whereNull('parent_id')->pluck('id')->all()));

            $lote->update(['indice_estado' => LotePrecioGrupo::INDICE_ACTUALIZADO, 'indice_error' => null]);
        } catch (Throwable $e) {
            report($e);
            $lote->update(['indice_estado' => LotePrecioGrupo::INDICE_ERROR, 'indice_error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    protected function aplicar(?AdminContract $actor, Solicitud $s, Vista $vista): LotePrecioGrupo
    {
        $lote = LotePrecioGrupo::query()->create([
            'lote' => (string) Str::uuid(),
            'token' => $vista->token,
            'customer_group_id' => $s->grupoId,
            'grupo_nombre_snapshot' => $vista->grupo['nombre'],
            'operacion' => $s->operacion,
            'parametros' => $s->parametros(),
            'alcance' => $s->alcance,
            'filtros' => $s->filtros === [] ? null : $s->filtros,
            'variantes' => count($s->ids),
            'creadas' => $vista->conteo[Vista::CREAR],
            'actualizadas' => $vista->conteo[Vista::ACTUALIZAR],
            'quitadas' => $vista->conteo[Vista::QUITAR],
            'sin_cambio' => $vista->conteo[Vista::SIN_CAMBIO],
            'indice_estado' => LotePrecioGrupo::INDICE_PENDIENTE,
            'actor_admin_id' => $actor?->id ? (int) $actor->id : null,
            'actor_nombre_snapshot' => $actor?->name,
            'motivo' => $s->motivo,
        ]);

        foreach ($vista->filas as $f) {
            if (! in_array($f['accion'], [Vista::CREAR, Vista::ACTUALIZAR, Vista::QUITAR], true)) {
                continue;
            }

            $datos = [
                'qty' => $s->qty,
                'value_type' => $f['nueva']['tipo'] ?? null,
                'value' => $f['nueva']['valor'] ?? null,
                'customer_group_id' => $s->grupoId,
                // Mismo formato que ProductCustomerGroupPriceRepository::saveCustomerGroupPrices (sin partes vacias).
                'unique_id' => implode('|', array_filter([$s->qty, $f['id'], $s->grupoId])),
            ];

            match ($f['accion']) {
                Vista::CREAR => $this->reglas->create(['product_id' => $f['id']] + $datos),
                Vista::ACTUALIZAR => $this->reglas->update($datos, $f['regla_id']),
                Vista::QUITAR => $this->reglas->delete($f['regla_id']),
            };

            CambioPrecioGrupo::query()->create([
                'lote_id' => $lote->id,
                'product_id' => $f['id'],
                'sku_snapshot' => $f['sku'],
                'customer_group_id' => $s->grupoId,
                'qty' => $s->qty,
                'accion' => $f['accion'],
                'tipo_anterior' => $f['anterior']['tipo'] ?? null,
                'valor_anterior' => $f['anterior']['valor'] ?? null,
                'tipo_nuevo' => $f['nueva']['tipo'] ?? null,
                'valor_nuevo' => $f['nueva']['valor'] ?? null,
                'precio_base_snapshot' => $f['base'],
                'precio_anterior' => $f['precio_anterior'],
                'precio_resultante' => $f['precio_nuevo'],
            ]);
        }

        return $lote;
    }

    protected function construir(Solicitud $s, bool $conCostos): Vista
    {
        $grupo = $this->consulta->grupo($s->grupoId) ?? throw new Invalido(Invalido::GRUPO_INVALIDO, 'El grupo ya no existe.');
        $variantes = $this->consulta->variantes($s->ids);

        if ($variantes->count() !== count($s->ids)) {
            throw new Invalido(Invalido::PRODUCTO_NO_VALIDO, 'Solo se pueden editar variantes (productos simples) existentes.');
        }

        $catalogo = $this->consulta->datosDeCatalogo($variantes);
        $costos = $conCostos ? $this->costos->costosDe($s->ids) : [];
        $filas = [];
        $estados = [];

        foreach ($s->ids as $id) {
            $v = $variantes[$id];
            $estados[$id] = $this->estado($v, $s->grupoId);
            $filas[] = $this->fila($s, $grupo, $v, $catalogo, $conCostos ? ($costos[$id] ?? null) : null);
        }

        usort($filas, fn ($a, $b) => [$a['referencia'], $a['talla_orden'], $a['sku']] <=> [$b['referencia'], $b['talla_orden'], $b['sku']]);

        $conteo = array_fill_keys([Vista::CREAR, Vista::ACTUALIZAR, Vista::QUITAR, Vista::SIN_CAMBIO, Vista::ERROR], 0);

        foreach ($filas as $f) {
            $conteo[$f['accion']]++;
        }

        return new Vista(
            solicitud: $s,
            grupo: ['id' => (int) $grupo->id, 'nombre' => (string) $grupo->name],
            filas: $filas,
            conteo: $conteo,
            borradoresAbiertos: $this->borradoresAbiertos($s->grupoId, $s->ids),
            conCostos: $conCostos,
            token: hash_hmac('sha256', json_encode([$s->grupoId, $s->parametros(), $s->ids, $estados]), (string) config('app.key')),
        );
    }

    /** @return array<string, mixed> */
    protected function fila(Solicitud $s, object $grupo, Product $v, array $catalogo, ?float $costo): array
    {
        $d = $catalogo['variantes'][(int) $v->id];
        $ref = $catalogo['referencias'][$d['ref']];
        $todas = $v->customer_group_prices->values();
        $delEscalon = $todas->filter(fn ($r) => $r->customer_group_id !== null && (int) $r->customer_group_id === $s->grupoId && (int) $r->qty === $s->qty)->values();
        $existente = $delEscalon->first();

        $fila = [
            'id' => (int) $v->id,
            'sku' => (string) $v->sku,
            'nombre' => $d['nombre'],
            'referencia' => $ref['nombre'],
            'color' => $d['color'],
            'talla' => $d['talla'],
            'talla_orden' => $d['talla_orden'],
            'regla_id' => $existente ? (int) $existente->id : null,
            'anterior' => $existente ? ['tipo' => (string) $existente->value_type, 'valor' => (float) $existente->value] : null,
            'nueva' => null,
            'accion' => Vista::SIN_CAMBIO,
            'motivo' => null,
            'advertencias' => [],
            'costo' => $costo,
        ];

        $antes = $this->motor->evaluar($v, $grupo, $s->qty);
        $fila['base'] = $antes->base;
        $fila['precio_anterior'] = $antes->final;
        $fila['precio_nuevo'] = $antes->final;
        $fila['origen_nuevo'] = $antes;

        if ($delEscalon->count() > 1) {
            return array_merge($fila, ['accion' => Vista::ERROR, 'motivo' => 'duplicado']);
        }

        $decision = $this->decidir($s, $existente, $antes->base);
        [$accion, $nueva, $motivo] = $decision;
        $fila['valor_calculado'] = $decision[3] ?? null;

        // Regla ByLopez: un fijo de grupo no puede ser mayor que el precio base de la variante (PrecioGrupoMaximo).
        if (in_array($accion, [Vista::CREAR, Vista::ACTUALIZAR], true) && $nueva !== null
            && PrecioGrupoMaximo::excede($nueva['tipo'], $nueva['valor'], $antes->base)) {
            [$accion, $motivo] = [Vista::ERROR, 'mayor_que_base'];
        }
        $fila = array_merge($fila, ['accion' => $accion, 'nueva' => $nueva, 'motivo' => $motivo]);

        if (! in_array($accion, [Vista::CREAR, Vista::ACTUALIZAR, Vista::QUITAR], true)) {
            if ($nueva === null && $existente) {
                $fila['nueva'] = $fila['anterior'];
            }

            return $fila;
        }

        $propuestas = match ($accion) {
            Vista::CREAR => $todas->concat([$this->reglaNueva($v, $s, $nueva)]),
            Vista::ACTUALIZAR => $todas->map(fn ($r) => $r->is($existente) ? (clone $r)->forceFill(['value_type' => $nueva['tipo'], 'value' => $nueva['valor']]) : $r),
            Vista::QUITAR => $todas->reject(fn ($r) => $r->is($existente)),
        };

        $despues = $this->motor->evaluar($v, $grupo, $s->qty, $propuestas);
        $fila['precio_nuevo'] = $despues->final;
        $fila['origen_nuevo'] = $despues;
        $fila['advertencias'] = $this->advertencias($v, $s, $nueva, $despues, $propuestas, $d, $costo);

        return $fila;
    }

    /** @return array{0: string, 1: ?array{tipo: string, valor: float}, 2: ?string} accion, regla nueva, motivo */
    protected function decidir(Solicitud $s, ?object $existente, float $base): array
    {
        $igual = fn (string $tipo, float $valor) => $existente && $existente->value_type === $tipo && abs((float) $existente->value - $valor) < self::TOLERANCIA;

        switch ($s->operacion) {
            case Solicitud::FIJO:
            case Solicitud::PORCENTAJE:
                $nueva = ['tipo' => $s->tipo, 'valor' => (float) $s->valor];

                return $igual($s->tipo, (float) $s->valor)
                    ? [Vista::SIN_CAMBIO, $nueva, 'igual']
                    : [$existente ? Vista::ACTUALIZAR : Vista::CREAR, $nueva, null];

            case Solicitud::COMPLETAR:
                return $existente
                    ? [Vista::SIN_CAMBIO, null, 'ya_tiene']
                    : [Vista::CREAR, ['tipo' => $s->tipo, 'valor' => (float) $s->valor], null];

            case Solicitud::QUITAR:
                return $existente ? [Vista::QUITAR, null, null] : [Vista::SIN_CAMBIO, null, 'sin_escalon'];

            case Solicitud::AJUSTAR:
                if (! $existente) {
                    return [Vista::SIN_CAMBIO, null, 'sin_escalon'];
                }

                if ($existente->value_type !== 'fixed') {
                    return [Vista::SIN_CAMBIO, null, 'es_porcentaje'];
                }

                $actual = (float) $existente->value;
                $bruto = $s->modoAjuste === Solicitud::AJUSTE_PORCENTAJE
                    ? $actual * (1 + (float) $s->valor / 100)
                    : $actual + (float) $s->valor;
                $nuevo = round(round($bruto / $s->redondeo) * $s->redondeo, 2);

                if ($nuevo <= 0 || $nuevo > self::MAX_VALOR) {
                    return [Vista::ERROR, ['tipo' => 'fixed', 'valor' => $nuevo], 'resultado_invalido', round($bruto, 2)];
                }

                return $igual('fixed', $nuevo)
                    ? [Vista::SIN_CAMBIO, ['tipo' => 'fixed', 'valor' => $nuevo], 'igual', round($bruto, 2)]
                    : [Vista::ACTUALIZAR, ['tipo' => 'fixed', 'valor' => $nuevo], null, round($bruto, 2)];
        }

        throw new Invalido(Invalido::OPERACION_INVALIDA, 'Accion desconocida.');
    }

    /** @return list<array{codigo: string, parametros: array<string, mixed>}> */
    protected function advertencias(Product $v, Solicitud $s, ?array $nueva, EvaluacionPrecioGrupo $despues, Collection $propuestas, array $d, ?float $costo): array
    {
        $avisos = [];
        $aviso = function (string $codigo, array $parametros = []) use (&$avisos) {
            $avisos[] = ['codigo' => $codigo, 'parametros' => $parametros];
        };

        if ($nueva !== null && $nueva['tipo'] === 'fixed' && $nueva['valor'] >= $despues->base - self::TOLERANCIA) {
            $aviso('fijo_no_menor_base', ['base' => $despues->base]);
        }

        if ($nueva !== null && in_array($despues->origen, [EvaluacionPrecioGrupo::ORIGEN_ESPECIAL, EvaluacionPrecioGrupo::ORIGEN_REGLA_CATALOGO], true)) {
            $aviso('gana_'.$despues->origen, ['precio' => $despues->final]);
        }

        $antes = $this->motor->escalonesSinEfecto($v, $s->grupoId);
        $ahora = $this->motor->escalonesSinEfecto($this->motor->conReglas($v, $propuestas), $s->grupoId);
        $nuevos = array_values(array_diff($ahora, $antes));

        if ($nueva !== null && in_array($s->qty, $ahora, true) && ! in_array($s->qty, $nuevos, true)) {
            $nuevos[] = $s->qty;
        }

        if ($nuevos !== []) {
            sort($nuevos);
            $aviso('escalones_sin_efecto', ['escalones' => implode(', ', $nuevos)]);
        }

        if ($v->customer_group_prices->contains(fn ($r) => $r->customer_group_id === null)) {
            $aviso('regla_todos_los_grupos');
        }

        if (! $d['activa']) {
            $aviso('variante_deshabilitada');
        }

        if ($costo !== null && $despues->final < $costo - self::TOLERANCIA) {
            $aviso('bajo_costo', ['costo' => $costo]);
        }

        return $avisos;
    }

    protected function reglaNueva(Product $v, Solicitud $s, array $nueva): object
    {
        return ProductCustomerGroupPriceProxy::modelClass()::make()->forceFill([
            'product_id' => (int) $v->id, 'customer_group_id' => $s->grupoId, 'qty' => $s->qty,
            'value_type' => $nueva['tipo'], 'value' => $nueva['valor'],
        ]);
    }

    /** Lo que, si cambia, invalida la vista previa: reglas del grupo y de «todos», precio base y especial. */
    protected function estado(Product $v, int $grupoId): array
    {
        return [
            'precio' => (string) $v->price,
            'especial' => [(string) $v->special_price, (string) $v->special_price_from, (string) $v->special_price_to],
            'reglas' => $v->customer_group_prices
                ->filter(fn ($r) => $r->customer_group_id === null || (int) $r->customer_group_id === $grupoId)
                ->sortBy('id')
                ->map(fn ($r) => [(int) $r->id, (int) $r->qty, (string) $r->value_type, number_format((float) $r->value, 4, '.', ''), $r->customer_group_id === null])
                ->values()->all(),
        ];
    }

    /** Ventas en curso (activas o confirmandose) con alguna de estas variantes y ESTE grupo efectivo. */
    protected function borradoresAbiertos(int $grupoId, array $ids): int
    {
        return DB::table('bylopez_va_borradores_venta as b')
            ->join('cart as c', 'c.id', '=', 'b.cart_id')
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->whereIn('b.estado', ['activo', 'confirmando'])
            ->whereRaw('(CASE WHEN b.grupo_override = 1 THEN b.grupo_efectivo_id ELSE cu.customer_group_id END) = ?', [$grupoId])
            ->whereExists(fn ($e) => $e->selectRaw('1')->from('cart_items as ci')->whereColumn('ci.cart_id', 'b.cart_id')->whereIn('ci.product_id', $ids))
            ->count();
    }

    protected function olvidarCacheTienda(array $ids): void
    {
        if ($ids === [] || ! class_exists(\Webkul\FPC\Listeners\Product::class)) {
            return;
        }

        $listener = app(\Webkul\FPC\Listeners\Product::class);

        foreach (ProductProxy::modelClass()::query()->whereIn('id', array_values(array_unique($ids)))->get() as $producto) {
            $listener->afterUpdate($producto);
        }
    }

    protected function exigirEdicion(?AdminContract $actor): void
    {
        if (! $this->puedeEditar($actor)) {
            throw new Invalido(Invalido::SIN_PERMISO, 'No tiene permiso para editar precios por grupo.');
        }
    }

    protected function valorFijo(mixed $valor): string
    {
        $texto = trim((string) $valor);

        if (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', $texto) || (float) $texto <= 0) {
            throw new Invalido(Invalido::VALOR_INVALIDO, 'El precio fijo debe ser mayor que 0 (hasta 2 decimales).');
        }

        return number_format((float) $texto, 2, '.', '');
    }

    protected function valorPorcentaje(mixed $valor): string
    {
        $texto = trim((string) $valor);

        if (! preg_match('/^\d{1,2}(\.\d{1,2})?$/', $texto) || (float) $texto <= 0) {
            throw new Invalido(Invalido::VALOR_INVALIDO, 'El descuento debe ser mayor que 0 % y menor que 100 % (hasta 2 decimales).');
        }

        return number_format((float) $texto, 2, '.', '');
    }

    protected function valorAjuste(mixed $valor, string $modo): string
    {
        $texto = trim((string) $valor);
        $limite = $modo === Solicitud::AJUSTE_PORCENTAJE ? '/^-?\d{1,3}(\.\d{1,2})?$/' : '/^-?\d{1,8}(\.\d{1,2})?$/';

        if (! preg_match($limite, $texto) || abs((float) $texto) < 0.005 || ($modo === Solicitud::AJUSTE_PORCENTAJE && (float) $texto <= -100)) {
            throw new Invalido(Invalido::VALOR_INVALIDO, $modo === Solicitud::AJUSTE_PORCENTAJE
                ? 'El ajuste debe ser un porcentaje distinto de 0, mayor que −100 % (p. ej. 5 o −3).'
                : 'El ajuste debe ser un importe distinto de 0 (p. ej. 500 o −200).');
        }

        return number_format((float) $texto, 2, '.', '');
    }

    /** @return list<int> */
    protected function ids(array $ids): array
    {
        $limpios = array_values(array_unique(array_filter(array_map(fn ($id) => ctype_digit((string) $id) ? (int) $id : 0, $ids))));
        sort($limpios);

        if ($limpios === []) {
            throw new Invalido(Invalido::SELECCION_VACIA, 'Seleccione al menos una variante.');
        }

        if (count($limpios) > self::MAX_VARIANTES) {
            throw new Invalido(Invalido::SELECCION_EXCESIVA, 'Se pueden editar como maximo '.self::MAX_VARIANTES.' variantes por operacion: acote los filtros.');
        }

        return $limpios;
    }
}
