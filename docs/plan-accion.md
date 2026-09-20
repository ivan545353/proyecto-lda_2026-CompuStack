# Plan de acción — Etapa 1

Implementación concreta del plan de migración. Código de referencia e instrucciones paso a paso.

---

# PROMPT PARA NUEVOS CHATS

> Copiar desde acá hasta el final del bloque al iniciar una conversación nueva.

---

**Contexto**

Estoy cursando Laboratorio de Desarrollo de Aplicaciones (Ingeniería en Sistemas, UNPA-UACO). El proyecto integrador tiene tres etapas: migrar el backend a Laravel, desarrollar una app Flutter, e implementar una API integrada con Flutter. **Estamos en la Etapa 1.**

El sistema original es un gestor de tienda informática: backend PHP vanilla con arquitectura MVC propia (Pipeline de middlewares, DAO, DTO) y frontend Angular separado, que lo consumía como API. Se migra a **Laravel 12 con PHP 8.2, MariaDB, vistas Blade y Bootstrap** (no Tailwind). Angular no se migra: las pantallas se rehacen en Blade y el proyecto Angular queda como referencia congelada.

Ya están hechos y aprobados: la auditoría del sistema original, el modelo de datos nuevo, el diagrama entidad-relación, la tabla de trazabilidad, el plan de migración y este plan de acción. **El modelo de datos está cerrado; no proponer cambios salvo que aparezca un problema real.**

**Qué necesito**

Implementar las fases del plan de acción adjunto, en orden. Indicame en qué fase estoy y seguimos desde ahí.

**Reglas que no se rompen**

1. **Alcance de la Etapa 1.** No implementar tienda pública, carrito, Mercado Pago, facturación AFIP, Zipnova, vales, banners ni Flutter. Si algo de eso hace falta más adelante, dejarlo preparado en el esquema, no implementado.
2. **Nada de credenciales en el repositorio.** Todo va a `.env`, con `.env.example` versionado.
3. **Los permisos son claves nombradas y se deniega por defecto.** Nunca un permiso por descarte ni un valor por omisión permisivo.
4. **La lógica de negocio va en servicios, no en controladores.** En la Etapa 3 los mismos servicios se consumen desde una API; si la lógica queda en el controlador, hay que reescribir todo.
5. **Las validaciones van en Form Requests**, no en el controlador ni en los setters del modelo.
6. **Código y base de datos en español**, con `$table` declarado explícitamente en cada modelo.
7. **Cada fase entrega sus tests.** Una fase sin tests no está terminada.
8. **Commits en formato Conventional Commits**, una rama por fase, integración por pull request.

**Archivos a adjuntar además del estado actual del código**

| Archivo | Para qué |
|---|---|
| `plan-accion.md` | Este documento: los pasos a implementar |
| `plan-migracion.md` | Fases, orden, criterios de terminado |
| `modelo-datos.md` | Esquema completo con la justificación de cada decisión |
| `der.puml` | Diagrama entidad-relación |
| `auditoria.md` | Hallazgos del sistema original que no hay que repetir |
| `trazabilidad.md` | Mapeo componente original → componente Laravel, con su estado |
| `Proyecto - Etapa 01.pdf` | Consigna del profesor |
| `lp_2025.sql` | Base de datos original, sólo como referencia |
| Los `.rar` del sistema original | Sólo si hay que consultar cómo funcionaba algo puntual |

Del código actual, adjuntar el árbol del proyecto Laravel y los archivos de la fase en curso.

---

# FIN DEL PROMPT

---

## Fase 0 — Repositorio y línea base

### 0.1 Preparar el legacy

```bash
mkdir -p sistema-gestion/legacy
cd sistema-gestion
git init -b main
```

Copiar el backend PHP a `legacy/backend/` y el Angular a `legacy/frontend/`, y limpiar lo que no debe versionarse:

```bash
rm -rf legacy/backend/app/vendor
rm -rf legacy/frontend/node_modules legacy/frontend/.angular
rm -f  legacy/frontend/*.zip
```

### 0.2 Quitar los secretos del legacy

Es obligatorio: la consigna prohíbe versionar credenciales y el sistema original las tiene escritas en el código.

En `legacy/backend/app/config/AppConfig.php`:

```php
// ANTES
define("JWT_SECRET", '2896bd45d7c219ccec38199c54734628');

// DESPUÉS
define("JWT_SECRET", 'REEMPLAZADO_POR_SEGURIDAD_VER_legacy/README.md');
```

En `legacy/backend/app/libs/database/Connection.php`, reemplazar usuario y contraseña por marcadores equivalentes.

### 0.3 Documentar el legacy

`legacy/README.md`:

```markdown
# Sistema original (congelado)

Versión previa a la migración, conservada como referencia según la consigna.
**No se modifica.**

- `backend/` — PHP vanilla, arquitectura MVC propia (Pipeline, DAO, DTO)
- `frontend/` — Angular 21, consumía el backend como API

## Modificaciones aplicadas al importarlo

1. `JWT_SECRET` y credenciales de base reemplazados por marcadores.
   El original los tenía escritos en el código, lo que incumple la regla
   de no versionar credenciales. Es el primer hallazgo de seguridad de
   la auditoría.
2. Se excluyeron `vendor/`, `node_modules/`, `.angular/` y un archivo
   comprimido que estaba versionado por error.

El código fuente no fue alterado en ningún otro aspecto.
```

### 0.4 Primer commit y tag

```bash
cat > .gitignore <<'EOF'
/vendor
/node_modules
/public/build
/public/hot
/public/storage
/storage/*.key
/storage/pail
.env
.env.backup
.env.production
.phpunit.result.cache
Homestead.json
Homestead.yaml
auth.json
npm-debug.log
yarn-error.log
/.fleet
/.idea
/.vscode
EOF

git add .
git commit -m "chore: importa el sistema original como linea base

Backend PHP y frontend Angular previos a la migracion, conservados
segun la consigna. Se removieron dependencias versionadas y se
reemplazaron las credenciales embebidas por marcadores."
git tag -a v0-original -m "Sistema original previo a la migracion"
```

