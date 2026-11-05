<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispensacion;

use App\Exceptions\Dominio\IdempotencyKeyRequerida;
use App\Http\Requests\Inventario\StoreEntradaRequest;
use App\Models\Dispensacion;
use App\Providers\AppServiceProvider;
use App\Services\Dispensacion\ClaveIdempotencia;
use App\Services\Dispensacion\DatosDispensacion;
use App\Services\Dispensacion\DispensacionService;
use App\Services\Dispensacion\HashCanonico;
use App\Services\Dispensacion\ItemSolicitado;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

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
 * Idempotency-Key (RN-09-AC3): obligatoria en la creación. Una sola cabecera, de 1 a 255
 * caracteres ASCII imprimibles (0x20–0x7E, el conjunto de un sf-string del borrador IETF), y no
 * solo espacios; si no, 400 IDEMPOTENCY_KEY_REQUERIDA. Se comprueba tras la autorización (401/403
 * primero) y antes de validar el cuerpo (400 antes que 422). La previsualización no la exige.
 */
class StoreDispensacionRequest extends FormRequest
{
    /** Máximo de ítems por dispensación (propuesta S-41). */
    public const ITEMS_MAXIMO = 50;

    /** Tope de unidades por ítem: el mismo de una entrada (S-24). */
    public const CANTIDAD_MAXIMA = StoreEntradaRequest::CANTIDAD_MAXIMA;

    /** Longitud máxima de Idempotency-Key (columna dispensaciones.idempotency_key, RN-09-AC3). */
    public const IDEMPOTENCY_KEY_MAXIMO = 255;

    public const CABECERA_IDEMPOTENCIA = 'Idempotency-Key';

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
            // Que esté activa (S-46) lo comprueba el servicio, y solo para una dispensación nueva: un
            // reintento con la misma Idempotency-Key reproduce la respuesta original aunque la
            // bodega se haya desactivado después (S-52).
            'bodega_id' => [...$id, 'exists:bodegas,id'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.self::ITEMS_MAXIMO],
            'items.*' => ['required', 'array:prescripcion_item_id,cantidad'],
            'items.*.prescripcion_item_id' => [...$id, 'distinct', 'exists:prescripcion_items,id'],
            // S-07: entero JSON estricto (rechaza true, "5", 2.0, 1.5); 0 y negativos → 422 (RN-04-AC7).
            'items.*.cantidad' => ['required', 'integer:strict', 'min:1', 'max:'.self::CANTIDAD_MAXIMA],
        ];
    }

    /** Claves admitidas en la raíz del cuerpo (S-51: cualquier otra → 422 VALIDACION). */
    public const CLAVES_RAIZ = ['paciente_id', 'prescripcion_id', 'bodega_id', 'items'];

    /**
     * S-51 / RN-04-AC11: igual que `array:` en cada ítem, la raíz no admite claves desconocidas
     * (p. ej. `nota`, `lote_id`, `producto_id`). Se mira solo el cuerpo (no la query string), y
     * con `after` y no con `prohibited`, que dejaría pasar una clave con valor null o "".
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->getInputSource()->all()) as $clave) {
                if (! in_array($clave, self::CLAVES_RAIZ, true)) {
                    $validator->errors()->add((string) $clave, 'Campo no permitido.');
                }
            }
        }];
    }

    /** ¿Esta operación exige Idempotency-Key? La previsualización (subclase) no. */
    protected function exigeIdempotencyKey(): bool
    {
        return true;
    }

    /**
     * Se ejecuta después de authorize() y antes de validar el cuerpo (ValidatesWhenResolvedTrait).
     *
     * @throws IdempotencyKeyRequerida
     */
    protected function getValidatorInstance(): Validator
    {
        if ($this->exigeIdempotencyKey()) {
            $this->idempotencyKey();
        }

        return parent::getValidatorInstance();
    }

    /**
     * @throws IdempotencyKeyRequerida
     */
    private function idempotencyKey(): string
    {
        $valores = $this->headers->all(strtolower(self::CABECERA_IDEMPOTENCIA));
        $clave = count($valores) === 1 ? $valores[0] : null;

        if (! is_string($clave)
            || strlen($clave) > self::IDEMPOTENCY_KEY_MAXIMO
            || trim($clave) === ''
            || preg_match('/^[\x20-\x7E]+$/', $clave) !== 1) {
            throw new IdempotencyKeyRequerida;
        }

        return $clave;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bodega_id.exists' => DispensacionService::MENSAJE_BODEGA_NO_DISPONIBLE,
            'items.*.array' => 'Cada ítem admite solo prescripcion_item_id y cantidad.',
            'items.*.prescripcion_item_id.distinct' => 'Un ítem prescrito no puede repetirse en la misma dispensación.',
        ];
    }

    public function datos(): DatosDispensacion
    {
        $cuerpo = $this->cuerpoNormalizado();

        return new DatosDispensacion(
            usuarioId: $this->user()->id ?? throw new AuthenticationException,
            pacienteId: $cuerpo['paciente_id'],
            prescripcionId: $cuerpo['prescripcion_id'],
            bodegaId: $cuerpo['bodega_id'],
            items: array_map(
                static fn (array $item): ItemSolicitado => new ItemSolicitado($item['prescripcion_item_id'], $item['cantidad']),
                $cuerpo['items'],
            ),
        );
    }

    /**
     * RN-09: clave del cliente + hash canónico del cuerpo YA validado y normalizado (ids a int), así
     * que `"bodega_id": "3"` y `"bodega_id": 3` (ambos aceptados) o las claves en otro orden dan
     * el mismo hash (AC4).
     *
     * @throws IdempotencyKeyRequerida
     */
    public function claveIdempotencia(): ClaveIdempotencia
    {
        return new ClaveIdempotencia($this->idempotencyKey(), HashCanonico::hashCanonico($this->cuerpoNormalizado()));
    }

    /**
     * @return array{paciente_id: int, prescripcion_id: int, bodega_id: int, items: list<array{prescripcion_item_id: int, cantidad: int}>}
     */
    private function cuerpoNormalizado(): array
    {
        /** @var array{paciente_id: int|string, prescripcion_id: int|string, bodega_id: int|string, items: list<array{prescripcion_item_id: int|string, cantidad: int}>} $validados */
        $validados = $this->validated();

        return [
            'paciente_id' => (int) $validados['paciente_id'],
            'prescripcion_id' => (int) $validados['prescripcion_id'],
            'bodega_id' => (int) $validados['bodega_id'],
            'items' => array_map(
                static fn (array $item): array => [
                    'prescripcion_item_id' => (int) $item['prescripcion_item_id'],
                    'cantidad' => $item['cantidad'],
                ],
                $validados['items'],
            ),
        ];
    }
}
