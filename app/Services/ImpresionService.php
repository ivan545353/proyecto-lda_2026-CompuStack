<?php

namespace App\Services;

use App\Impresion\Agrupacion;
use App\Impresion\Columna;
use App\Impresion\Listado;
use App\Impresion\Opciones;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentoPdf;
use Dompdf\Dompdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as Validador;

/**
 * El motor único de impresión: valida las opciones, arma el PDF con tope y escribe el CSV sin tope.
 */
class ImpresionService
{
    /** Filas por tanda del CSV: lo que vive en memoria a la vez. */
    public const TANDA = 1000;

    /** Filas que entran en un PDF: dompdf arma el documento entero en memoria antes de escribirlo. */
    public const TOPE_PDF = 500;

    /**
     * Las opciones pedidas, validadas; lo que no se pide toma el valor por omisión.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function opciones(Listado $listado, array $entrada, User $usuario): Opciones
    {
        $claves   = array_map(fn (Columna $columna) => $columna->clave, $listado->columnas());
        $delPapel = ['orientacion', 'hoja', 'densidad', 'agrupar', 'salto'];

        $validador = Validator::make($entrada, [
            'formato'     => ['nullable', Rule::in(array_keys(Opciones::FORMATOS))],
            'columnas'    => ['nullable', 'array', 'min:1'],
            'columnas.*'  => ['required', 'string', 'distinct', Rule::in($claves)],
            'orientacion' => ['nullable', 'prohibited_if:formato,csv', Rule::in(array_keys(Opciones::ORIENTACIONES))],
            'hoja'        => ['nullable', 'prohibited_if:formato,csv', Rule::in(array_keys(Opciones::HOJAS))],
            'densidad'    => ['nullable', 'prohibited_if:formato,csv', Rule::in(array_keys(Opciones::DENSIDADES))],
            'agrupar'     => ['nullable', 'prohibited_if:formato,csv', Rule::in(array_keys($listado->agrupaciones()))],
            'salto'       => ['nullable', 'prohibited_if:formato,csv', 'boolean'],
        ], [
            'columnas.min'        => 'Elegí al menos una columna.',
            'columnas.*.required' => 'Elegí al menos una columna.',
            'columnas.*.in'       => 'Pediste una columna que este listado no tiene.',
            'columnas.*.distinct' => 'Pediste la misma columna dos veces.',
            'agrupar.in'          => 'Este listado no se puede agrupar de esa forma.',
            ...array_fill_keys(
                array_map(fn (string $campo) => "{$campo}.prohibited_if", $delPapel),
                'La planilla no tiene hojas: :attribute es una opción del PDF.',
            ),
        ], [
            'formato'     => 'formato',
            'orientacion' => 'la orientación',
            'hoja'        => 'el tamaño de hoja',
            'densidad'    => 'la densidad',
            'agrupar'     => 'agrupar',
            'salto'       => 'cada grupo en una hoja nueva',
        ]);

        $validador->after(function (Validador $validador) use ($listado, $usuario, $entrada) {
            foreach ((array) ($entrada['columnas'] ?? []) as $clave) {
                $columna = is_string($clave) ? $listado->columna($clave) : null;

                if ($columna?->permiso !== null && ! $usuario->can($columna->permiso)) {
                    $validador->errors()->add('columnas', "La columna «{$columna->rotulo}» pide un permiso que no tenés.");
                }
            }

            if (filter_var($entrada['salto'] ?? false, FILTER_VALIDATE_BOOLEAN) && blank($entrada['agrupar'] ?? null)) {
                $validador->errors()->add('salto', 'Para empezar cada grupo en una hoja nueva, elegí cómo agrupar.');
            }
        });

        $datos   = $validador->validate();
        $pedidas = $datos['columnas'] ?? null;

        return new Opciones(
            formato: $datos['formato'] ?? 'pdf',
            columnas: array_values(array_filter(
                $listado->columnasPara($usuario),
                fn (Columna $columna) => $pedidas === null
                    ? $columna->porOmision
                    : in_array($columna->clave, $pedidas, true),
            )),
            orientacion: $datos['orientacion'] ?? $listado->orientacion(),
            hoja: $datos['hoja'] ?? 'a4',
            densidad: $datos['densidad'] ?? 'normal',
            agrupacion: isset($datos['agrupar']) ? $listado->agrupaciones()[$datos['agrupar']] : null,
            saltoDeHoja: filter_var($datos['salto'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * Cuántas filas coinciden y cuánto suman, contado por la base sobre todas y no sobre las impresas.
     *
     * @return array{cantidad: int, sumas: array<string, float>}
     */
    public function resumen(Builder $consulta, Opciones $opciones): array
    {
        $fila = (clone $consulta)->reorder()
            ->selectRaw($this->agregados('COUNT(*) as cantidad', $opciones))
            ->toBase()
            ->first();

        return ['cantidad' => (int) $fila->cantidad, 'sumas' => $this->sumasDe($fila, $opciones)];
    }

