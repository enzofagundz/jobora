<?php

use App\Models\JobPosting;
use App\Models\JobPostingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createOrphanItem(): JobPostingItem
{
    // Inserção fora da transação do RefreshDatabase via PDO separado,
    // pois PRAGMA foreign_keys não pode ser alterado dentro de transação.
    $pdo = new PDO('sqlite:'.database_path('database.testing.sqlite'));
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('DELETE FROM job_posting_items WHERE job_posting_id = 999999');
    $pdo->exec("INSERT INTO job_posting_items (job_posting_id, category, name, created_at, updated_at) VALUES (999999, 'skill', 'laravel', datetime('now'), datetime('now'))");
    $id = $pdo->lastInsertId();
    $pdo->exec('PRAGMA foreign_keys = ON');

    // Recarregar via Eloquent na conexão de teste
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
