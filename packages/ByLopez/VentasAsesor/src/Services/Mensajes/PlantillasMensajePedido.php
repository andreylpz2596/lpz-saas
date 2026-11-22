<?php

namespace ByLopez\VentasAsesor\Services\Mensajes;

use ByLopez\Logistica\Enums\TipoTransportadora;
use ByLopez\Logistica\Support\Moneda;
use Carbon\Carbon;

/**
 * Texto de cada mensaje, a partir de los datos que ya armo LectorPedidoParaMensaje. Solo formatea: no consulta nada ni decide
 * si un mensaje corresponde (eso es del servicio). Plantillas controladas por codigo (V1): cortas, claras, con emojis moderados.
 * Los productos salen como los entiende el cliente (nombre, talla, color), nunca el SKU.
 */
class PlantillasMensajePedido
{
    public const RESUMEN = 'resumen';

    public const LISTO_DOMICILIO = 'listo_domicilio';

    public const LISTO_RECOGER = 'listo_recoger';

    public const DESPACHO_COMPLETO = 'despacho_completo';

    public const DESPACHO_PARCIAL = 'despacho_parcial';

    public const DESPACHO_FINAL = 'despacho_final';

    public const ENTREGADO = 'entregado';

    public function __construct(
        protected string $marca,
        protected bool $emojis = true,
        protected ?string $fraseFinal = null,
    ) {}

    /**
     * @param  array<string, mixed>  $d  datos de LectorPedidoParaMensaje::leer
     * @param  array<string, mixed>|null  $envio  el envio al que se refiere (solo mensajes de despacho)
     */
    public function armar(string $tipo, array $d, ?array $envio = null): string
    {
        $parrafos = match ($tipo) {
            self::RESUMEN => $this->resumen($d),
            self::LISTO_DOMICILIO => $this->listoDomicilio($d),
            self::LISTO_RECOGER => $this->listoRecoger($d),
            self::DESPACHO_COMPLETO, self::DESPACHO_PARCIAL, self::DESPACHO_FINAL => $this->despacho($tipo, $d, $envio ?? []),
            self::ENTREGADO => $this->entregado($d),
        };

        return implode("\n\n", array_values(array_filter($parrafos, fn ($p) => $p !== null && $p !== '')));
    }

    /* ---------------------------------------------------------------- Plantillas */

    private function resumen(array $d): array
    {
        $filas = $this->filasDeLineas($d);

        return [
            $this->saludo($d),
            "Este es el resumen de tu pedido de {$this->marca} {$this->numero($d)}.",
            $this->e('👕').'Tu pedido:'."\n".$this->vinetas($filas),
            implode("\n", array_filter([
                $this->e('📦').'Total: '.$this->prendas($this->suma($filas)),
                $this->e('🚚').'Entrega: '.$this->tipoDeEntrega($d),
            ])),
            $d['pedido']['es_recogida'] ? $this->lugarDeRecogida($d) : $this->direccion($d),
            $this->dinero($d),
            $this->despedida(),
        ];
    }

    private function listoDomicilio(array $d): array
    {
        return [
            $this->saludo($d),
            "Tu pedido {$this->marca} {$this->numero($d)} está listo para salir a domicilio.".($this->emojis ? ' 🛵' : ''),
            $this->direccion($d),
            implode("\n", array_filter([$this->e('📦').$this->prendas($d['objetivo']), $this->dinero($d)])),
            'Por favor mantente pendiente del teléfono.',
            $this->despedida(),
        ];
    }

    private function listoRecoger(array $d): array
    {
        return [
            $this->saludo($d),
            "Tu pedido {$this->marca} {$this->numero($d)} ya está listo para recoger.".($this->emojis ? ' 🛍️' : ''),
            $this->lugarDeRecogida($d),
            $this->e('📦').$this->prendas($d['objetivo']),
            $this->despedida(),
        ];
    }

