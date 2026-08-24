<?php

use App\Filament\Pages\JobSearchPage;
use App\Models\ScreeningPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('renderiza a página de busca para usuário autenticado', function () {
    Livewire::test(JobSearchPage::class)
        ->assertSuccessful()
        ->assertSee('Busca de vagas');
});

it('renderiza sugestões sem preencher o estado da busca', function () {
    Livewire::test(JobSearchPage::class)
        ->assertSet('data.keywords', fn (mixed $value): bool => collect($value)->pluck('value')->all() === ['vaga'])
        ->assertSet('data.excluded_terms', [])
        ->assertSeeInOrder(['Palavras-chave', 'Sugestões', 'Filtros'])
        ->assertSee('Preferências')
        ->assertSee('Rejeições')
        ->assertSee('vaga')
        ->assertSee('oportunidade')
        ->assertSee('PJ')
        ->assertSee('CLT');

    $html = Livewire::test(JobSearchPage::class)->html();

    expect($html)->toContain('aria-pressed="true"')
        ->and($html)->toContain('suggestion-fixed_keywords-0')
        ->and($html)->toContain('suggestion-fixed_keywords-1')
        ->and($html)->toContain('suggestion-fixed_keywords-2')
        ->and($html)->toContain('suggestion-fixed_keywords-3')
        ->and(ScreeningPreference::count())->toBe(0);
});

it('não duplica preferência persistida igual a termo fixo', function () {
    ScreeningPreference::factory()->create([
        'user_id' => auth()->id(),
        'preferences' => ['PHP', 'Laravel', 'Vaga'],
        'rejections' => ['presencial'],
    ]);

    $page = Livewire::test(JobSearchPage::class);

    expect(collect($page->instance()->suggestedPreferences)->map(fn ($t) => mb_strtolower($t))->all())
        ->each->not->toBe('vaga');

    $html = $page->html();

    expect(substr_count($html, 'suggestion-fixed_keywords-'))->toBe(4)
        ->and(substr_count($html, 'suggestion-keywords-'))->toBe(2);

    $page->assertSee('PHP')
        ->assertSee('Laravel')
        ->assertSee('presencial');

    expect(ScreeningPreference::sole()->preferences)->toBe(['PHP', 'Laravel', 'Vaga']);
});

it('gera preview e link com a URL exata do LinkedIn', function () {
    Livewire::test(JobSearchPage::class)
        ->fillForm([
            'keywords' => [['value' => 'PHP'], ['value' => 'Laravel']],
            'excluded_terms' => [['value' => 'estágio']],
            'seniority' => 'mid_level',
            'location' => 'Brasil',
            'work_mode' => 'remote',
            'published_within_days' => '7',
            'easy_apply' => true,
            'search_mode' => 'jobs',
        ])
        ->call('generate')
        ->assertSet(
            'generatedUrl',
            'https://www.linkedin.com/jobs/search/?keywords=%22PHP%22%20AND%20%22Laravel%22%20NOT%20%22est%C3%A1gio%22&location=Brasil&f_TPR=r604800&f_WT=2&f_E=4&f_AL=true',
        )
        ->assertSee('Sua busca')
        ->assertSee('LinkedIn')
        ->assertSee('Abrir no LinkedIn');
});

it('gera URL do Indeed com termos, localização, remoto e janela', function () {
    Livewire::test(JobSearchPage::class)
        ->fillForm([
            'keywords' => [['value' => 'PHP']],
            'platform' => 'indeed',
            'location' => 'São Paulo',
            'work_mode' => 'remote',
            'published_within_days' => '7',
        ])
        ->call('generate')
        ->assertSet('generatedUrl', 'https://br.indeed.com/jobs?q=%22PHP%22%20%22remoto%22&l=S%C3%A3o%20Paulo&fromage=7&sc=0kf%3Aattr%28DSGMC%29%3B')
        ->assertSee('Abrir no Indeed');
});

