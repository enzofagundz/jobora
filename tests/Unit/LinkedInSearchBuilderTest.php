<?php

use App\DTO\SearchCriteria;
use App\Enums\SearchOperator;
use App\Enums\SearchSeniority;
use App\Enums\SearchWorkMode;
use App\Services\Search\LinkedInSearchBuilder;

function linkedInUrl(SearchCriteria $criteria): string
{
    return (new LinkedInSearchBuilder)->build($criteria);
}

function linkedInPublicationsUrl(SearchCriteria $criteria): string
{
    return (new LinkedInSearchBuilder)->buildPublications($criteria);
}

it('monta keywords com AND e omite filtros opcionais', function () {
    expect(linkedInUrl(SearchCriteria::fromInput(keywords: ['PHP', 'Laravel'])))
        ->toBe('https://www.linkedin.com/jobs/search/?keywords=%22PHP%22%20AND%20%22Laravel%22');
});

it('combina múltiplos termos e frases com OR', function () {
    expect(linkedInUrl(SearchCriteria::fromInput(
        keywords: ['PHP', 'desenvolvedor full stack'],
        operator: SearchOperator::Any,
    )))->toBe('https://www.linkedin.com/jobs/search/?keywords=%22PHP%22%20OR%20%22desenvolvedor%20full%20stack%22');
});

it('anexa exclusões com NOT e codifica espaços, acentos e caracteres especiais', function () {
    expect(linkedInUrl(SearchCriteria::fromInput(
        keywords: ['PHP'],
        excludedTerms: ['estágio', 'C++'],
    )))->toBe('https://www.linkedin.com/jobs/search/?keywords=%22PHP%22%20NOT%20%22est%C3%A1gio%22%20NOT%20%22C%2B%2B%22');
});

it('converte senioridade em f_E sem distinguir Pleno de Sênior', function (SearchSeniority $seniority, string $fE) {
    $url = linkedInUrl(SearchCriteria::fromInput(keywords: ['PHP'], seniority: $seniority));

    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    expect($query)->toHaveKey('f_E', $fE);
})->with([
    'estágio' => [SearchSeniority::Internship, '1'],
    'júnior' => [SearchSeniority::Junior, '3'],
    'pleno' => [SearchSeniority::MidLevel, '4'],
    'sênior' => [SearchSeniority::Senior, '4'],
]);

it('converte modalidade em f_WT e omiti quando qualquer', function (SearchWorkMode $workMode, ?string $fWT) {
    $url = linkedInUrl(SearchCriteria::fromInput(keywords: ['PHP'], workMode: $workMode));

    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    if ($fWT === null) {
        expect($query)->not->toHaveKey('f_WT');
    } else {
        expect($query)->toHaveKey('f_WT', $fWT);
    }
})->with([
    'qualquer' => [SearchWorkMode::Any, null],
    'presencial' => [SearchWorkMode::Onsite, '1'],
    'remoto' => [SearchWorkMode::Remote, '2'],
    'híbrido' => [SearchWorkMode::Hybrid, '3'],
]);

it('gera a URL completa determinística com todos os filtros', function () {
    expect(linkedInUrl(SearchCriteria::fromInput(
        keywords: ['PHP', 'Laravel'],
        excludedTerms: ['estágio', 'sênior'],
        seniority: SearchSeniority::MidLevel,
        location: 'Brasil',
        workMode: SearchWorkMode::Remote,
        publishedWithinDays: 7,
        easyApply: true,
    )))->toBe(
        'https://www.linkedin.com/jobs/search/?keywords=%22PHP%22%20AND%20%22Laravel%22%20NOT%20%22est%C3%A1gio%22%20NOT%20%22s%C3%AAnior%22&location=Brasil&f_TPR=r604800&f_WT=2&f_E=4&f_AL=true',
    );
});

it('usa janela de publicação em segundos com prefixo r', function (int $days, string $fTPR) {
    $url = linkedInUrl(SearchCriteria::fromInput(keywords: ['PHP'], publishedWithinDays: $days));

    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    expect($query)->toHaveKey('f_TPR', $fTPR);
})->with([
    '24 horas' => [1, 'r86400'],
    '3 dias' => [3, 'r259200'],
    '7 dias' => [7, 'r604800'],
    '14 dias' => [14, 'r1209600'],
]);

it('monta a URL de publicações com keywords, origem e ordenação por data', function () {
    expect(linkedInPublicationsUrl(SearchCriteria::fromInput(keywords: ['PHP', 'Laravel'], excludedTerms: ['WordPress'])))
        ->toBe('https://www.linkedin.com/search/results/content/?keywords=%22PHP%22%20AND%20%22Laravel%22%20NOT%20%22WordPress%22&origin=FACETED_SEARCH&sortBy=%5B%22date_posted%22%5D');
});

it('combina termos de publicações com OR', function () {
    expect(linkedInPublicationsUrl(SearchCriteria::fromInput(
        keywords: ['PHP', 'desenvolvedor full stack'],
        operator: SearchOperator::Any,
    )))->toBe('https://www.linkedin.com/search/results/content/?keywords=%22PHP%22%20OR%20%22desenvolvedor%20full%20stack%22&origin=FACETED_SEARCH&sortBy=%5B%22date_posted%22%5D');
});

it('converte janela de publicação em datePosted nas publicações', function (int $days, string $datePosted) {
    $url = linkedInPublicationsUrl(SearchCriteria::fromInput(keywords: ['PHP'], publishedWithinDays: $days));

    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    expect($query)->toHaveKey('datePosted', $datePosted);
})->with([
    '24 horas' => [1, '["past-24h"]'],
    '7 dias' => [7, '["past-week"]'],
    '30 dias' => [30, '["past-month"]'],
]);

it('omite datePosted quando qualquer período nas publicações', function () {
    $url = linkedInPublicationsUrl(SearchCriteria::fromInput(keywords: ['PHP']));

    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    expect($query)->not->toHaveKey('datePosted')
        ->and($url)->toContain('origin=FACETED_SEARCH');
});

it('nunca emite parâmetros da aba vagas em publicações', function () {
    $url = linkedInPublicationsUrl(SearchCriteria::fromInput(
        keywords: ['PHP'],
        seniority: SearchSeniority::MidLevel,
        location: 'Brasil',
        workMode: SearchWorkMode::Remote,
        publishedWithinDays: 7,
        easyApply: true,
    ));

    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    expect($url)->toStartWith('https://www.linkedin.com/search/results/content/')
        ->and($query)->not->toHaveKey('location')
        ->and($query)->not->toHaveKey('f_TPR')
        ->and($query)->not->toHaveKey('f_WT')
        ->and($query)->not->toHaveKey('f_E')
        ->and($query)->not->toHaveKey('f_AL');
});

it('rejeita janelas não suportadas em publicações', function (int $days) {
    linkedInPublicationsUrl(SearchCriteria::fromInput(keywords: ['PHP'], publishedWithinDays: $days));
})->with([3, 14])->throws(InvalidArgumentException::class, 'A janela de publicação selecionada não é suportada para buscas em publicações.');
