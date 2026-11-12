<?php

namespace App\Services;

use App\Enums\AgentIntegrationScope;
use App\Enums\ResumeLanguage;
use App\Models\DocumentArtifact;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Support\Staleness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class DocumentArtifactService
{
    public function __construct(private readonly ResumePdfService $pdf, private readonly ApplicationDocumentAccess $access) {}

    /** @param array<string, mixed> $content */
    public function render(User $user, array $content, ResumeLanguage $language, string $type = 'resume'): DocumentArtifact
    {
        $this->access->authorize($user);
        $path = 'documents/'.$user->id.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->makeDirectory(dirname($path));
        try {
            $this->pdf->frozen($content, $language)->save(Storage::disk('local')->path($path));
            $bytes = Storage::disk('local')->get($path);
            if (! is_string($bytes) || $bytes === '') {
                throw new LogicException('O PDF não foi gerado.');
            }

            return new DocumentArtifact([
                'user_id' => $user->id, 'type' => $type, 'language' => $language->value, 'path' => $path,
                'sha256' => hash('sha256', $bytes), 'mime_type' => 'application/pdf', 'bytes' => strlen($bytes),
                'requires_ats_authorization' => (bool) ($content['is_ats_optimized'] ?? false),
                'expires_at' => now()->addHours(max(1, (int) config('application-documents.artifact_ttl_hours', 24))),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function exportCurrent(User $user, JobPosting $posting, ResumeLanguage $language, bool $atsConsent = false): DocumentArtifact
    {
        $posting = $posting->fresh();
        $source = $this->access->sourceHash($user, $posting);
        $adaptation = $this->current($user, $posting, $language, $atsConsent);
        $artifact = $this->render($user, $adaptation->getAttributes(), $language);
        try {
            DB::transaction(function () use ($user, $posting, $source, $artifact): void {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $lockedPosting = JobPosting::query()->lockForUpdate()->findOrFail($posting->id);
                $user->professionalProfile()->lockForUpdate()->first();
                $user->resumes()->orderBy('id')->lockForUpdate()->get();
                $lockedPosting->resumeAdaptations()->orderBy('id')->lockForUpdate()->get();
                $lockedPosting->items()->orderBy('id')->lockForUpdate()->get();
                $this->access->posting($user, $lockedPosting);
                $this->access->authorize($user, scope: AgentIntegrationScope::AtsRead);
                if ($source !== $this->access->sourceHash($user, $lockedPosting)) {
                    throw new LogicException('As fontes mudaram durante a exportação.');
                }
                $artifact->save();
            });

            return $artifact;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($artifact->path);
            throw $exception;
        }
    }

    public function current(User $user, JobPosting $posting, ResumeLanguage $language, bool $atsConsent = false): ResumeAdaptation
    {
        $this->access->authorize($user, scope: AgentIntegrationScope::AtsRead);
        $posting = $posting->fresh();
        $this->access->posting($user, $posting);
        $adaptation = $posting->resumeAdaptation($language)->firstOrFail();
        if (Staleness::isAdaptationStale($adaptation)) {
            throw new LogicException('A adaptação está desatualizada.');
        }
        if ($adaptation->is_ats_optimized) {
            Gate::forUser($user)->authorize('useAtsOptimization');
            if (! $atsConsent || blank($adaptation->ats_prompt) || Staleness::isAtsPromptStale($adaptation)) {
                throw new LogicException('Confirme o consentimento e atualize a otimização ATS antes de exportar.');
            }
        }

        return $adaptation;
    }

    /** @return array<string, mixed> */
    public function deliver(User $user, DocumentArtifact $artifact, bool $session = false): array
    {
        $this->assertDownload($user, $artifact, $session);
        $expiry = now()->addMinutes(max(1, (int) config('application-documents.link_ttl_minutes', 15)))->min($artifact->expires_at);
        $url = URL::temporarySignedRoute($session ? 'documents.session.download' : 'documents.api.download', $expiry, ['artifact' => $artifact->id]);
        if (! str_starts_with($url, 'https://')) {
            throw new LogicException('A entrega remota de documentos exige HTTPS.');
        }

        return [...$this->present($artifact), 'download_url' => $url, 'expires_at' => $expiry->toISOString()];
    }

    public function assertDownload(User $user, DocumentArtifact $artifact, bool $session = false): void
    {
        $this->access->authorize($user, scope: $session ? null : AgentIntegrationScope::AtsRead);
        abort_unless($artifact->user_id === $user->id, 404);
        abort_if($artifact->revoked_at !== null || $artifact->expires_at->lte(now()), 410);
        if ($artifact->requires_ats_authorization) {
            Gate::forUser($user)->authorize('useAtsOptimization');
        }
        abort_unless(Storage::disk('local')->exists($artifact->path), 410);
    }

    public function revoke(User $user, string $id): void
    {
        $this->access->authorize($user);
        $artifact = DocumentArtifact::query()->where('user_id', $user->id)->findOrFail($id);
        if ($artifact->revoked_at === null) {
            $artifact->forceFill(['revoked_at' => now()])->save();
        }
    }

    /** @return array<string, mixed> */
    public function present(DocumentArtifact $artifact): array
    {
        return [
            'artifact_id' => $artifact->id, 'type' => $artifact->type, 'language' => $artifact->language,
            'sha256' => $artifact->sha256, 'mime_type' => $artifact->mime_type, 'bytes' => $artifact->bytes,
            'artifact_expires_at' => $artifact->expires_at->toISOString(), 'revoked' => $artifact->revoked_at !== null,
        ];
    }
}
