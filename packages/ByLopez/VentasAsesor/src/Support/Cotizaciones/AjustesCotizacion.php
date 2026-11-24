<?php

namespace ByLopez\VentasAsesor\Support\Cotizaciones;

/**
 * Lee lo que el administrador edita en Configuracion > Ventas Asesor > Cotizaciones (Config/system.php). Un solo lugar para las
 * claves: el servicio y el PDF no repiten nombres. Los campos vacios se omiten, nunca se inventan.
 */
class AjustesCotizacion
{
    private const BASE = 'ventas_asesor.cotizacion.';

    public function vigenciaDias(): int
    {
        $dias = (int) $this->valor('condiciones.vigencia_dias');

        return $dias >= 1 && $dias <= 90 ? $dias : 7;
    }

    /** @return array{nombre: string, detalle: ?string, contacto: ?string} */
    public function encabezado(): array
    {
        return [
            'nombre' => $this->valor('encabezado.empresa_nombre') ?: 'LÓPEZ LÓPEZ',
            'detalle' => $this->valor('encabezado.empresa_detalle'),
            'contacto' => $this->valor('encabezado.empresa_contacto'),
            'logo' => $this->logoParaPdf(),
        ];
    }

    /** Logo de la empresa (el mismo de la factura, Configuracion > Ventas > Facturas) como data URI para dompdf; null si no hay. */
    private function logoParaPdf(): ?string
    {
        $ruta = core()->getConfigData('sales.invoice_settings.pdf_print_outs.logo');

        if (! $ruta || ! \Illuminate\Support\Facades\Storage::disk('public')->exists($ruta)) {
            return null;
        }

        $mime = str_ends_with(strtolower($ruta), '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('public')->get($ruta));
    }

    /**
     * Medios de pago listos para imprimir: cada uno es una lista de lineas `[etiqueta, valor]`; los que no tienen datos no aparecen.
     *
     * @return list<array{medio: string, lineas: list<array{0: string, 1: string}>}>
     */
    public function mediosDePago(): array
    {
        $medios = [];

        if ($banco = $this->bloque('Cuenta', ['banco_tipo_cuenta' => 'Tipo', 'banco_numero' => 'Número', 'banco_titular' => 'Titular'], 'banco_nombre', 'banco_numero')) {
            $medios[] = $banco;
        }

        if ($nequi = $this->bloque('Nequi / Daviplata', ['nequi_numero' => 'Número', 'nequi_titular' => 'Titular'], null, 'nequi_numero')) {
            $medios[] = $nequi;
        }

        if (($llaves = $this->valor('pago.llaves')) !== null) {
            $medios[] = ['medio' => 'Llaves', 'lineas' => [['', $llaves]]];
        }

        if (($otros = $this->valor('pago.otros_medios')) !== null) {
            $medios[] = ['medio' => 'Otros', 'lineas' => [['', $otros]]];
        }

        return $medios;
    }

    /** @return list<string> */
    public function condiciones(): array
    {
        $texto = (string) ($this->valor('condiciones.condiciones') ?? '');

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $texto) ?: []), fn ($l) => $l !== ''));
    }

    public function aviso(): ?string
    {
        return $this->valor('condiciones.aviso');
    }

    /**
     * El medio solo existe si trae el dato que lo hace util (`requerido`): un banco sin numero de cuenta no se imprime.
     *
     * @param  array<string, string>  $campos
     * @return array{medio: string, lineas: list<array{0: string, 1: string}>}|null
     */
    private function bloque(string $titulo, array $campos, ?string $nombreCampo, string $requerido): ?array
    {
        if ($this->valor('pago.'.$requerido) === null) {
            return null;
        }

        $lineas = [];

        foreach ($campos as $campo => $etiqueta) {
            if (($v = $this->valor('pago.'.$campo)) !== null) {
                $lineas[] = [$etiqueta, $v];
            }
        }

        $medio = $nombreCampo !== null ? ($this->valor('pago.'.$nombreCampo) ?: $titulo) : $titulo;

        return $lineas === [] ? null : ['medio' => $medio, 'lineas' => $lineas];
    }

    private function valor(string $ruta): ?string
    {
        $v = core()->getConfigData(self::BASE.$ruta);
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' ? null : $v;
    }
}
