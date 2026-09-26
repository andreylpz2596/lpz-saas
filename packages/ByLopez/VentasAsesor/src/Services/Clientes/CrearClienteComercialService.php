<?php

namespace ByLopez\VentasAsesor\Services\Clientes;

use ByLopez\VentasAsesor\Contracts\NormalizadorTelefono;
use ByLopez\VentasAsesor\DataObjects\DatosClienteComercial;
use ByLopez\VentasAsesor\Exceptions\ClienteComercialExistenteException as Existente;
use ByLopez\VentasAsesor\Exceptions\ConflictoIdentidadClienteException;
use ByLopez\VentasAsesor\Exceptions\DatosClienteComercialInvalidosException as Invalidos;
use ByLopez\VentasAsesor\Support\Clientes\ValidadorGrupoComercial;
use ByLopez\VentasAsesor\Support\Correo\DestinatarioCorreo;
use ByLopez\VentasAsesor\Support\Telefono\LocalizadorTitularTelefono;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;
use Webkul\Customer\Contracts\Customer;
use Webkul\Customer\Repositories\CustomerRepository;

/**
 * Alta de un cliente comercial como Customer real de Bagisto (RT-04B1b, Doc. 13 §16.2).
 *
 * - Sin cuenta ecommerce: password NULL (no puede iniciar sesion), is_verified 0, sin
 *   token ni credenciales. Email NULL cuando no existe; nunca "" ni sintetico.
 * - Grupo explicito y valido (ValidadorGrupoComercial); sin grupo por defecto.
 * - No crea duplicados: telefono o email ya usados → ClienteComercialExistenteException;
 *   telefono de A y email de B → ConflictoIdentidadClienteException.
 * - Customer + principal (customers.phone e indice) en UNA transaccion: si algo falla
 *   no queda un Customer a medias.
 * - Eventos: customer.create.before y, tras el commit, customer.create.after (como el
 *   alta del panel). NO customer.registration.*: en el core significa registro de
 *   cuenta y dispara verificacion/bienvenida al cliente (Doc. 13 §16.1, N4). Tampoco
 *   NewCustomerNotification: no hay credenciales.
 */
class CrearClienteComercialService
{
    private const MAX = 255;

    public function __construct(
        protected CustomerRepository $clientes,
        protected TelefonosClienteService $telefonos,
        protected ValidadorGrupoComercial $validadorGrupo,
        protected NormalizadorTelefono $normalizador,
        protected LocalizadorTitularTelefono $localizador,
        protected BuscarClienteComercialService $buscador,
    ) {}

    public function crear(DatosClienteComercial $datos): Customer
    {
        $nombre = trim($datos->nombre);
        $apellido = trim($datos->apellido);
        $email = $datos->email === null || trim($datos->email) === '' ? null : trim($datos->email);

        $this->validar($nombre, $apellido, $email, $datos->telefono);
        $grupo = $this->validadorGrupo->validar($datos->customerGroupId);
        $channelId = $datos->channelId ?? core()->getDefaultChannel()->id;

        $this->exigirIdentidadLibre($datos->telefono, $email, $channelId);

        Event::dispatch('customer.create.before');

        $cliente = DB::transaction(function () use ($nombre, $apellido, $email, $grupo, $channelId, $datos) {
            try {
                $cliente = $this->clientes->create([
                    'first_name' => $nombre,
                    'last_name' => $apellido,
                    'email' => $email,
                    'phone' => null,
                    'password' => null,
                    'customer_group_id' => $grupo->id,
                    'channel_id' => $channelId,
                    'status' => 1,
                    'is_verified' => 0,
                    'is_suspended' => 0,
                    'subscribed_to_news_letter' => 0,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Carrera: otro proceso tomo el email entre la comprobacion y la escritura.
                $otro = $email === null ? null : $this->buscador->idPorEmail($email, $channelId);

                throw $otro ? new Existente($otro, Existente::POR_EMAIL) : $e;
            }

            // Si el telefono ya es de otro (carrera), lanza y revierte tambien el Customer.
            $this->telefonos->establecerPrincipal((int) $cliente->id, $datos->telefono, false);

            return $cliente->fresh();
        });

        DB::afterCommit(function () use ($cliente) {
            try {
                Event::dispatch('customer.create.after', $cliente);
            } catch (Throwable $e) {
                // Un listener (p. ej. el correo al administrador) no deshace un alta ya confirmada.
                report($e);
            }
        });

        return $cliente;
    }

    private function validar(string $nombre, string $apellido, ?string $email, string $telefono): void
    {
        $errores = [];

        foreach (['nombre' => $nombre, 'apellido' => $apellido] as $campo => $valor) {
            if ($valor === '') {
                $errores[$campo] = Invalidos::REQUERIDO;
            } elseif (mb_strlen($valor) > self::MAX) {
                $errores[$campo] = Invalidos::DEMASIADO_LARGO;
            }
        }

        if ($email !== null && (mb_strlen($email) > self::MAX || DestinatarioCorreo::motivoDeOmision($email) !== null)) {
            $errores['email'] = Invalidos::EMAIL_INVALIDO;
        }

        if (! $this->normalizador->normalizar($telefono)->esBuscable()) {
            $errores['telefono'] = Invalidos::TELEFONO_NO_NORMALIZABLE;
        }

        if ($errores !== []) {
            throw new Invalidos($errores);
        }
    }

    private function exigirIdentidadLibre(string $telefono, ?string $email, int $channelId): void
    {
        $porTelefono = $this->localizador->titular($this->normalizador->normalizar($telefono))?->customerId;
        $porEmail = $email === null ? null : $this->buscador->idPorEmail($email, $channelId);

        if ($porTelefono && $porEmail && $porTelefono !== $porEmail) {
            throw new ConflictoIdentidadClienteException($porTelefono, $porEmail);
        }

        if ($porTelefono) {
            throw new Existente($porTelefono, Existente::POR_TELEFONO);
        }

        if ($porEmail) {
            throw new Existente($porEmail, Existente::POR_EMAIL);
        }
    }
}
