<?php

function matchingArtifact(string $file): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/'.$file)), true, 512, JSON_THROW_ON_ERROR);
}

function matchingCanonical(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    if (! array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return array_map(matchingCanonical(...), $value);
}

function matchingFixtureHash(array $value): string
{
    return hash('sha256', json_encode(matchingCanonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
}

function matchingInventory(array $frozen): array
{
    $inventory = [];
    foreach (['requirements' => 'requirement', 'technologies' => 'technology'] as $field => $kind) {
        foreach ($frozen['opportunity']['snapshot'][$field] as $index => $value) {
            $inventory["{$kind}:{$index}"] = $kind;
        }
    }
    foreach (['preferences' => 'preference', 'restrictions' => 'restriction'] as $field => $kind) {
        foreach ($frozen['profile']['snapshot'][$field] as $index => $value) {
            $inventory["{$kind}:{$index}"] = $kind;
        }
    }
    foreach (['experience', 'seniority', 'education', 'location_modality', 'compensation'] as $kind) {
        $inventory[$kind] = $kind;
    }

    return $inventory;
}

function matchingPointer(array $snapshot, string $pointer): mixed
{
    foreach (explode('/', substr($pointer, 1)) as $part) {
        $part = str_replace(['~1', '~0'], ['/', '~'], $part);
        if (! is_array($snapshot) || ! array_key_exists($part, $snapshot)) {
            return null;
        }
        $snapshot = $snapshot[$part];
    }

    return $snapshot;
}

function matchingFixtureViolations(array $result, array $frozen): array
{
    $schema = matchingArtifact('matching-v1.schema.json');
    $errors = [];
    if (array_diff(array_keys($result), $schema['required']) !== [] || array_diff($schema['required'], array_keys($result)) !== []) {
        $errors[] = 'result_keys';
    }
    if (($result['schema_version'] ?? null) !== 'matching-result-v1' || ($result['criteria_version'] ?? null) !== 'matching-v1'
        || ($result['input_fingerprint'] ?? null) !== matchingFixtureHash($frozen)) {
        $errors[] = 'identity';
    }
    $basis = [
        'profile_id' => $frozen['profile']['id'], 'confirmed_version' => $frozen['profile']['confirmed_version'],
        'opportunity_id' => $frozen['opportunity']['id'], 'opportunity_version' => $frozen['opportunity']['opportunity_version'],
        'profile_hash' => $frozen['profile']['hash'], 'opportunity_hash' => $frozen['opportunity']['hash'],
    ];
    if (($result['basis'] ?? null) != $basis) {
        $errors[] = 'basis';
    }
    $inventory = matchingInventory($frozen);
    $anchors = array_column($frozen['anchors'], null, 'id');
    $seen = [];
    $counts = ['met' => 0, 'unsupported' => 0, 'incompatible' => 0, 'uncertain' => 0];
    $reasons = [
        'met' => ['explicit_support'],
        'unsupported' => ['criterion_not_stated', 'profile_information_missing', 'opportunity_information_missing', 'bilateral_information_missing'],
        'incompatible' => ['explicit_conflict'],
        'uncertain' => ['ambiguous_evidence', 'partial_overlap', 'incomparable_compensation'],
    ];
    foreach ($result['criteria'] ?? [] as $criterion) {
        if (array_diff(array_keys($criterion), $schema['$defs']['criterion']['required']) !== []
            || array_diff($schema['$defs']['criterion']['required'], array_keys($criterion)) !== []) {
            $errors[] = 'criterion_keys';
        }
        $id = $criterion['id'];
        $status = $criterion['status'];
        if (isset($seen[$id]) || ($inventory[$id] ?? null) !== $criterion['kind']) {
            $errors[] = 'inventory';
        }
        $seen[$id] = true;
        if (! isset($counts[$status]) || ! in_array($criterion['reason_code'], $reasons[$status] ?? [], true)) {
            $errors[] = 'status_reason';
        } else {
            $counts[$status]++;
        }
        $sources = [];
        foreach ($criterion['evidence'] as $evidence) {
            $anchor = $anchors[$evidence['anchor_id']] ?? null;
            if (count($evidence) !== 2 || ! isset($evidence['quote']) || $evidence['quote'] === '' || $anchor === null
                || ! str_contains($anchor['text'], $evidence['quote'])) {
                $errors[] = 'evidence';
            } else {
                $sources[] = $anchor['source'];
            }
        }
        if (in_array($status, ['met', 'incompatible'], true)
            && (! in_array('profile', $sources, true) || array_intersect(['opportunity', 'publication'], $sources) === [])) {
            $errors[] = 'bilateral_evidence';
        }
        if (($status === 'unsupported') !== ($criterion['missing_paths'] !== [])) {
            $errors[] = 'missing_paths';
        }
        if ($status === 'uncertain' && $criterion['evidence'] === []) {
            $errors[] = 'uncertain_without_evidence';
        }
        foreach ($criterion['missing_paths'] as $path) {
            if (! preg_match('/^(profile|opportunity):\/([^\/]+)/', $path, $parts)
                || ! array_key_exists($parts[2], $frozen[$parts[1]]['snapshot'])) {
                $errors[] = 'missing_side';
            }
        }
    }
    if (array_diff(array_keys($inventory), array_keys($seen)) !== [] || ($result['summary'] ?? null) != $counts) {
        $errors[] = 'inventory_or_counts';
    }

    return array_unique($errors);
}

it('publishes the approved matching contract and versioned fixture artifacts', function () {
    foreach (['docs/JOBORA_V2_MATCHING_CONTRACT.md', 'tests/Fixtures/matching-v1.schema.json', 'tests/Fixtures/matching-v1.json'] as $path) {
        expect(is_file(base_path($path)))->toBeTrue("Artefato ausente: {$path}");
    }
});

it('pins the public schema without scores or unknown output properties', function () {
    $schema = matchingArtifact('matching-v1.schema.json');
    expect($schema['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and($schema['properties']['schema_version']['const'])->toBe('matching-result-v1')
        ->and($schema['properties']['criteria_version']['const'])->toBe('matching-v1')
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['$defs']['criterion']['properties']['status']['enum'])->toBe(['met', 'unsupported', 'incompatible', 'uncertain'])
        ->and($schema['$defs']['anchor']['properties']['source']['enum'])->toBe(['profile', 'opportunity', 'publication']);
    foreach (['basis', 'criterion', 'evidence', 'summary', 'anchor'] as $definition) {
        expect($schema['$defs'][$definition]['additionalProperties'])->toBeFalse()
            ->and(array_keys($schema['$defs'][$definition]['properties']))->toBe($schema['$defs'][$definition]['required']);
    }
    foreach (['input_fingerprint' => 'hash', 'basis' => 'basis', 'summary' => 'summary'] as $field => $definition) {
        expect($schema['properties'][$field]['$ref'])->toBe('#/$defs/'.$definition)
            ->and($schema['$defs'])->toHaveKey($definition);
    }
    expect($schema['properties']['criteria']['items']['$ref'])->toBe('#/$defs/criterion')
        ->and($schema['$defs']['criterion']['properties']['evidence']['items']['$ref'])->toBe('#/$defs/evidence');
    expect(array_keys($schema['properties']))->toBe(['schema_version', 'criteria_version', 'input_fingerprint', 'basis', 'criteria', 'summary']);
});

it('assigns identical expected criteria and counts to identical frozen inputs', function () {
    $expectedByFingerprint = [];
    foreach (matchingArtifact('matching-v1.json')['cases'] as $case) {
        $fingerprint = matchingFixtureHash($case['frozen']);
        $expected = [
            'criteria' => array_map(fn (array $criterion): array => array_diff_key($criterion, ['explanation' => true]), $case['expected']['criteria']),
            'summary' => $case['expected']['summary'],
        ];
        if (isset($expectedByFingerprint[$fingerprint])) {
            expect($expected)->toBe($expectedByFingerprint[$fingerprint], $case['id']);
        } else {
            $expectedByFingerprint[$fingerprint] = $expected;
        }
    }
    expect($expectedByFingerprint)->not->toBeEmpty();
});

it('contains manually labelled scenarios with complete inventories and resolvable frozen anchors', function () {
    $dataset = matchingArtifact('matching-v1.json');
    expect($dataset['dataset_version'])->toBe('matching-v1')
        ->and(count($dataset['cases']))->toBeGreaterThanOrEqual(16);
    $ids = array_column($dataset['cases'], 'id');
    expect(count($ids))->toBe(count(array_unique($ids)));
    foreach ($dataset['cases'] as $case) {
        $frozen = $case['frozen'];
        expect($frozen['profile']['confirmed_version'])->toBeGreaterThan(0)
            ->and($frozen['criteria_version'])->toBe('matching-v1')
            ->and($frozen['schema_version'])->toBe('matching-result-v1')
            ->and($frozen['provider'])->toBe('opencode_go')
            ->and($frozen['model'])->toBe('deepseek-v4.1-flash')
            ->and($frozen['opportunity']['snapshot']['readiness'])->toBe('ready')
            ->and($frozen['profile']['hash'])->toBe(matchingFixtureHash($frozen['profile']['snapshot']))
            ->and($frozen['opportunity']['hash'])->toBe(matchingFixtureHash($frozen['opportunity']['snapshot']));
        $anchorIds = array_column($frozen['anchors'], 'id');
        expect(count($anchorIds))->toBe(count(array_unique($anchorIds)));
        foreach ($frozen['anchors'] as $anchor) {
            $entity = $anchor['source'] === 'publication' ? $frozen['provenance'][0] : $frozen[$anchor['source']];
            $prefix = ['profile' => 'p', 'opportunity' => 'o', 'publication' => 'pub'][$anchor['source']];
            expect($anchor['entity_id'])->toBe($entity['id'])
                ->and($anchor['version'])->toBe($entity[$anchor['source'] === 'profile' ? 'confirmed_version' : ($anchor['source'] === 'opportunity' ? 'opportunity_version' : 'version')])
                ->and($anchor['snapshot_hash'])->toBe($entity['hash'])
                ->and($anchor['text'])->toBe(matchingPointer($entity['snapshot'], $anchor['path']))
                ->and($anchor['id'])->toBe("{$prefix}:{$anchor['entity_id']}:v{$anchor['version']}:{$anchor['path']}")
                ->and($entity['hash'])->toBe(matchingFixtureHash($entity['snapshot']));
        }
        $sample = [
            'schema_version' => 'matching-result-v1', 'criteria_version' => 'matching-v1',
            'input_fingerprint' => matchingFixtureHash($frozen), 'basis' => $case['basis'],
            'criteria' => array_map(fn (array $criterion): array => [...$criterion, 'explanation' => 'Texto livre não comparado.'], $case['expected']['criteria']),
            'summary' => $case['expected']['summary'],
        ];
        expect(matchingFixtureViolations($sample, $frozen))->toBe([], $case['id']);
    }
});

it('pins expected domain labels without evaluating semantics or comparing free explanations', function () {
    $cases = array_column(matchingArtifact('matching-v1.json')['cases'], null, 'id');
    foreach ([
        ['php_bilateral', 'requirement:0', 'met', 'explicit_support', []],
        ['php_missing_profile', 'requirement:0', 'unsupported', 'profile_information_missing', ['profile:/competencies']],
        ['incomplete_opportunity', 'location_modality', 'unsupported', 'opportunity_information_missing', ['opportunity:/location', 'opportunity:/work_mode']],
        ['incomplete_opportunity', 'compensation', 'unsupported', 'opportunity_information_missing', ['opportunity:/salary_currency', 'opportunity:/salary_period']],
        ['onsite_restriction', 'restriction:0', 'incompatible', 'explicit_conflict', []],
        ['remote_hybrid_negotiable', 'preference:0', 'uncertain', 'ambiguous_evidence', []],
        ['java_not_javascript', 'requirement:0', 'unsupported', 'profile_information_missing', ['profile:/competencies']],
        ['c_not_cpp', 'requirement:0', 'unsupported', 'profile_information_missing', ['profile:/competencies']],
        ['education_ambiguous', 'education', 'uncertain', 'ambiguous_evidence', []],
        ['seniority_ambiguous', 'seniority', 'uncertain', 'ambiguous_evidence', []],
        ['overlapping_experience_not_added', 'experience', 'uncertain', 'ambiguous_evidence', []],
        ['compensation_met', 'compensation', 'met', 'explicit_support', []],
        ['compensation_incompatible', 'compensation', 'incompatible', 'explicit_conflict', []],
        ['compensation_partial_overlap', 'compensation', 'uncertain', 'partial_overlap', []],
        ['compensation_currency_missing', 'compensation', 'unsupported', 'profile_information_missing', ['profile:/compensation_expectation']],
        ['compensation_currency_incomparable', 'compensation', 'uncertain', 'incomparable_compensation', []],
        ['prompt_injection_ignored', 'requirement:0', 'met', 'explicit_support', []],
        ['technology_bilateral', 'technology:0', 'met', 'explicit_support', []],
    ] as [$caseId, $criterionId, $status, $reason, $missing]) {
        $criterion = array_column($cases[$caseId]['expected']['criteria'], null, 'id')[$criterionId];
        expect($criterion['status'])->toBe($status)
            ->and($criterion['reason_code'])->toBe($reason)
            ->and($criterion['missing_paths'])->toBe($missing);
    }
    expect($cases['php_bilateral']['expected']['criteria'][0]['evidence'])->toBe([
        ['anchor_id' => 'p:7:v4:/competencies/0', 'quote' => 'PHP'],
        ['anchor_id' => 'o:42:v3:/requirements/0', 'quote' => 'PHP'],
    ])->and($cases['php_bilateral']['frozen']['opportunity']['snapshot']['status'])->toBe('pending')
        ->and(matchingInventory($cases['prompt_injection_ignored']['frozen']))->toBe([
            'requirement:0' => 'requirement', 'experience' => 'experience', 'seniority' => 'seniority',
            'education' => 'education', 'location_modality' => 'location_modality', 'compensation' => 'compensation',
        ])->and($cases['prompt_injection_ignored']['frozen']['user_id'])->toBe(1);
    foreach ($cases['criteria_not_stated']['expected']['criteria'] as $criterion) {
        expect($criterion['status'])->toBe('unsupported')->and($criterion['reason_code'])->toBe('criterion_not_stated');
    }
});

it('checks the complete example and rejects the declared invalid output vectors', function () {
    $dataset = matchingArtifact('matching-v1.json');
    $case = array_column($dataset['cases'], null, 'id')[$dataset['sample_case']];
    expect(matchingFixtureViolations($dataset['sample_result'], $case['frozen']))->toBe([])
        ->and(count($dataset['invalid_outputs']))->toBeGreaterThanOrEqual(10);
    foreach ($dataset['sample_result']['criteria'] as $index => $criterion) {
        expect(array_diff_key($criterion, ['explanation' => true]))->toBe($case['expected']['criteria'][$index]);
    }
    foreach ($dataset['invalid_outputs'] as $vector) {
        $result = $dataset['sample_result'];
        data_set($result, $vector['path'], $vector['value']);
        expect($vector['expected_error'])->toBe('invalid_output')
            ->and(matchingFixtureViolations($result, $case['frozen']))->not->toBeEmpty($vector['id']);
    }
});

it('pins no-provider gate examples without claiming to execute authorization or matching', function () {
    $gates = array_column(matchingArtifact('matching-v1.json')['gate_cases'], null, 'id');
    foreach (['profile_not_confirmed' => 'profile_not_confirmed', 'opportunity_needs_review' => 'opportunity_not_ready', 'foreign_owner' => 'not_found', 'version_conflict' => 'conflict'] as $id => $error) {
        expect($gates[$id]['expected_error'])->toBe($error)->and($gates[$id]['expected_provider_calls'])->toBe(0);
    }
    expect($gates['profile_not_confirmed']['confirmed_snapshot'])->toBeNull()
        ->and($gates['profile_not_confirmed']['profile_confirmed_version'])->toBe(0);
});

it('keeps draft edits valid and versioned identity changes stale without automatic work', function () {
    $dataset = matchingArtifact('matching-v1.json');
    $cases = array_column($dataset['cases'], null, 'id');
    $expected = [
        'unchanged' => false, 'draft_changed' => false, 'new_confirmation' => true,
        'new_opportunity' => true, 'new_criteria' => true, 'changed_profile_hash' => true,
        'changed_provider_model' => true, 'changed_provenance' => true, 'changed_schema' => true,
    ];
    expect(array_column($dataset['validity_cases'], 'id'))->toBe(array_keys($expected));
    foreach ($dataset['validity_cases'] as $vector) {
        $frozen = $cases[$vector['basis_case']]['frozen'];
        $identity = [
            'user_id' => $frozen['user_id'], 'profile_id' => $frozen['profile']['id'],
            'confirmed_version' => $frozen['profile']['confirmed_version'], 'profile_hash' => $frozen['profile']['hash'],
            'opportunity_id' => $frozen['opportunity']['id'], 'opportunity_version' => $frozen['opportunity']['opportunity_version'],
            'opportunity_hash' => $frozen['opportunity']['hash'], 'criteria_version' => $frozen['criteria_version'],
            'schema_version' => $frozen['schema_version'], 'provider' => $frozen['provider'], 'model' => $frozen['model'],
            'provenance_hash' => matchingFixtureHash($frozen['provenance']),
        ];
        $currentIdentity = array_intersect_key($vector['current'], $identity);
        expect($vector['expected_stale'])->toBe($expected[$vector['id']])
            ->and($currentIdentity !== $identity)->toBe($vector['expected_stale'])
            ->and($vector['expected_automatic_dispatches'])->toBe(0);
    }
});
