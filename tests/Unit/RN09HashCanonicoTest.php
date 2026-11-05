<?php

declare(strict_types=1);

use App\Services\Dispensacion\HashCanonico;

/*
 * RN-09 — HashCanonico::hashCanonico(array $cuerpo): string como función pura (spec: "Funciones
 * críticas a probar: hashCanonico(array $cuerpo) (orden de claves, tipos numéricos, arrays
 * anidados)"; RN-09-AC4: "hash sobre JSON canónico").
 *
 * Contrato que se prueba (decisiones documentadas en el informe de RN-09):
 *  - Determinista y en forma de request_hash: 64 caracteres hexadecimales en minúscula.
 *  - El orden de las claves de los objetos (en cualquier nivel) NO cambia el hash.
 *  - El orden de los elementos de una lista SÍ lo cambia (en JSON una lista es ordenada).
 *  - Tipos numéricos (normalización acordada para RN-09, igual que los ids que acepta la
 *    validación `numeric|integer`): una cadena con forma de entero decimal ("5", "-3") y un float
 *    sin parte fraccionaria (5.0) equivalen al entero 5. Todo lo demás conserva su tipo JSON:
 *    1 ≠ true, 0 ≠ false, null ≠ "" ≠ [] ≠ clave ausente, "01" ≠ "1", 5.5 ≠ 5.
 *    Los enteros grandes (> 2^53, hasta PHP_INT_MAX) no pierden precisión (no pasan por float).
 *  - Ninguna concatenación ambigua: estructuras distintas nunca colisionan por cómo se serializan.
 *
 * Sin aplicación ni BD (función pura).
 */

/** @param array<mixed> $cuerpo */
function hc(array $cuerpo): string
{
    return HashCanonico::hashCanonico($cuerpo);
}

/** Cuerpo de dispensación realista. @return array<string, mixed> */
function hcCuerpo(): array
{
    return [
        'paciente_id' => 7,
        'prescripcion_id' => 11,
        'bodega_id' => 3,
        'items' => [
            ['prescripcion_item_id' => 21, 'cantidad' => 5],
            ['prescripcion_item_id' => 22, 'cantidad' => 1],
        ],
    ];
}

it('RN-09-AC4 hashCanonico: es determinista y devuelve 64 caracteres hexadecimales en minúscula (cabe en request_hash)', function (array $cuerpo) {
    $h = hc($cuerpo);

    expect($h)->toMatch('/^[0-9a-f]{64}$/')
        ->and(hc($cuerpo))->toBe($h)
        ->and(hc(unserialize(serialize($cuerpo))))->toBe($h);
})->with([
    'cuerpo de dispensación' => [hcCuerpo()],
    'array vacío' => [[]],
    'anidado con vacíos' => [['a' => [], 'b' => ['c' => []], 'd' => [[]]]],
    'unicode' => [['nombre' => 'Niño José ñandú 💊', 'código' => 'ÁÉÍÓÚ']],
]);

it('RN-09-AC4 hashCanonico: no muta el array recibido', function () {
    $cuerpo = ['z' => 1, 'a' => ['y' => 2, 'b' => 3], 'items' => [['q' => 1, 'c' => 2]]];
    $copia = $cuerpo;

    hc($cuerpo);

    expect($cuerpo)->toBe($copia)
        ->and(array_keys($cuerpo))->toBe(['z', 'a', 'items']);
});

it('RN-09-AC4 hashCanonico: el orden de las claves no cambia el hash, en ningún nivel de anidamiento', function (array $a, array $b) {
    expect(hc($a))->toBe(hc($b));
})->with([
    'raíz' => [
        ['paciente_id' => 7, 'prescripcion_id' => 11, 'bodega_id' => 3],
        ['bodega_id' => 3, 'paciente_id' => 7, 'prescripcion_id' => 11],
    ],
    'objetos dentro de una lista (ítems)' => [
        hcCuerpo(),
        [
            'items' => [
                ['cantidad' => 5, 'prescripcion_item_id' => 21],
                ['cantidad' => 1, 'prescripcion_item_id' => 22],
            ],
            'bodega_id' => 3,
            'prescripcion_id' => 11,
            'paciente_id' => 7,
        ],
    ],
    'tres niveles' => [
        ['a' => ['b' => ['c' => 1, 'd' => 2], 'e' => 3], 'f' => 4],
        ['f' => 4, 'a' => ['e' => 3, 'b' => ['d' => 2, 'c' => 1]]],
    ],
    'claves unicode' => [
        ['ñ' => 1, 'n' => 2, 'é' => 3, 'e' => 4, '💊' => 5],
        ['💊' => 5, 'e' => 4, 'é' => 3, 'n' => 2, 'ñ' => 1],
    ],
    'claves numéricas y alfanuméricas mezcladas' => [
        [10 => 'a', 9 => 'b', 'x' => 'c', '1e1' => 'd', '09' => 'e'],
        ['09' => 'e', '1e1' => 'd', 'x' => 'c', 9 => 'b', 10 => 'a'],
    ],
    'claves que difieren solo en mayúsculas' => [
        ['a' => 1, 'A' => 2, 'b' => 3],
        ['b' => 3, 'A' => 2, 'a' => 1],
    ],
]);

