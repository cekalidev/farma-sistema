<?php

declare(strict_types=1);

namespace App\Enums;

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

    public function puede(Habilidad $habilidad): bool
    {
        return in_array($habilidad, $this->habilidades(), true);
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $rol): string => $rol->value, self::cases());
    }
}
