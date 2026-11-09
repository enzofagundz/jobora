<?php

use App\Enums\ResumeLanguage;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\OpportunitySource;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationObservation;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\Discovery\LegacyDomainBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function discoveryLegacyCapture(User $user, array $attributes): JobCapture
{
    return $user->jobCaptures()->create($attributes + [
        'source' => 'linkedin',
        'external_id' => null,
        'dedup_key' => 'fingerprint:'.bin2hex(random_bytes(8)),
        'captured_at' => Carbon::parse('2026-11-01 12:00:00'),
    ]);
}

it('não faz nada em banco vazio', function () {
    $counts = app(LegacyDomainBackfill::class)->run();

    expect($counts)->toBe([
        'page_captures' => 0,
        'publications' => 0,
        'observations' => 0,
        'opportunity_sources' => 0,
        'skipped' => 0,
        'failed' => [],
    ])->and(PageCapture::withTrashed()->count())->toBe(0)
        ->and(Publication::count())->toBe(0);
});

it('converte o legado preservando vínculos, exclusões e proprietário', function () {
    $owner = User::factory()->create();
    $posting = JobPosting::factory()->for($owner, 'user')->confirmed()->create();
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    ResumeAdaptation::create(['job_posting_id' => $posting->id, 'adapted_content' => 'Versão adaptada.']);
    $application = Application::create(['job_posting_id' => $posting->id]);
    ApplicationEvent::create(['application_id' => $application->id, 'status' => $application->status, 'title' => 'Início', 'occurred_at' => now()]);

    $promoted = discoveryLegacyCapture($owner, [
        'external_id' => 'urn:li:activity:100',
        'dedup_key' => 'ext:urn:li:activity:100',
        'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:100/?trk=public',
        'job_posting_id' => $posting->id,
    ]);
    $indeedOne = discoveryLegacyCapture($owner, [
        'source' => 'indeed',
        'url' => 'https://br.indeed.com/viewjob?jk=abc123&from=serp',
        'dedup_key' => 'url:indeed-one',
    ]);
    $indeedTwo = discoveryLegacyCapture($owner, [
        'source' => 'indeed',
        'url' => 'https://br.indeed.com/viewjob?jk=abc123&utm_campaign=x',
        'dedup_key' => 'url:indeed-two',
    ]);
    $removed = discoveryLegacyCapture($owner, ['dedup_key' => 'fingerprint:'.str_repeat('a', 64)]);
    $removed->delete();

    $counts = app(LegacyDomainBackfill::class)->run();

    expect($counts)->toBe([
        'page_captures' => 4,
        'publications' => 3,
        'observations' => 4,
        'opportunity_sources' => 1,
        'skipped' => 0,
        'failed' => [],
    ]);

    expect(JobCapture::withTrashed()->count())->toBe(4)
        ->and(JobPosting::count())->toBe(1)
        ->and(JobCapture::query()->where('job_posting_id', $posting->id)->count())->toBe(1);

    $pageCaptures = PageCapture::withTrashed()->orderBy('id')->get();
    expect($pageCaptures)->toHaveCount(4)
        ->and($pageCaptures->every(fn (PageCapture $capture): bool => $capture->user_id === $owner->id))->toBeTrue()
        ->and($pageCaptures->every(fn (PageCapture $capture): bool => $capture->html_path === null))->toBeTrue();

    $removedPage = PageCapture::withTrashed()->where('legacy_job_capture_id', $removed->id)->sole();
    expect($removedPage->trashed())->toBeTrue();

    $indeedPublication = Publication::query()->where('source', 'indeed')->sole();
    expect($indeedPublication->canonical_url)->toBe('https://br.indeed.com/viewjob?jk=abc123')
        ->and($indeedPublication->observations()->count())->toBe(2)
        ->and($indeedOne->fresh()->job_posting_id)->toBeNull()
        ->and($indeedTwo->fresh()->job_posting_id)->toBeNull();

    $source = OpportunitySource::sole();
    expect($source->job_posting_id)->toBe($posting->id)
        ->and($source->publication_id)->toBe(Publication::query()->where('external_id', 'urn:li:activity:100')->sole()->id)
        ->and($source->is_legacy)->toBeTrue()
        ->and($promoted->fresh()->job_posting_id)->toBe($posting->id);

    expect($posting->items()->count())->toBe(1)
        ->and($posting->resumeAdaptation(ResumeLanguage::PtBr)->first()?->adapted_content)->toBe('Versão adaptada.')
        ->and($posting->application->events()->count())->toBe(1);
});

