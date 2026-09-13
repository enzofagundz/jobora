<?php

namespace App\Mcp\Tools\Competencies;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Services\StrategicCompetencyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Remove uma competência estratégica do usuário configurado. Operação destrutiva: exige confirm=true.')]
class RemoveProfessionalCompetencyTool extends JoboraTool
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
            'id' => ['required', 'integer'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $this->requireConfirm($request);

        $user = $this->user();
        $id = (int) $validated['id'];

        $competency = $user->professionalCompetencies()->whereKey($id)->first();

        if ($competency === null) {
            throw ToolException::notFound('Competência não encontrada.');
        }

        $this->competencies->remove($user, $id);

        return $this->ok(['deleted' => true, 'id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da competência.'),
            'confirm' => $schema->boolean()->required()->description('Envie true para confirmar a remoção.'),
        ];
    }
}
