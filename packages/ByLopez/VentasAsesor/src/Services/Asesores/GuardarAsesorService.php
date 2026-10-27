<?php

namespace ByLopez\VentasAsesor\Services\Asesores;

use ByLopez\VentasAsesor\Exceptions\AsesorInvalidoException as Rechazo;
use ByLopez\VentasAsesor\Models\Asesor;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;
use Webkul\User\Models\Admin;

/**
 * Alta, edicion y activacion de asesores (ASESOR-01). Exige `ventas_asesor.asesores.manage`.
 *
 * Reglas: el usuario de Bagisto debe existir, estar activo y tener el permiso de asesor por ACL (`operar_como_asesor`;
 * nunca por nombre de rol); un usuario tiene como mucho UN perfil (UNIQUE `admin_id`) y el usuario de un perfil NO
 * cambia (asi la atribucion historica, que guarda el id del usuario, siempre apunta al mismo asesor). Nunca se borra: se
 * inactiva. Esta pantalla ASOCIA usuarios; no crea usuarios ni contrasenas (eso sigue en Bagisto).
 */
class GuardarAsesorService
{
    public function __construct(protected AutorizacionVentasAsesor $autorizacion) {}

    /**
     * @param  array{admin_id: int|string, nombre: string, telefono?: ?string, activo?: bool}  $datos
     */
    public function crear(AdminContract $actor, array $datos): Asesor
    {
        $this->exigirPermiso($actor);

        $adminId = (int) ($datos['admin_id'] ?? 0);
        $usuario = Admin::query()->with('role')->find($adminId);

        if (! $usuario || ! $usuario->status) {
            throw new Rechazo(Rechazo::USUARIO_INVALIDO, 'El usuario no existe o está inactivo en Bagisto.');
        }

        if (! $this->autorizacion->esAsesor($usuario)) {
            throw new Rechazo(Rechazo::USUARIO_NO_ELEGIBLE, 'El rol del usuario no tiene el permiso «Operar como asesor».');
        }

        if (Asesor::query()->where('admin_id', $adminId)->exists()) {
            throw new Rechazo(Rechazo::USUARIO_YA_ASOCIADO, 'Ese usuario ya está asociado a un asesor.');
        }

        [$nombre, $telefono] = $this->datos($datos);

        try {
            return DB::transaction(fn () => Asesor::query()->create([
                'admin_id' => $adminId,
                'nombre' => $nombre,
                'telefono' => $telefono,
                'activo' => (bool) ($datos['activo'] ?? true),
                'creado_por_admin_id' => (int) $actor->id,
            ]));
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new Rechazo(Rechazo::USUARIO_YA_ASOCIADO, 'Ese usuario ya está asociado a un asesor.');
            }

            throw $e;
        }
    }

    /**
     * Solo nombre y telefono: el usuario asociado no se cambia.
     *
     * @param  array{nombre: string, telefono?: ?string}  $datos
     */
    public function actualizar(AdminContract $actor, int $asesorId, array $datos): Asesor
    {
        $this->exigirPermiso($actor);

        $asesor = $this->encontrar($asesorId);
        [$nombre, $telefono] = $this->datos($datos);

        $asesor->forceFill(['nombre' => $nombre, 'telefono' => $telefono])->save();

        return $asesor;
    }

    /** Activar exige que el usuario siga activo y siga teniendo el permiso de asesor. */
    public function cambiarEstado(AdminContract $actor, int $asesorId, bool $activo): Asesor
    {
        $this->exigirPermiso($actor);

        $asesor = $this->encontrar($asesorId);

        if ($activo) {
            $usuario = Admin::query()->with('role')->find($asesor->admin_id);

            if (! $usuario || ! $usuario->status || ! $this->autorizacion->esAsesor($usuario)) {
                throw new Rechazo(Rechazo::USUARIO_NO_ELEGIBLE, 'El usuario asociado está inactivo o ya no tiene el permiso «Operar como asesor».');
            }
        }

        $asesor->forceFill(['activo' => $activo])->save();

        return $asesor;
    }

    /** @return array{0: string, 1: ?string} */
    private function datos(array $datos): array
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $telefono = trim((string) ($datos['telefono'] ?? ''));

        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) {
            throw new Rechazo(Rechazo::DATOS_INVALIDOS, 'El nombre del asesor debe tener entre 2 y 120 caracteres.');
        }

        if ($telefono !== '' && ! preg_match('/^[0-9 +()-]{7,30}$/', $telefono)) {
            throw new Rechazo(Rechazo::DATOS_INVALIDOS, 'El teléfono no es válido.');
        }

        return [$nombre, $telefono === '' ? null : $telefono];
    }

    private function encontrar(int $asesorId): Asesor
    {
        return Asesor::query()->find($asesorId) ?? throw new Rechazo(Rechazo::INEXISTENTE, 'El asesor no existe.');
    }

    private function exigirPermiso(AdminContract $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_ASESORES_GESTIONAR)) {
            throw new Rechazo(Rechazo::SIN_PERMISO, 'No tienes permiso para gestionar asesores.');
        }
    }
}
