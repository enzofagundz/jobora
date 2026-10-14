<?php

namespace App\Mcp\Tools\Ats;

use App\Enums\ResumeLanguage;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobPosting;
use App\Services\AiOperationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
#[Description('Enfileira a adaptação do currículo do usuário configurado para uma vaga confirmada, no idioma pedido (padrão pt-BR). Assíncrona: acompanhe com get_ai_operation e leia o resultado com get_resume_adaptation.')]
class AdaptResumeTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly AiOperationService $operations,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'job_posting_id' => ['required', 'integer'],
            'language' => ['nullable', Rule::enum(ResumeLanguage::class)],
        ]);

        $language = $this->language($request);

        $user = $this->user();

        $posting = JobPosting::query()
            ->where('user_id', $user->id)
            ->whereKey((int) $validated['job_posting_id'])
            ->first();

        if ($posting === null) {
            throw ToolException::notFound('Vaga não encontrada.');
        }

        $operation = $this->operations->enqueueResumeAdaptation($user, $posting, $language);

        return $this->ok([
            'operation_id' => $operation->id,
            'status' => $operation->status?->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'job_posting_id' => $schema->integer()->required()->description('Id da vaga confirmada.'),
            'language' => $schema->string()->description('Idioma da adaptação: pt-BR (padrão) ou en. Exige o currículo daquele idioma.'),
        ];
    }
}
