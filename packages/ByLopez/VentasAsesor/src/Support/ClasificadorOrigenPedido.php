<?php

namespace ByLopez\VentasAsesor\Support;

use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * ADAPTADOR DE INFRAESTRUCTURA. Es el unico lugar del modulo que puede mirar la
 * peticion HTTP y el guard administrativo.
 *
 * El dominio —ContextoComercial, ResolverAtribucionService,
 * RegistrarAtribucionService— no usa request(), auth() ni sesion: recibe datos ya
 * decididos. Asi las reglas comerciales se pueden probar sin simular una peticion
 * y no cambian de resultado segun quien tenga sesion abierta.
 *
 * Clasificacion CERRADA, por precedencia [D-§16.1]:
 *
 *   1. contexto explicito registrado para ese cart_id;
 *   2. ruta administrativa exacta;
 *   3. ruta exacta de tienda o pasarela;
 *   4. no determinable -> fallo tecnico.
 *
 * Nunca hay `default => storefront`, ni comparaciones por prefijo. Un flujo nuevo
 * y desconocido produce una incidencia visible, no una venta atribuida a la
 * empresa por descarte.
 */
class ClasificadorOrigenPedido
{
    /** Ruta desconocida, ausente, o ejecucion fuera de una peticion HTTP. */
    public const CODIGO_ORIGEN_NO_DETERMINADO = 'listener.origen_no_determinado';

    /** Ruta de tienda reconocida, pero sin carrito con el que correlacionar. */
    public const CODIGO_CART_ID_AUSENTE = 'listener.cart_id_ausente';

    /** Ruta del panel sin administrador autenticado: origen invalido, nunca tienda. */
    public const CODIGO_ADMIN_SIN_AUTENTICAR = 'listener.admin_sin_autenticar';

    public function __construct(
        protected ResolverAtribucionService $resolver,
        protected ContextoComercialDePeticion $contextoDePeticion,
        protected AuthFactory $auth,
        protected Request $request,
    ) {}

    public function clasificar(?int $cartId): ContextoComercial
    {
        if ($cartId !== null && $cartId > 0) {
            // 1. Evidencia directa de quien creo el pedido. No se consume.
            $explicito = $this->contextoDePeticion->para($cartId);

            if ($explicito !== null) {
                return $explicito;
            }
        }

        $ruta = $this->rutaActual();

        if (RutasDeCreacionDePedido::esAdministrativa($ruta)) {
            return $this->paraRutaAdministrativa($cartId);
        }

        if (RutasDeCreacionDePedido::esDeTienda($ruta)) {
            return $this->paraRutaDeTienda($cartId);
        }

        // 4. Ni ruta conocida ni contexto: el modulo NO adivina.
        return $this->resolver->paraFalloTecnico(self::CODIGO_ORIGEN_NO_DETERMINADO);
    }

    /** Nombre exacto de la ruta actual, o null fuera de una peticion enrutada. */
    public function rutaActual(): ?string
    {
        $nombre = $this->request->route()?->getName();

        return is_string($nombre) && $nombre !== '' ? $nombre : null;
    }

    /**
     * 2. Panel administrativo. El mecanismo es `admin_bagisto` y el canal queda
     * DESCONOCIDO: que un asesor use WhatsApp es una suposicion, y el pedido no
     * trae ninguna evidencia del canal real. Quien lo sepa lo corrige despues.
     *
     * Quien recibe la venta lo decide ResolverAtribucionService con el permiso del
     * creador: asesor con 'custom' + permiso marcador se la queda; cualquier otro
     * administrador, incluido el de acceso total, deja el pedido sin asignar.
     */
    protected function paraRutaAdministrativa(?int $cartId): ContextoComercial
    {
        $creador = $this->administradorAutenticado();

        if (! $creador?->id) {
            /*
             * Ruta del panel sin sesion administrativa verificable. Es un estado
             * invalido, no una compra de cliente: degradar esto a tienda regalaria
             * la venta a la empresa inventando evidencia que no existe.
             */
            return $this->resolver->paraFalloTecnico(self::CODIGO_ADMIN_SIN_AUTENTICAR);
        }

        /*
         * Aqui el cart_id puede faltar sin romper la atribucion: la evidencia es el
         * administrador autenticado en una ruta que solo el panel puede alcanzar,
         * no la correlacion con el carrito. El cart_id se guarda cuando existe.
         */
        return $this->resolver->paraCreacionAdministrativa(
            creador: $creador,
            mecanismo: MecanismoCreacion::ADMIN_BAGISTO,
            canal: CanalVenta::DESCONOCIDO,
            seleccion: null,
            cartId: $cartId !== null && $cartId > 0 ? $cartId : null,
        );
    }

    /**
     * 3. Checkout de la tienda y retornos de pasarela: venta de la EMPRESA, sin
     * asesor, canal `ecommerce`, mecanismo `storefront`, estado `completa`.
     *
     * Que haya una sesion administrativa abierta no cambia nada: el panel y la
     * tienda comparten la sesion de Laravel [D-§7.3], asi que el guard admin activo
     * no prueba que el pedido lo haya creado un administrador.
     */
    protected function paraRutaDeTienda(?int $cartId): ContextoComercial
    {
        if ($cartId === null || $cartId <= 0) {
            /*
             * Decision explicita: NO se atribuye storefront solo por la ruta.
             *
             * El cart_id es la unica correlacion que permite verificar despues la
             * atribucion y reconciliarla (M-4), y la auditoria mostro que los 24
             * pedidos reales de la tienda lo tienen [D-§7.2]: su ausencia es una
             * anomalia, no un caso normal. Se prefiere una incidencia visible sobre
             * una atribucion que nadie podria volver a comprobar.
             */
            return $this->resolver->paraFalloTecnico(self::CODIGO_CART_ID_AUSENTE);
        }

        return $this->resolver->paraStorefront(
            canal: CanalVenta::ECOMMERCE,
            cartId: $cartId,
        );
    }

    protected function administradorAutenticado(): ?AdminContract
    {
        $usuario = $this->auth->guard('admin')->user();

        return $usuario instanceof AdminContract ? $usuario : null;
    }
}
