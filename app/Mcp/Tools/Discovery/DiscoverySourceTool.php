<?php

namespace App\Mcp\Tools\Discovery;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\DiscoverySource;
use App\Services\Discovery\DiscoverySourceConflictException;
use App\Services\Discovery\DiscoverySourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class DiscoverySourceTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        protected readonly DiscoverySourceService $sources,
    ) {
        parent::__construct($users);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(DiscoverySource $source): array
    {
        return [
            'id' => $source->id,
            'name' => $source->name,
            'source' => $source->source,
            'surface' => $source->surface,
            'url' => $source->url,
            'purpose' => $source->purpose,
            'criteria' => $source->criteria,
            'frequency_minutes' => $source->frequency_minutes,
            'active' => $source->active,
            'version' => $source->version,
            'last_attempt_at' => $source->last_attempt_at?->toIso8601String(),
            'last_success_at' => $source->last_success_at?->toIso8601String(),
            'next_due_at' => $source->next_due_at?->toIso8601String(),
            'created_at' => $source->created_at?->toIso8601String(),
            'updated_at' => $source->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  callable(): DiscoverySource  $callback
     */
    protected function respond(callable $callback): Response|ResponseFactory
    {
        try {
            return $this->ok(['source' => $this->present($callback())]);
        } catch (DiscoverySourceConflictException $exception) {
            throw ToolException::conflict($exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            throw ToolException::validation($exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function criteriaFromRequest(Request $request, array $validated): ?array
    {
        $keys = [
            'keywords', 'excluded_terms', 'operator', 'seniority', 'location',
            'work_mode', 'published_within_days', 'easy_apply',
        ];

        $criteria = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $validated)) {
                continue;
            }

            $criteria[$key] = $key === 'easy_apply' ? (bool) $validated[$key] : $validated[$key];
        }

        return $criteria === [] ? null : $criteria;
    }

    /**
     * @return array<string, mixed>
     */
    protected function criteriaSchema(JsonSchema $schema): array
    {
        return [
            'keywords' => $schema->array()->description('Palavras-chave da busca.')->items($schema->string()),
            'excluded_terms' => $schema->array()->description('Termos excluídos.')->items($schema->string()),
            'operator' => $schema->string()->description('Operador dos termos: and ou or (aceita all/any).'),
            'seniority' => $schema->string()->description('Senioridade: internship, junior, mid_level ou senior.'),
            'location' => $schema->string()->description('Localização da busca.'),
            'work_mode' => $schema->string()->description('Modalidade: any, remote, hybrid ou onsite.'),
            'published_within_days' => $schema->integer()->description('Janela de publicação: 1, 3, 7, 14 ou 30 dias.'),
            'easy_apply' => $schema->boolean()->description('Filtro Easy Apply (LinkedIn).'),
        ];
    }
}
