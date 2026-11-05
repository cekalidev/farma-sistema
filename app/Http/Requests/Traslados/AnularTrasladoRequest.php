<?php

declare(strict_types=1);

namespace App\Http\Requests\Traslados;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/traslados/{traslado}/anular (RN-07-AC8): `motivo` obligatorio (reglas de S-33). */
class AnularTrasladoRequest extends FormRequest
{
    use ReglasTraslado;

    public function authorize(): bool
    {
        // La ruta ya aplica ->can('anular', 'traslado').
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['motivo' => self::reglasMotivo()];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [$this->soloClaves(['motivo'])];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::mensajesComunes();
    }

    public function motivo(): string
    {
        /** @var array{motivo: string} $validados */
        $validados = $this->validated();

        return $validados['motivo'];
    }
}
