<?php

namespace App\Mcp\Tools\JobPostings;

use App\Enums\JobPostingStatus;
use App\Mcp\Support\JobPostingPresenter;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobPosting;
use App\Services\JobReviewService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Descarta a sugestão da IA de uma vaga em revisão, limpando a sugestão e voltando o status para pending. Operação destrutiva/de sobrescrita: exige confirm=true.')]
class DiscardJobPostingSuggestionTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly JobReviewService $review,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $this->requireConfirm($request);

        $user = $this->user();

        $posting = JobPosting::query()
            ->where('user_id', $user->id)
            ->whereKey((int) $validated['id'])
            ->first();

        if ($posting === null) {
            throw ToolException::notFound('Vaga não encontrada.');
        }

        if ($posting->status !== JobPostingStatus::NeedsReview) {
            throw ToolException::conflict('A vaga precisa estar aguardando revisão para descartar a sugestão.');
        }

        $this->review->discard($user, $posting);

        return $this->ok(['job_posting' => JobPostingPresenter::summary($posting->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da vaga em revisão.'),
            'confirm' => $schema->boolean()->required()->description('Envie true para confirmar o descarte.'),
        ];
    }
}
