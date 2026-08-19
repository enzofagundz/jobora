<?php

namespace App\Filament\Resources\JobCaptures\Tables;

use App\Filament\Resources\JobCaptures\Pages\ViewJobCapture;
use App\Models\JobCapture;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class JobCapturesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(70)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('source')
                    ->label('Origem')
                    ->badge(),
                TextColumn::make('external_id')
                    ->label('ID externo')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('captured_at')
                    ->label('Capturada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('job_posting_id')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Promovida' : 'Pendente')
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (JobCapture $record): string => ViewJobCapture::getUrl(['record' => $record])),
            ])
            ->defaultSort('captured_at', 'desc');
    }
}
