<?php

declare(strict_types=1);

use App\Enums\TipoMovimiento;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\MovimientoKardex;
use App\Models\Producto;
use App\Models\StockMinimo;
use App\Models\User;
use App\Services\Inventario\AjusteInventarioService;
use App\Services\Inventario\DatosAjuste;
use App\Services\Inventario\RegistradorKardex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * RN-11 — Alertas de vencimiento y stock mínimo (rn10-rn11.md, AC1..AC7).
 *
 * Supuestos: S-01 (vence hoy = vencido; "hoy" en America/Bogota), S-13 (admin no consulta
 * inventario ni alertas → 403), S-14 (tabla stock_minimos bodega+producto), S-15 (stock que cuenta
 * = suma de lotes NO vencidos del producto en la bodega; alerta si es estrictamente menor),
 * S-25/S-28 (paginación 25 por defecto, máx. 100; `page` ≤ 1.000.000; filtros mal formados → 422
 * VALIDACION; id inexistente → 200 vacío).
 *
 * El stock se carga por el camino real del kardex (RegistradorKardex, ENTRADA, cadena desde 0) y se
 * lleva a 0 con AjusteInventarioService (AJUSTE con motivo). El reloj se congela en RN11_HOY.
 *
 * Contrato HTTP supuesto (ver informe):
 *  - GET /api/v1/alertas/vencimientos?bodega_id&producto_id&tipo&page&por_pagina → {data: [ {bodega.id,
 *    producto.id, lote.id, fecha_vencimiento (Y-m-d), cantidad, tipo (VENCIDO|POR_VENCER),
 *    dias_restantes} ], meta: {per_page, total, …}} ordenado por fecha_vencimiento ASC.
 *  - GET /api/v1/alertas/stock-minimo?bodega_id&producto_id → {data: [ {bodega.id, producto.id, minimo,
 *    actual, faltante} ]}.
 *  Los ayudantes rn11AlertasVenc / rn11AlertasMin aplanan bodega.id, producto.id y lote.id a
 *  bodega_id, producto_id y lote_id para comparar.
 */

const RN11_URI_VENCIMIENTOS = '/api/v1/alertas/vencimientos';
const RN11_URI_STOCK_MINIMO = '/api/v1/alertas/stock-minimo';
const RN11_HOY = '2026-11-05';
const RN11_ZONA = 'America/Bogota';

/*
|--------------------------------------------------------------------------
| Ayudantes (prefijo rn11)
|--------------------------------------------------------------------------
*/

/** Congela el reloj en el día dado (Bogotá) a la hora dada. */
function rn11Congelar(string $hora = '10:00:00', string $dia = RN11_HOY): void
{
    test()->travelTo(Carbon::parse($dia.' '.$hora, RN11_ZONA));
}

/** Fecha Y-m-d a N días de RN11_HOY. */
function rn11Dia(int $dias): string
{
    return Carbon::parse(RN11_HOY, RN11_ZONA)->addDays($dias)->toDateString();
}

function rn11Carga(): User
{
    return User::query()->where('email', 'rn11-carga@farma.test')->first()
        ?? usuarioConRol('regente_farmacia', ['email' => 'rn11-carga@farma.test']);
}

/** Lote del producto que vence a N días de RN11_HOY (o en la fecha Y-m-d dada). */
function rn11Lote(Producto $producto, int|string $vence, ?string $codigo = null): Lote
{
    static $n = 0;
    $n++;

    return Lote::factory()->create([
        'producto_id' => $producto->id,
        'codigo' => $codigo ?? sprintf('RN11-%04d', $n),
        'fecha_vencimiento' => is_int($vence) ? rn11Dia($vence) : $vence,
    ]);
}

/** Existencia (bodega, lote) con `cantidad` cargada por una ENTRADA real del kardex. */
function rn11Stock(Bodega $bodega, Lote $lote, int $cantidad): Existencia
{
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);

    if ($cantidad > 0) {
        $carga = rn11Carga();
        DB::transaction(function () use ($existencia, $cantidad, $carga): void {
            $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
            app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $carga->id);
        });
    }

    return $existencia->refresh();
}

/** Lleva la existencia a `nuevo` con un AJUSTE real (servicio de ajustes, RN-06-AC6). */
function rn11AjustarA(Existencia $existencia, int $nuevo): void
{
    $delta = $nuevo - $existencia->refresh()->cantidad;
    if ($delta !== 0) {
        app(AjusteInventarioService::class)->registrar(new DatosAjuste(
            usuarioId: rn11Carga()->id,
            existenciaId: $existencia->id,
            cantidad: $delta,
            motivo: 'Conteo físico de cierre RN-11',
        ));
    }
}

function rn11Minimo(Bodega $bodega, Producto $producto, int $minimo): StockMinimo
{
    return StockMinimo::factory()->create([
        'bodega_id' => $bodega->id,
        'producto_id' => $producto->id,
        'cantidad_minima' => $minimo,
    ]);
}

/** Estado completo de inventario y kardex: una consulta de alertas no debe cambiarlo. */
function rn11Instantanea(): array
{
    return [
        'existencias' => Existencia::query()->orderBy('id')->pluck('cantidad', 'id')->all(),
        'kardex' => MovimientoKardex::query()->count(),
        'minimos' => StockMinimo::query()->orderBy('id')->pluck('cantidad_minima', 'id')->all(),
    ];
}

/**
 * Alertas de vencimiento indexadas por "bodega_id:lote_id".
 *
 * @return Collection<string, array<string, mixed>>
 */
function rn11AlertasVenc(TestResponse $r): Collection
{
    $r->assertOk();
    $data = $r->json('data');
    expect($data)->toBeArray();

    $porClave = collect($data)
        ->map(fn (array $a) => [
            'bodega_id' => $a['bodega']['id'] ?? null,
            'producto_id' => $a['producto']['id'] ?? null,
            'lote_id' => $a['lote']['id'] ?? null,
        ] + $a)
        ->keyBy(fn (array $a) => $a['bodega_id'].':'.$a['lote_id']);
    expect($porClave)->toHaveCount(count($data)); // sin duplicados (bodega, lote)

    return $porClave;
}

/**
 * Alertas de stock mínimo indexadas por "bodega_id:producto_id".
 *
 * @return Collection<string, array<string, mixed>>
 */
function rn11AlertasMin(TestResponse $r): Collection
{
    $r->assertOk();
    $data = $r->json('data');
    expect($data)->toBeArray();

    $porClave = collect($data)
        ->map(fn (array $a) => [
            'bodega_id' => $a['bodega']['id'] ?? null,
            'producto_id' => $a['producto']['id'] ?? null,
        ] + $a)
        ->keyBy(fn (array $a) => $a['bodega_id'].':'.$a['producto_id']);
    expect($porClave)->toHaveCount(count($data)); // una fila por (bodega, producto)

    return $porClave;
}

