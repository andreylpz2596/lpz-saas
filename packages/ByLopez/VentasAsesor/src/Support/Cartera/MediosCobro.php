<?php

namespace ByLopez\VentasAsesor\Support\Cartera;

use LogicException;

/**
 * Fuente UNICA de los medios de cobro (D-59 / C-1, C-2; Doc. 05 §42.7). Lee `ventasasesor.pagos.medios_cobro`.
 *
 * `medio_cobro` NO es el metodo de pago del pedido: es el medio por el que realmente entro un abono. Nadie
 * mas debe repetir esta lista ni la politica de referencia: los servicios, las validaciones y las vistas
 * futuras preguntan aqui.
 *
 * Politica de referencia por medio (no universal): `requerida`, `opcional` o `no_aplica`. Una configuracion
 * invalida lanza LogicException a proposito (una politica mal escrita no debe relajarse en silencio).
 */
class MediosCobro
{
    public const REFERENCIA_REQUERIDA = 'requerida';

    public const REFERENCIA_OPCIONAL = 'opcional';

    public const REFERENCIA_NO_APLICA = 'no_aplica';

    private const POLITICAS = [self::REFERENCIA_REQUERIDA, self::REFERENCIA_OPCIONAL, self::REFERENCIA_NO_APLICA];

    /**
     * Todos los medios configurados, activos o no.
     *
     * @return array<string, array{codigo: string, etiqueta: string, activo: bool, referencia: string, plataforma: bool}>
     */
    public function todos(): array
    {
        $salida = [];

        foreach ((array) config('ventasasesor.pagos.medios_cobro', []) as $codigo => $definicion) {
            $codigo = (string) $codigo;
            $definicion = (array) $definicion;
            $politica = (string) ($definicion['referencia'] ?? '');

            if (preg_match('/^[a-z][a-z0-9_]{1,39}$/', $codigo) !== 1) {
                throw new LogicException("Medio de cobro con codigo invalido: '{$codigo}' (minusculas, digitos y guion bajo).");
            }

            if (! in_array($politica, self::POLITICAS, true)) {
                throw new LogicException("Medio de cobro '{$codigo}': politica de referencia invalida ('{$politica}'). Use ".implode(', ', self::POLITICAS).'.');
            }

            $salida[$codigo] = [
                'codigo' => $codigo,
                'etiqueta' => (string) ($definicion['etiqueta'] ?? $codigo),
                'activo' => (bool) ($definicion['activo'] ?? true),
                'referencia' => $politica,
                'plataforma' => (bool) ($definicion['plataforma'] ?? false),
            ];
        }

        return $salida;
    }

    /**
     * Solo los medios activos (los que se ofrecen al registrar).
     *
     * @return array<string, array{codigo: string, etiqueta: string, activo: bool, referencia: string}>
     */
    public function activos(): array
    {
        return array_filter($this->todos(), fn (array $m) => $m['activo']);
    }

    /**
     * Plataformas activas de transferencia: codigo => etiqueta traducida.
     *
     * @return array<string, string>
     */
    public function plataformasActivas(): array
    {
        $salida = [];

        foreach ((array) config('ventasasesor.pagos.plataformas_transferencia', []) as $codigo => $definicion) {
            if ((bool) (((array) $definicion)['activo'] ?? true)) {
                $salida[(string) $codigo] = (string) trans((string) (((array) $definicion)['etiqueta'] ?? $codigo));
            }
        }

        return $salida;
    }

    /** Etiqueta de cualquier plataforma configurada (activa o no); el codigo si ya no existe. */
    public function etiquetaPlataforma(?string $codigo): ?string
    {
        if ($codigo === null || $codigo === '') {
            return null;
        }

        $definicion = config('ventasasesor.pagos.plataformas_transferencia.'.$codigo);

        return $definicion ? (string) trans((string) ($definicion['etiqueta'] ?? $codigo)) : $codigo;
    }

    /** El medio exige indicar la plataforma donde entro el dinero (flag `plataforma` en la configuracion). */
    public function exigePlataforma(string $codigo): bool
    {
        return (bool) (($this->todos()[$codigo] ?? [])['plataforma'] ?? false);
    }

    /** Medio activo con plataforma sobre el que se registran las lineas de un pago combinado; null si no hay. */
    public function medioParaCombinado(): ?string
    {
        foreach ($this->activos() as $codigo => $medio) {
            if ($medio['plataforma']) {
                return $codigo;
            }
        }

        return null;
    }

    public function existe(string $codigo): bool
    {
        return isset($this->todos()[$codigo]);
    }

    /** Existe y esta activo: el unico caso en que se acepta un medio nuevo. */
    public function estaActivo(string $codigo): bool
    {
        return isset($this->activos()[$codigo]);
    }

    /** Etiqueta traducida; si la clave no existe devuelve la propia clave. Un codigo desconocido lanza. */
    public function etiqueta(string $codigo): string
    {
        $medio = $this->todos()[$codigo] ?? throw new LogicException("Medio de cobro desconocido: '{$codigo}'.");

        return (string) trans($medio['etiqueta']);
    }

    public function politicaReferencia(string $codigo): string
    {
        return ($this->todos()[$codigo] ?? throw new LogicException("Medio de cobro desconocido: '{$codigo}'."))['referencia'];
    }

    public function exigeReferencia(string $codigo): bool
    {
        return $this->politicaReferencia($codigo) === self::REFERENCIA_REQUERIDA;
    }

    /** El medio acepta una referencia (requerida u opcional); `no_aplica` no la guarda. */
    public function admiteReferencia(string $codigo): bool
    {
        return $this->politicaReferencia($codigo) !== self::REFERENCIA_NO_APLICA;
    }

    /**
     * Forma normalizada de una referencia, para detectar repetidas despues (aviso, no bloqueo): sin espacios
     * ni signos, en mayusculas. Null si queda vacia.
     */
    public static function normalizarReferencia(?string $referencia): ?string
    {
        $normalizada = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $referencia));

        return $normalizada === '' ? null : $normalizada;
    }
}
