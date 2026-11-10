<?php

use App\Enums\AgentIntegrationScope;
use App\Models\DiscoverySource;
use App\Models\User;
use App\Services\Discovery\DiscoverySourceConflictException;
use App\Services\Discovery\DiscoverySourceService;
use App\Services\Discovery\DiscoverySourceUrl;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function discoveryService(): DiscoverySourceService
{
    return app(DiscoverySourceService::class);
}

it('creates a source from criteria preserving the publication period and generated url', function () {
    $user = User::factory()->create();

    $source = discoveryService()->create($user, [
        'name' => 'LinkedIn Laravel',
        'source' => 'linkedin',
        'surface' => 'linkedin_jobs',
        'frequency_minutes' => 30,
        'criteria' => [
            'keywords' => ['Laravel'],
            'published_within_days' => 7,
            'work_mode' => 'remote',
        ],
    ]);

    expect($source->version)->toBe(1)
        ->and($source->active)->toBeTrue()
        ->and($source->criteria['published_within_days'])->toBe(7)
        ->and($source->url)->toContain('f_TPR=r604800')
        ->and($source->url)->toContain('f_WT=2')
        ->and($source->canonical_url_hash)->toBe(app(DiscoverySourceUrl::class)->hash($source->url));
});

it('accepts a raw url for the declared surface', function () {
    $user = User::factory()->create();

    $source = discoveryService()->create($user, [
        'name' => 'Indeed PHP',
        'source' => 'indeed',
        'surface' => 'indeed_jobs',
        'frequency_minutes' => 60,
        'url' => 'https://br.indeed.com/jobs?q=PHP&fromage=3',
    ]);

    expect($source->url)->toContain('fromage=3')
        ->and($source->criteria)->toBeNull();
});

it('rejects urls that do not belong to the platform or surface', function (string $source, string $surface, string $url) {
    $user = User::factory()->create();

    expect(fn () => discoveryService()->create($user, [
        'name' => 'Inválida',
        'source' => $source,
        'surface' => $surface,
        'frequency_minutes' => 60,
        'url' => $url,
    ]))->toThrow(InvalidArgumentException::class);
})->with([
    'wrong host' => ['linkedin', 'linkedin_jobs', 'https://evil.example/jobs/search/?keywords=PHP'],
    'http scheme' => ['linkedin', 'linkedin_jobs', 'http://www.linkedin.com/jobs/search/?keywords=PHP'],
    'wrong path' => ['linkedin', 'linkedin_jobs', 'https://www.linkedin.com/search/results/content/?keywords=PHP'],
    'credentials' => ['indeed', 'indeed_jobs', 'https://user:pass@br.indeed.com/jobs?q=PHP'],
]);

it('rejects a url that diverges from the supplied criteria', function () {
    $user = User::factory()->create();

    expect(fn () => discoveryService()->create($user, [
        'name' => 'Divergente',
        'source' => 'linkedin',
        'surface' => 'linkedin_jobs',
        'frequency_minutes' => 60,
        'url' => 'https://www.linkedin.com/jobs/search/?keywords=PHP&f_TPR=r86400',
        'criteria' => ['keywords' => ['PHP'], 'published_within_days' => 30],
    ]))->toThrow(InvalidArgumentException::class);
});

it('deduplicates sources by canonical search url per user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    discoveryService()->create($user, [
        'name' => 'Primeira',
        'source' => 'linkedin',
        'surface' => 'linkedin_jobs',
        'frequency_minutes' => 60,
        'url' => 'https://www.linkedin.com/jobs/search/?keywords=PHP&f_TPR=r86400&trk=abc',
    ]);

    expect(fn () => discoveryService()->create($user, [
        'name' => 'Duplicada',
        'source' => 'linkedin',
        'surface' => 'linkedin_jobs',
        'frequency_minutes' => 60,
        'url' => 'https://WWW.LINKEDIN.COM/jobs/search/?f_TPR=r86400&keywords=PHP',
    ]))->toThrow(ValidationException::class);

    $source = discoveryService()->create($other, [
        'name' => 'Mesma URL outra conta',
        'source' => 'linkedin',
        'surface' => 'linkedin_jobs',
        'frequency_minutes' => 60,
        'url' => 'https://www.linkedin.com/jobs/search/?keywords=PHP&f_TPR=r86400',
    ]);

    expect($source->user_id)->toBe($other->id);
});

it('requires the expected version on update and increments it', function () {
    $user = User::factory()->create();
    $source = DiscoverySource::factory()->for($user, 'user')->create();

    expect(fn () => discoveryService()->update($user, $source->id, 99, ['name' => 'Nova']))
        ->toThrow(DiscoverySourceConflictException::class);

    $updated = discoveryService()->update($user, $source->id, 1, ['name' => 'Nova']);

    expect($updated->name)->toBe('Nova')
        ->and($updated->version)->toBe(2);
});

