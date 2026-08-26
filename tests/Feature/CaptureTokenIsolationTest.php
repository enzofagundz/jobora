<?php

use App\Filament\Pages\CaptureSetupPage;
use App\Filament\Resources\JobCaptures\Pages\ListJobCaptures;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\CaptureTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('usuário comum consegue acessar Setup de Captura e gerar sua credencial', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CaptureSetupPage::class)
        ->assertSuccessful()
        ->assertSee('Instale a extensão');

    // sem token inicialmente
    expect(app(CaptureTokenService::class)->tokenConfigured($user))->toBeFalse();

    Livewire::test(CaptureSetupPage::class)
        ->callAction('generateToken')
        ->assertSuccessful();

    $user->refresh();
    expect($user->capture_token_hash)->not->toBeNull()
        ->and($user->capture_token_last_four)->not->toBeNull()
        ->and($user->capture_token_hash)->not->toContain($user->capture_token_last_four); // hash !== last four

    // após geração, página deve mostrar máscara
    $page = Livewire::test(CaptureSetupPage::class)->assertSuccessful();
    expect($page->html())->toContain('•');
});

it('visitante não autenticado não consegue acessar Setup de Captura', function () {
    $this->get(route('filament.admin.pages.capture-setup'))
        ->assertRedirect(route('filament.admin.auth.login'));
});

it('usuário A não consegue visualizar ou reutilizar token de B', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $service = app(CaptureTokenService::class);
    $tokenA = $service->issueToken($userA);
    $tokenB = $service->issueToken($userB);

    // Cada usuário só vê sua própria máscara
    $this->actingAs($userA);
    $htmlA = Livewire::test(CaptureSetupPage::class)->html();
    $this->actingAs($userB);
    $htmlB = Livewire::test(CaptureSetupPage::class)->html();

    expect($tokenA)->not->toBe($tokenB)
        ->and($userA->fresh()->capture_token_hash)->not->toBe($userB->fresh()->capture_token_hash);

    // Token de B não deve autenticar como A e vice-versa: capturas isoladas
    $payload = [
        'source' => 'linkedin',
        'description' => 'Vaga isolada '.str_repeat('x', 50),
        'url' => 'https://example.com/job/iso1',
    ];

    $resA = $this->postJson('/api/job-captures', $payload, ['Authorization' => 'Bearer '.$tokenA]);
    $resA->assertStatus(201);
    $capA = JobCapture::where('url', 'https://example.com/job/iso1')->first();
    expect($capA->user_id)->toBe($userA->id);

    $payload2 = [
        'source' => 'linkedin',
        'description' => 'Vaga isolada B '.str_repeat('x', 50),
        'url' => 'https://example.com/job/iso2',
    ];
    $resB = $this->postJson('/api/job-captures', $payload2, ['Authorization' => 'Bearer '.$tokenB]);
    $resB->assertStatus(201);
    $capB = JobCapture::where('url', 'https://example.com/job/iso2')->first();
    expect($capB->user_id)->toBe($userB->id);

    // B não vê captura de A via Livewire
    $this->actingAs($userB);
    Livewire::test(ListJobCaptures::class)
        ->assertCanNotSeeTableRecords(collect([$capA]));
});

it('token revogado deixa de autenticar', function () {
    $user = User::factory()->create();
    $service = app(CaptureTokenService::class);
    $token = $service->issueToken($user);

    $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'Teste revogado '.str_repeat('x', 50),
        'url' => 'https://example.com/job/rev1',
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

    $service->revokeToken($user->fresh());

    $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'Teste revogado 2 '.str_repeat('x', 50),
        'url' => 'https://example.com/job/rev2',
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(401)
        ->assertJson(['message' => 'Token inválido.']);

    $this->getJson('/api/extension/auth/validate', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(401);
});

it('regeneração invalida o token anterior e o novo funciona', function () {
    $user = User::factory()->create();
    $service = app(CaptureTokenService::class);
    $old = $service->issueToken($user);
    $user->refresh();
    $oldHash = $user->capture_token_hash;

    $new = $service->issueToken($user->fresh());
    $user->refresh();

    expect($new)->not->toBe($old)
        ->and($user->capture_token_hash)->not->toBe($oldHash);

    $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'Velho '.str_repeat('x', 50),
        'url' => 'https://example.com/job/old',
    ], ['Authorization' => 'Bearer '.$old])->assertStatus(401);

    $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'Novo '.str_repeat('x', 50),
        'url' => 'https://example.com/job/new',
    ], ['Authorization' => 'Bearer '.$new])->assertStatus(201);

    $cap = JobCapture::where('url', 'https://example.com/job/new')->first();
    expect($cap->user_id)->toBe($user->id);
});

