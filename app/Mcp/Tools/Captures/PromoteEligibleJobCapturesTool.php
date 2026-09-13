<?php

namespace App\Mcp\Tools\Captures;

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobCapture;
use App\Services\JobPromotionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
#[Description('Promove em lote as capturas elegíveis (triadas como relevantes/incertas, ainda não promovidas) e dispara a análise por IA de cada vaga. Lote de no máximo 50.')]
class PromoteEligibleJobCapturesTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly JobPromotionService $promotions,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'max' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $this->user();
        $limit = (int) ($validated['max'] ?? 20);

        $candidates = JobCapture::query()
            ->where('user_id', $user->id)
            ->whereNull('job_posting_id')
            ->where('triage_status', JobCaptureTriageStatus::Completed)
            ->whereIn('triage_result', [JobCaptureTriageResult::Relevant, JobCaptureTriageResult::Uncertain])
            ->orderBy('id')
            ->limit(max(1, min(50, $limit)))
            ->get();

        $promoted = $this->promotions->promoteEligible($user, $candidates);

        $candidates->each->refresh();

        return $this->ok([
            'promoted_count' => $promoted,
            'job_posting_ids' => $candidates->pluck('job_posting_id')->filter()->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'max' => $schema->integer()->min(1)->max(50)->description('Quantidade máxima de capturas avaliadas (padrão 20).'),
        ];
    }
}
