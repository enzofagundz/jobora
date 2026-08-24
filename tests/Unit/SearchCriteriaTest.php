<?php

use App\DTO\SearchCriteria;
use App\Enums\SearchOperator;
use App\Enums\SearchSeniority;
use App\Enums\SearchWorkMode;

it('normaliza trim, vazios e duplicados de palavras-chave e exclusões', function () {
    $criteria = SearchCriteria::fromInput(
        keywords: ['  PHP ', '', 'php', 'PHP', '   Laravel'],
        excludedTerms: ['Estágio', 'estágio', '', '  '],
    );

    expect($criteria->keywords)->toBe(['PHP', 'Laravel'])
        ->and($criteria->excludedTerms)->toBe(['Estágio']);
});

it('preserva termos literais com espaços e caracteres especiais', function () {
    $criteria = SearchCriteria::fromInput(
        keywords: ['C++', 'UI/UX', 'R&D', 'desenvolvedor php pleno'],
        excludedTerms: ['vaga "100% presencial"', 'café'],
    );

    expect($criteria->keywords)->toBe(['C++', 'UI/UX', 'R&D', 'desenvolvedor php pleno'])
        ->and($criteria->excludedTerms)->toBe(['vaga "100% presencial"', 'café']);
});

it('rejeita lista sem palavra-chave', function () {
    SearchCriteria::fromInput(keywords: ['   ']);
})->throws(InvalidArgumentException::class, 'Informe ao menos uma palavra-chave para a busca.');

it('aceita janela de publicação de 1, 3, 7, 14 ou 30 dias', function (?int $days) {
    SearchCriteria::fromInput(keywords: ['PHP'], publishedWithinDays: $days);
})->with([null, 1, 3, 7, 14, 30])->throwsNoExceptions();

it('rejeita janela de publicação inválida', function (int $days) {
    SearchCriteria::fromInput(keywords: ['PHP'], publishedWithinDays: $days);
})->with([0, 2, 31])->throws(InvalidArgumentException::class, 'A janela de publicação selecionada não é válida.');

it('normaliza localização vazia para null', function () {
    $criteria = SearchCriteria::fromInput(keywords: ['PHP'], location: '   ');

    expect($criteria->location)->toBeNull();
});

it('expõe as propriedades semânticas do critério', function () {
    $criteria = SearchCriteria::fromInput(
        keywords: ['PHP'],
        excludedTerms: ['estágio'],
        operator: SearchOperator::Any,
        seniority: SearchSeniority::MidLevel,
        location: 'São Paulo, Brasil',
        workMode: SearchWorkMode::Remote,
        publishedWithinDays: 7,
        easyApply: true,
    );

    expect($criteria->keywords)->toBe(['PHP'])
        ->and($criteria->excludedTerms)->toBe(['estágio'])
        ->and($criteria->operator)->toBe(SearchOperator::Any)
        ->and($criteria->seniority)->toBe(SearchSeniority::MidLevel)
        ->and($criteria->location)->toBe('São Paulo, Brasil')
        ->and($criteria->workMode)->toBe(SearchWorkMode::Remote)
        ->and($criteria->publishedWithinDays)->toBe(7)
        ->and($criteria->easyApply)->toBeTrue();
});

it('não conhece plataforma nem produz URL', function () {
    $reflection = new ReflectionClass(SearchCriteria::class);

    $properties = collect($reflection->getProperties())->map(fn (ReflectionProperty $p): string => $p->getName());

    expect($properties)->not->toContain('platform')
        ->and(method_exists(SearchCriteria::class, 'toUrl'))->toBeFalse()
        ->and(method_exists(SearchCriteria::class, 'toQuery'))->toBeFalse();
});
