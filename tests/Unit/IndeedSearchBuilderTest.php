<?php

use App\DTO\SearchCriteria;
use App\Enums\SearchOperator;
use App\Enums\SearchSeniority;
use App\Enums\SearchWorkMode;
use App\Services\Search\IndeedSearchBuilder;

function indeedUrl(SearchCriteria $criteria): string
{
    return (new IndeedSearchBuilder)->build($criteria);
}

it('monta q com AND entre termos, inclusive frases', function () {
    expect(indeedUrl(SearchCriteria::fromInput(keywords: ['PHP', 'desenvolvedor full stack'])))
        ->toBe('https://br.indeed.com/jobs?q=%22PHP%22%20AND%20%22desenvolvedor%20full%20stack%22');
});

it('agrupa OR em parênteses', function () {
    expect(indeedUrl(SearchCriteria::fromInput(
        keywords: ['PHP', 'Laravel', 'Vue'],
        operator: SearchOperator::Any,
    )))->toBe('https://br.indeed.com/jobs?q=%28%22PHP%22%20OR%20%22Laravel%22%20OR%20%22Vue%22%29');
});

it('anexa exclusões manuais como -"termo"', function () {
    expect(indeedUrl(SearchCriteria::fromInput(
        keywords: ['PHP'],
        excludedTerms: ['estágio', 'wordpress'],
    )))->toBe('https://br.indeed.com/jobs?q=%22PHP%22%20-%22est%C3%A1gio%22%20-%22wordpress%22');
});

it('deduplica exclusões automáticas de senioridade contra as manuais sem diferenciar maiúsculas', function () {
    $url = indeedUrl(SearchCriteria::fromInput(
        keywords: ['PHP'],
        excludedTerms: ['pleno', 'SR'],
        seniority: SearchSeniority::Junior,
    ));

    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    // "Pleno" automática cai por duplicar "pleno" manual; "SR" manual vence.
    expect($query['q'])->toBe('"PHP" -"pleno" -"SR" -"Estágio" -"Estagio" -"Trainee" -"Sênior" -"Senior" -"Especialista" -"Executivo" -"Coordenador" -"Gerente" -"Diretor" -"PL"');
});

it('adiciona localização e janela de publicação', function () {
    $url = indeedUrl(SearchCriteria::fromInput(
        keywords: ['PHP'],
        location: 'São Paulo',
        publishedWithinDays: 7,
    ));

    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    expect($query)->toHaveKey('l', 'São Paulo')
        ->and($query)->toHaveKey('fromage', '7');
});

it('adiciona termo remoto e sc para modalidade remota', function () {
    $url = indeedUrl(SearchCriteria::fromInput(
        keywords: ['PHP'],
        workMode: SearchWorkMode::Remote,
    ));

    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    expect($query['q'])->toBe('"PHP" "remoto"')
        ->and($query)->toHaveKey('sc', '0kf:attr(DSGMC);');
});

it('omite parâmetros opcionais quando não usados', function () {
    expect(indeedUrl(SearchCriteria::fromInput(keywords: ['PHP'])))
        ->toBe('https://br.indeed.com/jobs?q=%22PHP%22');
});

it('codifica termos e localização acentuados', function () {
    expect(indeedUrl(SearchCriteria::fromInput(
        keywords: ['Vagas de TI'],
        location: 'São Paulo, Brasil',
    )))->toBe('https://br.indeed.com/jobs?q=%22Vagas%20de%20TI%22&l=S%C3%A3o%20Paulo%2C%20Brasil');
});

it('rejeita modalidades sem filtro estável no Indeed', function (SearchWorkMode $workMode) {
    indeedUrl(SearchCriteria::fromInput(keywords: ['PHP'], workMode: $workMode));
})->with([SearchWorkMode::Onsite, SearchWorkMode::Hybrid])
    ->throws(InvalidArgumentException::class, 'O filtro de modalidade selecionado não é suportado pelo Indeed.');

it('rejeita easy apply no Indeed', function () {
    indeedUrl(SearchCriteria::fromInput(keywords: ['PHP'], easyApply: true));
})->throws(InvalidArgumentException::class, 'O filtro Easy Apply é suportado apenas pelo LinkedIn.');
