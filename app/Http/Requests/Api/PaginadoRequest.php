<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `pagina` y `por_pagina` de todo listado de la API (docs/api.md §2).
 *
 * Los mensajes van acá y no en lang/: la API habla igual con cualquier APP_LOCALE.
 * La validación rechaza, no corrige: un `por_pagina` sobre el tope es 422, no se recorta.
 *
 * Métodos:
 *   pagina()      la página pedida (1 por omisión)
 *   porPagina()   el tamaño pedido (`paginado.por_pagina` por omisión)
 */
class PaginadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pagina'     => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:'.config('tienda.paginado.tope')],
        ];
    }

    public function messages(): array
    {
        return [
            'pagina.integer'     => 'La página tiene que ser un número entero.',
            'pagina.min'         => 'La página tiene que ser 1 o más.',
            'por_pagina.integer' => 'La cantidad por página tiene que ser un número entero.',
            'por_pagina.min'     => 'La cantidad por página tiene que ser 1 o más.',
            'por_pagina.max'     => 'Se pueden pedir hasta :max por página.',
        ];
    }

    public function pagina(): int
    {
        return $this->integer('pagina', 1);
    }

    public function porPagina(): int
    {
        return $this->integer('por_pagina', (int) config('tienda.paginado.por_pagina'));
    }
}
