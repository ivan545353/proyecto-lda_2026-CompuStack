<?php

namespace App\Http\Requests;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

/**
 * Validación del formulario de usuarios. Sirve para el alta y para la edición.
 *
 * Tres decisiones que vienen de la auditoría:
 *
 *   1. En la edición, `rol_id` está PROHIBIDO (C-3). El UserController
 *      original construía el DTO desde el body, que incluía `perfil_id`, y no
 *      comparaba identidades: cualquiera con permiso de edición se asignaba el
 *      perfil Administrador. El cambio de rol es una acción aparte, con su
 *      propio permiso y con la autoasignación bloqueada.
 *
 *   2. Rechaza, no corrige (M-31). `setNombre()` convertía en cadena vacía
 *      todo nombre de más de 100 caracteres y `setCorreo()` hacía lo mismo con
 *      un mail inválido; el servicio informaba después "el correo es
 *      obligatorio", que no describe el problema. Acá se rechaza la petición y
 *      el usuario conserva lo que escribió.
 *
 *   3. Qué campos del satélite se exigen lo decide `roles.ambito`, nunca el
 *      nombre del rol (M-33).
 *
 * Métodos:
 *   authorize()             true; el permiso ya lo exige la ruta
 *   prepareForValidation()  normaliza correo, documentos y el checkbox
 *   rules()                 reglas comunes + las del satélite que corresponda
 *   messages() / attributes()
 */
class UsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige usuario.crear o usuario.editar
    }

    /** El usuario que se edita, o null en el alta. */
    private function usuario(): ?User
    {
        return $this->route('usuario');
    }

    /**
     * El rol que determina qué satélite corresponde.
     *
     * En el alta viene del formulario. En la edición es el que el usuario ya
     * tiene, porque desde esta pantalla el rol no se cambia: si lo leyéramos
     * del body volveríamos a abrir C-3 por la puerta de atrás.
     */
    private function rol(): ?Rol
    {
        return $this->usuario()?->rol ?? Rol::find($this->input('rol_id'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
            'email'  => is_string($this->input('email'))
                ? Str::lower(trim($this->input('email')))
                : $this->input('email'),
        ]);

        // Normaliza el formato del documento, no su contenido: saca los
        // separadores con los que se escribe un CUIT (30-71555888-1) y deja
        // todo lo demás intacto. Si alguien escribe letras, siguen ahí y la
        // regla las rechaza con un mensaje que lo dice. Limpiarlas sería
        // repetir M-31: "30-ABC" se convertiría en "30" y se guardaría.
        if (is_string($this->input('cliente.nro_doc'))) {
            $this->merge(['cliente' => array_merge($this->input('cliente'), [
                'nro_doc' => preg_replace('/[\s.\-]/', '', $this->input('cliente.nro_doc')),
            ])]);
        }
    }

    public function rules(): array
    {
        $usuario = $this->usuario();
        $rol     = $this->rol();
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
                Rule::prohibitedIf(fn () => $usuario !== null
                    && $usuario->is($this->user())
                    && ! $this->boolean('activo')),
            ],
        ];

        if ($esAlta) {
            $reglas['rol_id']   = ['required', 'integer', Rule::exists('roles', 'id')];
            $reglas['password'] = ['required', 'confirmed', Password::min(8)->letters()->numbers()];
        } else {
            // C-3. No es un olvido: el rol se cambia en otra pantalla, con otro
            // permiso. Declararlo prohibido hace que el intento se vea, en vez
            // de ignorarse en silencio.
            $reglas['rol_id']   = ['prohibited'];
            $reglas['password'] = ['prohibited'];
        }

        return match ($rol?->ambito) {
            'gestion' => array_merge($reglas, $this->reglasDeEmpleado($usuario?->empleado?->id, $esAlta)),
            'tienda'  => array_merge($reglas, $this->reglasDeCliente($usuario?->cliente?->id)),
            // Sin rol válido no hay satélite que exigir: el error que
            // corresponde es el de `rol_id`, no quince campos faltantes.
            default   => $reglas,
        };
    }

    /** @return array<string, array<int, mixed>> */
    private function reglasDeEmpleado(?int $empleadoId, bool $esAlta): array
    {
        return [
            'empleado'          => ['required', 'array'],
            'empleado.legajo'   => [
                'required', 'string', 'max:20',
                Rule::unique('empleados', 'legajo')->ignore($empleadoId),
            ],
            'empleado.dni'      => [
                'required', 'digits_between:6,9',
                Rule::unique('empleados', 'dni')->ignore($empleadoId),
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
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function reglasDeCliente(?int $clienteId): array
    {
        $tipo = $this->input('cliente.tipo_doc');

        return [
            'cliente'               => ['required', 'array'],
            'cliente.razon_social'  => ['required', 'string', 'min:2', 'max:150'],
            'cliente.tipo_doc'      => ['required', Rule::in(['dni', 'cuit', 'cuil'])],

            'cliente.condicion_iva' => ['required', Rule::in([
                'responsable_inscripto', 'monotributo', 'consumidor_final', 'exento',
            ])],

            'cliente.nro_doc' => [
                // Quien factura A o es monotributista tiene que estar
                // identificado; el consumidor final puede no estarlo, y por eso
                // la columna es nullable.
                Rule::requiredIf(fn () => in_array(
                    $this->input('cliente.condicion_iva'),
                    ['responsable_inscripto', 'monotributo'],
                    true,
                )),
                'nullable',
                match ($tipo) {
                    'dni'          => 'digits_between:6,9',
                    'cuit', 'cuil' => 'digits:11',
                    default        => 'string',
                },
                // La base tiene UNIQUE(tipo_doc, nro_doc): la regla dice lo
                // mismo antes, para que el usuario reciba un mensaje y no un
                // error de integridad.
                Rule::unique('clientes', 'nro_doc')
                    ->where(fn ($query) => $query->where('tipo_doc', $tipo))
                    ->ignore($clienteId),
            ],

            'cliente.email'    => ['nullable', 'email', 'max:150'],
            'cliente.telefono' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'   => 'Ya hay una cuenta con ese correo.',
            'rol_id.required' => 'Elegí un rol para la persona.',
            'rol_id.exists'  => 'Ese rol no existe.',
            'rol_id.prohibited'   => 'El rol no se cambia desde esta pantalla. Usá la acción «Cambiar rol».',
            'password.prohibited' => 'La contraseña no se cambia desde esta pantalla.',
            'password.confirmed'  => 'Las dos contraseñas no coinciden.',
            'activo.prohibited' => 'No podés quitarte el acceso a vos mismo. Pedíselo a otra persona con permiso para editar usuarios.',

            'empleado.legajo.required' => 'El legajo es obligatorio.',
            'empleado.legajo.unique'   => 'Ese legajo ya está asignado a otra persona.',
            'empleado.dni.digits_between' => 'El DNI se escribe sin puntos, entre 6 y 9 dígitos.',
            'empleado.dni.unique'         => 'Ya hay un empleado registrado con ese DNI.',
            'empleado.fecha_ingreso.before_or_equal' => 'La fecha de ingreso no puede ser futura.',
            'empleado.fecha_baja.after_or_equal'     => 'La fecha de baja no puede ser anterior al ingreso.',
            'empleado.fecha_baja.before_or_equal'    => 'La fecha de baja no puede ser futura.',
            'empleado.fecha_baja.prohibited'         => 'No se puede dar de alta a alguien que ya no trabaja acá.',

            'cliente.nro_doc.required' => 'Para esa condición frente al IVA hace falta el número de documento.',
            'cliente.nro_doc.digits'   => 'El CUIT/CUIL tiene 11 dígitos.',
            'cliente.nro_doc.digits_between' => 'El DNI se escribe sin puntos, entre 6 y 9 dígitos.',
            'cliente.nro_doc.unique'   => 'Ya hay un cliente registrado con ese documento.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nombre'    => 'nombre',
            'apellido'  => 'apellido',
            'email'     => 'correo',
            'password'  => 'contraseña',
            'rol_id'    => 'rol',
            'activo'    => 'acceso al sistema',

            'empleado.legajo'        => 'legajo',
            'empleado.dni'           => 'DNI',
            'empleado.telefono'      => 'teléfono',
            'empleado.fecha_ingreso' => 'fecha de ingreso',
            'empleado.fecha_baja'    => 'fecha de baja',

            'cliente.razon_social'  => 'nombre o razón social',
            'cliente.tipo_doc'      => 'tipo de documento',
            'cliente.nro_doc'       => 'número de documento',
            'cliente.condicion_iva' => 'condición frente al IVA',
            'cliente.email'         => 'correo de contacto',
            'cliente.telefono'      => 'teléfono',
        ];
    }
}