it('exibe erro de formulário quando falta palavra-chave', function () {
    Livewire::test(JobSearchPage::class)
        ->fillForm(['keywords' => []])
        ->call('generate')
        ->assertHasFormErrors(['keywords']);
});

it('não persiste alterações transitórias nos critérios de triagem', function () {
    ScreeningPreference::factory()->create([
        'user_id' => auth()->id(),
        'preferences' => ['PHP'],
        'rejections' => [],
    ]);

    Livewire::test(JobSearchPage::class)
        ->fillForm(['keywords' => [['value' => 'Vue'], ['value' => 'React']]])
        ->call('applySuggestion', target: 'keywords', index: 0)
        ->call('applySuggestion', target: 'excluded_terms', index: 0)
        ->call('removeSuggestion', target: 'excluded_terms', index: 0)
        ->call('applySuggestion', target: 'fixed_keywords', index: 2)
        ->call('removeSuggestion', target: 'fixed_keywords', index: 2)
        ->call('generate');

    $criteria = ScreeningPreference::sole();

    expect($criteria->preferences)->toBe(['PHP'])
        ->and($criteria->rejections)->toBe([]);
});

it('cada usuário carrega apenas os próprios critérios', function () {
    $other = User::factory()->create();
    ScreeningPreference::factory()->create([
        'user_id' => $other->id,
        'preferences' => ['enfermagem'],
        'rejections' => ['plantão'],
    ]);

    $html = Livewire::test(JobSearchPage::class)->html();

    expect($html)->not->toContain('enfermagem')
        ->and($html)->not->toContain('plantão')
        ->and(Livewire::test(JobSearchPage::class)
            ->call('applySuggestion', target: 'fixed_keywords', index: 1)
            ->get('data.keywords'))->toBe([['value' => 'vaga'], ['value' => 'oportunidade']]);
});

it('impede acesso de visitante não autenticado', function () {
    auth()->logout();

    $this->get(JobSearchPage::getUrl())->assertRedirect();
});

it('gera URL de publicações do LinkedIn com operador booleano', function (string $operator, string $glue) {
    Livewire::test(JobSearchPage::class)
        ->fillForm([
            'keywords' => [['value' => 'PHP'], ['value' => 'Laravel']],
            'excluded_terms' => [['value' => 'WordPress']],
            'operator' => $operator,
            'published_within_days' => '7',
        ])
        ->call('generate')
        ->assertSet(
            'generatedUrl',
            'https://www.linkedin.com/search/results/content/?keywords=%22PHP%22'.$glue.'%22Laravel%22%20NOT%20%22WordPress%22&origin=FACETED_SEARCH&sortBy=%5B%22date_posted%22%5D&datePosted=%5B%22past-week%22%5D',
        )
        ->assertSee('Sua busca')
        ->assertSee('Abrir no LinkedIn');
})->with([
    'todas as palavras' => ['and', '%20AND%20'],
    'qualquer palavra' => ['or', '%20OR%20'],
]);

it('mantém o modo de busca visível apenas para o LinkedIn', function () {
    Livewire::test(JobSearchPage::class)
        ->assertFormFieldVisible('search_mode')
        ->fillForm(['platform' => 'indeed'])
        ->assertFormFieldHidden('search_mode')
        ->assertSet('data.search_mode', 'jobs');
});

it('ajusta campos exclusivos e texto de ajuda conforme o modo', function () {
    Livewire::test(JobSearchPage::class)
        ->assertFormFieldHidden('seniority')
        ->assertFormFieldHidden('work_mode')
        ->assertFormFieldHidden('location')
        ->assertFormFieldHidden('easy_apply')
        ->assertSee('Pesquise publicações do LinkedIn onde recrutadores e empresas divulgam vagas.')
        ->fillForm(['search_mode' => 'jobs'])
        ->assertFormFieldVisible('seniority')
        ->assertFormFieldVisible('work_mode')
        ->assertFormFieldVisible('location')
        ->assertFormFieldVisible('easy_apply')
        ->assertSee('Pesquise vagas diretamente na aba de vagas do LinkedIn.')
        ->assertDontSee('Pesquise publicações do LinkedIn onde recrutadores e empresas divulgam vagas.');
});

