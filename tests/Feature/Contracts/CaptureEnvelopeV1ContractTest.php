<?php

use App\Services\Discovery\CaptureValidator;

const CAPTURE_ENVELOPE_FIXTURES = 'fixtures/capture-envelope/v1';
const CAPTURE_ENVELOPE_FORMAT = 'jobora.capture-envelope';
const CAPTURE_ENVELOPE_VERSION = 1;

function captureEnvelopePath(string $file): string
{
    return base_path(CAPTURE_ENVELOPE_FIXTURES.'/'.$file);
}

function captureEnvelopeJson(string $file): array
{
    $path = captureEnvelopePath($file);
    expect(is_file($path))->toBeTrue("Fixture ausente: {$path}");

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray();

    return $decoded;
}

function captureEnvelopeDocument(string $html): DOMDocument
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($loaded)->toBeTrue();

    return $dom;
}

function assertCaptureEnvelopeHtmlSafe(string $html): void
{
    foreach (['script', 'iframe', 'object', 'embed', 'form', 'input', 'textarea', 'select', 'button', 'style'] as $tag) {
        expect($html)->not->toMatch('/<'.$tag.'\b/i');
    }

    expect($html)->not->toMatch('/\son[a-z]+\s*=/i');
    expect($html)->not->toMatch('/\ssrcdoc\s*=/i');
    expect($html)->not->toMatch('/\sstyle\s*=/i');
    expect($html)->not->toMatch('/(?:javascript|vbscript|data:text\/html):/i');

    $xpath = new DOMXPath(captureEnvelopeDocument($html));

    foreach ($xpath->query('//@*') as $attribute) {
        expect(strtolower($attribute->nodeName))->not->toStartWith('on');

        $value = strtolower(trim((string) $attribute->nodeValue));
        expect($value)->not->toContain('javascript:');
        expect($value)->not->toContain('vbscript:');
        expect($value)->not->toContain('data:text/html');
    }
}

function assertCaptureEnvelopeBlocks(array $envelope): void
{
    $blocks = $envelope['blocks'];
    expect($blocks)->toBeArray()->not->toBeEmpty();

    $byId = [];
    foreach ($blocks as $block) {
        expect($block)->toHaveKeys(['block_id', 'kind', 'platform_id', 'permalink', 'card_block_id', 'detail_block_id']);
        expect($block['block_id'])->toMatch('/^[a-z0-9][a-z0-9-]{0,63}$/');
        expect($byId)->not->toHaveKey($block['block_id']);
        expect($block['platform_id'])->toBeString()->not->toBeEmpty();
        expect($block['permalink'])->toStartWith('https://');

        $byId[$block['block_id']] = $block;
    }

    foreach ($blocks as $block) {
        if ($block['kind'] === 'post') {
            expect($block['card_block_id'])->toBeNull();
            expect($block['detail_block_id'])->toBeNull();
        }

        if ($block['kind'] === 'detail') {
            expect($block['detail_block_id'])->toBeNull();
            $card = $byId[$block['card_block_id']] ?? null;
            expect($card)->not->toBeNull();
            expect($card['kind'])->toBe('card');
            expect($card['detail_block_id'])->toBe($block['block_id']);
            expect($card['platform_id'])->toBe($block['platform_id']);
            expect($card['permalink'])->toBe($block['permalink']);
        }

        if ($block['kind'] === 'card' && $block['detail_block_id'] !== null) {
            $detail = $byId[$block['detail_block_id']] ?? null;
            expect($detail)->not->toBeNull();
            expect($detail['kind'])->toBe('detail');
            expect($detail['card_block_id'])->toBe($block['block_id']);
            expect($detail['platform_id'])->toBe($block['platform_id']);
            expect($detail['permalink'])->toBe($block['permalink']);
        }
    }

    $xpath = new DOMXPath(captureEnvelopeDocument($envelope['capture']['html']));
    $root = $xpath->query('//*[@data-capture-surface]')->item(0);
    expect($root)->not->toBeNull();
    expect($root->getAttribute('data-capture-surface'))->toBe($envelope['capture']['surface']);
    expect($root->getAttribute('data-capture-version'))->toBe((string) CAPTURE_ENVELOPE_VERSION);

    $nodes = [];
    foreach ($xpath->query('//*[@data-block-id]') as $node) {
        $id = $node->getAttribute('data-block-id');
        expect($nodes)->not->toHaveKey($id);
        $nodes[$id] = $node;
    }

    $nodeIds = array_keys($nodes);
    $blockIds = array_keys($byId);
    sort($nodeIds);
    sort($blockIds);
    expect($nodeIds)->toBe($blockIds);

    foreach ($byId as $id => $block) {
        $node = $nodes[$id];
        expect($node->getAttribute('data-kind'))->toBe($block['kind']);
        expect($node->getAttribute('data-platform-id'))->toBe($block['platform_id']);
        expect($node->getAttribute('data-permalink'))->toBe($block['permalink']);

        if ($block['kind'] === 'post') {
            expect($node->hasAttribute('data-card-block-id'))->toBeFalse();
            expect($node->hasAttribute('data-detail-block-id'))->toBeFalse();
        }

        if ($block['kind'] === 'card' && $block['detail_block_id'] !== null) {
            expect($node->getAttribute('data-detail-block-id'))->toBe($block['detail_block_id']);
        }

        if ($block['kind'] === 'detail') {
            expect($node->getAttribute('data-card-block-id'))->toBe($block['card_block_id']);
        }
    }
}

