<?php

namespace App\Impresion;

use App\Models\User;
use App\Services\ImpresionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * La impresión de un listado por la API (D-22): el endpoint del módulo pide el enlace con la
 * credencial, y el navegador lo abre sin ella. La firma cubre quién lo pidió, los filtros y las
 * opciones; al abrirlo se vuelve a exigir que esa cuenta siga activa y con el permiso.
 *
 * Métodos:
 *   enlace()    valida las opciones (422) y devuelve la URL firmada y temporal
 *   descarga()  el PDF o el CSV de un enlace ya verificado por el middleware `signed`
 *   archivo()   el PDF o el CSV de una consulta: lo comparten este camino y la web (ImprimeListados)
 */
final class ImpresionFirmada
{
    /** El nombre de la ruta firmada (routes/api.php). */
    public const RUTA = 'api.v1.impresiones.descarga';

    /** Los listados que se imprimen por enlace, por su clave en la URL. Cada tramo suma los suyos. */
    public const LISTADOS = [
        'marcas'      => ListadoMarcas::class,
        'categorias'  => ListadoCategorias::class,
        'productos'   => ListadoProductos::class,
        'clientes'    => ListadoClientes::class,
        'proveedores' => ListadoProveedores::class,
        'movimientos' => ListadoMovimientos::class,
        'compras'     => ListadoCompras::class,
    ];

    /** Las claves del diálogo de impresión; el resto de la consulta son filtros. */
    public const OPCIONES = ['formato', 'columnas', 'orientacion', 'hoja', 'densidad', 'agrupar', 'salto'];

    /** Quién pidió el enlace. No es un filtro, y la firma impide cambiarlo. */
    private const QUIEN = 'pedido_por';

    public function __construct(private ImpresionService $impresion)
    {
    }

    /**
     * @param  array<string, mixed>  $filtros  los ya validados por el Form Request de filtros del módulo
     * @param  array<string, mixed>  $entrada  la consulta entera: de ahí salen las opciones
     * @return array{url: string, vence: string}
     */
    public function enlace(string $clave, array $filtros, array $entrada, User $usuario): array
    {
        $listado  = $this->listado($clave);
        $opciones = array_intersect_key($entrada, array_flip(self::OPCIONES));

        $this->impresion->opciones($listado, $opciones, $usuario);

        $filtros = array_diff_key(array_filter($filtros, fn ($valor) => filled($valor)), array_flip(['page', 'pagina', 'por_pagina']));
        $vence   = now()->addMinutes((int) config('impresion.enlace_minutos'));

        return [
            'url'   => URL::temporarySignedRoute(self::RUTA, $vence, [
                'listado'   => $clave,
                self::QUIEN => $usuario->id,
                ...$filtros,
                ...$opciones,
            ]),
            'vence' => $vence->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @param  array<string, mixed>  $consulta  la de la URL, sin la firma
     *
     * @throws AuthorizationException  la cuenta ya no está activa o perdió el permiso
     */
    public function descarga(string $clave, array $consulta): Response
    {
        $listado = $this->listado($clave);
        $usuario = User::find($consulta[self::QUIEN] ?? null);

        if ($usuario === null || ! $usuario->activo || ! $usuario->can($listado->permiso())) {
            throw new AuthorizationException('Este enlace ya no sirve: pedí la impresión de nuevo.');
        }

        $entrada  = array_diff_key($consulta, array_flip([self::QUIEN, 'signature', 'expires']));
        $opciones = $this->impresion->opciones($listado, array_intersect_key($entrada, array_flip(self::OPCIONES)), $usuario);
        $filtros  = array_diff_key($entrada, array_flip(self::OPCIONES));

        return $this->archivo($listado, $listado->consulta($filtros), $opciones, $usuario, $listado->filtrosAplicados($filtros));
    }

    /** @param  array<string, string>  $aplicados  los filtros en palabras, para el bloque «Alcance» */
    public function archivo(Listado $listado, Builder $consulta, Opciones $opciones, User $usuario, array $aplicados): Response
    {
        $impresion = $this->impresion;

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

    /** @return Listado&ImprimiblePorEnlace */
    private function listado(string $clave): Listado
    {
        abort_unless(isset(self::LISTADOS[$clave]), 404);

        return app(self::LISTADOS[$clave]);
    }
}
