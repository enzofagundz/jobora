<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Tools\JoboraTool;
use App\Services\ApplicationApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Consulta histórico e validade efetiva dos consentimentos do material. Revalida vínculo, integração, versões e artefatos. Não retorna documentos nem autorização de envio.')]
class GetApplicationApprovalTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $input = Validator::validate(['input' => $request->all()], [
            'input' => ['required', 'array:preparation_id,limit,offset'], 'input.preparation_id' => ['required', 'uuid'],
            'input.limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'input.offset' => ['sometimes', 'integer', 'min:0'],
        ])['input'];

        return $this->ok(app(ApplicationApprovalService::class)->forPreparation($this->user(), $input['preparation_id'], $input['limit'] ?? 20, $input['offset'] ?? 0));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['preparation_id' => $schema->string()->required(), 'limit' => $schema->integer(), 'offset' => $schema->integer()];
    }
}
