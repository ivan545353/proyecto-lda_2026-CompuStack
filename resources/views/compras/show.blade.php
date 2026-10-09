@extends('layouts.app')

@use('App\Support\Importe')
@use('App\Support\MaquinaEstadosCompra')

@php
    $proveedor = $orden->proveedor;
    $destinos = MaquinaEstadosCompra::destinosDesde($orden->estado);

    $recibidas = (int) $orden->lineas->sum('cantidad_recibida');
    $pendientes = $orden->lineas->sum(fn($linea) => $linea->cantidadPendiente());

    // Cerrar incompleta sólo tiene sentido si entró algo y falta algo. El servicio
    // rechaza los otros dos casos con su motivo; acá simplemente no se ofrece.
    $sePuedeCerrar = in_array('recibida', $destinos, true) && $recibidas > 0 && $pendientes > 0;
@endphp

@section('title', "Orden {$orden->numeroFormateado()}")

@section('content')
    <a href="{{ route('compras.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">
                Orden {{ $orden->numeroFormateado() }}
                {{-- El estado nunca se comunica sólo con color: el badge lleva texto. --}}
                <span class="badge {{ $orden->estadoClase() }} align-middle ms-2">{{ $orden->estadoTexto() }}</span>
            </h1>
            <p class="text-body-secondary small mb-0">
                A <span class="fw-semibold">{{ $proveedor->razon_social }}</span>, por
                {{ Importe::pesos($orden->total_estimado) }}.
                @if ($orden->fueGeneradaPorElSistema())
                    La generó la tarea de reposición.
                @endif
            </p>
        </div>

        @if ($orden->esEditable())
            @can('compra.editar')
                <a href="{{ route('compras.edit', $orden) }}" class="btn btn-outline-dark">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar el borrador
                </a>
            @endcan
        @endif
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="card card-body border-0 shadow-sm h-100">
                <h2 class="h6 text-body-secondary">Proveedor</h2>

                <p class="mb-1 fw-semibold">{{ $proveedor->razon_social }}</p>

                <dl class="row small mb-0">
                    @if ($proveedor->cuit)
                        <dt class="col-5 fw-normal text-body-secondary">CUIT</dt>
                        <dd class="col-7 mb-1">{{ $proveedor->cuitFormateado() }}</dd>
                    @endif

                    @if ($proveedor->contacto)
                        <dt class="col-5 fw-normal text-body-secondary">Contacto</dt>
                        <dd class="col-7 mb-1">{{ $proveedor->contacto }}</dd>
                    @endif

                    <dt class="col-5 fw-normal text-body-secondary">Cómo se le pide</dt>
                    <dd class="col-7 mb-1">{{ $proveedor->canalPedidoTexto() }}</dd>

                    @if ($proveedor->plazo_entrega_dias)
                        <dt class="col-5 fw-normal text-body-secondary">Plazo de entrega</dt>
                        <dd class="col-7 mb-0">{{ $proveedor->plazo_entrega_dias }} días</dd>
                    @endif
                </dl>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card card-body border-0 shadow-sm h-100">
                <h2 class="h6 text-body-secondary">Quién y cuándo</h2>

                <dl class="row small mb-0">
                    <dt class="col-5 fw-normal text-body-secondary">Creada</dt>
                    <dd class="col-7 mb-1">
                        {{ $orden->created_at->format('d/m/Y H:i') }}
                        @if ($orden->usuarioCreo)
                            · {{ $orden->usuarioCreo->nombre }} {{ $orden->usuarioCreo->apellido }}
                        @else
                            {{-- null significa «la generó el sistema»: se dice con
                                 palabras en lugar de mostrar una celda vacía. --}}
                            · por el sistema
                        @endif
                    </dd>

                    <dt class="col-5 fw-normal text-body-secondary">Aprobada</dt>
                    <dd class="col-7 mb-1">
                        @if ($orden->fecha_aprobacion)
                            {{ $orden->fecha_aprobacion->format('d/m/Y H:i') }}
                            @if ($orden->usuarioAprobo)
                                · {{ $orden->usuarioAprobo->nombre }} {{ $orden->usuarioAprobo->apellido }}
                            @endif
                        @else
                            <span class="text-body-secondary">todavía no</span>
                        @endif
                    </dd>

                    <dt class="col-5 fw-normal text-body-secondary">Enviada</dt>
                    <dd class="col-7 mb-0">
                        @if ($orden->fecha_envio)
                            {{ $orden->fecha_envio->format('d/m/Y H:i') }}
                        @else
                            <span class="text-body-secondary">todavía no</span>
                        @endif
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    {{-- El canal de pedido es un dato descriptivo, no un despachador: el sistema no
         manda nada. Acá se usa para decir cuál es el próximo paso, que es lo único
         que cambia entre los tres canales. --}}
    @if ($orden->estado === 'aprobada')
        <div class="alert alert-info">
            <h2 class="h6">Qué sigue</h2>

            @switch($proveedor->canal_pedido)
                @case('email')
                    <p class="mb-0">
                        Descargá el pedido y mandáselo por correo
                        @if ($proveedor->email)
                            a <strong>{{ $proveedor->email }}</strong>
                        @endif
                        . Después marcá la orden como enviada.
                    </p>
                @break

                @case('portal_externo')
                    <p class="mb-0">
                        Descargá el pedido y cargalo en el portal del proveedor
                        @if ($proveedor->portal_url)
                            —<a href="{{ $proveedor->portal_url }}" target="_blank"
                                rel="noopener noreferrer">{{ $proveedor->portal_url }}</a>—
                        @endif
                        . Después marcá la orden como enviada.
                    </p>
                @break

                @default
                    <p class="mb-0">
                        Descargá el pedido y pasáselo por teléfono o WhatsApp
                        @if ($proveedor->telefono)
                            al <strong>{{ $proveedor->telefono }}</strong>
                        @endif
                        . Después marcá la orden como enviada.
                    </p>
            @endswitch
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <caption class="visually-hidden">Productos de la orden {{ $orden->numeroFormateado() }}</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Producto</th>
                        <th scope="col" class="text-end">Pedidas</th>
                        <th scope="col" class="text-end">Recibidas</th>
                        <th scope="col" class="text-end">Pendientes</th>
                        <th scope="col" class="text-end">Costo unitario</th>
                        <th scope="col" class="text-end">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orden->lineas as $linea)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $linea->producto->nombre }}</span>
                                <div class="small text-body-secondary">
                                    {{ $linea->producto->codigo }}
                                    {{-- El código del proveedor queda congelado en la
                                         línea: el pedido se imprime con el código que
                                         ese proveedor entiende, y sigue diciendo lo
                                         mismo aunque después renumere su catálogo. --}}
                                    @if ($linea->codigo_proveedor)
                                        · para el proveedor: {{ $linea->codigo_proveedor }}
                                    @endif
                                </div>
                            </td>
                            <td class="text-end">{{ $linea->cantidad_pedida }}</td>
                            <td class="text-end">{{ $linea->cantidad_recibida }}</td>
                            <td class="text-end">
                                @if ($linea->cantidadPendiente() > 0)
                                    <span class="badge text-bg-warning">{{ $linea->cantidadPendiente() }}</span>
                                @else
                                    <span class="text-body-secondary">—</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">{{ Importe::pesos($linea->costo_unitario) }}</td>
                            <td class="text-end text-nowrap">{{ Importe::pesos($linea->subtotalPedido()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-end fw-semibold">Total estimado</td>
                        <td class="text-end fw-semibold text-nowrap">{{ Importe::pesos($orden->total_estimado) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    @if ($orden->observaciones)
        <div class="card card-body border-0 shadow-sm mb-4">
            <h2 class="h6 text-body-secondary">Observaciones</h2>
            <p class="mb-0">{{ $orden->observaciones }}</p>
        </div>
    @endif

    <div class="card card-body border-0 shadow-sm mb-4">
        <h2 class="h5 mb-3">Acciones</h2>

        <div class="d-grid d-sm-flex flex-wrap gap-2 mb-3">
            @if ($orden->sePuedeImprimir())
                @can('compra.ver')
                    <a href="{{ route('compras.pdf', $orden) }}" class="btn btn-outline-dark">
                        <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Descargar el pedido
                    </a>
                @endcan
            @endif
        </div>
        @if ($destinos === [])
            {{-- Un estado final no tiene salidas en la tabla de transiciones, y la
                 pantalla no inventa botones que el servidor va a rechazar. --}}
            <p class="text-body-secondary mb-0">
                Esta orden está «{{ $orden->estadoTexto() }}» y no admite más cambios de estado.
            </p>
        @else
            <div class="d-grid d-sm-flex flex-wrap gap-2">
                @if (in_array('aprobada', $destinos, true))
                    @can('compra.aprobar')
                        <form method="POST" action="{{ route('compras.aprobar', $orden) }}"
                            onsubmit="return confirm(@js("¿Aprobar la orden {$orden->numeroFormateado()} por " . Importe::pesos($orden->total_estimado) . '? El monto queda comprometido y las líneas se congelan.'));">
                            @csrf
                            <button class="btn btn-acento w-100">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i> Aprobar
                            </button>
                        </form>
                    @endcan
                @endif

                @if (in_array('enviada', $destinos, true))
                    @can('compra.aprobar')
                        <form method="POST" action="{{ route('compras.enviada', $orden) }}">
                            @csrf
                            <button class="btn btn-outline-dark w-100">
                                <i class="bi bi-send" aria-hidden="true"></i> Marcar como enviada
                            </button>
                        </form>
                    @endcan
                @endif

                @if (MaquinaEstadosCompra::puedeRecibir($orden->estado) && $pendientes > 0)
                    @can('compra.recibir')
                        <a href="{{ route('compras.recepcion', $orden) }}" class="btn btn-outline-dark">
                            <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Recibir mercadería
                        </a>
                    @endcan
                @endif

                @if ($sePuedeCerrar)
                    @can('compra.recibir')
                        <form method="POST" action="{{ route('compras.cerrar', $orden) }}"
                            onsubmit="return confirm(@js("Quedan {$pendientes} unidad(es) sin recibir. ¿Cerrar la orden con lo que llegó? Las líneas van a seguir mostrando el saldo."));">
                            @csrf
                            <button class="btn btn-outline-dark w-100">
                                <i class="bi bi-box-seam" aria-hidden="true"></i> Cerrar con lo que llegó
                            </button>
                        </form>
                    @endcan
                @endif

                @if (in_array('cancelada', $destinos, true))
                    @can('compra.aprobar')
                        <form method="POST" action="{{ route('compras.cancelar', $orden) }}"
                            onsubmit="return confirm(@js("¿Cancelar la orden {$orden->numeroFormateado()}? No se puede deshacer."));">
                            @csrf
                            <button class="btn btn-outline-danger w-100">
                                <i class="bi bi-x-circle" aria-hidden="true"></i> Cancelar la orden
                            </button>
                        </form>
                    @endcan
                @endif
            </div>

        @endif
    </div>

    @if ($orden->movimientos->isNotEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body pb-0">
                <h2 class="h5 mb-1">Lo que entró por esta orden</h2>
                <p class="text-body-secondary small">
                    Sale del kardex, con su fecha y su usuario.
                </p>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">Movimientos de stock generados por esta orden</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Cuándo</th>
                            <th scope="col">Producto</th>
                            <th scope="col" class="text-end">Unidades</th>
                            <th scope="col" class="text-end">Stock resultante</th>
                            <th scope="col">Quién</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orden->movimientos->sortByDesc('created_at') as $movimiento)
                            <tr>
                                <td class="text-nowrap">{{ $movimiento->created_at->format('d/m/Y H:i') }}</td>
                                <td>
                                    {{ $movimiento->producto->nombre }}
                                    <div class="small text-body-secondary">{{ $movimiento->producto->codigo }}</div>
                                </td>
                                <td class="text-end">+{{ $movimiento->cantidad }}</td>
                                <td class="text-end">{{ $movimiento->stock_resultante }}</td>
                                <td>
                                    @if ($movimiento->usuario)
                                        {{ $movimiento->usuario->nombre }} {{ $movimiento->usuario->apellido }}
                                    @else
                                        <span class="text-body-secondary">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
