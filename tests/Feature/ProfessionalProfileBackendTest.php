<?php

use App\Enums\AgentIntegrationScope;
use App\Models\ProfessionalProfile;
use App\Models\Resume;
use App\Models\ScreeningPreference;
use App\Models\User;
use App\Services\ProfessionalProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

function profileDocument(array $overrides = []): array
{
    return array_replace([
        'experiences' => [['company' => 'Empresa', 'role' => 'Developer', 'period' => null, 'description' => 'PHP']],
        'education' => [['institution' => 'Universidade', 'qualification' => null, 'period' => null]],
        'competencies' => ['PHP'],
        'preferences' => ['Remoto'],
        'restrictions' => ['Presencial'],
        'compensation_expectation' => null,
    ], $overrides);
}

function profileToken(User $user, array $scopes = ['profile:read', 'profile:write']): string
{
    $client = Helpers::client();
    Helpers::integration($user, $client, $scopes);

    return Helpers::accessToken($user, $client);
}

it('reads only the authenticated profile without inventing a confirmation', function () {
    $user = User::factory()->create();
    ScreeningPreference::factory()->for($user)->create(['preferences' => ['Remoto'], 'rejections' => ['Presencial']]);
    $other = User::factory()->create();
    ScreeningPreference::factory()->for($other)->create(['preferences' => ['Segredo']]);

    $response = $this->withToken(profileToken($user))
        ->postJson('/mcp', Helpers::toolCall('get_professional_profile'))->assertOk();
    $body = Helpers::decodeToolResult($response->getContent());

    expect($body['ok'] ?? null)->toBeTrue()
        ->and($body['data'])->toEqual([
            'version' => 0,
            'draft' => profileDocument(['experiences' => [], 'education' => [], 'competencies' => []]),
            'confirmed_version' => 0,
            'confirmed_snapshot' => null,
            'confirmed_at' => null,
            'has_unconfirmed_changes' => false,
        ])
        ->and(app(ProfessionalProfileService::class)->confirmed($user))->toBeNull();
});

it('replaces the draft atomically with CAS and a single mutable preference source', function () {
    $user = User::factory()->create();
    $token = profileToken($user);
    $save = fn (array $document, int $version): array => Helpers::decodeToolResult($this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('save_professional_profile', ['profile' => $document, 'expected_version' => $version]))
        ->assertOk()->getContent());

    $first = $save(profileDocument(), 0);
    expect($first['ok'] ?? null)->toBeTrue()
        ->and($first['data']['version'])->toBe(1)
        ->and($first['data']['confirmed_snapshot'])->toBeNull()
        ->and($first['data']['has_unconfirmed_changes'])->toBeTrue();

    $replacement = profileDocument(['experiences' => [], 'education' => [], 'competencies' => [], 'preferences' => ['Híbrido']]);
    $second = $save($replacement, 1);
    expect($second['data']['draft'])->toEqual($replacement)
        ->and($second['data']['version'])->toBe(2)
        ->and($save(profileDocument(), 1)['error']['code'] ?? null)->toBe('conflict')
        ->and($save(profileDocument(), 0)['error']['code'] ?? null)->toBe('conflict');

    $profile = $user->professionalProfile()->sole();
    expect($profile->draft)->not->toHaveKeys(['preferences', 'restrictions'])
        ->and($profile->getRawOriginal('draft'))->not->toContain('compensation_expectation')
        ->and($profile->version)->toBe(2)
        ->and(ScreeningPreference::query()->where('user_id', $user->id)->sole()->preferences)->toBe(['Híbrido'])
        ->and(ProfessionalProfile::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(app(ProfessionalProfileService::class)->confirmed($user))->toBeNull();
});

