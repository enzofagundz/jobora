<?php

namespace App\Mcp\Tools\Profile;

use App\Mcp\Tools\JoboraTool;
use App\Services\ProfessionalProfileService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Substitui o documento inteiro do rascunho profissional, sem publicar a versão confirmada. Exige a versão lida anteriormente e confirm=true para autorizar a sobrescrita.')]
class SaveProfessionalProfileTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = Validator::validate(['arguments' => $request->all()], [
            'arguments' => ['required', 'array:profile,expected_version,confirm'],
            'arguments.confirm' => ['sometimes', 'boolean:strict'],
            'arguments.profile' => ['required', 'array'],
            'arguments.expected_version' => ['required', 'integer:strict', 'min:0', 'max:2147483646'],
        ])['arguments'];

        $this->requireConfirm($request);

        return $this->ok(app(ProfessionalProfileService::class)->saveDraft($this->user(), $validated['profile'], $validated['expected_version']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'confirm' => $schema->boolean()->required()->description('Envie true para autorizar a substituição do rascunho.'),
            'profile' => $schema->object([
                'experiences' => $schema->array()->max(100)->items($schema->object([
                    'company' => $schema->string()->max(255)->nullable()->required(),
                    'role' => $schema->string()->max(255)->nullable()->required(),
                    'period' => $schema->string()->max(100)->nullable()->required(),
                    'description' => $schema->string()->max(5000)->nullable()->required(),
                ]))->required(),
                'education' => $schema->array()->max(100)->items($schema->object([
                    'institution' => $schema->string()->max(255)->nullable()->required(),
                    'qualification' => $schema->string()->max(255)->nullable()->required(),
                    'period' => $schema->string()->max(100)->nullable()->required(),
                ]))->required(),
                'competencies' => $schema->array()->max(100)->items($schema->string()->max(100))->required(),
                'preferences' => $schema->array()->max(100)->items($schema->string()->max(100))->required(),
                'restrictions' => $schema->array()->max(100)->items($schema->string()->max(100))->required(),
                'compensation_expectation' => $schema->string()->max(500)->nullable()->required(),
            ])->description('Documento completo, somente com dados informados pelo usuário.')->required(),
            'expected_version' => $schema->integer()->min(0)->max(2147483646)->required(),
        ];
    }
}