function rn11Clave(Bodega $bodega, Lote|Producto $modelo): string
{
    return $bodega->id.':'.$modelo->id;
}

beforeEach(function () {
    rn11Congelar();
});

/*
|--------------------------------------------------------------------------
| Vencimientos
|--------------------------------------------------------------------------
*/

describe('RN-11-AC1 lotes por vencer (≤ 90 días)', function () {
    it('RN-11-AC1: lotes con stock a +1, +90 y +91 → aparecen +1 y +90 (POR_VENCER, dias_restantes 1 y 90) y no +91', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $l1 = rn11Lote($producto, 1);
        $l90 = rn11Lote($producto, 90);
        $l91 = rn11Lote($producto, 91);
        rn11Stock($bodega, $l1, 5);
        rn11Stock($bodega, $l90, 7);
        rn11Stock($bodega, $l91, 9);
        actuarComo('regente_farmacia');
        $antes = rn11Instantanea();

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        expect($alertas->keys()->all())->toBe([rn11Clave($bodega, $l1), rn11Clave($bodega, $l90)])
            ->and($alertas[rn11Clave($bodega, $l1)])->toMatchArray([
                'bodega_id' => $bodega->id,
                'producto_id' => $producto->id,
                'lote_id' => $l1->id,
                'fecha_vencimiento' => rn11Dia(1),
                'cantidad' => 5,
                'tipo' => 'POR_VENCER',
                'dias_restantes' => 1,
            ])
            ->and($alertas[rn11Clave($bodega, $l90)])->toMatchArray([
                'bodega_id' => $bodega->id,
                'producto_id' => $producto->id,
                'lote_id' => $l90->id,
                'fecha_vencimiento' => rn11Dia(90),
                'cantidad' => 7,
                'tipo' => 'POR_VENCER',
                'dias_restantes' => 90,
            ])
            ->and(rn11Instantanea())->toBe($antes);
    });

    it('RN-11-AC1 frontera: un lote con stock a N días aparece como POR_VENCER con dias_restantes N solo si N ≤ 90', function (int $dias, bool $aparece) {
        $bodega = Bodega::factory()->create();
        $lote = rn11Lote(Producto::factory()->create(), $dias);
        rn11Stock($bodega, $lote, 3);
        actuarComo('auxiliar_farmacia');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        if ($aparece) {
            expect($alertas)->toHaveCount(1)
                ->and($alertas[rn11Clave($bodega, $lote)]['tipo'])->toBe('POR_VENCER')
                ->and($alertas[rn11Clave($bodega, $lote)]['dias_restantes'])->toBe($dias)
                ->and($alertas[rn11Clave($bodega, $lote)]['cantidad'])->toBe(3);
        } else {
            expect($alertas)->toHaveCount(0);
        }
    })->with([
        '+1' => [1, true],
        '+2' => [2, true],
        '+89' => [89, true],
        '+90' => [90, true],
        '+91' => [91, false],
        '+92' => [92, false],
        '+400' => [400, false],
    ]);

    it('RN-11-AC1: el mismo lote en dos bodegas genera una alerta por bodega, cada una con su cantidad', function () {
        $b1 = Bodega::factory()->create();
        $b2 = Bodega::factory()->create();
        $lote = rn11Lote(Producto::factory()->create(), 30);
        rn11Stock($b1, $lote, 4);
        rn11Stock($b2, $lote, 11);
        actuarComo('auditor');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        expect($alertas)->toHaveCount(2)
            ->and($alertas[rn11Clave($b1, $lote)]['cantidad'])->toBe(4)
            ->and($alertas[rn11Clave($b2, $lote)]['cantidad'])->toBe(11)
            ->and($alertas[rn11Clave($b1, $lote)]['dias_restantes'])->toBe(30)
            ->and($alertas[rn11Clave($b2, $lote)]['dias_restantes'])->toBe(30);
    });

    it('RN-11-AC1 zona horaria: a las 23:59:59 de Bogotá (04:59:59 UTC del día siguiente) un lote que vence mañana tiene 1 día y +90 sigue en 90', function () {
        $this->travelTo(Carbon::parse('2026-11-06 04:59:59', 'UTC')); // = 2026-11-05 23:59:59 Bogotá
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $hoy = rn11Lote($producto, '2026-11-05');
        $manana = rn11Lote($producto, '2026-11-06');
        $mas90 = rn11Lote($producto, '2027-02-03');
        $mas91 = rn11Lote($producto, '2027-02-04');
        foreach ([$hoy, $manana, $mas90, $mas91] as $lote) {
            rn11Stock($bodega, $lote, 2);
        }
        actuarComo('regente_farmacia');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        expect($alertas->keys()->all())->toBe([rn11Clave($bodega, $hoy), rn11Clave($bodega, $manana), rn11Clave($bodega, $mas90)])
            ->and($alertas[rn11Clave($bodega, $hoy)]['tipo'])->toBe('VENCIDO')
            ->and($alertas[rn11Clave($bodega, $manana)]['tipo'])->toBe('POR_VENCER')
            ->and($alertas[rn11Clave($bodega, $manana)]['dias_restantes'])->toBe(1)
            ->and($alertas[rn11Clave($bodega, $mas90)]['dias_restantes'])->toBe(90);
    });

    it('RN-11-AC1 RN-11-AC2 zona horaria: a las 00:00 de Bogotá (05:00 UTC) cambia el día: lo de "mañana" pasa a VENCIDO y +91 entra con 90', function () {
        $this->travelTo(Carbon::parse('2026-11-06 05:00:00', 'UTC')); // = 2026-11-06 00:00:00 Bogotá
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $ayer = rn11Lote($producto, '2026-11-05');
        $hoy = rn11Lote($producto, '2026-11-06');
        $mas89 = rn11Lote($producto, '2027-02-03');
        $mas90 = rn11Lote($producto, '2027-02-04');
        foreach ([$ayer, $hoy, $mas89, $mas90] as $lote) {
            rn11Stock($bodega, $lote, 2);
        }
        actuarComo('regente_farmacia');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        expect($alertas)->toHaveCount(4)
            ->and($alertas[rn11Clave($bodega, $ayer)]['tipo'])->toBe('VENCIDO')
            ->and($alertas[rn11Clave($bodega, $hoy)]['tipo'])->toBe('VENCIDO')
            ->and($alertas[rn11Clave($bodega, $mas89)]['tipo'])->toBe('POR_VENCER')
            ->and($alertas[rn11Clave($bodega, $mas89)]['dias_restantes'])->toBe(89)
            ->and($alertas[rn11Clave($bodega, $mas90)]['dias_restantes'])->toBe(90);
    });

    it('RN-11-AC1 umbral configurable: con farma.dias_alerta_vencimiento = 30, +45 y +31 no aparecen y +30 sí (dias_restantes 30)', function () {
        config(['farma.dias_alerta_vencimiento' => 30]);
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $lotes = [];
        foreach ([-1, 29, 30, 31, 45, 90] as $dias) {
            $lotes[$dias] = rn11Lote($producto, $dias);
            rn11Stock($bodega, $lotes[$dias], 6);
        }
        actuarComo('regente_farmacia');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        expect($alertas->keys()->all())->toBe([rn11Clave($bodega, $lotes[-1]), rn11Clave($bodega, $lotes[29]), rn11Clave($bodega, $lotes[30])])
            ->and($alertas[rn11Clave($bodega, $lotes[-1])]['tipo'])->toBe('VENCIDO')
            ->and($alertas[rn11Clave($bodega, $lotes[29])]['dias_restantes'])->toBe(29)
            ->and($alertas[rn11Clave($bodega, $lotes[30])]['dias_restantes'])->toBe(30);
    });

    it('RN-11-AC1 umbral configurable: con farma.dias_alerta_vencimiento = 120, +91 y +120 aparecen y +121 no', function () {
        config(['farma.dias_alerta_vencimiento' => 120]);
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $lotes = [];
        foreach ([91, 120, 121] as $dias) {
            $lotes[$dias] = rn11Lote($producto, $dias);
            rn11Stock($bodega, $lotes[$dias], 6);
        }
        actuarComo('auditor');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        expect($alertas->keys()->all())->toBe([rn11Clave($bodega, $lotes[91]), rn11Clave($bodega, $lotes[120])])
            ->and($alertas[rn11Clave($bodega, $lotes[91])]['dias_restantes'])->toBe(91)
            ->and($alertas[rn11Clave($bodega, $lotes[120])]['dias_restantes'])->toBe(120);
    });
});

