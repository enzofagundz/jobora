<?php

use App\Filament\Pages\ScreeningPreferencesPage;
use App\Models\ScreeningPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// As páginas do painel exigem usuário autenticado (policy + middleware do painel).
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('renderiza a página de critérios de triagem', function () {
    Livewire::test(ScreeningPreferencesPage::class)
        ->assertSuccessful()
        ->assertSee('Critérios de triagem');
});

it('persiste preferências e rejeições ao salvar', function () {
    Livewire::test(ScreeningPreferencesPage::class)
        ->fillForm([
            'preferences' => [['value' => 'PHP'], ['value' => 'remoto']],
            'rejections' => [['value' => 'presencial']],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $criteria = ScreeningPreference::sole();

    expect($criteria->user_id)->toBe(auth()->id())
        ->and($criteria->preferences)->toBe(['PHP', 'remoto'])
        ->and($criteria->rejections)->toBe(['presencial']);
});

it('carrega critérios existentes no formulário', function () {
    ScreeningPreference::factory()->create([
        'user_id' => auth()->id(),
        'preferences' => ['PHP', 'remoto'],
        'rejections' => ['presencial'],
    ]);

    Livewire::test(ScreeningPreferencesPage::class)
        ->assertSet('data.preferences', fn (mixed $value): bool => collect($value)->pluck('value')->all() === ['PHP', 'remoto'])
        ->assertSet('data.rejections', fn (mixed $value): bool => collect($value)->pluck('value')->all() === ['presencial']);
});

it('edita preferências e rejeições existentes', function () {
    ScreeningPreference::factory()->create([
        'user_id' => auth()->id(),
        'preferences' => ['PHP'],
        'rejections' => ['presencial'],
    ]);

    Livewire::test(ScreeningPreferencesPage::class)
        ->fillForm([
            'preferences' => [['value' => 'Laravel']],
            'rejections' => [['value' => 'presencial'], ['value' => 'estágio']],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ScreeningPreference::query()->count())->toBe(1)
        ->and(ScreeningPreference::sole()->preferences)->toBe(['Laravel'])
        ->and(ScreeningPreference::sole()->rejections)->toBe(['presencial', 'estágio']);
});

it('remove preferências e rejeições ao salvar listas vazias', function () {
    ScreeningPreference::factory()->create([
        'user_id' => auth()->id(),
        'preferences' => ['PHP', 'Laravel'],
        'rejections' => ['presencial'],
    ]);

    Livewire::test(ScreeningPreferencesPage::class)
        ->fillForm([
            'preferences' => [],
            'rejections' => [],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ScreeningPreference::sole()->preferences)->toBe([])
        ->and(ScreeningPreference::sole()->rejections)->toBe([]);
});
