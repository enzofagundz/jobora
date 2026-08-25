<?php

namespace App\Filament\Resources\JobPostings\Schemas;

use App\Enums\ApplicationMethodType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * Schema compartilhado do repeater "application_methods" — usado pelo modal
 * de revisão da tabela e pelo modal "Gerenciar métodos" da página
 * ApplicationPage. Não inclui campos derivados (source/dedup_key/job_posting_id)
 * nem persistência: a substituição continua centralizada no JobReviewService.
 */
class ApplicationMethodForm
{
    /**
     * @return array<int, Select|TextInput|Textarea>
     */
    public static function schema(): array
    {
        return [
            Select::make('type')
                ->label('Tipo')
                ->options(ApplicationMethodType::class)
                ->required(),
            TextInput::make('platform')
                ->label('Plataforma')
                ->maxLength(100),
            TextInput::make('url')
                ->label('URL')
                ->url()
                ->maxLength(2000),
            TextInput::make('email')
                ->label('E-mail')
                ->email()
                ->maxLength(320),
            TextInput::make('phone')
                ->label('Telefone')
                ->maxLength(50),
            Textarea::make('instructions')
                ->label('Instruções')
                ->maxLength(2000),
        ];
    }
}
