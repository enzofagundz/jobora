<?php

namespace App\Mcp\Tools\Matching;

use App\Mcp\Tools\JoboraTool;
use App\Models\MatchingEvaluation;
use App\Services\AiOperationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Solicita matching explicável da oportunidade pronta com o perfil confirmado. Reutiliza entrada idêntica, sem score global.')]
class RequestMatchingTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $arguments = Validator::validate(['arguments' => $request->all()], [
            'arguments' => ['required', 'array:opportunity_id,expected_opportunity_version,expected_profile_confirmed_version,criteria_version,retry'],
            'arguments.opportunity_id' => ['required', 'integer:strict', 'min:1'],
            'arguments.expected_opportunity_version' => ['required', 'integer:strict', 'min:1'],
            'arguments.expected_profile_confirmed_version' => ['required', 'integer:strict', 'min:0'],
            'arguments.criteria_version' => ['sometimes', 'required', 'in:matching-v1'],
            'arguments.retry' => ['sometimes', 'boolean:strict'],
        ])['arguments'];
        $operation = app(AiOperationService::class)->enqueueMatching($this->user(), $arguments['opportunity_id'], $arguments['expected_opportunity_version'], $arguments['expected_profile_confirmed_version'], $arguments['criteria_version'] ?? 'matching-v1', ['source' => 'stdio'], $arguments['retry'] ?? false);

        return $this->ok([
            'operation_id' => $operation->id, 'status' => $operation->status->value,
            'matching_evaluation_id' => MatchingEvaluation::query()->where('user_id', $this->user()->id)->where('input_fingerprint', $operation->input_fingerprint)->value('id'),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'opportunity_id' => $schema->integer()->min(1)->required(),
            'expected_opportunity_version' => $schema->integer()->min(1)->required(),
            'expected_profile_confirmed_version' => $schema->integer()->min(0)->required(),
            'criteria_version' => $schema->string()->enum(['matching-v1'])->default('matching-v1'),
            'retry' => $schema->boolean()->default(false),
        ];
    }
}
