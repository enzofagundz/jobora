<?php

use App\Filament\Pages\AiOperationsPage;
use App\Filament\Pages\DiscoveryRunsPage;
use App\Filament\Pages\ManageAtsUsers;
use App\Filament\Pages\MarketComparisonPage;
use App\Filament\Pages\MarketTrendsPage;
use App\Filament\Resources\JobCaptures\JobCaptureResource;
use App\Filament\Resources\JobPostings\Pages\ListJobPostings;
use App\Filament\Resources\PageCaptures\Pages\ManagePageCaptures;
use App\Models\AiOperation;
use App\Models\DiscoveryRun;
use App\Models\DiscoveryRunEvent;
use App\Models\DiscoverySource;
use App\Models\JobPosting;
use App\Models\OpportunitySource;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationObservation;
use App\Models\PublicationTriageResult;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows only owned discovery executions and preserves history for removed owned sources', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $source = DiscoverySource::factory()->for($owner, 'user')->create(['name' => 'Minha busca']);
    $run = DiscoveryRun::factory()->for($owner, 'user')->for($source, 'source')->create();
    DiscoveryRunEvent::factory()->for($run, 'run')->create(['error' => 'Acesso temporariamente indisponível.']);
    $foreignSource = DiscoverySource::factory()->for($other, 'user')->create(['name' => 'Busca alheia']);
    $foreignRun = DiscoveryRun::factory()->for($other, 'user')->for($foreignSource, 'source')->create();
    $source->delete();

    $page = Livewire::actingAs($owner)->test(DiscoveryRunsPage::class)
        ->assertSuccessful()
        ->assertSee('Minha busca')
        ->assertDontSee('Busca alheia')
        ->assertCanSeeTableRecords([$run])
        ->assertCanNotSeeTableRecords([$foreignRun]);

    $page->call('inspectRun', $run->id)
        ->assertSee('Sucesso')
        ->assertSee('Acesso temporariamente indisponível.');

    expect(fn () => Livewire::actingAs($owner)->test(DiscoveryRunsPage::class)->call('inspectRun', $foreignRun->id))
        ->toThrow(ModelNotFoundException::class);
});

it('inspects owned durable captures, publication results and retention without exposing raw html path', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $capture = PageCapture::factory()->for($owner, 'user')->create([
        'html_path' => 'private/secrets/capture.html',
        'coverage_status' => 'partial',
        'coverage_reason' => 'Navegação interrompida.',
        'blocks_expected' => 8,
        'blocks_captured' => 5,
        'raw_expires_at' => now()->addDays(10),
        'ingest_status' => 'completed',
    ]);
    $publication = Publication::factory()->for($owner, 'user')->create([
        'user_id' => $owner->id, 'source' => 'linkedin', 'external_id' => 'urn:li:activity:77',
        'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:77', 'content' => 'Publicação observada',
        'content_hash' => hash('sha256', 'Publicação observada'), 'content_version' => 1, 'last_observed_at' => now(),
    ]);
    PublicationObservation::factory()->for($capture, 'pageCapture')->for($publication)->create([
        'observed_at' => now(),
        'content' => 'Publicação observada', 'content_hash' => hash('sha256', 'Publicação observada'), 'content_version' => 1,
    ]);
    v2UiOperation($owner, 'extraction-done')->update([
        'target_id' => $capture->id,
        'status' => 'completed',
    ]);
    $foreignCapture = PageCapture::factory()->for($other, 'user')->create(['url' => 'https://private.example/foreign']);

    $page = Livewire::actingAs($owner)->test(ManagePageCaptures::class)
        ->assertSuccessful()
        ->assertSee('5')
        ->assertDontSee('private.example/foreign')
        ->assertCanSeeTableRecords([$capture])
        ->assertCanNotSeeTableRecords([$foreignCapture]);

    $modal = $page->mountTableAction('view', $capture)->getMountedActionModalHtml();
    expect($modal)->toContain('Publicação observada')
        ->and($modal)->toContain('Concluída')
        ->and($modal)->toContain('Não processada nesta versão')
        ->and($modal)->toContain('Navegação interrompida.')
        ->and($modal)->not->toContain('private/secrets/capture.html');

    Livewire::actingAs($owner)->test(ManagePageCaptures::class)
        ->mountTableAction('view', $foreignCapture)
        ->assertActionNotMounted();
});

