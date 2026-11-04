<?php

use App\Models\JobPosting;
use App\Models\JobPostingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function createOrphanItem(): JobPostingItem
{
    $connection = DB::connection();
    $driver = $connection->getDriverName();

    if ($driver === 'pgsql') {
        $connection->unprepared('SET session_replication_role = replica');
    } else {
        $connection->unprepared('PRAGMA defer_foreign_keys = ON');
    }

    try {
        $id = $connection->table('job_posting_items')->insertGetId([
            'job_posting_id' => 999999,
            'category' => 'skill',
            'name' => 'laravel',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } finally {
        if ($driver === 'pgsql') {
            $connection->unprepared('SET session_replication_role = origin');
        } else {
            $connection->unprepared('PRAGMA defer_foreign_keys = OFF');
        }
    }

    return JobPostingItem::find((int) $id);
}

it('remove itens órfãos sem vaga correspondente na execução normal', function () {
    $orphan = createOrphanItem();

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $posting->items()->create(['category' => 'skill', 'name' => 'Laravel', 'source' => 'ai']);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    expect(JobPostingItem::find($orphan->id))->toBeNull()
        ->and($posting->refresh()->items()->count())->toBe(1);
});

it('preserva itens órfãos sem vaga correspondente no dry-run', function () {
    $orphan = createOrphanItem();

    $this->artisan('job-posting-items:normalize', ['--dry-run' => true])->assertSuccessful();

    expect(JobPostingItem::find($orphan->id))->not->toBeNull();
});
