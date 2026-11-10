<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\Comprobantes\ComprobantesService;
use ByLopez\CorteConfeccion\Exceptions\CarteraConflictoException;
use ByLopez\CorteConfeccion\Models\CarteraAplicacion;
use ByLopez\CorteConfeccion\Models\CarteraLiquidacion;
use ByLopez\CorteConfeccion\Models\CarteraMovimiento;
use ByLopez\CorteConfeccion\Models\CarteraSoporte;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Services\CarteraCuentaService;
use ByLopez\CorteConfeccion\Services\CarteraFuenteOperaciones;
use ByLopez\CorteConfeccion\Services\CarteraLiquidacionService;
use ByLopez\CorteConfeccion\Services\CarteraMovimientoService;
use ByLopez\CorteConfeccion\Support\CarteraConfig;
use ByLopez\CorteConfeccion\Support\CarteraGate;
use ByLopez\CorteConfeccion\Support\Dinero;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Cartera de talleres (satelites) y cortadores. Toda accion se autoriza en SERVIDOR con CarteraGate (vinculo del usuario
 * con el prestador + permiso concreto, denegando por defecto); ocultar botones en la vista es solo comodidad.
 */
class CarteraController extends Controller
{
    public function __construct(
        protected CarteraCuentaService $cuentas,
        protected CarteraMovimientoService $movimientos,
        protected CarteraLiquidacionService $liquidaciones,
        protected ComprobantesService $comprobantes,
    ) {}

    // ---------------------------------------------------------------- vistas

    public function index()
    {
        abort_unless(CarteraGate::puede(CarteraGate::VER), 403);

        $filas = collect()
            ->merge(Taller::query()->orderBy('nombre')->get()->map(fn ($t) => ['tipo' => 'taller', 'prestador' => $t]))
            ->merge(Cortador::query()->orderBy('nombre')->get()->map(fn ($c) => ['tipo' => 'cortador', 'prestador' => $c]))
            ->map(function (array $f) {
                $cuenta = $f['prestador'] instanceof Taller || $f['prestador'] instanceof Cortador ? $this->cuentas->cuenta($f['prestador']) : null;

                return $f + ['cuenta' => $cuenta];
            });

        return view('corteconfeccion::admin.cartera.index', [
            'filas' => $filas,
            'arranque' => CarteraConfig::fechaArranque(),
            'puedeArranque' => CarteraGate::puede(CarteraGate::ARRANQUE),
        ]);
    }

    public function cuenta(string $tipo, int $id)
    {
        $prestador = $this->prestador($tipo, $id);
        abort_unless(CarteraGate::puedeVerCuenta($prestador), 403);

        return $this->vistaCuenta($prestador, false);
    }

    /** El prestador abre SU cuenta: se resuelve por el vinculo del usuario, nunca por un id enviado por el cliente. */
    public function miCuenta()
    {
        $propio = CarteraGate::prestadorVinculado();

        if (! $propio) {
            // Personal de la empresa: no tiene «mi cuenta»; va al listado si puede verlo.
            return CarteraGate::puede(CarteraGate::VER)
                ? redirect()->route('admin.corte_confeccion.cartera.index')
                : abort(403);
        }

        abort_unless(CarteraGate::puedeVerCuenta($propio), 403);

        return $this->vistaCuenta($propio, true);
    }

    protected function vistaCuenta(Taller|Cortador $prestador, bool $propia)
    {
        $cuenta = $this->cuentas->cuenta($prestador);
        $esPrestador = CarteraGate::esPrestador();
        $col = $prestador instanceof Taller ? 'taller_id' : 'cortador_id';

        $movs = collect($cuenta['movimientos'])->map(function ($m) use ($cuenta) {
            $m->detalle_anticipo = $cuenta['detalle_anticipos'][$m->id] ?? null;

            return $m;
        });

        $operacionesPorMov = DB::table('bylopez_cc_cartera_operaciones')->whereIn('movimiento_id', $movs->pluck('id'))->where('estado', 'aceptada')->get()->groupBy('movimiento_id');
        $soportesPorMov = DB::table('bylopez_cc_cartera_soportes')->whereIn('movimiento_id', $movs->pluck('id'))->get(['id', 'movimiento_id', 'nombre_original', 'lectura_estado'])->groupBy('movimiento_id');
        $cortes = CarteraLiquidacion::query()->where($col, $prestador->id)->orderByDesc('id')->limit(30)->get();

        return view('corteconfeccion::admin.cartera.cuenta', [
            'prestador' => $prestador,
            'tipo' => $cuenta['tipo'],
            'cuenta' => $cuenta,
            'movs' => $movs->reverse()->values(),
            'operacionesPorMov' => $operacionesPorMov,
            'soportesPorMov' => $esPrestador ? collect() : $soportesPorMov, // el prestador no abre soportes: solo consulta
            'cortes' => $cortes,
            'propia' => $propia,
            'esPrestador' => $esPrestador,
            'puedeRegistrar' => CarteraGate::puede(CarteraGate::REGISTRAR),
            'puedePrestamos' => CarteraGate::puede(CarteraGate::PRESTAMOS),
            'puedeCorte' => CarteraGate::puede(CarteraGate::CORTE),
            'puedeReversar' => CarteraGate::puede(CarteraGate::REVERSAR),
            'puedeArranque' => CarteraGate::puede(CarteraGate::ARRANQUE),
            'lectorDisponible' => $this->comprobantes->lectorDisponible(),
            'maxArchivos' => (int) config('corteconfeccion.comprobantes.max_archivos', 20),
        ]);
    }