it('RN-09-AC4 hashCanonico: el orden de los elementos de una lista sí cambia el hash (las listas no se reordenan)', function (array $a, array $b) {
    expect(hc($a))->not->toBe(hc($b));
})->with([
    'ítems de la dispensación invertidos' => [
        hcCuerpo(),
        ['items' => array_reverse(hcCuerpo()['items'])] + hcCuerpo(),
    ],
    'lista de escalares' => [['l' => [1, 2, 3]], ['l' => [3, 2, 1]]],
    'lista anidada' => [['l' => [[1, 2], [3]]], ['l' => [[3], [1, 2]]]],
    'lista de cadenas' => [['l' => ['a', 'b']], ['l' => ['b', 'a']]],
]);

it('RN-09-AC2 hashCanonico: cualquier cambio de valor en el cuerpo cambia el hash', function (Closure $mutar) {
    expect(hc($mutar(hcCuerpo())))->not->toBe(hc(hcCuerpo()));
})->with([
    'otra cantidad' => [function (array $c): array {
        $c['items'][0]['cantidad'] = 6;

        return $c;
    }],
    'otro ítem' => [function (array $c): array {
        $c['items'][1]['prescripcion_item_id'] = 23;

        return $c;
    }],
    'otra bodega' => [function (array $c): array {
        $c['bodega_id'] = 4;

        return $c;
    }],
    'otro paciente' => [function (array $c): array {
        $c['paciente_id'] = 8;

        return $c;
    }],
    'ítem extra' => [function (array $c): array {
        $c['items'][] = ['prescripcion_item_id' => 24, 'cantidad' => 1];

        return $c;
    }],
    'ítem de menos' => [function (array $c): array {
        array_pop($c['items']);

        return $c;
    }],
    'clave extra en la raíz' => [function (array $c): array {
        $c['nota'] = 'x';

        return $c;
    }],
    'clave de menos en un ítem' => [function (array $c): array {
        unset($c['items'][0]['cantidad']);

        return $c;
    }],
]);

it('RN-09-AC4 hashCanonico: tipos numéricos: un entero escrito como cadena decimal o como float sin fracción equivale al entero', function (mixed $a, mixed $b) {
    expect(hc(['v' => $a]))->toBe(hc(['v' => $b]))
        ->and(hc(['items' => [['prescripcion_item_id' => $a, 'cantidad' => 2]]]))->toBe(hc(['items' => [['prescripcion_item_id' => $b, 'cantidad' => 2]]]));
})->with([
    '1 vs "1"' => [1, '1'],
    '0 vs "0"' => [0, '0'],
    '-3 vs "-3"' => [-3, '-3'],
    '5 vs 5.0' => [5, 5.0],
    '"5" vs 5.0' => ['5', 5.0],
    'PHP_INT_MAX vs su cadena' => [PHP_INT_MAX, (string) PHP_INT_MAX],
]);