Crear el repositorio en GitHub y subirlo con `git push -u origin main --tags`.

**Verificación antes de seguir:**

```bash
git log --oneline          # un solo commit
git tag                    # v0-original
grep -rn "2896bd45" .      # sin resultados
```

---

## Fase 1 — Proyecto Laravel y esquema de datos

Rama: `feat/fase-1-esquema-datos`

### 1.1 Crear el proyecto

```bash
composer create-project laravel/laravel:^12.0 temp-laravel
```

Mover el contenido de `temp-laravel/` a la raíz (conservando `/legacy` y el `.git`), y aplicar la guía de la cátedra para reemplazar Tailwind por Bootstrap.

> **Atención.** Si el starter kit trae componentes Flux, están construidos sobre Tailwind y se rompen al quitarlo. Reemplazar el layout por uno propio con Bootstrap antes de escribir cualquier vista.

### 1.2 Configurar el entorno

`.env`:

```ini
APP_NAME="Sistema de Gestión"
APP_LOCALE=es
APP_TIMEZONE=America/Argentina/Buenos_Aires

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gestion
DB_USERNAME=root
DB_PASSWORD=
```

Actualizar `.env.example` con las mismas claves y **sin valores reales**.

### 1.3 Orden de las migraciones

Las claves foráneas exigen este orden:

```
roles → permisos → rol_permiso → users → empleados → clientes → direcciones
categorias → marcas → proveedores → productos → movimientos_stock
ordenes_compra → orden_compra_lineas
ventas → venta_lineas → pagos
```

### 1.4 Migraciones de referencia

**Roles y permisos**

```php
Schema::create('roles', function (Blueprint $table) {
    $table->id();
    $table->string('nombre', 50)->unique();
    $table->string('descripcion', 150)->nullable();
    $table->enum('ambito', ['gestion', 'tienda']);
    $table->boolean('es_sistema')->default(false);
    $table->timestamps();
});

Schema::create('permisos', function (Blueprint $table) {
    $table->id();
    $table->string('clave', 60)->unique();   // venta.anular
    $table->string('modulo', 40);            // agrupador para la pantalla
    $table->string('descripcion', 150);
    $table->timestamps();
});

Schema::create('rol_permiso', function (Blueprint $table) {
    $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
    $table->foreignId('permiso_id')->constrained('permisos')->cascadeOnDelete();
    $table->primary(['rol_id', 'permiso_id']);
});
```

**Productos** — nótese `decimal` en todo importe, nunca `float`:

```php
Schema::create('productos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('categoria_id')->constrained('categorias');
    $table->foreignId('marca_id')->nullable()->constrained('marcas');
    $table->foreignId('proveedor_id')->nullable()->constrained('proveedores');
    $table->string('codigo', 30)->unique();
    $table->string('nombre', 150);
    $table->text('descripcion')->nullable();
    $table->json('imagenes')->nullable();

    $table->decimal('precio_lista', 12, 2);
    $table->decimal('precio_contado', 12, 2);
    $table->decimal('alicuota_iva', 4, 2)->default(21.00);
    $table->decimal('costo_promedio', 12, 2)->default(0);

    $table->integer('stock')->default(0);
    $table->integer('stock_reservado')->default(0);
    $table->integer('stock_minimo')->default(0);
    $table->integer('cantidad_reposicion')->default(0);
    $table->integer('peso_gramos')->nullable();

    $table->boolean('destacado')->default(false);
    $table->boolean('activo')->default(true);
    $table->timestamps();

    $table->index(['activo', 'categoria_id']);
});
```

**Ventas** — el `ENUM` declara los diez estados aunque la Etapa 1 use cuatro. Cambiarlo después reescribe la tabla:

```php
Schema::create('ventas', function (Blueprint $table) {
    $table->id();
    $table->enum('canal', ['mostrador', 'online'])->default('mostrador');
    $table->foreignId('cliente_id')->nullable()->constrained('clientes');
    $table->foreignId('usuario_id')->nullable()->constrained('users');

    $table->enum('estado', [
        'presupuesto', 'pendiente_pago', 'pagada', 'en_preparacion',
        'despachada', 'lista_retiro', 'entregada', 'cancelada',
        'devuelta_parcial', 'devuelta',
    ])->default('presupuesto');

    $table->enum('modo_entrega', ['retiro', 'envio'])->default('retiro');

    $table->decimal('subtotal', 12, 2)->default(0);
    $table->decimal('descuento', 12, 2)->default(0);
    $table->decimal('costo_envio', 12, 2)->default(0);
    $table->decimal('total', 12, 2)->default(0);
    $table->string('observaciones', 255)->nullable();
    $table->timestamps();

    $table->index(['estado', 'created_at']);
    $table->index('usuario_id');
});
```

> `vale_id` **no** se agrega ahora: la tabla `vales` es de la Etapa 2 y una clave foránea no puede apuntar a algo inexistente. Se sumará con un `ADD COLUMN` nullable.

**Pagos** — con las columnas de Mercado Pago desde ahora, nulas hasta la Etapa 2:

```php
Schema::create('pagos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
    $table->enum('metodo', ['efectivo', 'transferencia', 'qr', 'mercadopago']);
    $table->decimal('monto', 12, 2);

    // Etapa 2: quedan nulas, pero la tabla ya no se toca
    $table->decimal('comision', 12, 2)->nullable();
    $table->decimal('neto_acreditado', 12, 2)->nullable();
    $table->unsignedTinyInteger('cuotas')->nullable();
    $table->string('mp_payment_id', 50)->nullable()->unique();  // idempotencia
    $table->string('mp_status', 30)->nullable();

    $table->foreignId('usuario_id')->nullable()->constrained('users');
    $table->timestamp('fecha');
    $table->timestamps();
});
```

**Venta_lineas** — todo congelado:

