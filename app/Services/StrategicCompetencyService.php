<?php

namespace App\Services;

use App\Agents\StrategicCompetencyAgent;
use App\DTO\StrategicCompetencyResult;
use App\Enums\ProfessionalCompetencySource;
use App\Enums\ResumeLanguage;
use App\Enums\StrategicRole;
use App\Models\ProfessionalCompetency;
use App\Models\User;
use App\Support\Ai\AiRequestGate;
use App\Support\Ai\AiUsageHelper;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Classificação estratégica de competências do perfil profissional.
 *
 * O papel é contextual ao usuário: um mesmo conceito pode ter papéis
 * diferentes em perfis diferentes. Presença no currículo e lacuna são
 * dimensões separadas do papel — calculadas na leitura pelo ResumeAnalyzer,
 * nunca persistidas aqui.
 *
 * Precedência: registros manuais são autoridade e nunca são sobrescritos
 * pela IA; uma reclassificação AI substitui apenas registros AI anteriores.
 */
class StrategicCompetencyService
{
    public function __construct(
        private readonly StrategicCompetencyAgent $agent,
        private readonly ConceptNormalizer $normalizer,
        private readonly ResumeAnalyzer $resumeAnalyzer,
        private readonly ResumeService $resumeService,
        private readonly MarketAnalysisService $marketAnalysis,
        private readonly AiInputSnapshotService $snapshots,
        private readonly ?AiRequestGate $gate = null,
    ) {}

    /**
     * Run the AI classification for the user's profile and persist valid
     */
    public function classify(User $user): int
    {
        $resume = $this->resumeService->content($user, ResumeLanguage::PtBr);

        if (blank($resume)) {
            throw new LogicException('Cadastre o currículo antes de classificar as competências.');
        }

        $snapshot = $this->snapshots->strategicCompetency($user);

        if ($snapshot['payload']['candidate_concepts'] === []) {
            return 0;
        }

        return $this->classifyPayload($user, $snapshot['payload'], $snapshot['fingerprint']);
    }

    /**
     * Execute classification using the immutable payload captured at enqueue.
     *
     * @param  array<string, mixed>  $payload
     */
    public function classifyFromSnapshot(
        User $user,
        array $payload,
        string $fingerprint,
        ?Closure $persistWithinAttempt = null,
    ): int {
        if (blank($payload['resume'] ?? null)) {
            throw new LogicException('O snapshot da classificação não contém currículo.');
        }

        if ($payload['candidate_concepts'] === []) {
            return 0;
        }

        return $this->classifyPayload($user, $payload, $fingerprint, $persistWithinAttempt);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function classifyPayload(
        User $user,
        array $payload,
        string $fingerprint,
        ?Closure $persistWithinAttempt = null,
    ): int {

        $store = config('cache.lock_store', config('cache.default', 'file'));
        $stores = config('cache.stores', []);

        if (! isset($stores[$store])) {
            $store = 'file';
        }

        $opLock = Cache::store($store)->lock("strategic:classify:{$user->id}", 130);

        if (! $opLock->get()) {
            throw new \RuntimeException('Classificação já em andamento para este usuário. Aguarde a conclusão.');
        }

        $started = microtime(true);
        $inputChars = mb_strlen(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $usage = null;

        try {
            $gate = $this->gate ?? new AiRequestGate;
            $response = $gate->run(
                $this->agent->provider(),
                $this->agent->model(),
                fn () => $this->agent->prompt(json_encode($payload, JSON_UNESCAPED_UNICODE))
            );
            $usage = $response->usage ?? null;

            $data = json_decode($response->text, true);

            if (! is_array($data)) {
                throw new InvalidArgumentException('A resposta da IA não é um JSON válido.');
            }

            $result = StrategicCompetencyResult::fromStructured($data, $this->normalizer);

            $accepted = array_values(array_filter(
                $result->competencies,
                fn (array $competency): bool => in_array($competency['concept'], $payload['candidate_concepts'], true),
            ));

            $this->persistAi($user, $accepted, $fingerprint, $persistWithinAttempt);

            Log::channel('ai')->info('Strategic classification generated.', array_merge([
                'user_id' => $user->id,
                'operation' => 'strategic_classification',
                'provider' => $this->agent->provider(),
                'model' => $this->agent->model(),
                'input_chars' => $inputChars,
                'success' => true,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ], AiUsageHelper::extract($usage)));

            return count($accepted);
        } catch (Throwable $e) {
            Log::channel('ai')->error('Strategic classification failed.', array_merge([
                'user_id' => $user->id,
                'operation' => 'strategic_classification',
                'provider' => $this->agent->provider(),
                'model' => $this->agent->model(),
                'input_chars' => $inputChars,
                'success' => false,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $e->getMessage(),
            ], AiUsageHelper::extract($usage)));

            throw new \RuntimeException('Falha na classificação estratégica: '.$e->getMessage(), 0, $e);
        } finally {
            try {
                $opLock->release();
            } catch (Throwable) {
            }
        }
    }

    /**
     * Persist (upsert) a manual classification. Manual is authority: it
     * overwrites any previous AI classification for the same concept.
     *
     * @throws InvalidArgumentException when the name is empty after normalization
     */
    public function saveManual(User $user, string $concept, StrategicRole $role): ProfessionalCompetency
    {
        $normalized = $this->normalizer->normalize($concept);

        if ($normalized === '') {
            throw new InvalidArgumentException('Informe um nome de competência.');
        }

        if (strlen($normalized) > ProfessionalCompetency::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'O nome da competência excede o limite de %d caracteres.',
                ProfessionalCompetency::MAX_NAME_LENGTH,
            ));
        }

        return ProfessionalCompetency::updateOrCreate(
            ['user_id' => $user->id, 'concept' => $normalized],
            [
                'strategic_role' => $role,
                'source' => ProfessionalCompetencySource::Manual,
                'source_fingerprint' => null,
            ],
        );
    }

