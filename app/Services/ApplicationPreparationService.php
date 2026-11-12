<?php

namespace App\Services;

use App\Enums\AgentIntegrationScope;
use App\Enums\AiOperationType;
use App\Enums\ApplicationMethodType;
use App\Enums\ResumeLanguage;
use App\Mcp\Support\AgentIntegrationContext;
use App\Models\AiOperation;
use App\Models\Application;
use App\Models\ApplicationPreparation;
use App\Models\DocumentArtifact;
use App\Models\JobPosting;
use App\Models\ProfessionalProfile;
use App\Models\Resume;
use App\Models\User;
use App\Support\Staleness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LogicException;
use Throwable;

class ApplicationPreparationService
{
    public function __construct(private readonly ApplicationDocumentAccess $access, private readonly DocumentArtifactService $artifacts) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array{source?: string, integration_id?: int}  $actorMetadata
     * @return array<string, mixed>
     */
    public function prepare(User $user, array $input, array $actorMetadata = []): array
    {
        $input = Validator::validate(['input' => $input], [
            'input' => ['required', 'array:job_posting_id,expected_opportunity_version,expected_profile_confirmed_version,language,destination,questions,cover_letter_requested,cover_letter_required,translate_missing,ats_consent'],
            'input.job_posting_id' => ['required', 'integer', 'min:1'],
            'input.expected_opportunity_version' => ['sometimes', 'integer', 'min:1'],
            'input.expected_profile_confirmed_version' => ['required', 'integer', 'min:1'],
            'input.language' => ['sometimes', 'in:pt-BR,en'],
            'input.destination' => ['required', 'array:application_method_id,type,platform,url,email,phone,instructions'],
            'input.questions' => ['sometimes', 'array', 'max:100'],
            'input.questions.*' => ['array:id,question,answer,support'],
            'input.questions.*.id' => ['required', 'string', 'max:100', 'distinct'],
            'input.questions.*.question' => ['required', 'string', 'max:5000'],
            'input.questions.*.answer' => ['nullable', 'string', 'max:10000'],
            'input.questions.*.support' => ['sometimes', 'array', 'max:50'],
            'input.questions.*.support.*' => ['array:source,path,quote'],
            'input.questions.*.support.*.source' => ['required', 'in:profile,resume'],
            'input.questions.*.support.*.path' => ['required', 'string', 'max:255'],
            'input.questions.*.support.*.quote' => ['required', 'string', 'max:10000'],
            'input.cover_letter_requested' => ['sometimes', 'boolean:strict'],
            'input.cover_letter_required' => ['sometimes', 'boolean:strict'],
            'input.translate_missing' => ['sometimes', 'boolean:strict'],
            'input.ats_consent' => ['sometimes', 'boolean:strict'],
        ])['input'];
        $this->access->authorize($user, $actorMetadata, AgentIntegrationScope::ApplicationsWrite);
        $posting = JobPosting::query()->where('user_id', $user->id)->findOrFail($input['job_posting_id']);
        $this->access->posting($user, $posting);
        $language = ResumeLanguage::from($input['language'] ?? 'pt-BR');
        $profile = ProfessionalProfile::query()->where('user_id', $user->id)->first();
        $this->assertVersions($posting, $profile, $input);
        $destination = $this->destination($posting, $input['destination']);
        $pending = [];
        if (! ($input['cover_letter_requested'] ?? false)
            && (($input['cover_letter_required'] ?? false) || $this->requiresCoverLetter((string) ($destination['instructions'] ?? '')))) {
            $pending[] = ['code' => 'cover_letter_required', 'missing_fields' => ['cover_letter_requested']];
        }
        $resume = Resume::query()->where('user_id', $user->id)->where('language', $language->value)->first();
        if (blank($resume?->content)) {
            if ($language === ResumeLanguage::En && ($input['translate_missing'] ?? false)) {
                $this->access->authorize($user, $actorMetadata, AgentIntegrationScope::ResumeWrite);
                $operation = $this->active($user, AiOperationType::ResumeTranslation);
                $operation ??= app(AiOperationService::class)->enqueueResumeTranslation($user, $actorMetadata);

                return $this->preparing($posting, $profile, [$operation->id], $pending);
            }

            return $this->preparing($posting, $profile, [], [...$pending, ['code' => 'missing_resume', 'missing_fields' => ['resume.'.$language->value]]]);
        }
        $adaptation = $posting->resumeAdaptation($language)->first();
        if ($adaptation === null || Staleness::isAdaptationStale($adaptation)) {
            $this->access->authorize($user, $actorMetadata, AgentIntegrationScope::AtsWrite);
            $operation = $this->active($user, AiOperationType::ResumeAdaptation, $posting->id);
            $operation ??= app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting, $language, $actorMetadata);

            return $this->preparing($posting, $profile, [$operation->id], $pending);
        }
        $ats = (bool) ($input['ats_consent'] ?? false);
        if ($ats) {
            Gate::forUser($user)->authorize('useAtsOptimization');
            if (! $adaptation->is_ats_optimized || blank($adaptation->ats_prompt) || Staleness::isAtsPromptStale($adaptation)) {
                $this->access->authorize($user, $actorMetadata, AgentIntegrationScope::AtsWrite);
                $operation = $this->active($user, AiOperationType::AtsOptimization, $posting->id);
                $operation ??= app(AiOperationService::class)->enqueueAtsOptimization($user, $posting, true, $language, $actorMetadata);

                return $this->preparing($posting, $profile, [$operation->id], $pending);
            }
        }
        $sources = $this->sources($user, $posting, $language);
        $sourceHash = $this->hash($sources);
        $questions = $this->questions($input['questions'] ?? [], $sources, $pending);
        $frozenAdaptation = [
            'adapted_content' => $sources['adaptation']['adapted_content'], 'language' => $language->value,
            'is_ats_optimized' => $ats, 'ats_prompt' => $ats ? $sources['adaptation']['ats_prompt'] : null,
        ];
        $coverLetter = null;
        if ($input['cover_letter_requested'] ?? false) {
            $coverLetter = ($language === ResumeLanguage::En ? 'Application: ' : 'Candidatura: ')
                .(string) $sources['opportunity']['role'].' — '.(string) $sources['opportunity']['company']."\n\n".ResumeService::plainText($sources['resume']['content']);
        }
        $snapshot = [
            'profile' => $sources['profile']['confirmed_snapshot'], 'resume' => $sources['resume'],
            'opportunity' => $sources['opportunity'], 'adaptation' => $frozenAdaptation,
            'ats_consent' => $ats, 'cover_letter' => $coverLetter, 'questions' => $questions,
            'destination' => $destination, 'sources' => $sources,
        ];
        $application = Application::query()->firstOrCreate(['job_posting_id' => $posting->id]);
        $expectedPointerVersion = $application->preparation_version;
        $rendered = [];
        try {
            $rendered[] = $this->artifacts->render($user, $frozenAdaptation, $language);
            if ($coverLetter !== null) {
                $rendered[] = $this->artifacts->render($user, ['adapted_content' => $coverLetter, 'is_ats_optimized' => false], $language, 'cover_letter');
            }
            $preparation = DB::transaction(function () use ($user, $input, $actorMetadata, $posting, $language, $sourceHash, $snapshot, $pending, $application, $expectedPointerVersion, $rendered): ApplicationPreparation {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $lockedPosting = JobPosting::query()->lockForUpdate()->findOrFail($posting->id);
                $lockedProfile = ProfessionalProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();
                Resume::query()->where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get();
                $lockedPosting->resumeAdaptations()->orderBy('id')->lockForUpdate()->get();
                $lockedPosting->items()->orderBy('id')->lockForUpdate()->get();
                $lockedPosting->applicationMethods()->orderBy('id')->lockForUpdate()->get();
                $this->access->authorize($user, $actorMetadata, AgentIntegrationScope::ApplicationsWrite);
                $this->access->posting($user, $lockedPosting);
                $this->assertVersions($lockedPosting, $lockedProfile, $input);
                if ($sourceHash !== $this->hash($this->sources($user, $lockedPosting, $language))) {
                    throw new LogicException('As fontes mudaram durante a preparação. Repita com as versões atuais.');
                }
                $currentAdaptation = $lockedPosting->resumeAdaptation($language)->firstOrFail();
                if (Staleness::isAdaptationStale($currentAdaptation)
                    || (($snapshot['ats_consent'] ?? false) && (blank($currentAdaptation->ats_prompt) || Staleness::isAtsPromptStale($currentAdaptation)))) {
                    throw new LogicException('A adaptação ou otimização mudou durante a preparação.');
                }
                $versions = [
                    'profile_confirmed_version' => $lockedProfile->confirmed_version,
                    'opportunity_version' => $lockedPosting->opportunity_version,
                    'source_sha256' => $sourceHash,
                ];
                $fingerprint = $this->hash(['schema_version' => '1', 'snapshot' => $snapshot, 'source_versions' => $versions, 'pending' => $pending,
                    'pdfs' => array_map(fn (DocumentArtifact $artifact): array => ['type' => $artifact->type, 'sha256' => $artifact->sha256], $rendered)]);
                $preparation = ApplicationPreparation::query()->create([
                    'user_id' => $user->id, 'application_id' => $application->id, 'language' => $language->value,
                    'schema_version' => '1', 'state' => $pending === [] ? 'awaiting_approval' : 'pending_information',
                    'snapshot' => $snapshot, 'source_versions' => $versions, 'source_hash' => $sourceHash,
                    'fingerprint' => $fingerprint, 'pending' => $pending,
                ]);
                foreach ($rendered as $artifact) {
                    $artifact->preparation_id = $preparation->id;
                    $artifact->save();
                }
                if (Application::query()->whereKey($application->id)->where('preparation_version', $expectedPointerVersion)->update([
                    'current_preparation_id' => $preparation->id, 'preparation_version' => $expectedPointerVersion + 1,
                ]) !== 1) {
                    throw new LogicException('Outra preparação foi publicada. Consulte a preparação atual.');
                }

                return $preparation;
            });
        } catch (Throwable $exception) {
            foreach ($rendered as $artifact) {
                Storage::disk('local')->delete($artifact->path);
            }
            throw $exception;
        }

