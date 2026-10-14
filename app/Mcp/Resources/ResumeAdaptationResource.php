<?php

namespace App\Mcp\Resources;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ResumeAdaptationPresenter;
use App\Mcp\Support\ToolException;
use App\Models\JobPosting;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Support\UriTemplate;

#[Description('Adaptação de currículo de uma vaga do usuário configurado, no idioma da query (padrão pt-BR): texto adaptado, alterações, contemplados, lacunas, alertas e estado ATS.')]
class ResumeAdaptationResource extends JoboraResource implements HasUriTemplate
{
    public function __construct(
        McpUserResolver $users,
    ) {
        parent::__construct($users);
    }

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('jobora://resume-adaptations/{job_posting_id}');
    }

    protected function read(Request $request): Response
    {
        $posting = JobPosting::query()
            ->where('user_id', $this->user()->id)
            ->whereKey((int) $request->get('job_posting_id'))
            ->first();

        if ($posting === null) {
            throw ToolException::notFound('Vaga não encontrada.');
        }

        $adaptation = $posting->resumeAdaptation($this->language($request))->first();

        if ($adaptation === null) {
            throw ToolException::notFound('Adaptação não encontrada para esta vaga.');
        }

        return $this->ok(['adaptation' => ResumeAdaptationPresenter::toArray($adaptation)]);
    }
}
