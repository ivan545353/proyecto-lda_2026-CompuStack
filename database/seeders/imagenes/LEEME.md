# Fotos de los productos de demostración

`TiendaDemoSeeder` copia lo que haya en esta carpeta al disco `public` y se lo asigna a cada
producto de demostración. Se versionan acá para que la demo se arme igual en cualquier máquina.

## Nombre de los archivos

    <CÓDIGO>-<n>.jpg | .jpeg | .png | .webp

- `<CÓDIGO>` es el código del producto, tal cual: `VG248QG`, `HDD-SEA1TB`, `SSD-WD1TB`…
- `<n>` es el orden de la galería. **La 1 es la foto principal** (la del listado).
- Hasta 5 fotos por producto (el mismo tope que el formulario web). Las que sobren se ignoran.
- Un archivo que no cumpla el nombre se ignora sin avisar. Un producto sin fotos queda con la
  imagen por omisión.

## Productos de demostración

`VG248QG`, `G505`, `HDD-SEA1TB`, `SSD-WD1TB`, `MB-ASUSB450`, `RAM-KVR16`, `HP-2775`,
`HX-STINGER`, `MIC-K669B`, `COR-4000D`, `EVGA-600`.

## Qué fotos se pueden poner

Sólo fotos **propias** o de **uso libre** (con la licencia anotada abajo). No se descargan de la
web ni se copian de la página de un fabricante o una tienda. Hasta 1 MB cada una, igual que en
el formulario.

## Licencias

Una línea por archivo: nombre, autor y licencia. Sin esa línea la foto no entra.

| Archivo | Autor | Licencia |
| --- | --- | --- |
| _(todavía ninguna)_ | | |

## Cómo se aplican

    php artisan db:seed --class=TiendaDemoSeeder

Se puede correr cuantas veces haga falta. No pisa lo que alguien editó a mano: la descripción se
reemplaza sólo si sigue siendo el texto de relleno, y las fotos sólo si el producto no tiene o
son las que puso este seeder.
