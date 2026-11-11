<?php

namespace App\Enums;

use App\Mcp\Resources\JobPostingResource;
use App\Mcp\Resources\MarketSnapshotResource;
use App\Mcp\Resources\ProfessionalCompetenciesResource;
use App\Mcp\Resources\ResumeAdaptationResource;
use App\Mcp\Resources\ResumeResource;

enum AgentIntegrationScope: string
{
    case CapturesRead = 'captures:read';
    case CapturesWrite = 'captures:write';
    case JobPostingsRead = 'job_postings:read';
    case JobPostingsWrite = 'job_postings:write';
    case ResumeRead = 'resume:read';
    case ResumeWrite = 'resume:write';
    case ScreeningRead = 'screening:read';
    case ScreeningWrite = 'screening:write';
    case MarketRead = 'market:read';
    case CompetenciesRead = 'competencies:read';
    case CompetenciesWrite = 'competencies:write';
    case AtsRead = 'ats:read';
    case AtsWrite = 'ats:write';
    case ApplicationsRead = 'applications:read';
    case ApplicationsWrite = 'applications:write';
    case SearchRead = 'search:read';
    case OperationsRead = 'operations:read';
    case SourcesRead = 'sources:read';
    case SourcesWrite = 'sources:write';
    case RunsRead = 'runs:read';
    case RunsWrite = 'runs:write';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }

    /**
     * @return array<int, string>
     */
    public static function defaults(): array
    {
        return self::values();
    }

    public function label(): string
    {
        return match ($this) {
            self::CapturesRead => 'Ler capturas de vagas',
            self::CapturesWrite => 'Criar, excluir, triar e promover capturas',
            self::JobPostingsRead => 'Ler vagas',
            self::JobPostingsWrite => 'Criar, excluir, analisar e confirmar vagas',
            self::ResumeRead => 'Ler currículo',
            self::ResumeWrite => 'Salvar currículo',
            self::ScreeningRead => 'Ler preferências de triagem',
            self::ScreeningWrite => 'Salvar preferências de triagem',
            self::MarketRead => 'Ler dados de mercado',
            self::CompetenciesRead => 'Ler competências profissionais',
            self::CompetenciesWrite => 'Classificar, salvar e remover competências',
            self::AtsRead => 'Ler adaptações e exportar PDF',
            self::AtsWrite => 'Adaptar currículo e calcular ATS',
            self::ApplicationsRead => 'Ler candidaturas',
            self::ApplicationsWrite => 'Iniciar e movimentar candidaturas',
            self::SearchRead => 'Gerar URLs de busca',
            self::OperationsRead => 'Ler operações assíncronas',
            self::SourcesRead => 'Ler fontes de descoberta',
            self::SourcesWrite => 'Criar, editar e remover fontes de descoberta',
            self::RunsRead => 'Ler execuções de descoberta',
            self::RunsWrite => 'Adquirir execuções e relatar acesso',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $scope) {
            $labels[$scope->value] = $scope->label();
        }

        return $labels;
    }

    /**
     * @param  class-string  $tool
     */
    public static function forTool(string $tool): self
    {
        return match (class_basename($tool)) {
            'GetApplicationTool', 'ListApplicationsTool' => self::ApplicationsRead,
            'StartApplicationTool', 'RecordApplicationUpdateTool' => self::ApplicationsWrite,
            'GetResumeAdaptationTool', 'ExportAdaptedResumePdfTool' => self::AtsRead,
            'AdaptResumeTool', 'SetAtsOptimizationTool', 'ScoreAtsTool' => self::AtsWrite,
            'GetJobCaptureTool', 'ListJobCapturesTool', 'GetPageCaptureTool', 'ListPublicationsTool' => self::CapturesRead,
            'CaptureJobTool', 'IngestPageCaptureTool', 'DeleteJobCaptureTool', 'TriageJobCapturesTool',
            'PromoteJobCaptureTool', 'PromoteEligibleJobCapturesTool', 'RequestPublicationTriageTool' => self::CapturesWrite,
            'ListProfessionalCompetenciesTool' => self::CompetenciesRead,
            'ClassifyProfessionalCompetenciesTool', 'SetProfessionalCompetencyTool',
            'RemoveProfessionalCompetencyTool' => self::CompetenciesWrite,
            'GetJobPostingTool', 'ListJobPostingsTool' => self::JobPostingsRead,
            'CreateJobPostingTool', 'DeleteJobPostingTool', 'AnalyzeJobPostingTool',
            'ConfirmJobPostingTool', 'DiscardJobPostingSuggestionTool',
            'ReviewJobPostingTool' => self::JobPostingsWrite,
            'GetMarketComparisonTool', 'GetMarketFrequenciesTool',
            'GetMarketPairsTool', 'GetMarketTrendsTool' => self::MarketRead,
            'GetResumeTool' => self::ResumeRead,
            'SaveResumeTool' => self::ResumeWrite,
            'GetScreeningPreferencesTool' => self::ScreeningRead,
            'SaveScreeningPreferencesTool' => self::ScreeningWrite,
            'GenerateSearchUrlTool' => self::SearchRead,
            'GetAiOperationTool' => self::OperationsRead,
            'ListDiscoverySourcesTool', 'GetDiscoverySourceTool' => self::SourcesRead,
            'CreateDiscoverySourceTool', 'UpdateDiscoverySourceTool',
            'DeleteDiscoverySourceTool' => self::SourcesWrite,
            'ListPendingDiscoverySourcesTool' => self::SourcesRead,
            'ListDiscoveryRunsTool', 'GetDiscoveryRunTool' => self::RunsRead,
            'AcquireDiscoveryRunsTool', 'ReportDiscoveryAccessTool' => self::RunsWrite,
            default => throw new \LogicException("Sem escopo de integração para {$tool}."),
        };
    }

    /**
     * @param  class-string  $resource
     */
    public static function forResource(string $resource): self
    {
        return match ($resource) {
            ResumeResource::class => self::ResumeRead,
            ResumeAdaptationResource::class => self::AtsRead,
            JobPostingResource::class => self::JobPostingsRead,
            MarketSnapshotResource::class => self::MarketRead,
            ProfessionalCompetenciesResource::class => self::CompetenciesRead,
            default => throw new \LogicException("Sem escopo de integração para {$resource}."),
        };
    }
}
