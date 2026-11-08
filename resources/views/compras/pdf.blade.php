{{--
    El pedido formal que recibe el proveedor.

    **Nada de lo que imprime cambia entre una generación y otra**, y eso es lo que
    permite no archivar el archivo (ver docs/modelo-datos.md, sección
    ordenes_compra):

      - `cantidad_pedida` y `costo_unitario`, congelados en la línea.
      - `codigo_proveedor`, también congelado al escribir la línea: si el proveedor
        renumera su catálogo, este documento sigue diciendo el código con el que se
        le pidió.
      - La fecha es la de APROBACIÓN, no la de impresión. Un «impreso el 8/10» haría
        que el documento regenerado mañana sea distinto, que es exactamente lo que
        esta decisión evita.

    Nunca imprime `cantidad_recibida`: lo que llegó es información interna, y el
    pedido dice qué se pidió.

    Dompdf no interpreta flexbox ni grid, así que la maquetación va con tablas. Y la
    hoja de estilos va embebida: el PDF se arma en el servidor, sin acceso a los
    assets que compila Vite.
--}}
@use('App\Support\Importe')

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Orden de compra {{ $orden->numeroFormateado() }}</title>

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

        .total td {
            border-top: 2px solid #222;
            border-bottom: none;
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
                <div class="tipo">Orden de compra</div>
                <div class="numero">{{ $orden->numeroFormateado() }}</div>
                {{-- La fecha de aprobación, no la de impresión: el documento no
                     puede cambiar según cuándo se lo regenere. --}}
                <div class="tenue">
                    Fecha {{ $orden->fecha_aprobacion->format('d/m/Y') }}
                </div>
            </td>
        </tr>
    </table>

    <div class="linea-divisoria"></div>

    <div class="bloque">
        <h2>Proveedor</h2>

        <strong>{{ $orden->proveedor->razon_social }}</strong>

        <div class="tenue">
            @if ($orden->proveedor->cuit)
                CUIT {{ $orden->proveedor->cuitFormateado() }}<br>
            @endif
            @if ($orden->proveedor->contacto)
                At. {{ $orden->proveedor->contacto }}<br>
            @endif
            @if ($orden->proveedor->email)
                {{ $orden->proveedor->email }}
            @endif
            @if ($orden->proveedor->telefono)
                · Tel. {{ $orden->proveedor->telefono }}
            @endif
            @if ($orden->proveedor->plazo_entrega_dias)
                <br>Plazo de entrega acordado: {{ $orden->proveedor->plazo_entrega_dias }} días
            @endif
        </div>
    </div>

    <table class="productos">
        <thead>
            <tr>
                {{-- El código del proveedor va PRIMERO y no el nuestro: es con el que
                     él va a buscar el producto, y es lo que evita que manden otra
                     cosa. Nuestro código queda como referencia. --}}
                <th style="width: 15%;">Su código</th>
                <th style="width: 13%;">Ref. interna</th>
                <th>Producto</th>
                <th class="derecha" style="width: 10%;">Cantidad</th>
                <th class="derecha" style="width: 15%;">Precio unit.</th>
                <th class="derecha" style="width: 15%;">Subtotal</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($orden->lineas as $linea)
                <tr>
                    <td>{{ $linea->codigo_proveedor ?? '—' }}</td>
                    <td>{{ $linea->producto->codigo }}</td>
                    <td>{{ $linea->producto->nombre }}</td>
                    {{-- Cantidad PEDIDA, siempre. Nunca la recibida: lo que llegó es
                         información interna y haría que el documento cambie. --}}
                    <td class="derecha">{{ $linea->cantidad_pedida }}</td>
                    <td class="derecha">{{ Importe::pesos($linea->costo_unitario) }}</td>
                    <td class="derecha">{{ Importe::pesos($linea->subtotalPedido()) }}</td>
                </tr>
            @endforeach

            <tr class="total">
                <td colspan="5" class="derecha">Total</td>
                <td class="derecha">{{ Importe::pesos($orden->total_estimado) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($orden->observaciones)
        <div class="bloque" style="margin-top: 16px;">
            <h2>Observaciones</h2>
            {{ $orden->observaciones }}
        </div>
    @endif

    <p class="tenue" style="margin-top: 18px;">
        Autorizado por
        @if ($orden->usuarioAprobo)
            {{ $orden->usuarioAprobo->nombre }} {{ $orden->usuarioAprobo->apellido }},
        @endif
        el {{ $orden->fecha_aprobacion->format('d/m/Y') }} a las {{ $orden->fecha_aprobacion->format('H:i') }}.
    </p>

    <div class="pie">
        {{ $orden->numeroFormateado() }} · {{ config('empresa.nombre') }} ·
        Este documento es un pedido de mercadería y no un comprobante fiscal.
    </div>
</body>

</html>
