<?php

namespace App\Services\Search;

use App\DTO\SearchCriteria;
use App\Enums\SearchOperator;
use App\Enums\SearchSeniority;
use App\Enums\SearchWorkMode;
use InvalidArgumentException;

/**
 * Monta a URL de busca de vagas do LinkedIn a partir do critério comum.
 *
 * Parâmetros nativos (f_TPR, f_WT, f_E, f_AL) vivem somente aqui;
 * mudanças da plataforma alteram apenas este arquivo e seus testes.
 */
final class LinkedInSearchBuilder implements SearchBuilder
{
    private const BASE_URL = 'https://www.linkedin.com/jobs/search/';

    private const PUBLICATIONS_BASE_URL = 'https://www.linkedin.com/search/results/content/';

    /** Janelas de publicação aceitas na busca de conteúdo, em dias. */
    private const PUBLICATION_DATE_POSTED = [
        1 => '["past-24h"]',
        7 => '["past-week"]',
        30 => '["past-month"]',
    ];

    private const WORK_MODE_PARAMETERS = [
        SearchWorkMode::Onsite->value => '1',
        SearchWorkMode::Remote->value => '2',
        SearchWorkMode::Hybrid->value => '3',
    ];

    /** A plataforma não distingue Pleno/Sênior neste filtro. */
    private const SENIORITY_EXPERIENCE_PARAMETERS = [
        SearchSeniority::Internship->value => '1',
        SearchSeniority::Junior->value => '3',
        SearchSeniority::MidLevel->value => '4',
        SearchSeniority::Senior->value => '4',
    ];

    public function build(SearchCriteria $criteria): string
    {
        $parameters['keywords'] = $this->keywordExpression($criteria);

        if ($criteria->location !== null) {
            $parameters['location'] = $criteria->location;
        }

        if ($criteria->publishedWithinDays !== null) {
            $parameters['f_TPR'] = 'r'.($criteria->publishedWithinDays * 86400);
        }

        if ($criteria->workMode !== SearchWorkMode::Any) {
            $parameters['f_WT'] = self::WORK_MODE_PARAMETERS[$criteria->workMode->value];
        }

        if ($criteria->seniority !== null) {
            $parameters['f_E'] = self::SENIORITY_EXPERIENCE_PARAMETERS[$criteria->seniority->value];
        }

        if ($criteria->easyApply) {
            $parameters['f_AL'] = 'true';
        }

        return self::BASE_URL.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Monta a URL de busca de conteúdo (publicações) do LinkedIn.
     *
     * Usa apenas os parâmetros do endpoint /search/results/content/;
     * filtros exclusivos da aba de vagas (location, f_TPR, f_WT, f_E,
     * f_AL) nunca são emitidos aqui.
     */
    public function buildPublications(SearchCriteria $criteria): string
    {
        if ($criteria->publishedWithinDays !== null && ! isset(self::PUBLICATION_DATE_POSTED[$criteria->publishedWithinDays])) {
            throw new InvalidArgumentException('A janela de publicação selecionada não é suportada para buscas em publicações.');
        }

        $parameters['keywords'] = $this->keywordExpression($criteria);
        $parameters['origin'] = 'FACETED_SEARCH';
        $parameters['sortBy'] = '["date_posted"]';

        if ($criteria->publishedWithinDays !== null) {
            $parameters['datePosted'] = self::PUBLICATION_DATE_POSTED[$criteria->publishedWithinDays];
        }

        return self::PUBLICATIONS_BASE_URL.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Expressão booleana compartilhada: termos entre aspas unidos por
     * AND/OR conforme o operador, com exclusões anexadas como NOT.
     */
    private function keywordExpression(SearchCriteria $criteria): string
    {
        $glue = $criteria->operator === SearchOperator::Any ? ' OR ' : ' AND ';

        $included = array_map(
            fn (string $term): string => '"'.$this->escapeTerm($term).'"',
            $criteria->keywords,
        );

        $expression = implode($glue, $included);

        foreach ($criteria->excludedTerms as $term) {
            $expression .= ' NOT "'.$this->escapeTerm($term).'"';
        }

        return $expression;
    }

    /**
     * Escapa barra invertida e aspas duplas para não quebrarem a frase
     * entre aspas da query.
     */
    private function escapeTerm(string $term): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\"'], $term);
    }
}