        return $this->result($preparation);
    }

    /** @return array<string, mixed> */
    public function get(User $user, string $id): array
    {
        $this->access->authorize($user, scope: AgentIntegrationScope::ApplicationsRead);
        $preparation = ApplicationPreparation::query()->where('user_id', $user->id)->findOrFail($id);
        $application = $preparation->application;
        $current = $application->current_preparation_id === $preparation->id;
        $stale = ! $this->isCurrentForApproval($user, $preparation);
        $snapshot = $preparation->snapshot;
        unset($snapshot['sources']);
        if (app()->bound(AgentIntegrationContext::class)) {
            $context = app(AgentIntegrationContext::class);
            $resumeReadable = $context->can(AgentIntegrationScope::ResumeRead);
            $profileReadable = $context->can(AgentIntegrationScope::ProfileRead);
            if (! $resumeReadable) {
                unset($snapshot['resume'], $snapshot['cover_letter']);
            }
            if (! $profileReadable) {
                unset($snapshot['profile']);
            }
            if (! $context->can(AgentIntegrationScope::AtsRead)) {
                unset($snapshot['adaptation']);
            }
            foreach ($snapshot['questions'] as &$question) {
                foreach ($question['support'] ?? [] as $support) {
                    if (($support['source'] === 'resume' && ! $resumeReadable)
                        || ($support['source'] === 'profile' && ! $profileReadable)) {
                        $question['answer'] = null;
                        $question['support'] = [];
                        break;
                    }
                }
            }
            unset($question);
        }

        return [...$this->result($preparation), 'snapshot' => $snapshot, 'stale' => $stale, 'current' => $current];
    }

    public function export(User $user, string $id, bool $atsConsent = false): DocumentArtifact
    {
        $this->access->authorize($user, scope: AgentIntegrationScope::AtsRead);
        $preparation = ApplicationPreparation::query()->where('user_id', $user->id)->findOrFail($id);
        if (($preparation->snapshot['ats_consent'] ?? false) && ! $atsConsent) {
            throw new LogicException('Confirme ats_consent=true para exportar o snapshot otimizado.');
        }

        return $preparation->artifacts()->where('type', 'resume')->firstOrFail();
    }

    public function isCurrentForApproval(User $user, ApplicationPreparation $preparation): bool
    {
        $owner = $user->fresh();
        if ($owner === null || $owner->disabled_at !== null || $preparation->user_id !== $user->id) {
            return false;
        }
        $preparation = ApplicationPreparation::query()->where('user_id', $user->id)->find($preparation->id);
        $application = $preparation?->application;
        $posting = $application?->jobPosting;

        return $preparation !== null && $posting !== null && $posting->user_id === $user->id
            && $application->current_preparation_id === $preparation->id && $posting->isEligibleForApplicationDocuments()
            && $preparation->source_hash === $this->hash($this->sources($user, $posting, ResumeLanguage::from($preparation->language)));
    }

    /** @param array<string, mixed> $input */
    private function assertVersions(JobPosting $posting, ?ProfessionalProfile $profile, array $input): void
    {
        if ($profile === null || $profile->confirmed_snapshot === null || $profile->confirmed_version !== $input['expected_profile_confirmed_version']) {
            throw new LogicException('Confirme o perfil e informe a versão confirmada atual.');
        }
        if (($posting->isStructuredOpportunity() && ! isset($input['expected_opportunity_version']))
            || (isset($input['expected_opportunity_version']) && $posting->opportunity_version !== $input['expected_opportunity_version'])) {
            throw new LogicException('Informe a versão atual da oportunidade.');
        }
    }

    /** @param array<string, mixed> $destination @return array<string, mixed> */
    private function destination(JobPosting $posting, array $destination): array
    {
        if (isset($destination['application_method_id'])) {
            Validator::validate($destination, ['application_method_id' => ['required', 'integer']]);
            if (count($destination) !== 1) {
                throw new LogicException('Escolha um método existente ou um destino explícito.');
            }
            $method = $posting->applicationMethods()->findOrFail($destination['application_method_id']);
            $destination = ['application_method_id' => $method->id, 'type' => $method->type->value,
                ...$method->only(['platform', 'url', 'email', 'phone', 'instructions'])];
        }
        $destination = Validator::validate($destination, [
            'application_method_id' => ['sometimes', 'integer'], 'type' => ['required', Rule::enum(ApplicationMethodType::class)],
            'platform' => ['nullable', 'string', 'max:255'], 'url' => ['nullable', 'url:http,https', 'max:4096'],
            'email' => ['nullable', 'email', 'max:320'], 'phone' => ['nullable', 'string', 'max:100'],
            'instructions' => ['nullable', 'string', 'max:10000'],
        ]);
        $field = match ($destination['type']) {
            'email' => 'email', 'phone', 'whatsapp' => 'phone', 'other' => 'instructions', default => 'url',
        };
        if (blank($destination[$field] ?? null)) {
            throw new LogicException('O destino exige '.$field.'.');
        }

        return $destination;
    }

    /** @return array<string, mixed> */
    private function sources(User $user, JobPosting $posting, ResumeLanguage $language): array
    {
        $profile = ProfessionalProfile::query()->where('user_id', $user->id)->first();
        $resume = Resume::query()->where('user_id', $user->id)->where('language', $language->value)->first();
        $adaptation = $posting->resumeAdaptation($language)->first();

        return [
            'profile' => ['confirmed_version' => $profile?->confirmed_version, 'confirmed_snapshot' => $profile?->confirmed_snapshot],
            'resume' => $resume?->only(['id', 'language', 'content', 'source_fingerprint']),
            'canonical_resume' => $language === ResumeLanguage::En
                ? Resume::query()->where('user_id', $user->id)->where('language', ResumeLanguage::PtBr->value)->first()?->only(['id', 'language', 'content', 'source_fingerprint'])
                : null,
            'opportunity' => $posting->only(['id', 'readiness', 'opportunity_version', 'status', 'role', 'company', 'description', 'source_url',
                'location', 'work_mode', 'employment_type', 'salary_min', 'salary_max', 'salary_currency', 'salary_period', 'seniority', 'requirements', 'technologies', 'evidence', 'review_reasons']),
            'items' => $posting->items()->orderBy('id')->get()->map(fn ($item): array => $item->only(['id', 'name', 'category', 'source', 'is_required']))->all(),
            'methods' => $posting->applicationMethods()->orderBy('id')->get()->map(fn ($method): array => $method->only(['id', 'type', 'platform', 'url', 'email', 'phone', 'instructions']))->all(),
            'adaptation' => $adaptation?->only(['id', 'language', 'adapted_content', 'source_fingerprint', 'is_ats_optimized', 'ats_prompt', 'ats_prompt_fingerprint', 'changes', 'covered_keywords', 'missing_requirements', 'alerts']),
        ];
    }

    /** @param list<array<string, mixed>> $questions @param array<string, mixed> $sources @param list<array<string, mixed>> $pending @return list<array<string, mixed>> */
    private function questions(array $questions, array $sources, array &$pending): array
    {
        usort($questions, fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        foreach ($questions as &$question) {
            $quotes = [];
            foreach ($question['support'] ?? [] as $support) {
                $document = $support['source'] === 'profile' ? $sources['profile']['confirmed_snapshot'] : $sources['resume'];
                $field = data_get($document, $support['path']);
                $field = is_string($field) ? ResumeService::plainText($field) : null;
                $pattern = '/(?<![\p{L}\p{M}\p{N}_+#.])'.preg_quote($support['quote'], '/').'(?![\p{L}\p{M}\p{N}_+#]|\.[\p{L}\p{M}\p{N}_])/u';
                if (! is_string($field) || trim($support['quote']) === ''
                    || preg_match($pattern, $support['quote']) !== 1 || preg_match($pattern, $field) !== 1) {
                    $quotes = [];
                    $question['support'] = [];
                    break;
                }
                $quotes[] = $support['quote'];
            }
            $answer = $question['answer'] ?? null;
            if ($quotes === [] || ! is_string($answer) || $answer !== implode("\n", $quotes)) {
                $question['answer'] = null;
                $pending[] = ['code' => 'unsupported_answer', 'question_id' => $question['id'], 'missing_fields' => ['answer', 'support']];
            }
        }

        return $questions;
    }

    private function active(User $user, AiOperationType $type, ?int $postingId = null): ?AiOperation
    {
        return AiOperation::query()->where('user_id', $user->id)->where('operation_type', $type)
            ->when($postingId !== null, fn ($query) => $query->where('job_posting_id', $postingId))->active()->first();
    }

    /** Recognizes only explicit PT/EN requirement phrases, not arbitrary application instructions. */
    private function requiresCoverLetter(string $instructions): bool
    {
        $instructions = mb_strtolower(preg_replace('/\s+/u', ' ', $instructions) ?? $instructions);
        $portugueseRequirement = '(?:obrigatória|requerida|exigida)';
        $englishRequirement = '(?:required|mandatory)';
        $separator = '(?:\s+|\s*[:\-–—(]\s*)';
        $negations = '/(?:carta de apresentação'.$separator.'(?:(?:não\s+(?:é|será)\s+)|(?:(?:é|será)\s+)?não\s+)'.$portugueseRequirement
            .'|não\s+(?:(?:é|será)\s+)?'.$portugueseRequirement.'\s*[:\-]?\s*(?:(?:a|uma)\s+)?carta de apresentação'
            .'|cover letter'.$separator.'(?:is\s+)?not\s+'.$englishRequirement
            .'|not\s+(?:a\s+)?'.$englishRequirement.'\s*[:\-]?\s*(?:a\s+)?cover letter'
            .'|no\s+cover letter\s+(?:is\s+)?'.$englishRequirement.')/u';
        $instructions = preg_replace($negations, ' ', $instructions) ?? $instructions;

        return preg_match('/(?<![\p{L}\p{M}\p{N}_])(?:carta de apresentação'.$separator.'(?:(?:é|será)\s+)?'.$portugueseRequirement
            .'|'.$portugueseRequirement.'\s*[:\-]?\s*(?:(?:a|uma)\s+)?carta de apresentação'
            .'|cover letter'.$separator.'(?:is\s+)?'.$englishRequirement
            .'|'.$englishRequirement.'\s*[:\-]?\s*(?:a\s+)?cover letter)(?![\p{L}\p{M}\p{N}_])/u', $instructions) === 1;
    }

    /** @param list<int> $operations @param list<array<string, mixed>> $pending @return array<string, mixed> */
    private function preparing(JobPosting $posting, ProfessionalProfile $profile, array $operations, array $pending = []): array
    {
        return ['preparation_id' => null, 'state' => $pending === [] ? 'preparing' : 'pending_information',
            'operation_ids' => $operations, 'pending' => $pending, 'fingerprint' => null,
            'source_versions' => ['profile_confirmed_version' => $profile->confirmed_version, 'opportunity_version' => $posting->opportunity_version], 'artifacts' => []];
    }

    /** @return array<string, mixed> */
    private function result(ApplicationPreparation $preparation): array
    {
        return ['preparation_id' => $preparation->id, 'state' => $preparation->state, 'operation_ids' => [],
            'pending' => $preparation->pending, 'fingerprint' => $preparation->fingerprint, 'source_versions' => $preparation->source_versions,
            'artifacts' => $preparation->artifacts()->get()->map(fn (DocumentArtifact $artifact): array => $this->artifacts->present($artifact))->all()];
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
