<?php

use App\DTO\MatchingOutput;
use App\Services\MatchingException;
use App\Services\MatchingService;

it('rejects technical superstrings for every evidence source and status', function (string $source, string $status) {
    $dataset = json_decode(file_get_contents(base_path('tests/Fixtures/matching-v1.json')), true, 512, JSON_THROW_ON_ERROR);
    $case = array_column($dataset['cases'], null, 'id')['java_not_javascript'];
    $frozen = $case['frozen'];
    $frozen['anchors'][] = ['id' => 'regression-profile', 'source' => 'profile', 'text' => 'Python'];
    $frozen['anchors'][] = ['id' => 'regression-target', 'source' => $source, 'text' => 'JavaScript'];
    $frozen['anchors'][] = ['id' => 'regression-opportunity', 'source' => 'opportunity', 'text' => 'Java'];
    $output = [
        'schema_version' => 'matching-result-v1', 'criteria_version' => 'matching-v1',
        'input_fingerprint' => MatchingService::hash($frozen), 'basis' => MatchingOutput::basis($frozen),
        'criteria' => array_map(fn (array $criterion): array => [...$criterion, 'explanation' => 'Evidence regression.'], $case['expected']['criteria']),
        'summary' => $case['expected']['summary'],
    ];
    foreach ($output['criteria'] as &$criterion) {
        if ($criterion['id'] !== 'requirement:0') {
            continue;
        }
        $output['summary'][$criterion['status']]--;
        $output['summary'][$status]++;
        $criterion['status'] = $status;
        $criterion['reason_code'] = $status === 'met' ? 'explicit_support' : 'explicit_conflict';
        $criterion['missing_paths'] = [];
        $criterion['evidence'] = [
            ['anchor_id' => 'regression-profile', 'quote' => 'Python'],
            ['anchor_id' => 'regression-opportunity', 'quote' => 'Java'],
            ['anchor_id' => 'regression-target', 'quote' => 'JavaScript'],
        ];
    }
    unset($criterion);
    expect(fn () => MatchingOutput::parse(json_encode($output, JSON_THROW_ON_ERROR), $frozen, MatchingService::hash($frozen)))
        ->toThrow(MatchingException::class);
})->with(['profile', 'publication', 'opportunity'])->with(['met', 'incompatible']);
