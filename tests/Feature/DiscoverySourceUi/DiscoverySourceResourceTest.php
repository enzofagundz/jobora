<?php

use App\Enums\DiscoverySourceSurface;
use App\Filament\Resources\DiscoverySources\DiscoverySourceResource;
use App\Filament\Resources\DiscoverySources\Pages\CreateDiscoverySource;
use App\Filament\Resources\DiscoverySources\Pages\EditDiscoverySource;
use App\Filament\Resources\DiscoverySources\Pages\ListDiscoverySources;
use App\Models\DiscoverySource;
use App\Models\User;
use App\Services\Discovery\DiscoverySourceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('lists only the current user sources', function () {
    $mine = DiscoverySource::factory()->for($this->user, 'user')->create(['name' => 'Busca própria']);
    $foreign = DiscoverySource::factory()->create(['name' => 'Busca alheia']);

    Livewire::test(ListDiscoverySources::class)
        ->assertSee('Busca própria')
        ->assertDontSee('Busca alheia')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('filters sources by platform surface purpose frequency and state', function () {
    $linkedin = DiscoverySource::factory()->for($this->user, 'user')->create([
        'name' => 'LinkedIn Laravel',
        'source' => 'linkedin',
        'surface' => DiscoverySourceSurface::LinkedInJobs->value,
        'purpose' => 'Backend Laravel',
        'frequency_minutes' => 30,
        'active' => true,
    ]);
    $indeed = DiscoverySource::factory()->for($this->user, 'user')->create([
        'name' => 'Indeed PHP',
        'source' => 'indeed',
        'surface' => DiscoverySourceSurface::IndeedJobs->value,
        'purpose' => 'Backend PHP',
        'frequency_minutes' => 180,
        'active' => false,
    ]);

    Livewire::test(ListDiscoverySources::class)
        ->filterTable('source', 'linkedin')
        ->assertCanSeeTableRecords([$linkedin])
        ->assertCanNotSeeTableRecords([$indeed])
        ->filterTable('source', null)
        ->filterTable('surface', DiscoverySourceSurface::IndeedJobs->value)
        ->assertCanSeeTableRecords([$indeed])
        ->assertCanNotSeeTableRecords([$linkedin])
        ->filterTable('surface', null)
        ->filterTable('purpose', ['value' => 'Laravel'])
        ->assertCanSeeTableRecords([$linkedin])
        ->assertCanNotSeeTableRecords([$indeed])
        ->filterTable('purpose', ['value' => null])
        ->filterTable('state', 'inactive')
        ->assertCanSeeTableRecords([$indeed])
        ->assertCanNotSeeTableRecords([$linkedin])
        ->filterTable('state', null)
        ->filterTable('frequency', ['min' => 60, 'max' => 240])
        ->assertCanSeeTableRecords([$indeed])
        ->assertCanNotSeeTableRecords([$linkedin]);
});

it('creates a source from criteria through the domain service', function () {
    Livewire::test(CreateDiscoverySource::class)
        ->assertSuccessful()
        ->fillForm([
            'name' => 'LinkedIn Laravel',
            'source' => 'linkedin',
            'surface' => DiscoverySourceSurface::LinkedInJobs->value,
            'purpose' => 'Backend Laravel',
            'frequency_minutes' => 30,
            'search_mode' => 'criteria',
            'criteria' => [
                'keywords' => ['Laravel'],
                'work_mode' => 'remote',
                'published_within_days' => 7,
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $source = DiscoverySource::query()->sole();

    expect($source->user_id)->toBe($this->user->id)
        ->and($source->version)->toBe(1)
        ->and($source->active)->toBeTrue()
        ->and($source->criteria['published_within_days'])->toBe(7)
        ->and($source->url)->toContain('f_TPR=r604800')
        ->and($source->url)->toContain('f_WT=2');
});

it('creates a source from a raw url through the domain service', function () {
    Livewire::test(CreateDiscoverySource::class)
        ->fillForm([
            'name' => 'Indeed PHP',
            'source' => 'indeed',
            'surface' => DiscoverySourceSurface::IndeedJobs->value,
            'frequency_minutes' => 60,
            'search_mode' => 'url',
            'url' => 'https://br.indeed.com/jobs?q=PHP&fromage=3',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $source = DiscoverySource::query()->sole();

    expect($source->url)->toContain('fromage=3')
        ->and($source->criteria)->toBeNull();
});

it('updates a source through the service and increments its version', function () {
    $source = DiscoverySource::factory()->for($this->user, 'user')->create(['name' => 'Antiga']);

    Livewire::test(EditDiscoverySource::class, ['record' => $source->id])
        ->fillForm(['name' => 'Nova'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($source->refresh()->name)->toBe('Nova')
        ->and($source->version)->toBe(2);
});

it('edits a criteria source without rejecting the persisted operator', function () {
    $source = DiscoverySource::factory()->for($this->user, 'user')->create([
        'criteria' => [
            'keywords' => ['Laravel'],
            'excluded_terms' => [],
            'operator' => 'and',
            'seniority' => null,
            'location' => null,
            'work_mode' => 'any',
            'published_within_days' => 7,
            'easy_apply' => false,
        ],
    ]);

    Livewire::test(EditDiscoverySource::class, ['record' => $source->id])
        ->fillForm(['name' => 'Critérios atualizados'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($source->refresh()->name)->toBe('Critérios atualizados')
        ->and($source->criteria['operator'])->toBe('and')
        ->and($source->version)->toBe(2);
});

it('reports a friendly conflict without overwriting a concurrent edit', function () {
    $source = DiscoverySource::factory()->for($this->user, 'user')->create(['name' => 'Original']);

    $component = Livewire::test(EditDiscoverySource::class, ['record' => $source->id]);

    app(DiscoverySourceService::class)->update($this->user, $source->id, 1, ['name' => 'Alterada em outra sessão']);

    $component
        ->fillForm(['name' => 'Tentativa antiga'])
        ->call('save')
        ->assertNotified('Fonte alterada em outra sessão');

    expect($source->refresh()->name)->toBe('Alterada em outra sessão')
        ->and($source->version)->toBe(2);
});

it('deactivates a source through the service without deleting it', function () {
    $source = DiscoverySource::factory()->for($this->user, 'user')->create(['active' => true]);

    Livewire::test(ListDiscoverySources::class)
        ->callTableAction('deactivate', $source)
        ->assertNotified('Fonte desativada');

    expect($source->refresh()->active)->toBeFalse()
        ->and($source->next_due_at)->toBeNull()
        ->and($source->version)->toBe(2)
        ->and(DiscoverySource::query()->whereKey($source->id)->exists())->toBeTrue();
});

it('removes a source with a soft delete through the service', function () {
    $source = DiscoverySource::factory()->for($this->user, 'user')->create();

    Livewire::test(ListDiscoverySources::class)
        ->callTableAction('delete', $source)
        ->assertNotified('Fonte removida');

    expect(DiscoverySource::query()->whereKey($source->id)->exists())->toBeFalse()
        ->and(DiscoverySource::withTrashed()->whereKey($source->id)->exists())->toBeTrue()
        ->and(DiscoverySource::withTrashed()->find($source->id)->trashed())->toBeTrue();
});

it('does not expose another user source in the list or edit route', function () {
    $foreign = DiscoverySource::factory()->create();

    Livewire::test(ListDiscoverySources::class)
        ->assertCanNotSeeTableRecords([$foreign]);

    $this->get(DiscoverySourceResource::getUrl('edit', ['record' => $foreign]))
        ->assertNotFound();

    expect(fn () => app(DiscoverySourceService::class)->update($this->user, $foreign->id, $foreign->version, ['name' => 'Hack']))
        ->toThrow(ModelNotFoundException::class);
});
