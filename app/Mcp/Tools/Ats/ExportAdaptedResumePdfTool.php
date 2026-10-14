<?php

namespace App\Mcp\Tools\Ats;

use App\Enums\ResumeLanguage;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobPosting;
use App\Services\ResumePdfService;
use App\Support\Staleness;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Throwable;

#[Description('Gera o PDF da adaptação de currículo do idioma pedido (padrão pt-BR) de uma vaga confirmada e devolve o caminho do arquivo. Quando a adaptação está otimizada para ATS, exige ats_consent=true e rejeita otimização desatualizada.')]
class ExportAdaptedResumePdfTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly ResumePdfService $pdf,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'job_posting_id' => ['required', 'integer'],
            'ats_consent' => ['nullable', 'boolean'],
            'language' => ['nullable', Rule::enum(ResumeLanguage::class)],
        ]);

        $language = $this->language($request);

        $user = $this->user();

        $posting = JobPosting::query()
            ->where('user_id', $user->id)
            ->whereKey((int) $validated['job_posting_id'])
            ->first();

        if ($posting === null) {
            throw ToolException::notFound('Vaga não encontrada.');
        }

        $adaptation = $posting->resumeAdaptation($language)->first();

        if ($adaptation === null) {
            throw ToolException::notFound('Adaptação não encontrada para esta vaga.');
        }

        if ($adaptation->is_ats_optimized) {
            Gate::forUser($user)->authorize('useAtsOptimization');

            if (! (bool) ($validated['ats_consent'] ?? false)) {
                throw ToolException::precondition('Confirme o consentimento (ats_consent=true) para baixar o PDF otimizado.');
            }

            if (
                blank($adaptation->ats_prompt)
                || Staleness::isAtsPromptStale($adaptation)
                || Staleness::isAdaptationStale($adaptation)
            ) {
                throw ToolException::precondition('A otimização ATS está desatualizada; atualize-a antes de exportar o PDF.');
            }
        }

        $relativeDirectory = 'mcp/pdf';
        $suffix = $language === ResumeLanguage::En ? '-en' : '';
        $path = Storage::disk('local')->path($relativeDirectory."/adapted-resume-{$posting->id}{$suffix}.pdf");

        Storage::disk('local')->makeDirectory($relativeDirectory);

        try {
            $this->pdf->download($adaptation)->save($path);
        } catch (Throwable $exception) {
            Log::channel('ai')->error('PDF generation failed.', [
                'job_posting_id' => $posting->id,
                'error' => $exception->getMessage(),
            ]);

            throw ToolException::internal('Falha ao gerar o PDF.');
        }

        return $this->ok([
            'path' => $path,
            'mime_type' => 'application/pdf',
            'bytes' => is_file($path) ? (int) filesize($path) : 0,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'job_posting_id' => $schema->integer()->required()->description('Id da vaga confirmada.'),
            'ats_consent' => $schema->boolean()->description('Obrigatório true quando a adaptação está otimizada para ATS.'),
            'language' => $schema->string()->description('Idioma da adaptação exportada: pt-BR (padrão) ou en.'),
        ];
    }
}
