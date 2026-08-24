<?php

namespace App\Services\Search;

use App\DTO\SearchCriteria;
use App\Enums\SearchMode;
use App\Enums\SearchPlatform;
use Illuminate\Support\Facades\App;

/**
 * Seleciona o builder do fornecedor e despacha o critério comum.
 * Único ponto que conhece plataforma → builder; novos fornecedores
 * entram aqui com seu builder, sem alterar a DTO nem a página.
 */
class SearchUrlGenerator
{
    public function build(SearchPlatform $platform, SearchCriteria $criteria, SearchMode $mode = SearchMode::Jobs): string
    {
        if ($platform === SearchPlatform::LinkedIn && $mode === SearchMode::Publications) {
            return App::make(LinkedInSearchBuilder::class)->buildPublications($criteria);
        }

        /** @var SearchBuilder */
        return App::make(match ($platform) {
            SearchPlatform::LinkedIn => LinkedInSearchBuilder::class,
            SearchPlatform::Indeed => IndeedSearchBuilder::class,
        })->build($criteria);
    }
}
