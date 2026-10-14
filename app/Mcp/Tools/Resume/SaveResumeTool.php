<?php

namespace App\Mcp\Tools\Resume;

use App\Enums\ResumeLanguage;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Services\ResumeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Define o currículo do usuário configurado (texto puro) no idioma pedido (padrão pt-BR). Substitui a versão daquele idioma — não há histórico e os demais idiomas não são tocados. Operação de sobrescrita: exige confirm=true.')]
class SaveResumeTool extends JoboraTool
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
            'content' => ['required', 'string', 'max:50000'],
            'confirm' => ['nullable', 'boolean'],
            'language' => ['nullable', Rule::enum(ResumeLanguage::class)],
        ]);

        $this->requireConfirm($request);

        if (trim($validated['content']) === '') {
            throw ToolException::validation('O currículo não pode ficar vazio.');
        }

        $language = $this->language($request);

        $resume = $this->resumes->save($this->user(), $validated['content'], $language);

        return $this->ok([
            'content' => $resume->content,
            'updated_at' => $resume->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->required()->description('Texto completo do currículo.'),
            'confirm' => $schema->boolean()->required()->description('Envie true para confirmar a substituição do currículo.'),
            'language' => $schema->string()->description('Idioma do currículo: pt-BR (padrão) ou en. Não altera as outras versões.'),
        ];
    }
}
