<?php

namespace App\Services;

use App\Models\Marca;
use App\Support\Slug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Reglas de negocio de marcas.
 *
 * El servicio no conoce la petición HTTP ni la sesión: recibe datos y, cuando
 * corresponde, el archivo subido. Un UploadedFile es un archivo, no la
 * petición; el controlador de la API en la Etapa 3 va a entregar exactamente lo
 * mismo y va a llamar a estos métodos sin cambiarlos.
 *
 * Dos reglas propias:
 *
 *   1. El slug lo calcula el servicio, no el formulario. Se recalcula sólo si
 *      cambió el nombre.
 *   2. Una marca con productos NO se borra: se desactiva. Es la misma política
 *      que el sistema original no tenía —SaleDao::delete() era un DELETE plano
 *      y el ON DELETE CASCADE se llevaba el historial (hallazgo M-16)—. Acá la
 *      baja física se reserva a lo que nadie referencia.
 *
 * Métodos:
 *   crear()       alta, con logo opcional
 *   actualizar()  edición, reemplazo y quita del logo
 *   eliminar()    baja física o lógica según haya productos; devuelve cuál fue
 */
class MarcaService
{
    private const CARPETA = 'marcas';

    public function crear(array $datos, ?UploadedFile $logo = null): Marca
    {
        $ruta = $logo?->store(self::CARPETA, 'public');

        return DB::transaction(fn () => Marca::create([
            'nombre' => $datos['nombre'],
            'slug'   => Slug::unicoPara(Marca::class, $datos['nombre']),
            'activo' => $datos['activo'],
            'logo'   => $ruta,
        ]));
    }

    public function actualizar(Marca $marca, array $datos, ?UploadedFile $logo = null, bool $quitarLogo = false): Marca
    {
        $logoAnterior = $marca->logo;
        $rutaNueva    = $logo?->store(self::CARPETA, 'public');

        $actualizado = DB::transaction(function () use ($marca, $datos, $rutaNueva, $quitarLogo) {
            $cambioElNombre = $marca->nombre !== $datos['nombre'];

            $marca->update([
                'nombre' => $datos['nombre'],
                // Sólo se recalcula si cambió el nombre: regenerarlo siempre
                // haría que guardar sin tocar nada corriera el sufijo.
                'slug'   => $cambioElNombre
                    ? Slug::unicoPara(Marca::class, $datos['nombre'], $marca->id)
                    : $marca->slug,
                'activo' => $datos['activo'],
                'logo'   => match (true) {
                    $rutaNueva !== null => $rutaNueva,
                    $quitarLogo         => null,
                    default             => $marca->logo,
                },
            ]);

            return $marca->fresh();
        });

        // El archivo se borra DESPUÉS del commit. El sistema de archivos no
        // participa de la transacción: si la borráramos adentro y el commit
        // fallara, quedaría una fila apuntando a un archivo inexistente.
        if ($logoAnterior !== null && $logoAnterior !== $actualizado->logo) {
            Storage::disk('public')->delete($logoAnterior);
        }

        return $actualizado;
    }

    /**
     * @return bool  true si se borró la fila, false si sólo se desactivó
     */
    public function eliminar(Marca $marca): bool
    {
        // Borrar la marca de un producto vendido dejaría el producto sin marca
        // y la venta sin forma de reconstruir qué se vendió. La baja lógica
        // conserva el dato y saca la marca de los listados de alta.
        if ($marca->productos()->exists()) {
            $marca->update(['activo' => false]);

            return false;
        }

        $logo = $marca->logo;

        DB::transaction(fn () => $marca->delete());

        if ($logo !== null) {
            Storage::disk('public')->delete($logo);
        }

        return true;
    }
}