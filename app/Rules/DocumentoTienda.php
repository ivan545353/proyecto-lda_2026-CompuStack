<?php

namespace App\Rules;

use App\Models\Cliente;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El número de documento que carga el cliente desde la tienda: DNI de 7 u 8 dígitos; CUIT o CUIL
 * de 11 con su dígito verificador. Más estricta que `ReglasFiscales` (la del cajero), que no revisa
 * el verificador y cuyos tests usan CUIT inventados (HL-66): por eso vive aparte.
 */
class DocumentoTienda implements ValidationRule
{
    /** Pesos del dígito verificador del CUIT y del CUIL, de izquierda a derecha. */
    private const PESOS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public function __construct(private readonly ?string $tipoDoc) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('El número de documento tiene que ser un texto.');

            return;
        }

        if ($this->tipoDoc === 'dni') {
            if (preg_match('/^\d{7,8}$/', $value) !== 1) {
                $fail('El DNI se escribe sin puntos, con 7 u 8 dígitos.');
            }

            return;
        }

        // Sin un tipo válido, el error es de `tipo_doc`: no se duplica acá.
        if (! in_array($this->tipoDoc, ['cuit', 'cuil'], true)) {
            return;
        }

        $nombre = Cliente::TIPOS_DOC[$this->tipoDoc];

        if (preg_match('/^\d{11}$/', $value) !== 1) {
            $fail("El {$nombre} tiene 11 dígitos.");
        } elseif (! self::verificadorValido($value)) {
            $fail("Ese {$nombre} no es válido: revisá el último dígito.");
        }
    }

    /** Módulo 11: un resto que daría 10 no existe en un CUIT emitido. */
    private static function verificadorValido(string $numero): bool
    {
        $suma = 0;
        foreach (self::PESOS as $i => $peso) {
            $suma += (int) $numero[$i] * $peso;
        }

        $esperado = 11 - $suma % 11;
        $esperado = $esperado === 11 ? 0 : $esperado;

        return $esperado !== 10 && $esperado === (int) $numero[10];
    }
}
