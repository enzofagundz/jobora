<?php

namespace App\Mcp\Tools\JobPostings;

use App\DTO\PublicationTriageOutput;
use App\Enums\ApplicationMethodType;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobPosting;
use App\Services\OpportunityReviewConflict;
use App\Services\OpportunityReviewService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
#[Description('Corrige oportunidade estruturada em needs_review com controle de versão. source_url e evidence aceitam correção auditada, verificada contra snapshot persistido; resultado original da IA permanece intacto. Não confirma vaga V1. Replay idempotente pela chave ou conteúdo e versão esperada.')]
class ReviewJobPostingTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly OpportunityReviewService $reviews,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'corrections' => ['required', 'array:role,company,description,location,work_mode,employment_type,salary_min,salary_max,salary_currency,salary_period,seniority,requirements,technologies,application_methods,source_url,evidence', 'min:1'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'corrections.role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'corrections.company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'corrections.description' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'corrections.location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'corrections.work_mode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'corrections.employment_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'corrections.salary_min' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'corrections.salary_max' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'corrections.salary_currency' => ['sometimes', 'nullable', 'regex:/^[A-Z]{3}$/'],
            'corrections.salary_period' => ['sometimes', 'nullable', 'string', 'max:50'],
            'corrections.seniority' => ['sometimes', 'nullable', 'string', 'max:100'],
            'corrections.requirements' => ['sometimes', 'array', 'max:100'],
            'corrections.requirements.*' => ['required', 'string', 'max:100'],
            'corrections.technologies' => ['sometimes', 'array', 'max:100'],
            'corrections.technologies.*' => ['required', 'string', 'max:100'],
            'corrections.source_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'corrections.evidence' => ['sometimes', 'array', 'min:1', 'max:50'],
            'corrections.evidence.*' => ['required', 'array:quote,block_ids,fields'],
            'corrections.evidence.*.quote' => ['required', 'string', 'max:2000'],
            'corrections.evidence.*.block_ids' => ['required', 'array', 'min:1', 'max:128'],
            'corrections.evidence.*.block_ids.*' => ['required', 'string', 'max:128'],
            'corrections.evidence.*.fields' => ['required', 'array', 'min:1', 'max:'.count(PublicationTriageOutput::evidenceFields())],
            'corrections.evidence.*.fields.*' => ['required', 'string', 'in:'.implode(',', PublicationTriageOutput::evidenceFields())],
            'corrections.application_methods' => ['sometimes', 'array', 'max:20'],
            'corrections.application_methods.*' => ['required', 'array:type,platform,url,email,phone,instructions'],
            'corrections.application_methods.*.type' => ['required', 'string', 'in:'.implode(',', array_map(static fn (ApplicationMethodType $type): string => $type->value, ApplicationMethodType::cases()))],
            'corrections.application_methods.*.platform' => ['sometimes', 'nullable', 'string', 'max:100'],
            'corrections.application_methods.*.url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'corrections.application_methods.*.email' => ['sometimes', 'nullable', 'email', 'max:320'],
            'corrections.application_methods.*.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'corrections.application_methods.*.instructions' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);
        $posting = JobPosting::query()
            ->where('user_id', $this->user()->id)
            ->whereKey((int) $validated['id'])
            ->first();

        if ($posting === null) {
            throw ToolException::notFound('Oportunidade não encontrada.');
        }

        try {
            $revision = $this->reviews->review(
                $this->user(),
                $posting,
                (int) $validated['expected_version'],
                $validated['corrections'],
                $validated['idempotency_key'] ?? null,
            );
        } catch (OpportunityReviewConflict $exception) {
            throw ToolException::conflict($exception->getMessage());
        }

        $reviewedPosting = $revision->jobPosting;

        return $this->ok([
            'review_revision_id' => $revision->id,
            'job_posting_id' => $reviewedPosting->id,
            'readiness' => $reviewedPosting->readiness,
            'review_reasons' => $reviewedPosting->review_reasons,
            'opportunity_version' => $reviewedPosting->opportunity_version,
            'next_action' => array_intersect(
                ['publication_content_unavailable', 'missing_publication_source'],
                $reviewedPosting->review_reasons ?? [],
            ) !== []
                ? 'new_visit_required'
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $methodTypes = array_map(static fn (ApplicationMethodType $type): string => $type->value, ApplicationMethodType::cases());
        $evidenceFields = PublicationTriageOutput::evidenceFields();
        $evidence = $schema->object([
            'quote' => $schema->string()->max(2000)->required()->description('Trecho literal do snapshot persistido.'),
            'block_ids' => $schema->array()->min(1)->max(128)->required()->items($schema->string()->max(128)),
            'fields' => $schema->array()->min(1)->max(count($evidenceFields))->required()->items($schema->string()->enum($evidenceFields)),
        ])->required()->description('Citação literal, blocos do snapshot e campos sustentados. Todos serão validados contra conteúdo persistido.');

        return [
            'id' => $schema->integer()->min(1)->required()->description('Id da oportunidade. V1 não aceita correção estruturada.'),
            'expected_version' => $schema->integer()->min(1)->required()->description('Versão retornada por get_job_posting. Stale version retorna conflito.'),
            'corrections' => $schema->object([
                'role' => $schema->string()->max(255)->nullable()->description('Cargo.'),
                'company' => $schema->string()->max(255)->nullable()->description('Empresa.'),
                'description' => $schema->string()->max(100000)->nullable()->description('Descrição.'),
                'location' => $schema->string()->max(255)->nullable()->description('Localidade.'),
                'work_mode' => $schema->string()->max(100)->nullable()->description('Modalidade de trabalho.'),
                'employment_type' => $schema->string()->max(100)->nullable()->description('Tipo de contratação.'),
                'salary_min' => $schema->number()->min(0)->max(999999999999.99)->multipleOf(0.01)->nullable()->description('Salário mínimo não negativo, até duas casas decimais.'),
                'salary_max' => $schema->number()->min(0)->max(999999999999.99)->multipleOf(0.01)->nullable()->description('Salário máximo não negativo, até duas casas decimais.'),
                'salary_currency' => $schema->string()->pattern('^[A-Z]{3}$')->nullable()->description('Código monetário de três letras maiúsculas.'),
                'salary_period' => $schema->string()->max(50)->nullable()->description('Período salarial.'),
                'seniority' => $schema->string()->max(100)->nullable()->description('Senioridade.'),
                'requirements' => $schema->array()->max(100)->items($schema->string()->max(100)),
                'technologies' => $schema->array()->max(100)->items($schema->string()->max(100)),
                'application_methods' => $schema->array()->max(20)->items($schema->object([
                    'type' => $schema->string()->enum($methodTypes)->required(),
                    'platform' => $schema->string()->max(100)->nullable(),
                    'url' => $schema->string()->max(2048)->nullable()->description('URL HTTP(S).'),
                    'email' => $schema->string()->max(320)->nullable()->description('E-mail válido.'),
                    'phone' => $schema->string()->max(50)->nullable(),
                    'instructions' => $schema->string()->max(10000)->nullable(),
                ])),
                'source_url' => $schema->string()->max(2048)->nullable()->description('URL opcional. Valor não nulo precisa ser HTTP(S) válido e constar na publicação persistida.'),
                'evidence' => $schema->array()->min(1)->max(50)->items($evidence)->description('Substitui evidências; citação e block_ids precisam existir no snapshot persistido. fields vincula citação a campo e valores precisam aparecer literalmente.'),
            ])->required()->description('Campos editáveis. Correções de provenance são auditadas; evidências só aceitam citações e blocos verificáveis no snapshot.'),
            'idempotency_key' => $schema->string()->max(255)->description('Chave opcional para replay explícito da mesma correção.'),
        ];
    }
}
