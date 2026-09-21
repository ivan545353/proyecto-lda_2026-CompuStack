<?php

namespace App\Http\Requests;

use App\Models\Categoria;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del formulario de categoría. Sirve para el alta y la edición.
 *
 * Además de las reglas de campo (M-31: se rechaza, no se vacía), valida las
 * tres reglas de la jerarquía, porque la base no puede garantizar ninguna:
 *
 *   1. Nombre único dentro del mismo padre. El índice UNIQUE(parent_id,
 *      nombre) no cubre la raíz: en MariaDB dos NULL nunca colisionan, así
 *      que la base aceptaría dos "Componentes" de primer nivel.
 *   2. El padre no puede ser la propia categoría ni una descendiente. Eso
 *      formaría un ciclo, y todo lo que sube por el árbol (la ruta, el nivel)
 *      entraría en un bucle.
 *   3. El árbol no pasa de Categoria::PROFUNDIDAD_MAXIMA niveles. Al mover una
 *      categoría con hijas se mueve el subárbol completo, así que se controla
 *      la altura del subárbol, no sólo la categoría.
 *
 * peso_default_gramos no está en el formulario: se usa para cotizar envíos
 * (Etapa 2). El seeder lo completa y el servicio no lo pisa al editar.
 *
 * Métodos:
 *   authorize()             true; el permiso lo exige la ruta
 *   prepareForValidation()  normaliza nombre, orden y el checkbox
 *   rules()                 reglas
 *   padreValido()           reglas 2 y 3, y que el padre nuevo esté activo
 *   messages()              mensajes en español
 */
class CategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige categoria.crear o categoria.editar
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'orden'  => $this->input('orden') ?? 0,
            'activo' => $this->boolean('activo'),
        ]);
    }

    public function rules(): array
    {
        $categoria = $this->route('categoria');
        $padreId   = $this->input('parent_id');

        return [
            'nombre' => [
                'required', 'string', 'min:2', 'max:100',
                // Regla 1. El whereNull va escrito a mano y no confiado a que
                // el constructor de consultas traduzca un null: es justamente
                // el caso que el índice de la base no cubre, y tiene que
                // leerse sin ambigüedad.
                Rule::unique('categorias', 'nombre')
                    ->where(fn (Builder $query) => $padreId === null
                        ? $query->whereNull('parent_id')
                        : $query->where('parent_id', $padreId))
                    ->ignore($categoria?->id),
            ],

            // bail: si el padre no existe, no tiene sentido evaluar si forma
            // un ciclo. El usuario recibe un solo mensaje, el que corresponde.
            'parent_id' => ['bail', 'nullable', 'integer', 'exists:categorias,id', $this->padreValido(...)],

            'orden'  => ['required', 'integer', 'min:0', 'max:9999'],
            'activo' => ['required', 'boolean'],
        ];
    }

    private function padreValido(string $atributo, mixed $valor, Closure $fallar): void
    {
        $categoria = $this->route('categoria');   // null en el alta
        $padre     = Categoria::find($valor);

        if ($padre === null) {
            return;   // ya lo informó exists
        }

        // Regla 2: ni ella misma ni una descendiente.
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

        // Un padre inactivo no recibe categorías nuevas. Se controla sólo si
        // el padre CAMBIA: una categoría cuyo padre fue desactivado después
        // tiene que poder seguir editándose sin que el formulario la rechace
        // por algo que no tocó.
        $cambiaDePadre = $categoria === null || $categoria->parent_id !== $padre->id;

        if ($cambiaDePadre && ! $padre->activo) {
                 $fallar("«{$padre->nombre}» está inactiva. Activala primero o elegí otra.");

            return;
        }

        // Regla 3: el subárbol completo tiene que entrar debajo del padre.
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
            'nombre.required'  => 'La categoría necesita un nombre.',
            'nombre.min'       => 'El nombre debe tener al menos 2 caracteres.',
            'nombre.max'       => 'El nombre no puede superar los 100 caracteres.',
            'nombre.unique'    => 'Ya existe una categoría con ese nombre en el mismo grupo.',
            'parent_id.exists' => 'La categoría elegida ya no existe. Recargá y elegí otra.',
            'orden.integer'    => 'El orden debe ser un número entero.',
            'orden.min'        => 'El orden no puede ser negativo.',
            'orden.max'        => 'El orden no puede superar 9999.',
        ];
    }

    /** Cómo se nombra cada campo en los mensajes que no están en messages(). */
    public function attributes(): array
    {
        return [
            'nombre'    => 'nombre',
            'parent_id' => 'categoría en la que está',
            'orden'     => 'orden',
            'activo'    => 'estado',
        ];
    }
}