<?php

use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\User;
use App\Services\CaptureTokenService;
use App\Services\Discovery\DiscoveryRunService;
use App\Services\Discovery\DiscoverySourceUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

it('preserves known tracking equivalence but not unknown filters on observed Jobs URLs', function () {
    $user = User::factory()->create();
    $url = 'https://www.linkedin.com/jobs/search/?keywords=Laravel&f_WT=2';
    $source = DiscoverySource::factory()->for($user, 'user')->create(['url' => $url, 'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($url)]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'tracking-reservation')->sole();
    $hash = $run->canonical_url_hash;
    $observed = str_replace('/jobs/search/', '/jobs/search-results', $url).'&trk=abc&UTM_SOURCE=synthetic&utm_campaign=smoke&skipRedirect=true';
    $token = app(CaptureTokenService::class)->issueToken($user);
    $this->withToken($token)->postJson('/api/discovery-runs/resolve', ['url' => $observed])->assertOk()->assertJsonPath('execution_id', $run->execution_id);
    $this->withToken($token)->postJson('/api/discovery-runs/resolve', ['url' => $observed.'&utm_unknown=kept'])->assertNotFound();
    expect($run->refresh()->canonical_url_hash)->toBe($hash)
        ->and($source->refresh()->canonical_url_hash)->toBe($hash);
});

it('keeps allowed LinkedIn hosts distinct when resolving otherwise equivalent reservations', function () {
    $user = User::factory()->create();
    $runs = [];
    foreach (['www.linkedin.com', 'br.linkedin.com'] as $host) {
        $url = 'https://'.$host.'/jobs/search/?keywords=Laravel';
        DiscoverySource::factory()->for($user, 'user')->create(['url' => $url, 'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($url)]);
        $runs[$host] = app(DiscoveryRunService::class)->acquire($user, null, 'host-'.$host)->sole();
    }
    $token = app(CaptureTokenService::class)->issueToken($user);
    foreach ($runs as $host => $run) {
        $this->withToken($token)->postJson('/api/discovery-runs/resolve', ['url' => 'https://'.strtoupper($host).'/jobs/search-results?keywords=Laravel'])
            ->assertOk()->assertJsonPath('execution_id', $run->execution_id);
    }
    $runs['br.linkedin.com']->update(['status' => 'expired', 'active_key' => null]);
    $this->withToken($token)->postJson('/api/discovery-runs/resolve', ['url' => 'https://br.linkedin.com/jobs/search-results?keywords=Laravel'])->assertNotFound();
});

it('resolves a redirected LinkedIn Jobs observation against the frozen reservation without changing persisted hashes', function () {
    $user = User::factory()->create();
    $url = 'https://www.linkedin.com/jobs/search/?keywords=Laravel&f_WT=2&f_TPR=r86400&f_SAL=salary&custom=kept';
    $source = DiscoverySource::factory()->for($user, 'user')->create(['url' => $url, 'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($url)]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'redirected-reservation')->sole();
    $hash = $run->canonical_url_hash;
    $observed = str_replace('/jobs/search/', '/jobs/search-results', $url).'&currentJobId=4486639255&skipRedirect=true&infoNotice=ai-job-search';
    $token = app(CaptureTokenService::class)->issueToken($user);
    $this->withToken($token)->postJson('/api/discovery-runs/resolve', ['url' => $observed])->assertOk()->assertJsonPath('execution_id', $run->execution_id);
    expect($source->refresh()->canonical_url_hash)->toBe($hash)
        ->and($run->refresh()->canonical_url_hash)->toBe($hash);
});

it('does not collapse semantic changes or duplicate query parameters when resolving redirected Jobs', function (string $query) {
    $user = User::factory()->create();
    $url = 'https://www.linkedin.com/jobs/search/?keywords=Laravel&f_WT=2&f_TPR=r86400&f_SAL=salary&location=Remote&custom=kept';
    $source = DiscoverySource::factory()->for($user, 'user')->create(['url' => $url, 'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($url)]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'semantic-reservation')->sole();
    $token = app(CaptureTokenService::class)->issueToken($user);
    $this->withToken($token)->postJson('/api/discovery-runs/resolve', ['url' => 'https://www.linkedin.com/jobs/search-results?'.$query])->assertNotFound();
    expect($run->refresh()->page_capture_id)->toBeNull();
})->with([
    'changed keyword' => ['keywords=PHP&f_WT=2&f_TPR=r86400&f_SAL=salary&location=Remote&custom=kept'],
    'removed period' => ['keywords=Laravel&f_WT=2&f_SAL=salary&location=Remote&custom=kept'],
    'changed period' => ['keywords=Laravel&f_WT=2&f_TPR=r604800&f_SAL=salary&location=Remote&custom=kept'],
    'removed salary' => ['keywords=Laravel&f_WT=2&f_TPR=r86400&location=Remote&custom=kept'],
    'changed salary' => ['keywords=Laravel&f_WT=2&f_TPR=r86400&f_SAL=other&location=Remote&custom=kept'],
    'changed location' => ['keywords=Laravel&f_WT=2&f_TPR=r86400&f_SAL=salary&location=Other&custom=kept'],
    'removed unknown' => ['keywords=Laravel&f_WT=2&f_TPR=r86400&f_SAL=salary&location=Remote'],
    'added unknown' => ['keywords=Laravel&f_WT=2&f_TPR=r86400&f_SAL=salary&location=Remote&custom=kept&extra='],
    'duplicate keyword' => ['keywords=PHP&keywords=Laravel&f_WT=2&f_TPR=r86400&f_SAL=salary&location=Remote&custom=kept'],
    'duplicate period' => ['keywords=Laravel&f_WT=2&f_TPR=r604800&f_TPR=r86400&f_SAL=salary&location=Remote&custom=kept'],
    'duplicate unknown' => ['keywords=Laravel&f_WT=2&f_TPR=r86400&f_SAL=salary&location=Remote&custom=other&custom=kept'],
]);

it('resolves only owned unexpired frozen reservations and rejects ambiguity across path aliases', function () {
    $this->freezeTime();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $url = 'https://www.linkedin.com/jobs/search/?keywords=Laravel';
    $source = DiscoverySource::factory()->for($owner, 'user')->create(['url' => $url, 'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($url)]);
    $run = app(DiscoveryRunService::class)->acquire($owner, null, 'alias-fencing')->sole();
    $observed = 'https://www.linkedin.com/jobs/search-results/?keywords=Laravel&skipRedirect=true';
    $this->withToken(app(CaptureTokenService::class)->issueToken($other))->postJson('/api/discovery-runs/resolve', ['url' => $observed])->assertNotFound();
    $token = app(CaptureTokenService::class)->issueToken($owner);
    DiscoverySource::factory()->for($owner, 'user')->create(['url' => $observed, 'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($observed)]);
    app(DiscoveryRunService::class)->acquire($owner, null, 'second-alias');
    $this->withToken($token)->postJson('/api/discovery-runs/resolve', ['url' => $observed])->assertConflict();
    $this->travelTo($run->expires_at);
    $this->withToken($token)->postJson('/api/discovery-runs/resolve', ['url' => $observed])->assertNotFound();
});

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
        'initial_url' => $source->url,
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
