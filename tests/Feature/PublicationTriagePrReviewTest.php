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
