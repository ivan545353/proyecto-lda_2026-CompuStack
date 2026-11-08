<?php

use App\Support\ReglasFiscales;

test('normalizar saca guiones, puntos y espacios', function () {
    expect(ReglasFiscales::normalizar('30-71555888-1'))->toBe('30715558881')
        ->and(ReglasFiscales::normalizar('30.715.558.881'))->toBe('30715558881')
        ->and(ReglasFiscales::normalizar(' 41 556 778 '))->toBe('41556778');
});

test('normalizar no toca las letras', function () {
    // Normaliza el formato, no el contenido. Limpiarlas convertiría "30-ABC" en
    // "30" y lo guardaría como un documento válido: es M-31 exacto.
    expect(ReglasFiscales::normalizar('30-ABC-1'))->toBe('30ABC1');
});

test('normalizar deja pasar el valor nulo', function () {
    expect(ReglasFiscales::normalizar(null))->toBeNull();
});