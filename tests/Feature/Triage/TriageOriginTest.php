<?php

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Enums\TriageOrigin;
use App\Models\JobCapture;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('labels the prefilter and llm origins', function (): void {
    expect(TriageOrigin::Prefilter->getLabel())->toBe('Pré-filtro Clef')
        ->and(TriageOrigin::Llm->getLabel())->toBe('Triagem por IA');
});

it('backfills completed captures as llm during the schema evolution', function (): void {
    $completed = JobCapture::factory()->for(User::factory(), 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
    ]);
    $pending = JobCapture::factory()->for(User::factory(), 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Pending,
    ]);

    Schema::table('job_captures', function (Blueprint $table): void {
        $table->dropColumn('triage_origin');
    });

    $migrations = glob(database_path('migrations/*_add_triage_origin_to_job_captures_table.php'));
    expect($migrations)->toHaveCount(1);

    (require $migrations[0])->up();

    expect(DB::table('job_captures')->where('id', $completed->id)->value('triage_origin'))->toBe('llm')
        ->and(DB::table('job_captures')->where('id', $pending->id)->value('triage_origin'))->toBeNull();
});
