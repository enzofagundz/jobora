<?php

use App\Services\ConceptNormalizer;
use App\Services\ResumeAnalyzer;

function analyzer(): ResumeAnalyzer
{
    return new ResumeAnalyzer(new ConceptNormalizer);
}

it('detects concepts by keyword substring', function () {
    $content = 'Experiência com Laravel e PostgreSQL. Atuei com Docker no dia a dia.';

    expect(analyzer()->conceptsIn($content))
        ->toContain('Laravel', 'PostgreSQL', 'Docker')
        ->not->toContain('Kubernetes');
});

it('matches multiword keywords', function () {
    $content = 'Migração de infraestrutura para Google Cloud Platform.';

    expect(analyzer()->conceptsIn($content))->toContain('Google Cloud');
});

it('is case and whitespace insensitive', function () {
    $content = "CURRÍCULO:\n\nExperiência com  postgres   e A W S.";

    $concepts = analyzer()->conceptsIn($content);

    expect($concepts)->toContain('PostgreSQL')
        ->and($concepts)->not->toContain('AWS');
});

it('returns an empty set for a blank resume', function () {
    expect(analyzer()->conceptsIn(null))->toBe([])
        ->and(analyzer()->conceptsIn(''))->toBe([]);
});

it('checks a single concept presence', function () {
    $content = 'Stack principal: Laravel 11.';

    expect(analyzer()->hasConcept($content, 'Laravel'))->toBeTrue()
        ->and(analyzer()->hasConcept($content, 'React'))->toBeFalse();
});