it('deactivates without deleting history and soft deletes on removal', function () {
    $user = User::factory()->create();
    $source = DiscoverySource::factory()->for($user, 'user')->create();

    $deactivated = discoveryService()->deactivate($user, $source->id, 1);

    expect($deactivated->active)->toBeFalse()
        ->and($deactivated->next_due_at)->toBeNull()
        ->and(DiscoverySource::query()->whereKey($source->id)->exists())->toBeTrue();

    $deleted = discoveryService()->delete($user, $source->id, $deactivated->version);

    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->active)->toBeFalse()
        ->and(DiscoverySource::withTrashed()->whereKey($source->id)->exists())->toBeTrue();
});

it('does not expose or mutate another user source', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $source = DiscoverySource::factory()->for($owner, 'user')->create();

    expect(fn () => discoveryService()->get($intruder, $source->id))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => discoveryService()->update($intruder, $source->id, 1, ['name' => 'Hack']))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => discoveryService()->delete($intruder, $source->id, 1))
        ->toThrow(ModelNotFoundException::class);
});

it('paginates only the owner sources with filters', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    DiscoverySource::factory()->for($owner, 'user')->count(3)->create();
    DiscoverySource::factory()->for($owner, 'user')->create(['active' => false]);
    DiscoverySource::factory()->for($other, 'user')->create();

    $page = discoveryService()->paginate($owner, ['active' => true], 2, 0);

    expect($page->total())->toBe(3)
        ->and($page->perPage())->toBe(2)
        ->and($page->items())->toHaveCount(2);
});

it('grants the new source capabilities to new integrations only', function () {
    expect(AgentIntegrationScope::defaults())->toContain('sources:read', 'sources:write')
        ->and(AgentIntegrationScope::labels())->toHaveKeys(['sources:read', 'sources:write']);
});

it('rejects raw urls with unsupported or conflicting semantic period filters', function (string $source, string $surface, string $url) {
    $user = User::factory()->create();

    expect(fn () => discoveryService()->create($user, [
        'name' => 'Período inválido',
        'source' => $source,
        'surface' => $surface,
        'frequency_minutes' => 60,
        'url' => $url,
    ]))->toThrow(InvalidArgumentException::class);
})->with([
    'indeed fromage fora da lista' => ['indeed', 'indeed_jobs', 'https://br.indeed.com/jobs?q=PHP&fromage=999'],
    'linkedin f_TPR inválido' => ['linkedin', 'linkedin_jobs', 'https://www.linkedin.com/jobs/search/?keywords=PHP&f_TPR=r99999'],
    'linkedin jobs com datePosted de publicações' => ['linkedin', 'linkedin_jobs', 'https://www.linkedin.com/jobs/search/?keywords=PHP&datePosted=%5B%22past-week%22%5D'],
    'linkedin publications com f_TPR de vagas' => ['linkedin', 'linkedin_publications', 'https://www.linkedin.com/search/results/content/?keywords=PHP&f_TPR=r86400'],
    'datas conflitantes' => ['linkedin', 'linkedin_jobs', 'https://www.linkedin.com/jobs/search/?keywords=PHP&f_TPR=r86400&fromage=7'],
]);

it('keeps legitimate raw urls with supported period filters', function (string $source, string $surface, string $url) {
    $user = User::factory()->create();

    $created = discoveryService()->create($user, [
        'name' => 'Período válido',
        'source' => $source,
        'surface' => $surface,
        'frequency_minutes' => 60,
        'url' => $url,
    ]);

    expect($created->url)->toBe($url);
})->with([
    'indeed fromage válido' => ['indeed', 'indeed_jobs', 'https://br.indeed.com/jobs?q=PHP&fromage=7'],
    'linkedin f_TPR válido' => ['linkedin', 'linkedin_jobs', 'https://www.linkedin.com/jobs/search/?keywords=PHP&f_TPR=r604800'],
    'linkedin publications datePosted válido' => ['linkedin', 'linkedin_publications', 'https://www.linkedin.com/search/results/content/?keywords=PHP&datePosted=%5B%22past-week%22%5D'],
]);

it('maps legacy operator aliases to the canonical enum value', function () {
    $user = User::factory()->create();

    $source = discoveryService()->create($user, [
        'name' => 'OR legado',
        'source' => 'linkedin',
        'surface' => 'linkedin_jobs',
        'frequency_minutes' => 60,
        'criteria' => [
            'keywords' => ['Laravel', 'PHP'],
            'operator' => 'any',
        ],
    ]);

    expect($source->criteria['operator'])->toBe('or')
        ->and(urldecode($source->url))->toContain('"Laravel" OR "PHP"');
});