```php
Schema::create('venta_lineas', function (Blueprint $table) {
    $table->id();
    $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
    $table->foreignId('producto_id')->constrained('productos');
    $table->string('descripcion', 150);
    $table->unsignedInteger('cantidad');
    $table->unsignedInteger('cantidad_devuelta')->default(0);
    $table->decimal('precio_unitario', 12, 2);   // congelado
    $table->decimal('alicuota_iva', 4, 2);       // congelada
    $table->decimal('costo_unitario', 12, 2);    // congelado, para el margen
    $table->decimal('neto', 12, 2);
    $table->decimal('iva', 12, 2);
    $table->decimal('total', 12, 2);
    $table->timestamps();
});
```

**Movimientos de stock** — el kardex:

```php
Schema::create('movimientos_stock', function (Blueprint $table) {
    $table->id();
    $table->foreignId('producto_id')->constrained('productos');
    $table->enum('tipo', ['venta', 'devolucion', 'compra', 'ajuste']);
    $table->integer('cantidad');              // con signo
    $table->integer('stock_resultante');
    $table->nullableMorphs('origen');         // venta, orden de compra, etc.
    $table->foreignId('usuario_id')->nullable()->constrained('users');
    $table->string('motivo', 255)->nullable();
    $table->timestamps();

    $table->index(['producto_id', 'created_at']);
});
```

### 1.5 Seeders

`database/seeders/RolPermisoSeeder.php` — la fuente de verdad de qué puede hacer cada rol:

```php
public function run(): void
{
    $permisos = [
        'categoria' => ['ver', 'crear', 'editar', 'eliminar'],
        'marca'     => ['ver', 'crear', 'editar', 'eliminar'],
        'producto'  => ['ver', 'crear', 'editar', 'eliminar'],
        'usuario'   => ['ver', 'crear', 'editar', 'eliminar', 'cambiar_rol'],
        'cliente'   => ['ver', 'crear', 'editar', 'eliminar'],
        'proveedor' => ['ver', 'crear', 'editar', 'eliminar'],
        'compra'    => ['ver', 'crear', 'editar', 'aprobar', 'recibir'],
        'venta'     => ['ver', 'crear', 'editar', 'cobrar', 'anular'],
        'stock'     => ['ver', 'ajustar'],
        'rol'       => ['ver', 'editar'],
        'panel'     => ['ver_propio', 'ver_global'],
    ];

    foreach ($permisos as $modulo => $acciones) {
        foreach ($acciones as $accion) {
            Permiso::create([
                'clave'       => "$modulo.$accion",
                'modulo'      => $modulo,
                'descripcion' => ucfirst(str_replace('_', ' ', $accion))." $modulo",
            ]);
        }
    }

    $roles = [
        'Administrador'  => ['ambito' => 'gestion', 'permisos' => '*'],
        'Administrativo' => ['ambito' => 'gestion', 'permisos' => [
            'categoria.*', 'marca.*', 'producto.*', 'cliente.*', 'proveedor.*',
            'compra.*', 'stock.*', 'venta.ver', 'panel.ver_global',
        ]],
        'Vendedor' => ['ambito' => 'gestion', 'permisos' => [
            'categoria.ver', 'producto.ver', 'cliente.ver', 'cliente.crear',
            'venta.ver', 'venta.crear', 'venta.editar', 'panel.ver_propio',
        ]],
        'Cajero' => ['ambito' => 'gestion', 'permisos' => [
            'producto.ver', 'venta.ver', 'venta.cobrar', 'panel.ver_propio',
        ]],
        'Cliente' => ['ambito' => 'tienda', 'permisos' => []],
    ];

    // ... crear roles con es_sistema = true y asociar permisos
}
```

> **El rol Cliente no tiene permisos de gestión.** No es un olvido: su ámbito es `tienda` y en la Etapa 1 no hay tienda. Se crea ahora para que el modelo esté completo.

### 1.6 Verificación

```bash
php artisan migrate:fresh --seed
php artisan migrate:rollback --step=17   # el rollback también debe funcionar
```

---

## Fase 2 — Autenticación, roles y permisos

Rama: `feat/fase-2-auth-permisos`

### 2.1 Modelo User

```php
class User extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = ['nombre', 'apellido', 'email', 'password', 'rol_id', 'activo'];

    // Cierra el hallazgo C-1: el hash nunca sale en una respuesta
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'activo'   => 'boolean',
        ];
    }

    public function rol(): BelongsTo      { return $this->belongsTo(Rol::class); }
    public function empleado(): HasOne    { return $this->hasOne(Empleado::class); }
    public function cliente(): HasOne     { return $this->hasOne(Cliente::class); }

    /** Claves de permiso del rol, cacheadas. */
    public function permisos(): Collection
    {
        return Cache::remember(
            "permisos.rol.{$this->rol_id}",
            now()->addHour(),
            fn () => $this->rol->permisos()->pluck('clave')
        );
    }

    public function tienePermiso(string $clave): bool
    {
        return $this->permisos()->contains($clave);
    }

    public function esDeGestion(): bool
    {
        return $this->rol->ambito === 'gestion';
    }
}
```

### 2.2 Resolución de permisos

`app/Providers/AppServiceProvider.php`:

```php
public function boot(): void
{
    // Concede si el rol tiene el permiso; si no, devuelve null y sigue el flujo
    // normal de Gate, que sin regla definida DENIEGA. Deny-by-default.
    Gate::before(function (User $user, string $ability) {
        return $user->tienePermiso($ability) ? true : null;
    });
}
```

> **Esto cierra el hallazgo C-2.** El sistema original hacía `MAPA_PERMISOS[$action] ?? "can_update"`: toda acción no mapeada heredaba el permiso de actualizar, y por eso un vendedor podía anular ventas cobradas. Acá lo que no está asignado, no se concede.

Invalidar la caché cuando cambian los permisos de un rol:

```php
// En RolService, al guardar la asignación
Cache::forget("permisos.rol.{$rol->id}");
```

### 2.3 Middleware de usuario activo

`app/Http/Middleware/VerificarUsuarioActivo.php`:

