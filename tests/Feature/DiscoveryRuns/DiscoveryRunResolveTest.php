<?php

use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\User;
use App\Services\CaptureTokenService;
use App\Services\Discovery\DiscoveryRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

it('resolves an active run for the extension using the capture credential', function () {
    $user = User::factory()->create();
    $source = DiscoverySource::factory()->for($user, 'user')->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'idem')->first();
    $token = app(CaptureTokenService::class)->issueToken($user);

    $this->withToken($token)
        ->postJson('/api/discovery-runs/resolve', ['url' => $source->url])
        ->assertOk()
        ->assertJsonPath('execution_id', $run->execution_id)
        ->assertJsonPath('source', 'linkedin')
        ->assertJsonPath('surface', 'linkedin_jobs');
});

it('returns 404 for an unknown canonical search url', function () {
    $user = User::factory()->create();
    $token = app(CaptureTokenService::class)->issueToken($user);

    $this->withToken($token)
        ->postJson('/api/discovery-runs/resolve', [
            'url' => 'https://www.linkedin.com/jobs/search/?keywords=SemFonte',
        ])
        ->assertStatus(404);
});

it('returns 409 when more than one active run matches the source', function () {
    $user = User::factory()->create();
    $source = DiscoverySource::factory()->for($user, 'user')->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);
    app(DiscoveryRunService::class)->acquire($user, null, 'idem');
    DiscoveryRun::factory()->for($user, 'user')->create([
        'discovery_source_id' => $source->id,
        'canonical_url_hash' => $source->canonical_url_hash,
    ]);
    $token = app(CaptureTokenService::class)->issueToken($user);

    $this->withToken($token)
        ->postJson('/api/discovery-runs/resolve', ['url' => $source->url])
        ->assertStatus(409);
});

it('keeps oauth and capture credentials separate on the resolve endpoint', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client);
    $oauthToken = Helpers::accessToken($user, $client);

    $this->withToken($oauthToken)
        ->postJson('/api/discovery-runs/resolve', ['url' => 'https://www.linkedin.com/jobs/search/?keywords=Laravel'])
        ->assertStatus(401);

    $this->postJson('/api/discovery-runs/resolve', ['url' => 'https://www.linkedin.com/jobs/search/?keywords=Laravel'])
        ->assertStatus(401);
});

it('does not resolve another user source with a valid capture credential', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $source = DiscoverySource::factory()->for($owner, 'user')->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);
    app(DiscoveryRunService::class)->acquire($owner, null, 'idem');
    $token = app(CaptureTokenService::class)->issueToken($other);

    $this->withToken($token)
        ->postJson('/api/discovery-runs/resolve', ['url' => $source->url])
        ->assertStatus(404);
});
