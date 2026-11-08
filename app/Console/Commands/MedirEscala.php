<?php

namespace App\Console\Commands;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Mide las pantallas que crecen con los datos.
 *
 * Es el instrumento del tramo A: convierte las opiniones sobre escala en números
 * anotables. Sin él, un listado «anda bien» o «anda lento» según quién lo mire, y
 * los índices se agregan por intuición.
 *
 * Mide pegándole a la aplicación de verdad, por el kernel HTTP, con un usuario
 * autenticado: lo que informa incluye el middleware, los Form Request de filtro, el
 * controlador, los scopes y el render de Blade. Medir sólo la consulta diría que
 * todo está bien incluso cuando el problema es que el catálogo entero viaja en el
 * HTML, que es justamente lo que los pendientes #32 y #39 describen.
 *
 * De cada pantalla informa seis cosas, y las seis hacen falta:
 *   HTTP        el código de respuesta. Una redirección a login mediría la pantalla
 *               equivocada, así que se informa en lugar de suponerse
 *   Consultas   cuántas van a la base. Un N+1 se ve acá y no en el reloj
 *   SQL         cuánto tardó la base, separado del total
 *   Total       el tiempo de la petición completa
 *   HTML        el peso de la respuesta. Es la medida de #32 y #39, y el criterio
 *               de terminado del tramo E
 *   Memoria     el pico de la petición. Es lo que decide si el segundo motor de
 *               impresión del tramo K hace falta
 *
 * No agrega ninguna librería: no hay debugbar ni Telescope en el proyecto, y una
 * dependencia nueva para contar consultas sería una decisión más grande que el
 * problema. `DB::listen()` ya lo hace, y es el mismo mecanismo que usan los tests
 * de escala.
 */
class MedirEscala extends Command
{
    protected $signature = 'escala:medir
        {--repeticiones=3 : Cuántas veces se mide cada pantalla; se informa la mediana}
        {--usuario=       : Correo del usuario con el que se mide; por omisión, el administrador}
        {--markdown       : Imprime la tabla en Markdown, lista para pegar en docs/escala.md}';

    protected $description = 'Mide tiempo, consultas y peso del HTML de los listados y el panel';

    /**
     * Las pantallas que crecen con los datos, con los filtros que importan.
     *
     * Están los ocho listados del sistema y el panel, y además dos pantallas que no
     * son listados pero son las que los pendientes #32 y #39 nombran: el formulario
     * de venta y el armador de pedido mandan el catálogo entero en el HTML.
     *
     * Las que no crecen con los datos —categorías y marcas, que son 69 y 40 filas
     * administradas a mano— se miden igual, para poder afirmar con un número que no
     * tienen problema de escala en lugar de suponerlo.
     *
     * Las cuatro últimas son las del módulo Precios, y entran acá por el pendiente
     * #54: el tramo D las entregó sin medir porque el instrumento es de otro tramo,
     * y medirlas sale gratis cada vez que se corre el comando. `/precios/ajuste` se
     * mide **tal como abre**, sin previsualizar: sin el campo oculto el controlador
     * dibuja el formulario vacío, que es la pantalla que el usuario ve primero.
     *
     * Los marcadores entre llaves los resuelve pantallas() contra la base.
     */
    private const PANTALLAS = [
        'Catálogo'                     => '/productos',
        'Catálogo, búsqueda por texto' => '/productos?q=cartuchos',
        'Catálogo, por categoría'      => '/productos?categoria_id={categoria}',
        'Catálogo, stock crítico'      => '/productos?stock=critico',
        'Catálogo, sin proveedor'      => '/productos?stock=sin_proveedor',
        'Clientes'                     => '/clientes',
        'Clientes, búsqueda por texto' => '/clientes?q=gutierrez',
        'Proveedores'                  => '/proveedores',
        'Categorías'                   => '/categorias',
        'Marcas'                       => '/marcas',
        'Kardex'                       => '/stock',
        'Kardex de un producto'        => '/stock?producto_id={producto}',
        'Compras'                      => '/compras',
        'Ventas'                       => '/ventas',
        'Ventas entregadas'            => '/ventas?estado=entregada',
        'Panel general'                => '/panel/general',
        'Formulario de venta'          => '/ventas/crear',
        'Armador de pedido'            => '/compras/pedido',
        'Sugeridos de reposición'      => '/compras/sugeridos',
        'Precios'                      => '/precios',
        'Formulario de producto'       => '/productos/{producto}/editar',
        'Ajuste masivo de precios'     => '/precios/ajuste',
        'Lotes de ajuste'              => '/precios/lotes',
    ];

