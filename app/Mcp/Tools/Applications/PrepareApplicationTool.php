<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Tools\JoboraTool;
use App\Services\ApplicationPreparationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Prepara documentos verdadeiros para revisão humana, sem exigir matching ou nota ATS. Repetir após concluir as operações retornadas. Respostas factuais devem ser citações literais dos campos congelados, concatenadas por quebra de linha.')]
class PrepareApplicationTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $actor = app()->bound(AgentIntegrationContext::class)
            ? ['source' => 'mcp', 'integration_id' => app(AgentIntegrationContext::class)->integration->id]
            : ['source' => 'stdio'];

        return $this->ok(app(ApplicationPreparationService::class)->prepare($this->user(), $request->all(), $actor));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'job_posting_id' => $schema->integer()->required(),
            'expected_opportunity_version' => $schema->integer()->description('Obrigatório para oportunidade V2.'),
            'expected_profile_confirmed_version' => $schema->integer()->required(),
            'language' => $schema->string()->enum(['pt-BR', 'en'])->default('pt-BR'),
            'destination' => $schema->object([
                'application_method_id' => $schema->integer(), 'type' => $schema->string(),
                'platform' => $schema->string(), 'url' => $schema->string(), 'email' => $schema->string(),
                'phone' => $schema->string(), 'instructions' => $schema->string(),
            ])->required(),
            'questions' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(), 'question' => $schema->string()->required(), 'answer' => $schema->string(),
                'support' => $schema->array()->items($schema->object([
                    'source' => $schema->string()->enum(['profile', 'resume'])->required(),
                    'path' => $schema->string()->required(), 'quote' => $schema->string()->required(),
                ])),
            ])),
            'cover_letter_requested' => $schema->boolean()->default(false),
            'cover_letter_required' => $schema->boolean()->default(false)->description('Exigência estruturada declarada de carta. Sem cover_letter_requested=true, gera pendência. Instruções explícitas PT/EN do destino também podem exigir carta; texto arbitrário não é interpretado.'),
            'translate_missing' => $schema->boolean()->default(false),
            'ats_consent' => $schema->boolean()->default(false),
        ];
    }
}
