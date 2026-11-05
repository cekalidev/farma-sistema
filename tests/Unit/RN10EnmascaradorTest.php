<?php

declare(strict_types=1);

use App\Support\Enmascarador;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/*
 * RN-10-AC3 — Enmascarado de datos del paciente para el rol auditor (función crítica, pura).
 *
 * Formatos de la spec: documento `******5678` (solo los 4 últimos), nombres/apellidos inicial +
 * `***` por palabra (`J*** P***`), fecha de nacimiento solo el año (`1985`), teléfono `*******234`,
 * correo `j***@***`, dirección null. "Funciones críticas a probar": documento corto (< 4),
 * nombres compuestos, correo sin @, nulos.
 *
 * Donde la spec no fija el formato exacto (longitudes distintas de las del ejemplo, valores
 * demasiado cortos, correo sin @) se exige la propiedad de privacidad: no se revela nada más que
 * lo que la spec permite (cola del documento/teléfono, inicial de cada palabra, inicial del correo).
 */

/**
 * Falla si el resultado contiene cualquier fragmento de `$min` caracteres del original que no esté
 * formado solo por asteriscos (es decir, si revela un trozo del valor real).
 */
function rn10SinFragmentos(?string $resultado, string $original, int $min = 2): void
{
    if ($resultado === null) {
        return;
    }

    $longitud = mb_strlen($original);
    for ($i = 0; $i + $min <= $longitud; $i++) {
        $fragmento = mb_substr($original, $i, $min);
        if (trim($fragmento, '*') === '') {
            continue;
        }
        expect(mb_strpos($resultado, $fragmento))
            ->toBeFalse("El enmascarado «{$resultado}» revela el fragmento «{$fragmento}» de «{$original}»");
    }
}

describe('RN-10 Enmascarador: documento', function () {
    it('RN-10-AC3: documento de 10 dígitos → ******5678 (solo los 4 últimos)', function () {
        expect(Enmascarador::documento('1023455678'))->toBe('******5678');
    });

    it('RN-10-AC3: documento de otra longitud o con letras → solo asteriscos + los 4 últimos', function (string $documento, string $cola) {
        $resultado = Enmascarador::documento($documento);

        expect($resultado)->toBeString()
            ->and($resultado)->toEndWith($cola)
            ->and($resultado)->toMatch('/^\*+'.preg_quote($cola, '/').'$/u');
        // Nada del documento antes de la cola aparece en el resultado.
        rn10SinFragmentos(mb_substr((string) $resultado, 0, -4), mb_substr($documento, 0, -4), 1);
    })->with([
        '5 dígitos' => ['91234', '1234'],
        '8 dígitos' => ['52879341', '9341'],
        '12 dígitos' => ['100200300456', '0456'],
        'pasaporte con letras' => ['AB7654321', '4321'],
        'cola con letras' => ['PA12XYZW', 'XYZW'],
        'minúsculas y dígitos' => ['ce99887766x', '766x'],
    ]);

    it('RN-10-AC3: documento corto (< 4 caracteres) se enmascara por completo: no revela ningún carácter', function (string $documento) {
        $resultado = Enmascarador::documento($documento);

        expect($resultado)->toBeString()
            ->and($resultado)->toMatch('/^\*+$/')
            ->and($resultado)->not->toBe($documento);
    })->with([
        '1 carácter' => ['7'],
        '2 caracteres' => ['42'],
        '3 caracteres' => ['913'],
        '3 con letras' => ['A1B'],
    ]);

    it('RN-10-AC3: documento null → null', function () {
        expect(Enmascarador::documento(null))->toBeNull();
    });
});

