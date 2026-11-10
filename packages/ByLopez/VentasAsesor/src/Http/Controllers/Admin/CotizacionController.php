<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Exceptions\CotizacionInvalidaException;
use ByLopez\VentasAsesor\Services\Cotizaciones\CotizacionService;
use ByLopez\VentasAsesor\Support\Cotizaciones\AjustesCotizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Webkul\Core\Traits\PDFHandler;
use Webkul\User\Models\Admin;

/**
 * Cotizaciones de una venta en curso (Nueva venta). Cada ruta esta autorizada en Config/acl.php bajo la clave de «Nueva venta»;
 * ademas el servicio exige ser el propietario de la venta (o ver todas las ventas) y falla con un codigo estable.
 */
class CotizacionController extends Controller
{
    use PDFHandler;

    public function __construct(
        protected CotizacionService $cotizaciones,
        protected AjustesCotizacion $ajustes,
    ) {}

    /**
     * Enlace del menu «Datos de cotizacion»: lleva a Configuracion > Ventas Asesor > Cotizaciones, donde el administrador edita
     * datos bancarios, Nequi, llaves, vigencia y condiciones. Esa pantalla es la de Bagisto y exige su propia clave `configuration`.
     */
    public function datos(): RedirectResponse
    {
        return redirect()->route('admin.configuration.index', ['ventas_asesor', 'cotizacion']);
    }

    /** Crea la cotizacion (o devuelve la vigente si nada cambio) y entrega todo lo que la UI necesita para abrirla o compartirla. */
    public function store(int $borrador): JsonResponse
    {
        return $this->responder(function () use ($borrador) {
            $cotizacion = $this->cotizaciones->crear($borrador, $this->actor());

            return $this->cotizaciones->resumen($cotizacion) + [
                'url_pdf' => route('admin.ventas_asesor.nueva_venta.cotizaciones.pdf', $cotizacion->id),
                'texto' => $this->cotizaciones->textoParaCompartir($cotizacion),
            ];
        });
    }

    /** Historial de cotizaciones de la venta (para «Ver cotizacion» en Ventas en curso). */
    public function index(int $borrador): JsonResponse
    {
        return $this->responder(fn () => array_map(
            fn (array $r) => $r + ['url_pdf' => route('admin.ventas_asesor.nueva_venta.cotizaciones.pdf', $r['id'])],
            $this->cotizaciones->deLaVenta($borrador, $this->actor()),
        ));
    }

    /** El PDF se renderiza SIEMPRE desde la foto guardada: reabrirla muestra exactamente lo que se envio. */
    public function pdf(int $cotizacion): Response|JsonResponse
    {
        try {
            $c = $this->cotizaciones->obtener($cotizacion, $this->actor());
        } catch (CotizacionInvalidaException $e) {
            return $this->error($e);
        }

        $html = view('ventasasesor::admin.cotizaciones.pdf', [
            'cotizacion' => $c,
            'filas' => $this->cotizaciones->filasPdf($c),
            'encabezado' => $this->ajustes->encabezado(),
            'medios' => $c->datos_pago['medios'] ?? [],
            'condiciones' => $this->ajustes->condiciones(),
            'aviso' => $this->ajustes->aviso(),
        ])->render();

        return response($this->generatePdf($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$c->numero().'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    protected function responder(callable $accion): JsonResponse
    {
        try {
            return response()->json($accion());
        } catch (CotizacionInvalidaException $e) {
            return $this->error($e);
        }
    }

    protected function error(CotizacionInvalidaException $e): JsonResponse
    {
        return response()->json(['codigo' => $e->codigo, 'mensaje' => $e->getMessage()], $e->estadoHttp());
    }

    protected function actor(): Admin
    {
        return auth()->guard('admin')->user();
    }
}
