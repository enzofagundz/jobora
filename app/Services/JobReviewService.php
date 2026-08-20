<?php

namespace App\Services;

use App\Enums\ConceptCategory;
use App\Enums\ItemSource;
use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use Illuminate\Support\Facades\DB;

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
     */
    public function confirm(JobPosting $posting, array $items, ?string $role, ?string $company): void
    {
        $suggestedPairs = collect($posting->suggested_data['items'] ?? [])
            ->map(fn (array $item): string => $item['category'].'|'.$item['name'])
            ->all();

        DB::transaction(function () use ($posting, $items, $role, $company, $suggestedPairs): void {
            $posting->items()->delete();

            foreach ($items as $item) {
                $category = $item['category'] instanceof ConceptCategory ? $item['category']->value : $item['category'];
                $name = $this->normalizer->normalize($item['name']);

                if ($name === '') {
                    continue;
                }

                $posting->items()->create([
                    'category' => $category,
                    'name' => $name,
                    'source' => in_array($category.'|'.$name, $suggestedPairs, true)
                        ? ItemSource::Ai
                        : ItemSource::User,
                ]);
            }

            $posting->forceFill([
                'role' => blank($role) ? null : $role,
                'company' => blank($company) ? null : $company,
                'status' => JobPostingStatus::Confirmed,
                'suggested_data' => null,
                'error_message' => null,
            ])->save();
        });
    }

    public function discard(JobPosting $posting): void
    {
        $posting->forceFill([
            'status' => JobPostingStatus::Pending,
            'suggested_data' => null,
            'role' => null,
            'company' => null,
            'error_message' => null,
        ])->save();
    }
}
