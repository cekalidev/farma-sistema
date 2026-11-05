<?php

declare(strict_types=1);

use App\Models\Producto;
use App\Services\Dispensacion\ControlEspecial;
use Tests\TestCase;

/*
 * RN-05 / S-06 — decisión pura ControlEspecial::requiereAutorizacion(productos): basta un producto
 * de control especial para que la dispensación ENTERA quede pendiente (mixta → pendiente), en
 * cualquier posición; sin ninguno, no requiere autorización. Productos en memoria (sin BD); el
 * TestCase de Laravel solo arranca la aplicación para los casts del modelo.
 */
uses(TestCase::class);

/** @return list<Producto> productos en memoria según la lista de banderas control_especial */
function rn05UnitProductos(array $banderas): array
{
    return array_map(fn (bool $control): Producto => new Producto([
        'codigo' => 'P-UNIT-'.bin2hex(random_bytes(3)),
        'nombre' => 'Producto unitario',
        'unidad_medida' => 'tableta',
        'control_especial' => $control,
    ]), $banderas);
}

describe('RN-05 ControlEspecial::requiereAutorizacion', function () {
    it('RN-05-AC1 RN-05-AC2: requiere autorización si y solo si algún producto es de control especial (mixta → pendiente entera, en cualquier posición)', function (array $banderas, bool $esperado) {
        expect(ControlEspecial::requiereAutorizacion(rn05UnitProductos($banderas)))->toBe($esperado);
    })->with([
        'un producto sin control' => [[false], false],
        'varios sin control' => [[false, false, false], false],
        'un producto de control' => [[true], true],
        'control primero' => [[true, false], true],
        'control al final' => [[false, false, true], true],
        'control en medio' => [[false, true, false], true],
        'todos de control' => [[true, true], true],
    ]);

    it('RN-05-AC1: sin productos (iterable vacío) no requiere autorización', function () {
        expect(ControlEspecial::requiereAutorizacion([]))->toBeFalse();
    });

    it('RN-05-AC2: acepta cualquier iterable (generador o colección), no solo arreglos', function () {
        $generador = (function () {
            yield from rn05UnitProductos([false, true]);
        })();

        expect(ControlEspecial::requiereAutorizacion($generador))->toBeTrue()
            ->and(ControlEspecial::requiereAutorizacion(collect(rn05UnitProductos([false, false]))))->toBeFalse();
    });
});
