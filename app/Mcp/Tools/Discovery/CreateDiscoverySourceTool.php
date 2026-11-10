<?php

namespace App\Mcp\Tools\Discovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Cria uma fonte de descoberta LinkedIn/Indeed. Aceita URL pronta e/ou critérios de busca; quando ambos são enviados, precisam coincidir.')]
class CreateDiscoverySourceTool extends DiscoverySourceTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'source' => ['required', 'in:linkedin,indeed'],
            'surface' => ['required', 'in:linkedin_jobs,linkedin_publications,indeed_jobs'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'frequency_minutes' => ['required', 'integer', 'min:5', 'max:10080'],
            'active' => ['nullable', 'boolean'],
            'next_due_at' => ['nullable', 'date'],
            'url' => ['nullable', 'string', 'max:2000'],
            'keywords' => ['nullable', 'array', 'min:1', 'max:20'],
            'keywords.*' => ['string', 'max:100'],
            'excluded_terms' => ['nullable', 'array', 'max:20'],
            'excluded_terms.*' => ['string', 'max:100'],
            'operator' => ['nullable', 'in:all,any,and,or'],
            'seniority' => ['nullable', 'in:internship,junior,mid_level,senior'],
            'location' => ['nullable', 'string', 'max:255'],
            'work_mode' => ['nullable', 'in:any,remote,hybrid,onsite'],
            'published_within_days' => ['nullable', 'integer', 'in:1,3,7,14,30'],
            'easy_apply' => ['nullable', 'boolean'],
        ]);

        $criteria = $this->criteriaFromRequest($request, $validated);

        $data = [
            'name' => $validated['name'],
            'source' => $validated['source'],
            'surface' => $validated['surface'],
            'purpose' => $validated['purpose'] ?? null,
            'frequency_minutes' => (int) $validated['frequency_minutes'],
            'active' => (bool) ($validated['active'] ?? true),
            'next_due_at' => $validated['next_due_at'] ?? null,
            'url' => $validated['url'] ?? null,
        ];

        if ($criteria !== null) {
            $data['criteria'] = $criteria;
        }

        return $this->respond(fn () => $this->sources->create($this->user(), $data));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Identificação da fonte.'),
            'source' => $schema->string()->required()->description('Plataforma: linkedin ou indeed.'),
            'surface' => $schema->string()->required()->description('Superfície: linkedin_jobs, linkedin_publications ou indeed_jobs.'),
            'purpose' => $schema->string()->description('Finalidade da fonte.'),
            'frequency_minutes' => $schema->integer()->required()->description('Frequência em minutos (5–10080).'),
            'active' => $schema->boolean()->description('Fonte ativa.'),
            'next_due_at' => $schema->string()->description('Próxima execução (ISO 8601).'),
            'url' => $schema->string()->description('URL de busca já montada.'),
            ...$this->criteriaSchema($schema),
        ];
    }
}
