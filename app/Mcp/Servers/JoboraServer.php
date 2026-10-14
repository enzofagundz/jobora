<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\JobPostingResource;
use App\Mcp\Resources\MarketSnapshotResource;
use App\Mcp\Resources\ProfessionalCompetenciesResource;
use App\Mcp\Resources\ResumeAdaptationResource;
use App\Mcp\Resources\ResumeResource;
use App\Mcp\Server\Methods\ReadResource;
use App\Mcp\Tools\Applications\GetApplicationTool;
use App\Mcp\Tools\Applications\ListApplicationsTool;
use App\Mcp\Tools\Applications\RecordApplicationUpdateTool;
use App\Mcp\Tools\Applications\StartApplicationTool;
use App\Mcp\Tools\Ats\AdaptResumeTool;
use App\Mcp\Tools\Ats\ExportAdaptedResumePdfTool;
use App\Mcp\Tools\Ats\GetResumeAdaptationTool;
use App\Mcp\Tools\Ats\ScoreAtsTool;
use App\Mcp\Tools\Ats\SetAtsOptimizationTool;
use App\Mcp\Tools\Captures\CaptureJobTool;
use App\Mcp\Tools\Captures\DeleteJobCaptureTool;
use App\Mcp\Tools\Captures\GetJobCaptureTool;
use App\Mcp\Tools\Captures\ListJobCapturesTool;
use App\Mcp\Tools\Captures\PromoteEligibleJobCapturesTool;
use App\Mcp\Tools\Captures\PromoteJobCaptureTool;
use App\Mcp\Tools\Captures\TriageJobCapturesTool;
use App\Mcp\Tools\Competencies\ClassifyProfessionalCompetenciesTool;
use App\Mcp\Tools\Competencies\ListProfessionalCompetenciesTool;
use App\Mcp\Tools\Competencies\RemoveProfessionalCompetencyTool;
use App\Mcp\Tools\Competencies\SetProfessionalCompetencyTool;
use App\Mcp\Tools\JobPostings\AnalyzeJobPostingTool;
use App\Mcp\Tools\JobPostings\ConfirmJobPostingTool;
use App\Mcp\Tools\JobPostings\CreateJobPostingTool;
use App\Mcp\Tools\JobPostings\DeleteJobPostingTool;
use App\Mcp\Tools\JobPostings\DiscardJobPostingSuggestionTool;
use App\Mcp\Tools\JobPostings\GetJobPostingTool;
use App\Mcp\Tools\JobPostings\ListJobPostingsTool;
use App\Mcp\Tools\Market\GetMarketComparisonTool;
use App\Mcp\Tools\Market\GetMarketFrequenciesTool;
use App\Mcp\Tools\Market\GetMarketPairsTool;
use App\Mcp\Tools\Market\GetMarketTrendsTool;
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
    Servidor MCP do Jobora, um sistema pessoal de análise do mercado de trabalho a partir de vagas.

    Regras de uso:
    - A identidade é fixa (variável JOBORA_MCP_USER): todas as tools operam o usuário configurado e nunca aceitam user_id.
    - Operações de escrita destrutivas ou de sobrescrita exigem o parâmetro confirm=true.
    - Operações de IA (análise, triagem, adequação, ATS, competências) são assíncronas: a tool enfileira e retorna; acompanhe o estado com get_job_posting, get_job_capture ou get_ai_operation. É preciso um worker ativo no Jobora.
    - Só vagas com status "confirmed" compõem as análises de mercado.
    MARKDOWN)]
final class JoboraServer extends Server
{
    protected array $tools = [
        GetAiOperationTool::class,
        CaptureJobTool::class,
        GetJobCaptureTool::class,
        ListJobCapturesTool::class,
        DeleteJobCaptureTool::class,
        TriageJobCapturesTool::class,
        PromoteJobCaptureTool::class,
        PromoteEligibleJobCapturesTool::class,
        CreateJobPostingTool::class,
        GetJobPostingTool::class,
        ListJobPostingsTool::class,
        DeleteJobPostingTool::class,
        AnalyzeJobPostingTool::class,
        ConfirmJobPostingTool::class,
        DiscardJobPostingSuggestionTool::class,
        GetResumeTool::class,
        SaveResumeTool::class,
        GetScreeningPreferencesTool::class,
        SaveScreeningPreferencesTool::class,
        GenerateSearchUrlTool::class,
        GetMarketFrequenciesTool::class,
        GetMarketPairsTool::class,
        GetMarketComparisonTool::class,
        GetMarketTrendsTool::class,
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
        GetApplicationTool::class,
        ListApplicationsTool::class,
        RecordApplicationUpdateTool::class,
    ];

    protected array $resources = [
        MarketSnapshotResource::class,
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
