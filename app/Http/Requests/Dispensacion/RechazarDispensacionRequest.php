<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispensacion;

use App\Services\Inventario\RegistradorKardex;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/dispensaciones/{dispensacion}/rechazar (RN-05-AC8): `motivo` obligatorio con las
 * mismas reglas que el motivo de un ajuste (S-33): 10 a 500 caracteres tras recortar los extremos,
 * sin caracteres Cc/Cf/Zl/Zp. Rol y segundo regente ya los decidió la Policy de la ruta (403 antes
 * que 422); el estado lo decide el servicio con la fila bloqueada (409).
 */
class RechazarDispensacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La ruta ya aplica ->can('rechazar', 'dispensacion').
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo' => [
                'required',
                'string',
                'min:'.RegistradorKardex::MOTIVO_MINIMO,
                'max:'.RegistradorKardex::MOTIVO_MAXIMO,
                'regex:'.RegistradorKardex::REGEX_MOTIVO,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.regex' => 'El motivo no admite saltos de línea, caracteres de control ni invisibles.',
        ];
    }

    public function motivo(): string
    {
        /** @var array{motivo: string} $validados */
        $validados = $this->validated();

        return $validados['motivo'];
    }
}
