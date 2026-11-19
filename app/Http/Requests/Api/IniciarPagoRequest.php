<?php

namespace App\Http\Requests\Api;

use App\Models\IntentoPago;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Iniciar un pago (`POST /pedidos/{id}/pagos`): por qué pasarela. Si está habilitada lo decide
 * el servicio (409), no la validación: depende de la configuración, no de lo que llegó.
 */
class IniciarPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pasarela' => ['required', 'string', Rule::in(IntentoPago::PASARELAS)],
        ];
    }

    public function messages(): array
    {
        return [
            'pasarela.required' => 'Elegí cómo vas a pagar.',
            'pasarela.string'   => 'El medio de pago no es válido.',
            'pasarela.in'       => 'El medio de pago tiene que ser Mercado Pago o el simulador.',
        ];
    }

    public function pasarela(): string
    {
        return $this->validated('pasarela');
    }
}
