<?php

namespace ByLopez\VentasAsesor\Services;

use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\DataObjects\SeleccionPropiedadComercial;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\AtribucionInvalidaException;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Decide la propiedad comercial de un pedido. Es el unico punto donde viven las
 * reglas A a D aprobadas para la Fase 02A.
 *
 * Nunca infiere un asesor por nombre de rol, correo, cliente, fecha ni por el
 * usuario de la sesion actual: si no hay evidencia, la venta queda sin asignar.
 * La comprobacion de permisos se delega en AutorizacionVentasAsesor, que falla
 * cerrado; no se usa Admin::hasPermission() del core, que concede con tipos de
 * permiso desconocidos [B-§1, 06A-§1].
 */
class ResolverAtribucionService
{
    public function __construct(protected AutorizacionVentasAsesor $autorizacion) {}

    /**
     * Regla A. El cliente compra en la tienda: la venta es de la empresa, sin
     * asesor y sin creador administrativo. La conexion real es de la Fase 02B.
     */
    public function paraStorefront(
        CanalVenta $canal = CanalVenta::ECOMMERCE,
        ?int $cartId = null,
        ?string $referenciaExterna = null,
    ): ContextoComercial {
        return new ContextoComercial(
            propiedad: TipoPropiedadComercial::EMPRESA,
            advisorId: null,
            createdByAdminId: null,
            canal: $canal,
            mecanismo: MecanismoCreacion::STOREFRONT,
            origenPropiedad: OrigenPropiedadComercial::REGLA_STOREFRONT,
            referenciaExterna: $referenciaExterna,
            cartId: $cartId,
        );
    }

    /**
     * Reglas B y C. Creacion ejecutada por un administrador autenticado.
     *
     * - Asesor identificado (permission_type 'custom' + permiso marcador): se
     *   asigna a si mismo.
     * - Cualquier otro administrador, incluido el de acceso total: sin asignar,
     *   salvo que haya una seleccion explicita.
     */
    public function paraCreacionAdministrativa(
        ?AdminContract $creador,
        MecanismoCreacion $mecanismo,
        CanalVenta $canal,
        ?SeleccionPropiedadComercial $seleccion = null,
        ?int $cartId = null,
        ?string $referenciaExterna = null,
        ?string $notas = null,
    ): ContextoComercial {
        if (! $creador?->id) {
            throw new AtribucionInvalidaException(
                'La creacion administrativa exige un administrador autenticado con id.'
            );
        }

        if ($mecanismo->prohibeCreadorAdministrativo()) {
            throw new AtribucionInvalidaException(
                "El mecanismo '{$mecanismo->value}' no corresponde a una creacion administrativa."
            );
        }

        $creadorId = (int) $creador->id;

        [$propiedad, $advisorId, $origen] = $seleccion
            ? $this->resolverSeleccion($creador, $seleccion)
            : $this->resolverSinSeleccion($creador);

        return new ContextoComercial(
            propiedad: $propiedad,
            advisorId: $advisorId,
            createdByAdminId: $creadorId,
            canal: $canal,
            mecanismo: $mecanismo,
            origenPropiedad: $origen,
            referenciaExterna: $referenciaExterna,
            cartId: $cartId,
            notas: $notas,
        );
    }

    /**
     * Regla D. Pedido historico o sin evidencia suficiente: queda sin asignar,
     * con canal y mecanismo desconocidos. La Fase 02A no ejecuta backfill.
     */
    public function paraPedidoSinEvidencia(?string $notas = null): ContextoComercial
    {
        return new ContextoComercial(
            propiedad: TipoPropiedadComercial::SIN_ASIGNAR,
            advisorId: null,
            createdByAdminId: null,
            canal: CanalVenta::DESCONOCIDO,
            mecanismo: MecanismoCreacion::DESCONOCIDO,
            origenPropiedad: OrigenPropiedadComercial::SIN_CONTEXTO,
            notas: $notas,
        );
    }

    /**
     * Camino explicito para un fallo tecnico: el modulo no pudo determinar el
     * contexto. Queda `unassigned` + `fallo_tecnico`, SIEMPRE sin asesor, con un
     * codigo tecnico corto y seguro.
     *
     * El codigo no debe llevar trazas, credenciales ni payloads: se recorta y se
     * normaliza aqui para que ningun llamador pueda filtrar datos sensibles.
     *
     * Fase 02A2: NO admite notas. El texto libre era la puerta por la que podian
     * entrar telefonos, payloads o trazas a una fila que nadie vuelve a revisar.
     * El codigo validado basta para identificar la incidencia; el detalle operativo
     * va al log tecnico de Laravel.
     */
    public function paraFalloTecnico(string $codigoTecnico): ContextoComercial
    {
        $codigo = strtolower(trim($codigoTecnico));

        if ($codigo === '') {
            throw new AtribucionInvalidaException('Un fallo tecnico exige un codigo tecnico.');
        }

        if (preg_match('/^[a-z0-9._:-]{1,60}$/', $codigo) !== 1) {
            throw new AtribucionInvalidaException(
                'El codigo tecnico solo admite letras, digitos, punto, dos puntos, guion y guion bajo, hasta 60 caracteres.'
            );
        }

        return new ContextoComercial(
            propiedad: TipoPropiedadComercial::SIN_ASIGNAR,
            advisorId: null,
            createdByAdminId: null,
            canal: CanalVenta::DESCONOCIDO,
            mecanismo: MecanismoCreacion::DESCONOCIDO,
            origenPropiedad: OrigenPropiedadComercial::SIN_CONTEXTO,
            notas: null,
            estadoExplicito: EstadoAtribucion::FALLO_TECNICO,
            codigoTecnico: $codigo,
        );
    }

