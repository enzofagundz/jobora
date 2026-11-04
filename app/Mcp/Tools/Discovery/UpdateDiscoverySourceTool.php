<?php

namespace App\Mcp\Tools\Discovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Atualiza uma fonte de descoberta do usuário. Exige expected_version; versão divergente retorna conflito.')]
class UpdateDiscoverySourceTool extends DiscoverySourceTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'source' => ['sometimes', 'required', 'in:linkedin,indeed'],
            'surface' => ['sometimes', 'required', 'in:linkedin_jobs,linkedin_publications,indeed_jobs'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:500'],
            'frequency_minutes' => ['sometimes', 'required', 'integer', 'min:5', 'max:10080'],
            'active' => ['sometimes', 'boolean'],
            'next_due_at' => ['sometimes', 'nullable', 'date'],
            'url' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'keywords' => ['sometimes', 'nullable', 'array', 'min:1', 'max:20'],
            'keywords.*' => ['string', 'max:100'],
            'excluded_terms' => ['sometimes', 'nullable', 'array', 'max:20'],
            'excluded_terms.*' => ['string', 'max:100'],
            'operator' => ['sometimes', 'nullable', 'in:all,any'],
            'seniority' => ['sometimes', 'nullable', 'in:internship,junior,mid_level,senior'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'work_mode' => ['sometimes', 'nullable', 'in:any,remote,hybrid,onsite'],
            'published_within_days' => ['sometimes', 'nullable', 'integer', 'in:1,3,7,14,30'],
            'easy_apply' => ['sometimes', 'boolean'],
        ]);

        $fields = [
            'name', 'source', 'surface', 'purpose', 'frequency_minutes',
            'active', 'next_due_at', 'url',
        ];

        $data = array_intersect_key($validated, array_flip($fields));
        $criteria = $this->criteriaFromRequest($request, $validated);

        if ($criteria !== null) {
            $data['criteria'] = $criteria;
        }

        return $this->respond(fn () => $this->sources->update(
            $this->user(),
            (int) $validated['id'],
            (int) $validated['expected_version'],
            $data,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da fonte.'),
            'expected_version' => $schema->integer()->required()->description('Versão atual da fonte.'),
            'name' => $schema->string()->description('Identificação da fonte.'),
            'source' => $schema->string()->description('Plataforma: linkedin ou indeed.'),
            'surface' => $schema->string()->description('Superfície da fonte.'),
            'purpose' => $schema->string()->description('Finalidade da fonte.'),
            'frequency_minutes' => $schema->integer()->description('Frequência em minutos.'),
            'active' => $schema->boolean()->description('Fonte ativa.'),
            'next_due_at' => $schema->string()->description('Próxima execução (ISO 8601).'),
            'url' => $schema->string()->description('URL de busca já montada.'),
            ...$this->criteriaSchema($schema),
        ];
    }
}
