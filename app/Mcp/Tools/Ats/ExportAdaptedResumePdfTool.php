<?php

namespace App\Mcp\Tools\Ats;

use App\Enums\ResumeLanguage;
use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\DocumentArtifact;
use App\Models\JobPosting;
use App\Services\ApplicationPreparationService;
use App\Services\DocumentArtifactService;
use App\Services\ResumePdfService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Exporte PDF por preparation_id (currículo congelado), artifact_id (currículo ou carta congelada) ou job_posting_id (versão corrente pronta). Transporte remoto devolve artefato e URL HTTPS temporária com autenticação obrigatória; stdio preserva caminho local. ATS otimizado exige consentimento.')]
class ExportAdaptedResumePdfTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $input = $request->validate([
            'job_posting_id' => ['required_without_all:preparation_id,artifact_id', 'prohibits:preparation_id,artifact_id', 'integer'],
            'preparation_id' => ['required_without_all:job_posting_id,artifact_id', 'prohibits:job_posting_id,artifact_id', 'uuid'],
            'artifact_id' => ['required_without_all:job_posting_id,preparation_id', 'prohibits:job_posting_id,preparation_id', 'uuid'],
            'language' => ['sometimes', 'in:pt-BR,en'], 'ats_consent' => ['sometimes', 'boolean'],
        ]);
        $user = $this->user();
        $artifacts = app(DocumentArtifactService::class);
        if (! app()->bound(AgentIntegrationContext::class) && isset($input['job_posting_id'])) {
            $posting = JobPosting::query()->where('user_id', $user->id)->findOrFail($input['job_posting_id']);
            $adaptation = $artifacts->current($user, $posting, $this->language($request), (bool) ($input['ats_consent'] ?? false));
            $directory = 'mcp/pdf';
            Storage::disk('local')->makeDirectory($directory);
            $suffix = $adaptation->language === ResumeLanguage::En ? '-en' : '';
            $path = Storage::disk('local')->path($directory.'/adapted-resume-'.$posting->id.$suffix.'.pdf');
            app(ResumePdfService::class)->download($adaptation)->save($path);

            return $this->ok(['path' => $path, 'mime_type' => 'application/pdf', 'bytes' => is_file($path) ? (int) filesize($path) : 0, 'generated_at' => now()->toISOString()]);
        }
        $artifact = match (true) {
            isset($input['artifact_id']) => DocumentArtifact::query()->where('user_id', $user->id)->findOrFail($input['artifact_id']),
            isset($input['preparation_id']) => app(ApplicationPreparationService::class)->export($user, $input['preparation_id'], (bool) ($input['ats_consent'] ?? false)),
            default => $artifacts->exportCurrent($user, JobPosting::query()->where('user_id', $user->id)->findOrFail($input['job_posting_id']), $this->language($request), (bool) ($input['ats_consent'] ?? false)),
        };
        if ($artifact->requires_ats_authorization && ! ($input['ats_consent'] ?? false)) {
            throw ToolException::precondition('Confirme ats_consent=true para exportar o snapshot otimizado.');
        }
        if (app()->bound(AgentIntegrationContext::class)) {
            if ($artifact->revoked_at !== null || $artifact->expires_at->lte(now())) {
                throw ToolException::precondition('O artefato expirou ou foi revogado. Prepare uma nova versão.');
            }

            return $this->ok($artifacts->deliver($user, $artifact));
        }
        $artifacts->assertDownload($user, $artifact, true);

        return $this->ok([...$artifacts->present($artifact), 'path' => Storage::disk('local')->path($artifact->path), 'generated_at' => $artifact->created_at->toISOString()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'job_posting_id' => $schema->integer()->description('Id da vaga; não combine com preparation_id.'),
            'preparation_id' => $schema->string()->description('Id do snapshot imutável; não combine com job_posting_id.'),
            'artifact_id' => $schema->string()->description('Id de um artefato congelado, inclusive carta; não combine com outros ids.'),
            'ats_consent' => $schema->boolean(), 'language' => $schema->string()->enum(['pt-BR', 'en']),
        ];
    }
}