describe('RN-11-AC2 lotes vencidos con stock', function () {
    it('RN-11-AC2 S-01: un lote con stock que vence hoy o antes aparece con tipo VENCIDO', function (int $dias) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $lote = rn11Lote($producto, $dias);
        rn11Stock($bodega, $lote, 8);
        actuarComo('regente_farmacia');
        $antes = rn11Instantanea();

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        expect($alertas)->toHaveCount(1)
            ->and($alertas[rn11Clave($bodega, $lote)])->toMatchArray([
                'bodega_id' => $bodega->id,
                'producto_id' => $producto->id,
                'lote_id' => $lote->id,
                'fecha_vencimiento' => rn11Dia($dias),
                'cantidad' => 8,
                'tipo' => 'VENCIDO',
            ])
            ->and(rn11Instantanea())->toBe($antes);
    })->with([
        'vence hoy (0)' => [0],
        'venció ayer (−1)' => [-1],
        'venció hace 2 años' => [-730],
    ]);

    it('RN-11-AC2: vencidos y por vencer conviven: hoy y ayer → VENCIDO, mañana → POR_VENCER 1', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $ayer = rn11Lote($producto, -1);
        $hoy = rn11Lote($producto, 0);
        $manana = rn11Lote($producto, 1);
        foreach ([$ayer, $hoy, $manana] as $lote) {
            rn11Stock($bodega, $lote, 1);
        }
        actuarComo('auxiliar_farmacia');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        expect($alertas->map(fn (array $a) => $a['tipo'])->all())->toBe([
            rn11Clave($bodega, $ayer) => 'VENCIDO',
            rn11Clave($bodega, $hoy) => 'VENCIDO',
            rn11Clave($bodega, $manana) => 'POR_VENCER',
        ])->and($alertas[rn11Clave($bodega, $manana)]['dias_restantes'])->toBe(1);
    });
});

describe('RN-11-AC3 sin stock no hay alerta', function () {
    it('RN-11-AC3: un lote por vencer que se llevó a 0 con un ajuste real no aparece', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $sinStock = rn11Lote($producto, 10);
        $conStock = rn11Lote($producto, 20);
        $existencia = rn11Stock($bodega, $sinStock, 5);
        rn11Stock($bodega, $conStock, 5);
        rn11AjustarA($existencia, 0);
        expect($existencia->refresh()->cantidad)->toBe(0)
            ->and(MovimientoKardex::query()->where('existencia_id', $existencia->id)->count())->toBe(2);
        actuarComo('regente_farmacia');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        expect($alertas->keys()->all())->toBe([rn11Clave($bodega, $conStock)]);
    });

    it('RN-11-AC3: existencia en 0 de un lote que nunca tuvo stock (por vencer o vencido) no aparece', function (int $dias) {
        $bodega = Bodega::factory()->create();
        rn11Stock($bodega, rn11Lote(Producto::factory()->create(), $dias), 0);
        actuarComo('regente_farmacia');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        expect($alertas)->toHaveCount(0)
            ->and($this->getJson(RN11_URI_VENCIMIENTOS)->json('meta.total'))->toBe(0);
    })->with([
        'por vencer +1' => [1],
        'por vencer +90' => [90],
        'vence hoy' => [0],
        'vencido −5' => [-5],
    ]);

    it('RN-11-AC3: un lote vencido llevado a 0 con un ajuste (baja) deja de aparecer', function () {
        $bodega = Bodega::factory()->create();
        $existencia = rn11Stock($bodega, rn11Lote(Producto::factory()->create(), -3), 12);
        actuarComo('regente_farmacia');
        expect(rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS)))->toHaveCount(1);

        rn11AjustarA($existencia, 0);

        expect(rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS)))->toHaveCount(0);
    });

    it('RN-11-AC3: el mismo lote con 0 en una bodega y stock en otra solo alerta en la que tiene stock', function () {
        $b1 = Bodega::factory()->create();
        $b2 = Bodega::factory()->create();
        $lote = rn11Lote(Producto::factory()->create(), 15);
        $e1 = rn11Stock($b1, $lote, 9);
        rn11Stock($b2, $lote, 7);
        rn11AjustarA($e1, 0);
        actuarComo('auxiliar_farmacia');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        expect($alertas->keys()->all())->toBe([rn11Clave($b2, $lote)])
            ->and($alertas[rn11Clave($b2, $lote)]['cantidad'])->toBe(7);
    });
});