    /**
     * El conteo y las sumas de cada grupo, por la base y de todas las coincidencias, por la clave del grupo.
     *
     * @return array<string, array{cantidad: int, sumas: array<string, float>}>  '' es «sin grupo»
     */
    public function subtotales(Builder $consulta, Opciones $opciones): array
    {
        $columna = $consulta->qualifyColumn($opciones->agrupacion->columna);

        $filas = (clone $consulta)->reorder()
            ->selectRaw($this->agregados("{$columna} as grupo, COUNT(*) as cantidad", $opciones))
            ->groupBy($columna)
            ->toBase()
            ->get();

        $subtotales = [];
        foreach ($filas as $fila) {
            $subtotales[(string) $fila->grupo] = [
                'cantidad' => (int) $fila->cantidad,
                'sumas'    => $this->sumasDe($fila, $opciones),
            ];
        }

        return $subtotales;
    }
    /**
     * Lo que la vista del PDF necesita: las primeras filas, en sus grupos, y los totales de todas.
     *
     * @param  array<string, string>  $filtros  los de Listado::filtrosAplicados()
     * @return array<string, mixed>
     */
    public function datosDelPdf(
        Listado $listado,
        Builder $consulta,
        Opciones $opciones,
        User $usuario,
        array $filtros,
        int $tope = self::TOPE_PDF,
    ): array {
        $resumen    = $this->resumen($consulta, $opciones);
        $agrupacion = $opciones->agrupacion;

        $filas = ($agrupacion === null ? clone $consulta : $this->agrupadas($consulta, $agrupacion))
            ->with($listado->relaciones())
            ->withCount($listado->conteos())
            ->limit($tope)
            ->get();

        return [
            'listado'  => $listado,
            'opciones' => $opciones,
            'grupos'   => $this->grupos($filas, $consulta, $opciones),
            'cantidad' => $resumen['cantidad'],
            'sumas'    => $resumen['sumas'],
            'tope'     => $tope,
            'filtros'  => $filtros,
            'emision'  => $this->emision($usuario),
        ];
    }

    /**
     * El PDF del listado, con la hoja y la orientación pedidas y sus hojas numeradas.
     *
     * @param  array<string, string>  $filtros
     */
    public function pdf(Listado $listado, Builder $consulta, Opciones $opciones, User $usuario, array $filtros): DocumentoPdf
    {
        $pdf = Pdf::loadView('impresion.listado-pdf', $this->datosDelPdf($listado, $consulta, $opciones, $usuario, $filtros))
            ->setPaper($opciones->papelDompdf(), $opciones->orientacionDompdf());

        $this->numerarHojas($pdf->getDomPDF());

        return $pdf;
    }

    /**
     * Escribe el listado entero, sin tope, de a tandas: en memoria vive una tanda y no el listado.
     *
     * @param  array<string, string>  $filtros  los de Listado::filtrosAplicados()
     * @param  resource  $salida
     */
    public function escribirCsv(
        Listado $listado,
        Builder $consulta,
        Opciones $opciones,
        User $usuario,
        array $filtros,
        $salida,
        int $tanda = self::TANDA,
    ): void {
        $resumen = $this->resumen($consulta, $opciones);

        // La marca de orden de bytes es lo que hace que Excel lea los acentos como UTF-8.
        fwrite($salida, "\xEF\xBB\xBF");
        $this->fila($salida, [$listado->titulo()]);
        $this->fila($salida, [$this->emision($usuario)]);
        $this->fila($salida, [$this->alcance($filtros)]);
        $this->fila($salida, []);
        $this->fila($salida, array_map(fn (Columna $columna) => $columna->rotulo, $opciones->columnas));

        $escribir = function (iterable $filas) use ($salida, $opciones) {
            foreach ($filas as $fila) {
                $this->fila($salida, array_map(fn (Columna $columna) => $columna->paraCsv($fila), $opciones->columnas));
            }
        };

        $recorrido = (clone $consulta)->reorder()->with($listado->relaciones())->withCount($listado->conteos());        $columna   = $listado->columnaDeTanda();

        $listado->tandaDescendente()
            ? $recorrido->chunkByIdDesc($tanda, $escribir, $recorrido->qualifyColumn($columna), $columna)
            : $recorrido->chunkById($tanda, $escribir, $recorrido->qualifyColumn($columna), $columna);

        $this->fila($salida, []);
        $this->totales($salida, $listado, $opciones, $resumen);
    }

    /** «Emitido el 07/11/2026 a las 20:41 por Pérez, Ana.» */
    public function emision(User $usuario): string
    {
        $ahora = now();

        return "Emitido el {$ahora->format('d/m/Y')} a las {$ahora->format('H:i')} por {$usuario->apellido}, {$usuario->nombre}.";
    }

