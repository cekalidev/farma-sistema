<?php

declare(strict_types=1);

namespace App\Http\Requests\Traslados;

use App\Models\Traslado;
use App\Services\Traslados\DatosTraslado;
use App\Services\Traslados\TrasladoService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/traslados (RN-07-AC1/AC9):
 *
 *   {"bodega_origen_id": 1, "bodega_destino_id": 2, "observaciones": "…",
 *    "items": [{"producto_id": 1, "lote_id": 3, "cantidad": 10}]}
 *
 * `observaciones` es texto libre opcional (hasta 1000 caracteres; admite saltos de línea y
 * tabuladores, no otros caracteres de control ni invisibles). Se guarda y devuelve tal cual.
 */
class StoreTrasladoRequest extends FormRequest
{
    use ReglasTraslado;

    public const OBSERVACIONES_MAXIMO = 1000;

    /** Sin Cc/Cf salvo \t \n \r; con /u el UTF-8 inválido no casa. */
    public const REGEX_OBSERVACIONES = '/^(?:[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]|[\t\n\r])*$/u';

    public const CLAVES_RAIZ = ['bodega_origen_id', 'bodega_destino_id', 'observaciones', 'items'];

    public function authorize(): bool
    {
        return $this->user()?->can('create', Traslado::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bodega_origen_id' => [...self::reglasId(), 'exists:bodegas,id'],
            // RN-07-AC9 / S-64: origen ≠ destino se compara en after() con los ids ya normalizados.
            'bodega_destino_id' => [...self::reglasId(), 'exists:bodegas,id'],
            'observaciones' => ['nullable', 'string', 'max:'.self::OBSERVACIONES_MAXIMO, 'regex:'.self::REGEX_OBSERVACIONES],
            ...self::reglasItems(),
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [$this->soloClaves(self::CLAVES_RAIZ), $this->bodegasDistintas(...)];
    }

    /**
     * RN-07-AC9 / S-64: origen ≠ destino comparando los ids como enteros. La regla `different`
     * compara con === y dejaría pasar 1 frente a "1" o 1.0 (la BD lo rechazaría con un 500).
     * El servicio lo repite y la BD lo respalda con CHECK.
     */
    private function bodegasDistintas(Validator $validator): void
    {
        $errores = $validator->errors();
        if ($errores->has('bodega_origen_id') || $errores->has('bodega_destino_id')) {
            return;
        }

        $origen = $this->input('bodega_origen_id');
        $destino = $this->input('bodega_destino_id');
        if (is_numeric($origen) && is_numeric($destino) && (int) $origen === (int) $destino) {
            $errores->add('bodega_destino_id', TrasladoService::MENSAJE_BODEGAS_IGUALES);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...self::mensajesComunes(),
            'observaciones.regex' => 'Las observaciones no admiten caracteres de control ni invisibles.',
        ];
    }

    public function datos(): DatosTraslado
    {
        /** @var array{bodega_origen_id: int|string, bodega_destino_id: int|string, observaciones?: string|null} $validados */
        $validados = $this->validated();

        return new DatosTraslado(
            usuarioId: $this->user()->id ?? throw new AuthenticationException,
            bodegaOrigenId: (int) $validados['bodega_origen_id'],
            bodegaDestinoId: (int) $validados['bodega_destino_id'],
            observaciones: $validados['observaciones'] ?? null,
            items: $this->itemsValidados(),
        );
    }
}