it('é idempotente e não duplica registros em execuções repetidas', function () {
    $owner = User::factory()->create();
    discoveryLegacyCapture($owner, ['external_id' => 'urn:li:activity:200', 'dedup_key' => 'ext:urn:li:activity:200']);
    discoveryLegacyCapture($owner, ['dedup_key' => 'fingerprint:'.str_repeat('b', 64)]);

    $backfill = app(LegacyDomainBackfill::class);
    $first = $backfill->run();
    $second = $backfill->run();

    expect($first['page_captures'])->toBe(2)
        ->and($second)->toBe([
            'page_captures' => 0,
            'publications' => 0,
            'observations' => 0,
            'opportunity_sources' => 0,
            'skipped' => 2,
            'failed' => [],
        ])
        ->and(PageCapture::withTrashed()->count())->toBe(2)
        ->and(Publication::count())->toBe(2)
        ->and(PublicationObservation::count())->toBe(2);
});

it('normaliza fingerprint legado longo sem truncar e preserva o dedup_key original', function () {
    $owner = User::factory()->create();
    $raw = 'content:'.str_repeat('c', 400);
    $capture = discoveryLegacyCapture($owner, ['dedup_key' => $raw]);

    expect(strlen($raw))->toBeGreaterThan(255);

    $counts = app(LegacyDomainBackfill::class)->run();

    $publication = Publication::sole();
    expect($counts['publications'])->toBe(1)
        ->and(strlen($publication->fingerprint))->toBeLessThanOrEqual(255)
        ->and($publication->fingerprint)->not->toBe($raw)
        ->and($capture->fresh()->dedup_key)->toBe($raw)
        ->and($publication->fingerprint)->toBe(hash('sha256', $raw));
});

it('normaliza a mesma fonte entre legado e domínio', function () {
    $owner = User::factory()->create();
    $capture = discoveryLegacyCapture($owner, ['source' => 'LinkedIn', 'external_id' => 'urn:li:activity:300', 'dedup_key' => 'ext:urn:li:activity:300']);

    app(LegacyDomainBackfill::class)->run();

    expect(PageCapture::sole()->source)->toBe('linkedin')
        ->and(Publication::sole()->source)->toBe('linkedin')
        ->and($capture->fresh()->source)->toBe('LinkedIn');
});

it('reporta linha sem identidade sem impedir as válidas seguintes', function () {
    $owner = User::factory()->create();
    $invalid = discoveryLegacyCapture($owner, ['dedup_key' => '']);
    $valid = discoveryLegacyCapture($owner, ['external_id' => 'urn:li:activity:400', 'dedup_key' => 'ext:urn:li:activity:400']);

    $counts = app(LegacyDomainBackfill::class)->run();

    expect($counts['failed'])->toBe([
        ['job_capture_id' => $invalid->id, 'code' => 'invalid_identity'],
    ])
        ->and($counts['page_captures'])->toBe(1)
        ->and($counts['publications'])->toBe(1)
        ->and(PageCapture::withTrashed()->where('legacy_job_capture_id', $valid->id)->exists())->toBeTrue()
        ->and(PageCapture::withTrashed()->where('legacy_job_capture_id', $invalid->id)->exists())->toBeFalse();
});
