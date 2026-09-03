<?php

namespace App\Services;

use App\DTO\ApplicationMethodData;
use App\Enums\ConceptCategory;
use App\Enums\ItemSource;
use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Confirmação e descarte da sugestão de reestruturação de uma vaga,
 * extraídos da camada Filament para manter a regra de negócio no domínio.
 */
final class JobReviewService
{
    public function __construct(private readonly ConceptNormalizer $normalizer) {}

    /**
     * Confirma a sugestão: substitui os itens da vaga pelos itens revisados,
     * atribuindo origem IA aos itens inalterados e origem usuário aos
     * editados/adicionados.
     *
     * @param  array<int, array{category: string, name: string}>  $items
     * @param  array<int, array<string, mixed>>  $applicationMethods
     */
    public function confirm(User $user, JobPosting $posting, array $items, array $applicationMethods, ?string $role, ?string $company): void
    {
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }

        $suggestedPairs = collect($posting->suggested_data['items'] ?? [])
            ->map(fn (array $item): string => $item['category'].'|'.$item['name'])
            ->all();

        $suggestedMethodKeys = collect($posting->suggested_data['application_methods'] ?? [])
            ->map(fn (array $method): ?string => ApplicationMethodData::fromArray($method)?->dedupKey())
            ->filter()
            ->all();

        DB::transaction(function () use ($posting, $items, $applicationMethods, $role, $company, $suggestedPairs, $suggestedMethodKeys): void {
            $posting->items()->delete();

            foreach ($items as $item) {
                $category = $item['category'] instanceof ConceptCategory ? $item['category']->value : $item['category'];
                $categoryEnum = ConceptCategory::tryFrom($category);
                $isFreeText = $categoryEnum?->isFreeText() ?? in_array($category, ['requirement', 'experience'], true);
                $name = $isFreeText
                    ? $this->normalizer->clean($item['name'])
                    : $this->normalizer->normalize($item['name']);

                if ($name === '') {
                    continue;
                }

                // Sem remap para categoria dona aqui: na revisão o usuário
                // é autoridade sobre a categoria; normaliza-se só o nome.

                $posting->items()->create([
                    'category' => $category,
                    'name' => $name,
                    'source' => in_array($category.'|'.$name, $suggestedPairs, true)
                        ? ItemSource::Ai
                        : ItemSource::User,
                ]);
            }

            $this->syncApplicationMethods($posting, $applicationMethods, $suggestedMethodKeys);

            $posting->forceFill([
                'role' => blank($role) ? null : $role,
                'company' => blank($company) ? null : $company,
                'status' => JobPostingStatus::Confirmed,
                'suggested_data' => null,
                'error_message' => null,
            ])->save();
        });
    }

    public function discard(User $user, JobPosting $posting): void
    {
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }

        $posting->applicationMethods()->delete();

        $posting->forceFill([
            'status' => JobPostingStatus::Pending,
            'suggested_data' => null,
            'role' => null,
            'company' => null,
            'error_message' => null,
        ])->save();
    }

    /**
     * Substitui os métodos de candidatura da vaga por uma entrada manual.
     *
     * @param  array<int, array<string, mixed>>  $methods
     */
    public function replaceApplicationMethods(User $user, JobPosting $posting, array $methods): void
    {
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }

        DB::transaction(function () use ($posting, $methods): void {
            $this->syncApplicationMethods($posting, $methods, []);
        });
    }

    private function syncApplicationMethods(JobPosting $posting, array $methods, array $suggestedKeys = []): void
    {
        $posting->applicationMethods()->delete();

        $seen = [];

        foreach ($methods as $method) {
            $data = $method instanceof ApplicationMethodData ? $method : ApplicationMethodData::fromArray($method);

            if ($data === null || isset($seen[$data->dedupKey()])) {
                continue;
            }

            $seen[$data->dedupKey()] = true;

            $posting->applicationMethods()->create([
                ...$data->toArray(),
                'source' => in_array($data->dedupKey(), $suggestedKeys, true)
                    ? ItemSource::Ai
                    : ItemSource::User,
                'dedup_key' => $data->dedupKey(),
            ]);
        }
    }
}
