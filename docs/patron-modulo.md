# Patrón de un módulo

Cómo se construye un módulo en este proyecto. Se fijó en la Fase 3 (catálogo)
y lo replican los módulos de las fases siguientes. Cada pieza existe porque
resuelve un hallazgo concreto de la auditoría; están anotados al lado.

---

## Las nueve piezas

### 1. Modelo — `app/Models/`

`$table` declarado, relaciones, casts y **un scope por filtro del listado**.
Nada de validación y nada de reglas de negocio.

- Todo importe con cast `decimal:2`; los enteros, `integer` (A-17).
- Las columnas que sólo mueve un servicio quedan fuera de `$fillable`. Si
  tienen `DEFAULT` en la base, van en `$attributes` con el mismo valor, para
  que el objeto y la fila digan lo mismo desde el primer momento.
- Un scope nunca filtra con un valor vacío o desconocido: devuelve la consulta
  sin tocar.
- Búsquedas de texto con `App\Support\Like::contiene()`, que escapa los
  comodines.
- Un `OR` dentro de un scope va agrupado en un closure, o se combina mal con
  los demás filtros.

### 2. Form Request de datos — `app/Http/Requests/{Modulo}Request.php`

Uno solo para el alta y la edición; `$this->route('modelo')` distingue.

- **Rechaza, no corrige** (M-31). Nada de valores por defecto que tapen un
  campo faltante ni de truncar textos largos.
- `prepareForValidation()` normaliza (mayúsculas, formato de importe), no
  arregla.
- `messages()` y `attributes()` en español, siempre. Sin `attributes()`, los
  mensajes genéricos nombran la columna: "parent id".
- Una referencia a otra tabla se exige activa **sólo si cambia**: un registro
  cuyo padre se desactivó después tiene que poder seguir editándose.

### 3. Form Request de filtros — `{Modulo}FiltroRequest.php`

Declara el contrato de filtros. Es la corrección de fondo de A-24: el bug
existió porque no había ningún lugar donde constara qué filtros acepta el
listado.

Un filtro inválido redirige al listado limpio con un aviso, en vez de rebotar
o romper la pantalla.

### 4. Servicio — `app/Services/`

La lógica de negocio. No conoce la petición HTTP ni la sesión: recibe datos y
devuelve modelos. En la Etapa 3 la API llama a estos mismos métodos.

- Toda escritura dentro de `DB::transaction()`.
- Lo que está referenciado no se borra: se desactiva (M-16).
- Archivos: se guardan antes de la transacción y se borran si falla; los que
  se quitan, después del commit. **Qué borrar se calcula en el servidor**, a
  partir de lo que el registro tiene, nunca de lo que envía el usuario.
- Las cascadas son decisiones explícitas del usuario, nunca efectos
  automáticos.
- Los campos se enumeran uno por uno al escribir: nunca `create($validated)`.

### 5. Controlador — `app/Http/Controllers/`

Traduce entre HTTP y el servicio. Sin reglas de negocio y sin consultas de
negocio: encadena los scopes del modelo.

- `with()` para las relaciones que la vista usa; `withCount()` para contar en
  la base (A-26).
- `paginate()` **siempre**, con `withQueryString()` (A-25).
- El mensaje de resultado dice lo que pasó de verdad: si se desactivó en lugar
  de borrarse, lo dice.
- Después de guardar, se vuelve al contexto donde estaba el usuario.

### 6. Rutas — `routes/web.php`

**Un permiso por acción**, nunca uno por módulo (C-2):

    Route::post('/productos', [ProductoController::class, 'store'])
        ->middleware('can:producto.crear')->name('productos.store');

### 7. Vistas — `resources/views/{modulo}/`

`index.blade.php` y `form.blade.php` (alta y edición comparten formulario).

- Filtros por GET: la pantalla queda enlazable y el botón "atrás" funciona.
- `old()` en todos los campos: un error en un campo no borra los demás.
- Cada campo: `<label for>`, ayuda y error enlazados con `aria-describedby`.
- Ningún estado se comunica sólo con color: el badge lleva texto.
- El vacío por filtro y el vacío por falta de datos dicen cosas distintas.
- Botones de acción con `aria-label` que nombra el registro.
- Responsive: columnas secundarias con `d-none d-md-table-cell`, botones a lo
  ancho en móvil.
- Lenguaje del negocio, nunca del programador: ni "padre", ni "slug", ni ids.
  Trato de vos en todo el sistema.
- Componentes disponibles: `<x-opciones-categoria>`, `<x-gestor-imagenes>`,
  `data-buscable` en un select para agregarle búsqueda.

### 8. Tests — `tests/Feature/`

Dos niveles, y hacen falta los dos:

- `{Modulo}FiltrosTest`: los scopes filtran.
- `{Modulo}ModuloTest`: el parámetro de la URL llega al scope, los permisos
  protegen cada ruta, la validación rechaza y las bajas hacen lo que dicen.

A-24 pasó once commits sin detectarse porque el scope funcionaba y el
cableado no. Un solo nivel no lo habría encontrado.

El dataset de permisos prueba las dos direcciones: con todos los permisos del
módulo **menos** el de la ruta se deniega, y con **sólo** ese se permite. Sin
la primera mitad, una ruta protegida por el permiso equivocado pasaría.

### 9. Trazabilidad

`docs/trazabilidad.md` se actualiza en el mismo commit que migra el
componente. Nunca antes.

---

## Orden de trabajo

1. Modelo con scopes + test de filtros.
2. Form Request + servicio + test del servicio.
3. Filtros, controlador, rutas, vistas.
4. Test del módulo.
5. Trazabilidad.

Los pasos 1 y 2 son verificables en `tinker` antes de que exista una pantalla.