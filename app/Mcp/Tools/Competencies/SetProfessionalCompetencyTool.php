<?php

namespace App\Mcp\Tools\Competencies;

use App\Enums\StrategicRole;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Tools\JoboraTool;
use App\Services\StrategicCompetencyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Define ou atualiza manualmente o papel estratégico de uma competência do usuário configurado. A classificação manual prevalece sobre a da IA.')]
class SetProfessionalCompetencyTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly StrategicCompetencyService $competencies,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'concept' => ['required', 'string', 'max:100'],
            'strategic_role' => ['required', Rule::enum(StrategicRole::class)],
        ]);

        $competency = $this->competencies->saveManual(
            $this->user(),
            $validated['concept'],
            StrategicRole::from($validated['strategic_role']),
        );

        return $this->ok([
            'competency' => [
                'id' => $competency->id,
                'concept' => $competency->concept,
                'strategic_role' => $competency->strategic_role?->value,
                'source' => $competency->source?->value,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'concept' => $schema->string()->required()->description('Conceito (ex.: PHP, Kubernetes).'),
            'strategic_role' => $schema->string()->required()->description('core, expansion ou differentiation.'),
        ];
    }
}
