<?php

declare(strict_types=1);

use App\Enums\TipoMovimiento;
use App\Models\AuditoriaAcceso;
use App\Models\Bodega;
use App\Models\Dispensacion;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Paciente;
use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Models\Producto;
use App\Models\User;
use App\Services\Inventario\RegistradorKardex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * S-89 — Listado de dispensaciones (GET /api/v1/dispensaciones), panel «Pendientes de
 * autorización» del regente (RN-05: la dispensación con control especial queda
 * PENDIENTE_AUTORIZACION). Supuestos: S-25/S-28/S-88 (paginación y filtros), S-77 (mismos roles
 * que el detalle), RN-10 (sin el objeto `paciente` y sin filas de bitácora).
 *
 * Contrato que se prueba:
 *  - Query `estado` ∈ CONFIRMADA | PENDIENTE_AUTORIZACION | RECHAZADA, `bodega_id`, `por_pagina`
 *    (1–100, 25), `page` (≤ 1.000.000); mal formados → 422 VALIDACION; id inexistente → 200 vacío.
 *  - Orden created_at ASC, desempate por id.
 *  - Cada ítem: forma de DispensacionResource + `dispensado_por {id, nombre}`, sin `paciente` ni
 *    `detalles`. No escribe en auditoria_accesos (ni en 200 ni en 403).
 *  - Roles: auxiliar, regente, auditor → 200; médico, admin → 403 NO_AUTORIZADO; sin token → 401.
 *  - Sin N+1: el número de consultas no crece con el número de dispensaciones.
 */

const DL_URI = '/api/v1/dispensaciones';
const DL_HOY = '2026-11-04';

function dlCongelar(string $hora = '10:00:00'): void
{
    test()->travelTo(Carbon::parse(DL_HOY.' '.$hora, 'America/Bogota'));
}

/**
 * Prescripción vigente de un producto (con o sin control especial) con 50 u. en la bodega por una
 * ENTRADA real de kardex. El paciente lleva datos sintéticos fijos y reconocibles.
 *
 * @param  array<string, string>  $paciente
 * @return array{bodega: Bodega, prescripcion: Prescripcion, item: PrescripcionItem}
 */
function dlEscenario(Bodega $bodega, bool $control, array $paciente = []): array
{
    $producto = $control ? Producto::factory()->controlEspecial()->create() : Producto::factory()->create();
    $lote = Lote::factory()->create(['producto_id' => $producto->id]);
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);
    $regente = usuarioConRol('regente_farmacia');
    DB::transaction(function () use ($existencia, $regente): void {
        $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
        app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, 50, $regente->id);
    });

    $prescripcion = Prescripcion::factory()->create(['paciente_id' => Paciente::factory()->create($paciente)->id]);
    $item = PrescripcionItem::factory()->create([
        'prescripcion_id' => $prescripcion->id,
        'producto_id' => $producto->id,
        'cantidad' => 30,
    ]);

    return compact('bodega', 'prescripcion', 'item');
}

/**
 * POST /dispensaciones real (1 u.) como `$como`; devuelve el id creado.
 *
 * @param  array{bodega: Bodega, prescripcion: Prescripcion, item: PrescripcionItem}  $s
 */
function dlDispensar(User $como, array $s): int
{
    Sanctum::actingAs($como);
    $r = test()->postJson(DL_URI, [
        'paciente_id' => $s['prescripcion']->paciente_id,
        'prescripcion_id' => $s['prescripcion']->id,
        'bodega_id' => $s['bodega']->id,
        'items' => [['prescripcion_item_id' => $s['item']->id, 'cantidad' => 1]],
    ], cabecerasDispensacion());
    $r->assertCreated();

    return (int) $r->json('data.id');
}

/** Rechaza por la API (regente distinto del creador). */
function dlRechazar(int $id): void
{
    Sanctum::actingAs(usuarioConRol('regente_farmacia'));
    test()->postJson(DL_URI."/{$id}/rechazar", ['motivo' => 'Soporte de control especial incompleto'])
        ->assertOk()
        ->assertJsonPath('data.estado', 'RECHAZADA');
}

/** GET del listado como un rol nuevo. */
function dlListar(string $query = '', string $rol = 'regente_farmacia'): TestResponse
{
    actuarComo($rol);

    return test()->getJson(DL_URI.($query === '' ? '' : '?'.$query));
}

/**
 * Mismo created_at para todas, actualizándolas una a una en el orden dado (inverso de id): cada
 * UPDATE escribe una versión nueva de la fila, así el orden físico deja de ser el de id.
 *
 * @param  list<int>  $ids
 */
