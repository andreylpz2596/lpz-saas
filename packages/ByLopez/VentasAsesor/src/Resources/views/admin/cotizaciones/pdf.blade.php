@php
    use ByLopez\VentasAsesor\Services\Cotizaciones\CotizacionService as Cot;

    $d = fn (float $v) => Cot::dinero($v);
    $t = $cotizacion->totales;
    $e = $cotizacion->entrega ?? [];
    $c = $cotizacion->cliente;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $cotizacion->numero() }}</title>
    <style type="text/css">
        * { margin: 0; padding: 0; font-family: Arial, sans-serif; }
        body { font-size: 10px; color: #091341; }
        .page { padding: 28px 36px; }
        table { width: 100%; border-collapse: collapse; }
        .top td { vertical-align: middle; padding-bottom: 12px; border-bottom: 2px solid #000DBB; }
        .logo { font-size: 22px; font-weight: bold; color: #000DBB; letter-spacing: 1px; }
        .logo small { display: block; font-size: 9px; color: #5E6B9B; font-weight: normal; letter-spacing: 0; margin-top: 2px; }
        .titulo { text-align: right; }
        .titulo h1 { font-size: 24px; color: #000DBB; text-transform: uppercase; letter-spacing: 2px; }
        .titulo .n { font-size: 12px; font-weight: bold; margin-top: 2px; }
        .badge { display: inline-block; margin-top: 6px; padding: 3px 9px; background: #E9EFFC; color: #000DBB; font-size: 9px; font-weight: bold; }
        .meta td { width: 33%; vertical-align: top; padding: 14px 12px 0 0; line-height: 1.6; }
        .lbl { font-size: 8px; text-transform: uppercase; color: #5E6B9B; letter-spacing: 1px; margin-bottom: 3px; font-weight: bold; }
        .items { margin-top: 18px; }
        .items th { background: #000DBB; color: #fff; text-align: left; padding: 7px 8px; font-size: 9px; text-transform: uppercase; }
        .items td { padding: 8px; border-bottom: 1px solid #E9EFFC; vertical-align: top; }
        .r { text-align: right; }
        .sub { color: #5E6B9B; font-size: 9px; margin-top: 2px; }
        .tot { width: 300px; margin: 14px 0 0 auto; }
        .tot td { padding: 5px 8px; }
        .tot .g td { background: #000DBB; color: #fff; font-size: 13px; font-weight: bold; padding: 9px 8px; }
        .domicilio { text-align: right; margin-top: 6px; color: #5E6B9B; font-size: 9px; }
        .dos { margin-top: 20px; }
        .dos td { width: 50%; vertical-align: top; padding-right: 12px; }
        .dos ul { margin-left: 14px; line-height: 1.7; }
        .box { background: #F6F8FF; border: 1px solid #E9EFFC; padding: 10px 12px; line-height: 1.7; }
        .box + .box { margin-top: 6px; }
        .aviso { margin-top: 16px; padding: 9px 12px; border-left: 3px solid #F5A524; background: #FFF8EC; line-height: 1.5; }
        .pie { margin-top: 22px; padding-top: 8px; border-top: 1px solid #E9EFFC; color: #5E6B9B; font-size: 9px; }
    </style>
</head>
<body>
<div class="page">
    <table class="top">
        <tr>
            <td>
                <div class="logo">{{ mb_strtoupper($encabezado['nombre']) }}
                    @if ($encabezado['detalle'])<small>{{ $encabezado['detalle'] }}</small>@endif
                </div>
            </td>
            <td class="titulo">
                <h1>Cotización</h1>
                <div class="n">{{ $cotizacion->numero() }}</div>
                <span class="badge">VÁLIDA HASTA {{ $cotizacion->valida_hasta->format('d/m/Y') }}</span>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td>
                <div class="lbl">Cliente</div>
                <b>{{ $c['nombre'] }}</b>
                @if (! empty($c['telefono']))<br>Cel. {{ $c['telefono'] }}@endif
                @if (! empty($c['email']))<br>{{ $c['email'] }}@endif
            </td>
            <td>
                <div class="lbl">Entrega</div>
                @if (! empty($e['recogida']))
                    Recogida en {{ $e['destino'] ?? 'bodega' }}<br>Sin costo de envío
                @elseif (! empty($e['cobra_domicilio']))
                    {{ $e['destino'] ?? 'Por definir' }}<br>
                    {{ $e['metodo'] ?? 'Domicilio' }}<br>
                    Costo de envío por aparte
                @else
                    Por definir
                @endif
            </td>
            <td>
                <div class="lbl">Detalles</div>
                Fecha: {{ $cotizacion->created_at->format('d/m/Y') }}<br>
                @if ($cotizacion->creada_por_nombre)Asesor: {{ $cotizacion->creada_por_nombre }}<br>@endif
                Versión: {{ $cotizacion->version }}
            </td>
        </tr>
    </table>

    <table class="items">
        <tr>
            <th style="width: 62px">Ref.</th>
            <th>Producto</th>
            <th class="r" style="width: 80px">Precio unit.</th>
            <th class="r" style="width: 40px">Cant.</th>
            <th class="r" style="width: 90px">Subtotal</th>
        </tr>
        @foreach ($filas as $f)
            <tr>
                <td>{{ $f['ref'] }}</td>
                <td><b>{{ $f['producto'] }}</b>@if ($f['detalle'] !== '')<div class="sub">{{ $f['detalle'] }}</div>@endif</td>
                <td class="r">{{ $d($f['precio']) }}</td>
                <td class="r">{{ $f['cantidad'] }}</td>
                <td class="r">{{ $d($f['subtotal']) }}</td>
            </tr>
        @endforeach
    </table>

    <table class="tot">
        <tr><td>Subtotal</td><td class="r">{{ $d($t['subtotal']) }}</td></tr>
        @if ($t['descuento'] > 0)
            <tr><td>Descuento</td><td class="r">- {{ $d($t['descuento']) }}</td></tr>
        @endif
        @if ($t['impuesto'] > 0)
            <tr><td>Impuestos</td><td class="r">{{ $d($t['impuesto']) }}</td></tr>
        @endif
        <tr class="g"><td>TOTAL PRODUCTOS</td><td class="r">{{ $d($t['total_productos']) }}</td></tr>
    </table>
    @if (! empty($e['cobra_domicilio']))
        <div class="domicilio">
            Domicilio <b>no incluido</b>: se cobra por aparte @if (! empty($t['domicilio_estimado'])) (est. {{ $d($t['domicilio_estimado']) }})@endif
        </div>
    @endif

    <table class="dos">
        <tr>
            <td>
                <div class="lbl">Condiciones</div>
                <ul>
                    @foreach ($condiciones as $linea)<li>{{ $linea }}</li>@endforeach
                    <li>Cotización válida hasta el {{ $cotizacion->valida_hasta->format('d/m/Y') }}.</li>
                </ul>
            </td>
            <td>
                @if ($medios !== [])
                    <div class="lbl">Formas de pago</div>
                    @foreach ($medios as $m)
                        <div class="box">
                            <b>{{ $m['medio'] }}</b>
                            @foreach ($m['lineas'] as [$etiqueta, $valor])
                                <br>@if ($etiqueta !== '')<span style="color:#5E6B9B">{{ $etiqueta }}:</span> @endif{{ $valor }}
                            @endforeach
                        </div>
                    @endforeach
                @endif
            </td>
        </tr>
    </table>

    @if ($aviso)
        <div class="aviso">{{ $aviso }}</div>
    @endif

    <div class="pie">
        {{ $encabezado['nombre'] }}@if ($encabezado['contacto']) · {{ $encabezado['contacto'] }}@endif
        — {{ $cotizacion->numero() }}
    </div>
</div>
</body>
</html>