    private function despacho(string $tipo, array $d, array $envio): array
    {
        $enviadas = $this->filasDeEnvio($d, $envio);
        $local = in_array($envio['guia']['tipo'] ?? null, [TipoTransportadora::DOMICILIARIO->value, TipoTransportadora::PROPIA->value], true);
        $guia = $envio['guia'] ?? null;

        $encabezado = match (true) {
            $tipo === self::DESPACHO_COMPLETO => "¡Tu pedido de {$this->marca} {$this->numero($d)} ya fue despachado!".($this->emojis ? ' 📦✨' : ''),
            $tipo === self::DESPACHO_FINAL => "Realizamos el último despacho de tu pedido {$this->marca} {$this->numero($d)}.".($this->emojis ? ' ✅' : '')."\nCon este despacho tu pedido queda completo.",
            ($envio['orden'] ?? 1) > 1 => "Tenemos una nueva actualización de tu pedido {$this->marca} {$this->numero($d)}.\nRealizamos un nuevo despacho parcial.",
            default => "Tenemos una actualización de tu pedido {$this->marca} {$this->numero($d)}.\nRealizamos un despacho parcial.",
        };

        $parcial = $tipo === self::DESPACHO_PARCIAL;
        $enviamos = ($parcial ? $this->e('✅').'Productos enviados:' : $this->e('👕').'Enviamos:')."\n".$this->vinetas($enviadas);

        $pendientes = $parcial ? $this->e('⏳').'Pendientes:'."\n".$this->vinetas($this->filasPendientes($d, $envio)) : null;
        $total = $this->e('📦').'Total despachado: '.$this->prendas($this->suma($enviadas));

        // El domicilio local NO tiene transportadora ni guia externa que inventar: sale a domicilio con la direccion del cliente.
        $transporte = $local
            ? ($this->emojis ? '🛵 ' : '').'Tu pedido va en camino a domicilio.'."\n".$this->direccion($d)
            : $this->bloqueTransportadora($d, $guia);

        $cierre = match (true) {
            $parcial => "Las prendas pendientes continúan asociadas al mismo pedido.\nTe avisaremos cuando realicemos el siguiente despacho.",
            $local => 'Por favor mantente pendiente del teléfono.',
            ($guia['numero'] ?? null) !== null => 'Puedes realizar seguimiento con el número de guía.',
            default => null,
        };

        return [
            $this->saludo($d),
            $encabezado,
            $enviamos,
            $pendientes,
            $total,
            $transporte,
            $parcial ? null : $this->dinero($d),
            $cierre,
            $parcial ? null : $this->despedida(),
        ];
    }

    private function entregado(array $d): array
    {
        $gracias = $this->fraseFinal ?? "Muchas gracias por confiar en {$this->marca}.".($this->emojis ? ' 🤍' : '');
        $nombre = $d['cliente']['nombre'];

        return [
            'Hola'.($nombre !== '' ? ' '.$nombre : '').($this->emojis ? ' 🤍' : ''),
            "Nos aparece que tu pedido {$this->marca} {$this->numero($d)} ya fue entregado.",
            'Esperamos que todo haya llegado correctamente.',
            $gracias,
        ];
    }

    /* ---------------------------------------------------------------- Bloques */

    private function saludo(array $d): string
    {
        $nombre = $d['cliente']['nombre'];

        return 'Hola'.($nombre !== '' ? ' '.$nombre : '').($this->emojis ? ' 👋🏻' : '');
    }

    private function despedida(): string
    {
        return $this->fraseFinal ?? "Gracias por confiar en {$this->marca}.".($this->emojis ? ' 🤍' : '');
    }

    private function numero(array $d): string
    {
        return '#'.$d['pedido']['numero'];
    }

    private function e(string $emoji): string
    {
        return $this->emojis ? $emoji.' ' : '';
    }

    private function prendas(int $n): string
    {
        return $n.' '.($n === 1 ? 'prenda' : 'prendas');
    }

    private function suma(array $filas): int
    {
        return (int) array_sum(array_column($filas, 'cantidad'));
    }

    /** @param  list<array{cantidad: int, etiqueta: string}>  $filas */
    private function vinetas(array $filas): string
    {
        return implode("\n", array_map(fn ($f) => '• '.$f['cantidad'].' '.$f['etiqueta'], $filas));
    }

    private function tipoDeEntrega(array $d): string
    {
        return match (true) {
            $d['pedido']['es_recogida'] => 'Recoger en bodega',
            $d['pedido']['es_domicilio'] => 'Domicilio',
            $d['pedido']['metodo_envio'] === 'envio_nacional' => 'Envío nacional',
            default => 'Envío',
        };
    }

    /** Direccion de entrega del propio pedido (la del envio de ESTE pedido, nunca otra del cliente). */
    private function direccion(array $d): ?string
    {
        $x = $d['destino'];

        if ($x === null || $x['direccion'] === '') {
            return null;
        }

        return $this->e('📍').'Dirección:'."\n".$x['direccion'].($x['ciudad'] !== '' ? ', '.$x['ciudad'] : '');
    }

    /** Lugar de recogida = el elegido en la venta; fecha y horario solo si existen en pickup_orders. */
    private function lugarDeRecogida(array $d): ?string
    {
        $x = $d['destino'];

        if ($x === null) {
            return null;
        }

        $lugar = implode("\n", array_filter([
            $x['empresa'],
            trim($x['direccion'].($x['ciudad'] !== '' ? ', '.$x['ciudad'] : ''), ', '),
        ]));
        $r = $d['recogida'];
        $horario = null;

        if ($r !== null) {
            $fecha = Carbon::parse($r['fecha'])->format('d/m/Y');
            $horario = $this->e('🕒').'Fecha: '.$fecha.($r['desde'] && $r['hasta'] ? ', de '.$r['desde'].' a '.$r['hasta'] : '');
        }

        return implode("\n", array_filter([$this->e('📍').'Puedes recogerlo en:', $lugar, $horario]));
    }

