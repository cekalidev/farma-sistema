<?php

declare(strict_types=1);

namespace App\Http\Requests\Pacientes;

use App\Enums\TipoDocumento;
use App\Models\Paciente;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/pacientes/buscar {tipo_documento?, numero_documento} (RN-10-AC7): el documento
 * viaja en el cuerpo, nunca en la URL (no queda en logs de acceso ni en el historial). Los mensajes
 * de validación nunca repiten el valor enviado. Claves desconocidas → 422 (como S-41).
 */
class BuscarPacienteRequest extends FormRequest
{
    /** Mismo largo que la columna pacientes.numero_documento. */
    public const DOCUMENTO_MAXIMO = 20;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Paciente::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo_documento' => ['sometimes', 'string', Rule::enum(TipoDocumento::class)],
            'numero_documento' => ['required', 'string', 'max:'.self::DOCUMENTO_MAXIMO, 'regex:/^[A-Za-z0-9-]+$/D'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'numero_documento.regex' => 'El número de documento solo admite letras, dígitos y guiones.',
        ];
    }

    /** Claves admitidas en el cuerpo; cualquier otra → 422 VALIDACION. */
    public const CLAVES = ['tipo_documento', 'numero_documento'];

    /**
     * Solo el cuerpo (no la query string): un documento en la URL no se usa para buscar.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->getInputSource()->all()) as $clave) {
                if (! in_array($clave, self::CLAVES, true)) {
                    $validator->errors()->add((string) $clave, 'Campo no permitido.');
                }
            }
        }];
    }

    /**
     * Solo se valida (y se usa) el cuerpo: un `numero_documento` en la query string se ignora y la
     * búsqueda responde 422 por falta del documento en el cuerpo (AC7).
     *
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        return $this->getInputSource()->all();
    }

    public function tipo(): ?TipoDocumento
    {
        /** @var array{tipo_documento?: string} $validados */
        $validados = $this->validated();

        return isset($validados['tipo_documento']) ? TipoDocumento::from($validados['tipo_documento']) : null;
    }

    public function numeroDocumento(): string
    {
        /** @var array{numero_documento: string} $validados */
        $validados = $this->validated();

        return $validados['numero_documento'];
    }
}
