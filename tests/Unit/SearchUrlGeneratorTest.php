<?php

use App\DTO\SearchCriteria;
use App\Enums\SearchMode;
use App\Enums\SearchPlatform;
use App\Enums\SearchSeniority;
use App\Services\Search\SearchUrlGenerator;

beforeEach(function () {
    $this->generator = new SearchUrlGenerator;
});

it('despacha LinkedIn preservando a forma dos parâmetros', function () {
    expect($this->generator->build(
        SearchPlatform::LinkedIn,
        SearchCriteria::fromInput(keywords: ['PHP'], seniority: SearchSeniority::Junior),
    ))->toBe('https://www.linkedin.com/jobs/search/?keywords=%22PHP%22&f_E=3');
});

it('despacha Indeed com a base e os parâmetros do fornecedor', function () {
    expect($this->generator->build(
        SearchPlatform::Indeed,
        SearchCriteria::fromInput(keywords: ['PHP'], publishedWithinDays: 7),
    ))->toBe('https://br.indeed.com/jobs?q=%22PHP%22&fromage=7');
});

it('despacha LinkedIn Publicações para o endpoint de conteúdo', function () {
    expect($this->generator->build(
        SearchPlatform::LinkedIn,
        SearchCriteria::fromInput(keywords: ['PHP'], publishedWithinDays: 7),
        SearchMode::Publications,
    ))->toBe('https://www.linkedin.com/search/results/content/?keywords=%22PHP%22&origin=FACETED_SEARCH&sortBy=%5B%22date_posted%22%5D&datePosted=%5B%22past-week%22%5D');
});

it('propaga o mesmo critério para qualquer plataforma sem estado de fornecedor na DTO', function () {
    $criteria = SearchCriteria::fromInput(keywords: ['PHP', 'Laravel']);

    $linkedin = $this->generator->build(SearchPlatform::LinkedIn, $criteria);
    $indeed = $this->generator->build(SearchPlatform::Indeed, $criteria);

    expect(str_starts_with($linkedin, 'https://www.linkedin.com/jobs/search/'))->toBeTrue()
        ->and(str_starts_with($indeed, 'https://br.indeed.com/jobs'))->toBeTrue();
});
