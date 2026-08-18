<?php

namespace App\Filament\Resources\JobPostings\Schemas;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class JobPostingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                RichEditor::make('description')
                    ->label('Descrição da vaga')
                    ->required()
                    ->helperText('Cole o texto completo da vaga. A descrição é imutável após o cadastro (correção = excluir e recadastrar).')
                    ->minLength(20)
                    ->maxLength(100000)
                    ->columnSpanFull(),
                TextInput::make('source_url')
                    ->label('Link de referência (opcional)')
                    ->url()
                    ->placeholder('https://exemplo.com/vaga')
                    ->columnSpanFull(),
            ]);
    }
}
