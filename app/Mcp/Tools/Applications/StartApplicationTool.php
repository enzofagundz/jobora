<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobPosting;
use App\Services\ApplicationTrackingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Inicia o acompanhamento de candidatura de uma vaga do usuário configurado. Idempotente: chamar de novo devolve a mesma candidatura.')]
class StartApplicationTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly ApplicationTrackingService $applications,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['job_posting_id' => ['required', 'integer']]);

        $user = $this->user();

        $posting = JobPosting::query()
            ->where('user_id', $user->id)
            ->whereKey((int) $validated['job_posting_id'])
            ->first();

        if ($posting === null) {
            throw ToolException::notFound('Vaga não encontrada.');
        }

        $application = $this->applications->start($user, $posting);

        return $this->ok([
            'application' => [
                'id' => $application->id,
                'status' => $application->status?->value,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'job_posting_id' => $schema->integer()->required()->description('Id da vaga.'),
        ];
    }
}
