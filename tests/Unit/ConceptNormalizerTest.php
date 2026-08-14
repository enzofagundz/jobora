<?php

use App\Services\ConceptNormalizer;

it('maps aliases to canonical names', function () {
    $normalizer = new ConceptNormalizer;

    expect($normalizer->normalize('postgres'))->toBe('PostgreSQL')
        ->and($normalizer->normalize('PostgreSQL'))->toBe('PostgreSQL')
        ->and($normalizer->normalize('pgsql'))->toBe('PostgreSQL')
        ->and($normalizer->normalize('Laravel Framework'))->toBe('Laravel')
        ->and($normalizer->normalize('laravel'))->toBe('Laravel')
        ->and($normalizer->normalize('Amazon Web Services'))->toBe('AWS')
        ->and($normalizer->normalize('aws'))->toBe('AWS');
});

it('trims, collapses whitespace and lowercases unknown concepts', function () {
    $normalizer = new ConceptNormalizer;

    expect($normalizer->normalize('  React   Native '))->toBe('react native')
        ->and($normalizer->normalize('Kotlin'))->toBe('kotlin');
});

it('returns empty string for blank names', function () {
    $normalizer = new ConceptNormalizer;

    expect($normalizer->normalize(''))->toBe('')
        ->and($normalizer->normalize('   '))->toBe('')
        ->and($normalizer->normalize("\t\n"))->toBe('');
});

it('exposes canonical names and keywords for resume matching', function () {
    $normalizer = new ConceptNormalizer;

    expect($normalizer->canonicalNames())->toContain('PostgreSQL', 'Laravel', 'AWS')
        ->and($normalizer->keywordsFor('PostgreSQL'))->toContain('postgres')
        ->and($normalizer->keywordsFor('AWS'))->toContain('aws', 'amazon web services');
});

it('returns no keywords for unknown canonical names', function () {
    $normalizer = new ConceptNormalizer;

    expect($normalizer->keywordsFor('Inexistente'))->toBe([]);
});
