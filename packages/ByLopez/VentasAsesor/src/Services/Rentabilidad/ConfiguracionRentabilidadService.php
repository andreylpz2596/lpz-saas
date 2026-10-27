<?php

namespace ByLopez\VentasAsesor\Services\Rentabilidad;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\CostoDirecto;
use ByLopez\VentasAsesor\Models\CostoEstado;
use ByLopez\VentasAsesor\Models\RentabilidadPolitica;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Comisiones\ProgramadorDeVersiones;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\User\Contracts\Admin;

/**
 * R1 — configuracion de rentabilidad: politicas de ganancia minima, costos directos estimados y estado del costo estandar.
 * TODO nace vacio (A-07): sin politica la evaluacion dice «sin politica»; sin costo directo configurado se asume solo lo
 * configurado (nunca un valor inventado), y la vista de diagnostico lo marca como configuracion faltante.
 */
class ConfiguracionRentabilidadService
{
    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected ProgramadorDeVersiones $versiones,
    ) {}

    /* ------------------------------------------------------------------ lecturas ------------------------------------------------------------------ */

    /**
     * Politicas vigentes en la fecha, indexadas por «familia|tela» (0 = cualquiera; «0|0» = defecto). La resolucion de la
     * mas especifica la hace EvaluadorRentabilidad::politicaPara().
     *
     * @return array<string, RentabilidadPolitica>
     */
    public function politicasVigentes(CarbonInterface $fecha): array
    {
        $porClave = [];

        foreach (RentabilidadPolitica::query()->vigentesEn($fecha)->orderBy('version')->get() as $p) {
            $porClave[EvaluadorRentabilidad::clavePolitica($p->familia_id, $p->tipo_tela_id)] = $p;
        }

        return $porClave;
    }

    /** @return array<int, string> id → nombre de las opciones del atributo de tipo de tela */
    public function telas(): array
    {
        $atributo = DB::table('attributes')->where('code', (string) config('ventasasesor.rentabilidad.atributo_tela', 'tipo_tela'))->value('id');

        return $atributo === null ? [] : DB::table('attribute_options')->where('attribute_id', $atributo)->orderBy('sort_order')->orderBy('admin_name')
            ->pluck('admin_name', 'id')->map(fn ($n) => trim((string) $n))->all();
    }

    /** @return Collection<int, CostoDirecto> */
    public function costosDirectosVigentes(CarbonInterface $fecha): Collection
    {
        // Por codigo solo la ultima version vigente (el programador ya impide solapes; es defensa).
        return CostoDirecto::query()->vigentesEn($fecha)->orderBy('codigo')->orderByDesc('version')->get()->unique('codigo')->values();
    }

    /**
     * Estado del costo estandar por familia en la fecha: confirmado | provisional | vencido | sin_estado.
     *
     * @param  list<int>  $familias
     * @return array<int, string>
     */
    public function estadosDeCosto(array $familias, CarbonInterface $fecha): array
    {
        $estados = array_fill_keys($familias, 'sin_estado');

        foreach (CostoEstado::query()->whereIn('familia_id', $familias ?: [0])->vigentesEn($fecha)->orderBy('version')->get() as $e) {
            $vencido = $e->revisar_hasta !== null && $e->revisar_hasta->lt($fecha->copy()->startOfDay());
            $estados[(int) $e->familia_id] = $vencido ? 'vencido' : $e->estado;
        }

        return $estados;
    }

    /** @return array<int, string> id → nombre de las familias (referencias) de Bagisto */
    public function familias(): array
    {
        return DB::table('attribute_families')->orderBy('name')->pluck('name', 'id')->map(fn ($n) => trim((string) $n))->all();
    }

    /** Vista de configuracion: vigentes, programadas e historial de cada tipo. */
    public function panel(): array
    {
        return [
            'politicas' => RentabilidadPolitica::query()->orderBy('familia_clave')->orderBy('tela_clave')->orderByDesc('version')->get(),
            'costos_directos' => CostoDirecto::query()->orderBy('codigo')->orderByDesc('version')->get(),
            'estados_costo' => CostoEstado::query()->orderBy('familia_id')->orderByDesc('version')->get(),
            'familias' => $this->familias(),
            'telas' => $this->telas(),
            'metodos_envio' => $this->metodosEnvio(),
            'metodos_pago' => $this->metodosPago(),
        ];
    }

    /* ------------------------------------------------------------------ escritura ----------------------------------------------------------------- */

    /** @param  array{familia_id?: mixed, tipo_tela_id?: mixed, ganancia_minima?: mixed, margen_minimo?: mixed, redondeo?: mixed, vigente_desde?: ?string, motivo: string}  $d */
    public function programarPolitica(?Admin $actor, array $d): RentabilidadPolitica
    {
        $this->exigirConfigurar($actor);

        $familia = $this->familiaOpcional($d['familia_id'] ?? null);
        $tela = $this->telaOpcional($d['tipo_tela_id'] ?? null);
        $ganancia = $this->importe($d['ganancia_minima'] ?? null, 'La ganancia minima');
        $margen = $this->porcentajeOpcional($d['margen_minimo'] ?? null, 'El margen minimo');
        $redondeo = trim((string) ($d['redondeo'] ?? ''));

        if ($redondeo !== '' && (! ctype_digit($redondeo) || (int) $redondeo < 1)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El escalon de redondeo debe ser un entero positivo (p. ej. 100) o quedar vacio.');
        }

        return $this->versiones->programar(
            RentabilidadPolitica::class,
            // Cada combinacion referencia + tela (cualquiera de las dos puede quedar vacia) se versiona por separado.
            fn ($q) => $q->where('familia_clave', (int) $familia)->where('tela_clave', (int) $tela),
            [
                'familia_id' => $familia,
                'tipo_tela_id' => $tela,
                'ganancia_minima_unidad' => $ganancia,
                'margen_minimo_pct' => $margen,
                'redondeo_escalon' => $redondeo === '' ? null : (int) $redondeo,
            ],
            $d['vigente_desde'] ?? null,
            $actor,
            (string) ($d['motivo'] ?? ''),
        );
    }

    /** @param  array<string, mixed>  $d */
    public function programarCostoDirecto(?Admin $actor, array $d): CostoDirecto
    {
        $this->exigirConfigurar($actor);

        $concepto = (string) ($d['concepto'] ?? '');
        $tipo = (string) ($d['tipo_calculo'] ?? '');
        $fuente = (string) ($d['fuente_real'] ?? '');

        if (! in_array($concepto, CostoDirecto::CONCEPTOS, true) || ! in_array($tipo, CostoDirecto::TIPOS, true) || ! in_array($fuente, CostoDirecto::FUENTES_REALES, true)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'Concepto, tipo de calculo o fuente real no validos.');
        }

        $base = $tipo === 'porcentaje' ? (string) ($d['base_porcentaje'] ?? '') : null;

        if ($tipo === 'porcentaje' && ! in_array($base, CostoDirecto::BASES, true)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'Un costo porcentual debe declarar sobre que base se cobra.');
        }

        $valor = $tipo === 'porcentaje'
            ? $this->porcentajeOpcional($d['valor'] ?? null, 'El porcentaje') ?? throw new Invalida(Invalida::DATOS_INVALIDOS, 'Indique el porcentaje.')
            : $this->importe($d['valor'] ?? null, 'El valor');

        $nombre = trim((string) ($d['nombre'] ?? ''));

        if ($nombre === '') {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'Escriba un nombre para el costo.');
        }

        // El codigo identifica el costo a traves de sus versiones; uno nuevo se crea desde el nombre.
        $codigo = trim((string) ($d['codigo'] ?? '')) ?: Str::slug($concepto.' '.$nombre, '_');

        return $this->versiones->programar(
            CostoDirecto::class,
            fn ($q) => $q->where('codigo', $codigo),
            [
                'codigo' => mb_substr($codigo, 0, 60),
                'concepto' => $concepto,
                'nombre' => mb_substr($nombre, 0, 120),
                'tipo_calculo' => $tipo,
                'base_porcentaje' => $base,
                'valor' => $valor,
                'familia_id' => $this->familiaOpcional($d['familia_id'] ?? null),
                'metodo_envio' => $this->codigoOpcional($d['metodo_envio'] ?? null),
                'metodo_pago' => $this->codigoOpcional($d['metodo_pago'] ?? null),
                'fuente_real' => $fuente,
            ],
            $d['vigente_desde'] ?? null,
            $actor,
            (string) ($d['motivo'] ?? ''),
        );
    }

    /** @param  array{familia_id?: mixed, estado?: mixed, revisar_hasta?: ?string, vigente_desde?: ?string, motivo: string}  $d */
    public function programarEstadoCosto(?Admin $actor, array $d): CostoEstado
    {
        $this->exigirConfigurar($actor);

        $familia = $this->familiaOpcional($d['familia_id'] ?? null) ?? throw new Invalida(Invalida::DATOS_INVALIDOS, 'Elija la referencia.');
        $estado = (string) ($d['estado'] ?? '');

        if (! in_array($estado, [CostoEstado::CONFIRMADO, CostoEstado::PROVISIONAL], true)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El estado debe ser confirmado o provisional.');
        }

        $revisar = trim((string) ($d['revisar_hasta'] ?? ''));

        if ($revisar !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $revisar)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'Fecha de revision no valida.');
        }

        return $this->versiones->programar(
            CostoEstado::class,
            fn ($q) => $q->where('familia_id', $familia),
            ['familia_id' => $familia, 'estado' => $estado, 'revisar_hasta' => $revisar === '' ? null : $revisar],
            $d['vigente_desde'] ?? null,
            $actor,
            (string) ($d['motivo'] ?? ''),
        );
    }

    /* ------------------------------------------------------------------ apoyo --------------------------------------------------------------------- */

    /** @return array<string, string> codigo → titulo de los metodos de envio activos de Bagisto */
    public function metodosEnvio(): array
    {
        $salida = [];

        foreach ((array) config('carriers', []) as $codigo => $c) {
            $salida[(string) $codigo] = (string) ($c['title'] ?? $codigo);
        }

        return $salida;
    }

    /** @return array<string, string> */
    public function metodosPago(): array
    {
        $salida = [];

        foreach ((array) config('payment_methods', []) as $codigo => $m) {
            $salida[(string) $codigo] = (string) ($m['title'] ?? $codigo);
        }

        return $salida;
    }

    private function exigirConfigurar(?Admin $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_RENTABILIDAD_CONFIGURAR)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tiene permiso para configurar la rentabilidad.');
        }
    }

    private function telaOpcional(mixed $v): ?int
    {
        $v = trim((string) $v);

        if ($v === '' || $v === '0') {
            return null;
        }

        if (! ctype_digit($v) || ! array_key_exists((int) $v, $this->telas())) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El tipo de tela elegido no existe.');
        }

        return (int) $v;
    }

    private function familiaOpcional(mixed $v): ?int
    {
        $v = trim((string) $v);

        if ($v === '' || $v === '0') {
            return null;
        }

        if (! ctype_digit($v) || ! DB::table('attribute_families')->where('id', (int) $v)->exists()) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'La referencia elegida no existe.');
        }

        return (int) $v;
    }

    private function importe(mixed $v, string $campo): string
    {
        $v = trim((string) $v);

        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, "{$campo} debe ser un valor en pesos de 0 o mas.");
        }

        return $v;
    }

    private function porcentajeOpcional(mixed $v, string $campo): ?string
    {
        $v = trim((string) $v);

        if ($v === '') {
            return null;
        }

        if (! preg_match('/^\d{1,2}(\.\d{1,4})?$/', $v)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, "{$campo} debe estar entre 0 y 99,9999 %.");
        }

        return $v;
    }

    private function codigoOpcional(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, 100);
    }
}
