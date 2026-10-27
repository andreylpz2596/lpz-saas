<?php

namespace ByLopez\VentasAsesor\Services\Clientes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Antecedentes de envios de un cliente segun un PROVEEDOR intermediario (99 Envios): lectura y registro.
 *
 * Son HECHOS REPORTADOS con su procedencia, no una calificacion del cliente ni una entrega de ByLopez:
 *   - «entregado» NO es entrega registrada ni validada en Logistica, ni un pago;
 *   - la clase sale de equivalencias EXPLICITAS (`bylopez_va_estados_envio_clase`); un estado sin equivalencia es
 *     «desconocido» y nunca se cuenta como devolucion ni como entrega.
 *
 * Los conteos son por ENVIO UNICO (no por filas importadas): el mismo envio en varios cargues es un solo renglon, y un
 * envio que ya existe en Logistica (guia de un pedido del cliente con estado reportado) se cuenta UNA vez.
 */
class AntecedentesEnviosClienteService
{
    public const PROVEEDOR_99 = '99envios';

    public const CLASES = ['entregado', 'devolucion', 'pendiente', 'desconocido'];

    // Resultados de registrar().
    public const CREADO = 'creado';

    public const ACTUALIZADO = 'actualizado';

    public const SIN_CAMBIO = 'sin_cambio';

    public const OMITIDO_ANTIGUO = 'omitida_antigua';

    public const CONFLICTO = 'conflicto';

    /**
     * @return array{entregados:int, devoluciones:int, pendientes:int, desconocidos:int, total:int, envios:list<array<string,mixed>>}
     */
    public function resumen(int $customerId): array
    {
        $envios = $this->envios($customerId);
        $cuenta = array_count_values(array_column($envios, 'clase'));

        return [
            'entregados' => $cuenta['entregado'] ?? 0, 'devoluciones' => $cuenta['devolucion'] ?? 0,
            'pendientes' => $cuenta['pendiente'] ?? 0, 'desconocidos' => $cuenta['desconocido'] ?? 0,
            'total' => count($envios), 'envios' => $envios,
        ];
    }

    /**
     * Solo la cabecera (conteos) de varios clientes: para avisar al crear un duplicado sin cargar las listas.
     *
     * @param  list<int>  $customerIds
     * @return array<int, array{entregados:int, devoluciones:int, pendientes:int, desconocidos:int, total:int}>
     */
    public function resumenes(array $customerIds): array
    {
        $r = [];

        foreach ($customerIds as $id) {
            $s = $this->resumen((int) $id);
            unset($s['envios']);
            $s['direcciones_pendientes'] = app(DireccionesPendientesClienteService::class)->contar((int) $id);
            $s['datos_pendientes'] = app(DatosPendientesClienteService::class)->contar((int) $id);
            $r[(int) $id] = $s;
        }

        return $r;
    }

