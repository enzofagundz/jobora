<?php

namespace App\Mcp\Tools\Matching;

use App\Mcp\Tools\JoboraTool;
use App\Services\MatchingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Consulta avaliação privada ou resultado atual e histórico paginado da oportunidade. Nunca inicia matching.')]
class GetMatchingTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $arguments = Validator::validate(['arguments' => $request->all()], [
            'arguments' => ['required', 'array:id,opportunity_id,limit,offset'],
            'arguments.id' => ['required_without:arguments.opportunity_id', 'prohibits:arguments.opportunity_id', 'integer:strict', 'min:1'],
            'arguments.opportunity_id' => ['required_without:arguments.id', 'prohibits:arguments.id', 'integer:strict', 'min:1'],
            'arguments.limit' => ['sometimes', 'integer:strict', 'min:1', 'max:100'],
            'arguments.offset' => ['sometimes', 'integer:strict', 'min:0'],
        ])['arguments'];
        $service = app(MatchingService::class);

        return $this->ok(isset($arguments['id']) ? $service->get($this->user(), $arguments['id'])
            : $service->forOpportunity($this->user(), $arguments['opportunity_id'], $arguments['limit'] ?? 20, $arguments['offset'] ?? 0));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->min(1), 'opportunity_id' => $schema->integer()->min(1), 'limit' => $schema->integer()->min(1)->max(100), 'offset' => $schema->integer()->min(0)];
    }
}
