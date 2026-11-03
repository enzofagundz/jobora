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

it('trims, collapses whitespace and Title Cases unknown concepts', function () {
    $normalizer = new ConceptNormalizer;

    expect($normalizer->normalize('  React   Native '))->toBe('React Native')
        ->and($normalizer->normalize('Kotlin'))->toBe('Kotlin')
        ->and($normalizer->normalize('kafka'))->toBe('Kafka');
});

it('maps REST API variants to the canonical English form', function () {
    $normalizer = new ConceptNormalizer;

    expect($normalizer->normalize('api rest'))->toBe('REST API')
        ->and($normalizer->normalize('rest api'))->toBe('REST API')
        ->and($normalizer->normalize('REST API'))->toBe('REST API')
        ->and($normalizer->normalize('rest'))->toBe('REST API');
});

it('keeps canonical casing for seeded infrastructure acronyms', function () {
    $normalizer = new ConceptNormalizer;

    expect($normalizer->normalize('ci/cd'))->toBe('CI/CD')
        ->and($normalizer->normalize('graphql'))->toBe('GraphQL');
});

it('returns null owner for unknown or invalid categories', function () {
    $normalizer = new ConceptNormalizer;

    expect($normalizer->ownerCategory('Inexistente'))->toBeNull();

    config()->set('concepts.Bogus', ['category' => 'bogus']);

    expect($normalizer->ownerCategory('Bogus'))->toBeNull();
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

it('resolves keywords and categories for canonical names containing a dot', function () {
    $normalizer = new ConceptNormalizer;

    expect($normalizer->keywordsFor('Vue.js'))->toContain('vue.js')
        ->and($normalizer->keywordsFor('Node.js'))->toContain('node.js')
        ->and($normalizer->ownerCategory('Vue.js'))->toBe('skill')
        ->and($normalizer->ownerCategory('Node.js'))->toBe('skill');
});
