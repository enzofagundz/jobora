<?php

use App\Models\DiscoverySource;
use App\Models\User;
use App\Services\CaptureTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * @return list<array<string, mixed>>
 */
function laneBSanitizerBlocks(): array
{
    return [
        [
            'block_id' => 'card-1',
            'kind' => 'card',
            'platform_id' => '9001',
            'permalink' => 'https://www.linkedin.com/jobs/view/9001',
            'card_block_id' => null,
            'detail_block_id' => 'detail-1',
        ],
        [
            'block_id' => 'detail-1',
            'kind' => 'detail',
            'platform_id' => '9001',
            'permalink' => 'https://www.linkedin.com/jobs/view/9001',
            'card_block_id' => 'card-1',
            'detail_block_id' => null,
        ],
    ];
}

function laneBSanitizerHtml(?string $surface = 'linkedin_jobs', ?string $version = '1', ?string $inner = null): string
{
    $inner ??= '<article data-block-id="card-1" data-kind="card" data-platform-id="9001" data-permalink="https://www.linkedin.com/jobs/view/9001" data-detail-block-id="detail-1">Vaga</article>'
        .'<section data-block-id="detail-1" data-kind="detail" data-platform-id="9001" data-permalink="https://www.linkedin.com/jobs/view/9001" data-card-block-id="card-1">Descrição</section>';

    $attributes = '';

    if ($surface !== null) {
        $attributes .= ' data-capture-surface="'.$surface.'"';
    }

    if ($version !== null) {
        $attributes .= ' data-capture-version="'.$version.'"';
    }

    return '<main'.$attributes.'>'.$inner.'</main>';
}

function laneBSanitizerPost(User $user, string $html, ?array $blocks = null): TestResponse
{
    $source = DiscoverySource::factory()->for($user, 'user')->create();
    $blocks ??= laneBSanitizerBlocks();

    return test()->withToken(app(CaptureTokenService::class)->issueToken($user))->postJson('/api/page-captures', [
        'format' => 'jobora.capture-envelope',
        'version' => 1,
        'capture' => [
            'idempotency_key' => 'sanitizer-'.uniqid(),
            'execution_id' => null,
            'source' => $source->source,
            'surface' => $source->surface,
            'initial_url' => $source->url,
            'final_url' => $source->url,
            'captured_at' => now()->toIso8601String(),
            'coverage' => [
                'status' => 'complete',
                'reason' => null,
                'blocks_expected' => count($blocks),
                'blocks_captured' => count($blocks),
            ],
            'html' => $html,
        ],
        'blocks' => $blocks,
    ]);
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('accepts a valid root and block relations', function () {
    $user = User::factory()->create();

    laneBSanitizerPost($user, laneBSanitizerHtml())->assertStatus(201);
});

it('rejects missing or divergent capture root attributes', function () {
    $user = User::factory()->create();

    laneBSanitizerPost($user, laneBSanitizerHtml(surface: null, version: null))
        ->assertStatus(422)
        ->assertJsonPath('message', 'HTML exige exatamente uma raiz com data-capture-surface e data-capture-version.');

    laneBSanitizerPost($user, laneBSanitizerHtml(surface: 'indeed_jobs'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'data-capture-surface do HTML diverge do envelope.');

    laneBSanitizerPost($user, laneBSanitizerHtml(version: '2'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'data-capture-version do HTML diverge do envelope.');
});

it('rejects more than one capture root', function () {
    $user = User::factory()->create();

    $html = laneBSanitizerHtml(inner: '<article data-block-id="card-1" data-kind="card" data-platform-id="9001" data-permalink="https://www.linkedin.com/jobs/view/9001" data-detail-block-id="detail-1">Vaga</article>'
        .'<section data-block-id="detail-1" data-kind="detail" data-platform-id="9001" data-permalink="https://www.linkedin.com/jobs/view/9001" data-card-block-id="card-1">Descrição</section>'
        .'<div data-capture-surface="linkedin_jobs" data-capture-version="1"></div>');

    laneBSanitizerPost($user, $html)
        ->assertStatus(422)
        ->assertJsonPath('message', 'HTML exige exatamente uma raiz com data-capture-surface e data-capture-version.');
});

it('rejects blocks outside the capture root', function () {
    $user = User::factory()->create();

    $html = laneBSanitizerHtml(
        inner: '<article data-block-id="card-1" data-kind="card" data-platform-id="9001" data-permalink="https://www.linkedin.com/jobs/view/9001" data-detail-block-id="detail-1">Vaga</article>',
    ).'<section data-block-id="detail-1" data-kind="detail" data-platform-id="9001" data-permalink="https://www.linkedin.com/jobs/view/9001" data-card-block-id="card-1">Descrição</section>';

    laneBSanitizerPost($user, $html)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Bloco do HTML está fora da raiz da captura.');
});

it('rejects extra block ids outside the root', function () {
    $user = User::factory()->create();

    $html = laneBSanitizerHtml().'<article data-block-id="extra-1" data-kind="post" data-platform-id="x" data-permalink="https://www.linkedin.com/feed/update/1">Extra</article>';

    laneBSanitizerPost($user, $html)
        ->assertStatus(422);
});

it('rejects card and detail relation attributes diverging from the envelope', function () {
    $user = User::factory()->create();

    $cardWithoutRelation = '<article data-block-id="card-1" data-kind="card" data-platform-id="9001" data-permalink="https://www.linkedin.com/jobs/view/9001">Vaga</article>'
        .'<section data-block-id="detail-1" data-kind="detail" data-platform-id="9001" data-permalink="https://www.linkedin.com/jobs/view/9001" data-card-block-id="card-1">Descrição</section>';

    laneBSanitizerPost($user, laneBSanitizerHtml(inner: $cardWithoutRelation))
        ->assertStatus(422)
        ->assertJsonPath('message', 'data-detail-block-id do HTML diverge do envelope.');

    $detailWithoutRelation = '<article data-block-id="card-1" data-kind="card" data-platform-id="9001" data-permalink="https://www.linkedin.com/jobs/view/9001" data-detail-block-id="detail-1">Vaga</article>'
        .'<section data-block-id="detail-1" data-kind="detail" data-platform-id="9001" data-permalink="https://www.linkedin.com/jobs/view/9001">Descrição</section>';

    laneBSanitizerPost($user, laneBSanitizerHtml(inner: $detailWithoutRelation))
        ->assertStatus(422)
        ->assertJsonPath('message', 'data-card-block-id do HTML diverge do envelope.');
});

it('rejects relation attributes on post blocks', function () {
    $user = User::factory()->create();

    $blocks = [[
        'block_id' => 'post-1',
        'kind' => 'post',
        'platform_id' => '9002',
        'permalink' => 'https://www.linkedin.com/feed/update/9002',
        'card_block_id' => null,
        'detail_block_id' => null,
    ]];

    $html = laneBSanitizerHtml(
        inner: '<article data-block-id="post-1" data-kind="post" data-platform-id="9002" data-permalink="https://www.linkedin.com/feed/update/9002" data-card-block-id="card-1">Post</article>',
    );

    laneBSanitizerPost($user, $html, $blocks)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Bloco post não pode ter relação no HTML.');
});
