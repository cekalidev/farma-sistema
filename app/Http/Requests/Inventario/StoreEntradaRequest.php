<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventario;

use App\Models\Existencia;
use App\Providers\AppServiceProvider;
use App\Services\Inventario\DatosEntrada;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/inventario/entradas (RN-01-AC8, S-17).
 *
 * El lote se indica con `lote_id` (lote ya registrado del producto) o con
 * `lote.codigo` + `lote.fecha_vencimiento` (se crea si no existe). La fecha vencida NO es un
 * error de validación: la decide el servicio con Lote::estaVencido → 422 LOTE_VENCIDO.
 */
class StoreEntradaRequest extends FormRequest
{
    /**
     * Tope por entrada (S-24). No garantiza por sí solo que el saldo quepa en la columna integer
     * de existencias.cantidad: esa suma la comprueba el servicio sobre el saldo bloqueado
     * (422 CANTIDAD_EXCEDE_MAXIMO).
     */
    public const CANTIDAD_MAXIMA = 1_000_000;

    /** Rango aceptado de fecha de vencimiento: años de 4 dígitos que PostgreSQL siempre admite. */
    public const FECHA_VENCIMIENTO_MINIMA = '1900-01-01';

    public const FECHA_VENCIMIENTO_LIMITE = '2100-01-01';

    public function authorize(): bool
    {
        return $this->user()?->can('registrarEntrada', Existencia::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // `integer` solo usa FILTER_VALIDATE_INT, que acepta `true` (→ 1) y floats exactos (2.0).
        // Ids: `numeric` descarta booleanos y arreglos; se admite "5" como texto.
        $id = ['numeric', 'integer', 'min:1', 'max:'.AppServiceProvider::ID_MAXIMO];

        return [
            'bodega_id' => ['required', ...$id, Rule::exists('bodegas', 'id')->where('activa', true)],
            'producto_id' => ['required', ...$id, Rule::exists('productos', 'id')->where('activo', true)],
            // S-07: entero JSON estricto (is_int): rechaza true, "5", "2.5", 2.0, 1.5 y arreglos.
            'cantidad' => ['required', 'integer:strict', 'min:1', 'max:'.self::CANTIDAD_MAXIMA],
            'lote_id' => [
                'required_without:lote',
                'prohibits:lote',
                ...$id,
                Rule::exists('lotes', 'id')->where('producto_id', $this->integer('producto_id')),
            ],
            'lote' => ['required_without:lote_id', 'array:codigo,fecha_vencimiento'],
            // Sin espacios en los extremos, caracteres de control (Unicode Cc: C0, U+007F y C1) ni
            // de formato invisibles (Cf: U+200B, U+00AD, U+200D, U+FEFF, U+2060…), que permitirían
            // registrar "L0\u{200B}01" como lote distinto de "L001" (S-30). Ambas regex usan /u: con
            // UTF-8 inválido `regex` no coincide (preg_match → false) y la petición termina en 422,
            // nunca en un 500 de PostgreSQL.
            'lote.codigo' => ['required_with:lote', 'string', 'max:50', 'regex:/^\S(.*\S)?$/u', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            // Una fecha pasada pero en rango NO falla aquí: el servicio responde LOTE_VENCIDO.
            'lote.fecha_vencimiento' => [
                'bail',
                'required_with:lote',
                'date_format:Y-m-d',
                'regex:/^\d{4}-\d{2}-\d{2}$/',
                'after_or_equal:'.self::FECHA_VENCIMIENTO_MINIMA,
                'before:'.self::FECHA_VENCIMIENTO_LIMITE,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lote_id.exists' => 'El lote no existe o no pertenece al producto.',
            'bodega_id.exists' => 'La bodega no existe o está inactiva.',
            'producto_id.exists' => 'El producto no existe o está inactivo.',
        ];
    }

    public function datos(): DatosEntrada
    {
        /** @var array{bodega_id: int|string, producto_id: int|string, cantidad: int, lote_id?: int|string, lote?: array{codigo: string, fecha_vencimiento: string}} $validados */
        $validados = $this->validated();

        return new DatosEntrada(
            usuarioId: $this->user()->id ?? throw new AuthenticationException,
            bodegaId: (int) $validados['bodega_id'],
            productoId: (int) $validados['producto_id'],
            cantidad: $validados['cantidad'],
            loteId: isset($validados['lote_id']) ? (int) $validados['lote_id'] : null,
            loteCodigo: $validados['lote']['codigo'] ?? null,
            fechaVencimiento: $validados['lote']['fecha_vencimiento'] ?? null,
        );
    }
}
