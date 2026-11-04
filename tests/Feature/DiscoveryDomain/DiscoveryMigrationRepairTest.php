<?php

use App\Models\JobPosting;
use App\Models\OpportunitySource;
use App\Models\PublicationIdentityConflict;
use App\Models\User;
use App\Services\Discovery\PublicationIdentityResolver;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('repara schema antigo preservando origens e aplicando unicidade', function () {
    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $publication = app(PublicationIdentityResolver::class)->resolve($user, 'linkedin', 'urn:li:activity:repair', null);
    $source = OpportunitySource::create(['job_posting_id' => $posting->id, 'publication_id' => $publication->id]);

    Schema::table('publication_identity_conflicts', function (Blueprint $table): void {
        $table->dropUnique(['identity_hash']);
    });

    Schema::table('publication_identity_conflicts', function (Blueprint $table): void {
        $table->dropColumn('identity_hash');
    });

    Schema::table('opportunity_sources', function (Blueprint $table): void {
        $table->dropForeign(['job_posting_id']);
    });

    Schema::table('opportunity_sources', function (Blueprint $table): void {
        $table->foreignId('job_posting_id')->nullable(false)->change();
    });

    Schema::table('opportunity_sources', function (Blueprint $table): void {
        $table->foreign('job_posting_id')->references('id')->on('job_postings')->cascadeOnDelete();
    });

    $row = [
        'user_id' => $user->id,
        'source' => 'linkedin',
        'external_id' => 'urn:li:activity:repair',
        'canonical_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:repair',
        'publication_id' => null,
        'other_publication_id' => null,
        'reason' => 'external_id_url_mismatch',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('publication_identity_conflicts')->insert([$row, $row]);

    (require database_path('migrations/2026_11_04_200002_reconcile_discovery_domain_constraints.php'))->up();

    $conflict = PublicationIdentityConflict::sole();
    expect($conflict->identity_hash)->toHaveLength(64)
        ->and(PublicationIdentityConflict::count())->toBe(1)
        ->and(OpportunitySource::count())->toBe(1)
        ->and($source->fresh()->publication_id)->toBe($publication->id);

    $column = collect(Schema::getColumns('opportunity_sources'))->firstWhere('name', 'job_posting_id');
    $foreignKey = collect(Schema::getForeignKeys('opportunity_sources'))
        ->first(fn (array $key): bool => $key['columns'] === ['job_posting_id']);

    expect($column['nullable'])->toBeTrue()
        ->and(strtoupper((string) $foreignKey['on_delete']))->toBe('SET NULL');

    expect(fn () => DB::transaction(fn () => DB::table('publication_identity_conflicts')->insert($row + ['identity_hash' => $conflict->identity_hash])))
        ->toThrow(QueryException::class);

    $posting->delete();

    expect($source->fresh())->not->toBeNull()
        ->and($source->fresh()->job_posting_id)->toBeNull()
        ->and($publication->fresh())->not->toBeNull();
});
