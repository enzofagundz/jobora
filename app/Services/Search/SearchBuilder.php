<?php

namespace App\Services\Search;

use App\DTO\SearchCriteria;

interface SearchBuilder
{
    /**
     * Monta a URL de busca para o fornecedor a partir do critério comum.
     */
    public function build(SearchCriteria $criteria): string;
}