it('publica o schema fechado do envelope v1', function () {
    $schema = captureEnvelopeJson('capture-envelope.v1.schema.json');

    expect($schema['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema');
    expect($schema['$id'])->toContain('/v1');
    expect($schema['properties']['format']['const'])->toBe(CAPTURE_ENVELOPE_FORMAT);
    expect($schema['properties']['version']['const'])->toBe(CAPTURE_ENVELOPE_VERSION);
    expect($schema['additionalProperties'])->toBeFalse();
    expect($schema['properties']['capture']['additionalProperties'])->toBeFalse();
    expect($schema['$defs']['block']['additionalProperties'])->toBeFalse();
    expect($schema['properties']['capture']['properties']['surface']['enum'])
        ->toBe(['linkedin_publications', 'linkedin_jobs', 'indeed_jobs']);
    expect($schema['$defs']['block']['properties']['kind']['enum'])
        ->toBe(['post', 'card', 'detail']);
    expect($schema['properties']['capture']['properties']['coverage']['properties']['reason']['enum'])
        ->toContain('cancelled', 'navigation_interrupted', 'timeout', 'no_content');
    expect($schema['description'])->toContain('platform_id may also be null for kind=card or kind=detail on surface=indeed_jobs')
        ->and($schema['$defs']['block']['required'])->toContain('platform_id', 'permalink')
        ->and($schema['$defs']['block']['properties']['platform_id']['type'])->toBe(['string', 'null'])
        ->and($schema['$defs']['block']['properties']['permalink']['type'])->toBe(['string', 'null'])
        ->and($schema['allOf'][0]['if']['properties']['capture']['properties']['surface']['const'])->toBe('linkedin_publications')
        ->and($schema['allOf'][0]['then']['properties']['blocks']['items']['if']['properties']['kind']['const'])->toBe('post')
        ->and($schema['allOf'][0]['then']['properties']['blocks']['items']['else']['properties']['platform_id']['type'])->toBe('string')
        ->and($schema['allOf'][0]['else']['properties']['blocks']['items']['properties']['permalink']['type'])->toBe('string');
});

it('permits anonymous Indeed cards and details without weakening other surfaces', function () {
    $schema = captureEnvelopeJson('capture-envelope.v1.schema.json');
    $jobSurface = $schema['allOf'][0]['else'];

    expect($jobSurface['if']['properties']['capture']['properties']['surface']['const'] ?? null)->toBe('indeed_jobs');
    expect($jobSurface['then']['properties']['blocks']['items']['if']['properties']['kind']['enum'])->toBe(['card', 'detail']);
    expect($jobSurface['then']['properties']['blocks']['items']['else']['properties']['platform_id']['type'])->toBe('string');
    expect($jobSurface['else']['properties']['blocks']['items']['properties']['platform_id']['type'])->toBe('string');

    $envelope = captureEnvelopeJson('indeed-jobs.json');
    foreach ($envelope['blocks'] as &$block) {
        $block['platform_id'] = null;
    }
    unset($block);
    $envelope['capture']['html'] = preg_replace('/ data-platform-id="[^"]*"/', '', $envelope['capture']['html']);

    app(CaptureValidator::class)->validate($envelope);
    expect($envelope['blocks'])->toHaveCount(2);
});

it('keeps existing v1 envelope fixtures valid for ingestion', function () {
    foreach ([
        'linkedin-publications.json',
        'linkedin-jobs.json',
        'indeed-jobs.json',
        'indeed-jobs-partial.json',
    ] as $file) {
        expect(captureEnvelopeJson($file)['version'])->toBe(CAPTURE_ENVELOPE_VERSION);
        app(CaptureValidator::class)->validate(captureEnvelopeJson($file));
    }
});

it('mantém manifesto com as três superfícies e cobertura parcial', function () {
    $manifest = captureEnvelopeJson('fixtures.json');

    expect($manifest['version'])->toBe(CAPTURE_ENVELOPE_VERSION);

    $files = array_column($manifest['fixtures'], 'file');
    foreach ([
        'linkedin-publications.json',
        'linkedin-jobs.json',
        'indeed-jobs.json',
        'indeed-jobs-partial.json',
    ] as $file) {
        expect($files)->toContain($file);
    }

    $coverages = array_column($manifest['fixtures'], 'coverage');
    expect($coverages)->toContain('complete');
    expect($coverages)->toContain('partial');
});

dataset('capture envelope fixtures', [
    'LinkedIn publicações' => ['linkedin-publications.json', 'linkedin', 'linkedin_publications', 'complete', 2],
    'LinkedIn vagas' => ['linkedin-jobs.json', 'linkedin', 'linkedin_jobs', 'complete', 4],
    'Indeed vagas' => ['indeed-jobs.json', 'indeed', 'indeed_jobs', 'complete', 2],
    'Indeed parcial' => ['indeed-jobs-partial.json', 'indeed', 'indeed_jobs', 'partial', 3],
]);

it('representa a captura consolidada sem HTML executável', function (
    string $file,
    string $source,
    string $surface,
    string $coverage,
    int $blockCount,
) {
    $envelope = captureEnvelopeJson($file);

    expect($envelope['format'])->toBe(CAPTURE_ENVELOPE_FORMAT);
    expect($envelope['version'])->toBe(CAPTURE_ENVELOPE_VERSION);
    expect($envelope['capture'])->toHaveKeys([
        'idempotency_key',
        'execution_id',
        'source',
        'surface',
        'initial_url',
        'final_url',
        'captured_at',
        'coverage',
        'html',
    ]);
    expect($envelope['capture']['source'])->toBe($source);
    expect($envelope['capture']['surface'])->toBe($surface);
    expect($envelope['capture']['idempotency_key'])->toBeString()->not->toBeEmpty();
    expect($envelope['capture']['execution_id'])->toBeString()->not->toBeEmpty();
    expect($envelope['capture']['initial_url'])->toStartWith('https://');
    expect($envelope['capture']['final_url'])->toStartWith('https://');
    expect(strtotime($envelope['capture']['captured_at']))->toBeGreaterThan(0);

    expect($envelope['capture']['coverage']['status'])->toBe($coverage);
    expect($envelope['capture']['coverage']['blocks_captured'])->toBe(count($envelope['blocks']));
    expect($envelope['blocks'])->toHaveCount($blockCount);

    if ($coverage === 'complete') {
        expect($envelope['capture']['coverage']['reason'])->toBeNull();
    } else {
        expect($envelope['capture']['coverage']['reason'])->toBeString()->not->toBeEmpty();
    }

    $cardsWithoutDetail = array_filter(
        $envelope['blocks'],
        fn (array $block): bool => $block['kind'] === 'card' && $block['detail_block_id'] === null,
    );

    if ($coverage === 'partial') {
        expect($cardsWithoutDetail)->not->toBeEmpty();
    } else {
        expect($cardsWithoutDetail)->toBeEmpty();
    }

    if ($envelope['capture']['coverage']['blocks_expected'] !== null) {
        expect($envelope['capture']['coverage']['blocks_expected'])
            ->toBeGreaterThanOrEqual(count($envelope['blocks']));
    }

    expect($envelope['capture']['html'])->toContain('Exemplo');
    expect($envelope['capture']['html'])->not->toMatch('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i');

    assertCaptureEnvelopeHtmlSafe($envelope['capture']['html']);
    assertCaptureEnvelopeBlocks($envelope);
})->with('capture envelope fixtures');
