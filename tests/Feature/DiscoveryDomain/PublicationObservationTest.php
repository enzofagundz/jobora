<?php

use App\Models\JobPosting;
use App\Models\OpportunitySource;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationObservation;
use App\Models\User;
use App\Services\Discovery\ObservationRecorder;
use App\Services\Discovery\PublicationIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('registra uma observação por captura sem duplicar a publicação', function () {
    $user = User::factory()->create();
    $publication = app(PublicationIdentityResolver::class)->resolve($user, 'linkedin', 'urn:li:activity:1', null);
    $first = PageCapture::factory()->for($user)->create(['captured_at' => Carbon::parse('2026-11-04 10:00:00')]);
    $second = PageCapture::factory()->for($user)->create(['captured_at' => Carbon::parse('2026-11-04 11:00:00')]);

    $recorder = app(ObservationRecorder::class);
    $recorder->record($first, $publication);
    $recorder->record($second, $publication);

    expect(PublicationObservation::count())->toBe(2)
        ->and($publication->observations()->count())->toBe(2)
        ->and(Publication::count())->toBe(1);
});

it('é idempotente para o mesmo par captura-publicação', function () {
    $user = User::factory()->create();
    $publication = app(PublicationIdentityResolver::class)->resolve($user, 'linkedin', 'urn:li:activity:2', null);
    $capture = PageCapture::factory()->for($user)->create();

    $recorder = app(ObservationRecorder::class);
    $first = $recorder->record($capture, $publication);
    $second = $recorder->record($capture, $publication);

    expect($second->is($first))->toBeTrue()
        ->and(PublicationObservation::count())->toBe(1);
});

it('usa o instante da captura como observação padrão', function () {
    $user = User::factory()->create();
    $publication = app(PublicationIdentityResolver::class)->resolve($user, 'linkedin', 'urn:li:activity:3', null);
    $capture = PageCapture::factory()->for($user)->create(['captured_at' => Carbon::parse('2026-11-04 09:30:00')]);

    $observation = app(ObservationRecorder::class)->record($capture, $publication);

    expect($observation->observed_at->toDateTimeString())->toBe('2026-11-04 09:30:00');
});

it('recusa observação entre proprietários distintos', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $publication = app(PublicationIdentityResolver::class)->resolve($owner, 'linkedin', 'urn:li:activity:4', null);
    $capture = PageCapture::factory()->for($other)->create();

    expect(fn () => app(ObservationRecorder::class)->record($capture, $publication))
        ->toThrow(LogicException::class);
});

it('permite uma captura observar várias publicações', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);
    $first = $resolver->resolve($user, 'linkedin', 'urn:li:activity:7', null);
    $second = $resolver->resolve($user, 'linkedin', 'urn:li:activity:8', null);
    $capture = PageCapture::factory()->for($user)->create();

    $recorder = app(ObservationRecorder::class);
    $recorder->record($capture, $first);
    $recorder->record($capture, $second);

    expect($capture->observations()->count())->toBe(2)
        ->and(PublicationObservation::count())->toBe(2);
});

it('permite várias origens por oportunidade e várias oportunidades por publicação', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);
    $resumePost = $resolver->resolve($user, 'linkedin', 'urn:li:activity:5', null);
    $duplicatePost = $resolver->resolve($user, 'linkedin', 'urn:li:activity:6', null);

    $first = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $second = JobPosting::factory()->for($user, 'user')->confirmed()->create();

    OpportunitySource::create(['job_posting_id' => $first->id, 'publication_id' => $resumePost->id]);
    OpportunitySource::create(['job_posting_id' => $first->id, 'publication_id' => $duplicatePost->id]);
    OpportunitySource::create(['job_posting_id' => $second->id, 'publication_id' => $resumePost->id]);

    expect($first->publications()->count())->toBe(2)
        ->and($resumePost->jobPostings()->count())->toBe(2)
        ->and(OpportunitySource::count())->toBe(3);
});

it('recusa origem de publicação de outro proprietário', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $publication = app(PublicationIdentityResolver::class)->resolve($owner, 'linkedin', 'urn:li:activity:50', null);
    $foreignPosting = JobPosting::factory()->for($other, 'user')->confirmed()->create();

    expect(fn () => OpportunitySource::create([
        'job_posting_id' => $foreignPosting->id,
        'publication_id' => $publication->id,
    ]))->toThrow(LogicException::class);

    expect(OpportunitySource::count())->toBe(0);
});

it('preserva a origem quando a oportunidade é excluída', function () {
    $user = User::factory()->create();
    $publication = app(PublicationIdentityResolver::class)->resolve($user, 'linkedin', 'urn:li:activity:51', null);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $source = OpportunitySource::create(['job_posting_id' => $posting->id, 'publication_id' => $publication->id]);

    $posting->delete();

    expect($source->fresh())->not->toBeNull()
        ->and($source->fresh()->job_posting_id)->toBeNull()
        ->and($publication->fresh())->not->toBeNull();
});
