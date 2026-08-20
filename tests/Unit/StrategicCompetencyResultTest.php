<?php

use App\DTO\StrategicCompetencyResult;
use App\Enums\StrategicRole;
use App\Services\ConceptNormalizer;

function competencyResultFrom(array $data): StrategicCompetencyResult
{
    return StrategicCompetencyResult::fromStructured($data, new ConceptNormalizer);
}

it('builds a result from valid structured output', function () {
    $result = competencyResultFrom([
        'competencies' => [
            ['concept' => 'PHP', 'strategic_role' => 'core'],
            ['concept' => 'Laravel', 'strategic_role' => 'expansion'],
            ['concept' => 'Docker', 'strategic_role' => 'differentiation'],
        ],
    ]);

    expect($result->competencies)->toBe([
        ['concept' => 'PHP', 'strategic_role' => StrategicRole::Core],
        ['concept' => 'Laravel', 'strategic_role' => StrategicRole::Expansion],
        ['concept' => 'Docker', 'strategic_role' => StrategicRole::Differentiation],
    ]);
});

it('normalizes concepts via alias, case and whitespace', function () {
    $result = competencyResultFrom([
        'competencies' => [
            ['concept' => 'postgres', 'strategic_role' => 'core'],
            ['concept' => '  Laravel Framework ', 'strategic_role' => 'expansion'],
        ],
    ]);

    expect($result->competencies)->toBe([
        ['concept' => 'PostgreSQL', 'strategic_role' => StrategicRole::Core],
        ['concept' => 'Laravel', 'strategic_role' => StrategicRole::Expansion],
    ]);
});

it('collapses duplicates post-normalization keeping the first role', function () {
    $result = competencyResultFrom([
        'competencies' => [
            ['concept' => 'PHP', 'strategic_role' => 'core'],
            ['concept' => 'php', 'strategic_role' => 'expansion'],
        ],
    ]);

    expect($result->competencies)->toBe([
        ['concept' => 'PHP', 'strategic_role' => StrategicRole::Core],
    ]);
});

it('drops empty and oversized concept names', function () {
    $result = competencyResultFrom([
        'competencies' => [
            ['concept' => '   ', 'strategic_role' => 'core'],
            ['concept' => str_repeat('x', 101), 'strategic_role' => 'expansion'],
            ['concept' => 'Docker', 'strategic_role' => 'differentiation'],
        ],
    ]);

    expect($result->competencies)->toBe([
        ['concept' => 'Docker', 'strategic_role' => StrategicRole::Differentiation],
    ]);
});

it('truncates the competency list at 100 entries', function () {
    $data = [
        'competencies' => collect(range(1, 120))
            ->map(fn (int $i): array => ['concept' => "Concept $i", 'strategic_role' => 'core'])
            ->all(),
    ];

    $result = competencyResultFrom($data);

    expect($result->competencies)->toHaveCount(100);
});

it('throws when competencies is not a list', function () {
    competencyResultFrom(['competencies' => 'PHP']);
})->throws(InvalidArgumentException::class, 'deve ser uma lista');

it('throws when an entry is not an object', function () {
    competencyResultFrom(['competencies' => ['PHP']]);
})->throws(InvalidArgumentException::class, 'objeto');

it('throws when concept is not a string', function () {
    competencyResultFrom(['competencies' => [['concept' => 42, 'strategic_role' => 'core']]]);
})->throws(InvalidArgumentException::class, '"concept" deve ser uma string');

it('throws when strategic_role is invalid', function () {
    competencyResultFrom(['competencies' => [['concept' => 'PHP', 'strategic_role' => 'nucleo']]]);
})->throws(InvalidArgumentException::class, '"core", "expansion" ou "differentiation"');