it('limpa filtros exclusivos de vagas ao voltar para publicações', function () {
    Livewire::test(JobSearchPage::class)
        ->fillForm(['search_mode' => 'jobs'])
        ->fillForm([
            'seniority' => 'mid_level',
            'work_mode' => 'remote',
            'location' => 'Brasil',
            'easy_apply' => true,
            'published_within_days' => '14',
        ])
        ->fillForm(['search_mode' => 'publications'])
        ->assertSet('data.seniority', null)
        ->assertSet('data.work_mode', 'any')
        ->assertSet('data.location', null)
        ->assertSet('data.easy_apply', false)
        ->assertSet('data.published_within_days', null);
});

it('aplica e remove preferência e rejeição com estado visual', function () {
    ScreeningPreference::factory()->create([
        'user_id' => auth()->id(),
        'preferences' => ['PHP', 'Laravel'],
        'rejections' => ['presencial'],
    ]);

    $page = Livewire::test(JobSearchPage::class);

    $page->call('removeSuggestion', target: 'fixed_keywords', index: 0)
        ->assertSet('data.keywords', [])
        ->call('applySuggestion', target: 'keywords', index: 0)
        ->assertSet('data.keywords', [['value' => 'PHP']])
        ->assertSet('data.excluded_terms', [])
        ->call('applySuggestion', target: 'excluded_terms', index: 0)
        ->assertSet('data.excluded_terms', [['value' => 'presencial']]);

    expect($page->html())->toContain('aria-pressed="true"')
        ->and($page->html())->toContain('fi-badge-delete-btn')
        ->and($page->html())->toContain('Remover preferência PHP');

    $page->call('removeSuggestion', target: 'keywords', index: 0)
        ->assertSet('data.keywords', []);

    $fresh = Livewire::test(JobSearchPage::class);

    expect($fresh->instance()->isSuggestionActive('fixed_keywords', 0))->toBeTrue()
        ->and($fresh->call('applySuggestion', target: 'fixed_keywords', index: 1)->get('data.keywords'))
        ->toBe([['value' => 'vaga'], ['value' => 'oportunidade']])
        ->and(ScreeningPreference::count())->toBe(1);
});

it('não duplica termo digitado manualmente ao aplicar sugestão', function () {
    ScreeningPreference::factory()->create([
        'user_id' => auth()->id(),
        'preferences' => ['Laravel'],
        'rejections' => ['estágio'],
    ]);

    Livewire::test(JobSearchPage::class)
        ->fillForm([
            'keywords' => [['value' => 'laravel']],
            'excluded_terms' => [['value' => 'Estágio']],
        ])
        ->call('applySuggestion', target: 'keywords', index: 0)
        ->call('applySuggestion', target: 'excluded_terms', index: 0)
        ->assertSet('data.keywords', fn (mixed $value): bool => collect($value)->pluck('value')->all() === ['laravel'])
        ->assertSet('data.excluded_terms', fn (mixed $value): bool => collect($value)->pluck('value')->all() === ['Estágio']);
});

it('mantém termo manual ao aplicar sugestão diferente', function () {
    ScreeningPreference::factory()->create([
        'user_id' => auth()->id(),
        'preferences' => ['PHP'],
        'rejections' => [],
    ]);

    Livewire::test(JobSearchPage::class)
        ->fillForm(['keywords' => [['value' => 'Vue']]])
        ->call('applySuggestion', target: 'keywords', index: 0)
        ->assertSet('data.keywords', fn (mixed $value): bool => collect($value)->pluck('value')->all() === ['Vue', 'PHP']);
});

