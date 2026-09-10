<?php

namespace App\Mcp\Tools\JobPostings;

use App\Mcp\Support\JobPostingPresenter;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Tools\JoboraTool;
use App\Services\JobAnalysisService;
use App\Services\JobPostingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Cria uma vaga a partir de uma descrição (link de origem opcional). A vaga fica com status pending e NÃO é analisada automaticamente; use analyze_job_posting em seguida.')]
class CreateJobPostingTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly JobPostingService $postings,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'description' => [
                'required',
                'string',
                'min:'.JobAnalysisService::MIN_DESCRIPTION_LENGTH,
                'max:'.JobAnalysisService::MAX_DESCRIPTION_LENGTH,
            ],
            'source_url' => ['nullable', 'string', 'max:2000'],
        ]);

        $posting = $this->postings->create(
            $this->user(),
            $validated['description'],
            $validated['source_url'] ?? null,
        );

        return $this->ok(['job_posting' => JobPostingPresenter::summary($posting->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()->required()->description('Descrição da vaga (20 a 100.000 caracteres).'),
            'source_url' => $schema->string()->description('Link de origem (opcional).'),
        ];
    }
}
