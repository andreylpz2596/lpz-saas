<?php

namespace ByLopez\VentasAsesor\Services\Mensajes;

use ByLopez\Logistica\Support\Moneda;
use Carbon\CarbonImmutable;

/**
 * Texto del «Estado de cuenta» del cliente para WhatsApp (Doc. 31 §11). Solo formatea: los pedidos y saldos ya los decidio
 * MensajeCarteraClienteService con los saldos de Cartera. Pedidos agrupados por DIA (del mas antiguo al mas nuevo, el orden
 * FIFO de Cartera), una linea por pedido con su saldo; total y abonado entre parentesis solo si hubo abono.
 */
class PlantillaEstadoCuenta
{
    /** Pedidos que se listan; el resto se resume en una linea (el total siempre los incluye a todos). */
    public const MAXIMO_PEDIDOS = 10;

    private const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(
        protected string $marca,
        protected bool $emojis = true,
        protected ?string $fraseFinal = null,
    ) {}

    /**
     * @param  array{
     *     cliente: string,
     *     fecha: string,
     *     pedidos: list<array{numero: string, fecha: string, total: float, abonado: float, saldo: float}>,
     *     en_verificacion: float,
     *     medios: list<array{medio: string, lineas: list<array{0: string, 1: string}>}>
     * }  $d  `fecha` (hoy) y la de cada pedido en Y-m-d
     */
    public function armar(array $d): string
    {
        $pedidos = $d['pedidos'];
        $saludo = 'Hola'.($d['cliente'] !== '' ? ' '.$d['cliente'] : '').($this->emojis ? ' 👋🏻' : '');

        if ($pedidos === []) {
            return $this->unir([
                $saludo,
                '¡Estás al día! No tienes saldos pendientes con '.$this->marca.'.'.($this->emojis ? ' 🤍' : ''),
                $this->despedida(),
            ]);
        }

        $total = round(array_sum(array_column($pedidos, 'saldo')), 2);
        $n = count($pedidos);

        return $this->unir([
            $saludo,
            'Te compartimos el estado de tu cuenta en '.$this->marca.' al '.$this->fechaLarga($d['fecha']).'.',
            $this->e('🧾').'Pedidos con saldo pendiente:',
            $this->listado($pedidos),
            $this->e('💰').'Total pendiente: '.Moneda::formatear($total).' ('.$n.' '.($n === 1 ? 'pedido' : 'pedidos').')',
            $d['en_verificacion'] > 0
                ? $this->e('ℹ️').'Incluye abonos por '.Moneda::formatear($d['en_verificacion']).' que estamos verificando.'
                : null,
            $this->medios($d['medios']),
            'Cuando realices el pago, envíanos el comprobante por este medio.'.($this->emojis ? ' 🙌' : ''),
            $this->despedida(),
        ]);
    }

    /** @param  list<array{numero: string, fecha: string, total: float, abonado: float, saldo: float}>  $pedidos */
    private function listado(array $pedidos): string
    {
        $visibles = array_slice($pedidos, 0, self::MAXIMO_PEDIDOS);
        $resto = array_slice($pedidos, self::MAXIMO_PEDIDOS);
        $bloques = [];
        $dia = null;

        foreach ($visibles as $p) {
            if ($p['fecha'] !== $dia) {
                $dia = $p['fecha'];
                $bloques[] = [$this->e('📅').$this->fechaCorta($dia)];
            }

            $linea = '• #'.$p['numero'].' · Saldo '.Moneda::formatear($p['saldo']);

            if ($p['abonado'] > 0) {
                $linea .= ' (total '.Moneda::formatear($p['total']).', abonado '.Moneda::formatear($p['abonado']).')';
            }

            $bloques[array_key_last($bloques)][] = $linea;
        }

        $texto = implode("\n\n", array_map(fn (array $b) => implode("\n", $b), $bloques));

        if ($resto !== []) {
            $k = count($resto);
            $texto .= "\n\n… y ".$k.' '.($k === 1 ? 'pedido más' : 'pedidos más').' por '.Moneda::formatear(array_sum(array_column($resto, 'saldo')));
        }

        return $texto;
    }

    /** @param  list<array{medio: string, lineas: list<array{0: string, 1: string}>}>  $medios */
    private function medios(array $medios): ?string
    {
        if ($medios === []) {
            return null;
        }

        $vinetas = [];

        foreach ($medios as $m) {
            $campos = [];

            foreach ($m['lineas'] as [$etiqueta, $valor]) {
                $campos[$etiqueta] = $valor;
            }

            $nombre = $m['medio'].(isset($campos['Tipo']) ? ' ('.$campos['Tipo'].')' : '');
            $valor = $campos['Número'] ?? ($campos[''] ?? '');
            $vinetas[] = '• '.$nombre.': '.$valor.(isset($campos['Titular']) ? ' · Titular: '.$campos['Titular'] : '');
        }

        return $this->e('💳').'Puedes pagar a:'."\n".implode("\n", $vinetas);
    }

    private function despedida(): string
    {
        return $this->fraseFinal ?? 'Gracias por confiar en '.$this->marca.'.'.($this->emojis ? ' 🤍' : '');
    }

    private function fechaLarga(string $ymd): string
    {
        $f = CarbonImmutable::parse($ymd);

        return $f->day.' de '.self::MESES[$f->month].' de '.$f->year;
    }

    private function fechaCorta(string $ymd): string
    {
        $f = CarbonImmutable::parse($ymd);

        return $f->day.' de '.self::MESES[$f->month];
    }

    private function e(string $emoji): string
    {
        return $this->emojis ? $emoji.' ' : '';
    }

    /** @param  list<?string>  $parrafos */
    private function unir(array $parrafos): string
    {
        return implode("\n\n", array_values(array_filter($parrafos, fn ($p) => $p !== null && $p !== '')));
    }
}
