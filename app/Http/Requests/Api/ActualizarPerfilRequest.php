<?php

namespace App\Http\Requests\Api;

use App\Models\Cliente;
use App\Models\Direccion;
use App\Rules\DocumentoTienda;
use App\Support\ReglasFiscales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Editar la cuenta propia (`PATCH /perfil`, D-22). Parcial: se cambia sólo lo que viene, así el
 * paso 4 manda sólo la dirección y un dato que cargó el cajero no traba lo demás. Lo fiscal que
 * decide el comprobante (condición frente al IVA), el correo y la razón social no se mandan.
 *
 * Métodos:
 *   datos()  lo que guarda el servicio, sólo las claves que vinieron
 */
class ActualizarPerfilRequest extends FormRequest
{
    /** Largo de `clientes.razon_social` (HL-53). */
    private const RAZON_SOCIAL_MAX = 150;

    public function authorize(): bool
    {
        return true;   // la cuenta propia: la ruta sólo pide la sesión
    }

    /** El documento se guarda sin separadores, como en la ficha del cajero. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nro_doc'))) {
            $this->merge(['nro_doc' => ReglasFiscales::normalizar($this->input('nro_doc'))]);
        }
    }

    public function rules(): array
    {
        $direccion = [];
        if (is_array($this->input('direccion'))) {
            foreach (ConfirmarPedidoRequest::CAMPOS_DIRECCION as $campo => $largo) {
                $direccion["direccion.{$campo}"] = $campo === 'piso_depto'
                    ? ['nullable', 'string', "max:{$largo}"]
                    : ['required', 'string', "max:{$largo}"];
            }
            $direccion['direccion.provincia'][] = Rule::in(Direccion::PROVINCIAS);   // la lista de la web (HL-69)
        }

        $tipoDoc = $this->input('tipo_doc');

        return [
            'nombre'    => ['sometimes', 'required', 'string', 'max:100'],
            'apellido'  => ['sometimes', 'required', 'string', 'max:100'],
            'telefono'  => ['sometimes', 'nullable', 'string', 'max:30'],
            // Juntos o ninguno: los dos con valor lo cargan; los dos en null lo quitan. Sin `sometimes`:
            // con él, un tipo sin la clave del número ni se validaría.
            'tipo_doc'  => ['nullable', 'required_with:nro_doc', Rule::in(array_keys(Cliente::TIPOS_DOC))],
            'nro_doc'   => ['nullable', 'required_with:tipo_doc', new DocumentoTienda($tipoDoc),
                // Mismo UNIQUE(tipo_doc, nro_doc) de la base, con mensaje en vez de error de integridad.
                Rule::unique('clientes', 'nro_doc')
                    ->where(fn ($query) => $query->where('tipo_doc', $tipoDoc))
                    ->ignore($this->user()->cliente?->id),
            ],
            'direccion' => ['sometimes', 'nullable', 'array'],
            ...$direccion,
            ...array_fill_keys(['email', 'condicion_iva', 'razon_social'], ['prohibited']),
            ...array_fill_keys(CarritoCantidadRequest::IMPORTES, ['prohibited']),
        ];
    }

    /** Lo que depende de lo ya guardado: el largo de la razón social y quién no puede quedar sin documento. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $cliente = $this->user()->cliente;

                if (! $validator->errors()->hasAny(['nombre', 'apellido'])
                    && ($this->exists('nombre') || $this->exists('apellido'))
                    && mb_strlen($this->razonSocial()) > self::RAZON_SOCIAL_MAX) {
                    // Se marca el campo que vino; si vinieron los dos, el apellido (como el registro).
                    $validator->errors()->add($this->exists('apellido') ? 'apellido' : 'nombre',
                        'Entre nombre y apellido admiten hasta '.self::RAZON_SOCIAL_MAX.' caracteres.');
                }

                // Quitar el documento a quien factura A o es monotributista lo dejaría sin identificar.
                if ($this->exists('nro_doc') && $this->input('nro_doc') === null && $this->input('tipo_doc') === null
                    && in_array($cliente?->condicion_iva, ReglasFiscales::EXIGEN_DOCUMENTO, true)) {
                    $validator->errors()->add('nro_doc',
                        'Con tu condición frente al IVA hace falta el documento: corregilo, no lo quites.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            '*.prohibited'             => 'El precio lo calcula la tienda: no se envía.',
            'condicion_iva.prohibited' => 'La condición frente al IVA la carga CompuStack.',
            'email.prohibited'         => 'El correo de la cuenta no se cambia desde la tienda.',
            'razon_social.prohibited'  => 'La razón social se arma con tu nombre y apellido.',
            'nombre.required'          => 'Escribí tu nombre.',
            'nombre.string'            => 'El nombre tiene que ser un texto.',
            'nombre.max'               => 'El nombre admite hasta :max caracteres.',
            'apellido.required'        => 'Escribí tu apellido.',
            'apellido.string'          => 'El apellido tiene que ser un texto.',
            'apellido.max'             => 'El apellido admite hasta :max caracteres.',
            'telefono.string'          => 'El teléfono tiene que ser un texto.',
            'telefono.max'             => 'El teléfono admite hasta :max caracteres.',
            'tipo_doc.required_with'   => 'Elegí el tipo de documento.',
            'tipo_doc.in'              => 'Elegí DNI, CUIT o CUIL.',
            'nro_doc.required_with'    => 'Escribí el número de documento.',
            'nro_doc.unique'           => 'Ese documento ya está cargado en otra cuenta. Si es tuyo, avisanos.',
            'direccion.array'          => 'La dirección no tiene la forma esperada.',
            'direccion.*.required'     => 'Completá este dato de la dirección.',
            'direccion.*.string'       => 'Este dato de la dirección tiene que ser un texto.',
            'direccion.*.max'          => 'Este dato de la dirección admite hasta :max caracteres.',
            'direccion.provincia.in'   => 'Elegí una provincia de la lista.',
        ];
    }

    /** Lo que guarda el servicio, uno por uno y sólo lo que vino. */
    public function datos(): array
    {
        $datos = [];

        foreach (['nombre', 'apellido', 'telefono'] as $campo) {
            if ($this->exists($campo)) {
                $datos[$campo] = $this->input($campo);
            }
        }

        if ($this->exists('tipo_doc') || $this->exists('nro_doc')) {
            $datos['documento'] = $this->input('nro_doc') === null ? null : [
                'tipo_doc' => (string) $this->input('tipo_doc'),
                'nro_doc'  => (string) $this->input('nro_doc'),
            ];
        }

        if ($this->exists('direccion')) {
            $datos['direccion'] = $this->input('direccion') === null ? null : [
                'calle'         => (string) $this->input('direccion.calle'),
                'numero'        => (string) $this->input('direccion.numero'),
                'piso_depto'    => $this->input('direccion.piso_depto'),
                'codigo_postal' => (string) $this->input('direccion.codigo_postal'),
                'localidad'     => (string) $this->input('direccion.localidad'),
                'provincia'     => (string) $this->input('direccion.provincia'),
            ];
        }

        return $datos;
    }

    /** «nombre apellido» con lo que vino, o con lo guardado si no vino. */
    private function razonSocial(): string
    {
        $usuario = $this->user();

        return trim(($this->exists('nombre') ? $this->input('nombre') : $usuario->nombre)
            .' '.($this->exists('apellido') ? $this->input('apellido') : $usuario->apellido));
    }
}
