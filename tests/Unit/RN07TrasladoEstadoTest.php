<?php

declare(strict_types=1);

use App\Enums\AccionTraslado;
use App\Enums\TrasladoEstado;
use App\Exceptions\Dominio\CantidadRecibidaExcede;
use App\Services\Traslados\CalculadorRecepcion;

/*
 * RN-07 — funciones críticas puras (sin BD):
 *  - matriz completa de TrasladoEstado::puede(AccionTraslado): 7 estados × todas las acciones;
 *  - estados finales (RECIBIDO, RECIBIDO_PARCIAL, ANULADO);
 *  - cálculo del faltante (discrepancia) por ítem y estado resultante de la recepción.
 * Los valores esperados salen de la tabla de transiciones de RN-07 (S-08, S-09). `crear` no parte
 * de ningún estado (— → BORRADOR), así que no está permitida desde ninguno.
 */

/** Tabla de RN-07: estado → acciones permitidas (valores del enum). */
const RN07U_PERMITIDAS = [
    'BORRADOR' => ['solicitar', 'anular', 'editar_items'],
    'SOLICITADO' => ['aprobar', 'anular'],
    'APROBADO' => ['despachar', 'anular'],
    'EN_TRANSITO' => ['recibir'],
    'RECIBIDO' => [],
    'RECIBIDO_PARCIAL' => [],
    'ANULADO' => [],
];

const RN07U_ACCIONES = ['crear', 'solicitar', 'aprobar', 'despachar', 'recibir', 'anular', 'editar_items'];

dataset('rn07u_matriz', function () {
    foreach (RN07U_PERMITIDAS as $estado => $permitidas) {
        foreach (RN07U_ACCIONES as $accion) {
            $permitida = in_array($accion, $permitidas, true);
            yield "{$estado} × {$accion} → ".($permitida ? 'sí' : 'no') => [$estado, $accion, $permitida];
        }
    }
});

describe('RN-07 TrasladoEstado::puede', function () {
    it('RN-07-AC3: los estados son exactamente los 7 de la spec', function () {
        $valores = array_map(fn (TrasladoEstado $e): string => $e->value, TrasladoEstado::cases());
        sort($valores);

        expect($valores)->toBe(['ANULADO', 'APROBADO', 'BORRADOR', 'EN_TRANSITO', 'RECIBIDO', 'RECIBIDO_PARCIAL', 'SOLICITADO']);
    });

    it('RN-07-AC3: las acciones son exactamente crear, solicitar, aprobar, despachar, recibir, anular y editar ítems', function () {
        $valores = array_map(fn (AccionTraslado $a): string => $a->value, AccionTraslado::cases());
        sort($valores);
        $esperadas = RN07U_ACCIONES;
        sort($esperadas);

        expect($valores)->toBe($esperadas);
    });

    it('RN-07-AC3: matriz completa estado × acción según la tabla de transiciones', function (string $estado, string $accion, bool $permitida) {
        expect(TrasladoEstado::from($estado)->puede(AccionTraslado::from($accion)))->toBe($permitida);
    })->with('rn07u_matriz');

    it('RN-07-AC3: cada acción solo está permitida desde los estados de la tabla (vista por columnas)', function (string $accion, array $desde) {
        $permitidaEn = array_values(array_map(
            fn (TrasladoEstado $e): string => $e->value,
            array_filter(TrasladoEstado::cases(), fn (TrasladoEstado $e): bool => $e->puede(AccionTraslado::from($accion))),
        ));
        sort($permitidaEn);
        sort($desde);

        expect($permitidaEn)->toBe($desde);
    })->with([
        'crear' => ['crear', []],
        'solicitar' => ['solicitar', ['BORRADOR']],
        'aprobar' => ['aprobar', ['SOLICITADO']],
        'despachar' => ['despachar', ['APROBADO']],
        'recibir' => ['recibir', ['EN_TRANSITO']],
        'anular (S-09: nunca desde EN_TRANSITO)' => ['anular', ['APROBADO', 'BORRADOR', 'SOLICITADO']],
        'editar ítems (solo BORRADOR)' => ['editar_items', ['BORRADOR']],
    ]);

    it('RN-07-AC3: RECIBIDO, RECIBIDO_PARCIAL y ANULADO son finales y los demás no', function (string $estado, bool $final) {
        $e = TrasladoEstado::from($estado);
        $alguna = array_filter(AccionTraslado::cases(), fn (AccionTraslado $a): bool => $e->puede($a));

        expect($e->esFinal())->toBe($final)
            ->and($alguna === [])->toBe($final);
    })->with([
        'BORRADOR' => ['BORRADOR', false],
        'SOLICITADO' => ['SOLICITADO', false],
        'APROBADO' => ['APROBADO', false],
        'EN_TRANSITO' => ['EN_TRANSITO', false],
        'RECIBIDO' => ['RECIBIDO', true],
        'RECIBIDO_PARCIAL' => ['RECIBIDO_PARCIAL', true],
        'ANULADO' => ['ANULADO', true],
    ]);

    it('RN-07-AC2: el estado inicial de un traslado creado es BORRADOR', function () {
        expect(TrasladoEstado::inicial())->toBe(TrasladoEstado::from('BORRADOR'));
    });

    it('RN-07-AC2 RN-07-AC6: cada transición válida lleva al estado de la tabla', function (string $desde, string $accion, bool $completa, string $hacia) {
        expect(TrasladoEstado::from($desde)->tras(AccionTraslado::from($accion), $completa))->toBe(TrasladoEstado::from($hacia));
    })->with([
        'BORRADOR solicitar' => ['BORRADOR', 'solicitar', true, 'SOLICITADO'],
        'BORRADOR editar ítems' => ['BORRADOR', 'editar_items', true, 'BORRADOR'],
        'BORRADOR anular' => ['BORRADOR', 'anular', true, 'ANULADO'],
        'SOLICITADO aprobar' => ['SOLICITADO', 'aprobar', true, 'APROBADO'],
        'SOLICITADO anular' => ['SOLICITADO', 'anular', true, 'ANULADO'],
        'APROBADO despachar' => ['APROBADO', 'despachar', true, 'EN_TRANSITO'],
        'APROBADO anular' => ['APROBADO', 'anular', true, 'ANULADO'],
        'EN_TRANSITO recibir todo' => ['EN_TRANSITO', 'recibir', true, 'RECIBIDO'],
        'EN_TRANSITO recibir menos' => ['EN_TRANSITO', 'recibir', false, 'RECIBIDO_PARCIAL'],
    ]);

    it('RN-07-AC3: pedir la transición de un par no permitido lanza excepción (nunca devuelve un estado)', function (string $estado, string $accion, bool $permitida) {
        if ($permitida) {
            expect(TrasladoEstado::from($estado)->tras(AccionTraslado::from($accion)))->toBeInstanceOf(TrasladoEstado::class);

            return;
        }

        expect(fn () => TrasladoEstado::from($estado)->tras(AccionTraslado::from($accion)))->toThrow(LogicException::class);
    })->with('rn07u_matriz');
});

