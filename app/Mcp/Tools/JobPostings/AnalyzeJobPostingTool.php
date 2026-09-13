<?php

namespace App\Mcp\Tools\JobPostings;

use App\Enums\JobPostingStatus;
use App\Jobs\AnalyzeJobPosting;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobPosting;
use App\Services\JobAnalysisService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
#[Description('Dispara a análise por IA de uma vaga (extração estruturada). Assíncrona: acompanhe com get_job_posting. Não reanalisa vaga já confirmada.')]
class AnalyzeJobPostingTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['id' => ['required', 'integer']]);

        $user = $this->user();

        $posting = JobPosting::query()
            ->where('user_id', $user->id)
            ->whereKey((int) $validated['id'])
            ->first();

        if ($posting === null) {
            throw ToolException::notFound('Vaga não encontrada.');
        }

        if ($posting->status === JobPostingStatus::Confirmed) {
            throw ToolException::conflict('Vaga já confirmada; não é reanalisada.');
        }

        $length = mb_strlen((string) $posting->description);

        if ($length < JobAnalysisService::MIN_DESCRIPTION_LENGTH || $length > JobAnalysisService::MAX_DESCRIPTION_LENGTH) {
            throw ToolException::precondition(
                'A descrição da vaga deve ter entre '.JobAnalysisService::MIN_DESCRIPTION_LENGTH
                .' e '.JobAnalysisService::MAX_DESCRIPTION_LENGTH.' caracteres.',
            );
        }

        AnalyzeJobPosting::dispatch($posting, (int) $user->id, 'mcp');

        return $this->ok([
            'job_posting_id' => $posting->id,
            'status' => $posting->status?->value,
        ]);
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
