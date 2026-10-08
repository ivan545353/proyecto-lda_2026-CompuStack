<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductoProveedorRequest;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\ProductoProveedorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Proveedores de un producto: el comparador de precios.
 *
 * Recurso anidado, como las direcciones de un cliente: siempre se accede desde el
 * producto, y al comparador se vuelve después de cada acción.
 *
 *
 * Las seis rutas exigen `producto.editar`.
 *
 * Métodos:
 *   index()      el comparador, ordenado por costo, con el más barato y el elegido
 *   store()      agrega un proveedor; el formulario vive en el comparador
 *   edit() / update()  cambia costo, código y preferencia
 *   preferido()  elige a quién pedirle, sin tocar el resto del vínculo
 *   destroy()    quita el proveedor
 */
class ProductoProveedorController extends Controller
{
    public function __construct(private ProductoProveedorService $service)
    {
    }

    public function index(Producto $producto): View
    {
        $producto->load(['categoria.padre.padre', 'marca', 'proveedores']);

        return view('productos.proveedores', [
            'producto' => $producto,

            // Ordenados por costo: un comparador se lee de arriba hacia abajo. Los
            // que no tienen costo cargado van al FINAL, no al principio como haría
            // un null tratado como cero. Es el mismo criterio que
            // Producto::proveedorParaReponer(), y tenerlo igual en los dos lados es
            // lo que hace que la pantalla y la reposición no se contradigan.
            'vinculos' => $producto->proveedores
                ->sortBy(fn (Proveedor $proveedor) => [
                    $proveedor->pivot->costo_ultimo === null ? 1 : 0,
                    (float) ($proveedor->pivot->costo_ultimo ?? 0),
                ])
                ->values(),

            'masBarato' => $producto->proveedorMasBarato(),

            // Sólo los activos que todavía no están: vincular es preparar una
            // compra. El Form Request exige lo mismo, así que la pantalla no
            // ofrece lo que después va a rechazar.
            'disponibles' => Proveedor::query()
                ->where('activo', true)
                ->whereNotIn('id', $producto->proveedores->pluck('id'))
                ->orderBy('razon_social')
                ->get(['id', 'razon_social']),

            // Una consulta para toda la tabla, no una por fila. Decide si se ofrece
            // el botón de quitar y con qué motivo se reemplaza cuando no.
            'pedidos' => $this->service->pedidosEnCurso($producto),
        ]);
    }

    public function store(ProductoProveedorRequest $request, Producto $producto): RedirectResponse
    {
        $datos       = $request->validated();
        $proveedor   = Proveedor::findOrFail($datos['proveedor_id']);
        $esElPrimero = $producto->proveedores()->doesntExist();

        $this->service->vincular($producto, $datos);

        // El mensaje dice lo que pasó de verdad: el primero queda elegido aunque
        // nadie marque la casilla, y callarlo dejaría al usuario creyendo que no
        // eligió a nadie.
        return redirect()->route('producto-proveedores.index', $producto)->with('exito', $esElPrimero
            ? "Se agregó {$proveedor->razon_social}, y es a quien se le va a pedir la reposición."
            : "Se agregó {$proveedor->razon_social}.");
    }

    public function edit(Producto $producto, int $proveedor): View
    {
        return view('productos.proveedor', [
            'producto' => $producto,
            'vinculo'  => $this->vinculoDe($producto, $proveedor),
        ]);
    }

    public function update(ProductoProveedorRequest $request, Producto $producto, int $proveedor): RedirectResponse
    {
        $vinculo = $this->vinculoDe($producto, $proveedor);

        // Se calcula ANTES de guardar, igual que DireccionController con la
        // dirección predeterminada: después el dato ya cambió.
        $seQuisoDesmarcar = $vinculo->pivot->es_preferido && ! $request->boolean('es_preferido');

        $this->service->actualizar($producto, $proveedor, $request->validated());

        return redirect()->route('producto-proveedores.index', $producto)->with('exito', $seQuisoDesmarcar
            ? "Se guardó el vínculo con {$vinculo->razon_social}. Sigue siendo el elegido: para cambiarlo, marcá otro."
            : "Se guardó el vínculo con {$vinculo->razon_social}.");
    }

    public function preferido(Producto $producto, int $proveedor): RedirectResponse
    {
        $vinculo = $this->vinculoDe($producto, $proveedor);

        $this->service->marcarPreferido($producto, $proveedor);

        return redirect()->route('producto-proveedores.index', $producto)
            ->with('exito', "La reposición de «{$producto->nombre}» se le va a pedir a {$vinculo->razon_social}.");
    }

    public function destroy(Producto $producto, int $proveedor): RedirectResponse
    {
        $vinculo = $this->vinculoDe($producto, $proveedor);
        $era     = (bool) $vinculo->pivot->es_preferido;

        // Si hay un pedido en curso, el servicio lanza ReglaDeNegocioException y
        // bootstrap/app.php la devuelve como aviso a la pantalla anterior. Por eso
        // acá no hay try/catch (M-30).
        $this->service->desvincular($producto, $proveedor);

        $quedan = $producto->proveedores()->exists();

        return redirect()->route('producto-proveedores.index', $producto)->with('exito', $era && $quedan
            ? "Se quitó a {$vinculo->razon_social}. El más antiguo de los que quedaban pasó a ser el elegido."
            : "Se quitó a {$vinculo->razon_social}.");
    }

    /**
     * El vínculo, buscado DENTRO de los proveedores del producto.
     *
     * Un proveedor que no provee este producto da 404 en lugar de abrirse desde la
     * pantalla equivocada.
     */
    private function vinculoDe(Producto $producto, int $proveedorId): Proveedor
    {
        return $producto->proveedores()->findOrFail($proveedorId);
    }
}