    /** @return list<array<string,mixed>> envios unicos del cliente (cargues + Logistica), mas recientes primero */
    public function envios(int $customerId): array
    {
        if (! Schema::hasTable('bylopez_va_cliente_envios_historicos')) {
            return [];
        }

        $clases = DB::table('bylopez_va_estados_envio_clase')->where('activo', 1)->get(['proveedor', 'estado_texto', 'clase']);
        $clase = function (string $proveedor, ?string $estado) use ($clases): string {
            foreach ($clases as $c) {
                // Misma regla que la BD (colacion unicode_ci): sin distinguir mayusculas ni tildes.
                if ($c->proveedor === $proveedor && $estado !== null && $this->igual($c->estado_texto, $estado)) {
                    return $c->clase;
                }
            }

            return 'desconocido';
        };

        $envios = [];

        foreach (DB::table('bylopez_va_cliente_envios_historicos')->where('customer_id', $customerId)->get() as $h) {
            $envios[] = [
                'proveedor' => $h->proveedor, 'transportadora' => $h->transportadora_clave !== '' && ! str_starts_with($h->transportadora_clave, self::PREFIJO_PENDIENTE) ? $h->transportadora_clave : null, 'transportadora_original' => $h->transportadora_original ?? null, 'guia' => $h->numero_guia,
                'estado' => $h->estado_proveedor, 'clase' => $clase($h->proveedor, $h->estado_proveedor), 'fecha_estado' => $h->fecha_estado,
                'carga_id' => $h->carga_id, 'primer_carga_id' => $h->primer_carga_id, 'fila_origen' => $h->fila_origen, 'nombre_reportado' => $h->nombre_reportado ?? null, 'origen' => 'cargue', 'en_logistica' => false, 'reportes' => (int) $h->reportes,
            ];
        }

        // Envios que ya estan en Logistica (guia de un pedido del cliente con estado reportado): se cuentan UNA vez.
        if (Schema::hasTable('bylopez_lg_guia_estados_proveedor')) {
            $logistica = DB::table('bylopez_lg_guias as g')->join('orders as o', 'o.id', '=', 'g.order_id')
                ->join('bylopez_lg_guia_estados_proveedor as e', 'e.guia_id', '=', 'g.id')
                ->where('o.customer_id', $customerId)
                ->get(['g.numero_guia', 'g.transportadora_codigo', 'e.proveedor', 'e.estado_proveedor', 'e.fecha_estado_proveedor', 'e.carga_id', 'e.fila_origen', 'e.reportes']);

            foreach ($logistica as $l) {
                $dup = null;

                foreach ($envios as $i => $e) {
                    if ($e['origen'] === 'cargue' && $e['guia'] === $l->numero_guia && $e['proveedor'] === $l->proveedor
                        && ($e['transportadora'] === null || $e['transportadora'] === $l->transportadora_codigo)) {
                        $dup = $i;
                        break;
                    }
                }

                $nuevo = [
                    'proveedor' => $l->proveedor, 'transportadora' => $l->transportadora_codigo, 'guia' => $l->numero_guia,
                    'estado' => $l->estado_proveedor, 'clase' => $clase($l->proveedor, $l->estado_proveedor), 'fecha_estado' => $l->fecha_estado_proveedor,
                    'carga_id' => $l->carga_id, 'primer_carga_id' => null, 'fila_origen' => $l->fila_origen, 'nombre_reportado' => null, 'transportadora_original' => null, 'origen' => 'logistica', 'en_logistica' => true, 'reportes' => (int) $l->reportes,
                ];

                if ($dup === null) {
                    $envios[] = $nuevo;
                } else {
                    // El mismo envio: queda UNO, con el reporte mas reciente (a igual fecha, el de Logistica), marcado como ya en Logistica.
                    $viejo = $envios[$dup];
                    $nuevoGana = ($nuevo['fecha_estado'] ?? '') >= ($viejo['fecha_estado'] ?? '');
                    $envios[$dup] = ($nuevoGana ? $nuevo : $viejo) + [];
                    $envios[$dup]['en_logistica'] = true;
                    $envios[$dup]['transportadora'] = $nuevo['transportadora'];
                }
            }
        }

        usort($envios, fn ($a, $b) => strcmp((string) ($b['fecha_estado'] ?? ''), (string) ($a['fecha_estado'] ?? '')) ?: strcmp($a['guia'], $b['guia']));

        return $envios;
    }

    /** Prefijo de la clave de una transportadora INFORMADA que no tiene equivalencia (o es ambigua): cada texto original es un envio distinto. */
    public const PREFIJO_PENDIENTE = 'PEND:';

    /** Clave estable (<= 40 caracteres) de una transportadora original sin equivalencia: «PEND:» + huella del texto normalizado. */
    public static function clavePendiente(string $original): string
    {
        $n = trim((string) preg_replace('/\s+/', ' ', strtolower(\Illuminate\Support\Str::ascii($original))));

        return self::PREFIJO_PENDIENTE.substr(sha1($n), 0, 12);
    }

