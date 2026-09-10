<?php

namespace App\Mcp\Tools\Captures;

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobCapture;
use App\Services\JobAnalysisService;
use App\Services\JobPromotionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
#[Description('Promove uma captura triada como relevante ou incerta para uma vaga (status pending) e dispara a análise por IA. Assíncrona: acompanhe com get_job_posting.')]
class PromoteJobCaptureTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly JobPromotionService $promotions,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['id' => ['required', 'integer']]);

        $user = $this->user();

        $capture = JobCapture::query()
            ->where('user_id', $user->id)
            ->whereKey((int) $validated['id'])
            ->first();

        if ($capture === null) {
            throw ToolException::notFound('Captura não encontrada.');
        }

        if ($capture->job_posting_id !== null) {
            throw ToolException::conflict('Captura já promovida para uma vaga.');
        }

        if (mb_strlen((string) $capture->description) < JobAnalysisService::MIN_DESCRIPTION_LENGTH) {
            throw ToolException::precondition(
                'Descrição insuficiente para criar vaga (mínimo de '.JobAnalysisService::MIN_DESCRIPTION_LENGTH.' caracteres).',
            );
        }

        if ($capture->triage_status !== JobCaptureTriageStatus::Completed
            || ! in_array($capture->triage_result, [JobCaptureTriageResult::Relevant, JobCaptureTriageResult::Uncertain], true)) {
            throw ToolException::precondition('A captura precisa estar triada como relevante ou incerta antes de ser promovida.');
        }

        $posting = $this->promotions->promote($user, $capture);

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
            'id' => $schema->integer()->required()->description('Id da captura triada.'),
        ];
    }
}
