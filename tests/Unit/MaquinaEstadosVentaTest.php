<?php

use App\Exceptions\TransicionInvalidaException;
use App\Models\Venta;
use App\Support\MaquinaEstadosVenta;

/*
|--------------------------------------------------------------------------
| Transiciones de una venta
|--------------------------------------------------------------------------
|
| Va en tests/Unit porque no toca la base: es una tabla y tres funciones. Referenciar
| Venta::ESTADOS es leer una constante de clase, no instanciar el modelo, así que no
| hace falta que la aplicación esté levantada — igual que MaquinaEstadosCompraTest.
|
| Es el test de C-9, y la mitad que importa es la segunda: que las transiciones que
| NO están declaradas se rechacen. La primera lista verifica que el flujo funcione;
| la segunda, que los cuatro agujeros que la auditoría encontró estén tapados.
|
*/

test('las transiciones declaradas se permiten', function (string $desde, string $hacia) {
    expect(MaquinaEstadosVenta::puede($desde, $hacia))->toBeTrue();
})->with([
    // Mostrador: se cotiza, se cobra, se entrega.
    ['presupuesto', 'pagada'],
    ['presupuesto', 'cancelada'],

    // Online (Etapa 2): el webhook de Mercado Pago confirma el pago.
    ['pendiente_pago', 'pagada'],
    ['pendiente_pago', 'cancelada'],

    // Cobrada: se entrega en el mostrador, o se prepara si hay envío.
    ['pagada', 'entregada'],
    ['pagada', 'en_preparacion'],

    // Y si se deshace, el camino es la devolución, que revierte los pagos.
    ['pagada', 'devuelta'],
    ['pagada', 'devuelta_parcial'],

    // Preparación y entrega del canal online.
    ['en_preparacion', 'despachada'],
    ['en_preparacion', 'lista_retiro'],
    ['despachada', 'entregada'],
    ['lista_retiro', 'entregada'],

    // Devoluciones después de entregar.
    ['entregada', 'devuelta_parcial'],
    ['entregada', 'devuelta'],

    // El mouse en enero y la fuente en marzo: una segunda devolución parcial
    // deja la venta en el mismo estado.
    ['devuelta_parcial', 'devuelta_parcial'],
    ['devuelta_parcial', 'devuelta'],
]);

test('las transiciones que no estan declaradas se rechazan', function (string $desde, string $hacia) {
    expect(MaquinaEstadosVenta::puede($desde, $hacia))->toBeFalse();
})->with([
    // El agujero más grave del original: `presupuesto → cobrada` marcaba la venta
    // como cobrada SIN descontar stock y sin un solo pago registrado, porque la
    // cadena de if no contemplaba el caso y caía en el UPDATE pelado.
    ['presupuesto', 'entregada'],
    ['presupuesto', 'en_preparacion'],

    // Un presupuesto que espera que el cliente vuelva no es `pendiente_pago`: ese
    // estado significa que el checkout online se cerró y el webhook no llegó.
    ['presupuesto', 'pendiente_pago'],

    // Editar un presupuesto no es una transición.
    ['presupuesto', 'presupuesto'],

    // Idempotencia. `confirmada → confirmada` descontaba stock de nuevo.
    ['pagada', 'pagada'],

    // Después de cobrar el camino es la devolución, que revierte los pagos (A-11).
    // El original permitía `cobrada → anulada`: reponía stock y dejaba la plata
    // cobrada sobre una venta inexistente.
    ['pagada', 'cancelada'],
    ['pagada', 'presupuesto'],

    // `anulada → confirmada` revivía la venta sin volver a descontar stock.
    ['cancelada', 'pagada'],
    ['cancelada', 'presupuesto'],
    ['devuelta', 'pagada'],

    // `cobrada → confirmada` dejaba pagos registrados sobre una venta no cobrada.
    ['entregada', 'pagada'],

    // Una venta entregada tampoco se cancela: ya hubo plata y mercadería.
    ['entregada', 'cancelada'],

    // No se desdevuelve.
    ['devuelta_parcial', 'entregada'],
]);

test('los estados finales no tienen salida hacia ningun estado', function () {
    // Se prueba contra los once estados que el modelo conoce, no contra una lista
    // escrita a mano: el día que se agregue un valor al ENUM, este test lo incluye
    // solo. `cancelada` y `devuelta` son el final del camino.
    foreach (array_keys(Venta::ESTADOS) as $destino) {
        expect(MaquinaEstadosVenta::puede('cancelada', $destino))->toBeFalse()
            ->and(MaquinaEstadosVenta::puede('devuelta', $destino))->toBeFalse();
    }

    expect(MaquinaEstadosVenta::destinosDesde('cancelada'))->toBe([])
        ->and(MaquinaEstadosVenta::destinosDesde('devuelta'))->toBe([]);
});

test('entregada_parcial esta declarado en la base pero ninguna transicion lo alcanza', function () {
    // El valor existe en el ENUM porque agregarlo después reescribiría la tabla, y
    // la venta con faltante está fuera del alcance de la Etapa 1. Que sea
    // inalcanzable es la decisión, no un efecto colateral: si alguien implementa el
    // backorder, este test le va a fallar y eso es exactamente lo que tiene que
    // pasar.
    expect(Venta::ESTADOS)->toHaveKey('entregada_parcial');

    foreach (array_keys(Venta::ESTADOS) as $origen) {
        expect(MaquinaEstadosVenta::puede($origen, 'entregada_parcial'))->toBeFalse();
    }

    expect(MaquinaEstadosVenta::destinosDesde('entregada_parcial'))->toBe([]);
});

test('la tabla no nombra ningun estado que el modelo no conozca', function () {
    // Un destino mal escrito —'entregda'— permitiría una transición hacia un estado
    // que la base rechazaría y que ninguna pantalla sabría nombrar. No lo detecta
    // ningún otro test: las listas de arriba sólo preguntan por los pares que
    // nombran. Un origen mal escrito sí lo caza la primera lista, porque el estado
    // bien escrito se queda sin destinos.
    $conocidos = array_keys(Venta::ESTADOS);

    foreach ($conocidos as $origen) {
        foreach (MaquinaEstadosVenta::destinosDesde($origen) as $destino) {
            expect($destino)->toBeIn($conocidos);
        }
    }
});

test('validar lanza una excepcion que nombra los dos estados en palabras', function () {
    // El mensaje lo lee el usuario: dice «Presupuesto» y «Entregada», no
    // 'presupuesto' ni el valor crudo de la columna. Los textos salen de
    // Venta::ESTADOS, y EsquemaDatosTest ya verifica que esa constante y el ENUM
    // digan lo mismo.
    expect(fn () => MaquinaEstadosVenta::validar('presupuesto', 'entregada'))
        ->toThrow(TransicionInvalidaException::class, 'Una venta «Presupuesto» no puede pasar a «Entregada».');
});

test('un estado desconocido no habilita nada', function () {
    // El `?? []` del lookup. Es la forma de C-2 en este rincón: lo que no está
    // declarado no concede por descarte. El original hacía
    // MAPA_PERMISOS[$action] ?? "can_update" y por eso un vendedor anulaba ventas
    // cobradas.
    expect(MaquinaEstadosVenta::puede('inventado', 'pagada'))->toBeFalse()
        ->and(MaquinaEstadosVenta::puede('inventado', 'cancelada'))->toBeFalse()
        ->and(MaquinaEstadosVenta::destinosDesde('inventado'))->toBe([]);
});