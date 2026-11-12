<?php

use App\Models\JobPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('rolls back publication triage without losing postings with null descriptions', function () {
    $withoutDescription = JobPosting::factory()->create(['description' => null]);
    $withDescription = JobPosting::factory()->create(['description' => 'Descrição preservada']);
    $migration = require database_path('migrations/2026_11_05_230000_add_publication_triage_results_and_opportunities.php');

    $migration->down();

    expect(DB::table('job_postings')->where('id', $withoutDescription->id)->value('description'))->toBe('')
        ->and(DB::table('job_postings')->where('id', $withDescription->id)->value('description'))->toBe('Descrição preservada')
        ->and(DB::table('job_postings')->count())->toBe(2)
        ->and(Schema::hasColumn('job_postings', 'readiness'))->toBeFalse()
        ->and(Schema::hasTable('publication_triage_results'))->toBeFalse();
});
