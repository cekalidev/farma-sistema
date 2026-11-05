<?php

declare(strict_types=1);

namespace App\Http\Requests\Traslados;

use App\Models\Traslado;
use App\Services\Traslados\ItemRecibido;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/traslados/{traslado}/recibir (RN-07-AC5..AC7):
 *
 *   {"items": [{"traslado_item_id": 7, "cantidad_recibida": 10}, …]}
 *
 * Todos los ítems del traslado, cada uno una vez, sin ítems ajenos (si no, 422 VALIDACION);
 * `cantidad_recibida` entera JSON >= 0 (negativa → 422 VALIDACION). Que no supere lo despachado
 * (422 CANTIDAD_RECIBIDA_EXCEDE) lo decide el servicio tras el estado (409 antes).
 */
class RecibirTrasladoRequest extends FormRequest
{
    use ReglasTraslado;

    public function authorize(): bool
    {
        // La ruta ya aplica ->can('recibir', 'traslado').
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.self::ITEMS_MAXIMO],
            'items.*' => ['required', 'array:traslado_item_id,cantidad_recibida'],
            'items.*.traslado_item_id' => [...self::reglasId(), 'distinct'],
            'items.*.cantidad_recibida' => ['required', 'integer:strict', 'min:0', 'max:'.self::CANTIDAD_MAXIMA],
        ];
    }

    /**
     * Además de las claves de la raíz: los ids deben ser exactamente los ítems del traslado
     * (los ítems no cambian fuera de BORRADOR; el servicio lo repite con la fila bloqueada).
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->soloClaves(['items']),
            function (Validator $validator): void {
                $traslado = $this->route('traslado');
                if ($validator->errors()->isNotEmpty() || ! $traslado instanceof Traslado) {
                    return;
                }

                $enviados = array_map(fn (ItemRecibido $item): int => $item->trasladoItemId, $this->itemsSinValidar());
                $esperados = $traslado->items()->pluck('id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();

                if (array_diff($esperados, $enviados) !== []) {
                    $validator->errors()->add('items', 'La recepción debe incluir todos los ítems del traslado.');
                }
                if (array_diff($enviados, $esperados) !== []) {
                    $validator->errors()->add('items', 'La recepción incluye ítems que no son de este traslado.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.array' => 'Cada ítem admite solo traslado_item_id y cantidad_recibida.',
            'items.*.traslado_item_id.distinct' => 'Un ítem no puede repetirse en la recepción.',
        ];
    }

    /**
     * @return list<ItemRecibido>
     */
    public function recibidos(): array
    {
        /** @var array{items: list<array{traslado_item_id: int|string, cantidad_recibida: int}>} $validados */
        $validados = $this->validated();

        return self::aItems($validados['items']);
    }

    /**
     * Ítems del cuerpo ya validados en forma (dentro de `after`, antes de validated()).
     *
     * @return list<ItemRecibido>
     */
    private function itemsSinValidar(): array
    {
        /** @var list<array{traslado_item_id: int|string, cantidad_recibida: int}> $items */
        $items = $this->input('items');

        return self::aItems($items);
    }

    /**
     * @param  list<array{traslado_item_id: int|string, cantidad_recibida: int}>  $items
     * @return list<ItemRecibido>
     */
    private static function aItems(array $items): array
    {
        return array_map(
            static fn (array $item): ItemRecibido => new ItemRecibido((int) $item['traslado_item_id'], $item['cantidad_recibida']),
            $items,
        );
    }
}