    private int $consultas = 0;

    private float $milisegundosSql = 0.0;

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('La medición no corre en producción: autentica un administrador y le pega a las pantallas.');

            return self::FAILURE;
        }

        $usuario = $this->usuario();

        if ($usuario === null) {
            $this->error('No encontré con qué usuario medir. Pasá --usuario=correo o cargá el administrador.');

            return self::FAILURE;
        }

        $repeticiones = max((int) $this->option('repeticiones'), 1);

        // El guard conserva el usuario en memoria, así que las peticiones que el
        // kernel procesa a continuación pasan el middleware de autenticación aunque
        // su sesión sea nueva.
        Auth::login($usuario);

        DB::listen(function (QueryExecuted $consulta) {
            $this->consultas++;
            $this->milisegundosSql += $consulta->time;
        });

        $this->contexto($usuario);

        $filas = [];

        foreach ($this->pantallas() as $etiqueta => $url) {
            // Calentamiento sin medir: la primera visita a cada pantalla paga la
            // compilación de la vista Blade y el primer plan de consulta. Contarla
            // haría que la pantalla parezca más lenta de lo que es.
            $this->visitar($url);

            $mediciones = [];

            for ($r = 0; $r < $repeticiones; $r++) {
                $mediciones[] = $this->visitar($url);
            }

            $filas[] = $this->resultado($etiqueta, $mediciones);
        }

        $this->informar($filas);

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // La medición
    // ------------------------------------------------------------------

    /**
     * Una visita a la pantalla, con el reloj y el contador de consultas alrededor.
     *
     * @return array{estado: int, consultas: int, sql: float, total: float, bytes: int, memoria: int}
     */
    private function visitar(string $url): array
    {
        $this->consultas       = 0;
        $this->milisegundosSql = 0.0;

        // Reinicia el pico para que la memoria informada sea de esta petición y no la
        // más alta de toda la corrida. Existe desde PHP 8.2, que es el mínimo del
        // proyecto; la comprobación es por si alguien lo corre en uno anterior.
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $arranque  = microtime(true);
        $respuesta = app(HttpKernel::class)->handle(Request::create($url, 'GET'));
        $total     = (microtime(true) - $arranque) * 1000;

        return [
            'estado'    => $respuesta->getStatusCode(),
            'consultas' => $this->consultas,
            'sql'       => $this->milisegundosSql,
            'total'     => $total,
            'bytes'     => strlen((string) $respuesta->getContent()),
            'memoria'   => memory_get_peak_usage(true),
        ];
    }

    /**
     * Resume las repeticiones de una pantalla.
     *
     * Informa la mediana y no el promedio: una sola repetición lenta —el sistema
     * operativo haciendo otra cosa— corre el promedio y no dice nada sobre la
     * pantalla. Y si el conteo de consultas cambió entre repeticiones, lo informa
     * como rango en lugar de elegir uno: una consulta que aparece a veces es una
     * consulta cacheada, y conviene que se vea.
     *
     * @param  list<array{estado: int, consultas: int, sql: float, total: float, bytes: int, memoria: int}>  $mediciones
     * @return array<string, string|int>
     */
    private function resultado(string $etiqueta, array $mediciones): array
    {
        $totales = array_column($mediciones, 'total');
        sort($totales);

        $sql = array_column($mediciones, 'sql');
        sort($sql);

        $consultas = array_column($mediciones, 'consultas');
        $minimo    = min($consultas);
        $maximo    = max($consultas);

        return [
            'pantalla'  => $etiqueta,
            'estado'    => $mediciones[0]['estado'],
            'consultas' => $minimo === $maximo ? (string) $minimo : "{$minimo}–{$maximo}",
            'sql'       => $this->milisegundos($sql[intdiv(count($sql), 2)]),
            'total'     => $this->milisegundos($totales[intdiv(count($totales), 2)]),
            'html'      => $this->kilobytes($mediciones[0]['bytes']),
            'memoria'   => $this->megabytes(max(array_column($mediciones, 'memoria'))),
        ];
    }

    // ------------------------------------------------------------------
    // Contexto y salida
    // ------------------------------------------------------------------

    /**
     * Con qué se midió.
     *
     * Una medición sin su contexto no se puede comparar con otra: el documento
     * necesita saber cuántas filas había, con qué versión de PHP y de la base, y con
     * qué usuario, porque los permisos cambian lo que cada pantalla renderiza.
     */
    private function contexto(User $usuario): void
    {
        $filas = collect(['productos', 'clientes', 'proveedores', 'ventas', 'venta_lineas', 'pagos', 'movimientos_stock', 'ordenes_compra'])
            ->map(fn (string $tabla) => $tabla.': '.number_format(DB::table($tabla)->count(), 0, ',', '.'))
            ->implode(' · ');

        $this->components->twoColumnDetail('PHP', PHP_VERSION);
        $this->components->twoColumnDetail(
            'Base',
            DB::connection()->getDriverName().' '.DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
        );
        $this->components->twoColumnDetail('Usuario', $usuario->email.' ('.$usuario->rol?->nombre.')');
        $this->components->twoColumnDetail('Repeticiones', (string) max((int) $this->option('repeticiones'), 1));
        $this->newLine();
        $this->line($filas);
        $this->newLine();
    }

    /** @param  list<array<string, string|int>>  $filas */
    private function informar(array $filas): void
    {
        $encabezados = ['Pantalla', 'HTTP', 'Consultas', 'SQL (ms)', 'Total (ms)', 'HTML (KB)', 'Memoria (MB)'];
        $cuerpo      = array_map('array_values', $filas);

        if ($this->option('markdown')) {
            $this->newLine();
            $this->line('| '.implode(' | ', $encabezados).' |');
            $this->line('| '.implode(' | ', array_fill(0, count($encabezados), '---')).' |');

            foreach ($cuerpo as $fila) {
                $this->line('| '.implode(' | ', $fila).' |');
            }

            $this->newLine();
        } else {
            $this->table($encabezados, $cuerpo);
        }

        $fallidas = array_filter($filas, fn (array $fila) => $fila['estado'] !== 200);

        if ($fallidas !== []) {
            // Una pantalla que no devolvió 200 no se midió: se midió la redirección
            // al login o la página de error. El número que informa no significa nada
            // y no puede ir al documento.
            $this->warn(
                'Estas pantallas no devolvieron 200, así que su medición no vale: '
                .implode(', ', array_column($fallidas, 'pantalla')).'.'
            );
        }
    }

    // ------------------------------------------------------------------
    // Ayudas
    // ------------------------------------------------------------------

    /**
     * Las pantallas con sus marcadores resueltos.
     *
     * La categoría es la última rama creada —con los datos de volumen, una de las de
     * volumen— y el producto es el que más movimientos tiene, que es el peor caso
     * del kardex filtrado.
     *
     * @return array<string, string>
     */
    private function pantallas(): array
    {
        $categoria = Categoria::whereNull('parent_id')->orderByDesc('id')->value('id');

        $producto = Producto::query()
            ->withCount('movimientos')
            ->orderByDesc('movimientos_count')
            ->first()
            ?->id;

        $pantallas = [];

        foreach (self::PANTALLAS as $etiqueta => $url) {
            $pantallas[$etiqueta] = str_replace(
                ['{categoria}', '{producto}'],
                [(string) $categoria, (string) $producto],
                $url,
            );
        }

        return $pantallas;
    }

    /** El usuario con el que se mide: el que se pasó, o el administrador. */
    private function usuario(): ?User
    {
        $correo = $this->option('usuario');

        if ($correo !== null) {
            return User::where('email', $correo)->first();
        }

        return User::query()
            ->where('rol_id', Rol::where('nombre', 'Administrador')->value('id'))
            ->orderBy('id')
            ->first();
    }

    private function milisegundos(float $valor): string
    {
        return number_format($valor, 1, ',', '.');
    }

    private function kilobytes(int $bytes): string
    {
        return number_format($bytes / 1024, 1, ',', '.');
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 0, ',', '.');
    }
}