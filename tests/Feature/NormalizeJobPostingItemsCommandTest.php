<?php

use App\Enums\ItemSource;
use App\Models\JobPosting;
use App\Models\JobPostingItem;
use App\Models\ResumeAdaptation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function confirmedPostingWithItems(array $items): JobPosting
{
    $posting = JobPosting::factory()->for(User::factory()->create(), 'user')->confirmed()->create();

    foreach ($items as $item) {
        $posting->items()->create([
            'category' => $item['category'],
            'name' => $item['name'],
            'source' => $item['source'] ?? 'ai',
        ]);
    }

    return $posting;
}

it('une nomes com capitalização diferente mantendo o canônico', function () {
    $posting = confirmedPostingWithItems([
        ['category' => 'skill', 'name' => 'Laravel'],
        ['category' => 'skill', 'name' => 'laravel'],
    ]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    $remaining = $posting->refresh()->items()->get();

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->name)->toBe('Laravel');
});

it('une nomes com espaços extras', function () {
    $posting = confirmedPostingWithItems([
        ['category' => 'skill', 'name' => 'Laravel'],
        ['category' => 'skill', 'name' => ' Laravel  '],
    ]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    expect($posting->refresh()->items()->count())->toBe(1);
});

it('deduplica múltiplos duplicados apontando para um canonical', function () {
    $posting = confirmedPostingWithItems([
        ['category' => 'skill', 'name' => 'Laravel'],
        ['category' => 'skill', 'name' => 'laravel'],
        ['category' => 'skill', 'name' => ' LARAVEL '],
    ]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    $remaining = $posting->refresh()->items()->get();

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->id)->toBe($posting->items()->orderBy('id')->value('id'));
});

it('não une categorias diferentes', function () {
    $posting = confirmedPostingWithItems([
        ['category' => 'skill', 'name' => 'Laravel'],
        ['category' => 'tool', 'name' => 'laravel'],
    ]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    expect($posting->refresh()->items()->count())->toBe(2);
});

it('não une itens de vagas diferentes', function () {
    confirmedPostingWithItems([['category' => 'skill', 'name' => 'Laravel']]);
    confirmedPostingWithItems([['category' => 'skill', 'name' => 'laravel']]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    expect(JobPostingItem::count())->toBe(2);
});

it('mantém C, C++ e C# distintos', function () {
    $posting = confirmedPostingWithItems([
        ['category' => 'skill', 'name' => 'C'],
        ['category' => 'skill', 'name' => 'C++'],
        ['category' => 'skill', 'name' => 'C#'],
    ]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    expect($posting->refresh()->items()->count())->toBe(3);
});

it('mantém Node e Node.js distintos', function () {
    $posting = confirmedPostingWithItems([
        ['category' => 'skill', 'name' => 'Node'],
        ['category' => 'skill', 'name' => 'Node.js'],
    ]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    expect($posting->refresh()->items()->count())->toBe(2);
});

it('escolhe o registro de origem user como canônico', function () {
    $posting = confirmedPostingWithItems([
        ['category' => 'skill', 'name' => 'laravel', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'Laravel', 'source' => 'user'],
    ]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    $remaining = $posting->refresh()->items()->sole();

    expect($remaining->name)->toBe('Laravel')
        ->and($remaining->source)->toBe(ItemSource::User);
});

it('não altera referências inexistentes', function () {
    $posting = confirmedPostingWithItems([
        ['category' => 'skill', 'name' => 'Laravel'],
        ['category' => 'skill', 'name' => 'laravel'],
    ]);

    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'curriculo',
    ]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    expect(ResumeAdaptation::query()->sole()->job_posting_id)->toBe($posting->id);
});

it('não persiste alterações no dry-run', function () {
    $posting = confirmedPostingWithItems([
        ['category' => 'skill', 'name' => 'Laravel'],
        ['category' => 'skill', 'name' => 'laravel'],
    ]);

    $this->artisan('job-posting-items:normalize', ['--dry-run' => true])->assertSuccessful();

    expect($posting->refresh()->items()->count())->toBe(2);
});

it('persiste a deduplicação na execução normal', function () {
    $posting = confirmedPostingWithItems([
        ['category' => 'skill', 'name' => 'Laravel'],
        ['category' => 'skill', 'name' => 'laravel'],
    ]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    expect($posting->refresh()->items()->count())->toBe(1);
});

it('é idempotente em execuções consecutivas', function () {
    confirmedPostingWithItems([
        ['category' => 'skill', 'name' => 'Laravel'],
        ['category' => 'skill', 'name' => 'laravel'],
    ]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();
    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    expect(JobPostingItem::count())->toBe(1);
});

it('preserva e reporta casos ambíguos', function () {
    $posting = confirmedPostingWithItems([
        ['category' => 'skill', 'name' => ''],
        ['category' => 'skill', 'name' => ' '],
    ]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    expect($posting->refresh()->items()->count())->toBe(2);
});

it('não produz alterações sem duplicados', function () {
    confirmedPostingWithItems([
        ['category' => 'skill', 'name' => 'Laravel'],
        ['category' => 'skill', 'name' => 'PHP'],
    ]);

    $this->artisan('job-posting-items:normalize')->assertSuccessful();

    expect(JobPostingItem::count())->toBe(2);
});