function dlEmpatar(array $ids, Carbon $instante): void
{
    foreach ($ids as $id) {
        Dispensacion::query()->whereKey($id)->update(['created_at' => $instante]);
    }
}

/** @return list<int> ids de la página en el orden de la respuesta */
function dlIds(TestResponse $r): array
{
    return array_map(fn (array $d): int => $d['id'], $r->json('data'));
}

/**
 * Mundo de referencia: dos bodegas; en A una confirmada, dos pendientes y una rechazada; en B
 * una pendiente y una confirmada. Cada creación un minuto después de la anterior.
 *
 * @return array{a: Bodega, b: Bodega, ids: array<string, int>}
 */
function dlMundo(): array
{
    dlCongelar();
    $a = Bodega::factory()->create();
    $b = Bodega::factory()->create();
    $auxiliar = usuarioConRol('auxiliar_farmacia');
    $ids = [];
    $minuto = 0;
    $crear = function (string $clave, Bodega $bodega, bool $control) use ($auxiliar, &$ids, &$minuto): void {
        dlCongelar(sprintf('10:%02d:00', $minuto++));
        $ids[$clave] = dlDispensar($auxiliar, dlEscenario($bodega, $control));
    };

    $crear('a_confirmada', $a, false);
    $crear('a_pendiente_1', $a, true);
    $crear('b_pendiente', $b, true);
    $crear('a_rechazada', $a, true);
    $crear('a_pendiente_2', $a, true);
    $crear('b_confirmada', $b, false);
    dlRechazar($ids['a_rechazada']);

    return ['a' => $a, 'b' => $b, 'ids' => $ids];
}