describe('RN-07 CalculadorRecepcion', function () {
    it('RN-07-AC5 RN-07-AC6: el faltante por ítem es despachado − recibido', function (int $despachada, int $recibida, int $faltante) {
        expect(CalculadorRecepcion::faltante(1, $despachada, $recibida))->toBe($faltante);
    })->with([
        'recibe todo' => [10, 10, 0],
        'recibe 7 de 10' => [10, 7, 3],
        'recibe 9 de 10' => [10, 9, 1],
        'recibe 0 de 10 (el total)' => [10, 0, 10],
        'una unidad recibida' => [1, 1, 0],
        'una unidad perdida' => [1, 0, 1],
        'cantidad grande' => [1_000_000, 999_999, 1],
    ]);

    it('RN-07-AC7: recibido mayor que lo despachado → CANTIDAD_RECIBIDA_EXCEDE (422) con el ítem', function (int $despachada, int $recibida) {
        try {
            CalculadorRecepcion::faltante(42, $despachada, $recibida);
            $lanzada = null;
        } catch (CantidadRecibidaExcede $e) {
            $lanzada = $e;
        }

        expect($lanzada)->toBeInstanceOf(CantidadRecibidaExcede::class)
            ->and($lanzada->codigo())->toBe('CANTIDAD_RECIBIDA_EXCEDE')
            ->and($lanzada->status())->toBe(422)
            ->and($lanzada->detalles()['traslado_item_id'] ?? null)->toBe(42);
    })->with([
        'despachado + 1' => [10, 11],
        'muy por encima' => [1, 1000],
    ]);

    it('RN-07-AC7: cantidades fuera de rango (recibido negativo o despachado <= 0) se rechazan', function (int $despachada, int $recibida) {
        expect(fn () => CalculadorRecepcion::faltante(1, $despachada, $recibida))->toThrow(InvalidArgumentException::class);
    })->with([
        'recibido negativo' => [10, -1],
        'despachado 0' => [0, 0],
        'despachado negativo' => [-5, 0],
    ]);

    it('RN-07-AC5 RN-07-AC6: estado resultante: sin faltantes → RECIBIDO; algún faltante → RECIBIDO_PARCIAL', function (array $faltantes, string $estado) {
        expect(CalculadorRecepcion::estadoResultante($faltantes))->toBe(TrasladoEstado::from($estado));
    })->with([
        'un ítem completo' => [[0], 'RECIBIDO'],
        'varios ítems completos' => [[0, 0, 0], 'RECIBIDO'],
        'un ítem con faltante' => [[3], 'RECIBIDO_PARCIAL'],
        'faltante solo en el último' => [[0, 0, 1], 'RECIBIDO_PARCIAL'],
        'faltante solo en el primero' => [[5, 0], 'RECIBIDO_PARCIAL'],
        'nada recibido de ningún ítem' => [[10, 4], 'RECIBIDO_PARCIAL'],
    ]);

    it('RN-07-AC7: una recepción sin ítems no tiene estado resultante', function () {
        expect(fn () => CalculadorRecepcion::estadoResultante([]))->toThrow(InvalidArgumentException::class);
    });
});