    /** @param  array<string, string>  $filtros */
    public function alcance(array $filtros): string
    {
        if ($filtros === []) {
            return 'Alcance: sin ningún filtro aplicado.';
        }

        $partes = [];
        foreach ($filtros as $etiqueta => $valor) {
            $partes[] = "{$etiqueta}: {$valor}";
        }

        return 'Alcance: '.implode(' · ', $partes);
    }

    public function nombreDeArchivo(Listado $listado, string $extension): string
    {
        return $listado->archivo().'-'.now()->format('Y-m-d-His').'.'.$extension;
    }


    /** Primero el grupo y después el orden del listado, que se conserva entero adentro de cada uno. */
    private function agrupadas(Builder $consulta, Agrupacion $agrupacion): Builder
    {
        $agrupada   = clone $consulta;
        $base       = $agrupada->getQuery();
        $delListado = $base->orders ?? [];

        // Las órdenes del grupo no llevan bindings, así que se anteponen sin correr las del listado.
        $base->orders = null;
        $agrupada->orderBy(($agrupacion->orden)())->orderBy($agrupada->qualifyColumn($agrupacion->columna));
        $base->orders = [...$base->orders, ...$delListado];

        return $agrupada;
    }

    /**
     * Las filas impresas partidas en grupos, con el subtotal de la base; sin agrupar, un solo grupo sin título.
     *
     * @return array<int, array{etiqueta: ?string, detalle: ?string, filas: Collection, subtotal: ?array}>
     */
    private function grupos(Collection $filas, Builder $consulta, Opciones $opciones): array
    {
        $agrupacion = $opciones->agrupacion;

        if ($agrupacion === null || $filas->isEmpty()) {
            return [['etiqueta' => null, 'detalle' => null, 'filas' => $filas, 'subtotal' => null]];
        }

        $subtotales = $this->subtotales($consulta, $opciones);

        return $filas
            ->groupBy(fn (Model $fila) => (string) $fila->getAttribute($agrupacion->columna))
            ->map(fn (Collection $delGrupo, $clave) => [
                'etiqueta' => $agrupacion->etiquetaDe($delGrupo->first()),
                'detalle'  => $agrupacion->detalleDe($delGrupo->first()),
                'filas'    => $delGrupo,
                'subtotal' => $subtotales[(string) $clave],
            ])
            ->values()
            ->all();
    }
    
    /** «Hoja X de Y» abajo a la derecha: el total de hojas recién se conoce al terminar el documento. */
    private function numerarHojas(Dompdf $dompdf): void
    {
        $dompdf->setCallbacks([[
            'event' => 'end_document',
            'f'     => function ($hoja, $hojas, $lienzo, $metricas) {
                $texto  = "Hoja {$hoja} de {$hojas}";
                $fuente = $metricas->getFont('DejaVu Sans');
                $ancho  = $metricas->getTextWidth($texto, $fuente, 7);

                $lienzo->text($lienzo->get_width() - $ancho - 34, $lienzo->get_height() - 32, $texto, $fuente, 7, [0.4, 0.4, 0.4]);
            },
        ]]);
    }

    /**
     * El conteo y las sumas de la base, debajo de sus columnas.
     *
     * @param  resource  $salida
     * @param  array{cantidad: int, sumas: array<string, float>}  $resumen
     */
    private function totales($salida, Listado $listado, Opciones $opciones, array $resumen): void
    {
        $rotulo = 'Total: '.$listado->cuenta($resumen['cantidad']);
        $sumas  = array_map(
            fn (Columna $columna) => array_key_exists($columna->clave, $resumen['sumas'])
                ? $columna->enCsv($resumen['sumas'][$columna->clave])
                : '',
            $opciones->columnas,
        );

        if (($sumas[0] ?? '') !== '') {
            $this->fila($salida, [$rotulo]);
            $this->fila($salida, $sumas);

            return;
        }

        $sumas[0] = $rotulo;
        $this->fila($salida, $sumas);
    }

    /** @return array<int, Columna> las columnas elegidas que la base sabe sumar */
    private function sumadas(Opciones $opciones): array
    {
        return array_filter($opciones->columnas, fn (Columna $columna) => $columna->suma !== null);
    }

    /** El SELECT de una agregación: lo que se pide más una suma por columna sumable. */
    private function agregados(string $inicio, Opciones $opciones): string
    {
        foreach ($this->sumadas($opciones) as $columna) {
            $inicio .= ", COALESCE(SUM({$columna->suma}), 0) as suma_{$columna->clave}";
        }

        return $inicio;
    }

    /** @return array<string, float> */
    private function sumasDe(object $fila, Opciones $opciones): array
    {
        $sumas = [];
        foreach ($this->sumadas($opciones) as $columna) {
            $sumas[$columna->clave] = round((float) $fila->{"suma_{$columna->clave}"}, 2);
        }

        return $sumas;
    }

    /**
     * Punto y coma y coma decimal: es lo que abre bien una planilla configurada en castellano.
     *
     * @param  resource  $salida
     */
    private function fila($salida, array $celdas): void
    {
        fputcsv($salida, $celdas, ';', '"', '', "\r\n");
    }
}