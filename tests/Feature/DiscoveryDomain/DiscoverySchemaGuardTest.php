<?php

use App\Models\Publication;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('protege a reversão quando existem dados V2', function () {
    Publication::factory()->create();

    $migration = require database_path('migrations/2026_11_04_200001_create_discovery_domain_tables.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(Schema::hasTable('publications'))->toBeTrue();
});

it('protege a reversão do conteúdo extraído enquanto houver dados', function () {
    Publication::factory()->create();

    $migration = require database_path('migrations/2026_11_04_234249_add_publication_extraction_content.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(Schema::hasColumn('publications', 'content_hash'))->toBeTrue()
        ->and(Schema::hasColumn('publication_observations', 'evidence_block_ids'))->toBeTrue();
});

it('reverte o conteúdo extraído quando as tabelas do domínio estão vazias', function () {
    $migration = require database_path('migrations/2026_11_04_234249_add_publication_extraction_content.php');

    $migration->down();

    expect(Schema::hasColumn('publications', 'content_hash'))->toBeFalse()
        ->and(Schema::hasColumn('publication_observations', 'evidence_block_ids'))->toBeFalse();
});

it('impede conflito de identidade duplicado no banco', function () {
    $user = User::factory()->create();
    $row = [
        'user_id' => $user->id,
        'source' => 'linkedin',
        'external_id' => 'urn:li:activity:duplicate',
        'canonical_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:duplicate',
        'identity_hash' => hash('sha256', 'conflict-key'),
        'publication_id' => null,
        'other_publication_id' => null,
        'reason' => 'external_id_url_mismatch',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('publication_identity_conflicts')->insert($row);

    expect(fn () => DB::table('publication_identity_conflicts')->insert($row))
        ->toThrow(QueryException::class);
});