    /**
     * Registra o actualiza UN envio del cliente. Reglas explicitas de actualizacion (mismo envio en varios archivos):
     *  - mismo envio = (proveedor, transportadora, guia). La transportadora es: la efectiva (equivalencia), o «PEND:huella» cuando el archivo
     *    la informa pero NO tiene equivalencia (se conserva el texto original en `transportadora_original`; dos originales distintos con la
     *    misma guia son envios DISTINTOS), o '' cuando el archivo no la trae (entonces la guia sola si es unica para el proveedor);
     *  - el envio pertenece al cliente que lo registro primero: otro cliente → CONFLICTO (no se reasigna);
     *  - con fecha en ambos gana la mas reciente (la anterior no se aplica; a igual fecha y otro estado → CONFLICTO);
     *  - un reporte SIN fecha comprobada NUNCA reemplaza un estado distinto (con o sin fecha): sin fecha no se puede probar que sea
     *    posterior, asi que queda como «omitida» (SIN_FECHA_NO_REEMPLAZA) para revision; un reporte CON fecha si reemplaza a uno sin fecha;
     *  - el mismo estado no se vuelve a sumar (SIN_CAMBIO); la transportadora desconocida o pendiente se completa si luego llega la efectiva
     *    (o, si falta, el texto original), sin duplicar.
     *
     * @param  ?string  $transportadoraOriginal  texto de la transportadora tal como lo informo el archivo (evidencia)
     * @return array{resultado:string, motivo:?string, anterior:?string, id:?int}
     */
    public function registrar(int $customerId, string $proveedor, string $transportadora, string $guia, string $estado, ?string $fechaEstado, ?int $cargaId, ?int $fila, ?string $nombreReportado = null, ?string $transportadoraOriginal = null): array
    {
        $original = $transportadoraOriginal === null || trim($transportadoraOriginal) === '' ? null : mb_substr(trim($transportadoraOriginal), 0, 120);

        if ($transportadora === '' && $original !== null) {
            $transportadora = self::clavePendiente($original);          // informada, sin equivalencia: NO se mezcla bajo «sin determinar»
        }

        return DB::transaction(function () use ($customerId, $proveedor, $transportadora, $original, $guia, $estado, $fechaEstado, $cargaId, $fila, $nombreReportado) {
            $q = DB::table('bylopez_va_cliente_envios_historicos')->where('proveedor', $proveedor)->whereRaw('BINARY numero_guia = ?', [$guia])->lockForUpdate();
            $mismas = $q->get();
            $fila_ = $mismas->firstWhere('transportadora_clave', $transportadora);

            if (! $fila_ && $mismas->count() > 0) {
                $sinClave = $mismas->firstWhere('transportadora_clave', '');

                if ($transportadora !== '') {
                    // La efectiva llega ahora: completa el envio pendiente con el MISMO texto original; si no, el que no tenia transportadora.
                    $pendienteIgual = $original === null || str_starts_with($transportadora, self::PREFIJO_PENDIENTE) ? null
                        : $mismas->first(fn ($m) => str_starts_with($m->transportadora_clave, self::PREFIJO_PENDIENTE) && $m->transportadora_original !== null && $this->igual($m->transportadora_original, $original));
                    $fila_ = $pendienteIgual ?? $sinClave;
                } elseif ($mismas->count() === 1) {
                    $fila_ = $mismas->first();                // sin transportadora: la guia es unica para el proveedor
                } else {
                    return ['resultado' => self::CONFLICTO, 'motivo' => 'GUIA_AMBIGUA_TRANSPORTADORA', 'anterior' => null, 'id' => null];
                }
            }

            if (! $fila_) {
                $id = DB::table('bylopez_va_cliente_envios_historicos')->insertGetId([
                    'customer_id' => $customerId, 'proveedor' => $proveedor, 'transportadora_clave' => $transportadora, 'transportadora_original' => $original, 'numero_guia' => $guia,
                    'nombre_reportado' => $nombreReportado === null || trim($nombreReportado) === '' ? null : mb_substr(trim($nombreReportado), 0, 191),
                    'estado_proveedor' => $estado, 'fecha_estado' => $fechaEstado, 'primer_carga_id' => $cargaId, 'carga_id' => $cargaId, 'fila_origen' => $fila,
                    'reportes' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);

                return ['resultado' => self::CREADO, 'motivo' => null, 'anterior' => null, 'id' => $id];
            }

            if ((int) $fila_->customer_id !== $customerId) {
                return ['resultado' => self::CONFLICTO, 'motivo' => 'GUIA_DE_OTRO_CLIENTE', 'anterior' => $fila_->estado_proveedor, 'id' => (int) $fila_->id];
            }

            $completaciones = $this->completacionesTransportadora($fila_, $transportadora, $original);
            $mismoEstado = $this->igual($fila_->estado_proveedor, $estado);
            $anteriorFecha = $fila_->fecha_estado;
            $reemplaza = false;
            $motivo = null;

            if ($anteriorFecha !== null && $fechaEstado !== null) {
                $cmp = strcmp($fechaEstado, (string) $anteriorFecha);

                if ($cmp > 0) {
                    $reemplaza = ! $mismoEstado || $fechaEstado !== (string) $anteriorFecha;
                } elseif ($cmp < 0) {
                    return $this->soloTransportadora($fila_, $completaciones, self::OMITIDO_ANTIGUO, 'FECHA_ANTERIOR_A_LA_REGISTRADA');
                } elseif (! $mismoEstado) {
                    return ['resultado' => self::CONFLICTO, 'motivo' => 'CONFLICTO_MISMA_FECHA', 'anterior' => $fila_->estado_proveedor, 'id' => (int) $fila_->id];
                }
            } elseif ($anteriorFecha !== null && $fechaEstado === null) {
                if (! $mismoEstado) {
                    return $this->soloTransportadora($fila_, $completaciones, self::OMITIDO_ANTIGUO, 'SIN_FECHA_NO_REEMPLAZA_UN_ESTADO_FECHADO');
                }
            } elseif ($anteriorFecha === null && $fechaEstado !== null) {
                $reemplaza = true;
            } elseif (! $mismoEstado) {
                // Ninguno tiene fecha: no hay prueba de cual es posterior, y un archivo mas nuevo puede no serlo. No se sobrescribe.
                return $this->soloTransportadora($fila_, $completaciones, self::OMITIDO_ANTIGUO, 'SIN_FECHA_NO_REEMPLAZA');
            }

            if (! $reemplaza && $completaciones === []) {
                return ['resultado' => self::SIN_CAMBIO, 'motivo' => $motivo, 'anterior' => $fila_->estado_proveedor, 'id' => (int) $fila_->id];
            }

            $cambios = ['updated_at' => now()] + $completaciones;

            if ($reemplaza) {
                $cambios += ['estado_proveedor' => $estado, 'fecha_estado' => $fechaEstado, 'carga_id' => $cargaId, 'fila_origen' => $fila, 'reportes' => (int) $fila_->reportes + 1];
            }

            DB::table('bylopez_va_cliente_envios_historicos')->where('id', $fila_->id)->update($cambios);

            return ['resultado' => self::ACTUALIZADO, 'motivo' => $completaciones !== [] && ! $reemplaza ? 'TRANSPORTADORA_COMPLETADA' : null, 'anterior' => $fila_->estado_proveedor, 'id' => (int) $fila_->id];
        });
    }

    /**
     * Lo que un reporte puede COMPLETAR del envio sin tocar su estado: la transportadora efectiva sobre una desconocida ('') o pendiente
     * (PEND:), un pendiente sobre una desconocida, y el texto original si faltaba. Nunca cambia una transportadora ya resuelta.
     *
     * @return array<string,string>
     */
    protected function completacionesTransportadora(object $fila, string $transportadora, ?string $original): array
    {
        $c = [];
        $actual = (string) $fila->transportadora_clave;
        $efectiva = $transportadora !== '' && ! str_starts_with($transportadora, self::PREFIJO_PENDIENTE);

        if ($actual !== $transportadora && ($actual === '' || (str_starts_with($actual, self::PREFIJO_PENDIENTE) && $efectiva))) {
            $c['transportadora_clave'] = $transportadora;
        }

        if ($original !== null && ($fila->transportadora_original ?? null) === null) {
            $c['transportadora_original'] = $original;
        }

        return $c;
    }

    /** Un reporte que no se aplica puede, aun asi, completar la transportadora. */
    protected function soloTransportadora(object $fila, array $completaciones, string $resultado, string $motivo): array
    {
        if ($completaciones !== []) {
            DB::table('bylopez_va_cliente_envios_historicos')->where('id', $fila->id)->update($completaciones + ['updated_at' => now()]);

            return ['resultado' => self::ACTUALIZADO, 'motivo' => 'TRANSPORTADORA_COMPLETADA', 'anterior' => $fila->estado_proveedor, 'id' => (int) $fila->id];
        }

        return ['resultado' => $resultado, 'motivo' => $motivo, 'anterior' => $fila->estado_proveedor, 'id' => (int) $fila->id];
    }

    /** Igualdad sin distinguir mayusculas, tildes ni espacios sobrantes (la misma que usa la colacion de la BD). */
    protected function igual(string $a, string $b): bool
    {
        $n = fn (string $t) => trim((string) preg_replace('/\s+/', ' ', strtolower(\Illuminate\Support\Str::ascii($t))));

        return $n($a) === $n($b);
    }
}