describe('RN-10 Enmascarador: nombres y apellidos', function () {
    it('RN-10-AC3: inicial + *** por palabra', function (string $nombre, string $esperado) {
        expect(Enmascarador::nombre($nombre))->toBe($esperado);
    })->with([
        'simple' => ['Juan', 'J***'],
        'compuesto (ejemplo de la spec)' => ['Juan Pablo', 'J*** P***'],
        'tres palabras' => ['María de los', 'M*** d*** l***'],
        'apellidos con partícula' => ['de la Cruz', 'd*** l*** C***'],
        'tilde inicial' => ['Ángela María', 'Á*** M***'],
        'eñe inicial' => ['Ñusta Ñáñez', 'Ñ*** Ñ***'],
        'tilde interior' => ['Julián Andrés', 'J*** A***'],
        'una letra' => ['J', 'J***'],
        'espacios sobrantes no son palabras' => ['  Juan   Pablo  ', 'J*** P***'],
    ]);

    it('RN-10-AC3: el enmascarado de nombres no revela nada más que la inicial de cada palabra', function (string $nombre) {
        $resultado = Enmascarador::nombre($nombre);

        expect($resultado)->toBeString();
        foreach (preg_split('/\s+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
            rn10SinFragmentos($resultado, mb_substr($palabra, 1), 1);
        }
    })->with([
        'Ximena' => ['Ximena'],
        'Óscar Iván' => ['Óscar Iván'],
        'Nuñez Peñaranda' => ['Nuñez Peñaranda'],
        'compuesto con guion' => ['Ana-Lucía'],
    ]);

    it('RN-10-AC3: la inicial es un carácter completo (multibyte), no un byte suelto', function () {
        $resultado = Enmascarador::nombre('Ñandú Élber');

        expect($resultado)->toBe('Ñ*** É***')
            ->and(mb_check_encoding((string) $resultado, 'UTF-8'))->toBeTrue();
    });

    it('RN-10-AC3: nombre null → null', function () {
        expect(Enmascarador::nombre(null))->toBeNull();
    });
});

describe('RN-10 Enmascarador: fecha de nacimiento', function () {
    it('RN-10-AC3: fecha de nacimiento → solo el año', function (mixed $fecha) {
        expect((string) Enmascarador::fechaNacimiento($fecha))->toBe('1985');
    })->with([
        'texto Y-m-d' => ['1985-03-14'],
        'Carbon' => [fn () => Carbon::parse('1985-03-14')],
        'CarbonImmutable' => [fn () => CarbonImmutable::parse('1985-12-31')],
        '1 de enero' => ['1985-01-01'],
    ]);

    it('RN-10-AC3: fecha de nacimiento null → null', function () {
        expect(Enmascarador::fechaNacimiento(null))->toBeNull();
    });

    it('RN-10-AC3: el resultado no contiene mes ni día', function () {
        $resultado = (string) Enmascarador::fechaNacimiento('1985-03-14');

        expect($resultado)->not->toContain('03')
            ->and($resultado)->not->toContain('14')
            ->and($resultado)->not->toContain('-');
    });

    it('RN-10-AC3: no muta la fecha recibida (Carbon es mutable)', function () {
        $fecha = Carbon::parse('1985-03-14 08:30:00', 'America/Bogota');
        $copia = $fecha->toIso8601String();

        Enmascarador::fechaNacimiento($fecha);

        expect($fecha->toIso8601String())->toBe($copia);
    });
});

describe('RN-10 Enmascarador: teléfono', function () {
    it('RN-10-AC3: teléfono de 10 dígitos → *******234', function () {
        expect(Enmascarador::telefono('3105551234'))->toBe('*******234');
    });

    it('RN-10-AC3: teléfono de otra longitud → solo asteriscos + los 3 últimos', function (string $telefono, string $cola) {
        $resultado = Enmascarador::telefono($telefono);

        expect($resultado)->toMatch('/^\*+'.preg_quote($cola, '/').'$/');
        rn10SinFragmentos(mb_substr((string) $resultado, 0, -3), mb_substr($telefono, 0, -3), 1);
    })->with([
        'fijo de 7' => ['6012345', '345'],
        'con indicativo' => ['+573105551987', '987'],
        '4 dígitos' => ['5678', '678'],
    ]);

    it('RN-10-AC3: teléfono corto (≤ 3) se enmascara por completo', function (string $telefono) {
        expect(Enmascarador::telefono($telefono))->toMatch('/^\*+$/');
    })->with(['1', '12', '123']);

    it('RN-10-AC3: teléfono null → null', function () {
        expect(Enmascarador::telefono(null))->toBeNull();
    });
});

describe('RN-10 Enmascarador: correo', function () {
    it('RN-10-AC3: correo → inicial + ***@*** (sin dominio)', function (string $correo, string $esperado) {
        expect(Enmascarador::correo($correo))->toBe($esperado);
    })->with([
        'ejemplo de la spec' => ['juan.perez@correo.test', 'j***@***'],
        'mayúscula inicial' => ['Laura_99@sintetico.example', 'L***@***'],
        'inicial multibyte' => ['ñandu@ejemplo.test', 'ñ***@***'],
        'usuario de una letra' => ['x@y.co', 'x***@***'],
    ]);

    it('RN-10-AC3: correo sin @ no revela su contenido', function (string $correo) {
        $resultado = Enmascarador::correo($correo);

        expect($resultado === null || is_string($resultado))->toBeTrue()
            ->and($resultado)->not->toBe($correo);
        rn10SinFragmentos($resultado, $correo, 2);
        // Como mucho la inicial: ningún otro carácter alfanumérico del original.
        expect(preg_match_all('/[\p{L}\p{N}]/u', (string) $resultado))->toBeLessThanOrEqual(1);
    })->with([
        'sin arroba' => ['sinarroba.ejemplo.test'],
        'solo usuario' => ['pacientesintetico'],
        'con espacios' => ['nombre apellido'],
    ]);

    it('RN-10-AC3: el dominio nunca aparece', function () {
        $resultado = (string) Enmascarador::correo('ana@dominio-sintetico.test');

        expect($resultado)->not->toContain('dominio')
            ->and($resultado)->not->toContain('sintetico')
            ->and($resultado)->not->toContain('.test');
    });

    it('RN-10-AC3: correo vacío no inventa datos (null o cadena vacía)', function (string $vacio) {
        expect(Enmascarador::correo($vacio))->toBeIn([null, '']);
    })->with(['vacío' => [''], 'solo espacios' => ['   ']]);

    it('RN-10-AC3: correo null → null', function () {
        expect(Enmascarador::correo(null))->toBeNull();
    });
});

describe('RN-10 Enmascarador: dirección', function () {
    it('RN-10-AC3: la dirección siempre sale null', function (?string $direccion) {
        expect(Enmascarador::direccion($direccion))->toBeNull();
    })->with([
        'normal' => ['Carrera 7 # 12-34 Apto 501'],
        'con tildes' => ['Avenida Simón Bolívar 45-10'],
        'vacía' => [''],
        'null' => [null],
    ]);
});

/*
 * S-76: formatos fijos (longitud de asteriscos constante, para no revelar la longitud del valor).
 */
describe('RN-10 Enmascarador: formatos fijos de S-76', function () {
    it('RN-10-AC3 S-76: documento de 4 caracteres o menos → exactamente ******', function (string $documento) {
        expect(Enmascarador::documento($documento))->toBe('******');
    })->with(['4 dígitos' => ['1234'], '3 dígitos' => ['913'], '1 dígito' => ['7']]);

    it('RN-10-AC3 S-76: documento de 5 o más → ****** + 4 últimos, sin importar la longitud', function (string $documento, string $esperado) {
        expect(Enmascarador::documento($documento))->toBe($esperado);
    })->with([
        '5 dígitos' => ['91234', '******1234'],
        '12 dígitos' => ['100200300456', '******0456'],
    ]);

    it('RN-10-AC3 S-76: teléfono de 3 caracteres o menos → exactamente *******', function (string $telefono) {
        expect(Enmascarador::telefono($telefono))->toBe('*******');
    })->with(['3 dígitos' => ['123'], '1 dígito' => ['1']]);

    it('RN-10-AC3 S-76: teléfono de 4 o más → ******* + 3 últimos', function (string $telefono, string $esperado) {
        expect(Enmascarador::telefono($telefono))->toBe($esperado);
    })->with([
        '4 dígitos' => ['5678', '*******678'],
        'fijo de 7' => ['6012345', '*******345'],
    ]);

    it('RN-10-AC3 S-76: correo sin @ → exactamente ***', function (string $correo) {
        expect(Enmascarador::correo($correo))->toBe('***');
    })->with(['sin arroba' => ['sinarroba.ejemplo.test'], 'solo usuario' => ['pacientesintetico']]);

    it('RN-10-AC3 S-76: vacío o solo espacios → null en todos los campos', function (string $vacio) {
        expect(Enmascarador::documento($vacio))->toBeNull()
            ->and(Enmascarador::telefono($vacio))->toBeNull()
            ->and(Enmascarador::nombre($vacio))->toBeNull()
            ->and(Enmascarador::correo($vacio))->toBeNull()
            ->and(Enmascarador::fechaNacimiento($vacio))->toBeNull()
            ->and(Enmascarador::direccion($vacio))->toBeNull();
    })->with(['vacío' => [''], 'espacios' => ['   '], 'tabulador y salto' => ["\t\n "]]);

    it('RN-10-AC3 S-76: el año de nacimiento sale como texto (string "1985"), no como entero', function (mixed $fecha) {
        expect(Enmascarador::fechaNacimiento($fecha))->toBe('1985');
    })->with([
        'texto Y-m-d' => ['1985-03-14'],
        'Carbon' => [fn () => Carbon::parse('1985-03-14')],
        'CarbonImmutable' => [fn () => CarbonImmutable::parse('1985-12-31')],
    ]);
});
