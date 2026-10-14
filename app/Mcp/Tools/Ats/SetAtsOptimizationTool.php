<?php

namespace App\Mcp\Tools\Ats;

use App\Enums\ResumeLanguage;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ResumeAdaptationPresenter;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobPosting;
use App\Services\ResumeAdaptationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Ativa ou desativa a otimização ATS da adaptação do idioma pedido (padrão pt-BR) de uma vaga confirmada. Requer permissão de ATS. Síncrona (o bloco ATS é determinístico, sem IA).')]
class SetAtsOptimizationTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly ResumeAdaptationService $adaptations,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'job_posting_id' => ['required', 'integer'],
            'enabled' => ['required', 'boolean'],
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

        $adaptation = $this->adaptations->setAtsOptimization($user, $posting, (bool) $validated['enabled'], $language);

        return $this->ok(['adaptation' => ResumeAdaptationPresenter::toArray($adaptation)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'job_posting_id' => $schema->integer()->required()->description('Id da vaga confirmada.'),
            'enabled' => $schema->boolean()->required()->description('true ativa; false desativa a otimização ATS.'),
            'language' => $schema->string()->description('Idioma da adaptação: pt-BR (padrão) ou en.'),
        ];
    }
}
