{{--
    Cualquier listado en papel: las columnas, el alcance, los grupos y los totales los declara su
    definición en App\Impresion. Sin agrupar es un solo grupo sin título. El encabezado de la tabla
    se repite en cada hoja y «Hoja X de Y» lo escribe ImpresionService al terminar, cuando dompdf
    ya sabe cuántas hojas son.
--}}
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>{{ $listado->titulo() }}</title>

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

        body.compacta {
            font-size: 7pt;
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

        .filas thead {
            display: table-header-group;
        }

        .filas tr {
            page-break-inside: avoid;
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

        .compacta .filas th,
        .compacta .filas td {
            padding: 1.5px 4px;
        }

        .compacta .filas th {
            font-size: 6.5pt;
        }

        .filas .derecha {
            text-align: right;
            white-space: nowrap;
        }

        .filas .vacio {
            padding: 10px 6px;
        }

        .grupo {
            font-size: 9.5pt;
            font-weight: bold;
            margin: 12px 0 4px;
            page-break-after: avoid;
        }

        .grupo .tenue {
            font-weight: normal;
            margin-left: 6px;
        }

        .grupo.salto {
            page-break-before: always;
        }

        .subtotal td {
            border-top: 1px solid #999;
            border-bottom: none;
            font-weight: bold;
        }

        .total td {
            border-top: 2px solid #222;
            border-bottom: none;
            padding-top: 6px;
            font-weight: bold;
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
            font-size: 7pt;
            color: #666;
        }
    </style>
</head>

<body class="{{ $opciones->compacta() ? 'compacta' : 'normal' }}">
    <table class="encabezado">
        <tr>
            <td style="width: 55%;">
                <div class="empresa">{{ config('empresa.nombre') }}</div>
                <div class="tenue">
                    @if (config('empresa.cuit'))
                        CUIT {{ config('empresa.cuit') }}
                    @endif
                </div>
            </td>

            <td class="titulo">
                <div class="tipo">{{ $listado->titulo() }}</div>
                <div class="tenue">{{ $emision }}</div>
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
            Sin ningún filtro aplicado.
        @endif
    </div>

    @foreach ($grupos as $grupo)
        @if ($grupo['etiqueta'] !== null)
            <div @class(['grupo', 'salto' => $opciones->saltoDeHoja && !$loop->first]) data-grupo>
                {{ $grupo['etiqueta'] }}
                @if ($grupo['detalle'] && $grupo['detalle'] !== $grupo['etiqueta'])
                    <span class="tenue">{{ $grupo['detalle'] }}</span>
                @endif
            </div>
        @endif

        <table class="filas">
            <thead>
                <tr>
                    @foreach ($opciones->columnas as $columna)
                        <th @class(['derecha' => $columna->aLaDerecha()])>{{ $columna->rotulo }}</th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @forelse ($grupo['filas'] as $fila)
                    <tr>
                        @foreach ($opciones->columnas as $columna)
                            <td @class(['derecha' => $columna->aLaDerecha()])>{{ $columna->paraPdf($fila) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        @if (count($filtros))
                            <td colspan="{{ count($opciones->columnas) }}" class="vacio" data-vacio="filtro">
                                Nada coincide con el alcance de este listado.
                            </td>
                        @else
                            <td colspan="{{ count($opciones->columnas) }}" class="vacio" data-vacio="datos">
                                Todavía no hay datos cargados.
                            </td>
                        @endif
                    </tr>
                @endforelse

                {{-- El subtotal es de todo el grupo en la base, aunque el tope haya cortado sus filas. --}}
                @if ($grupo['subtotal'] !== null)
                    <tr class="subtotal" data-subtotal>
                        @foreach ($opciones->columnas as $columna)
                            <td @class(['derecha' => $columna->aLaDerecha()])>
                                @if ($loop->first)
                                    Subtotal: {{ $listado->cuenta($grupo['subtotal']['cantidad']) }}
                                @endif
                                @if (array_key_exists($columna->clave, $grupo['subtotal']['sumas']))
                                    {{ $columna->enPdf($grupo['subtotal']['sumas'][$columna->clave]) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endif

                @if ($loop->last && $cantidad > 0)
                    <tr class="total">
                        @foreach ($opciones->columnas as $columna)
                            <td @class(['derecha' => $columna->aLaDerecha()])>
                                @if ($loop->first)
                                    Total: {{ $listado->cuenta($cantidad) }}
                                @endif
                                @if (array_key_exists($columna->clave, $sumas))
                                    {{ $columna->enPdf($sumas[$columna->clave]) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
    @endforeach

    @if ($cantidad > $tope)
        {{-- Dice lo que pasó de verdad: que recortó, y que los totales son de todas las coincidencias. --}}
        <div class="aviso" data-recorte>
            Se imprimieron las primeras <strong>{{ \App\Support\Importe::numero($tope, 0) }}</strong> de
            <strong>{{ $listado->cuenta($cantidad) }}</strong> que coinciden, en el orden del listado.
            Los totales son de todas, no sólo de las impresas. Para el listado completo, elegí
            «Planilla (CSV)» en las opciones de impresión.
        </div>
    @endif

    <div class="pie">
        {{ config('empresa.nombre') }} ·
        Informe interno de gestión. No es una factura ni un comprobante fiscal.
    </div>
</body>

</html>
