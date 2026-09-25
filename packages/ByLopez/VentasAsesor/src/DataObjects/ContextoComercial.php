<?php

namespace ByLopez\VentasAsesor\DataObjects;

use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\AtribucionInvalidaException;

/**
 * Contexto comercial de un pedido, conocido ANTES de persistir la atribucion
 * (mecanismo M-3 de [M-§10.2]).
 *
 * Es inmutable y valida sus invariantes al construirse: ningun consumidor puede
 * armar una combinacion incoherente, como una venta de empresa con asesor.
 */
final class ContextoComercial
{
    /**
     * Campos comerciales persistibles que definen la identidad del contexto. La
     * idempotencia del registro se decide comparando exactamente estos.
     *
     * @var list<string>
     */
    public const CAMPOS_COMPARABLES = [
        'created_by_admin_id',
        'advisor_id',
        'commercial_owner_type',
        'commercial_owner_source',
        'sales_channel',
        'creation_source',
        'external_reference',
        'cart_id',
        'notes',
        'attribution_status',
    ];

    public function __construct(
        public readonly TipoPropiedadComercial $propiedad,
        public readonly ?int $advisorId,
        public readonly ?int $createdByAdminId,
        public readonly CanalVenta $canal,
        public readonly MecanismoCreacion $mecanismo,
        public readonly OrigenPropiedadComercial $origenPropiedad,
        public readonly ?string $referenciaExterna = null,
        public readonly ?int $cartId = null,
        public readonly ?string $notas = null,
        /**
         * Estado tecnico explicito. Solo se usa para registrar un FALLO_TECNICO;
         * en cualquier otro caso se deriva de la propiedad comercial.
         */
        private readonly ?EstadoAtribucion $estadoExplicito = null,
        /** Codigo tecnico seguro del fallo, sin datos sensibles ni trazas. */
        public readonly ?string $codigoTecnico = null,
    ) {
        if ($this->propiedad->exigeAsesor() && $this->advisorId === null) {
            throw new AtribucionInvalidaException('Una venta de asesor exige advisor_id.');
        }

        if (! $this->propiedad->exigeAsesor() && $this->advisorId !== null) {
            throw new AtribucionInvalidaException(
                "La propiedad comercial '{$this->propiedad->value}' no admite advisor_id."
            );
        }

        if ($this->mecanismo->prohibeCreadorAdministrativo() && $this->createdByAdminId !== null) {
            throw new AtribucionInvalidaException(
                "El mecanismo '{$this->mecanismo->value}' no puede tener creador administrativo."
            );
        }

        if ($this->mecanismo->exigeCreadorAdministrativo() && $this->createdByAdminId === null) {
            throw new AtribucionInvalidaException(
                "El mecanismo '{$this->mecanismo->value}' exige created_by_admin_id."
            );
        }

        if ($this->referenciaExterna !== null && trim($this->referenciaExterna) === '') {
            throw new AtribucionInvalidaException('La referencia externa no puede ser una cadena vacia.');
        }

        $this->validarEstadoExplicito();
    }

    public function estado(): EstadoAtribucion
    {
        return $this->estadoExplicito ?? EstadoAtribucion::desdePropiedad($this->propiedad);
    }

    public function esFalloTecnico(): bool
    {
        return $this->estado() === EstadoAtribucion::FALLO_TECNICO;
    }

    /** El creador y el asesor pueden ser la misma persona, pero son datos distintos [A-§5.5]. */
    public function creadorEsAsesor(): bool
    {
        return $this->advisorId !== null && $this->advisorId === $this->createdByAdminId;
    }

    public function conNotas(?string $notas): self
    {
        return new self(
            $this->propiedad,
            $this->advisorId,
            $this->createdByAdminId,
            $this->canal,
            $this->mecanismo,
            $this->origenPropiedad,
            $this->referenciaExterna,
            $this->cartId,
            $notas,
            $this->estadoExplicito,
            $this->codigoTecnico,
        );
    }

    /** @return array<string, mixed> Campos persistibles de la atribucion. */
    public function aAtributos(): array
    {
        return [
            'created_by_admin_id' => $this->createdByAdminId,
            'advisor_id' => $this->advisorId,
            'commercial_owner_type' => $this->propiedad,
            'commercial_owner_source' => $this->origenPropiedad,
            'sales_channel' => $this->canal,
            'creation_source' => $this->mecanismo,
            'attribution_status' => $this->estado(),
            'external_reference' => $this->referenciaExterna,
            'cart_id' => $this->cartId,
            'notes' => $this->notas,
        ];
    }

    /**
     * Valores normalizados para comparar contra una fila ya persistida: los enums
     * se reducen a su valor de base de datos.
     *
     * @return array<string, mixed>
     */
    public function valoresComparables(): array
    {
        $valores = [];

        foreach (self::CAMPOS_COMPARABLES as $campo) {
            $valores[$campo] = self::normalizar($this->aAtributos()[$campo]);
        }

        return $valores;
    }

    public static function normalizar(mixed $valor): mixed
    {
        if ($valor instanceof \BackedEnum) {
            return $valor->value;
        }

        if (is_int($valor) || $valor === null) {
            return $valor;
        }

        if (is_numeric($valor) && (string) (int) $valor === (string) $valor) {
            return (int) $valor;
        }

        return (string) $valor;
    }

    protected function validarEstadoExplicito(): void
    {
        if ($this->estadoExplicito === null) {
            if ($this->codigoTecnico !== null) {
                throw new AtribucionInvalidaException(
                    'El codigo tecnico solo tiene sentido en un contexto de fallo tecnico.'
                );
            }

            return;
        }

        if ($this->estadoExplicito !== EstadoAtribucion::FALLO_TECNICO) {
            throw new AtribucionInvalidaException(
                'El unico estado que puede forzarse es fallo_tecnico; los demas se derivan de la propiedad.'
            );
        }

        if ($this->propiedad !== TipoPropiedadComercial::SIN_ASIGNAR || $this->advisorId !== null) {
            throw new AtribucionInvalidaException('Un fallo tecnico siempre queda sin asignar y sin asesor.');
        }

        if ($this->codigoTecnico === null || trim($this->codigoTecnico) === '') {
            throw new AtribucionInvalidaException('Un fallo tecnico exige un codigo tecnico.');
        }
    }
}
