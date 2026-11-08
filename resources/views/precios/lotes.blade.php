@extends('layouts.app')

@use('App\Models\LoteAjuste')
@use('App\Support\Importe')

@section('title', 'Historial de ajustes')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Historial de ajustes</h1>
            <p class="text-body-secondary small mb-0">
                Cada ajuste masivo quedó registrado con su criterio. El último se puede deshacer.
            </p>
        </div>

        <div class="d-flex flex-column flex-sm-row gap-2">
            @can('precio.ajustar_masivo')
                <a href="{{ route('precios.ajuste') }}" class="btn btn-acento text-nowrap">
                    <i class="bi bi-percent" aria-hidden="true"></i> Nuevo ajuste
                </a>
            @endcan

            <a href="{{ route('precios.index') }}" class="btn btn-outline-dark text-nowrap">
                <i class="bi bi-sliders" aria-hidden="true"></i> Volver a Precios
            </a>
        </div>
    </div>

    {{-- La acción de la pantalla, arriba y sola: deshacer el último. Es lo que alguien
         viene a buscar acá, y no tiene sentido hacerle leer la tabla para encontrarla. --}}
    @if ($reversible !== null)
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge text-bg-dark">Último ajuste</span>
                            <span class="fw-semibold">{{ LoteAjuste::OPERACIONES[$reversible->operacion] }}</span>
                            @if ($reversible->parametro() !== null)
                                <span class="badge bg-dark-subtle text-dark-emphasis border">
                                    {{ $reversible->parametro() }}
                                </span>
                            @endif
                        </div>

                        <div class="text-body-secondary small">
                            {{ $reversible->criterio_texto }} ·
                            {{ $reversible->alcanzados }} producto(s) ·
                            {{ $reversible->created_at->format('d/m/Y H:i') }} ·
                            {{ $reversible->usuarioTexto() }}
                        </div>
                    </div>

                    @can('precio.deshacer_ajuste')
                        <form method="POST" action="{{ route('precios.lotes.deshacer', $reversible) }}"
                            onsubmit="return confirm(@js("Se van a devolver los precios de {$reversible->alcanzados} producto(s) a los que tenían antes del ajuste. Queda registrado como un ajuste nuevo. ¿Deshacer?"));">
                            @csrf

                            <button type="submit" class="btn btn-outline-dark text-nowrap"
                                aria-label="Deshacer el ajuste del {{ $reversible->created_at->format('d/m/Y H:i') }}">
                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Deshacer este ajuste
                            </button>
                        </form>
                    @endcan
                </div>

                {{-- La explicación de por qué sólo el último va plegada: se lee una vez y
                     después estorba. --}}
                <button
                    class="btn btn-link btn-sm text-decoration-none text-body-secondary p-0 mt-3 d-inline-flex align-items-center gap-1"
                    type="button" data-bs-toggle="collapse" data-bs-target="#porQueSoloElUltimo" aria-expanded="false"
                    aria-controls="porQueSoloElUltimo">
                    <i class="bi bi-question-circle" aria-hidden="true"></i>
                    <span>¿Por qué sólo se puede deshacer el último?</span>
                </button>

                <div class="collapse pt-2 mt-2 border-top small text-body-secondary" id="porQueSoloElUltimo">
                    Cada ajuste guarda el precio que cada producto tenía antes, y ese precio vale
                    mientras no haya pasado nada después. Deshacer el ajuste de la semana pasada
                    devolvería precios que dos ajustes posteriores ya movieron. Deshacer no pisa
                    nada: escribe un ajuste nuevo que devuelve los precios, igual que una venta
                    cobrada se devuelve en lugar de anularse. Y un producto cuyo precio se cambió
                    después del ajuste no se devuelve: se informa aparte.
                </div>
            </div>
        </section>
    @endif

    <section class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 pt-3 d-flex align-items-center gap-2">
            <h2 class="h5 mb-0">Todos los ajustes</h2>
            <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                data-bs-toggle="tooltip" data-bs-placement="top"
                data-bs-title="El criterio quedó congelado como texto: dice sobre qué conjunto corrió el ajuste aunque la categoría o el proveedor se hayan renombrado después."
                aria-label="Información sobre el historial de ajustes">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Ajustes masivos de precios, del más reciente al más antiguo</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Operación</th>
                        <th scope="col" class="d-none d-lg-table-cell">Sobre qué corrió</th>
                        <th scope="col" class="text-end">P.Alcanzados</th>
                        <th scope="col" class="d-none d-md-table-cell">Quién</th>
                        <th scope="col">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lotes as $lote)
                        <tr @class(['table-light' => $lote->operacion === 'deshacer'])>
                            <td class="text-nowrap small">{{ $lote->created_at->format('d/m/Y H:i') }}</td>

                            <td>
                                <span class="fw-semibold">{{ LoteAjuste::OPERACIONES[$lote->operacion] }}</span>

                                @if ($lote->parametro() !== null)
                                    <span class="badge bg-dark-subtle text-dark-emphasis border ms-1">
                                        {{ $lote->parametro() }}
                                    </span>
                                @endif
                            </td>

                            <td class="d-none d-lg-table-cell small text-body-secondary">
                                {{ $lote->criterio_texto }}
                            </td>

                            <td class="text-end fw-semibold">{{ $lote->alcanzados }}</td>



                            <td class="d-none d-md-table-cell small">{{ $lote->usuarioTexto() }}</td>

                            {{-- Ningún estado se comunica sólo con color: el badge lleva texto. --}}
                            <td class="text-nowrap">
                                @if ($lote->operacion === 'deshacer')
                                    <span class="badge text-bg-secondary">
                                        Deshizo el ajuste del
                                        {{ $lote->revertido?->created_at->format('d/m/Y H:i') ?? 'anterior' }}
                                    </span>

                                    @if ($lote->omitidos > 0)
                                        <span class="badge text-bg-warning ms-1">
                                            {{ $lote->omitidos }} sin devolver
                                        </span>
                                    @endif
                                @elseif ($lote->reversion !== null)
                                    <span class="badge text-bg-secondary">
                                        Deshecho el {{ $lote->reversion->created_at->format('d/m/Y H:i') }}
                                    </span>
                                @elseif ($reversible !== null && $reversible->id === $lote->id)
                                    <span class="badge text-bg-dark">Se puede deshacer</span>
                                @else
                                    <span class="badge text-bg-light border text-body-secondary">Aplicado</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        {{-- Sin filtros no hay vacío por filtro: el único vacío posible es que
                             todavía no se haya hecho ningún ajuste. --}}
                        <tr>
                            <td colspan="6" class="text-center text-body-secondary py-4">
                                Todavía no se hizo ningún ajuste masivo.
                                @can('precio.ajustar_masivo')
                                    <a href="{{ route('precios.ajuste') }}" class="text-decoration-none">Hacer el primero</a>.
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($lotes->hasPages())
            <div class="card-body pt-3">{{ $lotes->links() }}</div>
        @endif
    </section>
@endsection
