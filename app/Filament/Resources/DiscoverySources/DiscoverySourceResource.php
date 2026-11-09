<?php

namespace App\Filament\Resources\DiscoverySources;

use App\Enums\DiscoverySourceSurface;
use App\Enums\SearchPlatform;
use App\Filament\Resources\DiscoverySources\Pages\CreateDiscoverySource;
use App\Filament\Resources\DiscoverySources\Pages\EditDiscoverySource;
use App\Filament\Resources\DiscoverySources\Pages\ListDiscoverySources;
use App\Filament\Resources\DiscoverySources\Schemas\DiscoverySourceForm;
use App\Filament\Resources\DiscoverySources\Tables\DiscoverySourcesTable;
use App\Models\DiscoverySource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DiscoverySourceResource extends Resource
{
    protected static ?string $model = DiscoverySource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Fontes de descoberta';

    protected static string|UnitEnum|null $navigationGroup = 'Vagas';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'fonte';

    protected static ?string $pluralModelLabel = 'fontes';

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return auth()->check();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return DiscoverySourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DiscoverySourcesTable::configure($table);
    }

    /**
     * @return array<string, string>
     */
    public static function surfaceOptions(): array
    {
        return [
            DiscoverySourceSurface::LinkedInJobs->value => 'Vagas do LinkedIn',
            DiscoverySourceSurface::LinkedInPublications->value => 'Publicações do LinkedIn',
            DiscoverySourceSurface::IndeedJobs->value => 'Vagas do Indeed',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function surfaceOptionsFor(SearchPlatform|string|null $platform): array
    {
        if ($platform === null) {
            return [];
        }

        $allowed = DiscoverySourceSurface::forPlatform(
            $platform instanceof SearchPlatform ? $platform : SearchPlatform::from($platform),
        );

        return array_intersect_key(static::surfaceOptions(), array_flip($allowed));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeFormData(array $data): array
    {
        $mode = $data['search_mode'] ?? 'criteria';

        foreach (['source', 'surface'] as $key) {
            if (($data[$key] ?? null) instanceof BackedEnum) {
                $data[$key] = $data[$key]->value;
            }
        }

        unset($data['search_mode'], $data['expected_version']);

        if ($mode === 'url') {
            unset($data['criteria']);
        } else {
            unset($data['url']);
            $data['criteria'] = $data['criteria'] ?? [];
        }

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiscoverySources::route('/'),
            'create' => CreateDiscoverySource::route('/create'),
            'edit' => EditDiscoverySource::route('/{record}/edit'),
        ];
    }
}
