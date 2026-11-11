<?php

use App\Agents\PublicationTriageAgent;
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
