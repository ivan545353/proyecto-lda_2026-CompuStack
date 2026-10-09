<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaLinea;
use App\Support\Importe;
use App\Support\MaquinaEstadosVenta;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio de la venta.
 *
 * No conoce la petición HTTP ni la sesión: recibe datos ya validados y modelos, y
 * devuelve modelos. En la Etapa 3 la API llama a estos mismos métodos, y por eso
 * cada regla que el usuario puede violar está acá además de en el Form Request.
 *
 * Recibe el `User` como argumento y no lo saca de la sesión. Preguntarle a ese
 * objeto si tiene un permiso no es conocer la sesión: es leer un dato del usuario
 * que le pasaron, igual que leer su id para guardarlo como vendedor.
 *
 * Invariantes propias:
 *
 *   1. **El precio lo lee el servidor, siempre, y queda congelado en la línea.**
 *      Releer el precio de la base y nunca confiar en el que manda el cliente es lo
 *      único que el original hacía bien en este módulo —es la nota positiva de M-14—
 *      y se conserva igual. Lo que el original **no** hacía es distinguir *cotizar*
 *      de *recotizar*, y ahí está el hallazgo: `resolverPreciosYTotales()` releía el
 *      precio en cada `save` y en cada `update`, y `SaleDao::update()` borraba las
 *      líneas y las reinsertaba con el precio nuevo, así que un presupuesto emitido
 *      cambiaba de total sin que nadie lo hubiera pedido.
 *
 *      Acá son dos operaciones distintas. **Cotizan** `crear()` y las líneas nuevas
 *      de `actualizar()`: nacen con el precio, la alícuota y el costo del momento.
 *      Las líneas que ya existían **no se vuelven a tocar nunca** —si cambia la
 *      cantidad, se recalculan los importes con el precio que ya tenían—. Y
 *      `recotizar()` es una acción explícita, con su botón, que relee todo y
 *      **devuelve qué cambió** para que la pantalla lo informe. Recotizar no está
 *      mal; recotizar sin que nadie se entere, sí.
 *
 *   2. **La venta de mostrador cotiza con `precio_contado`, y es el único precio
 *      que interviene.** Un producto tiene dos —`precio_contado` y
 *      `precio_lista`— pero no son dos listas entre las que el vendedor elija: en
 *      el mostrador el precio es uno, y el recargo por tarjeta lo aplica el posnet
 *      al cobrar o se arregla informalmente. `precio_lista` es el precio del canal
 *      online, con el recargo ya adentro, porque Checkout Pro recibe el monto antes
 *      de saber con qué se va a pagar; lo va a usar la tienda de la Etapa 2. Que el
 *      sistema no calcule el recargo es una decisión: no afirma lo que no calcula.
 *
 *   3. **Los totales son derivados.** `subtotal`, `descuento` y `total` se calculan
 *      desde las líneas y nunca llegan del formulario; están fuera de `$fillable`,
 *      así que tampoco podrían. El subtotal es la suma de los totales de las líneas,
 *      el descuento es el porcentaje aplicado sobre ese subtotal, y el total es la
 *      resta. Con un solo precio, el descuento es lo único que el mostrador tiene
 *      para mover el total, y por eso su tope es lo que cierra A-12.
 *
 *   4. **El descuento se valida contra el tope de quien lo carga** (A-12), acá y en
 *      el Form Request. El Request da el mensaje en el campo y conserva lo que el
 *      usuario escribió; el servicio es la barrera que hereda la API. Los dos leen
 *      el mismo `config/venta.php` y el mismo permiso.
 *
 *   5. **No se valida stock al cotizar, a propósito.** Un presupuesto no compromete
 *      nada: no mueve stock, no genera comprobante y puede no convertirse nunca en
 *      venta. El stock se valida al cobrar, en `marcarPagada()`, cuando
 *      `StockService::descontar()` compara contra el **disponible**
 *      —`stock - stock_reservado`— y tira `StockInsuficienteException` si no alcanza.
 *      La pantalla avisa que un producto no tiene stock suficiente mientras se
 *      cotiza, porque avisar no es impedir: se puede cotizar lo que todavía no llegó,
 *      y lo que no se va a poder es cobrarlo.
 *
 *   6. **Un producto dado de baja no se puede vender, y se rechaza en dos momentos
 *      distintos.** `StockService` dejó esta validación explícitamente afuera del
 *      depósito, con el argumento de que un producto discontinuado sigue estando
 *      físicamente en el estante y el inventario tiene que poder corregirlo: «que un
 *      producto inactivo no se pueda vender es una validación de la línea de venta,
 *      no del depósito». Este es ese lugar, y son dos:
 *
 *      - **al cotizar** —`escribirLineas()` y `recotizar()`—, porque cotizar es
 *        ofrecer, y no se ofrece lo que no se puede vender;
 *      - **al cobrar** —`marcarPagada()`—, que es el momento en que la venta se
 *        vuelve real y sale mercadería del depósito.
 *
 *      Y hay un tercer momento donde **no** se valida, también a propósito: la
 *      edición de un presupuesto tolera un producto que se desactivó después de
 *      emitirlo. Una referencia que cambió más tarde no puede volver inválido un
 *      registro que ya existe, y negar la edición entera por un renglón que el
 *      usuario no tocó lo dejaría sin forma de quitarlo. La salida es quitar ese
 *      renglón, y mientras no lo haga, no va a poder cobrar.
 *
 *   7. **Todo cambio de estado pasa por `MaquinaEstadosVenta`**, y el estado destino
 *      **no es un parámetro público**. Cada transición es un método con su nombre y
 *      sus efectos, y `cambiarEstado()` es privado. El original tenía
 *      `SaleService::updateEstado($id, $nuevoEstado)` con el estado viniendo del
 *      body, y ahí está la mitad de C-9 que no se arregla con una tabla: poner una
 *      tabla de transiciones delante de la misma puerta cambia cuáles se rechazan,
 *      no quién decide a dónde va la venta. Además `estado` está fuera de
 *      `$fillable`, así que un `update()` con ese campo lo descarta en silencio.
 * 
 *   8. **Una venta cobrada no se cancela: se devuelve, y la devolución es la que
 *      mueve la plata de vuelta** (A-11). El original permitía
 *      `confirmada/cobrada → anulada`: reponía el stock y dejaba las filas de `pagos`
 *      intactas, así que quedaba plata cobrada sobre una venta inexistente. Acá esa
 *      transición no está declarada, y por lo tanto **no existe ningún camino que
 *      deshaga una venta cobrada sin pasar por donde se devuelve el dinero**. Dos
 *      estados que significaran «deshecha» con mecánicas distintas serían peor que
 *      uno.
 *
 *      La reversión del dinero es un **contra-asiento**: una fila de pago con monto
 *      negativo, no una edición ni un borrado de la fila original. Es el mismo
 *      criterio que `movimientos_stock.cantidad` con signo —un asiento por hecho— y
 *      es lo que permite que una devolución parcial produzca una reversión parcial
 *      sin inventar ninguna entidad. El medio por el que vuelve la plata **lo elige
 *      quien devuelve**: una venta cobrada por transferencia se puede devolver en
 *      efectivo de la caja, y escribir un negativo en transferencia afirmaría un
 *      hecho que no ocurrió.
 *
 *      **La devolución no toca los importes de la venta.** El documento sigue
 *      diciendo lo que se vendió; lo devuelto se lee en `cantidad_devuelta` y en los
 *      contra-asientos. Por eso el margen del panel se calcula con
 *      `cantidad - cantidad_devuelta` y no necesita consultar nada más.
 *
 * 
 *      Las transiciones que existen son un método público cada una, con su nombre y
 *      sus efectos: `marcarPagada()` pasa `'pagada'`, `entregar()` pasa
 *      `'entregada'`, `cancelar()` pasa `'cancelada'`. Ninguna recibe el destino, y
 *      por eso agregar transiciones no agranda la superficie de C-9: agregar un
 *      método no es lo mismo que agregar un valor posible a un parámetro.
 *
 *      `devolver()` es el caso que conviene mirar de cerca, porque **no tiene un
 *      destino literal**: pasa a `'devuelta'` o a `'devuelta_parcial'` según cuánto
 *      quedó sin devolver. Eso no reabre el hallazgo: los dos literales están
 *      escritos en este archivo y la elección sale de los datos de las líneas, no de
 *      la petición. Lo que C-9 señalaba es que el operador decidiera a dónde va la
 *      venta; acá ni siquiera el programador lo decide caso por caso — lo decide la
 *      aritmética. Es la misma forma que `CompraService::recibir()` con
 *      `recibida_parcial`.
 *
 * Métodos:
 *   crear()            alta de un presupuesto, con sus líneas cotizadas
 *   actualizar()       edición del presupuesto, conservando los precios congelados
 *   recotizar()        relee los precios de todas las líneas e informa qué cambió
 *   marcarPagada()     el pase a `pagada`: descuenta el stock, exactamente una vez
 *   entregar()         la mercadería salió; no mueve stock ni plata
 *   devolver()         repone stock, revierte los pagos y deriva el estado (A-11)
 *   cancelar()         da de baja un presupuesto, antes de que exista cualquier efecto
 *   topeDeDescuento()  el tope que rige para un usuario; lo usan el Request y la vista
 */
