<?php

use App\Models\JobCapture;
use App\Models\User;
use App\Services\CaptureTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('returns authenticated retirement guidance without storing or processing legacy captures', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $historic = JobCapture::factory()->for($owner, 'user')->create();
    $before = $historic->fresh()->getAttributes();
    $token = app(CaptureTokenService::class)->issueToken($owner);
    $this->withToken($token)->postJson('/api/job-captures', ['source' => 'linkedin', 'external_id' => 'retired-new-capture',
        'description' => 'Esta publicação não deve ser armazenada pelo contrato antigo.'])
        ->assertStatus(410)->assertJsonPath('error', 'legacy_capture_retired')->assertJsonPath('replacement', '/api/page-captures');
    expect($historic->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('job_captures', 1);
    $this->assertDatabaseCount('page_captures', 0);
    $this->assertDatabaseCount('ai_operations', 0);
    Queue::assertNothingPushed();
});
