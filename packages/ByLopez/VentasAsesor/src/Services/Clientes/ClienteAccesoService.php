<?php

namespace ByLopez\VentasAsesor\Services\Clientes;

use ByLopez\VentasAsesor\Exceptions\ClienteAccesoInvalidoException as Invalido;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Models\ClienteAcceso;
use ByLopez\VentasAsesor\Models\ClienteAccesoEvento;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Autoservicio\RolClienteFrecuente;
use ByLopez\VentasAsesor\Support\Clientes\ValidadorGrupoComercial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Customer\Models\Customer;
use Webkul\User\Contracts\Admin as AdminContract;
use Webkul\User\Models\Admin;

/**
 * Acceso de un cliente comercial al aplicativo (doc. 33, P1). Une, sin duplicar, `customers` y `admins`:
 *
 *  - Habilitar es una accion AUTORIZADA (clave ventas_asesor.clientes_acceso.manage); registrarse en la tienda no da acceso.
 *  - El usuario nace con una contrasena aleatoria que nadie conoce y SIN hash copiado del cliente de la tienda; se entra con
 *    el flujo normal de Bagisto «olvide mi contrasena» (enlace por correo). Nunca se envia ni se guarda una contrasena en claro.
 *  - El unico rol es el de autoservicio (RolClienteFrecuente). Un usuario existente solo se vincula si YA tiene ese rol: nunca
 *    se le cambia el rol a un asesor ni a un administrador.
 *  - V1: un usuario activo o suspendido por cliente (limite funcional; el modelo admite varios).
 *  - Cada accion deja un evento (append-only). Nada se borra.
 */
class ClienteAccesoService
{
    public const PERMISO = 'ventas_asesor.clientes_acceso.manage';

