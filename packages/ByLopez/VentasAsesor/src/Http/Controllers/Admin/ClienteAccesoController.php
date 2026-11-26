<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Exceptions\ClienteAccesoInvalidoException as Invalido;
use ByLopez\VentasAsesor\Services\Clientes\ClienteAccesoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;
use Webkul\User\Contracts\Admin;

/**
 * Acceso de clientes al aplicativo desde la ficha del cliente (doc. 33, P1). Solo traduce a JSON: las reglas viven en
 * ClienteAccesoService, que ademas exige la clave ventas_asesor.clientes_acceso.manage.
 */
class ClienteAccesoController extends Controller
{
    public function __construct(protected ClienteAccesoService $acceso) {}

    public function mostrar(int $cliente): JsonResponse
    {
        return $this->responder(fn () => $this->acceso->estado($cliente, $this->actor()));
    }

    public function crearUsuario(int $cliente, Request $request): JsonResponse
    {
        $datos = $request->validate(['email' => ['nullable', 'email', 'max:191']]);

        return $this->responder(fn () => $this->acceso->crearUsuario($cliente, $this->actor(), $datos['email'] ?? null));
    }

    public function vincular(int $cliente, Request $request): JsonResponse
    {
        $datos = $request->validate(['admin_id' => ['required', 'integer', 'min:1']]);

        return $this->responder(fn () => $this->acceso->vincular($cliente, (int) $datos['admin_id'], $this->actor()));
    }

    public function suspender(int $cliente, Request $request): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->responder(fn () => $this->acceso->suspender($cliente, $this->actor(), $datos['motivo']));
    }

    public function reactivar(int $cliente): JsonResponse
    {
        return $this->responder(fn () => $this->acceso->reactivar($cliente, $this->actor()));
    }

    public function desvincular(int $cliente, Request $request): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->responder(fn () => $this->acceso->desvincular($cliente, $this->actor(), $datos['motivo']));
    }

    public function contrasena(int $cliente, Request $request): JsonResponse
    {
        $datos = $request->validate(['contrasena' => ['required', 'string', 'min:6', 'max:100']]);

        return $this->responder(fn () => $this->acceso->cambiarContrasena($cliente, $this->actor(), $datos['contrasena']));
    }

    protected function responder(callable $accion): JsonResponse
    {
        try {
            return response()->json($accion());
        } catch (Invalido $e) {
            return response()->json(['codigo' => $e->codigo, 'mensaje' => $e->getMessage()], match ($e->codigo) {
                Invalido::SIN_PERMISO, Invalido::SOLO_ADMINISTRADOR => 403,
                Invalido::CLIENTE_INEXISTENTE, Invalido::USUARIO_INEXISTENTE => 404,
                Invalido::YA_TIENE_ACCESO, Invalido::SIN_ACCESO, Invalido::ESTADO_NO_PERMITE, Invalido::USUARIO_YA_VINCULADO, Invalido::CORREO_EN_USO => 409,
                default => 422,
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json(['codigo' => 'error_inesperado', 'mensaje' => 'No se pudo completar la operacion.'], 500);
        }
    }

    protected function actor(): Admin
    {
        return auth()->guard('admin')->user();
    }
}
