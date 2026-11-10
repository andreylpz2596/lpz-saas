<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Comprobantes\BorradorOperacion;
use ByLopez\CorteConfeccion\Comprobantes\DeduplicadorOperaciones;
use ByLopez\CorteConfeccion\Enums\TipoMovimientoCartera;
use ByLopez\CorteConfeccion\Exceptions\CarteraConflictoException;
use ByLopez\CorteConfeccion\Models\CarteraAplicacion;
use ByLopez\CorteConfeccion\Models\CarteraMovimiento;
use ByLopez\CorteConfeccion\Models\CarteraOperacion;
use ByLopez\CorteConfeccion\Models\CarteraSoporte;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\CuentaEvento;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Support\CarteraConfig;
use ByLopez\CorteConfeccion\Support\Dinero;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registro, aplicacion y reverso de movimientos de dinero de la cartera. Reglas centrales:
 *  - Una sola fuente del dinero: el movimiento. Aplicarlo a una orden crea una aplicacion; aplicar un anticipo despues
 *    crea otra aplicacion del MISMO movimiento ($0 de dinero nuevo).
 *  - Se bloquea la fila del prestador al inicio de cada operacion: doble clic, reintentos y dos usuarios simultaneos se
 *    serializan; la llave de idempotencia devuelve el mismo resultado o rechaza un contenido distinto.
 *  - Una transferencia confirmada no se contabiliza dos veces (huella + indice unico `huella_activa`); coincidencias
 *    dudosas exigen motivo auditado; un movimiento reversado conserva el vinculo y exige correccion explicita.
 *  - Nada se edita ni se borra: se reversa con motivo.
 */
