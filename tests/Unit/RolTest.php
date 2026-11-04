<?php

declare(strict_types=1);

use App\Enums\Rol;

it('BASE-AC5: el enum Rol tiene exactamente los cinco roles de la matriz de permisos', function () {
    $valores = array_map(fn (Rol $rol): string => $rol->value, Rol::cases());
    sort($valores);

    expect($valores)->toBe([
        'admin',
        'auditor',
        'auxiliar_farmacia',
        'medico',
        'regente_farmacia',
    ]);
});

it('BASE-AC5: un valor fuera del enum no se puede convertir en Rol', function (string $valor) {
    expect(Rol::tryFrom($valor))->toBeNull();
})->with(['superadmin', '', 'ADMIN', 'regente', ' admin']);
