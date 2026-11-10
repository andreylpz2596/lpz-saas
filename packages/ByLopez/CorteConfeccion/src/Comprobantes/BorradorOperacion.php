<?php

namespace ByLopez\CorteConfeccion\Comprobantes;

use ByLopez\CorteConfeccion\Support\Dinero;

/**
 * Borrador de una operacion leida de un comprobante (o escrita a mano). NO es dinero contabilizado: cada modulo lo
 * revisa, lo confirma y lo contabiliza con sus propias reglas. Ver docs/LECTURA_COMPROBANTES_PAGOS.md.
 *
 * Campos faltantes quedan en null (nunca se inventan). Una referencia ilegible queda null y marcada para revision.
 */
class BorradorOperacion
{
    public const EGRESO = 'egreso';   // pago a un prestador

    public const INGRESO = 'ingreso'; // cobro de una venta

    /**
     * @param  list<string>  $advertencias
     */
    public function __construct(
        public ?string $valor = null,
        public string $moneda = 'COP',
        public ?string $fecha = null,
        public ?string $hora = null,
        public ?string $bancoOrigen = null,
        public ?string $bancoDestino = null,
        public ?string $medio = null,
        public ?string $referencia = null,
        public ?string $contraparte = null,
        public ?string $autorizacion = null,
        public ?string $remitente = null,
        public string $direccion = self::EGRESO,
        public ?int $soporteId = null,
        public ?int $pagina = null,
        public array $advertencias = [],
    ) {}

    /** @param  array<string, mixed>  $datos */
    public static function desdeArray(array $datos): self
    {
        $valor = isset($datos['valor']) ? Dinero::parsear(is_scalar($datos['valor']) ? (string) $datos['valor'] : null) : null;

        return new self(
            valor: $valor,
            moneda: strtoupper(substr(trim((string) ($datos['moneda'] ?? 'COP')) ?: 'COP', 0, 3)),
            fecha: self::fechaValida($datos['fecha'] ?? null),
            hora: self::horaValida($datos['hora'] ?? null),
            bancoOrigen: self::texto($datos['banco_origen'] ?? null, 80),
            bancoDestino: self::texto($datos['banco_destino'] ?? null, 80),
            medio: self::texto($datos['medio'] ?? null, 40),
            referencia: self::texto($datos['referencia'] ?? null, 120),
            contraparte: self::texto($datos['contraparte'] ?? null, 160),
            autorizacion: self::texto($datos['autorizacion'] ?? null, 120),
            remitente: self::texto($datos['remitente'] ?? null, 160),
            direccion: ($datos['direccion'] ?? self::EGRESO) === self::INGRESO ? self::INGRESO : self::EGRESO,
            soporteId: isset($datos['soporte_id']) ? (int) $datos['soporte_id'] : null,
            pagina: isset($datos['pagina']) ? (int) $datos['pagina'] : null,
            advertencias: array_values(array_filter((array) ($datos['advertencias'] ?? []), 'is_string')),
        );
    }

    /** @return array<string, mixed> */
    public function aArray(): array
    {
        return [
            'valor' => $this->valor, 'moneda' => $this->moneda, 'fecha' => $this->fecha, 'hora' => $this->hora,
            'banco_origen' => $this->bancoOrigen, 'banco_destino' => $this->bancoDestino, 'medio' => $this->medio,
            'referencia' => $this->referencia, 'autorizacion' => $this->autorizacion, 'contraparte' => $this->contraparte, 'remitente' => $this->remitente, 'direccion' => $this->direccion,
            'soporte_id' => $this->soporteId, 'pagina' => $this->pagina, 'advertencias' => $this->advertencias,
            'huella' => $this->huella(),
        ];
    }

    /**
     * Identificador de la operacion sin espacios ni signos, en mayusculas: «M-000 1207» == «m0001207». Si el comprobante solo
     * trae un codigo de autorizacion/aprobacion (sin ID aparte), ese codigo identifica la operacion.
     */
    public function referenciaNormalizada(): ?string
    {
        $base = $this->referencia ?? $this->autorizacion;

        if ($base === null) {
            return null;
        }

        $normal = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $base) ?? '');

        return $normal === '' ? null : $normal;
    }

    /**
     * Huella de la operacion: referencia + contexto (banco, fecha, importe, moneda, direccion). Null si falta contexto
     * suficiente: una referencia aislada NO es universalmente unica, asi que sin contexto no hay huella (solo coincidencias
     * «posibles»).
     */
    public function huella(): ?string
    {
        $referencia = $this->referenciaNormalizada();
        $banco = $this->normalizarBanco($this->direccion === self::EGRESO ? ($this->bancoDestino ?? $this->bancoOrigen) : ($this->bancoOrigen ?? $this->bancoDestino)) ?? $this->normalizarBanco($this->medio);

        if ($referencia === null || $this->valor === null || $this->fecha === null || $banco === null) {
            return null;
        }

        return hash('sha256', implode('|', [
            $banco, $referencia, $this->fecha, Dinero::normalizar($this->valor), $this->moneda, $this->direccion,
        ]));
    }

    public function normalizarBanco(?string $banco): ?string
    {
        if ($banco === null) {
            return null;
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $banco) ?: $banco;
        $limpio = preg_replace('/[^a-z0-9]/', '', strtolower($ascii)) ?? '';

        return $limpio === '' ? null : $limpio;
    }

    public function estaCompleta(): bool
    {
        return $this->valor !== null && $this->fecha !== null && $this->referenciaNormalizada() !== null;
    }

    protected static function texto(mixed $valor, int $max): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $texto = trim(strip_tags((string) $valor));

        return $texto === '' ? null : mb_substr($texto, 0, $max);
    }

    protected static function fechaValida(mixed $valor): ?string
    {
        if (! is_string($valor) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($valor), $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? trim($valor) : null;
    }

    protected static function horaValida(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', trim($valor)) ? trim($valor) : null;
    }
}
