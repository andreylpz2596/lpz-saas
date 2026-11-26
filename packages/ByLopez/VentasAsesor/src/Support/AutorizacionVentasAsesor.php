<?php

namespace ByLopez\VentasAsesor\Support;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Punto unico de decision de autorizacion del modulo.
 *
 * - El asesor se identifica por el permiso ACL marcador (D-06), nunca por el
 *   nombre editable del rol.
 * - Falla cerrado: solo reconoce los tipos de permiso 'all' y 'custom'.
 * - No consulta pedidos ni tablas propias: decide con lo que recibe.
 */
class AutorizacionVentasAsesor
{
    public const PERMISO_MODULO = 'ventas_asesor';

    public const PERMISO_OPERAR_COMO_ASESOR = 'ventas_asesor.operar_como_asesor';

    /* Doc. 33 P3: autoservicio del cliente frecuente (rol seguro; ver Support/Autoservicio/RolClienteFrecuente). */
    public const PERMISO_AUTOSERVICIO_COMPRAR = 'autoservicio.comprar';

    public const PERMISO_AUTOSERVICIO_PEDIDOS = 'autoservicio.pedidos';

    public const PERMISO_PEDIDOS_VER_PROPIOS = 'ventas_asesor.pedidos.view_own';

    public const PERMISO_PEDIDOS_VER_TODOS = 'ventas_asesor.pedidos.view_all';

    public const PERMISO_PEDIDOS_ASIGNAR = 'ventas_asesor.pedidos.assign';

    public const PERMISO_PEDIDOS_REASIGNAR = 'ventas_asesor.pedidos.reassign';

    /* G-11 / COM-D-15: elegir muestra / reposicion / garantia en Nueva venta. Sin ella, solo `venta_normal`. */
    public const PERMISO_PEDIDOS_TIPO_OPERACION = 'ventas_asesor.pedidos.tipo_operacion';

    /* GRP-01: aplicar a UNA venta un grupo comercial temporal distinto del habitual del cliente (y restaurarlo). */
    public const PERMISO_PEDIDOS_CAMBIAR_GRUPO_TEMPORAL = 'ventas_asesor.pedidos.cambiar_grupo_temporal';

    /* ASESOR-01: ficha de asesores. `view_own` = solo la propia; `view_all` = todas; `manage` = crear, editar, activar. */
    public const PERMISO_ASESORES_VER_PROPIO = 'ventas_asesor.asesores.view_own';

    public const PERMISO_ASESORES_VER_TODOS = 'ventas_asesor.asesores.view_all';

    public const PERMISO_ASESORES_GESTIONAR = 'ventas_asesor.asesores.manage';

    /*
     * PAG-B (D-59 / P-08): ACL de Cartera. Registrar y revisar son capacidades DISTINTAS; el servicio siempre
     * valida (I-10). `ventas_asesor.cartera` es solo agrupadora y no autoriza nada.
     */
    public const PERMISO_CARTERA_VER_PROPIA = 'ventas_asesor.cartera.view_own';

    public const PERMISO_CARTERA_VER_TODA = 'ventas_asesor.cartera.view_all';

    public const PERMISO_CARTERA_REGISTRAR = 'ventas_asesor.cartera.registrar';

    public const PERMISO_CARTERA_ABONO_GENERAL = 'ventas_asesor.cartera.abono_general';

    public const PERMISO_CARTERA_REVISAR = 'ventas_asesor.cartera.revisar';

    public const PERMISO_CARTERA_REVERSAR = 'ventas_asesor.cartera.reversar';

    /* D10 V1: credito del cliente. Capacidades SEPARADAS; el servicio exige ademas el alcance de cartera `view_all`. Bodega (C5) no las recibe. */
    public const PERMISO_CREDITO_VER = 'ventas_asesor.cartera.credito.ver';

    public const PERMISO_CREDITO_APLICAR = 'ventas_asesor.cartera.credito.aplicar';

    public const PERMISO_CREDITO_REEMBOLSAR = 'ventas_asesor.cartera.credito.reembolsar';

    public const PERMISO_CREDITO_REVERSAR = 'ventas_asesor.cartera.credito.reversar';

    public const PERMISO_CREDITO_AJUSTAR = 'ventas_asesor.cartera.credito.ajustar';

    /*
     * Doc. 35: cupo / limite de cartera por cliente. Capacidades SEPARADAS. Ver = cifras para vender; historial = auditoria de cambios;
     * editar = fijar el limite y bloquear/desbloquear; autorizar_exceso = permitir UNA venta por encima del cupo. Editar y autorizar
     * exceso exigen ADEMAS ser el administrador autorizado (puedeCupo): un rol `all` cualquiera no basta.
     */
    public const PERMISO_CUPO_VER = 'ventas_asesor.cartera.cupo.ver';

