<?php

namespace App\Mcp\Tools\Screening;

use App\Mcp\Tools\JoboraTool;
use App\Services\ProfessionalProfileService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Define os critérios de triagem do usuário autenticado. Atualiza o rascunho profissional sem confirmar. Rejeições têm prioridade sobre preferências.')]
class SaveScreeningPreferencesTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = Validator::validate(['arguments' => $request->all()], [
            'arguments' => ['array:preferences,rejections,expected_version'],
            'arguments.preferences' => ['nullable', 'array', 'list', 'max:100'],
            'arguments.preferences.*' => ['string', 'max:100'],
            'arguments.rejections' => ['nullable', 'array', 'list', 'max:100'],
            'arguments.rejections.*' => ['string', 'max:100'],
            'arguments.expected_version' => ['sometimes', 'nullable', 'integer:strict', 'min:0', 'max:2147483646'],
        ])['arguments'];

        $profile = app(ProfessionalProfileService::class)->savePreferences(
            $this->user(),
            $validated['preferences'] ?? [],
            $validated['rejections'] ?? [],
            $validated['expected_version'] ?? null,
        );

        return $this->ok([
            'preferences' => $profile['draft']['preferences'],
            'rejections' => $profile['draft']['restrictions'],
            'version' => $profile['version'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'preferences' => $schema->array()->description('Critérios desejados (texto livre).')->items($schema->string()),
            'rejections' => $schema->array()->description('Critérios indesejados (têm prioridade).')->items($schema->string()),
            'expected_version' => $schema->integer()->min(0)->description('Versão esperada do perfil. Opcional para clientes legados.'),
        ];
    }
}
