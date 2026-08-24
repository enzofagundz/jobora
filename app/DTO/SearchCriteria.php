<?php

namespace App\DTO;

use App\Enums\SearchOperator;
use App\Enums\SearchSeniority;
use App\Enums\SearchWorkMode;
use InvalidArgumentException;

/**
 * Critério de busca de vagas independente de fornecedor.
 *
 * Não conhece URL, parâmetros de plataforma (f_E, f_TPR, q, fromage…)
 * nem sintaxe de LinkedIn/Indeed — isso é responsabilidade dos builders
 * em App\Services\Search. A DTO é pura: sem HTTP e sem persistência.
 */
final readonly class SearchCriteria
{
    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $excludedTerms
     */
    private function __construct(
        public array $keywords,
        public array $excludedTerms,
        public SearchOperator $operator,
        public ?SearchSeniority $seniority,
        public ?string $location,
        public SearchWorkMode $workMode,
        public ?int $publishedWithinDays,
        public bool $easyApply,
    ) {}

    /**
     * Normaliza a entrada bruta do formulário em um critério canônico.
     *
     * Termos são tratados como dados literais (C++, "R&D", frases com
     * espaços): nada de interpretar AND/OR/NOT ou aspas dentro deles.
     *
     * @param  array<int|string, mixed>  $keywords
     * @param  array<int|string, mixed>  $excludedTerms
     */
    public static function fromInput(
        array $keywords,
        array $excludedTerms = [],
        SearchOperator $operator = SearchOperator::All,
        ?SearchSeniority $seniority = null,
        ?string $location = null,
        SearchWorkMode $workMode = SearchWorkMode::Any,
        ?int $publishedWithinDays = null,
        bool $easyApply = false,
    ): self {
        $normalizedKeywords = self::normalizeTerms($keywords);
        $normalizedExclusions = self::normalizeTerms($excludedTerms);

        if ($normalizedKeywords === []) {
            throw new InvalidArgumentException('Informe ao menos uma palavra-chave para a busca.');
        }

        $trimmedLocation = trim((string) $location);

        if ($publishedWithinDays !== null && ! in_array($publishedWithinDays, [1, 3, 7, 14, 30], true)) {
            throw new InvalidArgumentException('A janela de publicação selecionada não é válida.');
        }

        return new self(
            keywords: $normalizedKeywords,
            excludedTerms: $normalizedExclusions,
            operator: $operator,
            seniority: $seniority,
            location: $trimmedLocation === '' ? null : $trimmedLocation,
            workMode: $workMode,
            publishedWithinDays: $publishedWithinDays,
            easyApply: $easyApply,
        );
    }

    /**
     * Trim, remove vazios e deduplica sem distinguir maiúsculas/minúsculas,
     * preservando a primeira grafia e a ordem original. Aceita somente strings.
     *
     * @param  array<int|string, mixed>  $terms
     * @return list<string>
     */
    private static function normalizeTerms(array $terms): array
    {
        $normalized = [];

        foreach ($terms as $term) {
            if (! is_string($term)) {
                continue;
            }

            $term = trim($term);

            if ($term === '') {
                continue;
            }

            foreach ($normalized as $existing) {
                if (mb_strtolower($existing) === mb_strtolower($term)) {
                    continue 2;
                }
            }

            $normalized[] = $term;
        }

        return $normalized;
    }
}
