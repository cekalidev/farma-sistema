<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispensacion;

use App\Http\Requests\Inventario\StoreEntradaRequest;
use App\Models\Dispensacion;
use App\Providers\AppServiceProvider;
use App\Services\Dispensacion\DatosDispensacion;
use App\Services\Dispensacion\ItemSolicitado;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/dispensaciones (RN-02; forma del cuerpo de RN-04-AC1/AC7/AC11):
 *
 *   {"paciente_id": 1, "prescripcion_id": 1, "bodega_id": 1,
 *    "items": [{"prescripcion_item_id": 1, "cantidad": 5}]}
 *
 * Solo forma (S-27): el producto sale del ítem prescrito y el cliente no puede enviar
 * `producto_id` ni `lote_id` por ítem (RN-04-AC11, `array:` rechaza claves extra). Que la
 * prescripción sea del paciente y los ítems de la prescripción, el stock y FEFO los decide el servicio.
 *
 * Idempotency-Key (RN-09): hasta implementar RN-09 la cabecera es opcional y se IGNORA (un
 * reintento duplica la salida). RN-09-AC3 la hará obligatoria (400 IDEMPOTENCY_KEY_REQUERIDA).
 */
class StoreDispensacionRequest extends FormRequest
{
    /** Máximo de ítems por dispensación (propuesta S-41). */
    public const ITEMS_MAXIMO = 50;

    /** Tope de unidades por ítem: el mismo de una entrada (S-24). */
    public const CANTIDAD_MAXIMA = StoreEntradaRequest::CANTIDAD_MAXIMA;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Dispensacion::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Ids: `numeric` descarta booleanos y arreglos; tope que cabe en bigint (AppServiceProvider).
        $id = ['bail', 'required', 'numeric', 'integer', 'min:1', 'max:'.AppServiceProvider::ID_MAXIMO];

        return [
            'paciente_id' => [...$id, 'exists:pacientes,id'],
            'prescripcion_id' => [...$id, 'exists:prescripciones,id'],
            'bodega_id' => [...$id, Rule::exists('bodegas', 'id')->where('activa', true)],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.self::ITEMS_MAXIMO],
            'items.*' => ['required', 'array:prescripcion_item_id,cantidad'],
            'items.*.prescripcion_item_id' => [...$id, 'distinct', 'exists:prescripcion_items,id'],
            // S-07: entero JSON estricto (rechaza true, "5", 2.0, 1.5); 0 y negativos → 422 (RN-04-AC7).
            'items.*.cantidad' => ['required', 'integer:strict', 'min:1', 'max:'.self::CANTIDAD_MAXIMA],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bodega_id.exists' => 'La bodega no existe o está inactiva.',
            'items.*.array' => 'Cada ítem admite solo prescripcion_item_id y cantidad.',
            'items.*.prescripcion_item_id.distinct' => 'Un ítem prescrito no puede repetirse en la misma dispensación.',
        ];
    }

    public function datos(): DatosDispensacion
    {
        /** @var array{paciente_id: int|string, prescripcion_id: int|string, bodega_id: int|string, items: list<array{prescripcion_item_id: int|string, cantidad: int}>} $validados */
        $validados = $this->validated();

        return new DatosDispensacion(
            usuarioId: $this->user()->id ?? throw new AuthenticationException,
            pacienteId: (int) $validados['paciente_id'],
            prescripcionId: (int) $validados['prescripcion_id'],
            bodegaId: (int) $validados['bodega_id'],
            items: array_map(
                static fn (array $item): ItemSolicitado => new ItemSolicitado((int) $item['prescripcion_item_id'], $item['cantidad']),
                $validados['items'],
            ),
        );
    }
}
