<?php

namespace App\Mcp\Tools\Search;

use App\DTO\SearchCriteria;
use App\Enums\SearchMode;
use App\Enums\SearchOperator;
use App\Enums\SearchPlatform;
use App\Enums\SearchSeniority;
use App\Enums\SearchWorkMode;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Services\Search\SearchUrlGenerator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Gera a URL determinística de busca de vagas no LinkedIn ou Indeed a partir de palavras-chave e filtros. Não faz requisição nem persiste nada.')]
class GenerateSearchUrlTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly SearchUrlGenerator $generator,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'platform' => ['required', Rule::enum(SearchPlatform::class)],
            'mode' => ['nullable', Rule::enum(SearchMode::class)],
            'keywords' => ['required', 'array', 'min:1', 'max:20'],
            'keywords.*' => ['string', 'max:100'],
            'excluded_terms' => ['nullable', 'array', 'max:20'],
            'excluded_terms.*' => ['string', 'max:100'],
            'operator' => ['nullable', Rule::enum(SearchOperator::class)],
            'seniority' => ['nullable', Rule::enum(SearchSeniority::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'work_mode' => ['nullable', Rule::enum(SearchWorkMode::class)],
            'published_within_days' => ['nullable', 'integer', 'in:1,3,7,14,30'],
            'easy_apply' => ['nullable', 'boolean'],
        ]);

        try {
            $criteria = SearchCriteria::fromInput(
                keywords: $validated['keywords'],
                excludedTerms: $validated['excluded_terms'] ?? [],
                operator: SearchOperator::tryFrom($validated['operator'] ?? 'and') ?? SearchOperator::All,
                seniority: isset($validated['seniority']) ? SearchSeniority::tryFrom($validated['seniority']) : null,
                location: $validated['location'] ?? null,
                workMode: SearchWorkMode::tryFrom($validated['work_mode'] ?? 'any') ?? SearchWorkMode::Any,
                publishedWithinDays: $validated['published_within_days'] ?? null,
                easyApply: (bool) ($validated['easy_apply'] ?? false),
            );

            $url = $this->generator->build(
                SearchPlatform::from($validated['platform']),
                $criteria,
                SearchMode::tryFrom($validated['mode'] ?? 'jobs') ?? SearchMode::Jobs,
            );
        } catch (InvalidArgumentException $exception) {
            throw ToolException::validation($exception->getMessage());
        }

        return $this->ok(['url' => $url]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'platform' => $schema->string()->required()->description('linkedin ou indeed.'),
            'mode' => $schema->string()->description('jobs (padrão) ou publications (só LinkedIn).'),
            'keywords' => $schema->array()->required()->description('Palavras-chave (ao menos uma).')->items($schema->string()),
            'excluded_terms' => $schema->array()->description('Termos a excluir.')->items($schema->string()),
            'operator' => $schema->string()->description('and (padrão) ou or.'),
            'seniority' => $schema->string()->description('internship, junior, mid_level ou senior.'),
            'location' => $schema->string()->description('Localização.'),
            'work_mode' => $schema->string()->description('any (padrão), onsite, remote ou hybrid.'),
            'published_within_days' => $schema->integer()->description('1, 3, 7, 14 ou 30 dias.'),
            'easy_apply' => $schema->boolean()->description('Somente LinkedIn.'),
        ];
    }
}