it('rejects invalid documents without creating a draft or preferences', function (array $document) {
    $user = User::factory()->create();
    $body = Helpers::decodeToolResult($this->withToken(profileToken($user))
        ->postJson('/mcp', Helpers::toolCall('save_professional_profile', ['profile' => $document, 'expected_version' => 0]))
        ->assertOk()->getContent());

    expect($body['error']['code'] ?? null)->toBe('validation');
    $this->assertDatabaseCount('professional_profiles', 0);
    $this->assertDatabaseCount('screening_preferences', 0);
})->with([
    'unknown key' => fn () => [profileDocument(['inferred_seniority' => 'senior'])],
    'unknown experience key' => fn () => [profileDocument(['experiences' => [['company' => null, 'role' => null, 'period' => null, 'description' => null, 'inferred' => true]]])],
    'missing field' => fn () => [array_diff_key(profileDocument(), ['education' => true])],
    'missing nested field' => fn () => [profileDocument(['education' => [['institution' => 'X']]])],
    'not a list' => fn () => [profileDocument(['competencies' => ['key' => 'PHP']])],
    'not a string' => fn () => [profileDocument(['competencies' => [42]])],
    'too many items' => fn () => [profileDocument(['preferences' => array_fill(0, 101, 'PHP')])],
    'too long' => fn () => [profileDocument(['compensation_expectation' => str_repeat('x', 501)])],
]);

it('confirms only the persisted version and preserves an immutable confirmed snapshot', function () {
    $user = User::factory()->create();
    $token = profileToken($user);
    $call = fn (string $name, array $arguments): array => Helpers::decodeToolResult($this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall($name, $arguments))->assertOk()->getContent());

    $call('save_professional_profile', ['profile' => profileDocument(), 'expected_version' => 0]);
    expect($call('confirm_professional_profile', ['expected_version' => 1])['error']['code'] ?? null)->toBe('conflict');
    expect($call('confirm_professional_profile', ['expected_version' => 1, 'confirm' => true, 'actor_metadata' => ['source' => 'forged']])['error']['code'] ?? null)->toBe('validation');
    expect(app(ProfessionalProfileService::class)->confirmed($user))->toBeNull();

    $confirmed = $call('confirm_professional_profile', ['expected_version' => 1, 'confirm' => true]);
    expect($confirmed['ok'] ?? null)->toBeTrue()
        ->and($confirmed['data']['version'])->toBe(2)
        ->and($confirmed['data']['confirmed_version'])->toBe(2)
        ->and($confirmed['data']['has_unconfirmed_changes'])->toBeFalse()
        ->and($confirmed['data']['confirmed_at'])->not->toBeNull()
        ->and($confirmed['data']['confirmed_snapshot'])->toEqual(profileDocument());

    $profile = $user->professionalProfile()->sole();
    expect($profile->confirmed_actor)->toEqual([
        'user_id' => $user->id,
        'integration_id' => $user->agentIntegrations()->sole()->id,
        'channel' => 'mcp',
    ])->and($profile->getRawOriginal('confirmed_snapshot'))->not->toContain('Empresa');

    expect($call('confirm_professional_profile', ['expected_version' => 1, 'confirm' => true])['error']['code'] ?? null)->toBe('conflict');
    $changed = profileDocument(['experiences' => [], 'preferences' => ['Híbrido']]);
    $after = $call('save_professional_profile', ['profile' => $changed, 'expected_version' => 2]);
    expect($after['data']['version'])->toBe(3)
        ->and($after['data']['confirmed_version'])->toBe(2)
        ->and($after['data']['confirmed_at'])->toBe($confirmed['data']['confirmed_at'])
        ->and($after['data']['has_unconfirmed_changes'])->toBeTrue()
        ->and(app(ProfessionalProfileService::class)->confirmed($user))->toEqual(profileDocument());

    $reconfirmed = $call('confirm_professional_profile', ['expected_version' => 3, 'confirm' => true]);
    expect($reconfirmed['data']['version'])->toBe(4)
        ->and($reconfirmed['data']['confirmed_version'])->toBe(4)
        ->and($reconfirmed['data']['has_unconfirmed_changes'])->toBeFalse()
        ->and(app(ProfessionalProfileService::class)->confirmed($user))->toEqual($changed);

    $this->assertDatabaseCount('resumes', 0);
});

