<?php

namespace App\Mcp\Tools\Captures;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationIdentityConflict;
use App\Models\PublicationObservation;
use App\Services\Discovery\PublicationExtractionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lista publicações de uma captura ou, com clef_status, do usuário. Inclui o resultado Clef da versão atual; confidence aplica-se à categoria non_job_kind.')]
class ListPublicationsTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly PublicationExtractionService $extractions,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'execution_id' => ['nullable', 'string', 'max:200', 'required_without_all:page_capture_id,clef_status'],
            'page_capture_id' => ['nullable', 'integer', 'required_without_all:execution_id,clef_status'],
            'clef_status' => ['nullable', 'in:pending,running,eligible,barred,inconclusive'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        if (isset($validated['execution_id'], $validated['page_capture_id'])) {
            throw ToolException::validation('Informe no máximo um entre execution_id e page_capture_id.');
        }

        if (! isset($validated['execution_id'])
            && ! isset($validated['page_capture_id'])
            && ! isset($validated['clef_status'])) {
            throw ToolException::validation('Informe execution_id, page_capture_id ou clef_status.');
        }

        $limit = (int) ($validated['limit'] ?? 20);
        $offset = (int) ($validated['offset'] ?? 0);

        if (isset($validated['execution_id']) || isset($validated['page_capture_id'])) {
            $capture = $this->capture($validated);

            if (! $this->extractions->supports((string) $capture->surface)) {
                throw ToolException::validation('A captura não pertence a uma superfície de publicações suportada.');
            }

            return $this->listCapture($capture, $validated['clef_status'] ?? null, $limit, $offset);
        }

        return $this->listUserPublications((string) $validated['clef_status'], $limit, $offset);
    }

    private function listCapture(PageCapture $capture, ?string $clefStatus, int $limit, int $offset): Response|ResponseFactory
    {
        $query = $capture->observations()->getQuery()->with('publication')->orderBy('id');

        if ($clefStatus !== null) {
            $this->applyClefStatusFilter($query, $clefStatus, true);
        }

        $total = (clone $query)->count();

        /** @var Collection<int, PublicationObservation> $observations */
        $observations = $query->offset($offset)->limit($limit)->get();

        $publications = $observations->pluck('publication')->filter();
        $conflicts = $this->conflicts($capture->user_id, $publications->pluck('id'));
        $clefResults = $this->currentClefResults($publications);
        $extraction = $this->extractions->describe($capture);

        $items = $observations->map(function (PublicationObservation $observation) use ($clefResults, $conflicts): array {
            $publication = $observation->publication;

            return [
                'publication' => $this->serializePublication($publication),
                'observation' => [
                    'id' => $observation->id,
                    'content' => $observation->content,
                    'content_hash' => $observation->content_hash,
                    'content_version' => $observation->content_version,
                    'evidence_block_ids' => $observation->evidence_block_ids ?? [],
                    'observed_at' => $observation->observed_at?->toIso8601String(),
                ],
                'clef' => $this->serializeClef($clefResults->get($publication->id)),
                'conflicts' => $this->serializeConflicts($conflicts, $observation->publication_id),
            ];
        })->values()->all();

        $next = $offset + $limit;

        return $this->ok([
            'capture' => [
                'id' => $capture->id,
                'execution_id' => $capture->execution_id,
                'surface' => $capture->surface,
                'captured_at' => $capture->captured_at?->toIso8601String(),
            ],
            'extraction' => $extraction,
            'conflicts' => $extraction['counts']['conflicts'],
            'items' => $items,
            'total' => $total,
            'next_offset' => $next < $total ? $next : null,
        ]);
    }

    private function listUserPublications(string $clefStatus, int $limit, int $offset): Response|ResponseFactory
    {
        $query = Publication::query()->where('user_id', $this->user()->id);
        $this->applyClefStatusFilter($query, $clefStatus);
        $total = (clone $query)->count();
        $publications = $query
            ->orderByDesc('last_observed_at')
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit)
            ->get();
        $conflicts = $this->conflicts($this->user()->id, $publications->pluck('id'));
        $clefResults = $this->currentClefResults($publications);
        $items = $publications->map(fn (Publication $publication): array => [
            'publication' => $this->serializePublication($publication),
            'observation' => null,
            'clef' => $this->serializeClef($clefResults->get($publication->id)),
            'conflicts' => $this->serializeConflicts($conflicts, $publication->id),
        ])->values()->all();
        $next = $offset + $limit;

        return $this->ok([
            'capture' => null,
            'extraction' => null,
            'conflicts' => $conflicts->flatten(1)->unique('id')->count(),
            'items' => $items,
            'total' => $total,
            'next_offset' => $next < $total ? $next : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function capture(array $validated): PageCapture
    {
        $query = PageCapture::query()->where('user_id', $this->user()->id);

        $capture = isset($validated['page_capture_id'])
            ? $query->whereKey((int) $validated['page_capture_id'])->first()
            : $query->where('execution_id', (string) $validated['execution_id'])->first();

        if ($capture === null) {
            throw ToolException::notFound('Captura não encontrada.');
        }

        return $capture;
    }

    /**
     * @return Collection<string, Collection<int, PublicationIdentityConflict>>
     */
    private function conflicts(int $userId, Collection $publicationIds): Collection
    {
        $publicationIds = $publicationIds->unique()->values();

        if ($publicationIds->isEmpty()) {
            return collect();
        }

        return PublicationIdentityConflict::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($publicationIds): void {
                $query->whereIn('publication_id', $publicationIds)
                    ->orWhereIn('other_publication_id', $publicationIds);
            })
            ->get()
            ->reduce(function (Collection $grouped, PublicationIdentityConflict $conflict): Collection {
                foreach (array_filter([$conflict->publication_id, $conflict->other_publication_id]) as $publicationId) {
                    $key = (string) $publicationId;
                    $grouped->put($key, $grouped->get($key, collect())->push($conflict));
                }

                return $grouped;
            }, collect());
    }

    /**
     * @param  Collection<string, Collection<int, PublicationIdentityConflict>>  $conflicts
     * @return list<array<string, mixed>>
     */
    private function serializeConflicts(Collection $conflicts, int $publicationId): array
    {
        return $conflicts->get((string) $publicationId, collect())
            ->map(fn (PublicationIdentityConflict $conflict): array => [
                'id' => $conflict->id,
                'reason' => $conflict->reason,
                'external_id' => $conflict->external_id,
                'canonical_url' => $conflict->canonical_url,
                'publication_id' => $conflict->publication_id,
                'other_publication_id' => $conflict->other_publication_id,
            ])
            ->values()
            ->all();
    }

    private function applyClefStatusFilter(Builder $query, string $status, bool $observationScoped = false): void
    {
        $query->whereExists(function ($matches) use ($status, $observationScoped): void {
            $matches->selectRaw('1')->from('publication_clef_results as current_clef');

            if ($observationScoped) {
                $matches->join('publications as current_publications', 'current_publications.id', '=', 'current_clef.publication_id')
                    ->whereColumn('current_clef.publication_id', 'publication_observations.publication_id')
                    ->whereColumn('current_clef.content_version', 'current_publications.content_version');
            } else {
                $matches->whereColumn('current_clef.publication_id', 'publications.id')
                    ->whereColumn('current_clef.content_version', 'publications.content_version');
            }

            $matches->where('current_clef.status', $status)
                ->whereNotExists(function ($newer): void {
                    $newer->selectRaw('1')
                        ->from('publication_clef_results as newer_clef')
                        ->whereColumn('newer_clef.publication_id', 'current_clef.publication_id')
                        ->whereColumn('newer_clef.content_version', 'current_clef.content_version')
                        ->whereColumn('newer_clef.attempt', '>', 'current_clef.attempt');
                });
        });
    }

    /**
     * @param  Collection<int, Publication>  $publications
     * @return Collection<int, PublicationClefResult>
     */
    private function currentClefResults(Collection $publications): Collection
    {
        $publicationIds = $publications->pluck('id')->unique()->values();

        if ($publicationIds->isEmpty()) {
            return collect();
        }

        return PublicationClefResult::query()
            ->join('publications', 'publications.id', '=', 'publication_clef_results.publication_id')
            ->whereIn('publication_clef_results.publication_id', $publicationIds)
            ->whereColumn('publication_clef_results.content_version', 'publications.content_version')
            ->orderByDesc('publication_clef_results.attempt')
            ->get(['publication_clef_results.*'])
            ->groupBy('publication_id')
            ->map(fn (Collection $attempts): PublicationClefResult => $attempts->first());
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePublication(Publication $publication): array
    {
        return [
            'id' => $publication->id,
            'external_id' => $publication->external_id,
            'url' => $publication->url,
            'content' => $publication->content,
            'content_version' => $publication->content_version,
            'last_observed_at' => $publication->last_observed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeClef(?PublicationClefResult $result): ?array
    {
        if ($result === null) {
            return null;
        }

        return [
            'status' => $result->status,
            'content_version' => $result->content_version,
            'probability' => $result->probability,
            'category' => $result->category,
            'confidence' => $result->confidence,
            'model' => $result->model,
            'classifier_identifier' => $result->classifier_identifier,
            'attempt' => $result->attempt,
            'error' => $result->error,
            'classified_at' => $result->classified_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()->description('Identificador opaco da execução.'),
            'page_capture_id' => $schema->integer()->description('Id da captura de página.'),
            'clef_status' => $schema->string()->enum(['pending', 'running', 'eligible', 'barred', 'inconclusive'])->description('Filtra publicações pelo resultado Clef da versão atual.'),
            'limit' => $schema->integer()->description('Máximo de 100 itens.'),
            'offset' => $schema->integer()->description('Deslocamento da paginação.'),
        ];
    }
}
