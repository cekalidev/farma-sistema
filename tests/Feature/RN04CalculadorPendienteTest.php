<?php

declare(strict_types=1);

use App\Models\Bodega;
use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dispensacion\CalculadorPendiente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * RN-04 — función crítica CalculadorPendiente::pendiente(PrescripcionItem $item): int
 * = cantidad prescrita − Σ dispensacion_items.cantidad del ítem en dispensaciones CONFIRMADA y
 *   PENDIENTE_AUTORIZACION (RN-04-AC5, AC9); RECHAZADA no cuenta (S-06).
 *
 * Toca la BD, así que vive en tests/Feature (reglas de tests). Es el unitario de la función
 * crítica: las dispensaciones se insertan por SQL en el estado deseado respetando los CHECK de la
 * tabla, para combinar estados libremente; aquí solo se prueba el cálculo, no el movimiento de
 * stock. El flujo real de RN-04-AC9 (pendiente creada por POST y rechazada o autorizada por sus
 * endpoints de RN-05) está en RN04PrescripcionTopeTest y RN05ControlEspecialTest.
 */

/*
|--------------------------------------------------------------------------
| Ayudantes (prefijo rn04Calc)
|--------------------------------------------------------------------------
*/

function rn04CalcUsuario(string $sufijo): User
{
    $email = "rn04calc-{$sufijo}@farma.test";

    return User::query()->where('email', $email)->first()
        ?? usuarioConRol('regente_farmacia', ['email' => $email]);
}

function rn04CalcItem(int $cantidad, ?Prescripcion $prescripcion = null, ?Producto $producto = null): PrescripcionItem
{
    return PrescripcionItem::factory()->create([
        'prescripcion_id' => ($prescripcion ?? Prescripcion::factory()->create())->id,
        'producto_id' => ($producto ?? Producto::factory()->create())->id,
        'cantidad' => $cantidad,
    ]);
}

/**
 * Dispensación de `cantidad` del ítem en el estado dado, insertada por SQL (CHECK respetados).
 * CONFIRMADA sin autorizador (= sin control especial), PENDIENTE_AUTORIZACION sin autorizador ni
 * rechazo, RECHAZADA con rechazador distinto, fecha y motivo.
 */
function rn04CalcDispensacion(PrescripcionItem $item, int $cantidad, string $estado): int
{
    $prescripcion = Prescripcion::query()->findOrFail($item->prescripcion_id);
    $ahora = now();
    $fila = [
        'paciente_id' => $prescripcion->paciente_id,
        'prescripcion_id' => $prescripcion->id,
        'bodega_id' => Bodega::factory()->create()->id,
        'dispensado_por_id' => rn04CalcUsuario('dispensador')->id,
        'estado' => $estado,
        'created_at' => $ahora,
        'updated_at' => $ahora,
    ];
    if ($estado === 'RECHAZADA') {
        $fila += [
            'rechazado_por_id' => rn04CalcUsuario('rechazador')->id,
            'rechazado_en' => $ahora,
            'motivo_rechazo' => 'Rechazo sintético de prueba RN-04',
        ];
    }

    $id = DB::table('dispensaciones')->insertGetId($fila);
    DB::table('dispensacion_items')->insert([
        'dispensacion_id' => $id,
        'prescripcion_id' => $prescripcion->id,
        'prescripcion_item_id' => $item->id,
        'producto_id' => $item->producto_id,
        'cantidad' => $cantidad,
        'created_at' => $ahora,
        'updated_at' => $ahora,
    ]);

    return $id;
}

function rn04CalcPendiente(PrescripcionItem $item): int
{
    // Se recarga el ítem: el cálculo no debe depender de nada cacheado en la instancia.
    $fresco = PrescripcionItem::query()->findOrFail($item->id);

    return app(CalculadorPendiente::class)->pendiente($fresco);
}