```php
public function handle(Request $request, Closure $next): Response
{
    if (Auth::check() && ! Auth::user()->activo) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->withErrors(['email' => 'Su cuenta fue deshabilitada.']);
    }

    return $next($request);
}
```

Registrarlo en `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \App\Http\Middleware\VerificarUsuarioActivo::class,
    ]);
})
```

> **Cierra el hallazgo A-5.** En el sistema original el perfil viajaba dentro del JWT y no se revalidaba: deshabilitar a un usuario no lo desconectaba, seguía operando hasta una hora.

### 2.4 Login

```php
class AuthController extends Controller
{
    public function login(LoginRequest $request): RedirectResponse
    {
        $credenciales = $request->validated();

        if (! Auth::attempt(
            ['email' => $credenciales['email'], 'password' => $credenciales['password']],
            $request->boolean('recordarme')
        )) {
            return back()->withErrors(['email' => 'Credenciales inválidas.'])
                         ->onlyInput('email');
        }

        if (! Auth::user()->activo) {
            Auth::logout();
            return back()->withErrors(['email' => 'Su cuenta está deshabilitada.']);
        }

        $request->session()->regenerate();   // previene fijación de sesión

        return redirect()->intended(route('panel'));
    }
}
```

Limitar los intentos en la ruta, que el sistema original no hacía (hallazgo A-6):

```php
Route::post('/login', [AuthController::class, 'login'])
    ->middleware(['guest', 'throttle:5,1'])
    ->name('login.post');
```

### 2.5 Autorización en rutas y vistas

```php
Route::middleware(['auth'])->group(function () {
    Route::get('/productos', [ProductoController::class, 'index'])
        ->middleware('can:producto.ver')->name('productos.index');

    Route::post('/productos', [ProductoController::class, 'store'])
        ->middleware('can:producto.crear')->name('productos.store');

    Route::post('/ventas/{venta}/anular', [VentaController::class, 'anular'])
        ->middleware('can:venta.anular')->name('ventas.anular');
});
```

En Blade, ocultar lo que el usuario no puede hacer:

```blade
@can('producto.crear')
    <a href="{{ route('productos.create') }}" class="btn btn-primary">Nuevo producto</a>
@endcan
```

> El menú **no** se arma comparando el nombre del rol. El frontend original hacía `perfil === 'Administrador'` a mano (hallazgo M-33): renombrar el perfil rompía la interfaz en silencio.

### 2.6 Tests de la fase

```php
test('un vendedor no puede anular una venta', function () {
    $vendedor = User::factory()->conRol('Vendedor')->create();
    $venta    = Venta::factory()->pagada()->create();

    $this->actingAs($vendedor)
         ->post(route('ventas.anular', $venta))
         ->assertForbidden();
});

test('una accion sin permiso definido se deniega', function () {
    $admin = User::factory()->conRol('Administrador')->create();

    expect(Gate::forUser($admin)->allows('modulo.inexistente'))->toBeFalse();
});

test('un usuario deshabilitado es desconectado', function () {
    $user = User::factory()->create(['activo' => true]);
    $this->actingAs($user);

    $user->update(['activo' => false]);

    $this->get(route('panel'))->assertRedirect(route('login'));
});
```

---

## Fase 3 — Catálogo (patrón de referencia)

Rama: `feat/fase-3-catalogo`

Esta fase define el patrón que replican todos los CRUD. Vale la pena hacerlo bien una vez.

### 3.1 Modelo

```php
class Producto extends Model
{
    protected $table = 'productos';   // explícito, no dependemos del pluralizador

    protected $fillable = [
        'categoria_id', 'marca_id', 'proveedor_id', 'codigo', 'nombre',
        'descripcion', 'imagenes', 'precio_lista', 'precio_contado',
        'alicuota_iva', 'stock_minimo', 'cantidad_reposicion',
        'peso_gramos', 'destacado', 'activo',
    ];

    // stock, stock_reservado y costo_promedio NO son asignables en masa:
    // sólo los modifica el servicio de stock, y siempre dejando movimiento
    protected $guarded = ['stock', 'stock_reservado', 'costo_promedio'];

    protected function casts(): array
    {
        return [
            'imagenes'       => 'array',
            'precio_lista'   => 'decimal:2',
            'precio_contado' => 'decimal:2',
            'costo_promedio' => 'decimal:2',
            'destacado'      => 'boolean',
            'activo'         => 'boolean',
        ];
    }

    public function categoria(): BelongsTo { return $this->belongsTo(Categoria::class); }
    public function marca(): BelongsTo     { return $this->belongsTo(Marca::class); }

    public function getStockDisponibleAttribute(): int
    {
        return $this->stock - $this->stock_reservado;
    }

    // --- Filtros ---
    // El sistema original tenía filtros que el controlador enviaba y el DAO
    // ignoraba, y nunca funcionaron (hallazgo A-24). Cada scope tiene su test.

    public function scopeBuscar(Builder $q, ?string $texto): Builder
    {
        return $q->when($texto, fn ($q) => $q->where(fn ($q) =>
            $q->where('nombre', 'like', "%{$texto}%")
              ->orWhere('codigo', 'like', "%{$texto}%")
        ));
    }

    public function scopeDeCategoria(Builder $q, ?int $id): Builder
    {
        return $q->when($id, fn ($q) => $q->where('categoria_id', $id));
    }

    public function scopeStockCritico(Builder $q): Builder
    {
        return $q->whereColumn('stock', '<=', 'stock_minimo');
    }
}
```

### 3.2 Form Request

```php
class ProductoRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('producto')?->id;

        return [
            'codigo'         => ['required', 'string', 'max:30', Rule::unique('productos')->ignore($id)],
            'nombre'         => ['required', 'string', 'max:150'],
            'descripcion'    => ['nullable', 'string'],
            'categoria_id'   => ['required', 'exists:categorias,id'],
            'marca_id'       => ['nullable', 'exists:marcas,id'],
            'proveedor_id'   => ['nullable', 'exists:proveedores,id'],
            'precio_lista'   => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'precio_contado' => ['required', 'numeric', 'min:0', 'lte:precio_lista'],
            'alicuota_iva'   => ['required', Rule::in([0, 10.5, 21])],
            'stock_minimo'   => ['required', 'integer', 'min:0'],
            'cantidad_reposicion' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'precio_contado.lte' => 'El precio de contado no puede superar al de lista.',
        ];
    }
}
```

