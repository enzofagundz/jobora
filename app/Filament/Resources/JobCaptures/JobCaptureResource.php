<?php

namespace App\Filament\Resources\JobCaptures;

use App\Filament\Resources\JobCaptures\Pages\ListJobCaptures;
use App\Filament\Resources\JobCaptures\Pages\ViewJobCapture;
use App\Filament\Resources\JobCaptures\Tables\JobCapturesTable;
use App\Models\JobCapture;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class JobCaptureResource extends Resource
{
    protected static ?string $model = JobCapture::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Capturas';

    protected static ?string $modelLabel = 'captura';

    protected static ?string $pluralModelLabel = 'capturas';

    public static function table(Table $table): Table
    {
        return JobCapturesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobCaptures::route('/'),
            'view' => ViewJobCapture::route('/{record}'),
        ];
    }
}
