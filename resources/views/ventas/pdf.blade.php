{{--
    El documento de la venta: presupuesto si todavía no se cobró, comprobante si sí.

    **No es un comprobante fiscal** y el pie lo dice con palabras: no lo gobierna AFIP,
    no lleva CAE ni numeración autorizada, y su número es el `id` formateado (A-18). La
    factura con CAE y QR vive en `comprobantes`, en la Etapa 2.

    **Sólo imprime datos congelados**, que es lo que permite no archivar el archivo: las
    líneas con su precio, los importes de la venta, el cliente, el vendedor y la fecha
    de emisión. Nunca el estado, ni los pagos, ni `cantidad_devuelta` — igual que el PDF
    del pedido imprime `cantidad_pedida` y nunca `cantidad_recibida`. Así, regenerarlo
    dentro de un año da el mismo papel.

    No discrimina IVA: discriminar es lo que hace una Factura A, y éste no es ese
    documento. La alícuota queda congelada en la línea para cuando la Etapa 2 emita.

    Dompdf no interpreta flexbox ni grid, así que la maquetación va con tablas, y la
    hoja de estilos va embebida.
--}}
@use('App\Models\Cliente')
@use('App\Support\Importe')

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>{{ $venta->esPresupuesto() ? 'Presupuesto' : 'Comprobante de venta' }} {{ $venta->numeroFormateado() }}
    </title>

    <style>
        @page {
            margin: 1.8cm 1.5cm 2.4cm 1.5cm;
        }

        body {
            /* DejaVu Sans es la única fuente que dompdf trae con acentos y ñ. */
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

        .importes {
            width: 45%;
            margin-left: 55%;
            margin-top: 10px;
        }

        .importes td {
            padding: 3px 6px;
        }

        .importes .total td {
            border-top: 2px solid #222;
            padding-top: 7px;
            font-weight: bold;
            font-size: 11pt;
        }

        /* Dompdf repite los elementos `fixed` en todas las páginas: es la forma de
           tener un pie de página sin escribir PHP dentro de la plantilla. */
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
                        {{ config('empresa.direccion') }}@if (config('empresa.localidad'))
                            , {{ config('empresa.localidad') }}
                        @endif
                        <br>
                    @endif
                    @if (config('empresa.telefono'))
                        Tel. {{ config('empresa.telefono') }}
                    @endif
                    @if (config('empresa.email'))
                        · {{ config('empresa.email') }}
                    @endif
                </div>
            </td>

            <td class="titulo">
                <div class="tipo">{{ $venta->esPresupuesto() ? 'Presupuesto' : 'Comprobante de venta' }}</div>
                <div class="numero">{{ $venta->numeroFormateado() }}</div>
                {{-- La fecha del hecho y no la de impresión. --}}
                <div class="tenue">Emitido el {{ $venta->created_at->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <div class="linea-divisoria"></div>

    <div class="bloque">
        <h2>Cliente</h2>

        @if ($venta->cliente)
            <strong>{{ $venta->cliente->razon_social }}</strong>

            <div class="tenue">
                {{ Cliente::TIPOS_DOC[$venta->cliente->tipo_doc] ?? $venta->cliente->tipo_doc }}
                {{ $venta->cliente->nro_doc }}
                · {{ Cliente::CONDICIONES_IVA[$venta->cliente->condicion_iva] ?? $venta->cliente->condicion_iva }}
                @if ($venta->cliente->email)
                    <br>{{ $venta->cliente->email }}
                @endif
                @if ($venta->cliente->telefono)
                    · Tel. {{ $venta->cliente->telefono }}
                @endif
            </div>
        @else
            {{-- Null significa consumidor final: es la venta rápida de mostrador que no
                 identifica a nadie, no un dato que falte. --}}
            <strong>{{ $venta->clienteTexto() }}</strong>
            <div class="tenue">Operación de mostrador sin identificación del comprador.</div>
        @endif
    </div>

    <table class="productos">
        <thead>
            <tr>
                <th style="width: 16%;">Código</th>
                <th>Producto</th>
                <th class="derecha" style="width: 11%;">Cantidad</th>
                <th class="derecha" style="width: 17%;">Precio unit.</th>
                <th class="derecha" style="width: 17%;">Subtotal</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($venta->lineas as $linea)
                <tr>
                    <td>{{ $linea->producto->codigo }}</td>
                    {{-- La descripción congelada en la línea, no el nombre de hoy. --}}
                    <td>{{ $linea->descripcion }}</td>
                    <td class="derecha">{{ $linea->cantidad }}</td>
                    <td class="derecha">{{ Importe::pesos($linea->precio_unitario) }}</td>
                    <td class="derecha">{{ Importe::pesos($linea->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="importes">
        <tr>
            <td>Subtotal</td>
            <td class="derecha">{{ Importe::pesos($venta->subtotal) }}</td>
        </tr>

        @if ((float) $venta->descuento > 0)
            <tr>
                <td>Descuento ({{ Importe::paraFormulario($venta->descuentoPorcentaje()) }} %)</td>
                <td class="derecha">− {{ Importe::pesos($venta->descuento) }}</td>
            </tr>
        @endif

        <tr class="total">
            <td>Total</td>
            <td class="derecha">{{ Importe::pesos($venta->total) }}</td>
        </tr>
    </table>

    @if ($venta->observaciones)
        <div class="bloque" style="margin-top: 16px;">
            <h2>Observaciones</h2>
            {{ $venta->observaciones }}
        </div>
    @endif

    @if ($venta->esPresupuesto())
        <div class="bloque" style="margin-top: 16px;">
            <h2>Validez</h2>
            Válido hasta el <strong>{{ $validoHasta->format('d/m/Y') }}</strong>.
            Después de esa fecha los precios se vuelven a cotizar.
            Un presupuesto no reserva stock ni compromete mercadería.
        </div>
    @endif

    <p class="tenue" style="margin-top: 18px;">
        @if ($venta->usuario)
            Atendió {{ $venta->usuario->nombre }} {{ $venta->usuario->apellido }}.
        @endif
        Importes con IVA incluido.
    </p>

    <div class="pie">
        {{ $venta->numeroFormateado() }} · {{ config('empresa.nombre') }} ·
        Este documento no es una factura ni un comprobante fiscal: no lleva CAE ni numeración autorizada por AFIP.
    </div>
</body>

</html>