class VentaService
{
    /**
     * `StockService` por el constructor, resuelto por el contenedor.
     *
     * Ningún método hace `new StockService` (M-28): el original hacía
     * `new SaleDao(Connection::get())` adentro de cada método del servicio, y por eso
     * la lógica de negocio era imposible de probar sin base real. Es la misma
     * inyección que `CompraService`, que recibe `StockService` y
     * `ProductoProveedorService`.
     */
    public function __construct(private StockService $stock)
    {
    }

    /**
     * Alta de un presupuesto.
     *
     * La venta nace en `presupuesto`, de canal `mostrador` y con entrega por
     * `retiro`: los tres son el `DEFAULT` de su columna y ninguno llega del
     * formulario. En la Etapa 1 no hay tienda ni tabla de envíos, así que una venta
     * con envío no tendría dónde guardar la dirección ni con qué cotizar el costo;
     * las columnas quedan declaradas para la Etapa 2, igual que `peso_gramos` en
     * productos.
     *
     * @param  array{cliente_id?: int|null, descuento_porcentaje?: float|string|null, observaciones?: string|null, lineas: array<int, array{producto_id: int, cantidad: int}>}  $datos
     *
     * @throws ReglaDeNegocioException
     */
    public function crear(array $datos, User $usuario): Venta
    {
        $lineas = $datos['lineas'] ?? [];

        if ($lineas === []) {
            throw new ReglaDeNegocioException('Una venta necesita al menos un producto.');
        }

        $this->exigirProductosDistintos($lineas);

        $porcentaje = (float) ($datos['descuento_porcentaje'] ?? 0);

        // Se valida ANTES de abrir la transacción, y acá eso está bien: es una
        // comprobación sobre lo que llegó y sobre el rol de quien lo manda, y
        // ninguna de las dos cosas la puede cambiar otra petición mientras tanto.
        // Es exactamente lo contrario del saldo de un pago, que depende de los pagos
        // ya registrados y por eso tiene que leerse DESPUÉS de bloquear la venta:
        // validarlo afuera es C-10, y es el error del original.
        $this->exigirDescuentoAutorizado($porcentaje, $usuario);

        return DB::transaction(function () use ($datos, $lineas, $porcentaje, $usuario) {
            $venta = Venta::create([
                // Null significa consumidor final. La venta rápida de mostrador no
                // identifica a nadie, y AFIP lo admite por debajo de cierto monto;
                // el umbral y la obligación de identificar son de la facturación,
                // en la Etapa 2.
                'cliente_id'    => $datos['cliente_id'] ?? null,
                'usuario_id'    => $usuario->id,
                'observaciones' => $datos['observaciones'] ?? null,
            ]);

            $this->escribirLineas($venta, $lineas);
            $this->recalcularTotales($venta, $porcentaje);

            return $venta->fresh('lineas');
        });
    }

