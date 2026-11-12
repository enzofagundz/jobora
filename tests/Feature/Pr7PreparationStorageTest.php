<?php

use App\Models\Application;
use App\Models\ApplicationPreparation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('round trips an encrypted preparation with full resume copies beyond the MySQL text limit', function () {
    $application = Application::factory()->create();
    $snapshot = ['resume' => str_repeat('Currículo PHP. ', 10000), 'canonical_resume' => str_repeat('Resume PHP. ', 10000)];
    $preparation = ApplicationPreparation::query()->create([
        'user_id' => $application->jobPosting->user_id, 'application_id' => $application->id,
        'language' => 'en', 'schema_version' => '1', 'state' => 'ready',
        'snapshot' => $snapshot, 'pending' => [], 'source_versions' => [],
        'source_hash' => str_repeat('a', 64), 'fingerprint' => str_repeat('b', 64),
    ]);

    expect(strlen($preparation->getRawOriginal('snapshot')))->toBeGreaterThan(65535)
        ->and($preparation->fresh()->snapshot)->toBe($snapshot);
});
