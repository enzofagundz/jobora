<?php

namespace App\Services\Search;

use App\DTO\SearchCriteria;
use App\Enums\SearchOperator;
use App\Enums\SearchSeniority;
use App\Enums\SearchWorkMode;
use InvalidArgumentException;

/**
 * Monta a URL de busca de vagas do Indeed (pt-BR) a partir do critério comum.
 *
 * O Indeed não tem filtros nativos de senioridade ou modalidade além do
 * remoto: senioridade é aproximada com exclusões automáticas no `q` e
 * modalidades não suportadas lançam erro explícito em vez de gerar uma
 * URL que pareça correta.
 */
final class IndeedSearchBuilder implements SearchBuilder
{
    private const BASE_URL = 'https://br.indeed.com/jobs';

    private const REMOTE_SC_PARAMETER = '0kf:attr(DSGMC);';

    /**
     * Exclusões automáticas por senioridade, aproximando o filtro nativo
     * que o Indeed não oferece. Deduplicadas contra as exclusões manuais,
     * sem diferenciar maiúsculas/minúsculas, preservando a ordem.
     */
    private const SENIORITY_EXCLUSIONS = [
        SearchSeniority::Internship->value => [
            'Júnior', 'Junior', 'Pleno', 'Sênior', 'Senior', 'Especialista',
            'Executivo', 'Coordenador', 'Gerente', 'Diretor', 'JR', 'PL', 'SR',
        ],
        SearchSeniority::Junior->value => [
            'Estágio', 'Estagio', 'Trainee', 'Pleno', 'Sênior', 'Senior',
            'Especialista', 'Executivo', 'Coordenador', 'Gerente', 'Diretor', 'PL', 'SR',
        ],
        SearchSeniority::MidLevel->value => [
            'Estágio', 'Estagio', 'Trainee', 'Júnior', 'Junior', 'Sênior', 'Senior',
            'Especialista', 'Executivo', 'Coordenador', 'Gerente', 'Diretor', 'JR', 'SR',
        ],
        SearchSeniority::Senior->value => [
            'Estágio', 'Estagio', 'Trainee', 'Júnior', 'Junior', 'Pleno',
            'Coordenador', 'Gerente', 'Diretor', 'JR', 'PL',
        ],
    ];

    public function build(SearchCriteria $criteria): string
    {
        if ($criteria->workMode === SearchWorkMode::Onsite || $criteria->workMode === SearchWorkMode::Hybrid) {
            throw new InvalidArgumentException('O filtro de modalidade selecionado não é suportado pelo Indeed.');
        }

        if ($criteria->easyApply) {
            throw new InvalidArgumentException('O filtro Easy Apply é suportado apenas pelo LinkedIn.');
        }

        $queryTerms = $this->quoted($criteria->keywords);

        $glue = match ($criteria->operator) {
            SearchOperator::Any => null,
            SearchOperator::All => ' AND ',
        };

        $query = count($queryTerms) > 1
            ? ($glue === null ? '('.implode(' OR ', $queryTerms).')' : implode($glue, $queryTerms))
            : $queryTerms[0];

        foreach ($this->exclusionsFor($criteria) as $term) {
            $query .= ' -"'.$term.'"';
        }

        if ($criteria->workMode === SearchWorkMode::Remote) {
            $query .= ' "remoto"';
        }

        $parameters['q'] = $query;

        if ($criteria->location !== null) {
            $parameters['l'] = $criteria->location;
        }

        if ($criteria->publishedWithinDays !== null) {
            $parameters['fromage'] = (string) $criteria->publishedWithinDays;
        }

        if ($criteria->workMode === SearchWorkMode::Remote) {
            $parameters['sc'] = self::REMOTE_SC_PARAMETER;
        }

        return self::BASE_URL.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exclusões manuais seguidas das automáticas de senioridade, deduplicadas
     * sem diferenciar maiúsculas/minúsculas e preservando a ordem.
     *
     * @return list<string>
     */
    private function exclusionsFor(SearchCriteria $criteria): array
    {
        $automatic = $criteria->seniority === null
            ? []
            : self::SENIORITY_EXCLUSIONS[$criteria->seniority->value];

        $result = [];

        foreach ([...$criteria->excludedTerms, ...$automatic] as $term) {
            foreach ($result as $existing) {
                if (mb_strtolower($existing) === mb_strtolower($term)) {
                    continue 2;
                }
            }

            $result[] = $term;
        }

        return $result;
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function quoted(array $terms): array
    {
        return array_map(fn (string $term): string => '"'.$term.'"', $terms);
    }
}
