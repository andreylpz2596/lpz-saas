<?php

namespace ByLopez\VentasAsesor\Services\Clientes;

use ByLopez\VentasAsesor\Contracts\NormalizadorTelefono;
use ByLopez\VentasAsesor\DataObjects\ConsultaClienteComercial;
use ByLopez\VentasAsesor\DataObjects\ResolucionCliente as Resolucion;
use ByLopez\VentasAsesor\Enums\EstadoResolucionCliente as Estado;
use ByLopez\VentasAsesor\Enums\TipoResultadoBusquedaCliente as Tipo;
use ByLopez\VentasAsesor\Exceptions\DatosClienteComercialInvalidosException as Invalidos;

/**
 * Decide que hacer con un cliente que llega con telefono (y opcionalmente email y
 * nombre): usar uno existente, crear uno nuevo o detenerse por conflicto (RT-04B1b).
 *
 * Solo lectura: no crea ni modifica. Crear es de CrearClienteComercialService;
 * agregar un telefono a un cliente, de TelefonosClienteService. Nunca fusiona ni
 * elige por nombre.
 */
class ResolverClienteComercialService
{
    public function __construct(
        protected BuscarClienteComercialService $buscador,
        protected NormalizadorTelefono $normalizador,
    ) {}

    public function resolver(string $telefono, ?string $email = null, ?string $nombre = null, ?int $channelId = null): Resolucion
    {
        if (! $this->normalizador->normalizar($telefono)->esBuscable()) {
            throw new Invalidos(['telefono' => Invalidos::TELEFONO_NO_NORMALIZABLE]);
        }

        $resultado = $this->buscador->buscar(new ConsultaClienteComercial($telefono, $email, null, $channelId));

        if ($resultado->tipo === Tipo::CONFLICTO) {
            return new Resolucion(Estado::CONFLICTO, conflicto: $resultado->conflicto,
                clientesEnConflicto: $resultado->clientesEnConflicto, avisos: $resultado->avisos);
        }

        if ($resultado->tipo === Tipo::TELEFONO_EXACTO) {
            $avisos = $resultado->avisos;
            $emailIndicado = $email === null || trim($email) === '' ? null : trim($email);

            if ($emailIndicado !== null && strcasecmp((string) $resultado->cliente->email, $emailIndicado) !== 0) {
                $avisos[] = Resolucion::AVISO_EMAIL_DISTINTO;
            }

            return new Resolucion(Estado::EXISTENTE, $resultado->cliente, avisos: $avisos);
        }

        if ($resultado->tipo === Tipo::EMAIL_EXACTO) {
            // Telefono sin titular y email de A: es A. Solo se avisa; el telefono NO se agrega aqui.
            return new Resolucion(Estado::EXISTENTE, $resultado->cliente,
                avisos: array_merge($resultado->avisos, [Resolucion::AVISO_TELEFONO_NO_REGISTRADO]));
        }

        $candidatos = $nombre === null ? [] : $this->buscador->candidatosPorNombre($nombre);

        return new Resolucion(Estado::NUEVO, candidatos: $candidatos,
            avisos: array_merge($resultado->avisos, $candidatos === [] ? [] : [Resolucion::AVISO_NOMBRES_PARECIDOS]));
    }
}
