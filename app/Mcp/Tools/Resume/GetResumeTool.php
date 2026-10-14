<?php

namespace App\Mcp\Tools\Resume;

use App\Enums\ResumeLanguage;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Tools\JoboraTool;
use App\Services\ResumeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Devolve o currículo do usuário configurado, no idioma pedido (padrão pt-BR): texto e data de atualização. Vazio quando ainda não cadastrado.')]
class GetResumeTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly ResumeService $resumes,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'language' => ['nullable', Rule::enum(ResumeLanguage::class)],
        ]);

        $language = $this->language($request);

        $resume = $this->resumes->current($this->user(), $language);

        return $this->ok([
            'content' => $resume?->content,
            'updated_at' => $resume?->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'language' => $schema->string()->description('Idioma do currículo: pt-BR (padrão) ou en.'),
        ];
    }
}
