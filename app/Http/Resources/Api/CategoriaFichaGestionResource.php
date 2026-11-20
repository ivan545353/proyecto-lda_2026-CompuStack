<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;

/**
 * Una categoría para su formulario y su miga de pan (tramo D): además, de dónde cuelga y qué tiene
 * activo adentro, que es lo que el formulario avisa antes de desactivarla.
 */
class CategoriaFichaGestionResource extends CategoriaGestionResource
{
    public function toArray(Request $request): array
    {
        $ancestros = [];

        for ($actual = $this->padre; $actual !== null; $actual = $actual->padre) {
            array_unshift($ancestros, ['id' => $actual->id, 'nombre' => $actual->nombre]);
        }

        return [
            ...parent::toArray($request),
            'ancestros'        => $ancestros,
            'contenido_activo' => $this->contenidoActivo(),
        ];
    }
}
