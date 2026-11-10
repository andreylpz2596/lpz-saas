<?php

namespace ByLopez\Cargues\Http\Controllers\Admin;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Http\Controllers\Admin\Concerns\RespondeJson;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Cargas\CargasService;
use ByLopez\Cargues\Services\Cargas\OutputRechazadasService;
use ByLopez\Cargues\Services\Cargas\ProgresoService;
use ByLopez\Cargues\Services\Cargas\ResultadoFilasService;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Cargues › Nuevo cargue e Historial. Solo administrador (middleware `SoloAdministrador` en TODAS las rutas). Los
 * controladores son delgados: la autoridad esta en CargasService, que ademas rechaza todo parametro no permitido.
 */
class CarguesController extends Controller
{
    use RespondeJson;

    public function __construct(
        protected CargasService $cargas,
        protected ProgresoService $progreso,
        protected OutputRechazadasService $output,
        protected ResultadoFilasService $filas,
    ) {}

    public function index(): View
    {
        // Todo lo que la interfaz (Vue) necesita: datos, rutas y textos. Se arma aqui y no en la vista.
        return view('cargues::admin.index', ['config' => [
            'tipos' => $this->cargas->tiposActivos(),
            'historial' => $this->cargas->historial(),
            'umbral' => (int) config('cargues.umbral_sin_noticias'),
            'rutas' => [
                'configurar' => route('admin.cargues.tipos.index'),
                'subir' => route('admin.cargues.cargas.store'),
                'carga' => route('admin.cargues.cargas.show', 0),
                'historial' => route('admin.cargues.historial'),
                'plantilla' => route('admin.cargues.tipos.plantilla', [0, 'xlsx']),
            ],
            't' => trans('cargues::app.ui'),
        ]]);
    }

    public function tipos(): JsonResponse
    {
        return response()->json(['tipos' => $this->cargas->tiposActivos()]);
    }

    public function historial(): JsonResponse
    {
        return response()->json(['historial' => $this->cargas->historial()]);
    }

    /** Sube el archivo y crea la carga. Parametros permitidos: tipo_id, archivo y accion. */
    public function store(Request $request): JsonResponse
    {
        return $this->responder(function () use ($request) {
            $carga = $this->cargas->crear(auth()->guard('admin')->user(), $this->datos($request), $request->file('archivo'));

            return ['ok' => true, 'carga' => $this->cargas->detalle($carga)];
        }, 201);
    }

    public function show(int $carga): JsonResponse
    {
        return response()->json($this->cargas->detalle(Carga::findOrFail($carga)));
    }

    /** Resultado por fila (lo persistido), paginado: filtro por estado y busqueda por guia/valor. */
    public function filas(Request $request, int $carga): JsonResponse
    {
        return $this->responder(fn () => $this->filas->pagina(
            Carga::findOrFail($carga), (string) $request->query('estado', 'todas'), $request->query('q') === null ? null : (string) $request->query('q'), (int) $request->query('pagina', 1),
        ));
    }

    /** Revision agrupada (opcional por tipo): p. ej. producto → variantes, en la validacion y en el resultado. */
    public function agrupado(int $carga): JsonResponse
    {
        return $this->responder(fn () => $this->cargas->agrupado(Carga::findOrFail($carga)));
    }

