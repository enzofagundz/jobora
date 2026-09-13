<?php

namespace App\Mcp\Tools\Applications;

use App\Enums\ApplicationStatus;
use App\Mcp\Support\ApplicationPresenter;
use App\Mcp\Tools\JoboraTool;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lista as candidaturas do usuário configurado, com filtro por status e paginação.')]
class ListApplicationsTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(ApplicationStatus::class)],
        ]);

        $page = $this->page($request);
        $user = $this->user();

        $query = Application::query()
            ->whereHas('jobPosting', fn ($postings) => $postings->where('user_id', $user->id))
            ->with('jobPosting:id,role,company');

        if (! empty($validated['status'])) {
            $query->where('status', ApplicationStatus::from($validated['status']));
        }

        $total = (clone $query)->count();

        $items = $query
            ->orderByDesc('id')
            ->skip($page['offset'])
            ->take($page['limit'])
            ->get()
            ->map(fn (Application $application): array => ApplicationPresenter::toArray($application) + [
                'role' => $application->jobPosting?->role,
                'company' => $application->jobPosting?->company,
            ])
            ->all();

        return $this->listed($items, $total, $page['limit'], $page['offset']);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('planned, preparing, submitted, waiting, assessment, interview, offer, completed, rejected ou withdrawn.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Itens por página (padrão 20).'),
            'offset' => $schema->integer()->min(0)->description('Deslocamento para paginação.'),
        ];
    }
}
