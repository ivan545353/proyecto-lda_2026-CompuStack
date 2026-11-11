<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegistroRequest;
use App\Models\Rol;
use App\Services\CredencialService;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistroController extends Controller
{
    public function __construct(
        private readonly UsuarioService $usuarios,
        private readonly CredencialService $credenciales,
    ) {}

    /** Crea la cuenta con su ficha de cliente y la deja con sesión abierta (201). */
    public function store(RegistroRequest $request): JsonResponse
    {
        $usuario = $this->usuarios->crear([
            'nombre'   => $request->input('nombre'),
            'apellido' => $request->input('apellido'),
            'email'    => $request->input('email'),
            'password' => $request->input('password'),
            // El rol de sistema de la tienda, no por su nombre: el nombre se puede editar.
            'rol_id'   => Rol::where('ambito', 'tienda')->where('es_sistema', true)->valueOrFail('id'),
            'activo'   => true,
            'cliente'  => [
                'razon_social'  => $request->razonSocial(),
                'tipo_doc'      => 'dni',
                'nro_doc'       => null,
                'condicion_iva' => 'consumidor_final',
                'email'         => $request->input('email'),
                'telefono'      => $request->input('telefono'),
            ],
        ]);

        return Recurso::respuesta(new JsonResource($this->credenciales->emitir($usuario)), 201);
    }
}