    public const PERMISO_CUPO_EDITAR = 'ventas_asesor.cartera.cupo.editar';

    public const PERMISO_CUPO_HISTORIAL = 'ventas_asesor.cartera.cupo.historial';

    public const PERMISO_CUPO_AUTORIZAR_EXCESO = 'ventas_asesor.cartera.cupo.autorizar_exceso';

    /* D-COST-04/05: «Control de costos». Ver = pantalla de comparacion; actualizar = vista previa y confirmacion. */
    public const PERMISO_COSTOS = 'ventas_asesor.costos';

    public const PERMISO_COSTOS_ACTUALIZAR = 'ventas_asesor.costos.actualizar';

    /* Doc. 32: «Precios por grupo», separado de costos. Ver = consulta; editar = vista previa, confirmacion y reindexacion. */
    public const PERMISO_PRECIOS_GRUPO = 'ventas_asesor.precios_grupo';

    public const PERMISO_PRECIOS_GRUPO_EDITAR = 'ventas_asesor.precios_grupo.editar';

    /*
     * Doc. 23 (R1–R4): comisiones y rentabilidad. Capacidades SEPARADAS: configurar acuerdos (`manage`), calcular,
     * aprobar liquidaciones y ajustes, registrar pagos, ajustar y revertir. El servicio valida siempre (no los botones).
     */
    public const PERMISO_COMISIONES_VER_PROPIAS = 'ventas_asesor.comisiones.view_own';

    public const PERMISO_COMISIONES_VER_TODAS = 'ventas_asesor.comisiones.view_all';

    public const PERMISO_COMISIONES_CONFIGURAR = 'ventas_asesor.comisiones.manage';

    public const PERMISO_COMISIONES_CALCULAR = 'ventas_asesor.comisiones.calcular';

    public const PERMISO_COMISIONES_APROBAR = 'ventas_asesor.comisiones.approve';

    public const PERMISO_COMISIONES_PAGAR = 'ventas_asesor.comisiones.pay';

    public const PERMISO_COMISIONES_AJUSTAR = 'ventas_asesor.comisiones.ajustar';

    public const PERMISO_COMISIONES_REVERTIR = 'ventas_asesor.comisiones.revertir';

    public const PERMISO_RENTABILIDAD_VER = 'ventas_asesor.rentabilidad.view';

    public const PERMISO_RENTABILIDAD_CONFIGURAR = 'ventas_asesor.rentabilidad.configurar';

    public const PERMISO_KPIS_VER_TODOS = 'ventas_asesor.kpis.view_all';

    /**
     * - 'all': concede todo, como Bagisto.
     * - 'custom': concede solo si los permisos son una lista de cadenas que
     *   contiene la clave exacta.
     * - Cualquier otro caso niega: administrador o rol nulo; tipo vacio, nulo o
     *   desconocido; permisos nulos, que no son arreglo o con elementos que no son
     *   cadenas.
     *
     * No se delega en Admin::hasPermission() porque concede con cualquier tipo
     * distinto de 'custom', compara con in_array() no estricto y lanza TypeError
     * con 'all'.
     */
    public function tienePermiso(?AdminContract $admin, string $permiso): bool
    {
        $rol = $admin?->role;

        if (! $rol) {
            return false;
        }

        if ($rol->permission_type === 'all') {
            return true;
        }

        if ($rol->permission_type !== 'custom') {
            return false;
        }

        $permisos = $rol->permissions;

        if (! is_array($permisos) || count(array_filter($permisos, 'is_string')) !== count($permisos)) {
            return false;
        }

        return in_array($permiso, $permisos, true);
    }

    /**
     * Administrador AUTORIZADO para operar dinero (D10 V1): comprobacion POSITIVA de identidad, no solo de permisos. El Bouncer y
     * `tienePermiso()` conceden todo a cualquier rol `permission_type = all` (p. ej. un «BODEGA» de QA creado como `all`), asi que
     * ese tipo de rol NO basta: tiene que ser el rol administrador configurado (`ventasasesor.credito.rol_administrador_id`, por
     * defecto 1, el mismo criterio que Cargues) y seguir activo.
     */
    public function esAdministradorAutorizado(?AdminContract $admin): bool
    {
        $rol = $admin?->role;

        return $admin !== null
            && (bool) ($admin->status ?? true)
            && $rol !== null
            && $rol->permission_type === 'all'
            && (int) $admin->role_id === (int) config('ventasasesor.credito.rol_administrador_id', 1);
    }

    /**
     * Permiso de credito del cliente (D10 V1). Operar (aplicar, reembolsar, reversar, ajustar) = administrador autorizado Y la clave.
     * Ver = la clave, pero un rol `all` que no sea el administrador autorizado tampoco ve (la «herencia» de `all` no es una asignacion).
     */
    public function puedeCredito(?AdminContract $admin, string $permiso): bool
    {
        if (! $this->tienePermiso($admin, $permiso)) {
            return false;
        }

        if ($admin->role->permission_type === 'all' || $permiso !== self::PERMISO_CREDITO_VER) {
            return $this->esAdministradorAutorizado($admin);
        }

        return true;
    }

