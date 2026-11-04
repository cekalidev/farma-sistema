<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\TokenResource;
use App\Http\Resources\UsuarioResource;
use App\Models\User;
use App\Services\Auth\AutenticacionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private readonly AutenticacionService $autenticacion) {}

    public function login(LoginRequest $request): TokenResource
    {
        $resultado = $this->autenticacion->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->string('dispositivo', 'api')->toString(),
        );

        return new TokenResource($resultado['usuario'], $resultado['token']);
    }

    public function logout(Request $request): Response
    {
        $this->autenticacion->logout($this->usuario($request), $request->bearerToken());

        return response()->noContent();
    }

    public function me(Request $request): UsuarioResource
    {
        return new UsuarioResource($this->usuario($request));
    }

    private function usuario(Request $request): User
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return $usuario;
    }
}
