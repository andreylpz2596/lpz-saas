<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/** Operacion de acceso de clientes rechazada por una regla del dominio (doc. 33, P1). */
class ClienteAccesoInvalidoException extends RuntimeException
{
    public const SIN_PERMISO = 'sin_permiso';

    public const CLIENTE_INEXISTENTE = 'cliente_inexistente';

    public const CLIENTE_NO_HABILITABLE = 'cliente_no_habilitable';

    public const CLIENTE_SIN_GRUPO_APTO = 'cliente_sin_grupo_apto';

    public const YA_TIENE_ACCESO = 'cliente_ya_tiene_acceso';

    public const SIN_ACCESO = 'cliente_sin_acceso';

    public const CORREO_INVALIDO = 'correo_invalido';

    public const CORREO_EN_USO = 'correo_en_uso';

    public const USUARIO_INEXISTENTE = 'usuario_inexistente';

    public const USUARIO_CON_OTRO_ROL = 'usuario_con_otro_rol';

    public const USUARIO_YA_VINCULADO = 'usuario_ya_vinculado';

    public const ESTADO_NO_PERMITE = 'estado_no_permite';

    public const MOTIVO_REQUERIDO = 'motivo_requerido';

    private const MENSAJES = [
        self::SIN_PERMISO => 'No tienes permiso para administrar accesos de clientes.',
        self::CLIENTE_INEXISTENTE => 'El cliente no existe.',
        self::CLIENTE_NO_HABILITABLE => 'El cliente esta inactivo o suspendido.',
        self::CLIENTE_SIN_GRUPO_APTO => 'El cliente no tiene un grupo comercial valido para vender.',
        self::YA_TIENE_ACCESO => 'El cliente ya tiene un acceso activo o suspendido.',
        self::SIN_ACCESO => 'El cliente no tiene acceso al aplicativo.',
        self::CORREO_INVALIDO => 'El correo no es valido o el cliente no tiene correo.',
        self::CORREO_EN_USO => 'Ya existe un usuario con ese correo.',
        self::USUARIO_INEXISTENTE => 'El usuario no existe.',
        self::USUARIO_CON_OTRO_ROL => 'Solo se puede vincular un usuario que ya tenga el rol de autoservicio.',
        self::USUARIO_YA_VINCULADO => 'Ese usuario ya pertenece a otro cliente.',
        self::ESTADO_NO_PERMITE => 'El estado actual del acceso no permite esta accion.',
        self::MOTIVO_REQUERIDO => 'Indica un motivo (entre 3 y 500 caracteres).',
    ];

    public function __construct(public readonly string $codigo)
    {
        parent::__construct(self::MENSAJES[$codigo] ?? $codigo);
    }
}
