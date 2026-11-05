<?php

namespace App\Mcp\Tools\Captures;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\PageCapture;
use App\Models\PublicationIdentityConflict;
use App\Models\PublicationObservation;
use App\Services\Discovery\PublicationExtractionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lista publicações observadas por uma captura de página, com conteúdo, versão e conflitos de identidade. Informe exatamente execution_id ou page_capture_id.')]
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
            'execution_id' => ['nullable', 'string', 'max:200', 'required_without:page_capture_id'],
            'page_capture_id' => ['nullable', 'integer', 'required_without:execution_id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        if (isset($validated['execution_id'], $validated['page_capture_id'])) {
            throw ToolException::validation('Informe exatamente execution_id ou page_capture_id.');
        }

        $capture = $this->capture($validated);

        if (! $this->extractions->supports((string) $capture->surface)) {
            throw ToolException::validation('A captura não pertence a uma superfície de publicações suportada.');
        }

        $limit = (int) ($validated['limit'] ?? 20);
        $offset = (int) ($validated['offset'] ?? 0);

        $query = $capture->observations()->with('publication')->orderBy('id');
        $total = (clone $query)->count();

        /** @var Collection<int, PublicationObservation> $observations */
        $observations = $query->offset($offset)->limit($limit)->get();

        $conflicts = $this->conflicts($capture->user_id, $observations);
        $extraction = $this->extractions->describe($capture);

        $items = $observations->map(fn (PublicationObservation $observation): array => [
            'publication' => [
                'id' => $observation->publication->id,
                'external_id' => $observation->publication->external_id,
                'url' => $observation->publication->url,
                'content' => $observation->publication->content,
                'content_version' => $observation->publication->content_version,
                'last_observed_at' => $observation->publication->last_observed_at?->toIso8601String(),
            ],
            'observation' => [
                'id' => $observation->id,
                'content' => $observation->content,
                'content_hash' => $observation->content_hash,
                'content_version' => $observation->content_version,
                'evidence_block_ids' => $observation->evidence_block_ids ?? [],
                'observed_at' => $observation->observed_at?->toIso8601String(),
            ],
            'conflicts' => $this->serializeConflicts($conflicts, $observation->publication_id),
        ])->values()->all();

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
     * @param  Collection<int, PublicationObservation>  $observations
     * @return Collection<string, Collection<int, PublicationIdentityConflict>>
     */
    private function conflicts(int $userId, Collection $observations): Collection
    {
        $publicationIds = $observations->pluck('publication_id')->unique()->values();

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

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()->description('Identificador opaco da execução.'),
            'page_capture_id' => $schema->integer()->description('Id da captura de página.'),
            'limit' => $schema->integer()->description('Máximo de 100 itens.'),
            'offset' => $schema->integer()->description('Deslocamento da paginação.'),
        ];
    }
}