    private function bloqueTransportadora(array $d, ?array $guia): ?string
    {
        if ($guia === null) {
            return null;
        }

        $x = $d['destino'];
        $destino = null;

        if ($x !== null && $x['ciudad'] !== '') {
            $depto = $x['departamento'];
            $repite = $depto === null || mb_stripos($x['ciudad'], $depto) !== false || mb_stripos($depto, $x['ciudad']) !== false;
            $destino = $x['ciudad'].($repite ? '' : ', '.$depto);
        }

        return implode("\n", array_filter([
            $guia['transportadora'] ? $this->e('🚚').'Transportadora: '.$guia['transportadora'] : null,
            $guia['numero'] ? $this->e('🔎').($guia['interna'] ? 'Guía interna: ' : 'Guía: ').$guia['numero'] : null,
            $destino ? $this->e('📍').'Destino: '.$destino : null,
            $guia['tracking'] ? $this->e('🔗').'Seguimiento: '.$guia['tracking'] : null,
        ])) ?: null;
    }

    /**
     * DINERO (decision de V1): siempre el total del pedido; abonos y saldo solo cuando Cartera los entrega sin ambiguedad
     * (el lector ya lo decidio). Nunca «debes pagar»: sin una fuente inequivoca no se afirma lo que falta.
     */
    private function dinero(array $d): ?string
    {
        $m = $d['dinero'];

        if ($m === null) {
            return null;
        }

        $lineas = [$this->e('💰').'Total del pedido: '.Moneda::formatear($m['total'])];

        if ($m['abonos'] !== null) {
            $lineas[] = $this->e('✅').'Abonos aplicados: '.Moneda::formatear($m['abonos']);
            $lineas[] = (float) $m['saldo'] > 0
                ? $this->e('⏳').'Saldo pendiente: '.Moneda::formatear($m['saldo'])
                : $this->e('✅').'Pedido pagado';
        }

        return implode("\n", $lineas);
    }

    /* ---------------------------------------------------------------- Productos */

    /** @return list<array{cantidad: int, etiqueta: string}> */
    private function filasDeLineas(array $d): array
    {
        $filas = [];

        foreach ($d['lineas'] as $l) {
            if ($l['objetivo'] > 0) {
                $filas[] = ['cantidad' => $l['objetivo'], 'etiqueta' => $this->etiqueta($l), 'clave' => $l['producto_id'].'|'.$l['talla'].'|'.$l['color']];
            }
        }

        return $this->consolidar($filas);
    }

    /** @return list<array{cantidad: int, etiqueta: string}> */
    private function filasDeEnvio(array $d, array $envio): array
    {
        $porId = array_column($d['lineas'], null, 'id');
        $filas = [];

        foreach ($envio['unidades'] as $clave => $u) {
            $l = is_int($clave) ? ($porId[$clave] ?? null) : null;
            $filas[] = $l
                ? ['cantidad' => $u['cantidad'], 'etiqueta' => $this->etiqueta($l), 'clave' => $l['producto_id'].'|'.$l['talla'].'|'.$l['color']]
                : ['cantidad' => $u['cantidad'], 'etiqueta' => $u['nombre'], 'clave' => 'n:'.$u['nombre']];
        }

        return $this->consolidar($filas);
    }

    /** @return list<array{cantidad: int, etiqueta: string}> */
    private function filasPendientes(array $d, array $envio): array
    {
        $porId = array_column($d['lineas'], null, 'id');
        $filas = [];

        foreach ($envio['pendientes_despues'] as $id => $cantidad) {
            if (isset($porId[$id]) && $cantidad > 0) {
                $filas[] = ['cantidad' => (int) $cantidad, 'etiqueta' => $this->etiqueta($porId[$id]), 'clave' => $porId[$id]['producto_id'].'|'.$porId[$id]['talla'].'|'.$porId[$id]['color']];
            }
        }

        return $this->consolidar($filas);
    }

    /** Solo se suman lineas IDENTICAS (mismo producto, talla y color): productos distintos con nombre parecido no se mezclan. */
    private function consolidar(array $filas): array
    {
        $unidas = [];

        foreach ($filas as $f) {
            $k = $f['clave'];
            $unidas[$k] ??= ['cantidad' => 0, 'etiqueta' => $f['etiqueta']];
            $unidas[$k]['cantidad'] += $f['cantidad'];
        }

        return array_values($unidas);
    }

    /** «Camiseta Piel Durazno Negro Hombre · Talla M»; el color se agrega solo si el nombre del producto no lo trae ya. */
    private function etiqueta(array $l): string
    {
        return implode(' · ', array_filter([
            $l['nombre'],
            $l['color'] !== null && mb_stripos($l['nombre'], $l['color']) === false ? $l['color'] : null,
            $l['talla'] !== '' ? 'Talla '.$l['talla'] : null,
        ]));
    }
}
