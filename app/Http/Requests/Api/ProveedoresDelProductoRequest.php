<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

/**
 * `GET /productos/{p}/proveedores` (tramo D): un recurso, dos representaciones (regla 36).
 *   sin forma          a quién se le puede pedir, para compras: pide proveedor.ver
 *   forma=comparador   el comparador del producto, con sus vínculos: pide producto.editar
 *   forma=disponibles  los activos que todavía no lo proveen, para el alta: pide producto.editar
 * El permiso depende de la forma, así que lo decide acá y no la ruta.
 *
 * Métodos:
 *   forma()         qué forma se pidió (null, la de compras)
 */
class ProveedoresDelProductoRequest extends PaginadoRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->forma() === null ? 'proveedor.ver' : 'producto.editar') === true;
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'forma' => ['nullable', Rule::in(['comparador', 'disponibles'])],
        ];
    }

    public function forma(): ?string
    {
        $forma = $this->query('forma');

        return in_array($forma, ['comparador', 'disponibles'], true) ? $forma : null;
    }
}