describe('RN-11-AC4 filtros, orden y paginación', function () {
    it('RN-11-AC4: filtro bodega_id → solo alertas de esa bodega', function () {
        $b1 = Bodega::factory()->create();
        $b2 = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $l1 = rn11Lote($producto, 5);
        $l2 = rn11Lote($producto, -2);
        $l3 = rn11Lote($producto, 60);
        rn11Stock($b1, $l1, 3);
        rn11Stock($b1, $l2, 3);
        rn11Stock($b2, $l1, 4);
        rn11Stock($b2, $l3, 4);
        actuarComo('regente_farmacia');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS.'?bodega_id='.$b1->id));

        expect($alertas->keys()->all())->toBe([rn11Clave($b1, $l2), rn11Clave($b1, $l1)])
            ->and($alertas->pluck('bodega_id')->unique()->values()->all())->toBe([$b1->id]);
    });

    it('RN-11-AC4: orden por fecha_vencimiento ASC, no por orden de creación ni por id', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $otro = Producto::factory()->create();
        $porDia = [];
        foreach ([60, -5, 2, 0, 90, -1, 30] as $i => $dias) {
            $porDia[$dias] = rn11Lote($i % 2 === 0 ? $producto : $otro, $dias);
            rn11Stock($bodega, $porDia[$dias], 1 + $i);
        }
        actuarComo('auditor');

        $r = $this->getJson(RN11_URI_VENCIMIENTOS);

        $r->assertOk();
        expect(collect($r->json('data'))->pluck('fecha_vencimiento')->all())->toBe(array_map(rn11Dia(...), [-5, -1, 0, 2, 30, 60, 90]))
            ->and(collect($r->json('data'))->pluck('lote.id')->all())->toBe(array_map(fn (int $d) => $porDia[$d]->id, [-5, -1, 0, 2, 30, 60, 90]));
    });

    it('RN-11-AC4: con empates de fecha el orden es determinista y la paginación no repite ni pierde alertas', function () {
        $b1 = Bodega::factory()->create();
        $b2 = Bodega::factory()->create();
        $productos = Producto::factory()->count(3)->create();
        $esperadas = [];
        for ($i = 0; $i < 6; $i++) {
            $lote = rn11Lote($productos[$i % 3], 30);
            rn11Stock($b1, $lote, 2);
            rn11Stock($b2, $lote, 3);
            $esperadas[] = rn11Clave($b1, $lote);
            $esperadas[] = rn11Clave($b2, $lote);
        }
        $primero = rn11Lote($productos[0], 29);
        rn11Stock($b2, $primero, 1);
        actuarComo('regente_farmacia');

        $paginas = [];
        foreach ([1, 2, 3] as $page) {
            $paginas[$page] = $this->getJson(RN11_URI_VENCIMIENTOS.'?por_pagina=5&page='.$page);
            $paginas[$page]->assertOk();
        }
        $repetida = $this->getJson(RN11_URI_VENCIMIENTOS.'?por_pagina=5&page=1');

        $claves = fn (TestResponse $r): array => collect($r->json('data'))->map(fn (array $a) => $a['bodega']['id'].':'.$a['lote']['id'])->all();
        $vistas = [...$claves($paginas[1]), ...$claves($paginas[2]), ...$claves($paginas[3])];
        $ordenadas = $vistas;
        sort($ordenadas);
        $todas = [...$esperadas, rn11Clave($b2, $primero)];
        sort($todas);

        expect($paginas[1]->json('data'))->toHaveCount(5)
            ->and($paginas[2]->json('data'))->toHaveCount(5)
            ->and($paginas[3]->json('data'))->toHaveCount(3)
            ->and($paginas[1]->json('meta.total'))->toBe(13)
            ->and($vistas[0])->toBe(rn11Clave($b2, $primero))
            ->and($ordenadas)->toBe($todas)
            ->and($claves($repetida))->toBe($claves($paginas[1]));
    });

    it('RN-11-AC4 S-25: pagina 25 por defecto; las páginas no se solapan y cubren todas las alertas', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $lote = rn11Lote($producto, $i - 5); // −5..+24: vencidos y por vencer
            rn11Stock($bodega, $lote, 1);
            $ids[] = $lote->id;
        }
        actuarComo('auxiliar_farmacia');

        $p1 = $this->getJson(RN11_URI_VENCIMIENTOS);
        $p2 = $this->getJson(RN11_URI_VENCIMIENTOS.'?page=2');
        $p3 = $this->getJson(RN11_URI_VENCIMIENTOS.'?page=3');

        $p1->assertOk();
        $p2->assertOk();
        $p3->assertOk();
        expect($p1->json('data'))->toHaveCount(25)
            ->and($p2->json('data'))->toHaveCount(5)
            ->and($p3->json('data'))->toBe([])
            ->and($p1->json('meta.per_page'))->toBe(25)
            ->and($p1->json('meta.total'))->toBe(30)
            ->and([...collect($p1->json('data'))->pluck('lote.id')->all(), ...collect($p2->json('data'))->pluck('lote.id')->all()])->toBe($ids);
    });

    it('RN-11-AC4 S-25: por_pagina admite hasta 100', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            rn11Stock($bodega, rn11Lote($producto, 10 + $i), 1);
        }
        actuarComo('regente_farmacia');

        $r1 = $this->getJson(RN11_URI_VENCIMIENTOS.'?por_pagina=1');
        $r100 = $this->getJson(RN11_URI_VENCIMIENTOS.'?por_pagina=100');

        $r1->assertOk();
        $r100->assertOk();
        expect($r1->json('data'))->toHaveCount(1)
            ->and($r1->json('meta.per_page'))->toBe(1)
            ->and($r1->json('meta.total'))->toBe(3)
            ->and($r100->json('data'))->toHaveCount(3)
            ->and($r100->json('meta.per_page'))->toBe(100);
    });

    it('RN-11-AC4: filtro producto_id → solo alertas de ese producto', function () {
        $bodega = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $l1 = rn11Lote($p1, 3);
        $l2 = rn11Lote($p2, 4);
        $l3 = rn11Lote($p1, -1);
        rn11Stock($bodega, $l1, 1);
        rn11Stock($bodega, $l2, 1);
        rn11Stock($bodega, $l3, 1);
        actuarComo('regente_farmacia');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS.'?producto_id='.$p1->id));

        expect($alertas->keys()->all())->toBe([rn11Clave($bodega, $l3), rn11Clave($bodega, $l1)]);
    });

    it('RN-11-AC4: filtro tipo → solo VENCIDO o solo POR_VENCER', function (string $tipo, array $diasEsperados) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $lotes = [];
        foreach ([-3, 0, 1, 90, 91] as $dias) {
            $lotes[$dias] = rn11Lote($producto, $dias);
            rn11Stock($bodega, $lotes[$dias], 2);
        }
        actuarComo('auditor');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS.'?tipo='.$tipo));

        expect($alertas->keys()->all())->toBe(array_map(fn (int $d) => rn11Clave($bodega, $lotes[$d]), $diasEsperados))
            ->and($alertas->pluck('tipo')->unique()->values()->all())->toBe([$tipo]);
    })->with([
        'VENCIDO' => ['VENCIDO', [-3, 0]],
        'POR_VENCER' => ['POR_VENCER', [1, 90]],
    ]);

    it('RN-11-AC4: filtros combinados bodega_id + tipo', function () {
        $b1 = Bodega::factory()->create();
        $b2 = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $vencido = rn11Lote($producto, -1);
        $porVencer = rn11Lote($producto, 7);
        foreach ([$b1, $b2] as $b) {
            rn11Stock($b, $vencido, 1);
            rn11Stock($b, $porVencer, 1);
        }
        actuarComo('regente_farmacia');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS.'?'.http_build_query(['bodega_id' => $b2->id, 'tipo' => 'POR_VENCER'])));

        expect($alertas->keys()->all())->toBe([rn11Clave($b2, $porVencer)]);
    });

    it('RN-11-AC4 S-25: filtro con id bien formado pero inexistente → 200 con data vacío', function (string $campo) {
        rn11Stock(Bodega::factory()->create(), rn11Lote(Producto::factory()->create(), 5), 3);
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN11_URI_VENCIMIENTOS.'?'.http_build_query([$campo => 987654321]));

        $r->assertOk();
        expect($r->json('data'))->toBe([]);
    })->with(['bodega_id', 'producto_id']);

    it('RN-11-AC4 S-28: página válida pero vacía → 200 con data vacío', function () {
        rn11Stock(Bodega::factory()->create(), rn11Lote(Producto::factory()->create(), 5), 3);
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN11_URI_VENCIMIENTOS.'?page=1000000');

        $r->assertOk();
        expect($r->json('data'))->toBe([]);
    });

    it('RN-11-AC4 S-25 S-28: filtros mal formados → 422 VALIDACION con el campo en detalles.campos (nunca 500) y sin cambios', function (string $campo, string $valor) {
        rn11Stock(Bodega::factory()->create(), rn11Lote(Producto::factory()->create(), 5), 3);
        actuarComo('regente_farmacia');
        $antes = rn11Instantanea();

        $r = $this->getJson(RN11_URI_VENCIMIENTOS.'?'.http_build_query([$campo => $valor]));

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($campo)
            ->and(rn11Instantanea())->toBe($antes);
    })->with([
        'bodega_id texto' => ['bodega_id', 'abc'],
        'bodega_id decimal' => ['bodega_id', '1.5'],
        'bodega_id fuera de rango bigint' => ['bodega_id', '99999999999999999999'],
        'producto_id texto' => ['producto_id', 'P1'],
        'producto_id negativo' => ['producto_id', '-3'],
        'tipo desconocido' => ['tipo', 'CADUCADO'],
        'tipo TODOS (no es un tipo)' => ['tipo', 'TODOS'],
        'page cero' => ['page', '0'],
        'page > 1.000.000 (S-28)' => ['page', '1000001'],
        'page texto' => ['page', 'dos'],
        'por_pagina 101' => ['por_pagina', '101'],
        'por_pagina cero' => ['por_pagina', '0'],
        'por_pagina decimal' => ['por_pagina', '2.5'],
    ]);
});

