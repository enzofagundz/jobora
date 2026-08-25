<?php

namespace App\Filament\Pages;

use App\DTO\SearchCriteria;
use App\Enums\SearchMode;
use App\Enums\SearchOperator;
use App\Enums\SearchPlatform;
use App\Enums\SearchSeniority;
use App\Enums\SearchWorkMode;
use App\Models\ScreeningPreference;
use App\Services\Search\SearchUrlGenerator;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use UnitEnum;

/**
 * Busca de vagas: o usuário configura critérios semânticos e o Jobora
 * monta uma URL determinística para LinkedIn ou Indeed. A configuração
 * é transitória — nada aqui grava em screening_preferences nem em
 * qualquer outra tabela; captura e triagem continuam nos fluxos deles.
 */
class JobSearchPage extends Page
{
    protected string $view = 'filament.pages.job-search';

    protected static ?string $slug = 'job-search';

    protected static ?string $navigationLabel = 'Busca de vagas';

    protected static ?string $title = 'Busca de vagas';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Vagas';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public ?string $generatedUrl = null;

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    /** @var list<string> Sugestões derivadas das preferências persistidas, sem estado da busca. */
    public array $suggestedPreferences = [];

    /** @var list<string> */
    public array $suggestedRejections = [];

    /** @var list<string> Sugestões fixas da página, independentes de ScreeningPreference. */
    public array $suggestedFixedPreferences = [];

