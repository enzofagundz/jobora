<?php

namespace App\Mcp\Tools\JobPostings;

use App\Enums\ResumeLanguage;
use App\Mcp\Support\JobPostingPresenter;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobPosting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Devolve o detalhe de uma vaga do usuário configurado: descrição, itens, métodos de candidatura, sugestão pendente, adaptação, candidatura e última tentativa de IA.')]
class GetJobPostingTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['id' => ['required', 'integer']]);

        $posting = JobPosting::query()
            ->with(['items', 'applicationMethods', 'application'])
            ->where('user_id', $this->user()->id)
            ->whereKey((int) $validated['id'])
            ->first();

        if ($posting === null) {
            throw ToolException::notFound('Vaga não encontrada.');
        }

        return $this->ok(['job_posting' => JobPostingPresenter::detail($posting, ResumeLanguage::PtBr)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da vaga.'),
        ];
    }
}