/*
|--------------------------------------------------------------------------
| Stock mínimo
|--------------------------------------------------------------------------
*/

describe('RN-11-AC5 stock por debajo del mínimo', function () {
    it('RN-11-AC5 S-15: mínimo 20 → con stock vigente 19 aparece (actual 19, minimo 20, faltante 1); con 20 o 21 no (estrictamente por debajo)', function (int $stock, bool $aparece) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        // Stock vigente repartido en dos lotes no vencidos: cuenta la suma.
        rn11Stock($bodega, rn11Lote($producto, 10), 12);
        rn11Stock($bodega, rn11Lote($producto, 200), $stock - 12);
        rn11Minimo($bodega, $producto, 20);
        actuarComo('regente_farmacia');
        $antes = rn11Instantanea();

        $alertas = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));

        if ($aparece) {
            expect($alertas)->toHaveCount(1)
                ->and($alertas[rn11Clave($bodega, $producto)])->toMatchArray([
                    'bodega_id' => $bodega->id,
                    'producto_id' => $producto->id,
                    'minimo' => 20,
                    'actual' => 19,
                    'faltante' => 1,
                ]);
        } else {
            expect($alertas)->toHaveCount(0);
        }
        expect(rn11Instantanea())->toBe($antes);
    })->with([
        'uno menos (19) → aparece' => [19, true],
        'igual (20) → no' => [20, false],
        'uno más (21) → no' => [21, false],
    ]);

    it('RN-11-AC5: el mínimo es por bodega: el mismo producto bajo el mínimo en B1 y sobrado en B2 solo alerta en B1', function () {
        $b1 = Bodega::factory()->create();
        $b2 = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $lote = rn11Lote($producto, 150);
        rn11Stock($b1, $lote, 5);
        rn11Stock($b2, $lote, 25);
        rn11Minimo($b1, $producto, 20);
        rn11Minimo($b2, $producto, 20);
        actuarComo('auxiliar_farmacia');

        $alertas = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));

        expect($alertas->keys()->all())->toBe([rn11Clave($b1, $producto)])
            ->and($alertas[rn11Clave($b1, $producto)])->toMatchArray(['minimo' => 20, 'actual' => 5, 'faltante' => 15]);
    });

    it('RN-11-AC5: un producto sin stock mínimo definido no alerta aunque tenga poco o ningún stock', function () {
        $bodega = Bodega::factory()->create();
        $conMinimo = Producto::factory()->create();
        $sinMinimo = Producto::factory()->create();
        rn11Stock($bodega, rn11Lote($sinMinimo, 100), 1);
        rn11Stock($bodega, rn11Lote($conMinimo, 100), 3);
        rn11Minimo($bodega, $conMinimo, 4);
        actuarComo('auditor');

        $alertas = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));

        expect($alertas->keys()->all())->toBe([rn11Clave($bodega, $conMinimo)])
            ->and($alertas[rn11Clave($bodega, $conMinimo)])->toMatchArray(['minimo' => 4, 'actual' => 3, 'faltante' => 1]);
    });

    it('RN-11-AC5 S-15: mínimo 0 nunca alerta, ni sin existencias (0 no es < 0)', function () {
        $bodega = Bodega::factory()->create();
        rn11Minimo($bodega, Producto::factory()->create(), 0);
        actuarComo('regente_farmacia');

        expect(rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO)))->toHaveCount(0);
    });

    it('RN-11-AC5: el stock que baja por un ajuste real hace aparecer la alerta en la consulta siguiente', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $existencia = rn11Stock($bodega, rn11Lote($producto, 100), 20);
        rn11Minimo($bodega, $producto, 20);
        actuarComo('regente_farmacia');
        expect(rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO)))->toHaveCount(0);

        rn11AjustarA($existencia, 19);

        $alertas = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));
        expect($alertas[rn11Clave($bodega, $producto)] ?? null)->toMatchArray(['minimo' => 20, 'actual' => 19, 'faltante' => 1]);
    });
});