    /**
     * Permiso del cupo de cartera (Doc. 35), mismo criterio que D10. Editar y autorizar exceso = administrador autorizado Y la clave.
     * Ver e historial = la clave; un rol `all` que no sea el administrador autorizado tampoco ve (la herencia de `all` no es una asignacion).
     * Falla cerrado: nunca depende del nombre del rol ni de ocultar botones.
     */
    public function puedeCupo(?AdminContract $admin, string $permiso): bool
    {
        if (! $this->tienePermiso($admin, $permiso)) {
            return false;
        }

        $soloConsulta = in_array($permiso, [self::PERMISO_CUPO_VER, self::PERMISO_CUPO_HISTORIAL], true);

        if ($admin->role->permission_type === 'all' || ! $soloConsulta) {
            return $this->esAdministradorAutorizado($admin);
        }

        return true;
    }

    /**
     * Puede SER asesor: incluye al administrador con permission_type 'all', que
     * puede elegirse expresamente como asesor (D-27).
     */
    public function esAsesor(?AdminContract $admin): bool
    {
        return $this->tienePermiso($admin, self::PERMISO_OPERAR_COMO_ASESOR);
    }

    /**
     * Recibe la venta AUTOMATICAMENTE al crear un pedido (D-27, decidida en la
     * Fase 01B). Exige permission_type 'custom' y el permiso marcador explicito.
     *
     * Un administrador con acceso total queda fuera a proposito: tener todos los
     * permisos no lo convierte en asesor ni le genera comision. Puede serlo solo
     * si alguien lo selecciona expresamente, y eso lo decide esAsesor().
     */
    public function esAsesorParaAtribucionAutomatica(?AdminContract $admin): bool
    {
        return $admin?->role?->permission_type === 'custom'
            && $this->tienePermiso($admin, self::PERMISO_OPERAR_COMO_ASESOR);
    }

    public function alcanceDeVisibilidad(?AdminContract $admin): AlcanceVisibilidad
    {
        if ($this->tienePermiso($admin, self::PERMISO_PEDIDOS_VER_TODOS)) {
            return AlcanceVisibilidad::TODOS;
        }

        if ($this->tienePermiso($admin, self::PERMISO_PEDIDOS_VER_PROPIOS)) {
            return AlcanceVisibilidad::PROPIOS;
        }

        return AlcanceVisibilidad::NINGUNO;
    }

    /**
     * Regla de aislamiento horizontal: sin atribucion valida y sin view_all, niega.
     * $advisorId es el asesor propietario del registro, o null si no tiene.
     */
    public function puedeVerRegistroDe(?AdminContract $admin, ?int $advisorId): bool
    {
        return match ($this->alcanceDeVisibilidad($admin)) {
            AlcanceVisibilidad::TODOS => true,
            AlcanceVisibilidad::PROPIOS => $advisorId !== null
                && $admin->id !== null
                && (int) $admin->id === $advisorId,
            AlcanceVisibilidad::NINGUNO => false,
        };
    }

    /**
     * Alcance de lectura de Cartera (P-08): `cartera.view_all` = todo; `cartera.view_own` = solo lo atribuido al
     * propio asesor; sin ninguna de las dos, nada. Es independiente de `pedidos.view_*`: tener la clave agrupadora
     * `ventas_asesor.cartera` sola NO concede lectura.
     */
    public function alcanceDeCartera(?AdminContract $admin): AlcanceVisibilidad
    {
        if ($this->tienePermiso($admin, self::PERMISO_CARTERA_VER_TODA)) {
            return AlcanceVisibilidad::TODOS;
        }

        if ($this->tienePermiso($admin, self::PERMISO_CARTERA_VER_PROPIA)) {
            return AlcanceVisibilidad::PROPIOS;
        }

        return AlcanceVisibilidad::NINGUNO;
    }

    /**
     * Espejo de `puedeVerRegistroDe()` para Cartera: `$advisorId` es el asesor propietario del pedido (o null si
     * no tiene atribucion). Sin atribucion valida y sin `view_all`, niega (RA-6).
     */
    public function puedeVerCarteraDe(?AdminContract $admin, ?int $advisorId): bool
    {
        return match ($this->alcanceDeCartera($admin)) {
            AlcanceVisibilidad::TODOS => true,
            AlcanceVisibilidad::PROPIOS => $advisorId !== null
                && $admin->id !== null
                && (int) $admin->id === $advisorId,
            AlcanceVisibilidad::NINGUNO => false,
        };
    }
}
