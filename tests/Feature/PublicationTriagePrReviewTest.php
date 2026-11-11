<?php

use App\Agents\PublicationTriageAgent;
use App\DTO\PublicationTriageOutput;
use App\Enums\ApplicationMethodType;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('constrains triage schema strings to the DTO contract', function () {
    $agent = new PublicationTriageAgent;
    $schema = $agent->schema(new JsonSchemaTypeFactory)['opportunities']->toArray()['items']['properties'];
    $method = $schema['application_methods']['items']['properties'];
    $types = array_column(ApplicationMethodType::cases(), 'value');

    expect($method['type']['enum'] ?? null)->toBe($types);
    foreach ($types as $type) {
        expect($agent->instructions())->toContain($type);
    }
    foreach (['role' => 255, 'company' => 255, 'description' => 100000, 'location' => 255, 'work_mode' => 100, 'employment_type' => 100, 'salary_currency' => 3, 'salary_period' => 50, 'seniority' => 100] as $field => $length) {
        expect($schema[$field]['maxLength'] ?? null)->toBe($length);
    }
    foreach (['platform' => 100, 'url' => 2048, 'email' => 320, 'phone' => 50, 'instructions' => 10000] as $field => $length) {
        expect($method[$field]['maxLength'] ?? null)->toBe($length);
    }
    expect($schema['requirements']['items']['maxLength'] ?? null)->toBe(100)
        ->and($schema['technologies']['items']['maxLength'] ?? null)->toBe(100)
        ->and($schema['evidence']['items']['properties']['quote']['maxLength'] ?? null)->toBe(2000)
        ->and($schema['evidence']['items']['properties']['block_ids']['items']['maxLength'] ?? null)->toBe(128)
        ->and($schema['evidence']['items']['properties']['fields']['items']['maxLength'] ?? null)->toBe(64);
});

it('recognizes grouped salary evidence without accepting a different amount', function (string $salary, float $amount) {
    $content = "Developer salary {$salary}";
    $opportunity = [
        'role' => 'Developer',
        'salary_min' => $amount,
        'salary_max' => $amount,
        'evidence' => [['quote' => $content, 'block_ids' => ['block-1'], 'fields' => ['role', 'salary_min', 'salary_max']]],
    ];
    $output = PublicationTriageOutput::fromStructured(['opportunities' => [$opportunity]], $content, ['block-1'], null);
    expect($output->opportunities[0]['readiness'])->toBe('ready');
    $opportunity['salary_min'] = $amount + 1;
    expect(PublicationTriageOutput::unsupportedFields($opportunity))->toContain('salary_min');
})->with([
    ['R$ 5.000', 5000.0],
    ['1,500', 1500.0],
    ['1.234.567', 1234567.0],
    ['5.000,50', 5000.5],
    ['1,500.50', 1500.5],
]);

it('accepts other application methods only with useful grounded data', function (array $method, bool $ready) {
    $content = 'Developer: bring your resume to reception. https://example.com/apply jobs@example.com +5511999999999';
    $opportunity = [
        'role' => 'Developer',
        'application_methods' => [['type' => 'other', ...$method]],
        'evidence' => [['quote' => $content, 'block_ids' => ['block-1'], 'fields' => ['role', 'application_methods']]],
    ];
    $output = PublicationTriageOutput::fromStructured(['opportunities' => [$opportunity]], $content, ['block-1'], null);
    expect($output->opportunities[0]['readiness'])->toBe($ready ? 'ready' : 'needs_review');
})->with([
    [['instructions' => 'bring your resume to reception'], true],
    [['url' => 'https://example.com/apply'], true],
    [['email' => 'jobs@example.com'], true],
    [['phone' => '+5511999999999'], true],
    [[], false],
    [['platform' => 'Developer'], false],
    [['instructions' => 'send a private message'], false],
    [['instructions' => 'bring your resume to reception', 'email' => 'invented@example.com'], false],
]);
