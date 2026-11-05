<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ConValores;

/**
 * Roles del sistema (sección 3 de la prueba). Se guardan en `users.rol`,
 * respaldados por un CHECK en la BD.
 *
 * La matriz rol → habilidades vive SOLO aquí (método habilidades()).
 * Para una RN nueva: añade la habilidad en App\Enums\Habilidad y
 * agrégala a los roles que corresponda.
 */
enum Rol: string
{
    use ConValores;

    case AuxiliarFarmacia = 'auxiliar_farmacia';
    case RegenteFarmacia = 'regente_farmacia';
    case Medico = 'medico';
    case Auditor = 'auditor';
    case Admin = 'admin';

    /**
     * @return list<Habilidad>
     */
    public function habilidades(): array
    {
        return match ($this) {
            self::AuxiliarFarmacia => [
                Habilidad::DispensacionCrear,
                Habilidad::InventarioConsultar,
                Habilidad::TrasladoOperar,
                Habilidad::TrasladoAnular,
                Habilidad::TrasladoConsultar,
                Habilidad::PacienteConsultar,
                Habilidad::BodegaConsultar,
                Habilidad::ProductoConsultar,
            ],
            self::RegenteFarmacia => [
                Habilidad::DispensacionCrear,
                Habilidad::DispensacionAutorizar,
                Habilidad::InventarioConsultar,
                Habilidad::InventarioRegistrar,
                Habilidad::TrasladoOperar,
                Habilidad::TrasladoAprobar,
                Habilidad::TrasladoAnular,
                Habilidad::TrasladoConsultar,
                Habilidad::PacienteConsultar,
                Habilidad::BodegaConsultar,
                Habilidad::ProductoConsultar,
            ],
            self::Medico => [
                Habilidad::PrescripcionCrear,
                Habilidad::PacienteConsultar,
                Habilidad::ProductoConsultar,
            ],
            self::Auditor => [
                Habilidad::InventarioConsultar,
                Habilidad::TrasladoConsultar,
                Habilidad::PacienteConsultar,
                Habilidad::AuditoriaConsultar,
                Habilidad::BodegaConsultar,
                Habilidad::ProductoConsultar,
            ],
            self::Admin => [
                Habilidad::AuditoriaConsultar,
                Habilidad::BodegaConsultar,
                Habilidad::ProductoConsultar,
                Habilidad::CatalogoGestionar,
            ],
        };
    }

    /**
     * RN-10-AC1/AC3: ¿ve los datos personales del paciente sin enmascarar? Solo los roles
     * asistenciales. El auditor (y cualquier otro rol) los ve enmascarados; el admin ni siquiera
     * llega a verlos (403, S-13). Lo consulta solo PacienteResource.
     */
    public function veDatosCompletosDePaciente(): bool
    {
        return match ($this) {
            self::AuxiliarFarmacia, self::RegenteFarmacia, self::Medico => true,
            self::Auditor, self::Admin => false,
        };
    }

    public function puede(Habilidad $habilidad): bool
    {
        return in_array($habilidad, $this->habilidades(), true);
    }
}
