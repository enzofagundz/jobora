<?php

use App\Models\JobPosting;
use App\Models\JobPostingItem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

it('ignora itens órfãos sem vaga correspondente', function () {
    DB::statement('PRAGMA foreign_keys = OFF');
    $orphan = JobPostingItem::create([
        'job_posting_id' => 999999,
        'category' => 'skill',
        'name' => 'laravel',
    ]);
    DB::statement('PRAGMA foreign_keys = ON');

    $posting = JobPosting::factory()->confirmed()->create();
    $posting->items()->create(['category' => 'skill', 'name' => 'Laravel', 'source' => 'ai']);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    expect(JobPostingItem::find($orphan->id))->not->toBeNull()
        ->and($posting->refresh()->items()->count())->toBe(1);
});
