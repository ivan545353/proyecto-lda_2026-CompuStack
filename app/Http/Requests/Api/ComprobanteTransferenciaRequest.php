<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El comprobante de una transferencia (docs/api.md, `POST /pedidos/{id}/comprobante-transferencia`).
 * Una foto o el PDF del home banking, hasta 5 MB. Los mensajes van acá, como en `RegistroRequest`.
 */
class ComprobanteTransferenciaRequest extends FormRequest
{
    public const MAX_KB = 5120;

    public function authorize(): bool
    {
        return true;   // la ruta ya exige tienda.cargar_comprobante
    }

    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:'.self::MAX_KB],
        ];
    }

    public function messages(): array
    {
        return [
            'archivo.required' => 'Elegí el comprobante de la transferencia.',
            'archivo.file'     => 'El comprobante tiene que ser un archivo.',
            'archivo.uploaded' => 'No se pudo subir el comprobante. Probá de nuevo.',
            'archivo.mimes'    => 'El comprobante tiene que ser una foto (JPG o PNG) o un PDF.',
            'archivo.max'      => 'El comprobante puede pesar hasta 5 MB.',
        ];
    }
}