> **Cierra el hallazgo M-31.** Los DTOs originales vaciaban el valor cuando no validaba: un nombre de 101 caracteres se guardaba como cadena vacía, y un email inválido producía el mensaje "el correo es obligatorio". Una regla de validación rechaza y explica.

### 3.3 Servicio

```php
class ProductoService
{
    public function crear(array $datos): Producto
    {
        return DB::transaction(fn () => Producto::create($datos));
    }

    public function actualizar(Producto $producto, array $datos): Producto
    {
        return DB::transaction(function () use ($producto, $datos) {
            $producto->update($datos);
            return $producto->fresh();
        });
    }

    public function eliminar(Producto $producto): void
    {
        // Baja lógica: un producto vendido no se borra, se desactiva
        if ($producto->ventaLineas()->exists()) {
            $producto->update(['activo' => false]);
            return;
        }

        $producto->delete();
    }
}
```

> El servicio no conoce la petición HTTP ni la sesión. Recibe datos y devuelve modelos. En la Etapa 3 el controlador de la API lo invoca igual.

### 3.4 Controlador

```php
class ProductoController extends Controller
{
    public function __construct(private ProductoService $service) {}

    public function index(Request $request): View
    {
        $productos = Producto::query()
            ->with(['categoria', 'marca'])
            ->buscar($request->query('q'))
            ->deCategoria($request->integer('categoria_id') ?: null)
            ->orderBy('nombre')
            ->paginate(20)          // el original traía la tabla entera (A-25)
            ->withQueryString();

        return view('productos.index', [
            'productos'  => $productos,
            'categorias' => Categoria::orderBy('nombre')->get(),
        ]);
    }

    public function store(ProductoRequest $request): RedirectResponse
    {
        $this->service->crear($request->validated());

        return redirect()->route('productos.index')
                         ->with('exito', 'Producto creado.');
    }

    public function update(ProductoRequest $request, Producto $producto): RedirectResponse
    {
        $this->service->actualizar($producto, $request->validated());

        return redirect()->route('productos.index')
                         ->with('exito', 'Producto actualizado.');
    }
}
```

El controlador sólo traduce entre HTTP y el servicio. Sin reglas de negocio.

### 3.5 Tests

```php
test('el filtro por categoria devuelve solo esa categoria', function () {
    $a = Categoria::factory()->create();
    $b = Categoria::factory()->create();
    Producto::factory()->count(3)->create(['categoria_id' => $a->id]);
    Producto::factory()->count(2)->create(['categoria_id' => $b->id]);

    $this->actingAs(usuarioCon('producto.ver'))
         ->get(route('productos.index', ['categoria_id' => $a->id]))
         ->assertOk()
         ->assertViewHas('productos', fn ($p) => $p->total() === 3);
});

test('rechaza un precio de contado mayor al de lista', function () {
    $this->actingAs(usuarioCon('producto.crear'))
         ->post(route('productos.store'), [
             'precio_lista' => 1000, 'precio_contado' => 1200, /* ... */
         ])
         ->assertSessionHasErrors('precio_contado');
});
```

---

## Fase 4 — Usuarios, clientes y empleados

Rama: `feat/fase-4-personas`

### 4.1 Alta con satélite en la misma transacción

```php
class UsuarioService
{
    public function crear(array $datos): User
    {
        return DB::transaction(function () use ($datos) {
            $rol  = Rol::findOrFail($datos['rol_id']);
            $user = User::create([
                'nombre'   => $datos['nombre'],
                'apellido' => $datos['apellido'],
                'email'    => $datos['email'],
                'password' => $datos['password'],   // el cast 'hashed' lo encripta
                'rol_id'   => $rol->id,
                'activo'   => true,
            ]);

            // Invariante: el satélite lo determina el ámbito del rol
            match ($rol->ambito) {
                'gestion' => $user->empleado()->create($datos['empleado']),
                'tienda'  => $user->cliente()->create($datos['cliente']),
            };

            return $user;
        });
    }
}
```

### 4.2 Cambio de rol como acción separada

```php
class UsuarioController extends Controller
{
    public function cambiarRol(CambiarRolRequest $request, User $usuario): RedirectResponse
    {
        // Nadie cambia su propio rol, ni siquiera un administrador.
        // Evita la escalada de privilegios y el autobloqueo.
        if ($usuario->id === $request->user()->id) {
            return back()->withErrors([
                'rol_id' => 'No puede modificar su propio rol.',
            ]);
        }

        $this->service->cambiarRol($usuario, $request->integer('rol_id'));

        return back()->with('exito', 'Rol actualizado.');
    }
}
```

Ruta con permiso propio:

```php
Route::patch('/usuarios/{usuario}/rol', [UsuarioController::class, 'cambiarRol'])
    ->middleware('can:usuario.cambiar_rol')
    ->name('usuarios.cambiar-rol');
```

> **Cierra el hallazgo C-3.** El sistema original armaba el DTO desde el body con `perfil_id` incluido y no comparaba identidades: cualquiera con permiso de edición podía asignarse el perfil Administrador. Acá el rol no es un campo del formulario de edición sino una acción aparte, con su propio permiso y con la autoasignación bloqueada.

### 4.3 Tests

```php
test('no se puede cambiar el rol propio', function () {
    $admin = User::factory()->conRol('Administrador')->create();

    $this->actingAs($admin)
         ->patch(route('usuarios.cambiar-rol', $admin), ['rol_id' => 1])
         ->assertSessionHasErrors('rol_id');
});

test('el listado de usuarios no expone el hash de contrasena', function () {
    User::factory()->count(3)->create();

    $this->actingAs(usuarioCon('usuario.ver'))
         ->get(route('usuarios.index'))
         ->assertDontSee('$2y$');    // prefijo de bcrypt
});
```