it('merges partial criteria updates into the persisted criteria', function () {
    $user = User::factory()->create();

    $source = DiscoverySource::factory()->for($user, 'user')->create([
        'criteria' => [
            'keywords' => ['Laravel'],
            'excluded_terms' => ['Estágio'],
            'operator' => 'or',
            'seniority' => 'senior',
            'location' => 'São Paulo',
            'work_mode' => 'remote',
            'published_within_days' => 7,
            'easy_apply' => true,
        ],
    ]);

    $updated = discoveryService()->update($user, $source->id, 1, [
        'criteria' => ['keywords' => ['PHP']],
    ]);

    expect($updated->criteria['keywords'])->toBe(['PHP'])
        ->and($updated->criteria['excluded_terms'])->toBe(['Estágio'])
        ->and($updated->criteria['operator'])->toBe('or')
        ->and($updated->criteria['seniority'])->toBe('senior')
        ->and($updated->criteria['location'])->toBe('São Paulo')
        ->and($updated->criteria['work_mode'])->toBe('remote')
        ->and($updated->criteria['published_within_days'])->toBe(7)
        ->and($updated->criteria['easy_apply'])->toBeTrue();
});

it('schedules a reactivated source as due immediately', function () {
    $user = User::factory()->create();
    $source = DiscoverySource::factory()->for($user, 'user')->create(['active' => true]);

    $deactivated = discoveryService()->deactivate($user, $source->id, 1);

    expect($deactivated->active)->toBeFalse()
        ->and($deactivated->next_due_at)->toBeNull();

    $reactivated = discoveryService()->update($user, $source->id, $deactivated->version, ['active' => true]);

    expect($reactivated->active)->toBeTrue()
        ->and($reactivated->next_due_at)->not->toBeNull()
        ->and($reactivated->next_due_at->lessThanOrEqualTo(now()))->toBeTrue();
});

it('treats trailing slashes and default ports as the same canonical source', function () {
    $urls = app(DiscoverySourceUrl::class);

    expect($urls->canonical('https://www.linkedin.com:443/jobs/search/?keywords=PHP'))
        ->toBe($urls->canonical('https://www.linkedin.com/jobs/search?keywords=PHP'));

    $user = User::factory()->create();

    discoveryService()->create($user, [
        'name' => 'Sem barra',
        'source' => 'linkedin',
        'surface' => 'linkedin_jobs',
        'frequency_minutes' => 60,
        'url' => 'https://www.linkedin.com/jobs/search?keywords=PHP',
    ]);

    expect(fn () => discoveryService()->create($user, [
        'name' => 'Com barra',
        'source' => 'linkedin',
        'surface' => 'linkedin_jobs',
        'frequency_minutes' => 60,
        'url' => 'https://www.linkedin.com:443/jobs/search/?keywords=PHP',
    ]))->toThrow(ValidationException::class);
});

it('allows recreating the same canonical url after a soft delete', function () {
    $user = User::factory()->create();

    $created = discoveryService()->create($user, [
        'name' => 'Primeira',
        'source' => 'linkedin',
        'surface' => 'linkedin_jobs',
        'frequency_minutes' => 60,
        'url' => 'https://www.linkedin.com/jobs/search/?keywords=PHP',
    ]);

    $deleted = discoveryService()->delete($user, $created->id, $created->version);

    expect($deleted->canonical_url_hash)->toBeNull();

    $recreated = discoveryService()->create($user, [
        'name' => 'Recriada',
        'source' => 'linkedin',
        'surface' => 'linkedin_jobs',
        'frequency_minutes' => 60,
        'url' => 'https://www.linkedin.com/jobs/search/?keywords=PHP',
    ]);

    expect($recreated->name)->toBe('Recriada')
        ->and($recreated->id)->not->toBe($created->id)
        ->and(DiscoverySource::query()->count())->toBe(1);
});

it('reports unsupported platform combinations as criteria validation errors', function (string $sourceName, string $surface, array $criteria) {
    $user = User::factory()->create();

    expect(fn () => discoveryService()->create($user, [
        'name' => 'Combinação inválida',
        'source' => $sourceName,
        'surface' => $surface,
        'frequency_minutes' => 60,
        'criteria' => $criteria,
    ]))->toThrow(ValidationException::class);
})->with([
    'indeed híbrido' => ['indeed', 'indeed_jobs', ['keywords' => ['PHP'], 'work_mode' => 'hybrid']],
    'indeed easy apply' => ['indeed', 'indeed_jobs', ['keywords' => ['PHP'], 'easy_apply' => true]],
    'publicações 3 dias' => ['linkedin', 'linkedin_publications', ['keywords' => ['PHP'], 'published_within_days' => 3]],
]);

it('returns the requested slice for offsets that are not page aligned', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $index) {
        DiscoverySource::factory()->for($user, 'user')->create(['name' => 'Fonte '.$index]);
    }

    $page = discoveryService()->paginate($user, [], 2, 1);

    expect($page->total())->toBe(5)
        ->and($page->perPage())->toBe(2)
        ->and($page->items())->toHaveCount(2)
        ->and($page->items()[0]->name)->toBe('Fonte 4')
        ->and($page->items()[1]->name)->toBe('Fonte 3');
});
