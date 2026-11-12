<?php

use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\OpportunitySource;
use App\Models\PublicationObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('cria uma execução padrão com dono e fonte coerentes', function () {
    $run = DiscoveryRun::factory()->create();
    $source = $run->source;

    expect($source)->not->toBeNull()
        ->and($run->user_id)->toBe($source->user_id)
        ->and($run->canonical_url_hash)->toBe($source->canonical_url_hash)
        ->and($run->initial_url)->toBe($source->url)
        ->and($run->surface)->toBe($source->surface);
});

it('deriva os campos da fonte informada na execução', function () {
    $source = DiscoverySource::factory()->create();

    $run = DiscoveryRun::factory()->create([
        'user_id' => $source->user_id,
        'discovery_source_id' => $source->id,
    ]);

    expect($run->canonical_url_hash)->toBe($source->canonical_url_hash)
        ->and($run->initial_url)->toBe($source->url)
        ->and($run->surface)->toBe($source->surface);
});

it('cria origem de oportunidade com dono compartilhado', function () {
    $source = OpportunitySource::factory()->create();

    expect($source->jobPosting->user_id)->toBe($source->publication->user_id);
});

it('cria observação com captura e publicação do mesmo dono', function () {
    $observation = PublicationObservation::factory()->create();

    expect($observation->pageCapture->user_id)->toBe($observation->publication->user_id);
});