    /** Limite funcional de V1 (no es una restriccion de la base). */
    public const MAX_USUARIOS_POR_CLIENTE = 1;

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected RolClienteFrecuente $rol,
        protected ValidadorGrupoComercial $grupos,
    ) {}

    /** Estado del acceso de un cliente para la ficha: sin_acceso | activo | suspendido (y el usuario si lo hay). */
    public function estado(int $customerId, AdminContract $actor): array
    {
        $this->exigirPermiso($actor);

        $acceso = $this->vigente($customerId);
        $cliente = Customer::query()->findOrFail($customerId);

        $usuario = $acceso ? Admin::query()->with('role:id,name')->find($acceso->admin_id) : null;

        return [
            'customer_id' => $customerId,
            'estado' => $acceso ? $acceso->estado : 'sin_acceso',
            'usuario' => $usuario ? [
                'id' => (int) $usuario->id,
                'nombre' => $usuario->name,
                'email' => $usuario->email,
                'activo' => (bool) $usuario->status,
                'rol' => $usuario->role?->name,
            ] : null,
            'habilitado_at' => $acceso?->habilitado_at?->toIso8601String(),
            'suspendido_motivo' => $acceso?->suspendido_motivo,
            // Bagisto no registra el ultimo ingreso de los usuarios administrativos: no se inventa el dato.
            'ultimo_acceso' => null,
            'email_cliente' => $cliente->email,
            'puede_habilitar' => $acceso === null,
            'puede_cambiar_contrasena' => $acceso !== null && $this->autorizacion->esAdministradorAutorizado($actor),
            'resumen' => $this->resumen(),
        ];
    }

    /** Contadores globales: activos, suspendidos y clientes sin acceso. */
    public function resumen(): array
    {
        $porEstado = ClienteAcceso::query()->whereIn('estado', [ClienteAcceso::ACTIVO, ClienteAcceso::SUSPENDIDO])
            ->select('estado', DB::raw('COUNT(DISTINCT customer_id) AS n'))->groupBy('estado')->pluck('n', 'estado');

        $conAcceso = ClienteAcceso::query()->whereIn('estado', [ClienteAcceso::ACTIVO, ClienteAcceso::SUSPENDIDO])->distinct()->count('customer_id');

        return [
            'activos' => (int) ($porEstado[ClienteAcceso::ACTIVO] ?? 0),
            'suspendidos' => (int) ($porEstado[ClienteAcceso::SUSPENDIDO] ?? 0),
            'sin_acceso' => max(0, (int) Customer::query()->count() - $conAcceso),
        ];
    }

    /**
     * Crea el usuario de acceso del cliente y lo vincula, todo o nada. El correo es el que se indique o, si no, el del
     * cliente. Devuelve el estado y si se pudo enviar el enlace para crear la contrasena.
     */
    public function crearUsuario(int $customerId, AdminContract $actor, ?string $email = null): array
    {
        $this->exigirPermiso($actor);
        $cliente = $this->clienteHabilitable($customerId);

        $correo = Str::lower(trim((string) ($email ?: $cliente->email)));

        if ($correo === '' || ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new Invalido(Invalido::CORREO_INVALIDO);
        }

        $rol = $this->rol->asegurar();

        $admin = DB::transaction(function () use ($customerId, $actor, $cliente, $correo, $rol) {
            // Serializa las habilitaciones del mismo cliente.
            Customer::query()->whereKey($customerId)->lockForUpdate()->first();
            $this->exigirSinAccesoVigente($customerId);

            if (Admin::query()->whereRaw('LOWER(email) = ?', [$correo])->exists()) {
                throw new Invalido(Invalido::CORREO_EN_USO);
            }

            $admin = Admin::query()->create([
                'name' => trim($cliente->first_name.' '.$cliente->last_name) ?: $correo,
                'email' => $correo,
                // Contrasena aleatoria que nadie conoce: se define con el enlace de recuperacion de Bagisto.
                'password' => Hash::make(Str::random(64)),
                'status' => 1,
                'role_id' => $rol->id,
            ]);

            $this->vincularFila($customerId, (int) $admin->id, $actor, 'crear_usuario');

            return $admin;
        });

        return $this->estado($customerId, $actor) + ['enlace_enviado' => $this->enviarEnlace($customerId, $admin, $actor)];
    }

    /** Vincula un usuario que YA existe y YA tiene el rol de autoservicio (nunca se cambia el rol de otro usuario). */
    public function vincular(int $customerId, int $adminId, AdminContract $actor): array
    {
        $this->exigirPermiso($actor);
        $this->clienteHabilitable($customerId);

        DB::transaction(function () use ($customerId, $adminId, $actor) {
            Customer::query()->whereKey($customerId)->lockForUpdate()->first();
            $this->exigirSinAccesoVigente($customerId);

            $admin = Admin::query()->with('role')->find($adminId) ?? throw new Invalido(Invalido::USUARIO_INEXISTENTE);

            if (! $admin->role || ! $this->rol->esSeguro($admin->role)) {
                throw new Invalido(Invalido::USUARIO_CON_OTRO_ROL);
            }

            $previo = ClienteAcceso::query()->where('admin_id', $adminId)->first();

            if ($previo && $previo->customer_id !== $customerId) {
                throw new Invalido(Invalido::USUARIO_YA_VINCULADO);
            }

            $this->vincularFila($customerId, $adminId, $actor, 'vincular', $previo);
            $admin->forceFill(['status' => 1])->save();
        });

        return $this->estado($customerId, $actor);
    }

    public function suspender(int $customerId, AdminContract $actor, string $motivo): array
    {
        $this->exigirPermiso($actor);
        $motivo = $this->motivo($motivo);

        DB::transaction(function () use ($customerId, $actor, $motivo) {
            $acceso = $this->bloquearVigente($customerId);

            if ($acceso->estado !== ClienteAcceso::ACTIVO) {
                throw new Invalido(Invalido::ESTADO_NO_PERMITE);
            }

            $acceso->update(['estado' => ClienteAcceso::SUSPENDIDO, 'suspendido_at' => now(), 'suspendido_motivo' => $motivo]);
            // status 0: el Bouncer cierra la sesion del usuario en su siguiente peticion.
            Admin::query()->whereKey($acceso->admin_id)->update(['status' => 0]);
            $this->evento($acceso, 'suspender', $actor, $motivo);
        });

        return $this->estado($customerId, $actor);
    }

    public function reactivar(int $customerId, AdminContract $actor): array
    {
        $this->exigirPermiso($actor);

        DB::transaction(function () use ($customerId, $actor) {
            $acceso = $this->bloquearVigente($customerId);

            if ($acceso->estado !== ClienteAcceso::SUSPENDIDO) {
                throw new Invalido(Invalido::ESTADO_NO_PERMITE);
            }

            $this->clienteHabilitable($customerId);
            $acceso->update(['estado' => ClienteAcceso::ACTIVO, 'suspendido_at' => null, 'suspendido_motivo' => null]);
            Admin::query()->whereKey($acceso->admin_id)->update(['status' => 1]);
            $this->evento($acceso, 'reactivar', $actor, null);
        });

        return $this->estado($customerId, $actor);
    }

    /** Desvincula con motivo obligatorio: el usuario queda inactivo y sin cliente; el historial se conserva. */
    public function desvincular(int $customerId, AdminContract $actor, string $motivo): array
    {
        $this->exigirPermiso($actor);
        $motivo = $this->motivo($motivo);

        DB::transaction(function () use ($customerId, $actor, $motivo) {
            $acceso = $this->bloquearVigente($customerId);

            $acceso->update(['estado' => ClienteAcceso::DESVINCULADO, 'desvinculado_at' => now(), 'desvinculado_motivo' => $motivo]);
            Admin::query()->whereKey($acceso->admin_id)->update(['status' => 0]);
            $this->evento($acceso, 'desvincular', $actor, $motivo);
        });

        return $this->estado($customerId, $actor);
    }

    /**
     * Contrasena puesta A MANO por el administrador (alternativa al enlace por correo). Solo el administrador autorizado, nunca
     * otro rol con la clave de accesos. Se guarda solo el hash y el evento no lleva la contrasena; se cierran los «recordarme».
     */
    public function cambiarContrasena(int $customerId, AdminContract $actor, string $contrasena): array
    {
        $this->exigirPermiso($actor);

        if (! $this->autorizacion->esAdministradorAutorizado($actor)) {
            throw new Invalido(Invalido::SOLO_ADMINISTRADOR);
        }

        if (mb_strlen($contrasena) < 8 || mb_strlen($contrasena) > 100) {
            throw new Invalido(Invalido::CONTRASENA_INVALIDA);
        }

        DB::transaction(function () use ($customerId, $actor, $contrasena) {
            $acceso = $this->bloquearVigente($customerId);
            $usuario = Admin::query()->lockForUpdate()->find($acceso->admin_id) ?? throw new Invalido(Invalido::USUARIO_INEXISTENTE);

            $usuario->forceFill(['password' => Hash::make($contrasena), 'remember_token' => null])->save();
            $this->evento($acceso, 'contrasena_manual', $actor, null);
        });

        return $this->estado($customerId, $actor);
    }

    /** Vinculo activo (no suspendido, no desvinculado) del usuario, o null. Lo usa el autoservicio (P3). */
    public function vinculoActivoDelUsuario(int $adminId): ?ClienteAcceso
    {
        return ClienteAcceso::query()->where('admin_id', $adminId)->where('estado', ClienteAcceso::ACTIVO)->first();
    }

    // --------------------------------------------------------------------------------------------------------------

    protected function exigirPermiso(AdminContract $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, self::PERMISO)) {
            throw new Invalido(Invalido::SIN_PERMISO);
        }
    }

    /** El cliente existe, esta activo, no suspendido y con grupo comercial apto (si no, no podria comprar). */
    protected function clienteHabilitable(int $customerId): Customer
    {
        $cliente = Customer::query()->find($customerId) ?? throw new Invalido(Invalido::CLIENTE_INEXISTENTE);

        if (! $cliente->status || $cliente->is_suspended) {
            throw new Invalido(Invalido::CLIENTE_NO_HABILITABLE);
        }

        try {
            $this->grupos->validar($cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id);
        } catch (GrupoComercialInvalidoException) {
            throw new Invalido(Invalido::CLIENTE_SIN_GRUPO_APTO);
        }

        return $cliente;
    }

    protected function vigente(int $customerId): ?ClienteAcceso
    {
        return ClienteAcceso::query()->where('customer_id', $customerId)
            ->whereIn('estado', [ClienteAcceso::ACTIVO, ClienteAcceso::SUSPENDIDO])->orderByDesc('id')->first();
    }

    protected function bloquearVigente(int $customerId): ClienteAcceso
    {
        return ClienteAcceso::query()->where('customer_id', $customerId)
            ->whereIn('estado', [ClienteAcceso::ACTIVO, ClienteAcceso::SUSPENDIDO])
            ->orderByDesc('id')->lockForUpdate()->first() ?? throw new Invalido(Invalido::SIN_ACCESO);
    }

    protected function exigirSinAccesoVigente(int $customerId): void
    {
        $vigentes = ClienteAcceso::query()->where('customer_id', $customerId)
            ->whereIn('estado', [ClienteAcceso::ACTIVO, ClienteAcceso::SUSPENDIDO])->count();

        if ($vigentes >= self::MAX_USUARIOS_POR_CLIENTE) {
            throw new Invalido(Invalido::YA_TIENE_ACCESO);
        }
    }

    protected function vincularFila(int $customerId, int $adminId, AdminContract $actor, string $accion, ?ClienteAcceso $previo = null): ClienteAcceso
    {
        $datos = [
            'customer_id' => $customerId, 'estado' => ClienteAcceso::ACTIVO, 'habilitado_por_admin_id' => (int) $actor->id,
            'habilitado_at' => now(), 'suspendido_at' => null, 'suspendido_motivo' => null,
            'desvinculado_at' => null, 'desvinculado_motivo' => null,
        ];

        if ($previo) {
            $previo->update($datos);
            $acceso = $previo;
        } else {
            $acceso = ClienteAcceso::query()->create($datos + ['admin_id' => $adminId]);
        }

        $this->evento($acceso, $accion, $actor, null);

        return $acceso;
    }

    protected function evento(ClienteAcceso $acceso, string $accion, AdminContract $actor, ?string $motivo, ?array $detalle = null): void
    {
        ClienteAccesoEvento::query()->create([
            'acceso_id' => $acceso->id, 'customer_id' => $acceso->customer_id, 'admin_id' => $acceso->admin_id,
            'accion' => $accion, 'actor_admin_id' => (int) $actor->id, 'motivo' => $motivo, 'detalle' => $detalle, 'created_at' => now(),
        ]);
    }

    protected function motivo(string $motivo): string
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 500) {
            throw new Invalido(Invalido::MOTIVO_REQUERIDO);
        }

        return $motivo;
    }

    /** Enlace de Bagisto para que el cliente cree su propia contrasena. Un fallo de correo NO deshace la habilitacion. */
    protected function enviarEnlace(int $customerId, Admin $admin, AdminContract $actor): bool
    {
        try {
            $enviado = Password::broker('admins')->sendResetLink(['email' => $admin->email]) === Password::RESET_LINK_SENT;
        } catch (Throwable) {
            $enviado = false;
        }

        $acceso = ClienteAcceso::query()->where('admin_id', $admin->id)->first();

        if ($acceso) {
            $this->evento($acceso, 'enlace_enviado', $actor, null, ['enviado' => $enviado]);
        }

        return $enviado;
    }
}
