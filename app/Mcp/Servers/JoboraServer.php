<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\JobPostingResource;
use App\Mcp\Resources\ProfessionalCompetenciesResource;
use App\Mcp\Resources\ResumeAdaptationResource;
use App\Mcp\Resources\ResumeResource;
use App\Mcp\Server\Methods\ReadResource;
use App\Mcp\Tools\Applications\ConsumeApplicationSubmissionTool;
use App\Mcp\Tools\Applications\GetApplicationApprovalTool;
use App\Mcp\Tools\Applications\GetApplicationPreparationTool;
use App\Mcp\Tools\Applications\GetApplicationSubmissionTool;
use App\Mcp\Tools\Applications\GetApplicationTool;
use App\Mcp\Tools\Applications\ListApplicationsTool;
use App\Mcp\Tools\Applications\PrepareApplicationTool;
use App\Mcp\Tools\Applications\ReconcileApplicationSubmissionTool;
use App\Mcp\Tools\Applications\RecordApplicationApprovalTool;
use App\Mcp\Tools\Applications\RecordApplicationUpdateTool;
use App\Mcp\Tools\Applications\ReportApplicationSubmissionTool;
use App\Mcp\Tools\Applications\ReserveApplicationSubmissionTool;
use App\Mcp\Tools\Applications\RevokeApplicationApprovalTool;
use App\Mcp\Tools\Applications\StartApplicationTool;
use App\Mcp\Tools\Applications\SubmitTelegramLinkTool;
use App\Mcp\Tools\Ats\AdaptResumeTool;
use App\Mcp\Tools\Ats\ExportAdaptedResumePdfTool;
use App\Mcp\Tools\Ats\GetResumeAdaptationTool;
use App\Mcp\Tools\Ats\ScoreAtsTool;
use App\Mcp\Tools\Ats\SetAtsOptimizationTool;
use App\Mcp\Tools\Captures\DeleteJobCaptureTool;
use App\Mcp\Tools\Captures\GetJobCaptureTool;
use App\Mcp\Tools\Captures\GetPageCaptureTool;
use App\Mcp\Tools\Captures\IngestPageCaptureTool;
use App\Mcp\Tools\Captures\ListJobCapturesTool;
use App\Mcp\Tools\Captures\ListPublicationsTool;
use App\Mcp\Tools\Captures\RequestPublicationTriageTool;
use App\Mcp\Tools\Competencies\ClassifyProfessionalCompetenciesTool;
use App\Mcp\Tools\Competencies\ListProfessionalCompetenciesTool;
use App\Mcp\Tools\Competencies\RemoveProfessionalCompetencyTool;
use App\Mcp\Tools\Competencies\SetProfessionalCompetencyTool;
use App\Mcp\Tools\Discovery\AcquireDiscoveryRunsTool;
use App\Mcp\Tools\Discovery\CreateDiscoverySourceTool;
use App\Mcp\Tools\Discovery\DeleteDiscoverySourceTool;
use App\Mcp\Tools\Discovery\GetDiscoveryRunTool;
use App\Mcp\Tools\Discovery\GetDiscoverySourceTool;
use App\Mcp\Tools\Discovery\ListDiscoveryRunsTool;
use App\Mcp\Tools\Discovery\ListDiscoverySourcesTool;
use App\Mcp\Tools\Discovery\ListPendingDiscoverySourcesTool;
use App\Mcp\Tools\Discovery\ReportDiscoveryAccessTool;
use App\Mcp\Tools\Discovery\UpdateDiscoverySourceTool;
use App\Mcp\Tools\JobPostings\AnalyzeJobPostingTool;
use App\Mcp\Tools\JobPostings\ConfirmJobPostingTool;
use App\Mcp\Tools\JobPostings\CreateJobPostingTool;
use App\Mcp\Tools\JobPostings\DeleteJobPostingTool;
use App\Mcp\Tools\JobPostings\DiscardJobPostingSuggestionTool;
use App\Mcp\Tools\JobPostings\GetJobPostingTool;
use App\Mcp\Tools\JobPostings\ListJobPostingsTool;
use App\Mcp\Tools\JobPostings\ReviewJobPostingTool;
use App\Mcp\Tools\Matching\GetMatchingTool;
use App\Mcp\Tools\Matching\RequestMatchingTool;
use App\Mcp\Tools\Profile\ConfirmProfessionalProfileTool;
use App\Mcp\Tools\Profile\ConfirmProfileImportTool;
use App\Mcp\Tools\Profile\GetProfessionalProfileTool;
use App\Mcp\Tools\Profile\GetProfileImportTool;
use App\Mcp\Tools\Profile\RejectProfileImportTool;
use App\Mcp\Tools\Profile\RequestProfileImportTool;
use App\Mcp\Tools\Profile\SaveProfessionalProfileTool;
use App\Mcp\Tools\Resume\GetResumeTool;
use App\Mcp\Tools\Resume\SaveResumeTool;
use App\Mcp\Tools\Screening\GetScreeningPreferencesTool;
use App\Mcp\Tools\Screening\SaveScreeningPreferencesTool;
use App\Mcp\Tools\Search\GenerateSearchUrlTool;
use App\Mcp\Tools\System\GetAiOperationTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Jobora')]
#[Version('0.1.0')]
#[Instructions(<<<'MARKDOWN'
    Servidor MCP privado do Jobora V2, com dados isolados por proprietário e permissões por integração.

    Regras de uso:
    - No transporte remoto, o token autentica o proprietário e a integração; scopes limitam cada operação. No stdio, JOBORA_MCP_USER define o proprietário. Nunca aceite user_id como troca de identidade.
    - Descoberta usa fontes e execuções reservadas. A extensão principal coleta envelopes nas superfícies LinkedIn publicações, LinkedIn Jobs e Indeed Jobs; ingest_page_capture persiste esses envelopes, sem equivaler ao antigo capture_job.
    - Perfil profissional confirmado e oportunidade revisada alimentam matching explicável e versionado. Clef não autoriza triagem automática: solicite request_publication_triage explicitamente.
    - Operações de escrita destrutivas ou de sobrescrita exigem o parâmetro confirm=true.
    - Operações de IA são assíncronas: acompanhe operações, publicações, oportunidades e matching. Um worker do Jobora processa essas operações.
    - Preparação e aprovação humana não enviam candidaturas. Só o primeiro consumo válido pode autorizar execução externa até execute_before; Hermes executa e relata evidência. Replay nunca autoriza, unknown bloqueia repetição e exige reconciliação. Não há garantia externa de exactly-once.
    - Ferramentas antigas de captura direta, triagem/promoção V1 e mercado não integram este catálogo. Histórico existente permanece privado e não é convertido silenciosamente.
    MARKDOWN)]