describe('RN-11-AC6 qué stock cuenta para el mínimo', function () {
    it('RN-11-AC6 S-15: stock solo de lotes vencidos no cuenta → aparece con actual 0', function (int $diasVencido) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn11Stock($bodega, rn11Lote($producto, $diasVencido), 50);
        rn11Minimo($bodega, $producto, 20);
        actuarComo('regente_farmacia');

        $alertas = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));

        expect($alertas)->toHaveCount(1)
            ->and($alertas[rn11Clave($bodega, $producto)])->toMatchArray([
                'bodega_id' => $bodega->id,
                'producto_id' => $producto->id,
                'minimo' => 20,
                'actual' => 0,
                'faltante' => 20,
            ]);
    })->with([
        'vence hoy (S-01)' => [0],
        'venció ayer' => [-1],
        'venció hace 1 año' => [-365],
    ]);

    it('RN-11-AC6 S-01: vigentes 10 + vencido hoy 25 + vencido ayer 30 con mínimo 20 → actual 10 (solo lo vigente)', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn11Stock($bodega, rn11Lote($producto, 1), 4);
        rn11Stock($bodega, rn11Lote($producto, 300), 6);
        rn11Stock($bodega, rn11Lote($producto, 0), 25);
        rn11Stock($bodega, rn11Lote($producto, -1), 30);
        rn11Minimo($bodega, $producto, 20);
        actuarComo('auxiliar_farmacia');

        $alertas = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));

        expect($alertas[rn11Clave($bodega, $producto)] ?? null)->toMatchArray(['minimo' => 20, 'actual' => 10, 'faltante' => 10]);
    });

    it('RN-11-AC6: mínimo definido sin ninguna existencia → aparece con actual 0', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn11Minimo($bodega, $producto, 7);
        actuarComo('auditor');

        $alertas = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));

        expect($alertas)->toHaveCount(1)
            ->and($alertas[rn11Clave($bodega, $producto)])->toMatchArray([
                'bodega_id' => $bodega->id,
                'producto_id' => $producto->id,
                'minimo' => 7,
                'actual' => 0,
                'faltante' => 7,
            ]);
    });

    it('RN-11-AC6: existencias vigentes en 0 (llevadas a 0 por ajuste) cuentan como actual 0', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $existencia = rn11Stock($bodega, rn11Lote($producto, 60), 9);
        rn11AjustarA($existencia, 0);
        rn11Minimo($bodega, $producto, 1);
        actuarComo('regente_farmacia');

        $alertas = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));

        expect($alertas[rn11Clave($bodega, $producto)] ?? null)->toMatchArray(['minimo' => 1, 'actual' => 0, 'faltante' => 1]);
    });

    it('RN-11-AC6: el stock de otra bodega no cuenta para el mínimo de esta', function () {
        $bodega = Bodega::factory()->create();
        $otra = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $lote = rn11Lote($producto, 100);
        rn11Stock($bodega, $lote, 3);
        rn11Stock($otra, $lote, 500);
        rn11Stock($otra, rn11Lote($producto, 50), 500);
        rn11Minimo($bodega, $producto, 20);
        actuarComo('regente_farmacia');

        $alertas = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));

        expect($alertas->keys()->all())->toBe([rn11Clave($bodega, $producto)])
            ->and($alertas[rn11Clave($bodega, $producto)])->toMatchArray(['minimo' => 20, 'actual' => 3, 'faltante' => 17]);
    });

    it('RN-11-AC6: el stock de otro producto en la misma bodega no cuenta', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $otro = Producto::factory()->create();
        rn11Stock($bodega, rn11Lote($otro, 100), 999);
        rn11Minimo($bodega, $producto, 5);
        actuarComo('regente_farmacia');

        $alertas = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));

        expect($alertas->keys()->all())->toBe([rn11Clave($bodega, $producto)])
            ->and($alertas[rn11Clave($bodega, $producto)])->toMatchArray(['minimo' => 5, 'actual' => 0, 'faltante' => 5]);
    });

    it('RN-11-AC6 S-01 zona horaria: a las 23:59:59 de Bogotá un lote que vence mañana cuenta; a las 00:00 ya no', function () {
        $this->travelTo(Carbon::parse('2026-11-06 04:59:59', 'UTC')); // = 2026-11-05 23:59:59 Bogotá
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn11Stock($bodega, rn11Lote($producto, '2026-11-06'), 15);
        rn11Stock($bodega, rn11Lote($producto, '2026-11-07'), 3);
        rn11Minimo($bodega, $producto, 20);
        actuarComo('regente_farmacia');

        $antesMedianoche = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));
        $this->travelTo(Carbon::parse('2026-11-06 05:00:00', 'UTC')); // = 2026-11-06 00:00:00 Bogotá
        $despuesMedianoche = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));

        expect($antesMedianoche[rn11Clave($bodega, $producto)] ?? null)->toMatchArray(['minimo' => 20, 'actual' => 18, 'faltante' => 2])
            ->and($despuesMedianoche[rn11Clave($bodega, $producto)] ?? null)->toMatchArray(['minimo' => 20, 'actual' => 3, 'faltante' => 17]);
    });

    it('RN-11-AC6: el umbral de vencimiento no afecta al stock mínimo: un lote por vencer (+1) sí cuenta', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn11Stock($bodega, rn11Lote($producto, 1), 20);
        rn11Minimo($bodega, $producto, 20);
        actuarComo('regente_farmacia');

        expect(rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO)))->toHaveCount(0);
    });
});