describe('RN-04 CalculadorPendiente::pendiente', function () {
    beforeEach(fn () => test()->travelTo(Carbon::parse('2026-11-04 10:00:00', 'America/Bogota')));

    it('RN-04-AC5: sin dispensaciones el pendiente es lo prescrito', function (int $prescrito) {
        $item = rn04CalcItem($prescrito);

        expect(rn04CalcPendiente($item))->toBe($prescrito);
    })->with([1, 10, 1_000_000]);

    it('RN-04-AC5: parciales CONFIRMADA acumuladas se restan, hasta exactamente el tope', function (array $parciales, int $esperado) {
        $item = rn04CalcItem(10);
        foreach ($parciales as $p) {
            rn04CalcDispensacion($item, $p, 'CONFIRMADA');
        }

        expect(rn04CalcPendiente($item))->toBe($esperado);
    })->with([
        'una de 4' => [[4], 6],
        '4 y 6 (exacto al tope)' => [[4, 6], 0],
        '1, 2 y 3' => [[1, 2, 3], 4],
        '10 de una vez' => [[10], 0],
        '9' => [[9], 1],
        'diez de 1' => [array_fill(0, 10, 1), 0],
    ]);

    it('RN-04-AC9: una PENDIENTE_AUTORIZACION de 3 sobre un ítem de 10 deja pendiente 7; al pasar a RECHAZADA vuelve a 10', function () {
        $item = rn04CalcItem(10);
        $id = rn04CalcDispensacion($item, 3, 'PENDIENTE_AUTORIZACION');

        expect(rn04CalcPendiente($item))->toBe(7);

        $actualizadas = DB::table('dispensaciones')->where('id', $id)->update([
            'estado' => 'RECHAZADA',
            'rechazado_por_id' => rn04CalcUsuario('rechazador')->id,
            'rechazado_en' => now(),
            'motivo_rechazo' => 'Rechazo sintético de prueba RN-04',
        ]);
        expect($actualizadas)->toBe(1)
            ->and(rn04CalcPendiente($item))->toBe(10);
    });

    it('RN-04-AC9: una PENDIENTE_AUTORIZACION de 3 que se autoriza (CONFIRMADA con autorizador) sigue restando 3', function () {
        $item = rn04CalcItem(10);
        $id = rn04CalcDispensacion($item, 3, 'PENDIENTE_AUTORIZACION');

        DB::table('dispensaciones')->where('id', $id)->update([
            'estado' => 'CONFIRMADA',
            'autorizado_por_id' => rn04CalcUsuario('autorizador')->id,
            'autorizado_en' => now(),
        ]);

        expect(rn04CalcPendiente($item))->toBe(7);
    });

    it('RN-04-AC9: las RECHAZADA se excluyen (aunque sumen más que lo prescrito)', function (array $rechazadas, int $esperado) {
        $item = rn04CalcItem(10);
        foreach ($rechazadas as $r) {
            rn04CalcDispensacion($item, $r, 'RECHAZADA');
        }

        expect(rn04CalcPendiente($item))->toBe($esperado);
    })->with([
        'una de 3' => [[3], 10],
        'una de 10' => [[10], 10],
        'varias que suman 25' => [[10, 10, 5], 10],
    ]);

    it('RN-04-AC5 RN-04-AC9: mezcla de estados: CONFIRMADA 2 + PENDIENTE 3 + RECHAZADA 4 sobre 10 → 5; con una CONFIRMADA más de 5 → 0', function () {
        $item = rn04CalcItem(10);
        rn04CalcDispensacion($item, 2, 'CONFIRMADA');
        rn04CalcDispensacion($item, 3, 'PENDIENTE_AUTORIZACION');
        rn04CalcDispensacion($item, 4, 'RECHAZADA');

        expect(rn04CalcPendiente($item))->toBe(5);

        rn04CalcDispensacion($item, 5, 'CONFIRMADA');

        expect(rn04CalcPendiente($item))->toBe(0);
    });

    it('RN-04-AC5: varios ítems de la misma prescripción son independientes', function () {
        $prescripcion = Prescripcion::factory()->create();
        $a = rn04CalcItem(10, $prescripcion);
        $b = rn04CalcItem(5, $prescripcion);
        $c = rn04CalcItem(7, $prescripcion);

        rn04CalcDispensacion($a, 4, 'CONFIRMADA');
        rn04CalcDispensacion($b, 5, 'PENDIENTE_AUTORIZACION');

        expect(rn04CalcPendiente($a))->toBe(6)
            ->and(rn04CalcPendiente($b))->toBe(0)
            ->and(rn04CalcPendiente($c))->toBe(7);
    });

    it('RN-04-AC5 RN-04-AC4: lo dispensado del mismo producto en otra prescripción (del mismo paciente o de otro) no cuenta', function () {
        $producto = Producto::factory()->create();
        $prescripcion = Prescripcion::factory()->create();
        $item = rn04CalcItem(10, $prescripcion, $producto);

        $otraMismoPaciente = Prescripcion::factory()->create(['paciente_id' => $prescripcion->paciente_id]);
        rn04CalcDispensacion(rn04CalcItem(10, $otraMismoPaciente, $producto), 8, 'CONFIRMADA');
        rn04CalcDispensacion(rn04CalcItem(10, null, $producto), 9, 'PENDIENTE_AUTORIZACION');

        expect(rn04CalcPendiente($item))->toBe(10);
    });

    it('RN-04-AC5: el pendiente no depende de la bodega: dispensaciones del ítem desde bodegas distintas se suman', function () {
        $item = rn04CalcItem(10);
        // rn04CalcDispensacion crea una bodega nueva en cada llamada.
        rn04CalcDispensacion($item, 3, 'CONFIRMADA');
        rn04CalcDispensacion($item, 4, 'CONFIRMADA');

        expect(DB::table('dispensaciones')->distinct()->count('bodega_id'))->toBe(2)
            ->and(rn04CalcPendiente($item))->toBe(3);
    });

    it('RN-04-AC5: lo calcula desde la BD en cada llamada (no cachea): 10 → 6 → 0', function () {
        $item = rn04CalcItem(10);
        $calculador = app(CalculadorPendiente::class);

        expect($calculador->pendiente($item))->toBe(10);
        rn04CalcDispensacion($item, 4, 'CONFIRMADA');
        expect($calculador->pendiente($item))->toBe(6);
        rn04CalcDispensacion($item, 6, 'PENDIENTE_AUTORIZACION');
        expect($calculador->pendiente($item))->toBe(0);
    });
});