---

## Fase 5 — Proveedores, compras y stock

Rama: `feat/fase-5-compras-stock`

### 5.1 Servicio de stock (kardex)

Toda variación de stock pasa por acá. Ningún otro código toca la columna.

```php
class StockService
{
    /**
     * @throws StockInsuficienteException
     */
    public function descontar(Producto $producto, int $cantidad, Model $origen, ?User $usuario = null): void
    {
        DB::transaction(function () use ($producto, $cantidad, $origen, $usuario) {
            // Bloqueo de fila: sin esto, dos ventas simultáneas del último
            // artículo dejan el stock en negativo
            $p = Producto::lockForUpdate()->findOrFail($producto->id);

            if ($p->stock < $cantidad) {
                throw new StockInsuficienteException(
                    "Stock insuficiente de {$p->nombre}: hay {$p->stock}, se piden {$cantidad}."
                );
            }

            $p->decrement('stock', $cantidad);
            $this->registrar($p, 'venta', -$cantidad, $origen, $usuario);
        });
    }

    public function reponer(Producto $producto, int $cantidad, string $tipo, Model $origen, ?User $usuario = null): void
    {
        DB::transaction(function () use ($producto, $cantidad, $tipo, $origen, $usuario) {
            $p = Producto::lockForUpdate()->findOrFail($producto->id);
            $p->increment('stock', $cantidad);
            $this->registrar($p, $tipo, $cantidad, $origen, $usuario);
        });
    }

    /** Recepción de mercadería: ingresa stock y recalcula el costo promedio ponderado. */
    public function recibirCompra(Producto $producto, int $cantidad, float $costoUnitario, OrdenCompra $orden, User $usuario): void
    {
        DB::transaction(function () use ($producto, $cantidad, $costoUnitario, $orden, $usuario) {
            $p = Producto::lockForUpdate()->findOrFail($producto->id);

            $stockPrevio = $p->stock;
            $costoPrevio = (float) $p->costo_promedio;

            $nuevoCosto = ($stockPrevio + $cantidad) > 0
                ? (($stockPrevio * $costoPrevio) + ($cantidad * $costoUnitario)) / ($stockPrevio + $cantidad)
                : $costoUnitario;

            $p->stock = $stockPrevio + $cantidad;
            $p->costo_promedio = round($nuevoCosto, 2);
            $p->save();

            $this->registrar($p, 'compra', $cantidad, $orden, $usuario);
        });
    }

    private function registrar(Producto $p, string $tipo, int $cantidad, Model $origen, ?User $usuario): void
    {
        MovimientoStock::create([
            'producto_id'      => $p->id,
            'tipo'             => $tipo,
            'cantidad'         => $cantidad,
            'stock_resultante' => $p->stock,
            'origen_type'      => $origen::class,
            'origen_id'        => $origen->id,
            'usuario_id'       => $usuario?->id,
        ]);
    }
}
```

### 5.2 Reposición automática

`app/Console/Commands/GenerarOrdenesReposicion.php`:

```php
public function handle(): int
{
    $criticos = Producto::query()
        ->where('activo', true)
        ->whereNotNull('proveedor_id')
        ->whereColumn('stock', '<=', 'stock_minimo')
        ->where('cantidad_reposicion', '>', 0)
        // No duplicar: si ya hay una orden abierta con este producto, se omite
        ->whereDoesntHave('ordenCompraLineas.ordenCompra', fn ($q) =>
            $q->whereNotIn('estado', ['recibida', 'cancelada'])
        )
        ->get()
        ->groupBy('proveedor_id');

    foreach ($criticos as $proveedorId => $productos) {
        $this->service->generarBorrador($proveedorId, $productos);
    }

    $this->info("Órdenes generadas: {$criticos->count()}");

    return self::SUCCESS;
}
```

Programarla en `routes/console.php`:

```php
Schedule::command('compras:generar-reposicion')->dailyAt('07:00');
```

> **La orden se genera en borrador y no se envía sola.** Un ajuste de stock mal cargado dispararía compras que nadie pidió. El administrativo aprueba, y recién ahí sale.

### 5.3 Envío según el canal del proveedor

```php
public function enviar(OrdenCompra $orden): void
{
    $pdf = Pdf::loadView('compras.pdf', ['orden' => $orden]);

    match ($orden->proveedor->canal_pedido) {
        // Se manda solo
        'email' => Mail::to($orden->proveedor->email)
                       ->send(new OrdenCompraMail($orden, $pdf->output())),

        // El administrativo la carga en el portal del proveedor y marca enviada
        'portal_externo', 'manual' => null,
    };

    $orden->update(['estado' => 'enviada', 'fecha_envio' => now()]);
}
```

---

## Fase 6 — Ventas y pagos

Rama: `feat/fase-6-ventas`

La fase más importante y la que más se aparta del original.

### 6.1 Máquina de estados

```php
class MaquinaEstadosVenta
{
    /** Transiciones permitidas. Lo que no está acá, no se puede. */
    private const TRANSICIONES = [
        'presupuesto'      => ['pagada', 'cancelada'],
        'pendiente_pago'   => ['pagada', 'cancelada'],          // Etapa 2
        'pagada'           => ['en_preparacion', 'entregada', 'devuelta_parcial', 'devuelta'],
        'en_preparacion'   => ['despachada', 'lista_retiro'],   // Etapa 2
        'despachada'       => ['entregada'],
        'lista_retiro'     => ['entregada'],
        'entregada'        => ['devuelta_parcial', 'devuelta'],
        'devuelta_parcial' => ['devuelta_parcial', 'devuelta'],
        'cancelada'        => [],
        'devuelta'         => [],
    ];

    public static function puede(string $desde, string $hacia): bool
    {
        return in_array($hacia, self::TRANSICIONES[$desde] ?? [], true);
    }

    public static function validar(string $desde, string $hacia): void
    {
        if (! self::puede($desde, $hacia)) {
            throw new TransicionInvalidaException(
                "No se puede pasar de '{$desde}' a '{$hacia}'."
            );
        }
    }
}
```