it('gera URL do LinkedIn a partir de sugestões aplicadas', function () {
    ScreeningPreference::factory()->create([
        'user_id' => auth()->id(),
        'preferences' => ['PHP'],
        'rejections' => ['estágio'],
    ]);

    Livewire::test(JobSearchPage::class)
        ->call('removeSuggestion', target: 'fixed_keywords', index: 0)
        ->call('applySuggestion', target: 'keywords', index: 0)
        ->call('applySuggestion', target: 'excluded_terms', index: 0)
        ->fillForm([
            'seniority' => 'mid_level',
            'location' => 'Brasil',
            'work_mode' => 'remote',
            'published_within_days' => '7',
            'easy_apply' => true,
            'search_mode' => 'jobs',
        ])
        ->call('generate')
        ->assertSet(
            'generatedUrl',
            'https://www.linkedin.com/jobs/search/?keywords=%22PHP%22%20NOT%20%22est%C3%A1gio%22&location=Brasil&f_TPR=r604800&f_WT=2&f_E=4&f_AL=true',
        );
});

it('gera URL do Indeed a partir de sugestões aplicadas', function () {
    ScreeningPreference::factory()->create([
        'user_id' => auth()->id(),
        'preferences' => ['PHP'],
        'rejections' => [],
    ]);

    Livewire::test(JobSearchPage::class)
        ->call('removeSuggestion', target: 'fixed_keywords', index: 0)
        ->call('applySuggestion', target: 'keywords', index: 0)
        ->fillForm([
            'platform' => 'indeed',
            'location' => 'São Paulo',
            'work_mode' => 'remote',
            'published_within_days' => '7',
        ])
        ->call('generate')
        ->assertSet(
            'generatedUrl',
            'https://br.indeed.com/jobs?q=%22PHP%22%20%22remoto%22&l=S%C3%A3o%20Paulo&fromage=7&sc=0kf%3Aattr%28DSGMC%29%3B',
        );
});

it('aplica e remove sugestão fixa sem criar screening preference', function () {
    Livewire::test(JobSearchPage::class)
        ->call('applySuggestion', target: 'fixed_keywords', index: 1)
        ->assertSet('data.keywords', [['value' => 'vaga'], ['value' => 'oportunidade']])
        ->call('removeSuggestion', target: 'fixed_keywords', index: 0)
        ->assertSet('data.keywords', [['value' => 'oportunidade']]);

    expect(ScreeningPreference::count())->toBe(0);
});

it('acrescenta vaga uma única vez ao entrar em publicações e voltar ao LinkedIn', function () {
    Livewire::test(JobSearchPage::class)
        ->fillForm([
            'keywords' => [['value' => 'PHP']],
            'search_mode' => 'jobs',
        ])
        ->fillForm(['search_mode' => 'publications'])
        ->assertSet('data.keywords', fn (mixed $value): bool => collect($value)->pluck('value')->all() === ['PHP', 'vaga'])
        ->fillForm(['platform' => 'indeed'])
        ->assertSet('data.search_mode', 'jobs')
        ->fillForm(['platform' => 'linkedin'])
        ->assertSet('data.search_mode', 'publications')
        ->assertSet('data.keywords', fn (mixed $value): bool => collect($value)->pluck('value')->all() === ['PHP', 'vaga']);
});

it('não reinsere vaga removida na geração seguinte', function () {
    Livewire::test(JobSearchPage::class)
        ->call('removeSuggestion', target: 'fixed_keywords', index: 0)
        ->assertSet('data.keywords', [])
        ->fillForm(['keywords' => [['value' => 'PHP']]])
        ->call('generate')
        ->assertSet(
            'generatedUrl',
            'https://www.linkedin.com/search/results/content/?keywords=%22PHP%22&origin=FACETED_SEARCH&sortBy=%5B%22date_posted%22%5D',
        );
});

it('gera URL de publicações do LinkedIn com a row inicial de vaga', function () {
    Livewire::test(JobSearchPage::class)
        ->fillForm(['published_within_days' => '7'])
        ->call('generate')
        ->assertSet(
            'generatedUrl',
            'https://www.linkedin.com/search/results/content/?keywords=%22vaga%22&origin=FACETED_SEARCH&sortBy=%5B%22date_posted%22%5D&datePosted=%5B%22past-week%22%5D',
        )
        ->assertSee('vaga');
});