it('RN-09-AC4 hashCanonico: fuera de esa normalización conserva los tipos JSON (número ≠ booleano ≠ null ≠ cadena no numérica)', function (mixed $a, mixed $b) {
    expect(hc(['v' => $a]))->not->toBe(hc(['v' => $b]))
        ->and(hc(['items' => [['cantidad' => $a]]]))->not->toBe(hc(['items' => [['cantidad' => $b]]]));
})->with([
    '1 vs true' => [1, true],
    '0 vs false' => [0, false],
    '"1" vs true' => ['1', true],
    '"true" vs true' => ['true', true],
    'null vs ""' => [null, ''],
    'null vs 0' => [null, 0],
    'null vs "0"' => [null, '0'],
    'null vs false' => [null, false],
    '"" vs 0' => ['', 0],
    '"" vs []' => ['', []],
    'null vs []' => [null, []],
    '10 vs 1' => [10, 1],
    '-1 vs 1' => [-1, 1],
    '"01" vs "1"' => ['01', '1'],
    '5.5 vs 5' => [5.5, 5],
    '"5.5" vs 5' => ['5.5', 5],
    '"5 " vs 5' => ['5 ', 5],
]);

it('RN-09-AC4 hashCanonico: enteros grandes no pierden precisión (ids bigint, más allá de 2^53)', function (int $a, int $b) {
    expect(hc(['paciente_id' => $a]))->not->toBe(hc(['paciente_id' => $b]))
        ->and(hc(['paciente_id' => $a]))->toBe(hc(['paciente_id' => $a]));
})->with([
    'PHP_INT_MAX vs PHP_INT_MAX − 1' => [PHP_INT_MAX, PHP_INT_MAX - 1],
    '2^53 + 1 vs 2^53' => [9007199254740993, 9007199254740992],
    '2^53 vs 2^53 − 1' => [9007199254740992, 9007199254740991],
]);

it('RN-09-AC4 hashCanonico: una clave ausente no equivale a la clave con null, vacío o lista vacía', function (array $conClave) {
    expect(hc($conClave))->not->toBe(hc(['a' => 1]));
})->with([
    'null' => [['a' => 1, 'b' => null]],
    'cadena vacía' => [['a' => 1, 'b' => '']],
    'lista vacía' => [['a' => 1, 'b' => []]],
]);

it('RN-09-AC4 hashCanonico: arrays anidados vacíos se distinguen entre sí y de sus contenidos', function (array $a, array $b) {
    expect(hc($a))->not->toBe(hc($b));
})->with([
    '[] vs [[]]' => [['a' => []], ['a' => [[]]]],
    '[[]] vs [[[]]]' => [['a' => [[]]], ['a' => [[[]]]]],
    '[[]] vs [[], []]' => [['a' => [[]]], ['a' => [[], []]]],
    '[[]] vs [""]' => [['a' => [[]]], ['a' => ['']]],
    '[[]] vs [null]' => [['a' => [[]]], ['a' => [null]]],
    'raíz vacía vs objeto con vacío' => [[], ['a' => []]],
    'items vacío vs items con un objeto vacío' => [['items' => []], ['items' => [[]]]],
]);

it('RN-09-AC4 hashCanonico: estructuras distintas nunca colisionan por la forma de serializarlas', function (array $a, array $b) {
    expect(hc($a))->not->toBe(hc($b));
})->with([
    'concatenación clave+valor' => [['a' => 'bc'], ['ab' => 'c']],
    'comillas inyectadas en un valor' => [['a' => 'x","b":"y'], ['a' => 'x', 'b' => 'y']],
    'objeto anidado vs clave con punto' => [['a' => ['b' => 1]], ['a.b' => 1]],
    'lista vs cadena con comas' => [['a' => [1, 2]], ['a' => '1,2']],
    'lista vs cadena con corchetes' => [['a' => [1, 2]], ['a' => '[1,2]']],
    'valor vs objeto con ese valor' => [['a' => 1], ['a' => ['a' => 1]]],
    'separadores dentro de claves' => [['a:1' => 2], ['a' => '1:2']],
    'barra invertida escapada' => [['a' => '\\"'], ['a' => '"']],
]);

it('RN-09-AC4 hashCanonico: cadenas unicode distintas dan hash distinto y la misma cadena da el mismo', function (string $a, string $b) {
    expect(hc(['v' => $a]))->not->toBe(hc(['v' => $b]))
        ->and(hc(['v' => $a]))->toBe(hc(['v' => $a]));
})->with([
    'ñ vs n' => ['ñ', 'n'],
    'é vs e' => ['é', 'e'],
    'emoji vs texto' => ['💊', ':pill:'],
    'mayúsculas' => ['José', 'JOSÉ'],
    'espacio final' => ['abc', 'abc '],
    'barra' => ['a/b', 'a\\/b'],
]);