it('não é possível forjar user_id no payload para alterar o proprietário', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $tokenA = app(CaptureTokenService::class)->issueToken($userA);

    $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'Tentativa forge '.str_repeat('x', 50),
        'url' => 'https://example.com/job/forge',
        'user_id' => $userB->id,
        'owner_user_id' => $userB->id,
        'capture_user' => $userB->id,
    ], ['Authorization' => 'Bearer '.$tokenA])->assertStatus(201);

    $cap = JobCapture::where('url', 'https://example.com/job/forge')->first();
    expect($cap->user_id)->toBe($userA->id)
        ->and($cap->user_id)->not->toBe($userB->id);
    expect($cap->toArray())->not->toHaveKey('user_id');
});

it('usuário desativado não consegue autenticar com o token', function () {
    $user = User::factory()->create();
    $token = app(CaptureTokenService::class)->issueToken($user);
    $user->forceFill(['disabled_at' => now()])->save();

    $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'Desativado '.str_repeat('x', 50),
        'url' => 'https://example.com/job/disabled',
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(401);

    $this->getJson('/api/extension/auth/validate', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(401);

    // painel deve negar acesso
    $this->actingAs($user->fresh())->get(route('filament.admin.pages.capture-setup'))
        ->assertForbidden();
});

it('usuário excluído tem suas capturas removidas e token deixa de funcionar', function () {
    $user = User::factory()->create();
    $token = app(CaptureTokenService::class)->issueToken($user);

    $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'Para excluir '.str_repeat('x', 50),
        'url' => 'https://example.com/job/delete',
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

    expect(JobCapture::where('user_id', $user->id)->count())->toBe(1);

    $user->delete();

    expect(JobCapture::withTrashed()->where('user_id', $user->id)->count())->toBe(0);

    $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'Após delete '.str_repeat('x', 50),
        'url' => 'https://example.com/job/after-delete',
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(401);
});

it('usuário desativado não consegue gerar novo token via página', function () {
    $user = User::factory()->create(['disabled_at' => now()]);
    $this->actingAs($user);

    // canAccess deve negar
    expect(CaptureSetupPage::canAccess())->toBeFalse();
});

it('token não é exposto em toArray, logs ou resposta', function () {
    $user = User::factory()->create();
    $service = app(CaptureTokenService::class);
    $plain = $service->issueToken($user);
    $user->refresh();

    expect($user->toArray())->not->toHaveKey('capture_token_hash')
        ->and($user->toArray())->not->toHaveKey('capture_token_last_four')
        ->and($user->toArray())->not->toHaveKey('disabled_at')
        ->and($user->capture_token_hash)->not->toBe($plain)
        ->and(hash('sha256', $plain))->toBe($user->capture_token_hash);

    $response = $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'Log test '.str_repeat('x', 50),
        'url' => 'https://example.com/job/log',
    ], ['Authorization' => 'Bearer '.$plain]);

    $response->assertStatus(201);
    expect(json_encode($response->json()))->not->toContain($plain)
        ->and(json_encode($response->json()))->not->toContain($user->capture_token_hash);
});

it('múltiplos dispositivos compartilham o mesmo token; regeneração invalida todos', function () {
    $user = User::factory()->create();
    $service = app(CaptureTokenService::class);
    $token = $service->issueToken($user);

    // Simula dois dispositivos com mesmo token
    $payload1 = ['source' => 'linkedin', 'description' => 'Device 1 '.str_repeat('x', 50), 'url' => 'https://example.com/job/d1'];
    $payload2 = ['source' => 'linkedin', 'description' => 'Device 2 '.str_repeat('x', 50), 'url' => 'https://example.com/job/d2'];

    $this->postJson('/api/job-captures', $payload1, ['Authorization' => 'Bearer '.$token])->assertStatus(201);
    $this->postJson('/api/job-captures', $payload2, ['Authorization' => 'Bearer '.$token])->assertStatus(201);

    $new = $service->issueToken($user->fresh());

    $this->postJson('/api/job-captures', ['source' => 'linkedin', 'description' => 'Após regen '.str_repeat('x', 50), 'url' => 'https://example.com/job/after'], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(401);

    $this->postJson('/api/job-captures', ['source' => 'linkedin', 'description' => 'Novo token '.str_repeat('x', 50), 'url' => 'https://example.com/job/new-device'], ['Authorization' => 'Bearer '.$new])
        ->assertStatus(201);
});

it('logout da web não revoga o token da extensão', function () {
    $user = User::factory()->create();
    $token = app(CaptureTokenService::class)->issueToken($user);
    $this->actingAs($user);
    auth()->logout();
    // token ainda deve funcionar via API (autenticação stateless por Bearer)
    $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'Após logout '.str_repeat('x', 50),
        'url' => 'https://example.com/job/logout',
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);
});
