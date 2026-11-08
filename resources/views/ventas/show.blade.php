@extends('layouts.app')

@use('App\Support\Importe')
@use('App\Support\MaquinaEstadosVenta')

@php
    $cliente = $venta->cliente;
    $porcentaje = $venta->descuentoPorcentaje();
    $hayDevoluciones = $venta->lineas->sum('cantidad_devuelta') > 0;
    $hayEntregas = $venta->entregas->isNotEmpty();
    $columnasAntesDelTotal = 5 + (int) $hayDevoluciones + (int) $hayEntregas;
    $pendientesDeEntregar = $venta->lineas->sum(fn($linea) => $linea->cantidadPendienteDeEntregar());
    $faltantes = $venta->esPresupuesto() ? $venta->faltantesDeStock()->keyBy('id') : collect();
@endphp

@section('title', "Venta {$venta->numeroFormateado()}")

@section('content')
    {{-- Arriba y no sólo abajo: en un documento largo, salir no debería exigir
         bajar hasta el final. Es el pendiente #14 atendido en la pantalla nueva. --}}
    <a href="{{ route('ventas.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">
                Venta {{ $venta->numeroFormateado() }}
                {{-- El estado nunca se comunica sólo con color: el badge lleva texto. --}}
                <span class="badge {{ $venta->estadoClase() }} align-middle ms-2">{{ $venta->estadoTexto() }}</span>
                @if ($faltantes->isNotEmpty())
                    <span class="badge text-bg-warning align-middle ms-1" id="insigniaSinStock">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                        Sin stock suficiente
                    </span>
                @endif
            </h1>

            <p class="text-body-secondary small mb-0">
                A <span class="fw-semibold">{{ $venta->clienteTexto() }}</span>, por
                {{ Importe::pesos($venta->total) }}.

                @if ($venta->esPresupuesto())
                    Un presupuesto no descuenta stock ni genera comprobante: se convierte en venta
                    cuando se cobra.
                @elseif ($venta->estado === 'cancelada')
                    Se canceló antes de cobrarse, así que no movió stock ni generó comprobante.
                @endif
            </p>
        </div>

        {{-- La acción principal, a la vista sin tener que bajar hasta el final del
             documento. Se repite en la tarjeta de acciones de abajo, igual que en la
             ficha de la orden de compra. --}}
        <div class="d-flex flex-column flex-sm-row gap-2">
            @if ($venta->esPresupuesto())
                @can('venta.editar')
                    <a href="{{ route('ventas.edit', $venta) }}" class="btn btn-outline-dark text-nowrap">
                        <i class="bi bi-pencil" aria-hidden="true"></i> Editar el presupuesto
                    </a>
                @endcan
            @endif

            {{-- Descargar es una lectura y no una transición, así que va acá y no en la
                 tarjeta de acciones, que agrupa los cambios de estado. --}}
            @can('venta.ver')
                @if ($venta->sePuedeImprimir())
                    <a href="{{ route('ventas.pdf', $venta) }}" class="btn btn-outline-dark text-nowrap">
                        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                        Descargar {{ $venta->esPresupuesto() ? 'el presupuesto' : 'el comprobante' }}
                    </a>
                @endif
            @endcan
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="card card-body border-0 shadow-sm h-100">
                <h2 class="h6 text-body-secondary">Cliente</h2>

                @if ($cliente)
                    <p class="mb-1 fw-semibold">{{ $cliente->razon_social }}</p>

                    <dl class="row small mb-2">
                        @if ($cliente->nro_doc)
                            <dt class="col-5 fw-normal text-body-secondary">
                                {{ App\Models\Cliente::TIPOS_DOC[$cliente->tipo_doc] ?? 'Documento' }}
                            </dt>
                            <dd class="col-7 mb-1">{{ $cliente->nro_doc }}</dd>
                        @endif

                        <dt class="col-5 fw-normal text-body-secondary">Frente al IVA</dt>
                        <dd class="col-7 mb-1">
                            {{ App\Models\Cliente::CONDICIONES_IVA[$cliente->condicion_iva] ?? $cliente->condicion_iva }}
                        </dd>

                        @if ($cliente->telefono)
                            <dt class="col-5 fw-normal text-body-secondary">Teléfono</dt>
                            <dd class="col-7 mb-1">{{ $cliente->telefono }}</dd>
                        @endif

                        @if ($cliente->email)
                            <dt class="col-5 fw-normal text-body-secondary">Correo</dt>
                            <dd class="col-7 mb-0">{{ $cliente->email }}</dd>
                        @endif
                    </dl>

                    <div class="d-flex flex-wrap gap-2 mt-auto">
                        {{-- La navegación que faltaba: desde una venta se llega al
                             historial de compras de ese cliente. Es la mitad del
                             pendiente #8 que esta fase podía levantar. --}}
                        <a href="{{ route('ventas.index', ['cliente_id' => $cliente->id]) }}"
                            class="btn btn-sm btn-outline-dark">
                            <i class="bi bi-clock-history" aria-hidden="true"></i> Sus compras
                        </a>

                        @can('cliente.editar')
                            <a href="{{ route('clientes.edit', $cliente) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-person-lines-fill" aria-hidden="true"></i> Su ficha
                            </a>
                        @endcan
                    </div>
                @else
                    <p class="mb-0 fw-semibold">{{ $venta->clienteTexto() }}</p>
                    {{-- cliente_id en null SIGNIFICA esto: no es un dato que falte.
                         La venta rápida de mostrador no identifica al comprador, y
                         el monto a partir del cual ARCA lo exige es un asunto de la
                         facturación, en la Etapa 4. --}}
                    <p class="small text-body-secondary mb-0">
                        La venta no identifica al comprador, que es lo habitual en el mostrador.
                    </p>
                @endif
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card card-body border-0 shadow-sm h-100">
                <h2 class="h6 text-body-secondary">Quién y cuándo</h2>

                <dl class="row small mb-0">
                    <dt class="col-5 fw-normal text-body-secondary">Emitida</dt>
                    <dd class="col-7 mb-1">
                        {{ $venta->created_at->format('d/m/Y H:i') }}
                        @if ($venta->usuario)
                            · {{ $venta->usuario->nombre_completo }}
                        @endif
                    </dd>

                    @if ($venta->updated_at->ne($venta->created_at))
                        <dt class="col-5 fw-normal text-body-secondary">Última modificación</dt>
                        <dd class="col-7 mb-1">{{ $venta->updated_at->format('d/m/Y H:i') }}</dd>
                    @endif

                    <dt class="col-5 fw-normal text-body-secondary">Entrega</dt>
                    <dd class="col-7 mb-1">Retiro en el local</dd>

                    <dt class="col-5 fw-normal text-body-secondary">Productos</dt>
                    <dd class="col-7 mb-0">{{ $venta->lineas->count() }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <caption class="visually-hidden">Productos de la venta {{ $venta->numeroFormateado() }}</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Producto</th>
                        <th scope="col" class="text-end">Cantidad</th>
                        @if ($hayEntregas)
                            <th scope="col" class="text-end">Entregadas</th>
                        @endif
                        @if ($hayDevoluciones)
                            <th scope="col" class="text-end">Devueltas</th>
                        @endif
                        <th scope="col" class="text-end">Precio unitario</th>
                        <th scope="col" class="text-end d-none d-md-table-cell">Neto</th>
                        <th scope="col" class="text-end d-none d-md-table-cell">IVA</th>
                        <th scope="col" class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($venta->lineas as $linea)
                        <tr>
                            <td>
                                {{-- La descripción se copió al cotizar: sigue diciendo
                                     qué se vendió aunque el producto se haya
                                     renombrado después. --}}
                                <span class="fw-semibold">{{ $linea->descripcion }}</span>
                                <div class="small text-body-secondary">
                                    {{ $linea->producto->codigo }}
                                    · IVA {{ Importe::paraFormulario($linea->alicuota_iva) }} %
                                </div>
                            </td>
                            <td class="text-end">
                                {{ $linea->cantidad }}

                                @if ($faltantes->has($linea->id))
                                    <div class="small text-warning-emphasis text-nowrap">
                                        {{ $linea->producto->stock_disponible > 0
                                            ? 'Hay ' . $linea->producto->stock_disponible . ' disponible(s)'
                                            : 'Sin stock disponible' }}
                                    </div>
                                @endif
                            </td>
                            @if ($hayDevoluciones)
                                <td class="text-end">
                                    @if ($linea->cantidad_devuelta > 0)
                                        <span class="badge text-bg-warning">{{ $linea->cantidad_devuelta }}</span>
                                    @else
                                        <span class="text-body-secondary">—</span>
                                    @endif
                                </td>
                                @if ($hayEntregas)
                                    <td class="text-end text-nowrap">
                                        {{ $linea->cantidad_entregada }}
                                        @if ($linea->cantidadPendienteDeEntregar() > 0)
                                            <div class="small text-body-secondary">
                                                faltan {{ $linea->cantidadPendienteDeEntregar() }}
                                            </div>
                                        @endif
                                    </td>
                                @endif
                            @endif
                            <td class="text-end text-nowrap">{{ Importe::pesos($linea->precio_unitario) }}</td>
                            <td class="text-end text-nowrap d-none d-md-table-cell">
                                {{ Importe::pesos($linea->neto) }}
                            </td>
                            <td class="text-end d-none d-md-table-cell">
                                @forelse ($linea->impuestos as $impuesto)
                                    <div>
                                        <span class="text-body-secondary small">{{ $impuesto->nombre }}</span>
                                        {{ Importe::pesos($impuesto->importe) }}
                                    </div>
                                @empty
                                    {{ Importe::pesos($linea->iva) }}
                                @endforelse
                            </td>
                            <td class="text-end text-nowrap">{{ Importe::pesos($linea->total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="{{ $columnasAntesDelTotal }}" class="text-end text-body-secondary">Subtotal</td>
                        <td class="text-end text-nowrap">{{ Importe::pesos($venta->subtotal) }}</td>
                    </tr>

                    @if ((float) $venta->descuento > 0)
                        <tr>
                            <td colspan="{{ $columnasAntesDelTotal }}" class="text-end text-body-secondary">
                                Descuento ({{ Importe::paraFormulario($porcentaje) }} %)
                            </td>
                            <td class="text-end text-nowrap">− {{ Importe::pesos($venta->descuento) }}</td>
                        </tr>
                    @endif

                    <tr>
                        <td colspan="{{ $columnasAntesDelTotal }}" class="text-end fw-semibold">Total</td>
                        <td class="text-end fw-semibold text-nowrap">{{ Importe::pesos($venta->total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <p class="text-body-secondary small">
        <i class="bi bi-lock" aria-hidden="true"></i>
        Los precios de este documento quedaron congelados cuando se cotizó: si el producto
        cambia de precio, la venta no cambia.
    </p>

    @if ($venta->observaciones)
        <div class="card card-body border-0 shadow-sm mb-4">
            <h2 class="h6 text-body-secondary">Observaciones</h2>
            <p class="mb-0">{{ $venta->observaciones }}</p>
        </div>
    @endif

    @if ($hayEntregas)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body pb-3">
                <h2 class="h5 mb-0">Entregas</h2>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">Entregas de la venta {{ $venta->numeroFormateado() }}</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Comprobante</th>
                            <th scope="col">Fecha</th>
                            <th scope="col" class="d-none d-md-table-cell">Entregó</th>
                            <th scope="col" class="text-end">Unidades</th>
                            <th scope="col" class="text-end"><span class="visually-hidden">Descargar</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($venta->entregas as $entrega)
                            <tr>
                                <td class="fw-semibold">{{ $entrega->numeroFormateado() }}</td>
                                <td class="text-nowrap">{{ $entrega->created_at->format('d/m/Y H:i') }}</td>
                                <td class="d-none d-md-table-cell">{{ $entrega->quienTexto() }}</td>
                                <td class="text-end">{{ $entrega->unidades }}</td>
                                <td class="text-end">
                                    @can('venta.ver')
                                        <a href="{{ route('ventas.entregas.pdf', [$venta, $entrega]) }}"
                                            class="btn btn-sm btn-outline-dark"
                                            aria-label="Descargar el comprobante de entrega {{ $entrega->numeroFormateado() }}">
                                            <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                                            <span class="d-none d-sm-inline">Descargar</span>
                                        </a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($venta->estado === 'entregada')
        <p class="text-body-secondary small" id="entregaSinComprobante">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            Esta venta se entregó antes de que el sistema registrara cada entrega, así que no tiene
            comprobante de entrega.
        </p>
    @endif

    @php
        $destinos = MaquinaEstadosVenta::destinosDesde($venta->estado);
    @endphp

    @canany(['venta.editar', 'venta.cobrar', 'venta.entregar', 'venta.anular'])
        <div class="card card-body border-0 shadow-sm mb-4">
            <h2 class="h5 mb-3">Acciones</h2>

            {{-- Operar el presupuesto. Editar y recotizar NO son transiciones de
                 estado: son operaciones sobre un documento que todavía no tiene
                 ningún efecto, y por eso no se preguntan contra `$destinos`. --}}
            @if ($venta->esPresupuesto())
                @can('venta.editar')
                    <div class="d-grid d-sm-flex flex-wrap gap-2 mb-3">
                        <a href="{{ route('ventas.edit', $venta) }}" class="btn btn-outline-dark">
                            <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                        </a>

                        <form method="POST" action="{{ route('ventas.recotizar', $venta) }}"
                            onsubmit="return confirm(@js('Esto trae los precios de hoy a todas las líneas del presupuesto. Después te vamos a decir qué cambió. ¿Seguimos?'));">
                            @csrf
                            <button type="submit" class="btn btn-outline-dark w-100">
                                <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Recotizar
                            </button>
                        </form>
                    </div>

                    <p class="text-body-secondary small mb-3">
                        Podés editar manteniendo precios anteriores o recotizar para actualizar todas las líneas con los valores de
                        hoy.
                    </p>
                @endcan
            @endif

            @if ($destinos === [])
                {{-- Un estado final es una lista vacía en la tabla de transiciones. --}}
                <p class="text-body-secondary mb-0">
                    Esta venta está «{{ $venta->estadoTexto() }}» y no admite más cambios de estado.
                </p>
            @else
                <div class="d-grid d-sm-flex flex-wrap gap-2">
                    @if (in_array('pagada', $destinos, true))
                        @can('venta.cobrar')
                            <a href="{{ route('pagos.create', $venta) }}" class="btn btn-acento">
                                <i class="bi bi-cash-coin" aria-hidden="true"></i> Cobrar
                            </a>
                        @endcan
                    @endif

                    @if (in_array('entregada', $destinos, true))
                        @can('venta.entregar')
                            <form method="POST" action="{{ route('ventas.entregar', $venta) }}"
                                onsubmit="return confirm(@js("¿Entregar lo pendiente de la venta {$venta->numeroFormateado()}? No mueve stock: eso ya pasó al cobrar."));">
                                @csrf
                                <button type="submit" class="btn btn-outline-dark w-100">
                                    <i class="bi bi-box-arrow-up" aria-hidden="true"></i>
                                    {{ $hayEntregas ? 'Entregar lo que falta' : 'Entregar todo' }}
                                </button>
                            </form>
                        @endcan
                    @endif

                    {{-- Con una sola unidad pendiente no hay parte que elegir. --}}
                    @if (in_array('entregada_parcial', $destinos, true) && $pendientesDeEntregar > 1)
                        @can('venta.entregar')
                            <a href="{{ route('ventas.entrega', $venta) }}" class="btn btn-outline-dark">
                                <i class="bi bi-boxes" aria-hidden="true"></i> Entregar una parte
                            </a>
                        @endcan
                    @endif

                    @if (in_array('devuelta_parcial', $destinos, true) || in_array('devuelta', $destinos, true))
                        @can('venta.anular')
                            {{-- Una venta cobrada no se cancela: se devuelve, y la
                                 devolución es la que repone el stock y revierte los
                                 pagos (A-11). La máquina de estados no declara
                                 `pagada → cancelada`, así que este botón es el único
                                 camino que deshace una venta cobrada — y pasa por
                                 donde se devuelve el dinero. --}}
                            <a href="{{ route('ventas.devolucion', $venta) }}" class="btn btn-outline-danger">
                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Devolver
                            </a>
                        @endcan
                    @endif

                    @if (in_array('cancelada', $destinos, true))
                        {{-- Cancelar exige `venta.editar` y no `venta.anular`: no
                             mueve stock ni plata, así que es parte de operar un
                             presupuesto y el vendedor tiene que poder dar de baja lo
                             que él mismo cargó. `venta.anular` es de la devolución,
                             que es la que toca el dinero. --}}
                        @can('venta.editar')
                            <form method="POST" action="{{ route('ventas.cancelar', $venta) }}"
                                onsubmit="return confirm(@js('El presupuesto va a quedar cancelado y no se puede volver atrás. No se borra: queda registrado con sus líneas. ¿Seguimos?'));">
                                @csrf
                                <button type="submit" class="btn btn-outline-danger w-100">
                                    <i class="bi bi-x-circle" aria-hidden="true"></i> Cancelar el presupuesto
                                </button>
                            </form>
                        @endcan
                    @endif
                </div>

                @if ($venta->esPresupuesto())
                    <p class="text-body-secondary small mb-0 mt-3">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                        Cobrar convierte el presupuesto en venta y descuenta el stock en el kardex.
                    </p>
                @endif
            @endif
        </div>
    @endcanany

    {{-- El libro de la venta. Es donde se ve que una devolución no borra ni edita el
         cobro: le suma un asiento en contra, y los dos hechos quedan a la vista. Es
         el mismo criterio con el que el kardex guarda la cantidad con signo, y es la
         diferencia con el original, que reponía stock y dejaba las filas de `pagos`
         intactas (A-11). --}}
    @if ($venta->pagos->isNotEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body pb-3">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="h5 mb-0">Movimientos de dinero</h2>
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="Registro inmutable de pagos y reintegros asociados a la venta."
                        aria-label="Información sobre movimientos de dinero">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">
                        Pagos y devoluciones de la venta {{ $venta->numeroFormateado() }}
                    </caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Fecha</th>
                            <th scope="col">Medio</th>
                            <th scope="col" class="d-none d-md-table-cell">Registró</th>
                            <th scope="col" class="text-end">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($venta->pagos->sortBy('id') as $pago)
                            @php($esReversion = (float) $pago->monto < 0)
                            <tr>
                                {{-- La fecha del hecho, no la de la fila: en el mostrador
                                     coinciden, y un webhook con atraso no. --}}
                                <td class="text-nowrap">{{ $pago->fecha->format('d/m/Y H:i') }}</td>

                                <td>
                                    {{ $pago->metodoTexto() }}
                                    @if ($esReversion)
                                        {{-- El estado nunca se comunica sólo con color. --}}
                                        <span class="badge text-bg-warning ms-1">Devolución</span>
                                    @endif
                                </td>

                                <td class="d-none d-md-table-cell">
                                    {{ $pago->usuario?->nombre_completo ?? '—' }}
                                </td>

                                <td class="text-end text-nowrap {{ $esReversion ? 'text-danger' : '' }}">
                                    @if ($esReversion)
                                        − {{ Importe::pesos(abs((float) $pago->monto)) }}
                                    @else
                                        {{ Importe::pesos($pago->monto) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end fw-semibold">Quedó cobrado</td>
                            <td class="text-end fw-semibold text-nowrap">
                                {{ Importe::pesos($venta->pagado()) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif
@endsection
