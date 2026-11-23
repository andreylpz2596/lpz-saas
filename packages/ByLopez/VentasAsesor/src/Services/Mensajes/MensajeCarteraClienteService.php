<?php

namespace ByLopez\VentasAsesor\Services\Mensajes;

use ByLopez\VentasAsesor\DataObjects\SaldoPedido;
use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Exceptions\MensajeCarteraNoDisponibleException as NoDisponible;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use ByLopez\VentasAsesor\Support\Cotizaciones\AjustesCotizacion;
use ByLopez\VentasAsesor\Support\Mensajes\AjustesMensajes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * «Estado de cuenta» del cliente para WhatsApp (Doc. 31 §11). SOLO LECTURA: los saldos son los de Cartera
 * (CarteraConsultaService::saldosDePedidos), el mismo calculo de la pantalla Cartera del cliente; no se recalcula nada.
 *
 * Reglas aprobadas (2026-11-22):
 *  - Solo lo genera quien ve TODA la cartera (alcance «todos»): con «ver propios» el total saldria incompleto si el cliente le
 *    debe a otra asesora.
 *  - Entran los pedidos dentro de Cartera con saldo > 0 y SIN revision pendiente. Los «por revisar» y los anteriores a la fecha
 *    de corte no salen en el texto: se avisan a quien lo genera (`avisos`, nunca al cliente).
 *  - Los abonos registrados sin confirmar ya los resta Cartera: el texto lo aclara («que estamos verificando»).
 *  - El saldo a favor (credito D10) NO se menciona: no se resta solo y es una decision pendiente.
 */
class MensajeCarteraClienteService
{
    public const ESTADO_CUENTA = 'estado-cuenta';

    public function __construct(
        protected CarteraConsultaService $cartera,
        protected AutorizacionVentasAsesor $autorizacion,
        protected FechaCorteCartera $fechaCorte,
        protected AjustesMensajes $ajustes,
        protected AjustesCotizacion $cotizacion,
    ) {}

    /** @return list<array{clave: string, tipo: string, etiqueta: string}> */
    public function opciones(int $clienteId, Admin $actor): array
    {
        $this->autorizar($clienteId, $actor);

        if (! $this->fechaCorte->activa()) {
            return [];
        }

        return [['clave' => self::ESTADO_CUENTA, 'tipo' => 'estado_cuenta', 'etiqueta' => trans('ventasasesor::app.mensajes.etiquetas.estado_cuenta')]];
    }

    /** @return array{clave: string, tipo: string, etiqueta: string, texto: string, telefono: ?string, avisos: list<string>, cliente: array{id: int, nombre: string}} */
    public function generar(int $clienteId, string $clave, Admin $actor): array
    {
        $cliente = $this->autorizar($clienteId, $actor);

        if ($clave !== self::ESTADO_CUENTA || ! $this->fechaCorte->activa()) {
            throw new NoDisponible(NoDisponible::MENSAJE_NO_DISPONIBLE);
        }

        $pedidos = DB::table('orders')->where('customer_id', $clienteId)->orderBy('created_at')->orderBy('id')
            ->get(['id', 'created_at'])->keyBy('id');
        $incluidos = [];
        $porRevisar = 0;
        $anteriores = 0;
        $enVerificacion = 0.0;

        foreach ($this->cartera->saldosDePedidos($pedidos->keys()->all()) as $id => $s) {
            if (! $s->enCartera) {
                $anteriores += $s->motivoFuera === SaldoPedido::FUERA_ANTERIOR_A_CORTE ? 1 : 0;

                continue;
            }

            if ($s->requiereRevision) {
                $porRevisar++;

                continue;
            }

            if ((float) $s->saldo <= 0) {
                continue;
            }

            $incluidos[] = [
                'numero' => $s->incrementId,
                'fecha' => substr((string) $pedidos[$id]->created_at, 0, 10),
                'total' => (float) ($s->totalOperativo ?? $s->total),
                'abonado' => (float) $s->aplicadoTotal,
                'saldo' => (float) $s->saldo,
            ];
            $enVerificacion += (float) $s->sinRevisar;
        }

        $texto = (new PlantillaEstadoCuenta($this->ajustes->marca(), $this->ajustes->usarEmojis(), $this->ajustes->fraseFinal()))->armar([
            'cliente' => $this->primerNombre((string) $cliente->first_name),
            'fecha' => CarbonImmutable::now(config('app.timezone'))->toDateString(),
            'pedidos' => $incluidos,
            'en_verificacion' => round($enVerificacion, 2),
            'medios' => $incluidos === [] ? [] : $this->cotizacion->mediosDePago(),
        ]);

        $avisos = [];

        if ($porRevisar > 0) {
            $avisos[] = trans('ventasasesor::app.mensajes.avisos.por_revisar', ['n' => $porRevisar]);
        }

        if ($anteriores > 0) {
            $avisos[] = trans('ventasasesor::app.mensajes.avisos.anteriores', ['n' => $anteriores, 'fecha' => $this->fechaCorte->valor()?->format('d/m/Y')]);
        }

        return [
            'clave' => self::ESTADO_CUENTA,
            'tipo' => 'estado_cuenta',
            'etiqueta' => trans('ventasasesor::app.mensajes.etiquetas.estado_cuenta'),
            'texto' => $texto,
            'telefono' => ($t = trim((string) $cliente->phone)) !== '' ? $t : null,
            'avisos' => $avisos,
            'cliente' => ['id' => (int) $cliente->id, 'nombre' => trim($cliente->first_name.' '.$cliente->last_name)],
        ];
    }

    /** Alcance «todos» antes de revelar si el cliente existe. */
    private function autorizar(int $clienteId, Admin $actor): object
    {
        if ($this->autorizacion->alcanceDeCartera($actor) !== AlcanceVisibilidad::TODOS) {
            throw new NoDisponible(NoDisponible::SIN_PERMISO);
        }

        $cliente = DB::table('customers')->where('id', $clienteId)->first(['id', 'first_name', 'last_name', 'phone']);

        if ($cliente === null) {
            throw new NoDisponible(NoDisponible::CLIENTE_INEXISTENTE);
        }

        return $cliente;
    }

    private function primerNombre(string $nombre): string
    {
        $primero = preg_split('/\s+/u', trim($nombre))[0] ?? '';

        return $primero === '' ? '' : mb_convert_case(mb_strtolower($primero), MB_CASE_TITLE);
    }
}