final class JoboraServer extends Server
{
    protected array $tools = [
        ReserveApplicationSubmissionTool::class,
        ConsumeApplicationSubmissionTool::class,
        GetApplicationSubmissionTool::class,
        ReportApplicationSubmissionTool::class,
        ReconcileApplicationSubmissionTool::class,
        RequestMatchingTool::class,
        GetMatchingTool::class,
        GetAiOperationTool::class,
        IngestPageCaptureTool::class,
        GetPageCaptureTool::class,
        ListPublicationsTool::class,
        RequestPublicationTriageTool::class,
        GetJobCaptureTool::class,
        ListJobCapturesTool::class,
        DeleteJobCaptureTool::class,
        CreateJobPostingTool::class,
        GetJobPostingTool::class,
        ReviewJobPostingTool::class,
        ListJobPostingsTool::class,
        DeleteJobPostingTool::class,
        AnalyzeJobPostingTool::class,
        ConfirmJobPostingTool::class,
        DiscardJobPostingSuggestionTool::class,
        GetResumeTool::class,
        GetProfessionalProfileTool::class,
        RequestProfileImportTool::class,
        GetProfileImportTool::class,
        ConfirmProfileImportTool::class,
        RejectProfileImportTool::class,
        SaveProfessionalProfileTool::class,
        ConfirmProfessionalProfileTool::class,
        SaveResumeTool::class,
        GetScreeningPreferencesTool::class,
        SaveScreeningPreferencesTool::class,
        GenerateSearchUrlTool::class,
        ListProfessionalCompetenciesTool::class,
        ClassifyProfessionalCompetenciesTool::class,
        SetProfessionalCompetencyTool::class,
        RemoveProfessionalCompetencyTool::class,
        GetResumeAdaptationTool::class,
        AdaptResumeTool::class,
        SetAtsOptimizationTool::class,
        ScoreAtsTool::class,
        ExportAdaptedResumePdfTool::class,
        StartApplicationTool::class,
        SubmitTelegramLinkTool::class,
        RecordApplicationApprovalTool::class,
        GetApplicationApprovalTool::class,
        RevokeApplicationApprovalTool::class,
        PrepareApplicationTool::class,
        GetApplicationPreparationTool::class,
        GetApplicationTool::class,
        ListApplicationsTool::class,
        RecordApplicationUpdateTool::class,
        ListDiscoverySourcesTool::class,
        GetDiscoverySourceTool::class,
        CreateDiscoverySourceTool::class,
        UpdateDiscoverySourceTool::class,
        DeleteDiscoverySourceTool::class,
        ListPendingDiscoverySourcesTool::class,
        AcquireDiscoveryRunsTool::class,
        ListDiscoveryRunsTool::class,
        GetDiscoveryRunTool::class,
        ReportDiscoveryAccessTool::class,
    ];

    protected array $resources = [
        ProfessionalCompetenciesResource::class,
        ResumeAdaptationResource::class,
        ResumeResource::class,
        JobPostingResource::class,
    ];

    protected array $prompts = [];

    protected function boot(): void
    {
        // Resolve resources de URI fixa também com query (`?language=...`).
        $this->addMethod('resources/read', ReadResource::class);
    }
}
