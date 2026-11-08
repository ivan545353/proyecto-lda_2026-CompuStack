{{--
    El comprobante interno de una entrega. No es un remito RG 1415: no lleva CAI ni numeración
    autorizada, y el pie lo dice. Sólo imprime datos que no cambian —los renglones congelados
    de la venta y lo que salió en esta entrega y en las anteriores—, así que regenerado dentro
    de un año da el mismo papel. No lleva precios: documenta mercadería, no plata.
--}}
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Comprobante de entrega {{ $entrega->numeroFormateado() }}</title>

    <style>
        @page {
            margin: 1.8cm 1.5cm 2.4cm 1.5cm;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9.5pt;
            color: #222;
            margin: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .encabezado td {
            vertical-align: top;
            padding: 0;
        }

        .empresa {
            font-size: 13pt;
            font-weight: bold;
        }

        .tenue {
            color: #666;
            font-size: 8.5pt;
        }

        .titulo {
            text-align: right;
        }

        .titulo .tipo {
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .titulo .numero {
            font-size: 16pt;
            font-weight: bold;
        }

        .linea-divisoria {
            border-top: 2px solid #222;
            margin: 10px 0 14px;
        }

        .bloque {
            border: 1px solid #ccc;
            padding: 8px 10px;
            margin-bottom: 14px;
        }

        .bloque h2 {
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
            margin: 0 0 4px;
        }

        .productos th {
            background: #eee;
            border-bottom: 1px solid #999;
            padding: 5px 6px;
            text-align: left;
            font-size: 8.5pt;
            text-transform: uppercase;
        }

        .productos td {
            border-bottom: 1px solid #e5e5e5;
            padding: 5px 6px;
        }

        .derecha {
            text-align: right;
        }

        .firma td {
            padding: 34px 10px 0;
            text-align: center;
            font-size: 8.5pt;
            color: #666;
        }

        .firma span {
            display: block;
            border-top: 1px solid #222;
            padding-top: 4px;
        }

        .pie {
            position: fixed;
            bottom: -1.5cm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 7.5pt;
            color: #666;
        }
    </style>
</head>

<body>
    <table class="encabezado">
        <tr>
            <td style="width: 55%;">
                <div class="empresa">{{ config('empresa.nombre') }}</div>
                <div class="tenue">
                    @if (config('empresa.cuit'))
                        CUIT {{ config('empresa.cuit') }}<br>
                    @endif
                    @if (config('empresa.direccion'))
                        {{ config('empresa.direccion') }}<br>
                    @endif
                    @if (config('empresa.telefono'))
                        Tel. {{ config('empresa.telefono') }}
                    @endif
                </div>
            </td>

            <td class="titulo">
                <div class="tipo">Comprobante de entrega</div>
                <div class="numero">{{ $entrega->numeroFormateado() }}</div>
                <div class="tenue">Entregado el {{ $entrega->created_at->format('d/m/Y H:i') }}</div>
                <div class="tenue">Venta {{ $venta->numeroFormateado() }}</div>
            </td>
        </tr>
    </table>

    <div class="linea-divisoria"></div>

    <div class="bloque">
        <h2>Cliente</h2>
        <strong>{{ $venta->clienteTexto() }}</strong>
    </div>

    <table class="productos">
        <thead>
            <tr>
                <th style="width: 16%;">Código</th>
                <th>Producto</th>
                <th class="derecha" style="width: 12%;">Vendidas</th>
                <th class="derecha" style="width: 14%;">Entregadas ahora</th>
                <th class="derecha" style="width: 14%;">Quedan por entregar</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($entrega->lineas as $linea)
                @php($renglon = $linea->ventaLinea)
                <tr>
                    <td>{{ $renglon->producto->codigo }}</td>
                    <td>{{ $renglon->descripcion }}</td>
                    <td class="derecha">{{ $renglon->cantidad }}</td>
                    <td class="derecha">{{ $linea->cantidad }}</td>
                    <td class="derecha">{{ max(0, $renglon->cantidad - ($acumulado[$renglon->id] ?? 0)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="tenue" style="margin-top: 14px;">Entregó: {{ $entrega->quienTexto() }}.</p>

    <table class="firma">
        <tr>
            <td><span>Recibí conforme: firma</span></td>
            <td><span>Aclaración</span></td>
            <td><span>Documento</span></td>
        </tr>
    </table>

    <div class="pie">
        {{ $entrega->numeroFormateado() }} · {{ config('empresa.nombre') }} ·
        Comprobante interno de entrega. No es un remito en los términos de la RG 1415 de ARCA,
        no lleva CAI y no es un comprobante fiscal.
    </div>
</body>

</html>