> **Cierra el hallazgo C-9.** El sistema original permitía cualquier transición. `presupuesto → cobrada` marcaba la venta como cobrada **sin descontar stock**, porque la cadena de `if` no contemplaba ese caso y simplemente actualizaba la columna. Una tabla de transiciones no deja huecos.

### 6.2 Confirmar la venta

```php
class VentaService
{
    public function __construct(private StockService $stock) {}

    public function cambiarEstado(Venta $venta, string $nuevoEstado, User $usuario): Venta
    {
        return DB::transaction(function () use ($venta, $nuevoEstado, $usuario) {
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            MaquinaEstadosVenta::validar($v->estado, $nuevoEstado);

            // El stock se descuenta una sola vez, al entrar en 'pagada'
            if ($nuevoEstado === 'pagada') {
                foreach ($v->lineas as $linea) {
                    $this->stock->descontar($linea->producto, $linea->cantidad, $v, $usuario);
                }
            }

            // Anular repone lo descontado
            if (in_array($nuevoEstado, ['cancelada', 'devuelta'], true)
                && in_array($v->estado, ['pagada', 'entregada'], true)) {
                foreach ($v->lineas as $linea) {
                    $this->stock->reponer($linea->producto, $linea->cantidad, 'devolucion', $v, $usuario);
                }
            }

            $v->update(['estado' => $nuevoEstado]);

            return $v->fresh();
        });
    }
}
```

### 6.3 Precios calculados en el servidor

```php
private function armarLineas(Venta $venta, array $items): void
{
    $subtotal = 0;

    foreach ($items as $item) {
        // El precio NUNCA se toma del cliente: se lee de la base.
        // Esto el sistema original ya lo hacía bien y se conserva.
        $producto = Producto::findOrFail($item['producto_id']);

        $precio = $item['modo_pago'] === 'contado'
            ? (float) $producto->precio_contado
            : (float) $producto->precio_lista;

        $total = round($precio * $item['cantidad'], 2);
        $neto  = round($total / (1 + ((float) $producto->alicuota_iva / 100)), 2);

        $venta->lineas()->create([
            'producto_id'     => $producto->id,
            'descripcion'     => $producto->nombre,
            'cantidad'        => $item['cantidad'],
            'precio_unitario' => $precio,                       // congelado
            'alicuota_iva'    => $producto->alicuota_iva,       // congelada
            'costo_unitario'  => $producto->costo_promedio,     // congelado, para el margen
            'neto'            => $neto,
            'iva'             => round($total - $neto, 2),
            'total'           => $total,
        ]);

        $subtotal += $total;
    }

    $venta->update(['subtotal' => $subtotal, 'total' => $subtotal]);
}
```

### 6.4 Registro de pagos

```php
public function registrarPago(Venta $venta, array $datos, User $usuario): Pago
{
    return DB::transaction(function () use ($venta, $datos, $usuario) {
        // Se bloquea PRIMERO y se valida DESPUÉS, dentro de la transacción.
        // El original validaba el monto en el servicio, fuera de la
        // transacción, y bloqueaba recién al insertar: dos pagos
        // concurrentes pasaban ambos la validación (hallazgo C-10).
        $v = Venta::lockForUpdate()->findOrFail($venta->id);

        $pagado = $v->pagos()->sum('monto');
        $saldo  = round((float) $v->total - (float) $pagado, 2);

        if ($datos['monto'] > $saldo) {
            throw new ValidationException(
                "El monto ({$datos['monto']}) supera el saldo pendiente ({$saldo})."
            );
        }

        $pago = $v->pagos()->create([
            'metodo'     => $datos['metodo'],
            'monto'      => $datos['monto'],
            'usuario_id' => $usuario->id,
            'fecha'      => now(),
        ]);

        // Saldada: pasa a pagada por la máquina de estados
        if (round($saldo - $datos['monto'], 2) <= 0.001) {
            $this->cambiarEstado($v, 'pagada', $usuario);
        }

        return $pago;
    });
}
```

### 6.5 Tests

Los más importantes de toda la etapa:

```php
test('confirmar una venta descuenta el stock una sola vez', function () {
    $producto = Producto::factory()->create(['stock' => 10]);
    $venta    = Venta::factory()->presupuesto()->conLinea($producto, 3)->create();

    app(VentaService::class)->cambiarEstado($venta, 'pagada', $admin = admin());

    expect($producto->fresh()->stock)->toBe(7);
    expect(MovimientoStock::where('producto_id', $producto->id)->count())->toBe(1);
});

test('rechaza una transicion no declarada', function () {
    $venta = Venta::factory()->cancelada()->create();

    expect(fn () => app(VentaService::class)->cambiarEstado($venta, 'pagada', admin()))
        ->toThrow(TransicionInvalidaException::class);
});

test('no se puede vender mas stock del disponible', function () {
    $producto = Producto::factory()->create(['stock' => 2]);
    $venta    = Venta::factory()->presupuesto()->conLinea($producto, 5)->create();

    expect(fn () => app(VentaService::class)->cambiarEstado($venta, 'pagada', admin()))
        ->toThrow(StockInsuficienteException::class);

    expect($producto->fresh()->stock)->toBe(2);   // la transacción revirtió
});

test('un pago no puede superar el saldo pendiente', function () {
    $venta = Venta::factory()->presupuesto()->create(['total' => 1000]);

    expect(fn () => app(VentaService::class)
        ->registrarPago($venta, ['metodo' => 'efectivo', 'monto' => 1500], admin()))
        ->toThrow(ValidationException::class);
});
```

---

## Fase 7 — Panel de métricas y PDF

Rama: `feat/fase-7-panel-pdf`

### 7.1 Métricas agregadas en la base

