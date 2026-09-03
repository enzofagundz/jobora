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
        'skills' => ['PostgreSQL', 'Laravel', 'PHP'],
        'experience' => ['3+ anos'],
    ]);

    expect($result->role)->toBe('Desenvolvedor PHP Sênior')
        ->and($result->company)->toBe('Acme Inc.')
        ->and($result->items)->toHaveKey(ConceptCategory::Skill->value)
        ->and($result->items[ConceptCategory::Skill->value])->toBe(['PostgreSQL', 'Laravel', 'PHP'])
        ->and($result->items[ConceptCategory::Experience->value])->toBe(['3+ anos']);
});

it('normalizes names and collapses duplicates post-normalization', function () {
    $result = resultFrom([
        'skills' => ['postgres', 'PostgreSQL', 'Laravel Framework', 'PHP', 'php'],
    ]);

    expect($result->items[ConceptCategory::Skill->value])->toBe(['PostgreSQL', 'Laravel', 'PHP']);
});

it('drops empty and oversized names and unknown categories', function () {
    $result = resultFrom([
        'role' => 'Dev',
        'skills' => ['   ', str_repeat('x', 101), 'Docker', 'Kafka'],
        'company' => '',
        'unknown_field' => ['ruído'],
    ]);

    // Docker é dono de tools; Kafka desconhecido fica em skills em Title Case.
    expect($result->items[ConceptCategory::Skill->value])->toBe(['Kafka'])
        ->and($result->items[ConceptCategory::Tool->value])->toBe(['Docker'])
        ->and($result->company)->toBeNull()
        ->and($result->items)->not->toHaveKey(ConceptCategory::Requirement->value);
});

it('moves misclassified concepts to the owner category without duplication', function () {
    $result = resultFrom([
        'tools' => ['php'],
    ]);

    expect($result->items)->toHaveKey(ConceptCategory::Skill->value)
        ->and($result->items[ConceptCategory::Skill->value])->toBe(['PHP'])
        ->and($result->items)->not->toHaveKey(ConceptCategory::Tool->value);
});

it('deduplicates the same concept across categories keeping the owner', function () {
    $result = resultFrom([
        'skills' => ['PHP'],
        'tools' => ['php', 'Docker'],
    ]);

    expect($result->items[ConceptCategory::Skill->value] ?? [])->toBe(['PHP'])
        ->and($result->items[ConceptCategory::Tool->value] ?? [])->toBe(['Docker']);
});

it('converges REST API variants to the canonical form in skills', function () {
    $result = resultFrom([
        'skills' => ['api rest'],
        'tools' => ['REST API'],
    ]);

    expect(collect($result->items)->flatten()->all())->toBe(['REST API'])
        ->and($result->items)->toHaveKey(ConceptCategory::Skill->value);
});

it('keeps pt-BR free text intact while Title Casing unknown concepts', function () {
    $result = resultFrom([
        'skills' => ['kafka'],
        'requirements' => ['disponibilidade para viagens'],
        'experience' => ['3+ anos'],
    ]);

    expect($result->items[ConceptCategory::Skill->value] ?? [])->toBe(['Kafka'])
        ->and($result->items[ConceptCategory::Requirement->value] ?? [])->toBe(['disponibilidade para viagens'])
        ->and($result->items[ConceptCategory::Experience->value] ?? [])->toBe(['3+ anos']);
});

it('moves misclassified methodologies to the owner category', function () {
    $result = resultFrom([
        'skills' => ['Scrum'],
    ]);

    expect($result->items)->toHaveKey(ConceptCategory::Methodology->value)
        ->and($result->items[ConceptCategory::Methodology->value])->toBe(['Scrum'])
        ->and($result->items)->not->toHaveKey(ConceptCategory::Skill->value);
});

it('keeps unmapped domain competencies where the model put them', function () {
    $result = resultFrom([
        'skills' => ['Vendas consultivas'],
    ]);

    expect($result->items[ConceptCategory::Skill->value] ?? [])->toBe(['Vendas Consultivas']);
});

it('truncates the item list at 100 items keeping category order', function () {
    $data = [
        'skills' => collect(range(1, 80))->map(fn ($i) => "Skill $i")->all(),
        'tools' => collect(range(1, 40))->map(fn ($i) => "Tool $i")->all(),
    ];

    $result = resultFrom($data);

    $total = collect($result->items)->flatten()->count();

    expect($total)->toBe(100)
        ->and($result->items[ConceptCategory::Skill->value])->toHaveCount(80)
        ->and($result->items[ConceptCategory::Tool->value])->toHaveCount(20);
});

it('throws when a category value is not a list', function () {
    resultFrom(['skills' => 'PHP']);
})->throws(InvalidArgumentException::class, 'deve ser uma lista de strings');

it('throws when a category entry is not a string', function () {
    resultFrom(['skills' => [123]]);
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
        'skills' => ['Laravel', 'PHP'],
    ]);

    expect($result->toSuggestedData())->toBe([
        'role' => 'Dev',
        'company' => null,
        'items' => [
            ['category' => 'skill', 'name' => 'Laravel'],
            ['category' => 'skill', 'name' => 'PHP'],
        ],
        'application_methods' => [],
    ]);
});

it('parses application methods from structured output', function () {
    $result = resultFrom([
        'application_methods' => [
            ['type' => 'email', 'email' => 'Recrutamento@Empresa.com'],
            ['type' => 'external_url', 'url' => 'https://empresa.gupy.io/jobs/123/', 'platform' => 'Gupy'],
            ['type' => 'linkedin_apply'],
        ],
    ]);

    expect($result->applicationMethods)->toHaveCount(3)
        ->and($result->applicationMethods[0]->email)->toBe('recrutamento@empresa.com')
        ->and($result->applicationMethods[1]->url)->toBe('https://empresa.gupy.io/jobs/123/')
        ->and($result->applicationMethods[2]->url)->toBeNull();
});

it('converts mailto urls into emails and drops empty method objects', function () {
    $result = resultFrom([
        'application_methods' => [
            ['type' => 'external_url', 'url' => 'mailto:vagas@empresa.com'],
            ['type' => 'email'],
        ],
    ]);

    expect($result->applicationMethods)->toHaveCount(1)
        ->and($result->applicationMethods[0]->email)->toBe('vagas@empresa.com')
        ->and($result->applicationMethods[0]->url)->toBeNull();
});
it('deduplicates identical application methods keeping the first', function (array $a, array $b) {
    $result = resultFrom(['application_methods' => [$a, $b]]);

    expect($result->applicationMethods)->toHaveCount(1);
})->with([
    fn () => [['type' => 'email', 'email' => 'vagas@empresa.com'], ['type' => 'email', 'email' => 'VAGAS@empresa.com']],
    fn () => [['type' => 'external_url', 'url' => 'https://empresa.gupy.io/jobs/1', 'platform' => 'Gupy'], ['type' => 'external_url', 'url' => 'https://empresa.gupy.io/jobs/1/', 'platform' => 'gupy']],
]);

it('rejects structurally invalid application methods', function (array $data) {
    resultFrom(['application_methods' => [$data]]);
})->throws(InvalidArgumentException::class)->with([
    fn () => [['type' => 'telepathy', 'instructions' => 'x']],
    fn () => [['type' => 'external_url', 'url' => 'javascript:alert(1)']],
    fn () => [['type' => 'email', 'email' => 'not-an-email']],
    fn () => [['not-an-object']],
]);
