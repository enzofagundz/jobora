<?php

use App\DTO\JobAnalysisResult;
use App\Enums\ConceptCategory;
use App\Services\ConceptNormalizer;

function resultFrom(array $data): JobAnalysisResult
{
    return JobAnalysisResult::fromStructured($data, new ConceptNormalizer);
}

it('builds a result from valid structured output', function () {
    $result = resultFrom([
        'role' => 'Desenvolvedor PHP Sênior',
        'company' => 'Acme Inc.',
        'technologies' => ['PostgreSQL', 'Laravel'],
        'languages' => ['PHP'],
        'experience' => ['3+ anos'],
    ]);

    expect($result->role)->toBe('Desenvolvedor PHP Sênior')
        ->and($result->company)->toBe('Acme Inc.')
        ->and($result->items)->toHaveKey(ConceptCategory::Technology->value)
        ->and($result->items[ConceptCategory::Technology->value])->toBe(['PostgreSQL', 'Laravel'])
        ->and($result->items[ConceptCategory::Experience->value])->toBe(['3+ anos']);
});

it('normalizes names and collapses duplicates post-normalization', function () {
    $result = resultFrom([
        'technologies' => ['postgres', 'PostgreSQL', 'Laravel Framework'],
        'languages' => ['PHP', 'php'],
    ]);

    expect($result->items[ConceptCategory::Technology->value])->toBe(['PostgreSQL', 'Laravel'])
        ->and($result->items[ConceptCategory::Language->value])->toBe(['PHP']);
});

it('drops empty and oversized names and unknown categories', function () {
    $result = resultFrom([
        'role' => 'Dev',
        'technologies' => ['   ', str_repeat('x', 101), 'Docker', 'Kafka'],
        'company' => '',
        'unknown_field' => ['ruído'],
    ]);

    expect($result->items[ConceptCategory::Technology->value])->toBe(['Docker', 'kafka'])
        ->and($result->company)->toBeNull()
        ->and($result->items)->not->toHaveKey(ConceptCategory::Requirement->value);
});

it('truncates the item list at 100 items keeping category order', function () {
    $data = [
        'technologies' => collect(range(1, 60))->map(fn ($i) => "Tech $i")->all(),
        'languages' => collect(range(1, 60))->map(fn ($i) => "Lang $i")->all(),
    ];

    $result = resultFrom($data);

    $total = collect($result->items)->flatten()->count();

    expect($total)->toBe(100)
        ->and($result->items[ConceptCategory::Technology->value])->toHaveCount(60)
        ->and($result->items[ConceptCategory::Language->value])->toHaveCount(40);
});

it('throws when a category value is not a list', function () {
    resultFrom(['technologies' => 'PHP']);
})->throws(InvalidArgumentException::class, 'deve ser uma lista de strings');

it('throws when a category entry is not a string', function () {
    resultFrom(['technologies' => [123]]);
})->throws(InvalidArgumentException::class, 'apenas strings');

it('throws when role or company is not a string or null', function () {
    resultFrom(['role' => 42]);
})->throws(InvalidArgumentException::class, 'string ou null');

it('treats blank role and company as null', function () {
    $result = resultFrom(['role' => '  ', 'company' => '']);

    expect($result->role)->toBeNull()
        ->and($result->company)->toBeNull();
});

it('serializes suggested data for the review page', function () {
    $result = resultFrom([
        'role' => 'Dev',
        'technologies' => ['Laravel'],
        'languages' => ['PHP'],
    ]);

    expect($result->toSuggestedData())->toBe([
        'role' => 'Dev',
        'company' => null,
        'items' => [
            ['category' => 'technology', 'name' => 'Laravel'],
            ['category' => 'language', 'name' => 'PHP'],
        ],
    ]);
});
