<?php

use App\DTO\ResumeAdaptationResult;

it('builds a validated result from structured output', function () {
    $result = ResumeAdaptationResult::fromStructured([
        'adapted_resume' => "Currículo adaptado.\nSeção 2.",
        'changes' => ['Resumo reescrito'],
        'covered_keywords' => ['Laravel'],
        'missing_requirements' => ['React'],
        'alerts' => [],
    ]);

    expect($result->adaptedResume)->toBe("Currículo adaptado.\nSeção 2.")
        ->and($result->changes)->toBe(['Resumo reescrito'])
        ->and($result->coveredKeywords)->toBe(['Laravel'])
        ->and($result->missingRequirements)->toBe(['React'])
        ->and($result->alerts)->toBe([]);
});

it('throws when adapted_resume is missing or not a string', function () {
    ResumeAdaptationResult::fromStructured(['changes' => []]);
})->throws(InvalidArgumentException::class, 'adapted_resume');

it('throws when adapted_resume is empty', function () {
    ResumeAdaptationResult::fromStructured(['adapted_resume' => '   ']);
})->throws(InvalidArgumentException::class, 'adapted_resume');

it('throws when adapted_resume contains HTML', function () {
    ResumeAdaptationResult::fromStructured(['adapted_resume' => '<p>Currículo</p>']);
})->throws(InvalidArgumentException::class, 'HTML');

it('throws when a list field is not a list of strings', function () {
    ResumeAdaptationResult::fromStructured([
        'adapted_resume' => 'Currículo',
        'changes' => 'não-lista',
    ]);
})->throws(InvalidArgumentException::class, 'changes');

it('throws when a list contains non-string entries', function () {
    ResumeAdaptationResult::fromStructured([
        'adapted_resume' => 'Currículo',
        'covered_keywords' => [42],
    ]);
})->throws(InvalidArgumentException::class, 'covered_keywords');

it('cleans, deduplicates and limits list entries', function () {
    $result = ResumeAdaptationResult::fromStructured([
        'adapted_resume' => 'Currículo',
        'changes' => ['  Alteração 1  ', 'Alteração 1', '', str_repeat('x', 300), 'Alteração 2'],
    ]);

    expect($result->changes)->toBe(['Alteração 1', 'Alteração 2']);
});

it('limits the number of entries per list', function () {
    $result = ResumeAdaptationResult::fromStructured([
        'adapted_resume' => 'Currículo',
        'changes' => array_map('strval', range(1, 60)),
    ]);

    expect(count($result->changes))->toBe(ResumeAdaptationResult::MAX_ITEMS_PER_LIST);
});

it('limits the adapted resume length', function () {
    ResumeAdaptationResult::fromStructured([
        'adapted_resume' => str_repeat('a', ResumeAdaptationResult::MAX_ADAPTED_LENGTH + 1),
    ]);
})->throws(InvalidArgumentException::class, 'limite');
