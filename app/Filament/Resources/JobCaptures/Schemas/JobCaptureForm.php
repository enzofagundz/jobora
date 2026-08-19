<?php

namespace App\Filament\Resources\JobCaptures\Schemas;

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Schema da página de view da captura: a ViewRecord renderiza o form
 * do resource como somente-leitura, exibindo todo o conteúdo da captura.
 */
class JobCaptureForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('description')
                    ->label('Descrição')
                    ->rows(8)
                    ->columnSpanFull(),
                TextInput::make('title')
                    ->label('Título')
                    ->placeholder('—'),
                TextInput::make('company')
                    ->label('Empresa')
                    ->placeholder('—'),
                TextInput::make('location')
                    ->label('Localização')
                    ->placeholder('—'),
                TextInput::make('source')
                    ->label('Origem'),
                TextInput::make('external_id')
                    ->label('ID externo')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextInput::make('url')
                    ->label('URL')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextInput::make('search_url')
                    ->label('URL de busca')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextInput::make('author_name')
                    ->label('Autor')
                    ->placeholder('—'),
                TextInput::make('author_profile_url')
                    ->label('Perfil do autor')
                    ->placeholder('—')
                    ->columnSpanFull(),
                DateTimePicker::make('captured_at')
                    ->label('Capturada em'),
                TextInput::make('triage_status')
                    ->label('Triagem')
                    ->formatStateUsing(fn (?string $state): string => JobCaptureTriageStatus::tryFrom((string) $state)?->label() ?? '—'),
                TextInput::make('triage_result')
                    ->label('Resultado')
                    ->formatStateUsing(fn (?string $state): string => JobCaptureTriageResult::tryFrom((string) $state)?->label() ?? '—'),
                Textarea::make('triage_reason')
                    ->label('Justificativa')
                    ->rows(3)
                    ->placeholder('—')
                    ->columnSpanFull(),
                Textarea::make('triage_error')
                    ->label('Erro')
                    ->rows(3)
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }
}