    public function mount(): void
    {
        $preference = ScreeningPreference::firstOrNew(['user_id' => auth()->id()]);

        $this->suggestedFixedPreferences = self::FIXED_KEYWORD_SUGGESTIONS;
        $this->suggestedPreferences = self::filterTerms(
            $preference->preferences,
            self::FIXED_KEYWORD_SUGGESTIONS,
        );
        $this->suggestedRejections = self::filterTerms($preference->rejections);

        $this->form->fill([
            'keywords' => [['value' => 'vaga']],
            'excluded_terms' => [],
            'platform' => SearchPlatform::LinkedIn->value,
            'search_mode' => SearchMode::Publications->value,
            'operator' => SearchOperator::All->value,
            'seniority' => null,
            'location' => null,
            'work_mode' => SearchWorkMode::Any->value,
            'published_within_days' => null,
            'easy_apply' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Termos')
                    ->description('Adicione termos manualmente ou use as sugestões abaixo. Alterações nesta página são temporárias e não alteram os critérios de triagem.')
                    ->schema([
                        Repeater::make('keywords')
                            ->label('Palavras-chave')
                            ->schema([
                                TextInput::make('value')
                                    ->label('Palavra-chave')
                                    ->required()
                                    ->maxLength(100)
                                    ->placeholder('Ex.: PHP'),
                            ])
                            ->addActionLabel('Adicionar palavra-chave')
                            ->minItems(1)
                            ->maxItems(20)
                            ->defaultItems(1)
                            ->itemLabel(fn (array $state): ?string => $state['value'] ?? null)
                            ->columnSpanFull(),
                        Repeater::make('excluded_terms')
                            ->label('Termos a excluir')
                            ->schema([
                                TextInput::make('value')
                                    ->label('Termo a excluir')
                                    ->maxLength(100)
                                    ->placeholder('Ex.: estágio'),
                            ])
                            ->addActionLabel('Adicionar exclusão')
                            ->maxItems(20)
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state): ?string => $state['value'] ?? null)
                            ->columnSpanFull(),
                    ]),
                Section::make('Sugestões')
                    ->description('Clique para adicionar uma sugestão à busca atual.')
                    ->schema([
                        View::make('filament.schemas.job-search-suggestions')
                            ->columnSpanFull(),
                    ]),
                Section::make('Filtros')
                    ->schema([
                        Radio::make('operator')
                            ->label('Como combinar as palavras-chave?')
                            ->options(SearchOperator::class)
                            ->required()
                            ->inline()
                            ->columnSpanFull(),
                        Grid::make(2)
                            ->schema([
                                Select::make('platform')
                                    ->label('Plataforma')
                                    ->options(SearchPlatform::class)
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, Get $get, SearchPlatform|string|null $state): void {
                                        $platform = $state instanceof \BackedEnum ? (string) $state->value : (string) $state;

                                        self::resetProviderSpecificFields($set, $platform);

                                        if (self::asEnum($platform, SearchPlatform::class) === SearchPlatform::LinkedIn) {
                                            $set('keywords', self::appendKeywordIfMissing($get('keywords'), 'vaga'));
                                        }
                                    }),
                                Select::make('search_mode')
                                    ->label('Onde pesquisar?')
                                    ->options(SearchMode::class)
                                    ->default(SearchMode::Publications->value)
                                    ->required()
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::asEnum($get('platform'), SearchPlatform::class) === SearchPlatform::LinkedIn)
                                    ->helperText(fn (Get $get): string => self::modeDescription(self::isLinkedInPublications($get)))
                                    ->afterStateUpdated(function (Set $set, Get $get, SearchMode|string|null $state): void {
                                        if ($state === SearchMode::Publications) {
                                            self::clearJobsOnlyFields($set);
                                            $set('keywords', self::appendKeywordIfMissing($get('keywords'), 'vaga'));
                                        }
                                    }),
                                Select::make('seniority')
                                    ->label('Senioridade')
                                    ->options(SearchSeniority::class)
                                    ->placeholder('Qualquer senioridade')
                                    ->visible(fn (Get $get): bool => ! self::isLinkedInPublications($get)),
                                Select::make('work_mode')
                                    ->label('Modalidade')
                                    ->options(fn (Get $get): array => self::workModeOptions($get))
                                    ->live()
                                    ->default(SearchWorkMode::Any->value)
                                    ->visible(fn (Get $get): bool => ! self::isLinkedInPublications($get)),
                                Select::make('published_within_days')
                                    ->label('Publicada nos últimos')
                                    ->options(fn (Get $get): array => self::publishedWithinOptions(self::isLinkedInPublications($get)))
                                    ->placeholder('Qualquer data'),
                                TextInput::make('location')
                                    ->label('Localização')
                                    ->maxLength(255)
                                    ->placeholder('Ex.: São Paulo, Brasil')
                                    ->visible(fn (Get $get): bool => ! self::isLinkedInPublications($get))
                                    ->columnSpanFull(),
                                Toggle::make('easy_apply')
                                    ->label('Somente Easy Apply')
                                    ->helperText('Disponível somente em Vagas do LinkedIn.')
                                    ->visible(fn (Get $get): bool => self::asEnum($get('platform'), SearchPlatform::class) === SearchPlatform::LinkedIn
                                        && ! self::isLinkedInPublications($get))
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    private const FIXED_KEYWORD_SUGGESTIONS = ['vaga', 'oportunidade', 'PJ', 'CLT'];

    /**
     * Gera a URL para a plataforma selecionada. Não escreve no banco.
     */
    public function generate(): void
    {
        $data = $this->form->getState();

        try {
            $platform = self::asEnum($data['platform'], SearchPlatform::class);

            $mode = $platform === SearchPlatform::Indeed
                ? SearchMode::Jobs
                : self::asEnum($data['search_mode'], SearchMode::class);

            $criteria = SearchCriteria::fromInput(
                keywords: array_column($data['keywords'] ?? [], 'value'),
                excludedTerms: array_column($data['excluded_terms'] ?? [], 'value'),
                operator: self::asEnum($data['operator'], SearchOperator::class),
                seniority: isset($data['seniority']) ? self::asEnum($data['seniority'], SearchSeniority::class) : null,
                location: $data['location'] ?? null,
                workMode: self::asEnum($data['work_mode'] ?? SearchWorkMode::Any->value, SearchWorkMode::class),
                publishedWithinDays: isset($data['published_within_days']) ? (int) $data['published_within_days'] : null,
                easyApply: (bool) ($data['easy_apply'] ?? false),
            );

            $this->generatedUrl = app(SearchUrlGenerator::class)->build($platform, $criteria, $mode);
            $this->preview = [
                'platform_label' => $platform->getLabel(),
                'keywords' => $criteria->keywords,
                'exclusions' => $criteria->excludedTerms,
                'operator_label' => $criteria->operator->getLabel(),
                'seniority_label' => $criteria->seniority?->getLabel(),
                'work_mode_label' => $criteria->workMode->getLabel(),
                'location' => $criteria->location,
                'published_within_label' => self::publishedWithinOptions($mode === SearchMode::Publications)[$criteria->publishedWithinDays] ?? null,
            ];
        } catch (InvalidArgumentException $exception) {
            $this->generatedUrl = null;
            $this->preview = null;

            Notification::make()
                ->danger()
                ->title('Busca não gerada')
                ->body($exception->getMessage())
                ->send();
        }
    }

    private const PUBLISHED_WITHIN_OPTIONS = [
        1 => '24 horas',
        3 => '3 dias',
        7 => '7 dias',
        14 => '14 dias',
    ];

    /** Janelas exclusivas da busca de publicações do LinkedIn. */
    private const PUBLICATION_WITHIN_OPTIONS = [
        1 => '24 horas',
        7 => '7 dias',
        30 => '30 dias',
    ];

    public function applySuggestion(string $target, int $index): void
    {
        [$list, $rows, $dataKey] = self::resolveSuggestionContext($target, $index);

        if ($list === null || count($rows) >= 20 || collect($rows)->contains(fn (mixed $row): bool => self::rowMatchesTerm($row, $list[$index]))) {
            return;
        }

        $this->data[$dataKey] = array_values([...$rows, ['value' => trim($list[$index])]]);
    }

    public function removeSuggestion(string $target, int $index): void
    {
        [$list, $rows, $dataKey] = self::resolveSuggestionContext($target, $index);

        if ($list === null) {
            return;
        }

        $term = $list[$index];
        $this->data[$dataKey] = array_values(array_filter(
            $rows,
            fn (mixed $row): bool => ! self::rowMatchesTerm($row, $term),
        ));
    }

    public function isSuggestionActive(string $target, int $index): bool
    {
        [$list, $rows] = self::resolveSuggestionContext($target, $index);

        return $list !== null && collect($rows)->contains(fn (mixed $row): bool => self::rowMatchesTerm($row, $list[$index]));
    }

    /**
     * @return array{0: list<string>|null, 1: list<array<string, mixed>>, 2: string|null}
     */
    private function resolveSuggestionContext(string $target, int $index): array
    {
        $sources = [
            'keywords' => [$this->suggestedPreferences, 'keywords'],
            'fixed_keywords' => [$this->suggestedFixedPreferences, 'keywords'],
            'excluded_terms' => [$this->suggestedRejections, 'excluded_terms'],
        ];
        $context = $sources[$target] ?? null;

        if ($context === null || ! isset($context[0][$index]) || ! is_string($context[0][$index])) {
            return [null, [], null];
        }

        $rows = $this->data[$context[1]] ?? [];

        return [$context[0], is_array($rows) ? array_values($rows) : [], $context[1]];
    }

    private static function rowMatchesTerm(mixed $row, string $term): bool
    {
        return is_array($row)
            && isset($row['value'])
            && is_string($row['value'])
            && mb_strtolower(trim($row['value'])) === mb_strtolower(trim($term));
    }

    /**
     * Acrescenta uma row de keyword com o termo se ainda não existir,
     * preservando as rows atuais e a ordem.
     *
     * @return list<array<string, mixed>>
     */
    private static function appendKeywordIfMissing(mixed $rows, string $term): array
    {
        $rows = is_array($rows) ? array_values($rows) : [];

        if (collect($rows)->contains(fn (mixed $row): bool => self::rowMatchesTerm($row, $term))) {
            return $rows;
        }

        return array_values([...$rows, ['value' => $term]]);
    }

    /**
     * @param  list<string>|null  $excluded
     * @return list<string>
     */
    private static function filterTerms(?array $terms, ?array $excluded = null): array
    {
        $excluded = array_map(
            fn (string $term): string => mb_strtolower(trim($term)),
            $excluded ?? [],
        );

        return array_values(array_filter(
            $terms ?? [],
            fn (mixed $value): bool => is_string($value)
                && trim($value) !== ''
                && ! in_array(mb_strtolower(trim($value)), $excluded, true),
        ));
    }

    /**
     * O Indeed só suporta qualquer modalidade ou remoto.
     *
     * @return array<string, string>
     */
    private static function workModeOptions(Get $get): array
    {
        $indeed = self::asEnum($get('platform'), SearchPlatform::class) === SearchPlatform::Indeed;

        return collect(SearchWorkMode::cases())
            ->reject(fn (SearchWorkMode $mode): bool => $indeed && in_array($mode, [SearchWorkMode::Onsite, SearchWorkMode::Hybrid], true))
            ->mapWithKeys(fn (SearchWorkMode $mode): array => [$mode->value => $mode->getLabel()])
            ->all();
    }

    /**
     * Publicações só existem no LinkedIn; demais combinações são Vagas.
     */
    private static function isLinkedInPublications(Get $get): bool
    {
        return self::asEnum($get('platform'), SearchPlatform::class) === SearchPlatform::LinkedIn
            && self::asEnum($get('search_mode') ?? SearchMode::Jobs->value, SearchMode::class) === SearchMode::Publications;
    }

    private static function modeDescription(bool $publications): string
    {
        return $publications
            ? 'Pesquise publicações do LinkedIn onde recrutadores e empresas divulgam vagas.'
            : 'Pesquise vagas diretamente na aba de vagas do LinkedIn.';
    }

    /**
     * Janelas por modo: publicações aceitam até 30 dias.
     *
     * @return array<int, string>
     */
    private static function publishedWithinOptions(bool $publications): array
    {
        return $publications ? self::PUBLICATION_WITHIN_OPTIONS : self::PUBLISHED_WITHIN_OPTIONS;
    }

    private static function resetProviderSpecificFields(Set $set, string $platform): void
    {
        $isLinkedIn = self::asEnum($platform, SearchPlatform::class) === SearchPlatform::LinkedIn;

        $set('search_mode', $isLinkedIn ? SearchMode::Publications->value : SearchMode::Jobs->value);
        $set('work_mode', SearchWorkMode::Any->value);
        $set('easy_apply', false);
        $set('published_within_days', null);

        if ($isLinkedIn) {
            $set('seniority', null);
            $set('location', null);
        }
    }

    /**
     * Zera filtros exclusivos da aba Vagas ao alternar para Publicações,
     * evitando que valores ocultos vazem para a próxima busca.
     */
    private static function clearJobsOnlyFields(Set $set): void
    {
        $set('seniority', null);
        $set('work_mode', SearchWorkMode::Any->value);
        $set('location', null);
        $set('easy_apply', false);
        $set('published_within_days', null);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function asEnum(mixed $state, string $enum): \BackedEnum
    {
        /** @var T */
        return $state instanceof $enum ? $state : $enum::from($state);
    }
}
