<?php

use App\Filament\Pages\TelegramIdentityPage;
use App\Mcp\Support\AgentIntegrationContext;
use App\Models\User;
use App\Services\TelegramIdentityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->owner = User::factory()->create(['password' => 'correct-password']);
    $this->client = Helpers::client('Hermes link UI');
    $this->integration = Helpers::integration($this->owner, $this->client, ['approvals:read', 'approvals:write']);
    $this->token = Helpers::accessToken($this->owner, $this->client);
    $this->actingAs($this->owner, 'web');
});

function telegramUiPanel(object $test): void
{
    app()->forgetInstance(AgentIntegrationContext::class);
    app('auth')->forgetGuards();
    $test->actingAs($test->owner, 'web');
}

function telegramUiRemote(object $test, string $tool, array $arguments): array
{
    app('auth')->forgetGuards();

    return Helpers::decodeToolResult($test->withToken($test->token)->postJson('/mcp', Helpers::toolCall($tool, $arguments))->assertOk()->getContent());
}

function telegramUiSubmit(object $test, array $challenge): array
{
    $submitted = telegramUiRemote($test, 'submit_telegram_link', [
        'challenge' => $challenge['challenge'], 'telegram_user_id' => '123456789012345',
        'chat_id' => '123456789012345', 'message_id' => '1', 'chat_type' => 'private',
    ]);
    telegramUiPanel($test);

    return $submitted;
}

function telegramUiLink(object $test): array
{
    telegramUiPanel($test);
    $challenge = app(TelegramIdentityService::class)->startChallenge($test->owner, $test->integration->id);
    telegramUiSubmit($test, $challenge);

    return $challenge;
}

it('starts a one-time challenge for owned active integration with approval scope', function () {
    $component = Livewire::test(TelegramIdentityPage::class)
        ->assertSuccessful()
        ->assertSee('Confiança delegada ao Hermes')
        ->set('integrationId', (string) $this->integration->id)
        ->call('startChallenge')
        ->assertNotified('Desafio temporário criado');

    expect(DB::table('telegram_link_challenges')->where('user_id', $this->owner->id)->count())->toBe(1)
        ->and($component->get('confirmationPassword'))->toBe('')
        ->and($component->get('integrationId'))->toBe((string) $this->integration->id);
    $page = Livewire::test(TelegramIdentityPage::class)->assertSee('10 minutos');
    expect($page->get('challenge'))->toBeNull();
});

it('shows submitted identity without secret and requires real password confirmation', function () {
    $challenge = telegramUiLink($this);
    $this->assertDatabaseCount('telegram_identity_bindings', 0);

    $page = Livewire::test(TelegramIdentityPage::class)
        ->assertSee('123456789012345')
        ->assertSee('Aguardando confirmação humana')
        ->assertDontSee($challenge['challenge'])
        ->set('confirmationChallengeId', $challenge['challenge_id'])
        ->set('confirmationPassword', 'wrong-password')
        ->call('confirmChallenge')
        ->assertNotified('Senha incorreta')
        ->assertSet('confirmationPassword', '');
    $this->assertDatabaseCount('telegram_identity_bindings', 0);

    $page->set('confirmationChallengeId', $challenge['challenge_id'])
        ->set('confirmationPassword', 'correct-password')
        ->call('confirmChallenge')
        ->assertNotified('Vínculo confirmado')
        ->assertSee('Vínculo Telegram atual')
        ->assertSee('ID Telegram');
    expect(app(TelegramIdentityService::class)->currentBinding($this->owner)['status'])->toBe('active');
    $reloaded = Livewire::test(TelegramIdentityPage::class)
        ->assertSee('Vínculo Telegram atual')
        ->assertSee('123456789012345')
        ->assertDontSee($challenge['challenge']);
    expect($reloaded->get('confirmationChallengeId'))->toBeNull()
        ->and($reloaded->get('confirmationPassword'))->toBe('');
});

it('rejects forged integration and foreign challenge identifiers', function () {
    $other = User::factory()->create(['password' => 'other-password']);
    $otherClient = Helpers::client('Other Hermes');
    $otherIntegration = Helpers::integration($other, $otherClient, ['approvals:write']);
    $this->actingAs($other, 'web');
    $otherChallenge = app(TelegramIdentityService::class)->startChallenge($other, $otherIntegration->id);
    $this->actingAs($this->owner, 'web');

    Livewire::test(TelegramIdentityPage::class)
        ->set('integrationId', (string) $otherIntegration->id)
        ->call('startChallenge')
        ->assertNotified('Integração indisponível');
    $this->assertDatabaseCount('telegram_link_challenges', 1);

    Livewire::test(TelegramIdentityPage::class)
        ->set('confirmationChallengeId', $otherChallenge['challenge_id'])
        ->set('confirmationPassword', 'correct-password')
        ->call('confirmChallenge')
        ->assertNotified('Desafio indisponível')
        ->assertSet('confirmationPassword', '');
    $this->assertDatabaseCount('telegram_identity_bindings', 0);
});

it('rejects expired submitted challenge and refresh hides it', function () {
    $challenge = telegramUiLink($this);
    $page = Livewire::test(TelegramIdentityPage::class)
        ->set('confirmationChallengeId', $challenge['challenge_id'])
        ->set('confirmationPassword', 'correct-password');
    $this->travel(11)->minutes();

    $page->call('confirmChallenge')
        ->assertNotified('Desafio não pode ser confirmado')
        ->assertSet('confirmationPassword', '')
        ->assertDontSee('123456789012345');
    $this->assertDatabaseCount('telegram_identity_bindings', 0);
});

it('revokes binding explicitly and does not silently replace it', function () {
    $challenge = telegramUiLink($this);
    app(TelegramIdentityService::class)->confirmChallenge($this->owner, $challenge['challenge_id'], 'correct-password');

    Livewire::test(TelegramIdentityPage::class)
        ->assertSee('Vínculo Telegram atual')
        ->call('revokeBinding')
        ->assertNotified('Vínculo revogado')
        ->assertSee('Aprovações antigas não serão reativadas')
        ->assertSee('Criar vínculo');
    expect(app(TelegramIdentityService::class)->currentBinding($this->owner))->toBeNull();
    expect(fn () => app(TelegramIdentityService::class)->revokeBinding($this->owner, '99999999'))
        ->toThrow(ModelNotFoundException::class);
});