describe('RN-11-AC5 filtros de stock mínimo (S-25)', function () {
    it('RN-11-AC5 S-25: filtros bodega_id y producto_id → solo las alertas que coinciden', function (string $filtro) {
        $b1 = Bodega::factory()->create();
        $b2 = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        foreach ([$b1, $b2] as $b) {
            foreach ([$p1, $p2] as $p) {
                rn11Minimo($b, $p, 10);
            }
        }
        actuarComo('regente_farmacia');
        $query = $filtro === 'bodega_id' ? ['bodega_id' => $b2->id] : ['producto_id' => $p1->id];

        $alertas = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO.'?'.http_build_query($query)));

        $esperadas = $filtro === 'bodega_id'
            ? [rn11Clave($b2, $p1), rn11Clave($b2, $p2)]
            : [rn11Clave($b1, $p1), rn11Clave($b2, $p1)];
        $vistas = $alertas->keys()->all();
        sort($vistas);
        sort($esperadas);
        expect($vistas)->toBe($esperadas)
            ->and($alertas->every(fn (array $a) => $a['actual'] === 0 && $a['minimo'] === 10 && $a['faltante'] === 10))->toBeTrue();
    })->with(['bodega_id', 'producto_id']);

    it('RN-11-AC5 S-25 S-28: filtros mal formados en stock-minimo → 422 VALIDACION con el campo en detalles.campos y sin cambios', function (string $campo, string $valor) {
        rn11Minimo(Bodega::factory()->create(), Producto::factory()->create(), 10);
        actuarComo('regente_farmacia');
        $antes = rn11Instantanea();

        $r = $this->getJson(RN11_URI_STOCK_MINIMO.'?'.http_build_query([$campo => $valor]));

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($campo)
            ->and(rn11Instantanea())->toBe($antes);
    })->with([
        'bodega_id texto' => ['bodega_id', 'abc'],
        'producto_id decimal' => ['producto_id', '1.5'],
        'page > 1.000.000 (S-28)' => ['page', '1000001'],
        'por_pagina 101' => ['por_pagina', '101'],
    ]);
});

/*
|--------------------------------------------------------------------------
| Roles
|--------------------------------------------------------------------------
*/

describe('RN-11-AC7 permisos', function () {
    it('RN-11-AC7: auxiliar, regente y auditor → 200 con las alertas; médico y admin → 403 NO_AUTORIZADO sin cambios', function (string $uri, string $rol, bool $permitido) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $lote = rn11Lote($producto, 10);
        rn11Stock($bodega, $lote, 4);
        rn11Minimo($bodega, $producto, 5);
        actuarComo($rol);
        $antes = rn11Instantanea();

        $r = $this->getJson($uri);

        if ($permitido) {
            if ($uri === RN11_URI_VENCIMIENTOS) {
                $alertas = rn11AlertasVenc($r);
                expect($alertas->keys()->all())->toBe([rn11Clave($bodega, $lote)])
                    ->and($alertas[rn11Clave($bodega, $lote)])->toMatchArray(['tipo' => 'POR_VENCER', 'dias_restantes' => 10, 'cantidad' => 4]);
            } else {
                $alertas = rn11AlertasMin($r);
                expect($alertas->keys()->all())->toBe([rn11Clave($bodega, $producto)])
                    ->and($alertas[rn11Clave($bodega, $producto)])->toMatchArray(['minimo' => 5, 'actual' => 4, 'faltante' => 1]);
            }
        } else {
            esperarError($r, 403, 'NO_AUTORIZADO');
        }
        expect(rn11Instantanea())->toBe($antes);
    })->with([
        'vencimientos' => [RN11_URI_VENCIMIENTOS],
        'stock-minimo' => [RN11_URI_STOCK_MINIMO],
    ])->with([
        'auxiliar_farmacia' => ['auxiliar_farmacia', true],
        'regente_farmacia' => ['regente_farmacia', true],
        'auditor' => ['auditor', true],
        'medico' => ['medico', false],
        'admin (S-13)' => ['admin', false],
    ]);

    it('RN-11-AC7: un 403 no filtra datos de alertas en la respuesta', function (string $uri, string $rol) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $lote = rn11Lote($producto, 10, 'RN11-SECRETO-LOTE');
        rn11Stock($bodega, $lote, 4);
        rn11Minimo($bodega, $producto, 5);
        actuarComo($rol);

        $r = $this->getJson($uri);

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect($r->getContent())->not->toContain('RN11-SECRETO-LOTE')
            ->and($r->getContent())->not->toContain('dias_restantes')
            ->and($r->getContent())->not->toContain('faltante');
    })->with([
        'vencimientos' => [RN11_URI_VENCIMIENTOS],
        'stock-minimo' => [RN11_URI_STOCK_MINIMO],
    ])->with(['medico', 'admin']);

    it('RN-11-AC7: sin token o con token inválido → 401 NO_AUTENTICADO', function (string $uri, bool $tokenInvalido) {
        rn11Stock(Bodega::factory()->create(), rn11Lote(Producto::factory()->create(), 10), 4);
        $antes = rn11Instantanea();

        $r = $tokenInvalido
            ? $this->withHeader('Authorization', 'Bearer 999|token-inventado-rn11')->getJson($uri)
            : $this->getJson($uri);

        esperarError($r, 401, 'NO_AUTENTICADO');
        expect(rn11Instantanea())->toBe($antes);
    })->with([
        'vencimientos' => [RN11_URI_VENCIMIENTOS],
        'stock-minimo' => [RN11_URI_STOCK_MINIMO],
    ])->with([
        'sin token' => [false],
        'token inválido' => [true],
    ]);
});

/*
|--------------------------------------------------------------------------
| Supuestos S-83..S-87
|--------------------------------------------------------------------------
*/

