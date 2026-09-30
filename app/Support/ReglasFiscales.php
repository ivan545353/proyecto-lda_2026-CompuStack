<?php

namespace App\Support;

use App\Models\Cliente;
use Illuminate\Validation\Rule;

/**
 * Reglas de validación de los datos fiscales de un cliente.
 *
 * Dos formularios piden los mismos datos con distinto nombre de campo: el de
 * usuarios, cuando el rol es de ámbito tienda y hay que crear la ficha del
 * cliente (`cliente.nro_doc`), y el de clientes (`nro_doc`). Hasta la Fase 4
 * las reglas vivían sólo en PersonalRequest; con el segundo uso quedó claro qué
 * es lo compartido —el juego de reglas— y qué cambia —el prefijo del campo—.
 *
 * Métodos:
 *   para()        las reglas, con el prefijo que corresponda
 *   normalizar()  saca los separadores de un documento, sin tocar el resto
 *   mensajes()    los mensajes en español, con el mismo prefijo
 */
final class ReglasFiscales
{
    /** Condiciones que obligan a identificar al cliente. */
    private const EXIGEN_DOCUMENTO = ['responsable_inscripto', 'monotributo'];

    /**
     * @param  string  $prefijo    'cliente.' en el formulario de usuarios, '' en el de clientes
     * @param  int|null  $clienteId  el cliente que se edita, para que el unique se ignore a sí mismo
     * @return array<string, array<int, mixed>>
     */
    public static function para(
        ?string $condicionIva,
        ?string $tipoDoc,
        ?int $clienteId = null,
        string $prefijo = '',
    ): array {
        return [
            $prefijo.'razon_social' => ['required', 'string', 'min:2', 'max:150'],

            // Las listas salen de las constantes del modelo, así que el Form
            // Request, el scope del listado y el selector de la vista comparan
            // exactamente los mismos valores.
            $prefijo.'tipo_doc'      => ['required', Rule::in(array_keys(Cliente::TIPOS_DOC))],
            $prefijo.'condicion_iva' => ['required', Rule::in(array_keys(Cliente::CONDICIONES_IVA))],

            $prefijo.'nro_doc' => [
                // Quien factura A o es monotributista tiene que estar
                // identificado; el consumidor final puede no estarlo, y por eso
                // la columna es nullable.
                in_array($condicionIva, self::EXIGEN_DOCUMENTO, true) ? 'required' : 'nullable',

                match ($tipoDoc) {
                    'dni'          => 'digits_between:6,9',
                    'cuit', 'cuil' => 'digits:11',
                    default        => 'string',
                },

                // La base tiene UNIQUE(tipo_doc, nro_doc): la regla dice lo
                // mismo antes, para que el usuario reciba un mensaje y no un
                // error de integridad. Los NULL no colisionan entre sí en
                // MariaDB, así que dos clientes de mostrador sin documento
                // conviven sin problema, que es justo lo que hace falta.
                Rule::unique('clientes', 'nro_doc')
                    ->where(fn ($query) => $query->where('tipo_doc', $tipoDoc))
                    ->ignore($clienteId),
            ],

            $prefijo.'email'    => ['nullable', 'email', 'max:150'],
            $prefijo.'telefono' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * Saca los separadores con los que se escribe un CUIT (30-71555888-1).
     *
     * Normaliza el formato, no el contenido: si hay letras siguen ahí y la regla
     * las rechaza con un mensaje que lo dice. Limpiarlas sería repetir M-31,
     * donde "30-ABC" se convertía en "30" y se guardaba igual.
     */
    public static function normalizar(?string $documento): ?string
    {
        return is_string($documento)
            ? preg_replace('/[\s.\-]/', '', $documento)
            : $documento;
    }

    /** @return array<string, string> */
    public static function mensajes(string $prefijo = ''): array
    {
        return [
            $prefijo.'razon_social.required'  => 'Escribí el nombre o la razón social.',
            $prefijo.'tipo_doc.required'      => 'Elegí el tipo de documento.',
            $prefijo.'condicion_iva.required' => 'Elegí la condición frente al IVA.',
            $prefijo.'nro_doc.required'       => 'Para esa condición frente al IVA hace falta el número de documento.',
            $prefijo.'nro_doc.digits'         => 'El CUIT/CUIL tiene 11 dígitos.',
            $prefijo.'nro_doc.digits_between' => 'El DNI se escribe sin puntos, entre 6 y 9 dígitos.',
            $prefijo.'nro_doc.unique'         => 'Ya hay un cliente registrado con ese documento.',
        ];
    }
}