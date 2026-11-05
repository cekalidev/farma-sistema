<?php

declare(strict_types=1);

namespace App\Http\Requests\Traslados;

use App\Enums\TipoResolucionDiscrepancia;
use App\Services\Traslados\ResolucionDiscrepancia;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/discrepancias/{discrepancia}/resolver (RN-07-AC12):
 * {"tipo": "PERDIDA" | "REINTEGRO_ORIGEN" | "ENTREGA_DESTINO", "motivo": "…"}.
 * `motivo`: reglas de S-33 con máximo ResolucionDiscrepancia::MOTIVO_MAXIMO (el kardex le
 * antepone la referencia a la discrepancia y al traslado).
 */
class ResolverDiscrepanciaRequest extends FormRequest
{
    use ReglasTraslado;

    public function authorize(): bool
    {
        // La ruta ya aplica ->can('resolver', 'discrepancia').
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', Rule::in(TipoResolucionDiscrepancia::valores())],
            'motivo' => self::reglasMotivo(ResolucionDiscrepancia::MOTIVO_MAXIMO),
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [$this->soloClaves(['tipo', 'motivo'])];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::mensajesComunes();
    }

    public function tipo(): TipoResolucionDiscrepancia
    {
        /** @var array{tipo: string} $validados */
        $validados = $this->validated();

        return TipoResolucionDiscrepancia::from($validados['tipo']);
    }

    public function motivo(): string
    {
        /** @var array{motivo: string} $validados */
        $validados = $this->validated();

        return $validados['motivo'];
    }
}
