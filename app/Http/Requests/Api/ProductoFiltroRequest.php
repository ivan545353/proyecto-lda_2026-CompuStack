<?php

namespace App\Http\Requests\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\Rule;

/**
 * Los filtros del listado de productos de la administración (tramo D): `GET /productos?forma=gestion`
 * y `GET /productos/impresion`. Sin `forma`, `GET /productos` sigue siendo el catálogo de la tienda
 * (CatalogoRequest) y no lee nada de esto. Un filtro inválido es 422.
 *
 * Contrato:
 *   ?q=             texto, código o nombre contiene
 *   ?categoria_id=  id, la categoría y todas sus subcategorías
 *   ?marca_id=      id
 *   ?proveedor_id=  id, los productos que se le compran a ese proveedor
 *   ?estado=        activos | inactivos
 *   ?stock=         disponible | agotado | critico | sin_proveedor
 *   ?con_proveedor= 1, sólo los que se le pueden comprar a alguien activo (el buscador de compras)
 *   ?excluir[]=     ids que ya están cargados en la pantalla, hasta 50 (el buscador de compras)
 *
 * Métodos:
 *   authorize()  la forma de gestión pide producto.ver; también al visitante, que recibe 403
 *   porPagina()  de a 20 por omisión, como la pantalla de siempre
 *   criterios()  lo que se aplicó, normalizado
 */
class ProductoFiltroRequest extends PaginadoRequest
{
    public const POR_PAGINA = 20;

    public const ESTADOS = ['activos', 'inactivos'];

    public const STOCK = ['disponible', 'agotado', 'critico', 'sin_proveedor'];

    private const FILTROS = ['q', 'categoria_id', 'marca_id', 'proveedor_id', 'estado', 'stock'];

    // Los recortes del buscador remoto (tramo D, ex /buscar/productos): no son filtros de la pantalla.
    private const RECORTES = ['con_proveedor', 'excluir'];

    public function authorize(): bool
    {
        return $this->user()?->can('producto.ver') === true;
    }

    /** Sin sesión es 401, como en una ruta protegida: lo que falta es ingresar, no un permiso. */
    protected function failedAuthorization(): void
    {
        if ($this->user() === null) {
            throw new AuthenticationException;
        }

        parent::failedAuthorization();
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'forma'        => ['nullable', Rule::in(['gestion'])],
            'q'            => ['nullable', 'string', 'max:100'],
            'categoria_id' => ['nullable', 'integer', 'min:1'],
            'marca_id'     => ['nullable', 'integer', 'min:1'],
            // Que exista: ignorar un recorte por un proveedor que no está devolvería el catálogo entero (M-31).
            'proveedor_id' => ['nullable', 'integer', 'min:1', 'exists:proveedores,id'],
            'estado'       => ['nullable', Rule::in(self::ESTADOS)],
            'stock'        => ['nullable', Rule::in(self::STOCK)],
            'con_proveedor' => ['nullable', 'boolean'],
            'excluir'      => ['nullable', 'array', 'max:50'],
            'excluir.*'    => ['integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'q.max'            => 'La búsqueda puede tener hasta :max caracteres.',
            'categoria_id.*'   => 'La categoría no es válida.',
            'marca_id.*'       => 'La marca no es válida.',
            'proveedor_id.*'   => 'El proveedor no es válido.',
            'excluir.max'      => 'No se pueden excluir más de :max productos.',
            'estado.in'        => 'Ese estado no existe: activos o inactivos.',
            'stock.in'         => 'Ese filtro de stock no existe. Podés usar: '.implode(', ', self::STOCK).'.',
        ];
    }

    public function porPagina(): int
    {
        return $this->integer('por_pagina', self::POR_PAGINA);
    }

    /** Los filtros validados, sin la forma ni el paginado: lo que reciben la consulta y el enlace. */
    public function filtros(): array
    {
        return array_intersect_key($this->validated(), array_flip([...self::FILTROS, ...self::RECORTES]));
    }

    public function criterios(): array
    {
        return [
            'forma' => 'gestion',
            ...array_map(fn (string $clave) => $this->filled($clave) ? (string) $this->input($clave) : null, array_combine(self::FILTROS, self::FILTROS)),
            'con_proveedor' => $this->boolean('con_proveedor'),
            'excluir'       => array_map('intval', array_values((array) $this->input('excluir', []))),
        ];
    }
}