it('requires explicit profile scopes and never expands an existing integration', function () {
    $user = User::factory()->create();
    $token = profileToken($user, ['resume:read', 'resume:write', 'screening:read', 'screening:write']);
    foreach (['get_professional_profile' => [], 'save_professional_profile' => ['profile' => profileDocument(), 'expected_version' => 0], 'confirm_professional_profile' => ['confirm' => true, 'expected_version' => 0]] as $tool => $arguments) {
        $body = Helpers::decodeToolResult($this->withToken($token)->postJson('/mcp', Helpers::toolCall($tool, $arguments))->assertOk()->getContent());
        expect($body['error']['code'] ?? null)->toBe('forbidden');
    }
    expect($user->agentIntegrations()->sole()->can(AgentIntegrationScope::ProfileWrite))->toBeFalse();
    $this->assertDatabaseCount('professional_profiles', 0);
});

it('keeps every write and confirmation private to the authenticated owner', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $service = app(ProfessionalProfileService::class);
    $service->saveDraft($bob, profileDocument(['competencies' => ['Segredo']]), 0);
    $service->confirm($bob, 1);
    $before = $service->get($bob);

    $token = profileToken($alice);
    $write = Helpers::decodeToolResult($this->withToken($token)->postJson('/mcp', Helpers::toolCall('save_professional_profile', [
        'profile' => profileDocument(), 'expected_version' => 0,
    ]))->assertOk()->getContent());
    expect($write['data']['draft']['competencies'] ?? null)->toBe(['PHP']);
    $confirm = Helpers::decodeToolResult($this->withToken($token)->postJson('/mcp', Helpers::toolCall('confirm_professional_profile', [
        'expected_version' => 1, 'confirm' => true,
    ]))->assertOk()->getContent());
    expect($confirm['data']['confirmed_snapshot']['competencies'] ?? null)->toBe(['PHP'])
        ->and($service->get($bob))->toEqual($before);
});

it('routes legacy screening writes through versioned drafts without confirming', function () {
    $user = User::factory()->create();
    $service = app(ProfessionalProfileService::class);
    $service->saveDraft($user, profileDocument(), 0);
    $service->confirm($user, 1);
    $token = profileToken($user, ['screening:write', 'screening:read']);
    $call = fn (array $arguments): array => Helpers::decodeToolResult($this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('save_screening_preferences', $arguments))->assertOk()->getContent());

    $saved = $call(['preferences' => [' Híbrido ', '', 'Híbrido'], 'rejections' => ['Viagens']]);
    expect($saved['ok'] ?? null)->toBeTrue()
        ->and($saved['data']['preferences'])->toBe(['Híbrido'])
        ->and($saved['data']['rejections'])->toBe(['Viagens'])
        ->and($service->get($user)['version'])->toBe(3)
        ->and($service->get($user)['confirmed_version'])->toBe(2)
        ->and($service->get($user)['has_unconfirmed_changes'])->toBeTrue()
        ->and($service->confirmed($user))->toEqual(profileDocument());

    expect($call(['preferences' => ['Stale'], 'expected_version' => 2])['error']['code'] ?? null)->toBe('conflict')
        ->and($service->get($user)['draft']['preferences'])->toBe(['Híbrido']);
    $fresh = $call(['preferences' => ['Remoto'], 'rejections' => [], 'expected_version' => 3]);
    expect($fresh['ok'] ?? null)->toBeTrue()
        ->and($service->get($user)['version'])->toBe(4)
        ->and($service->confirmed($user))->toEqual(profileDocument());
});

it('rejects extra MCP arguments and invalid versions before any write', function (string $tool, array $arguments) {
    $user = User::factory()->create();
    $body = Helpers::decodeToolResult($this->withToken(profileToken($user, ['profile:read', 'profile:write', 'screening:write']))
        ->postJson('/mcp', Helpers::toolCall($tool, $arguments))->assertOk()->getContent());

    expect($body['error']['code'] ?? null)->toBe('validation');
    $this->assertDatabaseCount('professional_profiles', 0);
    $this->assertDatabaseCount('screening_preferences', 0);
})->with([
    'read owner injection' => ['get_professional_profile', ['user_id' => 123]],
    'write owner injection' => fn () => ['save_professional_profile', ['profile' => profileDocument(), 'expected_version' => 0, 'user_id' => 123]],
    'negative version' => fn () => ['save_professional_profile', ['profile' => profileDocument(), 'expected_version' => -1]],
    'missing version' => fn () => ['save_professional_profile', ['profile' => profileDocument()]],
    'string version' => fn () => ['save_professional_profile', ['profile' => profileDocument(), 'expected_version' => '0']],
    'legacy unknown key' => ['save_screening_preferences', ['preferences' => [], 'inferred' => true]],
    'legacy invalid list' => ['save_screening_preferences', ['preferences' => ['key' => 'x']]],
    'legacy invalid version' => ['save_screening_preferences', ['preferences' => [], 'expected_version' => -1]],
]);

