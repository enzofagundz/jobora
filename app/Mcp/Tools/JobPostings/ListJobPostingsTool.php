<?php

namespace App\Mcp\Tools\JobPostings;

use App\Enums\ConceptCategory;
use App\Enums\JobPostingStatus;
use App\Mcp\Support\JobPostingPresenter;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobPosting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lista as vagas do usuário configurado, com filtros de status, categoria, cargo, empresa e período. Paginado (limit 20, máx 100).')]
class ListJobPostingsTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'role' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $page = $this->page($request);

        $query = JobPosting::query()
            ->with([
                'resumeAdaptations:id,job_posting_id',
                'application:id,job_posting_id,status',
            ])
            ->withCount('items')
            ->where('user_id', $this->user()->id);

        if (! empty($validated['status'])) {
            $status = JobPostingStatus::tryFrom((string) $validated['status']);

            if ($status === null) {
                throw ToolException::validation('Status de vaga inválido.');
            }

            $query->where('status', $status);
        }

        if (! empty($validated['category'])) {
            $category = ConceptCategory::tryFrom((string) $validated['category']);

            if ($category === null) {
                throw ToolException::validation('Categoria inválida.');
            }

            $query->whereHas('items', fn ($items) => $items->where('category', $category));
        }

        if (! empty($validated['role'])) {
            $query->where('role', 'like', '%'.$validated['role'].'%');
        }

        if (! empty($validated['company'])) {
            $query->where('company', 'like', '%'.$validated['company'].'%');
        }

        if (! empty($validated['from'])) {
            $query->where('collected_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->where('collected_at', '<=', $validated['to']);
        }

        $total = (clone $query)->count();

        $postings = $query
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->skip($page['offset'])
            ->take($page['limit'])
            ->get()
            ->map(fn (JobPosting $posting): array => JobPostingPresenter::summary($posting))
            ->all();

        return $this->listed($postings, $total, $page['limit'], $page['offset']);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('pending, needs_review, confirmed ou failed.'),
            'category' => $schema->string()->description('skill, tool, methodology, requirement ou experience.'),
            'role' => $schema->string()->description('Filtro parcial por cargo.'),
            'company' => $schema->string()->description('Filtro parcial por empresa.'),
            'from' => $schema->string()->description('Data inicial de coleta (ISO-8601).'),
            'to' => $schema->string()->description('Data final de coleta (ISO-8601).'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Itens por página (padrão 20).'),
            'offset' => $schema->integer()->min(0)->description('Deslocamento para paginação.'),
        ];
    }
}
