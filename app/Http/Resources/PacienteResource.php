<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Paciente;
use App\Models\User;
use App\Support\Enmascarador;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Paciente (RN-10). ÚNICO lugar donde se decide si los datos personales salen completos o
 * enmascarados, según el rol del usuario autenticado (nunca según el cliente):
 *  - auxiliar, regente, médico → completos (AC1);
 *  - auditor (y cualquier otro rol o petición sin usuario) → enmascarados con Enmascarador (AC3).
 * Todo recurso que anide un paciente (prescripciones, dispensación) usa este Resource (AC4).
 *
 * @mixin Paciente
 */
class PacienteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = [
            'id' => $this->id,
            'tipo_documento' => $this->tipo_documento->value,
        ];

        if (self::veDatosCompletos($request)) {
            return $base + [
                'numero_documento' => $this->numero_documento,
                'nombres' => $this->nombres,
                'apellidos' => $this->apellidos,
                'fecha_nacimiento' => $this->fecha_nacimiento->toDateString(),
                'telefono' => $this->telefono,
                'correo' => $this->correo,
                'direccion' => $this->direccion,
                'enmascarado' => false,
            ];
        }

        return $base + [
            'numero_documento' => Enmascarador::documento($this->numero_documento),
            'nombres' => Enmascarador::nombre($this->nombres),
            'apellidos' => Enmascarador::nombre($this->apellidos),
            'fecha_nacimiento' => Enmascarador::fechaNacimiento($this->fecha_nacimiento),
            'telefono' => Enmascarador::telefono($this->telefono),
            'correo' => Enmascarador::correo($this->correo),
            'direccion' => Enmascarador::direccion($this->direccion),
            'enmascarado' => true,
        ];
    }

    /** ¿El usuario de la petición ve datos de paciente sin enmascarar? Sin usuario: no. */
    public static function veDatosCompletos(Request $request): bool
    {
        $usuario = $request->user();

        return $usuario instanceof User && $usuario->rol->veDatosCompletosDePaciente();
    }
}
