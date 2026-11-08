<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validación del formulario de personal. Sirve para el alta y para la edición.
 *
 * Cuatro decisiones que vienen de la auditoría y del alcance:
 *
 *   1. En la edición, `rol_id` está PROHIBIDO (C-3). El UserController original
 *      construía el DTO desde el body, que incluía `perfil_id`, y no comparaba
 *      identidades: cualquiera con permiso de edición se asignaba el perfil
 *      Administrador. El cambio de rol es una acción aparte, con su propio
 *      permiso y con la autoasignación bloqueada.
 *
 *   2. En el alta, el rol tiene que ser de ámbito GESTIÓN. Esta pantalla
 *      administra al personal; una cuenta de la tienda no se crea desde acá.
 *      La capacidad existe en UsuarioService y está probada —es la base del
 *      registro de la Etapa 2— pero ninguna pantalla de la Etapa 1 la ofrece,
 *      porque en la Etapa 1 no hay tienda.
 *
 *   3. Rechaza, no corrige (M-31). `setNombre()` convertía en cadena vacía todo
 *      nombre de más de 100 caracteres y `setCorreo()` hacía lo mismo con un
 *      mail inválido; el servicio informaba después "el correo es obligatorio",
 *      que no describe el problema.
 *
 *   4. Como el rol es siempre de gestión, el satélite es siempre `empleados`.
 *      Las reglas fiscales que antes vivían acá se fueron a
 *      App\Support\ReglasFiscales, donde las usa ClienteRequest.
 *
 * Métodos:
 *   authorize()             true; el permiso ya lo exige la ruta
 *   prepareForValidation()  normaliza el correo y el checkbox
 *   rules()                 cuenta + datos laborales
 *   messages() / attributes()
 */
class PersonalRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_if($this->usuario() && ! $this->usuario()->esDeGestion(), 404);

        return true;   // la ruta exige usuario.crear o usuario.editar
    }

    /** La persona que se edita, o null en el alta. */
    private function usuario(): ?User
    {
        return $this->route('usuario');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
            'email'  => is_string($this->input('email'))
                ? Str::lower(trim($this->input('email')))
                : $this->input('email'),
        ]);
    }

    public function rules(): array
    {
        $usuario = $this->usuario();
        $esAlta  = $usuario === null;

        $reglas = [
            'nombre'   => ['required', 'string', 'min:2', 'max:100'],
            'apellido' => ['required', 'string', 'min:2', 'max:100'],
            'email'    => [
                'required', 'string', 'email', 'max:150',
                Rule::unique('users', 'email')->ignore($usuario?->id),
            ],
            'activo'   => [
                'required', 'boolean',
                // La sesión sí entra acá: un Form Request es parte de la capa
                // HTTP. Desactivar la propia cuenta es autoexpulsarse, porque
                // VerificarUsuarioActivo revalida en cada request (A-5).
                Rule::prohibitedIf(fn () => $usuario !== null
                    && $usuario->is($this->user())
                    && ! $this->boolean('activo')),
            ],
        ];

        if ($esAlta) {
            // El ->where('ambito', 'gestion') es lo que impide crear una cuenta
            // de la tienda desde la pantalla del personal.
            $reglas['rol_id']   = [
                'required', 'integer',
                Rule::exists('roles', 'id')->where('ambito', 'gestion'),
            ];
            $reglas['password'] = ['required', 'confirmed', Password::min(8)->letters()->numbers()];
        } else {
            // C-3. No es un olvido: el rol se cambia en otra pantalla, con otro
            // permiso. Declararlo prohibido hace que el intento se vea, en vez
            // de ignorarse en silencio.
            $reglas['rol_id']   = ['prohibited'];
            $reglas['password'] = ['prohibited'];
        }

        // El rol es siempre de gestión, así que el satélite es siempre
        // `empleados`. Ya no hace falta decidirlo leyendo el ámbito.
        return array_merge($reglas, [
            'empleado'        => ['required', 'array'],
            'empleado.legajo' => [
                'required', 'string', 'max:20',
                Rule::unique('empleados', 'legajo')->ignore($usuario?->empleado?->id),
            ],
            'empleado.dni' => [
                'required', 'digits_between:6,9',
                Rule::unique('empleados', 'dni')->ignore($usuario?->empleado?->id),
            ],
            'empleado.telefono' => ['nullable', 'string', 'max:30'],

            'empleado.fecha_ingreso' => [
                'required', 'date',
                'after_or_equal:1950-01-01',
                'before_or_equal:today',
            ],

            // En el alta no existe: nadie incorpora a alguien que ya se fue.
            'empleado.fecha_baja' => $esAlta
                ? ['prohibited']
                : ['nullable', 'date', 'after_or_equal:empleado.fecha_ingreso', 'before_or_equal:today'],
        ]);
    }

    public function messages(): array
    {
        return [
            'email.unique'    => 'Ya hay una cuenta con ese correo.',
            'rol_id.required' => 'Elegí un rol para la persona.',
            'rol_id.exists'   => 'Ese rol no existe o no es un rol del personal.',
            'rol_id.prohibited'   => 'El rol no se cambia desde esta pantalla. Usá la acción «Cambiar rol».',
            'password.prohibited' => 'La contraseña no se cambia desde esta pantalla.',
            'password.confirmed'  => 'Las dos contraseñas no coinciden.',

            'empleado.legajo.required' => 'El legajo es obligatorio.',
            'empleado.legajo.unique'   => 'Ese legajo ya está asignado a otra persona.',
            'empleado.dni.digits_between' => 'El DNI se escribe sin puntos, entre 6 y 9 dígitos.',
            'empleado.dni.unique'         => 'Ya hay un empleado registrado con ese DNI.',
            'empleado.fecha_ingreso.before_or_equal' => 'La fecha de ingreso no puede ser futura.',
            'empleado.fecha_baja.after_or_equal'     => 'La fecha de baja no puede ser anterior al ingreso.',
            'empleado.fecha_baja.before_or_equal'    => 'La fecha de baja no puede ser futura.',
            'empleado.fecha_baja.prohibited'         => 'No se puede dar de alta a alguien que ya no trabaja acá.',

            'activo.prohibited' => 'No podés quitarte el acceso a vos mismo. Pedíselo a otra persona con permiso para editar usuarios.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nombre'   => 'nombre',
            'apellido' => 'apellido',
            'email'    => 'correo',
            'password' => 'contraseña',
            'rol_id'   => 'rol',
            'activo'   => 'acceso al sistema',

            'empleado.legajo'        => 'legajo',
            'empleado.dni'           => 'DNI',
            'empleado.telefono'      => 'teléfono',
            'empleado.fecha_ingreso' => 'fecha de ingreso',
            'empleado.fecha_baja'    => 'fecha de baja',
        ];
    }
}