<?php

namespace App\Mcp\Tools\Applications;

use App\Enums\ApplicationStatus;
use App\Mcp\Support\ApplicationPresenter;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\Application;
use App\Services\ApplicationTrackingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Registra uma atualização no acompanhamento de candidatura do usuário configurado: altera o status e cria um evento com título, notas e contato.')]
class RecordApplicationUpdateTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly ApplicationTrackingService $applications,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'application_id' => ['required', 'integer'],
            'status' => ['required', Rule::enum(ApplicationStatus::class)],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'contact' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $user = $this->user();

        $application = Application::query()
            ->whereKey((int) $validated['application_id'])
            ->whereHas('jobPosting', fn ($posting) => $posting->where('user_id', $user->id))
            ->first();

        if ($application === null) {
            throw ToolException::notFound('Candidatura não encontrada.');
        }

        $event = $this->applications->recordUpdate(
            $user,
            $application,
            ApplicationStatus::from($validated['status']),
            $validated['title'] ?? null,
            $validated['notes'] ?? null,
            $validated['contact'] ?? null,
            isset($validated['occurred_at']) ? Carbon::parse($validated['occurred_at']) : now(),
        );

        return $this->ok([
            'application' => ApplicationPresenter::toArray($application->refresh()),
            'event' => ApplicationPresenter::event($event),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'application_id' => $schema->integer()->required()->description('Id da candidatura.'),
            'status' => $schema->string()->required()->description('Novo status da candidatura.'),
            'title' => $schema->string()->description('Título do evento (padrão: rótulo do status).'),
            'notes' => $schema->string()->description('Notas do evento.'),
            'contact' => $schema->string()->description('Contato relacionado.'),
            'occurred_at' => $schema->string()->description('Quando ocorreu (ISO-8601). Padrão: agora.'),
        ];
    }
}
