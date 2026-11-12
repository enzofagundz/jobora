<?php

namespace App\Filament\Resources\JobPostings\Schemas;

use App\DTO\PublicationTriageOutput;
use App\Models\JobPosting;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * Campos editáveis de uma oportunidade estruturada. A correção em si
 * continua centralizada no OpportunityReviewService, que valida, versiona e
 * preserva proveniência e histórico.
 */
class OpportunityReviewForm
{
    /**
     * @return array<int, Repeater|Select|TagsInput|Textarea|TextInput>
     */
    public static function schema(): array
    {
        return [
            TextInput::make('role')
                ->label('Cargo')
                ->maxLength(255),
            TextInput::make('company')
                ->label('Empresa')
                ->maxLength(255),
            Textarea::make('description')
                ->label('Descrição')
                ->rows(6)
                ->maxLength(100000)
                ->columnSpanFull(),
            TextInput::make('location')
                ->label('Localidade')
                ->maxLength(255),
            TextInput::make('work_mode')
                ->label('Modalidade')
                ->maxLength(100),
            TextInput::make('employment_type')
                ->label('Tipo de contratação')
                ->maxLength(100),
            TextInput::make('salary_min')
                ->label('Salário mínimo')
                ->numeric()
                ->minValue(0)
                ->maxValue(999999999999.99),
            TextInput::make('salary_max')
                ->label('Salário máximo')
                ->numeric()
                ->minValue(0)
                ->maxValue(999999999999.99),
            TextInput::make('salary_currency')
                ->label('Moeda')
                ->maxLength(3)
                ->regex('/^[A-Z]{3}$/')
                ->helperText('Código de três letras, como BRL ou USD.'),
            TextInput::make('salary_period')
                ->label('Período do salário')
                ->maxLength(50),
            TextInput::make('seniority')
                ->label('Senioridade')
                ->maxLength(100),
            TagsInput::make('requirements')
                ->label('Requisitos')
                ->columnSpanFull(),
            TagsInput::make('technologies')
                ->label('Tecnologias')
                ->columnSpanFull(),
            Repeater::make('application_methods')
                ->label('Formas de candidatura')
                ->schema(ApplicationMethodForm::schema())
                ->maxItems(20)
                ->columns(3)
                ->columnSpanFull(),
            TextInput::make('source_url')
                ->label('Link da publicação')
                ->url()
                ->maxLength(2048)
                ->placeholder('https://exemplo.com/vaga')
                ->helperText('Precisa ser o link da publicação ou constar nela.')
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                ->columnSpanFull(),
            Repeater::make('evidence')
                ->label('Evidências')
                ->helperText('A citação precisa existir na publicação persistida. Os blocos vêm dela e os campos dizem o que a citação sustenta.')
                ->schema([
                    Textarea::make('quote')
                        ->label('Citação literal')
                        ->required()
                        ->maxLength(2000)
                        ->rows(3)
                        ->columnSpanFull(),
                    TagsInput::make('block_ids')
                        ->label('Blocos da publicação')
                        ->required(),
                    Select::make('fields')
                        ->label('Campos sustentados')
                        ->multiple()
                        ->options(self::evidenceFieldOptions())
                        ->helperText('Obrigatório ao corrigir ou adicionar evidência.'),
                ])
                ->columns(2)
                ->maxItems(50)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(JobPosting $posting): array
    {
        return [
            'role' => $posting->role,
            'company' => $posting->company,
            'description' => $posting->description,
            'location' => $posting->location,
            'work_mode' => $posting->work_mode,
            'employment_type' => $posting->employment_type,
            'salary_min' => $posting->salary_min,
            'salary_max' => $posting->salary_max,
            'salary_currency' => $posting->salary_currency,
            'salary_period' => $posting->salary_period,
            'seniority' => $posting->seniority,
            'requirements' => $posting->requirements ?? [],
            'technologies' => $posting->technologies ?? [],
            'application_methods' => $posting->applicationMethods
                ->map(fn ($method): array => [
                    'type' => $method->type?->value,
                    'platform' => $method->platform,
                    'url' => $method->url,
                    'email' => $method->email,
                    'phone' => $method->phone,
                    'instructions' => $method->instructions,
                ])
                ->values()
                ->all(),
            'source_url' => $posting->source_url,
            'evidence' => self::normalizeEvidence($posting->evidence),
        ];
    }

    /**
     * Remove proveniência inalterada do payload para não reenviar evidência
     * antiga (sem "fields") nem sobrescrever o que o usuário não corrigiu.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withoutUnchangedProvenance(array $data, JobPosting $posting): array
    {
        if (array_key_exists('source_url', $data) && ($data['source_url'] ?? null) === $posting->source_url) {
            unset($data['source_url']);
        }

        if (array_key_exists('evidence', $data)
            && self::normalizeEvidence($data['evidence']) === self::normalizeEvidence($posting->evidence)) {
            unset($data['evidence']);
        }

        return $data;
    }

    /**
     * @return list<array{quote: string, block_ids: list<string>, fields: list<string>}>
     */
    private static function normalizeEvidence(mixed $evidence): array
    {
        return collect(is_array($evidence) ? $evidence : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'quote' => is_string($item['quote'] ?? null) ? $item['quote'] : '',
                'block_ids' => array_values(array_filter((array) ($item['block_ids'] ?? []), 'is_string')),
                'fields' => array_values(array_filter((array) ($item['fields'] ?? []), 'is_string')),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function evidenceFieldOptions(): array
    {
        return collect(PublicationTriageOutput::evidenceFields())
            ->mapWithKeys(fn (string $field): array => [$field => self::evidenceFieldLabel($field)])
            ->all();
    }

    public static function evidenceFieldLabel(string $field): string
    {
        return match ($field) {
            'role' => 'Cargo',
            'company' => 'Empresa',
            'description' => 'Descrição',
            'location' => 'Localidade',
            'work_mode' => 'Modalidade',
            'employment_type' => 'Tipo de contratação',
            'salary_min' => 'Salário mínimo',
            'salary_max' => 'Salário máximo',
            'salary_currency' => 'Moeda',
            'salary_period' => 'Período do salário',
            'seniority' => 'Senioridade',
            'requirements' => 'Requisitos',
            'technologies' => 'Tecnologias',
            'application_methods' => 'Formas de candidatura',
            default => $field,
        };
    }
}
