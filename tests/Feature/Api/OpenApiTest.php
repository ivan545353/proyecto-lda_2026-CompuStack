<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| docs/openapi.yaml contra las rutas reales de /api/v1 (tramo K)
|--------------------------------------------------------------------------
|
| El documento no puede afirmar lo que el servidor no hace (regla 16): cada operación es una
| ruta, cada ruta es una operación, la seguridad es la del middleware y cada `x-permiso` es el
| `can:` de su ruta. No toca la base: lee el archivo y el enrutador.
|
*/

beforeEach(function () {
    $this->spec = Yaml::parseFile(base_path('docs/openapi.yaml'));

    // «MÉTODO /ruta» => [clase de acceso, permiso del can: o null]. Sin HEAD: lo agrega el enrutador.
    $this->rutas = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($ruta) => str_starts_with($ruta->uri(), 'api/v1/'))
        ->flatMap(function ($ruta) {
            $mw      = array_filter($ruta->gatherMiddleware(), 'is_string');
            $permiso = collect($mw)->first(fn ($m) => str_starts_with($m, 'can:'));
            $acceso  = match (true) {
                (bool) array_intersect($mw, ['api.protegida', 'tienda.protegida'])           => 'protegida',
                (bool) array_intersect($mw, ['api.sesion_opcional', 'api.credencial_si_sirve']) => 'opcional',
                in_array('signed', $mw, true)                                                 => 'firmada',
                default                                                                       => 'publica',
            };
            $camino = substr($ruta->uri(), strlen('api/v1'));

            return collect(array_diff($ruta->methods(), ['HEAD']))
                ->mapWithKeys(fn ($metodo) => ["{$metodo} {$camino}" => [$acceso, $permiso ? substr($permiso, 4) : null]]);
        });

    // «MÉTODO /ruta» => [operación, parámetros del path].
    $this->operaciones = collect($this->spec['paths'])
        ->flatMap(fn (array $item, string $ruta) => collect($item)
            ->only(['get', 'post', 'put', 'patch', 'delete'])
            ->mapWithKeys(fn (array $op, string $metodo) => [strtoupper($metodo).' '.$ruta => [$op, $item['parameters'] ?? []]]));
});

test('el documento es OpenAPI 3.1 y su versión es la revisión de api.md', function () {
    preg_match('/\*\*Revisión (\d+)\*\*/', file_get_contents(base_path('docs/api.md')), $revision);

    expect($this->spec['openapi'])->toBe('3.1.0')
        ->and($this->spec['info']['version'])->toBe('r'.$revision[1]);
});

test('cada operación del documento es una ruta de /api/v1', function () {
    // El simulador existe sólo con su llave encendida (D-13): el documento lo marca como condicional.
    $sobran = $this->operaciones
        ->reject(fn ($par, $clave) => $this->rutas->has($clave))
        ->reject(fn ($par) => ($par[0]['x-condicional'] ?? null) === 'TIENDA_SIMULADOR' && ! config('tienda.pagos.simulador'))
        ->keys();

    expect($sobran->all())->toBe([]);
});

test('cada ruta de /api/v1 está en el documento', function () {
    $faltan = $this->rutas->keys()->reject(fn ($clave) => $this->operaciones->has($clave));

    expect($faltan->values()->all())->toBe([]);
});

test('cada $ref apunta a un componente que existe', function () {
    $refs = [];
    array_walk_recursive($this->spec, function ($valor, $clave) use (&$refs) {
        if ($clave === '$ref') {
            $refs[] = $valor;
        }
    });

    $rotos = collect($refs)->unique()->reject(function (string $ref) {
        $nodo = $this->spec;
        foreach (explode('/', substr($ref, 2)) as $parte) {
            if (! is_array($nodo) || ! array_key_exists($parte, $nodo)) {
                return false;
            }
            $nodo = $nodo[$parte];
        }

        return str_starts_with($ref, '#/components/');
    });

    expect($refs)->not->toBeEmpty()
        ->and($rotos->values()->all())->toBe([]);
});

test('cada operación declara la seguridad de su ruta y el error que le corresponde', function () {
    $mal = $this->operaciones->filter(fn ($par, $clave) => $this->rutas->has($clave))->filter(function ($par, $clave) {
        [$op]      = $par;
        [$acceso]  = $this->rutas[$clave];
        $respuestas = array_map('strval', array_keys($op['responses'] ?? []));

        return ! array_key_exists('security', $op) || match ($acceso) {
            'protegida' => $op['security'] !== [['credencial' => []]] || ! in_array('401', $respuestas, true),
            'opcional'  => $op['security'] !== [[], ['credencial' => []]],
            'firmada'   => $op['security'] !== [] || ! in_array('403', $respuestas, true),
            'publica'   => $op['security'] !== [],
        };
    })->keys();

    expect($mal->all())->toBe([]);
});

test('cada x-permiso es el can: de su ruta, y la ruta con permiso declara el 403', function () {
    $mal = $this->operaciones->filter(fn ($par, $clave) => $this->rutas->has($clave))->filter(function ($par, $clave) {
        [$op]          = $par;
        [, $permiso]   = $this->rutas[$clave];
        $respuestas    = array_map('strval', array_keys($op['responses'] ?? []));

        return ($op['x-permiso'] ?? null) !== $permiso
            || ($permiso !== null && ! in_array('403', $respuestas, true));
    })->map(fn ($par, $clave) => [$par[0]['x-permiso'] ?? null, $this->rutas[$clave][1]]);

    expect($mal->all())->toBe([]);
});

test('cada parámetro de la ruta está declarado en el documento', function () {
    $resolver = fn (array $p) => isset($p['$ref'])
        ? $this->spec['components']['parameters'][substr($p['$ref'], strlen('#/components/parameters/'))]
        : $p;

    $mal = $this->operaciones->filter(function ($par, $clave) use ($resolver) {
        [$op, $comunes] = $par;
        preg_match_all('/\{(\w+)\}/', $clave, $enLaRuta);
        $declarados = collect([...$comunes, ...($op['parameters'] ?? [])])
            ->map($resolver)
            ->filter(fn ($p) => $p['in'] === 'path' && ($p['required'] ?? false) === true)
            ->pluck('name')
            ->sort()->values()->all();

        return $declarados !== collect($enLaRuta[1])->sort()->values()->all();
    })->keys();

    expect($mal->all())->toBe([]);
});

test('cada parámetro trae exactamente las claves de un parámetro', function () {
    // Una coma sin comillas dentro de { … } no falla al leer: corta el valor y deja una clave suelta.
    $validas = ['name', 'in', 'required', 'description', 'schema'];
    $todos   = collect($this->spec['components']['parameters'])
        ->merge($this->operaciones->flatMap(fn ($par) => [...$par[1], ...($par[0]['parameters'] ?? [])])
            ->reject(fn ($p) => isset($p['$ref'])));

    $mal = $todos->filter(fn ($p) => array_diff(array_keys($p), $validas) !== []
        || ! isset($p['name'], $p['in'], $p['schema']));

    expect($mal->values()->all())->toBe([]);
});

test('cada operación tiene un operationId único y una respuesta correcta', function () {
    $ids = $this->operaciones->map(fn ($par) => $par[0]['operationId'] ?? null);

    $sinCorrecta = $this->operaciones->reject(fn ($par) => collect(array_keys($par[0]['responses'] ?? []))
        ->contains(fn ($codigo) => str_starts_with((string) $codigo, '2')))->keys();

    expect($ids->filter()->count())->toBe($ids->count())
        ->and($ids->duplicates()->all())->toBe([])
        ->and($sinCorrecta->all())->toBe([]);
});