        /**
     * Edición de un presupuesto.
     *
     * **Lo que distingue esto de `CompraService::actualizarBorrador()`**: ese método
     * borra las líneas y las reinserta, y está bien, porque el costo de una línea de
     * compra lo carga el formulario y no hay nada guardado en la fila que el
     * formulario no traiga. La línea de venta sí tiene estado propio —su precio
     * congelado, que no está en ningún otro lado— así que borrarla y reinsertarla
     * obliga a releer el precio, y releer el precio **es** M-14. El docblock de
     * `CompraService` ya lo tenía anotado: la misma técnica está bien o mal según si
     * la fila tiene estado propio.
     *
     * Entonces las líneas se ajustan en lugar de reemplazarse:
     *   - la que ya estaba, se queda con su precio y sólo recalcula si cambió la
     *     cantidad;
     *   - la que entra, se cotiza al precio de hoy;
     *   - la que salió del pedido, se borra.
     *
     * Sólo se valida que estén activos los productos que se **cotizan**, que son los
     * nuevos. Un producto que se dio de baja después de emitir el presupuesto no
     * impide cambiarle la cantidad a otro renglón, y la salida es quitarlo. Que no
     * se pueda cobrar una venta con un producto dado de baja es una validación del
     * pase a `pagada`, en la segunda mitad de la fase.
     *
     * @param  array{cliente_id?: int|null, descuento_porcentaje?: float|string|null, observaciones?: string|null, lineas: array<int, array{producto_id: int, cantidad: int}>}  $datos
     *
     * @throws ReglaDeNegocioException
     */
    public function actualizar(Venta $venta, array $datos, User $usuario): Venta
    {
        $lineas = $datos['lineas'] ?? [];

        if ($lineas === []) {
            throw new ReglaDeNegocioException('Una venta necesita al menos un producto.');
        }

        $this->exigirProductosDistintos($lineas);

        $porcentaje = (float) ($datos['descuento_porcentaje'] ?? 0);

        $this->exigirDescuentoAutorizado($porcentaje, $usuario);

        return DB::transaction(function () use ($venta, $datos, $lineas, $porcentaje) {
            // Se bloquea la fila antes de leer su estado: sin el lock, dos pestañas
            // sobre el mismo presupuesto pueden pasar las dos la comprobación de
            // «es un presupuesto» y escribir las dos.
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            $this->exigirPresupuesto($v, 'modificar');

            $v->update([
                'cliente_id'    => $datos['cliente_id'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
            ]);

            $this->ajustarLineas($v, $lineas);
            $this->recalcularTotales($v, $porcentaje);

            return $v->fresh('lineas');
        });
    }

    /**
     * Relee los precios de todas las líneas y devuelve qué cambió.
     *
     * Es la acción que el original no tenía, y la que convierte la recotización en
     * una decisión del vendedor en lugar de un efecto colateral de guardar. Cotiza
     * de nuevo cada línea completa —precio, alícuota, costo y descripción—, porque
     * recotizar es cotizar otra vez: tomar media foto nueva dejaría una línea con el
     * precio de hoy y el costo del mes pasado, y el margen saldría mal.
     *
     * **Conserva el porcentaje de descuento, no el monto.** Un 10 % cargado sobre
     * tres mil pesos sigue siendo un 10 % cuando el subtotal pasa a cinco mil. Por
     * eso el porcentaje se lee *antes* de tocar las líneas. No se vuelve a validar
     * contra el tope: el porcentaje no cambió, así que nadie está cargando un
     * descuento nuevo.
     *
     * **No recibe el usuario.** No habría qué hacer con él: el permiso lo exige la
     * ruta y el descuento no se revalida. Un parámetro que no se usa sería
     * decoración, que es el mismo motivo por el que `CompraService::marcarEnviada()`
     * tampoco lo recibe.
     *
     * Devuelve un arreglo y no un modelo, que es la excepción a la regla de la capa,
     * y tiene un motivo: el controlador necesita la venta **y** qué cambió para
     * escribir el mensaje, y el mensaje es la mitad de la corrección del hallazgo.
     * Una clase propia para transportar tres datos a un mensaje sería más maquinaria
     * que problema.
     *
     * @return array{venta: Venta, cambios: array<int, array{descripcion: string, precio_anterior: string, precio_nuevo: string}>, total_anterior: string}
     *
     * @throws ReglaDeNegocioException
     */
    public function recotizar(Venta $venta): array
    {
        return DB::transaction(function () use ($venta) {
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            $this->exigirPresupuesto($v, 'recotizar');

            // Los dos se leen antes de tocar nada: después de recotizar, el subtotal
            // es otro y el porcentaje original ya no se podría deducir.
            $porcentaje    = $v->descuentoPorcentaje();
            $totalAnterior = $v->total;

            $lineas = $v->lineas()->get();

            $productos = Producto::query()
                ->whereIn('id', $lineas->pluck('producto_id'))
                ->get()
                ->keyBy('id');

            $cambios = [];

            foreach ($lineas as $linea) {
                $producto = $productos->get($linea->producto_id);

                if ($producto === null) {
                    throw new ReglaDeNegocioException(
                        "«{$linea->descripcion}» ya no existe en el catálogo, así que no se puede recotizar. "
                        .'Quitalo del presupuesto.'
                    );
                }

                // Acá sí se exige que esté activo, al contrario que en la edición:
                // recotizar es cotizar, y no se cotiza lo que no se puede vender.
                if (! $producto->activo) {
                    throw new ReglaDeNegocioException(
                        "«{$producto->nombre}» está dado de baja y no se puede cotizar. Quitalo del "
                        .'presupuesto, o volvé a activarlo en el catálogo.'
                    );
                }

                $precioAnterior = $linea->precio_unitario;
                $precioNuevo    = $producto->precio_contado;

                $importes = $this->importesDe($precioNuevo, $producto->alicuota_iva, $linea->cantidad);

                $linea->descripcion     = $producto->nombre;
                $linea->precio_unitario = $precioNuevo;
                $linea->alicuota_iva    = $producto->alicuota_iva;
                $linea->costo_unitario  = $producto->costo_promedio;
                $linea->neto            = $importes['neto'];
                $linea->iva             = $importes['iva'];
                $linea->total           = $importes['total'];

                $linea->save();

                // Se comparan como números y no como texto: '1000.00' y '1000.0'
                // son el mismo precio y no un cambio que informar.
                if ((float) $precioAnterior !== (float) $precioNuevo) {
                    $cambios[] = [
                        'descripcion'     => $producto->nombre,
                        'precio_anterior' => $precioAnterior,
                        'precio_nuevo'    => $precioNuevo,
                    ];
                }
            }

            $this->recalcularTotales($v, $porcentaje);

            return [
                'venta'          => $v->fresh('lineas'),
                'cambios'        => $cambios,
                'total_anterior' => $totalAnterior,
            ];
        });
    }

        /**
     * El pase a `pagada`: descuenta el stock, exactamente una vez.
     *
     * Es el llamador que el cierre de C-9 dejó anunciado —«la segunda mitad no agrega
     * reglas acá: agrega el llamador de dos transiciones que ya están declaradas»— y
     * es el primer y único código del sistema que llama a `StockService::descontar()`,
     * que existe sin usar desde la Fase 5.
     *
     * **Por qué es público y por qué eso no afloja C-9.** La mitad del hallazgo que
     * una tabla de transiciones no cubre es que el estado destino **viajaba en la
     * petición**: `SaleService::updateEstado($id, $nuevoEstado)` con el valor saliendo
     * del body. Este método no recibe ningún estado: `'pagada'` está escrito acá
     * abajo, la ruta que lo alcanza es una sola y tiene su permiso. Agregar un método
     * público no agranda nada; lo que lo agrandaría es agregar un valor posible a un
     * parámetro, y por eso `cambiarEstado()` sigue privado y hay un test que lo
     * afirma por reflexión.
     *
     * **Por qué el descuento vive en `VentaService` y no en `PagoService`.** El
     * descuento necesita las líneas de la venta y el `origen` del movimiento de
     * kardex **es** la venta. Dejarlo en el servicio del cobro obligaría a ese
     * servicio a conocer las líneas, los productos y el estado de la venta, que es
     * todo lo que este servicio ya sabe. `PagoService::cobrar()` orquesta —bloquea,
     * valida el saldo, escribe los pagos— y cuando la venta queda saldada llama acá.
     *
     * **La idempotencia no es una bandera: es que `pagada → pagada` no esté
     * declarado.** Esto es literalmente el cuarto agujero de C-9: en el original
     * `confirmada → confirmada` volvía a descontar stock porque la cadena de `if` no
     * comparaba el origen. Acá un segundo pase sobre una venta ya pagada no llega a
     * `descontar()`: lo rechaza la tabla, en la primera línea útil del método. No hace
     * falta ni un contador, ni una columna «stock_descontado», ni consultar el kardex
     * para ver si ya hay un movimiento — tres cosas que habría que recordar mantener.
     *
     * **El orden de las cinco cosas que hace, y por qué ese orden.**
     *
     *   1. **El lock de la venta, primero.** Antes de leer cualquier cosa de ella.
     *      Dos pestañas sobre la misma venta se serializan acá, y la segunda encuentra
     *      el estado ya cambiado.
     *   2. **La transición, antes de tomar un solo lock de producto.** Si la venta no
     *      puede pasar a `pagada`, no tiene sentido bloquear veinte filas de
     *      `productos` y escribir veinte movimientos de kardex para que la transacción
     *      los revierta al final.
     *   3. **Los productos de baja, todos, antes de mover ninguno.** Son dos
     *      recorridas de las líneas en lugar de una, y es a propósito: con una sola,
     *      si el tercer renglón tiene un producto de baja y el primero no tiene stock,
     *      el mensaje que recibe el usuario depende del **orden de las líneas**. Y son
     *      dos problemas con dos salidas distintas: uno se arregla en el catálogo y el
     *      otro en el depósito. Que te diga cuál tenés primero no puede ser un
     *      accidente.
     *   4. **El descuento, línea por línea, en orden de `producto_id`.** El orden no
     *      es cosmético: dos cobros simultáneos de dos ventas que comparten dos
     *      productos, recorridos en órdenes distintos, se bloquean en cruz y MariaDB
     *      mata una de las dos transacciones con un error de interbloqueo —que no es
     *      una regla de negocio y le llegaría al usuario como un error del servidor—.
     *      Tomando siempre los locks en el mismo orden, el cruce no puede formarse.
     *   5. **El estado, al final.** Es la afirmación de que todo lo anterior salió
     *      bien, así que se escribe cuando ya salió bien.
     *
     * **`activo` se lee sin lock y el stock con lock, y la diferencia es el hallazgo
     * entero.** El disponible lo lee `StockService::descontar()` **después** de
     * bloquear la fila del producto, porque es una cantidad que otra petición puede
     * estar cambiando en este mismo instante. `activo` no: es un atributo comercial
     * que mueve un administrador desde el catálogo, y que se desactive un producto en
     * el medio de un cobro no vuelve mal el cobro —estaba activo cuando la venta se
     * procesó—. Es la misma distinción que `crear()` ya tiene escrita sobre el tope de
     * descuento: lo que no depende del estado se valida afuera, lo que depende se lee
     * después del lock. Validar afuera lo que depende del estado es C-10.
     *
     * **El usuario que llega acá es el cajero**, y es el que queda en
     * `movimientos_stock.usuario_id`: la plata la cobró él y la mercadería salió por
     * su cobro. Quién vendió sigue en `ventas.usuario_id`, intacto. Que sean dos
     * columnas en dos tablas es lo que le permite al panel de la Fase 7 rankear las
     * dos cosas sin confundirlas.
     *
     * @throws \App\Exceptions\TransicionInvalidaException   la venta no puede pasar a pagada
     * @throws ReglaDeNegocioException                       hay un producto dado de baja
     * @throws \App\Exceptions\StockInsuficienteException    no hay disponible de algún producto
     */
    public function marcarPagada(Venta $venta, User $usuario): Venta
    {
        return DB::transaction(function () use ($venta, $usuario) {
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            // 1. La transición, antes de tomar un solo lock de producto. Un segundo
            //    pase sobre una venta ya pagada muere acá: `pagada → pagada` no está
            //    declarado, y ésa es toda la idempotencia del descuento de stock.
            MaquinaEstadosVenta::validar($v->estado, 'pagada');

            // El orden por producto_id es lo que impide el interbloqueo entre dos
            // cobros que comparten productos. Ver el docblock.
            $lineas = $v->lineas()->with('producto')->orderBy('producto_id')->get();

            // No puede pasar por el camino normal —`crear()` rechaza una venta sin
            // líneas— pero el método no puede depender de eso: si descontara cero
            // productos y escribiera el estado, la venta quedaría «pagada» sin que
            // nada hubiera salido del depósito, que es el peor estado posible.
            if ($lineas->isEmpty()) {
                throw new ReglaDeNegocioException(
                    "La venta {$v->numeroFormateado()} no tiene productos, así que no hay nada que cobrar."
                );
            }

            // 2. Los productos de baja, TODOS, antes de mover ninguno. Ver el punto 3
            //    del docblock: con una sola recorrida, qué error recibe el usuario
            //    dependería del orden de las líneas.
            foreach ($lineas as $linea) {
                if (! $linea->producto->activo) {
                    throw new ReglaDeNegocioException(
                        "«{$linea->producto->nombre}» está dado de baja y no se puede vender, así que la "
                        ."venta {$v->numeroFormateado()} no se puede cobrar. Quitá el renglón del "
                        .'presupuesto, o volvé a activar el producto en el catálogo.'
                    );
                }
            }

            // 3. El descuento. StockService abre su propia transacción, y en Laravel
            //    una transacción anidada es un savepoint: si la tercera línea no
            //    tiene disponible, se revierte todo junto, incluidos los movimientos
            //    de kardex de las dos primeras y el pago que escribió quien llamó
            //    acá. Es el mismo mecanismo que CompraService::recibir().
            foreach ($lineas as $linea) {
                $this->stock->descontar($linea->producto, $linea->cantidad, $v, $usuario);
            }

            // 4. El estado, al final.
            $this->cambiarEstado($v, 'pagada');

            return $v->fresh(['lineas', 'movimientos']);
        });
    }

    /**
     * La mercadería salió del local.
     *
     * Es la transición más chica del sistema y no tiene ningún efecto más que el
     * estado: no mueve stock —eso ya pasó al cobrar— y no mueve plata. Existe porque
     * sin ella `entregada` sería un estado declarado en el ENUM, ofrecido como filtro
     * en el listado y consultado por el panel de la Fase 7, que nada podría alcanzar:
     * un `entregada_parcial` más, pero con el filtro ofreciendo una opción cuyo único
     * resultado posible es vacío.
     *
     * **No recibe el usuario**, por lo mismo que `cancelar()`: no hay dónde
     * guardarlo. `ventas` no tiene una columna de quién entregó, el permiso lo exige
     * la ruta —`venta.entregar`, propio, porque entregar no es editar ni cobrar— y
     * `updated_at` responde el cuándo. Un parámetro que no se usa sería decoración.
     *
     * @throws \App\Exceptions\TransicionInvalidaException
     */
    public function entregar(Venta $venta): Venta
    {
        return DB::transaction(function () use ($venta) {
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            $this->cambiarEstado($v, 'entregada');

            return $v->fresh();
        });
    }

    /**
     * La devolución, total o parcial. Cierra A-11.
     *
     * Es el espejo de `CompraService::recibir()`: mismo contrato —el id de cada línea
     * como clave y cuántas unidades vuelven como valor, con las filas en cero
     * descartadas porque el formulario muestra todas—, y mismo estado derivado al
     * final. La diferencia es que una recepción sólo mueve mercadería y una devolución
     * mueve mercadería **y** plata.
     *
     * **Lo que hace, en orden, y por qué ese orden:**
     *
     *   1. **Las comprobaciones sobre lo que llegó, afuera de la transacción**: que
     *      venga al menos una unidad y que el medio de pago exista. Ninguna de las dos
     *      depende de algo que otra petición pueda cambiar.
     *   2. **El lock de la venta, como primera lectura de ella.** Es el candado de
     *      todo lo que es plata de esta venta, igual que en el cobro: sin él, dos
     *      devoluciones simultáneas de la misma venta leerían las mismas cantidades
     *      pendientes y las dos pasarían la validación.
     *   3. **`puedeDevolver()`, antes de reponer una sola unidad.** No se puede
     *      validar todavía el destino exacto, porque cuál de los dos corresponde se
     *      deriva de las cantidades aplicadas; lo que sí se puede es rechazar de
     *      entrada una venta que no admite ninguna devolución, y así no se reponen
     *      diez unidades para que la transacción las revierta al final.
     *   4. **Las líneas, resueltas a través del padre y ordenadas por producto.**
     *      Ordenadas por el mismo motivo que en `marcarPagada()`: dos devoluciones
     *      concurrentes que comparten productos, recorridas en órdenes distintos, se
     *      bloquean en cruz. Y a través del padre porque Laravel no verifica que dos
     *      parámetros estén relacionados: un id de línea suelto permitiría devolver
     *      contra la venta de otro cliente con una petición armada a mano. Compras
     *      resuelve eso con un `findOrFail` por línea; acá hacen falta todas juntas
     *      para poder ordenarlas, así que la comprobación equivalente es que vuelvan
     *      todas las que se pidieron.
     *   5. **Por línea: validar, reponer, y recién después mover el contador.** El
     *      tope es `cantidad_devuelta + lo que vuelve <= cantidad`, que es el contador
     *      que impide devolver dos unidades tres veces y llevarse seis.
     *   6. **La plata.** Ver abajo.
     *   7. **El estado, derivado.**
     *
     * **Cuánta plata vuelve, que es la parte interesante.** El monto **no se escribe
     * nunca**: si el operador lo tipeara podría devolver más de lo que entró. Se
     * calcula, y en dos modos:
     *
     *   - **Devolución parcial**: la parte proporcional de lo que vuelve, con su
     *     parte proporcional del descuento. Un 10 % de descuento sobre la venta es un
     *     10 % sobre lo que se devuelve, porque si no, devolver de a poco saldría más
     *     caro que devolver todo junto.
     *   - **Devolución que completa la venta**: exactamente lo que quedó cobrado, no
     *     la cuenta proporcional. Si fuera proporcional, una venta devuelta en tres
     *     tandas podría cerrar en un centavo en lugar de en cero por el redondeo de
     *     cada parte, y un libro que no cierra en cero es un libro roto.
     *
     * Y en los dos modos, un techo: **nunca más de lo que quedó cobrado**. Con el flujo
     * normal no se puede superar —la suma de las partes es el total— pero el método no
     * depende de eso. El caso de cero cobrado existe y es legítimo: una venta que llegó
     * a `pagada` sin pagos registrados sólo la produce una factory, y entonces no se
     * escribe ningún contra-asiento en lugar de escribir uno de monto cero, que sería
     * un asiento que no afirma nada.
     *
     * @param  array{cantidades: array<int|string, int|string>, metodo: string, motivo?: string|null}  $datos
     *
     * @throws ReglaDeNegocioException
     * @throws \App\Exceptions\TransicionInvalidaException
     */
    public function devolver(Venta $venta, array $datos, User $usuario): Venta
    {
        // Las líneas donde no vuelve nada no son un error: el formulario las manda en
        // cero porque muestra todas. Lo que no puede pasar es que no vuelva nada en
        // ninguna. Mismo criterio que `CompraService::recibir()`.
        $cantidades = array_filter(
            array_map('intval', $datos['cantidades'] ?? []),
            fn (int $cantidad) => $cantidad > 0,
        );

        if ($cantidades === []) {
            throw new ReglaDeNegocioException(
                'Indicá cuántas unidades se devuelven en al menos un producto.'
            );
        }

        $metodo = (string) ($datos['metodo'] ?? '');

        $this->exigirMedioDeDevolucion($metodo);

        // Cadena vacía y null significan lo mismo —no consta— y la columna es
        // nullable: se guarda null y no '', para que «sin motivo» tenga una sola
        // representación en la base.
        $motivo = trim((string) ($datos['motivo'] ?? '')) ?: null;

        return DB::transaction(function () use ($venta, $cantidades, $metodo, $motivo, $usuario) {
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            if (! MaquinaEstadosVenta::puedeDevolver($v->estado)) {
                throw new ReglaDeNegocioException(
                    "La venta {$v->numeroFormateado()} está «{$v->estadoTexto()}» y en ese estado no se "
                    .'devuelve. Se devuelve lo que se cobró: un presupuesto se cancela, y una venta ya '
                    .'devuelta por completo no tiene nada más que volver.'
                );
            }

            $lineas = $v->lineas()
                ->with('producto')
                ->whereIn('id', array_keys($cantidades))
                ->orderBy('producto_id')
                ->get();

            if ($lineas->count() !== count($cantidades)) {
                throw new ReglaDeNegocioException(
                    "Alguno de los renglones que se intenta devolver no pertenece a la venta "
                    ."{$v->numeroFormateado()}. Volvé a cargar la pantalla."
                );
            }

            $subtotalDevuelto = 0.0;

            foreach ($lineas as $linea) {
                $cantidad  = $cantidades[$linea->id];
                $pendiente = $linea->cantidadPendienteDeDevolver();

                if ($cantidad > $pendiente) {
                    throw new ReglaDeNegocioException(
                        "De «{$linea->descripcion}» se vendieron {$linea->cantidad} unidad(es), ya se "
                        ."devolvieron {$linea->cantidad_devuelta} y se están devolviendo {$cantidad}: "
                        ."quedan {$pendiente} por devolver. No se puede devolver más de lo que se vendió."
                    );
                }

                // `StockService` abre su propia transacción, que en Laravel es un
                // savepoint: si una línea posterior falla, se revierte todo junto,
                // incluidos el kardex y el contra-asiento. El `origen` es la venta,
                // igual que en el descuento: el kardex va a tener dos asientos con el
                // mismo origen, uno negativo de tipo `venta` y uno positivo de tipo
                // `devolucion`, y eso es lo que cuenta la historia completa.
                $this->stock->reponer($linea->producto, $cantidad, $v, $usuario, $motivo);

                // `cantidad_devuelta` está fuera de `$fillable`: asignación directa.
                $linea->cantidad_devuelta = $linea->cantidad_devuelta + $cantidad;
                $linea->save();

                $subtotalDevuelto += round($cantidad * (float) $linea->precio_unitario, 2);
            }

            $subtotalDevuelto = round($subtotalDevuelto, 2);

            // El estado se deriva de las líneas YA escritas, y de ese mismo predicado
            // sale si la plata que vuelve es la proporcional o todo el saldo cobrado.
            $completa = ! $v->fresh('lineas')->tienePendientesDeDevolver();

            $retenido  = $v->pagado();
            $aDevolver = $completa
                ? $retenido
                : min($this->parteProporcional($v, $subtotalDevuelto), $retenido);

            if ($aDevolver > 0) {
                // El contra-asiento. `monto` es asignable porque el monto de un pago
                // lo pone quien cobra; acá lo pone el servicio, que es el único que
                // sabe cuánto corresponde. El cajero y la fecha van por asignación
                // directa, como en el cobro.
                $reversion = $v->pagos()->make([
                    'metodo' => $metodo,
                    'monto'  => -$aDevolver,
                ]);

                $reversion->usuario_id = $usuario->id;
                $reversion->fecha      = now();

                $reversion->save();
            }

            $this->cambiarEstado($v, $completa ? 'devuelta' : 'devuelta_parcial');

            return $v->fresh(['lineas', 'pagos', 'movimientos']);
        });
    }

    /**
     * Cancela un presupuesto.
     *
     * **No tiene ningún efecto más que el estado**, y eso no es una simplificación:
     * es lo que significa cancelar. La máquina de estados sólo admite `cancelada`
     * desde `presupuesto` y `pendiente_pago`, es decir antes de que exista
     * comprobante, antes de que se haya movido stock y antes de que haya entrado un
     * peso. No hay nada que reponer ni que devolver. Una venta que ya se cobró no se
     * cancela: se devuelve, y ése es el camino que revierte los pagos (A-11), en la
     * segunda mitad de la fase.
     *
     * El original permitía `cobrada → anulada`: reponía el stock y dejaba las filas
     * de `pagos` intactas, así que quedaba plata cobrada sobre una venta
     * inexistente. Acá esa transición no está declarada, y por eso este método no
     * necesita ni un `if` para rechazarla.
     *
     * **La venta no se borra**, y el estado es lo que lo hace evidente: un
     * presupuesto cancelado sigue teniendo sus líneas, su total y su número. Es el
     * núcleo de M-16 visto desde el otro lado: `SaleDao::delete()` era un `DELETE`
     * plano sobre una tabla con `ON DELETE CASCADE`.
     *
     * **No recibe el usuario**, porque no habría dónde guardarlo: `ventas` no tiene
     * una columna de quién canceló, y el permiso lo exige la ruta. Un parámetro que
     * no se usa sería decoración, igual que en `CompraService::marcarEnviada()`.
     * Quién vendió queda en `ventas.usuario_id` y cuándo cambió, en `updated_at`. Si
     * el negocio pidiera saber quién canceló, es una columna nullable más el
     * registro en el servicio, sin tocar ninguna relación.
     *
     * @throws \App\Exceptions\TransicionInvalidaException
     */
    public function cancelar(Venta $venta): Venta
    {
        return DB::transaction(function () use ($venta) {
            // Se bloquea antes de leer el estado: sin el lock, dos pestañas pueden
            // leer «presupuesto» las dos y escribir las dos. Acá el daño es menor
            // que en un pago, pero el mecanismo es el mismo y no hay motivo para
            // aplicarlo de a ratos.
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            $this->cambiarEstado($v, 'cancelada');

            return $v->fresh();
        });
    }

    /**
     * El tope de descuento que rige para este usuario, en porcentaje.
     *
     * Público porque lo necesitan tres lugares que tienen que decir lo mismo: el
     * Form Request, para que el error caiga en el campo; la vista, para escribir la
     * ayuda del campo; y este servicio, que es la barrera. Un número repetido en
     * tres archivos se desincroniza.
     */
    public function topeDeDescuento(User $usuario): float
    {
        return (float) ($usuario->tienePermiso('venta.autorizar_descuento')
            ? config('venta.descuento.tope_autorizado')
            : config('venta.descuento.tope_general'));
    }

    // ------------------------------------------------------------------
    // Interno
    // ------------------------------------------------------------------

    /**
     * Escribe las líneas cotizando cada una contra el precio de la base.
     *
     * Los productos se traen en **una** consulta y no una por línea: una venta de
     * veinte renglones haría veinte consultas para leer veinte precios.
     *
     * De la línea sólo son asignables `producto_id` y `cantidad`, que es lo único
     * que manda el formulario. Los seis campos congelados se escriben por asignación
     * directa; un `create()` con ellos adentro **los descartaría en silencio** y la
     * línea quedaría sin precio, así que no es una formalidad.
     *
     * @param  array<int, array{producto_id: int, cantidad: int}>  $lineas
     */
    private function escribirLineas(Venta $venta, array $lineas): void
    {
        $productos = Producto::query()
            ->whereIn('id', array_column($lineas, 'producto_id'))
            ->get()
            ->keyBy('id');

        foreach ($lineas as $datos) {
            $producto = $productos->get((int) $datos['producto_id']);
            // Inalcanzable hoy, y queda anotado para que nadie lea esta rama
            // creyendo que el caso existe: `venta_lineas.producto_id` es una
            // clave foránea RESTRICT, así que la base se niega a borrar un
            // producto que esté en una línea de venta, y `ProductoService`
            // lo desactiva antes de intentarlo. No es lo mismo que la rama
            // equivalente de `escribirLineas()`, que sí sirve, porque allá los
            // ids vienen del pedido y la API de la Etapa 3 puede mandar uno
            // inventado; acá salen de líneas que ya existen. Se conserva por si
            // alguna vez la cascada cambia.
            if ($producto === null) {
                throw new ReglaDeNegocioException(
                    'Uno de los productos de la venta ya no existe. Volvé a cargar la pantalla.'
                );
            }

            if (! $producto->activo) {
                throw new ReglaDeNegocioException(
                    "«{$producto->nombre}» está dado de baja y no se puede vender. Si hay que venderlo, "
                    .'hay que volver a activarlo en el catálogo.'
                );
            }

            $cantidad = (int) $datos['cantidad'];

            // Segunda barrera de la misma regla que el Form Request: una línea de
            // cero unidades es un renglón que no dice nada, y en negativo sería un
            // descuento disfrazado de producto.
            if ($cantidad < 1) {
                throw new ReglaDeNegocioException(
                    "La cantidad de «{$producto->nombre}» tiene que ser al menos 1."
                );
            }

            // El precio del mostrador es `precio_contado`, y es el único que
            // interviene en la Etapa 1. Se toma tal como lo devuelve el cast
            // `decimal:2` —un string— y no como float: convertirlo en el camino
            // sería reintroducir A-17 por la puerta de atrás.
            $precio   = $producto->precio_contado;
            $importes = $this->importesDe($precio, $producto->alicuota_iva, $cantidad);

            $linea = $venta->lineas()->make([
                'producto_id' => $producto->id,
                'cantidad'    => $cantidad,
            ]);

            // La descripción se COPIA, no se referencia: si el producto se renombra,
            // el documento tiene que seguir diciendo qué se vendió. Mismo principio
            // que el `codigo_proveedor` de la línea de compra.
            $linea->descripcion     = $producto->nombre;
            $linea->precio_unitario = $precio;
            $linea->alicuota_iva    = $producto->alicuota_iva;
            // Congelado para el margen. Sin esto el panel lo calcularía contra el
            // costo de hoy y daría números falsos cada vez que cambia un costo.
            $linea->costo_unitario  = $producto->costo_promedio;
            $linea->neto            = $importes['neto'];
            $linea->iva             = $importes['iva'];
            $linea->total           = $importes['total'];
            $linea->save();
        }
    }

        /**
     * Ajusta las líneas al pedido nuevo, sin borrar lo que sobrevive.
     *
     * El `keyBy` por producto puede hacerse sin miedo porque
     * `exigirProductosDistintos()` ya corrió: con un producto repetido, `keyBy` se
     * quedaría con uno de los dos en silencio.
     *
     * @param  array<int, array{producto_id: int, cantidad: int}>  $lineas
     */
    private function ajustarLineas(Venta $venta, array $lineas): void
    {
        $pedidos    = collect($lineas)->keyBy(fn (array $linea) => (int) $linea['producto_id']);
        $existentes = $venta->lineas()->get()->keyBy('producto_id');

        // 1. Lo que salió del pedido se va. Un DELETE con WHERE, no una línea por
        //    consulta.
        $venta->lineas()->whereNotIn('producto_id', $pedidos->keys())->delete();

        // 2. Lo que entra se cotiza al precio de hoy, por el mismo camino que el
        //    alta: una línea nueva en un presupuesto viejo no tiene precio viejo que
        //    conservar.
        $nuevas = $pedidos
            ->reject(fn (array $linea, int $productoId) => $existentes->has($productoId))
            ->values()
            ->all();

        if ($nuevas !== []) {
            $this->escribirLineas($venta, $nuevas);
        }

        // 3. Lo que sobrevive conserva su precio congelado. Esto es M-14.
        foreach ($existentes as $productoId => $linea) {
            if (! $pedidos->has($productoId)) {
                continue;
            }

            $cantidad = (int) $pedidos->get($productoId)['cantidad'];

            if ($cantidad < 1) {
                throw new ReglaDeNegocioException(
                    "La cantidad de «{$linea->descripcion}» tiene que ser al menos 1. "
                    .'Si no se vende, quitá el renglón.'
                );
            }

            if ($cantidad === $linea->cantidad) {
                continue;   // no cambió: no se escribe nada
            }

            $this->recalcularLinea($linea, $cantidad);
        }
    }

    /**
     * Recalcula los importes de una línea que ya existe, con el precio que ya tiene.
     *
     * No vuelve a mirar el producto. Es el método que hace que cambiar una cantidad
     * no sea una recotización encubierta.
     */
    private function recalcularLinea(VentaLinea $linea, int $cantidad): void
    {
        $importes = $this->importesDe($linea->precio_unitario, $linea->alicuota_iva, $cantidad);

        $linea->cantidad = $cantidad;
        $linea->neto     = $importes['neto'];
        $linea->iva      = $importes['iva'];
        $linea->total    = $importes['total'];

        $linea->save();
    }

    /**
     * Los tres importes de una línea.
     *
     * El precio es final: el IVA está adentro. Así que el total es precio por
     * cantidad, el neto se obtiene dividiendo por (1 + alícuota) y el IVA es la
     * diferencia — y no al revés. Calcular el IVA sobre el neto redondeado haría que
     * neto + iva no diera el total por un centavo, y el total es lo que el cliente
     * paga y lo que se declara.
     *
     * Vive en un solo lugar porque lo usan los tres caminos que escriben una línea
     * —cotizar, recalcular por cantidad y recotizar— y tres copias de una cuenta de
     * redondeo son tres oportunidades de que una quede distinta.
     *
     * @return array{neto: float, iva: float, total: float}
     */
    private function importesDe(float|string $precio, float|string $alicuota, int $cantidad): array
    {
        $total = round($cantidad * (float) $precio, 2);
        $neto  = round($total / (1 + ((float) $alicuota / 100)), 2);

        return [
            'neto'  => $neto,
            'iva'   => round($total - $neto, 2),
            'total' => $total,
        ];
    }

    /**
     * Sólo un presupuesto se modifica.
     *
     * Una venta cobrada no se corrige editándola: lo que se cobró y se entregó se
     * devuelve, y la devolución deja su rastro. Es la misma idea que «sólo un
     * borrador se edita» en compras, con la diferencia de que acá no hay un monto
     * comprometido sino plata cobrada y mercadería entregada.
     *
     * El mensaje nombra el estado en palabras y dice qué se intentó hacer, porque el
     * usuario puede llegar por una URL guardada en favoritos y no por un botón.
     */
    private function exigirPresupuesto(Venta $venta, string $accion): void
    {
        if (! $venta->esPresupuesto()) {
            throw new ReglaDeNegocioException(
                "La venta {$venta->numeroFormateado()} está «{$venta->estadoTexto()}» y ya no se puede "
                ."{$accion}: lo que se cobró no se corrige editando, se devuelve."
            );
        }
    }

        /**
     * Cambia el estado validando la transición. **Privado, a propósito.**
     *
     * El estado destino lo elige el método público que llama acá —`cancelar()` pasa
     * `'cancelada'` escrito en el código— y nunca viaja en una petición. Si este
     * método fuera público, el controlador podría recibir el destino del formulario
     * y pasarlo: sería `SaleService::updateEstado($id, $nuevoEstado)` con una tabla
     * delante, y el hallazgo C-9 no es sólo que la tabla faltara.
     *
     * `estado` está fuera de `$fillable`, así que se asigna por propiedad: un
     * `update(['estado' => …])` lo **descartaría en silencio** y la venta se quedaría
     * en el estado anterior sin que nada falle. Pasar siempre por acá evita tener
     * que recordarlo en cada método.
     *
     * No recibe columnas acompañantes como su equivalente de compras, y es porque
     * `ventas` no tiene ninguna: la orden de compra guarda `fecha_aprobacion` y
     * `fecha_envio`, pero las fechas de una venta viven en los documentos que
     * produjeron el cambio —`pagos.fecha`— y en `updated_at`. Un parámetro para un
     * caso que no existe sería adivinar el futuro.
     *
     * @throws \App\Exceptions\TransicionInvalidaException
     */
    private function cambiarEstado(Venta $venta, string $nuevo): void
    {
        MaquinaEstadosVenta::validar($venta->estado, $nuevo);

        $venta->estado = $nuevo;

        $venta->save();
    }

    /**
     * Recalcula los tres importes de la cabecera desde las líneas guardadas.
     *
     * La suma la hace la base y no PHP (A-26): no hace falta traer las líneas para
     * sumarlas. `costo_envio` entra en la fórmula aunque en la Etapa 1 sea siempre
     * cero, para que la cuenta esté escrita completa en un solo lugar el día que
     * haya envíos.
     */
    private function recalcularTotales(Venta $venta, float $porcentaje): void
    {
        $subtotal  = (float) $venta->lineas()->sum('total');
        $descuento = round($subtotal * $porcentaje / 100, 2);

        // Los tres están fuera de $fillable: asignación directa, igual que el estado.
        $venta->subtotal  = $subtotal;
        $venta->descuento = $descuento;
        $venta->total     = round($subtotal - $descuento + (float) $venta->costo_envio, 2);

        $venta->save();
    }

    /**
     * El tope de A-12.
     *
     * El mensaje dice el número y qué hacer, porque no hay flujo de autorización:
     * quien no puede, no puede, y alguien con el permiso tiene que cargar la venta.
     * Está anotado en los pendientes de usabilidad con ese motivo.
     */
    private function exigirDescuentoAutorizado(float $porcentaje, User $usuario): void
    {
        if ($porcentaje < 0) {
            throw new ReglaDeNegocioException(
                'El descuento no puede ser negativo: eso sería un recargo, y el recargo por tarjeta '
                .'no lo calcula este sistema.'
            );
        }

        $tope = $this->topeDeDescuento($usuario);

        if ($porcentaje > $tope) {
            throw new ReglaDeNegocioException(
                "El descuento máximo que podés aplicar es del {$tope} %, y estás cargando {$porcentaje} %. "
                .'Un descuento mayor lo tiene que cargar alguien con autorización para hacerlo.'
            );
        }
    }

    /**
     * Una sola línea por producto, con la cantidad total.
     *
     * A diferencia de compras, acá la base **no** tiene un UNIQUE que lo garantice:
     * `venta_lineas` no lo declara. No es un agujero de plata —el precio lo pone el
     * servidor, así que dos líneas del mismo producto valdrían lo mismo— pero es un
     * documento con el mismo renglón repetido, y el día que haya que emitir una nota
     * de crédito parcial habría dos líneas candidatas para la misma cosa.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function exigirProductosDistintos(array $lineas): void
    {
        $productos = array_column($lineas, 'producto_id');

        if (count($productos) !== count(array_unique($productos))) {
            throw new ReglaDeNegocioException(
                'La venta tiene el mismo producto cargado más de una vez. Dejá una sola línea '
                .'por producto, con la cantidad total.'
            );
        }
    }

        /**
     * La parte del dinero que corresponde a lo que vuelve, en una devolución parcial.
     *
     * El descuento de la venta es un **monto** sobre el subtotal, así que la parte
     * que vuelve se lleva su parte proporcional del descuento. Sin eso, devolver de a
     * poco saldría más caro que devolver todo junto: se reintegraría el precio de
     * lista de cada unidad sobre una venta que se cobró con descuento.
     *
     * `costo_envio` **no** entra en la cuenta aunque sí entre en el total de la
     * venta, y es a propósito: el envío no es proporcional a los renglones, se paga
     * una vez. En la Etapa 1 siempre es cero, así que no cambia ningún número; el día
     * que haya envíos, devolver un renglón no va a reintegrar una fracción del flete.
     *
     * Subtotal cero devuelve cero en lugar de dividir por cero. No se alcanza por el
     * camino normal —una venta sin líneas no llega a `pagada`— pero el método no
     * puede depender de eso, igual que `Venta::descuentoPorcentaje()`.
     */
    private function parteProporcional(Venta $venta, float $subtotalDevuelto): float
    {
        $subtotal = (float) $venta->subtotal;

        if ($subtotal <= 0) {
            return 0.0;
        }

        $descuento = round((float) $venta->descuento * $subtotalDevuelto / $subtotal, 2);

        return round($subtotalDevuelto - $descuento, 2);
    }

    /**
     * El medio por el que vuelve la plata tiene que existir y estar operativo.
     *
     * Se valida contra los medios **en uso** y no contra los cuatro del ENUM: en la
     * Etapa 1 no hay integración con Mercado Pago, así que una devolución registrada
     * por ese medio afirmaría un reembolso que el sistema no disparó. Cuando la Etapa
     * 2 integre el reembolso por la API, ese medio entra en la lista y este método no
     * se toca.
     *
     * Es la segunda barrera de la misma regla que el Form Request, que es la que
     * hereda la API de la Etapa 3.
     */
    private function exigirMedioDeDevolucion(string $metodo): void
    {
        if (! in_array($metodo, Pago::METODOS_EN_USO, true)) {
            $disponibles = collect(Pago::METODOS_EN_USO)
                ->map(fn (string $clave) => Pago::METODOS[$clave])
                ->implode(', ');

            throw new ReglaDeNegocioException(
                'Indicá por qué medio vuelve la plata. Los disponibles son: '.$disponibles.'.'
            );
        }
    }
}