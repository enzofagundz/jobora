<?php

namespace App\Services;

use App\Enums\ResumeLanguage;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

class AiInputSnapshotService
{
    public function __construct(
        private readonly ResumeAnalyzer $resumeAnalyzer,
        private readonly ResumeService $resumeService,
        private readonly MarketAnalysisService $marketAnalysis,
        private readonly AtsPromptBuilder $atsPromptBuilder,
    ) {}

    /**
     * @return array{payload: array<string, mixed>, fingerprint: string}
     */
    public function resumeAdaptation(User $user, JobPosting $posting, ResumeLanguage $language): array
    {
        $resume = ResumeService::plainText($this->resumeService->content($user, $language));
        $description = ResumeService::plainText($posting->description);
        $items = $this->orderedItems($posting);

        $positioning = $items
            ->map(fn ($item): array => [
                'name' => $item->name,
                'category' => $item->category->value,
                'present_in_resume' => $this->resumeAnalyzer->hasConcept($resume, $item->name),
            ])
            ->values()
            ->all();

        $payload = [
            'language' => $language->value,
            'resume' => $resume,
            'job_description' => $description,
            'job_role' => $posting->role,
            'job_company' => $posting->company,
            'positioning' => $positioning,
        ];

        return [
            'payload' => $payload,
            'fingerprint' => $this->fingerprint($payload),
        ];
    }

    /**
     * Snapshot for the PT-BR → EN translation: the canonical resume text and
     * its fingerprint, so drift can be detected after the translation.
     *
     * @return array{payload: array<string, mixed>, fingerprint: string}
     */
    public function resumeTranslation(User $user): array
    {
        $resume = ResumeService::plainText($this->resumeService->content($user, ResumeLanguage::PtBr));

        $payload = [
            'resume' => $resume,
        ];

        return [
            'payload' => $payload,
            'fingerprint' => $this->fingerprint($payload),
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, fingerprint: string}
     */
    public function strategicCompetency(User $user): array
    {
        $plainResume = ResumeService::plainText($this->resumeService->content($user, ResumeLanguage::PtBr));
        $resumeConcepts = $this->resumeAnalyzer->conceptsIn($plainResume);

        $marketItems = array_map(function (array $row) use ($plainResume): array {
            return [
                'category' => $row['category'],
                'name' => $row['name'],
                'total' => $row['total'],
                'present_in_resume' => $this->resumeAnalyzer->contains($plainResume, $row['name']),
            ];
        }, $this->marketAnalysis->frequencies($user));

        $candidates = [];

        foreach ($marketItems as $item) {
            $candidates[$item['name']] = true;
        }

        foreach ($resumeConcepts as $concept) {
            $candidates[$concept] = true;
        }

        $payload = [
            'resume' => $plainResume,
            'market_items' => $marketItems,
            'candidate_concepts' => array_keys($candidates),
        ];

        return [
            'payload' => $payload,
            'fingerprint' => $this->fingerprint($payload),
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, fingerprint: string}
     */
    public function atsScore(JobPosting $posting, ResumeLanguage $language, ?ResumeAdaptation $adaptation = null): array
    {
        $adaptation ??= $this->resolveAdaptation($posting, $language);

        if (! $adaptation instanceof ResumeAdaptation) {
            throw new LogicException('Gere a adaptação do currículo antes de simular a nota.');
        }

        $resume = $adaptation->adapted_content;

        if ($adaptation->is_ats_optimized && filled($adaptation->ats_prompt)) {
            $resume .= "\n\n".$adaptation->ats_prompt;
        }

        $items = $this->orderedItems($posting);
        $user = $posting->user;
        $payload = [
            'language' => $language->value,
            'resume' => ResumeService::plainText($resume),
            'source_resume' => ResumeService::plainText(
                $user !== null ? $this->resumeService->content($user, $language) : null,
            ),
            'job_description' => ResumeService::plainText($posting->description),
            'job_role' => $posting->role,
            'job_company' => $posting->company,
            'items' => $items->map(fn ($item): array => [
                'name' => $item->name,
                'category' => $item->category->value,
            ])->values()->all(),
        ];

        return [
            'payload' => $payload,
            'fingerprint' => $this->fingerprint($payload),
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, fingerprint: string}
     */
    public function atsOptimization(User $user, JobPosting $posting, bool $enabled, ResumeLanguage $language): array
    {
        $adaptation = $this->resolveAdaptation($posting, $language);

        if (! $adaptation instanceof ResumeAdaptation) {
            throw new LogicException('Gere a adaptação do currículo antes de otimizar para ATS.');
        }

        $resume = ResumeService::plainText($this->resumeService->content($user, $language));
        $items = $this->orderedItems($posting);
        $prompt = $enabled
            ? $this->atsPromptBuilder->build($posting, $resume, $language)
            : null;
        $payload = [
            'enabled' => $enabled,
            'language' => $language->value,
            'resume' => $resume,
            'job_role' => $posting->role,
            'job_company' => $posting->company,
            'items' => $items->map(fn ($item): array => [
                'name' => $item->name,
                'category' => $item->category->value,
            ])->values()->all(),
            'ats_prompt' => $prompt,
        ];

        return [
            'payload' => $payload,
            'fingerprint' => $this->fingerprint($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fingerprint(array $payload): string
    {
        return hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    private function resolveAdaptation(JobPosting $posting, ResumeLanguage $language): ?ResumeAdaptation
    {
        return $posting->resumeAdaptation($language)->first();
    }

    private function orderedItems(JobPosting $posting): Collection
    {
        $items = $posting->relationLoaded('items') ? $posting->items : $posting->items()->get();

        return $items
            ->sortBy(fn ($item): string => mb_strtolower($item->category->value.'|'.$item->name))
            ->values();
    }
}
