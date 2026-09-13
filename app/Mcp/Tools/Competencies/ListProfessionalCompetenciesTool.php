<?php

namespace App\Mcp\Tools\Competencies;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Tools\JoboraTool;
use App\Services\StrategicCompetencyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lista as competências estratégicas do usuário configurado, com papel (core/expansion/differentiation), origem (ai/manual) e presença ou lacuna no currículo.')]
class ListProfessionalCompetenciesTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly StrategicCompetencyService $competencies,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $items = array_map(fn (array $row): array => [
            'id' => $row['id'],
            'concept' => $row['concept'],
            'strategic_role' => $row['strategic_role']->value,
            'source' => $row['source']->value,
            'present' => $row['present'],
            'is_gap' => $row['is_gap'],
        ], $this->competencies->rows($this->user()));

        return $this->ok(['items' => array_values($items)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
