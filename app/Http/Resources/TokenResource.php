<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respuesta de login, sin envoltura "data": {token, token_type, rol, usuario}.
 */
class TokenResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    public function __construct(private readonly User $usuario, private readonly string $token)
    {
        parent::__construct($usuario);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token,
            'token_type' => 'Bearer',
            'expira_en_minutos' => $this->expiraEnMinutos(),
            'rol' => $this->usuario->rol->value,
            'usuario' => new UsuarioResource($this->usuario),
        ];
    }

    /**
     * Contrato: int o null (sin expiración). SANCTUM_EXPIRATION llega del .env como texto.
     */
    private function expiraEnMinutos(): ?int
    {
        $minutos = config('sanctum.expiration');

        return is_numeric($minutos) ? (int) $minutos : null;
    }
}