class CarteraMovimientoService
{
    public function __construct(
        protected CarteraCuentaService $cuentas,
        protected DeduplicadorOperaciones $deduplicador,
        protected CarteraFuenteOperaciones $fuente,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function registrar(array $data): CarteraMovimiento
    {
        $n = $this->normalizar($data);

        try {
            return DB::transaction(function () use ($n) {
                $prestador = $this->bloquearPrestador($n['prestador_tipo'], $n['prestador_id']);

                // Idempotencia DESPUES del bloqueo: ve lo que haya confirmado una peticion concurrente.
                $previo = CarteraMovimiento::query()->where('idempotency_key', $n['idempotency_key'])->first();

                if ($previo) {
                    if (! hash_equals($previo->payload_hash, $n['hash'])) {
                        throw new CarteraConflictoException('La llave de idempotencia ya se usó con un contenido distinto.');
                    }

                    return $previo->load(['operaciones', 'aplicaciones']);
                }

                $tipo = $n['tipo'];
                $arranque = CarteraConfig::fechaArranque();

                if (! $arranque) {
                    $this->error('fecha_efectiva', 'La cartera aún no tiene fecha de arranque. Defínela antes de registrar movimientos.');
                }

                if ($tipo->esSaldoInicial()) {
                    if ($n['fecha_efectiva'] >= $arranque->toDateString()) {
                        $this->error('fecha_efectiva', 'Un saldo inicial es anterior a la fecha de arranque ('.$arranque->format('d-m-Y').').');
                    }

                    $this->validarSaldoInicialUnico($tipo, $n['prestador_tipo'], $n['prestador_id']);
                } elseif ($n['fecha_efectiva'] < $arranque->toDateString()) {
                    $this->error('fecha_efectiva', 'La fecha es anterior al arranque de la cartera ('.$arranque->format('d-m-Y').'). Los saldos anteriores se cargan como saldo inicial, no como transferencias nuevas.');
                }

                if ($n['fecha_efectiva'] > now()->toDateString()) {
                    $this->error('fecha_efectiva', 'La fecha efectiva no puede ser futura.');
                }

                $cuentaAntes = $this->cuentas->cuenta($prestador);

                if ($tipo === TipoMovimientoCartera::Devolucion) {
                    $this->validarDevolucion($n, $cuentaAntes);
                }

                $operaciones = $this->prepararOperaciones($n, $tipo);

                $col = $n['prestador_tipo'] === 'taller' ? 'taller_id' : 'cortador_id';

                $movimiento = CarteraMovimiento::query()->create([
                    'codigo' => 'TMP-'.Str::uuid(),
                    'tipo' => $tipo->value,
                    $col => $prestador->id,
                    'valor' => $n['valor'],
                    'fecha_efectiva' => $n['fecha_efectiva'],
                    'medio' => $n['medio'],
                    'referencia' => $n['referencia'],
                    'observacion' => $n['observacion'],
                    'modo_detalle' => $n['modo_detalle'],
                    'num_transferencias' => $n['modo_detalle'] === 'detalle' ? collect($operaciones)->where('estado', 'aceptada')->count() : $n['num_transferencias'],
                    'prestamo_id' => $n['prestamo_id'],
                    'estado' => 'registrado',
                    'registrado_por' => auth()->guard('admin')->id(),
                    'idempotency_key' => $n['idempotency_key'],
                    'payload_hash' => $n['hash'],
                ]);

                $movimiento->update(['codigo' => $tipo->codigoPrefijo().'-'.str_pad((string) $movimiento->id, 4, '0', STR_PAD_LEFT)]);

                foreach ($operaciones as $op) {
                    $aceptada = $op['estado'] === 'aceptada';
                    CarteraOperacion::query()->create([
                        'movimiento_id' => $movimiento->id,
                        'soporte_id' => $op['soporte_id'],
                        'soporte_pagina' => $op['pagina'],
                        'valor' => $op['valor'],
                        'moneda' => $op['moneda'],
                        'direccion' => $op['direccion'],
                        'fecha_operacion' => $op['fecha'],
                        'hora_operacion' => $op['hora'],
                        'banco_origen' => $op['banco_origen'],
                        'banco_destino' => $op['banco_destino'],
                        'medio' => $op['medio'],
                        'referencia' => $op['referencia'],
                        'autorizacion' => $op['autorizacion'],
                        'contraparte' => $op['contraparte'],
                        'remitente' => $op['remitente'],
                        'huella' => $op['huella'],
                        'huella_activa' => $aceptada ? $op['huella'] : null,
                        'estado' => $op['estado'],
                        'duplicado_de_id' => $op['duplicado_de_id'],
                        'motivo_duplicado' => $op['motivo_duplicado'],
                    ]);
                }

                foreach ($n['soportes'] as $soporteId) {
                    CarteraSoporte::query()->whereKey($soporteId)->whereNull('movimiento_id')->update(['movimiento_id' => $movimiento->id]);
                }

                if ($tipo === TipoMovimientoCartera::Abono && ($n['aplicaciones'] || $n['aplicar_automatico'])) {
                    $this->aplicarLineas($movimiento, $prestador, $n['aplicaciones'], $n['aplicar_automatico'], $cuentaAntes, $n['valor']);
                }

                $this->evento($prestador, 'cartera_movimiento', null, [
                    'codigo' => $movimiento->codigo, 'tipo' => $tipo->value, 'valor' => $n['valor'], 'modo_detalle' => $n['modo_detalle'],
                ]);

                return $movimiento->refresh()->load(['operaciones', 'aplicaciones']);
            }, 3);
        } catch (QueryException $e) {
            // Segunda barrera contra la doble contabilizacion: indice unico de huella activa o de idempotencia.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                if (str_contains($e->getMessage(), 'bccco_huella_activa_unique')) {
                    $this->error('operaciones', 'Esta transferencia ya fue registrada por otra operación al mismo tiempo. No se contabilizó dos veces.');
                }

                if (str_contains($e->getMessage(), 'idempotency_key')) {
                    $previo = CarteraMovimiento::query()->where('idempotency_key', $n['idempotency_key'])->first();

                    if ($previo && hash_equals($previo->payload_hash, $n['hash'])) {
                        return $previo->load(['operaciones', 'aplicaciones']);
                    }

                    throw new CarteraConflictoException('La llave de idempotencia ya se usó con un contenido distinto.');
                }
            }

            throw $e;
        }
    }

    /**
     * Aplica (parte de) un abono o anticipo a ordenes. Crea otra aplicacion del MISMO movimiento: $0 de dinero nuevo.
     *
     * @param  list<array<string, mixed>>  $lineas
     */
    public function aplicar(CarteraMovimiento $movimiento, array $lineas): CarteraMovimiento
    {
        return DB::transaction(function () use ($movimiento, $lineas) {
            $prestador = $this->bloquearPrestador($movimiento->taller_id ? 'taller' : 'cortador', (int) ($movimiento->taller_id ?: $movimiento->cortador_id));
            $fresco = CarteraMovimiento::query()->lockForUpdate()->findOrFail($movimiento->id);

            if (! $fresco->estaVigente() || ! $fresco->tipoEnum()?->esDineroPorTrabajo()) {
                $this->error('movimiento', 'Solo se pueden aplicar abonos o anticipos vigentes.');
            }

            $cuenta = $this->cuentas->cuenta($prestador);
            $disponible = $cuenta['detalle_anticipos'][$fresco->id]['disponible'] ?? Dinero::cero();

            $this->aplicarLineas($fresco, $prestador, $lineas, false, $cuenta, $disponible);
            $this->evento($prestador, 'cartera_aplicacion', null, ['movimiento' => $fresco->codigo, 'lineas' => count($lineas)]);

            return $fresco->refresh()->load('aplicaciones');
        }, 3);
    }

    public function reversar(CarteraMovimiento $movimiento, string $motivo): CarteraMovimiento
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            $this->error('motivo', 'Indica el motivo del reverso.');
        }

        return DB::transaction(function () use ($movimiento, $motivo) {
            $prestador = $this->bloquearPrestador($movimiento->taller_id ? 'taller' : 'cortador', (int) ($movimiento->taller_id ?: $movimiento->cortador_id));
            $fresco = CarteraMovimiento::query()->lockForUpdate()->findOrFail($movimiento->id);

            if ($fresco->estado === 'reversado') {
                $this->error('movimiento', 'Este movimiento ya fue reversado.');
            }

            // Un prestamo con devoluciones o descuentos, o un abono cubierto por un corte vigente, no se reversa solo.
            if ($fresco->tipoEnum()?->esPrestamo()) {
                $usos = CarteraMovimiento::query()->where('prestamo_id', $fresco->id)->where('estado', 'registrado')->exists()
                    || CarteraAplicacion::query()->where('movimiento_id', $fresco->id)->where('concepto', CarteraAplicacion::DESCUENTO_PRESTAMO)->whereNull('anulada_en')->exists();

                if ($usos) {
                    $this->error('movimiento', 'El préstamo tiene devoluciones o descuentos vigentes. Reverso primero esos movimientos o el corte de cuentas.');
                }
            }

            $cubierto = DB::table('bylopez_cc_cartera_liquidaciones')
                ->where($fresco->taller_id ? 'taller_id' : 'cortador_id', $fresco->taller_id ?: $fresco->cortador_id)
                ->where('estado', 'registrado')
                ->get(['codigo', 'cifras'])
                ->first(fn ($l) => in_array($fresco->id, (array) (json_decode((string) $l->cifras, true)['movimientos_ids'] ?? []), true));

            if ($cubierto) {
                $this->error('movimiento', "El movimiento está cubierto por el corte {$cubierto->codigo}. Reversa primero ese corte.");
            }

            $fresco->update(['estado' => 'reversado', 'reversado_en' => now(), 'reversado_por' => auth()->guard('admin')->id(), 'motivo_reverso' => $motivo]);

            // La huella deja de contar: la operacion original se conserva (con su vinculo) y reutilizarla exige correccion explicita.
            CarteraOperacion::query()->where('movimiento_id', $fresco->id)->update(['huella_activa' => null]);

            $this->evento($prestador, 'cartera_reverso', $motivo, ['codigo' => $fresco->codigo, 'tipo' => $fresco->tipo, 'valor' => (string) $fresco->valor]);

            return $fresco->refresh();
        }, 3);
    }

    public function anularAplicacion(CarteraAplicacion $aplicacion, string $motivo): CarteraAplicacion
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            $this->error('motivo', 'Indica el motivo.');
        }

        return DB::transaction(function () use ($aplicacion, $motivo) {
            $mov = CarteraMovimiento::query()->findOrFail($aplicacion->movimiento_id);
            $prestador = $this->bloquearPrestador($mov->taller_id ? 'taller' : 'cortador', (int) ($mov->taller_id ?: $mov->cortador_id));
            $fresca = CarteraAplicacion::query()->lockForUpdate()->findOrFail($aplicacion->id);

            if ($fresca->anulada_en) {
                $this->error('aplicacion', 'La aplicación ya fue anulada.');
            }

            if ($fresca->liquidacion_id) {
                $this->error('aplicacion', 'La aplicación pertenece a un corte de cuentas. Reversa el corte.');
            }

            $fresca->update(['anulada_en' => now(), 'anulada_por' => auth()->guard('admin')->id(), 'motivo_anulacion' => $motivo]);
            $this->evento($prestador, 'cartera_aplicacion_anulada', $motivo, ['movimiento' => $mov->codigo, 'valor' => (string) $fresca->valor]);

            return $fresca->refresh();
        }, 3);
    }

    // ------------------------------------------------------------------------------------------------------------

    /** Prepara las operaciones del abono: valida, detecta duplicados y devuelve las filas a guardar (aceptadas y descartadas). */
    protected function prepararOperaciones(array $n, TipoMovimientoCartera $tipo): array
    {
        if ($n['modo_detalle'] !== 'detalle') {
            return [];
        }

        $direccion = $tipo === TipoMovimientoCartera::Devolucion ? BorradorOperacion::INGRESO : BorradorOperacion::EGRESO;
        $filas = [];
        $borradores = [];

        foreach ($n['operaciones'] as $i => $op) {
            $b = BorradorOperacion::desdeArray($op + ['direccion' => $direccion]);
            $b->direccion = $direccion;
            $filas[$i] = ['borrador' => $b, 'op' => $op];

            if (($op['estado'] ?? 'aceptada') === 'aceptada') {
                $borradores[$i] = $b;
            }
        }

        $aceptadas = array_values($borradores);

        if (! $aceptadas) {
            $this->error('operaciones', 'Un consolidado con detalle necesita al menos una operación aceptada.');
        }

        foreach ($aceptadas as $b) {
            if ($b->valor === null || ! Dinero::esPositivo($b->valor)) {
                $this->error('operaciones', 'Hay una operación incompleta (sin valor legible). Corrígela o descártala.');
            }
        }

        $analisis = $this->deduplicador->analizar($aceptadas, $this->fuente);
        $analisisPorIndice = array_combine(array_keys($borradores), $analisis);

        $suma = '0.00';
        $resultado = [];

        foreach ($filas as $i => $f) {
            $b = $f['borrador'];
            $op = $f['op'];
            $estado = ($op['estado'] ?? 'aceptada') === 'aceptada' ? 'aceptada' : 'descartada';
            $duplicadoDe = null;
            $motivoDup = null;

            if ($estado === 'aceptada') {
                $a = $analisisPorIndice[$i];
                $motivo = trim((string) ($op['motivo_duplicado'] ?? ''));
                $coincidencias = collect($a['coincidencias']);

                if ($a['en_lote_con'] !== null && $a['estado'] === DeduplicadorOperaciones::DUPLICADO) {
                    $this->error('operaciones', 'La operación '.($i + 1).' está repetida dentro del lote. Descártala.');
                }

                if ($a['estado'] === DeduplicadorOperaciones::DUPLICADO) {
                    $activas = $coincidencias->where('estado_movimiento', 'registrado');

                    if ($activas->isNotEmpty()) {
                        $c = $activas->first();
                        $this->error('operaciones', "Esta transferencia ya está registrada en {$c['movimiento_codigo']} ({$c['fecha']}, ".Dinero::formatear((string) $c['valor']).'). No se contabiliza dos veces.');
                    }

                    // Solo coincide con movimientos REVERSADOS: se conserva el vinculo y se exige una correccion explicita.
                    if ($motivo === '') {
                        $c = $coincidencias->first();
                        $this->error('operaciones', "Esta transferencia estuvo en {$c['movimiento_codigo']} (reversado). Para registrarla de nuevo indica el motivo de la corrección.");
                    }

                    $duplicadoDe = $coincidencias->first()['operacion_id'] ?? null;
                    $motivoDup = $motivo;
                } elseif ($a['estado'] === DeduplicadorOperaciones::POSIBLE) {
                    if ($motivo === '') {
                        $c = $coincidencias->first();
                        $detalle = $c ? " con {$c['movimiento_codigo']} ({$c['fecha']}, ".Dinero::formatear((string) $c['valor']).')' : ' dentro del lote';
                        $this->error('operaciones', "Posible transferencia duplicada{$detalle}. Revísala y, si continúas, indica el motivo.");
                    }

                    $duplicadoDe = $coincidencias->first()['operacion_id'] ?? null;
                    $motivoDup = $motivo;
                }

                $suma = Dinero::sumar($suma, $b->valor);
            }

            // Una fila descartada sin importe legible no se guarda (no hay nada que auditar); si lo tiene, queda como descartada.
            if ($estado === 'descartada' && $b->valor === null) {
                continue;
            }

            $resultado[] = [
                'valor' => $b->valor, 'moneda' => $b->moneda, 'direccion' => $b->direccion, 'fecha' => $b->fecha, 'hora' => $b->hora,
                'banco_origen' => $b->bancoOrigen, 'banco_destino' => $b->bancoDestino, 'medio' => $b->medio, 'referencia' => $b->referencia,
                'autorizacion' => $b->autorizacion, 'remitente' => $b->remitente,
                'contraparte' => $b->contraparte, 'soporte_id' => $b->soporteId, 'pagina' => $b->pagina,
                'huella' => $estado === 'aceptada' ? $b->huella() : null, 'estado' => $estado,
                'duplicado_de_id' => $duplicadoDe, 'motivo_duplicado' => $motivoDup,
            ];
        }

        if (Dinero::comparar($suma, $n['valor']) !== 0) {
            $this->error('valor', 'El total ('.Dinero::formatear($n['valor']).') debe coincidir con la suma de las operaciones aceptadas ('.Dinero::formatear($suma).').');
        }

        return $resultado;
    }

    /**
     * @param  list<array<string, mixed>>  $lineas
     */
    protected function aplicarLineas(CarteraMovimiento $mov, Taller|Cortador $prestador, array $lineas, bool $automatico, array $cuenta, string $disponible): void
    {
        $esTaller = $prestador instanceof Taller;
        $tipoOrden = $esTaller ? 'confeccion' : 'corte';
        $col = $esTaller ? 'orden_confeccion_id' : 'orden_corte_id';
        $porOrden = collect($cuenta['ordenes'])->keyBy('orden_id');

        $saldoIni = DB::table('bylopez_cc_cartera_movimientos')
            ->where($esTaller ? 'taller_id' : 'cortador_id', $prestador->id)->where('tipo', 'saldo_inicial_trabajo')->where('estado', 'registrado')->first();

        // Capacidad por destino (se descuenta a medida que se asigna dentro de esta llamada).
        $capOrden = $porOrden->map(fn ($o) => $o['saldo_estimado'])->all();
        $capSaldoIni = $cuenta['deuda_previa_pendiente'];

        if ($automatico && ! $lineas) {
            $resto = $disponible;

            if ($saldoIni && Dinero::esPositivo($capSaldoIni) && Dinero::esPositivo($resto)) {
                $v = Dinero::menor($resto, $capSaldoIni);
                $lineas[] = ['destino' => 'saldo_inicial', 'id' => $saldoIni->id, 'valor' => $v];
                $resto = Dinero::restar($resto, $v);
            }

            // FIFO: solo lo ya RECIBIDO (nunca se adelanta a lo pendiente sin pedirlo).
            foreach ($porOrden->sortBy('orden_id') as $o) {
                if (! Dinero::esPositivo($resto)) {
                    break;
                }

                if (Dinero::esPositivo($o['saldo_recibido'])) {
                    $v = Dinero::menor($resto, $o['saldo_recibido']);
                    $lineas[] = ['destino' => 'orden', 'id' => $o['orden_id'], 'valor' => $v];
                    $resto = Dinero::restar($resto, $v);
                }
            }
        }

        $total = '0.00';

        foreach ($lineas as $linea) {
            $valor = Dinero::parsear(is_scalar($linea['valor'] ?? null) ? (string) $linea['valor'] : null);

            if ($valor === null || ! Dinero::esPositivo($valor)) {
                $this->error('aplicaciones', 'Cada aplicación debe tener un valor positivo.');
            }

            $destino = $linea['destino'] ?? 'orden';
            $id = (int) ($linea['id'] ?? 0);

            if ($destino === 'saldo_inicial') {
                if (! $saldoIni || (int) $saldoIni->id !== $id) {
                    $this->error('aplicaciones', 'La deuda previa indicada no corresponde a este prestador.');
                }

                if (Dinero::comparar($valor, $capSaldoIni) > 0) {
                    $this->error('aplicaciones', 'La aplicación supera la deuda previa pendiente ('.Dinero::formatear($capSaldoIni).').');
                }

                $capSaldoIni = Dinero::restar($capSaldoIni, $valor);
                $datos = ['saldo_inicial_id' => $id];
            } else {
                $orden = $porOrden->get($id);

                if (! $orden || $orden['tipo'] !== $tipoOrden) {
                    $this->error('aplicaciones', 'La orden indicada no pertenece a este prestador o no tiene trabajo por pagar.');
                }

                if (Dinero::comparar($valor, $capOrden[$id]) > 0) {
                    $this->error('aplicaciones', "La aplicación supera el saldo de {$orden['codigo']} (".Dinero::formatear($capOrden[$id]).').');
                }

                $capOrden[$id] = Dinero::restar($capOrden[$id], $valor);
                $datos = [$col => $id];
            }

            $total = Dinero::sumar($total, $valor);

            if (Dinero::comparar($total, $disponible) > 0) {
                $this->error('aplicaciones', 'Las aplicaciones superan lo disponible del abono ('.Dinero::formatear($disponible).').');
            }

            CarteraAplicacion::query()->create($datos + [
                'movimiento_id' => $mov->id,
                'concepto' => CarteraAplicacion::PAGO_TRABAJO,
                'valor' => $valor,
                'creado_por' => auth()->guard('admin')->id(),
            ]);
        }
    }

    protected function validarDevolucion(array $n, array $cuenta): void
    {
        $prestamo = $n['prestamo_id'] ? ($cuenta['prestamos'][$n['prestamo_id']] ?? null) : null;

        if (! $prestamo) {
            $this->error('prestamo_id', 'Indica el préstamo que se devuelve.');
        }

        if (Dinero::comparar($n['valor'], $prestamo['pendiente']) > 0) {
            $this->error('valor', 'La devolución supera el préstamo pendiente ('.Dinero::formatear($prestamo['pendiente']).').');
        }
    }

    protected function validarSaldoInicialUnico(TipoMovimientoCartera $tipo, string $prestadorTipo, int $prestadorId): void
    {
        if ($tipo === TipoMovimientoCartera::SaldoInicialPrestamo) {
            return; // varios prestamos anteriores son posibles
        }

        $existe = CarteraMovimiento::query()
            ->where($prestadorTipo === 'taller' ? 'taller_id' : 'cortador_id', $prestadorId)
            ->where('tipo', $tipo->value)->where('estado', 'registrado')->exists();

        if ($existe) {
            $this->error('tipo', 'Ya hay un «'.$tipo->label().'» vigente para esta persona. Reversalo para cargarlo de nuevo.');
        }
    }

    /** @return array<string, mixed> */
    protected function normalizar(array $data): array
    {
        $tipo = TipoMovimientoCartera::tryFrom((string) ($data['tipo'] ?? ''));

        if (! $tipo) {
            $this->error('tipo', 'Tipo de movimiento no válido.');
        }

        $prestadorTipo = ($data['prestador_tipo'] ?? '') === 'cortador' ? 'cortador' : 'taller';
        $valor = Dinero::parsear(isset($data['valor']) && is_scalar($data['valor']) ? (string) $data['valor'] : null);

        if ($valor === null || ! Dinero::esPositivo($valor)) {
            $this->error('valor', 'El valor debe ser un importe positivo.');
        }

        $fecha = (string) ($data['fecha_efectiva'] ?? '');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || ! checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
            $this->error('fecha_efectiva', 'La fecha efectiva no es válida.');
        }

        $key = trim((string) ($data['idempotency_key'] ?? ''));

        if ($key === '' || strlen($key) > 100) {
            $this->error('idempotency_key', 'Falta la llave de idempotencia.');
        }

        $modo = ($data['modo_detalle'] ?? 'total') === 'detalle' ? 'detalle' : 'total';
        $num = isset($data['num_transferencias']) && $data['num_transferencias'] !== '' ? (int) $data['num_transferencias'] : null;

        if ($modo === 'total' && $num !== null && $num < 1) {
            $this->error('num_transferencias', 'La cantidad de transferencias debe ser al menos 1.');
        }

        $operaciones = array_values(array_map(fn ($o) => is_array($o) ? $o : [], (array) ($data['operaciones'] ?? [])));
        $operaciones = array_map(function (array $o) {
            $o['estado'] = ($o['estado'] ?? 'aceptada') === 'descartada' ? 'descartada' : 'aceptada';
            $o['valor'] = isset($o['valor']) && is_scalar($o['valor']) ? Dinero::parsear((string) $o['valor']) : null;

            return $o;
        }, $operaciones);

        $aplicaciones = array_values(array_map(fn ($a) => [
            'destino' => ($a['destino'] ?? 'orden') === 'saldo_inicial' ? 'saldo_inicial' : 'orden',
            'id' => (int) ($a['id'] ?? 0),
            'valor' => isset($a['valor']) && is_scalar($a['valor']) ? Dinero::parsear((string) $a['valor']) : null,
        ], array_filter((array) ($data['aplicaciones'] ?? []), 'is_array')));

        $n = [
            'tipo' => $tipo,
            'prestador_tipo' => $prestadorTipo,
            'prestador_id' => (int) ($data['prestador_id'] ?? 0),
            'valor' => $valor,
            'fecha_efectiva' => $fecha,
            'medio' => $this->texto($data['medio'] ?? null, 40),
            'referencia' => $this->texto($data['referencia'] ?? null, 255),
            'observacion' => $this->texto($data['observacion'] ?? null, 2000),
            'modo_detalle' => $modo,
            'num_transferencias' => $num,
            'prestamo_id' => ! empty($data['prestamo_id']) ? (int) $data['prestamo_id'] : null,
            'operaciones' => $operaciones,
            'soportes' => array_values(array_unique(array_map('intval', (array) ($data['soportes'] ?? [])))),
            'aplicaciones' => $aplicaciones,
            'aplicar_automatico' => (bool) ($data['aplicar_automatico'] ?? false),
            'idempotency_key' => $key,
        ];

        $canonico = $n;
        $canonico['tipo'] = $tipo->value;
        unset($canonico['idempotency_key']);
        $n['hash'] = hash('sha256', json_encode($canonico, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return $n;
    }

    protected function bloquearPrestador(string $tipo, int $id): Taller|Cortador
    {
        $modelo = $tipo === 'cortador' ? Cortador::class : Taller::class;
        $prestador = $modelo::query()->lockForUpdate()->find($id);

        if (! $prestador) {
            $this->error('prestador_id', 'El prestador indicado no existe.');
        }

        return $prestador;
    }

    protected function evento(Taller|Cortador $prestador, string $tipo, ?string $motivo, array $datos): void
    {
        CuentaEvento::query()->create([
            $prestador instanceof Taller ? 'taller_id' : 'cortador_id' => $prestador->id,
            'tipo' => $tipo,
            'actor_admin_id' => auth()->guard('admin')->id(),
            'motivo' => $motivo !== null ? mb_substr($motivo, 0, 500) : null,
            'datos' => $datos,
        ]);
    }

    protected function texto(mixed $valor, int $max): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $t = trim((string) $valor);

        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    protected function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