it('rolls back initial creation when the expected version is stale', function () {
    $user = User::factory()->create();
    $body = Helpers::decodeToolResult($this->withToken(profileToken($user))
        ->postJson('/mcp', Helpers::toolCall('save_professional_profile', ['profile' => profileDocument(), 'expected_version' => 1]))
        ->assertOk()->getContent());

    expect($body['error']['code'] ?? null)->toBe('conflict');
    $this->assertDatabaseCount('professional_profiles', 0);
    $this->assertDatabaseCount('screening_preferences', 0);
});

it('never backfills structured facts or confirmation from resumes', function () {
    $user = User::factory()->create();
    $portuguese = Resume::factory()->for($user)->create(['language' => 'pt-BR', 'content' => 'Experiência informada apenas no currículo.']);
    $english = Resume::factory()->for($user)->create(['language' => 'en', 'content' => 'Editable English translation.']);
    $originals = [$portuguese->refresh()->getAttributes(), $english->refresh()->getAttributes()];
    $service = app(ProfessionalProfileService::class);

    expect($service->get($user)['draft']['experiences'])->toBe([])
        ->and($service->get($user)['draft']['education'])->toBe([])
        ->and($service->get($user)['draft']['competencies'])->toBe([])
        ->and($service->confirmed($user))->toBeNull();
    $this->assertDatabaseCount('professional_profiles', 0);

    $service->saveDraft($user, profileDocument(), 0);
    expect($service->confirmed($user))->toBeNull();
    $service->confirm($user, 1, ['source' => 'filament']);
    expect($portuguese->refresh()->getAttributes())->toBe($originals[0])
        ->and($english->refresh()->getAttributes())->toBe($originals[1])
        ->and($user->professionalProfile()->sole()->confirmed_actor)->toEqual(['user_id' => $user->id, 'channel' => 'filament']);
});

it('enforces service validation before any write outside MCP', function () {
    $user = User::factory()->create();
    $service = app(ProfessionalProfileService::class);

    expect(fn () => $service->saveDraft($user, profileDocument(['user_id' => 42]), 0))->toThrow(ValidationException::class)
        ->and(fn () => $service->savePreferences($user, [42], [], 0))->toThrow(ValidationException::class)
        ->and(fn () => $service->confirm($user, -1))->toThrow(ValidationException::class)
        ->and(fn () => $service->confirm($user, 0, ['user_id' => 42]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('professional_profiles', 0);
    $this->assertDatabaseCount('screening_preferences', 0);
});

it('denies unauthenticated access to professional profiles', function () {
    $this->postJson('/mcp', Helpers::toolCall('get_professional_profile'))->assertUnauthorized();
    $this->assertDatabaseCount('professional_profiles', 0);
});

it('denies profile mutation with only a read scope', function () {
    $user = User::factory()->create();
    $token = profileToken($user, ['profile:read']);
    foreach (['save_professional_profile' => ['profile' => profileDocument(), 'expected_version' => 0], 'confirm_professional_profile' => ['expected_version' => 0, 'confirm' => true]] as $tool => $arguments) {
        $body = Helpers::decodeToolResult($this->withToken($token)->postJson('/mcp', Helpers::toolCall($tool, $arguments))->assertOk()->getContent());
        expect($body['error']['code'] ?? null)->toBe('forbidden');
    }
    $this->assertDatabaseCount('professional_profiles', 0);
});