    /**
     * Automatizaciones (Fase 12): asesor explicito verificado o sin asignar para
     * reconciliacion. El usuario tecnico nunca recibe la venta.
     */
    public function paraAutomatizacion(
        MecanismoCreacion $mecanismo,
        CanalVenta $canal,
        ?AdminContract $asesor = null,
        ?string $referenciaExterna = null,
        ?int $cartId = null,
    ): ContextoComercial {
        if (! in_array($mecanismo, [MecanismoCreacion::API, MecanismoCreacion::N8N, MecanismoCreacion::IMPORTACION_EXCEL], true)) {
            throw new AtribucionInvalidaException(
                "El mecanismo '{$mecanismo->value}' no es una automatizacion."
            );
        }

        if ($asesor && ! $this->autorizacion->esAsesor($asesor)) {
            throw new AtribucionInvalidaException('El asesor indicado no tiene el permiso de asesor.');
        }

        return new ContextoComercial(
            propiedad: $asesor ? TipoPropiedadComercial::ASESOR : TipoPropiedadComercial::SIN_ASIGNAR,
            advisorId: $asesor ? (int) $asesor->id : null,
            createdByAdminId: null,
            canal: $canal,
            mecanismo: $mecanismo,
            origenPropiedad: $asesor
                ? OrigenPropiedadComercial::SELECCION_EXPLICITA
                : OrigenPropiedadComercial::SIN_CONTEXTO,
            referenciaExterna: $referenciaExterna,
            cartId: $cartId,
        );
    }

    /** @return array{0: TipoPropiedadComercial, 1: ?int, 2: OrigenPropiedadComercial} */
    protected function resolverSinSeleccion(AdminContract $creador): array
    {
        if ($this->autorizacion->esAsesorParaAtribucionAutomatica($creador)) {
            return [TipoPropiedadComercial::ASESOR, (int) $creador->id, OrigenPropiedadComercial::AUTO_ASESOR];
        }

        return [TipoPropiedadComercial::SIN_ASIGNAR, null, OrigenPropiedadComercial::SIN_CONTEXTO];
    }

    /** @return array{0: TipoPropiedadComercial, 1: ?int, 2: OrigenPropiedadComercial} */
    protected function resolverSeleccion(AdminContract $creador, SeleccionPropiedadComercial $seleccion): array
    {
        if ($seleccion->propiedad === TipoPropiedadComercial::EMPRESA) {
            return [TipoPropiedadComercial::EMPRESA, null, OrigenPropiedadComercial::SELECCION_EXPLICITA];
        }

        if ($seleccion->propiedad === TipoPropiedadComercial::SIN_ASIGNAR) {
            return [TipoPropiedadComercial::SIN_ASIGNAR, null, OrigenPropiedadComercial::SELECCION_EXPLICITA];
        }

        if ($seleccion->esElCreador) {
            if (! $this->autorizacion->esAsesor($creador)) {
                throw new AtribucionInvalidaException(
                    'El creador no puede asignarse como asesor sin el permiso de asesor.'
                );
            }

            return [TipoPropiedadComercial::ASESOR, (int) $creador->id, OrigenPropiedadComercial::SELECCION_EXPLICITA];
        }

        $asesor = $seleccion->asesor;

        if (! $asesor?->id) {
            throw new AtribucionInvalidaException('La seleccion de otro asesor exige un administrador con id.');
        }

        if ((int) $asesor->id !== (int) $creador->id
            && ! $this->autorizacion->tienePermiso($creador, AutorizacionVentasAsesor::PERMISO_PEDIDOS_ASIGNAR)
        ) {
            throw new AtribucionInvalidaException(
                'Asignar la venta a otro asesor exige el permiso '.AutorizacionVentasAsesor::PERMISO_PEDIDOS_ASIGNAR.'.'
            );
        }

        if (! $this->autorizacion->esAsesor($asesor)) {
            throw new AtribucionInvalidaException('El asesor indicado no tiene el permiso de asesor.');
        }

        return [TipoPropiedadComercial::ASESOR, (int) $asesor->id, OrigenPropiedadComercial::SELECCION_EXPLICITA];
    }
}
