<?php

use App\Enums\AgentIntegrationScope;
use App\Filament\Resources\AgentIntegrations\Pages\ListAgentIntegrations;
use App\Models\AgentIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('lists only the integrations from the authenticated account', function () {
    $mine = AgentIntegration::factory()->for($this->user)->create(['name' => 'Hermes do candidato']);
    $foreign = AgentIntegration::factory()->create(['name' => 'Hermes de outra conta']);

    Livewire::test(ListAgentIntegrations::class)
        ->assertSee('Hermes do candidato')
        ->assertDontSee('Hermes de outra conta')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('shows every capability of an integration in the view modal without exposing the access token', function () {
    $client = Helpers::client();
    $integration = AgentIntegration::factory()->for($this->user)->create([
        'oauth_client_id' => (string) $client->id,
        'name' => 'Hermes do candidato',
    ]);
    $token = Helpers::accessToken($this->user, $client);

    $modalHtml = Livewire::test(ListAgentIntegrations::class)
        ->mountTableAction('view', $integration)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    foreach (AgentIntegrationScope::labels() as $label) {
        expect($modalHtml)->toContain(e($label));
    }

    expect($modalHtml)->not->toContain($token);
});

it('revokes only the selected integration through the service', function () {
    $integration = AgentIntegration::factory()->for($this->user)->create(['name' => 'Hermes revogável']);
    $other = AgentIntegration::factory()->for($this->user)->create(['name' => 'Hermes preservada']);

    Livewire::test(ListAgentIntegrations::class)
        ->callTableAction('revoke', $integration)
        ->assertNotified('Integração revogada');

    expect($integration->refresh()->revoked_at)->not->toBeNull()
        ->and($integration->isRevoked())->toBeTrue()
        ->and($other->refresh()->revoked_at)->toBeNull();
});

it('shows the revoked state and hides the revoke action for a revoked integration', function () {
    $integration = AgentIntegration::factory()->for($this->user)->revoked()->create(['name' => 'Hermes antiga']);

    Livewire::test(ListAgentIntegrations::class)
        ->assertSee('Hermes antiga')
        ->assertSee('Revogada')
        ->assertTableActionHidden('revoke', $integration);
});