    /**
     * Classified rows of the user with presence/gap computed on read.
     *
     * @return array<int, array{
     *     __key: string,
     *     id: int,
     *     concept: string,
     *     strategic_role: StrategicRole,
     *     source: ProfessionalCompetencySource,
     *     present: bool,
     *     is_gap: bool,
     * }>
     */
    public function rows(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $content = $this->resumeService->content($user, ResumeLanguage::PtBr);

        return $user->professionalCompetencies()
            ->orderBy('concept')
            ->get()
            ->map(fn (ProfessionalCompetency $competency): array => [
                '__key' => (string) $competency->id,
                'id' => $competency->id,
                'concept' => $competency->concept,
                'strategic_role' => $competency->strategic_role,
                'source' => $competency->source,
                'present' => $this->resumeAnalyzer->contains($content, $competency->concept),
                'is_gap' => ! $this->resumeAnalyzer->contains($content, $competency->concept),
            ])
            ->all();
    }

    /**
     * Concept → classification map for the given user, for the market
     * comparison. Empty without a user; the caller stays in control of
     * whose context is consulted.
     *
     * @return array<string, ProfessionalCompetency>
     */
    public function roleMap(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return $user->professionalCompetencies()
            ->get()
            ->keyBy('concept')
            ->all();
    }

    /**
     * Remove a classification row; only when it belongs to the given user.
     */
    public function remove(User $user, int $competencyId): void
    {
        $user->professionalCompetencies()
            ->whereKey($competencyId)
            ->delete();
    }

    /**
     * Context sent to the agent: resume + market items with presence,
     * and the closed set of classifiable candidates.
     * Deduplicated: resume text already contains the concepts, so the
     * separate resume_concepts list is omitted; candidate_concepts remains
     * as the allowlist (market + resume concepts).
     *
     * @return array<string, mixed>
     */
    private function buildPayload(User $user, string $resume): array
    {
        $plainResume = ResumeService::plainText($resume);
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

        return [
            'resume' => $plainResume,
            'market_items' => $marketItems,
            'candidate_concepts' => array_keys($candidates),
        ];
    }

    /**
     * Replace the user's AI classifications with the accepted response:
     * manual rows are preserved and never overwritten; AI rows omitted by
     * the new response are removed. Nothing is written on failure.
     *
     * @param  array<int, array{concept: string, strategic_role: StrategicRole}>  $accepted
     */
    private function persistAi(
        User $user,
        array $accepted,
        string $fingerprint,
        ?Closure $persistWithinAttempt = null,
    ): void {
        $persist = function () use ($user, $accepted, $fingerprint): void {
            DB::transaction(function () use ($user, $accepted, $fingerprint): void {
                $manualConcepts = $user->professionalCompetencies()
                    ->where('source', ProfessionalCompetencySource::Manual)
                    ->pluck('concept')
                    ->all();

                foreach ($accepted as $competency) {
                    if (in_array($competency['concept'], $manualConcepts, true)) {
                        continue;
                    }

                    ProfessionalCompetency::updateOrCreate(
                        ['user_id' => $user->id, 'concept' => $competency['concept']],
                        [
                            'strategic_role' => $competency['strategic_role'],
                            'source' => ProfessionalCompetencySource::Ai,
                            'source_fingerprint' => $fingerprint,
                        ],
                    );
                }

                $acceptedConcepts = array_column($accepted, 'concept');

                $user->professionalCompetencies()
                    ->where('source', ProfessionalCompetencySource::Ai)
                    ->whereNotIn('concept', $acceptedConcepts)
                    ->delete();
            });
        };

        if ($persistWithinAttempt !== null) {
            $persistWithinAttempt($persist);

            return;
        }

        $persist();
    }
}
