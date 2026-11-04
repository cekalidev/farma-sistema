<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\Dominio\CredencialesInvalidas;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

final class AutenticacionService
{
    /**
     * Emite un token Bearer. Correo inexistente y contraseña errónea producen el
     * mismo error (BASE-AC2); se verifica un hash ficticio para igualar tiempos.
     *
     * @return array{usuario: User, token: string}
     */
    public function login(string $correo, string $password, string $dispositivo): array
    {
        $usuario = User::query()->porCorreo($correo)->first();

        if ($usuario === null) {
            Hash::check($password, '$2y$12$'.str_repeat('a', 53));

            throw new CredencialesInvalidas;
        }

        if (! Hash::check($password, $usuario->password)) {
            throw new CredencialesInvalidas;
        }

        $token = $usuario->createToken($dispositivo)->plainTextToken;

        return ['usuario' => $usuario, 'token' => $token];
    }

    /**
     * Revoca solo el token Bearer con el que se hizo la petición. Se busca por el
     * valor del Bearer (y no por currentAccessToken()) porque en tests con
     * Sanctum::actingAs el token actual es transitorio y no se puede borrar.
     */
    public function logout(User $usuario, ?string $bearer): void
    {
        if ($bearer === null || $bearer === '') {
            return;
        }

        $usuario->tokens()
            ->whereKey(PersonalAccessToken::findToken($bearer)?->getKey())
            ->delete();
    }
}
