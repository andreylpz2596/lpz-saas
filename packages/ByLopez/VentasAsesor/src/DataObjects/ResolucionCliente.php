<?php

namespace ByLopez\VentasAsesor\DataObjects;

use ByLopez\VentasAsesor\Enums\EstadoResolucionCliente as Estado;

final class ResolucionCliente
{
    public const CONFLICTO_TELEFONO_Y_EMAIL = ResultadoBusquedaCliente::CONFLICTO_TELEFONO_Y_EMAIL;

    public const CONFLICTO_TELEFONO_EN_VARIAS_FICHAS = ResultadoBusquedaCliente::CONFLICTO_TELEFONO_EN_VARIAS_FICHAS;

    /**
     * Identificado por EMAIL: el telefono indicado no esta registrado en ese cliente.
     * Resolver no lo agrega; si procede, el llamador usa TelefonosClienteService.
     */
    public const AVISO_TELEFONO_NO_REGISTRADO = 'telefono_no_registrado';

    /** Existe por telefono pero el email indicado no es el que tiene registrado (o no tiene). No se modifica nada. */
    public const AVISO_EMAIL_DISTINTO = 'email_distinto_al_registrado';

    /** Nuevo, pero hay clientes con nombre parecido: revisar antes de crear. */
    public const AVISO_NOMBRES_PARECIDOS = 'nombres_parecidos';

    /**
     * @param  list<ClienteEncontrado>  $candidatos
     * @param  list<int>  $clientesEnConflicto
     * @param  list<string>  $avisos
     */
    public function __construct(
        public readonly Estado $estado,
        public readonly ?ClienteEncontrado $cliente = null,
        public readonly array $candidatos = [],
        public readonly ?string $conflicto = null,
        public readonly array $clientesEnConflicto = [],
        public readonly array $avisos = [],
    ) {}
}
