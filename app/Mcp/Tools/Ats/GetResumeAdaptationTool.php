<?php

namespace App\Mcp\Tools\Ats;

use App\Enums\ResumeLanguage;
use App\Mcp\Support\ResumeAdaptationPresenter;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobPosting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Devolve a adaptação de currículo do idioma pedido (padrão pt-BR) de uma vaga do usuário configurado: texto adaptado, alterações, contemplados, lacunas, alertas e estado ATS.')]
class GetResumeAdaptationTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'job_posting_id' => ['required', 'integer'],
            'language' => ['nullable', Rule::enum(ResumeLanguage::class)],
        ]);

        $language = $this->language($request);

        $posting = JobPosting::query()
            ->where('user_id', $this->user()->id)
            ->whereKey((int) $validated['job_posting_id'])
            ->first();

        if ($posting === null) {
            throw ToolException::notFound('Vaga não encontrada.');
        }

        $adaptation = $posting->resumeAdaptation($language)->first();

        if ($adaptation === null) {
            throw ToolException::notFound('Adaptação não encontrada para esta vaga.');
        }

        return $this->ok(['adaptation' => ResumeAdaptationPresenter::toArray($adaptation)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'job_posting_id' => $schema->integer()->required()->description('Id da vaga.'),
            'language' => $schema->string()->description('Idioma da adaptação: pt-BR (padrão) ou en.'),
        ];
    }
}
