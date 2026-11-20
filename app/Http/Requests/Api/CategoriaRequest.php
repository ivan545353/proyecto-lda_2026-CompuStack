<?php

namespace App\Http\Requests\Api;

use App\Models\Categoria;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El alta y la edición de una categoría (tramo D). Rechaza, no corrige (M-31): `activo` viaja
 * siempre, y en la edición también `parent_id`, aunque sea null. Un PATCH que lo omitiera
 * mudaría la categoría a la raíz sin que nadie lo pidiera.
 *
 * Las tres reglas de la jerarquía que la base no puede garantizar:
 *   1. Nombre único dentro del mismo padre: en MariaDB dos NULL nunca colisionan en un UNIQUE.
 *   2. El padre no puede ser la propia categoría ni una descendiente (sería un ciclo).
 *   3. El árbol no pasa de Categoria::PROFUNDIDAD_MAXIMA niveles, contando el subárbol que se mueve.
 *
 * Métodos:
 *   rules()        las reglas
 *   padreValido()  reglas 2 y 3, y que un padre nuevo esté activo
 *   messages()     los mensajes, en español
 */
class CategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige categoria.crear o categoria.editar
    }

    public function rules(): array
    {
        $categoria = $this->route('categoria');
        $padreId   = $this->input('parent_id');

        return [
            'nombre' => [
                'required', 'string', 'min:2', 'max:100',
                // Regla 1, con el whereNull escrito a mano: es justo el caso que el índice no cubre.
                Rule::unique('categorias', 'nombre')
                    ->where(fn (Builder $query) => $padreId === null
                        ? $query->whereNull('parent_id')
                        : $query->where('parent_id', $padreId))
                    ->ignore($categoria?->id),
            ],
            // bail: si el padre no existe, no tiene sentido evaluar si forma un ciclo.
            'parent_id' => [
                'bail', ...($categoria === null ? [] : ['present']), 'nullable', 'integer',
                'exists:categorias,id', $this->padreValido(...),
            ],
            'orden'                => ['nullable', 'integer', 'min:0', 'max:9999'],
            'activo'               => ['required', 'boolean'],
            'desactivar_contenido' => ['nullable', 'boolean'],
        ];
    }

    private function padreValido(string $atributo, mixed $valor, Closure $fallar): void
    {
        $categoria = $this->route('categoria');   // null en el alta
        $padre     = Categoria::find($valor);

        if ($padre === null) {
            return;   // ya lo informó exists
        }

        if ($categoria !== null) {
            if ($padre->id === $categoria->id) {
                $fallar('Una categoría no puede estar dentro de sí misma.');

                return;
            }

            if (in_array($padre->id, $categoria->idsDescendientes(), true)) {
                $fallar("«{$padre->nombre}» es una subcategoría de esta, así que no puede contenerla.");

                return;
            }
        }

        // Sólo si el padre CAMBIA: la hija de un padre desactivado después se sigue pudiendo editar.
        $cambiaDePadre = $categoria === null || $categoria->parent_id !== $padre->id;

        if ($cambiaDePadre && ! $padre->activo) {
            $fallar("«{$padre->nombre}» está inactiva. Activala primero o elegí otra.");

            return;
        }

        $altura = $categoria?->alturaSubarbol() ?? 1;

        if ($padre->nivel() + $altura > Categoria::PROFUNDIDAD_MAXIMA) {
            $fallar(sprintf(
                'Las categorías se pueden agrupar hasta en %d niveles. Dentro de «%s», esta categoría%s pasaría ese límite.',
                Categoria::PROFUNDIDAD_MAXIMA,
                $padre->nombre,
                $altura > 1 ? ', junto con sus subcategorías,' : '',
            ));
        }
    }

    public function messages(): array
    {
        return [
            'nombre.required'    => 'La categoría necesita un nombre.',
            'nombre.string'      => 'La categoría necesita un nombre.',
            'nombre.min'         => 'El nombre debe tener al menos 2 caracteres.',
            'nombre.max'         => 'El nombre no puede superar los 100 caracteres.',
            'nombre.unique'      => 'Ya existe una categoría con ese nombre en el mismo grupo.',
            'parent_id.present'  => 'Indicá dentro de qué categoría está, o ninguna si es principal.',
            'parent_id.integer'  => 'La categoría elegida no es válida.',
            'parent_id.exists'   => 'La categoría elegida ya no existe. Recargá y elegí otra.',
            'orden.integer'      => 'El orden debe ser un número entero.',
            'orden.min'          => 'El orden no puede ser negativo.',
            'orden.max'          => 'El orden no puede superar 9999.',
            'activo.required'    => 'Indicá si la categoría queda activa.',
            'activo.boolean'     => 'El estado de la categoría es sí o no.',
            '*.boolean'          => 'Esa opción es sí o no.',
        ];
    }
}
