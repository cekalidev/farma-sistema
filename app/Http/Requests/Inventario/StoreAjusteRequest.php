<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventario;

use App\Models\Existencia;
use App\Providers\AppServiceProvider;
use App\Services\Inventario\DatosAjuste;
use App\Services\Inventario\RegistradorKardex;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/inventario/ajustes (RN-06-AC6): `existencia_id`, `cantidad` entera con signo ≠ 0 y
 * `motivo` de 10 a 500 caracteres. Que el saldo no quede negativo ni supere el máximo lo decide el
 * servicio sobre el saldo bloqueado (409 STOCK_INSUFICIENTE / 422 CANTIDAD_EXCEDE_MAXIMO).
 */
class StoreAjusteRequest extends FormRequest
{
    /** Tope absoluto por ajuste: ±1.000.000 (S-32), mismo valor que el de la entrada. */
    public const CANTIDAD_MAXIMA = StoreEntradaRequest::CANTIDAD_MAXIMA;

    public function authorize(): bool
    {
        return $this->user()?->can('registrarAjuste', Existencia::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'existencia_id' => ['bail', 'required', 'numeric', 'integer', 'min:1', 'max:'.AppServiceProvider::ID_MAXIMO, 'exists:existencias,id'],
            // Entero JSON estricto con signo (rechaza true, "5", 2.0); 0 no es un ajuste.
            'cantidad' => ['required', 'integer:strict', 'not_in:0', 'min:-'.self::CANTIDAD_MAXIMA, 'max:'.self::CANTIDAD_MAXIMA],
            // TrimStrings ya quitó los espacios de los extremos. La regex positiva con /u rechaza
            // caracteres de control y de formato invisibles (Cc, Cf; también saltos de línea), los
            // separadores de línea y párrafo U+2028/U+2029 (Zl, Zp) y el UTF-8 inválido
            // (preg_match → false), que nunca debe llegar a PostgreSQL (S-33).
            'motivo' => [
                'required',
                'string',
                'min:'.RegistradorKardex::MOTIVO_MINIMO,
                'max:'.RegistradorKardex::MOTIVO_MAXIMO,
                'regex:/^[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+$/u',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'existencia_id.exists' => 'La existencia no existe.',
            'cantidad.not_in' => 'La cantidad del ajuste no puede ser 0.',
            'motivo.regex' => 'El motivo no admite saltos de línea, caracteres de control ni invisibles.',
        ];
    }

    public function datos(): DatosAjuste
    {
        /** @var array{existencia_id: int|string, cantidad: int, motivo: string} $validados */
        $validados = $this->validated();

        return new DatosAjuste(
            usuarioId: $this->user()->id ?? throw new AuthenticationException,
            existenciaId: (int) $validados['existencia_id'],
            cantidad: $validados['cantidad'],
            motivo: $validados['motivo'],
        );
    }
}
