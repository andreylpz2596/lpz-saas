<?php

namespace ByLopez\Cargues\Http\Controllers\Admin;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Http\Controllers\Admin\Concerns\RespondeJson;
use ByLopez\Cargues\Models\Tipo;
use ByLopez\Cargues\Models\TipoVersion;
use ByLopez\Cargues\Services\Configuracion\ActivarTipoService;
use ByLopez\Cargues\Services\Configuracion\TiposService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/** Cargues › Configurar tipos. Alta y edicion en BORRADOR; activacion con validacion. Solo administrador. */
class TiposController extends Controller
{
    use RespondeJson;

    public function __construct(protected TiposService $tipos, protected ActivarTipoService $activacion) {}

    public function index(): View
    {
        return view('cargues::admin.tipos.index', [
            'tipos' => Tipo::with(['versionVigente', 'versiones'])->orderBy('nombre')->get(),
        ]);
    }

    public function crear(): View
    {
        return view('cargues::admin.tipos.form', ['config' => $this->configuracionFormulario(null, null)]);
    }

    public function editar(int $tipo): View
    {
        $t = Tipo::findOrFail($tipo);
        $version = $t->versiones()->where('estado', TipoVersion::BORRADOR)->first() ?? $t->versiones()->first();

        return view('cargues::admin.tipos.form', ['config' => $this->configuracionFormulario($t, $version)]);
    }

    /** Lo que el formulario (Vue) necesita: estado del tipo y de su version, rutas y textos. */
    protected function configuracionFormulario(?Tipo $tipo, ?TipoVersion $version): array
    {
        $ejemplo = [
            'tabla' => 'bylopez_cgi_mi_tipo', 'acciones' => ['mi_accion'],
            'campos' => [
                ['nombre' => 'codigo', 'etiqueta' => 'Código', 'tipo' => 'identificador', 'longitud' => 20, 'nulo' => false],
                ['nombre' => 'detalle', 'etiqueta' => 'Detalle', 'tipo' => 'texto', 'longitud' => 100, 'nulo' => true, 'vacio' => 'nulo'],
            ],
            'llaves' => ['primaria' => ['codigo'], 'unicas' => [], 'indices' => []],
            'duplicados' => 'rechazar_todas', 'transaccion' => 'motor', 'reanudable' => false, 'tablas_negocio' => [],
        ];
        $conexiones = array_values((array) config('cargues.conexiones_autorizadas'));

        return [
            'estado' => [
                'tipo_id' => $tipo?->id, 'nombre' => $tipo?->nombre ?? '', 'descripcion' => $tipo?->descripcion ?? '',
                'version_id' => $version?->id, 'numero' => $version?->numero, 'estado' => $version?->estado,
                'conexion' => $version?->conexion ?? ($conexiones[0] ?? ''), 'procedimiento' => $version?->procedimiento ?? '',
                'accion' => $version?->accion ?? '', 'politica' => $version?->politica_errores,
                'limites' => $version?->limites ?: new \stdClass,
                'map' => json_encode($version?->map ?? $ejemplo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'revision' => $version?->revision_contrato, 'revision_por' => $version?->revision_admin_nombre,
                'revision_vigente' => (bool) ($version?->revision_huella && ($version->revision_contrato['huella_procedimiento'] ?? null) === $version->revision_huella),
                'conexiones' => $conexiones, 'limites_globales' => config('cargues.limites'),
            ],
            'rutas' => [
                'lista' => route('admin.cargues.tipos.index'), 'crear' => route('admin.cargues.tipos.store'),
                'base' => $tipo ? route('admin.cargues.tipos.editar', $tipo->id) : null,
            ],
            't' => trans('cargues::app.form'),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        return $this->responder(function () use ($request) {
            $tipo = $this->tipos->crear(auth()->guard('admin')->user(), $this->datos($request));

            return ['ok' => true, 'tipo_id' => $tipo->id, 'url' => route('admin.cargues.tipos.editar', $tipo->id)];
        }, 201);
    }

    public function guardar(Request $request, int $tipo): JsonResponse
    {
        return $this->responder(function () use ($request, $tipo) {
            $v = $this->tipos->guardarBorrador(auth()->guard('admin')->user(), Tipo::findOrFail($tipo), $this->datos($request));

            return ['ok' => true, 'version_id' => $v->id, 'errores' => $this->tipos->validar($v)];
        });
    }

    public function validar(int $tipo): JsonResponse
    {
        return $this->responder(function () use ($tipo) {
            $v = $this->borrador($tipo);

            return ['ok' => true, 'errores' => $this->tipos->validar($v), 'politica_errores' => $v->politica_errores];
        });
    }

    public function confirmarContrato(int $tipo): JsonResponse
    {
        return $this->responder(fn () => ['ok' => true, 'revision' => $this->tipos->confirmarContrato(auth()->guard('admin')->user(), $this->borrador($tipo))]);
    }

    public function activar(int $tipo): JsonResponse
    {
        return $this->responder(function () use ($tipo) {
            $v = $this->activacion->activar(auth()->guard('admin')->user(), $this->borrador($tipo));

            return ['ok' => true, 'version_id' => $v->id, 'estructura_id' => $v->estructura_id];
        });
    }

    public function activo(Request $request, int $tipo): JsonResponse
    {
        return $this->responder(fn () => ['ok' => true, 'activo' => $this->tipos->cambiarActivo(Tipo::findOrFail($tipo), $request->boolean('activo'))->activo]);
    }

    public function cancelarCargas(int $tipo): JsonResponse
    {
        return $this->responder(fn () => ['ok' => true, 'canceladas' => $this->activacion->cancelarCargasAbiertas(auth()->guard('admin')->user(), Tipo::findOrFail($tipo))]);
    }

    protected function borrador(int $tipo): TipoVersion
    {
        $v = Tipo::findOrFail($tipo)->versiones()->where('estado', TipoVersion::BORRADOR)->first();

        if (! $v) {
            throw new OperacionInvalidaException('SIN_BORRADOR', 'No hay un borrador: guarde cambios para crear una versión nueva.');
        }

        return $v;
    }
}
