<?php

namespace App\Mcp\Tools\JobPostings;

use App\Enums\ApplicationMethodType;
use App\Enums\ConceptCategory;
use App\Enums\JobPostingStatus;
use App\Mcp\Support\JobPostingPresenter;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobPosting;
use App\Services\JobReviewService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Confirma a revisão de uma vaga (status needs_review): persiste itens e métodos de candidatura, limpa a sugestão da IA e define status confirmed. Use get_job_posting para ler a sugestão antes.')]
class ConfirmJobPostingTool extends JoboraTool
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
            'items' => ['present', 'array', 'max:100'],
            'items.*.category' => ['required', Rule::enum(ConceptCategory::class)],
            'items.*.name' => ['required', 'string', 'max:100'],
            'application_methods' => ['nullable', 'array', 'max:20'],
            'application_methods.*.type' => ['required', Rule::enum(ApplicationMethodType::class)],
            'application_methods.*.platform' => ['nullable', 'string', 'max:100'],
            'application_methods.*.url' => ['nullable', 'string', 'max:2000'],
            'application_methods.*.email' => ['nullable', 'string', 'max:320'],
            'application_methods.*.phone' => ['nullable', 'string', 'max:50'],
            'application_methods.*.instructions' => ['nullable', 'string', 'max:2000'],
            'role' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $this->user();

        $posting = JobPosting::query()
            ->where('user_id', $user->id)
            ->whereKey((int) $validated['id'])
            ->first();

        if ($posting === null) {
            throw ToolException::notFound('Vaga não encontrada.');
        }

        if ($posting->status !== JobPostingStatus::NeedsReview) {
            throw ToolException::conflict('A vaga precisa estar aguardando revisão para ser confirmada.');
        }

        $this->review->confirm(
            $user,
            $posting,
            $validated['items'],
            $validated['application_methods'] ?? [],
            $validated['role'] ?? null,
            $validated['company'] ?? null,
        );

        return $this->ok(['job_posting' => JobPostingPresenter::summary($posting->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da vaga em revisão.'),
            'items' => $schema->array()->required()->description('Itens confirmados: [{category, name}].')->items(
                $schema->object([
                    'category' => $schema->string()->required()->description('skill, tool, methodology, requirement ou experience.'),
                    'name' => $schema->string()->required()->description('Nome do conceito.'),
                ]),
            ),
            'application_methods' => $schema->array()->description('Métodos de candidatura (opcional).')->items(
                $schema->object([
                    'type' => $schema->string()->required()->description('E-mail, url, etc.'),
                    'platform' => $schema->string(),
                    'url' => $schema->string(),
                    'email' => $schema->string(),
                    'phone' => $schema->string(),
                    'instructions' => $schema->string(),
                ]),
            ),
            'role' => $schema->string()->description('Cargo confirmado.'),
            'company' => $schema->string()->description('Empresa confirmada.'),
        ];
    }
}
