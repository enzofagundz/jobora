<?php

use App\Filament\Pages\ProfessionalProfilePage;
use App\Filament\Pages\ScreeningPreferencesPage;
use App\Models\User;
use App\Services\ProfessionalProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('saves draft and confirms it through the real profile service', function () {
    $page = Livewire::test(ProfessionalProfilePage::class)
        ->fillForm([
            'experiences' => [['company' => 'Acme', 'role' => 'Dev', 'period' => null, 'description' => null]],
            'education' => [],
            'competencies' => [['value' => 'PHP']],
            'preferences' => [],
            'restrictions' => [],
            'compensation_expectation' => null,
        ])
        ->call('saveDraft')
        ->assertSet('version', 1)
        ->call('confirm')
        ->assertSet('confirmedVersion', 2)
        ->assertNotified('Perfil confirmado');

    $profiles = app(ProfessionalProfileService::class);
    expect($profiles->confirmed(auth()->user()))
        ->toMatchArray(['experiences' => [['company' => 'Acme', 'role' => 'Dev', 'period' => null, 'description' => null]]])
        ->and($profiles->get(auth()->user())['confirmed_version'])->toBe(2)
        ->and($page->get('confirmedVersion'))->toBe(2);
});

it('keeps a stale second page form and leaves its loaded version unchanged', function () {
    $firstPage = Livewire::test(ProfessionalProfilePage::class);
    $secondPage = Livewire::test(ProfessionalProfilePage::class)
        ->fillForm(['competencies' => [['value' => 'Laravel']]]);

    $firstPage->fillForm(['competencies' => [['value' => 'PHP']]])->call('saveDraft')->assertSet('version', 1);

    $secondPage->call('saveDraft')
        ->assertSet('version', 0)
        ->assertSet('data.competencies.0.value', 'Laravel')
        ->assertNotified('Perfil alterado em outra sessão');

    expect(app(ProfessionalProfileService::class)->get(auth()->user())['draft']['competencies'])->toBe(['PHP']);
});

it('blocks confirmation with unsaved local edits and preserves them', function () {
    app(ProfessionalProfileService::class)->saveDraft(auth()->user(), [
        'experiences' => [], 'education' => [], 'competencies' => ['PHP'], 'preferences' => [], 'restrictions' => [], 'compensation_expectation' => null,
    ], 0);

    Livewire::test(ProfessionalProfilePage::class)
        ->set('data.competencies.0.value', 'Laravel')
        ->call('confirm')
        ->assertSet('version', 1)
        ->assertSet('data.competencies.0.value', 'Laravel')
        ->assertNotified('Salve o rascunho antes de confirmar');

    expect(app(ProfessionalProfileService::class)->confirmed(auth()->user()))->toBeNull();
});

it('keeps screening preferences and profile preferences on one service-backed source', function () {
    Livewire::test(ScreeningPreferencesPage::class)
        ->fillForm(['preferences' => [['value' => 'remoto']], 'rejections' => [['value' => 'presencial']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(ProfessionalProfileService::class)->get(auth()->user())['draft'])
        ->toMatchArray(['preferences' => ['remoto'], 'restrictions' => ['presencial']]);

    Livewire::test(ProfessionalProfilePage::class)
        ->assertSet('data.preferences', fn (array $values): bool => collect($values)->pluck('value')->all() === ['remoto'])
        ->assertSet('data.restrictions', fn (array $values): bool => collect($values)->pluck('value')->all() === ['presencial']);
});

it('isolates profile data between users', function () {
    app(ProfessionalProfileService::class)->saveDraft(auth()->user(), [
        'experiences' => [], 'education' => [], 'competencies' => ['PHP'], 'preferences' => [], 'restrictions' => [], 'compensation_expectation' => null,
    ], 0);

    $otherUser = User::factory()->create();
    $this->actingAs($otherUser);

    Livewire::test(ProfessionalProfilePage::class)
        ->assertSet('version', 0)
        ->assertSet('data.competencies', []);

    expect(app(ProfessionalProfileService::class)->get($otherUser)['draft']['competencies'])->toBe([]);
});

it('opens an explicit confirmation modal', function () {
    Livewire::test(ProfessionalProfilePage::class)
        ->call('requestConfirmation')
        ->assertSet('showConfirmation', true)
        ->assertSee('Confirmar perfil profissional?')
        ->assertSeeHtml('id="confirm-professional-profile"');
});

it('shows preference validation errors on the field', function () {
    Livewire::test(ScreeningPreferencesPage::class)
        ->fillForm(['preferences' => [['value' => str_repeat('x', 101)]], 'rejections' => []])
        ->call('save')
        ->assertHasErrors(['preferences.0']);
});

it('preserves zero in competency lists when saving the profile', function () {
    Livewire::test(ProfessionalProfilePage::class)
        ->fillForm(['competencies' => [['value' => '0']]])
        ->call('saveDraft')
        ->assertNotified('Rascunho salvo');

    expect(app(ProfessionalProfileService::class)->get(auth()->user())['draft']['competencies'])->toBe(['0']);
});
