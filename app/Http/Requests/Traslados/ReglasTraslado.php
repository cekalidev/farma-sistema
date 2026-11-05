<?php

declare(strict_types=1);

namespace App\Http\Requests\Traslados;

use App\Http\Requests\Inventario\StoreEntradaRequest;
use App\Providers\AppServiceProvider;
use App\Services\Inventario\RegistradorKardex;
use App\Services\Traslados\ItemTraslado;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reglas de forma compartidas por las peticiones de traslados (S-27: la forma se valida antes que
 * las reglas de negocio del servicio).
 *
 * @mixin FormRequest
 */
trait ReglasTraslado
{
    /** Ítems por traslado (mismo tope que una dispensación, S-41). */
    public const ITEMS_MAXIMO = 50;

    /** Unidades por ítem (S-07, S-24). */
    public const CANTIDAD_MAXIMA = StoreEntradaRequest::CANTIDAD_MAXIMA;

    /**
     * @return list<string>
     */
    protected static function reglasId(): array
    {
        // `numeric` descarta booleanos y arreglos; el tope cabe en bigint.
        return ['bail', 'required', 'numeric', 'integer', 'min:1', 'max:'.AppServiceProvider::ID_MAXIMO];
    }

    /**
     * RN-07-AC9: 1..50 ítems {producto_id, lote_id, cantidad}, lote sin repetir, cantidad entera
     * JSON estricta > 0. Que el lote sea del producto y no esté vencido lo decide el servicio.
     *
     * @return array<string, mixed>
     */
    protected static function reglasItems(): array
    {
        return [
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.self::ITEMS_MAXIMO],
            'items.*' => ['required', 'array:producto_id,lote_id,cantidad'],
            'items.*.producto_id' => [...self::reglasId(), 'exists:productos,id'],
            'items.*.lote_id' => [...self::reglasId(), 'distinct', 'exists:lotes,id'],
            'items.*.cantidad' => ['required', 'integer:strict', 'min:1', 'max:'.self::CANTIDAD_MAXIMA],
        ];
    }

    /**
     * Motivo con las mismas reglas que un ajuste (S-33).
     *
     * @return list<string>
     */
    protected static function reglasMotivo(int $maximo = RegistradorKardex::MOTIVO_MAXIMO): array
    {
        return [
            'required',
            'string',
            'min:'.RegistradorKardex::MOTIVO_MINIMO,
            'max:'.$maximo,
            'regex:'.RegistradorKardex::REGEX_MOTIVO,
        ];
    }

    /**
     * La raíz del cuerpo solo admite `$claves` (como S-51): otra clave → 422 VALIDACION.
     *
     * @param  list<string>  $claves
     * @return Closure(Validator): void
     */
    protected function soloClaves(array $claves): Closure
    {
        return function (Validator $validator) use ($claves): void {
            foreach (array_keys($this->getInputSource()->all()) as $clave) {
                if (! in_array($clave, $claves, true)) {
                    $validator->errors()->add((string) $clave, 'Campo no permitido.');
                }
            }
        };
    }

    /**
     * @return list<ItemTraslado>
     */
    protected function itemsValidados(): array
    {
        /** @var array{items: list<array{producto_id: int|string, lote_id: int|string, cantidad: int}>} $validados */
        $validados = $this->validated();

        return array_map(
            static fn (array $item): ItemTraslado => new ItemTraslado((int) $item['producto_id'], (int) $item['lote_id'], $item['cantidad']),
            $validados['items'],
        );
    }

    /**
     * @return array<string, string>
     */
    protected static function mensajesComunes(): array
    {
        return [
            'items.*.array' => 'Cada ítem admite solo producto_id, lote_id y cantidad.',
            'items.*.lote_id.distinct' => 'Un lote no puede repetirse en el mismo traslado.',
            'motivo.regex' => 'El motivo no admite saltos de línea, caracteres de control ni invisibles.',
        ];
    }
}