    /** Plantilla del tipo (archivo del paquete con ejemplos sinteticos). */
    public function plantilla(int $tipo, string $formato)
    {
        try {
            [$ruta, $nombre] = $this->cargas->plantilla($tipo, $formato);
        } catch (OperacionInvalidaException $e) {
            return $this->error($e);
        }

        return response()->download($ruta, $nombre, ['Content-Type' => $formato === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8']);
    }

    public function progreso(int $carga): JsonResponse
    {
        return response()->json($this->progreso->consultar(Carga::findOrFail($carga)));
    }

    public function lectura(Request $request, int $carga): JsonResponse
    {
        return $this->responder(fn () => ['ok' => true, 'carga' => $this->cargas->detalle($this->cargas->actualizarLectura(Carga::findOrFail($carga), $this->datos($request)))]);
    }

    public function validar(Request $request, int $carga): JsonResponse
    {
        return $this->responder(function () use ($request, $carga) {
            $c = $this->cargas->validar(auth()->guard('admin')->user(), Carga::findOrFail($carga), $this->datos($request));

            return ['ok' => true, 'carga' => $this->cargas->detalle($c)];
        }, 202);
    }

    public function reintentar(int $carga): JsonResponse
    {
        return $this->responder(fn () => ['ok' => true, 'carga' => $this->cargas->detalle($this->cargas->reintentar(Carga::findOrFail($carga)))], 202);
    }

    public function cancelar(int $carga): JsonResponse
    {
        return $this->responder(fn () => ['ok' => true, 'carga' => $this->cargas->detalle($this->cargas->cancelar(auth()->guard('admin')->user(), Carga::findOrFail($carga)))]);
    }

    public function comparaciones(int $carga): JsonResponse
    {
        return response()->json(['comparaciones' => $this->cargas->comparaciones(Carga::findOrFail($carga))]);
    }

    public function aplicarComparacion(int $carga, int $comparacion): JsonResponse
    {
        return $this->responder(fn () => ['ok' => true] + $this->cargas->aplicarComparacion(Carga::findOrFail($carga), $comparacion));
    }

    /** Ejecutar (E2): la puerta (politica de errores, confirmacion, CAS) vive en CargasService::ejecutar. */
    public function ejecutar(Request $request, int $carga): JsonResponse
    {
        return $this->responder(function () use ($request, $carga) {
            $c = $this->cargas->ejecutar(auth()->guard('admin')->user(), Carga::findOrFail($carga), $this->datos($request));

            return ['ok' => true, 'carga' => $this->cargas->detalle($c)];
        }, 202);
    }

    /** Recuperar una ejecucion interrumpida: no clasifica nada mientras el servidor siga ejecutando. */
    public function recuperar(int $carga): JsonResponse
    {
        return $this->responder(fn () => ['ok' => true, 'carga' => $this->cargas->detalle($this->cargas->recuperar(Carga::findOrFail($carga)))]);
    }

    public function descargarRechazadas(int $carga, string $formato)
    {
        $c = Carga::findOrFail($carga);

        if (! in_array($c->estado, [Estados::VALIDADA, Estados::COMPLETADA, Estados::FALLIDA_EJECUCION], true)) {
            return $this->error(new OperacionInvalidaException('ESTADO_NO_EJECUTABLE', 'El resultado aún no está disponible.'));
        }

        try {
            return $formato === 'xlsx' ? $this->output->xlsx($c) : $this->output->csv($c);
        } catch (OperacionInvalidaException $e) {
            return $this->error($e);
        }
    }

    /** Pendientes de una carga ejecutada: filas APLICADAS con una observacion que pide accion humana (no son rechazadas). */
    public function descargarPendientes(int $carga, string $formato)
    {
        $c = Carga::findOrFail($carga);

        if ($c->estado !== Estados::COMPLETADA) {
            return $this->error(new OperacionInvalidaException('ESTADO_NO_EJECUTABLE', 'Los pendientes existen solo para una carga ejecutada.'));
        }

        try {
            return $formato === 'xlsx' ? $this->output->xlsx($c, false, true) : $this->output->csv($c, false, true);
        } catch (OperacionInvalidaException $e) {
            return $this->error($e);
        }
    }

    /** Resultado completo de una carga ejecutada: todas las filas con su estado final, detalle y observaciones. */
    public function descargarResultado(int $carga, string $formato)
    {
        $c = Carga::findOrFail($carga);

        if ($c->estado !== Estados::COMPLETADA) {
            return $this->error(new OperacionInvalidaException('ESTADO_NO_EJECUTABLE', 'El resultado completo existe solo para una carga ejecutada.'));
        }

        try {
            return $formato === 'xlsx' ? $this->output->xlsx($c, true) : $this->output->csv($c, true);
        } catch (OperacionInvalidaException $e) {
            return $this->error($e);
        }
    }
}