describe('RN-11 supuestos S-83..S-87', function () {
    it('RN-11-AC1 RN-11-AC2 S-83: dias_restantes = fecha_vencimiento − hoy: 0 si vence hoy, negativo si ya venció (VENCIDO), 1 mañana (POR_VENCER)', function (int $dias, string $tipo) {
        $bodega = Bodega::factory()->create();
        $lote = rn11Lote(Producto::factory()->create(), $dias);
        rn11Stock($bodega, $lote, 3);
        actuarComo('auditor');

        $alertas = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));

        expect($alertas)->toHaveCount(1)
            ->and($alertas[rn11Clave($bodega, $lote)])->toMatchArray([
                'tipo' => $tipo,
                'dias_restantes' => $dias,
                'fecha_vencimiento' => rn11Dia($dias),
            ]);
    })->with([
        'vence hoy (0)' => [0, 'VENCIDO'],
        'venció ayer (−1)' => [-1, 'VENCIDO'],
        'venció hace 20 días (−20)' => [-20, 'VENCIDO'],
        'vence mañana (1)' => [1, 'POR_VENCER'],
    ]);

    it('RN-11-AC4 S-84: meta.hoy (fecha de Bogotá) y meta.dias_alerta_vencimiento informan el criterio aplicado en vencimientos', function (string $hora) {
        rn11Congelar($hora);
        config(['farma.dias_alerta_vencimiento' => 45]);
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN11_URI_VENCIMIENTOS);

        $r->assertOk();
        expect($r->json('meta.hoy'))->toBe(RN11_HOY)
            ->and($r->json('meta.dias_alerta_vencimiento'))->toBe(45);
    })->with([
        'a media mañana' => ['10:00:00'],
        '23:59:59 de Bogotá (ya es el día siguiente en UTC)' => ['23:59:59'],
        '00:00:00 de Bogotá' => ['00:00:00'],
    ]);

    it('RN-11-AC5 S-84: meta.hoy (fecha de Bogotá) informa el día con el que stock-minimo descarta lotes vencidos', function () {
        rn11Congelar('23:59:59');
        actuarComo('auxiliar_farmacia');

        $r = $this->getJson(RN11_URI_STOCK_MINIMO);

        $r->assertOk();
        expect($r->json('meta.hoy'))->toBe(RN11_HOY);
    });

    it('RN-11-AC5 S-85: stock-minimo se ordena por faltante DESC y, a igual faltante, por bodega y producto; la paginación conserva el orden', function () {
        $b1 = Bodega::factory()->create();
        $b2 = Bodega::factory()->create();
        [$p1, $p2, $p3] = Producto::factory()->count(3)->create()->all();
        // Se crean en un orden distinto del esperado para que el id no explique el resultado.
        rn11Minimo($b2, $p1, 10);
        rn11Stock($b2, rn11Lote($p1, -3), 8); // vencido: no reduce el faltante (S-15)
        rn11Minimo($b1, $p3, 5);
        rn11Minimo($b2, $p2, 5);
        rn11Minimo($b1, $p2, 5);
        rn11Minimo($b1, $p1, 30);
        rn11Stock($b1, rn11Lote($p1, 40), 10);
        rn11Minimo($b2, $p3, 10);
        actuarComo('regente_farmacia');

        $esperado = [
            [rn11Clave($b1, $p1), 20],
            [rn11Clave($b2, $p1), 10],
            [rn11Clave($b2, $p3), 10],
            [rn11Clave($b1, $p2), 5],
            [rn11Clave($b1, $p3), 5],
            [rn11Clave($b2, $p2), 5],
        ];
        $fila = fn (array $a): array => [$a['bodega']['id'].':'.$a['producto']['id'], $a['faltante']];

        $todo = $this->getJson(RN11_URI_STOCK_MINIMO);
        $paginado = [];
        foreach ([1, 2, 3] as $page) {
            $r = $this->getJson(RN11_URI_STOCK_MINIMO.'?por_pagina=2&page='.$page);
            $r->assertOk();
            $paginado = [...$paginado, ...array_map($fila, $r->json('data'))];
        }

        $todo->assertOk();
        expect(array_map($fila, $todo->json('data')))->toBe($esperado)
            ->and($paginado)->toBe($esperado);
    });

    it('RN-11-AC5 RN-11-AC6 S-86: un stock mínimo de 0 nunca alerta aunque solo haya stock vencido', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn11Stock($bodega, rn11Lote($producto, -2), 7);
        rn11Minimo($bodega, $producto, 0);
        actuarComo('regente_farmacia');

        expect(rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO)))->toHaveCount(0);
    });

    it('RN-11-AC1 RN-11-AC5 S-86: bodegas y productos inactivos sí aparecen en ambas alertas (el stock físico sigue existiendo)', function (bool $bodegaActiva, bool $productoActivo) {
        $bodega = Bodega::factory()->create(['activa' => $bodegaActiva]);
        $producto = Producto::factory()->create(['activo' => $productoActivo]);
        $vencido = rn11Lote($producto, -4);
        $porVencer = rn11Lote($producto, 15);
        rn11Stock($bodega, $vencido, 6);
        rn11Stock($bodega, $porVencer, 2);
        rn11Minimo($bodega, $producto, 10);
        actuarComo('auditor');
        $antes = rn11Instantanea();

        $venc = rn11AlertasVenc($this->getJson(RN11_URI_VENCIMIENTOS));
        $min = rn11AlertasMin($this->getJson(RN11_URI_STOCK_MINIMO));

        expect($venc->keys()->all())->toBe([rn11Clave($bodega, $vencido), rn11Clave($bodega, $porVencer)])
            ->and($venc[rn11Clave($bodega, $vencido)]['tipo'])->toBe('VENCIDO')
            ->and($venc[rn11Clave($bodega, $porVencer)]['tipo'])->toBe('POR_VENCER')
            ->and($min)->toHaveCount(1)
            ->and($min[rn11Clave($bodega, $producto)])->toMatchArray(['minimo' => 10, 'actual' => 2, 'faltante' => 8])
            ->and(rn11Instantanea())->toBe($antes);
    })->with([
        'bodega inactiva' => [false, true],
        'producto inactivo' => [true, false],
        'ambos inactivos' => [false, false],
    ]);

    it('RN-11-AC1 RN-11-AC2 S-87: con umbral 0 o negativo solo alertan los vencidos y meta.dias_alerta_vencimiento es 0', function (int $umbral) {
        config(['farma.dias_alerta_vencimiento' => $umbral]);
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $lotes = [];
        foreach ([-10, -1, 0, 1, 2, 30] as $dias) {
            $lotes[$dias] = rn11Lote($producto, $dias);
            rn11Stock($bodega, $lotes[$dias], 5);
        }
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN11_URI_VENCIMIENTOS);
        $alertas = rn11AlertasVenc($r);
        $porVencer = $this->getJson(RN11_URI_VENCIMIENTOS.'?tipo=POR_VENCER');

        expect($alertas->keys()->all())->toBe([rn11Clave($bodega, $lotes[-10]), rn11Clave($bodega, $lotes[-1]), rn11Clave($bodega, $lotes[0])])
            ->and($alertas->every(fn (array $a) => $a['tipo'] === 'VENCIDO'))->toBeTrue()
            ->and($alertas->pluck('dias_restantes')->all())->toBe([-10, -1, 0])
            ->and($r->json('meta.dias_alerta_vencimiento'))->toBe(0)
            ->and(rn11AlertasVenc($porVencer))->toHaveCount(0);
    })->with([
        'umbral 0' => [0],
        'umbral −1' => [-1],
        'umbral −30' => [-30],
    ]);
});