describe('S-89 listado de dispensaciones', function () {
    it('S-89: filtra por estado; las pendientes creadas con control especial por la API salen en el panel', function (string $estado, array $esperadas) {
        $m = dlMundo();

        $r = dlListar('estado='.$estado);

        $r->assertOk();
        expect(dlIds($r))->toBe(array_map(fn (string $c): int => $m['ids'][$c], $esperadas))
            ->and(collect($r->json('data'))->pluck('estado')->unique()->values()->all())->toBe([$estado])
            ->and($r->json('meta.total'))->toBe(count($esperadas));
    })->with([
        'PENDIENTE_AUTORIZACION' => ['PENDIENTE_AUTORIZACION', ['a_pendiente_1', 'b_pendiente', 'a_pendiente_2']],
        'CONFIRMADA' => ['CONFIRMADA', ['a_confirmada', 'b_confirmada']],
        'RECHAZADA' => ['RECHAZADA', ['a_rechazada']],
    ]);

    it('S-89: filtra por bodega, combina bodega y estado, y sin filtros devuelve todas', function () {
        $m = dlMundo();
        $i = $m['ids'];

        expect(dlIds(dlListar('bodega_id='.$m['b']->id)))->toBe([$i['b_pendiente'], $i['b_confirmada']])
            ->and(dlIds(dlListar('bodega_id='.$m['a']->id.'&estado=PENDIENTE_AUTORIZACION')))->toBe([$i['a_pendiente_1'], $i['a_pendiente_2']])
            ->and(dlIds(dlListar('bodega_id='.$m['b']->id.'&estado=RECHAZADA')))->toBe([])
            ->and(dlIds(dlListar()))->toBe(array_values($i));
    });

    it('S-89: cada ítem trae la forma de DispensacionResource con dispensado_por y sin paciente ni detalles', function () {
        dlCongelar();
        $auxiliar = usuarioConRol('auxiliar_farmacia', ['name' => 'Auxiliar Sintético Uno']);
        $s = dlEscenario(Bodega::factory()->create(), true);
        $id = dlDispensar($auxiliar, $s);

        $r = dlListar('estado=PENDIENTE_AUTORIZACION');

        $r->assertOk()->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
        $d = $r->json('data.0');
        expect(array_keys($d))->toBe([
            'id', 'estado', 'paciente_id', 'prescripcion_id', 'bodega_id', 'dispensado_por_id', 'dispensado_por',
            'autorizado_por_id', 'autorizado_en', 'rechazado_por_id', 'rechazado_en', 'motivo_rechazo', 'items', 'created_at',
        ])
            ->and($d['id'])->toBe($id)
            ->and($d['paciente_id'])->toBe($s['prescripcion']->paciente_id)
            ->and($d['dispensado_por'])->toBe(['id' => $auxiliar->id, 'nombre' => 'Auxiliar Sintético Uno'])
            ->and($d['dispensado_por_id'])->toBe($auxiliar->id)
            ->and($d['items'])->toHaveCount(1)
            ->and($d['items'][0]['prescripcion_item_id'])->toBe($s['item']->id)
            ->and($d['items'][0]['cantidad'])->toBe(1)
            ->and($d['items'][0]['producto']['id'])->toBe($s['item']->producto_id)
            ->and($d['items'][0]['producto']['control_especial'])->toBeTrue()
            // Hora de negocio con su desfase, tal como lo documenta el contrato (§1.6).
            ->and($d['created_at'])->toBe(DL_HOY.'T10:00:00-05:00');
    });

    it('S-89: orden por created_at ascendente con desempate por id', function () {
        dlCongelar();
        $auxiliar = usuarioConRol('auxiliar_farmacia');
        $s = dlEscenario(Bodega::factory()->create(), true);
        $tarde = dlDispensar($auxiliar, $s);   // menor id, pero más reciente
        $primero = dlDispensar($auxiliar, $s);
        $segundo = dlDispensar($auxiliar, $s);
        $tercero = dlDispensar($auxiliar, $s);
        Dispensacion::query()->whereKey($tarde)->update(['created_at' => now()->addHour()]);
        // Mismo instante para los otros tres: solo el id los ordena. Una a una y en orden inverso de
        // id, para que el orden físico de las filas (heap) no coincida con el esperado.
        dlEmpatar([$tercero, $segundo, $primero], now()->subHour());

        expect(dlIds(dlListar()))->toBe([$primero, $segundo, $tercero, $tarde]);
    });

    it('S-89: pagina con 25 por defecto y respeta por_pagina 1 y 100 y page', function () {
        dlCongelar();
        $auxiliar = usuarioConRol('auxiliar_farmacia');
        $s = dlEscenario(Bodega::factory()->create(), false);
        $ids = [];
        for ($n = 0; $n < 27; $n++) {
            $ids[] = dlDispensar($auxiliar, $s);
        }
        dlEmpatar(array_reverse($ids), now());

        $defecto = dlListar();
        expect(dlIds($defecto))->toBe(array_slice($ids, 0, 25))
            ->and($defecto->json('meta.per_page'))->toBe(25)
            ->and($defecto->json('meta.total'))->toBe(27)
            ->and($defecto->json('meta.last_page'))->toBe(2);

        expect(dlIds(dlListar('page=2')))->toBe(array_slice($ids, 25));

        $uno = dlListar('por_pagina=1&page=3&estado=CONFIRMADA');
        expect(dlIds($uno))->toBe([$ids[2]])
            ->and($uno->json('meta.per_page'))->toBe(1)
            ->and($uno->json('links.next'))->toContain('estado=CONFIRMADA');

        expect(dlIds(dlListar('por_pagina=100')))->toBe($ids);

        $vacia = dlListar('page=1000000');
        $vacia->assertOk();
        expect($vacia->json('data'))->toBe([]);
    });

    it('S-89: filtros y paginación mal formados → 422 VALIDACION en el campo', function (string $query, string $campo) {
        $antes = AuditoriaAcceso::query()->count();

        $r = dlListar($query);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($campo)
            ->and(AuditoriaAcceso::query()->count())->toBe($antes);
    })->with([
        'estado desconocido' => ['estado=ANULADA', 'estado'],
        'estado en minúsculas' => ['estado=pendiente_autorizacion', 'estado'],
        'estado vacío' => ['estado=', 'estado'],
        'estado como lista' => ['estado[]=CONFIRMADA', 'estado'],
        'bodega vacía' => ['bodega_id=', 'bodega_id'],
        'bodega texto' => ['bodega_id=abc', 'bodega_id'],
        'bodega 0' => ['bodega_id=0', 'bodega_id'],
        'bodega negativa' => ['bodega_id=-1', 'bodega_id'],
        'bodega decimal' => ['bodega_id=1.5', 'bodega_id'],
        'bodega fuera de bigint' => ['bodega_id=99999999999999999999', 'bodega_id'],
        'por_pagina 0' => ['por_pagina=0', 'por_pagina'],
        'por_pagina 101' => ['por_pagina=101', 'por_pagina'],
        'por_pagina texto' => ['por_pagina=abc', 'por_pagina'],
        'page texto' => ['page=abc', 'page'],
        'page 0' => ['page=0', 'page'],
        'page 1.000.001' => ['page=1000001', 'page'],
    ]);

    it('S-89: bodega inexistente → 200 con lista vacía', function () {
        dlMundo();

        $r = dlListar('bodega_id=999999');

        $r->assertOk();
        expect($r->json('data'))->toBe([])
            ->and($r->json('meta.total'))->toBe(0);
    });

    it('RN-10 S-89: la respuesta no contiene datos personales del paciente y no escribe en la bitácora', function (string $rol) {
        dlCongelar();
        $paciente = [
            'numero_documento' => '987654321',
            'nombres' => 'Zuleimy Hortensia',
            'apellidos' => 'Quintanillo Brezal',
            'fecha_nacimiento' => '1971-03-09',
            'telefono' => '3009998877',
            'direccion' => 'Calle 97 # 86-75',
            'correo' => 'zuleimy.listado@ejemplo.test',
        ];
        dlDispensar(usuarioConRol('auxiliar_farmacia'), dlEscenario(Bodega::factory()->create(), true, $paciente));
        $antes = AuditoriaAcceso::query()->count();

        $r = dlListar('', $rol);

        $r->assertOk();
        $crudo = (string) $r->getContent();
        $legible = (string) json_encode($r->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ([...array_values($paciente), 'Zuleimy', 'Quintanillo', '1971', '@ejemplo.test'] as $valor) {
            expect($crudo)->not->toContain($valor)
                ->and($legible)->not->toContain($valor);
        }
        expect($r->json('data'))->toHaveCount(1)
            ->and($r->json('data.0'))->not->toHaveKey('paciente')
            ->and($r->json('data.0'))->not->toHaveKey('detalles')
            ->and(AuditoriaAcceso::query()->count())->toBe($antes);
    })->with(['regente_farmacia', 'auxiliar_farmacia', 'auditor']);

    it('S-89: auxiliar, regente y auditor → 200; médico y admin → 403 NO_AUTORIZADO sin fila en la bitácora', function (string $rol, bool $permitido) {
        $m = dlMundo();
        $antes = AuditoriaAcceso::query()->count();

        $r = dlListar('estado=PENDIENTE_AUTORIZACION', $rol);

        if ($permitido) {
            $r->assertOk();
            expect($r->json('meta.total'))->toBe(3);
        } else {
            esperarError($r, 403, 'NO_AUTORIZADO');
        }
        expect(AuditoriaAcceso::query()->count())->toBe($antes)
            ->and(Dispensacion::query()->count())->toBe(count($m['ids']));
    })->with([
        'auxiliar' => ['auxiliar_farmacia', true],
        'regente' => ['regente_farmacia', true],
        'auditor' => ['auditor', true],
        'médico' => ['medico', false],
        'admin' => ['admin', false],
    ]);

    it('S-89: médico y admin con query inválida → 403 NO_AUTORIZADO (el permiso va antes que la validación)', function (string $rol) {
        $antes = AuditoriaAcceso::query()->count();

        esperarError(dlListar('estado=ANULADA&por_pagina=101&page=abc', $rol), 403, 'NO_AUTORIZADO');
        expect(AuditoriaAcceso::query()->count())->toBe($antes);
    })->with(['medico', 'admin']);

    it('S-89: sin token → 401 NO_AUTENTICADO', function () {
        esperarError(test()->getJson(DL_URI), 401, 'NO_AUTENTICADO');
    });

    it('S-89: sin N+1: las consultas no crecen con el número de dispensaciones, ítems y creadores', function () {
        dlCongelar();
        $contar = function (): int {
            actuarComo('regente_farmacia');
            DB::flushQueryLog();
            DB::enableQueryLog();
            test()->getJson(DL_URI)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $bodega = Bodega::factory()->create();
        dlDispensar(usuarioConRol('auxiliar_farmacia'), dlEscenario($bodega, true));
        $conUna = $contar();

        for ($n = 0; $n < 6; $n++) {
            dlDispensar(usuarioConRol($n % 2 === 0 ? 'auxiliar_farmacia' : 'regente_farmacia'), dlEscenario($bodega, $n % 2 === 0));
        }
        $conSiete = $contar();

        expect(Dispensacion::query()->count())->toBe(7)
            ->and($conSiete)->toBe($conUna);
    });

    it('S-89: índices del listado: (estado, created_at, id) sustituye al simple de estado y existe (created_at, id)', function () {
        $indices = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'dispensaciones'"))
            ->mapWithKeys(fn (object $i): array => [$i->indexname => $i->indexdef]);

        expect($indices->get('dispensaciones_estado_listado_index'))->toContain('(estado, created_at, id)')
            ->and($indices->get('dispensaciones_listado_index'))->toContain('(created_at, id)')
            ->and($indices->has('dispensaciones_estado_index'))->toBeFalse();
    });
});