    // ------------------------------------------------------------ movimientos

    public function registrarMovimiento(Request $request, string $tipo, int $id): JsonResponse
    {
        return $this->registrar($request, $tipo, $id, ['abono', 'saldo_inicial_trabajo', 'saldo_inicial_anticipo'], CarteraGate::REGISTRAR);
    }

    public function registrarPrestamo(Request $request, string $tipo, int $id): JsonResponse
    {
        return $this->registrar($request, $tipo, $id, ['prestamo', 'devolucion', 'saldo_inicial_prestamo'], CarteraGate::PRESTAMOS);
    }

    /** @param  list<string>  $permitidos */
    protected function registrar(Request $request, string $tipo, int $id, array $permitidos, string $permiso): JsonResponse
    {
        $prestador = $this->prestador($tipo, $id);
        abort_unless(CarteraGate::puede($permiso), 403);

        $datos = $request->validate([
            'tipo' => ['required', Rule::in($permitidos)],
            'valor' => ['required'],
            'fecha_efectiva' => ['required', 'date_format:Y-m-d'],
            'medio' => ['nullable', Rule::in(['nequi', 'transferencia', 'efectivo', 'daviplata', 'otro'])],
            'referencia' => ['nullable', 'string', 'max:255'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'modo_detalle' => ['nullable', Rule::in(['total', 'detalle'])],
            'num_transferencias' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'prestamo_id' => ['nullable', 'integer'],
            'operaciones' => ['nullable', 'array', 'max:'.(int) config('corteconfeccion.comprobantes.max_archivos', 20) * 50],
            'operaciones.*' => ['array'],
            'soportes' => ['nullable', 'array'],
            'soportes.*' => ['integer', 'exists:bylopez_cc_cartera_soportes,id'],
            'aplicaciones' => ['nullable', 'array'],
            'aplicar_automatico' => ['nullable', 'boolean'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        // Los saldos iniciales (deuda previa, anticipo previo, prestamos anteriores) los define quien administra el arranque.
        if (str_starts_with($datos['tipo'], 'saldo_inicial')) {
            abort_unless(CarteraGate::puede(CarteraGate::ARRANQUE), 403);
        }

        try {
            $mov = $this->movimientos->registrar($datos + ['prestador_tipo' => $tipo, 'prestador_id' => $prestador->id]);
        } catch (CarteraConflictoException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Movimiento '.$mov->codigo.' registrado.', 'data' => ['id' => $mov->id, 'codigo' => $mov->codigo]]);
    }

    public function aplicar(Request $request, int $id): JsonResponse
    {
        abort_unless(CarteraGate::puede(CarteraGate::REGISTRAR), 403);
        $mov = CarteraMovimiento::query()->findOrFail($id);

        $datos = $request->validate(['aplicaciones' => ['required', 'array', 'min:1'], 'aplicaciones.*' => ['array']]);
        $this->movimientos->aplicar($mov, $datos['aplicaciones']);

        return response()->json(['message' => 'Anticipo aplicado: sin dinero nuevo.']);
    }

    public function anularAplicacion(Request $request, int $id): JsonResponse
    {
        abort_unless(CarteraGate::puede(CarteraGate::REGISTRAR), 403);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);
        $this->movimientos->anularAplicacion(CarteraAplicacion::query()->findOrFail($id), $datos['motivo']);

        return response()->json(['message' => 'Aplicación anulada.']);
    }

    public function reversarMovimiento(Request $request, int $id): JsonResponse
    {
        abort_unless(CarteraGate::puede(CarteraGate::REVERSAR), 403);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);
        $this->movimientos->reversar(CarteraMovimiento::query()->findOrFail($id), $datos['motivo']);

        return response()->json(['message' => 'Movimiento reversado. El original se conserva con su motivo.']);
    }

    // ------------------------------------------------------------------ corte

    public function corteVista(Request $request, string $tipo, int $id): JsonResponse
    {
        $prestador = $this->prestador($tipo, $id);
        abort_unless(CarteraGate::puede(CarteraGate::CORTE), 403);

        $descuento = Dinero::parsear((string) $request->query('descuento', '0')) ?? '0.00';
        $previa = $this->liquidaciones->previsualizar($prestador, $descuento, $request->integer('prestamo_id') ?: null);
        unset($previa['reparto'], $previa['congelado']);

        return response()->json($previa);
    }

    public function corteConfirmar(Request $request, string $tipo, int $id): JsonResponse
    {
        $prestador = $this->prestador($tipo, $id);
        abort_unless(CarteraGate::puede(CarteraGate::CORTE), 403);

        $datos = $request->validate([
            'descuento' => ['nullable'],
            'prestamo_id' => ['nullable', 'integer'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        try {
            $liq = $this->liquidaciones->confirmar($prestador, $datos);
        } catch (CarteraConflictoException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Corte '.$liq->codigo.' confirmado. No se registró ninguna transferencia.', 'data' => ['codigo' => $liq->codigo]]);
    }

    public function corteReversar(Request $request, int $id): JsonResponse
    {
        abort_unless(CarteraGate::puede(CarteraGate::REVERSAR), 403);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);
        $this->liquidaciones->reversar(CarteraLiquidacion::query()->findOrFail($id), $datos['motivo']);

        return response()->json(['message' => 'Corte reversado.']);
    }

    // ----------------------------------------------------------------- arranque

    public function arranque(Request $request): JsonResponse
    {
        abort_unless(CarteraGate::puede(CarteraGate::ARRANQUE), 403);
        $datos = $request->validate(['fecha' => ['required', 'date_format:Y-m-d']]);

        // Cambiarla despues de registrar movimientos reinterpretaria lo ya cargado.
        if (CarteraConfig::fechaArranque() && DB::table('bylopez_cc_cartera_movimientos')->exists()) {
            return response()->json(['message' => 'Ya hay movimientos registrados: la fecha de arranque no se puede cambiar.'], 422);
        }

        $fecha = CarteraConfig::establecerFechaArranque($datos['fecha']);

        return response()->json(['message' => 'Cartera iniciada el '.$fecha->format('d-m-Y').'.']);
    }

    // ------------------------------------------------------------ comprobantes

    /** Adjunta y LEE comprobantes. Devuelve borradores y duplicados; no crea movimientos ni cambia saldos. */
    public function leerComprobantes(Request $request, string $tipo, int $id): JsonResponse
    {
        $prestador = $this->prestador($tipo, $id);
        abort_unless(CarteraGate::puede(CarteraGate::REGISTRAR), 403);

        $maximo = (int) config('corteconfeccion.comprobantes.max_archivos', 20);
        $request->validate([
            'comprobantes' => ['required_without:reintentar', 'array', 'max:'.$maximo], 'comprobantes.*' => ['file'],
            // Reintento: soportes ya guardados de ESTA cuenta, sin volver a subir el archivo.
            'reintentar' => ['required_without:comprobantes', 'array', 'max:'.$maximo], 'reintentar.*' => ['integer'],
        ]);

        // El OCR local puede tardar varios segundos por archivo: se amplia el limite de esta peticion (el cliente envia un archivo por vez).
        @set_time_limit(max(60, (int) config('corteconfeccion.comprobantes.tiempo_max', 120) + 30));

        $fuente = new CarteraFuenteOperaciones;
        $soportes = [];
        $borradores = [];

        $entradas = [];

        foreach ((array) $request->file('comprobantes', []) as $archivo) {
            $entradas[] = $this->comprobantes->adjuntar($archivo, $prestador, $fuente);
        }

        foreach ((array) $request->input('reintentar', []) as $soporteId) {
            $propio = CarteraSoporte::query()->whereKey($soporteId)
                ->where($prestador instanceof Taller ? 'taller_id' : 'cortador_id', $prestador->id)->first();
            abort_unless($propio, 403);
            $entradas[] = ['soporte' => $propio, 'ya_cargado' => false, 'forzar' => true];
        }

        foreach ($entradas as $adj) {
            $soporte = $adj['soporte'];
            $lectura = $this->comprobantes->leer($soporte, (bool) ($adj['forzar'] ?? false));

            $previos = $adj['ya_cargado'] ? array_map(fn ($c) => $this->filtrarPorPermiso($c), $fuente->soportesPorHuellaArchivo($soporte->sha256)) : [];

            $soportes[] = [
                'id' => $soporte->id, 'nombre' => $soporte->nombre_original, 'mime' => $soporte->mime,
                'url' => route('admin.corte_confeccion.cartera.soporte', $soporte->id),
                'ya_cargado' => $adj['ya_cargado'], 'coincidencias_archivo' => $previos,
                'lectura' => $lectura['estado'], 'lectura_reutilizada' => $lectura['reutilizada'], 'mensaje' => $lectura['mensaje'],
            ];

            foreach ($lectura['borradores'] as $b) {
                $borradores[] = $b;
            }
        }

        $analisis = $this->comprobantes->analizarDuplicados($borradores, $fuente);
        $filas = [];

        foreach ($borradores as $i => $b) {
            $a = $analisis[$i];
            $filas[] = $b->aArray() + [
                'analisis' => $a['estado'],
                'en_lote_con' => $a['en_lote_con'],
                'coincidencias' => array_map(fn ($c) => $this->filtrarPorPermiso($c), $a['coincidencias']),
            ];
        }

        return response()->json([
            'lector_disponible' => $this->comprobantes->lectorDisponible(),
            'soportes' => $soportes,
            'borradores' => $filas,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    /** Descarga de un soporte: solo personal con permiso sobre la cuenta a la que pertenece; nunca otro prestador. */
    public function soporte(int $id)
    {
        $soporte = CarteraSoporte::query()->findOrFail($id);
        abort_unless(CarteraGate::puede(CarteraGate::VER), 403);

        $prestadores = collect();

        if ($soporte->taller_id) {
            $prestadores->push(Taller::query()->find($soporte->taller_id));
        }

        if ($soporte->cortador_id) {
            $prestadores->push(Cortador::query()->find($soporte->cortador_id));
        }

        // Un soporte reutilizado tambien es visible desde las cuentas cuyos movimientos lo usan.
        $movimientoIds = DB::table('bylopez_cc_cartera_operaciones')->where('soporte_id', $soporte->id)->pluck('movimiento_id')->push($soporte->movimiento_id)->filter()->unique();

        foreach (CarteraMovimiento::query()->whereIn('id', $movimientoIds)->get(['taller_id', 'cortador_id']) as $m) {
            $prestadores->push($m->taller_id ? Taller::query()->find($m->taller_id) : Cortador::query()->find($m->cortador_id));
        }

        abort_unless($prestadores->filter()->contains(fn ($p) => CarteraGate::puedeVerCuenta($p)), 403);
        abort_unless(Storage::disk(ComprobantesService::DISCO)->exists($soporte->ruta), 404);

        return Storage::disk(ComprobantesService::DISCO)->response($soporte->ruta, $soporte->nombre_original, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    // ----------------------------------------------------------------- helpers

    protected function prestador(string $tipo, int $id): Taller|Cortador
    {
        return match ($tipo) {
            'taller' => Taller::query()->findOrFail($id),
            'cortador' => Cortador::query()->findOrFail($id),
            default => abort(404),
        };
    }

    /** Una coincidencia en otra cuenta solo se detalla (codigo, fecha, valor) a quien puede ver esa cuenta. */
    protected function filtrarPorPermiso(array $c): array
    {
        [$tipo, $pid] = array_pad(explode(':', (string) ($c['prestador'] ?? ''), 2), 2, null);
        $dueno = $tipo === 'taller' ? Taller::query()->find($pid) : ($tipo === 'cortador' ? Cortador::query()->find($pid) : null);

        if ($dueno && CarteraGate::puedeVerCuenta($dueno)) {
            return $c + ['visible' => true];
        }

        return ['visible' => false, 'estado_movimiento' => $c['estado_movimiento'] ?? null, 'fuerza' => $c['fuerza'] ?? null, 'mensaje' => 'Ya registrada en otra cuenta que no puedes consultar.'];
    }
}
