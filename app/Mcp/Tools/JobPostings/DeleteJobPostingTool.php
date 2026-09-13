<?php

namespace App\Mcp\Tools\JobPostings;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobPosting;
use App\Services\JobPostingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Exclui uma vaga do usuário configurado (com itens, adaptação e candidatura em cascata). Operação destrutiva: exige confirm=true.')]
class DeleteJobPostingTool extends JoboraTool
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

        $this->postings->delete($user, $posting);

        return $this->ok(['deleted' => true, 'id' => $posting->id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da vaga.'),
            'confirm' => $schema->boolean()->required()->description('Envie true para confirmar a exclusão.'),
        ];
    }
}
