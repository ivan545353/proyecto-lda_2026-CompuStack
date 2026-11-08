<?php

namespace App\Http\Controllers\Concerns;

use App\Impresion\Listado;
use App\Services\ImpresionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El botón «Imprimir» de un listado: lo que la pantalla filtra, en PDF o en CSV.
 */
trait ImprimeListados
{
    /** Una opción mal pedida no imprime otra cosa: vuelve al listado, con sus filtros, diciendo por qué. */
    protected function imprimirListado(
        FormRequest $filtros,
        Listado $listado,
        Builder $consulta,
        string $rutaDelListado,
    ): Response|StreamedResponse|RedirectResponse {
        $impresion = app(ImpresionService::class);
        $usuario   = $filtros->user();

        try {
            $opciones = $impresion->opciones($listado, $filtros->query(), $usuario);
        } catch (ValidationException $error) {
            return redirect()->route($rutaDelListado, $filtros->safe()->except('page'))
                ->with('error', 'No se imprimió: '.$error->validator->errors()->first());
        }

        $aplicados = $listado->filtrosAplicados($filtros->validated());

        if ($opciones->esCsv()) {
            return response()->streamDownload(
                function () use ($impresion, $listado, $consulta, $opciones, $usuario, $aplicados) {
                    $salida = fopen('php://output', 'w');
                    $impresion->escribirCsv($listado, $consulta, $opciones, $usuario, $aplicados, $salida);
                    fclose($salida);
                },
                $impresion->nombreDeArchivo($listado, 'csv'),
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            );
        }

        return $impresion->pdf($listado, $consulta, $opciones, $usuario, $aplicados)
            ->download($impresion->nombreDeArchivo($listado, 'pdf'));
    }
}