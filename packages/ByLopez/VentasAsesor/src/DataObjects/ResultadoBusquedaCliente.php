<?php

namespace ByLopez\VentasAsesor\DataObjects;

use ByLopez\VentasAsesor\Enums\TipoResultadoBusquedaCliente as Tipo;

final class ResultadoBusquedaCliente
{
    public const CONFLICTO_TELEFONO_Y_EMAIL = 'telefono_y_email_de_clientes_distintos';

    public const CONFLICTO_TELEFONO_EN_VARIAS_FICHAS = 'telefono_en_varias_fichas';

    public const AVISO_TELEFONO_NO_NORMALIZABLE = 'telefono_no_normalizable';

    public const AVISO_EMAIL_INVALIDO = 'email_invalido';

    /**
     * @param  list<ClienteEncontrado>  $candidatos
     * @param  list<int>  $clientesEnConflicto
     * @param  list<string>  $avisos
     */
    public function __construct(
        public readonly Tipo $tipo,
        public readonly ?ClienteEncontrado $cliente = null,
        public readonly array $candidatos = [],
        public readonly ?string $conflicto = null,
        public readonly array $clientesEnConflicto = [],
        public readonly array $avisos = [],
    ) {}

    public function esExacto(): bool
    {
        return $this->tipo === Tipo::TELEFONO_EXACTO || $this->tipo === Tipo::EMAIL_EXACTO;
    }

    public function toArray(): array
    {
        return [
            'tipo' => $this->tipo->value,
            'cliente' => $this->cliente?->toArray(),
            'candidatos' => array_map(fn (ClienteEncontrado $c) => $c->toArray(), $this->candidatos),
            'conflicto' => $this->conflicto,
            'clientes_en_conflicto' => $this->clientesEnConflicto,
            'avisos' => $this->avisos,
        ];
    }
}