```php
class PanelService
{
    /** Vendedor: sólo lo suyo. */
    public function paraVendedor(User $usuario, CarbonPeriod $periodo): array
    {
        $ventas = Venta::query()
            ->where('usuario_id', $usuario->id)
            ->whereIn('estado', ['pagada', 'entregada'])
            ->whereBetween('created_at', [$periodo->start, $periodo->end]);

        return [
            'cantidad'       => (clone $ventas)->count(),
            'facturado'      => (clone $ventas)->sum('total'),
            'ticketPromedio' => (clone $ventas)->avg('total') ?? 0,
        ];
    }

    /** Administrativo: el conjunto. */
    public function paraAdministrativo(CarbonPeriod $periodo): array
    {
        return [
            'ingresos' => Venta::whereIn('estado', ['pagada', 'entregada'])
                ->whereBetween('created_at', [$periodo->start, $periodo->end])
                ->sum('total'),

            // Margen sobre cantidad efectivamente vendida (descuenta devoluciones)
            'margen' => VentaLinea::query()
                ->whereHas('venta', fn ($q) => $q
                    ->whereIn('estado', ['pagada', 'entregada'])
                    ->whereBetween('created_at', [$periodo->start, $periodo->end]))
                ->selectRaw('SUM((cantidad - cantidad_devuelta) * (precio_unitario - costo_unitario)) as m')
                ->value('m') ?? 0,

            'rankingVendedores' => Venta::query()
                ->whereIn('estado', ['pagada', 'entregada'])
                ->whereBetween('created_at', [$periodo->start, $periodo->end])
                ->selectRaw('usuario_id, COUNT(*) as cantidad, SUM(total) as facturado')
                ->groupBy('usuario_id')
                ->orderByDesc('facturado')
                ->with('usuario:id,nombre,apellido')
                ->get(),

            'stockCritico' => Producto::stockCritico()->where('activo', true)->count(),

            'comprasPendientes' => OrdenCompra::whereNotIn('estado', ['recibida', 'cancelada'])->count(),
        ];
    }
}
```

> **Cierra el hallazgo A-26.** El panel original descargaba categorías, productos, ventas y usuarios completos al navegador y contaba con `.filter()`. Acá cada número es una consulta agregada; nunca viajan filas completas.

### 7.2 Exportación a PDF

```bash
composer require barryvdh/laravel-dompdf
```

```php
public function exportarVenta(Venta $venta): Response
{
    $pdf = Pdf::loadView('ventas.pdf', [
        'venta'         => $venta->load('lineas.producto', 'pagos', 'cliente', 'usuario'),
        'esPresupuesto' => $venta->estado === 'presupuesto',
        'validoHasta'   => $venta->created_at->addDays(15),
    ]);

    return $pdf->download("venta-{$venta->id}.pdf");
}
```

El PDF pasa al servidor porque en Blade no hay jsPDF, y porque en la Etapa 2 las facturas necesitan CAE y código QR, que no pueden generarse en el cliente.

---

## Fase 8 — Cierre

Rama: `feat/fase-8-cierre`

### 8.1 Repaso de seguridad

Verificar contra la auditoría, hallazgo por hallazgo:

| Hallazgo | Verificación |
|---|---|
| C-1 hashes expuestos | `$hidden` en `User`; ninguna vista los muestra |
| C-2 fallback permisivo | `Gate::before` deniega por defecto; test que lo comprueba |
| C-3 escalada de privilegios | Cambio de rol separado, con autoasignación bloqueada |
| C-9 transiciones arbitrarias | Máquina de estados con test de transición inválida |
| C-10 sobrepago | Bloqueo antes de validar, dentro de la transacción |
| A-4 secretos versionados | `.env` ignorado; `git log -p` sin credenciales |
| A-6 sin límite de intentos | `throttle:5,1` en el login |
| A-17 dinero en `float` | Todas las columnas monetarias en `decimal(12,2)` |
| A-25 sin paginación | Todos los listados paginados |
| A-26 panel en el cliente | Métricas agregadas en la base |

Buscar secretos en todo el historial, no sólo en el estado actual:

```bash
git log -p --all | grep -iE "password.*=.*['\"][^'\"]{8,}|secret.*=.*['\"]" | head
```

### 8.2 README

Secciones que pide la consigna:

```markdown
# Sistema de Gestión — Tienda Informática

## Propósito
## Alcance (actual y previsto por etapa)
## Arquitectura
## Modelo de datos
## Requisitos
## Instalación
## Configuración
## Ejecución
## Pruebas
## Seguridad
## Estructura del proyecto
## Migración desde el sistema original
```

Se actualiza en el mismo commit que introduce el cambio que describe.

### 8.3 Entrega

```bash
git tag -a v1.0-etapa1 -m "Entrega Etapa 1: backend migrado a Laravel"
git push origin --tags
```

---

## Anexo — Estructura del proyecto

```
app/
├── Console/Commands/          GenerarOrdenesReposicion
├── Exceptions/                StockInsuficiente, TransicionInvalida
├── Http/
│   ├── Controllers/           traducen HTTP ↔ servicio, sin lógica
│   ├── Middleware/            VerificarUsuarioActivo
│   └── Requests/              validación de entrada
├── Models/                    Eloquent + scopes de filtro
├── Services/                  LÓGICA DE NEGOCIO (reutilizada por la API en Etapa 3)
│   ├── StockService
│   ├── VentaService
│   ├── CompraService
│   └── PanelService
└── Support/
    └── MaquinaEstadosVenta

database/
├── migrations/                17 tablas
├── seeders/                   roles, permisos, datos ficticios
└── factories/

resources/views/
├── layouts/app.blade.php      Bootstrap
├── components/                tabla, paginador, alertas
└── {modulo}/                  index, create, edit, show, pdf

tests/
├── Feature/                   rutas, permisos, flujos completos
└── Unit/                      máquina de estados, cálculos
```

**La regla que sostiene todo:** la lógica vive en `app/Services`. Los controladores traducen. Cuando en la Etapa 3 haya que exponer una API, los controladores nuevos llaman a los mismos servicios y devuelven JSON en vez de vistas.
