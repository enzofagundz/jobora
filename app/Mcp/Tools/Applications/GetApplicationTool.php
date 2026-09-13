<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Support\ApplicationPresenter;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\ApplicationEvent;
use App\Models\JobPosting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Devolve a candidatura de uma vaga do usuário configurado, com o histórico de eventos.')]
class GetApplicationTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['job_posting_id' => ['required', 'integer']]);

        $posting = JobPosting::query()
            ->where('user_id', $this->user()->id)
            ->whereKey((int) $validated['job_posting_id'])
            ->first();

        if ($posting === null) {
            throw ToolException::notFound('Vaga não encontrada.');
        }

        $application = $posting->application()->first();

        if ($application === null) {
            throw ToolException::notFound('Candidatura não encontrada para esta vaga.');
        }

        return $this->ok([
            'application' => ApplicationPresenter::toArray($application),
            'events' => $application->events()
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get()
                ->map(fn (ApplicationEvent $event): array => ApplicationPresenter::event($event))
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'job_posting_id' => $schema->integer()->required()->description('Id da vaga.'),
        ];
    }
}
