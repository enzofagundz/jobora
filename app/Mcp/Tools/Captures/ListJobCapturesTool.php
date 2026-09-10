<?php

namespace App\Mcp\Tools\Captures;

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Mcp\Support\JobCapturePresenter;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobCapture;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lista as capturas de vaga do usuário configurado, com filtros de triagem, promoção e período. Paginado (limit 20, máx 100).')]
class ListJobCapturesTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'triage_status' => ['nullable', 'string'],
            'triage_result' => ['nullable', 'string'],
            'promoted' => ['nullable', 'boolean'],
            'source' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $page = $this->page($request);

        $query = JobCapture::query()->where('user_id', $this->user()->id);

        if (! empty($validated['triage_status'])) {
            $status = JobCaptureTriageStatus::tryFrom((string) $validated['triage_status']);

            if ($status === null) {
                throw ToolException::validation('Status de triagem inválido.');
            }

            $query->where('triage_status', $status);
        }

        if (! empty($validated['triage_result'])) {
            $result = JobCaptureTriageResult::tryFrom((string) $validated['triage_result']);

            if ($result === null) {
                throw ToolException::validation('Resultado de triagem inválido.');
            }

            $query->where('triage_result', $result);
        }

        if (array_key_exists('promoted', $validated) && $validated['promoted'] !== null) {
            $validated['promoted']
                ? $query->whereNotNull('job_posting_id')
                : $query->whereNull('job_posting_id');
        }

        if (! empty($validated['source'])) {
            $query->where('source', mb_strtolower(trim((string) $validated['source'])));
        }

        if (! empty($validated['from'])) {
            $query->where('captured_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->where('captured_at', '<=', $validated['to']);
        }

        $total = (clone $query)->count();

        $captures = $query
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->skip($page['offset'])
            ->take($page['limit'])
            ->get()
            ->map(fn (JobCapture $capture): array => JobCapturePresenter::toArray($capture))
            ->all();

        return $this->listed($captures, $total, $page['limit'], $page['offset']);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'triage_status' => $schema->string()->description('pending, processing, completed ou failed.'),
            'triage_result' => $schema->string()->description('relevant, rejected, not_a_job ou uncertain.'),
            'promoted' => $schema->boolean()->description('true = já virou vaga; false = ainda não promovida.'),
            'source' => $schema->string()->description('Origem (ex.: linkedin, indeed).'),
            'from' => $schema->string()->description('Data inicial (ISO-8601).'),
            'to' => $schema->string()->description('Data final (ISO-8601).'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Itens por página (padrão 20).'),
            'offset' => $schema->integer()->min(0)->description('Deslocamento para paginação.'),
        ];
    }
}
