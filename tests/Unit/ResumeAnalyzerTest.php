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

it('detects canonical concepts whose name contains a dot', function () {
    $content = 'Front-end em Vue.js e back-end em Node.js.';

    expect(analyzer()->conceptsIn($content))->toContain('Vue.js', 'Node.js');
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

it('detects concepts inside rich HTML content', function () {
    $content = '<p><strong>Experiência</strong> com <em>Laravel</em> e <u>PostgreSQL</u>.</p><ul><li>Docker</li></ul>';

    expect(analyzer()->conceptsIn($content))
        ->toContain('Laravel', 'PostgreSQL', 'Docker')
        ->not->toContain('Kubernetes');
});

it('does not match REST API on incidental substring rest', function () {
    $content = 'Prestação de serviços e interesse em vendas.';

    expect(analyzer()->conceptsIn($content))->not->toContain('REST API');
});

it('detects REST API by its multiword keyword', function () {
    $content = 'Experiência com REST API e integrações.';

    expect(analyzer()->conceptsIn($content))->toContain('REST API');
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

it('detects any item name present in the resume text', function () {
    $content = 'Stack: GitHub, CI/CD e Vue.js.';

    expect(analyzer()->contains($content, 'github'))->toBeTrue()
        ->and(analyzer()->contains($content, 'ci/cd'))->toBeTrue()
        ->and(analyzer()->contains($content, 'vue.js'))->toBeTrue()
        ->and(analyzer()->contains($content, 'gitlab'))->toBeFalse();
});

it('does not match partial words', function () {
    $content = 'JavaScript e MySQL.';

    expect(analyzer()->contains($content, 'java'))->toBeFalse()
        ->and(analyzer()->contains($content, 'sql'))->toBeFalse()
        ->and(analyzer()->contains('consolidado', 'solid'))->toBeFalse();
});

it('detects removal of a competence', function () {
    expect(analyzer()->contains('Aplico Clean Code.', 'clean code'))->toBeTrue()
        ->and(analyzer()->contains('Sem menção.', 'clean code'))->toBeFalse();
});

it('contains returns false for blank inputs', function () {
    expect(analyzer()->contains(null, 'x'))->toBeFalse()
        ->and(analyzer()->contains('', 'x'))->toBeFalse()
        ->and(analyzer()->contains('texto', ''))->toBeFalse();
});
