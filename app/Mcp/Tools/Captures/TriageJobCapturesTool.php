<?php

namespace App\Mcp\Tools\Captures;

use App\Enums\JobCaptureTriageStatus;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobCapture;
use App\Services\JobTriageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
#[Description('Dispara a triagem por IA das capturas elegíveis (não promovidas, pendentes ou com falha) do usuário configurado. Assíncrona: acompanhe com get_job_capture. Lote de no máximo 50.')]
class TriageJobCapturesTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly JobTriageService $triage,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'ids' => ['nullable', 'array', 'max:50'],
            'ids.*' => ['integer'],
            'max' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $this->user();

        $query = JobCapture::query()
            ->where('user_id', $user->id)
            ->whereNull('job_posting_id')
            ->whereIn('triage_status', [JobCaptureTriageStatus::Pending, JobCaptureTriageStatus::Failed]);

        if (! empty($validated['ids'])) {
            $query->whereIn('id', $validated['ids']);
        }

        $limit = (int) ($validated['max'] ?? 20);

        $candidates = $query
            ->orderBy('id')
            ->limit(min(JobTriageService::MAX_BATCH_SIZE, max(1, $limit)))
            ->get();

        $claimed = $this->triage->startBatch($candidates, $user);

        return $this->ok([
            'claimed_ids' => $claimed->pluck('id')->values()->all(),
            'batch_size' => $claimed->count(),
            'state' => $claimed->isEmpty() ? 'idle' : 'processing',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ids' => $schema->array()->items($schema->integer())->description('Ids específicos (máx 50). Sem ids, usa as capturas elegíveis mais antigas.'),
            'max' => $schema->integer()->min(1)->max(50)->description('Quantidade máxima quando ids não é informado (padrão 20).'),
        ];
    }
}
