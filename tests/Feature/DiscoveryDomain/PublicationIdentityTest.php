<?php

use App\Models\Publication;
use App\Models\PublicationIdentityConflict;
use App\Models\User;
use App\Services\Discovery\PublicationIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reusa a publicação pelo external_id dentro do proprietário', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);

    $first = $resolver->resolve($user, 'linkedin', 'urn:li:activity:1', 'https://www.linkedin.com/feed/update/urn:li:activity:1/?trk=abc');
    $second = $resolver->resolve($user, 'linkedin', 'urn:li:activity:1', 'https://www.linkedin.com/feed/update/urn:li:activity:1/?utm_source=x');

    expect($second->is($first))->toBeTrue()
        ->and(Publication::count())->toBe(1);
});

it('isola a mesma identidade entre usuários distintos', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);

    $mine = $resolver->resolve($owner, 'linkedin', 'urn:li:activity:9', null);
    $theirs = $resolver->resolve($other, 'linkedin', 'urn:li:activity:9', null);

    expect($theirs->isNot($mine))->toBeTrue()
        ->and(Publication::count())->toBe(2);
});

it('isola a mesma identidade entre fontes distintas', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);

    $linkedin = $resolver->resolve($user, 'linkedin', 'shared-post-id', null);
    $indeed = $resolver->resolve($user, 'indeed', 'shared-post-id', null);

    expect($indeed->isNot($linkedin))->toBeTrue()
        ->and(Publication::count())->toBe(2);
});

it('identifica por URL canônica removendo apenas tracking conhecido', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);

    $first = $resolver->resolve($user, 'linkedin', null, 'https://www.linkedin.com/jobs/view/123?trk=public&utm_source=a&refId=b');
    $second = $resolver->resolve($user, 'linkedin', null, 'https://www.linkedin.com/jobs/view/123/');

    expect($second->is($first))->toBeTrue()
        ->and($first->canonical_url)->toBe('https://www.linkedin.com/jobs/view/123')
        ->and(Publication::count())->toBe(1);
});

it('preserva jk e vjk do Indeed na identidade', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);

    $first = $resolver->resolve($user, 'indeed', null, 'https://br.indeed.com/viewjob?jk=abc123&from=serp&vjs=3');
    $same = $resolver->resolve($user, 'indeed', null, 'https://br.indeed.com/viewjob?jk=abc123&utm_campaign=x');
    $other = $resolver->resolve($user, 'indeed', null, 'https://br.indeed.com/viewjob?jk=def456');
    $spa = $resolver->resolve($user, 'indeed', null, 'https://br.indeed.com/jobs?q=php&vjk=abc123&spa=1');
    $spaAgain = $resolver->resolve($user, 'indeed', null, 'https://br.indeed.com/jobs?vjk=abc123&q=php');

    expect($same->is($first))->toBeTrue()
        ->and($other->isNot($first))->toBeTrue()
        ->and($spaAgain->is($spa))->toBeTrue()
        ->and($spa->canonical_url)->toContain('vjk=abc123')
        ->and($first->canonical_url)->toContain('jk=abc123');
});

it('usa fingerprint apenas quando não há external_id nem URL canônica', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);

    $first = $resolver->resolve($user, 'linkedin', null, null, 'fingerprint:aaa');
    $same = $resolver->resolve($user, 'linkedin', null, null, 'fingerprint:aaa');
    $other = $resolver->resolve($user, 'linkedin', null, null, 'fingerprint:bbb');

    expect($same->is($first))->toBeTrue()
        ->and($other->isNot($first))->toBeTrue()
        ->and(Publication::count())->toBe(2);
});

it('registra conflito explícito quando external_id e URL apontam para publicações distintas', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);

    $byExternal = $resolver->resolve($user, 'linkedin', 'urn:li:activity:1', 'https://www.linkedin.com/feed/update/urn:li:activity:1/');
    $byUrl = $resolver->resolve($user, 'linkedin', 'urn:li:activity:2', 'https://www.linkedin.com/feed/update/urn:li:activity:2/');

    $contradictory = $resolver->resolve($user, 'linkedin', 'urn:li:activity:1', 'https://www.linkedin.com/feed/update/urn:li:activity:2/');

    expect($contradictory->is($byExternal))->toBeTrue()
        ->and($byUrl->isNot($byExternal))->toBeTrue()
        ->and(Publication::count())->toBe(2)
        ->and(PublicationIdentityConflict::count())->toBe(1);

    $conflict = PublicationIdentityConflict::sole();
    expect($conflict->external_id)->toBe('urn:li:activity:1')
        ->and($conflict->canonical_url)->toBe('https://www.linkedin.com/feed/update/urn:li:activity:2')
        ->and($conflict->publication_id)->toBe($byExternal->id)
        ->and($conflict->other_publication_id)->toBe($byUrl->id);
});