it('shows only publication decisions and opportunities matching observed content version', function () {
    $owner = User::factory()->create();
    $firstCapture = PageCapture::factory()->for($owner, 'user')->create();
    $secondCapture = PageCapture::factory()->for($owner, 'user')->create();
    $publication = Publication::factory()->for($owner, 'user')->create([
        'user_id' => $owner->id, 'source' => 'linkedin', 'external_id' => 'urn:li:activity:versioned',
        'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:versioned', 'content' => 'Conteúdo versão 2',
        'content_hash' => hash('sha256', 'Conteúdo versão 2'), 'content_version' => 2, 'last_observed_at' => now(),
    ]);
    foreach ([[$firstCapture, 1, 'Conteúdo versão 1'], [$secondCapture, 2, 'Conteúdo versão 2']] as [$capture, $version, $content]) {
        PublicationObservation::factory()->for($capture, 'pageCapture')->for($publication)->create([
            'observed_at' => now(),
            'content' => $content, 'content_hash' => hash('sha256', $content), 'content_version' => $version,
        ]);
    }
    $operation = v2UiOperation($owner, 'triage-version-2');
    $operation->update(['operation_type' => 'publication_triage', 'target_type' => 'publication', 'target_id' => $publication->id]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id, 'content_version' => 2, 'attempt' => 1,
        'status' => 'barred', 'classified_at' => now(),
    ]);
    PublicationTriageResult::query()->create([
        'ai_operation_id' => $operation->id, 'publication_id' => $publication->id, 'content_version' => 2,
        'run_token' => (string) Str::uuid(), 'attempt_token' => (string) Str::uuid(), 'attempt' => 1,
        'status' => 'completed', 'provider' => 'private-provider', 'model' => 'private-model',
        'schema_version' => '1', 'input_fingerprint' => hash('sha256', 'private-input'),
    ]);
    $posting = JobPosting::factory()->for($owner, 'user')->create(['role' => 'Engenheira versão 2']);
    OpportunitySource::factory()->for($posting, 'jobPosting')->for($publication)->create([
        'publication_content_version' => 2, 'is_legacy' => false,
    ]);

    $firstModal = Livewire::actingAs($owner)->test(ManagePageCaptures::class)
        ->mountTableAction('view', $firstCapture)
        ->getMountedActionModalHtml();
    expect($firstModal)->toContain('Não processada nesta versão')
        ->and($firstModal)->not->toContain('Engenheira versão 2')
        ->and($firstModal)->not->toContain('rejected');

    $secondModal = Livewire::actingAs($owner)->test(ManagePageCaptures::class)
        ->mountTableAction('view', $secondCapture)
        ->getMountedActionModalHtml();
    expect($secondModal)->toContain('Engenheira versão 2')
        ->and($secondModal)->toContain('Barrada')
        ->and($secondModal)->toContain('Concluída');
});

it('lists only own safe AI operation metadata and hides private snapshots', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $own = v2UiOperation($owner, 'safe-error-code');
    v2UiOperation($owner, 'profile-target')->update(['target_type' => 'professional_profile', 'target_id' => 1]);
    v2UiOperation($owner, 'opportunity-target')->update(['target_type' => 'opportunity', 'target_id' => 1]);
    $foreign = v2UiOperation($other, 'foreign-private-code');
    $own->update(['input_snapshot' => ['secret' => 'PRIVATE_SNAPSHOT']]);

    Livewire::actingAs($owner)->test(AiOperationsPage::class)
        ->assertSuccessful()
        ->assertSee('Extração de publicação')
        ->assertSee('safe-error-code')
        ->assertSee('Oportunidade')
        ->assertSee('Perfil')
        ->assertDontSee('foreign-private-code')
        ->assertDontSee('PRIVATE_SNAPSHOT')
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('requires admin gate for permission updates and prevents self elevation', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $target = User::factory()->create(['is_admin' => false]);
    $this->actingAs($admin);

    Livewire::test(ManageAtsUsers::class)
        ->assertTableActionVisible('toggleAdmin', $target)
        ->callTableAction('toggleAdmin', $target)
        ->assertSuccessful();

    expect($target->fresh()->is_admin)->toBeTrue();

    Livewire::actingAs($admin)->test(ManageAtsUsers::class)->call('toggleAdmin', $admin->id);
    expect($admin->fresh()->is_admin)->toBeTrue();

    $plain = User::factory()->create();
    $component = Livewire::actingAs($admin)->test(ManageAtsUsers::class);
    auth()->setUser($plain);
    $component->call('toggleAdmin', $target->id);
    expect($target->fresh()->is_admin)->toBeTrue();
});

it('keeps market history pages available by URL and confirms legacy capture history remains in navigation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(MarketComparisonPage::shouldRegisterNavigation())->toBeFalse()
        ->and(MarketTrendsPage::shouldRegisterNavigation())->toBeFalse();

    $this->get(route('filament.admin.pages.market-comparison'))->assertSuccessful();
    $this->get(route('filament.admin.pages.market-trends'))->assertSuccessful();

    expect(JobCaptureResource::shouldRegisterNavigation())->toBeTrue()
        ->and(JobCaptureResource::getNavigationLabel())->toBe('Capturas legadas');
});

it('separates V2 readiness from legacy posting status in the postings list', function () {
    $owner = User::factory()->create();
    $ready = JobPosting::factory()->for($owner, 'user')->create(['readiness' => 'ready', 'status' => 'pending']);
    $legacy = JobPosting::factory()->for($owner, 'user')->confirmed()->create(['readiness' => null]);

    Livewire::actingAs($owner)->test(ListJobPostings::class)
        ->assertSuccessful()
        ->assertSee('Pronta')
        ->assertSee('Fluxo legado')
        ->assertSee('Status legado')
        ->assertDontSee('Arquivada')
        ->assertCanSeeTableRecords([$ready, $legacy]);
});

function v2UiOperation(User $user, string $failureCode): AiOperation
{
    return AiOperation::query()->create([
        'user_id' => $user->id,
        'operation_key' => (string) Str::uuid(),
        'operation_type' => 'publication_extraction',
        'target_type' => 'page_capture',
        'target_id' => 1,
        'run_token' => (string) Str::uuid(),
        'status' => 'failed',
        'attempts' => 2,
        'queued_at' => now(),
        'expires_at' => now()->addHour(),
        'input_snapshot' => ['secret' => $failureCode],
        'input_fingerprint' => hash('sha256', $failureCode),
        'failure_code' => $failureCode,
    ]);
}
