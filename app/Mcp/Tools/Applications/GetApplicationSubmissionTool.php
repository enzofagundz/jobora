<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Tools\JoboraTool;
use App\Services\ApplicationSubmissionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Consulta tentativa e eventos paginados. Nunca autoriza envio. Prazo vencido de resultado significa unknown e exige reconciliação.')]
class GetApplicationSubmissionTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $input = $request->validate(['attempt_id' => ['required', 'uuid'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'offset' => ['sometimes', 'integer', 'min:0']]);

        return $this->ok(app(ApplicationSubmissionService::class)->get($this->user(), $input['attempt_id'], $input['limit'] ?? 20, $input['offset'] ?? 0));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['attempt_id' => $schema->string()->required(), 'limit' => $schema->integer(), 'offset' => $schema->integer()];
    }
}
