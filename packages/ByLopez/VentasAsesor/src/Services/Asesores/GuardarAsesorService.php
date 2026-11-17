<?php

namespace ByLopez\VentasAsesor\Services\Asesores;

use ByLopez\VentasAsesor\Exceptions\AsesorInvalidoException as Rechazo;
use ByLopez\VentasAsesor\Models\Asesor;
use ByLopez\VentasAsesor\Services\Comisiones\CicloComisionService;
use ByLopez\VentasAsesor\Services\Comisiones\ReevaluacionComisionesService;
use Carbon\CarbonImmutable;
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
    /** Resultado del último cambio de fecha de ingreso (para el mensaje de la pantalla), o null si no se tocó. */
    public ?array $resultadoCiclo = null;

    public function __construct(protected AutorizacionVentasAsesor $autorizacion, protected CicloComisionService $ciclos) {}

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
     * Nombre, telefono y (si viene) fecha de ingreso: el usuario asociado no se cambia. La fecha de ingreso define el
     * PERÍODO de comisión (su día); el cambio lo decide CicloComisionService sin reescribir períodos vividos.
     *
     * @param  array{nombre: string, telefono?: ?string, fecha_ingreso?: ?string}  $datos
     */
    public function actualizar(AdminContract $actor, int $asesorId, array $datos): Asesor
    {
        $this->exigirPermiso($actor);
        $this->resultadoCiclo = null;

        $asesor = $this->encontrar($asesorId);
        [$nombre, $telefono] = $this->datos($datos);
        $tocaFecha = array_key_exists('fecha_ingreso', $datos);
        $fecha = $tocaFecha ? $this->fechaIngreso($datos['fecha_ingreso']) : null;

        DB::transaction(function () use ($actor, $asesor, $nombre, $telefono, $tocaFecha, $fecha) {
            $bloqueado = Asesor::query()->whereKey($asesor->id)->lockForUpdate()->firstOrFail();
            $cambios = ['nombre' => $nombre, 'telefono' => $telefono];

            if ($tocaFecha && $fecha !== $bloqueado->fecha_ingreso?->toDateString()) {
                $cambios['fecha_ingreso'] = $fecha;
                $this->resultadoCiclo = $this->ciclos->fijar((int) $bloqueado->admin_id, $fecha, $actor);
            }

            $bloqueado->forceFill($cambios)->save();
            $asesor->setRawAttributes($bloqueado->getAttributes(), true);
        });

        // Un período en curso que se alarga puede cambiar el umbral que lo rige (acuerdos dentro del tramo nuevo): se reevalúa.
        if ($this->resultadoCiclo !== null && $this->resultadoCiclo['modo'] === CicloComisionService::TRANSICION) {
            app(ReevaluacionComisionesService::class)->solicitarAsesorMes((int) $asesor->admin_id, $this->resultadoCiclo['periodo_actual']['clave'], 'ciclo_comision');
        }

        return $asesor;
    }

    /** Fecha de ingreso: vacía = sin día (mes calendario); si no, una fecha real (AAAA-MM-DD) no posterior a hoy. */
    private function fechaIngreso(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $valor) : false;

        if (! $fecha || $fecha->toDateString() !== $valor || $fecha->year < 2000) {
            throw new Rechazo(Rechazo::DATOS_INVALIDOS, 'La fecha de ingreso no es válida.');
        }

        if ($fecha->greaterThan(now()->endOfDay())) {
            throw new Rechazo(Rechazo::DATOS_INVALIDOS, 'La fecha de ingreso no puede ser posterior a hoy.');
        }

        return $valor;
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