it('registra conflito sem sobrescrever external_id existente da URL', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);

    $known = $resolver->resolve($user, 'linkedin', 'urn:li:activity:1', 'https://www.linkedin.com/feed/update/shared/');
    $contradictory = $resolver->resolve($user, 'linkedin', 'urn:li:activity:2', 'https://www.linkedin.com/feed/update/shared/');

    expect($contradictory->is($known))->toBeTrue()
        ->and($known->fresh()->external_id)->toBe('urn:li:activity:1')
        ->and(Publication::count())->toBe(1)
        ->and(PublicationIdentityConflict::count())->toBe(1);
});

it('enriquece url e canonical juntos ao reencontrar pelo external_id', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);

    $base = $resolver->resolve($user, 'linkedin', 'urn:li:activity:30', null);
    $withUrl = $resolver->resolve($user, 'linkedin', 'urn:li:activity:30', 'https://www.linkedin.com/feed/update/urn:li:activity:30/?trk=abc');
    $byUrl = $resolver->resolve($user, 'linkedin', null, 'https://www.linkedin.com/feed/update/urn:li:activity:30/?utm_source=x');

    expect($withUrl->is($base))->toBeTrue()
        ->and($byUrl->is($base))->toBeTrue()
        ->and($base->fresh()->canonical_url)->toBe('https://www.linkedin.com/feed/update/urn:li:activity:30')
        ->and($base->fresh()->canonical_url_hash)->toBe(hash('sha256', 'https://www.linkedin.com/feed/update/urn:li:activity:30'))
        ->and(Publication::count())->toBe(1);
});

it('enriquece external_id coerente ao reencontrar pela URL', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);

    $byUrl = $resolver->resolve($user, 'linkedin', null, 'https://www.linkedin.com/feed/update/urn:li:activity:31/');
    $withExternal = $resolver->resolve($user, 'linkedin', 'urn:li:activity:31', 'https://www.linkedin.com/feed/update/urn:li:activity:31/');
    $byExternal = $resolver->resolve($user, 'linkedin', 'urn:li:activity:31', null);

    expect($withExternal->is($byUrl))->toBeTrue()
        ->and($byExternal->is($byUrl))->toBeTrue()
        ->and(Publication::count())->toBe(1);
});

it('rejeita resolução sem nenhuma identidade', function () {
    $user = User::factory()->create();

    expect(fn () => app(PublicationIdentityResolver::class)->resolve($user, 'linkedin', null, null, null))
        ->toThrow(InvalidArgumentException::class);

    expect(Publication::count())->toBe(0);
});

it('normaliza fingerprint longo de forma estável', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);
    $long = str_repeat('a', 512);

    $first = $resolver->resolve($user, 'linkedin', null, null, $long);
    $second = $resolver->resolve($user, 'linkedin', null, null, $long);

    expect($second->is($first))->toBeTrue()
        ->and(strlen($first->fingerprint))->toBeLessThanOrEqual(255)
        ->and($first->fingerprint)->not->toBe($long)
        ->and(Publication::count())->toBe(1);
});

it('não duplica conflito idêntico em resoluções repetidas', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);

    $resolver->resolve($user, 'linkedin', 'urn:li:activity:40', 'https://www.linkedin.com/feed/update/urn:li:activity:40/');
    $resolver->resolve($user, 'linkedin', 'urn:li:activity:41', 'https://www.linkedin.com/feed/update/urn:li:activity:41/');

    $resolver->resolve($user, 'linkedin', 'urn:li:activity:40', 'https://www.linkedin.com/feed/update/urn:li:activity:41/');
    $resolver->resolve($user, 'linkedin', 'urn:li:activity:40', 'https://www.linkedin.com/feed/update/urn:li:activity:41/');

    expect(PublicationIdentityConflict::count())->toBe(1);
});

it('remove originalSubdomain da URL canônica do LinkedIn', function () {
    $user = User::factory()->create();
    $resolver = app(PublicationIdentityResolver::class);

    $first = $resolver->resolve($user, 'linkedin', null, 'https://www.linkedin.com/jobs/view/321?originalSubdomain=br');
    $second = $resolver->resolve($user, 'linkedin', null, 'https://www.linkedin.com/jobs/view/321?originalSubdomain=pt');
    $third = $resolver->resolve($user, 'linkedin', null, 'https://www.linkedin.com/jobs/view/321');

    expect($second->is($first))->toBeTrue()
        ->and($third->is($first))->toBeTrue()
        ->and($first->canonical_url)->toBe('https://www.linkedin.com/jobs/view/321')
        ->and(Publication::count())->toBe(1);
});
