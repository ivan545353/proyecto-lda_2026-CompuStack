{{--
    El listado de ventas, con los filtros que se aplicaron.

    **Es un informe y no un documento del hecho**, y por eso se comporta al revés que
    `ventas/pdf.blade.php`: ahí la fecha es la de emisión y no se imprime el estado,
    porque regenerarlo tiene que dar el mismo papel; acá la fecha es la de impresión y
    el estado sí se imprime, porque lo que el documento declara es cómo estaba el
    listado en ese momento. Un informe que no dijera cuándo se sacó no se podría leer.

    **Declara los filtros aplicados.** Sin eso, el PDF de un listado filtrado es
    indistinguible del completo, y alguien podría leer «total $120.000» como el total
    del mes cuando es el de un cliente.

    Horizontal porque son seis columnas, y los importes no tienen que partirse.
--}}
@use('App\Support\Importe')

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Listado de ventas</title>

    <style>
        @page {
            margin: 1.4cm 1.2cm 2cm 1.2cm;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8.5pt;
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
            font-size: 12pt;
            font-weight: bold;
        }

        .tenue {
            color: #666;
            font-size: 7.5pt;
        }

        .titulo {
            text-align: right;
        }

        .titulo .tipo {
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .linea-divisoria {
            border-top: 2px solid #222;
            margin: 8px 0 12px;
        }

        .bloque {
            border: 1px solid #ccc;
            padding: 6px 8px;
            margin-bottom: 12px;
        }

        .bloque h2 {
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #666;
            margin: 0 0 3px;
        }

        .filas th {
            background: #eee;
            border-bottom: 1px solid #999;
            padding: 4px 6px;
            text-align: left;
            font-size: 7.5pt;
            text-transform: uppercase;
        }

        .filas td {
            border-bottom: 1px solid #e5e5e5;
            padding: 4px 6px;
        }

        .derecha {
            text-align: right;
        }

        .total td {
            border-top: 2px solid #222;
            border-bottom: none;
            padding-top: 6px;
            font-weight: bold;
            font-size: 10pt;
        }

        .aviso {
            border: 1px solid #999;
            background: #f4f4f4;
            padding: 6px 8px;
            margin-top: 10px;
            font-size: 7.5pt;
        }

        .pie {
            position: fixed;
            bottom: -1.2cm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 7pt;
            color: #666;
        }
    </style>
</head>

<body>
    <table class="encabezado">
        <tr>
            <td style="width: 60%;">
                <div class="empresa">{{ config('empresa.nombre') }}</div>
                <div class="tenue">
                    @if (config('empresa.cuit'))
                        CUIT {{ config('empresa.cuit') }}
                    @endif
                </div>
            </td>

            <td class="titulo">
                <div class="tipo">Listado de ventas</div>
                {{-- Acá sí la fecha de impresión: es un informe de un momento. --}}
                <div class="tenue">Generado el {{ now()->format('d/m/Y') }} a las {{ now()->format('H:i') }}</div>
            </td>
        </tr>
    </table>

    <div class="linea-divisoria"></div>

    <div class="bloque">
        <h2>Alcance del listado</h2>

        @if (count($filtros))
            @foreach ($filtros as $etiqueta => $valor)
                <strong>{{ $etiqueta }}:</strong> {{ $valor }}@if (!$loop->last)
                    ·
                @endif
            @endforeach
        @else
            Todas las ventas, sin ningún filtro aplicado.
        @endif
    </div>

    <table class="filas">
        <thead>
            <tr>
                <th style="width: 10%;">Número</th>
                <th style="width: 12%;">Fecha</th>
                <th>Cliente</th>
                <th style="width: 20%;">Vendedor</th>
                <th style="width: 14%;">Estado</th>
                <th class="derecha" style="width: 15%;">Total</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($ventas as $venta)
                <tr>
                    <td>{{ $venta->numeroFormateado() }}</td>
                    <td>{{ $venta->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $venta->clienteTexto() }}</td>
                    <td>
                        @if ($venta->usuario)
                            {{ $venta->usuario->apellido }}, {{ $venta->usuario->nombre }}
                        @else
                            —
                        @endif
                    </td>
                    {{-- El estado en palabras, no el valor crudo de la columna. --}}
                    <td>{{ $venta->estadoTexto() }}</td>
                    <td class="derecha">{{ Importe::pesos($venta->total) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="padding: 10px 6px;">
                        Ninguna venta coincide con esos filtros.
                    </td>
                </tr>
            @endforelse

            @if ($cantidad > 0)
                <tr class="total">
                    <td colspan="5" class="derecha">Total de {{ $cantidad }} venta(s)</td>
                    <td class="derecha">{{ Importe::pesos($total) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($cantidad > $tope)
        {{-- El documento dice lo que pasó de verdad: que se recortó, y que el total de
             arriba es de todas las coincidencias y no sólo de las impresas. --}}
        <div class="aviso">
            Se imprimieron las primeras <strong>{{ $tope }}</strong> de
            <strong>{{ $cantidad }}</strong> ventas que coinciden, ordenadas de la más
            nueva a la más vieja. El total corresponde a las {{ $cantidad }}, no a las
            {{ $tope }} listadas. Para un listado más corto, acotá el período o agregá
            un filtro.
        </div>
    @endif

    <div class="pie">
        {{ config('empresa.nombre') }} ·
        Informe interno de gestión. No es una factura ni un comprobante fiscal.
    </div>
</body>

</html>
