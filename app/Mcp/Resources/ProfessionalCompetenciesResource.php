<?php

namespace App\Mcp\Resources;

use App\Mcp\Support\McpUserResolver;
use App\Services\StrategicCompetencyService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Uri;

#[Uri('jobora://professional-competencies')]
#[Description('Competências estratégicas do usuário configurado: conceito, papel (core/expansion/differentiation), origem (ai/manual) e presença ou lacuna no currículo.')]
class ProfessionalCompetenciesResource extends JoboraResource
{
    public function __construct(
        McpUserResolver $users,
        private readonly StrategicCompetencyService $competencies,
    ) {
        parent::__construct($users);
    }

    protected function read(Request $request): Response
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
}
