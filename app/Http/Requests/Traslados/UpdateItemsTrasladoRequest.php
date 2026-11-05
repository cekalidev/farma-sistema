<?php

declare(strict_types=1);

namespace App\Http\Requests\Traslados;

use App\Services\Traslados\ItemTraslado;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/v1/traslados/{traslado}/items (RN-07-AC9): reemplaza la lista completa de ítems
 * ({"items": [...]}, mismas reglas que al crear). Solo el creador (Policy) y solo en BORRADOR
 * (servicio, 409).
 */
class UpdateItemsTrasladoRequest extends FormRequest
{
    use ReglasTraslado;

    public function authorize(): bool
    {
        // La ruta ya aplica ->can('editarItems', 'traslado').
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::reglasItems();
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [$this->soloClaves(['items'])];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::mensajesComunes();
    }

    /**
     * @return list<ItemTraslado>
     */
    public function items(): array
    {
        return $this->itemsValidados();
    }
}
